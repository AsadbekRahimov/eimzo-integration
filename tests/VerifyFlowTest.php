<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Support\Facades\Config;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;

class VerifyFlowTest extends TestCase
{
    public function test_attached_verify_returns_verified_document_and_server_signer(): void
    {
        Config::set('eimzo.local_parse', false);
        $this->mockServer([
            'status' => 1,
            'pkcs7Info' => [
                'documentBase64' => base64_encode('contract'),
                'signers' => [[
                    'certificate' => [[
                        'serialNumber' => '00AB',
                        'subjectInfo' => ['CN' => 'Verifier'],
                    ]],
                ]],
            ],
        ]);

        $response = $this->postJson('/eimzo/verify', ['pkcs7' => 'AA==']);

        $response->assertOk();
        $this->assertSame('contract', base64_decode($response->json('document_base64')));
        $this->assertSame('AB', $response->json('signer.serial_number'));
        $this->assertSame('Verifier', $response->json('signer.cn'));
    }

    public function test_attached_verify_rejects_success_without_embedded_document(): void
    {
        Config::set('eimzo.local_parse', false);
        $this->mockServer(['status' => 1, 'pkcs7Info' => []]);

        $response = $this->postJson('/eimzo/verify', ['pkcs7' => 'AA==']);

        $response->assertStatus(503);
        $this->assertSame(-2, $response->json('status'));
    }

    public function test_attached_verify_rejects_malformed_pkcs7_info_without_php_error(): void
    {
        Config::set('eimzo.local_parse', false);
        $this->mockServer(['status' => 1, 'pkcs7Info' => 'malformed']);

        $response = $this->postJson('/eimzo/verify', ['pkcs7' => 'AA==']);

        $response->assertStatus(503);
        $this->assertSame(-2, $response->json('status'));
    }

    private function mockServer(array $payload): void
    {
        $this->app->singleton(EimzoServerClient::class, function () use ($payload) {
            return new class($payload) extends EimzoServerClient {
                private $payload;

                public function __construct(array $payload)
                {
                    $this->payload = $payload;
                }

                public function verifyAttached(string $pkcs7Base64, ?string $clientIp = null): array
                {
                    return $this->payload;
                }
            };
        });
    }
}
