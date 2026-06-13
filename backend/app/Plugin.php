<?php

declare(strict_types=1);

namespace PaymentForm;

use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\HTTP\Middleware\CapabilityCheckerMiddleware;
use PaymentForm\HTTP\Middleware\NonceCheckerMiddleware;
use PaymentForm\Providers\HookProvider;
use PaymentForm\Providers\InstallerProvider;
use PaymentForm\Providers\PluginServiceProvider;
use PaymentForm\Providers\ServiceContainer;

/**
 * Plugin singleton: entrypoint wiring + middleware registry for the wp-kit
 * router. `backend/bootstrap.php` calls {@see load()} at include time.
 *
 * Composition-root split (intentional):
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
     * Loads the plugin singleton and wires the WordPress integration.
     *
     * Ordering mirrors the pre-wp-kit bootstrap exactly: service bindings are
     * registered into the container at include time (early consumers — e.g.
     * the activation request — can resolve them before any hook fires), while
     * the provider's hook registrations are deferred to `plugins_loaded`.
     * HookProvider's constructor only adds the `rest_api_init` listener, so
     * constructing it here at include time is always early enough for the
     * wp-kit router (migration-spec Risk #10).
     *
     * @return bool false when the plugin was already loaded (no-op)
     */
    public static function load(): bool
    {
        if (self::$instance !== null) {
            return false;
        }

        self::$instance = new self();
        self::$instance->wire();

        return true;
    }

    private function wire(): void
    {
        // Lifecycle (activation/deactivation/uninstall) via wp-kit's Installer.
        // Constructed on every load so register_activation_hook() is in place
        // before the user activates; register() wires the requirement gate.
        (new InstallerProvider())->register();

        $provider = new PluginServiceProvider();
        $provider->register(ServiceContainer::instance());

        Hooks::addAction('plugins_loaded', [$provider, 'boot']);

        new HookProvider();
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
