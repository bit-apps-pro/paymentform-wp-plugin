<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

use PaymentForm\Providers\Container;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\StatusController;

final class Routes
{
    public static function register(Container $c): void
    {
        // GET /connect-url — initiate OAuth flow
        register_rest_route('paymentform/v1', '/connect-url', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => $c[ConnectController::class]->show($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // /callback and /webhook live on rewrite rules (see Rewrites.php) so
        // managed-WP hosts and security plugins that block /wp-json/ can't
        // strand the OAuth + webhook flows.

        // GET /status — check connection status
        register_rest_route('paymentform/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => $c[StatusController::class]->show($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // POST /refresh — manually refresh token
        register_rest_route('paymentform/v1', '/refresh', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => $c[RefreshController::class]->refresh($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // POST /disconnect — revoke and clear token
        register_rest_route('paymentform/v1', '/disconnect', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => $c[DisconnectController::class]->disconnect($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // GET /forms — list all forms (proxy to backend)
        register_rest_route('paymentform/v1', '/forms', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => $c[FormsProxyController::class]->index($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // GET /forms/{id} — get single form (proxy to backend)
        register_rest_route('paymentform/v1', '/forms/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => $c[FormsProxyController::class]->show($req),
            'permission_callback' => self::requiresCap(),
        ]);

    }

    private static function requiresCap(): callable
    {
        return static function ($req): bool {
            if (!current_user_can('paymentform_manage')) {
                return false;
            }

            $nonce = $req->get_header('X-WP-Nonce')
                  ?? $_SERVER['HTTP_X_WP_NONCE']
                  ?? $req->get_param('_wpnonce')
                  ?? '';
            return wp_verify_nonce($nonce, 'wp_rest') !== false;
        };
    }

}
