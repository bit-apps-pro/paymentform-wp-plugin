<?php

declare(strict_types=1);

namespace PaymentForm\HTTP\Requests;

use PaymentForm\Deps\BitApps\WPKit\Http\Request\Request;
use PaymentForm\HTTP\Requests\Rules\UuidRule;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates the {id} route param for GET /paymentform/v1/forms/{id}. Backend
 * form ids are UUIDs; the previous numeric guard in FormsProxyController was a
 * latent bug that would have rejected every real id. wp-kit runs this before
 * the action. Auth is enforced by the route middleware, so no authorize() here.
 *
 * @property string $id
 */
final class FormShowRequest extends Request
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', UuidRule::class],
        ];
    }
}
