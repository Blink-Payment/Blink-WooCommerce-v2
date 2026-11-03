<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: webhook verification tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$wv_orders     = array();
$wv_responses  = array();
$wv_get_calls  = array();
$wv_post_calls = array();
$wv_scheduled  = array();
$wv_token      = array( 'access_token' => 'test-token' );
$wv_status_log = array();

function __( $text, $domain = null ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_woocommerce_currency() { return 'GBP'; }
function blink_get_full_address( $order ) { return '1 Test Street'; }

class WP_Error {
	private $message;
	public function __construct( $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

function wp_remote_get( $url, $args ) {
	global $wv_responses, $wv_get_calls;
	$wv_get_calls[] = $url;
	if ( ! array_key_exists( $url, $wv_responses ) ) {
		return array( 'body' => '{"error":"Not found"}', 'response' => array( 'code' => 404 ) );
	}
	return $wv_responses[ $url ];
}

function wp_remote_post( $url, $args ) {
	global $wv_post_calls;
	$wv_post_calls[] = array( 'url' => $url, 'args' => $args );
	return array( 'body' => '{"id":1709999,"paylink_url":"https://pay.example.com/l/abc"}', 'response' => array( 'code' => 201 ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	global $wv_scheduled;
	$wv_scheduled[] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $wv_scheduled );
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	global $wv_scheduled;
	foreach ( $wv_scheduled as $action ) {
		if ( $action['hook'] === $hook && ( null === $args || $action['args'] == $args ) ) {
			return true;
		}
	}
	return false;
}

function wc_get_order( $id ) {
	global $wv_orders;
	return isset( $wv_orders[ (string) $id ] ) ? $wv_orders[ (string) $id ] : false;
}

// Only the "recorded on another order" lookups are answered; the recent-order
// fallback in order lookup finds nothing.
function wc_get_orders( $args ) {
	global $wv_orders;
	if ( ! isset( $args['transaction_id'] ) && ! isset( $args['meta_query'] ) ) {
		return array();
	}
	$exclude = isset( $args['exclude'] ) ? $args['exclude'] : array();
	$ids     = array();
	foreach ( $wv_orders as $order ) {
		if ( in_array( $order->get_id(), $exclude, true ) ) {
			continue;
		}
		$matches = isset( $args['transaction_id'] )
			? $order->get_transaction_id() === $args['transaction_id']
			: $order->get_meta( $args['meta_query'][0]['key'], true ) === $args['meta_query'][0]['value'];
		if ( $matches ) {
			$ids[] = $order->get_id();
		}
	}
	return $ids;
}

function WC() {
	return new class {
		public function api_request_url( $endpoint ) { return 'https://shop.example.com/wc-api/' . $endpoint . '/'; }
	};
}

// Order status changes are recorded rather than run through WooCommerce.
function blink_is_preauth_transaction( $order ) {
	return $order && 'yes' === $order->get_meta( '_blink_preauth', true );
}

function blink_payment_complete( $order, $txn_id = '', $note = '', $allow_processing = false ) {
	global $wv_status_log;
	$wv_status_log[] = array( 'complete', $order->get_id(), $txn_id );
	$order->status = 'processing';
}

function blink_payment_on_hold( $order, $reason = '' ) {
	global $wv_status_log;
	$wv_status_log[] = array( 'hold', $order->get_id() );
	$order->status = 'on-hold';
}

function blink_payment_failed( $order, $reason = '' ) {
	global $wv_status_log;
	$wv_status_log[] = array( 'failed', $order->get_id() );
	$order->status = 'failed';
}

class Blink_Logger {
	public static $entries = array();
	public static function log( $message, $context = null ) { self::$entries[] = array( $message, $context ); }
	public static function http_response_context( $response ) { return array(); }
}

class WV_Meta {
	public $key;
	public $value;
	public function __construct( $key, $value ) { $this->key = $key; $this->value = $value; }
}

class WV_Order {
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
		return array_map( function ( $value ) use ( $key ) { return new WV_Meta( $key, $value ); }, $values );
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

// Capture webhooks are handed to the rerun handler, which has its own tests;
// here we only record what it was given.
class WV_Transaction_Handler extends Blink_Transaction_Handler {
	public $rerun_calls = array();
	public function blink_handle_rerun_webhook( $order, $request, $transaction_id ) {
		if ( ! $this->blink_is_rerun_webhook( $order, $request, $transaction_id ) ) {
			return false;
		}
		$this->rerun_calls[] = array( 'order_id' => $order->get_id(), 'status' => $request['status'], 'transaction_id' => $transaction_id );
		return true;
	}
}

class WV_Rerun_Handler {
	public $in_progress = false;
	public function is_rerun_in_progress( $order ) { return $this->in_progress; }
}

class WV_Utils {
	public function blink_generate_access_token() {
		global $wv_token;
		return $wv_token;
	}
}

$wv_gateway = (object) array(
	'host_url'              => 'https://gateway.example.com',
	'secret_key'            => 'test-secret',
	'utils'                 => new WV_Utils(),
	'rerun_handler'         => new WV_Rerun_Handler(),
	'preauthorize_payments' => false,
	'paymentMethods'        => array( 'credit-card' ),
);

function wv_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

function wv_assert_contains( $needle, $haystack, $message ) {
	foreach ( (array) $haystack as $item ) {
		if ( false !== strpos( (string) $item, $needle ) ) {
			return;
		}
	}
	throw new RuntimeException( $message . ' Missing: ' . $needle . ' in ' . var_export( $haystack, true ) );
}

function wv_reset() {
	global $wv_orders, $wv_responses, $wv_get_calls, $wv_post_calls, $wv_scheduled, $wv_token, $wv_status_log, $wv_gateway;
	$wv_orders     = array();
	$wv_responses  = array();
	$wv_get_calls  = array();
	$wv_post_calls = array();
	$wv_scheduled  = array();
	$wv_token      = array( 'access_token' => 'test-token' );
	$wv_status_log = array();
	$wv_gateway->rerun_handler->in_progress = false;
	$wv_gateway->preauthorize_payments      = false;
	Blink_Logger::$entries = array();
}

function wv_order( $id, $props = array() ) {
	global $wv_orders;
	$order = new WV_Order( $id );
	foreach ( $props as $key => $value ) {
		$order->$key = $value;
	}
	$wv_orders[ (string) $id ] = $order;
	return $order;
}

function wv_transaction( $id, $data ) {
	global $wv_responses;
	$wv_responses[ 'https://gateway.example.com/pay/v1/transactions/' . $id ] = array(
		'body'     => json_encode( array( 'data' => array_merge( array( 'transaction_id' => $id, 'currency' => null ), $data ) ) ),
		'response' => array( 'code' => 200 ),
	);
}

function wv_paylink( $id, $data ) {
	global $wv_responses;
	$wv_responses[ 'https://gateway.example.com/paylink/v1/paylinks/' . $id ] = array(
		'body'     => json_encode( array_merge( array( 'id' => (int) $id, 'currency' => 'GBP', 'transaction_type' => 'Sale' ), $data ) ),
		'response' => array( 'code' => 200 ),
	);
}

// Hosted transactions come back from Blink with a currency and no merchant_data.
function wv_hosted_transaction( $id, $data = array() ) {
	wv_transaction( $id, array_merge( array( 'status' => 'Captured', 'amount' => 18, 'currency' => 'GBP' ), $data ) );
}

function wv_redirect( $order_id, $key ) {
	return 'https://shop.example.com/checkout/order-received/' . $order_id . '/?key=' . $key;
}

function wv_direct_request( $transaction_id, $order_id, $status, $extra = array() ) {
	return array_merge(
		array(
			'transaction_id' => $transaction_id,
			'event_type'     => 'new_transaction',
			'status'         => $status,
			'merchant_data'  => array( 'order_info' => array( 'order_id' => $order_id ) ),
		),
		$extra
	);
}

function wv_hosted_request( $paylink_id, $order_id, $transaction_id = 'BL-HOSTED-1' ) {
	return array(
		'transaction_id' => $transaction_id,
		'event_type'     => 'paylink_status_change',
		'paylink_id'     => (string) $paylink_id,
		'reference'      => 'WC-' . $order_id,
		'status'         => 'Paid',
		'amount'         => '18.00',
	);
}

function wv_handler() {
	global $wv_gateway;
	return new WV_Transaction_Handler( $wv_gateway );
}

$tests = array();

// --- Forged webhooks -----------------------------------------------------

$tests['forged Paid status on a real declined transaction does not complete the order'] = function () {
	$order = wv_order( 101 );
	wv_transaction( 'BL-DECLINED', array( 'status' => 'Declined', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":101}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-DECLINED', 101, 'Paid' ) );
	wv_assert( false, $order->has_status( array( 'processing', 'completed', 'on-hold' ) ), 'A declined transaction must not pay the order.' );
};

$tests['real transaction from another order is rejected and changes nothing'] = function () {
	global $wv_status_log;
	$order = wv_order( 102 );
	wv_transaction( 'BL-OTHER', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":999}}' ) );
	$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-OTHER', 102, 'Captured' ) );
	wv_assert( 403, $result['code'], 'A transaction for another order must be forbidden.' );
	wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
	wv_assert( array(), $wv_status_log, 'No status change may run.' );
};

$tests['transaction unknown to Blink is rejected'] = function () {
	$order  = wv_order( 103 );
	$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-MADE-UP', 103, 'Captured' ) );
	wv_assert( 403, $result['code'], 'An unknown transaction must be forbidden.' );
	wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
};

$tests['hosted webhook naming a Payment Attempted paylink does not complete the order'] = function () {
	global $wv_status_log;
	$order = wv_order( 104 );
	$order->add_meta_data( '_blink_paylink_id', '5001' );
	wv_paylink( 5001, array( 'status' => 'Payment Attempted', 'amount' => '18.00', 'redirect_url' => wv_redirect( 104, 'wc_order_genuine' ) ) );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5001, 104 ) );
	wv_assert( 200, $result['code'], 'A genuine but unpaid paylink is acknowledged.' );
	wv_assert( 'pending', $order->get_status(), 'A declined paylink must not pay the order.' );
	wv_assert( array(), $wv_status_log, 'No status change may run.' );
};

$tests['hosted webhook naming an Unpaid paylink does not complete the order'] = function () {
	$order = wv_order( 105 );
	$order->add_meta_data( '_blink_paylink_id', '5002' );
	wv_paylink( 5002, array( 'status' => 'Unpaid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 105, 'wc_order_genuine' ) ) );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5002, 105 ) );
	wv_assert( 'pending', $order->get_status(), 'An unpaid paylink must not pay the order.' );
};

$tests['hosted webhook naming another order\'s paid paylink is rejected'] = function () {
	$order = wv_order( 106 );
	wv_paylink( 5003, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 999, 'wc_order_other' ) ) );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5003, 106 ) );
	wv_assert( 403, $result['code'], 'Another order\'s paylink must be forbidden.' );
	wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
};

$tests['legacy paylink with the right order ID but wrong key is rejected'] = function () {
	$order  = wv_order( 107 );
	wv_paylink( 5004, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 107, 'wc_order_guess' ) ) );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5004, 107 ) );
	wv_assert( 403, $result['code'], 'A paylink whose redirect key does not match must be forbidden.' );
	wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
};

