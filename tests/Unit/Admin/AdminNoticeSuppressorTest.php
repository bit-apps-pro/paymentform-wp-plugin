<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Admin;

use PaymentForm\Admin\AdminNoticeSuppressor;
use PHPUnit\Framework\TestCase;
use TestWpFunctions;

final class AdminNoticeSuppressorTest extends TestCase
{
    protected function setUp(): void
    {
        TestWpFunctions::reset();
    }

    public function test_register_hooks_suppress_on_in_admin_header(): void
    {
        $suppressor = new AdminNoticeSuppressor;
        $suppressor->register();

        self::assertSame([[$suppressor, 'suppress']], TestWpFunctions::getActions('in_admin_header'));
    }

    public function test_suppress_removes_all_notice_hooks_on_plugin_screen(): void
    {
        TestWpFunctions::setCurrentScreenId(AdminNoticeSuppressor::SCREEN_ID);

        (new AdminNoticeSuppressor)->suppress();

        self::assertSame(AdminNoticeSuppressor::NOTICE_HOOKS, TestWpFunctions::getRemovedAllActions());
    }

    public function test_suppress_is_noop_on_other_screens(): void
    {
        TestWpFunctions::setCurrentScreenId('dashboard');

        (new AdminNoticeSuppressor)->suppress();

        self::assertSame([], TestWpFunctions::getRemovedAllActions());
    }

    public function test_suppress_is_noop_without_screen(): void
    {
        TestWpFunctions::setCurrentScreenId(null);

        (new AdminNoticeSuppressor)->suppress();

        self::assertSame([], TestWpFunctions::getRemovedAllActions());
    }
}
