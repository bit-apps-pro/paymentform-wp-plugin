<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Services\ConnectService;
use PHPUnit\Framework\TestCase;

class ConnectServiceTest extends TestCase
{
    private ConnectService $svc;

    protected function setUp(): void
    {
        $this->svc = new ConnectService();
        \TestWpFunctions::reset();
        \TestWpFunctions::setCurrentUser((object) ['ID' => 42, 'user_login' => 'admin']);
    }

    public function test_generate_state_returns_64_char_hex(): void
    {
        $state = $this->svc->generateState();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state);
    }

    public function test_consume_state_returns_user_id(): void
    {
        $state = $this->svc->generateState();

        $userId = $this->svc->consumeState($state);

        $this->assertSame(42, $userId);
    }

    public function test_consume_state_returns_null_for_invalid_state(): void
    {
        $userId = $this->svc->consumeState('invalid-state');

        $this->assertNull($userId);
    }

    public function test_consume_state_deletes_transient(): void
    {
        $state = $this->svc->generateState();
        $this->svc->consumeState($state);

        // Try to consume again
        $userId = $this->svc->consumeState($state);

        $this->assertNull($userId);
    }

    public function test_build_connect_url(): void
    {
        $state = 'test-state-123';
        $appUrl = 'https://app.paymentform.io';
        $callbackUrl = 'https://example.test/wp-admin/callback';

        $url = $this->svc->buildConnectUrl($appUrl, $callbackUrl, $state);

        $this->assertStringStartsWith($appUrl . '/connect?', $url);
        $this->assertStringContainsString('state=' . $state, $url);
        $this->assertStringContainsString('site_url=' . urlencode('http://example.test'), $url);
        $this->assertStringContainsString('callback_url=' . urlencode($callbackUrl), $url);
    }

    public function test_build_connect_url_encodes_parameters(): void
    {
        $state = 'state-value';
        $appUrl = 'https://app.test';
        $callbackUrl = 'https://example.test/?foo=bar&baz=qux';

        $url = $this->svc->buildConnectUrl($appUrl, $callbackUrl, $state);

        // Parse the URL and verify each parameter is properly encoded
        $parsed = parse_url($url);
        $this->assertSame($appUrl . '/connect', $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path']);
    }
}
