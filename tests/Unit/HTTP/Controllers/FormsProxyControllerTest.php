<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class FormsProxyControllerTest extends TestCase
{
    private ApiClient $apiClient;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $encryption = new EncryptionService();
        $tokenService = new TokenService($encryption);
        $this->apiClient = new ApiClient(
            $tokenService,
            new TokenRefreshLock(),
            PAYMENTFORM_API_BASE_URL
        );
    }

    public function test_show_returns_400_for_invalid_id(): void
    {
        $controller = new FormsProxyController($this->apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/forms/abc', ['id' => 'abc']);
        $response = $controller->show($req);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('INVALID_FORM_ID', $data['error_code']);
    }

    public function test_show_returns_400_for_non_positive_id(): void
    {
        $controller = new FormsProxyController($this->apiClient);
        $req = new MockWpRestRequest('GET', '/paymentform/v1/forms/0', ['id' => '0']);
        $response = $controller->show($req);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['status']);
        $this->assertSame('INVALID_FORM_ID', $data['error_code']);
    }
}
