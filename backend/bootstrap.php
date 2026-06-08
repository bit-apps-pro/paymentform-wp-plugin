<?php

declare(strict_types=1);

namespace PaymentForm;

use PaymentForm\Providers\PluginServiceProvider;
use PaymentForm\Providers\ServiceContainer;

if (!defined('ABSPATH')) {
    exit;
}

// Define base URL constant if not set
if (!defined('PAYMENTFORM_API_BASE_URL')) {
    define('PAYMENTFORM_API_BASE_URL', 'https://app.paymentform.io');
}

$container = ServiceContainer::instance();
$provider  = new PluginServiceProvider();
$provider->register($container);

add_action('plugins_loaded', static function () use ($provider) {
    $provider->boot();
});
