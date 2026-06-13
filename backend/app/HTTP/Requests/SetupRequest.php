<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\HTTP\Requests\Rules\CountryCodeRule;

/**
 * Validates the POST /paymentform/v1/setup payload. These rules mirror the
 * hand-rolled input guards that previously lived inline in SetupController;
 * wp-kit's RouteRegister runs them automatically before the action when the
 * action type-hints this class. Auth is already enforced by the route's
 * nonce + cap middleware, so this request intentionally defines no authorize().
 *
 * @property string      $email
 * @property string      $country
 * @property null|string $locale
 * @property null|string $email_override
 */
final class SetupRequest extends Request
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:256'],
            'country' => ['required', CountryCodeRule::class],
            'locale' => ['nullable', 'string', 'size:5'],
            'email_override' => ['nullable', 'email', 'max:256'],
        ];
    }
}
