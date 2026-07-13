<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use AsadbekRahimov\EimzoIntegration\Services\Pkcs7Parser;
use AsadbekRahimov\EimzoIntegration\Support\X500NameParser;
use AsadbekRahimov\EimzoIntegration\Tests\Support\Pkcs7TestFactory;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

require_once __DIR__ . '/Support/Pkcs7TestFactory.php';

class Pkcs7ParserTest extends TestCase
{
    public function test_returns_empty_info_for_empty_input(): void
    {
        $parser = new Pkcs7Parser();
        $info = $parser->parseSigner('');
        $this->assertNull($info['serial_number']);
        $this->assertNull($info['cn']);
    }

    public function test_returns_empty_info_for_garbage_input(): void
    {
        $parser = new Pkcs7Parser();
        $info = $parser->parseSigner(base64_encode(random_bytes(64)));
        $this->assertNull($info['serial_number']);
    }

    public function test_extracts_signer_from_self_signed_pkcs7(): void
    {
        $pkcs7 = $this->makePkcs7Attached([
            'C' => 'UZ',
            'CN' => 'Test User',
            'O' => 'Test Org',
            'UID' => '99999999',
            'INN' => '300000000',
        ], 'sample data');

        $parser = new Pkcs7Parser();
        $info = $parser->parseSigner($pkcs7);

        $this->assertSame('Test User', $info['cn']);
        $this->assertSame('Test Org', $info['o']);
        $this->assertSame('UZ', $info['country']);
        $this->assertSame('99999999', $info['uid']);
        $this->assertSame('300000000', $info['tin']);
        $this->assertNotNull($info['serial_number']);
        $this->assertNotEmpty($info['certificate_pem']);
    }

    public function test_extracts_tin_and_pinfl_from_uzbek_numeric_oids(): void
    {
        // Real E-IMZO certificates carry TIN / PINFL under OIDs that openssl
        // does not know, so the parsed DN keeps them in dotted-numeric form.
        $pkcs7 = $this->makePkcs7Attached([
            'C' => 'UZ',
            'CN' => 'Real Cert User',
            '1.2.860.3.16.1.1' => '301234567',
            '1.2.860.3.16.1.2' => '31234567890123',
        ], 'sample data');

        $parser = new Pkcs7Parser();
        $info = $parser->parseSigner($pkcs7);

        $this->assertSame('Real Cert User', $info['cn']);
        $this->assertSame('301234567', $info['tin']);
        $this->assertSame('31234567890123', $info['pinfl']);
    }

    public function test_x500_parser_handles_quoted_and_escaped_values(): void
    {
        $rdn = X500NameParser::parse('CN="Smith\, Jane",O=Acme\, Inc,UID=12345');
        $this->assertSame('Smith, Jane', $rdn['CN']);
        $this->assertSame('Acme, Inc', $rdn['O']);
        $this->assertSame('12345', $rdn['UID']);
    }

    /**
     * Generate a self-signed PKCS#7 envelope (attached, DER, base64) for testing.
     * Uses the openssl CLI because PHP's openssl_pkcs7_sign always emits S/MIME.
     */
    private function makePkcs7Attached(array $subject, string $data): string
    {
        return Pkcs7TestFactory::attached($subject, $data);
    }
}
