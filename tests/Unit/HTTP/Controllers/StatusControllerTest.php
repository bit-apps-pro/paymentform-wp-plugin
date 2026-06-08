<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use PHPUnit\Framework\TestCase;

class StatusControllerTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->encryption = new EncryptionService();
    }

    public function test_show_returns_not_connected_when_no_token(): void
    {
        $tokenService = new TokenService($this->encryption);
        $apiClient = new ApiClient(
            $tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL
        );

        $controller = new StatusController($tokenService, $apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/status');
        $response = $controller->show($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['connected']);
    }

    public function test_show_returns_connected_when_api_succeeds(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1000 seconds'))->format(\DateTimeInterface::ATOM));

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'user' => ['id' => 1, 'email' => 'foo@bar'],
                    'tenant' => 't1'
                ]
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient(
            $tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL,
            $httpClient
        );

        $controller = new StatusController($tokenService, $apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/status');
        $response = $controller->show($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['connected']);
        $this->assertSame(1, $data['user']['id']);
        $this->assertSame('foo@bar', $data['user']['email']);
        $this->assertSame('t1', $data['tenant']);
        $this->assertSame(1000, $data['expires_in']);
        $this->assertNotNull($data['expires_at']);
    }

    public function test_show_returns_disconnected_on_token_revoked(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));

        $mockHandler = new MockHandler([
            new Response(401, [], 'Unauthorized'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => ['token' => 'new-token', 'expires_at' => (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM)]
            ])),
            new Response(401, [], 'Unauthorized'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient(
            $tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL,
            $httpClient
        );

        $controller = new StatusController($tokenService, $apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/status');
        $response = $controller->show($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
        $this->assertNull($tokenService->get());
    }

    public function test_show_returns_disconnected_on_other_api_error(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));

        $mockHandler = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient(
            $tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL,
            $httpClient
        );

        $controller = new StatusController($tokenService, $apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/status');
        $response = $controller->show($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
    }
}
