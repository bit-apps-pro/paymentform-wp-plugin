<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'test-auth-key-' . str_repeat('a', 32));
}
if (!defined('AUTH_SALT')) {
    define('AUTH_SALT', 'test-auth-salt-' . str_repeat('b', 32));
}

// Global test double for WordPress functions
class TestWpFunctions
{
    private static array $options = [];
    private static array $transients = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
    }

    public static function getOption(string $option, mixed $default = false): mixed
    {
        return self::$options[$option] ?? $default;
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
