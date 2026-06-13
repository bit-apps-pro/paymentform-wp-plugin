<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use PaymentForm\Config;

final class TokenRefreshLock
{
    private const LOCK_OPTION = 'refresh_lock';
    private const TTL_SECONDS = 5;

    private ?string $ownerToken = null;

    public function acquire(): bool
    {
        $token = bin2hex(random_bytes(16));
        $payload = $token . '|' . time();

        if (Config::addOption(self::LOCK_OPTION, $payload)) {
            $this->ownerToken = $token;
            return true;
        }

        // Stale check
        $existing = (string) Config::getOption(self::LOCK_OPTION, '');
        $parts = explode('|', $existing, 2);
        $existingTs = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($existingTs > 0 && (time() - $existingTs) > self::TTL_SECONDS) {
            // Best-effort stale reclaim: try to swap atomically by deleting only the stale value, then re-add.
            // delete_option is not value-conditioned, so this race window remains. Documented limitation.
            Config::deleteOption(self::LOCK_OPTION);
            if (Config::addOption(self::LOCK_OPTION, $payload)) {
                $this->ownerToken = $token;
                return true;
            }
        }
        return false;
    }

    public function release(): void
    {
        if ($this->ownerToken === null) {
            return; // Not the owner — never call delete_option.
        }
        $existing = (string) Config::getOption(self::LOCK_OPTION, '');
        $parts = explode('|', $existing, 2);
        $existingToken = $parts[0] ?? '';
        if (hash_equals($existingToken, $this->ownerToken)) {
            Config::deleteOption(self::LOCK_OPTION);
        }
        $this->ownerToken = null;
    }
}
