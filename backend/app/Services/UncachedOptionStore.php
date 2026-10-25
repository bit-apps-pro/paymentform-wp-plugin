<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use InvalidArgumentException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct `$wpdb` access to wp_options rows that must bypass the options API.
 *
 * get_option()/delete_option() append misses to the shared `notoptions` cache
 * array that WP reads on every non-autoloaded option miss site-wide, so rows
 * keyed by attacker-supplied or one-shot names (e.g. connect state) must never
 * go through them. Names are full option names (already {@see \PaymentForm\Config::withPrefix()}ed).
 */
final class UncachedOptionStore
{
    /** WP 6.6+ value for a non-autoloaded row. */
    private const AUTOLOAD_OFF = 'off';

    /** Insert a new non-autoloaded row; false when it already exists or the write fails. */
    public function insert(string $name, mixed $value): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- bypasses the options API by design (see class docblock).
        $inserted = $wpdb->insert(
            $wpdb->options,
            ['option_name' => $name, 'option_value' => maybe_serialize($value), 'autoload' => self::AUTOLOAD_OFF],
            ['%s', '%s', '%s']
        );

        return $inserted === 1;
    }

    /** Read a row's unserialized value, or null when no such row exists. */
    public function get(string $name): mixed
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot rows must not touch the options cache (see class docblock).
        $raw = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name)
        );

        return $raw === null ? null : maybe_unserialize($raw);
    }

    /** Delete a row and evict any per-option cache entry; returns the affected row count (1 = this call removed it). */
    public function delete(string $name): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- bypasses the options API by design (see class docblock).
        $deleted = (int) $wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']);
        wp_cache_delete($name, 'options');

        return $deleted;
    }

    /**
     * Oldest-first rows whose name starts with any of the given prefixes, as name => unserialized value.
     *
     * @param  list<string>  $prefixes
     * @return array<string, mixed>
     */
    public function findByPrefixes(array $prefixes, int $limit): array
    {
        global $wpdb;

        $patterns = $this->likePatterns($prefixes);
        $where = self::likeClauses(count($patterns));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is only `option_name LIKE %s` placeholders, bound by prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_id ASC LIMIT %d",
                ...[...$patterns, $limit]
            )
        );
        // phpcs:enable

        $values = [];
        foreach ($rows as $row) {
            $values[$row->option_name] = maybe_unserialize($row->option_value);
        }

        return $values;
    }

    /**
     * Bulk-delete every row whose name starts with any of the given prefixes; returns the affected row count.
     *
     * @param  list<string>  $prefixes
     */
    public function deleteByPrefixes(array $prefixes): int
    {
        global $wpdb;

        $patterns = $this->likePatterns($prefixes);
        $where = self::likeClauses(count($patterns));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is only `option_name LIKE %s` placeholders, bound by prepare().
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE {$where}", ...$patterns));
        // phpcs:enable

        return (int) $deleted;
    }

    /**
     * LIKE patterns matching names that start with each prefix, wildcards escaped.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    private function likePatterns(array $prefixes): array
    {
        global $wpdb;

        if ($prefixes === []) {
            throw new InvalidArgumentException('At least one option-name prefix is required.');
        }

        return array_map(static fn (string $prefix): string => $wpdb->esc_like($prefix) . '%', $prefixes);
    }

    /** `(option_name LIKE %s OR …)` with one placeholder per pattern. */
    private static function likeClauses(int $count): string
    {
        return '(' . implode(' OR ', array_fill(0, $count, 'option_name LIKE %s')) . ')';
    }
}
