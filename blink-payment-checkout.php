<?php
/*
 * Plugin Name: Blink Payment Gateway for WooCommerce
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card and direct debit payments on your store.
 * Author: Blink Payment
 * Author URI: https://blinkpayment.co.uk/
 * Version: 1.1.0
 * Text Domain: blink-payment-checkout
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// WooCommerce fallback notice
function blink_wc_missing_notice()
{
    echo '<div class="error"><p><strong>' . sprintf(
            /* translators: %s is a link to the WooCommerce website. */
        esc_html__('Blink requires WooCommerce to be installed and active. You can download %s here.', 'blink-payment-checkout'),
        '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
    ) . '</strong></p></div>';
}

// Add settings link to plugin page
function blink_payment_plugin_action_links($actions, $plugin_file)
{
    static $plugin;
    if (!isset($plugin)) {
        $plugin = plugin_basename(__FILE__);
    }

    if ($plugin == $plugin_file) {
        $configs = include __DIR__ . '/config.php';
        $section = str_replace(' ', '', strtolower($configs['method_title']));
        $actions = array_merge(
            array('settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $section) . '">' . __('Settings', 'blink-payment-checkout') . '</a>'),
            $actions
        );
    }
    return $actions;
}

// Register the Blink Gateway class
function blink_add_gateway_class($gateways)
{
    $gateways[] = 'Blink_Payment_Gateway';
    return $gateways;
}

// Register block support
function blink_gateway_block_support()
{
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once __DIR__ . '/includes/class-blink-checkout-block.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Blink_Checkout_Block());
        }
    );
}

// Declare compatibility for checkout blocks
function blink_cart_checkout_blocks_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
}

// Initialize the gateway class
function blink_init_gateway_class()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'blink_wc_missing_notice');
        return;
    }

    include_once __DIR__ . '/includes/helper.php';
    include_once __DIR__ . '/includes/class-blink-payment-gateway.php';
    include_once __DIR__ . '/includes/class-blink-ajax-handler.php';
    include_once __DIR__ . '/includes/class-blink-3d-secure.php';
    include_once __DIR__ . '/includes/class-blink-payment-utils.php';
    require_once __DIR__ . '/includes/class-blink-settings-handler.php';
    require_once __DIR__ . '/includes/class-blink-payment-fields-handler.php';
    require_once __DIR__ . '/includes/class-blink-payment-handler.php';
    require_once __DIR__ . '/includes/class-blink-refund-handler.php';
    require_once __DIR__ . '/includes/class-blink-transaction-handler.php';

    add_filter('plugin_action_links', 'blink_payment_plugin_action_links', 10, 5);
    add_filter('woocommerce_payment_gateways', 'blink_add_gateway_class');
}

// Hook registrations
add_action('plugins_loaded', 'blink_init_gateway_class');
add_action('woocommerce_blocks_loaded', 'blink_gateway_block_support');
add_action('before_woocommerce_init', 'blink_cart_checkout_blocks_compatibility');
add_action('wp', ['Blink_Transaction_Handler', 'check_order_response'], 999);
