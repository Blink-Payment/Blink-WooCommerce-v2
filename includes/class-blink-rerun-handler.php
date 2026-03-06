<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Rerun_Handler {

	private $gateway;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Process rerun for a preauthorized order
	 *
	 * @param WC_Order $order The order to process rerun for
	 * @return array Result of the rerun operation
	 */
	public function process_rerun( $order ) {
		Blink_Logger::log( 'Processing rerun for order', array( 'order_id' => $order->get_id() ) );

		// Get the original transaction ID from order meta
		$transaction_id = $order->get_meta( 'blink_res', true );
		
		if ( empty( $transaction_id ) ) {
			Blink_Logger::log( 'No transaction ID found for order', array( 'order_id' => $order->get_id() ) );
			return array(
				'success' => false,
				'error'   => __( 'No transaction ID found for this order', 'blink-payment-gateway-for-woocommerce' )
			);
		}

		// Get access token
		$token = $this->gateway->utils->blink_set_tokens();
		if ( empty( $token ) || empty( $token['access_token'] ) ) {
			Blink_Logger::log( 'Failed to get access token for rerun', array( 'order_id' => $order->get_id() ) );
			return array(
				'success' => false,
				'error'   => __( 'Failed to authenticate with payment gateway', 'blink-payment-gateway-for-woocommerce' )
			);
		}

		// Prepare rerun API data
		$amount = $order->get_total();
		$currency = $order->get_currency();
		$order_id = $order->get_id();

		$rerun_data = array(
			'amount'     => $amount,
			'currency'   => $currency,
			'reference'  => 'WC-' . $order_id,
		);

		// Make rerun API call
		$url = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/reruns';
		Blink_Logger::log( 'process_rerun() POST reruns', $rerun_data );
		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['access_token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $rerun_data ),
				'timeout' => 30,
			)
		);
		Blink_Logger::log( 'process_rerun() POST reruns', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );	
		if ( is_wp_error( $response ) ) {
			Blink_Logger::log( 'process_rerun() POST reruns', array( 'error' => $response->get_error_message() ) );
			return array(
				'success' => false,
				'error'   => $response->get_error_message()
			);
		}

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( wp_remote_retrieve_response_code( $response ) === 200 && isset( $api_body['success'] ) && $api_body['success'] ) {
			// Rerun successful
			Blink_Logger::log( 'Rerun successful', array( 
				'order_id' => $order_id,
				'new_transaction_id' => $api_body['transaction_id'],
				'amount' => $api_body['amount']
			) );

			// Update order with new transaction ID
			$new_transaction_id = $api_body['transaction_id'];
			$order->set_transaction_id( $new_transaction_id );
			$order->update_meta_data( 'blink_rerun_id', $new_transaction_id );
			$order->update_meta_data( '_gateway_status', $api_body['status'] ); 
			$order->save();

			// Add order note
			$note = sprintf(
				__( 'Blink charge complete (Transaction ID: %s)', 'blink-payment-gateway-for-woocommerce' ),
				$new_transaction_id
			);
			$order->add_order_note( $note );

			return array(
				'success' => true,
				'transaction_id' => $new_transaction_id,
				'amount' => $api_body['amount'],
				'message' => $api_body['message']
			);

		} else {
			// Rerun failed
			$error_message = isset( $api_body['message'] ) ? $api_body['message'] : __( 'Unknown error occurred', 'blink-payment-gateway-for-woocommerce' );
			Blink_Logger::log( 'Rerun failed', array( 
				'order_id' => $order_id,
				'error' => $error_message,
				'response' => $api_body
			) );

			return array(
				'success' => false,
				'error'   => $error_message
			);
		}
	}

	/**
	 * Check if an order is eligible for rerun
	 *
	 * @param WC_Order $order The order to check
	 * @return bool True if eligible for rerun
	 */
	public function is_eligible_for_rerun( $order ) {
		// Check if order has a transaction ID
		$transaction_id = $order->get_meta( 'blink_res', true );
		if ( empty( $transaction_id ) ) {
			Blink_Logger::log( 'Rerun eligibility check failed: No transaction ID', array( 'order_id' => $order->get_id() ) );
			return false;
		}

		// Check if order is using Blink payment method
		if ( $order->get_payment_method() !== 'blink' ) {
			Blink_Logger::log( 'Rerun eligibility check failed: Not Blink payment method', array( 'order_id' => $order->get_id(), 'payment_method' => $order->get_payment_method() ) );
			return false;
		}

		// Check if order was processed with preauth
		if ( ! blink_is_preauth_transaction( $order ) ) {
			Blink_Logger::log( 'Rerun eligibility check failed: Not a preauth order', array( 'order_id' => $order->get_id() ) );
			return false;
		}

		return true;
	}
}
