<?php

declare(strict_types=1);

namespace PaymentForm\Services;

/**
 * Helper for storing and retrieving setup-flow transient state (nonce rows,
 * per-email pending state) via WP options with embedded TTL enforcement.
 *
 * IMPORTANT: autoload=no is required to prevent option-table bloat when
 * thousands of stale setup rows accumulate. The daily cron cleanup
 * (PurgeExpiredSetupOptions) removes rows older than 7 days; individual
 * read operations also auto-purge on-the-fly if expires_at < time().
 */
final class SetupOptionsStore
{
    private const PER_NONCE_PREFIX = 'paymentform_setup_by_nonce_';
    private const PER_EMAIL_PREFIX = 'paymentform_setup_pending_';

    /**
     * Persist both a per-nonce row (consumed at handshake) and a per-email row
     * (used by OTP-resend flow to find the setup context later).
     *
     * @param  array<string, mixed>  $row
     */
    public function put(string $nonce, array $row, string $emailMd5): void
    {
        update_option(self::PER_NONCE_PREFIX . $nonce, $row, false);
        update_option(self::PER_EMAIL_PREFIX . $emailMd5, $row, false);
    }

    /**
     * Retrieve by nonce with inline expiry check.
     *
     * @return array<string, mixed>|null
     */
    public function getByNonce(string $nonce): ?array
    {
        $row = get_option(self::PER_NONCE_PREFIX . $nonce, false);
        if ($row === false || !is_array($row)) {
            return null;
        }

        // Auto-purge expired rows on read.
        if (!isset($row['expires_at']) || (int) $row['expires_at'] < time()) {
            delete_option(self::PER_NONCE_PREFIX . $nonce);
            return null;
        }

        return $row;
    }

    /**
     * Retrieve by email MD5 hash with inline expiry check.
     *
     * @return array<string, mixed>|null
     */
    public function getByEmail(string $emailMd5): ?array
    {
        $row = get_option(self::PER_EMAIL_PREFIX . $emailMd5, false);
        if ($row === false || !is_array($row)) {
            return null;
        }

        // Auto-purge expired rows on read.
        if (!isset($row['expires_at']) || (int) $row['expires_at'] < time()) {
            delete_option(self::PER_EMAIL_PREFIX . $emailMd5);
            return null;
        }

        return $row;
    }

    /**
     * Atomically consume a nonce row. Returns true if the row existed and was
     * deleted; false if it was already gone (concurrent consumption or replay).
     */
    public function consumeByNonce(string $nonce): bool
    {
        return delete_option(self::PER_NONCE_PREFIX . $nonce) !== false;
    }
}
