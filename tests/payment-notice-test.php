<?php

define( 'ABSPATH', __DIR__ . '/' );

$test_orders  = array();
$test_notices = array();

class WC_Payment_Gateway {
}

function wc_get_order( $order_id ) {
	global $test_orders;
	return isset( $test_orders[ $order_id ] ) ? $test_orders[ $order_id ] : null;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wc_print_notice( $message, $type ) {
	global $test_notices;
	$test_notices[] = array( $message, $type );
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-payment-gateway.php';

class Blink_Notice_Test_Order {
	private $payment_method;
	private $status;
	private $message;

	public function __construct( $payment_method, $status, $message ) {
		$this->payment_method = $payment_method;
		$this->status         = $status;
		$this->message        = $message;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function has_status( $status ) {
		return $this->status === $status;
	}

	public function get_meta( $key, $single = true ) {
		return '_blink_payment_message' === $key ? $this->message : '';
	}
}

function blink_notice_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function blink_notice_gateway( $integration_type = 'direct' ) {
	$reflection = new ReflectionClass( 'Blink_Payment_Gateway' );
	$gateway    = $reflection->newInstanceWithoutConstructor();
	$gateway->integration_type = $integration_type;
	return $gateway;
}

$tests = array(
	'failed Direct order prints stored message' => function () {
		global $test_orders, $test_notices;
		$test_notices   = array();
		$test_orders[1] = new Blink_Notice_Test_Order( 'blink', 'failed', 'Missing or invalid data' );
		blink_notice_gateway()->blink_print_custom_notice( 1 );
		blink_notice_assert_same( array( array( 'Missing or invalid data', 'error' ) ), $test_notices, 'Direct failure notice' );
	},
	'empty message prints no custom notice' => function () {
		global $test_orders, $test_notices;
		$test_notices   = array();
		$test_orders[2] = new Blink_Notice_Test_Order( 'blink', 'failed', '' );
		blink_notice_gateway()->blink_print_custom_notice( 2 );
		blink_notice_assert_same( array(), $test_notices, 'Empty failure notice' );
	},
	'successful order prints no decline notice' => function () {
		global $test_orders, $test_notices;
		$test_notices   = array();
		$test_orders[3] = new Blink_Notice_Test_Order( 'blink', 'processing', 'Old decline' );
		blink_notice_gateway()->blink_print_custom_notice( 3 );
		blink_notice_assert_same( array(), $test_notices, 'Success notice' );
	},
	'Hosted failure prints a safely persisted message' => function () {
		global $test_orders, $test_notices;
		$test_notices   = array();
		$test_orders[4] = new Blink_Notice_Test_Order( 'blink', 'failed', 'Hosted decline' );
		blink_notice_gateway( 'hosted' )->blink_print_custom_notice( 4 );
		blink_notice_assert_same( array( array( 'Hosted decline', 'error' ) ), $test_notices, 'Hosted failure notice' );
	},
	'separate failed orders cannot suppress each other' => function () {
		global $test_orders, $test_notices;
		$test_notices   = array();
		$test_orders[5] = new Blink_Notice_Test_Order( 'blink', 'failed', 'First decline' );
		$test_orders[6] = new Blink_Notice_Test_Order( 'blink', 'failed', 'Second decline' );
		$gateway        = blink_notice_gateway();
		$gateway->blink_print_custom_notice( 5 );
		$gateway->blink_print_custom_notice( 6 );
		blink_notice_assert_same( array( array( 'First decline', 'error' ), array( 'Second decline', 'error' ) ), $test_notices, 'Separate order notices' );
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
