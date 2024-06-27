<?php
/*
 * Plugin Name: WooCommerce - Blink
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card and direct debit payments on your store.
 * Author: Blink Payment
 * Author URI: https://blinkpayment.co.uk/
 * Version: 1.0.2
*/
/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
*/
function blink_add_gateway_class( $gateways ) { 
	$gateways[] = 'WC_Blink_Gateway';
	return $gateways;
}
/*
 * The class itself, please note that it is inside plugins_loaded action hook
*/
add_action('plugins_loaded', 'blink_init_gateway_class');
add_action('the_content', 'blink_3d_form_submission');
add_action('init', 'checkFromSubmission');
add_action('parse_request', 'update_order_response', 99);
add_action('wp', 'check_order_response', 999);
add_filter('http_request_timeout', 'timeout_extend', 99);
add_action('wp_ajax_cancel_transaction', 'blink_cancel_transaction');
add_action( 'template_redirect', 'handle_payorder_request' );
add_action( 'before_woocommerce_init', 'cart_checkout_blocks_compatibility' );
add_action( 'wp_ajax_generate_access_token', 'generate_access_token' );
add_action( 'wp_ajax_generate_applepay_domains', 'generate_applepay_domains' );

function cart_checkout_blocks_compatibility() {

    if( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				false // true (compatible, default) or false (not compatible)
			);
    }
		
}

function handle_payorder_request() {
    if ( isset( $_GET['pay_for_order'] ) && $_GET['pay_for_order'] == 'true') {
        $order_id = get_query_var('order-pay');
        add_order_items_to_cart_again( $order_id );

        // Redirect to the checkout page
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }
}

function add_order_items_to_cart_again( $order_id ) {
    if ( ! $order_id ) return;

    $order = wc_get_order( $order_id );

    if ( ! $order ) return;

    foreach ( $order->get_items() as $item_id => $item ) {
        $product_id = $item->get_product_id();
        $quantity = $item->get_quantity();

        WC()->cart->add_to_cart( $product_id, $quantity );
    }
}


function blink_cancel_transaction() {

	if(!check_ajax_referer('cancel_order_nonce', 'cancel_order'))
	{
		wp_send_json_error('[Security mismatch]');
	}

	$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

	if (!$order_id) {
		wp_send_json_error('Invalid order ID.');
	}

	$transaction_id = get_post_meta($order_id, 'blink_res', true);

	if (!$transaction_id) {
		wp_send_json_error('Transaction ID not found.');
	}

	$gateWay = new WC_Blink_Gateway();
	// Call cancel API
	$data = $gateWay->cancel_transaction($transaction_id);
	$success  = isset($data['success']) ? $data['success'] : false;
	$order = wc_get_order($order_id);

	if ($success) {
		// Cancel WooCommerce order
		$order->update_status('cancelled');
		$order->add_order_note('Transaction cancelled successfully.');

		//wc_add_notice('Transaction cancelled successfully: ' . $transaction_id, 'error');
		wp_send_json_success('Transaction cancelled successfully.');
	} else {
		$order->add_order_note('Failed to cancel transaction: ['.$data['message'].']');
		wp_send_json_error('['.$data['message'].']');
	}
}

