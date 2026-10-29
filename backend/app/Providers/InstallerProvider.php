<?php

declare(strict_types=1);

namespace PaymentForm\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use PaymentForm\Activation\Activator;
use PaymentForm\Activation\Deactivator;
use PaymentForm\Activation\Uninstaller;
use PaymentForm\Config;
use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;
use PaymentForm\Deps\BitApps\WPKit\Installer;

/**
 * Wires the plugin lifecycle (activation / deactivation / uninstall) through
 * wp-kit's {@see Installer}, mirroring the BitApps plugin family.
 *
 * The Installer adds two cross-cutting concerns: PHP/WP requirement gating and
 * version-tracked DB migration. The plugin's own domain work — the
 * `paymentform_manage` capability, cron and option/transient
 * cleanup — stays in {@see Activator}/{@see Deactivator}/{@see Uninstaller},
 * which this provider invokes from the lifecycle hooks. The activation flow is:
 *
 *   register_activation_hook → registerActivator() → Activator::activate()
 *   then Hooks::doAction(activate) → Installer::activate() (requirement gate +
 *   migration) and StaticRouter::flushOnActivate().
 *
 * The plugin ships no custom DB tables yet, so migration/drop are wired empty;
 * MigrationHelper iterates an empty set, leaving a seam for future migrations
 * without dead scaffolding. Activation is single-site only (multisite: false),
 * matching {@see Activator}'s existing guard.
 */
final class InstallerProvider
{
    private string $activateHook;

    private string $deactivateHook;

    private static string $uninstallHook = '';

    public function __construct()
    {
        register_activation_hook(Config::pluginFile(), [$this, 'registerActivator']);
        register_deactivation_hook(Config::pluginFile(), [$this, 'registerDeactivator']);

        $this->activateHook   = Config::withPrefix('activate');
        $this->deactivateHook = Config::withPrefix('deactivate');
        self::$uninstallHook  = Config::withPrefix('uninstall');

        Hooks::addAction($this->deactivateHook, [$this, 'deactivate']);

        // Only a static class method or function can be used in an uninstall hook.
        register_uninstall_hook(Config::pluginFile(), [self::class, 'registerUninstaller']);
    }

    public function register(): void
    {
        $installer = new Installer(
            [
                'php'        => Config::REQUIRED_PHP_VERSION,
                'wp'         => Config::REQUIRED_WP_VERSION,
                'version'    => Config::VERSION,
                'oldVersion' => (string) Config::getOption('version', '0.0'),
                'multisite'  => false,
                'basename'   => Config::pluginBasename(),
            ],
            [
                'activate'  => $this->activateHook,
                'uninstall' => self::$uninstallHook,
            ],
            [
                'migration' => $this->migration(),
                'drop'      => $this->drop(),
            ]
        );
        $installer->register();
    }

    public function registerActivator(bool $networkWide = false): void
    {
        Activator::activate();
        Config::updateOption('version', Config::VERSION);
        Hooks::doAction($this->activateHook, $networkWide);
    }

    public function registerDeactivator(bool $networkWide = false): void
    {
        Hooks::doAction($this->deactivateHook, $networkWide);
    }

    public function deactivate(): void
    {
        Deactivator::deactivate();
    }

    public static function registerUninstaller(): void
    {
        Uninstaller::uninstall();
        Hooks::doAction(self::$uninstallHook);
    }

    /**
     * No custom tables yet — an empty set MigrationHelper iterates as a no-op.
     *
     * @return array{path: string, migrations: array<int, string>}
     */
    public function migration(): array
    {
        return ['path' => '', 'migrations' => []];
    }

    /**
     * @return array{path: string, migrations: array<int, string>}
     */
    public function drop(): array
    {
        return ['path' => '', 'migrations' => []];
    }
}
