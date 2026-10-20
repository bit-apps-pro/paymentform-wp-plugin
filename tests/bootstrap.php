<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// backend/hooks/*.php route files guard on ABSPATH; define it so tests can
// include them through the wp-kit Router (Router::registerFile()).
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wordpress/');
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'test-auth-key-' . str_repeat('a', 32));
}
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-' . str_repeat('b', 32));
}
if (!defined('SECURE_AUTH_KEY')) {
    define('SECURE_AUTH_KEY', 'test-secure-auth-key-' . str_repeat('c', 32));
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// Mock WordPress role
class TestWpRole
{
    private array $capabilities = [];

    public function add_cap(string $cap): void
    {
        $this->capabilities[$cap] = true;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->capabilities[$cap]);
    }

    public function has_cap(string $cap): bool
    {
        return isset($this->capabilities[$cap]);
    }
}

// Global test double for WordPress functions
class TestWpFunctions
{
    private static array $options = [];
    /** @var array<string, string> option name => stored autoload value ('on'/'off', WP 6.6+ semantics) */
    private static array $autoload = [];
    private static array $transients = [];
    private static int $nocacheHeadersSent = 0;
    /** @var list<array{0: string, 1: string}> [function, option name] for every options-API call, in order */
    private static array $optionApiCalls = [];
    /** @var list<array{0: string, 1: string}> [group, key] passed to wp_cache_delete(), in order */
    private static array $cacheDeletes = [];
    /** @var list<string> hooks passed to wp_clear_scheduled_hook(), in call order */
    private static array $clearedScheduledHooks = [];
    private static object $currentUser;
    private static array $roles = [];
    private static ?Closure $nonceValidator = null;
    /** null = legacy default: every capability check passes. */
    private static ?array $grantedCaps = null;
    /** @var array<string, list<callable>> */
    private static array $filters = [];
    /** @var array<string, list<array{callback: callable, priority: int}>> */
    private static array $actions = [];
    /** null = fall back to scanning post content for the block name. */
    private static ?bool $hasBlock = null;
    /** null = get_current_screen() returns null (screen not yet set). */
    private static ?string $currentScreenId = null;
    /** @var list<string> hooks passed to remove_all_actions(), in call order */
    private static array $removedAllActions = [];
    /** @var array<string, array{src: string, in_footer: bool}> scripts passed to wp_register_script() */
    private static array $registeredScripts = [];
    /** @var list<string> handles passed to wp_enqueue_script(), in call order */
    private static array $enqueuedScripts = [];
    /** @var array<string, array{name: string, data: array}> wp_localize_script() payloads keyed by handle */
    private static array $localizedScripts = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$nocacheHeadersSent = 0;
        self::$optionApiCalls = [];
        self::$cacheDeletes = [];
        if (($GLOBALS['wpdb'] ?? null) instanceof TestWpDb) {
            $GLOBALS['wpdb']->reset();
        }
        self::$clearedScheduledHooks = [];
        self::$currentUser = (object) ['ID' => 1, 'user_login' => 'test_user'];
        self::$roles = ['administrator' => new TestWpRole()];
        self::$nonceValidator = null;
        self::$grantedCaps = null;
        self::$filters = [];
        self::$actions = [];
        self::$hasBlock = null;
        self::$currentScreenId = null;
        self::$removedAllActions = [];
        self::$registeredScripts = [];
        self::$enqueuedScripts = [];
        self::$localizedScripts = [];

