<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class RefreshControllerTest extends TestCase
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

    public function test_refresh_returns_400_when_not_connected(): void
    {
        $controller = new RefreshController($this->tokenService, $this->apiClient);
        $req = new MockWpRestRequest('POST', '/paymentform/v1/refresh');
        $response = $controller->refresh($req);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('not_connected', $data['code']);
    }
}
