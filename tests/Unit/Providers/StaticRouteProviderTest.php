<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Providers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Http\RequestType;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\RouteRegister;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\Router;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\StaticRouter;
use PaymentForm\HTTP\StaticRouteDispatcher;
use PaymentForm\HTTP\StaticRoutes;
use PaymentForm\Providers\StaticRouteProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the backend/hooks/static.php route table and the provider's hook wiring.
 *
 * The route file is included once per process (include_once), so the router is
 * captured in setUpBeforeClass.
 */
final class StaticRouteProviderTest extends TestCase
{
    /** Expected table: [methods, path, action class, action method]. */
    private const EXPECTED_ROUTES = [
        [['GET'], 'callback', StaticRouteDispatcher::class, 'callback'],
        [['POST'], 'webhook', StaticRouteDispatcher::class, 'webhook'],
        [['GET'], 'setup-proof', StaticRouteDispatcher::class, 'setupProof'],
    ];

    private static Router $router;

    /** Builds the provider once and keeps the static router it populated. */
    public static function setUpBeforeClass(): void
    {
        Config::bootstrap(dirname(__DIR__, 3) . '/paymentform.php');
        new StaticRouteProvider();
        self::$router = Router::instance(RequestType::STATIC_PAGE);
    }

    /** Clears recorded hooks and query vars between tests. */
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_registers_exactly_the_expected_route_table(): void
    {
        $actual = array_map(
            static fn (RouteRegister $route): array => [
                $route->getMethods(),
                $route->getPath(),
                $route->getAction()[0],
                $route->getAction()[1],
            ],
            self::$router->getRoutes()
        );

        $this->assertSame(self::EXPECTED_ROUTES, $actual);
    }

    public function test_routes_carry_no_middleware(): void
    {
        foreach (self::$router->getRoutes() as $route) {
            $this->assertEmpty($route->getMiddleware(), $route->getPath());
        }
    }

    public function test_flushes_rewrite_rules_on_plugin_lifecycle_hooks(): void
    {
        new StaticRouteProvider();

        $this->assertCount(1, \TestWpFunctions::getActions(Config::withPrefix('activate')));
        $this->assertCount(1, \TestWpFunctions::getActions(Config::withPrefix('deactivate')));
    }

    public function test_dispatches_on_parse_request_instead_of_template_redirect(): void
    {
        new StaticRouteProvider();

        $routerHandlers = array_filter(
            \TestWpFunctions::getActions('parse_request'),
            static fn (array $callback): bool => $callback[0] instanceof StaticRouter
        );

        $this->assertSame(['handleRequest'], array_column($routerHandlers, 1));
        $this->assertSame([], \TestWpFunctions::getActions('template_redirect'));
    }

    public function test_registers_route_query_var(): void
    {
        new StaticRouteProvider();

        $this->assertContains(StaticRoutes::QUERY_VAR, apply_filters('query_vars', ['p']));
    }

    public function test_maps_query_route_before_static_router_dispatches(): void
    {
        $provider = new StaticRouteProvider();

        $records = array_filter(
            \TestWpFunctions::getActionRecords('parse_request'),
            static fn (array $record): bool => $record['callback'] === [$provider, 'mapQueryRoute']
        );

        $this->assertSame([9], array_column($records, 'priority'));
    }

    #[DataProvider('registeredRoutes')]
    public function test_query_route_points_request_at_route_path(string $route): void
    {
        $wp = $this->queryRequest($route);

        (new StaticRouteProvider())->mapQueryRoute($wp);

        $this->assertSame('paymentform/' . $route, $wp->request);
    }

    /** @return iterable<string, array{string}> */
    public static function registeredRoutes(): iterable
    {
        yield 'callback' => [StaticRoutes::CALLBACK];
        yield 'webhook' => [StaticRoutes::WEBHOOK];
        yield 'setup-proof' => [StaticRoutes::SETUP_PROOF];
    }

    #[DataProvider('ignoredQueryRoutes')]
    public function test_query_route_ignores_unregistered_values(mixed $route): void
    {
        $wp = $this->queryRequest($route);

        (new StaticRouteProvider())->mapQueryRoute($wp);

        $this->assertSame('', $wp->request);
    }

    /** @return iterable<string, array{mixed}> */
    public static function ignoredQueryRoutes(): iterable
    {
        yield 'unknown route' => ['nope'];
        yield 'path traversal' => ['../wp-admin'];
        yield 'nested path' => ['callback/extra'];
        yield 'array value' => [['callback']];
    }

    public function test_query_route_leaves_path_requests_alone(): void
    {
        $wp = $this->queryRequest(StaticRoutes::CALLBACK);
        $wp->request = 'sample-page';

        (new StaticRouteProvider())->mapQueryRoute($wp);

        $this->assertSame('sample-page', $wp->request);
    }

    /** Builds a front-page request carrying the route query var. */
    private function queryRequest(mixed $route): \WP
    {
        $wp = new \WP();
        $wp->query_vars[StaticRoutes::QUERY_VAR] = $route;

        return $wp;
    }
}
