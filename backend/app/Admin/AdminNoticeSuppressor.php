<?php

declare(strict_types=1);

namespace PaymentForm\Admin;

use PaymentForm\Deps\BitApps\WPKit\Hooks\Hooks;

/**
 * Clears third-party admin notices on the PaymentForm admin screen so they cannot push the SPA out of view.
 */
final class AdminNoticeSuppressor
{
    public const SCREEN_ID = 'toplevel_page_paymentform';

    public const NOTICE_HOOKS = ['admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices'];

    /** Hooks the suppressor in after the screen is known and before notices print. */
    public function register(): void
    {
        Hooks::addAction('in_admin_header', [$this, 'suppress']);
    }

    /** Removes every notice callback when the current screen is the plugin page. */
    public function suppress(): void
    {
        if (get_current_screen()?->id !== self::SCREEN_ID) {
            return;
        }

        foreach (self::NOTICE_HOOKS as $hook) {
            remove_all_actions($hook);
        }
    }
}
