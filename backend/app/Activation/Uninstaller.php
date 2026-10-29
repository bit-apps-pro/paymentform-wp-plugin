<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

use PaymentForm\Config;
use PaymentForm\Cron\PurgeExpiredFlowOptions;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\UncachedOptionStore;

if (!defined('ABSPATH')) {
    exit;
}

final class Uninstaller
{
    /** Bare prefixes of every short-lived flow row; `setup_` is broad so legacy setup rows go too. */
    private const FLOW_OPTION_PREFIXES = ['setup_', ConnectService::STATE_OPTION_PREFIX];

    /** Remove every capability, option, transient and cron event the plugin created. */
    public static function uninstall(): void
    {
        $role = get_role('administrator');
        if ($role !== null) {
            $role->remove_cap('paymentform_manage');
        }

        Config::deleteOption('credentials');
        Config::deleteOption('version');
        // Legacy marker from the pre-StaticRouter rewrites; still present on upgraded sites.
        Config::deleteOption('rewrite_rules_version');
        wp_clear_scheduled_hook(PurgeExpiredFlowOptions::HOOK);
        (new UncachedOptionStore())->deleteByPrefixes(
            array_map(Config::withPrefix(...), self::FLOW_OPTION_PREFIXES)
        );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall sweep of our own transients; static SQL, no input.
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_paymentform\_%' " .
            "OR option_name LIKE '\_transient\_timeout\_paymentform\_%' " .
            "OR option_name = 'paymentform_refresh_lock'"
        );
    }
}
