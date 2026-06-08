<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Activation\Activator;
use PHPUnit\Framework\TestCase;

class ActivatorTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_activate_adds_capability_to_admin_role(): void
    {
        $role = get_role('administrator');
        $this->assertFalse($role->has_cap('paymentform_manage'));

        Activator::activate();

        $this->assertTrue($role->has_cap('paymentform_manage'));
    }
}
