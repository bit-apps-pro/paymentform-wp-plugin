<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

use PaymentForm\Config;
use PaymentForm\Cron\PurgeExpiredFlowOptions;

if (!defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    /** Unschedule plugin cron events and drop the rewrite rules registered on activation. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(PurgeExpiredFlowOptions::HOOK);
        // Our rules are still registered for this request, so a flush here would keep
        // them; emptying the cache (row kept, autoload untouched) regenerates without them.
        update_option('rewrite_rules', '');
        Config::deleteOption('rewrite_rules_version');
    }
}
