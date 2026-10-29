<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\RouteRegister;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\StaticRouter;
use PaymentForm\HTTP\StaticRoutes;
use WP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires wp-kit's StaticRouter for the `/paymentform/*` front-end routes.
 */
final class StaticRouteProvider
{
    /** Ahead of StaticRouter's own parse_request handler (priority 10). */
    private const QUERY_ROUTE_PRIORITY = 9;

    private StaticRouter $router;

    /**
     * Registers the routes; must run at include time so the activation request's flush sees them.
     * Dispatches on parse_request: every route responds and exits before the main query 404s.
     */
    public function __construct()
    {
        $this->router = (new StaticRouter(StaticRoutes::PAGE_NAME, Config::withPrefix('activate'), Config::withPrefix('deactivate')))
            ->dispatchOnParseRequest();
        $this->router->loadRoutesFromFile(Config::pluginDir() . 'backend/hooks/static.php');

        Hooks::addFilter('query_vars', [$this, 'addRouteQueryVar']);
        Hooks::addAction('parse_request', [$this, 'mapQueryRoute'], self::QUERY_ROUTE_PRIORITY);
        Hooks::addFilter('redirect_canonical', [$this, 'skipCanonicalRedirect']);
    }

    /** Stops WP's 404 slug guess from 301-ing unmatched route URLs (bare /paymentform, GET on the webhook) to a lookalike post. */
    public function skipCanonicalRedirect(mixed $redirectUrl): mixed
    {
        // The parsed path, not query vars: WP resolves a missing page's slug as a post `name`.
        $path = ($GLOBALS['wp'] ?? null) instanceof WP ? trim((string) $GLOBALS['wp']->request, '/') : '';
        $isRouteUrl = $path === StaticRoutes::PAGE_NAME || str_starts_with($path, StaticRoutes::PAGE_NAME . '/');

        return $isRouteUrl ? false : $redirectUrl;
    }

    /**
     * Registers the route query var so WP parses it from the query string.
     *
     * @param list<string> $vars
     *
     * @return list<string>
     */
    public function addRouteQueryVar(array $vars): array
    {
        $vars[] = StaticRoutes::QUERY_VAR;

        return $vars;
    }

    /** Points a `?paymentform_route=<name>` request at that route's path so StaticRouter matches it. */
    public function mapQueryRoute(WP $wp): void
    {
        $route = $wp->query_vars[StaticRoutes::QUERY_VAR] ?? null;
        if ((string) $wp->request !== '' || !\in_array($route, $this->routeNames(), true)) {
            return;
        }

        $wp->request = StaticRoutes::PAGE_NAME . '/' . $route;
    }

    /**
     * Paths of the registered routes, the allow-list for the query var.
     *
     * @return list<string>
     */
    private function routeNames(): array
    {
        return array_map(
            static fn (RouteRegister $route): string => trim((string) $route->getPath(), '/'),
            $this->router->getRouter()->getRoutes()
        );
    }
}
