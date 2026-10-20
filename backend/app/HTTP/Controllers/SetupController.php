<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use InvalidArgumentException;
use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\HTTP\Requests\SetupRequest;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\ConnectService;
use PaymentForm\Services\ConnectStateException;
use PaymentForm\Services\Http\HttpResponse;
use PaymentForm\Services\SetupApiClient;
use PaymentForm\Services\SetupOptionsStore;
use PaymentForm\Services\TokenService;
use PaymentForm\Services\TokenServiceInterface;
use Throwable;

/**
 * REST controller for POST /paymentform/v1/setup — orchestrates the
 * Create-new-account flow per §4.4.
 *
 * Flow:
 * 1. Validate email, country, optional locale, email_override
 * 2. Generate nonce + wp_setup_token under SECURE_AUTH_KEY
 * 3. Persist setup row via SetupOptionsStore (non-autoloaded, TTL 10min)
 * 4. POST to /auth/setup on the backend (with proof_url); the backend runs the
 *    proof handshake synchronously, so the row is deleted once it answers
 * 5. Branch on response:
 *    - status: 'connected' → token present, store it, return { next: 'connected' }
 *    - status: 'use_connect_flow' → no token, return { next: 'redirect_connect', connect_url }
 *      where connect_url is issued locally by {@see ConnectService::createConnectUrl()}
 *    - 503 + error_code: 'FEATURE_DISABLED' → return { next: 'failed', code: 'service_unavailable' }
 *    - 422 + error_code: 'PROOF_FAILED' → return { next: 'failed', code: <sub_code> }
 *    - 422 + error_code: 'INVALID_PROOF_HOST' → return { next: 'failed', code: 'invalid_proof_host' }
 *    - Network/5xx error → return { next: 'failed', code: 'network' }
 *
 * Response transport: 2xx branches return bare arrays (wp-kit success
 * envelope); every non-2xx branch goes through {@see failure()}, which keeps
 * the full legacy body under `data` and mirrors its `code`/`message` onto the
 * wp-kit envelope per the migration-spec return-point mapping (MAJOR-2).
 */
final class SetupController
{
    private const TIMEOUT_SECONDS = 30;

    private readonly SetupApiClient $setupApi;

    private readonly TokenServiceInterface $tokenService;

    private readonly ConnectService $connectService;

    /** Resolve collaborators from the service container (wp-kit builds REST controllers with zero args). */
    public function __construct()
    {
        $this->setupApi = ServiceContainer::get(SetupApiClient::class);
        $this->tokenService = ServiceContainer::get(TokenService::class);
        $this->connectService = ServiceContainer::get(ConnectService::class);
    }

    /**
     * Start the Create-new-account flow and map the backend verdict for the wizard.
     *
     * @return array<string, mixed>|Response
     */
    public function handle(SetupRequest $req): array|Response
    {
        // Input is validated by SetupRequest::rules() before this runs (wp-kit
        // RouteRegister), so these are guaranteed well-formed.
        $email = (string) $req->get('email', '');
        $country = (string) $req->get('country', '');
        $locale = (string) $req->get('locale', '');
        $emailOverride = (string) $req->get('email_override', '');

        // Resolve effective email: the override when supplied (the rule
        // guarantees it is a valid email), else the primary email.
        $effectiveEmail = $emailOverride !== '' ? $emailOverride : $email;

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

        $setupRow = [
            'site_url' => site_url(),
            'wp_setup_token' => $wpSetupToken,
            'nonce_in_token' => $nonce,
            'token_expires_at' => $tokenExpiresAt,
            'expires_at' => $tokenExpiresAt,
        ];

        $store = new SetupOptionsStore();
        $store->put($nonce, $setupRow);

        // POST to backend /auth/setup.
        $backendPayload = [
            'email' => $effectiveEmail,
            'country' => $country,
            'site_url' => site_url(),
            'proof_url' => site_url('/paymentform/setup-proof'),
            'setup_token' => $wpSetupToken,
            'client_slug' => 'wp-plugin',
            'client_type' => 'server',
        ];

        if ($locale) {
            $backendPayload['locale'] = $locale;
        }

        try {
            return $this->mapSetupResponse(
                $this->setupApi->post('/auth/setup', $backendPayload, self::TIMEOUT_SECONDS),
                $wpSetupToken
            );
        } finally {
            // The proof handshake ran synchronously inside the backend call, so the row is spent.
            $store->consumeByNonce($nonce);
        }
    }

