<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

class StatusControllerTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
        $this->encryption = new EncryptionService();
    }

    private function apiClient(TokenService $tokenService): ApiClient
    {
        return new ApiClient($tokenService, new TokenRefreshLock(), PAYMENTFORM_API_BASE_URL);
    }

    public function test_show_returns_not_connected_when_no_token(): void
    {
        $tokenService = new TokenService($this->encryption);
        $controller = new StatusController($tokenService, $this->apiClient($tokenService));

        $response = $controller->show(new MockWpRestRequest('GET', '/paymentform/v1/status'));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['connected']);
    }

    public function test_show_returns_connected_when_api_succeeds(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1000 seconds'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(200, ['data' => ['user' => ['id' => 1, 'email' => 'foo@bar'], 'tenant' => 't1']]);

        $controller = new StatusController($tokenService, $this->apiClient($tokenService));
        $data = $controller->show(new MockWpRestRequest('GET', '/paymentform/v1/status'))->get_data();

        $this->assertTrue($data['connected']);
        $this->assertSame(1, $data['user']['id']);
        $this->assertSame('foo@bar', $data['user']['email']);
        $this->assertSame('t1', $data['tenant']);
        $this->assertSame(1000, $data['expiresIn']);
        $this->assertNotNull($data['expiresAt']);
    }

    public function test_show_returns_disconnected_on_token_revoked(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM)]]);
        WpHttpMock::queue(401, 'Unauthorized');

        $controller = new StatusController($tokenService, $this->apiClient($tokenService));
        $data = $controller->show(new MockWpRestRequest('GET', '/paymentform/v1/status'))->get_data();

        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
        $this->assertNull($tokenService->get());
    }

    public function test_show_returns_disconnected_on_other_api_error(): void
    {
        $tokenService = new TokenService($this->encryption);
        $tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(500, 'Internal Server Error');

        $controller = new StatusController($tokenService, $this->apiClient($tokenService));
        $data = $controller->show(new MockWpRestRequest('GET', '/paymentform/v1/status'))->get_data();

        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
    }
}
