<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Activation\Deactivator;
use PaymentForm\Cron\PurgeExpiredFlowOptions;
use PHPUnit\Framework\TestCase;

class DeactivatorTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_deactivate_unschedules_flow_option_purge(): void
    {
        Deactivator::deactivate();

        $this->assertSame([PurgeExpiredFlowOptions::HOOK], \TestWpFunctions::getClearedScheduledHooks());
    }

    public function test_deactivate_drops_cached_rewrite_rules_and_marker(): void
    {
        update_option('rewrite_rules', ['^paymentform/(callback|webhook|setup-proof)/?$' => 'index.php']);
        update_option('paymentform_rewrite_rules_version', '2');

        Deactivator::deactivate();

        $this->assertSame('', get_option('rewrite_rules'));
        $this->assertFalse(get_option('paymentform_rewrite_rules_version'));
    }
}
