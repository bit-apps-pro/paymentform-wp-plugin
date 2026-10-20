<?php

declare(strict_types=1);

namespace PaymentForm\Services;

use InvalidArgumentException;

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

        $raw = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name)
        );

        return $raw === null ? null : maybe_unserialize($raw);
    }

    /** Delete a row and evict any per-option cache entry; returns the affected row count (1 = this call removed it). */
    public function delete(string $name): int
    {
        global $wpdb;

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

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . $this->prefixFilter($prefixes)
            . $wpdb->prepare(' ORDER BY option_id ASC LIMIT %d', $limit)
        );

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

        return (int) $wpdb->query("DELETE FROM {$wpdb->options} WHERE " . $this->prefixFilter($prefixes));
    }

    /**
     * Prepared `(option_name LIKE … OR …)` predicate with LIKE wildcards in the prefixes escaped.
     *
     * @param  list<string>  $prefixes
     */
    private function prefixFilter(array $prefixes): string
    {
        global $wpdb;

        if ($prefixes === []) {
            throw new InvalidArgumentException('At least one option-name prefix is required.');
        }

        $patterns = array_map(static fn (string $prefix): string => $wpdb->esc_like($prefix) . '%', $prefixes);
        $clauses = array_fill(0, count($patterns), 'option_name LIKE %s');

        return $wpdb->prepare('(' . implode(' OR ', $clauses) . ')', ...$patterns);
    }
}
