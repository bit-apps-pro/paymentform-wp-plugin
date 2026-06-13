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
}
