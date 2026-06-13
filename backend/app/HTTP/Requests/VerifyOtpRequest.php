<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;

/**
 * Validates the POST /paymentform/v1/setup/verify-otp payload. Mirrors the
 * six-digit guard that previously lived inline in VerifyOtpController; wp-kit
 * runs it before the action. Auth is enforced by the route middleware, so no
 * authorize() is defined here.
 *
 * @property string $otp
 */
final class VerifyOtpRequest extends Request
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'digits:6'],
        ];
    }
}
