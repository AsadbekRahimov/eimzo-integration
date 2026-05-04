<?php

namespace AsadbekRahimov\EimzoIntegration\Support;

/**
 * Tiny RFC 2253 / 4514 distinguished-name parser tuned for the X.500 strings
 * E-IMZO returns. Output is a [string => string] map keyed by uppercase RDN.
 *
 * Example input:
 *   "C=UZ,CN=Asadbek Rahimov,O=ANTHROPIC,T=DEVELOPER,UID=12345678,
 *    PINFL=12345678901234,INN=200000000,SERIALNUMBER=00ABCDEF..."
 */
class X500NameParser
{
    public static function parse(string $dn): array
    {
        if ($dn === '') {
            return [];
        }

        $parts = self::splitRdns($dn);
        $out = [];
        foreach ($parts as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtoupper(trim(substr($part, 0, $eq)));
            $val = self::unescape(trim(substr($part, $eq + 1)));
            if ($key !== '') {
                // Don't clobber an earlier value (E-IMZO sometimes repeats SERIALNUMBER).
                $out[$key] = $out[$key] ?? $val;
            }
        }
        return $out;
    }

    public static function get(array $parsed, string $key, ?string $default = null): ?string
    {
        return $parsed[strtoupper($key)] ?? $default;
    }

    /**
     * Split on commas that are not escaped and not inside quotes.
     */
    private static function splitRdns(string $dn): array
    {
        $parts = [];
        $buf = '';
        $inQuotes = false;
        $len = strlen($dn);
        for ($i = 0; $i < $len; $i++) {
            $ch = $dn[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $ch . $dn[++$i];
                continue;
            }
            if ($ch === '"') {
                $inQuotes = ! $inQuotes;
                $buf .= $ch;
                continue;
            }
            if (($ch === ',' || $ch === '+' || $ch === ';') && ! $inQuotes) {
                if ($buf !== '') {
                    $parts[] = $buf;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }
        return $parts;
    }

    private static function unescape(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        if ($value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }
        return preg_replace('/\\\\(.)/', '$1', $value);
    }
}