        // Config memoises the parsed frontend/.env on first URL-getter call; the
        // static survives the whole test process. Clear it so each test starts
        // from a clean slate and never inherits a prior test's resolved hosts.
        (new ReflectionProperty(\PaymentForm\Config::class, 'devEnv'))->setValue(null, null);
    }

    /**
     * Forces has_block() to a fixed result for a single test. Pass null to
     * restore the default (scan the post content for the block name).
     */
    public static function setHasBlock(?bool $present): void
    {
        self::$hasBlock = $present;
    }

    /** Sets the id get_current_screen() reports; null means no screen. */
    public static function setCurrentScreenId(?string $id): void
    {
        self::$currentScreenId = $id;
    }

    /** Returns the stubbed WP_Screen-like object, or null when unset. */
    public static function getCurrentScreen(): ?object
    {
        return self::$currentScreenId === null ? null : (object) ['id' => self::$currentScreenId];
    }

    /** Records a remove_all_actions() call. */
    public static function removeAllActions(string $hook): void
    {
        self::$removedAllActions[] = $hook;
    }

    /** @return list<string> hooks cleared via remove_all_actions(), in call order */
    public static function getRemovedAllActions(): array
    {
        return self::$removedAllActions;
    }

    /** Records a wp_register_script() call. */
    public static function registerScript(string $handle, string $src, bool $inFooter): void
    {
        self::$registeredScripts[$handle] = ['src' => $src, 'in_footer' => $inFooter];
    }

    /** @return array<string, array{src: string, in_footer: bool}> */
    public static function getRegisteredScripts(): array
    {
        return self::$registeredScripts;
    }

    /** Records a wp_enqueue_script() call. */
    public static function enqueueScript(string $handle): void
    {
        self::$enqueuedScripts[] = $handle;
    }

    /** @return list<string> */
    public static function getEnqueuedScripts(): array
    {
        return self::$enqueuedScripts;
    }

    /** Records a wp_localize_script() call. */
    public static function localizeScript(string $handle, string $name, array $data): void
    {
        self::$localizedScripts[$handle] = ['name' => $name, 'data' => $data];
    }

    /** @return array<string, array{name: string, data: array}> */
    public static function getLocalizedScripts(): array
    {
        return self::$localizedScripts;
    }

    public static function hasBlock(string $blockName, mixed $post): bool
    {
        if (self::$hasBlock !== null) {
            return self::$hasBlock;
        }

        $content = is_object($post) ? ($post->post_content ?? '') : (string) $post;

        return str_contains((string) $content, '<!-- wp:' . $blockName);
    }

    public static function getOption(string $option, mixed $default = false): mixed
    {
        return self::$options[$option] ?? $default;
    }

    public static function addOption(string $option, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
    {
        // add_option returns false if option already exists (atomic DB unique-key behavior)
        if (isset(self::$options[$option])) {
            return false;
        }
        self::$options[$option] = $value;
        self::$autoload[$option] = in_array($autoload, ['yes', 'on'], true) ? 'on' : 'off';
        return true;
    }

    public static function updateOption(string $option, mixed $value, bool $autoload = true): bool
    {
        self::$options[$option] = $value;
        self::$autoload[$option] = $autoload ? 'on' : 'off';
        return true;
    }

    /** Mirrors delete_option(): false when the option did not exist (single-use consumption relies on it). */
    public static function deleteOption(string $option): bool
    {
        $existed = array_key_exists($option, self::$options);
        unset(self::$options[$option], self::$autoload[$option]);
        return $existed;
    }

    /** @return array<string, array{value: mixed, autoload: string}> stored options in insertion (option_id) order */
    public static function optionRows(): array
    {
        $rows = [];
        foreach (self::$options as $name => $value) {
            $rows[$name] = ['value' => $value, 'autoload' => self::$autoload[$name] ?? 'on'];
        }
        return $rows;
    }

    /** Records a get_option()/add_option()/update_option()/delete_option() call. */
    public static function recordOptionApiCall(string $function, string $option): void
    {
        self::$optionApiCalls[] = [$function, $option];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function getOptionApiCalls(): array
    {
        return self::$optionApiCalls;
    }

    /** Stores a row as a raw $wpdb->insert() would; false when the name already exists. */
    public static function insertOptionRow(string $option, mixed $value, string $autoload): bool
    {
        if (array_key_exists($option, self::$options)) {
            return false;
        }
        self::$options[$option] = $value;
        self::$autoload[$option] = $autoload;
        return true;
    }

    /** Records a wp_cache_delete() call. */
    public static function cacheDelete(string $key, string $group): void
    {
        self::$cacheDeletes[] = [$group, $key];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function getCacheDeletes(): array
    {
        return self::$cacheDeletes;
    }

    /** Records a nocache_headers() call. */
    public static function sendNocacheHeaders(): void
    {
        self::$nocacheHeadersSent++;
    }

    /** Number of nocache_headers() calls since reset. */
    public static function getNocacheHeadersSent(): int
    {
        return self::$nocacheHeadersSent;
    }

    /** Records a wp_clear_scheduled_hook() call. */
    public static function clearScheduledHook(string $hook): void
    {
        self::$clearedScheduledHooks[] = $hook;
    }

    /** @return list<string> */
    public static function getClearedScheduledHooks(): array
    {
        return self::$clearedScheduledHooks;
    }

    public static function setTransient(string $transient, mixed $value, int $expiration = 0): bool
    {
        self::$transients[$transient] = [
            'value' => $value,
            'expires_at' => $expiration > 0 ? time() + $expiration : PHP_INT_MAX,
        ];
        return true;
    }

    public static function getTransient(string $transient): mixed
    {
        if (!isset(self::$transients[$transient])) {
            return false;
        }

        if (self::$transients[$transient]['expires_at'] < time()) {
            unset(self::$transients[$transient]);
            return false;
        }

        return self::$transients[$transient]['value'];
    }

    public static function deleteTransient(string $transient): bool
    {
        unset(self::$transients[$transient]);
        return true;
    }

    public static function getCurrentUser(): object
    {
        return self::$currentUser;
    }

    public static function setCurrentUser(object $user): void
    {
        self::$currentUser = $user;
    }

    public static function getHomeUrl(string $path = ''): string
    {
        $base = 'http://example.test';
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }

    public static function getRole(string $roleName): ?TestWpRole
    {
        return self::$roles[$roleName] ?? null;
    }

    /**
     * Overrides wp_verify_nonce() behaviour for a single test. Pass null to
     * restore the default (any non-empty nonce verifies).
     *
     * @param null|callable(?string, string): (int|false) $validator
     */
    public static function setNonceValidator(?callable $validator): void
    {
        self::$nonceValidator = $validator === null ? null : Closure::fromCallable($validator);
    }

    public static function verifyNonce(?string $nonce, string $action): int|false
    {
        if (self::$nonceValidator !== null) {
            return (self::$nonceValidator)($nonce, $action);
        }

        // Default mirrors the historical stub: any non-empty nonce verifies.
        return $nonce ? 1 : false;
    }

    /**
     * Restricts current_user_can() to the given capabilities. Pass null to
     * restore the default (every capability check passes).
     *
     * @param null|list<string> $caps
     */
    public static function setGrantedCaps(?array $caps): void
    {
        self::$grantedCaps = $caps;
    }

    public static function userCan(string $cap): bool
    {
        return self::$grantedCaps === null || in_array($cap, self::$grantedCaps, true);
    }

    public static function addFilter(string $tag, callable $callback): void
    {
        self::$filters[$tag][] = $callback;
    }

    public static function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach (self::$filters[$tag] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$actions[$tag][] = ['callback' => $callback, 'priority' => $priority];
    }

    /**
     * Callbacks recorded by the add_action() stub, in registration order.
     * The harness never fires hooks — tests invoke callbacks explicitly.
     *
     * @return list<callable>
     */
    public static function getActions(string $tag): array
    {
        return array_map(
            static fn (array $record): callable => $record['callback'],
            self::$actions[$tag] ?? []
        );
    }

    /**
     * Full action records (callback + priority), in registration order — for
     * tests that assert hook ordering (e.g. an inline-data printer must run at
     * a lower priority number than a module printer).
     *
     * @return list<array{callback: callable, priority: int}>
     */
    public static function getActionRecords(string $tag): array
    {
        return self::$actions[$tag] ?? [];
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        TestWpFunctions::recordOptionApiCall('get_option', $option);
        return TestWpFunctions::getOption($option, $default);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|null $autoload = null): bool
    {
        TestWpFunctions::recordOptionApiCall('update_option', $option);
        return TestWpFunctions::updateOption($option, $value, $autoload ?? true);
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
    {
        TestWpFunctions::recordOptionApiCall('add_option', $option);
        return TestWpFunctions::addOption($option, $value, $deprecated, $autoload);
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        TestWpFunctions::recordOptionApiCall('delete_option', $option);
        return TestWpFunctions::deleteOption($option);
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return TestWpFunctions::setTransient($transient, $value, $expiration);
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return TestWpFunctions::getTransient($transient);
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        return TestWpFunctions::deleteTransient($transient);
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return TestWpFunctions::getCurrentUser();
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) (TestWpFunctions::getCurrentUser()->ID ?? 0);
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return TestWpFunctions::getHomeUrl($path);
    }
}

if (!function_exists('http_build_query')) {
    function http_build_query(array $data): string
    {
        return \http_build_query($data);
    }
}

if (!function_exists('get_role')) {
    function get_role(string $role)
    {
        return TestWpFunctions::getRole($role);
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
        // No-op in tests
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return TestWpFunctions::getCurrentUser();
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        $base = 'http://example.test';
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        $base = 'http://example.test/wp-json/';
        return $path ? $base . ltrim($path, '/') : $base;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        $base = 'http://example.test/wp-admin/';
        return $path ? $base . ltrim($path, '/') : $base;
    }
}

if (!function_exists('rest_api_init')) {
    function rest_api_init(): void
    {
        // No-op in tests
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = []): bool
    {
        // No-op in tests — route registration is tested by integration tests
        return true;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): void
    {
        throw new RedirectException($location, $status);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(?string $nonce, string $action = ''): int|false
    {
        // Default: valid for any non-empty nonce; override per-test via
        // TestWpFunctions::setNonceValidator().
        return TestWpFunctions::verifyNonce($nonce, $action);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap, mixed ...$args): bool
    {
        // Default: every check passes; restrict per-test via
        // TestWpFunctions::setGrantedCaps().
        return TestWpFunctions::userCan($cap);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        // Minimal harness: priority/accepted_args are ignored.
        TestWpFunctions::addFilter($tag, $callback);
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return TestWpFunctions::applyFilters($tag, $value, ...$args);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($str)));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return 'paymentform/paymentform.php';
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $function_to_add, int $priority = 10, int $accepted_args = 1): bool
    {
        // Recorded but never fired; accepted_args is ignored. Priority IS
        // recorded so tests can assert hook ordering. Inspect via
        // TestWpFunctions::getActions() / getActionRecords().
        TestWpFunctions::addAction($hook, $function_to_add, $priority);
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        // Harness never fires hooks; tests invoke recorded callbacks explicitly
        // (mirrors the add_action() stub contract).
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        // Recorded under a synthetic tag so tests can assert the lifecycle
        // provider registered its activation callback.
        TestWpFunctions::addAction('__activation_hook__', $callback);
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        TestWpFunctions::addAction('__deactivation_hook__', $callback);
    }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook(string $file, callable $callback): void
    {
        TestWpFunctions::addAction('__uninstall_hook__', $callback);
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): void
    {
        TestWpFunctions::enqueueScript($handle);
    }
}

if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string|false $src, array $deps = [], mixed $ver = false, array|bool $args = []): bool
    {
        TestWpFunctions::registerScript($handle, (string) $src, $args === true || (is_array($args) && ($args['in_footer'] ?? false)));
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $object_name, string $var_name, array $l10n): bool
    {
        TestWpFunctions::localizeScript($object_name, $var_name, $l10n);
        return true;
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, string|false $src, array $deps = [], mixed $ver = false, string $media = 'all'): bool
    {
        // No-op in tests
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): void
    {
        // No-op in tests
    }
}

