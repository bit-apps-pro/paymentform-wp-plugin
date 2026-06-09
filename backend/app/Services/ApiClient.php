<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use JsonException;
use RuntimeException;

/**
 * Token-aware HTTP client that wraps `wp_remote_request` (the same pattern
 * bit-* plugins use for outbound HTTP). Replaces the previous Guzzle-based
 * implementation: WP-native means we ride the site's own CA bundle and
 * cURL transport configuration instead of bundling our own.
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
        private readonly string $baseUrl = 'https://api.paymentform.io',
    ) {}

    /**
     * Execute an authenticated request against the upstream API, transparently
     * refreshing the bearer once on 401.
     *
     * @param  array<string, mixed>  $options  Extra wp_remote_request args; `headers` are merged.
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

        $response = wp_remote_request($this->baseUrl . '/auth/refresh', [
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $current,
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('REFRESH_FAILED: ' . $response->get_error_message());
        }

        $body = $this->decodeBody($response);
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
        $url = rtrim($this->baseUrl, '/') . $effectivePath;

        $args = $this->buildRequestArgs($method, $token, $options);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException('API_REQUEST_FAILED: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status >= 200 && $status < 300) {
            return $this->decodeBody($response);
        }

        if ($status === 401) {
            return $this->handle401($method, $path, $options, $isRetry, $token);
        }

        throw new RuntimeException(sprintf(
            'API_ERROR_%d: %s',
            $status,
            $this->truncate((string) wp_remote_retrieve_body($response)),
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
            throw new RuntimeException('REFRESH_IN_PROGRESS');
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildRequestArgs(string $method, string $token, array $options): array
    {
        $headers = array_merge(
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        );

        $args = $options;
        unset($args['headers']);
        $args['method'] = strtoupper($method);
        $args['headers'] = $headers;
        $args['timeout'] = $args['timeout'] ?? self::TIMEOUT_SECONDS;

        if (isset($args['json'])) {
            $args['body'] = json_encode($args['json']);
            $args['headers']['Content-Type'] = 'application/json';
            unset($args['json']);
        }

        return $args;
    }

    /**
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    private function decodeBody($response): array
    {
        $raw = (string) wp_remote_retrieve_body($response);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('INVALID_JSON_RESPONSE', 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function truncate(string $body): string
    {
        return strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
    }
}

class DisconnectedException extends RuntimeException {}
