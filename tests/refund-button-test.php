<?php
/**
 * The order screen's Refund and Cancel Order buttons must only change for Blink
 * orders. Versions up to 1.3.7 wrote other gateways' transaction IDs to blink_res,
 * and that alone used to hide WooCommerce's Refund button on those orders.
 */

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function(
	function () use ( &$tests_completed ) {
		if ( ! $tests_completed ) {
			fwrite( STDERR, "FAIL: refund button tests did not complete (unexpected exit or fatal error).\n" );
			exit( 1 );
		}
	}
);

// Any PHP warning or notice (e.g. "Undefined array key") fails the test.
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message . ' in ' . basename( $file ) . ':' . $line, 0, $severity );
	}
);

class WC_DateTime extends DateTime {
	public function date( $format ) { return $this->format( $format ); }
}

class WC_Order {
	public $payment_method;
	public $meta;
	public $status;
	public $created;
	public function __construct( $payment_method, $meta = array(), $status = 'processing', $hours_old = 1 ) {
		$this->payment_method = $payment_method;
		$this->meta           = $meta;
		$this->status         = $status;
		$this->created        = new WC_DateTime( '-' . $hours_old . ' hours' );
	}
	public function get_id() { return 264; }
	public function get_payment_method() { return $this->payment_method; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function get_date_created() { return $this->created; }
}

function __( $text, $domain = null ) { return $text; }
function esc_attr( $text ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function wc_get_order( $id ) { return $GLOBALS['rb_order']; }

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-refund-handler.php';

/**
 * Transaction handler whose status answer is scripted, like Blink's lookup.
 */
class Rb_Handler {
	public $gateway;
	public $source;
	public $status;
	public $checked = false;
	public function blink_get_transaction_status( $transaction_id, $order = null ) {
		$this->checked                 = true;
		$this->gateway->payment_source = $this->source;
		$this->gateway->payment_status = $this->status;
		return $this->status;
	}
}

function rb_handler( $order, $status = '', $source = '' ) {
	$GLOBALS['rb_order'] = $order;
	$gateway             = (object) array(
		'payment_source' => '',
		'payment_status' => '',
	);
	$lookup              = new Rb_Handler();
	$lookup->gateway     = $gateway;
	$lookup->status      = $status;
	$lookup->source      = $source;
	$gateway->transaction_handler = $lookup;
	return array( new Blink_PG_Refund_Handler( $gateway ), $lookup );
}

function rb_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function rb_other_gateway_order() {
	// What 1.3.7 left behind: the other gateway's own transaction ID in blink_res.
	return new WC_Order( 'klarna_payments', array( 'blink_res' => 'f851b80d-e711-4655-8502-74e7583a6d85' ) );
}

$tests = array(
	'other gateway order tagged with blink_res keeps its Refund button' => function () {
		$order                     = rb_other_gateway_order();
		list( $handler, $lookup ) = rb_handler( $order );
		rb_assert( true === $handler->blink_should_render_refunds( true, 264, $order ), 'Refund button shown' );
		rb_assert( false === $lookup->checked, 'Blink is not asked about another gateway\'s transaction' );
	},
	'other gateway order keeps whatever WooCommerce decided' => function () {
		$order                     = rb_other_gateway_order();
		list( $handler, $lookup ) = rb_handler( $order );
		rb_assert( false === $handler->blink_should_render_refunds( false, 264, $order ), 'Hidden stays hidden' );
	},
	'other gateway order loaded by ID keeps its Refund button' => function () {
		list( $handler, $lookup ) = rb_handler( rb_other_gateway_order() );
		rb_assert( true === $handler->blink_should_render_refunds( true, 264, null ), 'Refund button shown' );
		rb_assert( false === $lookup->checked, 'Blink is not asked' );
	},
	'other gateway order gets no Cancel Order button, even with a status left from another lookup' => function () {
		$order                     = rb_other_gateway_order();
		list( $handler, $lookup ) = rb_handler( $order );
		// The button reads the status the last lookup left on the gateway.
		$lookup->gateway->payment_source = 'Card';
		$lookup->gateway->payment_status = 'Captured';
		ob_start();
		$handler->blink_add_cancel_button( $order );
		rb_assert( '' === ob_get_clean(), 'Nothing output' );
	},
	'Blink order keeps its Refund button when Blink cannot be reached' => function () {
		$order                     = new WC_Order( 'blink', array( 'blink_res' => 'BL-TEST' ) );
		list( $handler, $lookup ) = rb_handler( $order, '' );
		rb_assert( true === $handler->blink_should_render_refunds( true, 264, $order ), 'Refund button shown' );
		rb_assert( true === $lookup->checked, 'Blink was asked' );
	},
	'captured Blink card payment under 24 hours old still hides Refund' => function () {
		$order                     = new WC_Order( 'blink', array( 'blink_res' => 'BL-TEST' ), 'processing', 1 );
		list( $handler, $lookup ) = rb_handler( $order, 'Captured', 'Card' );
		rb_assert( false === $handler->blink_should_render_refunds( true, 264, $order ), 'Refund button hidden' );
	},
	'captured Blink card payment over 24 hours old still shows Refund' => function () {
		$order                     = new WC_Order( 'blink', array( 'blink_res' => 'BL-TEST' ), 'processing', 30 );
		list( $handler, $lookup ) = rb_handler( $order, 'Captured', 'Card' );
		rb_assert( true === $handler->blink_should_render_refunds( true, 264, $order ), 'Refund button shown' );
	},
	'cancelled Blink order still hides Refund' => function () {
		$order                     = new WC_Order( 'blink', array( 'blink_res' => 'BL-TEST' ), 'cancelled', 30 );
		list( $handler, $lookup ) = rb_handler( $order, 'Voided', 'Card' );
		rb_assert( false === $handler->blink_should_render_refunds( true, 264, $order ), 'Refund button hidden' );
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
