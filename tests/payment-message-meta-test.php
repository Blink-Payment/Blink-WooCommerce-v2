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

function sanitize_email( $email ) {
	return (string) $email;
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

function wc_get_checkout_url() {
	return 'https://example.com/checkout';
}

function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}

function wp_remote_get( $url, $args ) {
	global $test_api_data;
	return array(
		'body'     => json_encode( array( 'data' => $test_api_data ) ),
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
	);
}

function wp_remote_retrieve_headers( $response ) {
	return $response['headers'];
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
	public static function set_context( $context ) {
	}

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
	public function blink_set_tokens( $intent_id ) {
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

class Blink_Payment_Message_Test_Handler extends Blink_Transaction_Handler {
	private $transaction_result;

	public function __construct( $gateway, $transaction_result ) {
		parent::__construct( $gateway );
		$this->transaction_result = $transaction_result;
	}

	public function blink_validate_transaction( $order, $transaction ) {
		return $this->transaction_result;
	}
}

function blink_payment_message_test_order( $id, $status, $message ) {
	global $test_orders;
	$order              = new Blink_Payment_Message_Test_Order( $id, $status, $message );
	$test_orders[ $id ] = $order;
	return $order;
}

function blink_payment_message_process( $order, $status, $message ) {
	$result = array(
		'transaction_id' => 'validated-' . $order->get_id(),
		'status'         => $status,
		'payment_source' => 'credit card',
		'message'        => $message,
	);
	$handler = new Blink_Payment_Message_Test_Handler( new Blink_Payment_Message_Test_Gateway(), $result );
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
		$order  = blink_payment_message_test_order( 5, 'processing', 'Old decline' );
		$result = array(
			'transaction_id' => 'unused',
			'status'         => 'captured',
			'payment_source' => 'credit card',
			'message'        => 'unused',
		);
		$handler = new Blink_Payment_Message_Test_Handler( new Blink_Payment_Message_Test_Gateway(), $result );
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
			'payment_source' => 'credit card',
			'message'        => 'First-load decline',
		);
		$wp = (object) array( 'query_vars' => array( 'order-received' => $order->get_id() ) );
		WC()->payment_gateways->set_gateway( $gateway );
		$_REQUEST['transaction_id'] = 'return-transaction';

		Blink_Transaction_Handler::blink_capture_order_response();

		blink_payment_message_assert_same( 'return-transaction', $order->get_meta( 'blink_res', true ), 'Captured return transaction ID' );
		blink_payment_message_assert_same( 'failed', $order->get_status(), 'Validated first-load status' );
		blink_payment_message_assert_same( 'First-load decline', $order->get_meta( '_blink_payment_message', true ), 'Persisted first-load decline' );
		unset( $_REQUEST['transaction_id'] );
	},
	'authenticated Hosted decline is persisted during wp without a transaction ID' => function () {
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

		blink_payment_message_assert_same( 'failed', $order->get_status(), 'Hosted first-load status' );
		blink_payment_message_assert_same( '', $order->get_meta( 'blink_res', true ), 'Hosted transaction ID remains empty' );
		blink_payment_message_assert_same( 'Payment was declined. Please try again.', $order->get_meta( '_blink_payment_message', true ), 'Hosted generic decline' );
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
