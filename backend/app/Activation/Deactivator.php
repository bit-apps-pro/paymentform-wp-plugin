<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

use PaymentForm\Cron\PurgeExpiredFlowOptions;

if (!defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    /** Unschedule plugin cron events; StaticRouter drops the rewrite rules itself. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(PurgeExpiredFlowOptions::HOOK);
    }
}
