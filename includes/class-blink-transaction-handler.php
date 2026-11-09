<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Transaction_Handler {

	const WEBHOOK_RECHECK_HOOK  = 'blink_recheck_webhook';
	const WEBHOOK_RECHECK_LIMIT = 3;

	protected $gateway;
	protected $token;

	/**
	 * What the order-received page tells the customer about a Hosted return Blink
	 * has not confirmed as paid on this request: null, or 'type' (confirming,
	 * declined or mismatch) and 'order_id'. Display only; nothing is stored.
	 *
	 * @var array|null
	 */
	public static $hosted_return_notice = null;

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
	 * Blink payment notifications. Blink does not sign webhooks, so the payload is
	 * only used to find the order and the payment; everything applied to the order
	 * is read back from Blink's API.
	 */
	public function blink_webhook() {
		Blink_Logger::log( 'webhook called', array( 'ip' => $this->blink_get_client_ip() ) );

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

		// Security: Validate transaction_id exists and is not empty
		if ( empty( $request['transaction_id'] ) ) {
			Blink_Logger::log( 'webhook rejected: missing transaction_id', array( 'ip' => $this->blink_get_client_ip() ) );
			http_response_code( 400 );
			echo wp_json_encode( array( 'error' => 'Missing transaction_id' ) );
			exit();
		}

		$result = $this->blink_process_webhook( $request );
		http_response_code( $result['code'] );
		echo wp_json_encode( $result['body'] );
		exit();
	}

	/**
	 * Verify a webhook with Blink and apply it to its order.
	 *
	 * @param array $request Decoded webhook payload.
	 * @param int   $attempt Number of earlier re-checks of this webhook.
	 * @return array HTTP response 'code' and 'body'.
	 */
	public function blink_process_webhook( $request, $attempt = 0 ) {
		$transaction_id = ! empty( $request['transaction_id'] ) && is_scalar( $request['transaction_id'] ) ? sanitize_text_field( (string) $request['transaction_id'] ) : '';

		Blink_Logger::log( 'Webhook processing', array(
			'transaction_id' => $transaction_id,
			'reference' => isset($request['reference']) ? $request['reference'] : 'not set',
			'status' => isset($request['status']) ? $request['status'] : 'not set',
			'event_type' => isset($request['event_type']) ? $request['event_type'] : 'not set',
			'attempt' => $attempt,
		) );

		$order = $this->blink_find_webhook_order( $request, $transaction_id );
		if ( ! $order ) {
			Blink_Logger::log( 'Order not found', array( 'transaction_id' => $transaction_id ) );
			return array(
				'code' => 200,
				'body' => array(
					'transaction_id' => '' !== $transaction_id ? $transaction_id : null,
					'error'          => __( 'No order found with this transaction ID', 'blink-payment-gateway-for-woocommerce' ),
				),
			);
		}

		// Security: Verify order belongs to Blink payment method
		if ( 'blink' !== $order->get_payment_method() ) {
			Blink_Logger::log( 'webhook rejected: order does not belong to Blink', array(
				'order_id' => $order->get_id(),
				'transaction_id' => $transaction_id,
				'ip' => $this->blink_get_client_ip()
			) );
			return $this->blink_webhook_response( 403 );
		}

		$is_rerun     = $this->blink_is_rerun_webhook( $order, $request, $transaction_id );
		$verification = $this->blink_verify_webhook( $order, $request, $transaction_id, $is_rerun );

		if ( 'unavailable' === $verification['result'] ) {
			$this->blink_schedule_webhook_recheck( $order, $request, $transaction_id, $attempt );
			return $this->blink_webhook_response( 503 );
		}
		if ( 'unlinked' === $verification['result'] ) {
			Blink_Logger::log( 'webhook rejected: payment not linked to order', array(
				'order_id' => $order->get_id(),
				'transaction_id' => $transaction_id,
				'reason' => $verification['reason'],
				'ip' => $this->blink_get_client_ip()
			) );
			return $this->blink_webhook_response( 403 );
		}

		$status = $verification['status'];
		Blink_Logger::log( 'Webhook verified', array(
			'order_id' => $order->get_id(),
			'transaction_id' => $transaction_id,
			'status' => $status,
			'webhook_status' => isset( $request['status'] ) ? $request['status'] : '',
		) );

		// A rerun is a capture attempt against an existing pre-authorisation.
		// Handle it before the generic transaction path so a failed capture
		// cannot replace the original transaction or fail the order.
		if ( $is_rerun ) {
			$request['status'] = $status;
			$this->blink_handle_rerun_webhook( $order, $request, $transaction_id );
			return $this->blink_webhook_response( 200 );
		}

		// A genuine Paylink that has not been paid (Unpaid or Payment Attempted).
		if ( '' === $status ) {
			return $this->blink_webhook_response( 200 );
		}

		// A repeat of the transaction and status this order already recorded, such
		// as a reversal that put it On-hold, must not be applied again.
		if ( '' !== $transaction_id && $transaction_id === (string) $order->get_transaction_id() && $status === (string) $order->get_meta( 'status', true ) ) {
			Blink_Logger::log( 'Repeat webhook for a recorded status ignored', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'status' => $status ) );
			return $this->blink_webhook_response( 200 );
		}

		if ( null !== $verification['mismatch'] ) {
			$this->blink_record_unexpected_payment( $order, $transaction_id, $status, $verification['mismatch'] );
			return $this->blink_webhook_response( 200 );
		}

		// A Hosted return applies a paid Paylink without a transaction ID (the
		// redirect carries none), so the webhook records it without paying again.
		if ( '' !== $verification['paylink_id'] && '' === (string) $order->get_transaction_id()
			&& $verification['paylink_id'] === (string) $order->get_meta( '_blink_paylink_paid_id', true ) ) {
			$this->blink_record_paylink_transaction( $order, $transaction_id, $status );
			return $this->blink_webhook_response( 200 );
		}

		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			$this->blink_record_payment_on_paid_order( $order, $transaction_id, $status, $verification['paylink_id'] );
			return $this->blink_webhook_response( 200 );
		}

		Blink_Logger::log( 'Order found and updating', array(
			'order_id' => $order->get_id(),
			'transaction_id' => $transaction_id,
			'status' => $status,
			'payment_method' => $order->get_payment_method()
		) );

		$order->update_meta_data( '_debug', $request );
		$order->update_meta_data( 'blink_res', $transaction_id );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( 'status', $status );
		if ( '' !== $verification['paylink_id'] ) {
			$order->update_meta_data( '_blink_paylink_paid_id', $verification['paylink_id'] );
		}

		$mapped_status = blink_get_status( $status, '', $order );
		$this->blink_add_preauth_note( $order, $transaction_id, $status );

		Blink_Logger::log( 'Webhook processed', array( 'order_id' => $order->get_id(), 'status' => $status ) );
		blink_change_status( $order, $transaction_id, $status, '', 'failed' === $mapped_status ? $verification['message'] : '' );

		Blink_Logger::log( 'Order updated successfully', array(
			'order_id' => $order->get_id(),
			'blink_res' => $order->get_meta('blink_res', true),
			'gateway_status' => $order->get_meta('_gateway_status', true),
		) );

		return $this->blink_webhook_response( 200 );
	}

	/**
	 * Run a webhook re-check scheduled while Blink could not be reached.
	 *
	 * @param array $args Scheduled 'request' and 'attempt'.
	 */
	public static function blink_recheck_webhook( $args ) {
		$gateway = self::blink_get_gateway();
		if ( ! $gateway || empty( $gateway->transaction_handler ) ) {
			Blink_Logger::log( 'Webhook re-check skipped: gateway unavailable' );
			return;
		}
		$gateway->transaction_handler->blink_recheck_webhook_request( $args );
	}

	/**
	 * @param array $args Scheduled 'request' and 'attempt'.
	 */
	public function blink_recheck_webhook_request( $args ) {
		$request = isset( $args['request'] ) && is_array( $args['request'] ) ? $args['request'] : array();
		$attempt = isset( $args['attempt'] ) ? (int) $args['attempt'] : 1;
		Blink_Logger::log( 'Webhook re-check', array( 'transaction_id' => isset( $request['transaction_id'] ) ? $request['transaction_id'] : '', 'attempt' => $attempt ) );
		$this->blink_process_webhook( $request, $attempt );
	}

	/**
	 * Find the order a webhook names, from merchant_data, the reference or a stored transaction ID.
	 *
	 * @return WC_Order|null
	 */
	private function blink_find_webhook_order( $request, $transaction_id ) {
		if ( '' === $transaction_id ) {
			return null;
		}
		$order_id      = '';
		$merchant_data = $this->blink_webhook_merchant_data( $request );

		if ( ! empty( $merchant_data['order_info']['order_id'] ) ) {
			$order_id = sanitize_text_field( $merchant_data['order_info']['order_id'] );
			Blink_Logger::log( 'Order ID from merchant_data', array( 'order_id' => $order_id ) );
		} elseif ( ! empty( $request['reference'] ) && is_scalar( $request['reference'] ) ) {
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

			// Check each order's meta data efficiently
			foreach ( (array) $recent_orders as $order_id_candidate ) {
				$candidate_order = wc_get_order( $order_id_candidate );
				if ( ! $candidate_order ) {
					continue;
				}
				if ( $candidate_order->get_transaction_id() === $transaction_id || $candidate_order->get_meta( 'blink_res', true ) === $transaction_id ) {
					$order_id = $order_id_candidate;
					break;
				}
			}
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		return $order ? $order : null;
	}

	/**
	 * merchant_data arrives as an array (form posts) or a JSON string (JSON bodies).
	 */
	private function blink_webhook_merchant_data( $request ) {
		$merchant_data = isset( $request['merchant_data'] ) ? $request['merchant_data'] : array();
		if ( is_string( $merchant_data ) && '' !== $merchant_data ) {
			$merchant_data = json_decode( $merchant_data, true );
		}
		return is_array( $merchant_data ) ? $merchant_data : array();
	}

	/**
	 * Look a webhook's Paylink or transaction up with Blink and check it belongs to the order.
	 *
	 * @return array 'result' (verified, unlinked or unavailable), the verified 'status'
	 *               ('' when a Paylink is not paid), 'mismatch' (null, or 'type' and
	 *               'detail' when it belongs to the order but does not fit it),
	 *               'paylink_id', 'message', 'payment_source' and 'reason'; for an
	 *               unpaid Paylink, its lower-case 'paylink_status' and details ('paylink').
	 */
	private function blink_verify_webhook( $order, $request, $transaction_id, $is_rerun ) {
		$paylink_id = ! empty( $request['paylink_id'] ) && is_scalar( $request['paylink_id'] ) ? sanitize_text_field( (string) $request['paylink_id'] ) : '';
		if ( ! $is_rerun && '' !== $paylink_id ) {
			return $this->blink_verify_paylink( $order, $paylink_id, $transaction_id );
		}
		return $this->blink_verify_transaction( $order, $transaction_id, $is_rerun );
	}

	private function blink_verify_paylink( $order, $paylink_id, $transaction_id ) {
		$lookup = $this->blink_fetch_from_blink( '/paylink/v1/paylinks/' . rawurlencode( $paylink_id ) );
		if ( 'ok' !== $lookup['state'] ) {
			return self::blink_verification( 'not_found' === $lookup['state'] ? 'unlinked' : 'unavailable', array( 'reason' => 'paylink not found' ) );
		}
		$paylink = isset( $lookup['body']['data'] ) && is_array( $lookup['body']['data'] ) ? $lookup['body']['data'] : $lookup['body'];
		if ( ! $this->blink_paylink_belongs_to_order( $order, $paylink_id, $paylink ) ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'paylink belongs to another order' ) );
		}

		// Only Paid means paid. A declined Paylink stays payable as "Payment
		// Attempted", which blink_get_status() would treat as complete.
		$paylink_status = isset( $paylink['status'] ) && is_scalar( $paylink['status'] ) ? strtolower( trim( (string) $paylink['status'] ) ) : '';
		if ( 'paid' !== $paylink_status ) {
			Blink_Logger::log( 'Paylink not paid; nothing to apply', array( 'order_id' => $order->get_id(), 'paylink_id' => $paylink_id, 'status' => $paylink_status ) );
			return self::blink_verification( 'verified', array( 'paylink_id' => $paylink_id, 'paylink_status' => $paylink_status, 'paylink' => $paylink ) );
		}

		// The transaction ID comes from the webhook and is stored for refunds and
		// captures, so it must be checked before the Paylink is applied. A Hosted
		// return carries none; the webhook checks and records it later.
		if ( '' !== $transaction_id ) {
			$transaction_check = $this->blink_verify_paylink_transaction( $order, $transaction_id, $paylink );
			if ( 'verified' !== $transaction_check['result'] ) {
				return $transaction_check;
			}
		}

		$type             = isset( $paylink['transaction_type'] ) && is_scalar( $paylink['transaction_type'] ) ? (string) $paylink['transaction_type'] : '';
		$paylink_currency = isset( $paylink['currency'] ) && is_scalar( $paylink['currency'] ) ? strtoupper( (string) $paylink['currency'] ) : '';
		$mismatch         = null;
		if ( ( 'preauth' === strtolower( $type ) ) !== (bool) blink_is_preauth_transaction( $order ) ) {
			$mismatch = array( 'type' => 'mode', 'detail' => sprintf( 'transaction type %s', $type ) );
		} elseif ( ! self::blink_amount_matches( isset( $paylink['amount'] ) ? $paylink['amount'] : null, $order->get_total() ) ) {
			$mismatch = array( 'type' => 'amount', 'detail' => sprintf( 'amount %s', isset( $paylink['amount'] ) && is_scalar( $paylink['amount'] ) ? $paylink['amount'] : '' ) );
		} elseif ( $paylink_currency !== strtoupper( (string) $order->get_currency() ) ) {
			$mismatch = array( 'type' => 'amount', 'detail' => sprintf( 'currency %s', $paylink_currency ) );
		}

		return self::blink_verification( 'verified', array( 'status' => 'Paid', 'mismatch' => $mismatch, 'paylink_id' => $paylink_id ) );
	}

	/**
	 * A Paylink belongs to the order when its ID was stored on the order, or, for
	 * Paylinks created before IDs were stored, when the redirect URL the plugin
	 * gave Blink carries this order's ID and key.
	 */
	private function blink_paylink_belongs_to_order( $order, $paylink_id, $paylink ) {
		foreach ( (array) $order->get_meta( '_blink_paylink_id', false ) as $meta ) {
			$value = is_object( $meta ) ? $meta->value : $meta;
			if ( (string) $value === (string) $paylink_id ) {
				return true;
			}
		}

		$redirect = isset( $paylink['redirect_url'] ) && is_string( $paylink['redirect_url'] ) ? $paylink['redirect_url'] : '';
		$query    = array();
		parse_str( (string) parse_url( $redirect, PHP_URL_QUERY ), $query );
		if ( isset( $query['order-received'] ) && is_scalar( $query['order-received'] ) ) {
			$received_id = (string) $query['order-received'];
		} else {
			$received_id = preg_match( '#/order-received/(\d+)/?$#', (string) parse_url( $redirect, PHP_URL_PATH ), $matches ) ? $matches[1] : '';
		}

		return '' !== $received_id
			&& (int) $received_id === (int) $order->get_id()
			&& ! empty( $query['key'] )
			&& is_string( $query['key'] )
			&& hash_equals( (string) $order->get_order_key(), $query['key'] );
	}

	/**
	 * A Paylink response has no transaction ID, and a Hosted transaction has no
	 * merchant_data, so nothing links the two directly. Accept the webhook's
	 * transaction only when Blink knows it, it is not a decline, its amount and
	 * currency match the Paylink, any merchant_data names this order, and no
	 * other order has recorded it.
	 */
	private function blink_verify_paylink_transaction( $order, $transaction_id, $paylink ) {
		$lookup = $this->blink_fetch_from_blink( '/pay/v1/transactions/' . rawurlencode( $transaction_id ) );
		if ( 'ok' !== $lookup['state'] ) {
			return self::blink_verification( 'not_found' === $lookup['state'] ? 'unlinked' : 'unavailable', array( 'reason' => 'paylink transaction not found' ) );
		}
		$transaction = isset( $lookup['body']['data'] ) && is_array( $lookup['body']['data'] ) ? $lookup['body']['data'] : array();
		$status      = isset( $transaction['status'] ) && is_scalar( $transaction['status'] ) ? (string) $transaction['status'] : '';
		if ( '' === $status || ( 'failed' === blink_get_status( $status ) && ! self::blink_is_preauth_status( $status ) ) ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'paylink transaction not successful' ) );
		}

		$paylink_amount   = isset( $paylink['amount'] ) ? $paylink['amount'] : null;
		$currency         = isset( $transaction['currency'] ) && is_scalar( $transaction['currency'] ) ? strtoupper( (string) $transaction['currency'] ) : '';
		$paylink_currency = isset( $paylink['currency'] ) && is_scalar( $paylink['currency'] ) ? strtoupper( (string) $paylink['currency'] ) : '';
		if ( ! self::blink_amount_matches( isset( $transaction['amount'] ) ? $transaction['amount'] : null, $paylink_amount )
			|| ( '' !== $currency && $currency !== $paylink_currency ) ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'paylink transaction amount differs' ) );
		}

		$merchant_data = isset( $transaction['merchant_data'] ) ? $transaction['merchant_data'] : null;
		if ( is_string( $merchant_data ) ) {
			$merchant_data = json_decode( $merchant_data, true );
		}
		if ( is_array( $merchant_data ) && isset( $merchant_data['order_info']['order_id'] ) && (int) $merchant_data['order_info']['order_id'] !== (int) $order->get_id() ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'paylink transaction belongs to another order' ) );
		}

		if ( $this->blink_transaction_recorded_elsewhere( $order, $transaction_id ) ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'paylink transaction recorded on another order' ) );
		}

		return self::blink_verification( 'verified' );
	}

	/**
	 * A captured pre-authorisation keeps its original ID only in blink_res and its
	 * capture in blink_rerun_id, so check those as well as the transaction ID.
	 */
	private function blink_transaction_recorded_elsewhere( $order, $transaction_id ) {
		$common = array(
			'limit'   => 1,
			'return'  => 'ids',
			'exclude' => array( $order->get_id() ),
		);
		$by_transaction = wc_get_orders( array_merge( $common, array( 'transaction_id' => $transaction_id ) ) );
		if ( ! empty( $by_transaction ) ) {
			return true;
		}
		foreach ( array( 'blink_res', 'blink_rerun_id' ) as $key ) {
			$by_meta = wc_get_orders( array_merge( $common, array(
				'meta_query' => array(
					array(
						'key'   => $key,
						'value' => $transaction_id,
					),
				),
			) ) );
			if ( ! empty( $by_meta ) ) {
				return true;
			}
		}
		return false;
	}

	private function blink_verify_transaction( $order, $transaction_id, $is_rerun ) {
		$lookup = $this->blink_fetch_from_blink( '/pay/v1/transactions/' . rawurlencode( $transaction_id ) );
		if ( 'ok' !== $lookup['state'] ) {
			return self::blink_verification( 'not_found' === $lookup['state'] ? 'unlinked' : 'unavailable', array( 'reason' => 'transaction not found' ) );
		}
		$transaction = isset( $lookup['body']['data'] ) && is_array( $lookup['body']['data'] ) ? $lookup['body']['data'] : array();
		$status      = isset( $transaction['status'] ) && is_scalar( $transaction['status'] ) ? sanitize_text_field( (string) $transaction['status'] ) : '';
		if ( '' === $status ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'transaction has no status' ) );
		}

		// merchant_data is set by the plugin in its server-side payment request.
		$merchant_order_id = null;
		$merchant_data     = isset( $transaction['merchant_data'] ) ? $transaction['merchant_data'] : null;
		if ( is_string( $merchant_data ) ) {
			$merchant_data = json_decode( $merchant_data, true );
		}
		if ( is_array( $merchant_data ) && isset( $merchant_data['order_info']['order_id'] ) && is_numeric( $merchant_data['order_info']['order_id'] ) ) {
			$merchant_order_id = (int) $merchant_data['order_info']['order_id'];
		}

		$amount_matches = self::blink_amount_matches( isset( $transaction['amount'] ) ? $transaction['amount'] : null, $order->get_total() );
		if ( $is_rerun ) {
			// Capture transactions carry no merchant_data. Accept the capture this
			// order recorded, or one this order is waiting on for the same amount
			// that no other order has recorded.
			$recorded = $transaction_id === (string) $order->get_meta( 'blink_rerun_id', true );
			$linked   = ( $recorded || ( $amount_matches && $this->blink_is_capture_pending( $order ) && ! $this->blink_transaction_recorded_elsewhere( $order, $transaction_id ) ) )
				&& ( null === $merchant_order_id || $merchant_order_id === (int) $order->get_id() );
		} else {
			$linked = $merchant_order_id === (int) $order->get_id();
		}
		if ( ! $linked ) {
			return self::blink_verification( 'unlinked', array( 'reason' => 'transaction belongs to another order' ) );
		}

		$mismatch = null;
		if ( ! $is_rerun && self::blink_is_preauth_status( $status ) && ! blink_is_preauth_transaction( $order ) ) {
			$mismatch = array( 'type' => 'mode', 'detail' => sprintf( 'status %s on a sale order', $status ) );
		} elseif ( ! $is_rerun && ! $amount_matches ) {
			$mismatch = array( 'type' => 'amount', 'detail' => sprintf( 'amount %s', isset( $transaction['amount'] ) && is_scalar( $transaction['amount'] ) ? $transaction['amount'] : '' ) );
		}

		return self::blink_verification( 'verified', array(
			'status'         => $status,
			'mismatch'       => $mismatch,
			'message'        => isset( $transaction['message'] ) && is_scalar( $transaction['message'] ) ? sanitize_text_field( (string) $transaction['message'] ) : '',
			'payment_source' => isset( $transaction['payment_source'] ) && is_scalar( $transaction['payment_source'] ) ? sanitize_text_field( (string) $transaction['payment_source'] ) : '',
		) );
	}

	private static function blink_verification( $result, $fields = array() ) {
		return array_merge(
			array( 'result' => $result, 'status' => '', 'mismatch' => null, 'paylink_id' => '', 'paylink_status' => '', 'paylink' => null, 'message' => '', 'payment_source' => '', 'reason' => '' ),
			$fields
		);
	}

	private function blink_is_capture_pending( $order ) {
		$rerun_handler = isset( $this->gateway->rerun_handler ) ? $this->gateway->rerun_handler : null;
		return ( is_object( $rerun_handler ) && $rerun_handler->is_rerun_in_progress( $order ) )
			|| 'yes' === $order->get_meta( '_blink_rerun_outcome_unknown', true );
	}

	private static function blink_is_preauth_status( $status ) {
		$status = preg_replace( '/[\s_-]+/', ' ', strtolower( trim( (string) $status ) ) );
		return in_array( $status, array( 'preauth', 'pre auth', 'preauthorized', 'pre authorized', 'preauthorised', 'pre authorised' ), true );
	}

	/**
	 * Compare amounts in pence: the transactions API returns numbers, Paylinks and
	 * WooCommerce return strings.
	 */
	private static function blink_amount_matches( $amount, $order_total ) {
		return is_numeric( $amount ) && is_numeric( $order_total )
			&& (int) round( (float) $amount * 100 ) === (int) round( (float) $order_total * 100 );
	}

	/**
	 * GET a Blink API resource.
	 *
	 * @return array 'state' (ok, not_found or unavailable) and, when ok, the decoded 'body'.
	 */
	private function blink_fetch_from_blink( $path ) {
		$token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $token ) || empty( $token['access_token'] ) ) {
			Blink_Logger::log( 'Blink verification: failed to get access token' );
			return array( 'state' => 'unavailable' );
		}

		$response = wp_remote_get(
			$this->gateway->host_url . $path,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
				'timeout' => 10, // Shorter timeout for webhook validation
			)
		);
		if ( is_wp_error( $response ) ) {
			Blink_Logger::log( 'Blink verification: API error', array( 'path' => $path, 'error' => $response->get_error_message() ) );
			return array( 'state' => 'unavailable' );
		}

		// Only the response code is logged: the body holds customer details.
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $code || 429 === $code || $code >= 500 ) {
			Blink_Logger::log( 'Blink verification: Blink unavailable', array( 'path' => $path, 'response_code' => $code ) );
			return array( 'state' => 'unavailable' );
		}
		if ( 200 !== $code ) {
			Blink_Logger::log( 'Blink verification: not found', array( 'path' => $path, 'response_code' => $code ) );
			return array( 'state' => 'not_found' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			Blink_Logger::log( 'Blink verification: unreadable response', array( 'path' => $path ) );
			return array( 'state' => 'unavailable' );
		}
		return array( 'state' => 'ok', 'body' => $body );
	}

	/**
	 * A genuine payment for this order that does not fit it (wrong amount,
	 * currency or transaction type). Money may have moved, so the merchant is told.
	 */
	private function blink_record_unexpected_payment( $order, $transaction_id, $status, $mismatch ) {
		if ( 'failed' === blink_get_status( $status, '', $order ) ) {
			/* translators: 1: transaction ID, 2: what does not match */
			$order->add_order_note( sprintf( __( 'Blink: ignored declined transaction %1$s because it does not match this order (%2$s).', 'blink-payment-gateway-for-woocommerce' ), $transaction_id, $mismatch['detail'] ) );
			$order->save();
			return;
		}

		/* translators: 1: transaction ID, 2: Blink status, 3: what does not match */
		$note = sprintf( __( 'Blink: payment %1$s (%2$s) does not match this order (%3$s), so the order has not been marked as paid. Check the Blink portal and reconcile the payment.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id, $status, $mismatch['detail'] );
		if ( 'amount' === $mismatch['type'] && $order->needs_payment() ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
		$order->save();
		Blink_Logger::log( 'Webhook payment does not match order', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'mismatch' => $mismatch['type'] ) );
	}

	/**
	 * Store the verified transaction of a Paylink a Hosted return already applied,
	 * for refunds and captures.
	 */
	private function blink_record_paylink_transaction( $order, $transaction_id, $status ) {
		$order->update_meta_data( 'blink_res', $transaction_id );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( 'status', $status );
		/* translators: %s: transaction ID */
		$order->add_order_note( sprintf( __( 'Blink: transaction %s recorded for the paid Paylink.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id ) );
		// A pre-authorisation can only be captured once its transaction is recorded.
		$this->blink_add_preauth_note( $order, $transaction_id, $status );
		$order->save();
		Blink_Logger::log( 'Paylink transaction recorded', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Tell the merchant how to take an authorised pre-authorisation.
	 */
	private function blink_add_preauth_note( $order, $transaction_id, $status ) {
		if ( ! blink_is_preauth_transaction( $order ) || 'hold' !== blink_get_status( $status, '', $order ) ) {
			return;
		}
		$preauth_note = __('Blink payment preauthorized (Transaction ID: ', 'blink-payment-gateway-for-woocommerce') . $transaction_id . '). ' .
					   __('Process order to take payment, or cancel to remove the pre-authorization. ', 'blink-payment-gateway-for-woocommerce') .
					   __('Refunding is unavailable until payment has been captured. ', 'blink-payment-gateway-for-woocommerce');
		$order->add_order_note( $preauth_note );
	}

	/**
	 * A verified payment event for an order that is already paid.
	 */
	private function blink_record_payment_on_paid_order( $order, $transaction_id, $status, $paylink_id ) {
		// The status comes from Blink's API, so a change to the payment this order
		// recorded is genuine, unlike a decline from some other attempt.
		if ( '' !== $transaction_id && $transaction_id === (string) $order->get_transaction_id() && 'complete' !== blink_get_status( $status, '', $order ) ) {
			$this->blink_record_reversal( $order, $transaction_id, $status );
			return;
		}

		if ( 'failed' === blink_get_status( $status, '', $order ) ) {
			/* translators: %s: transaction ID */
			$order->add_order_note( sprintf( __( 'Blink: ignored declined transaction %s because this order is already paid.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id ) );
			$order->save();
			return;
		}

		$same_payment = $transaction_id === (string) $order->get_transaction_id()
			|| ( '' !== $paylink_id && $paylink_id === (string) $order->get_meta( '_blink_paylink_paid_id', true ) );
		if ( $same_payment ) {
			Blink_Logger::log( 'Repeat webhook for a recorded payment ignored', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id ) );
			return;
		}

		/* translators: 1: transaction ID, 2: Blink status */
		$order->add_order_note( sprintf( __( 'Blink: a second payment (transaction %1$s, %2$s) was received for this order, which is already paid. Check the Blink portal; it may need refunding.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id, $status ) );
		$order->save();
	}

	/**
	 * Blink reports the paid order's own transaction is no longer successful
	 * (for example voided or reversed). Hold the order rather than fail it, so
	 * the merchant checks before fulfilling. A refund only gets a note, because
	 * refunds are normally made through WooCommerce.
	 */
	private function blink_record_reversal( $order, $transaction_id, $status ) {
		$order->update_meta_data( 'status', $status );
		Blink_Logger::log( 'Recorded payment no longer successful', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'status' => $status ) );

		if ( false !== strpos( strtolower( $status ), 'refund' ) ) {
			/* translators: 1: transaction ID, 2: Blink status */
			$order->add_order_note( sprintf( __( 'Blink: transaction %1$s is now %2$s.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id, $status ) );
			$order->save();
			return;
		}

		/* translators: 1: transaction ID, 2: Blink status */
		$order->update_status( 'on-hold', sprintf( __( 'Blink: transaction %1$s is now %2$s, so this order may no longer be paid. Check the Blink portal before fulfilling it.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id, $status ) );
		$order->save();
	}

	/**
	 * Blink retries a webhook that gets a 503 five times, five minutes apart.
	 * Re-check once those retries are over, in case Blink was down throughout.
	 */
	private function blink_schedule_webhook_recheck( $order, $request, $transaction_id, $attempt ) {
		if ( $attempt >= self::WEBHOOK_RECHECK_LIMIT ) {
			/* translators: %s: transaction ID */
			$order->add_order_note( sprintf( __( 'Blink: could not verify transaction %s with Blink. Check the Blink portal before fulfilling this order.', 'blink-payment-gateway-for-woocommerce' ), $transaction_id ) );
			$order->save();
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Blink_Logger::log( 'Webhook re-check not scheduled: Action Scheduler unavailable', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$args = array(
			array(
				'request' => $this->blink_webhook_recheck_request( $request ),
				'attempt' => $attempt + 1,
			),
		);
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::WEBHOOK_RECHECK_HOOK, $args, 'blink' ) ) {
			return;
		}
		as_schedule_single_action( time() + 30 * MINUTE_IN_SECONDS, self::WEBHOOK_RECHECK_HOOK, $args, 'blink' );
		Blink_Logger::log( 'Webhook re-check scheduled', array( 'order_id' => $order->get_id(), 'transaction_id' => $transaction_id, 'attempt' => $attempt + 1 ) );
	}

	/**
	 * The fields needed to verify the webhook again, without customer details.
	 */
	private function blink_webhook_recheck_request( $request ) {
		$recheck = array();
		foreach ( array( 'transaction_id', 'paylink_id', 'event_type', 'reference', 'status' ) as $key ) {
			if ( isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ) {
				$recheck[ $key ] = sanitize_text_field( (string) $request[ $key ] );
			}
		}
		$merchant_data = $this->blink_webhook_merchant_data( $request );
		if ( ! empty( $merchant_data['order_info']['order_id'] ) && is_numeric( $merchant_data['order_info']['order_id'] ) ) {
			$recheck['merchant_data'] = array( 'order_info' => array( 'order_id' => (int) $merchant_data['order_info']['order_id'] ) );
		}
		return $recheck;
	}

	/**
	 * Webhook responses never say whether an order exists or what state it is in.
	 */
	private function blink_webhook_response( $code ) {
		$bodies = array(
			200 => array( 'result' => 'ok' ),
			403 => array( 'error' => 'Forbidden' ),
			503 => array( 'error' => 'Unable to verify the transaction with Blink; retry later' ),
		);
		return array( 'code' => $code, 'body' => $bodies[ $code ] );
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
		$webhook_reference = ! empty( $request['reference'] ) && is_scalar( $request['reference'] ) ? sanitize_text_field( (string) $request['reference'] ) : '';
		if ( 'yes' === $order->get_meta( '_blink_rerun_outcome_unknown', true ) ) {
			$expected_reference = (string) $order->get_meta( '_blink_rerun_reference', true );
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
			$rerun_handler->record_rerun_failure( $order, $message, $transaction_id, $webhook_reference );
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
	 * Apply a payment return with a transaction ID. The return URL is
	 * customer-controlled, so only what Blink's API confirms for this order is
	 * applied, with the webhook's checks; its status and note are never used.
	 *
	 * Runs on woocommerce_thankyou_blink, which WooCommerce only reaches with a
	 * valid order key, and from blink_capture_order_response() once it has
	 * checked the key.
	 *
	 * @param int    $order_id       WooCommerce order ID.
	 * @param string $transaction_id Transaction ID from the return URL; the stored
	 *                               transaction when empty.
	 */
	public function blink_check_response_for_order( $order_id, $transaction_id = '' ) {
		$wc_order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $wc_order ) {
			return;
		}
		if ( ! $wc_order->needs_payment() ) {
			$this->blink_update_payment_message( $wc_order );
			return;
		}

		$stored = (string) $wc_order->get_meta( 'blink_res', true );
		if ( '' === $transaction_id ) {
			$transaction_id = $stored;
		}
		// A Hosted return carries no transaction ID; blink_capture_order_response()
		// checks its Paylink instead.
		if ( '' === $transaction_id ) {
			return;
		}
		// Already applied on an earlier load of this page.
		if ( $transaction_id === $stored && 'true' === $wc_order->get_meta( '_blink_res_expired', true ) ) {
			return;
		}

		$verification = $this->blink_verify_transaction( $wc_order, $transaction_id, false );
		$this->gateway->utils->blink_destroy_session_tokens( $wc_order->get_meta( '_blink_intent_id', true ) );
		// The webhook re-checks a payment Blink could not confirm and records one
		// that does not fit the order, so neither is applied here.
		if ( 'verified' !== $verification['result'] || null !== $verification['mismatch'] ) {
			Blink_Logger::log( 'Return not applied', array(
				'order_id'       => $order_id,
				'transaction_id' => $transaction_id,
				'result'         => $verification['result'],
				'reason'         => null !== $verification['mismatch'] ? $verification['mismatch']['type'] : $verification['reason'],
			) );
			return;
		}

		$status  = $verification['status'];
		$source  = $verification['payment_source'];
		$message = $verification['message'];
		$wc_order->update_meta_data( 'blink_res', $transaction_id );
		$wc_order->update_meta_data( 'status', $status );
		$wc_order->update_meta_data( '_blink_status', $status );
		$wc_order->update_meta_data( 'payment_type', $source );
		$wc_order->update_meta_data( '_blink_res_expired', 'true' );
		$wc_order->set_transaction_id( $transaction_id );
		$wc_order->add_order_note( __( 'Pay by ', 'blink-payment-gateway-for-woocommerce' ) . $source );
		if ( '' !== $message ) {
			$wc_order->add_order_note( __( 'Transaction Note: ', 'blink-payment-gateway-for-woocommerce' ) . $message );
		}
		$this->blink_add_preauth_note( $wc_order, $transaction_id, $status );
		$wc_order->save();
		Blink_Logger::log( 'Transaction handler processed', array( 'order_id' => $order_id, 'status' => $status ) );
		blink_change_status( $wc_order, $transaction_id, $status, $source, $message );
		if (
			$this->gateway->blink_is_hosted()
			&& $wc_order->has_status( 'failed' )
			&& '' === $message
			&& '' === $wc_order->get_meta( '_blink_payment_message', true )
		) {
			$message = self::blink_get_hosted_decline_message();
		}
		$this->blink_update_payment_message( $wc_order, $message );
		delete_transient( 'blink_3d_process' . $order_id );
		delete_transient( 'blink_3d_challenge_token_' . $order_id );
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
	 * The order's stored Paylink IDs, newest first.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @return string[]
	 */
	private function blink_stored_paylink_ids( $wc_order ) {
		$paylink_ids = array();
		foreach ( (array) $wc_order->get_meta( '_blink_paylink_id', false ) as $meta ) {
			$paylink_ids[] = (string) ( is_object( $meta ) ? $meta->value : $meta );
		}
		return array_values( array_reverse( array_unique( array_filter( $paylink_ids ) ) ) );
	}

	/**
	 * Look the order's stored Paylinks up with Blink, newest first, and apply a paid one.
	 *
	 * Used by the Hosted return and by each new Hosted payment attempt. Only a Paid
	 * Paylink that fits the order is applied, through the same path as the webhook,
	 * which later records the transaction ID.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @return array 'result': paid (applied), mismatch (paid but does not fit the
	 *               order; left for the webhook to reconcile), unavailable, or unpaid;
	 *               'payable': the newest Paylink's details when Blink still takes
	 *               payment on it (Unpaid or Payment Attempted), otherwise null.
	 */
	public function blink_check_hosted_paylinks( $wc_order ) {
		$paylink_ids = $this->blink_stored_paylink_ids( $wc_order );
		$payable     = null;

		foreach ( $paylink_ids as $index => $paylink_id ) {
			$verification = $this->blink_verify_paylink( $wc_order, $paylink_id, '' );
			if ( 'unavailable' === $verification['result'] ) {
				Blink_Logger::log( 'Hosted Paylinks not checked: Blink unavailable', array( 'order_id' => $wc_order->get_id(), 'paylink_id' => $paylink_id ) );
				return array( 'result' => 'unavailable', 'payable' => null );
			}
			if ( 'verified' !== $verification['result'] ) {
				continue;
			}
			if ( '' === $verification['status'] ) {
				if ( 0 === $index && in_array( $verification['paylink_status'], array( 'unpaid', 'payment attempted' ), true ) ) {
					$payable = $verification['paylink'];
				}
				continue;
			}
			if ( null !== $verification['mismatch'] ) {
				Blink_Logger::log( 'Hosted Paylink paid but does not fit the order; left for the webhook', array( 'order_id' => $wc_order->get_id(), 'paylink_id' => $paylink_id, 'mismatch' => $verification['mismatch']['type'] ) );
				return array( 'result' => 'mismatch', 'payable' => null );
			}

			$status = $verification['status'];
			$wc_order->update_meta_data( '_blink_paylink_paid_id', $paylink_id );
			$wc_order->update_meta_data( 'status', $status );
			$wc_order->update_meta_data( '_blink_status', $status );
			$wc_order->save();
			Blink_Logger::log( 'Hosted payment confirmed by Paylink', array( 'order_id' => $wc_order->get_id(), 'paylink_id' => $paylink_id ) );
			/* translators: %s: Paylink ID */
			blink_change_status( $wc_order, '', $status, '', sprintf( __( 'Blink confirmed Paylink %s is paid.', 'blink-payment-gateway-for-woocommerce' ), $paylink_id ) );
			$this->blink_update_payment_message( $wc_order );
			return array( 'result' => 'paid', 'payable' => null );
		}

		Blink_Logger::log( 'Hosted Paylinks checked: none paid', array( 'order_id' => $wc_order->get_id(), 'paylinks' => count( $paylink_ids ) ) );
		return array( 'result' => 'unpaid', 'payable' => $payable );
	}

	/**
	 * Process an authenticated Hosted return.
	 *
	 * The redirect carries no transaction ID and its status is customer-controlled,
	 * so only Blink's answer changes the order; the URL status only picks what the
	 * customer is told. A decline never fails the order: Blink keeps a declined
	 * Paylink payable, so the order stays Pending and the customer can pay again.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 * @param string   $status   Sanitized status from the redirect URL.
	 */
	private function blink_handle_hosted_return( $wc_order, $status ) {
		$status = strtolower( trim( $status ) );
		$check  = $this->blink_check_hosted_paylinks( $wc_order );
		// Blink records the payment before redirecting, but the Paylink API can
		// briefly lag behind, so a return that says Paid is checked once more.
		if ( 'paid' === $status && 'unpaid' === $check['result'] && array() !== $this->blink_stored_paylink_ids( $wc_order ) ) {
			$delay = (int) apply_filters( 'blink_hosted_return_recheck_delay', 1 );
			if ( $delay > 0 ) {
				sleep( $delay );
			}
			$check = $this->blink_check_hosted_paylinks( $wc_order );
		}

		if ( 'paid' === $check['result'] ) {
			return;
		}
		if ( 'mismatch' === $check['result'] ) {
			self::$hosted_return_notice = array( 'type' => 'mismatch', 'order_id' => $wc_order->get_id() );
		} elseif ( 'paid' === $status ) {
			self::$hosted_return_notice = array( 'type' => 'confirming', 'order_id' => $wc_order->get_id() );
		} elseif ( 'declined' === $status ) {
			self::$hosted_return_notice = array( 'type' => 'declined', 'order_id' => $wc_order->get_id() );
			if ( 'unpaid' === $check['result'] ) {
				$wc_order->add_order_note( __( 'Blink: the customer\'s payment was declined. The Paylink is still open, so the order stays pending and the customer can pay again.', 'blink-payment-gateway-for-woocommerce' ) );
				$wc_order->save();
			}
		}
		Blink_Logger::log( 'Hosted return not confirmed', array( 'order_id' => $wc_order->get_id(), 'url_status' => $status, 'result' => $check['result'] ) );
	}

	/**
	 * Page title for a Hosted return Blink has not confirmed as paid.
	 *
	 * Filters both the order-received endpoint title (Classic) and the order
	 * confirmation heading (Blocks); Blocks may pass no order, so only the return
	 * handled on this request is used.
	 *
	 * @param string $title Title WooCommerce would show.
	 * @return string
	 */
	public static function blink_hosted_return_title( $title ) {
		$titles = array(
			'confirming' => __( 'Confirming your payment', 'blink-payment-gateway-for-woocommerce' ),
			'declined'   => __( 'Payment declined', 'blink-payment-gateway-for-woocommerce' ),
			'mismatch'   => __( 'Payment needs checking', 'blink-payment-gateway-for-woocommerce' ),
		);
		$notice = self::$hosted_return_notice;
		return null !== $notice && isset( $titles[ $notice['type'] ] ) ? $titles[ $notice['type'] ] : $title;
	}

	/**
	 * Replace the order confirmation text for a Hosted return Blink has not
	 * confirmed as paid, so the customer is never thanked for an unpaid order.
	 *
	 * @param string        $text  Text WooCommerce would show.
	 * @param WC_Order|null $order Ignored: Blocks passes null for some statuses.
	 * @return string
	 */
	public static function blink_hosted_return_text( $text, $order = null ) {
		$notice = self::$hosted_return_notice;
		if ( null === $notice ) {
			return $text;
		}
		if ( 'mismatch' === $notice['type'] ) {
			return esc_html( __( 'We received a payment that does not match this order. Please contact us rather than paying again.', 'blink-payment-gateway-for-woocommerce' ) );
		}
		// Paying again is safe: the order's open Paylink is reused, and one already
		// paid completes the order instead of taking a second payment.
		$wc_order = wc_get_order( $notice['order_id'] );
		$pay_link = $wc_order ? ' <a href="' . esc_url( $wc_order->get_checkout_payment_url() ) . '" class="button pay">' . esc_html( __( 'Pay', 'blink-payment-gateway-for-woocommerce' ) ) . '</a>' : '';
		switch ( $notice['type'] ) {
			case 'confirming':
				return esc_html( __( 'We\'re confirming your payment. If you completed it, please don\'t pay again. If you didn\'t finish paying, you can return to your payment.', 'blink-payment-gateway-for-woocommerce' ) ) . $pay_link;
			case 'declined':
				return esc_html( self::blink_get_hosted_decline_message() ) . $pay_link;
		}
		return $text;
	}

	/**
	 * Return the safe generic message used for Hosted Paylink declines.
	 *
	 * @return string
	 */
	private static function blink_get_hosted_decline_message() {
		return __( 'Payment was declined. Please try again.', 'blink-payment-gateway-for-woocommerce' );
	}

	/**
	 * Process a return to the order-received page before it renders.
	 *
	 * Runs on wp for every order-received page, so nothing is trusted until the
	 * order key is checked. Nothing on the order changes once it is paid.
	 */
	public static function blink_capture_order_response() {
		global $wp;
		if ( empty( $wp->query_vars['order-received'] ) ) {
			return;
		}
		$order_id = apply_filters( 'woocommerce_thankyou_order_id', absint( $wp->query_vars['order-received'] ) );
		$wc_order = wc_get_order( $order_id );
		if ( empty( $wc_order ) || 'blink' !== $wc_order->get_payment_method() ) {
			return;
		}

		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method     = isset( $available_gateways['blink'] ) ? $available_gateways['blink'] : false;
		if ( ! $payment_method ) {
			return;
		}

		$order_key = isset( $_GET['key'] ) && is_scalar( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' === $order_key || ! hash_equals( (string) $wc_order->get_order_key(), $order_key ) ) {
			return;
		}
		if ( ! $wc_order->needs_payment() ) {
			return;
		}

		$instance = new self( $payment_method );
		// Blink appends the transaction ID to the Direct payment return URL.
		$transaction_id = isset( $_REQUEST['transaction_id'] ) && is_scalar( $_REQUEST['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['transaction_id'] ) ) : '';
		if ( '' !== $transaction_id ) {
			$instance->blink_check_response_for_order( $order_id, $transaction_id );
			return;
		}
		if ( ! $payment_method->blink_is_hosted() ) {
			return;
		}

		$status    = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$reference = isset( $_GET['reference'] ) && is_scalar( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
		if ( 'WC-' . $order_id !== $reference ) {
			return;
		}
		$instance->blink_handle_hosted_return( $wc_order, $status );
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
			// A concurrent request may already have captured it, in which case
			// this request was rejected under the lock and Processing is correct.
			$already_captured = '' !== (string) $order->get_meta( 'blink_rerun_id', true );
			if ( $already_captured ) {
				Blink_Logger::log(
					'Legacy pre-authorisation capture skipped; order already captured',
					array( 'order_id' => $order_id, 'error' => isset( $result['error'] ) ? $result['error'] : '' )
				);
				return;
			}
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

		$result = $gateway->rerun_handler->process_rerun( $order, true );
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
