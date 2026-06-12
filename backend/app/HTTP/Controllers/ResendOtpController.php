<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;
use PaymentForm\Providers\ServiceContainer;
use PaymentForm\Services\SetupApiClient;

/**
 * POST /setup/resend-otp — re-send the verification code for the deferred-token
 * setup flow.
 *
 * Bridges to backend `/auth/setup/resend-otp`, which owns the 60s per-user
 * cooldown (resending resets the attempt counter, so the cooldown is the
 * brute-force guard). The wp_setup_token is read from the per-admin transient
 * — same pattern as {@see VerifyOtpController} — and never exposed to JS.
 *
 * 2xx returns a bare array `{ ok, resend_after_seconds }` (the SPA's
 * `onSuccess`); any failure goes through {@see failure()} so the SPA shows
 * "Could not resend".
 *
 * @see \PaymentForm\HTTP\Controllers\VerifyOtpController
 */
final class ResendOtpController
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
            return $this->failure('unauthenticated', 401);
        }

        $wpSetupToken = get_transient($this->pendingTransientKey($userId));
        if (! is_string($wpSetupToken) || $wpSetupToken === '') {
            return $this->failure('setup_expired', 410);
        }

        $res = $this->setupApi->post(
            '/auth/setup/resend-otp',
            ['setup_token' => $wpSetupToken],
            self::TIMEOUT_SECONDS
        );

        if ($res->isError()) {
            return $this->failure('network', 503);
        }

        $status = $res->status;

        if ($status === 410) {
            delete_transient($this->pendingTransientKey($userId));

            return $this->failure('setup_expired', 410);
        }

        if ($status === 503) {
            return $this->failure('service_unavailable', 503);
        }

        if ($status < 200 || $status >= 300) {
            return $this->failure('resend_failed', $status ?: 502);
        }

        if ($res->jsonError) {
            return $this->failure('invalid_response', 502);
        }

        $body = $res->body;
        if (! array_key_exists('data', $body) || ! is_array($body['data'])) {
            return $this->failure('invalid_response', 502);
        }

        $data = $body['data'];

        return [
            'ok' => true,
            'resend_after_seconds' => (int) ($data['resend_after_seconds'] ?? 60),
        ];
    }

    /**
     * Wraps a failure in the wp-kit error envelope so the SPA's `onError`
     * handler fires. The `ok:false` body lets the SPA render "Could not
     * resend"; the code is mirrored onto the envelope.
     */
    private function failure(string $code, int $httpStatus): Response
    {
        return Response::error(['ok' => false], $httpStatus)
            ->code($code)
            ->message(null);
    }

    /**
     * Per-admin transient key holding the wp_setup_token. Duplicated from
     * {@see SetupController} (the source of truth) for the same one-line YAGNI
     * reason as {@see VerifyOtpController}.
     */
    private function pendingTransientKey(int $userId): string
    {
        return 'paymentform_setup_pending_' . $userId;
    }
}
