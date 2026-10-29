<?php

declare(strict_types=1);

namespace PaymentForm\HTTP;

use PaymentForm\HTTP\Controllers\CallbackController;
use PaymentForm\HTTP\Controllers\SetupProofController;
use PaymentForm\HTTP\Controllers\WebhookController;
use PaymentForm\Providers\ServiceContainer;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static-route actions from `backend/hooks/static.php`; wp-kit builds them with no
 * arguments, so each one hands a captured request to its container-built controller.
 */
final class StaticRouteDispatcher
{
    /** Completes the OAuth connect redirect. */
    public function callback(): void
    {
        ServiceContainer::get(CallbackController::class)->handle($this->captureRequest(StaticRoutes::CALLBACK));
    }

    /** Verifies a signed app webhook and emits its JSON response. */
    public function webhook(): void
    {
        $this->emitAndExit(
            ServiceContainer::get(WebhookController::class)->handle($this->captureRequest(StaticRoutes::WEBHOOK))
        );
    }

    /** Answers the setup flow's proof-of-control handshake. */
    public function setupProof(): void
    {
        ServiceContainer::get(SetupProofController::class)->handle($this->captureRequest(StaticRoutes::SETUP_PROOF));
    }

    /** Builds a REST request from the globals; wp-kit's Request exposes neither headers nor the raw body. */
    private function captureRequest(string $route): WP_REST_Request
    {
        $method = strtoupper(sanitize_key(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
        $request = new WP_REST_Request($method, '/' . StaticRoutes::PAGE_NAME . '/' . $route);

        // phpcs:disable WordPress.Security.NonceVerification -- callback/webhook/setup-proof authenticate by state token or HMAC, not a WP nonce; controllers validate every param.
        foreach (wp_unslash($_GET) as $key => $value) {
            $request->set_param($key, $value);
        }
        foreach (wp_unslash($_POST) as $key => $value) {
            $request->set_param($key, $value);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        // The webhook HMAC is computed over these exact bytes.
        $body = file_get_contents('php://input');
        if (is_string($body)) {
            $request->set_body($body);
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $request->set_header(str_replace('_', '-', substr($key, 5)), $value);
            }
        }

        return $request;
    }

    /** Emits a REST response as JSON and stops WP before the template layer. */
    private function emitAndExit(WP_REST_Response $response): never
    {
        status_header($response->get_status());
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($response->get_data());
        exit;
    }
}
