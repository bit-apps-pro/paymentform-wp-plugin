<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;

/**
 * POST /setup/resend-otp — the backend already dedups the verification
 * notification on a fresh /setup call, so until a dedicated backend resend
 * endpoint lands we hand the user a 200/no-op plus a countdown.
 * Zero collaborators by design.
 *
 * @see \PaymentForm\HTTP\Controllers\VerifyOtpController
 */
final class ResendOtpController
{
    /**
     * @return array{ok: bool, resend_after_seconds: int}
     */
    public function handle(Request $request): array
    {
        return ['ok' => true, 'resend_after_seconds' => 60];
    }
}
