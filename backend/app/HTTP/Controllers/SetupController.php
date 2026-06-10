<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use JsonException;
use PaymentForm\Config;
use PaymentForm\Services\ApiClient;
use PaymentForm\Services\SetupOptionsStore;
use PaymentForm\Services\TokenServiceInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for POST /paymentform/v1/setup — orchestrates the
 * Create-new-account flow per §4.4.
 *
 * Flow:
 * 1. Validate email, country, optional locale, email_override
 * 2. Generate nonce + wp_setup_token under SECURE_AUTH_KEY
 * 3. Persist setup row via SetupOptionsStore (autoload=no, TTL 10min)
 * 4. POST to /auth/setup on the backend (with proof_url)
 * 5. Branch on response:
 *    - status: 'connected' → token present, store it, return { next: 'connected' }
 *    - status: 'use_connect_flow' → no token, return { next: 'redirect_connect', connect_url }
 *    - 503 + error_code: 'FEATURE_DISABLED' → return { next: 'failed', code: 'service_unavailable' }
 *    - 422 + error_code: 'PROOF_FAILED' → return { next: 'failed', code: <sub_code> }
 *    - 422 + error_code: 'INVALID_PROOF_HOST' → return { next: 'failed', code: 'invalid_proof_host' }
 *    - Network/5xx error → return { next: 'failed', code: 'network' }
 */
