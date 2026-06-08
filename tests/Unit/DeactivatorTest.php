<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Activation\Deactivator;
use PHPUnit\Framework\TestCase;

class DeactivatorTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_deactivate_does_not_error(): void
    {
        // MVP deactivator is a no-op
        Deactivator::deactivate();
        $this->assertTrue(true);
    }
}
