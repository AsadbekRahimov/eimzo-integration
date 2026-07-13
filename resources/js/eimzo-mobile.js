/*
 * Browser helper for the mobile API exposed by asadbekrahimov/eimzo-integration.
 *
 * The E-IMZO ID-CARD mobile app reads a QR that encodes:
 *
 *     siteId(4 chars) + documentId(8 chars) + gostHash(64 chars) + crc32(8 chars)
 *
 * The QR string is generated client-side. This module:
 *
 *   - calls the Laravel backend to issue documentId/challenge
 *   - exposes makeQrPayload() so you can render the QR with any QR lib
 *   - polls the status endpoint until status === 1
 *   - calls the complete endpoint and returns the result
 *
 * GOST R 34.11-94 hashing is NOT part of the bundled vendor scripts: load the
 * gost-hash module (global `GostHash`) from test.e-imzo.uz/demo/eimzoidcard
 * before calling makeQrPayload(). Without it a SHA-256 fallback is used, which
 * real ID-CARD apps will reject - it only exists for mock/demo mode.
 *
 * Usage:
 *
 *     const m = new EimzoMobile({ csrfToken: '...' });
 *
 *     // Login flow
 *     const session = await m.startAuth();
 *     // session = { document_id, site_id, challenge, qr }
 *     renderQr(session.qr);
 *     const result = await m.waitAndCompleteAuth(session.document_id);
 *
 *     // Sign flow
 *     const doc = btoa(JSON.stringify(action));
 *     const signSession = await m.startSign(doc);
 *     renderQr(signSession.qr);
 *     const sig = await m.waitAndCompleteSign(signSession.document_id, doc);
 */
(function (global) {
    'use strict';

    const DEFAULT_ROUTES = {
        authStart: '/eimzo/mobile/auth/start',
        authStatus: '/eimzo/mobile/auth/status',
        authComplete: '/eimzo/mobile/auth/complete',
        signStart: '/eimzo/mobile/sign/start',
        signStatus: '/eimzo/mobile/sign/status',
        signComplete: '/eimzo/mobile/sign/complete'
    };

    const CRC32_TABLE = (function () {
        const t = new Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) {
                c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            }
            t[n] = c >>> 0;
        }
        return t;
    })();

    function crc32Hex(str) {
        let crc = 0xFFFFFFFF;
        for (let i = 0; i < str.length; i++) {
            crc = (crc >>> 8) ^ CRC32_TABLE[(crc ^ str.charCodeAt(i)) & 0xFF];
        }
        crc = (crc ^ 0xFFFFFFFF) >>> 0;
        return ('00000000' + crc.toString(16)).slice(-8).toUpperCase();
    }

    function gostHashOrFallback(text) {
        // Prefer the GOST hash bundled with the desktop client. Fallback uses
        // SHA-256 hex - the mobile app expects GOST so this fallback only
        // works in mock/demo mode without a real ID-CARD device.
        if (typeof global.GostHash === 'function') {
            try {
                return new global.GostHash().gosthash(text);
            } catch (e) { /* fall through */ }
        }
        if (global.CryptoJS && global.CryptoJS.SHA256) {
            return global.CryptoJS.SHA256(text).toString().toUpperCase();
        }
        return null;
    }

    function fetchJson(url, options, csrfToken) {
        options = options || {};
        const headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }
        const init = { method: options.method || 'GET', headers, credentials: 'same-origin' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(url, init).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (json) {
                if (!r.ok && !(json && json.status === 1)) {
                    const err = new Error((json && json.message) || ('HTTP ' + r.status));
                    err.payload = json;
                    err.status = r.status;
                    throw err;
                }
                if (json === null) {
                    throw new Error('Non-JSON response from ' + url);
                }
                return json;
            });
        });
    }

    function delay(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    class EimzoMobile {
        constructor(options) {
            options = options || {};
            this.routes = Object.assign({}, DEFAULT_ROUTES, options.routes || {});
            this.csrfToken = options.csrfToken
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || null;
            this.pollIntervalMs = options.pollIntervalMs || 1500;
            this.pollTimeoutMs = options.pollTimeoutMs || 120000;
        }

        /**
         * Build the QR / deeplink payload string per protocol:
         *   siteId + documentId + gostHash(text) + crc32(siteId+documentId+gostHash)
         *
         * For auth flows, `text` is the challenge returned by the backend.
         * For sign flows, `text` is the document content (or its canonical
         * representation) that the user is signing.
         */
        makeQrPayload(siteId, documentId, text) {
            const hash = gostHashOrFallback(text);
            if (!hash) {
                throw new Error('GOST hashing module is not loaded');
            }
            const code = String(siteId) + String(documentId) + String(hash);
            return code + crc32Hex(code);
        }

        startAuth() {
            return fetchJson(this.routes.authStart, { method: 'POST', body: {} }, this.csrfToken)
                .then((res) => {
                    res.qr = this.makeQrPayload(res.site_id, res.document_id, res.challenge);
                    return res;
                });
        }

        startSign(documentBase64) {
            return fetchJson(this.routes.signStart, { method: 'POST', body: {} }, this.csrfToken)
                .then((res) => {
                    res.qr = this.makeQrPayload(res.site_id, res.document_id, documentBase64);
                    return res;
                });
        }

        pollAuth(documentId) {
            return fetchJson(this.routes.authStatus, {
                method: 'POST', body: { document_id: documentId }
            }, this.csrfToken);
        }

        pollSign(documentId) {
            return fetchJson(this.routes.signStatus, {
                method: 'POST', body: { document_id: documentId }
            }, this.csrfToken);
        }

        completeAuth(documentId) {
            return fetchJson(this.routes.authComplete, {
                method: 'POST', body: { document_id: documentId }
            }, this.csrfToken);
        }

        completeSign(documentId, documentBase64, extras) {
            const body = Object.assign({
                document_id: documentId,
                document: documentBase64
            }, extras || {});
            return fetchJson(this.routes.signComplete, {
                method: 'POST', body
            }, this.csrfToken);
        }

        async waitAndCompleteAuth(documentId) {
            await this._waitFor(() => this.pollAuth(documentId));
            return this.completeAuth(documentId);
        }

        async waitAndCompleteSign(documentId, documentBase64, extras) {
            await this._waitFor(() => this.pollSign(documentId));
            return this.completeSign(documentId, documentBase64, extras);
        }

        async _waitFor(probe) {
            const started = Date.now();
            while (Date.now() - started < this.pollTimeoutMs) {
                const r = await probe();
                if (r && r.status === 1) {
                    return r;
                }
                if (r && r.status !== 2 && r.status !== undefined && r.status !== null && r.status < 0) {
                    const err = new Error((r && r.message) || ('mobile status ' + r.status));
                    err.payload = r;
                    throw err;
                }
                await delay(this.pollIntervalMs);
            }
            throw new Error('Mobile signing timed out after ' + this.pollTimeoutMs + 'ms');
        }
    }

    global.EimzoMobile = EimzoMobile;
})(typeof window !== 'undefined' ? window : globalThis);
