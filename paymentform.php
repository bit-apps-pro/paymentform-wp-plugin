<?php
/**
 * Plugin Name: PaymentForm
 * Plugin URI:  https://paymentform.io
 * Description: Embed payment forms from paymentform.io into WordPress pages and posts.
 * Version:     0.1.0
 * Author:      PaymentForm
 * Author URI:  https://paymentform.io
 * License:     GPL-2.0-or-later
 * Text Domain: paymentform
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) {
    exit;
}

$paymentform_plugin_dir = plugin_dir_path(__FILE__);

if (!file_exists($paymentform_plugin_dir . 'vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'PaymentForm: Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}

require_once $paymentform_plugin_dir . 'vendor/autoload.php';

use PaymentForm\Activation\Activator;
use PaymentForm\Activation\Deactivator;
use PaymentForm\Config;

Config::bootstrap(__FILE__);

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

require_once $paymentform_plugin_dir . 'backend/bootstrap.php';
