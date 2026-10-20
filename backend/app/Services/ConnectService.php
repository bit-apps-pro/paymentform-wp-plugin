<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use PaymentForm\Config;

/**
 * Issues and redeems the single-use OAuth-style `state` for the Connect flow.
 *
 * State lives in a non-autoloaded wp_options row rather than a transient: on
 * sites with a persistent object cache transients exist only in the cache,
 * where eviction or a non-shared cache node loses them mid-flow. Rows go
 * through {@see UncachedOptionStore} because the public callback redeems
 * caller-supplied states, which must not grow the shared `notoptions` cache.
 * Expired rows are swept by {@see \PaymentForm\Cron\PurgeExpiredFlowOptions}.
 */
final class ConnectService
{
    public const STATE_OPTION_PREFIX = 'connect_state_';

    /** Long enough for the user to sign up or log in on the platform first. */
    private const STATE_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

    private const CALLBACK_PATH = '/paymentform/callback';

    /** Defaults to a fresh store so the zero-arg container binding keeps working. */
    public function __construct(
        private readonly UncachedOptionStore $options = new UncachedOptionStore(),
    ) {}

    /**
     * Issue a fresh state bound to the current admin and return the platform connect URL carrying it.
     *
     * @throws ConnectStateException when the state row cannot be persisted
     */
    public function createConnectUrl(): string
    {
        $params = [
            'site_url' => site_url(),
            'callback_url' => home_url(self::CALLBACK_PATH),
            'state' => $this->generateState(),
        ];

        return Config::appBaseUrl() . '/connect?' . http_build_query($params);
    }

    /** Redeem a state exactly once, returning the WP user id that issued it or null when invalid. */
    public function consumeState(string $state): ?int
    {
        $name = $this->optionName($state);
        $data = $this->options->get($name);
        if ($data === null) {
            return null;
        }

        // A single DB DELETE: only one concurrent replay can observe the affected row.
        if ($this->options->delete($name) !== 1 || ! is_array($data)) {
            return null;
        }

        if ((int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        // The random state is the CSRF credential; we don't bind to wp_get_current_user() because
        // the callback is a top-level navigation without X-WP-Nonce, so WP REST sees user 0.
        $userId = (int) ($data['user_id'] ?? 0);

        return $userId === 0 ? null : $userId;
    }

    /** Persist a new random state row for the current user and return the state. */
    private function generateState(): string
    {
        $state = bin2hex(random_bytes(32));
        $row = [
            'user_id' => get_current_user_id(),
            'expires_at' => time() + self::STATE_TTL_SECONDS,
        ];

        if (! $this->options->insert($this->optionName($state), $row)) {
            throw new ConnectStateException('Failed to persist connect state.');
        }

        return $state;
    }

    /** Full wp_options name of the row holding a state. */
    private function optionName(string $state): string
    {
        return Config::withPrefix(self::STATE_OPTION_PREFIX . $state);
    }
}
