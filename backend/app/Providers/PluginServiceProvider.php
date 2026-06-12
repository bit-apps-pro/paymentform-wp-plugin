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
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\WebhookService;
use PaymentForm\Cron\PurgeExpiredSetupOptions;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;

final class PluginServiceProvider
{
    /**
     * Script Module id for the admin SPA, in the npm-scope style WP core uses
     * for its own modules (`@wordpress/*`). The admin SPA config is delivered
     * through the WP 6.7 Script Module Data API keyed on this id, so it must
     * match the `wp_enqueue_script_module()` id, the `script_module_data_{id}`
     * filter tag, and the frontend reader's `wp-script-module-data-{id}` lookup.
     */
    private const ADMIN_MODULE_ID = '@' . Config::SLUG . '/admin';

    /**
     * Dev-only Script Module ids. In dev the admin SPA is served from the Vite
     * dev server, but it still ships through the Script Modules API so WP emits
     * the config data tag and keeps dev/prod loading identical. The React Fast
     * Refresh preamble and the Vite HMR client are enqueued as their own modules
     * so they execute before the SPA entry.
     */
    private const DEV_REFRESH_MODULE_ID = '@' . Config::SLUG . '/dev-react-refresh';
    private const DEV_VITE_CLIENT_MODULE_ID = '@' . Config::SLUG . '/vite-client';

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

        // Shared outbound HTTP transport (wp-kit HttpClient adapter). Owns the
        // API base URL; stateless beyond that — a fresh client is built per call
        // inside send().
        $c[HttpTransport::class] = static fn() => new HttpTransport(Config::apiBaseUrl());

        // API client with token awareness
        $c[ApiClient::class] = static fn($c) => new ApiClient(
            $c[TokenService::class],
            $c[TokenRefreshLock::class],
            $c[HttpTransport::class]
        );

        // Unauthenticated client for the pre-auth setup/exchange endpoints.
        $c[SetupApiClient::class] = static fn($c) => new SetupApiClient(
            $c[HttpTransport::class]
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
            $c[TokenService::class],
            $c[SetupApiClient::class]
        );

        $c[WebhookController::class] = static fn($c) => new WebhookController(
            $c[TokenService::class],
            $c[WebhookService::class]
        );

        $c[SetupProofController::class] = static fn() => new SetupProofController();
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
        // Only the block-editor bundle still ships as a classic ES-module
        // script (WP has no `editorScriptModule`, so the editor entry is
        // enqueued as a classic script that needs `type="module"`). The admin
        // SPA now ships through the Script Modules API (wp_enqueue_script_module),
        // so it never passes through `script_loader_tag` and is intentionally
        // absent from the allowlist. `paymentform-form-view-script` is a plain
        // IIFE talking to window globals; tagging it `type="module"` would route
        // it through WP's module loader and omit the `<script src>` tag in favour
        // of an import-map entry, breaking shortcode hydration.
        //
        // The editor handle is the one captured by registerBlockAndShortcode
        // (init), not a hard-coded literal — WP's `<block>-editor-script`
        // naming convention has shifted across versions, and a mismatch here
        // would print the module bundle as a classic script (SyntaxError on
        // its top-level `import`, dead editor). `script_loader_tag` fires at
        // print time, after init populated the property.
        $moduleHandles = [$this->blockEditorScriptHandle];
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

    /**
     * The auto-generated view *Script Module* id for the form-embed block,
     * captured from `register_block_type`'s return value. The block view now
     * ships as a Script Module (block.json `viewScriptModule`), so WP exposes
     * its id on `view_script_module_ids` rather than `view_script_handles`.
     * The block view's config is delivered through the WP 6.7 Script Module
     * Data API keyed on this id (`script_module_data_{id}`); the shortcode
     * classic path keeps its own `window.paymentformView` localize.
     */
    private string $blockViewScriptModuleId = 'paymentform-form-view-script';

