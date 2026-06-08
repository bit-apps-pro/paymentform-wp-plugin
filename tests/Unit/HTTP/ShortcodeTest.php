<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP;

use PaymentForm\HTTP\Shortcode;
use PHPUnit\Framework\TestCase;

final class ShortcodeTest extends TestCase
{
    private Shortcode $shortcode;

    protected function setUp(): void
    {
        $this->shortcode = new Shortcode();
    }

    public function test_render_with_valid_id(): void
    {
        $result = $this->shortcode->render(['id' => 'form_123']);
        $expected = '<div class="paymentform-embed" data-form-id="form_123"></div>';
        self::assertSame($expected, $result);
    }

    public function test_render_with_empty_id_returns_empty(): void
    {
        $result = $this->shortcode->render(['id' => '']);
        self::assertSame('', $result);
    }

    public function test_render_with_missing_id_returns_empty(): void
    {
        $result = $this->shortcode->render([]);
        self::assertSame('', $result);
    }

    public function test_render_sanitizes_id(): void
    {
        $result = $this->shortcode->render(['id' => '../../etc/passwd']);
        $expected = '<div class="paymentform-embed" data-form-id="etcpasswd"></div>';
        self::assertSame($expected, $result);
    }

    public function test_render_with_attribute_injection(): void
    {
        $result = $this->shortcode->render(['id' => 'form_" data-evil="true']);
        // sanitize_key removes invalid chars (quotes, spaces), keeps alphanumeric, _, -
        $expected = '<div class="paymentform-embed" data-form-id="form_data-eviltrue"></div>';
        self::assertSame($expected, $result);
    }
}
