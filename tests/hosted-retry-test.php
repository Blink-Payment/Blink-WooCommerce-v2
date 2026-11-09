<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: hosted retry tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$hr_orders     = array();
$hr_responses  = array();
$hr_get_calls  = array();
$hr_post_calls = array();
$hr_post_reply = null;
$hr_token      = array( 'access_token' => 'test-token' );
$hr_status_log = array();
$hr_notices    = array();
$hr_on_get     = null;

function __( $text, $domain = null ) { return $text; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wc_clean( $value ) { return sanitize_text_field( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return (string) $url; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function delete_transient( $key ) { return true; }
function get_woocommerce_currency() { return 'GBP'; }
function wc_add_notice( $message, $type = 'success' ) {
	global $hr_notices;
	$hr_notices[] = array( $type, $message );
}
function blink_get_full_address( $order = null ) { return '1 Test Street'; }

// The return page re-checks a Paylink after a short wait; tests don't wait.
function apply_filters( $hook, $value ) {
	return 'blink_hosted_return_recheck_delay' === $hook ? 0 : $value;
}

class WP_Error {
	private $message;
	public function __construct( $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

/**
 * Paylink lookups answer from a queue per URL, so a test can return Unpaid on the
 * first lookup and Paid on the re-check; the last answer repeats.
 */
function wp_remote_get( $url, $args ) {
	global $hr_responses, $hr_get_calls, $hr_on_get;
	$hr_get_calls[] = $url;
	if ( $hr_on_get ) {
		( $hr_on_get )( $url );
	}
	if ( empty( $hr_responses[ $url ] ) ) {
		return array( 'body' => '{"error":"Not found"}', 'response' => array( 'code' => 404 ) );
	}
	return count( $hr_responses[ $url ] ) > 1 ? array_shift( $hr_responses[ $url ] ) : $hr_responses[ $url ][0];
}

function wp_remote_post( $url, $args ) {
	global $hr_post_calls, $hr_post_reply;
	$hr_post_calls[] = array( $url, json_decode( $args['body'], true ) );
	return $hr_post_reply;
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wc_get_order( $id ) {
	global $hr_orders;
	return isset( $hr_orders[ (string) $id ] ) ? $hr_orders[ (string) $id ] : false;
}

function WC() {
	return new class {
		public $payment_gateways;
		public function __construct() {
			$this->payment_gateways = new class {
				public function get_available_payment_gateways() {
					global $hr_gateway;
					return array( 'blink' => $hr_gateway );
				}
			};
		}
		public function api_request_url( $request ) { return 'https://shop.example.com/wc-api/' . $request; }
	};
}

// Order status changes are recorded rather than run through WooCommerce.
function blink_is_preauth_transaction( $order ) {
	return $order && 'yes' === $order->get_meta( '_blink_preauth', true );
}

function blink_payment_complete( $order, $txn_id = '', $note = '', $allow_processing = false ) {
	global $hr_status_log;
	$hr_status_log[] = array( 'complete', $order->get_id(), $txn_id );
	$order->status = 'processing';
}

function blink_payment_on_hold( $order, $reason = '' ) {
	global $hr_status_log;
	$hr_status_log[] = array( 'hold', $order->get_id() );
	$order->status = 'on-hold';
}

function blink_payment_failed( $order, $reason = '' ) {
	global $hr_status_log;
	$hr_status_log[] = array( 'failed', $order->get_id() );
	$order->status = 'failed';
}

class Blink_Logger {
	public static $entries = array();
	public static function log( $message, $context = null ) { self::$entries[] = array( $message, $context ); }
	public static function http_response_context( $response ) { return array(); }
}

class HR_Meta {
	public $key;
	public $value;
	public function __construct( $key, $value ) { $this->key = $key; $this->value = $value; }
}

class HR_Order {
	public $id;
	public $total          = '18.00';
	public $currency       = 'GBP';
	public $order_key      = 'wc_order_genuine';
	public $status         = 'pending';
	public $payment_method = 'blink';
	public $transaction_id = '';
	public $meta           = array();
	public $notes          = array();

	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_order_key() { return $this->order_key; }
	public function get_status() { return $this->status; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function needs_payment() { return $this->has_status( array( 'pending', 'failed' ) ); }
	public function get_payment_method() { return $this->payment_method; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function get_checkout_payment_url() { return 'https://shop.example.com/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->order_key; }
	public function get_checkout_order_received_url() { return 'https://shop.example.com/checkout/order-received/' . $this->id . '/?key=' . $this->order_key; }
	public function get_billing_first_name() { return 'Test'; }
	public function get_billing_last_name() { return 'Customer'; }
	public function get_billing_email() { return 'customer@example.com'; }
	public function get_billing_postcode() { return 'AB1 2CD'; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = array( $value ); }
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ][] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function get_meta( $key, $single = true ) {
		$values = isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : array();
		if ( $single ) {
			return $values ? $values[0] : '';
		}
		return array_map( function ( $value ) use ( $key ) { return new HR_Meta( $key, $value ); }, $values );
	}
	public function save() {}
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( $note ) {
			$this->notes[] = $note;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-transaction-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-payment-handler.php';

class HR_Utils {
	public function blink_generate_access_token() {
		global $hr_token;
		return $hr_token;
	}
	public function blink_set_tokens( $intent_id = '' ) {
		global $hr_token;
		return $hr_token;
	}
	public function blink_destroy_session_tokens( $intent_id = '' ) {}
}

class HR_Gateway {
	public $host_url              = 'https://gateway.example.com';
	public $paymentMethods        = array( 'credit-card' );
	public $preauthorize_payments = false;
	public $utils;
	public $transaction_handler;
	public $payment_handler;
	public function __construct() {
		$this->utils               = new HR_Utils();
		$this->transaction_handler = new Blink_Transaction_Handler( $this );
		$this->payment_handler     = new Blink_Payment_Handler( $this );
	}
	public function blink_is_hosted() { return true; }
	public function blink_get_return_url( $order = null ) { return $order->get_checkout_order_received_url(); }
}

$hr_gateway = new HR_Gateway();

function hr_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

function hr_assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( (string) $haystack, $needle ) ) {
		throw new RuntimeException( $message . ' Missing: ' . var_export( $needle, true ) . ' In: ' . var_export( $haystack, true ) );
	}
}

function hr_reset() {
	global $hr_orders, $hr_responses, $hr_get_calls, $hr_post_calls, $hr_post_reply, $hr_token, $hr_status_log, $hr_notices, $hr_on_get, $hr_gateway, $wp;
	$hr_orders     = array();
	$hr_responses  = array();
	$hr_get_calls  = array();
	$hr_post_calls = array();
	$hr_post_reply = array(
		'body'     => json_encode( array( 'id' => 7999, 'paylink_url' => 'https://pay.example.com/new-paylink' ) ),
		'response' => array( 'code' => 201 ),
	);
	$hr_token      = array( 'access_token' => 'test-token' );
	$hr_status_log = array();
	$hr_notices    = array();
	$hr_on_get     = null;
	$hr_gateway    = new HR_Gateway();
	$wp            = null;
	$_GET          = array();
	$_REQUEST      = array();
	$_POST         = array();
	Blink_Logger::$entries = array();
	Blink_Transaction_Handler::$hosted_return_notice = null;
}

function hr_order( $id, $props = array() ) {
	global $hr_orders;
	$order = new HR_Order( $id );
	foreach ( $props as $key => $value ) {
		$order->$key = $value;
	}
	$hr_orders[ (string) $id ] = $order;
	return $order;
}

/**
 * Queue one or more Blink answers for a Paylink. Each answer is an array of
 * Paylink fields, or 'unavailable'.
 */
function hr_paylink( $id, ...$answers ) {
	global $hr_responses;
	$url = 'https://gateway.example.com/paylink/v1/paylinks/' . $id;
	foreach ( $answers as $answer ) {
		if ( 'unavailable' === $answer ) {
			$hr_responses[ $url ][] = array( 'body' => '{"error":"Service unavailable"}', 'response' => array( 'code' => 503 ) );
			continue;
		}
		$hr_responses[ $url ][] = array(
			'body'     => json_encode( array_merge( array( 'id' => (int) $id, 'currency' => 'GBP', 'transaction_type' => 'Sale', 'amount' => '18.00', 'paylink_url' => 'https://pay.example.com/paylink-' . $id ), $answer ) ),
			'response' => array( 'code' => 200 ),
		);
	}
}

function hr_paylink_calls( $id ) {
	global $hr_get_calls;
	return count( array_keys( $hr_get_calls, 'https://gateway.example.com/paylink/v1/paylinks/' . $id, true ) );
}

// Load the order-received page the way WordPress does (see return-verification-test.php).
function hr_visit( $order, $query ) {
	global $wp, $hr_gateway;
	$wp       = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
	$_GET     = $query;
	$_REQUEST = $query;
	Blink_Transaction_Handler::$hosted_return_notice = null;
	Blink_Transaction_Handler::blink_capture_order_response();
	if ( isset( $query['key'] ) && $query['key'] === $order->get_order_key() ) {
		$hr_gateway->transaction_handler->blink_check_response_for_order( $order->get_id() );
	}
	$_GET     = array();
	$_REQUEST = array();
}

function hr_return( $order, $status, $extra = array() ) {
	hr_visit( $order, array_merge( array( 'key' => $order->get_order_key(), 'status' => $status, 'amount' => '18.00', 'reference' => 'WC-' . $order->get_id() ), $extra ) );
}

// What the page shows: the Blocks and Classic title and text, with a null order as Blocks passes.
function hr_page() {
	return array(
		'title' => Blink_Transaction_Handler::blink_hosted_return_title( 'Order received' ),
		'text'  => Blink_Transaction_Handler::blink_hosted_return_text( 'Thank you. Your order has been received.', null ),
	);
}

function hr_assert_default_page( $message ) {
	hr_assert( array( 'title' => 'Order received', 'text' => 'Thank you. Your order has been received.' ), hr_page(), $message );
}

function hr_pay( $order ) {
	global $hr_gateway;
	return $hr_gateway->payment_handler->blink_handle_payment( $order->get_id() );
}

$tests = array();

// --- Returning from the Paylink -------------------------------------------

$tests['1. retry return on a Paid Paylink completes the order once and shows the normal page'] = function () {
	global $hr_status_log;
	$order = hr_order( 301 );
	$order->add_meta_data( '_blink_paylink_id', '7001' );
	hr_paylink( '7001', array( 'status' => 'Paid' ) );
	hr_return( $order, 'Paid' );
	hr_return( $order, 'Paid' );
	hr_assert( 'processing', $order->get_status(), 'A Paid Paylink completes the order.' );
	hr_assert( array( array( 'complete', 301, '' ) ), $hr_status_log, 'The order is completed once, even on reload.' );
	hr_assert_default_page( 'A confirmed payment shows the normal success page.' );
};

$tests['2a. retry return before Blink records Paid shows the confirming notice'] = function () {
	global $hr_status_log;
	$order = hr_order( 302 );
	$order->add_meta_data( '_blink_paylink_id', '7002' );
	hr_paylink( '7002', array( 'status' => 'Payment Attempted' ) );
	hr_return( $order, 'Paid' );
	hr_assert( 'pending', $order->get_status(), 'The URL status must not pay the order.' );
	hr_assert( array(), $hr_status_log, 'No status change may run.' );
	hr_assert( 2, hr_paylink_calls( '7002' ), 'The Paylink is checked once more before giving up.' );
	$page = hr_page();
	hr_assert( 'Confirming your payment', $page['title'], 'The title says the payment is being confirmed.' );
	hr_assert_contains( 'If you completed it, please don&#039;t pay again.', $page['text'], 'The customer is told not to pay again.' );
	hr_assert_contains( 'href="' . $order->get_checkout_payment_url() . '"', $page['text'], 'A Pay link stays for a customer who did not finish; it reuses the same Paylink.' );
	hr_assert( false, strpos( $page['text'], 'Thank you. Your order has been received.' ), 'No thank-you text while unconfirmed.' );
	hr_assert( false, strpos( $page['text'], 'declined' ), 'No decline message.' );
	hr_assert( '', $order->get_meta( '_blink_awaiting_confirmation', true ), 'Nothing is stored on the order.' );
};

$tests['2b. retry return completes the order when the re-check finds it Paid'] = function () {
	global $hr_status_log;
	$order = hr_order( 303 );
	$order->add_meta_data( '_blink_paylink_id', '7003' );
	hr_paylink( '7003', array( 'status' => 'Payment Attempted' ), array( 'status' => 'Paid' ) );
	hr_return( $order, 'Paid' );
	hr_assert( 'processing', $order->get_status(), 'The re-check catches a Paylink Blink has just recorded as Paid.' );
	hr_assert( array( array( 'complete', 303, '' ) ), $hr_status_log, 'The order is completed once.' );
	hr_assert_default_page( 'A confirmed payment shows the normal success page.' );
};

$tests['2c. retry return when Blink is unreachable shows the confirming notice without a re-check'] = function () {
	$order = hr_order( 304 );
	$order->add_meta_data( '_blink_paylink_id', '7004' );
	hr_paylink( '7004', 'unavailable' );
	hr_return( $order, 'Paid' );
	hr_assert( 'pending', $order->get_status(), 'The order is left for the webhook.' );
	hr_assert( 1, hr_paylink_calls( '7004' ), 'An unreachable Blink is not asked again on the same page load.' );
	hr_assert( 'Confirming your payment', hr_page()['title'], 'The customer sees the confirming notice.' );
};

$tests['2d. reloading an unconfirmed return checks Blink again and completes the order'] = function () {
	$order = hr_order( 305 );
	$order->add_meta_data( '_blink_paylink_id', '7005' );
	hr_paylink( '7005', array( 'status' => 'Unpaid' ), array( 'status' => 'Unpaid' ), array( 'status' => 'Paid' ) );
	hr_return( $order, 'Paid' );
	hr_assert( 'Confirming your payment', hr_page()['title'], 'First load: still confirming.' );
	hr_return( $order, 'Paid' );
	hr_assert( 'processing', $order->get_status(), 'A reload completes the order once Blink has it as Paid.' );
	hr_assert_default_page( 'The reload shows the normal success page.' );
};

$tests['3. decline return keeps the order Pending and offers to pay again'] = function () {
	global $hr_status_log;
	$order = hr_order( 306 );
	$order->add_meta_data( '_blink_paylink_id', '7006' );
	hr_paylink( '7006', array( 'status' => 'Payment Attempted' ) );
	hr_return( $order, 'Declined' );
	hr_assert( 'pending', $order->get_status(), 'A decline never fails a Hosted order.' );
	hr_assert( array(), $hr_status_log, 'No status change may run.' );
	hr_assert( 1, hr_paylink_calls( '7006' ), 'A decline is not re-checked.' );
	$page = hr_page();
	hr_assert( 'Payment declined', $page['title'], 'The title says the payment was declined.' );
	hr_assert_contains( 'Payment was declined. Please try again.', $page['text'], 'The decline message shows.' );
	hr_assert_contains( 'href="' . $order->get_checkout_payment_url() . '"', $page['text'], 'A Pay link lets the customer try again.' );
	hr_assert( '', $order->get_meta( '_blink_payment_message', true ), 'The message is not stored on the order.' );
	hr_assert( 1, count( $order->notes ), 'The decline is noted on the order.' );
	hr_assert_contains( 'declined', $order->notes[0], 'The note records the decline.' );
};

$tests['3b. decline return when Blink is unreachable shows the decline message without a note'] = function () {
	$order = hr_order( 307 );
	$order->add_meta_data( '_blink_paylink_id', '7007' );
	hr_paylink( '7007', 'unavailable' );
	hr_return( $order, 'Declined' );
	hr_assert( 'pending', $order->get_status(), 'A decline never fails a Hosted order.' );
	hr_assert( 'Payment declined', hr_page()['title'], 'The customer still sees the decline.' );
	hr_assert( array(), $order->notes, 'An unconfirmed decline is not noted.' );
};

$tests['4. reopening an earlier decline URL after the retry was paid changes nothing'] = function () {
	global $hr_status_log, $hr_get_calls;
	$order = hr_order( 308, array( 'status' => 'processing' ) );
	$order->add_meta_data( '_blink_paylink_id', '7008' );
	hr_return( $order, 'Declined' );
	hr_assert( 'processing', $order->get_status(), 'A stale decline must not undo a payment.' );
	hr_assert( array(), $hr_status_log, 'No status change may run.' );
	hr_assert( array(), $hr_get_calls, 'A paid order is not looked up.' );
	hr_assert_default_page( 'A paid order shows the normal page.' );
};

$tests['5. Cancelled or missing status shows the order as it is'] = function () {
	foreach ( array( 'Cancelled', '' ) as $index => $status ) {
		$order = hr_order( 309 + $index );
		$order->add_meta_data( '_blink_paylink_id', '7009' );
		hr_paylink( '7009', array( 'status' => 'Unpaid' ) );
		hr_return( $order, $status );
		hr_assert( 'pending', $order->get_status(), 'An unpaid Paylink leaves the order Pending.' );
		hr_assert_default_page( 'No Blink notice for status "' . $status . '".' );
		hr_assert( array(), $order->notes, 'Nothing is noted.' );
	}
};

$tests['6. forged return with a bad key or reference changes nothing and shows nothing'] = function () {
	global $hr_status_log, $hr_get_calls;
	$order = hr_order( 311 );
	$order->add_meta_data( '_blink_paylink_id', '7011' );
	hr_paylink( '7011', array( 'status' => 'Paid' ) );
	foreach ( array( array( 'key' => 'wc_order_wrong' ), array( 'key' => '' ), array( 'reference' => 'WC-999' ), array( 'reference' => '' ) ) as $forged ) {
		foreach ( array( 'Paid', 'Declined' ) as $status ) {
			hr_return( $order, $status, $forged );
			hr_assert( 'pending', $order->get_status(), 'A forged return must not change the order.' );
			hr_assert_default_page( 'A forged return shows no Blink notice.' );
		}
	}
	hr_assert( array(), $hr_status_log, 'No status change may run.' );
	hr_assert( array(), $hr_get_calls, 'Nothing is looked up for a forged return.' );
	hr_assert( array(), $order->notes, 'Nothing is noted.' );
};

$tests['9. the page filters work without an order, as Blocks calls them'] = function () {
	hr_assert_default_page( 'With no return handled, the filters change nothing.' );
	$order = hr_order( 312 );
	$order->add_meta_data( '_blink_paylink_id', '7012' );
	hr_paylink( '7012', array( 'status' => 'Payment Attempted' ) );
	hr_return( $order, 'Declined' );
	hr_assert( 'Payment declined', Blink_Transaction_Handler::blink_hosted_return_title( 'Order failed', null ), 'The title filter ignores a null order.' );
	hr_assert_contains( 'Payment was declined', Blink_Transaction_Handler::blink_hosted_return_text( 'Your order cannot be processed', null ), 'The text filter ignores a null order.' );
};

$tests['10. decline URL on a Paylink Blink reports Paid completes the order'] = function () {
	global $hr_status_log;
	$order = hr_order( 313 );
	$order->add_meta_data( '_blink_paylink_id', '7013' );
	hr_paylink( '7013', array( 'status' => 'Paid' ) );
	hr_return( $order, 'Declined' );
	hr_assert( 'processing', $order->get_status(), 'Blink wins over the URL.' );
	hr_assert( array( array( 'complete', 313, '' ) ), $hr_status_log, 'The order is completed once.' );
	hr_assert_default_page( 'No decline message on a paid order.' );
	hr_assert( array(), preg_grep( '/declined/', $order->notes ), 'No decline note.' );
};

$tests['a Paid Paylink that does not fit the order asks the customer to get in touch'] = function () {
	global $hr_status_log;
	$order = hr_order( 314 );
	$order->add_meta_data( '_blink_paylink_id', '7014' );
	hr_paylink( '7014', array( 'status' => 'Paid', 'amount' => '5.00' ) );
	hr_return( $order, 'Paid' );
	hr_assert( 'pending', $order->get_status(), 'A payment that does not fit is left for the webhook to reconcile.' );
	hr_assert( array(), $hr_status_log, 'No status change may run.' );
	hr_assert_contains( 'contact', hr_page()['text'], 'The customer is told to get in touch rather than pay again.' );
};

// --- Starting a payment attempt -------------------------------------------

$tests['7a. Pay reuses the newest Paylink while it is still payable and fits the order'] = function () {
	global $hr_post_calls;
	foreach ( array( 'Unpaid', 'Payment Attempted' ) as $index => $status ) {
		$order = hr_order( 321 + $index );
		$order->add_meta_data( '_blink_paylink_id', '7021' );
		$order->add_meta_data( '_blink_paylink_id', '7022' );
		hr_paylink( '7021', array( 'status' => 'Payment Attempted' ) );
		hr_paylink( '7022', array( 'status' => $status ) );
		$result = hr_pay( $order );
		hr_assert( array( 'result' => 'success', 'redirect' => 'https://pay.example.com/paylink-7022' ), $result, 'The customer goes back to the same Paylink (' . $status . ').' );
	}
	hr_assert( array(), $hr_post_calls, 'No new Paylink is created.' );
};

$tests['7b. Pay creates a new Paylink when the newest one no longer fits the order'] = function () {
	global $hr_post_calls, $hr_gateway;
	$cases = array(
		'amount'   => array( array( 'amount' => '12.00' ), false ),
		'currency' => array( array( 'currency' => 'EUR' ), false ),
		'mode'     => array( array(), true ),
	);
	$id = 323;
	foreach ( $cases as $label => list( $fields, $preauth ) ) {
		hr_reset();
		global $hr_gateway, $hr_post_calls;
		$hr_gateway->preauthorize_payments = $preauth;
		$order = hr_order( $id++ );
		$order->add_meta_data( '_blink_paylink_id', '7023' );
		hr_paylink( '7023', array_merge( array( 'status' => 'Payment Attempted' ), $fields ) );
		$result = hr_pay( $order );
		hr_assert( 'https://pay.example.com/new-paylink', $result['redirect'], 'A Paylink that no longer fits (' . $label . ') is not reused.' );
		hr_assert( 1, count( $hr_post_calls ), 'One new Paylink is created (' . $label . ').' );
		hr_assert( array( '7023', '7999' ), array_map( function ( $meta ) { return $meta->value; }, $order->get_meta( '_blink_paylink_id', false ) ), 'The new Paylink is stored; the old one is left alone (' . $label . ').' );
	}
};

$tests['7c. Pay reuses a pre-auth Paylink when pre-auth is still on'] = function () {
	global $hr_gateway, $hr_post_calls;
	$hr_gateway->preauthorize_payments = true;
	$order = hr_order( 326 );
	$order->add_meta_data( '_blink_paylink_id', '7026' );
	hr_paylink( '7026', array( 'status' => 'Payment Attempted', 'transaction_type' => 'Preauth' ) );
	$result = hr_pay( $order );
	hr_assert( 'https://pay.example.com/paylink-7026', $result['redirect'], 'An unpaid order is not marked pre-auth yet; the setting decides.' );
	hr_assert( array(), $hr_post_calls, 'No new Paylink is created.' );
};

$tests['7d. Pay on an order whose Paylink is already Paid completes it instead of taking a payment'] = function () {
	global $hr_post_calls, $hr_status_log;
	$order = hr_order( 327 );
	$order->add_meta_data( '_blink_paylink_id', '7027' );
	hr_paylink( '7027', array( 'status' => 'Paid' ) );
	$result = hr_pay( $order );
	hr_assert( array( 'result' => 'success', 'redirect' => $order->get_checkout_order_received_url() ), $result, 'The customer is sent to the order confirmation.' );
	hr_assert( array( array( 'complete', 327, '' ) ), $hr_status_log, 'The order is completed.' );
	hr_assert( array(), $hr_post_calls, 'No new Paylink is created.' );
};

$tests['7e. Pay when Blink is unreachable shows an error and creates nothing'] = function () {
	global $hr_post_calls, $hr_notices;
	$order = hr_order( 328, array( 'status' => 'failed' ) );
	$order->add_meta_data( '_blink_paylink_id', '7028' );
	$order->update_meta_data( '_blink_payment_message', 'Payment was declined. Please try again.' );
	hr_paylink( '7028', 'unavailable' );
	$result = hr_pay( $order );
	hr_assert( 'failure', $result['result'], 'The attempt is refused.' );
	hr_assert( 'error', $hr_notices[0][0], 'The customer sees an error.' );
	hr_assert( array(), $hr_post_calls, 'No new Paylink is created while the old one might be paid.' );
	hr_assert( 'pending', $order->get_status(), 'The attempt was started before Blink was asked, as a decline now leaves an order.' );
};

$tests['7f. Pay with no stored Paylink creates and stores one'] = function () {
	global $hr_post_calls, $hr_get_calls;
	$order  = hr_order( 329 );
	$result = hr_pay( $order );
	hr_assert( 'https://pay.example.com/new-paylink', $result['redirect'], 'A first attempt goes to a new Paylink.' );
	hr_assert( array(), $hr_get_calls, 'There is nothing to look up.' );
	hr_assert( 'SALE', $hr_post_calls[0][1]['transaction_type'], 'The Paylink is created as before.' );
	hr_assert( '7999', $order->get_meta( '_blink_paylink_id', true ), 'Its ID is stored.' );
};

$tests['7g. Pay creates a new Paylink when the stored one is Cancelled or unknown'] = function () {
	global $hr_post_calls;
	$order = hr_order( 330 );
	$order->add_meta_data( '_blink_paylink_id', '7030' );
	hr_paylink( '7030', array( 'status' => 'Cancelled' ) );
	hr_assert( 'https://pay.example.com/new-paylink', hr_pay( $order )['redirect'], 'A cancelled Paylink is not reused.' );
	$order = hr_order( 331 );
	$order->add_meta_data( '_blink_paylink_id', '7031' );
	hr_assert( 'https://pay.example.com/new-paylink', hr_pay( $order )['redirect'], 'A Paylink Blink does not know is not reused.' );
	hr_assert( 2, count( $hr_post_calls ), 'Each order gets one new Paylink.' );
};

$tests['8. Pay on a Failed order returns it to Pending and clears the old decline'] = function () {
	foreach ( array( 'reuse' => '7032', 'new' => '' ) as $label => $paylink_id ) {
		$order = hr_order( 'reuse' === $label ? 332 : 333, array( 'status' => 'failed' ) );
		if ( '' !== $paylink_id ) {
			$order->add_meta_data( '_blink_paylink_id', $paylink_id );
			hr_paylink( $paylink_id, array( 'status' => 'Payment Attempted' ) );
		}
		$order->update_meta_data( '_blink_payment_message', 'Payment was declined. Please try again.' );
		$order->update_meta_data( '_blink_res_expired', 'true' );
		hr_pay( $order );
		hr_assert( 'pending', $order->get_status(), 'A new attempt returns a Failed order to Pending (' . $label . ').' );
		hr_assert( '', $order->get_meta( '_blink_payment_message', true ), 'The old decline message is cleared (' . $label . ').' );
		hr_assert( '', $order->get_meta( '_blink_res_expired', true ), 'The old expired state is cleared (' . $label . ').' );
	}
};

$tests['8b. a failed Paylink creation leaves the order Pending and unpaid'] = function () {
	global $hr_post_reply, $hr_status_log;
	$hr_post_reply = array( 'body' => json_encode( array( 'error' => 'Invalid request' ) ), 'response' => array( 'code' => 400 ) );
	$order         = hr_order( 334, array( 'status' => 'failed' ) );
	$result        = hr_pay( $order );
	hr_assert( 'failure', $result['result'], 'The attempt fails.' );
	hr_assert( 'pending', $order->get_status(), 'The order is Pending, as after any Hosted decline.' );
	hr_assert( array(), $hr_status_log, 'Nothing is completed.' );
};

$tests['8c. a Failed order is reset before Blink is asked, so a webhook completing it meanwhile is kept'] = function () {
	global $hr_on_get;
	$order = hr_order( 335, array( 'status' => 'failed' ) );
	$order->add_meta_data( '_blink_paylink_id', '7035' );
	hr_paylink( '7035', array( 'status' => 'Payment Attempted' ) );
	$status_when_asked = null;
	$hr_on_get         = function () use ( $order, &$status_when_asked ) {
		$status_when_asked = $order->get_status();
		$order->status     = 'processing'; // A webhook completes the order while Blink is asked.
	};
	hr_pay( $order );
	hr_assert( 'pending', $status_when_asked, 'The reset runs before any call to Blink, not seconds later on a stale order.' );
	hr_assert( 'processing', $order->get_status(), 'The webhook\'s completion is not overwritten.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	hr_reset();
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		++$failures;
		echo "FAIL: {$name} - {$error->getMessage()}\n";
	}
}

echo count( $tests ) . ' tests, ' . $failures . " failures\n";
$tests_completed = true;
exit( 0 === $failures ? 0 : 1 );
