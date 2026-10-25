<?php

declare(strict_types=1);

namespace PaymentForm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin-level config accessor. Replaces ad-hoc `define()` globals so internal
 * code depends on namespaced lookups instead of polluting the global table.
 *
 * The two upstream URLs (API / app) resolve to their production `DEFAULT_*`
 * constants, overridable in development from `frontend/.env` — the same `APP_*`
 * vars the Vite dev server consumes. The frontend source (and thus `.env`) never
 * ships in the built plugin (see bin/package.sh), so production always uses the
 * defaults and no `PAYMENTFORM_*` / `wp-config.php` globals are involved. The
 * renderer host is not configured here: it is the connected tenant's subdomain
 * of the app host (`<tenant>.<app-base>`), derived per-request in
 * PluginServiceProvider via {@see swapAppSubdomain()} — there is no static
 * renderer host. Everything else (plugin path / URL / version) is plugin-internal
 * — callers read it via `Config::*()` so no names leak into the global PHP
 * constant table.
 */
final class Config
{
    public const VERSION = '0.1.0';

    /**
     * Canonical plugin slug. Single source of truth for the text domain, the
     * admin page slug, and the `@<slug>/…` Script Module id namespace.
     */
    public const SLUG = 'paymentform';

    /**
     * Prefix for plugin-owned option names and internal wp-kit hook tags
     * (see {@see withPrefix()}). Single source of truth so the lifecycle
     * provider and option accessors never drift.
     */
    public const VAR_PREFIX = 'paymentform_';

    /** Minimum runtime the {@see \PaymentForm\Providers\InstallerProvider} gates activation on. */
    public const REQUIRED_PHP_VERSION = '8.1';

    public const REQUIRED_WP_VERSION = '6.7';

    private const DEFAULT_API_BASE_URL = 'https://api.paymentform.io';

    private const DEFAULT_APP_BASE_URL = 'https://app.paymentform.io';

    private static ?string $pluginFile = null;

    /** Parsed `frontend/.env` dev overrides, memoised per request ({@see devOverride()}). */
    private static ?array $devEnv = null;

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

    /**
     * `plugin_basename` of the entrypoint (e.g. `paymentform/paymentform.php`).
     * Required by the lifecycle hooks the {@see \PaymentForm\Providers\InstallerProvider}
     * registers.
     */
    public static function pluginBasename(): string
    {
        return plugin_basename(self::pluginFile());
    }

    /**
     * Namespaces an option/hook key with {@see VAR_PREFIX} so plugin-owned
     * entries never collide with WordPress core or sibling plugins.
     */
    public static function withPrefix(string $key): string
    {
        return self::VAR_PREFIX . $key;
    }

    /**
     * Reads a plugin-owned option (auto-prefixed). Mirrors `get_option`'s
     * default semantics.
     */
    public static function getOption(string $option, mixed $default = false): mixed
    {
        return get_option(self::withPrefix($option), $default);
    }

    /**
     * Writes a plugin-owned option (auto-prefixed).
     */
    public static function updateOption(string $option, mixed $value, bool $autoload = false): bool
    {
        return update_option(self::withPrefix($option), $value, $autoload);
    }

    /**
     * Creates a plugin-owned option (auto-prefixed). Returns false if the
     * option already exists — mirrors `add_option`'s atomic create semantics.
     */
    public static function addOption(string $option, mixed $value, bool $autoload = false): bool
    {
        return add_option(self::withPrefix($option), $value, '', $autoload ? 'yes' : 'no');
    }

    /**
     * Deletes a plugin-owned option (auto-prefixed).
     */
    public static function deleteOption(string $option): bool
    {
        return delete_option(self::withPrefix($option));
    }

    public static function apiBaseUrl(): string
    {
        return self::devOverride('APP_API_BASE_URL') ?? self::DEFAULT_API_BASE_URL;
    }

    /** Whether the API base URL comes from the dev-only `frontend/.env` override. */
    public static function hasDevApiBaseUrl(): bool
    {
        return self::devOverride('APP_API_BASE_URL') !== null;
    }

    public static function appBaseUrl(): string
    {
        return self::devOverride('APP_APP_BASE_URL') ?? self::DEFAULT_APP_BASE_URL;
    }

    /**
     * Rewrites the leading `app.` label of an `app.<base>` URL's host to
     * `<subdomain>.<base>`, preserving scheme and port. Returns host-only
     * (`scheme://host[:port]`) — any path/query/fragment is discarded, which is
     * intended: the inputs are bare host roots. Returns null when the URL has no
     * parseable host or its host is not the expected `app.*` shape, so callers
     * fall back. Used to build the per-tenant renderer host (`<tenant>.<base>`)
     * from the app base URL.
     */
    public static function swapAppSubdomain(string $url, string $subdomain): ?string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !str_starts_with((string) $parts['host'], 'app.')) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $host = $subdomain . '.' . substr((string) $parts['host'], 4);

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    /**
     * Development override for an upstream URL, read from `frontend/.env` — the
     * same `APP_*` vars the Vite dev server consumes. Returns the value when the
     * key is present and non-empty, null otherwise so the caller falls back to
     * its production `DEFAULT_*` constant. Only honoured when the site opts in
     * via {@see devModeEnabled()}, so a stray `.env` can never repoint a live site.
     * Parsed once per request.
     */
    private static function devOverride(string $key): ?string
    {
        if (!self::devModeEnabled()) {
            return null;
        }

        if (self::$devEnv === null) {
            self::$devEnv = self::parseEnvFile(self::pluginDir() . 'frontend/.env');
        }

        $value = self::$devEnv[$key] ?? '';

        return $value !== '' ? $value : null;
    }

    /**
     * Minimal `KEY=VALUE` reader for `frontend/.env`: skips blank and
     * `#`-comment lines, trims surrounding whitespace and one layer of matching
     * quotes. Sufficient for the handful of `APP_*` dev overrides — not a
     * general-purpose dotenv implementation.
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }

        return $env;
    }

    /**
     * Dev-mode marker: the Vite dev server writes its port to `frontend/port`
     * on boot and removes it on shutdown (see vite.config.ts,
     * setDevServerConfig). The file's presence means "serve the admin SPA
     * from the dev server with HMR" instead of the built `assets/` bundle.
     * Requires the {@see devModeEnabled()} opt-in.
     */
    public static function isDev(): bool
    {
        return self::devModeEnabled() && is_readable(self::pluginDir() . 'frontend/port');
    }

    /** Development tooling opt-in: `define('PAYMENTFORM_DEV', true);` in wp-config.php. */
    private static function devModeEnabled(): bool
    {
        return defined('PAYMENTFORM_DEV') && PAYMENTFORM_DEV === true;
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
