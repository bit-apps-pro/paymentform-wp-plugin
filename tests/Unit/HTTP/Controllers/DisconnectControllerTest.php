<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;

class DisconnectControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private TokenService $tokenService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->tokenService = new TokenService(new EncryptionService());
        $this->seedContainer([
            TokenService::class => $this->tokenService,
            ApiClient::class => new ApiClient(
                $this->tokenService,
                new TokenRefreshLock(),
                new HttpTransport(Config::apiBaseUrl())
            ),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
    }

    public function test_disconnect_returns_success_when_already_disconnected(): void
    {
        $data = (new DisconnectController())->disconnect($this->makeWpKitRequest(method: 'POST'));

        $this->assertSame(['status' => true], $data);
    }

    public function test_disconnect_clears_token(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        $this->assertSame('test-token', $this->tokenService->get());

        $data = (new DisconnectController())->disconnect($this->makeWpKitRequest(method: 'POST'));

        $this->assertSame(['status' => true], $data);
        $this->assertNull($this->tokenService->get());
    }
}
