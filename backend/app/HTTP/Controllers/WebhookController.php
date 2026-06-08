<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Services\TokenServiceInterface;
use PaymentForm\Services\WebhookService;
use WP_REST_Response;

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

        if (!is_string($signature) || $signature === '') {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'missing_signature',
            ], 401);
        }

        $secret = $this->tokenService->getWebhookSecret();
        if ($secret === null) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'no_webhook_secret',
            ], 401);
        }

        if (!$this->webhookService->verify($body, $signature, $secret)) {
            return new WP_REST_Response([
                'status' => false,
                'code' => 'invalid_signature',
            ], 401);
        }

        return new WP_REST_Response([
            'status' => true,
            'received' => true,
        ], 200);
    }
}
