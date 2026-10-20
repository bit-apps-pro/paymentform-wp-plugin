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
}
