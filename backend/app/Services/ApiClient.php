<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

final class ApiClient
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly TokenRefreshLock $refreshLock,
        private readonly string $baseUrl = 'https://api.paymentform.io',
        private readonly ?Client $httpClient = null,
    ) {}

    public function request(string $method, string $path, array $options = []): array
    {
        return $this->makeRequest($method, $path, $options, isRetry: false, knownToken: null);
    }

    public function refresh(): void
    {
        $current = $this->tokenService->get();
        if ($current === null) {
            throw new RuntimeException('no_token_to_refresh');
        }

        // Bypass auto-retry by calling Guzzle directly
        $client = $this->httpClient ?? new Client();
        try {
            $response = $client->request('POST', $this->baseUrl . '/auth/refresh', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $current,
                    'Accept' => 'application/json',
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $token = $body['data']['token'] ?? null;
            $expiresAt = $body['data']['expires_at'] ?? null;
            if (!is_string($token) || !is_string($expiresAt)) {
                throw new RuntimeException('refresh_invalid_response');
            }
            $this->tokenService->store($token, $expiresAt);
        } catch (\JsonException $e) {
            throw new RuntimeException('refresh_invalid_response', 0, $e);
        }
    }

    private function makeRequest(string $method, string $path, array $options, bool $isRetry, ?string $knownToken = null): array
    {
        $client = $this->httpClient ?? new Client();

        // Inject tenant prefix for non-auth paths
        $tenant = $this->tokenService->getTenant();
        $effectivePath = $tenant !== null && !str_starts_with($path, '/auth/')
            ? '/' . rawurlencode($tenant) . $path
            : $path;
        $url = rtrim($this->baseUrl, '/') . $effectivePath;

        $token = $knownToken ?? $this->tokenService->get();

        if ($token === null) {
            throw new RuntimeException('no_token');
        }

        $mergedOptions = $options;
        $mergedOptions['headers'] = array_merge(
            $options['headers'] ?? [],
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ]
        );

        try {
            $response = $client->request($method, $url, $mergedOptions);
            try {
                return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException('invalid_json_response', 0, $e);
            }
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 401) {
                if ($isRetry) {
                    // Second 401: clear token and throw
                    $this->tokenService->clear();
                    throw new DisconnectedException('token_revoked');
                }

                // First 401: try to refresh
                if (!$this->refreshLock->acquire()) {
                    // Another worker is refreshing — re-read token, give it one chance to have updated.
                    $newToken = $this->tokenService->get();
                    if ($newToken !== null && $newToken !== $token) {
                        return $this->makeRequest($method, $path, $options, isRetry: true, knownToken: $newToken);
                    }
                    throw new RuntimeException('refresh_in_progress');
                }

                try {
                    $this->refresh();
                } catch (RuntimeException $refreshException) {
                    // On refresh error, treat as revoked
                    $this->tokenService->clear();
                    throw new DisconnectedException('token_revoked');
                } finally {
                    $this->refreshLock->release();
                }

                // Retry the request OUTSIDE the lock window
                return $this->makeRequest($method, $path, $options, isRetry: true);
            }

            // Handle other HTTP errors
            if ($e->getResponse() !== null) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = (string) $e->getResponse()->getBody();
                $truncated = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;

                throw new RuntimeException(
                    'api_error_' . $statusCode . ': ' . $truncated
                );
            }

            throw new RuntimeException('api_request_failed: ' . $e->getMessage());
        }
    }
}

class DisconnectedException extends RuntimeException {}
