<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Providers;

use PaymentForm\Config;
use PaymentForm\HTTP\Shortcode;
use PaymentForm\Providers\PluginServiceProvider;
use PHPUnit\Framework\TestCase;

final class FrontendAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        Config::bootstrap(dirname(__DIR__, 3) . '/paymentform.php');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post']);
    }

    public function test_view_script_is_registered_even_when_post_content_has_no_shortcode(): void
    {
        unset($GLOBALS['post']);

        (new PluginServiceProvider)->enqueueFrontendAssets();

        $registered = \TestWpFunctions::getRegisteredScripts();
        self::assertArrayHasKey(Shortcode::VIEW_HANDLE, $registered);
        self::assertTrue($registered[Shortcode::VIEW_HANDLE]['in_footer']);
        self::assertSame('paymentformView', \TestWpFunctions::getLocalizedScripts()[Shortcode::VIEW_HANDLE]['name']);
    }

    public function test_view_script_is_not_enqueued_until_a_shortcode_renders(): void
    {
        (new PluginServiceProvider)->enqueueFrontendAssets();

        self::assertSame([], \TestWpFunctions::getEnqueuedScripts());
    }
}
