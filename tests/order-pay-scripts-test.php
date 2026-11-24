<?php
/**
 * Pay-for-order scripts must not crash when the order in the URL doesn't exist.
 */

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function(
	function () use ( &$tests_completed ) {
		if ( ! $tests_completed ) {
			fwrite( STDERR, "FAIL: order-pay script tests did not complete (unexpected exit or fatal error).\n" );
			exit( 1 );
		}
	}
);

$ops_order     = false;
$ops_enqueued  = array();
$ops_localized = array();

class WC_Payment_Gateway {
	public function get_option( $key ) {
		return '';
	}
}

class Ops_Order {
	public function get_id() { return 42; }
	public function get_order_key() { return 'wc_order_right'; }
	public function __call( $name, $args ) {
		return 'value';
	}
}

function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugins_url( $path, $file ) { return 'http://example.test/' . ltrim( $path, './' ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function do_action( $hook ) {}
function do_action_deprecated( $hook, $args, $version, $replacement = '' ) {}
function is_wc_endpoint_url( $endpoint ) { return 'order-pay' === $endpoint; }
function is_cart() { return false; }
function is_checkout() { return true; }
function is_ssl() { return true; }
function get_query_var( $name ) { return 'order-pay' === $name ? '999999' : ''; }
function absint( $value ) { return abs( (int) $value ); }
function get_queried_object_id() { return 0; }
function wc_get_order( $id ) { global $ops_order; return $ops_order; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function current_user_can( $capability, $id = null ) { global $ops_can_pay; return 'pay_for_order' === $capability && $ops_can_pay; }
function admin_url( $path ) { return 'http://example.test/wp-admin/' . $path; }
function wp_create_nonce( $action ) { return 'nonce'; }
function wp_add_inline_script( $handle, $code ) {}
function wp_register_style( $handle, $src, $deps = array(), $ver = false ) {}
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $footer = false ) {}
function wp_enqueue_style( $handle ) {}
function wp_add_inline_style( $handle, $css ) {}
function wp_enqueue_script( $handle ) { global $ops_enqueued; $ops_enqueued[] = $handle; }
function wp_localize_script( $handle, $name, $data ) { global $ops_localized; $ops_localized[ $handle ] = $data; }

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-payment-gateway.php';

function ops_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ops_run( $order, $key = 'wc_order_right', $can_pay = true ) {
	global $ops_order, $ops_enqueued, $ops_localized, $ops_can_pay;
	$ops_can_pay = $can_pay;
	if ( null === $key ) {
		unset( $_GET['key'] );
	} else {
		$_GET['key'] = $key;
	}
	$ops_order     = $order;
	$ops_enqueued  = array();
	$ops_localized = array();

	$reflection = new ReflectionClass( 'Blink_PG_Payment_Gateway' );
	$gateway    = $reflection->newInstanceWithoutConstructor();
	foreach ( array( 'enabled' => 'yes', 'api_key' => 'key', 'secret_key' => 'secret', 'testmode' => true, 'version' => '1.0' ) as $property => $value ) {
		$gateway->$property = $value;
	}
	$gateway->blink_payment_scripts();
}

$tests = array(
	'a missing order loads no Blink scripts instead of crashing' => function () {
		global $ops_enqueued;
		ops_run( false );
		ops_assert( array() === $ops_enqueued, 'Nothing is enqueued for a missing order' );
	},
	'a wrong order key prints no billing details' => function () {
		global $ops_enqueued, $ops_localized;
		ops_run( new Ops_Order(), 'wc_order_wrong' );
		ops_assert( array() === $ops_enqueued && array() === $ops_localized, 'Nothing is enqueued or localized' );
	},
	'a missing order key prints no billing details' => function () {
		global $ops_enqueued, $ops_localized;
		ops_run( new Ops_Order(), null );
		ops_assert( array() === $ops_enqueued && array() === $ops_localized, 'Nothing is enqueued or localized' );
	},
	'a visitor who may not pay the order gets no billing details' => function () {
		global $ops_enqueued, $ops_localized;
		ops_run( new Ops_Order(), 'wc_order_right', false );
		ops_assert( array() === $ops_enqueued && array() === $ops_localized, 'Nothing is enqueued or localized' );
	},
	'an existing order still gets the pay-for-order script and its data' => function () {
		global $ops_enqueued, $ops_localized;
		ops_run( new Ops_Order() );
		ops_assert( in_array( 'woocommerce_blink_payment_order_pay', $ops_enqueued, true ), 'Order-pay script is enqueued' );
		ops_assert( 42 === $ops_localized['woocommerce_blink_payment_order_pay']['order_id'], 'Order ID is passed to the script' );
	},
);

$failed = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $e ) {
		++$failed;
		echo "FAIL: {$name}: {$e->getMessage()}\n";
	}
}
$tests_completed = true;
exit( $failed ? 1 : 0 );