    public function registerBlockAndShortcode(): void
    {
        $blockType = register_block_type(Config::pluginDir() . 'assets/form-embed');
        if ($blockType && !empty($blockType->editor_script_handles)) {
            $this->blockEditorScriptHandle = (string) $blockType->editor_script_handles[0];
        } else {
            error_log('PaymentForm: register_block_type returned no editor_script_handles; falling back to default handle name. Block editor localize may target a stale handle.');
        }
        if ($blockType && !empty($blockType->view_script_module_ids)) {
            $this->blockViewScriptModuleId = (string) $blockType->view_script_module_ids[0];
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

        // Always localize the request-layer essentials: the block's Edit
        // component fetches /forms through the SPA `request()` helper, which
        // needs `restUrl` + `nonce` even on a brand-new post where the block
        // is inserted for the first time. These are computed without DB hits.
        $config = [
            'siteUrl' => site_url(),
            'appBaseUrl' => Config::appBaseUrl(),
            'restUrl' => rest_url('paymentform/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ];

        // Connection-derived fields drive the preview iframe's tenant renderer
        // host. Localize them unconditionally: gating on has_block() left a
        // freshly-inserted block with no rendererBaseUrl until save+reload, so
        // the preview showed "Preview unavailable" on a connected site. Cost is
        // one cached TokenService::getRaw() read per editor load.
        $raw = $this->container[TokenService::class]->getRaw();
        $isArr = is_array($raw);

        $config['connected'] = $isArr && !empty($raw['access_token']);
        $config['tenant'] = $isArr ? ($raw['tenant'] ?? null) : null;
        $config['rendererBaseUrl'] = $this->resolveRendererBaseUrl();

        wp_localize_script($this->blockEditorScriptHandle, 'paymentformAdmin', $config);
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

        // WP core auto-prints the import map, the enqueued Script Module, and
        // its `wp-script-module-data-{id}` JSON tag on `admin_print_footer_scripts`
        // (admin support since 6.6) — no manual printer is needed. The admin SPA
        // config is delivered via the Script Module Data filter registered here.
        $this->registerAdminConfigData();
    }

    /**
     * Dev mode (Config::isDev()): serve the admin SPA straight from the Vite
     * dev server so wp-admin gets HMR. The React Fast Refresh preamble, the Vite
     * HMR client and the SPA entry all ship through the Script Modules API — the
     * same path as production — so WP prints them as `type="module"` scripts and
     * auto-emits the `wp-script-module-data-{id}` config tag for the enqueued
     * admin module (no manual tag print needed).
     *
     * The preamble must execute before the entry, and the Vite client before the
     * SPA registers its Fast Refresh boundaries. Module scripts execute in
     * document order and WP prints enqueued modules in registration order, so
     * register them preamble → client → entry. `version: false` suppresses the
     * `?ver` query WP would otherwise append — Vite's dev endpoints
     * (`/@vite/client`, `/@react-refresh`) don't expect it.
     *
     * The chrome-flattening CSS ships inside the built `assets/admin.css`. A
     * clean dev checkout may not have it (no build has run); when absent we skip
     * the enqueue — the SPA still functions, the wp-admin chrome simply isn't
     * flattened until a build produces `assets/admin.css` once.
     */
    private function enqueueDevAdminAssets(): void
    {
        $dev = Config::devServerUrl();

        $css_file = Config::pluginDir() . 'assets/admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style('paymentform-admin', Config::pluginUrl() . 'assets/admin.css', [], Config::VERSION);
        }

        wp_enqueue_script_module(self::DEV_REFRESH_MODULE_ID, $dev . '/dev-react-refresh.js', [], false);
        wp_enqueue_script_module(self::DEV_VITE_CLIENT_MODULE_ID, $dev . '/@vite/client', [], false);
        wp_enqueue_script_module(self::ADMIN_MODULE_ID, $dev . '/src/main.tsx', [], false);
    }

    /**
     * Production: enqueue the built `assets/` bundle. Returns false when no
     * build exists (fresh checkout) — callers skip the config-data step
     * entirely, matching the historical early-return behaviour.
     */
    private function enqueueBuiltAdminAssets(): bool
    {
        $assets_dir = Config::pluginDir() . 'assets';
        $js_file = $assets_dir . '/admin.js';
        $css_file = $assets_dir . '/admin.css';
        $asset_file = $assets_dir . '/admin.asset.php';

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

        // The SPA ships as a Script Module. A Script Module's dependency array
        // accepts *module* ids only — it cannot declare classic-script deps.
        // The bundle reads `window.wp.*` globals (Vite externalizes @wordpress/*
        // to globals; the SPA bundles its own React), so enqueue the WP-core
        // classic handles from admin.asset.php separately. WP prints them
        // before the module executes, so the globals exist at module run time.
        foreach ($deps as $dep) {
            wp_enqueue_script($dep);
        }

        wp_enqueue_script_module(
            self::ADMIN_MODULE_ID,
            Config::pluginUrl() . 'assets/admin.js',
            [],
            $version
        );

        // The build always emits `assets/admin.css` (chrome overrides). Guard
        // the enqueue with file_exists so a partial build (JS present, CSS
        // missing) degrades to no-stylesheet rather than a 404.
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'paymentform-admin',
                Config::pluginUrl() . 'assets/admin.css',
                [],
                $version
            );
        }

        return true;
    }

