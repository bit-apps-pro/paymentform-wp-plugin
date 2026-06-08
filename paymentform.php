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

define('PAYMENTFORM_VERSION', '0.1.0');
define('PAYMENTFORM_PLUGIN_FILE', __FILE__);
define('PAYMENTFORM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYMENTFORM_PLUGIN_URL', plugin_dir_url(__FILE__));
defined('PAYMENTFORM_API_BASE_URL') || define('PAYMENTFORM_API_BASE_URL', 'https://api.paymentform.io');
defined('PAYMENTFORM_APP_BASE_URL') || define('PAYMENTFORM_APP_BASE_URL', 'https://app.paymentform.io');

if (!file_exists(PAYMENTFORM_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'PaymentForm: Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}

require_once PAYMENTFORM_PLUGIN_DIR . 'vendor/autoload.php';

// Register activation and deactivation hooks (before bootstrap)
// Uninstall hook is registered via uninstall.php instead
use PaymentForm\Activation\Activator;
use PaymentForm\Activation\Deactivator;

register_activation_hook(PAYMENTFORM_PLUGIN_FILE, [Activator::class, 'activate']);
register_deactivation_hook(PAYMENTFORM_PLUGIN_FILE, [Deactivator::class, 'deactivate']);

require_once PAYMENTFORM_PLUGIN_DIR . 'backend/bootstrap.php';
