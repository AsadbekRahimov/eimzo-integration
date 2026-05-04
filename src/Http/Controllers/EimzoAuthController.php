<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AsadbekRahimov\EimzoIntegration\Exceptions\ChallengeExpiredException;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Exceptions\VerificationFailedException;
use AsadbekRahimov\EimzoIntegration\Services\EimzoAuthService;

class EimzoAuthController extends Controller
{
    /**
     * @var EimzoAuthService
     */
    private $auth;

    public function __construct(EimzoAuthService $auth)
    {
        $this->auth = $auth;
    }

    /**
     * GET /eimzo/auth/challenge
     */
    public function challenge(Request $request): JsonResponse
    {
        try {
            $row = $this->auth->issueChallenge($request);
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json([
            'status' => 1,
            'message' => '',
            'challenge' => $row->challenge,
            'expires_at' => $row->expires_at->toIso8601String(),
            'ttl' => (int) config('eimzo.auth.challenge_ttl', 120),
        ]);
    }

    /**
     * POST /eimzo/auth/verify
     *
     * @body { challenge: string, pkcs7: string }
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'pkcs7' => ['required', 'string'],
        ]);

        try {
            $result = $this->auth->verifyChallenge($data['challenge'], $data['pkcs7'], $request);
        } catch (ChallengeExpiredException $e) {
            return response()->json([
                'status' => -1, 'message' => $e->getMessage(),
            ], 410);
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
                'valid_from' => optional($cert->valid_from)->toIso8601String(),
                'valid_to' => optional($cert->valid_to)->toIso8601String(),
            ],
            'authenticated' => $user !== null,
        ]);
    }

    /**
     * POST /eimzo/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        return response()->json(['status' => 1, 'message' => '']);
    }
}
