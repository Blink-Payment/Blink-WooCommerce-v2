<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Api_Handler {

    public static function init() {
        // Register REST API endpoints for cart amount and set intent
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {

        register_rest_route( 'blink/v1', '/set-intent', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'set_intent' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function set_intent( WP_REST_Request $request ) {
        Blink_Logger::log( 'REST set_intent called' );

		$cart_amount = $request->get_param('cartAmount');
		$gateWay = new Blink_Payment_Gateway();

        $gateWay->utils->setTokens();
        // Use setIntents to always get the latest intent based on current cart
        $intent = $gateWay->utils->setIntents( array( 'payment_by' => 'credit-card' ), null, $cart_amount );
        Blink_Logger::log( 'REST set_intent result', array( 'has_intent' => ! empty( $intent ) ) );

        return array(
            'intent' => $intent,
        );
    }

}

Blink_Api_Handler::init();
