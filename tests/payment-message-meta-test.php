<?php

define( 'ABSPATH', __DIR__ . '/' );

$test_orders  = array();
$test_options = array();
$test_api_data = array();

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wc_clean( $value ) {
	return sanitize_text_field( $value );
}

function wc_get_order( $order_id ) {
	global $test_orders;
	return isset( $test_orders[ $order_id ] ) ? $test_orders[ $order_id ] : null;
}

function add_option( $key, $value ) {
	global $test_options;
	if ( isset( $test_options[ $key ] ) ) {
		return false;
	}
	$test_options[ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	global $test_options;
	unset( $test_options[ $key ] );
}

function delete_transient( $key ) {
	return true;
}

function wp_remote_get( $url, $args ) {
	global $test_api_data;
	return array(
		'body'     => json_encode( array( 'data' => $test_api_data ) ),
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
	);
}

function is_wp_error( $value ) {
	return false;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function WC() {
	static $woocommerce;
	if ( ! $woocommerce ) {
		$woocommerce = (object) array(
			'cart'             => null,
			'payment_gateways' => new Blink_Payment_Message_Test_Gateway_Registry(),
		);
	}
	return $woocommerce;
}

class Blink_Logger {
	public static function log( $message, $context = null ) {
	}

	public static function http_response_context( $response ) {
		return array();
	}
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-transaction-handler.php';

class Blink_Payment_Message_Test_Gateway {
	public $host_url = 'https://gateway.example.com';
	public $utils;
	private $hosted;

	public function __construct( $hosted = false ) {
		$this->utils = new Blink_Payment_Message_Test_Utils();
		$this->hosted = $hosted;
	}

	public function blink_is_hosted() {
		return $this->hosted;
	}
}

class Blink_Payment_Message_Test_Utils {
	public function blink_generate_access_token() {
		return array( 'access_token' => 'test-token' );
	}

	public function blink_destroy_session_tokens( $intent_id ) {
	}
}

class Blink_Payment_Message_Test_Gateway_Registry {
	private $gateways = array();

	public function set_gateway( $gateway ) {
		$this->gateways['blink'] = $gateway;
	}

	public function get_available_payment_gateways() {
		return $this->gateways;
	}
}

class Blink_Payment_Message_Test_Order {
	private $id;
	private $status;
	private $meta;
	private $transaction_id = '';

	public function __construct( $id, $status, $message ) {
		$this->id     = $id;
		$this->status = $status;
		$this->meta   = array(
			'_blink_payment_message' => $message,
			'_blink_preauth'         => 'no',
			'blink_res'              => 'txn-' . $id,
		);
	}

	public function get_id() {
		return $this->id;
	}

	public function get_billing_email() {
		return 'customer@example.com';
	}

	public function get_payment_method() {
		return 'blink';
	}

	public function get_transaction_id() {
		return $this->transaction_id;
	}

	public function get_order_key() {
		return 'wc_order_test_key_' . $this->id;
	}

	public function get_total() {
		return '18.00';
	}

	public function needs_payment() {
		return in_array( $this->status, array( 'pending', 'failed' ), true );
	}

	public function has_status( $status ) {
		return is_array( $status ) ? in_array( $this->status, $status, true ) : $this->status === $status;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function set_transaction_id( $transaction_id ) {
		$this->transaction_id = $transaction_id;
	}

	public function add_order_note( $note ) {
	}

	public function update_status( $status, $note = '' ) {
		$this->status = $status;
	}

	public function payment_complete( $transaction_id = '' ) {
		$this->transaction_id = $transaction_id;
		$this->status         = 'processing';
	}

	public function save() {
	}
}

function blink_payment_message_test_order( $id, $status, $message ) {
	global $test_orders;
	$order              = new Blink_Payment_Message_Test_Order( $id, $status, $message );
	$test_orders[ $id ] = $order;
	return $order;
}

// Blink's transaction API returns the order's own transaction with this status and message.
function blink_payment_message_api_data( $order, $status, $message ) {
	global $test_api_data;
	$test_api_data = array(
		'transaction_id' => $order->get_meta( 'blink_res', true ),
		'status'         => $status,
		'amount'         => 18,
		'payment_source' => 'credit card',
		'message'        => $message,
		'merchant_data'  => array( 'order_info' => array( 'order_id' => $order->get_id() ) ),
	);
}

function blink_payment_message_process( $order, $status, $message ) {
	blink_payment_message_api_data( $order, $status, $message );
	$handler = new Blink_Transaction_Handler( new Blink_Payment_Message_Test_Gateway() );
	$handler->blink_check_response_for_order( $order->get_id() );
}

function blink_payment_message_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$tests = array(
	'failed revisit preserves an existing message when the API message is empty' => function () {
		$order = blink_payment_message_test_order( 1, 'failed', 'Original decline' );
		blink_payment_message_process( $order, 'failed', '' );
		blink_payment_message_assert_same( 'Original decline', $order->get_meta( '_blink_payment_message', true ), 'Preserved decline message' );
	},
	'failed revisit replaces an existing message with a new API message' => function () {
		$order = blink_payment_message_test_order( 2, 'failed', 'Original decline' );
		blink_payment_message_process( $order, 'failed', '<strong>New decline</strong>' );
		blink_payment_message_assert_same( 'New decline', $order->get_meta( '_blink_payment_message', true ), 'Updated decline message' );
	},
	'URL note cannot replace the server-validated customer message' => function () {
		$_GET['note'] = 'URL-controlled decline';
		$order         = blink_payment_message_test_order( 3, 'failed', 'Original decline' );
		blink_payment_message_process( $order, 'failed', '' );
		blink_payment_message_assert_same( 'Original decline', $order->get_meta( '_blink_payment_message', true ), 'Trusted decline message' );
		unset( $_GET['note'] );
	},
	'order transitioning out of failed removes the stale message' => function () {
		$order = blink_payment_message_test_order( 4, 'failed', 'Old decline' );
		blink_payment_message_process( $order, 'captured', '' );
		blink_payment_message_assert_same( 'processing', $order->get_status(), 'Successful order status' );
		blink_payment_message_assert_same( '', $order->get_meta( '_blink_payment_message', true ), 'Removed stale decline message' );
	},
	'already successful order cleans stale message without transaction validation' => function () {
		$order   = blink_payment_message_test_order( 5, 'processing', 'Old decline' );
		$handler = new Blink_Transaction_Handler( new Blink_Payment_Message_Test_Gateway() );
		$handler->blink_check_response_for_order( $order->get_id() );
		blink_payment_message_assert_same( '', $order->get_meta( '_blink_payment_message', true ), 'Removed stale successful-order message' );
	},
	'Direct return transaction ID is captured and validated on wp' => function () {
		global $test_api_data, $wp;
		$order         = blink_payment_message_test_order( 6, 'pending', '' );
		$gateway       = new Blink_Payment_Message_Test_Gateway();
		$test_api_data = array(
			'transaction_id' => 'return-transaction',
			'status'         => 'failed',
			'amount'         => 18,
			'payment_source' => 'credit card',
			'message'        => 'First-load decline',
			'merchant_data'  => array( 'order_info' => array( 'order_id' => $order->get_id() ) ),
		);
		$wp = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
		WC()->payment_gateways->set_gateway( $gateway );
		$_GET     = array( 'key' => $order->get_order_key(), 'transaction_id' => 'return-transaction' );
		$_REQUEST = $_GET;

		Blink_Transaction_Handler::blink_capture_order_response();

		blink_payment_message_assert_same( 'return-transaction', $order->get_meta( 'blink_res', true ), 'Captured return transaction ID' );
		blink_payment_message_assert_same( 'failed', $order->get_status(), 'Validated first-load status' );
		blink_payment_message_assert_same( 'First-load decline', $order->get_meta( '_blink_payment_message', true ), 'Persisted first-load decline' );
		$_GET     = array();
		$_REQUEST = array();
	},
	'authenticated Hosted decline keeps the order Pending and is shown, not stored, during wp' => function () {
		global $wp;
		$order   = blink_payment_message_test_order( 7, 'pending', '' );
		$order->delete_meta_data( 'blink_res' );
		$gateway = new Blink_Payment_Message_Test_Gateway( true );
		$wp      = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
		WC()->payment_gateways->set_gateway( $gateway );
		$_GET = array(
			'key'       => $order->get_order_key(),
			'status'    => 'Declined',
			'reference' => 'WC-' . $order->get_id(),
		);
		$_REQUEST = $_GET;

		Blink_Transaction_Handler::blink_capture_order_response();

		// Blink keeps a declined Paylink payable, so the order stays Pending (AB#17324).
		blink_payment_message_assert_same( 'pending', $order->get_status(), 'Hosted first-load status' );
		blink_payment_message_assert_same( '', $order->get_meta( 'blink_res', true ), 'Hosted transaction ID remains empty' );
		blink_payment_message_assert_same( '', $order->get_meta( '_blink_payment_message', true ), 'Hosted decline message is not stored' );
		blink_payment_message_assert_same( 'declined', Blink_Transaction_Handler::$hosted_return_notice['type'], 'Hosted generic decline is shown' );
		Blink_Transaction_Handler::$hosted_return_notice = null;
		$_GET     = array();
		$_REQUEST = array();
	},
	'Hosted decline with a mismatched order key is ignored during wp' => function () {
		global $wp;
		$order   = blink_payment_message_test_order( 8, 'pending', '' );
		$order->delete_meta_data( 'blink_res' );
		$gateway = new Blink_Payment_Message_Test_Gateway( true );
		$wp      = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
		WC()->payment_gateways->set_gateway( $gateway );
		$_GET = array(
			'key'       => 'wrong-order-key',
			'status'    => 'Declined',
			'reference' => 'WC-' . $order->get_id(),
		);
		$_REQUEST = $_GET;

		Blink_Transaction_Handler::blink_capture_order_response();

		blink_payment_message_assert_same( 'pending', $order->get_status(), 'Unauthenticated Hosted status' );
		blink_payment_message_assert_same( '', $order->get_meta( '_blink_payment_message', true ), 'Unauthenticated Hosted message' );
		$_GET     = array();
		$_REQUEST = array();
	},
);

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$name} - {$error->getMessage()}\n" );
	}
}

echo count( $tests ) . ' tests, ' . $failures . " failures\n";
exit( 0 === $failures ? 0 : 1 );
