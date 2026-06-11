<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetPluginSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetPluginSingleton();
    }

    public function test_load_creates_singleton_and_reports_already_loaded(): void
    {
        $this->assertNull(Plugin::instance());

        $this->assertTrue(Plugin::load());
        $this->assertInstanceOf(Plugin::class, Plugin::instance());

        $this->assertFalse(Plugin::load(), 'second load must be a no-op');
    }

    public function test_load_keeps_the_first_instance(): void
    {
        Plugin::load();
        $first = Plugin::instance();

        Plugin::load();

        $this->assertSame($first, Plugin::instance());
    }

    public function test_middlewares_exposes_the_nonce_and_cap_registry(): void
    {
        Plugin::load();

        $this->assertSame(
            [
                'nonce' => NonceCheckerMiddleware::class,
                'cap'   => CapabilityCheckerMiddleware::class,
            ],
            Plugin::instance()->middlewares()
        );
    }

    private function resetPluginSingleton(): void
    {
        (new ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, null);
    }
}
