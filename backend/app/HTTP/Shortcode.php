<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

final class Shortcode
{
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
        return sprintf(
            '<div class="paymentform-embed" data-form-id="%s"></div>',
            \esc_attr($formId)
        );
    }
}
