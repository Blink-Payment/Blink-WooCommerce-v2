<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Payment_Utils {

	public $gateway;
	public $token;
	public $intent;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public static function extend_timeout( $time ) {
		return 10;
	}

	public function blink_generate_access_token() {
		$url          = $this->gateway->host_url . '/pay/v1/tokens';
		$request_data = array(
			'api_key'                 => $this->gateway->api_key,
			'secret_key'              => $this->gateway->secret_key,
			'source_site'             => get_bloginfo( 'name' ),
			'application_name'        => 'Woocommerce Blink ' . $this->gateway->version,
			'application_description' => 'WP-' . get_bloginfo( 'version' ) . ' WC-' . WC_VERSION,
		);
		$response     = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 45,
				'body'    => $request_data,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {
			$retry_after = $headers['retry-after'] + 2;
			sleep( $retry_after );
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'timeout' => 45,
					'body'    => $request_data,
				)
			);
		}
		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 == wp_remote_retrieve_response_code( $response ) ) {
			return $api_body;
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
			blink_add_notice( $error );
		}

		return array();
	}

	public function create_payment_intent( $method = 'credit-card', $order = null, $amount = null ) {
		$cart_amount = $amount; 

		if ( $this->token ) {

			if($amount === null) {

				if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
					$cart_amount = WC()->cart->get_total( 'raw' );
				}
			}

			if ( ! empty( $order ) && ! is_object( $order ) ) {
					$order = wc_get_order( $order );
			}

			$amount       = ! empty( $order ) ? $order->get_total() : $cart_amount;

			if ( empty( $amount ) ) {
				return array();
			}

			$request_data = array(
				'card_layout'      => 'single-line',
				'amount'           => $amount,
				'payment_type'     => $method,
				'currency'         => get_woocommerce_currency(),
				'return_url'       => $this->gateway->get_return_url( $order ),
				'notification_url' => WC()->api_request_url( 'blink_gateway' ),
			);
			$url          = $this->gateway->host_url . '/pay/v1/intents';
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
					'body'    => $request_data,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$headers = wp_remote_retrieve_headers( $response );
			if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {
				$retry_after = $headers['retry-after'] + 2;
				sleep( $retry_after );
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'POST',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 201 == wp_remote_retrieve_response_code( $response ) ) {
				return $api_body;
			} else {
				$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				blink_add_notice( $error );
			}
		}

		return array();
	}

	public function update_payment_intent( $method = 'credit-card', $order = null, $id = null, $amount = null  ) {
		if ( $this->token ) {
			$request_data = array(
				'payment_type' => $method,
				'amount'       => ! empty( $order ) ? $order->get_total() : $amount,
				'return_url'   => $this->gateway->get_return_url( $order ),
			);
			if ( $id ) {
				$url      = $this->gateway->host_url . '/pay/v1/intents/' . $id;
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'PATCH',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);

				if ( is_wp_error( $response ) ) {
					return array();
				}

				$headers = wp_remote_retrieve_headers( $response );
				if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {
					$retry_after = $headers['retry-after'] + 2;
					sleep( $retry_after );
					$response = wp_remote_post(
						$url,
						array(
							'method'  => 'PATCH',
							'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
							'body'    => $request_data,
						)
					);
				}

				$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
					return $api_body;
				} else {
					$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
					blink_add_notice( $error );
					return $this->create_payment_intent( $method, $order, $amount);
				}
			}
		}

		return array();
	}


	public function setTokens() {
			$token = $this->blink_generate_access_token();
			$this->token = $token;

		return $this->token;
	}

	/**
	 * Set or update the payment intent and store it in a transient.
	 *
	 * @param array $request Request data containing payment method.
	 * @param WC_Order|null $order WooCommerce order object.
	 * @return array Payment intent data.
	 */
	public function setIntents( $request = array(), $order = null, $amount = null ) {

		$this->setTokens();

		// Default to 'credit-card' for unsupported or missing payment methods.
		$payment_method = !empty( $request['payment_by'] ) && ! in_array( $request['payment_by'], array( 'google-pay', 'apple-pay' ) )
			? $request['payment_by']
			: 'credit-card';

		$intent = get_transient( 'blink_intent' );

		$intent_expired = 1;

		if ( ! empty( $intent ) ) {
			if ( isset( $intent['expiry_date'] ) ) {
				$intent_expired = blink_check_timestamp_expired( $intent['expiry_date'] );
			}
			if (  $intent_expired ) {

				// Create a new payment intent if expired or not set.
				$intent = $this->create_payment_intent( $payment_method, $order, $amount );
			} elseif ( ! empty( $order ) && isset( $intent['id'] ) ) {
				// Update the existing payment intent if possible.
				$intent = $this->update_payment_intent( $payment_method, $order, $intent['id'], $amount );
			} else {
				$intent = $this->update_payment_intent( $payment_method, null, $intent['id'], $amount );
			}
		} else {
			// Create a new payment intent if none exists.
			$intent = $this->create_payment_intent( $payment_method, $order, $amount );
		}

		$this->intent = $intent;

		set_transient( 'blink_intent', $intent, 15 * MINUTE_IN_SECONDS );

		return $this->intent;
	}

	public function destroy_session_tokens() {
		delete_transient( 'blink_token' );
		$this->destroy_session_intent();
	}

	public function destroy_session_intent() {
		delete_transient( 'blink_intent' );
	}
}

add_filter( 'http_request_timeout', array( 'Blink_Payment_Utils', 'extend_timeout' ) );
