<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Config;
use PHPUnit\Framework\TestCase;

/**
 * Covers the option helpers added to Config. The WP option functions are the
 * stubs from tests/bootstrap.php (TestWpFunctions::$options), so these assert
 * the stored key carries the VAR_PREFIX and the helpers map onto the right
 * primitives (add = create-once, delete = remove).
 */
class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_add_option_writes_a_prefixed_option(): void
    {
        $this->assertTrue(Config::addOption('refresh_lock', 'token|123'));

        // Stored under the prefixed name; the bare key alone resolves nothing.
        $this->assertSame('token|123', get_option('paymentform_refresh_lock'));
        $this->assertFalse(get_option('refresh_lock'));
        $this->assertSame('token|123', Config::getOption('refresh_lock'));
    }

    public function test_add_option_returns_false_when_option_already_exists(): void
    {
        $this->assertTrue(Config::addOption('refresh_lock', 'first'));
        $this->assertFalse(Config::addOption('refresh_lock', 'second'));
        $this->assertSame('first', Config::getOption('refresh_lock'));
    }

    public function test_delete_option_removes_a_prefixed_option(): void
    {
        Config::addOption('credentials', ['x' => 1]);
        $this->assertSame(['x' => 1], get_option('paymentform_credentials'));

        $this->assertTrue(Config::deleteOption('credentials'));
        $this->assertFalse(get_option('paymentform_credentials'));
        $this->assertFalse(Config::getOption('credentials'));
    }

    public function test_update_option_writes_a_prefixed_option(): void
    {
        $this->assertTrue(Config::updateOption('rewrite_rules_version', '2'));
        $this->assertSame('2', get_option('paymentform_rewrite_rules_version'));
    }

    public function test_with_prefix_applies_var_prefix(): void
    {
        $this->assertSame('paymentform_credentials', Config::withPrefix('credentials'));
    }

    public function test_swap_app_subdomain_rewrites_the_app_label(): void
    {
        $this->assertSame(
            'https://acme.paymentform.io',
            Config::swapAppSubdomain('https://app.paymentform.io', 'acme')
        );
    }

    public function test_swap_app_subdomain_preserves_scheme_and_port(): void
    {
        // Mirrors the dev `.env` shape (http + custom port): the renderer host
        // must keep both so the iframe targets the running dev server.
        $this->assertSame(
            'http://render.paymentform.btcd-test.io:8021',
            Config::swapAppSubdomain('http://app.paymentform.btcd-test.io:8021', 'render')
        );
    }

    public function test_swap_app_subdomain_returns_null_when_host_is_not_app(): void
    {
        $this->assertNull(Config::swapAppSubdomain('https://example.com', 'acme'));
    }

    public function test_swap_app_subdomain_returns_null_when_url_has_no_host(): void
    {
        $this->assertNull(Config::swapAppSubdomain('not-a-url', 'acme'));
    }

    public function test_dev_files_are_ignored_without_the_dev_opt_in(): void
    {
        $this->assertFalse(defined('PAYMENTFORM_DEV'));
        $dir = sys_get_temp_dir() . '/paymentform-config-' . bin2hex(random_bytes(4));
        mkdir($dir . '/frontend', 0777, true);
        file_put_contents($dir . '/frontend/.env', "APP_API_BASE_URL=http://localhost:9999\n");
        file_put_contents($dir . '/frontend/port', '5173');
        $previous = (new \ReflectionProperty(Config::class, 'pluginFile'))->getValue();

        try {
            Config::bootstrap($dir . '/paymentform.php');

            $this->assertSame('https://api.paymentform.io', Config::apiBaseUrl());
            $this->assertFalse(Config::hasDevApiBaseUrl());
            $this->assertFalse(Config::isDev());
        } finally {
            (new \ReflectionProperty(Config::class, 'pluginFile'))->setValue(null, $previous);
            unlink($dir . '/frontend/.env');
            unlink($dir . '/frontend/port');
            rmdir($dir . '/frontend');
            rmdir($dir);
        }
    }
}
