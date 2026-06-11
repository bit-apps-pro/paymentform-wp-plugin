<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Middleware;

use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PHPUnit\Framework\TestCase;

final class NonceCheckerMiddlewareTest extends TestCase
{
    use InteractsWithWpKit;

    private NonceCheckerMiddleware $middleware;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->resetWpKitResponse();
        $this->middleware = new NonceCheckerMiddleware();
    }

    protected function tearDown(): void
    {
        $this->resetWpKitResponse();
        \TestWpFunctions::reset();
    }

    public function test_valid_nonce_header_passes(): void
    {
        $request = $this->makeWpKitRequest(['X-WP-Nonce' => 'valid-nonce']);

        $this->assertTrue($this->middleware->handle($request));
    }

    public function test_valid_wpnonce_param_fallback_passes(): void
    {
        $request = $this->makeWpKitRequest([], ['_wpnonce' => 'valid-nonce']);

        $this->assertTrue($this->middleware->handle($request));
    }

    public function test_missing_nonce_returns_401_invalid_nonce(): void
    {
        $result = $this->middleware->handle($this->makeWpKitRequest());

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('error', Response::getStatus());
        $this->assertSame(401, Response::getHttpStatusCode());
        $this->assertSame('INVALID_NONCE', Response::getCode());
    }

    public function test_invalid_nonce_returns_401_invalid_nonce(): void
    {
        \TestWpFunctions::setNonceValidator(static fn (): bool => false);

        $result = $this->middleware->handle(
            $this->makeWpKitRequest(['X-WP-Nonce' => 'expired-nonce'])
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, Response::getHttpStatusCode());
        $this->assertSame('INVALID_NONCE', Response::getCode());
    }

    public function test_nonce_is_verified_against_wp_rest_action(): void
    {
        $seenAction = null;
        \TestWpFunctions::setNonceValidator(
            static function (?string $nonce, string $action) use (&$seenAction): int {
                $seenAction = $action;

                return 1;
            }
        );

        $this->middleware->handle($this->makeWpKitRequest(['X-WP-Nonce' => 'abc']));

        $this->assertSame('wp_rest', $seenAction);
    }

    public function test_array_wpnonce_param_returns_401_without_php_warning(): void
    {
        // phpunit.xml.dist converts warnings to exceptions; passing an array
        // where a string is expected must not trigger "Array to string
        // conversion" before the 401 fires.
        $request = $this->makeWpKitRequest([], ['_wpnonce' => ['injected']]);

        $result = $this->middleware->handle($request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, Response::getHttpStatusCode());
        $this->assertSame('INVALID_NONCE', Response::getCode());
    }
}
