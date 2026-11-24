<?php
/**
 * Blink API helpers for access tokens and payment intents.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gets access tokens and creates or updates payment intents with the Blink API, caching both in transients.
 */
class Blink_PG_Payment_Utils {

	/**
	 * Seconds to wait for a Blink API request. Set on each request rather than
	 * through http_request_timeout, so other plugins and WordPress keep their own.
	 */
	const API_TIMEOUT = 30;

	/**
	 * Seconds to wait for Blink to issue an access token.
	 */
	const TOKEN_TIMEOUT = 45;

	/**
	 * The gateway these helpers belong to.
	 *
	 * @var Blink_PG_Payment_Gateway
	 */
	public $gateway;

	/**
	 * Current access token data.
	 *
	 * @var array
	 */
	public $token;

	/**
	 * Current payment intent data.
	 *
	 * @var array
	 */
	public $intent;

	/**
	 * Set up the helpers for a gateway.
	 *
	 * @param Blink_PG_Payment_Gateway $gateway The Blink gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Request a new access token from Blink with the gateway's API keys, retrying once if rate limited.
	 *
	 * @return array Token data, or an empty array on failure.
	 */
	public function blink_generate_access_token() {
		Blink_PG_Logger::log( 'Generating access token' );
		$url          = $this->gateway->host_url . '/pay/v1/tokens';
		$request_data = array(
			'api_key'                 => $this->gateway->api_key,
			'secret_key'              => $this->gateway->secret_key,
			'source_site'             => get_bloginfo( 'name' ),
			'application_name'        => 'Woocommerce Blink ' . $this->gateway->version,
			'application_description' => 'WP-' . get_bloginfo( 'version' ) . ' WC-' . WC_VERSION,
		);
		Blink_PG_Logger::log( 'blink_generate_access_token() POST tokens', $request_data );
		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => self::TOKEN_TIMEOUT,
				'body'    => $request_data,
			)
		);
		Blink_PG_Logger::log( 'blink_generate_access_token() POST tokens', Blink_PG_Logger::http_response_context( $response ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['retry-after'] ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$retry_after = $headers['retry-after'] + 2;
			sleep( $retry_after );
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'timeout' => self::TOKEN_TIMEOUT,
					'body'    => $request_data,
				)
			);
			Blink_PG_Logger::log( 'Access token retry response code', Blink_PG_Logger::http_response_context( $response ) );
		}
		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return $api_body;
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
			blink_pg_add_notice( $error );
			Blink_PG_Logger::log( 'Access token error', array( 'error' => $error ) );
		}

		return array();
	}

	/**
	 * Create a payment intent for an order or the cart, retrying once if rate limited.
	 *
	 * @param string            $method Payment method, such as credit-card.
	 * @param WC_Order|int|null $order  Order or order ID; null to use the cart.
	 * @param float|null        $amount Amount to charge when there is no order; null to use the cart total.
	 * @return array Intent data, or an empty array on failure.
	 */
	public function blink_create_payment_intent( $method = 'credit-card', $order = null, $amount = null ) {
		Blink_PG_Logger::log(
			'Creating payment intent',
			array(
				'method'   => $method,
				'order_id' => is_object( $order ) ? $order->get_id() : $order,
				'amount'   => $amount,
			)
		);
		$cart_amount = $amount;

		if ( $this->token ) {

			if ( null === $amount ) {

				if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
					$cart_amount = WC()->cart->get_total( 'raw' );
				}
			}

			if ( ! empty( $order ) && ! is_object( $order ) ) {
					$order = wc_get_order( $order );
			}

			$amount = ! empty( $order ) ? $order->get_total() : $cart_amount;

			if ( null !== $amount && (float) $amount <= 0 ) {
				Blink_PG_Logger::log( 'create_payment_intent: empty amount, aborting' );
				return array();
			}

			// Determine transaction type based on preauthorization setting.
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
			Blink_PG_Logger::log( 'create_payment_intent() POST intents', $request_data );
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::API_TIMEOUT,
					'method'  => 'POST',
					'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
					'body'    => $request_data,
				)
			);
			Blink_PG_Logger::log( 'create_payment_intent() POST intents', Blink_PG_Logger::http_response_context( $response ) );

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$headers = wp_remote_retrieve_headers( $response );
			if ( isset( $headers['retry-after'] ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$retry_after = $headers['retry-after'] + 2;
				sleep( $retry_after );
				$response = wp_remote_post(
					$url,
					array(
						'timeout' => self::API_TIMEOUT,
						'method'  => 'POST',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);
				Blink_PG_Logger::log( 'create_payment_intent retry response code', Blink_PG_Logger::http_response_context( $response ) );
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 201 === (int) wp_remote_retrieve_response_code( $response ) ) {
				Blink_PG_Logger::log( 'create_payment_intent success' );
				return $api_body;
			} else {
				$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				blink_pg_add_notice( $error );
				Blink_PG_Logger::log( 'create_payment_intent error', array( 'error' => $error ) );
			}
		}

		return array();
	}

	/**
	 * Update an existing payment intent, creating a new one if the update fails.
	 *
	 * @param string        $method Payment method, such as credit-card.
	 * @param WC_Order|null $order  WooCommerce order; null when there is no order yet.
	 * @param string|null   $id     ID of the intent to update.
	 * @param float|null    $amount Amount to charge when there is no order.
	 * @return array Intent data, or an empty array on failure.
	 */
	public function blink_update_payment_intent( $method = 'credit-card', $order = null, $id = null, $amount = null ) {
		Blink_PG_Logger::log(
			'Updating payment intent',
			array(
				'id'       => $id,
				'method'   => $method,
				'order_id' => is_object( $order ) ? $order->get_id() : $order,
				'amount'   => $amount,
			)
		);
		if ( $this->token ) {
			// Determine transaction type based on preauthorization setting.
			$transaction_type = $this->gateway->preauthorize_payments ? 'PREAUTH' : 'SALE';

			$request_data = array(
				'payment_type'     => $method,
				'transaction_type' => $transaction_type,
				'amount'           => ! empty( $order ) ? $order->get_total() : $amount,
				'return_url'       => $this->gateway->blink_get_return_url( $order ),
			);
			if ( $id ) {
				$url = $this->gateway->host_url . '/pay/v1/intents/' . $id;
				Blink_PG_Logger::log( 'update_payment_intent() PATCH intents', $request_data );
				$response = wp_remote_post(
					$url,
					array(
						'timeout' => self::API_TIMEOUT,
						'method'  => 'PATCH',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);
				Blink_PG_Logger::log( 'update_payment_intent() PATCH intents', Blink_PG_Logger::http_response_context( $response ) );

				if ( is_wp_error( $response ) ) {
					return array();
				}

				$headers = wp_remote_retrieve_headers( $response );
				if ( isset( $headers['retry-after'] ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
					$retry_after = $headers['retry-after'] + 2;
					sleep( $retry_after );
					$response = wp_remote_post(
						$url,
						array(
							'timeout' => self::API_TIMEOUT,
							'method'  => 'PATCH',
							'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
							'body'    => $request_data,
						)
					);
					Blink_PG_Logger::log( 'update_payment_intent retry response code', Blink_PG_Logger::http_response_context( $response ) );
				}

				$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
					Blink_PG_Logger::log( 'update_payment_intent success' );
					return $api_body;
				} else {
					$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
					blink_pg_add_notice( $error );
					Blink_PG_Logger::log( 'update_payment_intent error, falling back to create', array( 'error' => $error ) );
					return $this->blink_create_payment_intent( $method, $order, $amount );
				}
			}
		}

		return array();
	}


	/**
	 * Load the access token cached for an intent, or generate a new one if there is none or it has expired.
	 *
	 * @param string $intent_id Intent ID the token was cached under.
	 * @return array Token data, or an empty array on failure.
	 */
	public function blink_set_tokens( $intent_id = '' ) {
		Blink_PG_Logger::log( 'setTokens called', array( 'intent_id' => $intent_id ) );
		$token_key = $this->blink_get_token_transient_key( $intent_id );
		$cached    = ! empty( $token_key ) ? get_transient( $token_key ) : array();

		if ( ! empty( $cached['access_token'] ) ) {
			$this->token = $cached;
			if ( $this->blink_is_token_valid() ) {
				Blink_PG_Logger::log(
					'setTokens result',
					array(
						'has_token' => true,
						'from'      => 'transient',
					)
				);
				return $this->token;
			}
		}
		$token       = $this->blink_generate_access_token();
		$this->token = $token;
		Blink_PG_Logger::log( 'setTokens result', array( 'has_token' => ! empty( $this->token ) ) );
		return $this->token;
	}

	/**
	 * Whether the current token exists and is not expired.
	 *
	 * @return bool
	 */
	private function blink_is_token_valid() {
		if ( empty( $this->token['access_token'] ) ) {
			return false;
		}
		$t = $this->token;
		if ( ! empty( $t['expired_on'] ) && function_exists( 'blink_pg_check_timestamp_expired' ) ) {
			return 0 === blink_pg_check_timestamp_expired( $t['expired_on'] );
		}
		return true;
	}

	/**
	 * Get transient key for storing access token.
	 *
	 * @param string $intent_id Optional intent id from request.
	 * @return string Non-empty transient key.
	 */
	private function blink_get_token_transient_key( $intent_id = '' ) {
		if ( ! empty( $intent_id ) ) {
			return 'blink_pg_token_id_' . sanitize_key( $intent_id );
		}
		return '';
	}

	/**
	 * Get transient key for storing payment intent.
	 * When intent_id is provided, key is per-intent. When empty, key is per session/user.
	 *
	 * @param string $intent_id Optional intent id from request.
	 * @return string Non-empty transient key.
	 */
	private function blink_get_intent_transient_key( $intent_id = '' ) {
		if ( ! empty( $intent_id ) ) {
			return 'blink_pg_intent_id_' . sanitize_key( $intent_id );
		}
		return '';
	}

	/**
	 * Set or update the payment intent and store it in a transient.
	 *
	 * @param array         $request Request data containing payment method.
	 * @param WC_Order|null $order WooCommerce order object.
	 * @param float|null    $amount  Amount to charge when there is no order; null to use the cart total.
	 * @return array|null Payment intent data, or null when not on checkout, order-pay or a REST request.
	 */
	public function blink_set_intents( $request = array(), $order = null, $amount = null ) {

		$is_order_pay = is_wc_endpoint_url( 'order-pay' ) || ( ! empty( $request['order'] ) && is_numeric( $request['order'] ) );
		if ( ! blink_pg_is_rest_request() && ( ! is_checkout() && ! $is_order_pay ) ) {
			return;
		}

		if ( ! empty( $order ) ) {
			$amount = (float) $order->get_total();
		}

		Blink_PG_Logger::log(
			'setIntents called',
			array(
				'request_keys' => is_array( $request ) ? array_keys( $request ) : array(),
				'order_id'     => is_object( $order ) ? $order->get_id() : $order,
				'amount'       => $amount,
			)
		);

		// Default to 'credit-card' for unsupported or missing payment methods.
		$payment_method = ! empty( $request['payment_by'] ) && ! in_array( $request['payment_by'], array( 'google-pay', 'apple-pay' ), true )
			? $request['payment_by']
			: 'credit-card';

		$intent             = array();
		$intent_id          = ! empty( $request['intent_id'] ) ? $request['intent_id'] : '';
		$intent_expiry_date = ! empty( $request['intent_expiry_date'] ) ? $request['intent_expiry_date'] : '';

		if ( null !== $amount && (float) $amount <= 0 ) {
			if ( ! empty( $intent_id ) ) {
				$this->blink_destroy_session_tokens( $intent_id );
			}
			$this->intent = array();
			Blink_PG_Logger::log( 'setIntents result: no payment intent required for zero amount' );
			return array();
		}

		if ( ! empty( $intent_id ) && ! empty( $intent_expiry_date ) ) {
			$intent = array(
				'id'          => $intent_id,
				'expiry_date' => $intent_expiry_date,
			);
		}

		if ( ! $this->blink_is_token_valid() ) {
			$this->blink_set_tokens( $intent_id );
		}

		$transient_key = '';
		if ( empty( $intent ) ) {
			$transient_key = $this->blink_get_intent_transient_key( $intent_id );
			$intent        = ! empty( $transient_key ) ? get_transient( $transient_key ) : array();

		}

		$intent_expired = 1;

		if ( ! empty( $intent ) ) {
			if ( isset( $intent['expiry_date'] ) ) {
				$intent_expired = blink_pg_check_timestamp_expired( $intent['expiry_date'] );
			}
			if ( $intent_expired ) {
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

		if ( empty( $intent ) || empty( $intent['id'] ) ) {
			$this->intent = array();
			Blink_PG_Logger::log( 'setIntents result: empty intent' );
			return array();
		}

		$this->intent = $intent;

		$transient_key = $this->blink_get_intent_transient_key( $intent['id'] );
		set_transient( $transient_key, $intent, 15 * MINUTE_IN_SECONDS );

		$token_transient_key = $this->blink_get_token_transient_key( $intent['id'] );
		set_transient( $token_transient_key, $this->token, 15 * MINUTE_IN_SECONDS );

		Blink_PG_Logger::log(
			'setIntents result',
			array(
				'has_intent'    => ! empty( $this->intent ),
				'intent_id'     => isset( $this->intent['id'] ) ? $this->intent['id'] : null,
				'transient_key' => $transient_key,
			)
		);
		return $this->intent;
	}

	/**
	 * Delete the access token and payment intent cached for an intent.
	 *
	 * @param string $intent_id Intent ID.
	 */
	public function blink_destroy_session_tokens( $intent_id = '' ) {
		delete_transient( $this->blink_get_token_transient_key( $intent_id ) );
		$this->blink_destroy_session_intent( $intent_id );
	}

	/**
	 * Destroy session-specific payment intent (deletes session-scoped transient).
	 *
	 * @param string $intent_id Intent ID.
	 */
	public function blink_destroy_session_intent( $intent_id = '' ) {
		delete_transient( $this->blink_get_intent_transient_key( $intent_id ) );
	}
}
