<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;

/**
 * High-level wrapper around the mobile API of E-IMZO-SERVER.
 *
 * Two flows are supported:
 *
 *   AUTH:
 *     1. issueAuth()                                 -> {siteId, documentId, challenge}
 *     2. mobile app posts PKCS#7 to /frontend/mobile/upload
 *     3. pollStatus($documentId) -> 1 when ready
 *     4. completeAuth($documentId) -> certificate + user + login
 *
 *   SIGN:
 *     1. issueSign() -> {siteId, documentId} (you compute hash on backend)
 *     2. mobile app posts PKCS#7 to /frontend/mobile/upload
 *     3. pollStatus($documentId) -> 1 when ready
 *     4. completeSign($documentId, $document) -> stored EimzoSignature
 */
class EimzoMobileService
{
    /**
     * @var EimzoServerClient
     */
    private $server;

    /**
     * @var AuthFactory
     */
    private $auth;

    public function __construct(EimzoServerClient $server, AuthFactory $auth)
    {
        $this->server = $server;
        $this->auth = $auth;
    }

    /**
     * Issue a mobile-auth DocumentID + challenge and persist the challenge
     * locally (purpose=mobile-auth) for one-time-use enforcement.
     *
     * @return array{
     *   document_id: string,
     *   site_id: string,
     *   challenge: string,
     *   server_payload: array
     * }
     */
    public function issueAuth(Request $request): array
    {
        $payload = $this->server->mobileAuth($request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new EimzoServerException(
                $payload['message'] ?? 'mobile/auth rejected',
                $payload
            );
        }

        $documentId = (string) ($payload['documentId'] ?? '');
        $siteId = (string) ($payload['siteId'] ?? '');
        // Upstream protocol uses "challange" (sic). Accept both.
        $challenge = (string) ($payload['challenge'] ?? $payload['challange'] ?? '');

        if ($documentId === '' || $challenge === '') {
            throw new EimzoServerException(
                'E-IMZO-SERVER /frontend/mobile/auth returned empty fields',
                $payload
            );
        }

        EimzoChallenge::issue(
            'mobile-auth',
            $request->ip(),
            substr((string) $request->userAgent(), 0, 512),
            ['site_id' => $siteId, 'document_id' => $documentId, 'server_payload' => $payload],
            $documentId
        );

        return [
            'document_id' => $documentId,
            'site_id' => $siteId,
            'challenge' => $challenge,
            'server_payload' => $payload,
        ];
    }

    /**
     * Issue a mobile-sign DocumentID. The original document and its hash must
     * be tracked by the caller (the backend), since the mobile system only
     * sees the GOST hash.
     *
     * @return array{
     *   document_id: string,
     *   site_id: string,
     *   server_payload: array
     * }
     */
    public function issueSign(Request $request): array
    {
        $payload = $this->server->mobileSign($request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new EimzoServerException(
                $payload['message'] ?? 'mobile/sign rejected',
                $payload
            );
        }

        $documentId = (string) ($payload['documentId'] ?? '');
        $siteId = (string) ($payload['siteId'] ?? '');

        if ($documentId === '') {
            throw new EimzoServerException(
                'E-IMZO-SERVER /frontend/mobile/sign returned empty documentId',
                $payload
            );
        }

        EimzoChallenge::issue(
            'mobile-sign',
            $request->ip(),
            substr((string) $request->userAgent(), 0, 512),
            ['site_id' => $siteId, 'document_id' => $documentId, 'server_payload' => $payload],
            $documentId
        );

        return [
            'document_id' => $documentId,
            'site_id' => $siteId,
            'server_payload' => $payload,
        ];
    }

    /**
     * Proxy /frontend/mobile/status. Returns the raw server payload.
     */
    public function pollStatus(string $documentId, Request $request): array
    {
        return $this->server->mobileStatus($documentId, $request->ip());
    }

    /**
     * Forward an incoming PKCS#7 from the ID-CARD app to E-IMZO-SERVER.
     * The mobile system posts to the client-domain UPLOAD URL; this method
     * relays the body and the original query string.
     */
    public function relayUpload(string $rawBody, array $query, Request $request): array
    {
        return $this->server->mobileUpload($rawBody, $query, $request->ip());
    }

