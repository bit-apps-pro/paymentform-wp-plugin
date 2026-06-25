<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\Http\HttpTransport;
use RuntimeException;

/**
 * Token-aware HTTP client that drives the shared {@see HttpTransport} adapter
 * (wp-kit `HttpClient` under the hood, which itself rides the site's own
 * `wp_remote_request` transport and CA bundle).
 *
 * The class intentionally throws plain `RuntimeException` for upstream
 * failures so callers can map them to their own response shape; 401 is
 * special-cased into a refresh + retry loop and ultimately into
 * `DisconnectedException` to signal "the token is gone, prompt reconnect".
 */
final class ApiClient
{
    /**
     * Timeout for individual HTTP calls. Kept on the conservative side so a
     * stalled upstream can't park a WP REST request long enough to trip the
     * fronting PHP-FPM `request_terminate_timeout`.
     */
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly TokenRefreshLock $refreshLock,
        private readonly HttpTransport $http,
    ) {}

    /**
     * Execute an authenticated request against the upstream API, transparently
     * refreshing the bearer once on 401.
     *
     * @param  array<string, mixed>  $options  Extra request args; `headers` are merged, `json`/`timeout` honored.
     * @return array<string, mixed>            Decoded JSON body.
     */
    public function request(string $method, string $path, array $options = []): array
    {
        return $this->makeRequest($method, $path, $options, isRetry: false, knownToken: null);
    }

    /**
     * Trade the current bearer for a fresh one via the upstream `/auth/refresh`
     * endpoint and persist it. Called by `makeRequest()` on 401 inside the
     * refresh-lock window; also exposed for explicit consumers.
     */
    public function refresh(): void
    {
        $current = $this->tokenService->get();
        if ($current === null) {
            throw new RuntimeException('NO_TOKEN_TO_REFRESH');
        }

        $response = $this->http->post(
            '/auth/refresh',
            [
                'Authorization' => 'Bearer ' . $current,
                'Accept' => 'application/json',
            ],
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if ($response->isError()) {
            throw new RuntimeException('REFRESH_FAILED: ' . $response->error->get_error_message());
        }

        // Refresh historically did not check the HTTP status — it only decoded
        // the body and validated the token fields. Preserve that exactly.
        $body = $this->decodeOrThrow($response);
        $token = $body['data']['token'] ?? null;
        $expiresAt = $body['data']['expires_at'] ?? null;

        if (! is_string($token) || ! is_string($expiresAt)) {
            throw new RuntimeException('REFRESH_INVALID_RESPONSE');
        }

        $this->tokenService->store($token, $expiresAt, $this->tokenService->getTenant());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, string $path, array $options, bool $isRetry, ?string $knownToken = null): array
    {
        $token = $knownToken ?? $this->tokenService->get();
        if ($token === null) {
            throw new RuntimeException('NO_TOKEN');
        }

        $tenant = $this->tokenService->getTenant();
        $effectivePath = $tenant !== null && ! str_starts_with($path, '/auth/')
            ? '/' . rawurlencode($tenant) . $path
            : $path;

        $headers = array_merge(
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        );
        $json = is_array($options['json'] ?? null) ? $options['json'] : null;
        // A raw `body` option is forwarded for parity with the legacy client;
        // when `json` is also set the adapter encodes it and ignores `body`.
        $body = is_string($options['body'] ?? null) ? $options['body'] : null;
        $timeout = $options['timeout'] ?? self::TIMEOUT_SECONDS;

        $response = $this->http->send($method, $effectivePath, $headers, json: $json, body: $body, timeoutSeconds: $timeout);

        if ($response->isError()) {
            throw new RuntimeException('API_REQUEST_FAILED: ' . $response->error->get_error_message());
        }

        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            return $this->decodeOrThrow($response);
        }

        if ($status === 401) {
            return $this->handle401($method, $path, $options, $isRetry, $token);
        }

        throw new RuntimeException(sprintf(
            'API_ERROR_%d: %s',
            $status,
            $this->truncate($response->rawBody),
        ));
    }

    /**
     * 401 means our bearer is stale. Try to refresh once (lock-protected so two
     * worker processes can't both rotate). If the lock is held, re-read the
     * token store: a sibling worker might have already published a new one.
     * Any failure during the refresh + retry sequence clears the token so the
     * admin sees a fresh "Connect" prompt instead of silently looping.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function handle401(string $method, string $path, array $options, bool $isRetry, string $currentToken): array
    {
        if ($isRetry) {
            $this->tokenService->clear();
            throw new DisconnectedException('TOKEN_REVOKED');
        }

        if (! $this->refreshLock->acquire()) {
            $newToken = $this->tokenService->get();
            if ($newToken !== null && $newToken !== $currentToken) {
                return $this->makeRequest($method, $path, $options, isRetry: true, knownToken: $newToken);
            }
            // Lock held by another worker and no fresh token yet — clear credentials
            // so the admin sees a reconnect prompt rather than a generic server error.
            $this->tokenService->clear();
            throw new DisconnectedException('REFRESH_LOCK_TIMEOUT');
        }

        try {
            $this->refresh();
        } catch (RuntimeException) {
            $this->tokenService->clear();
            throw new DisconnectedException('TOKEN_REVOKED');
        } finally {
            $this->refreshLock->release();
        }

        return $this->makeRequest($method, $path, $options, isRetry: true);
    }

    /**
     * Map the adapter's normalized body onto the array contract callers expect,
     * preserving the legacy `JSON_THROW_ON_ERROR` behavior: a malformed body
     * (flagged by the adapter) becomes `INVALID_JSON_RESPONSE`.
     *
     * @return array<string, mixed>
     */
    private function decodeOrThrow(HttpResponse $response): array
    {
        if ($response->jsonError) {
            throw new RuntimeException('INVALID_JSON_RESPONSE');
        }

        return $response->body;
    }

    private function truncate(string $body): string
    {
        return strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
    }
}

class DisconnectedException extends RuntimeException {}
