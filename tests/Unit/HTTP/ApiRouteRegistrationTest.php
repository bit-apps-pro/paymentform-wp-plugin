<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP;

use PaymentForm\Deps\BitApps\WPKit\Http\RequestType;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\RouteRegister;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\Router;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\ResendOtpController;
use PaymentForm\HTTP\Controllers\SetupController;
use PaymentForm\HTTP\Controllers\SetupDiagnoseController;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\HTTP\Controllers\VerifyOtpController;
use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Locks the backend/hooks/api.php route table: exact paths/methods/actions
 * and — the auth-parity gate — BOTH middlewares on every single route.
 *
 * Registration happens once per process (Router::registerFile() uses
 * include_once), so the route file is loaded in setUpBeforeClass against a
 * class-held Router.
 */
final class ApiRouteRegistrationTest extends TestCase
{
    private const NONCE_MIDDLEWARE = 'nonce';

    private const CAP_MIDDLEWARE = 'cap:paymentform_manage';

    /** Expected table: [methods, path, controller, action]. */
    private const EXPECTED_ROUTES = [
        [['GET'], 'connect-url', ConnectController::class, 'show'],
        [['GET'], 'status', StatusController::class, 'show'],
        [['POST'], 'refresh', RefreshController::class, 'refresh'],
        [['POST'], 'disconnect', DisconnectController::class, 'disconnect'],
        [['GET'], 'forms', FormsProxyController::class, 'index'],
        [['GET'], 'forms/{id}', FormsProxyController::class, 'show'],
        [['POST'], 'setup', SetupController::class, 'handle'],
        [['POST'], 'setup/diagnose', SetupDiagnoseController::class, 'handle'],
        [['POST'], 'setup/verify-otp', VerifyOtpController::class, 'handle'],
        [['POST'], 'setup/resend-otp', ResendOtpController::class, 'handle'],
        [['GET'], 'setup/status', SetupController::class, 'status'],
    ];

    private static Router $router;

    public static function setUpBeforeClass(): void
    {
        Plugin::load();

        self::$router = new Router(RequestType::API, 'paymentform', 'v1');
        self::$router->setMiddlewares(Plugin::instance()->middlewares());
        self::$router->registerFile(dirname(__DIR__, 3) . '/backend/hooks/api.php');
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

    public function test_every_route_action_method_exists(): void
    {
        // A typo'd [class, method] pair would only surface as a runtime
        // fatal when wp-kit reflects the action mid-request; fail here instead.
        foreach (self::$router->getRoutes() as $route) {
            [$class, $method] = $route->getAction();

            $this->assertTrue(
                method_exists($class, $method),
                sprintf("route '%s' targets missing action %s::%s()", $route->getPath(), $class, $method)
            );
        }
    }

    public function test_every_route_carries_nonce_then_capability_middleware(): void
    {
        $routes = self::$router->getRoutes();
        $this->assertCount(\count(self::EXPECTED_ROUTES), $routes);

        foreach ($routes as $route) {
            $middleware = $route->getMiddleware();
            $path = $route->getPath();

            $this->assertContains(self::NONCE_MIDDLEWARE, $middleware, "route '{$path}' lacks the nonce middleware");
            $this->assertContains(self::CAP_MIDDLEWARE, $middleware, "route '{$path}' lacks the capability middleware");
            $this->assertLessThan(
                array_search(self::CAP_MIDDLEWARE, $middleware, true),
                array_search(self::NONCE_MIDDLEWARE, $middleware, true),
                "route '{$path}' must check the nonce before the capability"
            );
        }
    }

    public function test_router_resolves_both_middlewares_from_plugin_registry(): void
    {
        // Proves the names used in api.php resolve through
        // Plugin::middlewares(); a typo'd key would return null and wp-kit
        // would silently skip the middleware (file-manager's latent bug).
        $this->assertInstanceOf(NonceCheckerMiddleware::class, self::$router->getRegisteredMiddleware('nonce'));
        $this->assertInstanceOf(CapabilityCheckerMiddleware::class, self::$router->getRegisteredMiddleware('cap'));
    }
}
