<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Middleware;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Deps\BitApps\WPKit\Utils\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-kit middleware: enforces a WordPress capability on REST routes.
 *
 * Routes attach it as `cap:paymentform_manage`; wp-kit splits the route
 * string on the colon and passes the tail positionally, so $cap arrives as
 * the second argument. A bare `cap` (no colon) passes no extra args — the
 * default below must therefore stay in place.
 *
 * Capabilities::filter($cap) passes when EITHER
 *   current_user_can($cap)                                  — direct cap, OR
 *   current_user_can(apply_filters($cap, 'manage_options')) — the cap name
 *     doubles as a filter tag whose default value is `manage_options`.
 * Net effect: administrators (manage_options) always pass, and site code can
 * re-route the check via add_filter('paymentform_manage', ...) for
 * multisite/agency grants. Standard installs are unchanged — the Activator
 * grants `paymentform_manage` to the administrator role anyway.
 */
final class CapabilityCheckerMiddleware
{
    /**
     * @return Response|true true to continue the chain, a 403 Response otherwise
     */
    public function handle(Request $request, string $cap = 'paymentform_manage'): Response|bool
    {
        if ($cap === '' || !Capabilities::filter($cap)) {
            return Response::error([], 403)
                ->code('NOT_AUTHORIZED')
                ->message(__('You are not allowed to do this.', 'paymentform'));
        }

        return true;
    }
}
