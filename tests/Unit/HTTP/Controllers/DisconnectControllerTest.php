<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class DisconnectControllerTest extends TestCase
{
    private TokenService $tokenService;
    private EncryptionService $encryption;
    private ApiClient $apiClient;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->encryption = new EncryptionService();
        $this->tokenService = new TokenService($this->encryption);
        $this->apiClient = new ApiClient(
            $this->tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL
        );
    }

    public function test_disconnect_returns_200_when_already_disconnected(): void
    {
        $controller = new DisconnectController($this->tokenService, $this->apiClient);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/disconnect');
        $response = $controller->disconnect($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['status']);
    }

    public function test_disconnect_clears_token(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        $this->assertSame('test-token', $this->tokenService->get());

        $controller = new DisconnectController($this->tokenService, $this->apiClient);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/disconnect');
        $response = $controller->disconnect($req);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($this->tokenService->get());
    }
}
