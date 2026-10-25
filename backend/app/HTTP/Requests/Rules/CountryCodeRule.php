<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests\Rules;

use PaymentForm\Deps\BitApps\WPValidator\Rule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates an ISO 3166-1 alpha-2 country code: exactly two uppercase ASCII
 * letters. wp-validator has no `regex`/`alpha` rule, and `size:2`+`uppercase`
 * is too loose (it admits "A1", "12", and 2-byte multibyte chars), so this
 * reproduces the previous inline `^[A-Z]{2}$` guard at the boundary.
 */
final class CountryCodeRule extends Rule
{
    private const PATTERN = '/^[A-Z]{2}$/';

    public function validate($value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function message(): string
    {
        return 'The :attribute must be a valid ISO 3166-1 alpha-2 country code.';
    }
}
