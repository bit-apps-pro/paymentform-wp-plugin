<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

final class ApiClient
{
    private mixed $refreshCallback = null;

    public function __construct(
        private readonly TokenService $tokenService,
        private readonly TokenRefreshLock $refreshLock,
        private readonly string $baseUrl = 'https://app.paymentform.io',
        private readonly ?Client $httpClient = null,
    ) {}

    public function setRefreshCallback(callable $callback): void
    {
        $this->refreshCallback = $callback;
    }

    public function request(string $method, string $path, array $options = []): array
    {
        return $this->makeRequest($method, $path, $options, isRetry: false);
    }

    private function makeRequest(string $method, string $path, array $options, bool $isRetry): array
    {
        $client = $this->httpClient ?? new Client();
        $url = $this->baseUrl . $path;
        $token = $this->tokenService->get();

        if ($token === null) {
            throw new RuntimeException('no_token');
        }

        $defaultOptions = [
            'headers' => array_merge(
                $options['headers'] ?? [],
                [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ]
            ),
        ];

        $mergedOptions = array_merge($defaultOptions, $options);

        try {
            $response = $client->request($method, $url, $mergedOptions);
            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 401) {
                if ($isRetry) {
                    // Second 401: clear token and throw
                    $this->tokenService->clear();
                    throw new RuntimeException('token_revoked');
                }

                // First 401: try to refresh
                if ($this->refreshCallback !== null) {
                    try {
                        ($this->refreshCallback)();
                        // Retry the request
                        return $this->makeRequest($method, $path, $options, isRetry: true);
                    } catch (RuntimeException $e) {
                        // Refresh failed, rethrow
                        throw $e;
                    }
                }

                // No refresh callback set, treat as revoked
                $this->tokenService->clear();
                throw new RuntimeException('token_revoked');
            }

            // Handle other HTTP errors
            if ($e->getResponse() !== null) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = $e->getResponse()->getBody()->getContents();

                throw new RuntimeException(
                    'api_error_' . $statusCode . ': ' . $body
                );
            }

            throw new RuntimeException('api_request_failed: ' . $e->getMessage());
        }
    }
}

class DisconnectedException extends RuntimeException {}