if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style(string $handle, string $data): bool
    {
        // No-op in tests
        return true;
    }
}

if (!function_exists('wp_register_script_module')) {
    function wp_register_script_module(string $id, string $src, array $deps = [], string|false|null $version = false): void
    {
        // No-op in tests — Script Module registration is browser-verified.
    }
}

if (!function_exists('wp_enqueue_script_module')) {
    function wp_enqueue_script_module(string $id, string $src = '', array $deps = [], string|false|null $version = false): void
    {
        // No-op in tests — Script Module enqueue is browser-verified.
    }
}

if (!function_exists('wp_print_inline_script_tag')) {
    function wp_print_inline_script_tag(string $javascript, array $attributes = []): void
    {
        // Mirrors WP core: prints the inline <script> tag. Tests that exercise
        // the footer printers capture output; ordering is asserted via the
        // recorded add_action priorities.
        echo '<script>' . $javascript . '</script>';
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'test-nonce-' . $action;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), '/\\') . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'http://example.test/wp-content/plugins/paymentform/';
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/i', '', $key) ?: '';
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
    {
        $out = [];
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }
        return $out;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        // No-op in tests
    }
}

if (!function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool
    {
        // Check if shortcode is present in content
        return strpos($content, '[' . $tag) !== false;
    }
}

