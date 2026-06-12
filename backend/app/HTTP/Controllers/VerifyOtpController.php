<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\SetupApiClient;

/**
 * POST /setup/verify-otp — the wizard's manual code-entry fallback for the
 * deferred-token setup flow.
 *
 * The SPA sends only `{ otp }`; the wp_setup_token never crosses the wire to
 * JS. We read it from the per-admin transient (same key {@see SetupController}
 * writes on check_inbox) and bridge to backend `/auth/setup/verify-otp`.
 *
 * Transport mirrors {@see SetupController::status}: 2xx returns a bare array
 * (wp-kit success envelope → SPA `onSuccess`); structural failures go through
 * {@see failure()} so the SPA's `onError` handler fires. A wrong/expended code
 * is NOT a structural failure — the backend reports it as HTTP 200
 * `{ verified: false, attempts_remaining }`, which we surface as a 200 with a
 * human message in the existing `onSuccess` branch.
 */
final class VerifyOtpController
{
    private const TIMEOUT_SECONDS = 30;

    private readonly SetupApiClient $setupApi;

    public function __construct()
    {
        $this->setupApi = ServiceContainer::get(SetupApiClient::class);
    }

    /**
     * @return array<string, mixed>|Response
     */
    public function handle(Request $req): array|Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return $this->failure(['verified' => false, 'error' => 'unauthenticated'], 401);
        }

        $otp = (string) $req->get('otp', '');
        if (! preg_match('/^\d{6}$/', $otp)) {
            return $this->failure(['verified' => false, 'error' => 'bad_request'], 400);
        }

        $wpSetupToken = get_transient($this->pendingTransientKey($userId));
        if (! is_string($wpSetupToken) || $wpSetupToken === '') {
            return $this->failure(['verified' => false, 'error' => 'setup_expired'], 410);
        }

        $res = $this->setupApi->post(
            '/auth/setup/verify-otp',
            ['setup_token' => $wpSetupToken, 'code' => $otp],
            self::TIMEOUT_SECONDS
        );

        if ($res->isError()) {
            return $this->failure(['verified' => false, 'error' => 'network'], 503);
        }

        $status = $res->status;

        // Backend dropped the pending row (expired/replayed) — drop the
        // transient too so a retry doesn't hammer the dead key.
        if ($status === 410) {
            delete_transient($this->pendingTransientKey($userId));

            return $this->failure(['verified' => false, 'error' => 'setup_expired'], 410);
        }

        if ($status === 503) {
            return $this->failure(['verified' => false, 'error' => 'service_unavailable'], 503);
        }

        if ($status < 200 || $status >= 300) {
            // Passthrough of the backend's own status (degenerate $status === 0
            // → 502 Bad Gateway rather than a misleading client error).
            return $this->failure(['verified' => false, 'error' => 'verify_failed'], $status ?: 502);
        }

        if ($res->jsonError) {
            return $this->failure(['verified' => false, 'error' => 'invalid_response'], 502);
        }

        $body = $res->body;
        if (! array_key_exists('data', $body) || ! is_array($body['data'])) {
            return $this->failure(['verified' => false, 'error' => 'invalid_response'], 502);
        }

        $data = $body['data'];

        if (($data['verified'] ?? false) === true) {
            return ['verified' => true];
        }

        return ['verified' => false, 'error' => $this->buildAttemptsMessage($data)];
    }

    /**
     * Render the human-facing error for a wrong/expended code from the
     * backend's `attempts_remaining`. Zero remaining is terminal; otherwise we
     * tell the user how many tries are left.
     *
     * @param array<string, mixed> $data backend `data` payload
     */
    private function buildAttemptsMessage(array $data): string
    {
        $remaining = (int) ($data['attempts_remaining'] ?? 0);

        if ($remaining <= 0) {
            return __('Too many failed attempts. Request a new code.', 'paymentform');
        }

        /* translators: %d: number of verification attempts left. */
        return sprintf(__('Invalid code. %d attempts remaining.', 'paymentform'), $remaining);
    }

    /**
     * Wraps an error body in the wp-kit error envelope so the SPA's `onError`
     * handler fires. Mirrors {@see SetupController::failure}.
     *
     * @param array<string, mixed> $body must carry a string `error` code
     */
    private function failure(array $body, int $httpStatus): Response
    {
        return Response::error($body, $httpStatus)
            ->code((string) $body['error'])
            ->message(null);
    }

    /**
     * Per-admin transient key holding the wp_setup_token between the initial
     * /setup call and OTP entry. Duplicated from {@see SetupController} (the
     * source of truth) rather than extracted to a service — it is a single
     * line and a shared helper would be over-engineering (YAGNI).
     */
    private function pendingTransientKey(int $userId): string
    {
        return 'paymentform_setup_pending_' . $userId;
    }
}
