<?php
/*
 * Plugin Name: WooCommerce - Blink
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card and direct debit payments on your store.
 * Author: Blink Payment
 * Author URI: https://blinkpayment.co.uk/
 * Version: 1.1.0
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
add_action( 'plugins_loaded', 'blink_init_gateway_class' );
add_action( 'wp_footer', 'blink_3d_form_submission' );
add_action( 'wp', 'check_order_response', 999 );
add_filter( 'http_request_timeout', 'timeout_extend', 99 );
add_action( 'wp_ajax_cancel_transaction', 'blink_cancel_transaction' );
add_action( 'before_woocommerce_init', 'cart_checkout_blocks_compatibility' );
add_action( 'wp_ajax_generate_access_token', 'generate_access_token' );
add_action( 'wp_ajax_generate_applepay_domains', 'generate_applepay_domains' );
add_action( 'wp_ajax_blink_payment_fields', 'blink_payment_fields_ajax' );
add_action( 'wp_ajax_nopriv_blink_payment_fields', 'blink_payment_fields_ajax' );
add_action( 'woocommerce_blocks_loaded', 'gateway_block_support' );
add_action( 'before_woocommerce_init', 'cart_checkout_blocks_compatibility' );


function gateway_block_support() {

	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	// here we're including our "gateway block support class"
	require_once __DIR__ . '/includes/class-wc-blink-gateway-block.php';

	// registering the PHP class we have just included
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new WC_Blink_Gateway_Block() );
		}
	);
}
function cart_checkout_blocks_compatibility() {

	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
}
function blink_payment_fields_ajax() {
	// Make sure WooCommerce is available
	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		$gateway = new WC_Blink_Gateway();
		ob_start();
		$gateway->destroy_session_intent();
		$gateway->payment_fields();
		$payment_fields_html = ob_get_clean();

		wp_send_json_success(
			array(
				'html' => $payment_fields_html,
			)
		);
	} else {
		wp_send_json_error( 'Payment gateway not found' );
	}
}

function blink_cancel_transaction() {

	if ( ! check_ajax_referer( 'cancel_order_nonce', 'cancel_order' ) ) {
		wp_send_json_error( '[Security mismatch]' );
	}

	$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;

	if ( ! $order_id ) {
		wp_send_json_error( 'Invalid order ID.' );
	}

	$transaction_id = get_post_meta( $order_id, 'blink_res', true );

	if ( ! $transaction_id ) {
		wp_send_json_error( 'Transaction ID not found.' );
	}

	$gateWay = new WC_Blink_Gateway();
	// Call cancel API
	$data    = $gateWay->cancel_transaction( $transaction_id );
	$success = isset( $data['success'] ) ? $data['success'] : false;
	$order   = wc_get_order( $order_id );

	if ( $success ) {
		// Cancel WooCommerce order
		$order->update_status( 'cancelled' );
		$order->add_order_note( 'Transaction cancelled successfully.' );

		// wc_add_notice('Transaction cancelled successfully: ' . $transaction_id, 'error');
		wp_send_json_success( 'Transaction cancelled successfully.' );
	} else {
		$order->add_order_note( 'Failed to cancel transaction: [' . $data['message'] . ']' );
		wp_send_json_error( '[' . $data['message'] . ']' );
	}
}

function timeout_extend( $time ) {
	// Default timeout is 5
	return 10;
}
function check_order_response() {

	global $wp;

	$transaction_id = ! empty( $_REQUEST['transaction_id'] ) ? sanitize_text_field( $_REQUEST['transaction_id'] ) : '';
	if ( empty( $transaction_id ) ) {
		return;
	}

	if ( ! empty( $wp->query_vars['order-received'] ) ) {
		$order_id    = apply_filters( 'woocommerce_thankyou_order_id', absint( $wp->query_vars['order-received'] ) );
		$transaction = wc_clean( wp_unslash( $transaction_id ) );
		$wc_order    = wc_get_order( $order_id );
		$wc_order->update_meta_data( 'blink_res', $transaction );
		$wc_order->update_meta_data( '_blink_res_expired', 'false' );
		$wc_order->save();
		$payment_method_id  = $wc_order->get_payment_method();
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method     = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : false;

		if ( $payment_method && $payment_method_id === 'blink' ) {
			$payment_method->check_response_for_order( $order_id );
		}
	}
}


function blink_3d_form_submission() {
	$blink3dprocess = isset( $_GET['blink3dprocess'] ) ? sanitize_text_field( $_GET['blink3dprocess'] ) : '';

	if ( ! empty( $blink3dprocess ) ) {
		$token = get_transient( 'blink3dProcess' . $blink3dprocess );
		?>
		<div class="blink-loading">Loading&#8230;</div>
		<div class="3d-content"><?php echo $token; ?></div>
		<script nonce="2020">
			jQuery(document).ready(function(){
				jQuery('#form3ds22').submit();
				setTimeout(function() {
					jQuery('#btnSubmit').val('Please Wait...');
				}, 100);
			});
		</script>
		<?php
	}
}

/**
 * WooCommerce fallback notice.
 */
function wc_blink_missing_notice() {
	/* translators: 1. URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Blink requires WooCommerce to be installed and active. You can download %s here.', 'blink' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}
function add_wc_blink_payment_action_plugin( $actions, $plugin_file ) {
	static $plugin;
	if ( ! isset( $plugin ) ) {
		$plugin = plugin_basename( __FILE__ );
	}
	if ( $plugin == $plugin_file ) {
		$configs = include __DIR__ . '/config.php';
		$section = str_replace( ' ', '', strtolower( $configs['method_title'] ) );
		$actions = array_merge( array( 'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section ) . '">' . __( 'Settings', 'General' ) . '</a>' ), $actions );
	}
	return $actions;
}
function blink_init_gateway_class() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_blink_missing_notice' );
		return;
	}
	add_filter( 'plugin_action_links', 'add_wc_blink_payment_action_plugin', 10, 5 );
	include __DIR__ . '/includes/helper.php';
	include __DIR__ . '/includes/wc-blink-gateway-class.php';
	add_filter( 'woocommerce_payment_gateways', 'blink_add_gateway_class' );
}

function pay_action() {
	$checkout = WC_Checkout::instance();
	$response = $checkout->process_checkout();
	$result   = json_decode( $response, true );
	if ( $result['result'] === 'failure' ) {
		wp_redirect( trailingslashit( wc_get_checkout_url() ) );
	}
}
