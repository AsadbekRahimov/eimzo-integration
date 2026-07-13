<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Support\SignerInfo;

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

        $documentId = is_string($payload['documentId'] ?? null)
            ? trim($payload['documentId'])
            : '';
        $siteId = $this->resolveSiteId($payload);
        // Upstream protocol uses "challange" (sic). Accept both.
        $challengeValue = $payload['challenge'] ?? ($payload['challange'] ?? null);
        $challenge = is_string($challengeValue) ? $challengeValue : '';

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

        $documentId = is_string($payload['documentId'] ?? null)
            ? trim($payload['documentId'])
            : '';
        $siteId = $this->resolveSiteId($payload);

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
        if (empty($info['serial_number'])) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned successful mobile authentication without a signer certificate',
                $payload
            );
        }

        // Claim the DocumentID atomically before persisting anything so a
        // concurrent replay can only ever produce one successful login.
        if (! $row->markUsed()) {
            throw new VerificationFailedException('Mobile DocumentID already used');
        }

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

        $documentBase64 = (string) preg_replace('/\s+/', '', $documentBase64);
        $rawData = base64_decode($documentBase64, true);
        if ($documentBase64 === '' || $rawData === false) {
            throw new \InvalidArgumentException('document must be non-empty valid base64');
        }

        $payload = $this->server->mobileVerify($documentId, $documentBase64, $request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new VerificationFailedException(
                $payload['message'] ?? 'mobile signature verification failed',
                $payload
            );
        }

        $pkcs7Attached = is_string($payload['pkcs7Attached'] ?? null)
            ? trim($payload['pkcs7Attached'])
            : '';
        if ($pkcs7Attached === '') {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned successful mobile verification without pkcs7Attached',
                $payload
            );
        }

        // Same one-shot claim as completeAuth: only one of two concurrent
        // completions may store a signature for this DocumentID.
        if (! $row->markUsed()) {
            throw new VerificationFailedException('Mobile DocumentID already used');
        }

        $info = $this->subjectInfo($payload);
        $certificate = ! empty($info['serial_number'])
            ? EimzoCertificate::upsertFromSigner($info, $context['user_id'] ?? null)
            : null;
        if ($certificate) {
            $certificate->forceFill([
                'last_verify_payload' => $payload,
                'last_verified_at' => now(),
            ])->save();
        }

        $verificationInfo = is_array($payload['verificationInfo'] ?? null)
            ? $payload['verificationInfo']
            : [];

        $sig = EimzoSignature::create([
            'user_id' => $context['user_id'] ?? null,
            'certificate_id' => $certificate ? $certificate->id : null,
            'document_type' => $context['document_type'] ?? 'mobile-sign',
            'document_name' => $context['document_name'] ?? ('mobile-sign:' . $documentId),
            'document_size' => strlen($rawData),
            'document_hash' => hash('sha256', $rawData),
            'pkcs7' => null,
            'detached' => false,
            'pkcs7_with_timestamp' => $pkcs7Attached,
            'signed_at' => $this->parseTime($verificationInfo['signingTime'] ?? null),
            'timestamp_at' => $this->parseTime($verificationInfo['timestampedTime'] ?? null),
            'verification_status' => EimzoSignature::STATUS_VALID,
            'verification_payload' => $payload,
            'verified_at' => now(),
            'meta' => array_merge($context['meta'] ?? [], [
                'mobile_document_id' => $documentId,
                'policy_identifiers' => is_array($verificationInfo['policyIdentifiers'] ?? null)
                    ? $verificationInfo['policyIdentifiers']
                    : [],
            ]),
        ]);

        if ($sig->pkcs7_with_timestamp && ($disk = config('eimzo.sign.storage_disk'))) {
            $path = config('eimzo.sign.storage_path', 'eimzo/signatures') . '/' . $sig->id . '.p7';
            try {
                $binary = base64_decode($sig->pkcs7_with_timestamp, true);
                Storage::disk($disk)->put($path, $binary === false ? $sig->pkcs7_with_timestamp : $binary);
                $sig->forceFill(['pkcs7_path' => $path])->save();
            } catch (\Throwable $e) {
                // The verified DB copy remains authoritative when storage is unavailable.
            }
        }

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
        return SignerInfo::fromServerPayload($payload);
    }

    private function resolveUser(array $info): ?Authenticatable
    {
        $modelClass = config('eimzo.auth.user_model');
        $configuredColumn = (string) config('eimzo.auth.user_lookup_column', 'tin');
        if (! $modelClass || ! class_exists($modelClass)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;
        $lookup = $this->resolveLookupCandidate($model->getTable(), $configuredColumn, $info);
        if ($lookup === null) {
            return null;
        }
        [$column, $value] = $lookup;

        /** @var \Illuminate\Database\Eloquent\Model $modelClass */
        $user = $modelClass::query()->where($column, $value)->first();
        if ($user) {
            return $user;
        }

        if (! config('eimzo.auth.auto_register', false)) {
            return null;
        }

        $table = $model->getTable();
        $payload = [$column => $value];
        if (Schema::hasColumn($table, 'name')) {
            $payload['name'] = $info['cn'] ?? $value;
        }
        if (Schema::hasColumn($table, 'email')) {
            $payload['email'] = $info['email'] ?? ($value . '@eimzo.local');
        }
        if (Schema::hasColumn($table, 'password')) {
            $payload['password'] = bcrypt(bin2hex(random_bytes(16)));
        }
        if (Schema::hasColumn($table, 'tin')) {
            $payload['tin'] = $info['tin'] ?? null;
        }
        if (Schema::hasColumn($table, 'pinfl')) {
            $payload['pinfl'] = $info['pinfl'] ?? null;
        }

        return $modelClass::query()->create($payload);
    }

    private function resolveSiteId(array $payload): string
    {
        $serverSiteId = is_string($payload['siteId'] ?? null)
            ? trim($payload['siteId'])
            : '';
        $configuredSiteId = trim((string) config('eimzo.mobile.site_id', ''));

        if (
            $serverSiteId !== ''
            && $configuredSiteId !== ''
            && strcasecmp($serverSiteId, $configuredSiteId) !== 0
        ) {
            throw new EimzoServerException(
                'Configured E-IMZO mobile SiteID does not match E-IMZO-SERVER',
                ['configured_site_id' => $configuredSiteId, 'server_payload' => $payload]
            );
        }

        $siteId = $serverSiteId !== '' ? $serverSiteId : $configuredSiteId;
        if ($siteId === '') {
            throw new EimzoServerException('E-IMZO mobile SiteID is not configured', $payload);
        }

        return $siteId;
    }

    /**
     * Pick a safe user lookup column. UID is intentionally not an implicit
     * fallback; it is only used when configured explicitly.
     *
     * @return array{0:string,1:string}|null
     */
    private function resolveLookupCandidate(string $table, string $configuredColumn, array $info): ?array
    {
        $configuredColumn = trim($configuredColumn);
        $candidates = [];

        if ($configuredColumn !== '') {
            $candidates[] = [
                $configuredColumn,
                $this->signerFieldForUserColumn($configuredColumn),
            ];
        }

        foreach ([
            ['pinfl', 'pinfl'],
            ['tin', 'tin'],
            ['inn', 'tin'],
            ['eimzo_serial_number', 'serial_number'],
            ['serial_number', 'serial_number'],
        ] as $fallback) {
            if ($fallback[0] !== $configuredColumn) {
                $candidates[] = $fallback;
            }
        }

        foreach ($candidates as [$column, $signerField]) {
            $value = $info[$signerField] ?? null;
            if (! is_string($value) || $value === '' || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            return [$column, $value];
        }

        return null;
    }

    private function signerFieldForUserColumn(string $column): string
    {
        if ($column === 'inn') {
            return 'tin';
        }
        if ($column === 'eimzo_serial_number') {
            return 'serial_number';
        }

        return $column;
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