    /**
     * Register the admin SPA config on the WP 6.7 Script Module Data filter for
     * the admin module. When WP prints the enqueued module on
     * `admin_print_footer_scripts` it runs `script_module_data_{id}`, encodes
     * the returned array, and emits it as a non-executable
     * `<script type="application/json" id="wp-script-module-data-{id}">` tag —
     * parsed into the DOM before the deferred `type=module` SPA executes, so the
     * SPA reads it synchronously at eval. Registered on both the prod and dev
     * admin paths — dev enqueues the SPA through the Script Modules API too, so
     * WP emits the data tag identically in both.
     */
    private function registerAdminConfigData(): void
    {
        Hooks::addFilter('script_module_data_' . self::ADMIN_MODULE_ID, [$this, 'createConfigVariable']);
    }

    /**
     * Script Module Data filter callback for the admin module: merges the SPA
     * config onto whatever data WP (or another filter) has already accumulated.
     * WP encodes the returned array itself into a `type="application/json"` tag,
     * so no manual `wp_json_encode`/escaping is needed here.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createConfigVariable(array $data): array
    {
        return array_merge($data, $this->adminConfig());
    }

    /**
     * Build the admin SPA config array (token/expiry/email/CF-country derivation
     * + the central app/renderer hosts). Sole builder of the 14-key config that
     * the admin Script Module Data filter delivers, and that the dev path prints
     * directly into its data tag.
     *
     * @return array<string, mixed>
     */
    private function adminConfig(): array
    {
        if ($this->container === null) {
            return [];
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

        return [
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
        ];
    }

    public function enqueueFrontendAssets(): void
    {
        global $post;
        $hasShortcode = $post && has_shortcode($post->post_content, 'paymentform');
        $hasBlock = $post && has_block('paymentform/form', $post);

        // Block runtime: the view module is auto-enqueued by register_block_type
        // via block.json `viewScriptModule`, and WP runs `script_module_data_{id}`
        // for it, emitting the config as a `type="application/json"` data tag the
        // view module reads. Register the filter whenever the block is present —
        // independent of whether the built `assets/block-view.js` exists locally,
        // since the data tag is keyed on the registered module id, not the file.
        if ($hasBlock) {
            $rendererBaseUrl = $this->resolveRendererBaseUrl();
            Hooks::addFilter(
                'script_module_data_' . $this->blockViewScriptModuleId,
                static fn (array $data): array => array_merge($data, ['rendererBaseUrl' => $rendererBaseUrl]),
            );
        }

        $view_js = Config::pluginDir() . 'assets/block-view.js';

        if ($hasShortcode && file_exists($view_js)) {
            $version = Config::VERSION . '.' . filemtime($view_js);

            // Shortcode path stays a CLASSIC script (it does not go through
            // block.json). The SAME built block-view.js works both ways — it is
            // import/export-free, so it loads fine as a classic <script> here
            // and as the block's view *module* (block.json viewScriptModule)
            // elsewhere. A distinct classic handle keeps this enqueue independent
            // of the block's Script Module registration and any future classic
            // handle WP might mint for the block.
            wp_enqueue_script(
                'paymentform-shortcode-view',
                Config::pluginUrl() . 'assets/block-view.js',
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
