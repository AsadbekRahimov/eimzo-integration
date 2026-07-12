<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use AsadbekRahimov\EimzoIntegration\Exceptions\ChallengeExpiredException;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;

class EimzoAuthService
{
    /**
     * @var EimzoServerClient
     */
    private $server;

    /**
     * @var Pkcs7Parser
     */
    private $parser;

    /**
     * @var AuthFactory
     */
    private $auth;

    public function __construct(EimzoServerClient $server, Pkcs7Parser $parser, AuthFactory $auth)
    {
        $this->server = $server;
        $this->parser = $parser;
        $this->auth = $auth;
    }

    /**
     * Issue a fresh challenge to be signed by the client.
     */
    public function issueChallenge(Request $request): EimzoChallenge
    {
        $payload = $this->server->challenge($request->ip());
        $challenge = $payload['challenge'] ?? null;
        if (! is_string($challenge) || $challenge === '') {
            throw new EimzoServerException('E-IMZO-SERVER did not return a challenge', $payload);
        }

        return EimzoChallenge::issue(
            'auth',
            $request->ip(),
            substr((string) $request->userAgent(), 0, 512),
            ['server_payload' => $payload],
            $challenge
        );
    }

    /**
     * Verify a signed challenge and (when configured) log the user in.
     *
     * @return array{
     *   challenge: EimzoChallenge,
     *   certificate: EimzoCertificate,
     *   signature: EimzoSignature,
     *   user: Authenticatable|null,
     *   payload: array
     * }
     */
    public function verifyChallenge(string $challenge, string $pkcs7Base64, Request $request): array
    {
        $row = EimzoChallenge::where('challenge', $challenge)->where('purpose', 'auth')->first();
        if (! $row) {
            throw new VerificationFailedException('Unknown challenge');
        }
        if ($row->isUsed()) {
            throw new ChallengeExpiredException('Challenge already used');
        }
        if ($row->isExpired()) {
            throw new ChallengeExpiredException('Challenge expired');
        }

        $payload = $this->server->authenticate($pkcs7Base64, $request->ip());
        if (($payload['status'] ?? null) !== 1) {
            throw new VerificationFailedException(
                $payload['message'] ?? 'Authentication rejected by E-IMZO-SERVER',
                $payload
            );
        }

        // E-IMZO-SERVER returns the actual signed challenge inside payload.subjectName/etc.
        // The signed bytes must equal the issued challenge.
        $signed = $payload['payload']['challenge'] ?? null;
        if (is_string($signed) && $signed !== '' && $signed !== $challenge) {
            throw new VerificationFailedException('Challenge mismatch in PKCS#7', $payload);
        }

        // Claim the challenge atomically before persisting anything: of two
        // concurrent requests replaying the same signed challenge only one
        // can win the conditional UPDATE, so only one login ever succeeds.
        if (! $row->markUsed()) {
            throw new ChallengeExpiredException('Challenge already used');
        }

        $info = config('eimzo.local_parse', true)
            ? $this->parser->parseSigner($pkcs7Base64)
            : [];

        $info = $this->mergeServerInfo($info, $this->serverCertificateInfo($payload));

        $user = $this->resolveUser($info);

        $certificate = EimzoCertificate::upsertFromSigner($info, $user ? $user->getAuthIdentifier() : null);
        $certificate->forceFill([
            'last_verify_payload' => $payload,
            'last_verified_at' => now(),
        ])->save();

        $signature = EimzoSignature::create([
            'user_id' => $user ? $user->getAuthIdentifier() : null,
            'certificate_id' => $certificate->id,
            'document_type' => 'auth-challenge',
            'document_name' => 'challenge:' . $challenge,
            'document_size' => strlen($challenge),
            'document_hash' => hash('sha256', $challenge),
            'pkcs7' => $pkcs7Base64,
            'detached' => false,
            'verification_status' => EimzoSignature::STATUS_VALID,
            'verification_payload' => $payload,
            'verified_at' => now(),
            'signed_at' => now(),
        ]);

        if ($user instanceof Authenticatable) {
            $this->auth->guard(config('eimzo.auth.guard', 'web'))->login($user, true);

            if ($user instanceof Model) {
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
        }

        return [
            'challenge' => $row,
            'certificate' => $certificate,
            'signature' => $signature,
            'user' => $user,
            'payload' => $payload,
        ];
    }

    public function logout(?string $guard = null): void
    {
        $this->auth->guard($guard ?? config('eimzo.auth.guard', 'web'))->logout();
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

        $payload = [
            $column => $value,
            'name' => $info['cn'] ?? $value,
            'email' => $info['email'] ?? ($value . '@eimzo.local'),
            'password' => bcrypt(bin2hex(random_bytes(16))),
        ];
        if (Schema::hasColumn((new $modelClass)->getTable(), 'tin')) {
            $payload['tin'] = $info['tin'] ?? null;
        }
        if (Schema::hasColumn((new $modelClass)->getTable(), 'pinfl')) {
            $payload['pinfl'] = $info['pinfl'] ?? null;
        }

        return $modelClass::query()->create($payload);
    }

    /**
     * Pick a safe user lookup column. UID is intentionally not an implicit
     * fallback: in Uzbek E-IMZO certificates it is a separate certificate
     * identifier and can attach a signer to the wrong account. Developers can
     * still opt into it explicitly via EIMZO_USER_LOOKUP_COLUMN=uid.
     *
     * @return array{0:string,1:string}|null
     */
    private function resolveLookupCandidate(string $table, string $configuredColumn, array $info): ?array
    {
        $configuredColumn = trim($configuredColumn);
        $candidates = [];

        if ($configuredColumn !== '') {
            $candidates[] = $configuredColumn;
        }

        foreach (['pinfl', 'tin', 'serial_number'] as $fallback) {
            if ($fallback !== $configuredColumn) {
                $candidates[] = $fallback;
            }
        }

        foreach ($candidates as $column) {
            $value = $info[$column] ?? null;
            if (! is_string($value) || $value === '' || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            return [$column, $value];
        }

        return null;
    }

    /**
     * Server may return upstream cert info; merge missing fields into the
     * locally-parsed map so downstream code sees a unified structure.
     */
    private function mergeServerInfo(array $info, array $serverPayload): array
    {
        $map = [
            'CN' => 'cn',
            'TIN' => 'tin',
            'INN' => 'tin',
            'PINFL' => 'pinfl',
            '1.2.860.3.16.1.1' => 'tin',
            '1.2.860.3.16.1.2' => 'pinfl',
            'UID' => 'uid',
            'O' => 'o',
            'T' => 't',
            'serialNumber' => 'serial_number',
            'subjectName' => 'subject_dn',
            'issuerName' => 'issuer_dn',
            'validFrom' => 'valid_from',
            'validTo' => 'valid_to',
        ];
        foreach ($map as $from => $to) {
            if (empty($info[$to]) && ! empty($serverPayload[$from])) {
                $info[$to] = $serverPayload[$from];
            }
        }

        // Keep serials in the same canonical form the local parser and the
        // mobile flow use, otherwise the same certificate would upsert into
        // two different rows depending on which side supplied the serial.
        if (! empty($info['serial_number']) && is_string($info['serial_number'])) {
            $info['serial_number'] = strtoupper(ltrim($info['serial_number'], '0')) ?: '0';
        }

        return $info;
    }

    private function serverCertificateInfo(array $payload): array
    {
        $info = $payload['payload'] ?? [];
        if (isset($payload['subjectCertificateInfo'])) {
            $info = array_merge($info, (array) $payload['subjectCertificateInfo']);
        }

        if (isset($info['subjectName']) && is_array($info['subjectName'])) {
            $info['subject_dn'] = collect($info['subjectName'])
                ->map(fn ($value, $key) => $key . '=' . $value)
                ->implode(',');

            foreach ($info['subjectName'] as $key => $value) {
                $info[$key] = $value;
            }

            unset($info['subjectName']);
        }

        return $info;
    }
}
