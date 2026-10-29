<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\Deps\BitApps\WPKit\Http\RequestType;
use PaymentForm\Deps\BitApps\WPKit\Http\Router\Router;
use PaymentForm\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the wp-kit REST router.
 *
 * The constructor is deliberately lightweight: it only registers the
 * `rest_api_init` listener. The Router is constructed inside the callback
 * and only on REST requests, so constructing this provider on
 * `plugins_loaded` (from Plugin::load(), Phase 2) is always early enough
 * and costs nothing on non-REST requests.
 *
 * wp-kit keeps one Router per request type, so this API router coexists
 * with the static one owned by StaticRouteProvider.
 */
final class HookProvider
{
    private const REST_NAMESPACE = 'paymentform';

    private const REST_VERSION = 'v1';

    public function __construct()
    {
        Hooks::addAction('rest_api_init', [$this, 'loadApiRoutes']);
    }

    /**
     * Registers the declarative route table under `paymentform/v1`.
     */
    public function loadApiRoutes(): void
    {
        if (!RequestType::is(RequestType::API)) {
            return;
        }

        $routeFile = Config::pluginDir() . 'backend/hooks/api.php';
        if (!is_readable($routeFile)) {
            // Fail closed (no routes → REST 404s); leave a trace when debugging.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only diagnostics; output would corrupt redirect/JSON responses.
                error_log('PaymentForm: REST route file missing: ' . $routeFile);
            }
            return;
        }

        $router = new Router(RequestType::API, self::REST_NAMESPACE, self::REST_VERSION);

        // SECURITY — the auth-parity gate. wp-kit registers all routes with
        // `permission_callback => '__return_true'`, so the nonce + capability
        // middleware attached in hooks/api.php are the ONLY auth layer.
        // Without this call, Router::getRegisteredMiddleware() returns null
        // for every name and the middleware chain is silently skipped (the
        // file-manager reference plugin omits this on its API router —
        // do not copy that). Plugin::load() must precede construction of
        // this provider; the null-coalescing throw below enforces that contract.
        $plugin = Plugin::instance()
            ?? throw new \LogicException('Plugin::load() must run before HookProvider registers routes.');
        $router->setMiddlewares($plugin->middlewares());

        $router->registerFile($routeFile);
        $router->register();
    }
}
