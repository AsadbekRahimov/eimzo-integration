<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Support\SignerInfo;

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

        $detached = array_key_exists('detached', $input)
            ? (bool) $input['detached']
            : strtolower((string) config('eimzo.sign.default_mode', 'attached')) === 'detached';
        $data = $input['data'] ?? null;

        if ($detached && (! is_string($data) || $data === '')) {
            throw new \InvalidArgumentException('detached signatures require base64-encoded data');
        }

        $detachedData = null;
        if ($detached) {
            $detachedData = $this->decodeBase64((string) $data);
            if ($detachedData === null) {
                throw new \InvalidArgumentException('data must be valid base64 for detached signatures');
            }
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
            } catch (EimzoServerException $e) {
                $tsResp = ['status' => -2, 'message' => $e->getMessage()];
            }
            if (($tsResp['status'] ?? null) === 1) {
                $tsPayload = is_array($tsResp['payload'] ?? null) ? $tsResp['payload'] : [];
                $timestampedSigners = is_array($tsResp['timestampedSignerList'] ?? null)
                    ? $tsResp['timestampedSignerList']
                    : [];
                $firstTimestampedSigner = is_array($timestampedSigners[0] ?? null)
                    ? $timestampedSigners[0]
                    : [];
                // E-IMZO-SERVER returns the timestamped envelope at the top level:
                //   {"pkcs7b64":"...","timestampedSignerList":[...],"status":1}
                // Older builds nested it under "payload"; accept both for safety.
                $pkcs7WithTs = $this->firstString([
                    $tsResp['pkcs7b64'] ?? null,
                    $tsResp['pkcs7_64'] ?? null,
                    $tsResp['pkcs7'] ?? null,
                    $tsPayload['pkcs7b64'] ?? null,
                    $tsPayload['pkcs7_64'] ?? null,
                    $tsPayload['pkcs7'] ?? null,
                ]);

                if ($pkcs7WithTs !== null) {
                    $tsTime = $tsResp['timestamp']
                        ?? $firstTimestampedSigner['timestamp']
                        ?? $tsPayload['timestamp']
                        ?? null;
                    try {
                        $timestampAt = $tsTime ? \Carbon\Carbon::parse($tsTime) : now();
                    } catch (\Throwable $e) {
                        $timestampAt = now();
                        $input['meta'] = array_merge($input['meta'] ?? [], [
                            'timestamp_warning' => 'E-IMZO-SERVER returned an invalid timestamp value',
                        ]);
                    }
                } else {
                    $input['meta'] = array_merge($input['meta'] ?? [], [
                        'timestamp_error' => 'E-IMZO-SERVER returned status=1 without timestamped PKCS#7',
                    ]);
                }
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
        $info = SignerInfo::merge($info, SignerInfo::fromServerPayload($verifyResp));
        $certificate = ! empty($info['serial_number'])
            ? EimzoCertificate::upsertFromSigner($info, $input['user_id'] ?? null)
            : null;
        if ($certificate) {
            $certificate->forceFill([
                'last_verify_payload' => $verifyResp,
                'last_verified_at' => now(),
            ])->save();
        }

        // document_hash / document_size must describe bytes that were
        // actually verified. For detached mode that is the caller-supplied
        // data - the server checked the signature over exactly those bytes.
        // For attached mode it is the document embedded in the envelope, as
        // returned by the verify call; the caller's copy is never trusted
        // here, since it could differ from what was really signed.
        $rawData = null;
        if ($detached) {
            $rawData = $detachedData;
        } else {
            $pkcs7Info = is_array($verifyResp['pkcs7Info'] ?? null) ? $verifyResp['pkcs7Info'] : [];
            $hasEmbedded = array_key_exists('documentBase64', $pkcs7Info);
            $embedded = $hasEmbedded ? $pkcs7Info['documentBase64'] : null;
            if (is_string($embedded)) {
                $rawData = $this->decodeBase64($embedded);
            }

            if ($status === EimzoSignature::STATUS_VALID && (! $hasEmbedded || $rawData === null)) {
                throw new EimzoServerException(
                    'E-IMZO-SERVER returned a valid attached signature without a valid embedded document',
                    $verifyResp
                );
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
                $storedPkcs7 = $pkcs7WithTs ?? $pkcs7;
                $binary = base64_decode($storedPkcs7, true);
                Storage::disk($disk)->put($path, $binary === false ? $storedPkcs7 : $binary);
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
        $original = $value;
        $value = (string) preg_replace('/\s+/', '', $value);
        if ($value === '') {
            return $original === '' ? '' : null;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
