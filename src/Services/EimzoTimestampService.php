<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Http\Request;

/**
 * Wrapper around the TSA-related E-IMZO-SERVER endpoints.
 *
 *  - timestampPkcs7(): wrap an existing PKCS#7 with a timestamp token
 *  - timestampData():  request a raw RFC 3161 token over arbitrary data
 *  - makeAttached():   convert a detached PKCS#7 to attached form
 *  - join():           merge two co-signatures into a single PKCS#7
 */
class EimzoTimestampService
{
    /**
     * @var EimzoServerClient
     */
    private $server;

    public function __construct(EimzoServerClient $server)
    {
        $this->server = $server;
    }

    public function timestampPkcs7(string $pkcs7Base64, Request $request): array
    {
        return $this->server->timestampPkcs7($pkcs7Base64, $request->ip());
    }

    public function timestampData(string $dataBase64, Request $request): array
    {
        return $this->server->timestampData($dataBase64, $request->ip());
    }

    /**
     * Re-embed the original document into a detached PKCS#7. The protocol body
     * is `<data>|<pkcs7>`, so $dataBase64 is the first argument.
     */
    public function makeAttached(string $dataBase64, string $detachedPkcs7Base64, Request $request): array
    {
        return $this->server->makeAttached($dataBase64, $detachedPkcs7Base64, $request->ip());
    }

    public function join(string $pkcs7A, string $pkcs7B, Request $request): array
    {
        return $this->server->joinPkcs7($pkcs7A, $pkcs7B, $request->ip());
    }
}
