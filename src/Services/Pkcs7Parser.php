<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use AsadbekRahimov\EimzoIntegration\Support\X500NameParser;

/**
 * Extracts signer-certificate metadata from a PKCS#7 envelope without
 * contacting the E-IMZO-SERVER. Useful for displaying signer info before /
 * after full trust-chain validation, or as a fallback if the backend is down.
 *
 * Note: this does NOT cryptographically verify the signature against the
 * trust anchor. Use EimzoServerClient for that.
 */
class Pkcs7Parser
{
    /**
     * @return array{
     *   serial_number: string|null,
     *   cn: string|null,
     *   tin: string|null,
     *   pinfl: string|null,
     *   uid: string|null,
     *   o: string|null,
     *   t: string|null,
     *   country: string|null,
     *   email: string|null,
     *   valid_from: \DateTimeImmutable|null,
     *   valid_to: \DateTimeImmutable|null,
     *   subject_dn: string|null,
     *   issuer_dn: string|null,
     *   certificate_pem: string|null
     * }
     */
    public function parseSigner(string $pkcs7Base64): array
    {
        $der = base64_decode($this->stripPemMarkers($pkcs7Base64), true);
        if ($der === false || $der === '') {
            return $this->emptyInfo();
        }

        // Wrap into a PEM block; openssl_pkcs7_read extracts embedded
        // certificates without requiring a valid trust chain (cryptographic
        // verification is delegated to E-IMZO-SERVER).
        $pem = "-----BEGIN PKCS7-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PKCS7-----\n";

        $certs = [];
        if (! @openssl_pkcs7_read($pem, $certs) || empty($certs)) {
            return $this->emptyInfo();
        }

        // PKCS#7 envelopes typically embed [signer, intermediate?, root?].
        // Pick the first cert with a non-empty Subject - that's the signer.
        foreach ($certs as $certPem) {
            if (! is_string($certPem) || $certPem === '') {
                continue;
            }
            $info = $this->buildInfo($certPem);
            if ($info['subject_dn'] !== null || $info['serial_number'] !== null) {
                return $info;
            }
        }

        return $this->emptyInfo();
    }

    private function buildInfo(string $certificatePem): array
    {
        $info = $this->emptyInfo();
        $info['certificate_pem'] = $certificatePem;

        $resource = @openssl_x509_read($certificatePem);
        if ($resource === false) {
            return $info;
        }

        $parsed = openssl_x509_parse($resource);
        if (! is_array($parsed)) {
            return $info;
        }

        $subject = $parsed['name'] ?? '';
        if (is_string($subject) && $subject !== '') {
            // openssl returns DN with `/` separators: "/C=UZ/CN=..."
            $subject = ltrim($subject, '/');
            $subject = str_replace('/', ',', $subject);
        }
        $issuerDn = '';
        if (is_array($parsed['issuer'] ?? null)) {
            foreach ($parsed['issuer'] as $k => $v) {
                $issuerDn .= ($issuerDn === '' ? '' : ',') . $k . '=' . (is_array($v) ? implode('+', $v) : $v);
            }
        }

        $sn = $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? null);
        if (is_string($sn)) {
            $info['serial_number'] = strtoupper(ltrim((string) $sn, '0')) ?: '0';
        }

        $info['subject_dn'] = $subject ?: null;
        $info['issuer_dn'] = $issuerDn ?: null;

        $rdn = X500NameParser::parse($subject);
        $info['cn'] = X500NameParser::get($rdn, 'CN');
        $info['o'] = X500NameParser::get($rdn, 'O');
        $info['t'] = X500NameParser::get($rdn, 'T') ?? X500NameParser::get($rdn, 'TITLE');
        $info['country'] = X500NameParser::get($rdn, 'C');
        $info['email'] = X500NameParser::get($rdn, 'EMAILADDRESS')
            ?? X500NameParser::get($rdn, 'EMAIL')
            ?? X500NameParser::get($rdn, 'E');
        $info['uid'] = X500NameParser::get($rdn, 'UID');
        // Uzbek certificates carry TIN / PINFL under OIDs unknown to openssl,
        // so the DN string keeps them in dotted-numeric form:
        //   1.2.860.3.16.1.1 = INN/TIN, 1.2.860.3.16.1.2 = PINFL.
        $info['pinfl'] = X500NameParser::get($rdn, 'PINFL')
            ?? X500NameParser::get($rdn, '1.2.860.3.16.1.2');
        // INN / TIN are interchangeable Uzbek-tax-id labels. UID is a separate
        // certificate identifier and must NOT be used as a TIN fallback - it
        // would attach the cert to the wrong user record.
        $info['tin'] = X500NameParser::get($rdn, 'INN')
            ?? X500NameParser::get($rdn, 'TIN')
            ?? X500NameParser::get($rdn, '1.2.860.3.16.1.1');

        if (isset($parsed['validFrom_time_t'])) {
            $info['valid_from'] = (new \DateTimeImmutable())->setTimestamp((int) $parsed['validFrom_time_t']);
        }
        if (isset($parsed['validTo_time_t'])) {
            $info['valid_to'] = (new \DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']);
        }

        return $info;
    }

    private function emptyInfo(): array
    {
        return [
            'serial_number' => null,
            'cn' => null,
            'tin' => null,
            'pinfl' => null,
            'uid' => null,
            'o' => null,
            't' => null,
            'country' => null,
            'email' => null,
            'valid_from' => null,
            'valid_to' => null,
            'subject_dn' => null,
            'issuer_dn' => null,
            'certificate_pem' => null,
        ];
    }

    private function stripPemMarkers(string $blob): string
    {
        $blob = trim($blob);
        if (strpos($blob, '-----BEGIN') !== false) {
            $blob = preg_replace('/-----[A-Z ]+-----/', '', $blob);
            $blob = preg_replace('/\s+/', '', (string) $blob);
        }
        return (string) $blob;
    }
}
