<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\ApiClient;
use PaymentForm\Services\TokenService;
use RuntimeException;
use WP_REST_Response;

final class RefreshController
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly ApiClient $apiClient,
    ) {}

    public function refresh($req): WP_REST_Response
    {
        $token = $this->tokenService->get();
        if ($token === null) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'not_connected',
            ], 400);
        }

        try {
            $this->apiClient->refresh();

            $expiresIn = $this->tokenService->expiresIn();
            $rawData = $this->tokenService->getRaw();
            $expiresAt = $rawData['expires_at'] ?? null;

            return new WP_REST_Response([
                'status' => true,
                'data' => [
                    'expires_at' => $expiresAt,
                    'expires_in' => $expiresIn,
                ]
            ], 200);
        } catch (RuntimeException $e) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'refresh_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
