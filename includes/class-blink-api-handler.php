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
        $intent_id = sanitize_text_field( (string) $request->get_param('intentId') );
        $cart_amount_value = is_numeric( $cart_amount ) ? (float) $cart_amount : 0.0;
		$gateWay = new Blink_Payment_Gateway();

        if ( $cart_amount_value <= 0 ) {
            if ( ! empty( $intent_id ) ) {
                $gateWay->utils->blink_destroy_session_tokens( $intent_id );
            }

            Blink_Logger::log( 'REST set_intent zero amount; no payment intent required' );

            return array(
                'intent'           => null,
                'payment_required' => false,
                'amount'           => number_format( $cart_amount_value, 2, '.', '' ),
            );
        }

        $gateWay->utils->blink_set_tokens();
        // Blocks cart updates should receive a current payable intent, not reuse
        // stale hosted fields from a previous payable state.
        $intent = $gateWay->utils->blink_set_intents( array( 'payment_by' => 'credit-card' ), null, $cart_amount_value );
        Blink_Logger::log( 'REST set_intent result', array( 'has_intent' => ! empty( $intent ) ) );

        return array(
            'intent'           => $intent,
            'payment_required' => ! empty( $intent ),
            'amount'           => number_format( $cart_amount_value, 2, '.', '' ),
        );
    }

}

Blink_Api_Handler::init();
