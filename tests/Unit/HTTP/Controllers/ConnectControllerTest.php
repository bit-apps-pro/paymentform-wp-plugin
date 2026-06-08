<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\Services\ConnectService;
use PaymentForm\Tests\Unit\HTTP\Controllers\MockWpRestRequest;
use PHPUnit\Framework\TestCase;

class ConnectControllerTest extends TestCase
{
    private ConnectService $connectService;
    private ConnectController $controller;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->connectService = new ConnectService();
        $this->controller = new ConnectController($this->connectService);
    }

    public function test_show_returns_connect_url_with_state(): void
    {
        $req = new MockWpRestRequest('GET', '/paymentform/v1/connect-url');

        $response = $this->controller->show($req);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('url', $data);
        $this->assertStringContainsString('state=', $data['url']);
        $this->assertStringContainsString(PAYMENTFORM_APP_BASE_URL, $data['url']);
        $this->assertStringContainsString('callback_url=', $data['url']);
    }

    public function test_show_generates_unique_states(): void
    {
        $req1 = new MockWpRestRequest('GET', '/paymentform/v1/connect-url');
        $req2 = new MockWpRestRequest('GET', '/paymentform/v1/connect-url');

        $response1 = $this->controller->show($req1);
        $response2 = $this->controller->show($req2);

        $data1 = $response1->get_data();
        $data2 = $response2->get_data();

        $this->assertNotSame($data1['url'], $data2['url']);
    }
}
