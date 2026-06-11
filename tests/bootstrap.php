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
if (!defined('PAYMENTFORM_API_BASE_URL')) {
    define('PAYMENTFORM_API_BASE_URL', 'https://api.paymentform.io');
}
if (!defined('PAYMENTFORM_APP_BASE_URL')) {
    define('PAYMENTFORM_APP_BASE_URL', 'https://app.paymentform.io');
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
    private static array $transients = [];
    private static object $currentUser;
    private static array $roles = [];
    private static ?Closure $nonceValidator = null;
    /** null = legacy default: every capability check passes. */
    private static ?array $grantedCaps = null;
    /** @var array<string, list<callable>> */
    private static array $filters = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$currentUser = (object) ['ID' => 1, 'user_login' => 'test_user'];
        self::$roles = ['administrator' => new TestWpRole()];
        self::$nonceValidator = null;
        self::$grantedCaps = null;
        self::$filters = [];
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
        return true;
    }

    public static function updateOption(string $option, mixed $value, bool $autoload = true): bool
    {
        self::$options[$option] = $value;
        return true;
    }

    public static function deleteOption(string $option): bool
    {
        unset(self::$options[$option]);
        return true;
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
}

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return TestWpFunctions::getOption($option, $default);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool $autoload = true): bool
    {
        return TestWpFunctions::updateOption($option, $value, $autoload);
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
    {
        return TestWpFunctions::addOption($option, $value, $deprecated, $autoload);
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
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
        // No-op in tests
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): void
    {
        // No-op in tests
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $object_name, string $var_name, array $l10n): bool
    {
        // No-op in tests
        return true;
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

// Queue-based stub for wp_remote_request / wp_remote_retrieve_* / is_wp_error.
// Tests push canned responses with WpHttpMock::queue(...) and inspect the
// recorded calls afterwards.
class WpHttpMock
{
    /** @var array<int, array{response: array{code: int}, headers: array<string, string>, body: string}> */
    public static array $queue = [];

    /** @var array<int, array{url: string, args: array<string, mixed>}> */
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
        self::$calls[] = ['url' => $url, 'args' => $args];

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

// Mock WordPress database
class TestWpDb
{
    public array $queryLog = [];

    public function query(string $query): int|false
    {
        $this->queryLog[] = $query;
        // Simulate successful DELETE
        if (stripos($query, 'DELETE') === 0) {
            return 1;
        }
        return false;
    }
}

// Global $wpdb mock
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new TestWpDb();
    $GLOBALS['wpdb']->options = 'wp_options';
}