$tests['response does not reveal the order status'] = function () {
	wv_order( 108 );
	wv_transaction( 'BL-OK-108', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":108}}' ) );
	$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-OK-108', 108, 'Captured' ) );
	wv_assert( false, array_key_exists( 'order_status', $result['body'] ), 'The response must not include the order status.' );
};

// A genuine Paid Paylink paired with a transaction ID the webhook made up or borrowed.
$forged_hosted_transactions = array(
	'unknown to Blink'                       => function () {},
	'declined'                               => function () { wv_hosted_transaction( 'BL-SWAP', array( 'status' => 'Declined' ) ); },
	'for a different amount'                 => function () { wv_hosted_transaction( 'BL-SWAP', array( 'amount' => 5 ) ); },
	'in a different currency'                => function () { wv_hosted_transaction( 'BL-SWAP', array( 'currency' => 'EUR' ) ); },
	'from another order\'s direct payment'   => function () { wv_hosted_transaction( 'BL-SWAP', array( 'merchant_data' => '{"order_info":{"order_id":999}}' ) ); },
	'recorded on another order'              => function () { wv_hosted_transaction( 'BL-SWAP' ); wv_order( 998, array( 'status' => 'processing', 'transaction_id' => 'BL-SWAP' ) ); },
	'kept as another captured order\'s pre-auth' => function () {
		wv_hosted_transaction( 'BL-SWAP' );
		$other = wv_order( 997, array( 'status' => 'processing', 'transaction_id' => 'BL-OTHER-CAPTURE' ) );
		$other->update_meta_data( 'blink_res', 'BL-SWAP' );
	},
);
foreach ( $forged_hosted_transactions as $name => $setup ) {
	$tests[ "hosted webhook pairing a paid paylink with a transaction {$name} is rejected" ] = function () use ( $setup ) {
		global $wv_status_log;
		$order = wv_order( 109 );
		$order->add_meta_data( '_blink_paylink_id', '5005' );
		wv_paylink( 5005, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 109, 'wc_order_genuine' ) ) );
		$setup();
		$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5005, 109, 'BL-SWAP' ) );
		wv_assert( 403, $result['code'], 'An unconfirmed transaction must be forbidden.' );
		wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
		wv_assert( '', $order->get_transaction_id(), 'The transaction ID must not be stored.' );
		wv_assert( '', $order->get_meta( 'blink_res', true ), 'The refund transaction must not be stored.' );
		wv_assert( array(), $wv_status_log, 'No status change may run.' );
	};
}

