<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

use Pimple\Container;
use PaymentForm\HTTP\Controllers\ConnectController;
use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\StatusController;
use PaymentForm\HTTP\Controllers\RefreshController;
use PaymentForm\HTTP\Controllers\DisconnectController;
use PaymentForm\HTTP\Controllers\FormsProxyController;
use PaymentForm\HTTP\Controllers\WebhookController;

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

        // GET /callback — OAuth callback handler
        register_rest_route('paymentform/v1', '/callback', [
            'methods'             => 'GET',
            'callback'            => static fn($req) => $c[CallbackController::class]->handle($req),
            'permission_callback' => self::publicCallback(),
        ]);

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

        // POST /webhook — receive form submission webhooks
        register_rest_route('paymentform/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => static fn($req) => $c[WebhookController::class]->handle($req),
            'permission_callback' => self::publicCallback(),
        ]);
    }

    private static function requiresCap(): callable
    {
        return static function (): bool {
            if (!current_user_can('paymentform_manage')) {
                return false;
            }

            $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? null;
            return wp_verify_nonce($nonce, 'wp_rest') !== false;
        };
    }

    private static function publicCallback(): callable
    {
        return static fn() => true;
    }
}
