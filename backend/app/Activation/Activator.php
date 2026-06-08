<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

final class Activator
{
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

        // Flush rewrite rules to register REST endpoints
        flush_rewrite_rules();
    }
}