    /**
     * Finalise mobile authentication. Marks the challenge used, upserts
     * EimzoCertificate, creates an EimzoSignature row, optionally logs the
     * user in and updates user fields.
     *
     * @return array{
     *   challenge: EimzoChallenge,
     *   certificate: EimzoCertificate,
     *   signature: EimzoSignature,
     *   user: Authenticatable|null,
     *   payload: array
     * }
     */
    public function completeAuth(string $documentId, Request $request): array
    {
        $row = $this->loadChallenge($documentId, 'mobile-auth');

        $payload = $this->server->mobileAuthenticate($documentId, $request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new VerificationFailedException(
                $payload['message'] ?? 'mobile authentication rejected',
                $payload
            );
        }

        $info = $this->subjectInfo($payload);
        $user = $this->resolveUser($info);

        $certificate = EimzoCertificate::upsertFromSigner(
            $info,
            $user ? $user->getAuthIdentifier() : null
        );
        $certificate->forceFill([
            'last_verify_payload' => $payload,
            'last_verified_at' => now(),
        ])->save();

        $signature = EimzoSignature::create([
            'user_id' => $user ? $user->getAuthIdentifier() : null,
            'certificate_id' => $certificate->id,
            'document_type' => 'mobile-auth',
            'document_name' => 'mobile-auth:' . $documentId,
            'document_size' => null,
            'document_hash' => null,
            'pkcs7' => null,
            'detached' => true,
            'verification_status' => EimzoSignature::STATUS_VALID,
            'verification_payload' => $payload,
            'verified_at' => now(),
            'signed_at' => now(),
        ]);

        $row->markUsed();

        if ($user instanceof Authenticatable) {
            $this->auth->guard(config('eimzo.auth.guard', 'web'))->login($user, true);
            $this->touchUser($user, $info);
        }

        return [
            'challenge' => $row,
            'certificate' => $certificate,
            'signature' => $signature,
            'user' => $user,
            'payload' => $payload,
        ];
    }

    /**
     * Finalise mobile signing. $documentBase64 is the original document the
     * caller decided to sign (must match the hash that was put into the QR /
     * deeplink). Stores the timestamped pkcs7Attached returned by the server.
     *
     * @return EimzoSignature
     */
    public function completeSign(
        string $documentId,
        string $documentBase64,
        Request $request,
        array $context = []
    ): EimzoSignature {
        $row = $this->loadChallenge($documentId, 'mobile-sign');

        $payload = $this->server->mobileVerify($documentId, $documentBase64, $request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new VerificationFailedException(
                $payload['message'] ?? 'mobile signature verification failed',
                $payload
            );
        }

        $info = $this->subjectInfo($payload);
        $certificate = ! empty($info['serial_number'])
            ? EimzoCertificate::upsertFromSigner($info, $context['user_id'] ?? null)
            : null;

        $rawData = base64_decode($documentBase64, true);
        $sig = EimzoSignature::create([
            'user_id' => $context['user_id'] ?? null,
            'certificate_id' => $certificate ? $certificate->id : null,
            'document_type' => $context['document_type'] ?? 'mobile-sign',
            'document_name' => $context['document_name'] ?? ('mobile-sign:' . $documentId),
            'document_size' => $rawData !== false ? strlen($rawData) : null,
            'document_hash' => $rawData !== false ? hash('sha256', $rawData) : null,
            'pkcs7' => null,
            'detached' => false,
            'pkcs7_with_timestamp' => (string) ($payload['pkcs7Attached'] ?? '') ?: null,
            'signed_at' => $this->parseTime($payload['verificationInfo']['signingTime'] ?? null),
            'timestamp_at' => $this->parseTime($payload['verificationInfo']['timestampedTime'] ?? null),
            'verification_status' => EimzoSignature::STATUS_VALID,
            'verification_payload' => $payload,
            'verified_at' => now(),
            'meta' => array_merge($context['meta'] ?? [], [
                'mobile_document_id' => $documentId,
                'policy_identifiers' => $payload['verificationInfo']['policyIdentifiers'] ?? [],
            ]),
        ]);

        $row->markUsed();

        return $sig;
    }

