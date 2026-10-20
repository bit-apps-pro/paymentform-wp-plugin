<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use PaymentForm\Config;

/**
 * Stores the per-nonce setup row that the backend's synchronous proof
 * handshake (/paymentform/setup-proof) looks up while /auth/setup or
 * /auth/setup/diagnose is in flight.
 *
 * Rows are non-autoloaded options with an embedded expires_at. Callers delete
 * the row once their backend call returns; reads auto-purge expired rows and
 * the daily {@see \PaymentForm\Cron\PurgeExpiredFlowOptions} sweep removes any
 * left behind by aborted requests.
 *
 * The prefix is bare (no `paymentform_`); {@see Config} prepends VAR_PREFIX so
 * the stored option names are `paymentform_setup_by_nonce_*`.
 */
final class SetupOptionsStore
{
    public const PER_NONCE_PREFIX = 'setup_by_nonce_';

    /**
     * Persist the setup row under its nonce.
     *
     * @param  array<string, mixed>  $row
     */
    public function put(string $nonce, array $row): void
    {
        Config::updateOption(self::PER_NONCE_PREFIX . $nonce, $row);
    }

    /**
     * Retrieve the row for a nonce, purging and rejecting it when expired.
     *
     * @return array<string, mixed>|null
     */
    public function getByNonce(string $nonce): ?array
    {
        $row = Config::getOption(self::PER_NONCE_PREFIX . $nonce, false);
        if ($row === false || !is_array($row)) {
            return null;
        }

        if (!isset($row['expires_at']) || (int) $row['expires_at'] < time()) {
            Config::deleteOption(self::PER_NONCE_PREFIX . $nonce);
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
        return Config::deleteOption(self::PER_NONCE_PREFIX . $nonce);
    }
}