if (!function_exists('has_block')) {
    function has_block(string $block_name, mixed $post = null): bool
    {
        // Mirrors has_shortcode's shape; default scans the post content for the
        // block comment, overridable per-test via TestWpFunctions::setHasBlock().
        return TestWpFunctions::hasBlock($block_name, $post);
    }
}

if (!function_exists('register_block_type')) {
    function register_block_type(string $block_type, array $args = []): void
    {
        // No-op in tests
    }
}

// SetupController / SetupDiagnoseController / SetupProofController type-hint
// WP_REST_Request natively; alias the shared test double so MockWpRestRequest
// instances satisfy that hint in unit tests.
if (!class_exists('WP_REST_Request')) {
    class_alias(\PaymentForm\Tests\Support\MockWpRestRequest::class, 'WP_REST_Request');
}

// Mock WP_REST_Response. The set_* methods mirror WordPress core; wp-kit's
// RouteRegister::sendApiResponse() builds responses through them.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private array $data;
        private int $status;
        private array $headers = [];

        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data = (array) $data;
            $this->status = $status;
        }

        public function get_data(): array
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_data(mixed $data): void
        {
            $this->data = (array) $data;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function set_headers(array $headers): void
        {
            $this->headers = $headers;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

// Exception for redirect testing (thrown by wp_safe_redirect mock)
// Extends Error instead of Exception so it won't be caught by "catch (Exception)"
if (!class_exists('RedirectException')) {
    final class RedirectException extends Error
    {
        public string $location;
        public int $status;

        public function __construct(string $location, int $status = 302)
        {
            $this->location = $location;
            $this->status = $status;
            parent::__construct('Redirect: ' . $location, $status);
        }
    }
}

// Queue-based stub for wp_(safe_)remote_request / wp_remote_retrieve_* / is_wp_error.
// Tests push canned responses with WpHttpMock::queue(...) and inspect the
// recorded calls afterwards.
class WpHttpMock
{
    /** @var array<int, array{response: array{code: int}, headers: array<string, string>, body: string}> */
    public static array $queue = [];

    /** @var array<int, array{url: string, args: array<string, mixed>, option_names: list<string>}> option_names = options stored when the request was sent */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$calls = [];
    }

    public static function queue(int $status, array|string $body = '', array $headers = []): void
    {
        self::$queue[] = [
            'response' => ['code' => $status],
            'headers' => $headers,
            'body' => is_array($body) ? (string) json_encode($body) : $body,
        ];
    }

    public static function queueError(string $message): void
    {
        self::$queue[] = ['error' => new WP_Error('http_request_failed', $message)];
    }

    public static function pop(string $url, array $args): mixed
    {
        self::$calls[] = ['url' => $url, 'args' => $args, 'option_names' => array_keys(TestWpFunctions::optionRows())];

        if (self::$queue === []) {
            return ['response' => ['code' => 500], 'headers' => [], 'body' => ''];
        }

        return array_shift(self::$queue);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private readonly string $code = '',
            private readonly string $message = '',
        ) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        return TestWpFunctions::getCurrentScreen();
    }
}

