<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Services\EimzoVerifyService;

class EimzoVerifyController extends Controller
{
    /**
     * @var EimzoVerifyService
     */
    private $verifier;

    public function __construct(EimzoVerifyService $verifier)
    {
        $this->verifier = $verifier;
    }

    /**
     * POST /eimzo/verify
     *
     * @body { pkcs7: string, data?: string }
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pkcs7' => ['required', 'string'],
            'data' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->verifier->verify($data['pkcs7'], $data['data'] ?? null, $request);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json([
            'status' => $result['ok'] ? 1 : -1,
            'message' => $result['message'] ?? '',
            'signer' => $result['signer'],
            'signers' => $result['signers'] ?? [],
            'document_base64' => $result['document_base64'] ?? null,
            'payload' => $result['payload'],
        ]);
    }
}
