<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E-IMZO-SERVER backend
    |--------------------------------------------------------------------------
    |
    | URL of the Java E-IMZO-SERVER service. The server is the only component
    | with VPN access to the certificate authority for OCSP/cert validation.
    | All signature verification, authentication, and timestamping is proxied
    | through it.
    |
    | Production: contact PKI Technical Center to obtain VPN keys, then run
    | the JAR locally. See https://github.com/qo0p/e-imzo-doc.
    |
    */

    'server_url' => env('EIMZO_SERVER_URL', 'http://127.0.0.1:8080'),
    'frontend_url' => env('EIMZO_FRONTEND_URL'),
    'server_timeout' => env('EIMZO_SERVER_TIMEOUT', 20),
    'server_connect_timeout' => env('EIMZO_SERVER_CONNECT_TIMEOUT', 3),
    'request_host' => env('EIMZO_REQUEST_HOST'),

    /*
    |--------------------------------------------------------------------------
    | API keys (per-domain authorisation)
    |--------------------------------------------------------------------------
    |
    | E-IMZO desktop client requires a per-domain API key registered via
    | CAPIWS.apikey() before any cryptographic call. The desktop client then
    | rejects any request whose page host is not in the registered list.
    |
    | Three input formats are supported (see CONFIG.md):
    |
    |   1. Recommended map form (single env var, no order/pairing pitfalls):
    |
    |        EIMZO_API_KEYS="localhost=AAA...;127.0.0.1=BBB...;eimzo.test=CCC..."
    |
    |   2. Per-host env vars (best for secret managers / CI):
    |
    |        EIMZO_API_KEY_LOCALHOST=AAA...
    |        EIMZO_API_KEY_127_0_0_1=BBB...
    |        EIMZO_API_KEY_EIMZO_TEST=CCC...
    |
    |   3. Inline assoc array right here in the published config file:
    |
    |        'api_keys' => [
    |            'localhost'   => 'AAA...',
    |            '127.0.0.1'   => 'BBB...',
    |            'eimzo.test'  => 'CCC...',
    |        ],
    |
    | The legacy comma-pair form (`domain,key,domain,key,...`) is still
    | accepted for backward compatibility with the upstream qo0p demo. The
    | normalised list is filtered down to the request host before it ever
    | reaches the browser - other domains' keys never leak.
    |
    | The defaults below are the publicly-published demo keys for localhost /
    | 127.0.0.1 - safe for development, NOT valid for any production domain.
    | Replace them with the keys issued for your domain by PKI Technical
    | Centre (https://pki.gov.uz).
    |
    */

    'api_keys' => \AsadbekRahimov\EimzoIntegration\Support\ApiKeyRegistry::normalize(
        env('EIMZO_API_KEYS', implode(';', [
            'localhost=96D0C1491615C82B9A54D9989779DF825B690748224C2B04F500F370D51827CE2644D8D4A82C18184D73AB8530BB8ED537269603F61DB0D03D2104ABF789970B',
            '127.0.0.1=A7BCFA5D490B351BE0754130DF03A068F855DB4333D43921125B9CF2670EF6A40370C646B90401955E1F7BC9CDBF59CE0B2C5467D820BE189C845D0B79CFC96F',
        ])),
        array_merge(getenv() ?: [], $_ENV ?? [])
    ),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Challenge time-to-live in seconds (default: 120, matches E-IMZO-SERVER).
        'challenge_ttl' => env('EIMZO_CHALLENGE_TTL', 120),

        // Eloquent model used to authenticate the signed certificate.
        // Must implement Authenticatable; see App\Models\User.
        'user_model' => env('EIMZO_USER_MODEL', \App\Models\User::class),

        // Column on the user model to look up by TIN/PINFL extracted from the
        // signer certificate. Set to 'tin' / 'pinfl' / 'inn' as appropriate.
        'user_lookup_column' => env('EIMZO_USER_LOOKUP_COLUMN', 'tin'),

        // Auto-create users that successfully authenticate but don't yet exist.
        'auto_register' => env('EIMZO_AUTO_REGISTER', false),

        // Guard to call Auth::guard($guard)->login(...) on after verifying.
        'guard' => env('EIMZO_AUTH_GUARD', 'web'),

        // Where to redirect after a successful authentication.
        'redirect_after_login' => env('EIMZO_REDIRECT_AFTER_LOGIN', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    */

    'sign' => [
        // Whether to attach a TSA timestamp to every signature (recommended).
        'attach_timestamp' => env('EIMZO_ATTACH_TIMESTAMP', true),

        // Default mode: 'attached' (data embedded in PKCS#7) or 'detached'.
        'default_mode' => env('EIMZO_SIGN_MODE', 'attached'),

        // Filesystem disk used by EimzoSignService::storeBinary() when
        // persisting raw PKCS#7 blobs. Set to null to keep them in DB only.
        'storage_disk' => env('EIMZO_STORAGE_DISK', 'local'),
        'storage_path' => env('EIMZO_STORAGE_PATH', 'eimzo/signatures'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile API (ID-CARD via NFC)
    |--------------------------------------------------------------------------
    |
    | Mirror of the E-IMZO-SERVER mobile.* config. site_id MUST be the SiteID
    | issued by PKI Technical Center for your domain. The QR / deeplink the
    | client builds includes this value, so it has to match config.properties.
    |
    | upload_url is what you register with PKI Technical Center as the public
    | URL the ID-CARD app posts the signed PKCS#7 to. By default we expose
    | it at /eimzo/mobile/upload (web) and /api/eimzo/mobile/upload (api).
    |
    */

    'mobile' => [
        'enabled' => env('EIMZO_MOBILE_ENABLED', true),
        'site_id' => env('EIMZO_MOBILE_SITE_ID'),
        'upload_url' => env('EIMZO_MOBILE_UPLOAD_URL'),
        // How long the client keeps polling /mobile/.../status before giving up.
        'poll_timeout' => env('EIMZO_MOBILE_POLL_TIMEOUT', 120),
        'poll_interval_ms' => env('EIMZO_MOBILE_POLL_INTERVAL_MS', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => env('EIMZO_ROUTES_ENABLED', true),
        'prefix' => env('EIMZO_ROUTE_PREFIX', 'eimzo'),
        'middleware' => ['web'],
        'api_prefix' => env('EIMZO_API_PREFIX', 'api/eimzo'),
        'api_middleware' => ['api'],
    ],

    'assets' => [
        'enabled' => env('EIMZO_ASSET_ROUTES_ENABLED', true),
        'middleware' => [],
        'cache_seconds' => env('EIMZO_ASSET_CACHE_SECONDS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database table names
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'challenges' => 'eimzo_challenges',
        'certificates' => 'eimzo_certificates',
        'signatures' => 'eimzo_signatures',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local fallback verification
    |--------------------------------------------------------------------------
    |
    | When true, the module first attempts to extract signer info from the
    | PKCS#7 envelope locally via openssl, then calls E-IMZO-SERVER for full
    | trust-chain validation. Disable to skip local parsing entirely.
    |
    */

    'local_parse' => env('EIMZO_LOCAL_PARSE', true),

];
