<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;
use WpHttpMock;

class StatusControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private TokenService $tokenService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        WpHttpMock::reset();
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

    /** @return array<string, mixed> */
    private function show(): array
    {
        return (new StatusController())->show($this->makeWpKitRequest());
    }

    public function test_show_returns_not_connected_when_no_token(): void
    {
        $this->assertSame(['connected' => false], $this->show());
    }

    public function test_show_returns_connected_when_api_succeeds(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1000 seconds'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(200, ['data' => ['user' => ['id' => 1, 'email' => 'foo@bar'], 'tenant' => 't1']]);

        $data = $this->show();

        $this->assertTrue($data['connected']);
        $this->assertSame(1, $data['user']['id']);
        $this->assertSame('foo@bar', $data['user']['email']);
        $this->assertSame('t1', $data['tenant']);
        $this->assertSame(1000, $data['expiresIn']);
        $this->assertNotNull($data['expiresAt']);
    }

    public function test_show_returns_disconnected_on_token_revoked(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(401, 'Unauthorized');
        WpHttpMock::queue(200, ['data' => ['token' => 'new-token', 'expires_at' => (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM)]]);
        WpHttpMock::queue(401, 'Unauthorized');

        $data = $this->show();

        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
        $this->assertNull($this->tokenService->get());
    }

    public function test_show_returns_disconnected_on_other_api_error(): void
    {
        $this->tokenService->store('test-token', (new \DateTime('+1 day'))->format(\DateTimeInterface::ATOM));
        WpHttpMock::queue(500, 'Internal Server Error');

        $data = $this->show();

        $this->assertFalse($data['connected']);
        $this->assertSame('token_revoked', $data['error']);
    }
}
