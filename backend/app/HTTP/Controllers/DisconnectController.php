<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\ApiClient;
use PaymentForm\Services\TokenService;
use RuntimeException;
use WP_REST_Response;

final class DisconnectController
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly ApiClient $apiClient,
    ) {}

    public function disconnect($req): WP_REST_Response
    {
        $token = $this->tokenService->get();

        if ($token !== null) {
            // Attempt to revoke, but don't fail if it errors
            try {
                $this->apiClient->request('POST', '/auth/revoke');
            } catch (RuntimeException) {
                // Log + swallow: we'll still clear locally
            }
        }

        $this->tokenService->clear();

        return new WP_REST_Response(['status' => true], 200);
    }
}
