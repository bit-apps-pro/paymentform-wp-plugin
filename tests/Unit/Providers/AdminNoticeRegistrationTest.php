<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Providers;

use PaymentForm\Admin\AdminNoticeSuppressor;
use PaymentForm\Providers\PluginServiceProvider;
use PHPUnit\Framework\TestCase;

final class AdminNoticeRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
    }

    public function test_boot_registers_admin_notice_suppressor(): void
    {
        (new PluginServiceProvider)->boot();

        $owners = array_map(
            static fn (callable $cb): ?string => is_array($cb) ? $cb[0]::class : null,
            \TestWpFunctions::getActions('in_admin_header')
        );

        self::assertContains(AdminNoticeSuppressor::class, $owners);
    }
}
