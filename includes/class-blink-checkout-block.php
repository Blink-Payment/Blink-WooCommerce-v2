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

		return array( 'blink-checkout-block-integration' );
	}

	public function get_payment_method_data() {

		$cart_data = $this->get_elements_with_cart_amount();

		return array(
			'title'             => $this->get_setting( 'title' ),
			'description'       => $this->get_setting( 'description' ),
			'icon'              => plugins_url('/../assets/img/blink_logo_sml.svg', __FILE__),
			'supports'          => array_filter( $this->gateway->supports ),
			'hostUrl'          => $this->gateway->configs['host_url'],
			'elements'          => $cart_data['element'] ?? array(),
			'selected_methods'  => array_values($this->gateway->paymentMethods),
			'apple_pay_enabled' => 'yes' === $this->get_setting( 'apple_pay_enabled' ),
			'isSafari'          => blink_is_safari(),
			'makePayment'       => empty( $cart_data['element'] ) ? false : true,
			'isHosted'       	=> 'direct' !== $this->get_setting( 'integration_type' ),
			'cartAmount'       	=> (
										isset($cart_data['amount']) && $cart_data['amount'] !== ''
											? number_format($cart_data['amount'], 2, '.', '')
											: ''
									),
		);
	}

	private function get_elements_with_cart_amount() {

		if ( is_admin() ) {
			return array();
		}

		$paymentGateway = new $this->gateway();

		$request['payment_by'] = '';

		$intent = $paymentGateway->utils->setIntents( $request );

		$element = ! empty( $intent ) ? $intent['element'] : '';
		$amount = ! empty( $intent ) ? $intent['amount'] : '';

		return array('element' => $element, 'amount' => $amount);
	}

}
