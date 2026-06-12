<?php

declare(strict_types=1);

namespace PaymentForm\Tests\Unit\Providers;

use PaymentForm\Config;
use PaymentForm\Providers\InstallerProvider;
use PHPUnit\Framework\TestCase;

class InstallerProviderTest extends TestCase
{
    protected function setUp(): void
    {
        \TestWpFunctions::reset();
        // The provider reads Config::pluginFile()/pluginBasename() in its ctor.
        Config::bootstrap(dirname(__DIR__, 3) . '/paymentform.php');
    }

    public function test_constructor_registers_lifecycle_hooks(): void
    {
        new InstallerProvider();

        $activation   = \TestWpFunctions::getActions('__activation_hook__');
        $deactivation = \TestWpFunctions::getActions('__deactivation_hook__');
        $uninstall    = \TestWpFunctions::getActions('__uninstall_hook__');

        $this->assertCount(1, $activation);
        $this->assertSame('registerActivator', $activation[0][1]);

        $this->assertCount(1, $deactivation);
        $this->assertSame('registerDeactivator', $deactivation[0][1]);

        // WordPress only accepts a static callback for the uninstall hook.
        $this->assertCount(1, $uninstall);
        $this->assertSame([InstallerProvider::class, 'registerUninstaller'], $uninstall[0]);
    }

    public function test_constructor_listens_on_deactivate_action(): void
    {
        new InstallerProvider();

        $listeners = \TestWpFunctions::getActions(Config::withPrefix('deactivate'));

        $this->assertCount(1, $listeners);
        $this->assertSame('deactivate', $listeners[0][1]);
    }

    public function test_register_wires_installer_activate_and_uninstall_actions(): void
    {
        (new InstallerProvider())->register();

        // wp-kit Installer::register() attaches its own listeners to the
        // activate + uninstall hooks (requirement gate + migration/drop).
        $this->assertNotEmpty(\TestWpFunctions::getActions(Config::withPrefix('activate')));
        $this->assertNotEmpty(\TestWpFunctions::getActions(Config::withPrefix('uninstall')));
    }

    public function test_register_activator_runs_activation_and_stamps_version(): void
    {
        $role = get_role('administrator');
        $this->assertFalse($role->has_cap('paymentform_manage'));

        (new InstallerProvider())->registerActivator();

        // Domain work delegated to Activator, then the running version is stamped
        // so future upgrades can trigger version-gated migration.
        $this->assertTrue($role->has_cap('paymentform_manage'));
        $this->assertSame(Config::VERSION, Config::getOption('version'));
    }

    public function test_register_uninstaller_runs_uninstall(): void
    {
        $role = get_role('administrator');
        $role->add_cap('paymentform_manage');
        $this->assertTrue($role->has_cap('paymentform_manage'));

        InstallerProvider::registerUninstaller();

        $this->assertFalse($role->has_cap('paymentform_manage'));
    }

    public function test_migration_and_drop_are_empty_until_tables_exist(): void
    {
        $provider = new InstallerProvider();

        foreach ([$provider->migration(), $provider->drop()] as $spec) {
            $this->assertArrayHasKey('path', $spec);
            $this->assertArrayHasKey('migrations', $spec);
            $this->assertSame([], $spec['migrations']);
        }
    }
}
