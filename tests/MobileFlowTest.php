<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

class MobileFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_start_persists_document_id_as_challenge(): void
    {
        $this->mockServer([
            'mobileAuth' => [
                'status' => 1,
                'siteId' => '0001',
                'documentId' => 'DOCAUTH1',
                // upstream mistypes "challenge"
                'challange' => 'CHALLENGE-VALUE',
            ],
        ]);

        $r = $this->postJson('/eimzo/mobile/auth/start');

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $this->assertSame('DOCAUTH1', $r->json('document_id'));
        $this->assertSame('0001', $r->json('site_id'));
        $this->assertSame('CHALLENGE-VALUE', $r->json('challenge'));

        $row = EimzoChallenge::where('challenge', 'DOCAUTH1')->first();
        $this->assertNotNull($row);
        $this->assertSame('mobile-auth', $row->purpose);
    }

    public function test_status_passthrough_returns_server_payload(): void
    {
        $this->mockServer([
            'mobileStatus' => ['status' => 2],
        ]);

        $r = $this->postJson('/eimzo/mobile/auth/status', [
            'document_id' => 'NOPE',
        ]);

        $r->assertOk();
        $this->assertSame(2, $r->json('status'));
    }

    public function test_auth_complete_logs_user_in_and_persists_certificate(): void
    {
        $this->mockServer([
            'mobileAuth' => [
                'status' => 1,
                'siteId' => '0001',
                'documentId' => 'DOC123',
                'challange' => 'X',
            ],
            'mobileAuthenticate' => [
                'status' => 1,
                'subjectCertificateInfo' => [
                    'serialNumber' => 'AABB1122',
                    'X500Name' => 'CN=Test,UID=400,1.2.860.3.16.1.2=300000',
                    'subjectName' => [
                        'CN' => 'Test User',
                        'UID' => '400',
                        '1.2.860.3.16.1.2' => '30000000000000',
                        '1.2.860.3.16.1.1' => '300000000',
                    ],
                    'validFrom' => '2024-01-01 00:00:00',
                    'validTo' => '2030-01-01 00:00:00',
                ],
            ],
        ]);

        TestUser::create(['tin' => '300000000', 'name' => 'Test', 'email' => 't@t']);

        $this->postJson('/eimzo/mobile/auth/start')->assertOk();

        $r = $this->postJson('/eimzo/mobile/auth/complete', [
            'document_id' => 'DOC123',
        ]);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $this->assertTrue((bool) $r->json('authenticated'));
        $this->assertSame('AABB1122', $r->json('certificate.serial_number'));
        $this->assertSame(1, EimzoCertificate::count());
        $this->assertSame(1, EimzoSignature::count());
        $this->assertNotNull(EimzoChallenge::where('challenge', 'DOC123')->first()->used_at);
    }

    public function test_auth_complete_does_not_use_uid_as_an_implicit_user_lookup_fallback(): void
    {
        Schema::table('users', function ($table) {
            $table->string('uid')->nullable()->index();
        });
        TestUser::create(['uid' => '400000000', 'name' => 'UID Match', 'email' => 'uid-mobile@example.test']);

        $this->mockServer([
            'mobileAuth' => [
                'status' => 1,
                'siteId' => '0001',
                'documentId' => 'DOCUIDONLY',
                'challange' => 'X',
            ],
            'mobileAuthenticate' => [
                'status' => 1,
                'subjectCertificateInfo' => [
                    'serialNumber' => 'UIDMOBILE123',
                    'subjectName' => [
                        'CN' => 'UID Mobile User',
                        'UID' => '400000000',
                    ],
                    'validFrom' => '2024-01-01 00:00:00',
                    'validTo' => '2030-01-01 00:00:00',
                ],
            ],
        ]);

        $this->postJson('/eimzo/mobile/auth/start')->assertOk();

        $response = $this->postJson('/eimzo/mobile/auth/complete', [
            'document_id' => 'DOCUIDONLY',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('status'));
        $this->assertFalse((bool) $response->json('authenticated'));

        $certificate = EimzoCertificate::first();
        $this->assertSame('UIDMOBILE123', $certificate->serial_number);
        $this->assertSame('400000000', $certificate->uid);
        $this->assertNull($certificate->user_id);
    }

    public function test_auth_complete_rejects_unknown_document_id(): void
    {
        $this->mockServer([]);

        $r = $this->postJson('/eimzo/mobile/auth/complete', [
            'document_id' => 'unknown',
        ]);

        $r->assertStatus(422);
        $this->assertSame(-1, $r->json('status'));
    }

    public function test_sign_complete_stores_timestamped_pkcs7(): void
    {
        Config::set('eimzo.local_parse', false);
        $this->mockServer([
            'mobileSign' => [
                'status' => 1, 'siteId' => '0001', 'documentId' => 'SIGNDOC',
            ],
            'mobileVerify' => [
                'status' => 1,
                'subjectCertificateInfo' => [
                    'serialNumber' => 'CCDD',
                    'subjectName' => ['CN' => 'Signer', '1.2.860.3.16.1.1' => '111'],
                ],
                'verificationInfo' => [
                    'policyIdentifiers' => ['1.2.3'],
                    'signingTime' => '2026-05-01 10:00:00',
                    'timestampedTime' => '2026-05-01 10:00:05',
                ],
                'pkcs7Attached' => 'PKCS7-WITH-TS',
            ],
        ]);

        $this->postJson('/eimzo/mobile/sign/start')->assertOk();

        $r = $this->postJson('/eimzo/mobile/sign/complete', [
            'document_id' => 'SIGNDOC',
            'document' => base64_encode('hello world'),
            'document_name' => 'invoice.json',
            'document_type' => 'invoice',
        ]);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $sig = EimzoSignature::first();
        $this->assertSame('PKCS7-WITH-TS', $sig->pkcs7_with_timestamp);
        $this->assertSame('invoice', $sig->document_type);
        $this->assertSame('invoice.json', $sig->document_name);
        $this->assertNotNull($sig->signed_at);
        $this->assertNotNull($sig->timestamp_at);
        $this->assertContains('1.2.3', $sig->meta['policy_identifiers']);
        $this->assertSame('SIGNDOC', $sig->meta['mobile_document_id']);
    }

    public function test_upload_relays_raw_body_to_server(): void
    {
        $captured = [];
        $this->app->singleton(EimzoServerClient::class, function () use (&$captured) {
            return new class($captured) extends EimzoServerClient {
                public $captured;
                public function __construct(&$captured)
                {
                    $this->captured = &$captured;
                }
                public function mobileUpload(string $rawBody, array $query = [], ?string $clientIp = null): array
                {
                    $this->captured['body'] = $rawBody;
                    $this->captured['query'] = $query;
                    return ['status' => 1];
                }
            };
        });

        $r = $this->call(
            'POST',
            '/eimzo/mobile/upload?DocumentID=ABC&SerialNumber=123',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'BASE64-PKCS7'
        );

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
    }

    /**
     * Replace EimzoServerClient with a stub that returns the given payloads
     * for each method name.
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
                public function mobileAuth(?string $clientIp = null): array
                {
                    return $this->methods['mobileAuth'] ?? ['status' => -9];
                }
                public function mobileSign(?string $clientIp = null): array
                {
                    return $this->methods['mobileSign'] ?? ['status' => -9];
                }
                public function mobileStatus(string $documentId, ?string $clientIp = null): array
                {
                    return $this->methods['mobileStatus'] ?? ['status' => 2];
                }
                public function mobileAuthenticate(string $documentId, ?string $clientIp = null): array
                {
                    return $this->methods['mobileAuthenticate'] ?? ['status' => -9];
                }
                public function mobileVerify(string $documentId, string $document, ?string $clientIp = null): array
                {
                    return $this->methods['mobileVerify'] ?? ['status' => -9];
                }
                public function mobileUpload(string $rawBody, array $query = [], ?string $clientIp = null): array
                {
                    return $this->methods['mobileUpload'] ?? ['status' => 1];
                }
            };
        });
    }
}
