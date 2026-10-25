<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests\Rules;

use PaymentForm\Deps\BitApps\WPValidator\Rule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates a dashboard redirect path for the SSO login-link flow (spec §L1).
 *
 * The allowlist is deliberately strict: a single leading `/`, then only
 * path-safe characters (`A-Za-z0-9/_-`). This is the boundary defense against
 * open redirects — it rejects protocol-relative (`//host`), absolute
 * (`https://`), backslash, and query/fragment smuggling (`?`, `#`, `:`) before
 * the value is ever forwarded to the backend mint endpoint. It permits the two
 * sanctioned destinations `/myforms` and `/build/<id>` (ids are UUID-shaped,
 * i.e. alphanumeric + hyphen).
 *
 * Single source of truth for the pattern: both {@see \PaymentForm\HTTP\Requests\LoginLinkRequest}
 * (router-level rejection) and {@see \PaymentForm\HTTP\Controllers\LoginLinkController}
 * (defensive default-and-validate, since the router does not run rules() for a
 * direct action call) consult this rule.
 */
final class RedirectPathRule extends Rule
{
    public const PATTERN = '#^/[A-Za-z0-9/_-]*$#';

    public function validate($value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function message(): string
    {
        return 'The :attribute must be a relative path beginning with "/".';
    }
}
