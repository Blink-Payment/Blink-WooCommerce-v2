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
		Blink_Logger::log( 'Generating access token' );
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
		Blink_Logger::log( 'Access token response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );

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
			Blink_Logger::log( 'Access token retry response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );
		}
		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 == wp_remote_retrieve_response_code( $response ) ) {
			return $api_body;
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
			blink_add_notice( $error );
			Blink_Logger::log( 'Access token error', array( 'error' => $error ) );
		}

		return array();
	}

	public function blink_create_payment_intent( $method = 'credit-card', $order = null, $amount = null ) {
		Blink_Logger::log( 'Creating payment intent', array( 'method' => $method, 'order_id' => is_object( $order ) ? $order->get_id() : $order, 'amount' => $amount ) );
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
				Blink_Logger::log( 'create_payment_intent: empty amount, aborting' );
				return array();
			}

			// Determine transaction type based on preauthorization setting
			$transaction_type = $this->gateway->preauthorize_payments ? 'PREAUTH' : 'SALE';
			
			$request_data = array(
				'amount'           => $amount,
				'payment_type'     => $method,
				'transaction_type' => $transaction_type,
				'currency'         => get_woocommerce_currency(),
				'return_url'       => $this->gateway->blink_get_return_url( $order ),
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
			Blink_Logger::log( 'create_payment_intent response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );

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
				Blink_Logger::log( 'create_payment_intent retry response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 201 == wp_remote_retrieve_response_code( $response ) ) {
				Blink_Logger::log( 'create_payment_intent success' );
				return $api_body;
			} else {
				$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				blink_add_notice( $error );
				Blink_Logger::log( 'create_payment_intent error', array( 'error' => $error ) );
			}
		}

		return array();
	}

	public function blink_update_payment_intent( $method = 'credit-card', $order = null, $id = null, $amount = null  ) {
		Blink_Logger::log( 'Updating payment intent', array( 'id' => $id, 'method' => $method, 'order_id' => is_object( $order ) ? $order->get_id() : $order, 'amount' => $amount ) );
		if ( $this->token ) {
			// Determine transaction type based on preauthorization setting
			$transaction_type = $this->gateway->preauthorize_payments ? 'PREAUTH' : 'SALE';
			
			$request_data = array(
				'payment_type'     => $method,
				'transaction_type' => $transaction_type,
				'amount'           => ! empty( $order ) ? $order->get_total() : $amount,
				'return_url'       => $this->gateway->blink_get_return_url( $order ),
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
				Blink_Logger::log( 'update_payment_intent response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );

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
					Blink_Logger::log( 'update_payment_intent retry response code', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );
				}

				$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
					Blink_Logger::log( 'update_payment_intent success' );
					return $api_body;
				} else {
					$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
					blink_add_notice( $error );
					Blink_Logger::log( 'update_payment_intent error, falling back to create', array( 'error' => $error ) );
					return $this->blink_create_payment_intent( $method, $order, $amount);
				}
			}
		}

		return array();
	}


	public function blink_set_tokens() {
		Blink_Logger::log( 'setTokens called' );
			$token = $this->blink_generate_access_token();
			$this->token = $token;
			Blink_Logger::log( 'setTokens result', array( 'has_token' => ! empty( $this->token ) ) );

		return $this->token;
	}

	/**
	 * Get a unique transient key for storing payment intent.
	 * @return string Transient key.
	 */
	private function blink_get_intent_transient_key() {
		
		if ( is_user_logged_in() ) {
			return 'blink_intent_user_' . absint( get_current_user_id() );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_key = WC()->session->get( 'blink_intent_session_key' );
			if ( empty( $session_key ) ) {
				$session_key = wp_generate_uuid4();
				WC()->session->set( 'blink_intent_session_key', $session_key );
			}
			return 'blink_intent_session_' . sanitize_key( $session_key );
		}

		$unique_id = wp_generate_uuid4();
		return 'blink_intent_unique_' . sanitize_key( $unique_id );
	}

	/**
	 * Set or update the payment intent and store it in a transient.
	 *
	 * @param array $request Request data containing payment method.
	 * @param WC_Order|null $order WooCommerce order object.
	 * @return array Payment intent data.
	 */
	public function blink_set_intents( $request = array(), $order = null, $amount = null ) {

		$is_order_pay = is_wc_endpoint_url( 'order-pay' ) || ( ! empty( $request['order'] ) && is_numeric( $request['order'] ) );
		if ( !blink_is_rest_request() && (! is_checkout() && ! $is_order_pay )) {
			return;
		}

		if ( isset( $_GET['blink_3d_nonce'] ) ) {
			return;
		}

		if ( ! empty( $order ) ) {
			$amount = (float) $order->get_total();
		}

		Blink_Logger::log( 'setIntents called', array( 'request_keys' => is_array( $request ) ? array_keys( $request ) : array(), 'order_id' => is_object( $order ) ? $order->get_id() : $order, 'amount' => $amount ) );

		$this->blink_set_tokens();

		// Default to 'credit-card' for unsupported or missing payment methods.
		$payment_method = !empty( $request['payment_by'] ) && ! in_array( $request['payment_by'], array( 'google-pay', 'apple-pay' ) )
			? $request['payment_by']
			: 'credit-card';

		$intent = array();
		$intent_id = ! empty( $request['intent_id'] ) ? $request['intent_id'] : '';
		$intent_expiry_date = ! empty( $request['intent_expiry_date'] ) ? $request['intent_expiry_date'] : '';

		if ( ! empty( $intent_id ) && ! empty( $intent_expiry_date ) ) {
			$intent = array(
				'id' => $intent_id,
				'expiry_date' => $intent_expiry_date,
			);
		}
		$transient_key = '';
		if ( empty( $intent ) ) {
			$transient_key = $this->blink_get_intent_transient_key();
			$intent = get_transient( $transient_key );
		}


		$intent_expired = 1;

		if ( ! empty( $intent ) ) {
			if ( isset( $intent['expiry_date'] ) ) {
				$intent_expired = blink_check_timestamp_expired( $intent['expiry_date'] );
			}
			if (  $intent_expired ) {
				// Create a new payment intent if expired or not set.
				$intent = $this->blink_create_payment_intent( $payment_method, $order, $amount );
			} elseif ( ! empty( $order ) && isset( $intent['id'] ) ) {
				$intent = $this->blink_update_payment_intent( $payment_method, $order, $intent['id'], $amount );
			} else {
				$intent = $this->blink_update_payment_intent( $payment_method, null, $intent['id'], $amount );
			}
		} else {
			// Create a new payment intent if none exists.
			$intent = $this->blink_create_payment_intent( $payment_method, $order, $amount );
		}

		$this->intent = $intent;

		if ( ! empty( $transient_key ) ) {
			set_transient( $transient_key, $intent, 15 * MINUTE_IN_SECONDS );
		}
		Blink_Logger::log( 'setIntents result', array( 'has_intent' => ! empty( $this->intent ), 'intent_id' => isset( $this->intent['id'] ) ? $this->intent['id'] : null, 'transient_key' => $transient_key ) );
		return $this->intent;
	}

	public function blink_destroy_session_tokens() {
		delete_transient( 'blink_token' );
		$this->blink_destroy_session_intent();
	}

	/**
	 * Destroy session-specific payment intent.
	 * Also cleans up the old global transient for backward compatibility.
	 * @param WC_Order|null $order Optional order object to destroy order-specific intent.
	 */
	public function blink_destroy_session_intent( $order = null ) {
		// Delete the old global transient for backward compatibility
		delete_transient( 'blink_intent' );

		// Delete session/order-specific transient if provided
		if ( ! empty( $order ) ) {
			$transient_key = $this->blink_get_intent_transient_key( $order );
			delete_transient( $transient_key );
		} else {
			// Try to delete current session's intent
			$transient_key = $this->blink_get_intent_transient_key();
			delete_transient( $transient_key );
		}
	}
}

add_filter( 'http_request_timeout', array( 'Blink_Payment_Utils', 'extend_timeout' ) );
