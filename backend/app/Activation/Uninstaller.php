<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

final class Uninstaller
{
    public static function uninstall(): void
    {
        // Remove capability from administrator role
        $role = get_role('administrator');
        if ($role !== null) {
            $role->remove_cap('paymentform_manage');
        }

        // Delete credentials option
        delete_option('paymentform_credentials');

        // Wipe all stale transients and related options
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_paymentform\_%' " .
            "OR option_name LIKE '\_transient\_timeout\_paymentform\_%' " .
            "OR option_name = 'paymentform_refresh_lock'"
        );
    }
}
