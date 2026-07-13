<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class SignFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_stores_signature_and_timestamp(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $this->mockServer([
            'timestampPkcs7' => ['status' => 1, 'pkcs7b64' => 'PKCS7-WITH-TS', 'timestampedSignerList' => [['timestamp' => '2026-05-01 10:00:05']]],
            'verifyAttached' => ['status' => 1, 'message' => ''],
        ]);

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'document_type' => 'contract',
            'document_name' => 'contract.json',
            'attach_timestamp' => true,
        ]);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $this->assertTrue((bool) $r->json('signature.has_timestamp'));

        $sig = EimzoSignature::first();
        $this->assertSame('PKCS7-WITH-TS', $sig->pkcs7_with_timestamp);
        $this->assertSame(EimzoSignature::STATUS_VALID, $sig->verification_status);
    }

    public function test_tsa_outage_does_not_fail_the_sign_request(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $this->mockServer([
            'timestampPkcs7' => new EimzoServerException('E-IMZO-SERVER unreachable: timeout', ['path' => '/frontend/timestamp/pkcs7']),
            'verifyAttached' => ['status' => 1, 'message' => ''],
        ]);

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'attach_timestamp' => true,
        ]);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));

        $sig = EimzoSignature::first();
        $this->assertNull($sig->pkcs7_with_timestamp);
        $this->assertSame(EimzoSignature::STATUS_VALID, $sig->verification_status);
        $this->assertStringContainsString('unreachable', $sig->meta['timestamp_error']);
    }

    public function test_attached_sign_hashes_the_verified_embedded_document(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $document = '{"action":"approve_invoice","entity_id":1024}';
        $this->mockServer([
            'verifyAttached' => [
                'status' => 1,
                'message' => '',
                'pkcs7Info' => ['documentBase64' => base64_encode($document)],
            ],
        ]);

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'detached' => false,
            'attach_timestamp' => false,
        ]);

        $r->assertOk();
        $sig = EimzoSignature::first();
        $this->assertSame(hash('sha256', $document), $sig->document_hash);
        $this->assertSame(strlen($document), $sig->document_size);
    }

    public function test_attached_sign_never_trusts_caller_supplied_data_for_the_hash(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $signedDocument = '{"action":"approve_invoice","entity_id":1024}';
        $this->mockServer([
            'verifyAttached' => [
                'status' => 1,
                'message' => '',
                'pkcs7Info' => ['documentBase64' => base64_encode($signedDocument)],
            ],
        ]);

        // A malicious caller sends a valid envelope for document A together
        // with unrelated "data" for document B - the stored hash must still
        // describe A, the document that was actually verified.
        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'data' => base64_encode('{"action":"approve_invoice","entity_id":9999}'),
            'detached' => false,
            'attach_timestamp' => false,
        ]);

        $r->assertOk();
        $this->assertSame(hash('sha256', $signedDocument), EimzoSignature::first()->document_hash);
    }

    public function test_detached_sign_accepts_line_wrapped_base64_data(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $this->mockServer([
            'verifyDetached' => ['status' => 1, 'message' => ''],
        ]);

        $document = str_repeat('contract body ', 20);
        // MIME-style base64 with 64-char lines, as many clients produce.
        $wrapped = trim(chunk_split(base64_encode($document), 64, "\n"));

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'data' => $wrapped,
            'detached' => true,
            'attach_timestamp' => false,
        ]);

        $r->assertOk();
        $sig = EimzoSignature::first();
        $this->assertSame(hash('sha256', $document), $sig->document_hash);
        $this->assertSame(strlen($document), $sig->document_size);
    }

    public function test_detached_sign_without_data_is_a_validation_error(): void
    {
        $this->mockServer([]);

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'detached' => true,
            'attach_timestamp' => false,
        ]);

        $r->assertStatus(422);
        $this->assertSame(0, EimzoSignature::count());
    }

    public function test_failed_verification_marks_signature_invalid_and_returns_422(): void
    {
        Config::set('eimzo.local_parse', false);
        Config::set('eimzo.sign.storage_disk', null);
        $this->mockServer([
            'verifyAttached' => ['status' => -5, 'message' => 'signature invalid'],
        ]);

        $r = $this->postJson('/eimzo/sign', [
            'pkcs7' => base64_encode('envelope'),
            'attach_timestamp' => false,
        ]);

        $r->assertStatus(422);
        $this->assertSame(-1, $r->json('status'));
        $this->assertSame(EimzoSignature::STATUS_INVALID, EimzoSignature::first()->verification_status);
    }

    /**
     * Replace EimzoServerClient with a stub. A payload value that is a
     * Throwable is thrown instead of returned.
     */
    private function mockServer(array $methods): void
    {
        $this->app->singleton(EimzoServerClient::class, function () use ($methods) {
            return new class($methods) extends EimzoServerClient {
                private $methods;

                public function __construct(array $methods)
                {
                    $this->methods = $methods;
                }

                private function respond(string $method): array
                {
                    $value = $this->methods[$method] ?? ['status' => -9, 'message' => 'unexpected call: ' . $method];
                    if ($value instanceof \Throwable) {
                        throw $value;
                    }
                    return $value;
                }

                public function timestampPkcs7(string $pkcs7Base64, ?string $clientIp = null): array
                {
                    return $this->respond('timestampPkcs7');
                }

                public function verifyAttached(string $pkcs7Base64, ?string $clientIp = null): array
                {
                    return $this->respond('verifyAttached');
                }

                public function verifyDetached(string $dataBase64, string $pkcs7Base64, ?string $clientIp = null): array
                {
                    return $this->respond('verifyDetached');
                }
            };
        });
    }
}
