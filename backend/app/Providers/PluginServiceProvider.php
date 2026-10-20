<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

use PaymentForm\Admin\AdminNoticeSuppressor;
use PaymentForm\Config;
use PaymentForm\Cron\PurgeExpiredFlowOptions;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\SetupProofController;
use PaymentForm\HTTP\Controllers\WebhookController;
use PaymentForm\HTTP\Rewrites;
use PaymentForm\HTTP\Shortcode;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\Http\HttpTransport;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\TokenRefreshLock;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\WebhookService;

final class PluginServiceProvider
{
    /**
     * Script Module id for the admin SPA, in the npm-scope style WP core uses
     * for its own modules (`@wordpress/*`). The admin SPA config is delivered
     * through the WP 6.7 Script Module Data API keyed on this id, so it must
     * match the `wp_enqueue_script_module()` id, the `script_module_data_{id}`
     * filter tag, and the frontend reader's `wp-script-module-data-{id}` lookup.
     */
    private const ADMIN_MODULE_ID = '@'.Config::SLUG.'/admin';

    /**
     * Dev-only Script Module ids. In dev the admin SPA is served from the Vite
     * dev server, but it still ships through the Script Modules API so WP emits
     * the config data tag and keeps dev/prod loading identical. The React Fast
     * Refresh preamble and the Vite HMR client are enqueued as their own modules
     * so they execute before the SPA entry.
     */
    private const DEV_REFRESH_MODULE_ID = '@'.Config::SLUG.'/dev-react-refresh';

    private const DEV_VITE_CLIENT_MODULE_ID = '@'.Config::SLUG.'/vite-client';

    /**
     * Script Module id for the form-embed block's editor entry. WP has no
     * `editorScriptModule` block.json field, so the editor bundle is enqueued
     * through the Script Modules API directly (not via `register_block_type`)
     * and its config is delivered on the `script_module_data_{id}` filter keyed
     * on this id — the editor reads the matching `wp-script-module-data-{id}`
     * JSON tag.
     */
    private const EDITOR_MODULE_ID = '@'.Config::SLUG.'/block-editor';

    private ?Container $container = null;

    public function register(Container $c): void
    {
        $this->container = $c;
        // Core encryption service
        $c[EncryptionService::class] = static fn () => new EncryptionService;

        // Token management
        $c[TokenService::class] = static fn ($c) => new TokenService($c[EncryptionService::class]);

        // Token refresh synchronization
        $c[TokenRefreshLock::class] = static fn () => new TokenRefreshLock;

        // Shared outbound HTTP transport (wp-kit HttpClient adapter). Owns the
        // API base URL; stateless beyond that — a fresh client is built per call
        // inside send(). Only a dev override may point at a private/loopback host.
        $c[HttpTransport::class] = static fn () => new HttpTransport(
            Config::apiBaseUrl(),
            allowPrivateBaseHost: Config::hasDevApiBaseUrl(),
        );

        // API client with token awareness
        $c[ApiClient::class] = static fn ($c) => new ApiClient(
            $c[TokenService::class],
            $c[TokenRefreshLock::class],
            $c[HttpTransport::class]
        );

        // Unauthenticated client for the pre-auth setup/exchange endpoints.
        $c[SetupApiClient::class] = static fn ($c) => new SetupApiClient(
            $c[HttpTransport::class]
        );

        // OAuth-like connect flow
        $c[ConnectService::class] = static fn () => new ConnectService;

        // Webhook signature verification
        $c[WebhookService::class] = static fn () => new WebhookService;

        // Non-REST controllers, dispatched by Rewrites.php from the
        // container. REST controllers are NOT bound here: wp-kit instantiates
        // them with zero args and they pull services from ServiceContainer
        // (see Plugin.php docblock for the composition-root split).
        $c[CallbackController::class] = static fn ($c) => new CallbackController(
            $c[ConnectService::class],
            $c[TokenService::class],
            $c[SetupApiClient::class]
        );

        $c[WebhookController::class] = static fn ($c) => new WebhookController(
            $c[TokenService::class],
            $c[WebhookService::class]
        );

        $c[SetupProofController::class] = static fn () => new SetupProofController;
    }

