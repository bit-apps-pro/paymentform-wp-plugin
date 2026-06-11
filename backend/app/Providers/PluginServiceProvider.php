<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use PaymentForm\Providers\Container;
use PaymentForm\Config;
use PaymentForm\HTTP\Rewrites;
use PaymentForm\HTTP\Shortcode;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\SetupProofController;
use PaymentForm\HTTP\Controllers\WebhookController;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\WebhookService;
use PaymentForm\Cron\PurgeExpiredSetupOptions;

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
            Config::apiBaseUrl()
        );

        // OAuth-like connect flow
        $c[ConnectService::class] = static fn() => new ConnectService();

        // Webhook signature verification
        $c[WebhookService::class] = static fn() => new WebhookService();

        // Non-REST controllers, dispatched by Rewrites.php from the
        // container. REST controllers are NOT bound here: wp-kit instantiates
        // them with zero args and they pull services from ServiceContainer
        // (see Plugin.php docblock for the composition-root split).
        $c[CallbackController::class] = static fn($c) => new CallbackController(
            $c[ConnectService::class],
            $c[TokenService::class]
        );

        $c[WebhookController::class] = static fn($c) => new WebhookController(
            $c[TokenService::class],
            $c[WebhookService::class]
        );

        $c[SetupProofController::class] = static fn($c) => new SetupProofController();
    }

    public function boot(): void
    {
        add_action('init', [$this, 'loadTextdomain']);
        add_action('init', [$this, 'registerBlockAndShortcode']);
        add_action('init', [$this, 'registerCronSchedules']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
        add_filter('script_loader_tag', [$this, 'addModuleTypeToScripts'], 10, 2);

        if ($this->container !== null) {
            (new Rewrites($this->container))->register();
        }
    }

    public function registerCronSchedules(): void
    {
        if (!wp_next_scheduled('paymentform_setup_purge')) {
            wp_schedule_event(time(), 'daily', 'paymentform_setup_purge');
        }
        add_action('paymentform_setup_purge', [new PurgeExpiredSetupOptions(), 'run']);
    }

    public function addModuleTypeToScripts(string $tag, string $handle): string
    {
        // Only the admin SPA bundle ships as an ES module (Vite emits real
        // imports there). `paymentform-form-view-script` is a plain IIFE
        // talking to window globals; tagging it `type="module"` causes WP
        // to register it through its module loader pipeline, which then
        // *omits the standard `<script src>` tag* in favour of an import
        // map entry — leaving only the inline `-extra` localised data on
        // the page and breaking shortcode hydration.
        $moduleHandles = ['paymentform-admin', 'paymentform-form-editor-script'];
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
        load_plugin_textdomain('paymentform', false, dirname(plugin_basename(Config::pluginFile())) . '/languages');
    }

    /**
     * The auto-generated editor-script handle for the form-embed block. Captured
     * from `register_block_type`'s return value so we don't depend on WP's
     * `<block>-editor-script` convention (which has shifted across WP versions
     * for namespaced block names). Falls back to the historical name if WP fails
     * to register (e.g. block.json malformed).
     */
    private string $blockEditorScriptHandle = 'paymentform-form-editor-script';
    private string $blockViewScriptHandle = 'paymentform-form-view-script';

    public function registerBlockAndShortcode(): void
    {
        $blockType = register_block_type(Config::pluginDir() . 'frontend/blocks/form-embed');
        if ($blockType && !empty($blockType->editor_script_handles)) {
            $this->blockEditorScriptHandle = (string) $blockType->editor_script_handles[0];
        } else {
            error_log('PaymentForm: register_block_type returned no editor_script_handles; falling back to default handle name. Block editor localize may target a stale handle.');
        }
        if ($blockType && !empty($blockType->view_script_handles)) {
            $this->blockViewScriptHandle = (string) $blockType->view_script_handles[0];
        }
        (new Shortcode())->register();
    }

    /**
     * Inject the tenant-scoped `paymentformAdmin` globals into the block editor
     * so the form-embed block's Edit component can build a preview iframe against
     * the connected tenant's renderer host.
     *
     * Runs on `enqueue_block_editor_assets` — fires on every Gutenberg editor.
     * Cheap: one `wp_localize_script` + a single TokenService::getRaw() round
     * (the local cache halves what would otherwise be three DB hits).
     */
    public function enqueueBlockEditorAssets(): void
    {
        if ($this->container === null) {
            return;
        }

        // Skip localize on editor screens that don't actually use the block to
        // avoid a TokenService::getRaw() DB lookup + nonce generation per page.
        // `has_block()` returns false for FSE/site-editor (no post context) which
        // is fine — those screens don't render the form-embed block edit UI.
        global $post;
        if ($post && ! has_block('paymentform/form', $post)) {
            return;
        }

        $raw = $this->container[TokenService::class]->getRaw();
        $isArr = is_array($raw);
        $tenant = $isArr ? ($raw['tenant'] ?? null) : null;
        $connected = $isArr && !empty($raw['access_token']);

        wp_localize_script($this->blockEditorScriptHandle, 'paymentformAdmin', [
            'connected' => $connected,
            'tenant' => $tenant,
            'siteUrl' => site_url(),
            'appBaseUrl' => Config::appBaseUrl(),
            'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
            'restUrl' => rest_url('paymentform/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function registerAdminMenu(): void
    {
        // WP applies a CSS filter to admin menu icons that maps the entire color
        // surface to a single neutral tone (then re-colors on hover/active). Any SVG
        // with a fill — including a colored background rect — gets flattened to
        // black-on-black, which renders invisible against the dark sidebar. Ship a
        // monochrome path with `fill="currentColor"` so WP's recolor pipeline owns
        // the tint.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor">'
            . '<path d="M11 25V8H18.7645C20.0345 8 21.1116 8.21702 21.9957 8.65106C22.8799 9.08511 23.547 9.70402 23.9972 10.5078'
            . 'C24.4634 11.3116 24.6965 12.2842 24.6965 13.4255C24.6965 14.5669 24.4634 15.5475 23.9972 16.3674'
            . 'C23.547 17.1712 22.8799 17.7901 21.9957 18.2241C21.1116 18.6582 20.0345 18.8752 18.7645 18.8752'
            . 'H14.7376V22L11 25ZM14.7376 15.9816H18.1376C19.1021 15.9816 19.8336 15.7645 20.3319 15.3305'
            . 'C20.8463 14.8965 21.1035 14.2615 21.1035 13.4255C21.1035 12.5896 20.8463 11.9626 20.3319 11.5447'
            . 'C19.8336 11.1267 19.1021 10.9177 18.1376 10.9177H14.7376V15.9816Z"/></svg>';
        $iconSvg = 'data:image/svg+xml;base64,' . base64_encode($svg);

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

    /**
     * CSS that flattens WP admin chrome on our page so the React shell fills the
     * viewport without producing an outer page scrollbar or a gap between the WP
     * admin sidebar and the plugin sidebar.
     */
    private function adminChromeOverrides(): string
    {
        return <<<'CSS'
html.wp-toolbar { overflow: hidden; }
body.toplevel_page_paymentform #wpcontent { padding-left: 0 !important; margin-left: 160px; }
body.folded.toplevel_page_paymentform #wpcontent { margin-left: 36px; }
body.toplevel_page_paymentform #wpbody { padding-top: 0 !important; }
body.toplevel_page_paymentform #wpbody-content { padding-bottom: 0 !important; }
body.toplevel_page_paymentform .wrap { margin: 0 !important; padding: 0 !important; }
body.toplevel_page_paymentform #wpfooter { display: none !important; }
body.toplevel_page_paymentform #paymentform-admin-root,
body.toplevel_page_paymentform #paymentform-admin-root > * { height: calc(100vh - 32px); }
@media screen and (max-width: 782px) {
    body.toplevel_page_paymentform #wpcontent { margin-left: 0; }
    body.toplevel_page_paymentform #paymentform-admin-root,
    body.toplevel_page_paymentform #paymentform-admin-root > * { height: calc(100vh - 46px); }
}
CSS;
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_paymentform') {
            return;
        }

        if (Config::isDev()) {
            $this->enqueueDevAdminAssets();
        } elseif (!$this->enqueueBuiltAdminAssets()) {
            return;
        }

        wp_add_inline_style('paymentform-admin', $this->adminChromeOverrides());
        $this->localizeAdminConfig();
    }

    /**
     * Dev mode (Config::isDev()): serve the admin SPA straight from the Vite
     * dev server so wp-admin gets HMR. Registers src-less stubs for the
     * `paymentform-admin` script/style handles so the shared localize +
     * inline-chrome-CSS plumbing works exactly as in prod, then prints the
     * React Fast Refresh preamble, Vite client and SPA entry as
     * `type="module"` tags. The preamble must execute before the entry
     * module and WP's script-module API gives no ordering guarantee for
     * inline modules, so all three print together in the admin footer.
     */
    private function enqueueDevAdminAssets(): void
    {
        $dev = Config::devServerUrl();

        wp_register_script('paymentform-admin', false, [], Config::VERSION, true);
        wp_enqueue_script('paymentform-admin');
        wp_register_style('paymentform-admin', false, [], Config::VERSION);
        wp_enqueue_style('paymentform-admin');

        add_action('admin_print_footer_scripts', static function () use ($dev): void {
            printf(
                '<script type="module">import RefreshRuntime from "%s";'
                    . 'RefreshRuntime.injectIntoGlobalHook(window);'
                    . 'window.$RefreshReg$ = () => {};'
                    . 'window.$RefreshSig$ = () => (type) => type;'
                    . 'window.__vite_plugin_react_preamble_installed__ = true;'
                    . '</script>' . "\n",
                esc_url($dev . '/@react-refresh')
            );
            printf('<script type="module" src="%s"></script>' . "\n", esc_url($dev . '/@vite/client'));
            printf('<script type="module" src="%s"></script>' . "\n", esc_url($dev . '/src/main.tsx'));
        }, 5);
    }

    /**
     * Production: enqueue the built `dist/` bundle. Returns false when no
     * build exists (fresh checkout) — callers skip the localize/chrome-CSS
     * steps entirely, matching the historical early-return behaviour.
     */
    private function enqueueBuiltAdminAssets(): bool
    {
        $frontend_dir = Config::pluginDir() . 'frontend/dist';
        $js_file = $frontend_dir . '/admin.js';
        $css_file = $frontend_dir . '/admin.css';
        $asset_file = $frontend_dir . '/admin.asset.php';

        if (!file_exists($js_file)) {
            return false;
        }

        // Vite plugin emits `admin.asset.php` with the deps + content-hash version so
        // we enqueue the exact `wp-*` script handles the bundle externalizes against.
        // Fall back to filemtime + wp-element if the asset file isn't present (e.g.
        // someone shipped a partial build).
        $asset = file_exists($asset_file) ? require $asset_file : null;
        $version = is_array($asset) && isset($asset['version'])
            ? (string) $asset['version']
            : Config::VERSION . '.' . filemtime($js_file);
        $deps = is_array($asset) && isset($asset['dependencies']) && is_array($asset['dependencies'])
            ? $asset['dependencies']
            : ['wp-element'];

        wp_enqueue_script(
            'paymentform-admin',
            Config::pluginUrl() . 'frontend/dist/admin.js',
            $deps,
            $version,
            true
        );

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'paymentform-admin',
                Config::pluginUrl() . 'frontend/dist/admin.css',
                [],
                $version
            );
        } else {
            // Even without compiled CSS we still need an inline style handle.
            wp_register_style('paymentform-admin', false, [], $version);
            wp_enqueue_style('paymentform-admin');
        }

        return true;
    }

    /**
     * Attach the `paymentformAdmin` config object to the admin SPA handle
     * (built bundle or dev-mode stub alike).
     */
    private function localizeAdminConfig(): void
    {
        if ($this->container === null) {
            return;
        }

        $tokenService = $this->container[TokenService::class];
        $raw = $tokenService->getRaw();
        $token = $tokenService->get();
        $expiresAt = is_array($raw) ? ($raw['expires_at'] ?? null) : null;
        $tenant = is_array($raw) ? ($raw['tenant'] ?? null) : null;
        $emailVerified = is_array($raw) ? ($raw['email_verified'] ?? null) : null;

        $currentUser = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $adminEmail = ($currentUser && $currentUser->exists()) ? (string) $currentUser->user_email : '';

        // Best-effort Cloudflare country hint for the wizard's default country.
        // Absent outside a CF edge → empty string; wizard falls back to its own default.
        $cfCountry = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? (string) $_SERVER['HTTP_CF_IPCOUNTRY'] : '';
        if (!preg_match('/^[A-Z]{2}$/', $cfCountry)) {
            $cfCountry = '';
        }

        wp_localize_script('paymentform-admin', 'paymentformAdmin', [
            'nonce' => wp_create_nonce('wp_rest'),
            'restUrl' => rest_url('paymentform/v1/'),
            'adminUrl' => admin_url('admin.php?page=paymentform'),
            'connected' => $token !== null,
            'hasConnection' => $token !== null,
            'expiresAt' => $expiresAt,
            'tenant' => $tenant,
            'version' => Config::VERSION,
            'siteUrl' => site_url(),
            // Wizard pre-fill: current admin email + CF-country hint. Banner reads
            // `emailVerified === false` so `null` keeps it hidden until the value
            // is populated by the next /me round-trip.
            'adminEmail' => $adminEmail,
            'cfCountry' => $cfCountry,
            'emailVerified' => $emailVerified,
            // Central app shell host (`app.<base>`). Used by admin SPA links that
            // point at the form editor / dashboard on app.paymentform.io. NOT
            // tenant-scoped — same host regardless of which tenant is connected.
            'appBaseUrl' => Config::appBaseUrl(),
            // Tenant-scoped renderer base — same host-swap that powers the
            // shortcode `<tenant>.paymentform.io/<form-id>` embed. The admin SPA's
            // EmbedDrawer uses this to render the "Iframe URL" + preview iframe
            // against the correct subdomain instead of the static `app.` host.
            'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
        ]);
    }

    public function enqueueFrontendAssets(): void
    {
        $view_js = Config::pluginDir() . 'frontend/dist/block-view.js';

        if (!file_exists($view_js)) {
            return;
        }

        global $post;
        $hasShortcode = $post && has_shortcode($post->post_content, 'paymentform');
        $hasBlock = $post && has_block('paymentform/form', $post);

        // Block runtime: auto-enqueued by register_block_type via
        // block.json viewScript. We only need to attach the renderer config
        // so view.ts can build the iframe src against the tenant origin.
        if ($hasBlock) {
            wp_localize_script($this->blockViewScriptHandle, 'paymentformView', [
                'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
            ]);
        }

        if ($hasShortcode) {
            $version = Config::VERSION . '.' . filemtime($view_js);

            // Distinct handle from the block's auto-registered `viewScript`
            // (block.json registers `paymentform-form-view-script` with an
            // empty `src` because the `file:` path resolves through
            // `register_block_type`'s loader; reusing that handle here would
            // hit `WP_Dependencies::add()`'s already-registered guard and
            // leave us with only the inline `-extra` localised data).
            wp_enqueue_script(
                'paymentform-shortcode-view',
                Config::pluginUrl() . 'frontend/dist/block-view.js',
                [],
                $version,
                true
            );

            wp_localize_script('paymentform-shortcode-view', 'paymentformView', [
                'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
            ]);
        }
    }

    /**
     * Tenant subdomain owns the form render — replace the `app.` host in the
     * configured app base URL with the connected tenant slug so the iframe
     * lands on `<tenant>.paymentform.io/<form-id>` instead of the central
     * app shell. Falls back to the static renderer URL when there is no
     * active connection (e.g. the shortcode rendered before the admin
     * connected).
     */
    private function resolveRendererBaseUrl(): string
    {
        if ($this->container === null) {
            return Config::rendererBaseUrl();
        }

        $tenant = $this->container[TokenService::class]->getTenant();
        if (!is_string($tenant) || $tenant === '') {
            return Config::rendererBaseUrl();
        }

        $appBase = Config::appBaseUrl();
        $parts = parse_url($appBase);
        if (!is_array($parts) || empty($parts['host'])) {
            return Config::rendererBaseUrl();
        }

        $host = preg_replace('/^app\./', $tenant . '.', $parts['host'], 1);
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }
}
