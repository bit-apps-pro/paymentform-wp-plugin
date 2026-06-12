<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Enums\ErrorCode;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;

final class RefreshController
{
    private readonly TokenServiceInterface $tokenService;

    private readonly ApiClient $apiClient;

    public function __construct()
    {
        $this->tokenService = ServiceContainer::get(TokenService::class);
        $this->apiClient = ServiceContainer::get(ApiClient::class);
    }

    /**
     * @return array{expiresAt: ?string, expiresIn: ?int}|Response
     */
    public function refresh(Request $req): array|Response
    {
        $token = $this->tokenService->get();
        if ($token === null) {
            return Response::error([
                'status' => false,
                'error_code' => ErrorCode::NOT_CONNECTED->value,
            ], 400)->code(ErrorCode::NOT_CONNECTED->value)->message(null);
        }

        try {
            $this->apiClient->refresh();

            $expiresIn = $this->tokenService->expiresIn();
            $rawData = $this->tokenService->getRaw();
            $expiresAt = $rawData['expires_at'] ?? null;

            // Flat, camelCase payload mirroring StatusController so request()'s
            // single envelope-unwrap surfaces { expiresAt, expiresIn } directly
            // — a self-wrapped { status, data: {...} } would nest the values one
            // level deeper than every other endpoint.
            return [
                'expiresAt' => $expiresAt,
                'expiresIn' => $expiresIn,
            ];
        } catch (RuntimeException $e) {
            return Response::error($this->failureBody($e), 502)
                ->code(ErrorCode::REFRESH_FAILED->value)
                ->message(null);
        }
    }

    /**
     * Upstream exception text can include internal hostnames or stack hints.
     * Surface a generic body in normal operation; reveal the detail only
     * under `WP_DEBUG` so admins debugging in-place still get the signal.
     *
     * @return array<string, mixed>
     */
    private function failureBody(RuntimeException $e): array
    {
        $body = ['status' => false, 'error_code' => ErrorCode::REFRESH_FAILED->value];

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $body['message'] = $e->getMessage();
        }

        return $body;
    }
}
