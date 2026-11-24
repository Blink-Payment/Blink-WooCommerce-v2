<?php
/**
 * Cancel and refund must cope with Blink responses that are missing fields,
 * aren't JSON, or never arrive, and a cancel that gets no answer must check
 * the transaction before deciding.
 */

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function(
	function () use ( &$tests_completed ) {
		if ( ! $tests_completed ) {
			fwrite( STDERR, "FAIL: cancel/refund response tests did not complete (unexpected exit or fatal error).\n" );
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

$crr_response = null;
$crr_notices  = array();

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

class Blink_PG_Logger {
	public static function log( $message, $context = array() ) {}
	public static function set_context( $context ) {}
	public static function http_response_context( $response ) {
		return array();
	}
}

class Crr_Order {
	public $notes  = array();
	public $status = 'completed';
	public function get_id() { return 228; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status ) { $this->status = $status; }
	public function get_total_refunded() { return 0; }
	public function get_total() { return 10.0; }
}

function __( $text, $domain = null ) { return $text; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wc_add_notice( $message, $type = 'success' ) { global $crr_notices; $crr_notices[] = $message; }
function wc_price( $amount ) { return '£' . number_format( (float) $amount, 2 ); }
function wc_get_order( $id ) { return $GLOBALS['crr_order']; }
function wp_remote_post( $url, $args ) { global $crr_response; return $crr_response; }
function wp_remote_get( $url, $args ) { global $crr_response; return $crr_response; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? $response['body'] : ''; }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? $response['code'] : ''; }
function blink_pg_get_charge_transaction_id( $order ) { return 'BL-TEST'; }

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-payment-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-transaction-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-refund-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-pg-ajax-handler.php';

class Crr_Utils {
	public function blink_generate_access_token() {
		return array( 'access_token' => 'token' );
	}
}

function crr_gateway() {
	return (object) array(
		'host_url'       => 'https://blink.test/api',
		'utils'          => new Crr_Utils(),
		'payment_source' => '',
		'payment_status' => '',
	);
}

/**
 * Transaction handler whose cancel and status answers are scripted.
 */
class Crr_Handler {
	public $cancel;
	public $status;
	public $status_checked = false;
	public function __construct( $cancel, $status = '' ) {
		$this->cancel = $cancel;
		$this->status = $status;
	}
	public function blink_cancel_transaction( $transaction_id ) {
		return $this->cancel;
	}
	public function blink_get_transaction_status( $transaction_id, $order = null ) {
		$this->status_checked = true;
		return $this->status;
	}
}

function crr_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function crr_json( $data, $code = 200 ) {
	return array(
		'body' => json_encode( $data ),
		'code' => $code,
	);
}

function crr_cancel( $response ) {
	$GLOBALS['crr_response'] = $response;
	$handler                 = new Blink_PG_Transaction_Handler( crr_gateway() );
	return $handler->blink_cancel_transaction( 'BL-TEST' );
}

function crr_refund( $response ) {
	$GLOBALS['crr_response'] = $response;
	$GLOBALS['crr_order']    = new Crr_Order();
	$handler                 = new Blink_PG_Refund_Handler( crr_gateway() );
	return array( $handler->blink_handle_refund( 228, 1.0, 'test' ), $GLOBALS['crr_order'] );
}

$tests = array(
	// Item 4: a request that fails returns an array the caller can read, and no shopper notice.
	'cancel with no response returns an unknown result and adds no notice' => function () {
		global $crr_notices;
		$crr_notices = array();
		$result      = crr_cancel( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		crr_assert( false === $result['success'], 'Not a success' );
		crr_assert( true === $result['unknown'], 'Outcome is unknown' );
		crr_assert( false !== strpos( $result['message'], 'cURL error 28' ), 'Message carries the HTTP error' );
		crr_assert( false === strpos( $result['message'], 'transaction status' ), 'Message is about the cancel, not the status lookup' );
		crr_assert( array() === $crr_notices, 'No shopper notice' );
	},
	// Item 3: non-JSON and message-less responses.
	'cancel with a non-JSON page is unknown' => function () {
		$result = crr_cancel( array( 'body' => '<html>502 Bad Gateway</html>', 'code' => 502 ) );
		crr_assert( true === $result['unknown'], 'Outcome is unknown' );
		crr_assert( false !== strpos( $result['message'], '502' ), 'Message gives the HTTP code' );
	},
	'cancel refused without a message gets a fallback message' => function () {
		$result = crr_cancel( crr_json( array( 'success' => false ) ) );
		crr_assert( false === $result['success'] && false === $result['unknown'], 'A definite failure' );
		crr_assert( '' !== $result['message'], 'Has a message' );
	},
	'cancel accepted is a success' => function () {
		$result = crr_cancel( crr_json( array( 'success' => true, 'message' => 'Cancelled' ) ) );
		crr_assert( true === $result['success'], 'Success' );
	},
	// Item 7: an unanswered cancel is decided by the transaction's status.
	'unanswered cancel that Blink did cancel marks the order cancelled' => function () {
		$order   = new Crr_Order();
		$handler = new Crr_Handler( array( 'success' => false, 'unknown' => true, 'message' => 'cURL error 28' ), 'Canceled' );
		$result  = Blink_PG_Ajax_Handler::blink_cancel_order_transaction( $order, 'BL-TEST', $handler );
		crr_assert( $handler->status_checked, 'Status was checked' );
		crr_assert( true === $result['success'], 'Reported as cancelled' );
		crr_assert( 'cancelled' === $order->status, 'Order is cancelled' );
	},
	'unanswered cancel that Blink did not cancel leaves the order and says so' => function () {
		$order   = new Crr_Order();
		$handler = new Crr_Handler( array( 'success' => false, 'unknown' => true, 'message' => 'cURL error 28' ), 'Captured' );
		$result  = Blink_PG_Ajax_Handler::blink_cancel_order_transaction( $order, 'BL-TEST', $handler );
		crr_assert( false === $result['success'], 'Not reported as cancelled' );
		crr_assert( 'completed' === $order->status, 'Order unchanged' );
		crr_assert( 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'did not confirm' ), 'Note says Blink did not confirm' );
		crr_assert( false === strpos( $order->notes[0], 'Failed to cancel' ), 'Note does not claim the cancel failed' );
	},
	'refused cancel does not check the status' => function () {
		$order   = new Crr_Order();
		$handler = new Crr_Handler( array( 'success' => false, 'unknown' => false, 'message' => 'Too late to cancel' ) );
		$result  = Blink_PG_Ajax_Handler::blink_cancel_order_transaction( $order, 'BL-TEST', $handler );
		crr_assert( ! $handler->status_checked, 'Status not checked' );
		crr_assert( false === $result['success'] && 'completed' === $order->status, 'Order unchanged' );
		crr_assert( false !== strpos( $order->notes[0], 'Too late to cancel' ), 'Note has Blink\'s reason' );
	},
	// Item 3: refunds.
	'refund refused without success or message key returns an error, no warnings' => function () {
		list( $result, $order ) = crr_refund( crr_json( array( 'error' => 'Transaction not settled' ), 422 ) );
		crr_assert( $result instanceof WP_Error, 'Returns WP_Error' );
		crr_assert( false !== strpos( $result->get_error_message(), 'Transaction not settled' ), 'Error has Blink\'s reason' );
	},
	'refund with a non-JSON page returns an error' => function () {
		list( $result, $order ) = crr_refund( array( 'body' => '<html>502</html>', 'code' => 502 ) );
		crr_assert( $result instanceof WP_Error, 'Returns WP_Error' );
		crr_assert( false !== strpos( $result->get_error_message(), '502' ), 'Error gives the HTTP code' );
	},
	'refund accepted without a transaction ID still succeeds' => function () {
		list( $result, $order ) = crr_refund( crr_json( array( 'success' => true ) ) );
		crr_assert( true === $result, 'Returns true' );
		crr_assert( false === strpos( $order->notes[0], 'Transaction ID' ), 'No empty transaction ID in the note' );
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
