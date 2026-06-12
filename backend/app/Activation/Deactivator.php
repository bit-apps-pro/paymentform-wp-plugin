<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

final class Deactivator
{
    public static function deactivate(): void
    {
        // No scheduled events to clean up for MVP
        // Future: clean up any wp_schedule_event hooks if added

        // Drop the REST/rewrite rules registered on activation so deactivating
        // the plugin leaves no stale routes behind (mirrors Activator::activate).
        flush_rewrite_rules();
    }
}
