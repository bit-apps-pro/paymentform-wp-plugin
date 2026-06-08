<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use RedirectException;
use PHPUnit\Framework\TestCase;

class CallbackControllerTest extends TestCase
{
    private ConnectService $connectService;
    private TokenService $tokenService;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->connectService = new ConnectService();
    }

    public function test_handle_missing_code_redirects_with_error(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'state' => 'valid-state',
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_missing_state_redirects_with_error(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_invalid_state_redirects_with_error(): void
    {
        // Generate a properly formatted state but don't store it, so consumeState fails
        $invalidState = bin2hex(random_bytes(32)); // 64 chars, valid format, but not stored

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $invalidState,
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=invalid_state/');

        $controller->handle($req);
    }

    public function test_handle_exchange_failure_redirects_with_error(): void
    {
        $state = $this->connectService->generateState();

        $mockHandler = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_code'
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'invalid-code',
            'state' => $state,
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService, $httpClient);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=exchange_failed/');

        $controller->handle($req);

        $this->assertNull($this->tokenService->get());
    }

    public function test_handle_successful_exchange_stores_token_and_webhook_secret_then_redirects(): void
    {
        $state = $this->connectService->generateState();

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => true,
                'data' => [
                    'token' => 'plaintext-token',
                    'expires_at' => '2026-07-08T09:43:46+00:00',
                    'webhook_secret' => 'hex-secret',
                ]
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService, $httpClient);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/connected=1/');

        $controller->handle($req);

        $this->assertSame('plaintext-token', $this->tokenService->get());
        $this->assertSame('hex-secret', $this->tokenService->getWebhookSecret());
    }

    public function test_handle_rejects_empty_code(): void
    {
        $state = $this->connectService->generateState();

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => '',
            'state' => $state,
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_rejects_oversized_code(): void
    {
        $state = $this->connectService->generateState();

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => str_repeat('a', 129),
            'state' => $state,
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_rejects_empty_state(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => '',
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_rejects_wrongly_sized_state(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => str_repeat('a', 63),  // Should be 64
        ]);

        $controller = new CallbackController($this->connectService, $this->tokenService);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=missing_params/');

        $controller->handle($req);
    }

    public function test_handle_storage_failure_clears_token_and_redirects_with_error(): void
    {
        $state = $this->connectService->generateState();

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => true,
                'data' => [
                    'token' => 'plaintext-token',
                    'expires_at' => '2026-07-08T09:43:46+00:00',
                    'webhook_secret' => 'hex-secret',
                ]
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        // Use a mock TokenService that throws on store
        $tokenServiceMock = $this->createMock(\PaymentForm\Services\TokenServiceInterface::class);
        $tokenServiceMock->expects($this->once())->method('storeWebhookSecret');
        $tokenServiceMock->expects($this->once())->method('store')->willThrowException(new \RuntimeException('storage_error'));
        $tokenServiceMock->expects($this->once())->method('clear');

        $controller = new CallbackController($this->connectService, $tokenServiceMock, $httpClient);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=storage_failed/');

        $controller->handle($req);
    }
}
