<?php

declare(strict_types=1);

namespace PaymentForm\Services;

final class TokenRefreshLock
{
    private const LOCK_OPTION = 'paymentform_refresh_lock';
    private const TTL_SECONDS = 5;

    public function acquire(): bool
    {
        // add_option returns false if the option already exists (atomic at DB unique-key level).
        $acquired = \add_option(self::LOCK_OPTION, (string)time(), '', 'no');
        if ($acquired) {
            return true;
        }

        // Stale lock? check timestamp.
        $existing = (int) \get_option(self::LOCK_OPTION, '0');
        if ($existing && (time() - $existing) > self::TTL_SECONDS) {
            \delete_option(self::LOCK_OPTION);
            return \add_option(self::LOCK_OPTION, (string)time(), '', 'no');
        }

        return false;
    }

    public function release(): void
    {
        \delete_option(self::LOCK_OPTION);
    }
}
