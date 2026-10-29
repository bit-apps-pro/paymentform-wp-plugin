<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

if (!defined('ABSPATH')) {
    exit;
}

final class Activator
{
    /** Grant the manage capability; StaticRouter flushes the rewrite rules on the activate hook. */
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
    }
}
