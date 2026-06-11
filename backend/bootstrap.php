<?php

declare(strict_types=1);

namespace PaymentForm;

if (!defined('ABSPATH')) {
    exit;
}

// Define base URL constants if not set
if (!defined('PAYMENTFORM_API_BASE_URL')) {
    define('PAYMENTFORM_API_BASE_URL', 'https://api.paymentform.io');
}
if (!defined('PAYMENTFORM_APP_BASE_URL')) {
    define('PAYMENTFORM_APP_BASE_URL', 'https://app.paymentform.io');
}

Plugin::load();
