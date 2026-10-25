<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Middleware;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-kit middleware: verifies the WordPress REST nonce (`wp_rest` action).
 *
 * The admin SPA sends the nonce in the `X-WP-Nonce` header; a `_wpnonce`
 * query/body param is accepted as a fallback for parity with the legacy
 * permission callback this middleware replaced. Returning anything other
 * than `true` makes
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
        //
        // _wpnonce must be a string: a client can send `_wpnonce[]=x` which
        // makes Request::get() return an array. Cast or coerce only after an
        // explicit is_string() guard so no "Array to string conversion" warning
        // is emitted on attacker-controlled input before the 401 fires.
        $headerNonce = $request->get_header('X-WP-Nonce');
        $paramNonce  = $request->get('_wpnonce', '');
        $nonce       = is_string($headerNonce) && $headerNonce !== ''
            ? $headerNonce
            : (is_string($paramNonce) ? $paramNonce : '');

        if ($nonce === '' || wp_verify_nonce(sanitize_text_field($nonce), 'wp_rest') === false) {
            return Response::error([], 401)
                ->code('INVALID_NONCE')
                ->message(__('Your session expired. Please reload the page.', 'paymentform'));
        }

        return true;
    }
}
