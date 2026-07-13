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
 * before calling makeQrPayload(). The helper fails closed when GOST hashing
 * is unavailable because SHA-256 QR payloads are rejected by real ID-CARD apps.
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
 *     const bytes = new TextEncoder().encode(JSON.stringify(action));
 *     const doc = btoa(String.fromCharCode(...bytes));
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

    function gostHash(text, customHash) {
        if (typeof customHash === 'function') {
            return customHash(text);
        }
        if (typeof global.GostHash === 'function') {
            try {
                return new global.GostHash().gosthash(text);
            } catch (e) { /* fall through */ }
        }
        return null;
    }

    function decodeBase64(value) {
        if (typeof value !== 'string') {
            throw new Error('Mobile document must be a Base64 string');
        }
        const compact = value.replace(/\s+/g, '');
        if (!compact || compact.length % 4 === 1 || !/^[A-Za-z0-9+/]*={0,2}$/.test(compact)) {
            throw new Error('Mobile document is not valid Base64');
        }
        try {
            return global.atob(compact);
        } catch (e) {
            throw new Error('Mobile document is not valid Base64');
        }
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
            const runtime = global.EIMZO_MOBILE_CONFIG || {};
            this.routes = Object.assign({}, DEFAULT_ROUTES, runtime.routes || {}, options.routes || {});
            this.csrfToken = options.csrfToken
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || null;
            this.pollIntervalMs = options.pollIntervalMs || runtime.pollIntervalMs || 1500;
            this.pollTimeoutMs = options.pollTimeoutMs || runtime.pollTimeoutMs || 120000;
            this.hashFunction = options.hashFunction || null;
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
            let hash = gostHash(text, this.hashFunction);
            hash = typeof hash === 'string' ? hash.replace(/\s+/g, '').toUpperCase() : '';
            if (!/^[0-9A-F]{64}$/.test(hash)) {
                throw new Error('GOST hash function is unavailable or returned an invalid digest');
            }
            const code = String(siteId) + String(documentId) + String(hash);
            return code + crc32Hex(code);
        }

        startAuth() {
            return fetchJson(this.routes.authStart, { method: 'POST', body: {} }, this.csrfToken)
                .then((res) => {
                    this._applyRuntimeConfig(res);
                    res.qr = this.makeQrPayload(res.site_id, res.document_id, res.challenge);
                    return res;
                });
        }

        startSign(documentBase64) {
            let document;
            try {
                // `/backend/mobile/verify` decodes the Base64 document before
                // checking it, so the QR must contain GOST(raw bytes), not a
                // hash of the printable Base64 representation.
                document = decodeBase64(documentBase64);
            } catch (e) {
                return Promise.reject(e);
            }

            return fetchJson(this.routes.signStart, { method: 'POST', body: {} }, this.csrfToken)
                .then((res) => {
                    this._applyRuntimeConfig(res);
                    res.qr = this.makeQrPayload(res.site_id, res.document_id, document);
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
                if (r && r.status !== 2) {
                    const err = new Error((r && r.message) || ('mobile status ' + r.status));
                    err.payload = r;
                    throw err;
                }
                await delay(this.pollIntervalMs);
            }
            throw new Error('Mobile signing timed out after ' + this.pollTimeoutMs + 'ms');
        }

        _applyRuntimeConfig(response) {
            const interval = Number(response && response.poll_interval_ms);
            const timeout = Number(response && response.poll_timeout);
            if (interval > 0) {
                this.pollIntervalMs = interval;
            }
            if (timeout > 0) {
                this.pollTimeoutMs = timeout * 1000;
            }
        }
    }

    global.EimzoMobile = EimzoMobile;
})(typeof window !== 'undefined' ? window : globalThis);
