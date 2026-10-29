<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Config;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\ConnectStateException;
use PaymentForm\Tests\Support\IssuesConnectState;
use PHPUnit\Framework\TestCase;

class ConnectServiceTest extends TestCase
{
    use IssuesConnectState;

    private const STATE_OPTION_PREFIX = 'paymentform_connect_state_';
    private const STATE_TTL_SECONDS = 1800;

    private ConnectService $svc;

    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        \TestWpFunctions::setCurrentUser((object) ['ID' => 42, 'user_login' => 'admin']);
        $this->svc = new ConnectService();
    }

    public function test_create_connect_url_targets_app_connect_with_site_callback_and_state(): void
    {
        update_option('permalink_structure', '/%postname%/');
        $url = $this->svc->createConnectUrl();
        $params = $this->connectUrlParams($url);

        $this->assertStringStartsWith(Config::appBaseUrl() . '/connect?', $url);
        $this->assertSame('http://example.test', $params['site_url']);
        $this->assertSame('http://example.test/paymentform/callback', $params['callback_url']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $params['state']);
    }

    public function test_create_connect_url_issues_unique_states(): void
    {
        $this->assertNotSame($this->issueConnectState($this->svc), $this->issueConnectState($this->svc));
    }

    public function test_state_is_stored_as_non_autoloaded_option_with_30_minute_ttl(): void
    {
        $before = time();
        $state = $this->issueConnectState($this->svc);

        $row = \TestWpFunctions::optionRows()[self::STATE_OPTION_PREFIX . $state] ?? null;

        $this->assertNotNull($row, 'State must be persisted as an option, not a transient.');
        $this->assertSame('off', $row['autoload']);
        $this->assertSame(42, $row['value']['user_id']);
        $this->assertGreaterThanOrEqual($before + self::STATE_TTL_SECONDS, $row['value']['expires_at']);
        $this->assertLessThanOrEqual(time() + self::STATE_TTL_SECONDS, $row['value']['expires_at']);
    }

    public function test_consume_state_returns_issuing_user_id_and_deletes_row(): void
    {
        $state = $this->issueConnectState($this->svc);

        $this->assertSame(42, $this->svc->consumeState($state));
        $this->assertArrayNotHasKey(self::STATE_OPTION_PREFIX . $state, \TestWpFunctions::optionRows());
    }

    public function test_consume_state_returns_null_for_unknown_state(): void
    {
        $this->assertNull($this->svc->consumeState(bin2hex(random_bytes(32))));
    }

    public function test_unknown_state_never_touches_options_api_or_cache(): void
    {
        $this->assertNull($this->svc->consumeState(bin2hex(random_bytes(32))));

        $this->assertSame([], \TestWpFunctions::getOptionApiCalls());
        $this->assertSame([], \TestWpFunctions::getCacheDeletes());
    }

    public function test_issue_and_redeem_bypass_options_api(): void
    {
        $state = $this->issueConnectState($this->svc);
        $this->svc->consumeState($state);

        $stateCalls = array_filter(
            \TestWpFunctions::getOptionApiCalls(),
            static fn (array $call): bool => str_starts_with($call[1], self::STATE_OPTION_PREFIX)
        );

        $this->assertSame([], $stateCalls);
    }

    public function test_create_connect_url_throws_when_state_cannot_be_persisted(): void
    {
        $GLOBALS['wpdb']->insertFails = true;

        $this->expectException(ConnectStateException::class);

        $this->svc->createConnectUrl();
    }

    public function test_consume_state_is_single_use(): void
    {
        $state = $this->issueConnectState($this->svc);

        $this->assertSame(42, $this->svc->consumeState($state));
        $this->assertNull($this->svc->consumeState($state));
    }

    public function test_consume_state_rejects_expired_state_and_deletes_row(): void
    {
        $state = $this->issueConnectState($this->svc);
        update_option(self::STATE_OPTION_PREFIX . $state, ['user_id' => 42, 'expires_at' => time() - 1], false);

        $this->assertNull($this->svc->consumeState($state));
        $this->assertArrayNotHasKey(self::STATE_OPTION_PREFIX . $state, \TestWpFunctions::optionRows());
    }

    public function test_consume_state_rejects_malformed_row(): void
    {
        $state = $this->issueConnectState($this->svc);
        update_option(self::STATE_OPTION_PREFIX . $state, 'garbage', false);

        $this->assertNull($this->svc->consumeState($state));
    }

    public function test_consume_state_accepts_when_current_user_differs(): void
    {
        \TestWpFunctions::setCurrentUser((object) ['ID' => 5, 'user_login' => 'user5']);
        $state = $this->issueConnectState($this->svc);

        // The callback runs as user 0 / another user; the single-use state is the credential.
        \TestWpFunctions::setCurrentUser((object) ['ID' => 7, 'user_login' => 'user7']);

        $this->assertSame(5, $this->svc->consumeState($state));
    }

    public function test_consume_state_rejects_state_issued_to_anonymous_user(): void
    {
        \TestWpFunctions::setCurrentUser((object) ['ID' => 0, 'user_login' => '']);
        $state = $this->issueConnectState($this->svc);

        \TestWpFunctions::setCurrentUser((object) ['ID' => 1, 'user_login' => 'user1']);

        $this->assertNull($this->svc->consumeState($state));
    }
}
