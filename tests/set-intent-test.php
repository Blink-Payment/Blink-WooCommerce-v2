<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: set-intent tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$si_logged_in     = false;
$si_cart          = null;
$si_intent_amount = null;

function __( $text, $domain = null ) { return $text; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function wp_verify_nonce( $nonce, $action ) { return 'wp_rest' === $action && 'good-nonce' === $nonce ? 1 : false; }
function is_user_logged_in() { global $si_logged_in; return $si_logged_in; }
function rest_authorization_required_code() { return is_user_logged_in() ? 403 : 401; }
function wc_load_cart() {}
function WC() {
	global $si_cart;
	return (object) array( 'cart' => $si_cart );
}

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
}

class WP_REST_Request {
	private $headers;
	public function __construct( $headers = array() ) { $this->headers = array_change_key_case( $headers ); }
	public function get_header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}
}

class Blink_PG_Logger {
	public static function log( $message, $context = array() ) {}
}

class SI_Cart {
	public $loaded = false;
	private $total;
	public function __construct( $total ) { $this->total = $total; }
	public function get_cart() { $this->loaded = true; return array(); }
	public function get_total( $context ) {
		// The session total is only there once get_cart() has loaded it.
		return 'raw' === $context && $this->loaded ? $this->total : 0;
	}
}

class SI_Utils {
	public function blink_set_tokens() {}
	public function blink_set_intents( $request, $order, $amount ) {
		global $si_intent_amount;
		$si_intent_amount = $amount;
		return array( 'id' => 'intent-1', 'amount' => $amount );
	}
}

class Blink_PG_Payment_Gateway {
	public $utils;
	public function __construct() { $this->utils = new SI_Utils(); }
}

require_once dirname( __DIR__ ) . '/includes/class-blink-pg-api-handler.php';

function si_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

$tests = array();

$tests['a guest without a nonce is refused with 401'] = function () {
	$result = Blink_PG_Api_Handler::can_set_intent( new WP_REST_Request() );
	si_assert( true, $result instanceof WP_Error, 'Refused.' );
	si_assert( 401, $result->data['status'], 'Guests get 401.' );
};

$tests['a logged-in shopper with a bad nonce is refused with 403'] = function () {
	global $si_logged_in;
	$si_logged_in = true;
	$result       = Blink_PG_Api_Handler::can_set_intent( new WP_REST_Request( array( 'X-WP-Nonce' => 'stale' ) ) );
	si_assert( 403, $result->data['status'], 'Logged-in shoppers get 403.' );
};

$tests['the checkout page nonce is accepted'] = function () {
	si_assert( true, Blink_PG_Api_Handler::can_set_intent( new WP_REST_Request( array( 'X-WP-Nonce' => 'good-nonce' ) ) ), 'Allowed.' );
};

$tests['the intent amount is the server cart total, whatever the browser sends'] = function () {
	global $si_cart, $si_intent_amount;
	$si_cart       = new SI_Cart( '18.00' );
	$_POST         = array( 'cartAmount' => '0.01' );
	$_REQUEST      = $_POST;
	$result        = Blink_PG_Api_Handler::set_intent();
	si_assert( 18.0, $si_intent_amount, 'The intent uses the cart total.' );
	si_assert( '18.00', $result['amount'], 'The response reports the cart total.' );
	si_assert( true, $result['payment_required'], 'Payment is required.' );
};

$tests['a zero cart needs no intent'] = function () {
	global $si_cart;
	$si_cart = new SI_Cart( '0.00' );
	$result  = Blink_PG_Api_Handler::set_intent();
	si_assert( false, $result['payment_required'], 'No payment needed.' );
	si_assert( null, $result['intent'], 'No intent is created.' );
};

$tests['no cart is a 400 error'] = function () {
	global $si_cart;
	$si_cart = null;
	$result  = Blink_PG_Api_Handler::set_intent();
	si_assert( true, $result instanceof WP_Error, 'Error returned.' );
	si_assert( 400, $result->data['status'], 'Status 400.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	$si_logged_in     = false;
	$si_intent_amount = null;
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $e ) {
		$failures++;
		echo "FAIL: {$name} - " . $e->getMessage() . "\n";
	}
}

$tests_completed = true;
echo count( $tests ) . " tests, {$failures} failures\n";
exit( $failures ? 1 : 0 );
