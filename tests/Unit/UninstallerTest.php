<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Activation\Uninstaller;
use PaymentForm\Services\EncryptionService;
use PaymentForm\Services\TokenService;
use PHPUnit\Framework\TestCase;

class UninstallerTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_uninstall_removes_capability(): void
    {
        $role = get_role('administrator');
        $role->add_cap('paymentform_manage');
        $this->assertTrue($role->has_cap('paymentform_manage'));

        Uninstaller::uninstall();

        $this->assertFalse($role->has_cap('paymentform_manage'));
    }

    public function test_uninstall_deletes_credentials(): void
    {
        $encryption = new EncryptionService();
        $tokenService = new TokenService($encryption);

        // Store some credentials
        $tokenService->store('test-token', (new \DateTime('+30 days'))->format(\DateTimeInterface::ATOM));
        $this->assertNotNull($tokenService->get());

        Uninstaller::uninstall();

        $this->assertNull($tokenService->get());
    }
}
