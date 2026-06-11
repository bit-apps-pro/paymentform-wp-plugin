<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Middleware;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;

/**
 * wp-kit middleware: verifies the WordPress REST nonce (`wp_rest` action).
 *
 * The admin SPA sends the nonce in the `X-WP-Nonce` header; a `_wpnonce`
 * query/body param is accepted as a fallback for parity with the legacy
 * Routes.php permission callback. Returning anything other than `true` makes
 * RouteRegister::handleMiddleware() short-circuit: the returned Response is
 * emitted (HTTP 401 here) and the controller action never runs.
 */
final class NonceCheckerMiddleware
{
    /**
     * @return Response|true true to continue the chain, a 401 Response otherwise
     */
    public function handle(Request $request): Response|bool
    {
        // get_header() is delegated to the wrapped WP_REST_Request via
        // Request::__call(); RouteRegister sets the rest request before any
        // middleware runs, so the delegation target always exists here.
        $nonce = (string) ($request->get_header('X-WP-Nonce') ?: $request->get('_wpnonce', ''));

        if ($nonce === '' || wp_verify_nonce(sanitize_text_field($nonce), 'wp_rest') === false) {
            return Response::error([], 401)
                ->code('INVALID_NONCE')
                ->message(__('Your session expired. Please reload the page.', 'paymentform'));
        }

        return true;
    }
}
