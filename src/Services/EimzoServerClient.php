<?php

namespace AsadbekRahimov\EimzoIntegration\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException;

/**
 * Thin HTTP client for the Java E-IMZO-SERVER component. The Java service
 * proxies VPN access to the certificate authority, so this client is the
 * canonical place that talks to the gov backend - everything else in the
 * module dispatches through here.
 *
 *   POST /backend/auth                          - verify auth challenge via server_url
 *   GET  /frontend/challenge                    - issue auth challenge via frontend_url/server_url
 *   POST /backend/pkcs7/verify/attached         - verify attached pkcs7
 *   POST /backend/pkcs7/verify/detached         - verify detached pkcs7
 *   POST /frontend/timestamp/pkcs7              - add TSA timestamp to pkcs7
 *   POST /frontend/timestamp/data               - timestamp raw data
 *   POST /frontend/pkcs7/make-attached          - convert detached to attached
 *   POST /frontend/pkcs7/join                   - merge two pkcs7
 *   POST /frontend/mobile/auth                  - issue mobile-auth DocumentID + challenge
 *   POST /frontend/mobile/sign                  - issue mobile-sign DocumentID
 *   POST /frontend/mobile/status                - poll mobile DocumentID state
 *   POST /frontend/mobile/upload                - receive PKCS#7 from ID-CARD app
 *   GET  /backend/mobile/authenticate/{id}      - finalise mobile auth
 *   POST /backend/mobile/verify                 - finalise mobile sign
 */
class EimzoServerClient
{
    /**
     * @var HttpFactory
     */
    private $http;

    public function __construct(HttpFactory $http)
    {
        $this->http = $http;
    }

    public function authenticate(string $pkcs7Base64, ?string $clientIp = null): array
    {
        return $this->postRaw('/backend/auth', $pkcs7Base64, $clientIp);
    }

