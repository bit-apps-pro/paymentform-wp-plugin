<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\SeedsServiceContainer;
use PHPUnit\Framework\TestCase;

class RefreshControllerTest extends TestCase
{
    use InteractsWithWpKit;
    use SeedsServiceContainer;

    private TokenService $tokenService;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        \WpHttpMock::reset();
        $this->resetWpKitResponse();
        $this->tokenService = new TokenService(new EncryptionService());
        $this->seedContainer([
            TokenService::class => $this->tokenService,
            ApiClient::class => new ApiClient(
                $this->tokenService,
                new TokenRefreshLock(),
                new HttpTransport(PAYMENTFORM_API_BASE_URL)
            ),
        ]);
    }

    protected function tearDown(): void
    {
        ServiceContainer::reset();
        $this->resetWpKitResponse();
    }

    public function test_refresh_returns_400_when_not_connected(): void
    {
        $response = (new RefreshController())->refresh($this->makeWpKitRequest(method: 'POST'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, Response::getHttpStatusCode());
        $this->assertSame('NOT_CONNECTED', Response::getCode());
        $this->assertSame(['status' => false, 'error_code' => 'NOT_CONNECTED'], Response::getData());
    }

    public function test_refresh_returns_flat_camelcase_expiry_payload_on_success(): void
    {
        $this->tokenService->store('old-token', (new \DateTime('+10 seconds'))->format(\DateTimeInterface::ATOM), 'tenant1');
        $newExpiry = (new \DateTime('+1000 seconds'))->format(\DateTimeInterface::ATOM);
        \WpHttpMock::queue(200, ['data' => ['token' => 'fresh-token', 'expires_at' => $newExpiry]]);

        $result = (new RefreshController())->refresh($this->makeWpKitRequest(method: 'POST'));

        // Flat, single-level, camelCase — mirrors StatusController so request()'s
        // single envelope-unwrap surfaces { expiresAt, expiresIn } directly rather
        // than nesting them one level deeper under `data`.
        $this->assertIsArray($result);
        $this->assertSame(['expiresAt', 'expiresIn'], array_keys($result));
        $this->assertSame($newExpiry, $result['expiresAt']);
        $this->assertIsInt($result['expiresIn']);
        $this->assertGreaterThan(900, $result['expiresIn']);
        $this->assertLessThanOrEqual(1000, $result['expiresIn']);
    }
}
