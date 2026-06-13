<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\TestCase;
use RedirectException;
use WpHttpMock;

class CallbackControllerTest extends TestCase
{
    private ConnectService $connectService;
    private TokenService $tokenService;
    private EncryptionService $encryption;
    private SetupApiClient $setupApi;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->connectService = new ConnectService();
        $this->setupApi = new SetupApiClient(new HttpTransport(Config::apiBaseUrl()));
    }

    private function controller(): CallbackController
    {
        return new CallbackController($this->connectService, $this->tokenService, $this->setupApi);
    }

    public function test_handle_missing_code_redirects_with_error(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', ['state' => 'valid-state']);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_missing_state_redirects_with_error(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', ['code' => 'auth-code']);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_invalid_state_redirects_with_error(): void
    {
        $invalidState = bin2hex(random_bytes(32));

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $invalidState,
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=INVALID_STATE/');

        $this->controller()->handle($req);
    }

    public function test_handle_exchange_failure_redirects_with_error(): void
    {
        $state = $this->connectService->generateState();
        WpHttpMock::queue(400, ['error_code' => 'INVALID_EXCHANGE_CODE']);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'invalid-code',
            'state' => $state,
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=EXCHANGE_FAILED.*upstream=INVALID_EXCHANGE_CODE/');

        $this->controller()->handle($req);
    }

    public function test_handle_successful_exchange_stores_token_and_webhook_secret_then_redirects(): void
    {
        $state = $this->connectService->generateState();
        WpHttpMock::queue(200, [
            'status' => true,
            'data' => [
                'token' => 'plaintext-token',
                'expires_at' => '2026-07-08T09:43:46+00:00',
                'webhook_secret' => 'hex-secret',
                'tenant' => 'ws-test',
            ],
        ]);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/connected=1/');

        try {
            $this->controller()->handle($req);
        } finally {
            $this->assertSame('plaintext-token', $this->tokenService->get());
            $this->assertSame('hex-secret', $this->tokenService->getWebhookSecret());
            $this->assertSame('ws-test', $this->tokenService->getTenant());
        }
    }

    public function test_handle_rejects_empty_code(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => '',
            'state' => $this->connectService->generateState(),
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_rejects_oversized_code(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => str_repeat('a', 129),
            'state' => $this->connectService->generateState(),
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_rejects_empty_state(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => '',
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_rejects_wrongly_sized_state(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => str_repeat('a', 63),
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=MISSING_PARAMS/');

        $this->controller()->handle($req);
    }

    public function test_handle_storage_failure_clears_token_and_redirects_with_error(): void
    {
        $state = $this->connectService->generateState();
        WpHttpMock::queue(200, [
            'status' => true,
            'data' => [
                'token' => 'plaintext-token',
                'expires_at' => '2026-07-08T09:43:46+00:00',
                'webhook_secret' => 'hex-secret',
            ],
        ]);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        $tokenServiceMock = $this->createMock(\PaymentForm\Services\TokenServiceInterface::class);
        $tokenServiceMock->expects($this->once())->method('storeWebhookSecret');
        $tokenServiceMock->expects($this->once())->method('store')->willThrowException(new \RuntimeException('storage_error'));
        $tokenServiceMock->expects($this->once())->method('clear');

        $controller = new CallbackController($this->connectService, $tokenServiceMock, $this->setupApi);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=STORAGE_FAILED/');

        $controller->handle($req);
    }

    public function test_handle_drops_attacker_controlled_upstream_value(): void
    {
        $state = $this->connectService->generateState();
        WpHttpMock::queue(400, ['error_code' => '<script>alert(1)</script>']);

        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'invalid-code',
            'state' => $state,
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessageMatches('/error=EXCHANGE_FAILED/');

        try {
            $this->controller()->handle($req);
        } catch (RedirectException $e) {
            $this->assertStringNotContainsString('upstream=', $e->location);
            throw $e;
        }
    }
}