    public function challenge(?string $clientIp = null): array
    {
        $request = $this->request($clientIp);

        try {
            $response = $request->get($this->frontendUrl('/frontend/challenge'));
        } catch (\Throwable $e) {
            throw new EimzoServerException(
                'E-IMZO-SERVER unreachable: ' . $e->getMessage(),
                ['path' => '/frontend/challenge'],
                0,
                $e
            );
        }

        if ($response->failed()) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned HTTP ' . $response->status(),
                ['status' => $response->status(), 'body' => $response->body(), 'path' => '/frontend/challenge'],
                $response->status()
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned non-JSON response',
                ['body' => $response->body(), 'path' => '/frontend/challenge']
            );
        }

        return $payload;
    }

    public function verifyAttached(string $pkcs7Base64, ?string $clientIp = null): array
    {
        return $this->postRaw('/backend/pkcs7/verify/attached', $pkcs7Base64, $clientIp);
    }

    public function verifyDetached(string $dataBase64, string $pkcs7Base64, ?string $clientIp = null): array
    {
        return $this->postRaw('/backend/pkcs7/verify/detached', $dataBase64 . '|' . $pkcs7Base64, $clientIp);
    }

    public function timestampPkcs7(string $pkcs7Base64, ?string $clientIp = null): array
    {
        return $this->postFrontendRaw('/frontend/timestamp/pkcs7', $pkcs7Base64, $clientIp);
    }

    public function timestampData(string $dataBase64, ?string $clientIp = null): array
    {
        return $this->postFrontendRaw('/frontend/timestamp/data', $dataBase64, $clientIp);
    }

    /**
     * Convert a detached PKCS#7 to an attached form by re-embedding the document.
     *
     * Per E-IMZO-SERVER protocol the body order is `<document>|<pkcs7>`
     * (original document first, then the detached signature - both base64).
     * See https://github.com/qo0p/e-imzo-doc#frontendpkcs7make-attached.
     */
    public function makeAttached(string $dataBase64, string $detachedPkcs7Base64, ?string $clientIp = null): array
    {
        return $this->postFrontendRaw(
            '/frontend/pkcs7/make-attached',
            $dataBase64 . '|' . $detachedPkcs7Base64,
            $clientIp
        );
    }

    public function joinPkcs7(string $pkcs7A, string $pkcs7B, ?string $clientIp = null): array
    {
        return $this->postFrontendRaw('/frontend/pkcs7/join', $pkcs7A . '|' . $pkcs7B, $clientIp);
    }

    /**
     * Issue a fresh mobile-auth DocumentID + challenge. The body is empty per
     * the protocol; E-IMZO-SERVER stores the (siteId, documentId, challenge)
     * triple in Redis with a TTL.
     *
     * Response shape: { status, siteId, documentId, challange }
     * (note: "challange" - typo is part of the upstream protocol).
     */
    public function mobileAuth(?string $clientIp = null): array
    {
        return $this->postFrontendRaw('/frontend/mobile/auth', '', $clientIp);
    }

    /**
     * Issue a fresh mobile-sign DocumentID.
     * Response shape: { status, siteId, documentId }
     */
    public function mobileSign(?string $clientIp = null): array
    {
        return $this->postFrontendRaw('/frontend/mobile/sign', '', $clientIp);
    }

    /**
     * Poll the mobile DocumentID state from Redis.
     *  status=1: PKCS#7 received from the mobile app
     *  status=2: still pending
     *  status<0: error (see protocol)
     */
    public function mobileStatus(string $documentId, ?string $clientIp = null): array
    {
        return $this->postFrontendForm(
            '/frontend/mobile/status',
            ['documentId' => $documentId],
            $clientIp
        );
    }

    /**
     * Forward a PKCS#7 received from the ID-CARD mobile app to E-IMZO-SERVER
     * so it can persist it in Redis under the DocumentID. The mobile system
     * posts the raw base64 PKCS#7 to the client domain UPLOAD URL; the client
     * just relays it.
     */
    public function mobileUpload(string $rawBody, array $query = [], ?string $clientIp = null): array
    {
        $url = $this->frontendUrl('/frontend/mobile/upload');
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $this->sendRaw('POST', $url, '/frontend/mobile/upload', $rawBody, $clientIp);
    }

    /**
     * Finalise mobile authentication for $documentId. Returns the verified
     * subjectCertificateInfo on status=1.
     */
    public function mobileAuthenticate(string $documentId, ?string $clientIp = null): array
    {
        return $this->getBackend(
            '/backend/mobile/authenticate/' . rawurlencode($documentId),
            $clientIp
        );
    }

    /**
     * Finalise mobile signing for $documentId over $documentBase64. Returns
     * the timestamped pkcs7Attached + verificationInfo on status=1.
     */
    public function mobileVerify(string $documentId, string $documentBase64, ?string $clientIp = null): array
    {
        return $this->postBackendForm(
            '/backend/mobile/verify',
            ['documentId' => $documentId, 'document' => $documentBase64],
            $clientIp
        );
    }

    private function postRaw(string $path, string $body, ?string $clientIp): array
    {
        return $this->sendRaw('POST', $this->url($path), $path, $body, $clientIp);
    }

    private function postFrontendRaw(string $path, string $body, ?string $clientIp): array
    {
        return $this->sendRaw('POST', $this->frontendUrl($path), $path, $body, $clientIp);
    }

    private function postFrontendForm(string $path, array $form, ?string $clientIp): array
    {
        return $this->sendForm('POST', $this->frontendUrl($path), $path, $form, $clientIp);
    }

    private function postBackendForm(string $path, array $form, ?string $clientIp): array
    {
        return $this->sendForm('POST', $this->url($path), $path, $form, $clientIp);
    }

    private function sendForm(string $method, string $url, string $path, array $form, ?string $clientIp): array
    {
        $request = $this->request($clientIp, 'application/x-www-form-urlencoded');

        try {
            $response = $request->asForm()->send($method, $url, ['form_params' => $form]);
        } catch (\Throwable $e) {
            throw new EimzoServerException(
                'E-IMZO-SERVER unreachable: ' . $e->getMessage(),
                ['path' => $path],
                0,
                $e
            );
        }

        return $this->decode($response, $path);
    }

    private function getBackend(string $path, ?string $clientIp): array
    {
        $request = $this->request($clientIp);

        try {
            $response = $request->get($this->url($path));
        } catch (\Throwable $e) {
            throw new EimzoServerException(
                'E-IMZO-SERVER unreachable: ' . $e->getMessage(),
                ['path' => $path],
                0,
                $e
            );
        }

        return $this->decode($response, $path);
    }

    private function decode($response, string $path): array
    {
        if ($response->failed()) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned HTTP ' . $response->status(),
                ['status' => $response->status(), 'body' => $response->body(), 'path' => $path],
                $response->status()
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned non-JSON response',
                ['body' => $response->body(), 'path' => $path]
            );
        }

        return $payload;
    }

    private function sendRaw(string $method, string $url, string $path, string $body, ?string $clientIp): array
    {
        $request = $this->request($clientIp);

        try {
            $response = $request
                ->withBody($body, 'text/plain')
                ->send($method, $url);
        } catch (\Throwable $e) {
            throw new EimzoServerException(
                'E-IMZO-SERVER unreachable: ' . $e->getMessage(),
                ['path' => $path],
                0,
                $e
            );
        }

        if ($response->failed()) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned HTTP ' . $response->status(),
                ['status' => $response->status(), 'body' => $response->body(), 'path' => $path],
                $response->status()
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EimzoServerException(
                'E-IMZO-SERVER returned non-JSON response',
                ['body' => $response->body(), 'path' => $path]
            );
        }

        return $payload;
    }

    private function request(?string $clientIp, string $contentType = 'text/plain'): PendingRequest
    {
        $headers = [];
        if ($clientIp !== null && $clientIp !== '') {
            $headers['X-Real-IP'] = $clientIp;
        }

        $host = config('eimzo.request_host') ?: (function () {
            try {
                return request()->getHttpHost();
            } catch (\Throwable $e) {
                return null;
            }
        })();
        if (is_string($host) && $host !== '') {
            $headers['Host'] = $host;
        }

        return $this->http
            ->timeout((int) config('eimzo.server_timeout', 20))
            ->withOptions(['connect_timeout' => (int) config('eimzo.server_connect_timeout', 3)])
            ->withHeaders(['Content-Type' => $contentType])
            ->withHeaders($headers);
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('eimzo.server_url', 'http://127.0.0.1:8080'), '/');
        return $base . '/' . ltrim($path, '/');
    }

    private function frontendUrl(string $path): string
    {
        $base = config('eimzo.frontend_url');
        if (! is_string($base) || trim($base) === '') {
            return $this->url($path);
        }

        $base = rtrim(trim($base), '/');
        $suffix = $this->baseTargetsFrontendRoot($base) && strpos($path, '/frontend/') === 0
            ? substr($path, strlen('/frontend'))
            : '/' . ltrim($path, '/');

        if (strpos($base, 'http://') === 0 || strpos($base, 'https://') === 0) {
            return $base . $suffix;
        }

        $origin = null;
        try {
            $origin = request()->getSchemeAndHttpHost();
        } catch (\Throwable $e) {
            $origin = rtrim((string) config('app.url', 'http://127.0.0.1'), '/');
        }

        return $origin . '/' . trim($base, '/') . $suffix;
    }

    private function baseTargetsFrontendRoot(string $base): bool
    {
        $path = parse_url($base, PHP_URL_PATH);
        $path = is_string($path) ? $path : $base;
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === 'frontend' || substr($path, -strlen('/frontend')) === '/frontend';
    }
}
