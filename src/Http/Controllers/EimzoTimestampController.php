<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Services\EimzoTimestampService;

class EimzoTimestampController extends Controller
{
    /**
     * @var EimzoTimestampService
     */
    private $tsa;

    public function __construct(EimzoTimestampService $tsa)
    {
        $this->tsa = $tsa;
    }

    /**
     * POST /eimzo/timestamp/pkcs7  { pkcs7: string }
     */
    public function pkcs7(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pkcs7' => ['required', 'string'],
        ]);

        return $this->forward(fn () => $this->tsa->timestampPkcs7($data['pkcs7'], $request));
    }

    /**
     * POST /eimzo/timestamp/data  { data: string }
     */
    public function data(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'string'],
        ]);

        return $this->forward(fn () => $this->tsa->timestampData($data['data'], $request));
    }

    /**
     * POST /eimzo/pkcs7/make-attached  { data: string, pkcs7: string }
     *
     * `data` is the original document (base64), `pkcs7` is the detached
     * signature over it. The pair is forwarded to E-IMZO-SERVER as
     * `<data>|<pkcs7>` per the protocol.
     */
    public function makeAttached(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'string'],
            'pkcs7' => ['required', 'string'],
        ]);

        return $this->forward(fn () => $this->tsa->makeAttached($data['data'], $data['pkcs7'], $request));
    }

    /**
     * POST /eimzo/pkcs7/join  { a: string, b: string }
     */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'a' => ['required', 'string'],
            'b' => ['required', 'string'],
        ]);

        return $this->forward(fn () => $this->tsa->join($data['a'], $data['b'], $request));
    }

    private function forward(callable $cb): JsonResponse
    {
        try {
            $payload = $cb();
        } catch (EimzoServerException $e) {
            return response()->json([
                'status' => -2, 'message' => $e->getMessage(), 'payload' => $e->payload(),
            ], 503);
        }

        return response()->json($payload);
    }
}
