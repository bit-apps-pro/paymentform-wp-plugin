<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP\Middleware;

use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Capabilities::filter() semantics the middleware relies on:
 * pass when current_user_can($cap) OR
 * current_user_can(apply_filters($cap, 'manage_options')).
 */
final class CapabilityCheckerMiddlewareTest extends TestCase
{
    use InteractsWithWpKit;

    private CapabilityCheckerMiddleware $middleware;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->resetWpKitResponse();
        $this->middleware = new CapabilityCheckerMiddleware();
    }

    protected function tearDown(): void
    {
        $this->resetWpKitResponse();
        \TestWpFunctions::reset();
    }

    public function test_user_with_plugin_cap_passes(): void
    {
        \TestWpFunctions::setGrantedCaps(['paymentform_manage']);

        // No explicit $cap: exercises the 'paymentform_manage' default that
        // covers a bare `cap` middleware string (no colon, no positional arg).
        $this->assertTrue($this->middleware->handle($this->makeWpKitRequest()));
    }

    public function test_manage_options_filter_default_passes(): void
    {
        \TestWpFunctions::setGrantedCaps(['manage_options']);

        $this->assertTrue(
            $this->middleware->handle($this->makeWpKitRequest(), 'paymentform_manage')
        );
    }

    public function test_user_with_neither_cap_gets_403_not_authorized(): void
    {
        \TestWpFunctions::setGrantedCaps([]);

        $result = $this->middleware->handle($this->makeWpKitRequest(), 'paymentform_manage');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('error', Response::getStatus());
        $this->assertSame(403, Response::getHttpStatusCode());
        $this->assertSame('NOT_AUTHORIZED', Response::getCode());
    }

    public function test_filter_based_grant_passes(): void
    {
        \TestWpFunctions::setGrantedCaps(['edit_others_posts']);
        add_filter('paymentform_manage', static fn (): string => 'edit_others_posts');

        $this->assertTrue(
            $this->middleware->handle($this->makeWpKitRequest(), 'paymentform_manage')
        );
    }
}
