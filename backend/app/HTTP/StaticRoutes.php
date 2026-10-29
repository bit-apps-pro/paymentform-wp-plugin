<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Names and public URLs of the `/paymentform/*` static routes.
 */
final class StaticRoutes
{
    public const PAGE_NAME = 'paymentform';

    /** Carries the route name on sites without URL rewriting, e.g. `/?paymentform_route=callback`. */
    public const QUERY_VAR = 'paymentform_route';

    public const CALLBACK = 'callback';

    public const WEBHOOK = 'webhook';

    public const SETUP_PROOF = 'setup-proof';

    /** Site-relative URL of a route: its rewrite path, or the query-var form where the server can't rewrite URLs. */
    public static function path(string $route): string
    {
        return self::usesRewritePaths()
            ? '/' . self::PAGE_NAME . '/' . $route
            : '/?' . http_build_query([self::QUERY_VAR => $route]);
    }

    /** Mirrors WP_Rewrite::using_mod_rewrite_permalinks(): plain and `/index.php/…` structures mean no server rewriting. */
    private static function usesRewritePaths(): bool
    {
        $structure = (string) get_option('permalink_structure');

        return $structure !== '' && !preg_match('#^/*index\.php#', $structure);
    }
}
