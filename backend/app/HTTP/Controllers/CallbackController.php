<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\TokenServiceInterface;
use RuntimeException;
use WP_REST_Response;

final class CallbackController
{
    public function __construct(
        private readonly ConnectService $connectService,
        private readonly TokenServiceInterface $tokenService,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    public function handle($req)
    {
        $code = $req->get_param('code');
        $state = $req->get_param('state');

        if (!is_string($code) || !is_string($state)) {
            wp_safe_redirect(admin_url('admin.php?page=paymentform&error=missing_params'));
            return;
        }

        $userId = $this->connectService->consumeState($state);
        if ($userId === null) {
            wp_safe_redirect(admin_url('admin.php?page=paymentform&error=invalid_state'));
            return;
        }

        try {
            $client = $this->httpClient ?? new Client();
            $response = $client->request('POST', PAYMENTFORM_API_BASE_URL . '/auth/exchange', [
                'json' => [
                    'code' => $code,
                    'site_url' => site_url(),
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);

            $responseBody = (string) $response->getBody();
            $body = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

            $token = $body['data']['token'] ?? null;
            $expiresAt = $body['data']['expires_at'] ?? null;
            $webhookSecret = $body['data']['webhook_secret'] ?? null;

            if (!is_string($token) || !is_string($expiresAt)) {
                wp_safe_redirect(admin_url('admin.php?page=paymentform&error=invalid_response'));
                return;
            }

            $this->tokenService->store($token, $expiresAt);

            if (is_string($webhookSecret)) {
                $this->tokenService->storeWebhookSecret($webhookSecret);
            }

            wp_safe_redirect(admin_url('admin.php?page=paymentform&connected=1'));
            return;
        } catch (RuntimeException|Exception $e) {
            // Log or debug the error here in production
            // error_log('PaymentForm callback exchange error: ' . $e->getMessage());
            wp_safe_redirect(admin_url('admin.php?page=paymentform&error=exchange_failed'));
            return;
        }
    }
}
