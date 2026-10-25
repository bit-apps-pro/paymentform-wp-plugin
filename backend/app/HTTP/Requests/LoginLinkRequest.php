<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\HTTP\Requests\Rules\RedirectPathRule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates the POST /paymentform/v1/login-link payload (SSO login-link mint).
 *
 * Only an optional `redirect` is accepted; it must satisfy the §L1 allowlist
 * (see {@see RedirectPathRule}). user/tenant/client_type are never
 * client-supplied — the backend derives them from the bearer's AppConnection.
 * wp-kit's RouteRegister runs these rules before the action; auth is enforced
 * by the route's nonce + cap middleware, so no authorize() is defined here.
 *
 * @property null|string $redirect
 */
final class LoginLinkRequest extends Request
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'redirect' => ['nullable', 'string', 'max:512', RedirectPathRule::class],
        ];
    }
}
