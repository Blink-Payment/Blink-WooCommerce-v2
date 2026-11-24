<?php
/**
 * REST route the block checkout uses to fetch card fields.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and serves the plugin's REST API routes.
 */
class Blink_PG_Api_Handler {

	/**
	 * Hook route registration into the REST API start-up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register the set-intent route.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		// Block checkout asks for card fields for the current cart. Shoppers are
		// often guests, so the route checks the checkout page's REST nonce rather
		// than a capability.
		register_rest_route(
			'blink-payment-gateway/v1',
			'/set-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_intent' ),
				'permission_callback' => array( __CLASS__, 'can_set_intent' ),
			)
		);
	}

	/**
	 * Allow the request only with the nonce the checkout page was given.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_set_intent( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' !== $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Your session has expired. Please refresh the page and try again.', 'blink-payment-gateway-for-woocommerce' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Create or update the payment intent for the shopper's cart. The amount is
	 * the cart total held on the server; anything the browser sends is ignored.
	 *
	 * @return array|WP_Error
	 */
	public static function set_intent() {
		Blink_PG_Logger::log( 'REST set_intent called' );

		$cart_amount = self::cart_total();
		if ( null === $cart_amount ) {
			return new WP_Error( 'blink_no_cart', __( 'Unable to read the cart.', 'blink-payment-gateway-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( $cart_amount <= 0 ) {
			Blink_PG_Logger::log( 'REST set_intent zero amount; no payment intent required' );

			return array(
				'intent'           => null,
				'payment_required' => false,
				'amount'           => number_format( $cart_amount, 2, '.', '' ),
			);
		}

		$gateway = new Blink_PG_Payment_Gateway();
		$gateway->utils->blink_set_tokens();
		// Blocks cart updates should receive a current payable intent, not reuse
		// stale hosted fields from a previous payable state.
		$intent = $gateway->utils->blink_set_intents( array( 'payment_by' => 'credit-card' ), null, $cart_amount );
		Blink_PG_Logger::log( 'REST set_intent result', array( 'has_intent' => ! empty( $intent ) ) );

		return array(
			'intent'           => $intent,
			'payment_required' => ! empty( $intent ),
			'amount'           => number_format( $cart_amount, 2, '.', '' ),
		);
	}

	/**
	 * The shopper's cart total. WooCommerce does not load the cart for REST
	 * requests, so load it from the session first; get_cart() reads the session
	 * contents and totals.
	 *
	 * @return float|null Null when there is no cart.
	 */
	private static function cart_total() {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		WC()->cart->get_cart();
		return (float) WC()->cart->get_total( 'raw' );
	}
}

Blink_PG_Api_Handler::init();
