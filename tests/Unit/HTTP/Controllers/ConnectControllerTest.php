<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\IssuesConnectState;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;

class ConnectControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use IssuesConnectState;
    use SeedsServiceContainer;

    private ConnectService $connectService;

    private ConnectController $controller;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->resetWpKitResponse();
        $this->connectService = new ConnectService();
        $this->seedContainer([ConnectService::class => $this->connectService]);
        $this->controller = new ConnectController();
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
        $this->resetWpKitResponse();
    }

    public function test_show_returns_connect_url_with_state(): void
    {
        $data = $this->controller->show($this->makeWpKitRequest());

        $this->assertArrayHasKey('url', $data);
        $this->assertStringContainsString('state=', $data['url']);
        $this->assertStringContainsString(Config::appBaseUrl(), $data['url']);
        $this->assertStringContainsString('callback_url=', $data['url']);
    }

    public function test_show_issues_redeemable_state_for_current_user(): void
    {
        $data = $this->controller->show($this->makeWpKitRequest());

        $this->assertSame(1, $this->connectService->consumeState($this->connectUrlParams($data['url'])['state']));
    }

    public function test_show_returns_storage_failed_when_state_cannot_be_persisted(): void
    {
        $GLOBALS['wpdb']->insertFails = true;

        $result = $this->controller->show($this->makeWpKitRequest());

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('error', Response::getStatus());
        $this->assertSame(500, Response::getHttpStatusCode());
        $this->assertSame(['status' => false, 'error_code' => 'STORAGE_FAILED'], Response::getData());
        $this->assertSame('STORAGE_FAILED', Response::getCode());
    }

    public function test_show_generates_unique_states(): void
    {
        $data1 = $this->controller->show($this->makeWpKitRequest());
        $data2 = $this->controller->show($this->makeWpKitRequest());

        $this->assertNotSame($data1['url'], $data2['url']);
    }
}