if (!function_exists('remove_all_actions')) {
    function remove_all_actions(string $hook_name, int|false $priority = false): bool
    {
        TestWpFunctions::removeAllActions($hook_name);
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []): mixed
    {
        $response = WpHttpMock::pop($url, $args);
        return $response['error'] ?? $response;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_safe_remote_request')) {
    function wp_safe_remote_request(string $url, array $args = []): mixed
    {
        $args['reject_unsafe_urls'] = true;
        return wp_remote_request($url, $args);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): mixed
    {
        $args['method'] = 'POST';
        return wp_remote_request($url, $args);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): mixed
    {
        $args['method'] = 'GET';
        return wp_remote_request($url, $args);
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int|string
    {
        return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers(mixed $response): array
    {
        return is_array($response) ? (array) ($response['headers'] ?? []) : [];
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(mixed $response, string $header): string|array
    {
        if (!is_array($response)) {
            return '';
        }

        foreach ((array) ($response['headers'] ?? []) as $name => $value) {
            if (strtolower((string) $name) === strtolower($header)) {
                return $value;
            }
        }

        return '';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        TestWpFunctions::sendNocacheHeaders();
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int|false
    {
        TestWpFunctions::clearScheduledHook($hook);
        return 0;
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize(mixed $data): mixed
    {
        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(int|string $key, string $group = ''): bool
    {
        TestWpFunctions::cacheDelete((string) $key, $group);
        return true;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $data): mixed
    {
        if (!is_string($data)) {
            return $data;
        }
        $value = @unserialize($data, ['allowed_classes' => false]);
        return ($value !== false || $data === 'b:0;') ? $value : $data;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        if (is_object($args)) {
            $parsed = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed = $args;
        } else {
            parse_str((string) $args, $parsed);
        }

        return array_merge($defaults, $parsed);
    }
}

// Mock WordPress database backed by TestWpFunctions' option store. Understands
// just enough SQL for the plugin's option sweeps: `option_name LIKE '…'`
// predicates, an optional `autoload = '…'` filter, and `LIMIT n`.
class TestWpDb
{
    public array $queryLog = [];

    /** When true, insert() fails as a DB write error would. */
    public bool $insertFails = false;

    /** Clears per-test state (called from TestWpFunctions::reset()). */
    public function reset(): void
    {
        $this->queryLog = [];
        $this->insertFails = false;
    }

    /** Inserts an option row; 1 on success, false on duplicate name or forced failure. */
    public function insert(string $table, array $data, array $format = []): int|false
    {
        if ($this->insertFails) {
            return false;
        }
        $inserted = TestWpFunctions::insertOptionRow(
            $data['option_name'],
            maybe_unserialize($data['option_value']),
            $data['autoload'] ?? 'on'
        );
        return $inserted ? 1 : false;
    }

    /** Deletes the option row named in $where; returns the affected count. */
    public function delete(string $table, array $where, array $format = []): int|false
    {
        return TestWpFunctions::deleteOption($where['option_name']) ? 1 : 0;
    }

    /** Returns the serialized option_value for an `option_name = '…'` lookup, or null when absent. */
    public function get_var(string $query): ?string
    {
        $this->queryLog[] = $query;
        if (preg_match("/option_name = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m) !== 1) {
            return null;
        }
        $rows = TestWpFunctions::optionRows();
        $name = stripslashes($m[1]);
        if (!array_key_exists($name, $rows)) {
            return null;
        }
        return $this->serializeValue($rows[$name]['value']);
    }

    /** Runs a DELETE against the matching fake option rows; returns the affected count. */
    public function query(string $query): int|false
    {
        $this->queryLog[] = $query;
        if (stripos($query, 'DELETE') !== 0) {
            return false;
        }
        $names = $this->matchingOptionNames($query);
        foreach ($names as $name) {
            TestWpFunctions::deleteOption($name);
        }
        return count($names);
    }

    /** Returns matching option rows as objects carrying option_name / serialized option_value. */
    public function get_results(string $query): array
    {
        $this->queryLog[] = $query;
        $rows = TestWpFunctions::optionRows();

        return array_map(
            fn (string $name): object => (object) [
                'option_name' => $name,
                'option_value' => $this->serializeValue($rows[$name]['value']),
            ],
            $this->matchingOptionNames($query)
        );
    }

    /** Mirrors wpdb::esc_like(). */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** Minimal wpdb::prepare(): substitutes %s (quoted, escaped) and %d placeholders in order. */
    public function prepare(string $query, mixed ...$args): string
    {
        return preg_replace_callback('/%[sd]/', static function (array $m) use (&$args): string {
            $arg = array_shift($args);
            return $m[0] === '%d' ? (string) (int) $arg : "'" . addslashes((string) $arg) . "'";
        }, $query);
    }

    /** Serializes a stored value the way it sits in the option_value column. */
    private function serializeValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : serialize($value);
    }

    /** @return list<string> option names matched by the query's LIKE / autoload / LIMIT clauses */
    private function matchingOptionNames(string $query): array
    {
        preg_match_all("/option_name LIKE '((?:[^'\\\\]|\\\\.)*)'/", $query, $likes);
        if ($likes[1] === []) {
            return [];
        }
        $regexes = array_map(fn (string $literal): string => $this->likeToRegex(stripslashes($literal)), $likes[1]);
        $autoload = preg_match("/autoload\\s*=\\s*'([^']*)'/", $query, $m) === 1 ? $m[1] : null;
        $limit = preg_match('/LIMIT (\\d+)/', $query, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;

        $names = [];
        foreach (TestWpFunctions::optionRows() as $name => $row) {
            if ($autoload !== null && $row['autoload'] !== $autoload) {
                continue;
            }
            foreach ($regexes as $regex) {
                if (preg_match($regex, $name) === 1) {
                    $names[] = $name;
                    break;
                }
            }
        }
        return array_slice($names, 0, $limit);
    }

    /** Converts a SQL LIKE pattern (backslash escape) to an anchored PCRE. */
    private function likeToRegex(string $pattern): string
    {
        $regex = '';
        for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $n) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }
        return '/^' . $regex . '$/s';
    }
}

// Global $wpdb mock
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new TestWpDb();
    $GLOBALS['wpdb']->options = 'wp_options';
}

// Mirror production bootstrap order: paymentform.php calls Config::bootstrap()
// before Plugin::load() wires the InstallerProvider. Anchor Config at the real
// plugin entrypoint so pluginFile()/pluginDir()/pluginBasename() resolve in any
// test that boots the plugin. Individual tests may re-bootstrap if they need a
// different anchor.
\PaymentForm\Config::bootstrap(dirname(__DIR__) . '/paymentform.php');
