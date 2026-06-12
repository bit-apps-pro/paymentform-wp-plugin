<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Providers;

use PaymentForm\Config;
use PaymentForm\Providers\Container;
use PaymentForm\Providers\PluginServiceProvider;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Locks the WP 6.7 Script Module Data delivery for the two Script Modules:
 *  - the admin SPA module (`@paymentform/admin`) — `createConfigVariable` merges
 *    the full admin config onto the data WP passes while printing the module;
 *  - the block-view module (`paymentform-form-view-script`) — a filter merges the
 *    resolved renderer base URL.
 *
 * Both run through `Hooks::addFilter` → the global `add_filter`, so the harness
 * records them and `apply_filters` replays them.
 */
final class ScriptModuleDataTest extends TestCase
{
    private const ADMIN_FILTER = 'script_module_data_@paymentform/admin';
    private const VIEW_FILTER = 'script_module_data_paymentform-form-view-script';

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        Config::bootstrap(dirname(__DIR__, 3) . '/paymentform.php');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post']);
        \TestWpFunctions::reset();
    }

    public function test_admin_filter_returns_the_full_config(): void
    {
        $this->makeProvider()->enqueueAdminAssets('toplevel_page_paymentform');

        $data = apply_filters(self::ADMIN_FILTER, []);

        $this->assertIsArray($data);
        foreach (
            [
                'nonce', 'restUrl', 'adminUrl', 'connected', 'hasConnection',
                'expiresAt', 'tenant', 'version', 'siteUrl', 'adminEmail',
                'cfCountry', 'emailVerified', 'appBaseUrl', 'rendererBaseUrl',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $data, "Missing config key: {$key}");
        }

        $this->assertIsString($data['nonce']);
        $this->assertIsBool($data['connected']);
        $this->assertSame(Config::VERSION, $data['version']);
        $this->assertSame('http://example.test/wp-json/paymentform/v1/', $data['restUrl']);
    }

    public function test_admin_filter_preserves_preexisting_data(): void
    {
        $this->makeProvider()->enqueueAdminAssets('toplevel_page_paymentform');

        $data = apply_filters(self::ADMIN_FILTER, ['preexisting' => 'x']);

        $this->assertSame('x', $data['preexisting']);
    }

    public function test_block_view_filter_returns_the_renderer_base_url(): void
    {
        \TestWpFunctions::setHasBlock(true);
        $GLOBALS['post'] = (object) ['post_content' => '<!-- wp:paymentform/form /-->'];

        $this->makeProvider()->enqueueFrontendAssets();

        $data = apply_filters(self::VIEW_FILTER, []);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('rendererBaseUrl', $data);
        // No connection seeded → falls back to the static renderer base URL.
        $this->assertSame(Config::rendererBaseUrl(), $data['rendererBaseUrl']);
    }

    public function test_block_view_filter_is_not_registered_without_the_block(): void
    {
        \TestWpFunctions::setHasBlock(false);
        $GLOBALS['post'] = (object) ['post_content' => 'plain content'];

        $this->makeProvider()->enqueueFrontendAssets();

        $this->assertSame([], apply_filters(self::VIEW_FILTER, []));
    }

    private function makeProvider(): PluginServiceProvider
    {
        // Mirror PluginTest::makeProvider — a WP_User-shaped double so the admin
        // config builder reads user_email/exists() without fataling.
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
}
