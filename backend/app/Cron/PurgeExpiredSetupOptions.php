<?php

declare(strict_types=1);

namespace PaymentForm\Cron;

/**
 * Daily cleanup of expired setup-flow option rows to prevent bloat.
 * Matches option names `paymentform_setup_*` with autoload=no and deletes
 * rows where expires_at < time() - 7 days.
 *
 * Scheduled by PluginServiceProvider::boot() and invoked by `wp_cron`.
 */
final class PurgeExpiredSetupOptions
{
    private const BATCH_LIMIT = 500;
    private const RETENTION_DAYS = 7;

    public function run(): void
    {
        global $wpdb;

        $cutoff = time() - (self::RETENTION_DAYS * 86400);
        $prefix = $wpdb->esc_like('paymentform_setup_');

        // Find option rows with the setup prefix, autoload=no, and embedded
        // expires_at timestamp older than 7 days.
        $options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_id FROM $wpdb->options
                 WHERE option_name LIKE %s
                   AND autoload = %s
                 LIMIT %d",
                $prefix . '%',
                'no',
                self::BATCH_LIMIT
            )
        );

        if (empty($options)) {
            return;
        }

        foreach ($options as $optionId) {
            $name = $wpdb->get_var(
                $wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_id = %d", $optionId)
            );

            if (!is_string($name)) {
                continue;
            }

            $row = get_option($name);
            if (!is_array($row) || !isset($row['expires_at'])) {
                continue;
            }

            if ((int) $row['expires_at'] < $cutoff) {
                delete_option($name);
            }
        }
    }
}
