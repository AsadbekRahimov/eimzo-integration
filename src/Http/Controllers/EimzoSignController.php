<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;
use AsadbekRahimov\EimzoIntegration\Services\EimzoSignService;

class EimzoSignController extends Controller
{
    /**
     * @var EimzoSignService
     */
    private $signer;

    public function __construct(EimzoSignService $signer)
    {
        $this->signer = $signer;
    }

    /**
     * POST /eimzo/sign
     *
     * @body {
     *   pkcs7: string,
     *   data?: string,                  // required when detached=true
     *   detached?: bool,
     *   document_type?: string,
     *   document_name?: string,
     *   attach_timestamp?: bool,
     *   meta?: array
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pkcs7' => ['required', 'string'],
            'data' => ['nullable', 'string', 'required_if:detached,true'],
            'detached' => ['nullable', 'boolean'],
            'document_type' => ['nullable', 'string', 'max:64'],
            'document_name' => ['nullable', 'string', 'max:255'],
            'attach_timestamp' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $data['user_id'] = $user ? $user->getAuthIdentifier() : null;

        try {
            $sig = $this->signer->store($data, $request);
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
            'signature' => $this->present($sig),
        ]);
    }

    /**
     * GET /eimzo/signatures/{signature}
     *
     * Deny-by-default: only the authenticated owner of the signature may view
     * it. To open access up (back-office, auditors, guest flows), register a
     * policy for EimzoSignature in the consuming app - it fully takes over
     * the decision:
     *
     *   Gate::policy(EimzoSignature::class, YourSignaturePolicy::class);
     */
    public function show(Request $request, EimzoSignature $signature): JsonResponse
    {
        if (! $this->authorizedToView($request, $signature)) {
            return response()->json([
                'status' => -1, 'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'status' => 1,
            'signature' => $this->present($signature->load('certificate')),
        ]);
    }

    private function authorizedToView(Request $request, EimzoSignature $signature): bool
    {
        if (Gate::getPolicyFor($signature) !== null) {
            return Gate::forUser($request->user())->allows('view', $signature);
        }

        $user = $request->user();

        return $user !== null
            && $signature->user_id !== null
            && (string) $signature->user_id === (string) $user->getAuthIdentifier();
    }

    private function present(EimzoSignature $sig): array
    {
        return [
            'id' => $sig->id,
            'document_type' => $sig->document_type,
            'document_name' => $sig->document_name,
            'document_hash' => $sig->document_hash,
            'detached' => $sig->detached,
            'verification_status' => $sig->verification_status,
            'signed_at' => optional($sig->signed_at)->toIso8601String(),
            'timestamp_at' => optional($sig->timestamp_at)->toIso8601String(),
            'verified_at' => optional($sig->verified_at)->toIso8601String(),
            'has_timestamp' => filled($sig->pkcs7_with_timestamp),
            'certificate' => $sig->certificate ? [
                'serial_number' => $sig->certificate->serial_number,
                'cn' => $sig->certificate->cn,
                'tin' => $sig->certificate->tin,
                'pinfl' => $sig->certificate->pinfl,
                'valid_to' => optional($sig->certificate->valid_to)->toIso8601String(),
            ] : null,
        ];
    }
}
