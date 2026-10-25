<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class DisconnectController
{
    private readonly TokenServiceInterface $tokenService;

    private readonly ApiClient $apiClient;

    public function __construct()
    {
        $this->tokenService = ServiceContainer::get(TokenService::class);
        $this->apiClient = ServiceContainer::get(ApiClient::class);
    }

    /**
     * @return array{status: bool}
     */
    public function disconnect(Request $req): array
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

        return ['status' => true];
    }
}
