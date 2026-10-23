<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: merchant_data tests did not complete (unexpected webhook exit or assertion failure).\n" );
		exit( 1 );
	}
} );

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wc_get_order( $id ) {
	global $test_order;
	return (string) $test_order->get_id() === (string) $id ? $test_order : null;
}

function wc_get_orders( $args ) {
	throw new RuntimeException( 'Order identification must not need the transaction search fallback.' );
}

function wp_remote_get( $url, $args ) {
	merchant_data_assert( 'https://gateway.example.com/pay/v1/transactions/TXN-12345', $url, 'Webhook must validate the transaction.' );
	return array( 'body' => '{"data":{"transaction_id":"TXN-12345"}}', 'response' => array( 'code' => 200 ) );
}

function is_wp_error( $response ) {
	return false;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

// Stop at status dispatch so the real webhook parser can be exercised repeatedly
// without its terminal exit(). Status mapping has its own helper-status tests.
class Merchant_Data_Status_Dispatch extends RuntimeException {}

function blink_is_preauth_transaction( $order ) {
	return false;
}

function blink_get_status( $status, $unused = '', $order = null ) {
	return $status;
}

function blink_change_status( $order, $transaction_id, $status, $unused = '', $note = '' ) {
	global $test_order, $test_status;
	merchant_data_assert( $test_order, $order, 'Webhook must dispatch to the identified order.' );
	merchant_data_assert( 'TXN-12345', $transaction_id, 'Transaction ID must be preserved.' );
	merchant_data_assert( $test_status, $status, 'Payment outcome must be preserved.' );
	throw new Merchant_Data_Status_Dispatch();
}

class Blink_Logger {
	public static function log( $message, $context = null ) {}
}

class Merchant_Data_Order {
	public $meta = array();
	public $transaction_id = '';

	public function get_id() { return 12345; }
	public function get_payment_method() { return 'blink'; }
	public function get_transaction_id() { return $this->transaction_id; }
	public function set_transaction_id( $id ) { $this->transaction_id = $id; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }

	public function __call( $method, $args ) {
		throw new RuntimeException( 'merchant_data must not read customer or other order fields: ' . $method );
	}
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-transaction-handler.php';

function merchant_data_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Actual: ' . var_export( $actual, true ) );
	}
}

$test_order = new Merchant_Data_Order();
$minimal = blink_get_payment_information( '12345' );
merchant_data_assert( '{"order_info":{"order_id":12345}}', $minimal, 'Exact JSON must contain only the numeric order ID.' );
echo "PASS: exact JSON structure without reading customer fields\n";

$historical = array(
	'payer_info' => array(
		'customer_id' => 42,
		'customer_name' => "Test O'Customer",
		'customer_email' => 'customer@example.com',
		'billing_phone' => '01234567890',
		'billing_address_1' => '1 Test Street',
	),
	'order_info' => array(
		'order_id' => 12345,
		'order_number' => 'CUSTOM-12345',
		'order_date' => '2025-01-01 12:00:00',
		'order_total' => '16.00',
		'order_currency' => 'GBP',
	),
);
foreach ( $historical['payer_info'] as $value ) {
	merchant_data_assert( false, strpos( $minimal, (string) $value ), 'Customer PII must be absent.' );
}
echo "PASS: customer identifiers, name, email, phone and address absent\n";

$gateway = (object) array(
	'host_url' => 'https://gateway.example.com',
	'secret_key' => 'test-secret',
	'utils' => new class {
		public function blink_generate_access_token() { return array( 'access_token' => 'test-token' ); }
	},
);
$handler = new Blink_Transaction_Handler( $gateway );
$_SERVER['REQUEST_METHOD'] = 'POST';

foreach ( array( 'minimal' => $minimal, 'historical' => wp_json_encode( $historical ), 'hosted reference' => '' ) as $name => $payload ) {
	foreach ( array( 'Captured', 'Declined', 'Cancelled' ) as $test_status ) {
		$test_order = new Merchant_Data_Order();
		$_REQUEST = array(
			'transaction_id' => 'TXN-12345',
			'status' => $test_status,
			'merchant_data' => addslashes( $payload ),
		);
		if ( 'hosted reference' === $name ) {
			$_REQUEST['reference'] = 'WC-12345';
		}
		try {
			$handler->blink_webhook();
			throw new RuntimeException( 'Webhook did not dispatch the status.' );
		} catch ( Merchant_Data_Status_Dispatch $dispatched ) {
			merchant_data_assert( 'TXN-12345', $test_order->get_transaction_id(), 'Webhook must store the transaction on the right order.' );
			merchant_data_assert( $test_status, $test_order->meta['status'], 'Webhook must store the payment outcome.' );
			merchant_data_assert( $payload ? json_decode( $payload, true ) : null, $test_order->meta['_debug']['merchant_data'], 'Historical payload parsing must remain unchanged.' );
			echo "PASS: {$name} webhook identifies order for {$test_status}\n";
		}
	}
}
$tests_completed = true;
echo "11 tests, 0 failures\n";
