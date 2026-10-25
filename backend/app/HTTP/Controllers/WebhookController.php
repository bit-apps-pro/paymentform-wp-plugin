<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Enums\ErrorCode;
use PaymentForm\Services\TokenServiceInterface;
use PaymentForm\Services\WebhookService;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookController
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly WebhookService $webhookService,
    ) {}

    public function handle($req): WP_REST_Response
    {
        $body = $req->get_body();
        $signature = $req->get_header('X-PaymentForm-Signature');

        $reason = null;

        if (!is_string($signature) || $signature === '') {
            $reason = 'missing_signature';
        } elseif (($secret = $this->tokenService->getWebhookSecret()) === null) {
            $reason = 'no_webhook_secret';
        } elseif (!$this->webhookService->verify($body, $signature, $secret)) {
            $reason = 'invalid_signature';
        }

        if ($reason !== null) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only diagnostics; output would corrupt redirect/JSON responses.
                error_log('[paymentform][webhook] ' . $reason);
            }
            return new WP_REST_Response([
                'status' => false,
                'error_code' => ErrorCode::UNAUTHORIZED->value,
            ], 401);
        }

        return new WP_REST_Response([
            'status' => true,
            'received' => true,
        ], 200);
    }
}
