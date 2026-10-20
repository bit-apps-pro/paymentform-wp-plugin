<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Services;

use InvalidArgumentException;
use PaymentForm\Services\UncachedOptionStore;
use PHPUnit\Framework\TestCase;

class UncachedOptionStoreTest extends TestCase
{
    private UncachedOptionStore $store;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        $this->store = new UncachedOptionStore();
    }

    public function test_insert_stores_non_autoloaded_row_readable_via_get(): void
    {
        $this->assertTrue($this->store->insert('pf_row', ['a' => 1]));

        $this->assertSame(['a' => 1], $this->store->get('pf_row'));
        $this->assertSame('off', \TestWpFunctions::optionRows()['pf_row']['autoload']);
    }

    public function test_insert_refuses_existing_name(): void
    {
        $this->store->insert('pf_row', 'first');

        $this->assertFalse($this->store->insert('pf_row', 'second'));
        $this->assertSame('first', $this->store->get('pf_row'));
    }

    public function test_get_returns_null_for_missing_row(): void
    {
        $this->assertNull($this->store->get('pf_missing'));
    }

    public function test_delete_reports_affected_rows_and_evicts_option_cache(): void
    {
        $this->store->insert('pf_row', 'value');

        $this->assertSame(1, $this->store->delete('pf_row'));
        $this->assertSame(0, $this->store->delete('pf_row'));
        $this->assertSame([['options', 'pf_row'], ['options', 'pf_row']], \TestWpFunctions::getCacheDeletes());
    }

    public function test_operations_never_use_options_api(): void
    {
        $this->store->insert('pf_row', 'value');
        $this->store->get('pf_row');
        $this->store->findByPrefixes(['pf_'], 10);
        $this->store->delete('pf_row');

        $this->assertSame([], \TestWpFunctions::getOptionApiCalls());
    }

    public function test_find_by_prefixes_treats_like_wildcards_literally(): void
    {
        $this->store->insert('pf_a_1', '1');
        $this->store->insert('pfXaX2', '2');
        $this->store->insert('pf_b_3', '3');

        $this->assertSame(['pf_a_1' => '1'], $this->store->findByPrefixes(['pf_a_'], 10));
    }

    public function test_find_by_prefixes_honours_limit_oldest_first(): void
    {
        $this->store->insert('pf_1', '1');
        $this->store->insert('pf_2', '2');

        $this->assertSame(['pf_1' => '1'], $this->store->findByPrefixes(['pf_'], 1));
    }

    public function test_delete_by_prefixes_removes_only_matching_rows(): void
    {
        $this->store->insert('pf_a_1', '1');
        $this->store->insert('other', 2);

        $this->assertSame(1, $this->store->deleteByPrefixes(['pf_a_']));
        $this->assertSame(['other'], array_keys(\TestWpFunctions::optionRows()));
    }

    public function test_prefix_queries_reject_empty_prefix_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->findByPrefixes([], 10);
    }
}
