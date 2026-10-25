<?php

declare(strict_types=1);

namespace PaymentForm\Cron;

use PaymentForm\Config;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\SetupOptionsStore;
use PaymentForm\Services\UncachedOptionStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily sweep of expired setup-nonce / connect-state option rows (each carries
 * an embedded `expires_at`) left behind by aborted flows.
 *
 * Deliberately ignores the `autoload` column: WP 6.6+ stores non-autoloaded
 * options as 'off' (not 'no'), so filtering on it silently matched nothing.
 * Deletes go through {@see UncachedOptionStore} so they never grow `notoptions`.
 *
 * Scheduled by PluginServiceProvider::registerCronSchedules() under {@see HOOK}.
 */
final class PurgeExpiredFlowOptions
{
    public const HOOK = Config::VAR_PREFIX . 'purge_flow_options';

    /** Bare option-name prefixes (pre-{@see Config::withPrefix()}) of the rows this sweep owns. */
    private const OPTION_PREFIXES = [SetupOptionsStore::PER_NONCE_PREFIX, ConnectService::STATE_OPTION_PREFIX];

    private const BATCH_LIMIT = 500;

    /** Defaults to a fresh store so the hook can bind a zero-arg instance. */
    public function __construct(
        private readonly UncachedOptionStore $options = new UncachedOptionStore(),
    ) {}

    /** Delete up to one batch of owned rows whose expires_at has passed (or is missing). */
    public function run(): void
    {
        $prefixes = array_map(Config::withPrefix(...), self::OPTION_PREFIXES);
        $now = time();

        foreach ($this->options->findByPrefixes($prefixes, self::BATCH_LIMIT) as $name => $value) {
            if ($this->expiresAt($value) < $now) {
                $this->options->delete($name);
            }
        }
    }

    /** Embedded expiry timestamp of a stored row, 0 when the row is malformed. */
    private function expiresAt(mixed $value): int
    {
        return is_array($value) ? (int) ($value['expires_at'] ?? 0) : 0;
    }
}
