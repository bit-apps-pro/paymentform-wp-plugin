<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Config;
use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Plugin;
use PaymentForm\Providers\Container;
use PaymentForm\Providers\HookProvider;
use PaymentForm\Providers\PluginServiceProvider;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

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

    /**
     * The admin SPA config is delivered through the WP 6.7 Script Module Data
     * API: on the plugin page the prod enqueue registers a
     * `script_module_data_@paymentform/admin` filter that merges the config onto
     * the data WP passes while printing the enqueued module. WP core auto-prints
     * the module and its data tag — there is no manual printer to assert.
     */
    public function test_admin_page_registers_the_script_module_data_filter(): void
    {
        $this->enqueueAdminAssetsOnPluginPage();

        $data = apply_filters('script_module_data_@paymentform/admin', []);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('nonce', $data);
        $this->assertArrayHasKey('restUrl', $data);
        $this->assertArrayHasKey('connected', $data);
        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('siteUrl', $data);
        $this->assertArrayHasKey('appBaseUrl', $data);
        $this->assertArrayHasKey('rendererBaseUrl', $data);
        $this->assertSame('http://example.test/wp-json/paymentform/v1/', $data['restUrl']);

        // The callback merges onto incoming data — pre-existing keys survive.
        $merged = apply_filters('script_module_data_@paymentform/admin', ['x' => 1]);
        $this->assertSame(1, $merged['x']);
    }

    public function test_admin_config_filter_is_not_registered_off_the_plugin_page(): void
    {
        $this->makeProvider()->enqueueAdminAssets('some_other_admin_page');

        $this->assertSame(
            [],
            apply_filters('script_module_data_@paymentform/admin', []),
            'Off the plugin page the admin config filter must not be registered.'
        );
    }

    /**
     * Drive the production admin enqueue on the plugin's top-level admin page.
     * Config::bootstrap points pluginDir() at the real plugin so the built
     * frontend/dist/admin.js is found and the prod (module) path runs; the
     * provider's container is seeded so the inline-config printer is wired.
     */
    private function enqueueAdminAssetsOnPluginPage(): void
    {
        $this->makeProvider()->enqueueAdminAssets('toplevel_page_paymentform');
    }

    private function makeProvider(): PluginServiceProvider
    {
        Config::bootstrap(dirname(__DIR__, 2) . '/paymentform.php');

        // The admin-config printer reads wp_get_current_user()->exists()/->user_email.
        // The harness's default user is a bare stdClass; give it a WP_User-shaped
        // double so the prod enqueue path runs to completion.
        \TestWpFunctions::setCurrentUser(new class {
            public string $user_email = 'admin@example.test';
            public function exists(): bool
            {
                return true;
            }
        });

        $container = new Container();
        $container[EncryptionService::class] = static fn (): EncryptionService => new EncryptionService();
        $container[TokenService::class] = static fn (Container $c): TokenService
            => new TokenService($c[EncryptionService::class]);

        $provider = new PluginServiceProvider();
        (new ReflectionProperty(PluginServiceProvider::class, 'container'))
            ->setValue($provider, $container);

        return $provider;
    }

    private function resetPluginSingleton(): void
    {
        (new ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, null);
    }
}
