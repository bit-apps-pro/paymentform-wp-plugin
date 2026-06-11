<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Controllers;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\Deps\BitApps\WPKit\Http\Response;

/**
 * POST /setup/verify-otp — the wizard's manual code-entry fallback.
 *
 * Not implemented yet; the wizard falls back to polling /setup/status when
 * the user clicks the email link instead. The 501 keeps the SPA behaviour
 * unchanged (WizardCheckInboxStep branches on `error.status === 501`).
 * Zero collaborators by design.
 */
final class VerifyOtpController
{
    public function handle(Request $request): Response
    {
        return Response::error(['verified' => false, 'error' => 'not_implemented'], 501)
            ->code('NOT_IMPLEMENTED');
    }
}
