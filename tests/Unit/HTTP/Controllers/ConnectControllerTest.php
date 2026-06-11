<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;

class ConnectControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private ConnectController $controller;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->seedContainer([ConnectService::class => new ConnectService()]);
        $this->controller = new ConnectController();
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
    }

    public function test_show_returns_connect_url_with_state(): void
    {
        $data = $this->controller->show($this->makeWpKitRequest());

        $this->assertArrayHasKey('url', $data);
        $this->assertStringContainsString('state=', $data['url']);
        $this->assertStringContainsString(PAYMENTFORM_APP_BASE_URL, $data['url']);
        $this->assertStringContainsString('callback_url=', $data['url']);
    }

    public function test_show_generates_unique_states(): void
    {
        $data1 = $this->controller->show($this->makeWpKitRequest());
        $data2 = $this->controller->show($this->makeWpKitRequest());

        $this->assertNotSame($data1['url'], $data2['url']);
    }
}
