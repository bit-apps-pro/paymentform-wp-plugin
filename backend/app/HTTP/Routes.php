<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

use PaymentForm\Providers\Container;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\SetupController;
use PaymentForm\HTTP\Controllers\SetupDiagnoseController;
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

        // POST /setup — initiate Create flow
        register_rest_route('paymentform/v1', '/setup', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => $c[SetupController::class]->handle($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // POST /setup/diagnose — pre-flight health check
        register_rest_route('paymentform/v1', '/setup/diagnose', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => $c[SetupDiagnoseController::class]->handle($req),
            'permission_callback' => self::requiresCap(),
        ]);

        // POST /setup/verify-otp — verify OTP (stub for Phase C)
        register_rest_route('paymentform/v1', '/setup/verify-otp', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => new \WP_REST_Response(['verified' => false, 'error' => 'not_implemented'], 501),
            'permission_callback' => self::requiresCap(),
        ]);

        // POST /setup/resend-otp — resend OTP (stub for Phase C)
        register_rest_route('paymentform/v1', '/setup/resend-otp', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => new \WP_REST_Response(['ok' => true], 200),
            'permission_callback' => self::requiresCap(),
        ]);

        // GET /setup/status — check provisioning status
        register_rest_route('paymentform/v1', '/setup/status', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => new \WP_REST_Response(['provisioning' => 'pending', 'email_verified' => false, 'tenant' => null], 200),
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