    private function loadChallenge(string $documentId, string $purpose): EimzoChallenge
    {
        $row = EimzoChallenge::where('challenge', $documentId)
            ->where('purpose', $purpose)
            ->first();
        if (! $row) {
            throw new VerificationFailedException('Unknown mobile DocumentID');
        }
        if ($row->isUsed()) {
            throw new VerificationFailedException('Mobile DocumentID already used');
        }
        if ($row->isExpired()) {
            throw new VerificationFailedException('Mobile DocumentID expired');
        }
        return $row;
    }

    private function subjectInfo(array $payload): array
    {
        $cert = $payload['subjectCertificateInfo'] ?? [];
        $info = [
            'serial_number' => $cert['serialNumber'] ?? null,
            'subject_dn' => $cert['X500Name'] ?? null,
            'valid_from' => $this->parseTime($cert['validFrom'] ?? null),
            'valid_to' => $this->parseTime($cert['validTo'] ?? null),
        ];

        $sn = $cert['subjectName'] ?? [];
        if (is_array($sn)) {
            $info['cn'] = $sn['CN'] ?? null;
            $info['uid'] = $sn['UID'] ?? null;
            $info['pinfl'] = $sn['1.2.860.3.16.1.2'] ?? ($sn['PINFL'] ?? null);
            $info['tin'] = $sn['1.2.860.3.16.1.1'] ?? ($sn['TIN'] ?? ($sn['INN'] ?? null));
            $info['o'] = $sn['O'] ?? null;
            $info['t'] = $sn['T'] ?? ($sn['TITLE'] ?? null);
            $info['country'] = $sn['C'] ?? null;
            $info['email'] = $sn['EMAILADDRESS'] ?? ($sn['EMAIL'] ?? null);
        }

        if (! empty($info['serial_number']) && is_string($info['serial_number'])) {
            $info['serial_number'] = strtoupper(ltrim($info['serial_number'], '0')) ?: '0';
        }

        return $info;
    }

    private function resolveUser(array $info): ?Authenticatable
    {
        $modelClass = config('eimzo.auth.user_model');
        $column = config('eimzo.auth.user_lookup_column', 'tin');
        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        $value = $info[$column] ?? null;
        if (! is_string($value) || $value === '') {
            foreach (['pinfl', 'tin', 'uid', 'serial_number'] as $alt) {
                if (! empty($info[$alt])) {
                    $column = $alt;
                    $value = (string) $info[$alt];
                    break;
                }
            }
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model $modelClass */
        $user = $modelClass::query()->where($column, $value)->first();
        if ($user) {
            return $user;
        }

        if (! config('eimzo.auth.auto_register', false)) {
            return null;
        }

        $payload = [
            $column => $value,
            'name' => $info['cn'] ?? $value,
            'email' => $info['email'] ?? ($value . '@eimzo.local'),
            'password' => bcrypt(bin2hex(random_bytes(16))),
        ];
        $table = (new $modelClass)->getTable();
        if (Schema::hasColumn($table, 'tin')) {
            $payload['tin'] = $info['tin'] ?? null;
        }
        if (Schema::hasColumn($table, 'pinfl')) {
            $payload['pinfl'] = $info['pinfl'] ?? null;
        }

        return $modelClass::query()->create($payload);
    }

    private function touchUser(Authenticatable $user, array $info): void
    {
        if (! $user instanceof Model) {
            return;
        }
        $touch = [];
        $table = $user->getTable();
        if (Schema::hasColumn($table, 'eimzo_authenticated_at')) {
            $touch['eimzo_authenticated_at'] = now();
        }
        if (Schema::hasColumn($table, 'eimzo_serial_number')) {
            $touch['eimzo_serial_number'] = $info['serial_number'] ?? null;
        }
        if (Schema::hasColumn($table, 'eimzo_full_name')) {
            $touch['eimzo_full_name'] = $info['cn'] ?? null;
        }
        if ($touch) {
            $user->forceFill($touch)->save();
        }
    }

    /**
     * @return \Illuminate\Support\Carbon|null
     */
    private function parseTime($value)
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
