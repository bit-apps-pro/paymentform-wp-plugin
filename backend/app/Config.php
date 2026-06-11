<?php

declare(strict_types=1);

namespace PaymentForm;

/**
 * Plugin-level config accessor. Replaces ad-hoc `define()` globals so internal
 * code depends on namespaced lookups instead of polluting the global table.
 *
 * Two upstream URLs (`PAYMENTFORM_API_BASE_URL`, `PAYMENTFORM_APP_BASE_URL`)
 * stay as `define()` constants because operators routinely override them from
 * `wp-config.php`; the matching getters here honour the override when present
 * and fall back to production defaults otherwise. Everything else
 * (plugin path / URL / version, renderer URL) is plugin-internal — callers
 * read it via `Config::*()` so we never leak names like `PAYMENTFORM_VERSION`
 * into the global PHP constant table.
 */
final class Config
{
    public const VERSION = '0.1.0';

    private const DEFAULT_API_BASE_URL = 'https://api.paymentform.io';

    private const DEFAULT_APP_BASE_URL = 'https://app.paymentform.io';

    private const DEFAULT_RENDERER_BASE_URL = 'https://render.paymentform.io';

    private static ?string $pluginFile = null;

    /**
     * Called once from the entrypoint with `__FILE__`; every other accessor
     * derives its value (dir, URL) from this single anchor so the plugin
     * survives being symlinked / mu-loaded.
     */
    public static function bootstrap(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
    }

    public static function pluginFile(): string
    {
        if (self::$pluginFile === null) {
            throw new \LogicException('Config::bootstrap() must run before plugin services initialise.');
        }

        return self::$pluginFile;
    }

    public static function pluginDir(): string
    {
        return plugin_dir_path(self::pluginFile());
    }

    public static function pluginUrl(): string
    {
        return plugin_dir_url(self::pluginFile());
    }

    public static function apiBaseUrl(): string
    {
        return defined('PAYMENTFORM_API_BASE_URL') ? (string) PAYMENTFORM_API_BASE_URL : self::DEFAULT_API_BASE_URL;
    }

    public static function appBaseUrl(): string
    {
        return defined('PAYMENTFORM_APP_BASE_URL') ? (string) PAYMENTFORM_APP_BASE_URL : self::DEFAULT_APP_BASE_URL;
    }

    public static function rendererBaseUrl(): string
    {
        return defined('PAYMENTFORM_RENDERER_BASE_URL') ? (string) PAYMENTFORM_RENDERER_BASE_URL : self::DEFAULT_RENDERER_BASE_URL;
    }

    /**
     * Dev-mode marker: the Vite dev server writes its port to `frontend/port`
     * on boot and removes it on shutdown (see frontend/vite.config.ts,
     * setDevServerConfig). The file's presence means "serve the admin SPA
     * from the dev server with HMR" instead of the built `dist/` bundle.
     */
    public static function isDev(): bool
    {
        return is_readable(self::pluginDir() . 'frontend/port');
    }

    /**
     * Origin of the running Vite dev server. Only meaningful when
     * {@see isDev()} returns true; falls back to Vite's default port when
     * the port file holds anything other than digits.
     */
    public static function devServerUrl(): string
    {
        $portFile = self::pluginDir() . 'frontend/port';
        $port = is_readable($portFile) ? trim((string) file_get_contents($portFile)) : '';
        if (!ctype_digit($port)) {
            $port = '5173';
        }

        return 'http://localhost:' . $port;
    }
}
