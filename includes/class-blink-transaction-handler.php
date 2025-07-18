<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Transaction_Handler {

	protected $gateway;
	protected $token;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function cancel_transaction( $transaction_id ) {
		$url = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/cancels';

		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $this->token ) ) {
			return array( 'message' => __( 'Error creating access token', 'blink-payment-checkout' ) );
		}
		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		$response = wp_remote_post( $url, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( __( 'Error fetching transaction status: ', 'blink-payment-checkout' ) . $response->get_error_message(), 'error' );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return $data;
	}

	// New function to fetch transaction status
	public function get_transaction_status( $transaction_id, $order = null ) {
		$url         = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id;
		$data        = array();
		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( $this->token ) {
			// Prepare request headers
			$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

			$response = wp_remote_get( $url, array( 'headers' => $headers ) );

			if ( is_wp_error( $response ) ) {
				wc_add_notice( __( 'Error fetching transaction status: ', 'blink-payment-checkout' ) . $response->get_error_message(), 'error' );
				return;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ) );
		}

		$this->gateway->paymentSource = ! empty( $data->data->payment_source ) ? $data->data->payment_source : '';
		$this->gateway->paymentStatus = ! empty( $data->data->status ) ? $data->data->status : '';
	}

	/*
	 * In case we need a webhook, like PayPal IPN etc
	*/
	public function webhook() {
		global $wpdb;
		$order_id = '';
		$request  = isset( $_REQUEST['transaction_id'] ) ? $_REQUEST : file_get_contents( 'php://input' );
		if ( is_array( $request ) ) {
			$data = isset( $request['merchant_data'] ) ? stripslashes( $request['merchant_data'] ) : '';
			$request['merchant_data'] = json_decode( $data, true );
		} else {
			$request = json_decode( $request, true );
		}
		$transaction_id = ! empty( $request['transaction_id'] ) ? sanitize_text_field( $request['transaction_id'] ) : '';

		// Try to get order_id from merchant_data or reference
		if ( $transaction_id ) {
			$merchant_data = isset( $request['merchant_data'] ) ? $request['merchant_data'] : array();
			if ( ! empty( $merchant_data ) && ! empty( $merchant_data['order_info']['order_id'] ) ) {
				$order_id = sanitize_text_field( $merchant_data['order_info']['order_id'] );
			} elseif ( ! empty( $request['reference'] ) ) {
				// Try to extract order ID from reference, e.g. "WC-124"
				if ( preg_match( '/WC-(\d+)/', $request['reference'], $matches ) ) {
					$order_id = $matches[1];
				}
			}

			// Fallback: try to find order by transaction_id
			if ( ! $order_id ) {
				$order_id = wp_cache_get( 'order_id_' . $transaction_id, 'blink_payment' );
				if ( false === $order_id ) {
					$args = array(
						'post_type'   => 'shop_order',
						'meta_query'  => array(
							'relation' => 'OR',
							array(
								'key'   => '_transaction_id',
								'value' => $transaction_id,
							),
							array(
								'key'   => 'blink_res',
								'value' => $transaction_id,
							),
						),
						'fields'      => 'ids',
						'numberposts' => 1,
					);
					$order_ids = get_posts( $args );
					if ( ! empty( $order_ids ) ) {
						$order_id = $order_ids[0];
					}
					wp_cache_set( 'order_id_' . $transaction_id, $order_id, 'blink_payment', HOUR_IN_SECONDS );
				}
			}

			$status  = ! empty( $request['status'] ) ? $request['status'] : '';
			$note    = ! empty( $request['note'] ) ? $request['note'] : '';
			$order   = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_debug', $request );
				$order->update_meta_data( 'blink_res', $transaction_id );
				$order->set_transaction_id( $transaction_id );
				$order->update_meta_data( 'status', $status );
				blink_change_status( $order, $transaction_id, $status, '', $note );

				$response = array(
					'order_id'     => $order_id,
					'order_status' => $status,
				);
				echo wp_json_encode( $response );
				exit();
			}
		}
		$response = array(
			'transaction_id' => ! empty( $transaction_id ) ? $transaction_id : null,
			'error'          => __( 'No order found with this transaction ID', 'blink-payment-checkout' ),
		);
		echo wp_json_encode( $response );
		exit();
	}

	public function validate_transaction( $order, $transaction ) {
		$token        = $this->gateway->utils->blink_generate_access_token();
		$responseCode = ! empty( $transaction ) ? $transaction : '';
		$url          = $this->gateway->host_url . '/pay/v1/transactions/' . $responseCode;
		$response     = wp_remote_get(
			$url,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
			)
		);
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

		$this->gateway->utils->destroy_session_tokens();
		if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
			return ! empty( $api_body['data'] ) ? $api_body['data'] : array();
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
		}

		return array();
	}

	public function check_response_for_order( $order_id ) {
		if ( $order_id ) {
			$wc_order = wc_get_order( $order_id );
			if ( ! $wc_order->needs_payment() ) {
				return;
			}
			if ( 'true' == $wc_order->get_meta( '_blink_res_expired', true ) ) {
				return;
			}
			$transaction        = $wc_order->get_meta( 'blink_res', true );
			$transaction_result = $this->validate_transaction( $wc_order, $transaction );
			$status             = isset( $transaction_result['status'] ) ? $transaction_result['status'] : ( isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '' );
			$source             = ! empty( $transaction_result['payment_source'] ) ? $transaction_result['payment_source'] : '';
			$message            = isset( $transaction_result['message'] ) ? $transaction_result['message'] : ( isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '' );
			$wc_order->update_meta_data( '_blink_status', $status );
			$wc_order->update_meta_data( 'payment_type', $source );
			$wc_order->update_meta_data( '_blink_res_expired', 'true' );
			$wc_order->set_transaction_id( $transaction_result['transaction_id'] );
			$wc_order->add_order_note( __( 'Pay by ', 'blink-payment-checkout' ) . $source );
			$wc_order->add_order_note( __( 'Transaction Note: ', 'blink-payment-checkout' ) . $message );
			$wc_order->save();
			blink_change_status( $wc_order, $transaction_result['transaction_id'], $status, $source, $message );
		}
	}

	public static function check_order_response() {
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
				$instance->check_response_for_order( $order_id );
			}
		} else {
			$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
			$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
			$message   = isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '';
			blink_change_status( $wc_order, null, $status, $reference, $message );
		}
	}
}
