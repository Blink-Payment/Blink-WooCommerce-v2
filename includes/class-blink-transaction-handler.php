<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Transaction_Handler {

	protected $gateway;
	protected $token;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function blink_cancel_transaction( $transaction_id ) {
		Blink_Logger::log( 'cancel_transaction called', array( 'transaction_id' => $transaction_id ) );
		$url = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/cancels';

		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $this->token ) ) {
			return array( 'message' => __( 'Error creating access token', 'blink-payment-gateway-for-woocommerce' ) );
		}
		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		$response = wp_remote_post( $url, array( 'headers' => $headers ) );
		Blink_Logger::log( 'cancel_transaction response code', Blink_Logger::http_response_context( $response ) );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( __( 'Error fetching transaction status: ', 'blink-payment-gateway-for-woocommerce' ) . $response->get_error_message(), 'error' );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		Blink_Logger::log( 'cancel_transaction result', array( 'success' => isset( $data['success'] ) ? $data['success'] : null ) );
		return $data;
	}

	// New function to fetch transaction status
	public function blink_get_transaction_status( $transaction_id, $order = null ) {
		Blink_Logger::log( 'get_transaction_status called', array( 'transaction_id' => $transaction_id, 'order_id' => is_object( $order ) ? $order->get_id() : $order ) );
		$url         = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id;
		$data        = array();
		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( $this->token ) {
			// Prepare request headers
			$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

			$response = wp_remote_get( $url, array( 'headers' => $headers ) );
			Blink_Logger::log( 'get_transaction_status response code', Blink_Logger::http_response_context( $response ) );

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'Error fetching transaction status: ', 'blink-payment-gateway-for-woocommerce' ) . $response->get_error_message(), 'error' );
				return;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ) );
		}

		$this->gateway->paymentSource = ! empty( $data->data->payment_source ) ? $data->data->payment_source : '';
		$this->gateway->paymentStatus = ! empty( $data->data->status ) ? $data->data->status : '';
		Blink_Logger::log( 'get_transaction_status result', array( 'payment_source' => $this->gateway->paymentSource, 'status' => $this->gateway->paymentStatus ) );
	}

	/**
	 * Get client IP address for logging purposes.
	 * 
	 * @return string Client IP address.
	 */
	private function blink_get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (from X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				return $ip;
			}
		}
		return 'unknown';
	}

	/*
	 * In case we need a webhook, like PayPal IPN etc
	*/
	public function blink_webhook() {
		Blink_Logger::log( 'webhook called', array( 'ip' => $this->blink_get_client_ip() ) );
		global $wpdb;
		$order_id = '';
		
		// Note: This is a webhook endpoint for external payment processors
		// Nonce verification is not applicable for webhook endpoints as they come from external systems
		// Basic security: only allow POST requests for webhooks
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			http_response_code( 405 );
			exit( 'Method not allowed' );
		}
		
		$request  = isset( $_REQUEST['transaction_id'] ) ? $_REQUEST : file_get_contents( 'php://input' );
		if ( is_array( $request ) ) {
			$data = isset( $request['merchant_data'] ) ? stripslashes( $request['merchant_data'] ) : '';
			$request['merchant_data'] = json_decode( $data, true );
		} else {
			$request = json_decode( $request, true );
		}

		Blink_Logger::log( 'request data', array( 'request' => $request ) );

		if(empty($request['status'])) {
			Blink_Logger::log( 'no transaction status found in the request' );
			echo wp_json_encode( array( 'error' => 'No valid transaction status found in the request' ) );
			exit();
		}

		$transaction_id = ! empty( $request['transaction_id'] ) ? sanitize_text_field( $request['transaction_id'] ) : '';
		
		// Security: Validate transaction_id exists and is not empty
		if ( empty( $transaction_id ) ) {
			Blink_Logger::log( 'webhook rejected: missing transaction_id', array( 'ip' => $this->blink_get_client_ip() ) );
			http_response_code( 400 );
			echo wp_json_encode( array( 'error' => 'Missing transaction_id' ) );
			exit();
		}

		Blink_Logger::log( 'Webhook processing', array( 
			'transaction_id' => $transaction_id,
			'reference' => isset($request['reference']) ? $request['reference'] : 'not set',
			'status' => isset($request['status']) ? $request['status'] : 'not set',
			'merchant_data_type' => isset($request['merchant_data']) ? gettype($request['merchant_data']) : 'not set',
			'event_type' => isset($request['event_type']) ? $request['event_type'] : 'not set'
		) );

		// Try to get order_id from merchant_data or reference
		if ( $transaction_id ) {
			$merchant_data = isset( $request['merchant_data'] ) ? $request['merchant_data'] : array();
			
			// Handle merchant_data as JSON string (direct payments) or array (hosted payments)
			if ( is_string( $merchant_data ) && ! empty( $merchant_data ) ) {
				$merchant_data = json_decode( $merchant_data, true );
				Blink_Logger::log( 'Decoded merchant_data', array( 'merchant_data' => $merchant_data ) );
			}
			
			if ( ! empty( $merchant_data ) && ! empty( $merchant_data['order_info']['order_id'] ) ) {
				$order_id = sanitize_text_field( $merchant_data['order_info']['order_id'] );
				Blink_Logger::log( 'Order ID from merchant_data', array( 'order_id' => $order_id, 'merchant_data' => $merchant_data ) );
			} elseif ( ! empty( $request['reference'] ) ) {
				// Try to extract order ID from reference, e.g. "WC-124"
				if ( preg_match( '/WC-(\d+)/', $request['reference'], $matches ) ) {
					$order_id = $matches[1];
					Blink_Logger::log( 'Order ID from reference', array( 'order_id' => $order_id, 'reference' => $request['reference'] ) );
				}
			}

			if ( ! $order_id ) {
				
				$recent_orders = wc_get_orders( array(
					'limit'  => 20, // Limit to very recent orders only
					'return' => 'ids',
					'status' => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ),
					'orderby' => 'date',
					'order'   => 'DESC',
				) );
				
				$order_id = null;
				if ( ! empty( $recent_orders ) ) {
					// Check each order's meta data efficiently
					foreach ( $recent_orders as $order_id_candidate ) {
						$candidate_order = wc_get_order( $order_id_candidate );
						if ( ! $candidate_order ) {
							continue;
						}
						$transaction_id_meta = $candidate_order->get_transaction_id();
						$blink_res_meta      = $candidate_order->get_meta( 'blink_res', true );
						
						if ( $transaction_id_meta === $transaction_id || $blink_res_meta === $transaction_id ) {
							$order_id = $order_id_candidate;
							break;
						}
					}
				}
			}

			$status  = ! empty( $request['status'] ) ? $request['status'] : '';
			$note    = ! empty( $request['note'] ) ? $request['note'] : '';
			$order   = wc_get_order( $order_id );
			if ( $order ) {
				// Security: Verify order belongs to Blink payment method
				$payment_method = $order->get_payment_method();
				if ( $payment_method !== 'blink' ) {
					Blink_Logger::log( 'webhook rejected: order does not belong to Blink', array( 
						'order_id' => $order_id,
						'payment_method' => $payment_method,
						'transaction_id' => $transaction_id,
						'ip' => $this->blink_get_client_ip()
					) );
					http_response_code( 403 );
					echo wp_json_encode( array( 'error' => 'Forbidden: Order does not belong to Blink payment method' ) );
					exit();
				}

				// Security: Validate transaction_id against Blink API to ensure it's legitimate
				$transaction_valid = $this->blink_validate_webhook_transaction( $transaction_id );
				if ( ! $transaction_valid ) {
					Blink_Logger::log( 'webhook rejected: transaction validation failed', array( 
						'order_id' => $order_id,
						'transaction_id' => $transaction_id,
						'ip' => $this->blink_get_client_ip()
					) );
					http_response_code( 403 );
					echo wp_json_encode( array( 'error' => 'Forbidden: Invalid transaction ID' ) );
					exit();
				}

				Blink_Logger::log( 'Order found and updating', array( 
					'order_id' => $order_id,
					'transaction_id' => $transaction_id,
					'status' => $status,
					'payment_method' => $order->get_payment_method()
				) );
				
				$order->update_meta_data( '_debug', $request );
				$order->update_meta_data( 'blink_res', $transaction_id );
				$order->set_transaction_id( $transaction_id );
				$order->update_meta_data( 'status', $status );

				$is_preauth = blink_is_preauth_transaction($order);
				if ( $is_preauth && strtolower( $status ) !== 'captured' ) {
					$preauth_note = __('Blink payment preauthorized (Transaction ID: ', 'blink-payment-gateway-for-woocommerce') . $transaction_id . '). ' . 
								   __('Process order to take payment, or cancel to remove the pre-authorization. ', 'blink-payment-gateway-for-woocommerce') .
								   __('Refunding is unavailable until payment has been captured. ', 'blink-payment-gateway-for-woocommerce');
					$order->add_order_note( $preauth_note );
				}
				
				Blink_Logger::log( 'Webhook processed', array( 'order_id' => $order_id, 'status' => $status ) );
				blink_change_status( $order, $transaction_id, $status, '', $note );

				Blink_Logger::log( 'Order updated successfully', array( 
					'order_id' => $order_id,
					'blink_res' => $order->get_meta('blink_res', true),
					'gateway_status' => $order->get_meta('_gateway_status', true),
				) );

				$response = array(
					'order_id'     => $order_id,
					'order_status' => $status,
				);
				echo wp_json_encode( $response );
				exit();
			} else {
				Blink_Logger::log( 'Order not found', array( 'order_id' => $order_id ) );
			}
		}
		$response = array(
			'transaction_id' => ! empty( $transaction_id ) ? $transaction_id : null,
			'error'          => __( 'No order found with this transaction ID', 'blink-payment-gateway-for-woocommerce' ),
		);
		echo wp_json_encode( $response );
		exit();
	}

	/**
	 * Validate webhook transaction ID against Blink API.
	 * This ensures the transaction_id is legitimate before updating order status.
	 * 
	 * @param string $transaction_id Transaction ID from webhook.
	 * @return bool True if transaction exists and is valid, false otherwise.
	 */
	private function blink_validate_webhook_transaction( $transaction_id ) {
		if ( empty( $transaction_id ) || empty( $this->gateway->secret_key ) ) {
			return false;
		}

		// Generate access token
		$token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $token ) || empty( $token['access_token'] ) ) {
			Blink_Logger::log( 'webhook transaction validation: failed to get access token' );
			// If we can't validate, allow through but log warning (fail-open for reliability)
			// In production, you may want to fail-closed by returning false
			return true;
		}

		// Validate transaction exists in Blink system
		$url = $this->gateway->host_url . '/pay/v1/transactions/' . sanitize_text_field( $transaction_id );
		$response = wp_remote_get(
			$url,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
				'timeout' => 10, // Shorter timeout for webhook validation
			)
		);

		if ( is_wp_error( $response ) ) {
			Blink_Logger::log( 'webhook transaction validation: API error', array( 'error' => $response->get_error_message() ) );
			// Fail-open: if API is down, allow webhook through to prevent order processing delays
			return true;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $response_code ) {
			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			// Transaction exists and is valid
			return ! empty( $api_body['data'] );
		}

		// Transaction not found or invalid
		$response_context = Blink_Logger::http_response_context( $response );
		Blink_Logger::log( 'webhook transaction validation: transaction not found', array( 
			'transaction_id' => $transaction_id,
			'response_code' => $response_code,
			'body'          => isset( $response_context['body'] ) ? $response_context['body'] : '',
		) );
		return false;
	}

	public function blink_validate_transaction( $order, $transaction ) {
		$email = '';
		if ( is_object( $order ) && method_exists( $order, 'get_billing_email' ) ) {
			$email = sanitize_email( $order->get_billing_email() );
		}
		Blink_Logger::set_context(
			array(
				'order_id'       => is_object( $order ) ? $order->get_id() : $order,
				'transaction_id' => $transaction,
				'billing_email'  => $email,
			)
		);
		$intent_id 	  = $order->get_meta( '_blink_intent_id', true );
		$token        = $this->gateway->utils->blink_set_tokens($intent_id);
		$responseCode = ! empty( $transaction ) ? $transaction : '';
		$url          = $this->gateway->host_url . '/pay/v1/transactions/' . $responseCode;
		Blink_Logger::log( 'blink_validate_transaction() GET transactions', $url );
		$response     = wp_remote_get(
			$url,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
			)
		);
		Blink_Logger::log( 'blink_validate_transaction() GET transactions', Blink_Logger::http_response_context( $response ) );
		$redirect     = trailingslashit( wc_get_checkout_url() );

		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {
			$retry_after = $headers['retry-after'] + 2;
			sleep( $retry_after );
			$response = wp_remote_get(
				$url,
				array(
					'method'  => 'GET',
					'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
				)
			);
		}

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->gateway->utils->blink_destroy_session_tokens($intent_id);
		if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
			Blink_Logger::log( 'Transaction validated' );
			return ! empty( $api_body['data'] ) ? $api_body['data'] : array();
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
		}

		return array();
	}

	public function blink_check_response_for_order( $order_id ) {
		if ( $order_id ) {
			$wc_order = wc_get_order( $order_id );
			if ( ! $wc_order->needs_payment() ) {
				return;
			}
			if ( 'true' == $wc_order->get_meta( '_blink_res_expired', true ) ) {
				return;
			}
			$transaction        = $wc_order->get_meta( 'blink_res', true );
			$transaction_result = $this->blink_validate_transaction( $wc_order, $transaction );
			// Note: $_GET usage is for webhook processing from external payment processors
			// Nonce verification is not applicable for webhook endpoints
			$status             = isset( $transaction_result['status'] ) ? $transaction_result['status'] : ( isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '' );
			$source             = ! empty( $transaction_result['payment_source'] ) ? $transaction_result['payment_source'] : '';
			$message            = isset( $transaction_result['message'] ) ? $transaction_result['message'] : ( isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '' );
			$wc_order->update_meta_data( '_blink_status', $status );
			$wc_order->update_meta_data( 'payment_type', $source );
			$wc_order->update_meta_data( '_blink_res_expired', 'true' );
			$wc_order->set_transaction_id( $transaction_result['transaction_id'] );
			$wc_order->add_order_note( __( 'Pay by ', 'blink-payment-gateway-for-woocommerce' ) . $source );
			$wc_order->add_order_note( __( 'Transaction Note: ', 'blink-payment-gateway-for-woocommerce' ) . $message );
			$wc_order->save();
			Blink_Logger::log( 'Transaction handler processed', array( 'order_id' => $order_id, 'status' => $status ) );
			blink_change_status( $wc_order, $transaction_result['transaction_id'], $status, $source, $message );
		}
	}

	public static function blink_capture_order_response() {
		global $wp;
		$wc_order = null;
		$order_id = null;

		if ( ! empty( $wp->query_vars['order-received'] ) ) {

			$order_id = apply_filters( 'woocommerce_thankyou_order_id', absint( $wp->query_vars['order-received'] ) );
			$wc_order = wc_get_order( $order_id );
		}

		if ( empty( $wc_order ) ) {
			return;
		}

		$transaction_id = '';
		// Note: $_REQUEST usage is for webhook processing from external payment processors
		// Nonce verification is not applicable for webhook endpoints
		if ( isset( $_REQUEST['transaction_id'] ) && ! empty( $_REQUEST['transaction_id'] ) ) {
			$transaction_id = sanitize_text_field( wp_unslash( $_REQUEST['transaction_id'] ) );
		} elseif ( $wc_order && $wc_order->get_transaction_id() ) {
			$transaction_id = $wc_order->get_transaction_id();
		}

		if ( ! empty( $transaction_id ) ) {
			$transaction = wc_clean( wp_unslash( $transaction_id ) );
			$wc_order->update_meta_data( 'blink_res', $transaction );
			$wc_order->update_meta_data( '_blink_res_expired', 'false' );
			$wc_order->save();

			$payment_method_id  = $wc_order->get_payment_method();
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			$payment_method     = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : false;

			if ( $payment_method && $payment_method_id === 'blink' ) {
				$instance = new self( $payment_method );
				$instance->blink_check_response_for_order( $order_id );
			}
		}
	}

	/**
	 * Handle order status change to trigger rerun for preauthorized orders
	 *
	 * @param int $order_id Order ID
	 * @param string $old_status Old order status
	 * @param string $new_status New order status
	 */
	public static function blink_handle_order_status_change($order_id, $old_status, $new_status) {
		// Only process when changing to 'processing' from 'on-hold'
		if ($new_status !== 'processing' || $old_status !== 'on-hold') {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}

		// Check if this is a Blink payment method
		if ($order->get_payment_method() !== 'blink') {
			return;
		}

		// Get the gateway instance
		$gateways = WC()->payment_gateways->payment_gateways();
		$gateway = isset($gateways['blink']) ? $gateways['blink'] : null;
		
		if (!$gateway || !isset($gateway->rerun_handler) || !is_object($gateway->rerun_handler)) {
			return;
		}

		// Check if order is eligible for rerun
		if (!$gateway->rerun_handler->is_eligible_for_rerun($order)) {
			return;
		}

		// Process the rerun
		try {
			$result = $gateway->rerun_handler->process_rerun($order);

			if ($result && isset($result['success']) && $result['success']) {
				Blink_Logger::log('Rerun successful for order', array(
					'order_id' => $order_id,
					'transaction_id' => $result['transaction_id'],
					'amount' => $result['amount']
				));
			} else {
				$error_message = isset($result['error']) ? $result['error'] : 'Unknown error occurred';
				Blink_Logger::log('Rerun failed for order', array(
					'order_id' => $order_id,
					'error' => $error_message
				));
				
				// Add error note to order
				$order->add_order_note(
					sprintf(
						__('Blink rerun failed: %s', 'blink-payment-gateway-for-woocommerce'),
						$error_message
					)
				);
			}
		} catch (Exception $e) {
			Blink_Logger::log('Rerun exception for order', array(
				'order_id' => $order_id,
				'error' => $e->getMessage()
			));
			
			// Add error note to order
			$order->add_order_note(
				sprintf(
					__('Blink rerun failed: %s', 'blink-payment-gateway-for-woocommerce'),
					$e->getMessage()
				)
			);
		}
	}
}
