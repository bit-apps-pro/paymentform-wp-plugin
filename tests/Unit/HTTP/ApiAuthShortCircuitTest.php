<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\HTTP;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\RequestType;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\Route;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\RouteRegister;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\Router;
use PaymentForm\Plugin;
use PaymentForm\Tests\Support\InteractsWithWpKit;
use PaymentForm\Tests\Support\MockWpRestRequest;
use PHPUnit\Framework\TestCase;

/**
 * Spy target: records whether wp-kit ever invoked the action.
 */
final class AuthShortCircuitSpyController
{
    public static bool $invoked = false;

    /**
     * @return array{ok: bool}
     */
    public function handle(Request $request): array
    {
        self::$invoked = true;

        return ['ok' => true];
    }
}

/**
 * Integration lock for the middleware short-circuit (migration-spec MINOR-7):
 * the 401 path depends on the static Response singleton holding the error
 * state from handleMiddleware() through handleAction(), gated on
 * REST_REQUEST being defined. A bad nonce must yield HTTP 401 INVALID_NONCE
 * AND the controller action must never run.
 */
final class ApiAuthShortCircuitTest extends TestCase
{
    use InteractsWithWpKit;

    public static function setUpBeforeClass(): void
    {
        // RouteRegister::invokeAsReflection() only takes the short-circuit
        // (API) branch when REST_REQUEST is truthy — see RequestType::is().
        if (!\defined('REST_REQUEST')) {
            \define('REST_REQUEST', true);
        }
    }

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->resetWpKitResponse();
        AuthShortCircuitSpyController::$invoked = false;
    }

    protected function tearDown(): void
    {
        $this->resetWpKitResponse();
        \TestWpFunctions::reset();
    }

    public function test_bad_nonce_returns_401_and_never_invokes_the_controller(): void
    {
        \TestWpFunctions::setNonceValidator(static fn (): bool => false);
        $route = $this->registerSpyRoute();

        $response = $this->dispatch(
            $route,
            new MockWpRestRequest('GET', '/paymentform/v1/spy', [], '', ['X-WP-Nonce' => 'bad-nonce'])
        );

        $this->assertSame(401, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('error', $data['status']);
        $this->assertSame('INVALID_NONCE', $data['code']);
        $this->assertFalse(
            AuthShortCircuitSpyController::$invoked,
            'controller action must not run after a middleware failure'
        );
    }

    public function test_passing_middleware_chain_reaches_the_controller(): void
    {
        // Guards against a vacuously-green spy: with default stubs (any
        // non-empty nonce verifies, all caps granted) the action MUST run.
        $route = $this->registerSpyRoute();

        $response = $this->dispatch(
            $route,
            new MockWpRestRequest('GET', '/paymentform/v1/spy', [], '', ['X-WP-Nonce' => 'valid-nonce'])
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('success', $data['status']);
        $this->assertSame('SUCCESS', $data['code']);
        $this->assertSame(['ok' => true], $data['data']);
        $this->assertTrue(AuthShortCircuitSpyController::$invoked);
    }

    /**
     * Builds a fresh API router with the production middleware registry and
     * a spy route carrying the same middleware pair as backend/hooks/api.php.
     */
    private function registerSpyRoute(): RouteRegister
    {
        Plugin::load();

        $router = new Router(RequestType::API, 'paymentform', 'v1');
        $router->setMiddlewares(Plugin::instance()->middlewares());

        return Route::get('spy', [AuthShortCircuitSpyController::class, 'handle'])
            ->middleware('nonce', 'cap:paymentform_manage');
    }

    /**
     * Drives RouteRegister::handleRequest() with balanced output buffering.
     *
     * handleRequest() opens one buffer, but setResponse() can ob_get_clean()
     * up to three times on a failing chain and the method ends with a bare
     * ob_clean(). Pre-opened sacrificial buffers absorb the extra pops so
     * wp-kit never drains PHPUnit's own buffer; the finally block restores
     * the original nesting level either way.
     */
    private function dispatch(RouteRegister $route, MockWpRestRequest $request): \WP_REST_Response
    {
        $level = ob_get_level();
        ob_start();
        ob_start();

        try {
            return $route->handleRequest($request);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            while (ob_get_level() < $level) {
                ob_start();
            }
        }
    }
}
