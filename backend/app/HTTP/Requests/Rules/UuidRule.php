<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests\Rules;

use PaymentForm\Deps\BitApps\WPValidator\Rule;

/**
 * Validates a canonical RFC 4122 UUID string (case-insensitive). Any
 * version/variant is accepted — backend form ids are UUIDs and we only need to
 * reject non-UUID shapes (numeric ids, empty strings) at the boundary, not pin
 * a specific version.
 */
final class UuidRule extends Rule
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function validate($value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function message(): string
    {
        return 'The :attribute must be a valid UUID.';
    }
}