final class SetupController
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly TokenServiceInterface $tokenService,
    ) {}

    public function handle(WP_REST_Request $req): WP_REST_Response
    {
        // Parse and validate input.
        $email = (string) ($req->get_param('email') ?? '');
        $country = (string) ($req->get_param('country') ?? '');
        $locale = (string) ($req->get_param('locale') ?? '');
        $emailOverride = (string) ($req->get_param('email_override') ?? '');

        // Validate email format.
        if (!$email || strlen($email) > 256 || !is_email($email)) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'bad_request', 'message' => 'Invalid email address.'], 400);
        }

        // Validate country (ISO 3166-1 alpha-2, uppercase).
        if (!$country || strlen($country) !== 2 || !preg_match('/^[A-Z]{2}$/', $country)) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'bad_request', 'message' => 'Invalid country code.'], 400);
        }

        // Validate optional locale (format: xx_YY).
        if ($locale && strlen($locale) !== 5) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'bad_request', 'message' => 'Invalid locale format.'], 400);
        }

        // Cap string lengths before hashing/persisting.
        if (strlen($email) > 256) {
            $email = substr($email, 0, 256);
        }
        if ($emailOverride && strlen($emailOverride) > 256) {
            $emailOverride = substr($emailOverride, 0, 256);
        }

        // Resolve effective email: override if provided, else use param.
        $effectiveEmail = $emailOverride && is_email($emailOverride) ? $emailOverride : $email;

        // Generate nonce (32 hex chars = 16 random bytes).
        $nonce = bin2hex(random_bytes(16));
        $tokenExpiresAt = time() + 600; // 10 min TTL

        // Build wp_setup_token with HMAC under SECURE_AUTH_KEY. Payload is
        // base64url-encoded (no padding, `+`/`/` swapped for `-`/`_`) so the token
        // survives URL-encoding by proxies / curl handlers without round-trip
        // ambiguity — backend hands the token back in an HTTP header but any
        // intermediary that touches the value mustn't break decode.
        $payload = site_url() . ':' . $nonce . ':' . $tokenExpiresAt;
        $hmac = hash_hmac('sha256', $payload, SECURE_AUTH_KEY);
        $payloadB64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $wpSetupToken = 'v1.' . $payloadB64 . '.' . $hmac;

        // Persist setup row (both per-nonce and per-email).
        $emailMd5 = md5($effectiveEmail);
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

        // POST to backend /auth/setup.
        $backendPayload = [
            'email' => $effectiveEmail,
            'country' => $country,
            'site_url' => site_url(),
            'proof_url' => site_url('/paymentform/setup-proof'),
            'wp_setup_token' => $wpSetupToken,
            'client_slug' => 'wp-plugin',
            'client_type' => 'server',
        ];

        if ($locale) {
            $backendPayload['locale'] = $locale;
        }

        $response = wp_remote_request(Config::apiBaseUrl() . '/auth/setup', [
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($backendPayload),
        ]);

        // Handle network errors.
        if (is_wp_error($response)) {
            return new WP_REST_Response(
                [
                    'next' => 'failed',
                    'code' => 'network',
                    'message' => 'Could not reach paymentform.io. Check your hosting firewall.',
                ],
                503
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);

        // Handle 503 kill switch.
        if ($status === 503) {
            try {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['error_code']) && $body['error_code'] === 'FEATURE_DISABLED') {
                    $retryAfter = (int) ($body['retry_after'] ?? 3600);
                    return new WP_REST_Response(
                        [
                            'next' => 'failed',
                            'code' => 'service_unavailable',
                            'message' => 'PaymentForm signup is temporarily unavailable. Please try again later.',
                            'retry_after_seconds' => $retryAfter,
                        ],
                        503
                    );
                }
            } catch (JsonException) {
                // Fall through to generic network error.
            }
            return new WP_REST_Response(
                ['next' => 'failed', 'code' => 'network', 'message' => 'Backend service unavailable.'],
                503
            );
        }

        // Handle 429 rate-limit explicitly so the wizard can render a
        // "try again in N seconds" message instead of a generic error.
        if ($status === 429) {
            $retryAfter = 0;
            $retryHeader = wp_remote_retrieve_header($response, 'retry-after');
            if (is_string($retryHeader) && ctype_digit($retryHeader)) {
                $retryAfter = (int) $retryHeader;
            }

            return new WP_REST_Response(
                [
                    'next' => 'failed',
                    'code' => 'rate_limited',
                    'message' => 'Too many requests. Please wait a moment before retrying.',
                    'retry_after_seconds' => $retryAfter,
                ],
                429
            );
        }

        // Successful response (2xx).
        if ($status >= 200 && $status < 300) {
            try {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new WP_REST_Response(
                    ['next' => 'failed', 'code' => 'invalid_response', 'message' => 'Invalid backend response.'],
                    502
                );
            }

            // Backend wraps payload in `{ status: true, data: {...} }`. Hard
            // require the envelope so a contract drift surfaces as a clear
            // `invalid_response` rather than silently falling back to the
            // outer body (which would mis-route `status: false` errors).
            if (! is_array($body) || ! array_key_exists('data', $body) || ! is_array($body['data'])) {
                return new WP_REST_Response(
                    ['next' => 'failed', 'code' => 'invalid_response', 'message' => 'Invalid backend response.'],
                    502
                );
            }
            $data = $body['data'];
            $responseStatus = $data['status'] ?? null;

            // Branch C: brand new user, token created.
            if ($responseStatus === 'connected') {
                $token = $data['token'] ?? null;
                $expiresAt = $data['expires_at'] ?? null;
                $webhookSecret = $data['webhook_secret'] ?? null;

                if ($token && $expiresAt) {
                    try {
                        if ($webhookSecret) {
                            $this->tokenService->storeWebhookSecret($webhookSecret);
                        }
                        $this->tokenService->store($token, $expiresAt, $data['tenant'] ?? null);
                        $this->tokenService->storeCreatedVia('setup');

                        // Wipe per-nonce row; keep per-email for OTP-resend reference.
                        delete_option('paymentform_setup_by_nonce_' . $nonce);

                        return new WP_REST_Response(
                            ['next' => 'connected', 'has_workspace' => true],
                            200
                        );
                    } catch (Throwable $e) {
                        $this->tokenService->clear();
                        return new WP_REST_Response(
                            ['next' => 'failed', 'code' => 'storage_failed', 'message' => 'Failed to store connection.'],
                            500
                        );
                    }
                }
            }

            // Deferred-token: backend created the user but is waiting on the
            // email-verification click before issuing the access token. Stash
            // the wp_setup_token under the current WP admin so /setup/status
            // can post it to /auth/setup/finalize without exposing it to JS.
            if ($responseStatus === 'check_inbox') {
                $userId = get_current_user_id();
                if ($userId > 0) {
                    set_transient(
                        $this->pendingTransientKey($userId),
                        $wpSetupToken,
                        15 * MINUTE_IN_SECONDS
                    );
                }

                // Per-nonce row is no longer needed once the handshake completed.
                delete_option('paymentform_setup_by_nonce_' . $nonce);

                return new WP_REST_Response(
                    [
                        'next' => 'check_inbox',
                        'email_masked' => is_string($data['email_masked'] ?? null) ? $data['email_masked'] : null,
                        'provisioning' => is_string($data['provisioning'] ?? null) ? $data['provisioning'] : 'pending',
                    ],
                    200
                );
            }

            // Branch A: existing email, redirect to Connect.
            if ($responseStatus === 'use_connect_flow') {
                $connectUrl = $data['connect_url'] ?? null;
                if ($connectUrl) {
                    // Wipe per-nonce row.
                    delete_option('paymentform_setup_by_nonce_' . $nonce);

                    return new WP_REST_Response(
                        ['next' => 'redirect_connect', 'connect_url' => $connectUrl, 'has_workspace' => false],
                        200
                    );
                }
            }
        }

        // Handle 422 errors (validation / proof failure).
        if ($status === 422) {
            try {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new WP_REST_Response(
                    ['next' => 'failed', 'code' => 'invalid_response'],
                    502
                );
            }

            $errorCode = $body['error_code'] ?? null;

            // Proof failed with sub-code.
            if ($errorCode === 'PROOF_FAILED') {
                $errors = $body['errors'] ?? [];
                $subCode = is_array($errors) ? (current($errors) ?? 'proof_failed') : 'proof_failed';

                $message = $this->mapProofErrorMessage($subCode);

                // Wipe per-nonce row.
                delete_option('paymentform_setup_by_nonce_' . $nonce);

                return new WP_REST_Response(
                    ['next' => 'failed', 'code' => $subCode, 'message' => $message],
                    422
                );
            }

            // Proof host mismatch.
            if ($errorCode === 'INVALID_PROOF_HOST') {
                delete_option('paymentform_setup_by_nonce_' . $nonce);

                return new WP_REST_Response(
                    ['next' => 'failed', 'code' => 'invalid_proof_host', 'message' => 'Site URL host mismatch.'],
                    422
                );
            }
        }

        // Generic error response.
        delete_option('paymentform_setup_by_nonce_' . $nonce);

        return new WP_REST_Response(
            ['next' => 'failed', 'code' => 'unknown_error', 'message' => 'An unexpected error occurred.'],
            500
        );
    }

    /**
     * Poll the deferred-token finalize endpoint. The wp_setup_token never
     * crosses the wire to JS — it lives in a per-admin transient that we
     * forward to /auth/setup/finalize on the wizard's behalf.
     *
     * Mirrors `handle()` response shape so the wizard can keep using the
     * same `next:` branching it already has for the initial /setup call.
     */
    public function status(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'unauthenticated'], 401);
        }

        $wpSetupToken = get_transient($this->pendingTransientKey($userId));
        if (! is_string($wpSetupToken) || $wpSetupToken === '') {
            return new WP_REST_Response(
                ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
                410
            );
        }

        $response = wp_remote_request(Config::apiBaseUrl() . '/auth/setup/finalize', [
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['wp_setup_token' => $wpSetupToken]),
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'network', 'message' => 'Could not reach paymentform.io.'], 503);
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);

        // 410 (or any 4xx) means the cache row is gone server-side — drop
        // the transient so a retry doesn't keep hammering the dead key.
        if ($statusCode === 410) {
            delete_transient($this->pendingTransientKey($userId));

            return new WP_REST_Response(
                ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
                410
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'finalize_failed'], $statusCode);
        }

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'invalid_response'], 502);
        }

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            return new WP_REST_Response(['next' => 'failed', 'code' => 'invalid_response'], 502);
        }

        $data = $body['data'];
        $responseStatus = $data['status'] ?? null;

        if ($responseStatus === 'connected') {
            $token = $data['token'] ?? null;
            $expiresAt = $data['expires_at'] ?? null;
            $webhookSecret = $data['webhook_secret'] ?? null;

            if (! is_string($token) || ! is_string($expiresAt)) {
                return new WP_REST_Response(['next' => 'failed', 'code' => 'invalid_response'], 502);
            }

            try {
                if (is_string($webhookSecret) && $webhookSecret !== '') {
                    $this->tokenService->storeWebhookSecret($webhookSecret);
                }
                $this->tokenService->store($token, $expiresAt, is_string($data['tenant'] ?? null) ? $data['tenant'] : null);
                $this->tokenService->storeCreatedVia('setup');
            } catch (Throwable $e) {
                $this->tokenService->clear();

                return new WP_REST_Response(['next' => 'failed', 'code' => 'storage_failed'], 500);
            }

            // Consume the transient — the access token has superseded the
            // setup token and a replay would be wasted server work.
            delete_transient($this->pendingTransientKey($userId));

            return new WP_REST_Response(
                ['next' => 'connected', 'has_workspace' => true, 'email_verified' => true],
                200
            );
        }

        // Still pending — wizard keeps polling.
        return new WP_REST_Response(
            [
                'next' => 'check_inbox',
                'email_verified' => (bool) ($data['email_verified'] ?? false),
                'provisioning' => is_string($data['provisioning'] ?? null) ? $data['provisioning'] : 'pending',
            ],
            200
        );
    }

    /**
     * Per-admin transient key used to stash the wp_setup_token between the
     * initial /setup call and the wizard's polling of /setup/status. Tied to
     * the WP user_id so a second admin on the same install can't pick up
     * another admin's in-progress flow.
     */
    private function pendingTransientKey(int $userId): string
    {
        return 'paymentform_setup_pending_' . $userId;
    }

    /**
     * Map proof sub-codes to human-friendly messages.
     */
    private function mapProofErrorMessage(string $subCode): string
    {
        return match ($subCode) {
            'proof_dns_failed' => "Could not resolve your site's DNS. Confirm your site URL is correct.",
            'proof_tls_failed' => "Your site's SSL certificate isn't trusted. Renew it or use HTTP for testing.",
            'proof_unreachable' => 'We couldn\'t reach your WordPress site. Make sure it\'s publicly accessible from the internet.',
            'proof_redirect_refused' => 'Your site redirected our verification request. Disable redirect plugins on /paymentform/setup-proof.',
            'proof_timeout' => 'Your site took too long to respond. Please try again.',
            'proof_bad_hmac' => 'Verification failed. Your WordPress security keys may have been rotated.',
            'proof_nonce_mismatch' => 'Verification failed. Please try again.',
            default => 'We couldn\'t verify your WordPress site. Please try again.',
        };
    }
}
