<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'test-auth-key-' . str_repeat('a', 32));
}
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-' . str_repeat('b', 32));
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

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$currentUser = (object) ['ID' => 1, 'user_login' => 'test_user'];
        self::$roles = ['administrator' => new TestWpRole()];
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
