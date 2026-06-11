<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Plugin;
use PaymentForm\Providers\HookProvider;
use PaymentForm\Providers\PluginServiceProvider;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->resetPluginSingleton();
        ServiceContainer::reset();
    }

    protected function tearDown(): void
    {
        $this->resetPluginSingleton();
        ServiceContainer::reset();
        \TestWpFunctions::reset();
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

    public function test_load_registers_service_bindings_at_load_time(): void
    {
        Plugin::load();

        // PluginServiceProvider::register() must run inside load() itself —
        // not deferred to plugins_loaded — so early consumers (e.g. the
        // activation request) can resolve services before any hook fires.
        $this->assertInstanceOf(ApiClient::class, ServiceContainer::get(ApiClient::class));
    }

    public function test_load_defers_provider_boot_to_plugins_loaded(): void
    {
        Plugin::load();

        $callbacks = \TestWpFunctions::getActions('plugins_loaded');
        $this->assertCount(1, $callbacks);
        $this->assertIsArray($callbacks[0]);
        $this->assertInstanceOf(PluginServiceProvider::class, $callbacks[0][0]);
        $this->assertSame('boot', $callbacks[0][1]);
    }

    public function test_load_constructs_hook_provider_listening_on_rest_api_init(): void
    {
        Plugin::load();

        // HookProvider's constructor registers the wp-kit route loading on
        // rest_api_init — the sole entrypoint of the plugin's REST layer.
        $callbacks = \TestWpFunctions::getActions('rest_api_init');
        $this->assertCount(1, $callbacks);
        $this->assertIsArray($callbacks[0]);
        $this->assertInstanceOf(HookProvider::class, $callbacks[0][0]);
        $this->assertSame('loadApiRoutes', $callbacks[0][1]);
    }

    public function test_second_load_does_not_rewire(): void
    {
        Plugin::load();
        Plugin::load();

        $this->assertCount(1, \TestWpFunctions::getActions('plugins_loaded'));
        $this->assertCount(1, \TestWpFunctions::getActions('rest_api_init'));
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