$tests['forged hosted webhook arriving first does not block the genuine one'] = function () {
	global $wv_status_log;
	$order = wv_order( 110 );
	$order->add_meta_data( '_blink_paylink_id', '5006' );
	wv_paylink( 5006, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 110, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-GENUINE-110' );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5006, 110, 'BL-MADE-UP' ) );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5006, 110, 'BL-GENUINE-110' ) );
	wv_assert( array( array( 'complete', 110, 'BL-GENUINE-110' ) ), $wv_status_log, 'Only the genuine webhook completes the order.' );
	wv_assert( 'BL-GENUINE-110', $order->get_meta( 'blink_res', true ), 'The genuine transaction is kept for refunds.' );
};

// --- Genuine webhooks ----------------------------------------------------

$tests['genuine direct card payment completes the order using the API status'] = function () {
	global $wv_status_log;
	$order = wv_order( 201 );
	wv_transaction( 'BL-CARD', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":201}}' ) );
	$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-CARD', 201, 'captured' ) );
	wv_assert( 200, $result['code'], 'A genuine payment is accepted.' );
	wv_assert( array( array( 'complete', 201, 'BL-CARD' ) ), $wv_status_log, 'The order must be completed once.' );
	wv_assert( 'BL-CARD', $order->get_transaction_id(), 'The transaction ID is stored.' );
};

$tests['direct pre-auth uses the API status, not the webhook\'s "reversed"'] = function () {
	$order = wv_order( 202 );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	wv_transaction( 'BL-PREAUTH', array( 'status' => 'Preauth', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":202}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-PREAUTH', 202, 'reversed' ) );
	wv_assert( 'on-hold', $order->get_status(), 'An authorised pre-auth puts the order On-hold.' );
};

$tests['genuine hosted payment on a stored paylink completes the order'] = function () {
	global $wv_status_log;
	$order = wv_order( 203 );
	$order->add_meta_data( '_blink_paylink_id', '5101' );
	wv_paylink( 5101, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 203, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-H-203' );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5101, 203, 'BL-H-203' ) );
	wv_assert( 200, $result['code'], 'A genuine hosted payment is accepted.' );
	wv_assert( array( array( 'complete', 203, 'BL-H-203' ) ), $wv_status_log, 'The order must be completed once.' );
};

$tests['genuine hosted payment on an older paylink of the same order is recognised'] = function () {
	$order = wv_order( 204 );
	$order->add_meta_data( '_blink_paylink_id', '5201' );
	$order->add_meta_data( '_blink_paylink_id', '5202' );
	wv_paylink( 5201, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 204, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-HOSTED-1' );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5201, 204 ) );
	wv_assert( 'processing', $order->get_status(), 'A payment on the first paylink must complete the order.' );
};

$tests['hosted order from before the fix is verified through the paylink redirect URL'] = function () {
	$order = wv_order( 205 );
	wv_paylink( 5301, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 205, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-HOSTED-1' );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5301, 205 ) );
	wv_assert( 'processing', $order->get_status(), 'A legacy paylink with matching order ID and key completes the order.' );
};

$tests['hosted pre-auth paylink puts a pre-auth order On-hold'] = function () {
	$order = wv_order( 206 );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->add_meta_data( '_blink_paylink_id', '5401' );
	wv_paylink( 5401, array( 'status' => 'Paid', 'transaction_type' => 'Preauth', 'amount' => '18.00', 'redirect_url' => wv_redirect( 206, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-HOSTED-1', array( 'status' => 'Preauth' ) );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5401, 206 ) );
	wv_assert( 'on-hold', $order->get_status(), 'An authorised hosted pre-auth puts the order On-hold.' );
};

// A Hosted return completes the order from its Paylink, which has no transaction ID.
function wv_order_paid_by_hosted_return( $id, $paylink_id, $props = array() ) {
	$order = wv_order( $id, array_merge( array( 'status' => 'processing' ), $props ) );
	$order->add_meta_data( '_blink_paylink_id', $paylink_id );
	$order->update_meta_data( '_blink_paylink_paid_id', $paylink_id );
	$order->update_meta_data( 'status', 'Paid' );
	wv_paylink( $paylink_id, array( 'status' => 'Paid', 'amount' => '18.00' ) );
	return $order;
}

$tests['webhook records the transaction of a Paylink a Hosted return already applied'] = function () {
	global $wv_status_log;
	$order = wv_order_paid_by_hosted_return( 207, '5501' );
	wv_hosted_transaction( 'BL-H-207' );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5501, 207, 'BL-H-207' ) );
	wv_assert( 200, $result['code'], 'The genuine webhook is accepted.' );
	wv_assert( 'BL-H-207', $order->get_meta( 'blink_res', true ), 'The transaction is stored for refunds and captures.' );
	wv_assert( 'BL-H-207', $order->get_transaction_id(), 'The order transaction ID is set.' );
	wv_assert( array(), $wv_status_log, 'The order is not completed again.' );
	wv_assert( 'processing', $order->get_status(), 'The order stays paid.' );
	wv_assert_contains( 'BL-H-207', $order->notes, 'The merchant is told the transaction was recorded.' );

	$notes = count( $order->notes );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5501, 207, 'BL-H-207' ) );
	wv_assert( $notes, count( $order->notes ), 'A repeat adds nothing.' );
};

$tests['webhook does not record a transaction that does not fit the Paylink a Hosted return applied'] = function () {
	$order = wv_order_paid_by_hosted_return( 208, '5502' );
	wv_hosted_transaction( 'BL-H-208-WRONG', array( 'amount' => 5 ) );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5502, 208, 'BL-H-208-WRONG' ) );
	wv_assert( 403, $result['code'], 'A transaction for a different amount is not linked to the Paylink.' );
	wv_assert( '', $order->get_meta( 'blink_res', true ), 'Nothing is stored.' );
	wv_assert( '', $order->get_transaction_id(), 'The transaction ID stays empty for the genuine webhook.' );
};

$tests['webhook does not record a transaction another order already has after a Hosted return'] = function () {
	wv_order( 997, array( 'status' => 'processing', 'transaction_id' => 'BL-H-TAKEN' ) );
	$order = wv_order_paid_by_hosted_return( 209, '5503' );
	wv_hosted_transaction( 'BL-H-TAKEN' );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5503, 209, 'BL-H-TAKEN' ) );
	wv_assert( 403, $result['code'], 'Another order\'s transaction is not linked to this Paylink.' );
	wv_assert( '', $order->get_transaction_id(), 'Nothing is stored.' );
};

$tests['webhook records a Hosted pre-auth a return put On-hold and says how to capture it'] = function () {
	global $wv_status_log;
	$order = wv_order_paid_by_hosted_return( 210, '5504', array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	wv_paylink( '5504', array( 'status' => 'Paid', 'transaction_type' => 'Preauth', 'amount' => '18.00' ) );
	wv_hosted_transaction( 'BL-H-210', array( 'status' => 'Preauth' ) );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5504, 210, 'BL-H-210' ) );
	wv_assert( 'BL-H-210', $order->get_meta( 'blink_res', true ), 'The pre-auth is stored so it can be captured.' );
	wv_assert( array(), $wv_status_log, 'The hold is not applied again.' );
	wv_assert_contains( 'Process order to take payment', $order->notes, 'The merchant is told how to capture it.' );
};

// --- Genuine but unexpected ----------------------------------------------

$tests['genuine direct payment for a different amount puts the order On-hold to reconcile'] = function () {
	global $wv_status_log;
	$order = wv_order( 301 );
	wv_transaction( 'BL-OLD-TOTAL', array( 'status' => 'Captured', 'amount' => 16, 'merchant_data' => '{"order_info":{"order_id":301}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-OLD-TOTAL', 301, 'Captured' ) );
	wv_assert( 'on-hold', $order->get_status(), 'A payment for the wrong amount must not complete the order.' );
	wv_assert( false, in_array( array( 'complete', 301, 'BL-OLD-TOTAL' ), $wv_status_log, true ), 'Payment completion must not run.' );
	wv_assert_contains( 'reconcile', $order->notes, 'The merchant is asked to reconcile.' );
};

$tests['genuine hosted payment for a different currency puts the order On-hold to reconcile'] = function () {
	$order = wv_order( 302 );
	$order->add_meta_data( '_blink_paylink_id', '5501' );
	wv_paylink( 5501, array( 'status' => 'Paid', 'amount' => '18.00', 'currency' => 'EUR', 'redirect_url' => wv_redirect( 302, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-HOSTED-1', array( 'currency' => 'EUR' ) );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5501, 302 ) );
	wv_assert( 'on-hold', $order->get_status(), 'A payment in the wrong currency must not complete the order.' );
	wv_assert_contains( 'reconcile', $order->notes, 'The merchant is asked to reconcile.' );
};

$tests['pre-auth transaction on a sale order leaves the order unchanged with a note'] = function () {
	$order = wv_order( 303 );
	wv_transaction( 'BL-WRONG-MODE', array( 'status' => 'Preauth', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":303}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-WRONG-MODE', 303, 'Preauth' ) );
	wv_assert( 'pending', $order->get_status(), 'A mode mismatch must not change the order.' );
	wv_assert_contains( 'BL-WRONG-MODE', $order->notes, 'The merchant gets a note naming the transaction.' );
};

$tests['second genuine payment on a paid order adds a refund note'] = function () {
	$order = wv_order( 304, array( 'status' => 'processing', 'transaction_id' => 'BL-FIRST' ) );
	wv_transaction( 'BL-SECOND', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":304}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-SECOND', 304, 'Captured' ) );
	wv_assert( 'processing', $order->get_status(), 'The order stays paid.' );
	wv_assert( 'BL-FIRST', $order->get_transaction_id(), 'The first payment stays recorded.' );
	wv_assert_contains( 'BL-SECOND', $order->notes, 'The second payment is noted for refund.' );
	wv_assert_contains( 'refund', $order->notes, 'The note says it may need refunding.' );
};

$tests['repeated webhook for the same payment is a no-op'] = function () {
	global $wv_status_log;
	$order = wv_order( 305, array( 'status' => 'processing', 'transaction_id' => 'BL-SAME' ) );
	wv_transaction( 'BL-SAME', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":305}}' ) );
	$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-SAME', 305, 'Captured' ) );
	wv_assert( 200, $result['code'], 'A repeat is acknowledged.' );
	wv_assert( array(), $wv_status_log, 'No status change may run again.' );
	wv_assert( array(), $order->notes, 'No duplicate note is added.' );
};

$tests['late genuine decline does not fail a paid order'] = function () {
	$order = wv_order( 306, array( 'status' => 'processing', 'transaction_id' => 'BL-PAID' ) );
	wv_transaction( 'BL-EARLIER-DECLINE', array( 'status' => 'Declined', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":306}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-EARLIER-DECLINE', 306, 'Declined' ) );
	wv_assert( 'processing', $order->get_status(), 'A late decline must not fail a paid order.' );
	wv_assert_contains( 'BL-EARLIER-DECLINE', $order->notes, 'The ignored decline is noted.' );
};

$tests['paid order whose own transaction is now voided goes On-hold once'] = function () {
	$order = wv_order( 308, array( 'status' => 'processing', 'transaction_id' => 'BL-VOIDED' ) );
	$order->update_meta_data( 'status', 'Captured' );
	wv_transaction( 'BL-VOIDED', array( 'status' => 'Voided', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":308}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-VOIDED', 308, 'Voided' ) );
	wv_assert( 'on-hold', $order->get_status(), 'A reversed payment must stop the order being treated as paid.' );
	wv_assert_contains( 'Voided', $order->notes, 'The merchant is told the new status.' );
	$notes = count( $order->notes );
	$order->status = 'processing';
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-VOIDED', 308, 'Voided' ) );
	wv_assert( 'processing', $order->get_status(), 'A repeat must not undo the merchant\'s decision.' );
	wv_assert( $notes, count( $order->notes ), 'A repeat adds no note.' );
};

$tests['repeated void on an order it put On-hold keeps it On-hold'] = function () {
	$order = wv_order( 310, array( 'status' => 'processing', 'transaction_id' => 'BL-VOIDED-TWICE' ) );
	$order->update_meta_data( 'status', 'Captured' );
	wv_transaction( 'BL-VOIDED-TWICE', array( 'status' => 'Voided', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":310}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-VOIDED-TWICE', 310, 'Voided' ) );
	wv_assert( 'on-hold', $order->get_status(), 'A reversed payment must stop the order being treated as paid.' );
	$notes = count( $order->notes );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-VOIDED-TWICE', 310, 'Voided' ) );
	wv_assert( 'on-hold', $order->get_status(), 'A repeat must not fail the held order.' );
	wv_assert( $notes, count( $order->notes ), 'A repeat adds no note.' );
};

$tests['paid order whose own transaction is now refunded only gets a note'] = function () {
	$order = wv_order( 309, array( 'status' => 'processing', 'transaction_id' => 'BL-REFUNDED' ) );
	wv_transaction( 'BL-REFUNDED', array( 'status' => 'Refunded', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":309}}' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-REFUNDED', 309, 'Refunded' ) );
	wv_assert( 'processing', $order->get_status(), 'A refund leaves the order status to WooCommerce.' );
	wv_assert_contains( 'Refunded', $order->notes, 'The refund is noted.' );
};

$tests['second hosted paylink paid on a paid order adds a refund note'] = function () {
	$order = wv_order( 307, array( 'status' => 'processing', 'transaction_id' => 'BL-H-FIRST' ) );
	$order->add_meta_data( '_blink_paylink_id', '5601' );
	$order->add_meta_data( '_blink_paylink_id', '5602' );
	$order->update_meta_data( '_blink_paylink_paid_id', '5601' );
	wv_paylink( 5602, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 307, 'wc_order_genuine' ) ) );
	wv_hosted_transaction( 'BL-H-SECOND' );
	wv_handler()->blink_process_webhook( wv_hosted_request( 5602, 307, 'BL-H-SECOND' ) );
	wv_assert( 'processing', $order->get_status(), 'The order stays paid.' );
	wv_assert_contains( 'refund', $order->notes, 'The second paylink payment is noted for refund.' );
};

// --- Blink unavailable ---------------------------------------------------

$unavailable = array(
	'access token failure' => function () { global $wv_token; $wv_token = array(); },
	'transport error'      => function () { global $wv_responses; $wv_responses['https://gateway.example.com/pay/v1/transactions/BL-DOWN'] = new WP_Error( 'timeout' ); },
	'HTTP 500'             => function () { global $wv_responses; $wv_responses['https://gateway.example.com/pay/v1/transactions/BL-DOWN'] = array( 'body' => '', 'response' => array( 'code' => 500 ) ); },
	'HTTP 429'             => function () { global $wv_responses; $wv_responses['https://gateway.example.com/pay/v1/transactions/BL-DOWN'] = array( 'body' => '', 'response' => array( 'code' => 429 ) ); },
);
foreach ( $unavailable as $name => $setup ) {
	$tests[ "{$name} returns 503 and schedules a re-check" ] = function () use ( $setup ) {
		global $wv_scheduled, $wv_status_log;
		$order = wv_order( 401 );
		$setup();
		$result = wv_handler()->blink_process_webhook( wv_direct_request( 'BL-DOWN', 401, 'Captured' ) );
		wv_assert( 503, $result['code'], 'Blink must be asked to retry.' );
		wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
		wv_assert( array(), $wv_status_log, 'No status change may run.' );
		wv_assert( 1, count( $wv_scheduled ), 'One re-check is scheduled.' );
		wv_assert( 'blink_recheck_webhook', $wv_scheduled[0]['hook'], 'The re-check uses the Blink hook.' );
	};
}

$tests['hosted transaction lookup failing returns 503 and schedules a re-check'] = function () {
	global $wv_scheduled, $wv_responses;
	$order = wv_order( 405 );
	$order->add_meta_data( '_blink_paylink_id', '5702' );
	wv_paylink( 5702, array( 'status' => 'Paid', 'amount' => '18.00', 'redirect_url' => wv_redirect( 405, 'wc_order_genuine' ) ) );
	$wv_responses['https://gateway.example.com/pay/v1/transactions/BL-H-405'] = array( 'body' => '', 'response' => array( 'code' => 502 ) );
	$result = wv_handler()->blink_process_webhook( wv_hosted_request( 5702, 405, 'BL-H-405' ) );
	wv_assert( 503, $result['code'], 'Blink must be asked to retry.' );
	wv_assert( 'pending', $order->get_status(), 'The order must be unchanged.' );
	wv_assert( 1, count( $wv_scheduled ), 'One re-check is scheduled.' );
};

$tests['re-check completes a genuine payment once Blink is reachable'] = function () {
	global $wv_scheduled, $wv_token;
	$order    = wv_order( 402 );
	$wv_token = array();
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-LATER', 402, 'Captured' ) );
	$wv_token = array( 'access_token' => 'test-token' );
	wv_transaction( 'BL-LATER', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":402}}' ) );
	wv_handler()->blink_recheck_webhook_request( $wv_scheduled[0]['args'][0] );
	wv_assert( 'processing', $order->get_status(), 'The re-check completes the order.' );
};

$tests['re-check is not scheduled twice for the same webhook'] = function () {
	global $wv_scheduled, $wv_token;
	wv_order( 403 );
	$wv_token = array();
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-TWICE', 403, 'Captured' ) );
	wv_handler()->blink_process_webhook( wv_direct_request( 'BL-TWICE', 403, 'Captured' ) );
	wv_assert( 1, count( $wv_scheduled ), 'Blink retries must not pile up re-checks.' );
};

$tests['scheduled re-check holds no customer details'] = function () {
	global $wv_scheduled, $wv_token;
	wv_order( 404 );
	$wv_token = array();
	wv_handler()->blink_process_webhook( array_merge( wv_hosted_request( 5701, 404 ), array( 'name' => 'Test Customer' ) ) );
	wv_assert( false, strpos( json_encode( $wv_scheduled ), 'Test Customer' ), 'Customer names must not be stored in the scheduler.' );
};

// --- Captures ------------------------------------------------------------

$tests['capture webhook for the recorded capture uses the API status'] = function () {
	$handler = wv_handler();
	$order   = wv_order( 501, array( 'status' => 'processing' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	$order->update_meta_data( 'blink_rerun_id', 'BL-CAPTURE' );
	wv_transaction( 'BL-CAPTURE', array( 'status' => 'Captured', 'amount' => 18 ) );
	$handler->blink_process_webhook( wv_direct_request( 'BL-CAPTURE', 501, 'Declined', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( array( array( 'order_id' => 501, 'status' => 'Captured', 'transaction_id' => 'BL-CAPTURE' ) ), $handler->rerun_calls, 'The capture handler must get the API status.' );
};

$tests['capture webhook while a capture is in progress is accepted when the amount matches'] = function () {
	global $wv_gateway;
	$handler = wv_handler();
	$order   = wv_order( 502, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	$wv_gateway->rerun_handler->in_progress = true;
	wv_transaction( 'BL-CAPTURE-NEW', array( 'status' => 'Captured', 'amount' => 18 ) );
	$handler->blink_process_webhook( wv_direct_request( 'BL-CAPTURE-NEW', 502, 'Captured', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( 1, count( $handler->rerun_calls ), 'An in-flight capture is handled.' );
};

$tests['capture webhook with no capture in progress is rejected'] = function () {
	$handler = wv_handler();
	$order   = wv_order( 503, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	wv_transaction( 'BL-STRAY-CAPTURE', array( 'status' => 'Captured', 'amount' => 18 ) );
	$result = $handler->blink_process_webhook( wv_direct_request( 'BL-STRAY-CAPTURE', 503, 'Captured', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( 403, $result['code'], 'A capture nobody started must be forbidden.' );
	wv_assert( array(), $handler->rerun_calls, 'The capture handler must not run.' );
};

// A capture transaction from the API has no merchant_data and nothing naming its
// pre-auth, so while a capture is in progress another order's capture for the same
// amount cannot be told apart. These cover what can be checked.
$tests['capture webhook while a capture is in progress is rejected when the amount differs'] = function () {
	global $wv_gateway;
	$handler = wv_handler();
	$order   = wv_order( 504, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	$wv_gateway->rerun_handler->in_progress = true;
	wv_transaction( 'BL-OTHER-AMOUNT', array( 'status' => 'Captured', 'amount' => 25 ) );
	$result = $handler->blink_process_webhook( wv_direct_request( 'BL-OTHER-AMOUNT', 504, 'Captured', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( 403, $result['code'], 'A capture for another amount must be forbidden.' );
	wv_assert( array(), $handler->rerun_calls, 'The capture handler must not run.' );
};

$tests['capture webhook while a capture is in progress is rejected when another order recorded it'] = function () {
	global $wv_gateway;
	$handler = wv_handler();
	$order   = wv_order( 506, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	$other = wv_order( 996, array( 'status' => 'processing', 'transaction_id' => 'BL-AUTH-996' ) );
	$other->update_meta_data( 'blink_rerun_id', 'BL-CAPTURE-996' );
	$wv_gateway->rerun_handler->in_progress = true;
	wv_transaction( 'BL-CAPTURE-996', array( 'status' => 'Captured', 'amount' => 18 ) );
	$result = $handler->blink_process_webhook( wv_direct_request( 'BL-CAPTURE-996', 506, 'Captured', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( 403, $result['code'], 'Another order\'s capture must be forbidden.' );
	wv_assert( array(), $handler->rerun_calls, 'The capture handler must not run.' );
};

$tests['capture webhook while a capture is in progress is rejected when it names another order'] = function () {
	global $wv_gateway;
	$handler = wv_handler();
	$order   = wv_order( 505, array( 'status' => 'on-hold' ) );
	$order->update_meta_data( '_blink_preauth', 'yes' );
	$order->update_meta_data( 'blink_res', 'BL-AUTH' );
	$wv_gateway->rerun_handler->in_progress = true;
	wv_transaction( 'BL-OTHER-ORDER', array( 'status' => 'Captured', 'amount' => 18, 'merchant_data' => '{"order_info":{"order_id":999}}' ) );
	$result = $handler->blink_process_webhook( wv_direct_request( 'BL-OTHER-ORDER', 505, 'Captured', array( 'event_type' => 'rerun_transaction' ) ) );
	wv_assert( 403, $result['code'], 'Another order\'s transaction must be forbidden.' );
	wv_assert( array(), $handler->rerun_calls, 'The capture handler must not run.' );
};

// --- Paylink creation ----------------------------------------------------

$tests['creating a paylink stores its ID on the order'] = function () {
	global $wv_gateway;
	$order = wv_order( 601 );
	$wv_gateway->blink_get_return_url = null;
	$gateway = new class( $wv_gateway ) {
		private $base;
		public $transaction_handler;
		public function __construct( $base ) {
			$this->base                = $base;
			$this->transaction_handler = new Blink_Transaction_Handler( $this );
		}
		public function __get( $name ) { return $this->base->$name; }
		public function blink_get_return_url( $order ) { return wv_redirect( $order->get_id(), $order->get_order_key() ); }
	};
	$handler   = new Blink_Payment_Handler( $gateway );
	$set_token = function () { $this->token = array( 'access_token' => 'test-token' ); };
	$set_token->call( $handler );
	$handler->blink_handle_hosted_payment( $order, array() );
	$handler->blink_handle_hosted_payment( $order, array() );
	wv_assert( array( '1709999', '1709999' ), array_map( function ( $meta ) { return $meta->value; }, $order->get_meta( '_blink_paylink_id', false ) ), 'Each paylink ID is kept on the order.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	wv_reset();
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
