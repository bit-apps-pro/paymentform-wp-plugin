<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\HTTP\StaticRoutes;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\SetupOptionsStore;

if (!defined('ABSPATH')) {
    exit;
}

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
 * The target is always the site's own setup-proof route. The site_url
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

    /** Resolve collaborators from the service container (wp-kit builds REST controllers with zero args). */
    public function __construct()
    {
        $this->setupApi = ServiceContainer::get(SetupApiClient::class);
    }

    /**
     * Ask the backend to run a throwaway proof handshake against this site.
     *
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
        $setupRow = [
            'site_url' => site_url(),
            'wp_setup_token' => $wpSetupToken,
            'nonce_in_token' => $nonce,
            'token_expires_at' => $tokenExpiresAt,
            'expires_at' => $tokenExpiresAt,
        ];

        $store = new SetupOptionsStore();
        $store->put($nonce, $setupRow);

        try {
            return $this->mapDiagnoseResponse($this->setupApi->post('/auth/setup/diagnose', [
                'proof_url' => site_url(StaticRoutes::path(StaticRoutes::SETUP_PROOF)),
                'site_url' => site_url(),
                'setup_token' => $wpSetupToken,
                'client_slug' => 'wp-plugin',
            ], self::TIMEOUT_SECONDS));
        } finally {
            // The proof handshake ran synchronously inside the backend call, so the row is spent.
            $store->consumeByNonce($nonce);
        }
    }

    /**
     * Map the backend's diagnose verdict, soft-passing anything but an explicit failure.
     *
     * @return array<string, mixed>
     */
    private function mapDiagnoseResponse(HttpResponse $res): array
    {
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
