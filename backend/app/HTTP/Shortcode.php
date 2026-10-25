<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

final class Shortcode
{
    /** Classic script handle that turns `.paymentform-embed` placeholders into iframes. */
    public const VIEW_HANDLE = 'paymentform-shortcode-view';

    public function register(): void
    {
        \add_shortcode('paymentform', [$this, 'render']);
    }

    public function render(array|string $atts): string
    {
        $atts = \shortcode_atts(['id' => ''], (array) $atts, 'paymentform');
        $formId = \sanitize_key((string) $atts['id']);
        if ($formId === '') {
            return '';
        }
        // Enqueue from render so shortcodes in widgets, templates and builders load the view too.
        \wp_enqueue_script(self::VIEW_HANDLE);

        return sprintf(
            '<div class="paymentform-embed" data-form-id="%s"></div>',
            \esc_attr($formId)
        );
    }
}
