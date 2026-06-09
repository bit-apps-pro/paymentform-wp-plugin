<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Enums\ErrorCode;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;
use WP_REST_Response;

final class RefreshController
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly ApiClient $apiClient,
    ) {}

    public function refresh($req): WP_REST_Response
    {
        $token = $this->tokenService->get();
        if ($token === null) {
            return new WP_REST_Response([
                'status' => false,
                'error_code' => ErrorCode::NOT_CONNECTED->value,
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
            return new WP_REST_Response($this->failureBody($e), 502);
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
