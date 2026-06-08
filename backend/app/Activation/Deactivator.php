<?php

declare(strict_types=1);

namespace PaymentForm\Activation;

final class Deactivator
{
    public static function deactivate(): void
    {
        // No scheduled events to clean up for MVP
        // Future: clean up any wp_schedule_event hooks if added
    }
}
