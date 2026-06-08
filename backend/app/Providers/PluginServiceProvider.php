<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use Pimple\Container;
use PaymentForm\HTTP\Routes;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\WebhookService;

final class PluginServiceProvider
{
    private ?Container $container = null;

    public function register(Container $c): void
    {
        $this->container = $c;
        // Core encryption service
        $c[EncryptionService::class] = static fn() => new EncryptionService();

        // Token management
        $c[TokenService::class] = static fn($c) => new TokenService($c[EncryptionService::class]);

        // Token refresh synchronization
        $c[TokenRefreshLock::class] = static fn() => new TokenRefreshLock();

        // API client with token awareness
        $c[ApiClient::class] = static fn($c) => new ApiClient(
            $c[TokenService::class],
            $c[TokenRefreshLock::class],
            PAYMENTFORM_API_BASE_URL
        );

        // OAuth-like connect flow
        $c[ConnectService::class] = static fn() => new ConnectService();

        // Webhook signature verification
        $c[WebhookService::class] = static fn() => new WebhookService();

        // HTTP Controllers
        $c[ConnectController::class] = static fn($c) => new ConnectController(
            $c[ConnectService::class]
        );

        $c[CallbackController::class] = static fn($c) => new CallbackController(
            $c[ConnectService::class],
            $c[TokenService::class]
        );

        $c[StatusController::class] = static fn($c) => new StatusController(
            $c[TokenService::class],
            $c[ApiClient::class]
        );
    }

    public function boot(): void
    {
        add_action('init', [$this, 'loadTextdomain']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('paymentform', false, dirname(plugin_basename(PAYMENTFORM_PLUGIN_FILE)) . '/languages');
    }

    public function registerRoutes(): void
    {
        if ($this->container === null) {
            return;
        }
        Routes::register($this->container);
    }

    public function registerAdminMenu(): void
    {
        // Wired up in Phase 4
    }
}
