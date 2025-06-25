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

		$elements = $this->get_elements();

		return array(
			'title'             => $this->get_setting( 'title' ),
			'description'       => $this->get_setting( 'description' ),
			'supports'          => array_filter( $this->gateway->supports ),
			'elements'          => $elements,
			'selected_methods'  => $this->gateway->paymentMethods,
			'apple_pay_enabled' => 'yes' === $this->get_setting( 'apple_pay_enabled' ),
			'isSafari'          => blink_is_safari(),
			'makePayment'       => empty( $elements ) ? false : true,
		);
	}

	private function get_elements() {

		if ( is_admin() ) {
			return array();
		}

		$paymentGateway = new $this->gateway();

		// Validate, unslash, and sanitize the input
		$request['payment_by'] = isset( $_POST['payment_by'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_by'] ) ) : '';

		$token = $paymentGateway->utils->setTokens();

		$intent = $paymentGateway->utils->setIntents( $request );

		$element = ! empty( $intent ) ? $intent['element'] : array();

		return $element;
	}
}
