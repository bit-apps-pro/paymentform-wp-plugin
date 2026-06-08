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

    public static function reset(): void
    {
        self::$options = [];
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
