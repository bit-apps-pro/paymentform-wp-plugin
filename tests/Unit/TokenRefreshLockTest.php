<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Services\TokenRefreshLock;
use PHPUnit\Framework\TestCase;

class TokenRefreshLockTest extends TestCase
{
    private TokenRefreshLock $lock;

    protected function setUp(): void
    {
        $this->lock = new TokenRefreshLock();
        \TestWpFunctions::reset();
    }

    public function test_acquire_succeeds_first_time(): void
    {
        $this->assertTrue($this->lock->acquire());
    }

    public function test_acquire_fails_when_already_held(): void
    {
        $this->assertTrue($this->lock->acquire());
        $this->assertFalse($this->lock->acquire());
    }

    public function test_release_allows_next_acquire(): void
    {
        $this->assertTrue($this->lock->acquire());
        $this->assertFalse($this->lock->acquire());

        $this->lock->release();

        $this->assertTrue($this->lock->acquire());
    }
}
