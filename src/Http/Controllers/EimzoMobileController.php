<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Services\EimzoMobileService;

/**
 * HTTP surface for the mobile API. Two flows are exposed:
 *
 *   AUTH:  POST eimzo/mobile/auth/start        -> returns documentId + challenge + deeplink
 *          POST eimzo/mobile/auth/status       -> polls status
 *          POST eimzo/mobile/auth/complete     -> finalises auth (logs the user in)
 *
 *   SIGN:  POST eimzo/mobile/sign/start        -> returns documentId + deeplink (caller supplies hash)
 *          POST eimzo/mobile/sign/status       -> polls status
 *          POST eimzo/mobile/sign/complete     -> finalises sign and stores EimzoSignature
 *
 *   POST eimzo/mobile/upload                   -> proxies the ID-CARD app upload to E-IMZO-SERVER
 *
 * The deeplink payload itself is computed client-side using the bundled
 * e-imzo-mobile.js helper. The backend only returns `siteId` + `documentId`
 * + (for auth) `challenge` so the JS can build the QR / deeplink URL.
 */
class EimzoMobileController extends Controller
{
    /**
     * @var EimzoMobileService
     */
    private $mobile;

    public function __construct(EimzoMobileService $mobile)
    {
        $this->mobile = $mobile;
    }

    public function authStart(Request $request): JsonResponse
    {
        try {
            $data = $this->mobile->issueAuth($request);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json([
            'status' => 1,
            'message' => '',
            'site_id' => $data['site_id'],
            'document_id' => $data['document_id'],
            'challenge' => $data['challenge'],
            'ttl' => (int) config('eimzo.auth.challenge_ttl', 120),
            'poll_timeout' => (int) config('eimzo.mobile.poll_timeout', 120),
            'poll_interval_ms' => (int) config('eimzo.mobile.poll_interval_ms', 1500),
        ]);
    }

    public function signStart(Request $request): JsonResponse
    {
        try {
            $data = $this->mobile->issueSign($request);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json([
            'status' => 1,
            'message' => '',
            'site_id' => $data['site_id'],
            'document_id' => $data['document_id'],
            'ttl' => (int) config('eimzo.auth.challenge_ttl', 120),
            'poll_timeout' => (int) config('eimzo.mobile.poll_timeout', 120),
            'poll_interval_ms' => (int) config('eimzo.mobile.poll_interval_ms', 1500),
        ]);
    }

    /**
     * Poll the upstream Redis state by DocumentID. The same endpoint serves
     * both auth and sign flows; the caller passes whichever document_id it
     * received from /start.
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'string'],
        ]);

        try {
            $payload = $this->mobile->pollStatus($data['document_id'], $request);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json($payload);
    }

    public function authComplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'string'],
        ]);

        try {
            $result = $this->mobile->completeAuth($data['document_id'], $request);
        } catch (VerificationFailedException $e) {
            return response()->json([
                'status' => -1, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 422);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        $user = $result['user'];
        $cert = $result['certificate'];

        return response()->json([
            'status' => 1,
            'message' => '',
            'redirect' => config('eimzo.auth.redirect_after_login', '/'),
            'user' => $user ? [
                'id' => $user->getAuthIdentifier(),
                'name' => $cert->cn ?? null,
            ] : null,
            'certificate' => [
                'serial_number' => $cert->serial_number,
                'cn' => $cert->cn,
                'tin' => $cert->tin,
                'pinfl' => $cert->pinfl,
                'uid' => $cert->uid,
                'valid_from' => optional($cert->valid_from)->toIso8601String(),
                'valid_to' => optional($cert->valid_to)->toIso8601String(),
            ],
            'authenticated' => $user !== null,
        ]);
    }

    public function signComplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'string'],
            'document' => ['required', 'string'],
            'document_type' => ['nullable', 'string', 'max:64'],
            'document_name' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $context = [
            'user_id' => optional($request->user())->getAuthIdentifier(),
            'document_type' => $data['document_type'] ?? null,
            'document_name' => $data['document_name'] ?? null,
            'meta' => $data['meta'] ?? [],
        ];

        try {
            $sig = $this->mobile->completeSign(
                $data['document_id'],
                $data['document'],
                $request,
                $context
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => -1, 'message' => $e->getMessage(),
            ], 422);
        } catch (VerificationFailedException $e) {
            return response()->json([
                'status' => -1, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 422);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json([
            'status' => 1,
            'message' => '',
            'signature' => [
                'id' => $sig->id,
                'document_type' => $sig->document_type,
                'document_name' => $sig->document_name,
                'document_hash' => $sig->document_hash,
                'has_timestamp' => filled($sig->pkcs7_with_timestamp),
                'signed_at' => optional($sig->signed_at)->toIso8601String(),
                'timestamp_at' => optional($sig->timestamp_at)->toIso8601String(),
                'verified_at' => optional($sig->verified_at)->toIso8601String(),
                'certificate' => $sig->certificate ? [
                    'serial_number' => $sig->certificate->serial_number,
                    'cn' => $sig->certificate->cn,
                    'tin' => $sig->certificate->tin,
                    'pinfl' => $sig->certificate->pinfl,
                ] : null,
            ],
        ]);
    }

    /**
     * The ID-CARD mobile app posts the signed PKCS#7 to the client-domain
     * UPLOAD URL. We accept anything (raw body or form fields), forward it
     * unchanged to E-IMZO-SERVER, and return whatever the server returned.
     */
    public function upload(Request $request): JsonResponse
    {
        $body = (string) $request->getContent();
        if ($body === '' && $request->isMethod('post')) {
            $body = http_build_query($request->all());
        }

        try {
            $payload = $this->mobile->relayUpload(
                $body,
                $request->query->all(),
                $request
            );
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json($payload);
    }
}
