<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\WebhookController;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\WebhookService;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\TestCase;

class WebhookControllerTest extends TestCase
{
    private TokenService $tokenService;
    private WebhookService $webhookService;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->webhookService = new WebhookService();
    }

    public function test_handle_returns_401_when_signature_missing(): void
    {
        $controller = new WebhookController($this->tokenService, $this->webhookService);
        $body = json_encode(['event' => 'submission.created']);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/webhook', [], $body);
        $response = $controller->handle($req);

        $this->assertSame(401, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('UNAUTHORIZED', $data['error_code']);
    }

    public function test_handle_returns_401_when_no_webhook_secret(): void
    {
        $controller = new WebhookController($this->tokenService, $this->webhookService);
        $body = json_encode(['event' => 'submission.created']);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/webhook', [], $body, [
            'X-PaymentForm-Signature' => 't=1234567890,v1=' . str_repeat('0', 64),
        ]);
        $response = $controller->handle($req);

        $this->assertSame(401, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('UNAUTHORIZED', $data['error_code']);
    }

    public function test_handle_returns_401_for_invalid_signature(): void
    {
        $secret = 'webhook-secret-xyz';
        $this->tokenService->storeWebhookSecret($secret);

        $controller = new WebhookController($this->tokenService, $this->webhookService);
        $body = json_encode(['event' => 'submission.created']);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/webhook', [], $body, [
            'X-PaymentForm-Signature' => 't=1234567890,v1=' . str_repeat('f', 64),
        ]);
        $response = $controller->handle($req);

        $this->assertSame(401, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('UNAUTHORIZED', $data['error_code']);
    }

    public function test_handle_returns_200_with_valid_signature(): void
    {
        $secret = 'webhook-secret-xyz';
        $this->tokenService->storeWebhookSecret($secret);

        $body = json_encode(['event' => 'submission.created']);
        $timestamp = (string) time();
        $message = $timestamp . '.' . $body;
        $hmac = hash_hmac('sha256', $message, $secret);
        $signature = 't=' . $timestamp . ',v1=' . $hmac;

        $controller = new WebhookController($this->tokenService, $this->webhookService);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/webhook', [], $body, [
            'X-PaymentForm-Signature' => $signature,
        ]);
        $response = $controller->handle($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['status']);
        $this->assertTrue($data['received']);
    }
}
