<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;

class EimzoSignService
{
    /**
     * @var EimzoServerClient
     */
    private $server;

    /**
     * @var Pkcs7Parser
     */
    private $parser;

    public function __construct(EimzoServerClient $server, Pkcs7Parser $parser)
    {
        $this->server = $server;
        $this->parser = $parser;
    }

    /**
     * Persist a freshly produced PKCS#7 signature, optionally requesting a
     * timestamp from the TSA, then run server-side verification.
     *
     * @param  array{
     *   pkcs7: string,
     *   data?: string|null,
     *   document_type?: string|null,
     *   document_name?: string|null,
     *   detached?: bool,
     *   attach_timestamp?: bool|null,
     *   meta?: array|null,
     *   signable?: Model|null,
     *   user_id?: int|null,
     * } $input
     */
    public function store(array $input, Request $request): EimzoSignature
    {
        $pkcs7 = (string) ($input['pkcs7'] ?? '');
        if ($pkcs7 === '') {
            throw new \InvalidArgumentException('pkcs7 is required');
        }

        $detached = (bool) ($input['detached'] ?? false);
        $data = $input['data'] ?? null;

        if ($detached && (! is_string($data) || $data === '')) {
            throw new \InvalidArgumentException('detached signatures require base64-encoded data');
        }

        $attachTimestamp = $input['attach_timestamp'] ?? config('eimzo.sign.attach_timestamp', true);

        $pkcs7WithTs = null;
        $timestampAt = null;
        if ($attachTimestamp) {
            // A TSA outage must not fail the signing request: the unstamped
            // PKCS#7 is still stored and verified, and the error is recorded
            // in meta.timestamp_error so it can be re-stamped later.
            try {
                $tsResp = $this->server->timestampPkcs7($pkcs7, $request->ip());
            } catch (\AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException $e) {
                $tsResp = ['status' => -2, 'message' => $e->getMessage()];
            }
            if (($tsResp['status'] ?? null) === 1) {
                // E-IMZO-SERVER returns the timestamped envelope at the top level:
                //   {"pkcs7b64":"...","timestampedSignerList":[...],"status":1}
                // Older builds nested it under "payload"; accept both for safety.
                $pkcs7WithTs = (string) (
                    $tsResp['pkcs7b64']
                    ?? $tsResp['pkcs7_64']
                    ?? $tsResp['pkcs7']
                    ?? $tsResp['payload']['pkcs7b64']
                    ?? $tsResp['payload']['pkcs7_64']
                    ?? $tsResp['payload']['pkcs7']
                    ?? ''
                );
                $pkcs7WithTs = $pkcs7WithTs ?: null;

                $tsTime = $tsResp['timestamp']
                    ?? $tsResp['timestampedSignerList'][0]['timestamp']
                    ?? $tsResp['payload']['timestamp']
                    ?? null;
                $timestampAt = $tsTime ? \Carbon\Carbon::parse($tsTime) : now();
            } else {
                // Don't fail the whole request just because TSA is offline -
                // record the failure in meta and continue with the unstamped pkcs7.
                $input['meta'] = array_merge($input['meta'] ?? [], [
                    'timestamp_error' => $tsResp['message'] ?? 'unknown',
                ]);
            }
        }

        $verifyResp = $detached
            ? $this->server->verifyDetached((string) $data, $pkcs7WithTs ?? $pkcs7, $request->ip())
            : $this->server->verifyAttached($pkcs7WithTs ?? $pkcs7, $request->ip());

        $status = ($verifyResp['status'] ?? null) === 1
            ? EimzoSignature::STATUS_VALID
            : EimzoSignature::STATUS_INVALID;

        $info = config('eimzo.local_parse', true) ? $this->parser->parseSigner($pkcs7) : [];
        $certificate = ! empty($info['serial_number'])
            ? EimzoCertificate::upsertFromSigner($info, $input['user_id'] ?? null)
            : null;

        // document_hash / document_size must describe bytes that were
        // actually verified. For detached mode that is the caller-supplied
        // data - the server checked the signature over exactly those bytes.
        // For attached mode it is the document embedded in the envelope, as
        // returned by the verify call; the caller's copy is never trusted
        // here, since it could differ from what was really signed.
        $rawData = null;
        if ($detached) {
            $rawData = $this->decodeBase64((string) $data);
        } else {
            $embedded = $verifyResp['pkcs7Info']['documentBase64'] ?? null;
            if (is_string($embedded) && $embedded !== '') {
                $rawData = $this->decodeBase64($embedded);
            }
        }

        $sig = new EimzoSignature();
        $sig->fill([
            'user_id' => $input['user_id'] ?? null,
            'certificate_id' => $certificate ? $certificate->id : null,
            'document_type' => $input['document_type'] ?? null,
            'document_name' => $input['document_name'] ?? null,
            'document_size' => $rawData !== null ? strlen($rawData) : null,
            'document_hash' => $rawData !== null ? hash('sha256', $rawData) : null,
            'pkcs7' => $pkcs7,
            'detached' => $detached,
            'pkcs7_with_timestamp' => $pkcs7WithTs,
            'signed_at' => now(),
            'timestamp_at' => $timestampAt,
            'verification_status' => $status,
            'verification_payload' => $verifyResp,
            'verified_at' => now(),
            'meta' => $input['meta'] ?? null,
        ]);

        $signable = $input['signable'] ?? null;
        if ($signable instanceof Model) {
            $sig->signable_type = $signable->getMorphClass();
            $sig->signable_id = $signable->getKey();
        }

        $sig->save();

        if ($disk = config('eimzo.sign.storage_disk')) {
            $path = config('eimzo.sign.storage_path', 'eimzo/signatures') . '/' . $sig->id . '.p7';
            try {
                Storage::disk($disk)->put($path, base64_decode($pkcs7WithTs ?? $pkcs7, true) ?: ($pkcs7WithTs ?? $pkcs7));
                $sig->forceFill(['pkcs7_path' => $path])->save();
            } catch (\Throwable $e) {
                // Storage isn't critical; keep DB copy.
            }
        }

        if ($status !== EimzoSignature::STATUS_VALID) {
            throw new VerificationFailedException(
                $verifyResp['message'] ?? 'PKCS#7 verification failed',
                ['signature_id' => $sig->id, 'response' => $verifyResp]
            );
        }

        return $sig;
    }

    /**
     * Base64-decode tolerating the line wrapping / whitespace that real-world
     * clients produce, while still rejecting non-base64 garbage (a plain
     * non-strict decode would silently "decode" it into junk bytes).
     */
    private function decodeBase64(string $value): ?string
    {
        $value = (string) preg_replace('/\s+/', '', $value);
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
