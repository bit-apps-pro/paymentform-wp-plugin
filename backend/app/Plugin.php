<?php

declare(strict_types=1);

namespace PaymentForm;

use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;

/**
 * Plugin singleton and middleware registry for the wp-kit router.
 *
 * Composition-root split (intentional, relied on from Phase 2 onwards):
 *
 *  - REST controllers (the targets wired in `backend/hooks/api.php`) are
 *    instantiated by wp-kit's RouteRegister via `new $class()` with ZERO
 *    constructor arguments, so they pull their collaborators from
 *    `ServiceContainer::get(...)` inside their own constructors.
 *  - The non-REST trio (CallbackController, WebhookController,
 *    SetupProofController) stays constructor-injected and is dispatched by
 *    Rewrites.php from the Container.
 *
 * Both sides read services from the same ServiceContainer/Container
 * singleton, so there is exactly one composition root.
 *
 * Phase 1 (current): `load()` only records the singleton — the wp-kit routing
 * stack is dormant and exercised by unit tests only. Phase 2 will extend
 * `load()` to drive PluginServiceProvider register/boot and construct
 * HookProvider on `plugins_loaded`.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    /**
     * Instances are only created through {@see load()}.
     */
    private function __construct()
    {
    }

    /**
     * Loads the plugin singleton.
     *
     * @return bool false when the plugin was already loaded
     */
    public static function load(): bool
    {
        if (self::$instance !== null) {
            return false;
        }

        self::$instance = new self();

        return true;
    }

    public static function instance(): ?Plugin
    {
        return self::$instance;
    }

    /**
     * Middleware registry handed to the wp-kit Router via `setMiddlewares()`.
     *
     * SECURITY: wp-kit registers every REST route with
     * `permission_callback => '__return_true'`, so WordPress itself performs
     * no auth — this registry is the only auth layer. If the map is never
     * passed to the API router, `Router::getRegisteredMiddleware()` resolves
     * each name to null and `RouteRegister::handleMiddleware()` silently
     * skips it, leaving every route fully public (the file-manager reference
     * plugin ships exactly that bug on its API router).
     * {@see \PaymentForm\Providers\HookProvider::loadApiRoutes()} MUST hand
     * this map to the router.
     *
     * @return array<string, class-string>
     */
    public function middlewares(): array
    {
        return [
            'nonce' => NonceCheckerMiddleware::class,
            'cap'   => CapabilityCheckerMiddleware::class,
        ];
    }
}