    /**
     * Map the backend's /auth/setup response onto the wizard's `next:` contract.
     *
     * @return array<string, mixed>|Response
     */
    private function mapSetupResponse(HttpResponse $res, string $wpSetupToken): array|Response
    {
        // Handle network errors.
        if ($res->isError()) {
            return $this->failure(
                [
                    'next' => 'failed',
                    'code' => 'network',
                    'message' => 'Could not reach paymentform.io. Check your hosting firewall.',
                ],
                503
            );
        }

        $status = $res->status;

        // Handle 503 kill switch.
        if ($status === 503) {
            $body = $res->body;
            if (! $res->jsonError && isset($body['error_code']) && $body['error_code'] === 'FEATURE_DISABLED') {
                $retryAfter = (int) ($body['retry_after'] ?? 3600);

                return $this->failure(
                    [
                        'next' => 'failed',
                        'code' => 'service_unavailable',
                        'message' => 'PaymentForm signup is temporarily unavailable. Please try again later.',
                        'retry_after_seconds' => $retryAfter,
                    ],
                    503
                );
            }

            return $this->failure(
                ['next' => 'failed', 'code' => 'network', 'message' => 'Backend service unavailable.'],
                503
            );
        }

        // Handle 429 rate-limit explicitly so the wizard can render a
        // "try again in N seconds" message instead of a generic error.
        if ($status === 429) {
            $retryAfter = 0;
            $retryHeader = $res->headers['retry-after'] ?? null;
            if (is_string($retryHeader) && ctype_digit($retryHeader)) {
                $retryAfter = (int) $retryHeader;
            }

            return $this->failure(
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
            if ($res->jsonError) {
                return $this->failure(
                    ['next' => 'failed', 'code' => 'invalid_response', 'message' => 'Invalid backend response.'],
                    502
                );
            }
            $body = $res->body;

            // Backend wraps payload in `{ status: true, data: {...} }`. Hard
            // require the envelope so a contract drift surfaces as a clear
            // `invalid_response` rather than silently falling back to the
            // outer body (which would mis-route `status: false` errors).
            if (! is_array($body) || ! array_key_exists('data', $body) || ! is_array($body['data'])) {
                return $this->failure(
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

                        return ['next' => 'connected', 'has_workspace' => true];
                    } catch (Throwable) {
                        $this->tokenService->clear();

                        return $this->failure(
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

                return [
                    'next' => 'check_inbox',
                    'email_masked' => is_string($data['email_masked'] ?? null) ? $data['email_masked'] : null,
                    'provisioning' => is_string($data['provisioning'] ?? null) ? $data['provisioning'] : 'pending',
                ];
            }

            // Branch A: existing email, redirect to Connect.
            if ($responseStatus === 'use_connect_flow') {
                return $this->redirectToConnect();
            }
        }

        // Handle 422 errors (validation / proof failure).
        if ($status === 422) {
            if ($res->jsonError) {
                return $this->failure(['next' => 'failed', 'code' => 'invalid_response'], 502);
            }
            $body = $res->body;

            $errorCode = $body['error_code'] ?? null;

            // Proof failed with sub-code.
            if ($errorCode === 'PROOF_FAILED') {
                $subCode = $this->extractProofSubCode($body['errors'] ?? null);
                $message = $this->mapProofErrorMessage($subCode);

                return $this->failure(['next' => 'failed', 'code' => $subCode, 'message' => $message], 422);
            }

            // Proof host mismatch.
            if ($errorCode === 'INVALID_PROOF_HOST') {
                return $this->failure(
                    ['next' => 'failed', 'code' => 'invalid_proof_host', 'message' => 'Site URL host mismatch.'],
                    422
                );
            }
        }

        // Generic error response.
        return $this->failure(
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
     *
     * @return array<string, mixed>|Response
     */
    public function status(Request $req): array|Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return $this->failure(['next' => 'failed', 'code' => 'unauthenticated'], 401);
        }

        $wpSetupToken = get_transient($this->pendingTransientKey($userId));
        if (! is_string($wpSetupToken) || $wpSetupToken === '') {
            return $this->failure(
                ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
                410
            );
        }

        $res = $this->setupApi->post('/auth/setup/finalize', ['setup_token' => $wpSetupToken], self::TIMEOUT_SECONDS);

        if ($res->isError()) {
            return $this->failure(
                ['next' => 'failed', 'code' => 'network', 'message' => 'Could not reach paymentform.io.'],
                503
            );
        }

        $statusCode = $res->status;

        // 410 (or any 4xx) means the cache row is gone server-side — drop
        // the transient so a retry doesn't keep hammering the dead key.
        if ($statusCode === 410) {
            delete_transient($this->pendingTransientKey($userId));

            return $this->failure(
                ['next' => 'failed', 'code' => 'setup_expired', 'message' => 'Your setup link has expired. Please start over.'],
                410
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            // Dynamic passthrough of the backend's own status (mapping S5). A
            // degenerate/unparseable upstream response yields $statusCode === 0,
            // which Response::getHttpStatusCode() silently coerces to 400 — fall
            // back to 502 Bad Gateway so the wizard sees an upstream failure,
            // not a misleading client error.
            return $this->failure(['next' => 'failed', 'code' => 'finalize_failed'], $statusCode ?: 502);
        }

        if ($res->jsonError) {
            return $this->failure(['next' => 'failed', 'code' => 'invalid_response'], 502);
        }
        $body = $res->body;

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            return $this->failure(['next' => 'failed', 'code' => 'invalid_response'], 502);
        }

        $data = $body['data'];
        $responseStatus = $data['status'] ?? null;

        if ($responseStatus === 'connected') {
            $token = $data['token'] ?? null;
            $expiresAt = $data['expires_at'] ?? null;
            $webhookSecret = $data['webhook_secret'] ?? null;

            if (! is_string($token) || ! is_string($expiresAt)) {
                return $this->failure(['next' => 'failed', 'code' => 'invalid_response'], 502);
            }

            try {
                if (is_string($webhookSecret) && $webhookSecret !== '') {
                    $this->tokenService->storeWebhookSecret($webhookSecret);
                }
                $this->tokenService->store($token, $expiresAt, is_string($data['tenant'] ?? null) ? $data['tenant'] : null);
                $this->tokenService->storeCreatedVia('setup');
            } catch (Throwable) {
                $this->tokenService->clear();

                return $this->failure(['next' => 'failed', 'code' => 'storage_failed'], 500);
            }

            // Consume the transient — the access token has superseded the
            // setup token and a replay would be wasted server work.
            delete_transient($this->pendingTransientKey($userId));

            return ['next' => 'connected', 'has_workspace' => true, 'email_verified' => true];
        }

        // The email owner denied this site on the verify-link page; the setup is over.
        if ($responseStatus === 'denied') {
            delete_transient($this->pendingTransientKey($userId));

            return [
                'next' => 'failed',
                'code' => 'setup_denied',
                'message' => 'The email owner denied access to this site. Start over to try again.',
            ];
        }

        // Still pending — wizard keeps polling.
        return [
            'next' => 'check_inbox',
            'email_verified' => (bool) ($data['email_verified'] ?? false),
            'authorized' => (bool) ($data['authorized'] ?? false),
            'provisioning' => is_string($data['provisioning'] ?? null) ? $data['provisioning'] : 'pending',
        ];
    }

    /**
     * Hand the wizard a locally issued connect URL, or a storage failure when no state could be persisted.
     *
     * @return array<string, mixed>|Response
     */
    private function redirectToConnect(): array|Response
    {
        try {
            $connectUrl = $this->connectService->createConnectUrl();
        } catch (ConnectStateException) {
            return $this->failure(
                ['next' => 'failed', 'code' => 'storage_failed', 'message' => 'Failed to start the connect flow.'],
                500
            );
        }

        return ['next' => 'redirect_connect', 'connect_url' => $connectUrl, 'has_workspace' => false];
    }

    /**
     * Wraps a legacy error body in the wp-kit error envelope: the full body
     * stays under `data` (so `next`/`message`/`retry_after_seconds` survive)
     * and its `code`/`message` are mirrored onto the envelope so the SPA's
     * error parser recovers the same semantic values (migration-spec MAJOR-2).
     *
     * The message is set unconditionally: wp-kit's Response is an all-static
     * singleton, so a message-less body must explicitly clear any message a
     * previous response left behind. `message(null)` stores null verbatim
     * (getMessage() returns the raw property) and RouteRegister::setResponse()
     * omits falsy messages from the emitted body, so nothing stale leaks.
     *
     * @param array<string, mixed> $body legacy error body; string `code` required
     *
     * @throws InvalidArgumentException when the body carries no string `code`
     */
    private function failure(array $body, int $httpStatus): Response
    {
        if (!isset($body['code']) || !is_string($body['code'])) {
            throw new InvalidArgumentException('Setup failure body must declare a string `code`.');
        }

        return Response::error($body, $httpStatus)
            ->code($body['code'])
            ->message($body['message'] ?? null);
    }

    /**
     * First entry of the backend's PROOF_FAILED `errors` array, defaulting to
     * `proof_failed` when the array is missing, empty, non-array, or carries
     * a non-string first element. The empty-array case used to slip
     * `current([]) === false` past a `?? 'proof_failed'` null-only fallback
     * and crash mapProofErrorMessage() under strict_types.
     */
    private function extractProofSubCode(mixed $errors): string
    {
        $first = is_array($errors) && $errors !== [] ? current($errors) : null;

        return is_string($first) && $first !== '' ? $first : 'proof_failed';
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
