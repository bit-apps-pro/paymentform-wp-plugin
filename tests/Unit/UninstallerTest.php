<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit;

use PaymentForm\Activation\Uninstaller;
use PaymentForm\Cron\PurgeExpiredFlowOptions;
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

    public function test_uninstall_deletes_setup_and_connect_state_options_only(): void
    {
        update_option('paymentform_setup_by_nonce_abc', ['expires_at' => time() + 600], false);
        update_option('paymentform_setup_pending_legacy', ['expires_at' => time() + 600], false);
        add_option('paymentform_connect_state_def', ['user_id' => 1, 'expires_at' => time() + 600], '', 'no');
        update_option('paymentform_settings', ['keep' => true]);
        update_option('other_plugin_setup_x', 'keep');

        Uninstaller::uninstall();

        $this->assertSame(['paymentform_settings', 'other_plugin_setup_x'], array_keys(\TestWpFunctions::optionRows()));
    }

    public function test_uninstall_deletes_version_markers(): void
    {
        update_option('paymentform_version', '0.1.0');
        update_option('paymentform_rewrite_rules_version', '2');

        Uninstaller::uninstall();

        $this->assertFalse(get_option('paymentform_version'));
        $this->assertFalse(get_option('paymentform_rewrite_rules_version'));
    }

    public function test_uninstall_unschedules_flow_option_purge(): void
    {
        Uninstaller::uninstall();

        $this->assertSame([PurgeExpiredFlowOptions::HOOK], \TestWpFunctions::getClearedScheduledHooks());
    }

    public function test_uninstall_calls_database_cleanup(): void
    {
        // Verify that uninstall calls wpdb->query for cleanup
        // The actual row deletion is performed by WordPress at the DB level
        $wpdb = $GLOBALS['wpdb'];
        $initialQueryCount = count($wpdb->queryLog);

        Uninstaller::uninstall();

        // Verify wpdb->query was called
        $this->assertGreaterThan($initialQueryCount, count($wpdb->queryLog));
        $lastQuery = end($wpdb->queryLog);
        $this->assertStringContainsString('DELETE', $lastQuery);
        $this->assertStringContainsString('paymentform', $lastQuery);
    }
}
