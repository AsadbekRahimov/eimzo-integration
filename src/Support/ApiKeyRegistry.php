<?php

namespace AsadbekRahimov\EimzoIntegration\Support;

/**
 * Normalises the per-domain E-IMZO API keys into the flat
 *   [domain1, key1, domain2, key2, ...]
 * shape that the browser SDK (`CAPIWS.apikey()`) expects.
 *
 * Accepted input formats (all interchangeable):
 *
 *  1. Modern map form (recommended):
 *
 *         EIMZO_API_KEYS="localhost=KEY1;127.0.0.1=KEY2;eimzo.test=KEY3"
 *
 *     Pair separator: `;` (or newline / space). Domain/key separator: `=`.
 *
 *  2. Legacy comma-pair form (kept for backward-compat with the upstream
 *     example.uz demo and earlier releases of this package):
 *
 *         EIMZO_API_KEYS="localhost,KEY1,127.0.0.1,KEY2"
 *
 *  3. Per-host env vars - useful when keys must not appear in shell histories
 *     side-by-side, or when domains contain `=`/`;`:
 *
 *         EIMZO_API_KEY_LOCALHOST=KEY1
 *         EIMZO_API_KEY_127_0_0_1=KEY2
 *         EIMZO_API_KEY_EIMZO_TEST=KEY3
 *
 *     Naming rules: prefix with `EIMZO_API_KEY_`, then UPPERCASE the host,
 *     replace any character that is not [A-Z0-9] with `_`. The original host
 *     (lowercased, preserving dots) is reconstructed from the optional
 *     EIMZO_API_KEY_<KEY>_HOST companion var, otherwise the suffix is
 *     used verbatim (with `_` -> `.`) which is good enough for the common case.
 *
 *  4. `config/eimzo.php` array - if you publish the config and prefer to
 *     hard-code an associative array `['localhost' => 'KEY', ...]` or a flat
 *     pair list `['localhost', 'KEY', '127.0.0.1', 'KEY']`, both work.
 *
 * The browser-side SDK requires the page's `window.location.hostname` to appear
 * verbatim in the registered list, so {@see filterForHost()} filters the
 * registry down to the entry that matches the request host before it is
 * shipped to the browser - other domains' keys are never exposed.
 */
final class ApiKeyRegistry
{
    /**
     * Resolve the [domain, key, domain, key, ...] list that should be shipped
     * to the browser for the given request host.
     *
     * Accepts ANY of the supported {@see normalize()} input shapes plus the
     * pre-flattened pair list, so callers (Blade views, controllers, tests)
     * can pass `config('eimzo.api_keys')` straight through without caring
     * which form was configured.
     *
     * @param  mixed  $value
     * @return array<int,string>
     */
    public static function resolveForHost($value, ?string $host): array
    {
        $map = self::asMap($value);
        return self::filterForHost($map, $host);
    }

