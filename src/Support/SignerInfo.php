<?php

namespace AsadbekRahimov\EimzoIntegration\Support;

/**
 * Normalises signer-certificate data returned by the different
 * E-IMZO-SERVER endpoints into the shape used by EimzoCertificate.
 */
final class SignerInfo
{
    /**
     * Extract the end-entity certificate from auth, verify, or mobile output.
     */
    public static function fromServerPayload(array $payload): array
    {
        $certificate = $payload['subjectCertificateInfo'] ?? null;
        $nested = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $pkcs7Info = is_array($payload['pkcs7Info'] ?? null) ? $payload['pkcs7Info'] : [];

        if (! is_array($certificate)) {
            $certificate = $nested['subjectCertificateInfo'] ?? null;
        }

        if (! is_array($certificate)) {
            $signers = is_array($pkcs7Info['signers'] ?? null) ? $pkcs7Info['signers'] : [];
            $firstSigner = is_array($signers[0] ?? null) ? $signers[0] : [];
            $chain = is_array($firstSigner['certificate'] ?? null) ? $firstSigner['certificate'] : [];
            $certificate = $chain[0] ?? null;
        }

        // Some older auth adapters put certificate fields directly in payload.
        if (! is_array($certificate)) {
            $certificate = $nested;
        }

        return self::fromCertificate($certificate);
    }

    /**
     * Merge trusted server fields into locally parsed data without replacing
     * useful non-empty local values.
     */
    public static function merge(array $local, array $server): array
    {
        foreach ($server as $key => $value) {
            if (self::isEmpty($local[$key] ?? null) && ! self::isEmpty($value)) {
                $local[$key] = $value;
            }
        }

        if (! self::isEmpty($local['serial_number'] ?? null)) {
            $local['serial_number'] = self::normaliseSerial((string) $local['serial_number']);
        }

        return $local;
    }

    public static function fromCertificate(array $certificate): array
    {
        $subject = $certificate['subjectName'] ?? ($certificate['subjectInfo'] ?? []);
        if (! is_array($subject)) {
            $subject = X500NameParser::parse((string) $subject);
        }

        // Legacy adapters may return CN/TIN/etc. directly in the candidate.
        if ($subject === []) {
            $subject = $certificate;
        }

        $issuer = $certificate['issuerInfo'] ?? [];
        if (! is_array($issuer)) {
            $issuer = X500NameParser::parse((string) $issuer);
        }

        $serial = self::scalarString($certificate['serialNumber'] ?? ($certificate['serial_number'] ?? null));

        $subjectDn = $certificate['X500Name'] ?? null;
        if (! is_string($subjectDn) || $subjectDn === '') {
            $subjectDn = $certificate['subjectName'] ?? ($certificate['subject_dn'] ?? null);
        }
        if (! is_string($subjectDn) || $subjectDn === '') {
            $subjectDn = self::toDn($subject);
        }

        $issuerDn = $certificate['issuerName'] ?? ($certificate['issuer_dn'] ?? null);
        if (! is_string($issuerDn) || $issuerDn === '') {
            $issuerDn = self::toDn($issuer);
        }

        return [
            'serial_number' => is_string($serial) && $serial !== '' ? self::normaliseSerial($serial) : null,
            'cn' => self::value($subject, ['CN', 'cn']),
            'tin' => self::value($subject, ['1.2.860.3.16.1.1', 'TIN', 'INN', 'tin']),
            'pinfl' => self::value($subject, ['1.2.860.3.16.1.2', 'PINFL', 'pinfl']),
            'uid' => self::value($subject, ['UID', 'uid']),
            'o' => self::value($subject, ['O', 'o']),
            't' => self::value($subject, ['T', 'TITLE', 't']),
            'country' => self::value($subject, ['C', 'country']),
            'email' => self::value($subject, ['EMAILADDRESS', 'EMAIL', 'E', 'email']),
            'valid_from' => self::scalarString($certificate['validFrom'] ?? ($certificate['valid_from'] ?? null)),
            'valid_to' => self::scalarString($certificate['validTo'] ?? ($certificate['valid_to'] ?? null)),
            'subject_dn' => is_string($subjectDn) && $subjectDn !== '' ? $subjectDn : null,
            'issuer_dn' => is_string($issuerDn) && $issuerDn !== '' ? $issuerDn : null,
            'certificate_pem' => self::scalarString(
                $certificate['certificatePem'] ?? ($certificate['certificate_pem'] ?? null)
            ),
        ];
    }

    private static function value(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function toDn(array $values): ?string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_numeric($value))) {
                continue;
            }
            $parts[] = $key . '=' . (string) $value;
        }

        return $parts === [] ? null : implode(',', $parts);
    }

    private static function normaliseSerial(string $serial): string
    {
        $serial = strtoupper(trim($serial));

        return ltrim($serial, '0') ?: '0';
    }

    private static function isEmpty($value): bool
    {
        return $value === null || $value === '';
    }

    private static function scalarString($value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
