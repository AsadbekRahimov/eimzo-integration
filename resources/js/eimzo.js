/*
 * High-level Promise wrapper around the bundled E-IMZO vendor SDK
 * (e-imzo.js + e-imzo-client.js, which expose `CAPIWS` and `EIMZOClient`).
 *
 * Loads the desktop client over WebSocket (wss://127.0.0.1:64443), enumerates
 * available certificates, signs challenges/payloads, then talks to the Laravel
 * backend mounted at /eimzo/* by the EimzoServiceProvider.
 *
 * Usage:
 *   const eimzo = new EimzoBridge({ csrfToken: '...' });
 *   await eimzo.install();
 *   const keys = await eimzo.listKeys();
 *   const result = await eimzo.login(keys[0]);   // logs the user in
 *   const sig = await eimzo.sign(keys[0], { data: '...' });
 *   const verifyResult = await eimzo.verify({ pkcs7: '...', data: '...' });
 */
(function (global) {
    'use strict';

    function fail(reason) {
        return Promise.reject(reason instanceof Error ? reason : new Error(String(reason)));
    }

    function ensureGlobals() {
        if (typeof global.CAPIWS === 'undefined') {
            return fail('CAPIWS is not loaded - include vendor/e-imzo.js before vendor/e-imzo-client.js');
        }
        if (typeof global.EIMZOClient === 'undefined') {
            return fail('EIMZOClient is not loaded - include vendor/e-imzo-client.js');
        }
        return Promise.resolve();
    }

    function utf8ToBase64(str) {
        if (typeof global.Base64 !== 'undefined' && global.Base64.encode) {
            return global.Base64.encode(str);
        }
        return btoa(unescape(encodeURIComponent(str)));
    }

    class EimzoBridge {
        constructor(options) {
            options = options || {};
            this.routes = Object.assign({
                challenge: '/eimzo/auth/challenge',
                verify: '/eimzo/auth/verify',
                logout: '/eimzo/auth/logout',
                sign: '/eimzo/sign',
                verifySignature: '/eimzo/verify',
                timestampPkcs7: '/eimzo/timestamp/pkcs7',
                timestampData: '/eimzo/timestamp/data',
                makeAttached: '/eimzo/pkcs7/make-attached',
                join: '/eimzo/pkcs7/join'
            }, options.routes || {});
            this.csrfToken = options.csrfToken
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || null;
            this.apiKeys = options.apiKeys || global.EIMZO_API_KEYS || null;
            this.installed = false;
        }

        install() {
            return ensureGlobals().then(() => {
                if (this.installed) {
                    return;
                }
                if (this.apiKeys) {
                    global.EIMZOClient.API_KEYS = this.apiKeys;
                }
                return new Promise((resolve, reject) => {
                    global.EIMZOClient.checkVersion(
                        () => global.EIMZOClient.installApiKeys(
                            () => { this.installed = true; resolve(); },
                            (e) => reject(new Error('installApiKeys failed: ' + (e && e.message || e)))
                        ),
                        (major, minor) => reject(new Error('E-IMZO version too old: ' + major + '.' + minor))
                    );
                });
            });
        }

        listKeys() {
            return this.install().then(() => new Promise((resolve, reject) => {
                let counter = 0;
                global.EIMZOClient.listAllUserKeys(
                    (key) => 'key-' + (key.serialNumber || key.cardUID || counter) + '-' + (counter++),
                    (id, key) => Object.assign({ id }, key),
                    (items, firstId) => resolve(items || []),
                    (e, reason) => reject(new Error(reason || (e && e.message) || 'listAllUserKeys failed'))
                );
            }));
        }

        loadKey(key) {
            return this.install().then(() => new Promise((resolve, reject) => {
                global.EIMZOClient.loadKey(
                    key,
                    (keyId) => resolve(keyId),
                    (e, reason) => reject(new Error(reason || 'loadKey failed'))
                );
            }));
        }

        signRaw(key, data, detached) {
            const data64 = (typeof data === 'string') ? utf8ToBase64(data) : data;
            return this.loadKey(key).then((keyId) => new Promise((resolve, reject) => {
                global.CAPIWS.callFunction(
                    { plugin: 'pkcs7', name: 'create_pkcs7', arguments: [data64, keyId, detached ? 'yes' : 'no'] },
                    (event, data) => {
                        if (data && data.success === true) {
                            resolve(data.pkcs7_64);
                        } else {
                            reject(new Error((data && data.reason) || 'create_pkcs7 failed'));
                        }
                    },
                    (e) => reject(new Error('create_pkcs7 transport error: ' + (e && e.message || e)))
                );
            }));
        }

        login(key) {
            return this.fetch(this.routes.challenge, { method: 'GET' })
                .then((res) => {
                    if (!res || !res.challenge) {
                        throw new Error((res && res.message) || 'Failed to issue challenge');
                    }
                    return this.signRaw(key, res.challenge, false).then((pkcs7) => ({ pkcs7, challenge: res.challenge }));
                })
                .then((p) => this.fetch(this.routes.verify, {
                    method: 'POST',
                    body: { challenge: p.challenge, pkcs7: p.pkcs7 }
                }));
        }

        logout() {
            return this.fetch(this.routes.logout, { method: 'POST', body: {} });
        }

        sign(key, options) {
            options = options || {};
            const detached = !!options.detached;
            return this.signRaw(key, options.data, detached).then((pkcs7) => this.fetch(this.routes.sign, {
                method: 'POST',
                body: {
                    pkcs7,
                    detached,
                    data: detached ? (typeof options.data === 'string' ? utf8ToBase64(options.data) : options.data) : undefined,
                    document_type: options.document_type || null,
                    document_name: options.document_name || null,
                    attach_timestamp: options.attach_timestamp,
                    meta: options.meta || null
                }
            }));
        }

        verify(options) {
            return this.fetch(this.routes.verifySignature, {
                method: 'POST',
                body: { pkcs7: options.pkcs7, data: options.data || undefined }
            });
        }

        timestampPkcs7(pkcs7) {
            return this.fetch(this.routes.timestampPkcs7, { method: 'POST', body: { pkcs7 } });
        }

        timestampData(data64) {
            return this.fetch(this.routes.timestampData, { method: 'POST', body: { data: data64 } });
        }

        fetch(url, options) {
            options = options || {};
            const headers = {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };
            if (this.csrfToken) {
                headers['X-CSRF-TOKEN'] = this.csrfToken;
            }
            const init = { method: options.method || 'GET', headers, credentials: 'same-origin' };
            if (options.body !== undefined) {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(options.body);
            }
            return fetch(url, init).then((r) => r.json().then((json) => {
                if (!r.ok && (json && json.status !== 1)) {
                    const err = new Error((json && json.message) || ('HTTP ' + r.status));
                    err.payload = json;
                    err.status = r.status;
                    throw err;
                }
                return json;
            }));
        }
    }

    global.EimzoBridge = EimzoBridge;
})(typeof window !== 'undefined' ? window : globalThis);
