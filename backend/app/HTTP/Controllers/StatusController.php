<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;
use WP_REST_Response;

final class StatusController
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly ApiClient $apiClient,
    ) {}

    public function show($req): WP_REST_Response
    {
        $token = $this->tokenService->get();
        if ($token === null) {
            return new WP_REST_Response(['connected' => false], 200);
        }

        try {
            $response = $this->apiClient->request('GET', '/api/me');
        } catch (DisconnectedException|RuntimeException $e) {
            return new WP_REST_Response([
                'connected' => false,
                'error' => 'token_revoked',
            ], 200);
        }

        $expiresIn = $this->tokenService->expiresIn();
        $expiresAt = null;
        $rawData = $this->tokenService->getRaw();
        if ($rawData !== null && isset($rawData['expires_at'])) {
            $expiresAt = $rawData['expires_at'];
        }

        return new WP_REST_Response([
            'connected' => true,
            'user' => $response['data']['user'] ?? null,
            'tenant' => $response['data']['tenant'] ?? null,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn,
        ], 200);
    }
}
