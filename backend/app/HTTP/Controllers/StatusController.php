<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\DisconnectedException;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class StatusController
{
    private readonly TokenServiceInterface $tokenService;

    private readonly ApiClient $apiClient;

    public function __construct()
    {
        $this->tokenService = ServiceContainer::get(TokenService::class);
        $this->apiClient = ServiceContainer::get(ApiClient::class);
    }

    /**
     * Every branch (including token-revoked) answers 200 with a plain array;
     * "not connected" is a state, not an error.
     *
     * @return array<string, mixed>
     */
    public function show(Request $req): array
    {
        $token = $this->tokenService->get();
        if ($token === null) {
            return ['connected' => false];
        }

        try {
            $response = $this->apiClient->request('GET', '/me');
        } catch (DisconnectedException|RuntimeException) {
            return [
                'connected' => false,
                'error' => 'token_revoked',
            ];
        }

        $expiresIn = $this->tokenService->expiresIn();
        $expiresAt = null;
        $rawData = $this->tokenService->getRaw();
        if ($rawData !== null && isset($rawData['expires_at'])) {
            $expiresAt = $rawData['expires_at'];
        }

        return [
            'connected' => true,
            'user' => $response['data']['user'] ?? null,
            'tenant' => $response['data']['tenant'] ?? null,
            // Camel-cased to match the React `ConnectionStatus` type the SPA reads
            // (useConnection.ts). REST layer does NOT auto-convert.
            'expiresAt' => $expiresAt,
            'expiresIn' => $expiresIn,
        ];
    }
}
