<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Blink_Gateway_Block extends AbstractPaymentMethodType {

	private $gateway;

	protected $name = 'blink'; // payment gateway id

	public function initialize() {
		// get payment gateway settings
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );

		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[ $this->name ];
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {

		wp_register_script(
			'wc-blink-blocks-integration',
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

		return array( 'wc-blink-blocks-integration' );
	}

	public function get_payment_method_data() {
		return array(
			'title'             => $this->get_setting( 'title' ),
			'description'       => $this->get_setting( 'description' ),
			'supports'          => array_filter( $this->gateway->supports ),
			'elements'          => $this->get_elements(),
			'apple_pay_enabled' => 'yes' === $this->get_setting( 'apple_pay_enabled' ),
			'isSafari'          => isSafari(),
			'makePayment'       => empty( $this->get_elements() ) ? false : true,
		);
	}

	private function get_elements() {

		$paymentGateway = new $this->gateway();
		$request        = $_POST;

		$token = $paymentGateway->setTokens();

		$intent = $paymentGateway->setIntents( $request );

		$element = ! empty( $intent ) ? $intent['element'] : array();

		return $element;
	}
}
