<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class Blink_Payment_Handler {

	protected $gateway;
	protected $token;
	protected $intent;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function blink_process_open_banking( $order, $request ) {
		Blink_Logger::log( 'process_open_banking called', array( 'order_id' => $order->get_id() ) );
		$order_id   = $order->get_id();
		$return_arr = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {
			$request_data = array(
				'merchant_id'       => $this->intent['merchant_id'],
				'payment_intent'    => $this->intent['payment_intent'],
				'user_name'         => ! empty( $request['customer_name'] ) ? sanitize_text_field( $request['customer_name'] ) : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'user_email'        => ! empty( $request['customer_email'] ) ? sanitize_email( $request['customer_email'] ) : $order->get_billing_email(),
				'customer_address'  => ! empty( $request['customer_address'] ) ? sanitize_text_field( $request['customer_address'] ) : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode' => ! empty( $request['customer_postcode'] ) ? sanitize_text_field( $request['customer_postcode'] ) : $order->get_billing_postcode(),
				'merchant_data'     => blink_get_payment_information( $order_id ),
			);
			$url          = $this->gateway->host_url . '/pay/v1/openbankings';
			Blink_Logger::log( 'blink_process_open_banking() POST openbankings', $request_data );
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			Blink_Logger::log( 'blink_process_open_banking() POST openbankings', Blink_Logger::http_response_context( $response ) );

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;
				Blink_Logger::log( 'process_open_banking response', $api_body );

				if ( ! empty( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				} elseif ( ! empty( $api_body['redirect_url'] ) ) {
					$return_arr['redirect_url'] = $api_body['redirect_url'];
				}
				Blink_Logger::log( 'process_open_banking success', array( 'redirect_url' => $return_arr['redirect_url'] ) );
			} 
			if(! empty( $api_body['error'] )) {
				$error                 = ! empty( $api_body['error_response'] ) ? $api_body['error_response'] : $api_body['error'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
				Blink_Logger::log( 'process_open_banking error', array( 'error' => $error ) );
			}
		}

		return $return_arr;
	}

	public function blink_process_direct_debit( $order, $request ) {
		Blink_Logger::log( 'process_direct_debit called', array( 'order_id' => $order->get_id() ) );
		$order_id   = $order->get_id();
		$return_arr = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {
			$request_data = array(
				'payment_intent'      => $this->intent['payment_intent'],
				'given_name'          => ! empty( $request['given_name'] ) ? $request['given_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'family_name'         => $request['family_name'],
				'company_name'        => $request['company_name'],
				'email'               => ! empty( $request['email'] ) ? $request['email'] : $order->get_billing_email(),
				'country_code'        => get_woocommerce_currency(),
				'account_holder_name' => $request['account_holder_name'],
				'branch_code'         => $request['branch_code'],
				'account_number'      => $request['account_number'],
				'customer_address'    => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode'   => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'merchant_data'       => blink_get_payment_information( $order_id ),
			);
			$url          = $this->gateway->host_url . '/pay/v1/directdebits';
			Blink_Logger::log( 'blink_process_direct_debit() POST directdebits', $request_data );
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			Blink_Logger::log( 'blink_process_direct_debit() POST directdebits', Blink_Logger::http_response_context( $response ) );

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;
				if ( ! empty( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
				Blink_Logger::log( 'process_direct_debit success', array( 'redirect_url' => $return_arr['redirect_url'] ) );
			} 
			if( ! empty( $api_body['error'] )) {
				$error                 = ! empty( $api_body['error_response'] ) ? $api_body['error_response'] : $api_body['error'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
				Blink_Logger::log( 'process_direct_debit error', array( 'error' => $error ) );
			}
		}

		return $return_arr;
	}

	public function blink_process_credit_card( $order, $request, $endpoint = 'creditcards' ) {
		Blink_Logger::log( 'process_credit_card called', array( 'order_id' => $order->get_id(), 'endpoint' => $endpoint ) );
		$cart_amount = null; 

		if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
			$cart_amount = WC()->cart->get_total( 'raw' );
		}

		$amount      = ! empty( $order ) ? $order->get_total() : $cart_amount;

		if ( empty( $amount ) ) {
				Blink_Logger::log( 'process_credit_card: empty amount' );
				return array(
				'success'      => false,
				'redirect_url' => false,
				'error'        => 'Invalid order.',
			);
		}
		
		$return_arr  = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		if ( ! empty( $request['paymenttoken'] ) ) {
			$request['paymentToken'] = $request['paymenttoken'];
		}

		if ( empty( $request['paymentToken'] ) ) {
			Blink_Logger::log( 'process_credit_card: missing payment token' );
			return array(
				'success'      => false,
				'redirect_url' => false,
				'error'        => __( 'Invalid Payment Token!', 'blink-payment-gateway-for-woocommerce' ),
			);
		}

		$order_id = $order->get_id();
		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {
			// Determine transaction type based on preauthorization setting
			$transaction_type = $this->gateway->preauthorize_payments ? 'PREAUTH' : 'SALE';
			
			$request_data = array(
				'resource'           => $endpoint,
				'payment_intent'     => $this->intent['payment_intent'],
				'paymentToken'       => $request['paymentToken'],
				'type'               => $request['type'],
				'transaction_type'   => $transaction_type,
				'raw_amount'         => $amount,
				'customer_email'     => ! empty( $request['customer_email'] ) ? $request['customer_email'] : $order->get_billing_email(),
				'customer_name'      => ! empty( $request['customer_name'] ) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'customer_address'   => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode'  => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'transaction_unique' => 'WC-' . $order_id,
				'merchant_data'      => blink_get_payment_information( $order_id ),
			);

			$request_data['device_timezone']          = $request['device_timezone'];
			$request_data['device_capabilities']      = $request['device_capabilities'];
			$request_data['device_accept_language']   = $request['device_accept_language'];
			$request_data['device_screen_resolution'] = $request['device_screen_resolution'];

			if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				$ip = explode(',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ))[0];
			} else {
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			}

			$request_data['remote_address']           = $ip;
			$request_data['device_ip_address']        = $ip;

			$url = $this->gateway->host_url . '/pay/v1/' . $endpoint;
			Blink_Logger::log( 'blink_process_credit_card() POST ' . $endpoint, $request_data );
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			Blink_Logger::log( 'blink_process_credit_card() POST ' . $endpoint, Blink_Logger::http_response_context( $response ) );

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;

					if ( isset( $api_body['acsform'] ) ) {
							$threedToken = $api_body['acsform'];
						set_transient( 'blink_3d_process' . $order_id, $threedToken, 300 );
						$nonce = wp_generate_uuid4();
						set_transient( 'blink_3d_challenge_token_' . $order_id, $nonce, 300 );
						$return_arr['redirect_url'] = blink_get_3ds_challenge_url( $order_id, $nonce );
					} elseif ( isset( $api_body['url'] ) ) {
						$return_arr['redirect_url'] = $api_body['url'];
					}
					Blink_Logger::log( 'process_credit_card success', array( 'redirect_url' => $return_arr['redirect_url'] ) );
			} else {
				if ( isset( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
			}
			if( ! empty( $api_body['error'] )) {
				$error                 = ! empty( $api_body['error_response'] ) ? $api_body['error_response'] : $api_body['error'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
				Blink_Logger::log( 'process_credit_card error', array( 'error' => $error ) );
			}
		}

		return $return_arr;
	}

	public function blink_handle_payment( $order_id ) {

		Blink_Logger::log( 'handle_payment called', array( 'order_id' => $order_id ) );
		$order   = wc_get_order( $order_id );
		if ( ! $order ) {
			Blink_Logger::log( 'handle_payment error: invalid order', array( 'order_id' => $order_id ) );
			return blink_error_payment_process( __( 'Invalid order.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$request = $_POST;
		$order_total = (float) $order->get_total();
		if ( $order_total <= 0 ) {
			Blink_Logger::log( 'handle_payment zero total order', array( 'order_id' => $order_id ) );
			return array(
				'result'   => 'success',
				'redirect' => $this->gateway->blink_get_return_url( $order ),
			);
		}

		$intent_id = ! empty( $request['intent_id'] ) ? $request['intent_id'] : '';
		$this->token  = $this->gateway->utils->blink_set_tokens($intent_id);
		$order->add_meta_data( '_blink_intent_id', $intent_id );
		$order->save();
		if ( method_exists( $this->gateway, 'blink_is_hosted' ) && $this->gateway->blink_is_hosted() ) {
			return $this->blink_handle_hosted_payment( $order, $request );
		}

		$this->intent = $this->gateway->utils->blink_set_intents( $request, $order );

		if ( empty( $this->intent ) || empty( $this->token ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) ) {
				return;
			}
			Blink_Logger::log( 'handle_payment error: missing intent or token' );
			return blink_error_payment_process( __( 'Missing intent or token', 'blink-payment-gateway-for-woocommerce' ) );
		}

		// Decode payment tokens if present
		if ( ! empty( $request['paymenttoken'] ) ) {
			$token_array = json_decode( $request['paymenttoken'], true );
			if ( ! empty( $token_array ) ) {
				$request['paymenttoken'] = $token_array;
			}
		}
		if ( ! empty( $request['paymentToken'] ) ) {
			$token_array = json_decode( wp_unslash( $request['paymentToken'] ), true );
			if ( ! empty( $token_array ) ) {
				$request['paymentToken'] = $token_array;
			}
		}

		$payment_by = isset( $request['payment_by'] ) ? sanitize_text_field( wp_unslash( $request['payment_by'] ) ) : '';
		$supported_methods = array();
		if ( ! empty( $this->gateway->paymentMethods ) && is_array( $this->gateway->paymentMethods ) ) {
			$supported_methods = $this->gateway->paymentMethods;
		}

		if ( empty( $payment_by ) ) {
			Blink_Logger::log( 'handle_payment error: missing payment_by', array( 'order_id' => $order_id ) );
			return blink_error_payment_process( __( 'Unable to determine Blink payment method.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		if ( empty( $supported_methods ) || ! in_array( $payment_by, $supported_methods, true ) ) {
			Blink_Logger::log( 'handle_payment error: unsupported payment_by', array( 'order_id' => $order_id, 'payment_by' => $payment_by ) );
			return blink_error_payment_process( __( 'Unsupported Blink payment method.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$response = null;

		switch ( $payment_by ) {
			case 'credit-card':
				if ( isset( $_REQUEST['credit-card-data'] ) ) {
					parse_str( sanitize_text_field( wp_unslash( $_REQUEST['credit-card-data'] ) ), $parsed_data );
					$parsed_data['customer_name']  = sanitize_text_field( $request['customer_name'] );
					$parsed_data['customer_email'] = sanitize_email( $request['customer_email'] );
					$request = array_merge( $request, $parsed_data );
				}
				$response = $this->blink_process_credit_card( $order, $request );
				break;
			case 'google-pay':
				$response = $this->blink_process_credit_card( $order, $request, 'googlepay' );
				break;
			case 'apple-pay':
				$response = $this->blink_process_credit_card( $order, $request, 'applepay' );
				break;
			case 'direct-debit':
				$response = $this->blink_process_direct_debit( $order, $request );
				break;
			case 'open-banking':
				$response = $this->blink_process_open_banking( $order, $request );
				break;
		}

		if ( ! is_array( $response ) || ! array_key_exists( 'success', $response ) ) {
			Blink_Logger::log( 'handle_payment failed: invalid processor response', array( 'payment_by' => $payment_by ) );
			$this->gateway->utils->blink_destroy_session_tokens( $intent_id );
			return blink_error_payment_process( __( 'Unable to complete Blink payment. Please try again.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		if ( ! $response['success'] ) {
			Blink_Logger::log( 'handle_payment failed', array( 'error' => $response['error'] ) );
			$this->gateway->utils->blink_destroy_session_tokens($intent_id);
			return blink_error_payment_process( ! empty( $response['error'] ) ? $response['error'] : __( 'Unable to complete Blink payment. Please try again.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		Blink_Logger::log( 'handle_payment success', array( 'redirect' => $response['redirect_url'] ) );
		return array(
			'result'   => 'success',
			'redirect' => $response['redirect_url'],
		);
	}

	/**
	 * Handle hosted payment (paylink API).
	 */
	public function blink_handle_hosted_payment( $order, $request ) {
		Blink_Logger::log( 'handle_hosted_payment called', array( 'order_id' => $order->get_id() ) );
		$order_id = $order->get_id();


		// Prepare paylink API data
		$payment_methods = array();
		if ( isset( $this->gateway->paymentMethods ) && is_array( $this->gateway->paymentMethods ) ) {
			$payment_methods = $this->gateway->paymentMethods;
		} else {
			$payment_methods = array( 'credit-card' );
		}

		$amount = (float) $order->get_total();
		$customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

		// Determine transaction type based on preauthorization setting
		$transaction_type = $this->gateway->preauthorize_payments ? 'PREAUTH' : 'SALE';
		
		$paylink_data = array(
			'payment_method'     => $payment_methods,
			'transaction_type'   => $transaction_type,
			'full_name'          => $customer_name,
			'email'              => $order->get_billing_email(),
			'transaction_unique' => 'WC-' . $order_id,
			'is_decide_amount'   => false,
			'amount'             => $amount,
			'address'            => blink_get_full_address($order),
			'postcode'           => $order->get_billing_postcode(),
			'notes'              => 'WooCommerce Order #' . $order_id,
			'currency'         	 => get_woocommerce_currency(),
			'redirect_url'       => $this->gateway->blink_get_return_url( $order ),
			'notification_url'   => WC()->api_request_url( 'blink_gateway' ),
		);

		$url = $this->gateway->host_url . '/paylink/v1/paylinks';
		Blink_Logger::log( 'handle_hosted_payment() POST paylinks', $paylink_data );
		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'   => 'Bearer ' . $this->token['access_token'],
					'Content-Type'    => 'application/json',
				),
				'body'    => wp_json_encode( $paylink_data ),
				'timeout' => 30,
			)
		);
		Blink_Logger::log( 'handle_hosted_payment() POST paylinks', Blink_Logger::http_response_context( $response ) );


		if ( is_wp_error( $response ) ) {
			return blink_error_payment_process( $response->get_error_message() );
		}

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $api_body['paylink_url'] ) ) {
			Blink_Logger::log( 'handle_hosted_payment success', array( 'redirect' => $api_body['paylink_url'] ) );
			return array(
				'result'   => 'success',
				'redirect' => $api_body['paylink_url'],
			);
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body['error'] : $api_body;
			Blink_Logger::log( 'handle_hosted_payment error', array( 'error' => $error ) );
			return blink_error_payment_process( $error );
		}
	}
}
