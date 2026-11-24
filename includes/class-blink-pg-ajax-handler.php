<?php
/**
 * AJAX endpoints for the Blink gateway.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and handles the gateway's admin and checkout AJAX actions.
 */
class Blink_PG_Ajax_Handler {

	/**
	 * Register the AJAX actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_blink_pg_payment_fields', array( __CLASS__, 'blink_payment_fields_ajax' ) );
		add_action( 'wp_ajax_nopriv_blink_pg_payment_fields', array( __CLASS__, 'blink_payment_fields_ajax' ) );
		add_action( 'wp_ajax_blink_pg_generate_access_token', 'blink_pg_generate_access_token' );
		add_action( 'wp_ajax_blink_pg_generate_applepay_domains', 'blink_pg_generate_applepay_domains' );
		add_action( 'wp_ajax_blink_pg_cancel_transaction', array( __CLASS__, 'blink_cancel_transaction' ) );
	}

	/**
	 * Cancel an order's Blink transaction from the admin and mark the order cancelled.
	 *
	 * Sends a JSON success or error response.
	 *
	 * @return void
	 */
	public static function blink_cancel_transaction() {
		if ( ! check_ajax_referer( 'cancel_order_nonce', 'cancel_order' ) ) {
			wp_send_json_error( __( 'Security mismatch', 'blink-payment-gateway-for-woocommerce' ) );
		}
		Blink_PG_Logger::log(
			'blink_cancel_transaction AJAX called',
			array( 'order_id' => isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0 )
		);

		// Check user permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
		Blink_PG_Logger::set_context( array( 'order_id' => $order_id ) );

		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order ID.', 'blink-payment-gateway-for-woocommerce' ) );
		}
		$transaction_id = blink_pg_get_charge_transaction_id( $order );

		if ( ! $transaction_id ) {
			wp_send_json_error( __( 'Transaction ID not found.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$gate_way = new Blink_PG_Payment_Gateway();
		$result   = self::blink_cancel_order_transaction( $order, $transaction_id, $gate_way->transaction_handler );

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		}
		wp_send_json_error( $result['message'] );
	}

	/**
	 * Cancel an order's Blink transaction, then update the order to match.
	 *
	 * When the cancel request gets no answer, Blink may still have cancelled the
	 * payment, so the transaction's status is checked before anything is written.
	 *
	 * @param WC_Order                     $order               WooCommerce order.
	 * @param string                       $transaction_id      Blink transaction ID.
	 * @param Blink_PG_Transaction_Handler $transaction_handler Handler that talks to Blink.
	 * @return array{success: bool, message: string} Result to show the admin.
	 */
	public static function blink_cancel_order_transaction( $order, $transaction_id, $transaction_handler ) {
		$order_id = $order->get_id();
		$data     = $transaction_handler->blink_cancel_transaction( $transaction_id );

		if ( ! $data['success'] && $data['unknown'] ) {
			$status = strtolower( (string) $transaction_handler->blink_get_transaction_status( $transaction_id, $order ) );
			Blink_PG_Logger::log(
				'blink_cancel_transaction no response, checked status',
				array(
					'order_id'       => $order_id,
					'transaction_id' => $transaction_id,
					'message'        => $data['message'],
					'status'         => $status,
				)
			);

			if ( in_array( $status, array( 'canceled', 'cancelled' ), true ) ) {
				$data['success'] = true;
			} else {
				$message = sprintf(
					/* translators: %s is the HTTP error, e.g. a timeout. */
					__( 'Blink did not confirm the cancel (%s). Check the transaction in the Blink portal: if it shows Canceled, change this order to Cancelled; if not, try the cancel again.', 'blink-payment-gateway-for-woocommerce' ),
					$data['message']
				);
				$order->add_order_note( $message );
				return array(
					'success' => false,
					'message' => $message,
				);
			}
		}

		if ( $data['success'] ) {
			$message = __( 'Transaction cancelled successfully.', 'blink-payment-gateway-for-woocommerce' );
			$order->update_status( 'cancelled' );
			$order->add_order_note( $message );
			Blink_PG_Logger::log(
				'blink_cancel_transaction success',
				array(
					'order_id'       => $order_id,
					'transaction_id' => $transaction_id,
				)
			);
			return array(
				'success' => true,
				'message' => $message,
			);
		}

		/* translators: %s is the error message returned by the API. */
		$message = sprintf( __( 'Failed to cancel transaction: %s', 'blink-payment-gateway-for-woocommerce' ), $data['message'] );
		$order->add_order_note( $message );
		Blink_PG_Logger::log(
			'blink_cancel_transaction failed',
			array(
				'order_id'       => $order_id,
				'transaction_id' => $transaction_id,
				'message'        => $data['message'],
			)
		);
		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * Return the rendered payment fields HTML as a JSON response.
	 *
	 * @return void
	 */
	public static function blink_payment_fields_ajax() {
		// Verify nonce for security.
		if ( ! check_ajax_referer( 'blink_payment_fields_nonce', 'security', false ) ) {
			wp_send_json_error( __( 'Security check failed', 'blink-payment-gateway-for-woocommerce' ) );
		}

		// Make sure WooCommerce is available.
		if ( class_exists( 'Blink_PG_Payment_Gateway' ) ) {
			$gateway = new Blink_PG_Payment_Gateway();
			ob_start();
			$gateway->payment_fields();
			$payment_fields_html = ob_get_clean();

			wp_send_json_success( array( 'html' => $payment_fields_html ) );
		} else {
			wp_send_json_error( __( 'Payment gateway not found', 'blink-payment-gateway-for-woocommerce' ) );
		}
	}
}

Blink_PG_Ajax_Handler::init();
