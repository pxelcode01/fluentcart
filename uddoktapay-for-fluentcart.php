<?php
/**
 * Plugin Name: UddoktaPay for FluentCart
 * Description: Accept bKash, Nagad, Rocket, Upay and other Bangladeshi mobile banking payments in FluentCart via UddoktaPay.
 * Version: 1.0.0
 * Author: pxelCode
 * Text Domain: uddoktapay-for-fluentcart
 * Requires Plugins: fluent-cart
 */

if (!defined('ABSPATH')) {
    exit;
}

define('UDDOKTAPAY_FC_VERSION', '1.0.0');
define('UDDOKTAPAY_FC_FILE', __FILE__);
define('UDDOKTAPAY_FC_DIR', plugin_dir_path(__FILE__));
define('UDDOKTAPAY_FC_URL', plugin_dir_url(__FILE__));
define('UDDOKTAPAY_FC_SLUG', 'uddoktapay');

function uddoktapay_fc_load_classes()
{
    static $loaded = false;
    if ($loaded) {
        return true;
    }
    if (!class_exists('FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway')) {
        return false;
    }

    require_once UDDOKTAPAY_FC_DIR . 'includes/class-uddoktapay-settings.php';
    require_once UDDOKTAPAY_FC_DIR . 'includes/class-uddoktapay-api.php';
    require_once UDDOKTAPAY_FC_DIR . 'includes/class-uddoktapay-gateway.php';

    $loaded = true;
    return true;
}

/**
 * Register the gateway with FluentCart once FluentCart's own
 * registration hook fires. This is the recommended hook per
 * FluentCart's payment gateway integration docs.
 *
 * IMPORTANT: our gateway/settings classes extend FluentCart base
 * classes (AbstractPaymentGateway, BaseGatewaySettings). WordPress
 * does not guarantee plugin load order, so we must not require_once
 * those files until we're inside a hook that only fires after
 * FluentCart itself has fully loaded — otherwise PHP throws a fatal
 * "Class not found" error the moment our files are parsed.
 */
add_action('fluent_cart/register_payment_methods', function () {
    if (!function_exists('fluent_cart_api')) {
        return;
    }
    if (!uddoktapay_fc_load_classes()) {
        return;
    }

    fluent_cart_api()->registerCustomPaymentMethod(
        UDDOKTAPAY_FC_SLUG,
        new UddoktaPay_FC_Gateway()
    );
});

/**
 * Register the return-URL confirm handler independently of the
 * gateway's boot() method, on plugins_loaded (fires on every
 * request, including the admin-ajax.php hit UddoktaPay redirects
 * back to) - so it doesn't depend on FluentCart definitely calling
 * boot() on the gateway instance.
 */
add_action('plugins_loaded', function () {
    if (!uddoktapay_fc_load_classes()) {
        return;
    }

    $confirm = function () {
        (new UddoktaPay_FC_Gateway())->confirmPayment();
    };

    add_action('wp_ajax_uddoktapay_fc_confirm', $confirm);
    add_action('wp_ajax_nopriv_uddoktapay_fc_confirm', $confirm);
});

/**
 * Friendly admin notice if FluentCart isn't active.
 */
add_action('admin_notices', function () {
    if (function_exists('fluent_cart_api')) {
        return;
    }
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-warning"><p>'
        . esc_html__('UddoktaPay for FluentCart requires the FluentCart plugin to be installed and active.', 'uddoktapay-for-fluentcart')
        . '</p></div>';
});
