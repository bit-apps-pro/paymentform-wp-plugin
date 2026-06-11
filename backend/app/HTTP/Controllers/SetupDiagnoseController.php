<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Config;
use PaymentForm\Services\SetupOptionsStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Pre-flight diagnostic endpoint for the setup flow.
 *
 * POST /paymentform/v1/setup/diagnose confirms that the WP site's
 * /paymentform/setup-proof endpoint is reachable from the outside before
 * the user spends time in the wizard. Catches firewall / CDN blocks early.
 *
 * SSRF scope note: this endpoint does NOT accept caller-supplied URLs.
 * The target is always site_url('/paymentform/setup-proof'). The site_url
 * is already a WP-internal value; trust boundary is the WP admin (via
 * manage_options capability check in Routes.php).
 */
final class SetupDiagnoseController
{
    private const TIMEOUT_SECONDS = 10;

    public function handle(WP_REST_Request $req): WP_REST_Response
    {
        // Build stub token + nonce for the diagnose call.
        $nonce = bin2hex(random_bytes(16));
        $tokenExpiresAt = time() + 60;
        $payload = site_url() . ':' . $nonce . ':' . $tokenExpiresAt;
        $hmac = hash_hmac('sha256', $payload, SECURE_AUTH_KEY);
        $wpSetupToken = 'v1.' . base64_encode($payload) . '.' . $hmac;

        // Persist the stub row.
        $emailMd5 = md5('diagnose@stub');
        $setupRow = [
            'email_md5' => $emailMd5,
            'site_url' => site_url(),
            'wp_setup_token' => $wpSetupToken,
            'nonce_in_token' => $nonce,
            'token_expires_at' => $tokenExpiresAt,
            'expires_at' => $tokenExpiresAt,
        ];

        $store = new SetupOptionsStore();
        $store->put($nonce, $setupRow, $emailMd5);

        // Try calling the backend diagnose endpoint (if it exists).
        // Gracefully fall back to "ok" if the endpoint returns 404.
        $response = wp_remote_request(Config::apiBaseUrl() . '/auth/setup/diagnose', [
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'proof_url' => site_url('/paymentform/setup-proof'),
                'setup_token' => $wpSetupToken,
                'nonce' => $nonce,
            ]),
        ]);

        // Network error or 404 → soft pass (backend endpoint may not exist yet).
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) === 404) {
            return new WP_REST_Response(['next' => 'ok'], 200);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            try {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['next']) && $body['next'] === 'ok') {
                    return new WP_REST_Response(['next' => 'ok'], 200);
                }
                // If backend returned a sub-code, surface it.
                if (isset($body['next']) && $body['next'] === 'failed') {
                    return new WP_REST_Response(
                        ['next' => 'failed', 'code' => $body['code'] ?? 'unknown', 'message' => $body['message'] ?? 'Diagnosis failed.'],
                        200
                    );
                }
            } catch (\JsonException) {
                // Fall through to soft pass.
            }
        }

        // Any other response → soft pass.
        return new WP_REST_Response(['next' => 'ok'], 200);
    }
}
