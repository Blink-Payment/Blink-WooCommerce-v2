<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Ajax_Handler {

	public static function init() {
		add_action( 'wp_ajax_blink_payment_fields', array( __CLASS__, 'blink_payment_fields_ajax' ) );
		add_action( 'wp_ajax_nopriv_blink_payment_fields', array( __CLASS__, 'blink_payment_fields_ajax' ) );
		add_action( 'wp_ajax_blink_generate_access_token', 'blink_generate_access_token' );
		add_action( 'wp_ajax_blink_generate_applepay_domains', 'blink_generate_applepay_domains' );
		add_action( 'wp_ajax_cancel_transaction', array( __CLASS__, 'blink_cancel_transaction' ) );
	}

	public static function blink_cancel_transaction() {
		if ( ! check_ajax_referer( 'cancel_order_nonce', 'cancel_order' ) ) {
			wp_send_json_error( __( 'Security mismatch', 'blink-payment-checkout' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'blink-payment-checkout' ) );
		}

		$transaction_id = get_post_meta( $order_id, 'blink_res', true );

		if ( ! $transaction_id ) {
			wp_send_json_error( __( 'Transaction ID not found.', 'blink-payment-checkout' ) );
		}

		$gateWay = new Blink_Payment_Gateway();
		// Call cancel API
		$data    = $gateWay->transaction_handler->cancel_transaction( $transaction_id );
		$success = isset( $data['success'] ) ? $data['success'] : false;
		$order   = wc_get_order( $order_id );

		if ( $success ) {
			// Cancel WooCommerce order
			$order->update_status( 'cancelled' );
			$order->add_order_note( __( 'Transaction cancelled successfully.', 'blink-payment-checkout' ) );

			wp_send_json_success( __( 'Transaction cancelled successfully.', 'blink-payment-checkout' ) );
		} else {
			/* translators: %s is the error message returned by the API. */
			$order->add_order_note( sprintf( __( 'Failed to cancel transaction: [%s]', 'blink-payment-checkout' ), $data['message'] ) );
			/* translators: %s is the error message returned by the API. */
			wp_send_json_error( sprintf( __( '[%s]', 'blink-payment-checkout' ), $data['message'] ) );
		}
	}

	public static function blink_payment_fields_ajax() {
		// Make sure WooCommerce is available
		if ( class_exists( 'Blink_Payment_Gateway' ) ) {
			$gateway = new Blink_Payment_Gateway();
			ob_start();
			$gateway->utils->destroy_session_intent();
			$gateway->payment_fields();
			$payment_fields_html = ob_get_clean();

			wp_send_json_success( array( 'html' => $payment_fields_html ) );
		} else {
			wp_send_json_error( __( 'Payment gateway not found', 'blink-payment-checkout' ) );
		}
	}
}

Blink_Ajax_Handler::init();
