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

        // Clean up any transients related to PaymentForm
        // (In a real implementation, we'd iterate and delete all paymentform_* transients)
        // For MVP, we can ignore this since WordPress will clean up old transients
    }
}