function timeout_extend( $time ) { 
	// Default timeout is 5
	return 10;
}
function check_order_response( $wp ) { 
	global $wp;
	$order_id = 0;
	$order = !empty($_GET['order']) ? sanitize_text_field($_GET['order']) : '';

	if (isset($wp->query_vars['order-received']) && '' !== $wp->query_vars['order-received']) {
		$order_id = apply_filters('woocommerce_thankyou_order_id', absint($wp->query_vars['order-received']));
	} else {
		// Check if the order ID exists.
		if ( !empty($order) ) {
			$order_id = absint(apply_filters('woocommerce_thankyou_order_id', absint($order)));
		}
	}
	if ($order_id) {
		$gateWay = new WC_Blink_Gateway();
		$gateWay->accessToken = $gateWay->generate_access_token();
		$gateWay->check_response_for_order($order_id);
	}
	return $wp;
}
function update_order_response( $wp ) { 
	$transaction_id = !empty($_REQUEST['transaction_id']) ? sanitize_text_field($_REQUEST['transaction_id']) : '';
	if (isset($wp->query_vars['order-received']) && '' !== $wp->query_vars['order-received']) {
		$order_id = apply_filters('woocommerce_thankyou_order_id', absint($wp->query_vars['order-received']));
		if (empty($transaction_id)) {
			return;
		}
		$transaction = wc_clean(wp_unslash($transaction_id));
		$wc_order = wc_get_order($order_id);
		$wc_order->update_meta_data('blink_res', $transaction);
		$wc_order->update_meta_data('_blink_res_expired', 'false');
		$wc_order->save();
	}
	return $wp;
}
function checkOrderPayment( $order_id ) { 
	$order = wc_get_order($order_id);
	if (!$order->needs_payment()) {
		wc_add_notice('Something Wrong! Please initate the payment from checkout page', 'error');
		wp_redirect(wc_get_checkout_url());
	}
	return $order;
}
function checkFromSubmission() { 
	$wpnonce = !empty($_POST['_wpnonce']) ? sanitize_text_field($_POST['_wpnonce']) : '';
	if ( isset( $wpnonce ) && wp_verify_nonce( $wpnonce, 'submit-payment' ) ) {
		$action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
		if (!empty($action) && 'blinkSubmitPayment' == $action) {
			$gateWay = new WC_Blink_Gateway();
			$accessToken = isset( $_POST['access_token'] ) ? sanitize_text_field($_POST['access_token']) : '';
			$gateWay->accessToken = !empty($accessToken) ? $accessToken : $gateWay->generate_access_token();
			$request = $_POST;
			$order_id = sanitize_text_field($request['order_id']);
			$order = checkOrderPayment($order_id);
			if ( 'credit-card' == $request['payment_by'] ) {
				$gateWay->processCreditCard($order_id, $request);
			}
			if ('direct-debit' == $request['payment_by'] ) {
				$gateWay->processDirectDebit($order_id, $request);
			}
			if ('open-banking' == $request['payment_by'] ) {
				$gateWay->processOpenBanking($order_id, $request);
			}
		}
	} 
}
function blink_3d_form_submission( $content ) { 
	$blink3dprocess = isset($_GET['blink3dprocess']) ? sanitize_text_field($_GET['blink3dprocess']) : '';
	if (!empty($blink3dprocess)) {
		$token = get_transient('blink3dProcess' . $blink3dprocess);
		$html = '<div class="blink-loading">Loading&#8230;</div><div class="3d-content">' . $token . '</div>';
		$script = '<script nonce="2020">
		jQuery(document).ready(function(){
	
			jQuery(\'#form3ds22\').submit();
			setTimeout(function(){jQuery(\'#btnSubmit\').val(\'Please Wait...\');},100);
		});
	</script>';
		return $html . $script;
	}
	return $content;
}
/**
 * WooCommerce fallback notice.
 */
function wc_blink_missing_notice() { 
	/* translators: 1. URL link. */
	echo '<div class="error"><p><strong>' . sprintf(esc_html__('Blink requires WooCommerce to be installed and active. You can download %s here.', 'blink'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}
function add_wc_blink_payment_action_plugin( $actions, $plugin_file ) { 
	static $plugin;
	if (!isset($plugin)) {
		$plugin = plugin_basename(__FILE__);
	}
	if ($plugin == $plugin_file) {
		$configs = include dirname(__FILE__) . '/config.php';
		$section = str_replace(' ', '', strtolower($configs['method_title']));
		$actions = array_merge(['settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $section) . '">' . __('Settings', 'General') . '</a>', ], $actions);
	}
	return $actions;
}
function blink_init_gateway_class() { 
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'wc_blink_missing_notice');
		return;
	}
	add_filter('plugin_action_links', 'add_wc_blink_payment_action_plugin', 10, 5);
	include dirname(__FILE__) . '/includes/helper.php';
	include dirname(__FILE__) . '/includes/wc-blink-gateway-class.php';
	add_filter('woocommerce_payment_gateways', 'blink_add_gateway_class');
}