<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Blink_Payment_Utils
{

	protected $gateway;
	protected $token;
	protected $intent;

	public function __construct($gateway)
	{
		$this->gateway = $gateway;
	}

	public static function extend_timeout($time)
	{
		return 10;
	}

	public function blink_generate_access_token()
	{
		$url          = $this->gateway->host_url . '/pay/v1/tokens';
		$request_data = array(
			'api_key'                 => $this->gateway->api_key,
			'secret_key'              => $this->gateway->secret_key,
			'source_site'             => get_bloginfo('name'),
			'application_name'        => 'Woocommerce Blink ' . $this->gateway->version,
			'application_description' => 'WP-' . get_bloginfo('version') . ' WC-' . WC_VERSION,
			'address_postcode_required' => true,
			'send_blink_receipt' => false,
		);
		$response     = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 45,
				'body'    => $request_data,
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		$headers = wp_remote_retrieve_headers($response);
		if (isset($headers['retry-after']) && 429 == wp_remote_retrieve_response_code($response)) {
			$retry_after = $headers['retry-after'] + 2;
			sleep($retry_after);
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'timeout' => 45,
					'body'    => $request_data,
				)
			);
		}

		$api_body = json_decode(wp_remote_retrieve_body($response), true);

		if (201 == wp_remote_retrieve_response_code($response)) {
			return $api_body;
		} else {
			$error = ! empty($api_body['error']) ? $api_body : $response['response'];
			blink_add_notice($error);
		}

		return array();
	}

	public function create_payment_intent($method = 'credit-card', $order = null)
	{
		if ($this->token) {

			$cart_amount = null;

			if (WC()->cart && method_exists(WC()->cart, 'get_total')) {
				$cart_amount = WC()->cart->get_total('raw');
			}

			$amount       = ! empty($order) ? $order->get_total() : $cart_amount;

			if (empty($amount)) {
				return array();
			}

			$request_data = array(
				'card_layout'      => 'single-line',
				'amount'           => $amount,
				'payment_type'     => $method,
				'currency'         => get_woocommerce_currency(),
				'return_url'       => $this->gateway->get_return_url($order),
				'notification_url' => WC()->api_request_url('blink_gateway'),
			);
			$url          = $this->gateway->host_url . '/pay/v1/intents';
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array('Authorization' => 'Bearer ' . $this->token['access_token']),
					'body'    => $request_data,
				)
			);

			if (is_wp_error($response)) {
				return array();
			}

			$headers = wp_remote_retrieve_headers($response);
			if (isset($headers['retry-after']) && 429 == wp_remote_retrieve_response_code($response)) {
				$retry_after = $headers['retry-after'] + 2;
				sleep($retry_after);
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'POST',
						'headers' => array('Authorization' => 'Bearer ' . $this->token['access_token']),
						'body'    => $request_data,
					)
				);
			}

			$api_body = json_decode(wp_remote_retrieve_body($response), true);

			if (201 == wp_remote_retrieve_response_code($response)) {
				return $api_body;
			} else {
				$error = ! empty($api_body['error']) ? $api_body : $response['response'];
				blink_add_notice($error);
			}
		}

		return array();
	}

	public function update_payment_intent($method = 'credit-card', $order = null, $id = null)
	{
		if ($this->token) {
			$request_data = array(
				'payment_type' => $method,
				'amount'       => $order->get_total(),
				'return_url'   => $this->gateway->get_return_url($order),
			);
			if ($id) {
				$url      = $this->gateway->host_url . '/pay/v1/intents/' . $id;
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'PATCH',
						'headers' => array('Authorization' => 'Bearer ' . $this->token['access_token']),
						'body'    => $request_data,
					)
				);

				if (is_wp_error($response)) {
					return array();
				}

				$headers = wp_remote_retrieve_headers($response);
				if (isset($headers['retry-after']) && 429 == wp_remote_retrieve_response_code($response)) {
					$retry_after = $headers['retry-after'] + 2;
					sleep($retry_after);
					$response = wp_remote_post(
						$url,
						array(
							'method'  => 'PATCH',
							'headers' => array('Authorization' => 'Bearer ' . $this->token['access_token']),
							'body'    => $request_data,
						)
					);
				}

				$api_body = json_decode(wp_remote_retrieve_body($response), true);

				if (200 == wp_remote_retrieve_response_code($response)) {
					return $api_body;
				} else {
					$error = ! empty($api_body['error']) ? $api_body : $response['response'];
					blink_add_notice($error);
					return $this->create_payment_intent($method, $order);
				}
			}
		}

		return array();
	}

	public function setTokens()
	{
		$this->token = get_transient('blink_token');
		$expired     = 0;
		if (! empty($this->token)) {
			$expired = blink_check_timestamp_expired($this->token['expired_on']);
		}
		if (empty($expired)) {
			$this->token = $this->blink_generate_access_token();
		}
		set_transient('blink_token', $this->token, 15 * MINUTE_IN_SECONDS);

		return $this->token;
	}

	public function setIntents($request = '', $order = null)
	{
		if (empty($request['payment_by']) || $request['payment_by'] == 'google-pay' || $request['payment_by'] == 'apple-pay') {
			$request['payment_by'] = 'credit-card';
		}

		$this->intent   = get_transient('blink_intent');
		$intent_expired = 0;
		if (! empty($this->intent)) {
			$intent_expired = blink_check_timestamp_expired($this->intent['expiry_date']);
		}
		if (empty($intent_expired)) {
			$this->intent = $this->create_payment_intent($request['payment_by'], $order);
		} elseif (! empty($order)) {
			$this->intent = $this->update_payment_intent($request['payment_by'], $order, $this->intent['id']);
		}
		set_transient('blink_intent', $this->intent, 15 * MINUTE_IN_SECONDS);

		return $this->intent;
	}

	public function destroy_session_tokens()
	{
		delete_transient('blink_token');
		$this->destroy_session_intent();
	}

	public function destroy_session_intent()
	{
		delete_transient('blink_intent');
	}
}

add_filter('http_request_timeout', array('Blink_Payment_Utils', 'extend_timeout'));
