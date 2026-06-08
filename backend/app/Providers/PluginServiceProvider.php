<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use Pimple\Container;
use PaymentForm\Services\EncryptionService;

final class PluginServiceProvider
{
    public function register(Container $c): void
    {
        $c[EncryptionService::class] = static fn() => new EncryptionService();
        // More services registered in later tasks
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
        // Wired up in Phase 3
    }

    public function registerAdminMenu(): void
    {
        // Wired up in Phase 4
    }
}