    public function boot(): void
    {
        Hooks::addAction('init', [$this, 'loadTextdomain']);
        Hooks::addAction('init', [$this, 'registerBlockAndShortcode']);
        Hooks::addAction('init', [$this, 'registerCronSchedules']);
        Hooks::addAction('admin_menu', [$this, 'registerAdminMenu']);
        Hooks::addAction('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        Hooks::addAction('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        Hooks::addAction('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
        (new AdminNoticeSuppressor)->register();

        if ($this->container !== null) {
            (new Rewrites($this->container))->register();
        }
    }

    /** Ensure the daily flow-option purge is scheduled and bound to its handler. */
    public function registerCronSchedules(): void
    {
        if (! wp_next_scheduled(PurgeExpiredFlowOptions::HOOK)) {
            wp_schedule_event(time(), 'daily', PurgeExpiredFlowOptions::HOOK);
        }
        Hooks::addAction(PurgeExpiredFlowOptions::HOOK, [new PurgeExpiredFlowOptions, 'run']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('paymentform', false, dirname(plugin_basename(Config::pluginFile())).'/languages');
    }

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
        $blockType = register_block_type(Config::pluginDir().'assets/form-embed');
        if ($blockType && ! empty($blockType->view_script_module_ids)) {
            $this->blockViewScriptModuleId = (string) $blockType->view_script_module_ids[0];
        }
        (new Shortcode)->register();
    }

    /**
     * Enqueue the form-embed block's editor entry as a Script Module and deliver
     * its config through the WP Script Module Data API — the same delivery path
     * the admin SPA and the block view use.
     *
     * Runs on `enqueue_block_editor_assets` (every Gutenberg editor). Cheap: one
     * module enqueue plus a single cached `TokenService` read inside
     * `resolveRendererBaseUrl()` when the data filter fires.
     */
    public function enqueueBlockEditorAssets(): void
    {
        if ($this->container === null) {
            return;
        }

        // The editor bundle externalizes @wordpress/* to the editor's `wp.*`
        // globals, so its classic deps come from block-editor.asset.php (full
        // handle set as the partial-build fallback).
        $fallbackDeps = ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch'];
        if ($this->enqueueBuiltScriptModule(self::EDITOR_MODULE_ID, 'block-editor', $fallbackDeps) === null) {
            return;
        }

        // WP runs `script_module_data_{id}` when it prints the enqueued module,
        // emitting the returned array as a `wp-script-module-data-{id}` JSON tag
        // the editor reads at module eval (see frontend blocks/form-embed/edit.tsx).
        Hooks::addFilter(
            'script_module_data_'.self::EDITOR_MODULE_ID,
            [$this, 'createBlockEditorConfigData']
        );
    }

    /**
     * Script Module Data filter callback for the editor module. The Edit
     * component needs the REST nonce (it registers its own apiFetch nonce
     * middleware), the tenant renderer host (preview iframe) and the app host
     * (empty-state "create form" CTA). Merges onto whatever WP or another filter
     * accumulated; WP encodes and escapes the JSON tag itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createBlockEditorConfigData(array $data): array
    {
        return array_merge($data, [
            'nonce' => wp_create_nonce('wp_rest'),
            'appBaseUrl' => Config::appBaseUrl(),
            'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
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
            .'<path d="M11 25V8H18.7645C20.0345 8 21.1116 8.21702 21.9957 8.65106C22.8799 9.08511 23.547 9.70402 23.9972 10.5078'
            .'C24.4634 11.3116 24.6965 12.2842 24.6965 13.4255C24.6965 14.5669 24.4634 15.5475 23.9972 16.3674'
            .'C23.547 17.1712 22.8799 17.7901 21.9957 18.2241C21.1116 18.6582 20.0345 18.8752 18.7645 18.8752'
            .'H14.7376V22L11 25ZM14.7376 15.9816H18.1376C19.1021 15.9816 19.8336 15.7645 20.3319 15.3305'
            .'C20.8463 14.8965 21.1035 14.2615 21.1035 13.4255C21.1035 12.5896 20.8463 11.9626 20.3319 11.5447'
            .'C19.8336 11.1267 19.1021 10.9177 18.1376 10.9177H14.7376V15.9816Z"/></svg>';
        $iconSvg = 'data:image/svg+xml;base64,'.base64_encode($svg);

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
        } elseif (! $this->enqueueBuiltAdminAssets()) {
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

        $css_file = Config::pluginDir().'assets/admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style('paymentform-admin', Config::pluginUrl().'assets/admin.css', [], Config::VERSION);
        }

        wp_enqueue_script_module(self::DEV_REFRESH_MODULE_ID, $dev.'/dev-react-refresh.js', [], null);
        wp_enqueue_script_module(self::DEV_VITE_CLIENT_MODULE_ID, $dev.'/@vite/client', [], null);
        wp_enqueue_script_module(self::ADMIN_MODULE_ID, $dev.'/src/main.tsx', [], null);
    }

    /**
     * Enqueue a built `assets/<stem>.js` bundle as a Script Module, returning its
     * version string — or null when no build exists (fresh checkout), so the
     * caller can skip its follow-up steps.
     *
     * The bundle externalizes `@wordpress/*` to `wp.*` globals, so a Script
     * Module's module-id-only dependency array cannot declare them; the classic
     * `wp-*` handles from `<stem>.asset.php` are enqueued separately — WP prints
     * them before the deferred module runs, so the globals exist at module eval.
     * `$fallbackDeps` + filemtime cover a partial build shipped without the
     * `.asset.php` manifest.
     *
     * @param  list<string>  $fallbackDeps
     */
    private function enqueueBuiltScriptModule(string $moduleId, string $stem, array $fallbackDeps): ?string
    {
        $assets_dir = Config::pluginDir().'assets';
        $js_file = $assets_dir.'/'.$stem.'.js';

        if (! file_exists($js_file)) {
            return null;
        }

        $asset_file = $assets_dir.'/'.$stem.'.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : null;
        $version = is_array($asset) && isset($asset['version'])
            ? (string) $asset['version']
            : Config::VERSION.'.'.filemtime($js_file);
        $deps = is_array($asset) && isset($asset['dependencies']) && is_array($asset['dependencies'])
            ? $asset['dependencies']
            : $fallbackDeps;

        foreach ($deps as $dep) {
            wp_enqueue_script($dep);
        }

        wp_enqueue_script_module($moduleId, Config::pluginUrl().'assets/'.$stem.'.js', [], $version);

        return $version;
    }

    /**
     * Production: enqueue the built admin SPA Script Module + its stylesheet.
     * Returns false when no build exists (fresh checkout) — callers skip the
     * config-data step entirely, matching the historical early-return behaviour.
     */
    private function enqueueBuiltAdminAssets(): bool
    {
        $version = $this->enqueueBuiltScriptModule(self::ADMIN_MODULE_ID, 'admin', ['wp-element']);
        if ($version === null) {
            return false;
        }

        // The build always emits `assets/admin.css` (chrome overrides). Guard
        // the enqueue with file_exists so a partial build (JS present, CSS
        // missing) degrades to no-stylesheet rather than a 404. Same version as
        // the module so cache-busting stays in lockstep.
        if (file_exists(Config::pluginDir().'assets/admin.css')) {
            wp_enqueue_style(
                'paymentform-admin',
                Config::pluginUrl().'assets/admin.css',
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
        Hooks::addFilter('script_module_data_'.self::ADMIN_MODULE_ID, [$this, 'createConfigVariable']);
    }

    /**
     * Script Module Data filter callback for the admin module: merges the SPA
     * config onto whatever data WP (or another filter) has already accumulated.
     * WP encodes the returned array itself into a `type="application/json"` tag,
     * so no manual `wp_json_encode`/escaping is needed here.
     *
     * @param  array<string, mixed>  $data
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
        if (! preg_match('/^[A-Z]{2}$/', $cfCountry)) {
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
            // CopyEmbedButton and FormPreview use this for the "Iframe URL" + preview iframe
            // against the correct subdomain instead of the static `app.` host.
            'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
        ];
    }

    public function enqueueFrontendAssets(): void
    {
        global $post;
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
                'script_module_data_'.$this->blockViewScriptModuleId,
                static fn (array $data): array => array_merge($data, ['rendererBaseUrl' => $rendererBaseUrl]),
            );
        }

        $this->registerShortcodeView();
    }

    /**
     * Registers the classic shortcode view script (enqueued by Shortcode::render) with its renderer config.
     */
    private function registerShortcodeView(): void
    {
        // The same built block-view.js serves the block (as a Script Module) and the
        // shortcode (as this classic script); it is import/export-free so both work.
        $viewJs = Config::pluginDir().'assets/block-view.js';
        $version = Config::VERSION.(file_exists($viewJs) ? '.'.filemtime($viewJs) : '');

        wp_register_script(
            Shortcode::VIEW_HANDLE,
            Config::pluginUrl().'assets/block-view.js',
            [],
            $version,
            true
        );

        wp_localize_script(Shortcode::VIEW_HANDLE, 'paymentformView', [
            'rendererBaseUrl' => $this->resolveRendererBaseUrl(),
        ]);
    }

    /**
     * The renderer host is the connected tenant's subdomain of the app host —
     * swap the `app.` label in the app base URL for the tenant slug so the iframe
     * lands on `<tenant>.<base>/<form-id>`. There is no static renderer host:
     * without an active connection (no container / no tenant, or an app host that
     * is not the expected `app.*` shape) this returns an empty string, and every
     * consumer (admin SPA, block editor preview, block/shortcode view) treats an
     * empty renderer base as "not connected".
     */
    private function resolveRendererBaseUrl(): string
    {
        if ($this->container === null) {
            return '';
        }

        $tenant = $this->container[TokenService::class]->getTenant();
        if (! is_string($tenant) || $tenant === '') {
            return '';
        }

        return Config::swapAppSubdomain(Config::appBaseUrl(), $tenant) ?? '';
    }
}
