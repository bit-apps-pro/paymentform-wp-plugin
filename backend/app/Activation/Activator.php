<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

use PaymentForm\Config;

if (!defined('ABSPATH')) {
    exit;
}

final class Activator
{
    /** Grant the manage capability and schedule a rewrite flush for the next request. */
    public static function activate(): void
    {
        if (is_multisite()) {
            // MVP: single-site only. Network activation not supported.
            return;
        }

        // Register paymentform_manage capability to administrator role
        $role = get_role('administrator');
        if ($role !== null) {
            $role->add_cap('paymentform_manage');
        }

        // Activation runs after plugins_loaded, so the rules aren't registered yet;
        // clearing the marker makes Rewrites::maybeFlush() flush on the next init.
        Config::deleteOption('rewrite_rules_version');
    }
}
