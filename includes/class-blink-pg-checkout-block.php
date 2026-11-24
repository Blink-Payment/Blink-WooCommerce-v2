<?php
/**
 * WooCommerce Checkout Blocks integration for the Blink gateway.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the Blink payment method with the Checkout block and passes it its settings and intent data.
 */
final class Blink_PG_Checkout_Block extends AbstractPaymentMethodType {

	/**
	 * Blink gateway instance.
	 *
	 * @var Blink_PG_Payment_Gateway
	 */
	private $gateway;

	/**
	 * Payment method name, matching the gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'blink'; // payment gateway id.

	/**
	 * Load the gateway settings and gateway instance.
	 *
	 * @return void
	 */
	public function initialize() {
		// get payment gateway settings.
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );

		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[ $this->name ];
	}

	/**
	 * Register the block checkout script and return its handle.
	 *
	 * @return string[]
	 */
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
			// The build's modified time changes with each build, so browsers and CDNs
			// can cache the script and still pick up a new release.
			(string) filemtime( plugin_dir_path( __DIR__ ) . 'dist/blink-block.js' ),
			true
		);
		wp_localize_script(
			'blink-checkout-block-integration',
			'blink_params',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'remoteAddress' => function_exists( 'blink_pg_get_client_ip' ) ? blink_pg_get_client_ip() : '',
			)
		);

		return array( 'blink-checkout-block-integration' );
	}

	/**
	 * Data passed to the block checkout script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {

		$cart_data = $this->blink_get_elements_with_cart_amount();

		return array(
			'title'                 => $this->get_setting( 'title' ),
			'description'           => $this->get_setting( 'description' ),
			'icon'                  => plugins_url( '/../assets/img/blink_logo_sml.svg', __FILE__ ),
			'card_logos'            => blink_pg_get_card_logos( $this->settings ),
			'supports'              => array_filter( $this->gateway->supports ),
			'hostUrl'               => $this->gateway->configs['host_url'],
			'elements'              => $cart_data['element'] ?? array(),
			'selected_methods'      => array_values( $this->gateway->payment_methods ),
			'apple_pay_enabled'     => 'yes' === $this->get_setting( 'apple_pay_enabled' ),
			'preauthorize_payments' => 'yes' === $this->get_setting( 'preauthorize_payments' ),
			'makePayment'           => empty( $cart_data['element'] ) ? false : true,
			'isHosted'              => 'direct' !== $this->get_setting( 'integration_type' ),
			'cartAmount'            => (
										isset( $cart_data['amount'] ) && '' !== $cart_data['amount']
											? number_format( $cart_data['amount'], 2, '.', '' )
											: ''
									),
			'intentId'              => $cart_data['intent_id'] ?? '',
			'intentExpiryDate'      => $cart_data['intent_expiry_date'] ?? '',
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
			// rest_url() follows the site's install path and permalink setting.
			'setIntentUrl'          => rest_url( 'blink-payment-gateway/v1/set-intent' ),
		);
	}

	/**
	 * Create a Blink intent for the cart total and return its elements and details.
	 *
	 * Returns an empty array in the admin, and no elements for a zero-total cart.
	 *
	 * @return array
	 */
	private function blink_get_elements_with_cart_amount() {

		if ( is_admin() ) {
			return array();
		}

		$payment_gateway = new $this->gateway();

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

		$intent = $payment_gateway->utils->blink_set_intents( $request, null, $cart_amount );

		$element            = ! empty( $intent ) ? $intent['element'] : '';
		$amount             = ! empty( $intent ) ? $intent['amount'] : '';
		$intent_id          = ! empty( $intent ) ? $intent['id'] : '';
		$intent_expiry_date = ! empty( $intent ) ? $intent['expiry_date'] : '';

		return array(
			'element'            => $element,
			'amount'             => $amount,
			'intent_id'          => $intent_id,
			'intent_expiry_date' => $intent_expiry_date,
		);
	}
}
