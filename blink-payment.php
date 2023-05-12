<?php
/*
 * Plugin Name: WooCommerce - Blink
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card and direct debit payments on your store.
 * Author: Blink Payment
 * Author URI: https://blinkpayment.co.uk/
 * Version: 1.0.9
*/
/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
*/
function blink_add_gateway_class($gateways) { 
	$gateways[] = 'WC_Blink_Gateway'; // your class name is here
	return $gateways;
}
/*
 * The class itself, please note that it is inside plugins_loaded action hook
*/
add_action('plugins_loaded', 'blink_init_gateway_class');
add_action('the_content', 'blink_3d_form_submission');
add_action('the_content', 'checkBlinkPaymentMethod');
add_action('init', 'checkFromSubmission');
add_action('parse_request', 'update_order_response', 99);
add_action('wp', 'check_order_response', 999);
add_filter('http_request_timeout', 'timeout_extend', 99);
function timeout_extend($time) { 
	// Default timeout is 5
	return 10;
}
function check_order_response($wp) { 
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
function update_order_response($wp) { 
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
function checkOrderPayment($order_id) { 
	$order = wc_get_order($order_id);
	if (!$order->needs_payment()) {
		wc_add_notice('Something Wrong! Please initate the payment from checkout page', 'error');
		wp_redirect(wc_get_checkout_url());
	}
	return $order;
}
function checkFromSubmission() { 
	if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'submit-payment' ) ) {
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
function checkBlinkPaymentMethod($content) { 
	$blinkPay = isset($_GET['blinkPay']) ? sanitize_text_field($_GET['blinkPay']) : '';
	$method = isset($_GET['p']) ? sanitize_text_field($_GET['p']) : '';
	if (!empty($blinkPay)) {
		checkOrderPayment($blinkPay);
		$gateWay = new WC_Blink_Gateway();
		if (!empty($method) && in_array($method, $gateWay->paymentMethods)) {
			$gateWay->accessToken = $gateWay->generate_access_token();
			$gateWay->paymentIntent = $gateWay->create_payment_intent();
			if (isset($gateWay->paymentIntent['payment_intent'])) {
				$string = implode(' ', array_map('ucfirst', explode('-', $method)));
				$html = wc_print_notices();
				$html.= '<section class="blink-api-section">
							<div class="blink-api-form-stracture">
								<h2 class="heading-text">Pay with ' . $string . '</h2>
								<section class="blink-api-tabs-content">';
				if ('credit-card' == $method && $gateWay->paymentIntent['element']['ccElement']) {
					$html.= '<div id="tab1" class="tab-contents active">
											<form name="blink-card" id="blink-card" method="POST" action="">
												' . $gateWay->paymentIntent['element']['ccElement'];
				}
				if ('direct-debit' == $method && $gateWay->paymentIntent['element']['ddElement']) {
					$html.= '<div id="tab1" class="tab-contents active">
											<form name="blink-debit" id="blink-debit" method="POST" action="">
												' . $gateWay->paymentIntent['element']['ddElement'];
				}
				if ('open-banking' == $method && $gateWay->paymentIntent['element']['obElement']) {
					$html.= '<div id="tab1" class="tab-contents active">
											<form name="blink-open" id="blink-open" method="POST" action="">
												' . $gateWay->paymentIntent['element']['obElement'];
				}
				$html.= '<input type="hidden" name="transaction_unique" value="' . $gateWay->paymentIntent['transaction_unique'] . '">
												<input type="hidden" name="amount" value="' . $gateWay->paymentIntent['amount'] . '">
												<input type="hidden" name="intent_id" value="' . $gateWay->paymentIntent['id'] . '">
												<input type="hidden" name="intent" value="' . $gateWay->paymentIntent['payment_intent'] . '">
												<input type="hidden" name="access_token" value="' . $gateWay->accessToken . '">
												<input type="hidden" name="payment_by" id="payment_by" value="' . $method . '">
												<input type="hidden" name="action" value="blinkSubmitPayment">
												<input type="hidden" name="order_id" value="' . $blinkPay . '">
												'.wp_nonce_field( 'submit-payment' ).'
												<input type="submit" value="Pay now" name="blink-submit" />
											</form>
										</div>';
				$html.= '</section>
							</div>
						</section>';
				return $html;
			} else {
				wc_add_notice($gateWay->paymentIntent['error'] ? $gateWay->paymentIntent['error'] : 'Something Wrong! Please initate the payment from checkout page', 'error');
				wp_redirect(wc_get_checkout_url());
			}
		}
	}
	return $content;
}
function blink_3d_form_submission($content) { 
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
function add_wc_blink_payment_action_plugin($actions, $plugin_file) { 
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
