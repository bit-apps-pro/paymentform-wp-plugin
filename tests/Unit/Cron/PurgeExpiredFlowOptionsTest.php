<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Cron;

use PaymentForm\Cron\PurgeExpiredFlowOptions;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\SetupOptionsStore;
use PaymentForm\Tests\Support\IssuesConnectState;
use PHPUnit\Framework\TestCase;

class PurgeExpiredFlowOptionsTest extends TestCase
{
    use IssuesConnectState;

    private const SETUP_ROW = 'paymentform_setup_by_nonce_';
    private const CONNECT_ROW = 'paymentform_connect_state_';

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_purges_expired_setup_rows_stored_with_autoload_off(): void
    {
        (new SetupOptionsStore())->put('expired', ['expires_at' => time() - 1]);
        $this->assertSame('off', \TestWpFunctions::optionRows()[self::SETUP_ROW . 'expired']['autoload']);

        (new PurgeExpiredFlowOptions())->run();

        $this->assertArrayNotHasKey(self::SETUP_ROW . 'expired', \TestWpFunctions::optionRows());
    }

    public function test_purges_expired_connect_state_rows(): void
    {
        $state = $this->issueConnectState(new ConnectService());
        update_option(self::CONNECT_ROW . $state, ['user_id' => 1, 'expires_at' => time() - 1], false);

        (new PurgeExpiredFlowOptions())->run();

        $this->assertArrayNotHasKey(self::CONNECT_ROW . $state, \TestWpFunctions::optionRows());
    }

    public function test_keeps_unexpired_rows(): void
    {
        (new SetupOptionsStore())->put('live', ['expires_at' => time() + 600]);
        $state = $this->issueConnectState(new ConnectService());

        (new PurgeExpiredFlowOptions())->run();

        $rows = \TestWpFunctions::optionRows();
        $this->assertArrayHasKey(self::SETUP_ROW . 'live', $rows);
        $this->assertArrayHasKey(self::CONNECT_ROW . $state, $rows);
    }

    public function test_purges_rows_without_a_valid_expiry(): void
    {
        update_option(self::SETUP_ROW . 'no_expiry', ['site_url' => 'http://example.test'], false);
        update_option(self::CONNECT_ROW . 'scalar', 'garbage', false);

        (new PurgeExpiredFlowOptions())->run();

        $this->assertSame([], \TestWpFunctions::optionRows());
    }

    public function test_leaves_unrelated_options_untouched(): void
    {
        update_option('paymentform_credentials', ['expires_at' => time() - 1], false);
        update_option('paymentform_setup_completed', true, false);
        update_option('paymentform_setup_pending_legacy', ['expires_at' => time() - 1], false);
        update_option('paymentformXsetupXbyXnonceXlookalike', ['expires_at' => time() - 1], false);
        update_option('other_plugin_setup_by_nonce_x', ['expires_at' => time() - 1], false);

        (new PurgeExpiredFlowOptions())->run();

        $this->assertCount(5, \TestWpFunctions::optionRows());
    }

    public function test_deletes_without_options_api_and_evicts_option_cache(): void
    {
        (new SetupOptionsStore())->put('expired', ['expires_at' => time() - 1]);
        $callsBefore = \TestWpFunctions::getOptionApiCalls();

        (new PurgeExpiredFlowOptions())->run();

        $this->assertSame($callsBefore, \TestWpFunctions::getOptionApiCalls());
        $this->assertSame([['options', self::SETUP_ROW . 'expired']], \TestWpFunctions::getCacheDeletes());
        $this->assertSame([], \TestWpFunctions::optionRows());
    }

    public function test_processes_at_most_one_batch_per_run(): void
    {
        $store = new SetupOptionsStore();
        for ($i = 0; $i < 501; $i++) {
            $store->put((string) $i, ['expires_at' => time() - 1]);
        }

        (new PurgeExpiredFlowOptions())->run();

        $this->assertSame([self::SETUP_ROW . '500'], array_keys(\TestWpFunctions::optionRows()));
    }
}