    /**
     * @param  mixed  $value
     * @return array<string,string>
     */
    public static function asMap($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            // Already a [d=>k] map?
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $out = [];
                foreach ($value as $d => $k) {
                    if (is_string($d) && is_string($k) && $d !== '' && $k !== '') {
                        $out[strtolower(trim($d))] = trim($k);
                    }
                }
                return $out;
            }
            // Flat pair list - reuse the normaliser.
            return self::normalize($value, []);
        }
        if (is_string($value)) {
            return self::normalize($value, []);
        }
        return [];
    }

    /**
     * Build a {domain => key} map from any of the supported input shapes.
     *
     * @param  array|string|null  $configValue  raw value from config('eimzo.api_keys')
     * @param  array<string,string>  $envVars   all OS env vars (typically $_ENV)
     * @return array<string,string>
     */
    public static function normalize($configValue, array $envVars = []): array
    {
        $map = [];

        // 1. config('eimzo.api_keys') value: array (assoc or flat pairs) or string.
        if (is_array($configValue)) {
            $map = self::fromArray($configValue, $map);
        } elseif (is_string($configValue) && $configValue !== '') {
            $map = self::fromString($configValue, $map);
        }

        // 2. Per-host env vars override any colliding pair from #1.
        foreach ($envVars as $name => $value) {
            if (! is_string($name) || strncmp($name, 'EIMZO_API_KEY_', 14) !== 0) {
                continue;
            }
            if ($name === 'EIMZO_API_KEYS') { // not a per-host one
                continue;
            }
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $suffix = substr($name, 14);
            // Allow EIMZO_API_KEY_<KEY>_HOST=eimzo.test to override the host literal.
            if (substr($suffix, -5) === '_HOST') {
                continue;
            }
            $hostOverride = $envVars['EIMZO_API_KEY_' . $suffix . '_HOST'] ?? null;
            $host = is_string($hostOverride) && $hostOverride !== ''
                ? strtolower(trim($hostOverride))
                : self::suffixToHost($suffix);
            if ($host === '') {
                continue;
            }
            $map[$host] = trim($value);
        }

        return $map;
    }

    /**
     * Flatten the map to the [d, k, d, k, ...] shape expected by the JS SDK
     * (`EIMZOClient.API_KEYS` and `CAPIWS.apikey()`).
     *
     * @param  array<string,string>  $map
     * @return array<int,string>
     */
    public static function flatten(array $map): array
    {
        $flat = [];
        foreach ($map as $domain => $key) {
            $flat[] = (string) $domain;
            $flat[] = (string) $key;
        }
        return $flat;
    }

    /**
     * Return only the [domain, key] pair that matches the given browser host
     * (with localhost/127.0.0.1 always allowed when the request itself comes
     * from one of them, since the desktop client treats the two as separate
     * domains and many devs alternate between them).
     *
     * If no exact match is found and a wildcard `*` entry is present, that is
     * returned. Otherwise an empty array is returned and the browser SDK will
     * reject every cryptographic call - which is the safe default.
     *
     * @param  array<string,string>  $map
     * @return array<int,string>
     */
    public static function filterForHost(array $map, ?string $host): array
    {
        $host = is_string($host) ? strtolower(trim($host)) : '';
        if ($host === '') {
            return self::flatten($map);
        }

        // Strip optional :port - the desktop client matches the host only.
        if (($colon = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        $picked = [];
        foreach ($map as $domain => $key) {
            $d = strtolower((string) $domain);
            if ($d === $host) {
                $picked[$d] = $key;
            }
        }

        // Allow co-existence of localhost and 127.0.0.1 - dev servers commonly
        // mix the two and registering both is harmless.
        if ($host === 'localhost' && isset($map['127.0.0.1'])) {
            $picked['127.0.0.1'] = $map['127.0.0.1'];
        } elseif ($host === '127.0.0.1' && isset($map['localhost'])) {
            $picked['localhost'] = $map['localhost'];
        }

        if (empty($picked) && isset($map['*'])) {
            return [$host, $map['*']];
        }

        return self::flatten($picked);
    }

    /**
     * @param  array<string,string>  $map
     * @return array<string,string>
     */
    private static function fromArray(array $value, array $map): array
    {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);

        if ($isAssoc) {
            foreach ($value as $domain => $key) {
                if (! is_string($domain) || ! is_string($key)) {
                    continue;
                }
                $domain = strtolower(trim($domain));
                $key = trim($key);
                if ($domain !== '' && $key !== '') {
                    $map[$domain] = $key;
                }
            }
            return $map;
        }

        // Flat pairs: ['localhost', 'KEY', '127.0.0.1', 'KEY']
        $count = count($value);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $domain = is_string($value[$i]) ? strtolower(trim($value[$i])) : '';
            $key = is_string($value[$i + 1]) ? trim($value[$i + 1]) : '';
            if ($domain !== '' && $key !== '') {
                $map[$domain] = $key;
            }
        }
        return $map;
    }

    /**
     * @param  array<string,string>  $map
     * @return array<string,string>
     */
    private static function fromString(string $value, array $map): array
    {
        $value = trim($value);
        if ($value === '') {
            return $map;
        }

        // If `=` appears anywhere we treat it as the modern map form, where
        // pairs are separated by `;`, newline, or whitespace - and inside each
        // pair, `=` separates the domain from the key. This is unambiguous
        // because neither valid hex API keys nor hostnames contain `=`.
        if (strpos($value, '=') !== false) {
            $parts = preg_split('/[;\r\n\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $pair) {
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    continue;
                }
                $domain = strtolower(trim(substr($pair, 0, $eq)));
                $key = trim(substr($pair, $eq + 1));
                if ($domain !== '' && $key !== '') {
                    $map[$domain] = $key;
                }
            }
            return $map;
        }

        // Legacy comma-pair form: "localhost,KEY,127.0.0.1,KEY".
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        $count = count($parts);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $domain = strtolower($parts[$i]);
            $key = $parts[$i + 1];
            if ($domain !== '' && $key !== '') {
                $map[$domain] = $key;
            }
        }
        return $map;
    }

    private static function suffixToHost(string $suffix): string
    {
        $suffix = strtolower(trim($suffix, "_ \t\r\n"));
        if ($suffix === '') {
            return '';
        }
        // Convention: EIMZO_API_KEY_EIMZO_TEST -> eimzo.test
        // Underscores map back to dots. Pure-numeric segments (e.g. 127_0_0_1)
        // are treated the same way and will reconstruct as 127.0.0.1.
        return str_replace('_', '.', $suffix);
    }
}
