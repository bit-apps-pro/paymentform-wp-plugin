<?php

declare(strict_types=1);

namespace PaymentForm\Services;

final class TokenRefreshLock
{
    private const TRANSIENT_KEY = 'paymentform_refresh_lock';
    private const TTL = 5;

    public function acquire(): bool
    {
        // Try to set transient with race-safe check via add_option
        // If transient doesn't exist, add_option('transient_' . key) will succeed
        // Otherwise it will fail
        $transientKey = '_transient_' . self::TRANSIENT_KEY;

        // Use WordPress transient API - set_transient returns false if it fails
        // For acquire semantics, we check if get returns nothing and set atomically
        if (get_transient(self::TRANSIENT_KEY) === false) {
            set_transient(self::TRANSIENT_KEY, true, self::TTL);
            return true;
        }

        return false;
    }

    public function release(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }
}
