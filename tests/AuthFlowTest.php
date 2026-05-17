<?php

namespace AsadbekRahimov\EimzoIntegration\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;
use AsadbekRahimov\EimzoIntegration\Tests\Support\Pkcs7TestFactory;
use AsadbekRahimov\EimzoIntegration\Tests\TestCase;

require_once __DIR__ . '/Support/Pkcs7TestFactory.php';

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_endpoint_issues_server_challenge_and_stores_it_locally(): void
    {
        $this->mockServerChallenge('server-issued-challenge');

        $r = $this->getJson('/eimzo/auth/challenge');

        $r->assertOk();
        $r->assertJsonStructure(['status', 'challenge', 'expires_at', 'ttl']);
        $this->assertSame(1, $r->json('status'));
        $this->assertSame('server-issued-challenge', $r->json('challenge'));
        $this->assertSame(1, EimzoChallenge::count());
    }

    public function test_verify_rejects_unknown_challenge(): void
    {
        $this->mockServerSuccess();

        $r = $this->postJson('/eimzo/auth/verify', [
            'challenge' => 'no-such-challenge',
            'pkcs7' => 'AA==',
        ]);

        $r->assertStatus(422);
        $this->assertSame(-1, $r->json('status'));
    }

    public function test_verify_marks_challenge_used_and_persists_certificate(): void
    {
        $this->mockServerSuccess();

        $issued = $this->getJson('/eimzo/auth/challenge')->json('challenge');

        $pkcs7 = $this->loadSamplePkcs7();
        $r = $this->postJson('/eimzo/auth/verify', [
            'challenge' => $issued,
            'pkcs7' => $pkcs7,
        ]);

        $r->assertOk();
        $this->assertSame(1, $r->json('status'));
        $this->assertSame(1, EimzoSignature::count());
        $this->assertSame(1, EimzoCertificate::count());
        $this->assertNotNull(EimzoChallenge::where('challenge', $issued)->first()->used_at);
    }

    public function test_verify_accepts_eimzo_server_subject_certificate_info_shape(): void
    {
        Config::set('eimzo.local_parse', false);
        $this->mockServerSuccess([
            'status' => 1,
            'message' => '',
            'subjectCertificateInfo' => [
                'serialNumber' => 'ABCD1234',
                'subjectName' => [
                    'CN' => 'Test User',
                    '1.2.860.3.16.1.1' => '300000000',
                    '1.2.860.3.16.1.2' => '12345678901234',
                ],
            ],
        ]);

        $issued = $this->getJson('/eimzo/auth/challenge')->json('challenge');

        $response = $this->postJson('/eimzo/auth/verify', [
            'challenge' => $issued,
            'pkcs7' => 'AA==',
        ]);

        $response->assertOk();
        $certificate = EimzoCertificate::first();
        $this->assertSame('ABCD1234', $certificate->serial_number);
        $this->assertSame('Test User', $certificate->cn);
        $this->assertSame('300000000', $certificate->tin);
        $this->assertSame('12345678901234', $certificate->pinfl);
    }

    public function test_verify_does_not_use_uid_as_an_implicit_user_lookup_fallback(): void
    {
        Config::set('eimzo.local_parse', false);
        Schema::table('users', function ($table) {
            $table->string('uid')->nullable()->index();
        });
        TestUser::create(['uid' => '400000000', 'name' => 'UID Match', 'email' => 'uid@example.test']);

        $this->mockServerSuccess([
            'status' => 1,
            'message' => '',
            'subjectCertificateInfo' => [
                'serialNumber' => 'UIDONLY1234',
                'subjectName' => [
                    'CN' => 'UID Only User',
                    'UID' => '400000000',
                ],
            ],
        ]);

        $issued = $this->getJson('/eimzo/auth/challenge')->json('challenge');

        $response = $this->postJson('/eimzo/auth/verify', [
            'challenge' => $issued,
            'pkcs7' => 'AA==',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('status'));
        $this->assertFalse((bool) $response->json('authenticated'));

        $certificate = EimzoCertificate::first();
        $this->assertSame('UIDONLY1234', $certificate->serial_number);
        $this->assertSame('400000000', $certificate->uid);
        $this->assertNull($certificate->user_id);
    }

    private function mockServerSuccess(?array $authPayload = null): void
    {
        $this->app->singleton(EimzoServerClient::class, function () use ($authPayload) {
            return new class($authPayload) extends EimzoServerClient {
                private $authPayload;

                public function __construct(?array $authPayload = null)
                {
                    $this->authPayload = $authPayload;
                }

                public function challenge(?string $ip = null): array
                {
                    return ['status' => 1, 'challenge' => 'mock-challenge'];
                }
                public function authenticate(string $pkcs7, ?string $ip = null): array
                {
                    return $this->authPayload ?? ['status' => 1, 'message' => '', 'payload' => []];
                }
            };
        });
    }

    private function mockServerChallenge(string $challenge): void
    {
        $this->app->singleton(EimzoServerClient::class, function () use ($challenge) {
            return new class($challenge) extends EimzoServerClient {
                private $challenge;

                public function __construct(string $challenge)
                {
                    $this->challenge = $challenge;
                }

                public function challenge(?string $ip = null): array
                {
                    return ['status' => 1, 'challenge' => $this->challenge];
                }
            };
        });
    }

    private function loadSamplePkcs7(): string
    {
        return Pkcs7TestFactory::attached([
            'CN' => 'Test',
            'O' => 'Test',
            'UID' => '1',
            'INN' => '2',
        ], 'authchallenge');
    }
}
