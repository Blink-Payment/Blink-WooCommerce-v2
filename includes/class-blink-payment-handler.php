<?php
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

	public function process_open_banking( $order, $request ) {
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

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;
				if ( ! empty( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				} elseif ( ! empty( $api_body['redirect_url'] ) ) {
					$return_arr['redirect_url'] = $api_body['redirect_url'];
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}

	public function process_direct_debit( $order, $request ) {
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

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;
				if ( ! empty( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}

	public function process_credit_card( $order, $request, $endpoint = 'creditcards' ) {
		$cart_amount = null; 

		if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
			$cart_amount = WC()->cart->get_total( 'raw' );
		}

		$amount      = ! empty( $order ) ? $order->get_total() : $cart_amount;

		if ( empty( $amount ) ) {
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

		$order_id = $order->get_id();
		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {
			$request_data = array(
				'resource'           => $request['resource'],
				'payment_intent'     => $this->intent['payment_intent'],
				'paymentToken'       => $request['paymentToken'],
				'type'               => $request['type'],
				'raw_amount'         => $amount,
				'customer_email'     => ! empty( $request['customer_email'] ) ? $request['customer_email'] : $order->get_billing_email(),
				'customer_name'      => ! empty( $request['customer_name'] ) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'customer_address'   => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode'  => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'transaction_unique' => 'WC-' . $order_id,
				'merchant_data'      => blink_get_payment_information( $order_id ),
			);
			if ( isset( $request['remote_address'] ) ) {
				$request_data['device_timezone']          = $request['device_timezone'];
				$request_data['device_capabilities']      = $request['device_capabilities'];
				$request_data['device_accept_language']   = $request['device_accept_language'];
				$request_data['device_screen_resolution'] = $request['device_screen_resolution'];
				$request_data['remote_address']           = $request['remote_address'];
			}

			$url = $this->gateway->host_url . '/pay/v1/' . $endpoint;

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

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;

				if ( isset( $api_body['acsform'] ) ) {
					$threedToken = $api_body['acsform'];
					set_transient( 'blink3dProcess' . $order_id, $threedToken, 300 );
					if ( is_wc_endpoint_url( 'order-pay' ) ) {
						$return_arr['redirect_url'] = add_query_arg( 'blink3dprocess', $order_id, $order->get_checkout_payment_url() );
					} else {
						$return_arr['redirect_url'] = add_query_arg( 'blink3dprocess', $order_id, wc_get_checkout_url() );
					}
				} elseif ( isset( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}

	public function handle_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$request = $_POST;

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

		$this->token  = $this->gateway->utils->setTokens();
		$this->intent = $this->gateway->utils->setIntents( $request, $order );

		if ( empty( $this->intent ) || empty( $this->token ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) ) {
				return;
			}
			return blink_error_payment_process();
		}

		$response = array();

		if ( isset( $request['payment_by'] ) && $request['payment_by'] === 'credit-card' ) {
			if ( isset( $_REQUEST['credit-card-data'] ) ) {
				parse_str( sanitize_text_field( wp_unslash( $_REQUEST['credit-card-data'] ) ), $parsed_data );
				$parsed_data['customer_name']  = sanitize_text_field( $request['customer_name'] );
				$parsed_data['customer_email'] = sanitize_email( $request['customer_email'] );
				$request                       = array_merge( $request, $parsed_data );
			}
			$response = $this->process_credit_card( $order, $request );

		}
		if ( isset( $request['payment_by'] ) && $request['payment_by'] === 'google-pay' ) {
			$response = $this->process_credit_card( $order, $request, 'googlepay' );
		}
		if ( isset( $request['payment_by'] ) && $request['payment_by'] === 'apple-pay' ) {
			$response = $this->process_credit_card( $order, $request, 'applepay' );
		}
		if ( isset( $request['payment_by'] ) && $request['payment_by'] === 'direct-debit' ) {
			$response = $this->process_direct_debit( $order, $request );
		}
		if ( isset( $request['payment_by'] ) && $request['payment_by'] === 'open-banking' ) {
			$response = $this->process_open_banking( $order, $request );
		}

		if ( ! $response['success'] ) {
			$this->gateway->utils->destroy_session_tokens();
			return blink_error_payment_process( $response['error'] );
		}

		return array(
			'result'   => 'success',
			'redirect' => $response['redirect_url'],
		);
	}
}
