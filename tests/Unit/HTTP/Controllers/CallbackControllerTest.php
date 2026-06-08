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
        $req = new MockWpRestRequest('GET', '/paymentform/v1/callback', [
            'code' => 'auth-code',
            'state' => 'invalid-state',
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
}
