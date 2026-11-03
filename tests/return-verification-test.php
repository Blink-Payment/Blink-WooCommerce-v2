<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: return verification tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$rv_orders     = array();
$rv_responses  = array();
$rv_get_calls  = array();
$rv_token      = array( 'access_token' => 'test-token' );
$rv_status_log = array();

function __( $text, $domain = null ) { return $text; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wc_clean( $value ) { return sanitize_text_field( $value ); }
function absint( $value ) { return abs( (int) $value ); }
// The return page re-checks a Paylink after a short wait; tests don't wait.
function apply_filters( $hook, $value ) { return 'blink_hosted_return_recheck_delay' === $hook ? 0 : $value; }
function delete_transient( $key ) { return true; }

class WP_Error {
	private $message;
	public function __construct( $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

function wp_remote_get( $url, $args ) {
	global $rv_responses, $rv_get_calls;
	$rv_get_calls[] = $url;
	if ( ! array_key_exists( $url, $rv_responses ) ) {
		return array( 'body' => '{"error":"Not found"}', 'response' => array( 'code' => 404 ) );
	}
	return $rv_responses[ $url ];
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wc_get_order( $id ) {
	global $rv_orders;
	return isset( $rv_orders[ (string) $id ] ) ? $rv_orders[ (string) $id ] : false;
}

function WC() {
	global $rv_gateway;
	return (object) array(
		'payment_gateways' => new class {
			public function get_available_payment_gateways() {
				global $rv_gateway;
				return array( 'blink' => $rv_gateway );
			}
		},
	);
}

// Order status changes are recorded rather than run through WooCommerce.
function blink_is_preauth_transaction( $order ) {
	return $order && 'yes' === $order->get_meta( '_blink_preauth', true );
}

function blink_payment_complete( $order, $txn_id = '', $note = '', $allow_processing = false ) {
	global $rv_status_log;
	$rv_status_log[] = array( 'complete', $order->get_id(), $txn_id );
	$order->status = 'processing';
}

function blink_payment_on_hold( $order, $reason = '' ) {
	global $rv_status_log;
	$rv_status_log[] = array( 'hold', $order->get_id() );
	$order->status = 'on-hold';
}

function blink_payment_failed( $order, $reason = '' ) {
	global $rv_status_log;
	$rv_status_log[] = array( 'failed', $order->get_id() );
	$order->status = 'failed';
}

class Blink_Logger {
	public static $entries = array();
	public static function log( $message, $context = null ) { self::$entries[] = array( $message, $context ); }
	public static function http_response_context( $response ) { return array(); }
}

class RV_Meta {
	public $key;
	public $value;
	public function __construct( $key, $value ) { $this->key = $key; $this->value = $value; }
}

class RV_Order {
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
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = array( $value ); }
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ][] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function get_meta( $key, $single = true ) {
		$values = isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : array();
		if ( $single ) {
			return $values ? $values[0] : '';
		}
		return array_map( function ( $value ) use ( $key ) { return new RV_Meta( $key, $value ); }, $values );
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

class RV_Utils {
	public $destroyed = array();
	public function blink_generate_access_token() {
		global $rv_token;
		return $rv_token;
	}
	public function blink_destroy_session_tokens( $intent_id = '' ) {
		$this->destroyed[] = $intent_id;
	}
}

class RV_Gateway {
	public $host_url = 'https://gateway.example.com';
	public $utils;
	public $hosted = false;
	public function __construct() { $this->utils = new RV_Utils(); }
	public function blink_is_hosted() { return $this->hosted; }
}

$rv_gateway = new RV_Gateway();

function rv_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

function rv_reset() {
	global $rv_orders, $rv_responses, $rv_get_calls, $rv_token, $rv_status_log, $rv_gateway, $wp;
	$rv_orders     = array();
	$rv_responses  = array();
	$rv_get_calls  = array();
	$rv_token      = array( 'access_token' => 'test-token' );
	$rv_status_log = array();
	$rv_gateway    = new RV_Gateway();
	$wp            = null;
	$_GET          = array();
	$_REQUEST      = array();
	Blink_Logger::$entries = array();
	Blink_Transaction_Handler::$hosted_return_notice = null;
}

function rv_order( $id, $props = array() ) {
	global $rv_orders;
	$order = new RV_Order( $id );
	foreach ( $props as $key => $value ) {
		$order->$key = $value;
	}
	$rv_orders[ (string) $id ] = $order;
	return $order;
}

function rv_transaction( $id, $data ) {
	global $rv_responses;
	$rv_responses[ 'https://gateway.example.com/pay/v1/transactions/' . $id ] = array(
		'body'     => json_encode( array( 'data' => array_merge( array( 'transaction_id' => $id, 'currency' => null, 'payment_source' => 'Credit Card' ), $data ) ) ),
		'response' => array( 'code' => 200 ),
	);
}

function rv_paylink( $id, $data ) {
	global $rv_responses;
	$rv_responses[ 'https://gateway.example.com/paylink/v1/paylinks/' . $id ] = array(
		'body'     => json_encode( array_merge( array( 'id' => (int) $id, 'currency' => 'GBP', 'transaction_type' => 'Sale', 'amount' => '18.00' ), $data ) ),
		'response' => array( 'code' => 200 ),
	);
}

function rv_unavailable( $path ) {
	global $rv_responses;
	$rv_responses[ 'https://gateway.example.com' . $path ] = array( 'body' => '{"error":"Service unavailable"}', 'response' => array( 'code' => 503 ) );
}

/**
 * Load the order-received page the way WordPress does: blink_capture_order_response()
 * on wp, then blink_check_response_for_order() on the thank-you hook, which
 * WooCommerce only reaches when the order key is valid.
 */
function rv_visit( $order, $query ) {
	global $wp, $rv_gateway;
	$wp       = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
	$_GET     = $query;
	$_REQUEST = $query;
	Blink_Transaction_Handler::blink_capture_order_response();
	if ( isset( $query['key'] ) && $query['key'] === $order->get_order_key() ) {
		( new Blink_Transaction_Handler( $rv_gateway ) )->blink_check_response_for_order( $order->get_id() );
	}
	$_GET     = array();
	$_REQUEST = array();
}

function rv_direct_return( $order, $transaction_id, $extra = array() ) {
	rv_visit( $order, array_merge( array( 'key' => $order->get_order_key(), 'transaction_id' => $transaction_id ), $extra ) );
}

function rv_hosted_return( $order, $status, $extra = array() ) {
	rv_visit( $order, array_merge( array( 'key' => $order->get_order_key(), 'status' => $status, 'reference' => 'WC-' . $order->get_id() ), $extra ) );
}

function rv_hosted() {
	global $rv_gateway;
	$rv_gateway->hosted = true;
}

$tests = array();

// --- Forged returns ------------------------------------------------------

$tests['forged Hosted return with status=Paid leaves an unpaid order Pending'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 201 );
	$order->add_meta_data( '_blink_paylink_id', '6001' );
	rv_paylink( '6001', array( 'status' => 'Unpaid' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'pending', $order->get_status(), 'A URL status must not pay the order.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['forged Hosted return on an order with no stored Paylink stays Pending'] = function () {
	global $rv_status_log, $rv_get_calls;
	rv_hosted();
	$order = rv_order( 202 );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'pending', $order->get_status(), 'With nothing to look up, the order is left for the webhook.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( array(), $rv_get_calls, 'There is no Paylink to look up.' );
};

$tests['return with another order\'s paid transaction does not complete the order'] = function () {
	global $rv_status_log;
	$order = rv_order( 203 );
	rv_transaction( 'BL-OTHER-PAID', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":999}}' ) );
	rv_direct_return( $order, 'BL-OTHER-PAID' );
	rv_assert( 'pending', $order->get_status(), 'Another order\'s payment must not pay this order.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( '', $order->get_meta( 'blink_res', true ), 'The transaction must not be stored.' );
	rv_assert( '', $order->get_transaction_id(), 'The transaction ID must not be set.' );
};

$tests['return with a transaction ID and no key changes nothing'] = function () {
	global $rv_status_log, $rv_get_calls;
	$order = rv_order( 204 );
	rv_transaction( 'BL-NOKEY', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":204}}' ) );
	rv_visit( $order, array( 'transaction_id' => 'BL-NOKEY' ) );
	rv_assert( 'pending', $order->get_status(), 'Without the order key the order must not change.' );
	rv_assert( array(), $order->meta, 'No meta may be written.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( array(), $rv_get_calls, 'Nothing is looked up for an unauthenticated visitor.' );
};

$tests['return with a wrong key changes nothing'] = function () {
	global $rv_status_log;
	$order = rv_order( 205 );
	rv_transaction( 'BL-WRONGKEY', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":205}}' ) );
	rv_visit( $order, array( 'key' => 'wc_order_wrong', 'transaction_id' => 'BL-WRONGKEY' ) );
	rv_assert( 'pending', $order->get_status(), 'A wrong key must not change the order.' );
	rv_assert( array(), $order->meta, 'No meta may be written.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['return on a paid order does not replace its transaction'] = function () {
	global $rv_status_log;
	$order = rv_order( 206, array( 'status' => 'processing', 'transaction_id' => 'BL-ORIGINAL' ) );
	$order->update_meta_data( 'blink_res', 'BL-ORIGINAL' );
	$order->update_meta_data( '_blink_res_expired', 'true' );
	$meta_before = $order->meta;
	rv_transaction( 'BL-REPLACEMENT', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":206}}' ) );
	rv_direct_return( $order, 'BL-REPLACEMENT' );
	rv_visit( $order, array( 'transaction_id' => 'BL-REPLACEMENT' ) );
	rv_assert( 'BL-ORIGINAL', $order->get_meta( 'blink_res', true ), 'Refunds and captures must keep using the original transaction.' );
	rv_assert( 'BL-ORIGINAL', $order->get_transaction_id(), 'The order transaction ID must not change.' );
	rv_assert( $meta_before, $order->meta, 'No meta may change on a paid order.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

// --- Genuine Direct returns ---------------------------------------------

$tests['genuine Direct return completes the order with the verified transaction'] = function () {
	global $rv_status_log;
	$order = rv_order( 211 );
	rv_transaction( 'BL-DIRECT-PAID', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":211}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-PAID', array( 'status' => 'Declined' ) );
	rv_assert( 'processing', $order->get_status(), 'A verified payment completes the order, whatever the URL says.' );
	rv_assert( array( array( 'complete', 211, 'BL-DIRECT-PAID' ) ), $rv_status_log, 'The order is completed once.' );
	rv_assert( 'BL-DIRECT-PAID', $order->get_meta( 'blink_res', true ), 'The verified transaction is stored for refunds.' );
	rv_assert( 'BL-DIRECT-PAID', $order->get_transaction_id(), 'The order transaction ID is set.' );
};

$tests['genuine Direct decline fails the order with Blink\'s message, not the URL note'] = function () {
	$order = rv_order( 212 );
	rv_transaction( 'BL-DIRECT-DECLINED', array( 'status' => 'Declined', 'amount' => 18, 'message' => 'Card declined by issuer', 'merchant_data' => '{"order_info":{"order_id":212}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-DECLINED', array( 'status' => 'Paid', 'note' => 'URL-controlled note' ) );
	rv_assert( 'failed', $order->get_status(), 'A verified decline fails the order.' );
	rv_assert( 'Card declined by issuer', $order->get_meta( '_blink_payment_message', true ), 'The customer sees Blink\'s message.' );
};

$tests['genuine Direct pre-auth return puts a pre-auth order On-hold'] = function () {
	$order = rv_order( 213 );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	rv_transaction( 'BL-DIRECT-PREAUTH', array( 'status' => 'Preauth', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":213}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-PREAUTH' );
	rv_assert( 'on-hold', $order->get_status(), 'An authorised pre-auth waits for capture.' );
	rv_assert( 'BL-DIRECT-PREAUTH', $order->get_meta( 'blink_res', true ), 'The pre-auth is stored for the capture.' );
};

$tests['Direct return for a different amount is left for the webhook'] = function () {
	global $rv_status_log;
	$order = rv_order( 214 );
	rv_transaction( 'BL-DIRECT-WRONG-AMOUNT', array( 'status' => 'Captured', 'amount' => 5, 'merchant_data' => '{"order_info":{"order_id":214}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-WRONG-AMOUNT' );
	rv_assert( 'pending', $order->get_status(), 'A payment that does not fit the order must not complete it.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( '', $order->get_meta( 'blink_res', true ), 'The transaction is not stored.' );
};

$tests['reloading a processed Direct return does not apply it again'] = function () {
	global $rv_status_log;
	$order = rv_order( 215 );
	rv_transaction( 'BL-DIRECT-RELOAD', array( 'status' => 'Declined', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":215}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-RELOAD' );
	$notes = count( $order->notes );
	rv_direct_return( $order, 'BL-DIRECT-RELOAD' );
	rv_assert( array( array( 'failed', 215 ) ), $rv_status_log, 'The decline is applied once.' );
	rv_assert( $notes, count( $order->notes ), 'A reload adds no notes.' );
};

$tests['Direct return when Blink is unreachable leaves the order for the webhook'] = function () {
	global $rv_status_log;
	$order = rv_order( 216 );
	rv_unavailable( '/pay/v1/transactions/BL-DIRECT-DOWN' );
	rv_direct_return( $order, 'BL-DIRECT-DOWN', array( 'status' => 'Paid' ) );
	rv_assert( 'pending', $order->get_status(), 'The URL status must not be used when Blink cannot confirm it.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( '', $order->get_meta( 'blink_res', true ), 'Nothing is stored.' );

	rv_transaction( 'BL-DIRECT-DOWN', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":216}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-DOWN' );
	rv_assert( 'processing', $order->get_status(), 'Once Blink answers, a reload completes the order.' );
};

$tests['Direct return when the access token fails leaves the order unchanged'] = function () {
	global $rv_status_log, $rv_token;
	$rv_token = array();
	$order    = rv_order( 217 );
	rv_transaction( 'BL-DIRECT-NOTOKEN', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":217}}' ) );
	rv_direct_return( $order, 'BL-DIRECT-NOTOKEN', array( 'status' => 'Paid' ) );
	rv_assert( 'pending', $order->get_status(), 'The URL status must not be used.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

// --- Genuine Hosted returns ---------------------------------------------

$tests['genuine Hosted return on a Paid Paylink completes the order without a transaction ID'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 221 );
	$order->add_meta_data( '_blink_paylink_id', '6021' );
	rv_paylink( '6021', array( 'status' => 'Paid' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'processing', $order->get_status(), 'A Paid Paylink completes the order.' );
	rv_assert( array( array( 'complete', 221, '' ) ), $rv_status_log, 'The order is completed once, with no transaction ID.' );
	rv_assert( '6021', $order->get_meta( '_blink_paylink_paid_id', true ), 'The paid Paylink is recorded for the webhook.' );
	rv_assert( '', $order->get_meta( 'blink_res', true ), 'The webhook records the transaction later.' );
};

$tests['Hosted return on a Payment Attempted Paylink stays Pending'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 222 );
	$order->add_meta_data( '_blink_paylink_id', '6022' );
	rv_paylink( '6022', array( 'status' => 'Payment Attempted' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'pending', $order->get_status(), 'A declined, still-payable Paylink is not paid.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['Hosted return finds a payment on an older Paylink of the same order'] = function () {
	rv_hosted();
	$order = rv_order( 223 );
	$order->add_meta_data( '_blink_paylink_id', '6023' );
	$order->add_meta_data( '_blink_paylink_id', '6024' );
	rv_paylink( '6023', array( 'status' => 'Paid' ) );
	rv_paylink( '6024', array( 'status' => 'Unpaid' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'processing', $order->get_status(), 'A payment on any of the order\'s Paylinks counts.' );
	rv_assert( '6023', $order->get_meta( '_blink_paylink_paid_id', true ), 'The Paylink that was paid is recorded.' );
};

$tests['Hosted pre-auth return puts a pre-auth order On-hold'] = function () {
	rv_hosted();
	$order = rv_order( 224 );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->add_meta_data( '_blink_paylink_id', '6025' );
	rv_paylink( '6025', array( 'status' => 'Paid', 'transaction_type' => 'Preauth' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'on-hold', $order->get_status(), 'An authorised Hosted pre-auth waits for capture.' );
};

$tests['Hosted return on a Paylink for a different amount is left for the webhook'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 225 );
	$order->add_meta_data( '_blink_paylink_id', '6026' );
	rv_paylink( '6026', array( 'status' => 'Paid', 'amount' => '5.00' ) );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'pending', $order->get_status(), 'A payment that does not fit the order must not complete it.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['Hosted return when Blink is unreachable leaves the order Pending'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 226 );
	$order->add_meta_data( '_blink_paylink_id', '6027' );
	rv_unavailable( '/paylink/v1/paylinks/6027' );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( 'pending', $order->get_status(), 'The URL status must not be used when Blink cannot confirm it.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['Hosted decline return keeps the order Pending and shows the decline message'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 227 );
	$order->add_meta_data( '_blink_paylink_id', '6028' );
	rv_paylink( '6028', array( 'status' => 'Payment Attempted' ) );
	rv_hosted_return( $order, 'Declined' );
	rv_assert( 'pending', $order->get_status(), 'Blink keeps a declined Paylink payable, so the order stays Pending.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
	rv_assert( 'declined', Blink_Transaction_Handler::$hosted_return_notice['type'], 'The fixed decline message is shown.' );
};

$tests['Hosted decline return with a wrong reference changes nothing'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 228 );
	rv_hosted_return( $order, 'Declined', array( 'reference' => 'WC-999' ) );
	rv_assert( 'pending', $order->get_status(), 'A mismatched reference must not change the order.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['Hosted decline return does not fail a paid order'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 230, array( 'status' => 'processing' ) );
	rv_hosted_return( $order, 'Declined' );
	rv_assert( 'processing', $order->get_status(), 'A URL decline must not undo a payment.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['Hosted decline return does not fail an authorised pre-auth'] = function () {
	global $rv_status_log;
	rv_hosted();
	$order = rv_order( 231, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	rv_hosted_return( $order, 'Declined' );
	rv_assert( 'on-hold', $order->get_status(), 'A URL decline must not fail an order waiting for capture.' );
	rv_assert( array(), $rv_status_log, 'No status change may run.' );
};

$tests['paid Hosted order shows no stale decline message'] = function () {
	rv_hosted();
	$order = rv_order( 229, array( 'status' => 'processing' ) );
	$order->update_meta_data( '_blink_payment_message', 'Payment was declined. Please try again.' );
	rv_hosted_return( $order, 'Paid' );
	rv_assert( '', $order->get_meta( '_blink_payment_message', true ), 'A paid order must not keep a decline message.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	rv_reset();
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
