<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Http\Request;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;
use AsadbekRahimov\EimzoIntegration\Support\SignerInfo;

/**
 * Stateless verification service: takes a PKCS#7 (and optional original data
 * for detached signatures) and reports the result, without persisting anything.
 *
 * Use EimzoSignService when you also want to record the signature.
 */
class EimzoVerifyService
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
     * @return array{
     *   ok: bool,
     *   status: int|null,
     *   message: string|null,
     *   signer: array,
     *   signers: array<int, array>,
     *   document_base64: string|null,
     *   payload: array
     * }
     */
    public function verify(string $pkcs7Base64, ?string $dataBase64, Request $request): array
    {
        $detached = is_string($dataBase64) && $dataBase64 !== '';

        $payload = $detached
            ? $this->server->verifyDetached($dataBase64, $pkcs7Base64, $request->ip())
            : $this->server->verifyAttached($pkcs7Base64, $request->ip());

        $signer = config('eimzo.local_parse', true) ? $this->parser->parseSigner($pkcs7Base64) : [];
        $signer = SignerInfo::merge($signer, SignerInfo::fromServerPayload($payload));

        // /backend/pkcs7/verify/attached returns
        //   { "pkcs7Info": { "signers": [...], "documentBase64": "..." }, "status": 1 }
        // Surface signers/document so callers can audit cert chain + OCSP.
        $pkcs7Info = is_array($payload['pkcs7Info'] ?? null) ? $payload['pkcs7Info'] : [];
        $signers = is_array($pkcs7Info['signers'] ?? null) ? $pkcs7Info['signers'] : [];
        $document = $pkcs7Info['documentBase64'] ?? null;
        if (! $detached && ($payload['status'] ?? null) === 1) {
            $hasDocument = array_key_exists('documentBase64', $pkcs7Info);
            $decoded = is_string($document)
                ? base64_decode((string) preg_replace('/\s+/', '', $document), true)
                : false;

            if (! $hasDocument || $decoded === false) {
                throw new EimzoServerException(
                    'E-IMZO-SERVER returned a valid attached signature without a valid embedded document',
                    $payload
                );
            }
        }

        return [
            'ok' => ($payload['status'] ?? null) === 1,
            'status' => $payload['status'] ?? null,
            'message' => $payload['message'] ?? null,
            'signer' => $signer,
            'signers' => $signers,
            'document_base64' => is_string($document) ? $document : null,
            'payload' => $payload,
        ];
    }
}
