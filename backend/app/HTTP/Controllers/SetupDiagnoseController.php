<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\SetupOptionsStore;

/**
 * Pre-flight diagnostic endpoint for the setup flow.
 *
 * POST /paymentform/v1/setup/diagnose confirms that the WP site's
 * /paymentform/setup-proof endpoint is reachable from the outside before
 * the user spends time in the wizard. Catches firewall / CDN blocks early.
 *
 * Soft-pass by design: every branch returns a bare array (HTTP 200 via the
 * wp-kit success envelope); only an explicit backend failure verdict
 * surfaces as { next: 'failed' }.
 *
 * SSRF scope note: this endpoint does NOT accept caller-supplied URLs.
 * The target is always site_url('/paymentform/setup-proof'). The site_url
 * is already a WP-internal value; trust boundary is the WP admin (nonce +
 * paymentform_manage middleware on the route). The actual guarded outbound
 * fetch is performed by the backend (WordPressSetupProof::handshake, which
 * rejects RFC1918/loopback/metadata hosts, follows no redirects, and verifies
 * TLS) — this controller only relays the proof_url/site_url/setup_token to it.
 */
final class SetupDiagnoseController
{
    private const TIMEOUT_SECONDS = 10;

    private readonly SetupApiClient $setupApi;

    public function __construct()
    {
        $this->setupApi = ServiceContainer::get(SetupApiClient::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $req): array
    {
        // Build stub token + nonce for the diagnose call.
        $nonce = bin2hex(random_bytes(16));
        $tokenExpiresAt = time() + 60;
        $payload = site_url() . ':' . $nonce . ':' . $tokenExpiresAt;
        $hmac = hash_hmac('sha256', $payload, SECURE_AUTH_KEY);
        // NOTE: plain base64_encode here diverges from SetupController::handle()
        // (SetupController.php:103), which uses base64url. Preserved as-is during
        // the transport refactor; unifying the encoding is a deliberate
        // out-of-scope follow-up (see spec Non-goals).
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
        $res = $this->setupApi->post('/auth/setup/diagnose', [
            'proof_url' => site_url('/paymentform/setup-proof'),
            'site_url' => site_url(),
            'setup_token' => $wpSetupToken,
            'client_slug' => 'wp-plugin',
        ], self::TIMEOUT_SECONDS);

        // Network error or 404 → soft pass (backend endpoint may not exist yet).
        if ($res->isError() || $res->status === 404) {
            return ['next' => 'ok'];
        }

        if ($res->status >= 200 && $res->status < 300 && ! $res->jsonError) {
            $body = $res->body;
            if (isset($body['next']) && $body['next'] === 'ok') {
                return ['next' => 'ok'];
            }
            // If backend returned a sub-code, surface it (still HTTP 200).
            if (isset($body['next']) && $body['next'] === 'failed') {
                return [
                    'next' => 'failed',
                    'code' => $body['code'] ?? 'unknown',
                    'message' => $body['message'] ?? 'Diagnosis failed.',
                ];
            }
        }

        // Any other response → soft pass.
        return ['next' => 'ok'];
    }
}
