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

				// A rerun is a capture attempt against an existing pre-authorisation.
				// Handle it before the generic transaction path so a failed capture
				// cannot replace the original transaction or fail the order.
				if ( $this->blink_handle_rerun_webhook( $order, $request, $transaction_id ) ) {
					$response = array(
						'order_id'     => $order_id,
						'order_status' => $order->get_status(),
					);
					echo wp_json_encode( $response );
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

				$is_preauth    = blink_is_preauth_transaction( $order );
				$mapped_status = blink_get_status( $status, '', $order );
				if ( $is_preauth && 'hold' === $mapped_status ) {
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
	 * Handle a rerun_transaction webhook without entering generic payment status handling.
	 *
	 * The event type, existing pre-auth state, and distinct transaction ID are all
	 * required. This keeps ordinary sale and initial pre-authorisation declines on
	 * the existing Declined -> Failed path.
	 *
	 * @param WC_Order $order          Resolved WooCommerce order.
	 * @param array    $request        Sanitized/decoded webhook payload.
	 * @param string   $transaction_id Webhook transaction ID.
	 * @return bool True when the webhook was handled as a capture event.
	 */
	public function blink_handle_rerun_webhook( $order, $request, $transaction_id ) {
		if ( ! $this->blink_is_rerun_webhook( $order, $request, $transaction_id ) ) {
			return false;
		}

		$status        = ! empty( $request['status'] ) && is_scalar( $request['status'] ) ? sanitize_text_field( (string) $request['status'] ) : '';
		$rerun_handler = isset( $this->gateway->rerun_handler ) ? $this->gateway->rerun_handler : null;

		if ( ! is_object( $rerun_handler ) ) {
			Blink_Logger::log( 'Rerun webhook ignored: rerun handler unavailable', array( 'order_id' => $order->get_id() ) );
			return true;
		}
		if ( 'yes' === $order->get_meta( '_blink_rerun_outcome_unknown', true ) ) {
			$expected_reference = (string) $order->get_meta( '_blink_rerun_reference', true );
			$webhook_reference = ! empty( $request['reference'] ) && is_scalar( $request['reference'] ) ? sanitize_text_field( (string) $request['reference'] ) : '';
			if ( '' === $expected_reference || $expected_reference !== $webhook_reference ) {
				Blink_Logger::log( 'Unknown rerun outcome remains blocked: webhook correlation unavailable', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id ) );
				return true;
			}
		}

		$is_success    = $rerun_handler->is_successful_rerun_status( $status );
		$captured_id = (string) $order->get_meta( 'blink_rerun_id', true );
		$matching_capture = '' !== $captured_id && $captured_id === (string) $transaction_id;
		$mapped_status = $is_success ? 'complete' : ( $matching_capture && $rerun_handler->is_failed_rerun_status( $status ) ? 'failed' : blink_get_status( $status, '', $order ) );
		// Once a capture is recorded, only events for that exact capture may
		// update its result. Unrelated/late reruns are ignored; a matching
		// terminal event is still recorded (for example a later reversal).
		if ( '' !== $captured_id && $captured_id !== (string) $transaction_id ) {
			Blink_Logger::log(
				'Rerun webhook ignored because capture is already recorded',
				array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'status' => $status )
			);
			return true;
		}

		if ( 'complete' === $mapped_status ) {
			$order->delete_meta_data( '_blink_rerun_outcome_unknown' );
			$rerun_handler->record_rerun_success( $order, $transaction_id, $status, false );
			$rerun_handler->complete_rerun_payment( $order, $transaction_id );
		} elseif ( 'failed' === $mapped_status ) {
			$message = '';
			if ( ! empty( $request['note'] ) && is_scalar( $request['note'] ) ) {
				$message = sanitize_text_field( (string) $request['note'] );
			} elseif ( ! empty( $request['message'] ) && is_scalar( $request['message'] ) ) {
				$message = sanitize_text_field( (string) $request['message'] );
			}
			$message = '' !== $message ? $message : $status;
			$order->delete_meta_data( '_blink_rerun_outcome_unknown' );
			$order->update_meta_data( '_gateway_status', $status );
			$order->save();
			$rerun_handler->record_rerun_failure( $order, $message, $transaction_id );
			// Only the optimistic legacy On hold -> Processing flow may be
			// restored while its capture request is still active. A delayed
			// webhook must never reopen a later merchant-selected status.
			$active_legacy_failure = $order->has_status( 'processing' )
				&& '' === $captured_id
				&& $rerun_handler->is_rerun_in_progress( $order );
			if ( $active_legacy_failure ) {
				$order->update_status( 'on-hold' );
			} elseif ( $matching_capture && $order->has_status( 'processing' ) ) {
				// A recorded charge that is subsequently reversed is no longer
				// paid; preserve any later merchant-selected terminal status.
				$order->update_status( 'failed' );
			}
		} else {
			Blink_Logger::log(
				'Rerun webhook reported a non-final status',
				array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'status' => $status )
			);
			return true;
		}

		return true;
	}

	/**
	 * Narrowly classify Blink capture webhooks.
	 *
	 * @param WC_Order $order          Resolved WooCommerce order.
	 * @param array    $request        Webhook payload.
	 * @param string   $transaction_id Webhook transaction ID.
	 * @return bool
	 */
	public function blink_is_rerun_webhook( $order, $request, $transaction_id ) {
		$event_type = ! empty( $request['event_type'] ) && is_scalar( $request['event_type'] )
			? strtolower( trim( (string) $request['event_type'] ) )
			: '';
		$event_type = preg_replace( '/[\s-]+/', '_', $event_type );
		$original   = is_object( $order ) ? (string) $order->get_meta( 'blink_res', true ) : '';

		return 'rerun_transaction' === $event_type
			&& is_object( $order )
			&& 'blink' === $order->get_payment_method()
			&& blink_is_preauth_transaction( $order )
			&& '' !== $original
			&& '' !== (string) $transaction_id
			&& $original !== (string) $transaction_id;
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
				$this->blink_update_payment_message( $wc_order );
				return;
			}
			if ( 'true' == $wc_order->get_meta( '_blink_res_expired', true ) ) {
				return;
			}
			$transaction        = $wc_order->get_meta( 'blink_res', true );
			$transaction_result = $this->blink_validate_transaction( $wc_order, $transaction );
			// Blink appends transaction results to the external payment return URL.
			// Request data is retained only for status processing and private notes;
			// the customer-facing message below comes from the transaction API.
			$status             = isset( $transaction_result['status'] ) ? $transaction_result['status'] : ( isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '' );
			$source             = ! empty( $transaction_result['payment_source'] ) ? $transaction_result['payment_source'] : '';
			$transaction_message = isset( $transaction_result['message'] ) ? $transaction_result['message'] : '';
			$redirect_note       = isset( $_GET['note'] ) ? wp_unslash( $_GET['note'] ) : '';
			$message             = blink_resolve_payment_message( $transaction_message, $redirect_note );
			$payment_message     = blink_resolve_payment_message( $transaction_message );
			$wc_order->update_meta_data( '_blink_status', $status );
			$wc_order->update_meta_data( 'payment_type', $source );
			$wc_order->update_meta_data( '_blink_res_expired', 'true' );
			$wc_order->set_transaction_id( $transaction_result['transaction_id'] );
			$wc_order->add_order_note( __( 'Pay by ', 'blink-payment-gateway-for-woocommerce' ) . $source );
			$wc_order->add_order_note( __( 'Transaction Note: ', 'blink-payment-gateway-for-woocommerce' ) . $message );
			$wc_order->save();
			Blink_Logger::log( 'Transaction handler processed', array( 'order_id' => $order_id, 'status' => $status ) );
			blink_change_status( $wc_order, $transaction_result['transaction_id'], $status, $source, $message );
			if (
				$this->gateway->blink_is_hosted()
				&& $wc_order->has_status( 'failed' )
				&& '' === $payment_message
				&& '' === $wc_order->get_meta( '_blink_payment_message', true )
			) {
				$payment_message = $this->blink_get_hosted_decline_message();
			}
			$this->blink_update_payment_message( $wc_order, $payment_message );
			delete_transient( 'blink_3d_process' . $order_id );
			delete_transient( 'blink_3d_challenge_token_' . $order_id );
		}
	}

	/**
	 * Keep the customer-facing payment message in sync with order status.
	 *
	 * Only a message returned by the transaction API is passed to this method. An
	 * empty message must not erase a useful decline reason when a failed order is
	 * revisited, while a non-failed order must not retain stale failure metadata.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $message  Sanitized, server-validated transaction message.
	 */
	private function blink_update_payment_message( $wc_order, $message = '' ) {
		if ( $wc_order->has_status( 'failed' ) ) {
			if ( '' !== $message ) {
				$wc_order->update_meta_data( '_blink_payment_message', $message );
				$wc_order->save();
			}
			return;
		}

		if ( '' !== $wc_order->get_meta( '_blink_payment_message', true ) ) {
			$wc_order->delete_meta_data( '_blink_payment_message' );
			$wc_order->save();
		}
	}

	/**
	 * Process an authenticated Hosted Paylink decline before thank-you rendering.
	 *
	 * Hosted decline redirects do not include a transaction ID or a trusted
	 * decline reason, so use a fixed plugin-authored customer message.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $status   Sanitized status returned by Blink.
	 */
	private function blink_handle_hosted_decline_return( $wc_order, $status ) {
		$message = $this->blink_get_hosted_decline_message();
		if ( 'true' === $wc_order->get_meta( '_blink_res_expired', true ) && $wc_order->has_status( 'failed' ) ) {
			if ( '' === $wc_order->get_meta( '_blink_payment_message', true ) ) {
				$this->blink_update_payment_message( $wc_order, $message );
			}
			return;
		}

		$wc_order->update_meta_data( '_blink_status', $status );
		$wc_order->update_meta_data( '_blink_res_expired', 'true' );
		$wc_order->save();
		blink_change_status( $wc_order, '', $status, '', $message );
		$this->blink_update_payment_message( $wc_order, $message );
	}

	/**
	 * Return the safe generic message used for Hosted Paylink declines.
	 *
	 * @return string
	 */
	private function blink_get_hosted_decline_message() {
		return __( 'Payment was declined. Please try again.', 'blink-payment-gateway-for-woocommerce' );
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
		// Blink appends the transaction ID to the external payment return URL.
		if ( isset( $_REQUEST['transaction_id'] ) && ! empty( $_REQUEST['transaction_id'] ) ) {
			$transaction_id = sanitize_text_field( wp_unslash( $_REQUEST['transaction_id'] ) );
		} elseif ( $wc_order && $wc_order->get_transaction_id() ) {
			$transaction_id = $wc_order->get_transaction_id();
		}

		$payment_method_id  = $wc_order->get_payment_method();
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method     = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : false;

		if ( ! $payment_method || 'blink' !== $payment_method_id ) {
			return;
		}

		$instance = new self( $payment_method );
		if ( ! empty( $transaction_id ) ) {
			$transaction = wc_clean( wp_unslash( $transaction_id ) );
			$wc_order->update_meta_data( 'blink_res', $transaction );
			$wc_order->update_meta_data( '_blink_res_expired', 'false' );
			$wc_order->save();
			$instance->blink_check_response_for_order( $order_id );
			return;
		}

		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if (
			$payment_method->blink_is_hosted()
			&& 'declined' === strtolower( trim( $status ) )
			&& 'WC-' . $order_id === $reference
			&& '' !== $order_key
			&& hash_equals( $wc_order->get_order_key(), $order_key )
		) {
			$instance->blink_handle_hosted_decline_return( $wc_order, $status );
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

		$gateway = self::blink_get_gateway();
		
		if (!$gateway || !isset($gateway->rerun_handler) || !is_object($gateway->rerun_handler)) {
			return;
		}

		// Check if order is eligible for rerun
		if (!$gateway->rerun_handler->is_eligible_for_rerun($order)) {
			return;
		}

		$result = $gateway->rerun_handler->process_rerun($order);
		if ( ! empty( $result['success'] ) ) {
			// process_rerun() may have received a completing webhook while the
			// HTTP request was in flight. Reload persisted state before completion
			// so the payment-complete guard is observed by this caller.
			$fresh_order = wc_get_order( $order_id );
			if ( $fresh_order ) {
				$order = $fresh_order;
			}
			$gateway->rerun_handler->complete_rerun_payment( $order, $result['transaction_id'] );
		}
		if ( empty( $result['success'] ) ) {
			$fresh_order = wc_get_order( $order_id );
			if ( $fresh_order ) {
				$order = $fresh_order;
			}
			// The merchant already changed the order to Processing. Restore the
			// accurate payment state; this transition does not match the capture hook.
			if ( $order->has_status( 'processing' ) ) {
				$order->update_status( 'on-hold' );
			}
			Blink_Logger::log(
				'Legacy pre-authorisation capture failed; order restored to on-hold',
				array( 'order_id' => $order_id, 'error' => isset( $result['error'] ) ? $result['error'] : '' )
			);
		}
	}

	/**
	 * Add the native capture action for eligible orders.
	 *
	 * @param array    $actions Existing WooCommerce order actions.
	 * @param WC_Order $order   Current order.
	 * @return array
	 */
	public static function blink_add_capture_order_action( $actions, $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		$gateway = self::blink_get_gateway();
		if ( $gateway && isset( $gateway->rerun_handler ) && $gateway->rerun_handler->is_eligible_for_rerun( $order, true ) ) {
			$actions['blink_capture_preauthorisation'] = __( 'Capture Blink pre-authorisation', 'blink-payment-gateway-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * Process the native WooCommerce capture order action.
	 *
	 * @param WC_Order $order Order selected in the admin.
	 * @return void
	 */
	public static function blink_capture_preauthorisation_action( $order ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$gateway = self::blink_get_gateway();
		if ( ! $gateway || ! isset( $gateway->rerun_handler ) || ! $gateway->rerun_handler->is_eligible_for_rerun( $order, true ) ) {
			return;
		}

		$result = $gateway->rerun_handler->process_rerun( $order );
		$fresh_order = wc_get_order( $order->get_id() );
		if ( $fresh_order ) {
			$order = $fresh_order;
		}
		if ( ! empty( $result['success'] ) ) {
			$gateway->rerun_handler->complete_rerun_payment( $order, $result['transaction_id'] );
		}
	}

	/**
	 * Return the configured Blink gateway without constructing a duplicate instance.
	 *
	 * @return Blink_Payment_Gateway|null
	 */
	private static function blink_get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return null;
		}
		$payment_gateways = WC()->payment_gateways();
		if ( ! is_object( $payment_gateways ) || ! method_exists( $payment_gateways, 'payment_gateways' ) ) {
			return null;
		}
		$gateways = $payment_gateways->payment_gateways();
		return isset( $gateways['blink'] ) ? $gateways['blink'] : null;
	}
}
