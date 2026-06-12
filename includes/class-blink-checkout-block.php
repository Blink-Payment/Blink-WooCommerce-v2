<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Blink_Checkout_Block extends AbstractPaymentMethodType {

	private $gateway;

	protected $name = 'blink'; // payment gateway id

	public function initialize() {
		// get payment gateway settings
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );

		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[ $this->name ];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'blink-checkout-block-integration',
			plugin_dir_url( __DIR__ ) . 'dist/blink-block.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			time(),
			true
		);
		wp_localize_script(
			'blink-checkout-block-integration',
			'blink_params',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'remoteAddress' => function_exists( 'get_client_ipv4_address' ) ? get_client_ipv4_address() : '',
			)
		);

		return array( 'blink-checkout-block-integration' );
	}

	public function get_payment_method_data() {

		$cart_data = $this->blink_get_elements_with_cart_amount();

		return array(
			'title'             => $this->get_setting( 'title' ),
			'description'       => $this->get_setting( 'description' ),
			'icon'              => plugins_url('/../assets/img/blink_logo_sml.svg', __FILE__),
			'supports'          => array_filter( $this->gateway->supports ),
			'hostUrl'          => $this->gateway->configs['host_url'],
			'elements'          => $cart_data['element'] ?? array(),
			'selected_methods'  => array_values($this->gateway->paymentMethods),
			'apple_pay_enabled' => 'yes' === $this->get_setting( 'apple_pay_enabled' ),
			'preauthorize_payments' => 'yes' === $this->get_setting( 'preauthorize_payments' ),
			'isSafari'          => blink_is_safari(),
			'makePayment'       => empty( $cart_data['element'] ) ? false : true,
			'isHosted'       	=> 'direct' !== $this->get_setting( 'integration_type' ),
			'cartAmount'       	=> (
										isset($cart_data['amount']) && $cart_data['amount'] !== ''
											? number_format($cart_data['amount'], 2, '.', '')
											: ''
									),
			'intentId'       	=> $cart_data['intent_id'] ?? '',
			'intentExpiryDate'  => $cart_data['intent_expiry_date'] ?? '',
		);
	}

	private function blink_get_elements_with_cart_amount() {

		if ( is_admin() ) {
			return array();
		}

		$paymentGateway = new $this->gateway();

		$request['payment_by'] = '';

		$cart_amount = null; 
		if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
			$cart_amount = WC()->cart->get_total( 'raw' );
		}

		if ( null !== $cart_amount && (float) $cart_amount <= 0 ) {
			return array(
				'element'            => array(),
				'amount'             => number_format( (float) $cart_amount, 2, '.', '' ),
				'intent_id'          => '',
				'intent_expiry_date' => '',
			);
		}

		$intent = $paymentGateway->utils->blink_set_intents( $request, null, $cart_amount );

		$element = ! empty( $intent ) ? $intent['element'] : '';
		$amount = ! empty( $intent ) ? $intent['amount'] : '';
		$intent_id = ! empty( $intent ) ? $intent['id'] : '';
		$intent_expiry_date = ! empty( $intent ) ? $intent['expiry_date'] : '';

		return array('element' => $element, 'amount' => $amount, 'intent_id' => $intent_id, 'intent_expiry_date' => $intent_expiry_date);
	}

}
