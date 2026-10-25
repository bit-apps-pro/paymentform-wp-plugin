<?php

declare(strict_types=1);

namespace PaymentForm\Enums;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Symbolic error codes emitted by the plugin's WP REST endpoints + bubbled
 * up from the upstream paymentform API. Keep values in UPPER_SNAKE so they
 * match the backend's `error_code` shape (Google / Zoho style) and stay
 * stable across translations / message changes.
 */
enum ErrorCode: string
{
    case API_ERROR = 'API_ERROR';
    case DISCONNECTED = 'DISCONNECTED';
    case EXCHANGE_FAILED = 'EXCHANGE_FAILED';
    case INVALID_FORM_ID = 'INVALID_FORM_ID';
    case INVALID_RESPONSE = 'INVALID_RESPONSE';
    case INVALID_STATE = 'INVALID_STATE';
    case MISSING_PARAMS = 'MISSING_PARAMS';
    case NOT_CONNECTED = 'NOT_CONNECTED';
    case REFRESH_FAILED = 'REFRESH_FAILED';
    case STORAGE_FAILED = 'STORAGE_FAILED';
    case UNAUTHORIZED = 'UNAUTHORIZED';
}
