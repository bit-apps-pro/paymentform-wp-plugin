<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use Pimple\Container;
use PaymentForm\HTTP\Routes;
use PaymentForm\HTTP\Shortcode;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\WebhookController;
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

        $c[RefreshController::class] = static fn($c) => new RefreshController(
            $c[TokenService::class],
            $c[ApiClient::class]
        );

        $c[DisconnectController::class] = static fn($c) => new DisconnectController(
            $c[TokenService::class],
            $c[ApiClient::class]
        );

        $c[FormsProxyController::class] = static fn($c) => new FormsProxyController(
            $c[ApiClient::class]
        );

        $c[WebhookController::class] = static fn($c) => new WebhookController(
            $c[TokenService::class],
            $c[WebhookService::class]
        );
    }

    public function boot(): void
    {
        add_action('init', [$this, 'loadTextdomain']);
        add_action('init', [$this, 'registerBlockAndShortcode']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
        add_filter('script_loader_tag', [$this, 'addModuleTypeToScripts'], 10, 2);
    }

    public function addModuleTypeToScripts(string $tag, string $handle): string
    {
        $moduleHandles = ['paymentform-admin', 'paymentform-form-view-script', 'paymentform-form-editor-script'];
        if (!in_array($handle, $moduleHandles, true)) {
            return $tag;
        }
        if (str_contains($tag, 'type="module"')) {
            return $tag;
        }
        return str_replace('<script ', '<script type="module" ', $tag);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('paymentform', false, dirname(plugin_basename(PAYMENTFORM_PLUGIN_FILE)) . '/languages');
    }

    public function registerBlockAndShortcode(): void
    {
        register_block_type(PAYMENTFORM_PLUGIN_DIR . 'frontend/blocks/form-embed');
        (new Shortcode())->register();
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
        $iconSvg = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAzMiAzMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnL'
            . 'jAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHJ4PSI3IiBmaWxsPSIjNkU2MkU1Ii8+PHBhdGggZD0iTTExIDI1VjhIMTguNzY0NUMyMC4wMzQ1IDggMjEu'
            . 'MTExNiA4LjIxNzAyIDIxLjk5NTcgOC42NTEwNkMyMi44Nzk5IDkuMDg1MTEgMjMuNTQ3IDkuNzA0MDIgMjMuOTk3MiAxMC41MDc4QzI0LjQ2MzQgMTEuMzExNiAyNC42OTY1IDEyLj'
            . 'I4NDIgMjQuNjk2NSAxMy40MjU1QzI0LjY5NjUgMTQuNTY2OSAyNC40NjM0IDE1LjU0NzUgMjMuOTk3MiAxNi4zNjc0QzIzLjU0NyAxNy4xNzEyIDIyLjg3OTkgMTcuNzkwMSAyMS45'
            . 'OTU3IDE4LjIyNDFDMjEuMTExNiAxOC42NTgyIDIwLjAzNDUgMTguODc1MiAxOC43NjQ1IDE4Ljg3NTJIMTQuNzM3NlYyMkwxMSAyNVpNMTQuNzM3NiAxNS45ODE2SDE4LjEzNzZDMT'
            . 'kuMTAyMSAxNS45ODE2IDE5LjgzMzYgMTUuNzY0NSAyMC4zMzE5IDE1LjMzMDVDMjAuODQ2MyAxNC44OTY1IDIxLjEwMzUgMTQuMjYxNSAyMS4xMDM1IDEzLjQyNTVDMjEuMTAzNSAx'
            . 'Mi41ODk2IDIwLjg0NjMgMTEuOTYyNiAyMC4zMzE5IDExLjU0NDdDMTkuODMzNiAxMS4xMjY3IDE5LjEwMjEgMTAuOTE3NyAxOC4xMzc2IDEwLjkxNzdIMTQuNzM3NlYxNS45ODE2WiIg'
            . 'ZmlsbD0id2hpdGUiLz48L3N2Zz4=';

        add_menu_page(
            'PaymentForm',
            'PaymentForm',
            'manage_options',
            'paymentform',
            [$this, 'renderAdminPage'],
            $iconSvg,
            80
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div id="paymentform-admin-root"></div>';
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_paymentform') {
            return;
        }

        $frontend_dir = PAYMENTFORM_PLUGIN_DIR . 'frontend/dist';
        $js_file = $frontend_dir . '/admin.js';
        $css_file = $frontend_dir . '/admin.css';

        if (!file_exists($js_file)) {
            return;
        }

        $version = PAYMENTFORM_VERSION;
        if (file_exists($js_file)) {
            $version .= '.' . filemtime($js_file);
        }

        wp_enqueue_script(
            'paymentform-admin',
            PAYMENTFORM_PLUGIN_URL . 'frontend/dist/admin.js',
            ['wp-element'],
            $version,
            true
        );

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'paymentform-admin',
                PAYMENTFORM_PLUGIN_URL . 'frontend/dist/admin.css',
                [],
                $version
            );
        }

        if ($this->container === null) {
            return;
        }

        $tokenService = $this->container[TokenService::class];
        $raw = $tokenService->getRaw();
        $token = $tokenService->get();
        $expiresAt = is_array($raw) ? ($raw['expires_at'] ?? null) : null;
        $tenant = is_array($raw) ? ($raw['tenant'] ?? null) : null;

        wp_localize_script('paymentform-admin', 'paymentformAdmin', [
            'nonce' => wp_create_nonce('wp_rest'),
            'restUrl' => rest_url('paymentform/v1/'),
            'adminUrl' => admin_url('admin.php?page=paymentform'),
            'connected' => $token !== null,
            'expiresAt' => $expiresAt,
            'tenant' => $tenant,
            'version' => PAYMENTFORM_VERSION,
            'siteUrl' => site_url(),
        ]);
    }

    public function enqueueFrontendAssets(): void
    {
        $frontend_dir = PAYMENTFORM_PLUGIN_DIR . 'frontend/dist';
        $view_js = $frontend_dir . '/block-view.js';

        if (!file_exists($view_js)) {
            return;
        }

        $version = PAYMENTFORM_VERSION;
        if (file_exists($view_js)) {
            $version .= '.' . filemtime($view_js);
        }

        // Check if shortcode is used in current post
        global $post;
        if ($post && has_shortcode($post->post_content, 'paymentform')) {
            wp_enqueue_script(
                'paymentform-form-view-script',
                PAYMENTFORM_PLUGIN_URL . 'frontend/dist/block-view.js',
                [],
                $version,
                true
            );

            wp_localize_script('paymentform-form-view-script', 'paymentformView', [
                'rendererBaseUrl' => PAYMENTFORM_RENDERER_BASE_URL,
            ]);
        }
    }
}
