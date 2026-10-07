<?php

define( 'ABSPATH', __DIR__ . '/' );

$test_orders             = array();
$test_remote_post_calls  = array();
$test_remote_response    = array();
$test_logs               = array();
$test_current_user_can   = true;
$test_woocommerce        = null;
$test_options            = array();
$test_filters            = array();

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function current_user_can( $capability ) {
	global $test_current_user_can;
	return $test_current_user_can;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_generate_uuid4() {
	static $sequence = 0;
	return '00000000-0000-4000-8000-' . str_pad( (string) ++$sequence, 12, '0', STR_PAD_LEFT );
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	global $test_filters;
	$test_filters[ $tag ][] = array( $callback, $priority, $accepted_args );
}

function remove_filter( $tag, $callback, $priority = 10 ) {
	global $test_filters;
	if ( empty( $test_filters[ $tag ] ) ) return;
	$test_filters[ $tag ] = array_values( array_filter( $test_filters[ $tag ], function ( $item ) use ( $callback, $priority ) {
		return $item[1] !== $priority || $item[0] !== $callback;
	} ) );
}

function apply_filters( $tag, $value ) {
	global $test_filters;
	$args = func_get_args();
	array_shift( $args );
	foreach ( $test_filters[ $tag ] ?? array() as $item ) {
		$value = call_user_func_array( $item[0], array_slice( $args, 0, $item[2] ) );
		$args[0] = $value;
	}
	return $value;
}

function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	global $test_options;
	if ( array_key_exists( $key, $test_options ) ) {
		return false;
	}
	$test_options[ $key ] = $value;
	return true;
}

function get_option( $key, $default = false ) {
	global $test_options;
	return array_key_exists( $key, $test_options ) ? $test_options[ $key ] : $default;
}

function delete_option( $key ) {
	global $test_options;
	unset( $test_options[ $key ] );
	return true;
}

class WP_Error {
	private $message;

	public function __construct( $message ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_remote_post( $url, $args ) {
	global $test_remote_post_calls, $test_remote_response;
	$test_remote_post_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return is_callable( $test_remote_response ) ? $test_remote_response( $url, $args ) : $test_remote_response;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wc_get_order( $order_id ) {
	global $test_orders;
	return isset( $test_orders[ $order_id ] ) ? $test_orders[ $order_id ] : null;
}

function WC() {
	global $test_woocommerce;
	return $test_woocommerce;
}

function blink_is_preauth_transaction( $order ) {
	return $order && 'blink' === $order->get_payment_method() && 'yes' === $order->get_meta( '_blink_preauth', true );
}

function blink_payment_complete( $order, $transaction_id = '', $note = '', $force = false ) {
	if ( ! $force && $order->has_status( array( 'processing', 'completed' ) ) ) {
		return;
	}
	$filter = null;
	if ( $force && $order->has_status( 'processing' ) ) {
		$id = (int) $order->get_id();
		$filter = static function ( $statuses, $candidate ) use ( $id ) {
			if ( (int) $candidate->get_id() === $id ) $statuses[] = 'processing';
			return $statuses;
		};
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $filter, 10, 2 );
	}
	$valid = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled' ), $order );
	if ( $order->has_status( 'processing' ) && ! in_array( 'processing', $valid, true ) ) return;
	$order->update_meta_data( '_blink_payment_complete_done', 'yes' );
	$order->date_paid = time();
	++$order->woocommerce_payment_complete_calls;
	$order->payment_complete( $transaction_id );
	if ( $filter ) remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $filter, 10 );
}

function blink_get_status( $status, $source = '', $order = null ) {
	$status = strtolower( trim( (string) $status ) );
	if ( in_array( $status, array( 'captured', 'settled', 'completed', 'success' ), true ) ) {
		return 'complete';
	}
	if ( in_array( $status, array( 'authorized', 'authorised', 'pending' ), true ) ) {
		return 'hold';
	}
	return 'failed';
}

class Blink_Logger {
	public static function log( $message, $context = array() ) {
		global $test_logs;
		$test_logs[] = array( $message, $context );
	}

	public static function http_response_context( $response ) {
		return array( 'response_code' => wp_remote_retrieve_response_code( $response ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-blink-rerun-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-transaction-handler.php';

class Blink_Rerun_Test_Order {
	private $id;
	private $payment_method;
	private $status;
	private $meta;
	private $total;
	private $currency;
	public $transaction_id = '';
	public $notes          = array();
	public $save_calls     = 0;
	public $status_history = array();
	public $payment_complete_calls = 0;
	public $woocommerce_payment_complete_calls = 0;
	public $date_paid = 0;

	public function __construct( $id, $payment_method = 'blink', $status = 'on-hold', $is_preauth = true, $captured_id = '' ) {
		$this->id             = $id;
		$this->payment_method = $payment_method;
		$this->status         = $status;
		$this->total          = '42.50';
		$this->currency       = 'GBP';
		$this->status_history = array( $status );
		$this->meta           = array(
			'_blink_preauth' => $is_preauth ? 'yes' : 'no',
			'blink_res'      => 'PREAUTH-' . $id,
		);
		if ( '' !== $captured_id ) {
			$this->meta['blink_rerun_id'] = $captured_id;
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function get_status() {
		return $this->status;
	}

	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
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

	public function get_total() {
		return $this->total;
	}

	public function get_currency() {
		return $this->currency;
	}

	public function set_transaction_id( $transaction_id ) {
		$this->transaction_id = $transaction_id;
	}

	public function payment_complete( $transaction_id = '' ) {
		++$this->payment_complete_calls;
		$this->transaction_id = $transaction_id;
		$this->status = 'processing';
		$this->status_history[] = 'processing';
	}

	public function save() {
		++$this->save_calls;
		return $this->id;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function update_status( $status, $note = '' ) {
		$old_status   = $this->status;
		$this->status = $status;
		$this->status_history[] = $status;
		Blink_Transaction_Handler::blink_handle_order_status_change( $this->id, $old_status, $status );
	}
}

class Blink_Rerun_Test_Utils {
	public function blink_set_tokens() {
		return array( 'access_token' => 'test-token' );
	}
}

function blink_rerun_test_reset( $response = null ) {
	global $test_orders, $test_remote_post_calls, $test_remote_response, $test_logs, $test_current_user_can, $test_woocommerce, $test_options, $test_filters;
	$test_orders            = array();
	$test_remote_post_calls = array();
	$test_logs              = array();
	$test_current_user_can  = true;
	$test_options           = array();
	$test_filters           = array();
	$test_remote_response   = null === $response
		? array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'success'        => true,
					'transaction_id' => 'CAPTURE-1',
					'amount'         => '42.50',
					'status'         => 'captured',
					'message'        => 'Captured',
				)
			),
		)
		: $response;

	$gateway                = new stdClass();
	$gateway->host_url      = 'https://example.test/api';
	$gateway->utils         = new Blink_Rerun_Test_Utils();
	$gateway->rerun_handler = new Blink_Rerun_Handler( $gateway );
	$test_woocommerce       = new class( $gateway ) {
		public $payment_gateways;

		public function __construct( $gateway ) {
			$this->payment_gateways = new class( $gateway ) {
				private $gateway;

				public function __construct( $gateway ) {
					$this->gateway = $gateway;
				}

				public function payment_gateways() {
					return array( 'blink' => $this->gateway );
				}
			};
		}

		public function payment_gateways() {
			return $this->payment_gateways;
		}
	};
}

function blink_rerun_test_order( $id, $payment_method = 'blink', $status = 'on-hold', $is_preauth = true, $captured_id = '' ) {
	global $test_orders;
	$order                     = new Blink_Rerun_Test_Order( $id, $payment_method, $status, $is_preauth, $captured_id );
	$test_orders[ $order->get_id() ] = $order;
	return $order;
}

function blink_rerun_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function blink_rerun_assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message . ': ' . var_export( $needle, true ) . ' not found in ' . var_export( $haystack, true ) );
	}
}

function blink_rerun_note_count( $order, $needle ) {
	$count = 0;
	foreach ( $order->notes as $note ) {
		if ( false !== strpos( $note, $needle ) ) {
			++$count;
		}
	}
	return $count;
}

$tests = array(
	'scoped processing completion filter only allows its order' => function () {
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 901, 'blink', 'processing', true );
		$other = blink_rerun_test_order( 902, 'blink', 'processing', true );
		$id = $order->get_id();
		$filter = static function ( $statuses, $candidate ) use ( $id ) {
			if ( (int) $candidate->get_id() === (int) $id ) $statuses[] = 'processing';
			return $statuses;
		};
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $filter, 10, 2 );
		try {
			blink_rerun_assert_same( true, in_array( 'processing', apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold' ), $order ), true ), 'Target processing order allowed' );
			blink_rerun_assert_same( false, in_array( 'processing', apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold' ), $other ), true ), 'Other processing order unchanged' );
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $filter, 10 );
		}
	},
	'eligible Blink pre-auth on hold has capture action' => function () {
		blink_rerun_test_reset();
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 1 ) );
		blink_rerun_assert_same( 'Capture Blink pre-authorisation', $actions['blink_capture_preauthorisation'], 'Capture action label' );
	},
	'normal Blink sale has no capture action' => function () {
		blink_rerun_test_reset();
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 2, 'blink', 'on-hold', false ) );
		blink_rerun_assert_same( array(), $actions, 'Sale actions' );
	},
	'non-Blink order has no capture action' => function () {
		blink_rerun_test_reset();
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 3, 'cod' ) );
		blink_rerun_assert_same( array(), $actions, 'Non-Blink actions' );
	},
	'Blink pre-auth not on hold has no capture action' => function () {
		blink_rerun_test_reset();
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 4, 'blink', 'processing' ) );
		blink_rerun_assert_same( array(), $actions, 'Processing actions' );
	},
	'already captured Blink pre-auth has no capture action' => function () {
		blink_rerun_test_reset();
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 5, 'blink', 'on-hold', true, 'CAPTURED-5' ) );
		blink_rerun_assert_same( array(), $actions, 'Captured actions' );
	},
	'Blink pre-auth without an original transaction has no capture action' => function () {
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 51 );
		$order->update_meta_data( 'blink_res', '' );
		blink_rerun_assert_same( array(), Blink_Transaction_Handler::blink_add_capture_order_action( array(), $order ), 'Missing transaction actions' );
	},
	'unauthorised user has no capture action' => function () {
		global $test_current_user_can;
		blink_rerun_test_reset();
		$test_current_user_can = false;
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), blink_rerun_test_order( 6 ) );
		blink_rerun_assert_same( array(), $actions, 'Unauthorised actions' );
	},
	'explicit capture persists success and changes to processing exactly once' => function () {
		global $test_remote_post_calls;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 7 );
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Capture request count' );
		blink_rerun_assert_contains( '/transactions/PREAUTH-7/reruns', $test_remote_post_calls[0]['url'], 'Capture URL' );
		blink_rerun_assert_same( 'CAPTURE-1', $order->get_meta( 'blink_rerun_id', true ), 'Persistent capture ID' );
		blink_rerun_assert_same( 'CAPTURE-1', $order->transaction_id, 'Order transaction ID' );
		blink_rerun_assert_same( 'captured', $order->get_meta( '_gateway_status', true ), 'Gateway status' );
		blink_rerun_assert_same( 'processing', $order->get_status(), 'Order status' );
		blink_rerun_assert_same( 1, $order->payment_complete_calls, 'Payment completion call count' );
		blink_rerun_assert_same( 1, $order->woocommerce_payment_complete_calls, 'WooCommerce payment-complete hook count' );
		blink_rerun_assert_same( true, $order->date_paid > 0, 'Paid date recorded' );
		blink_rerun_assert_contains( 'captured successfully', implode( ' ', $order->notes ), 'Success note' );
		blink_rerun_assert_same( 1, blink_rerun_note_count( $order, 'captured successfully' ), 'Success note count' );
		blink_rerun_assert_same( false, get_option( 'blink_rerun_lock_7', false ), 'Success lock cleared' );
	},
	'explicit capture failure remains on hold and records no capture ID' => function () {
		global $test_remote_post_calls;
		blink_rerun_test_reset(
			array(
				'response' => array( 'code' => 422 ),
				'body'     => json_encode( array( 'success' => false, 'message' => 'Authorisation expired' ) ),
			)
		);
		$order = blink_rerun_test_order( 8 );
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Failed request count' );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Failed order status' );
		blink_rerun_assert_same( '', $order->get_meta( 'blink_rerun_id', true ), 'Failed capture ID' );
		blink_rerun_assert_contains( 'Authorisation expired', implode( ' ', $order->notes ), 'Failure note' );
	},
	'malformed successful response is treated as a capture failure' => function () {
		blink_rerun_test_reset(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'success' => true ) ),
			)
		);
		$order = blink_rerun_test_order( 9 );
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Malformed response order status' );
		blink_rerun_assert_same( '', $order->get_meta( 'blink_rerun_id', true ), 'Malformed response capture ID' );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Malformed response marker' );
		blink_rerun_assert_contains( 'outcome is unknown', implode( ' ', $order->notes ), 'Malformed response note' );
	},
	'capture accepts final Paid and Approved statuses despite pre-auth mapping' => function () {
		global $test_remote_response;
		foreach ( array( 'Paid', 'Approved' ) as $index => $status ) {
			blink_rerun_test_reset(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array( 'success' => true, 'transaction_id' => 'CAPTURE-' . $status, 'status' => $status ) ),
				)
			);
			$order = blink_rerun_test_order( 90 + $index );
			Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
			blink_rerun_assert_same( 'processing', $order->get_status(), $status . ' capture status' );
			blink_rerun_assert_same( 'CAPTURE-' . $status, $order->get_meta( 'blink_rerun_id', true ), $status . ' capture ID' );
		}
	},
	'legacy on-hold to processing captures exactly once' => function () {
		global $test_remote_post_calls;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 10 );
		$order->update_status( 'processing' );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Legacy capture request count' );
		blink_rerun_assert_same( 'processing', $order->get_status(), 'Legacy success status' );
		blink_rerun_assert_same( 'CAPTURE-1', $order->get_meta( 'blink_rerun_id', true ), 'Legacy capture ID' );
		blink_rerun_assert_same( 1, $order->payment_complete_calls, 'Legacy payment completion call count' );
		blink_rerun_assert_same( 1, $order->woocommerce_payment_complete_calls, 'Legacy WooCommerce payment-complete hook count' );
		blink_rerun_assert_same( true, $order->date_paid > 0, 'Legacy paid date recorded' );
	},
	'processing payment completion requires the capture compatibility path' => function () {
		$order = blink_rerun_test_order( 101, 'blink', 'processing' );
		blink_payment_complete( $order, 'DIRECT-PROCESSING', '', false );
		blink_rerun_assert_same( 0, $order->woocommerce_payment_complete_calls, 'Core processing guard' );
		blink_rerun_assert_same( 0, $order->date_paid, 'Core processing paid date guard' );
		blink_payment_complete( $order, 'LEGACY-CAPTURE', '', true );
		blink_rerun_assert_same( 1, $order->woocommerce_payment_complete_calls, 'Compatibility completion hook' );
		blink_rerun_assert_same( true, $order->date_paid > 0, 'Compatibility paid date' );
		blink_rerun_assert_same( 'LEGACY-CAPTURE', $order->transaction_id, 'Compatibility capture transaction' );
	},
	'already captured legacy order does not capture again' => function () {
		global $test_remote_post_calls;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 11, 'blink', 'on-hold', true, 'CAPTURED-11' );
		$order->update_status( 'processing' );
		blink_rerun_assert_same( 0, count( $test_remote_post_calls ), 'Duplicate legacy request count' );
	},
	'failed legacy capture returns order to on hold without recursion' => function () {
		global $test_remote_post_calls;
		blink_rerun_test_reset( new WP_Error( 'Gateway unavailable' ) );
		$order = blink_rerun_test_order( 12 );
		$order->update_status( 'processing' );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Legacy failure request count' );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Legacy failure status' );
		blink_rerun_assert_contains( 'Gateway unavailable', implode( ' ', $order->notes ), 'Legacy failure note' );
	},
	'network failure clears the persistent in-progress marker' => function () {
		blink_rerun_test_reset( new WP_Error( 'Network timeout' ) );
		$order = blink_rerun_test_order( 13 );
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Network failure marker' );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Network failure status' );
	},
	'exception clears the persistent in-progress marker' => function () {
		global $test_remote_response;
		blink_rerun_test_reset();
		$test_remote_response = function () {
			throw new RuntimeException( 'Transport exception' );
		};
		$order = blink_rerun_test_order( 131 );
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Exception marker' );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Exception status' );
		blink_rerun_assert_same( 1, blink_rerun_note_count( $order, 'Transport exception' ), 'Exception note count' );
	},
	'process_rerun itself rejects an already captured order' => function () {
		global $test_remote_post_calls, $test_woocommerce;
		blink_rerun_test_reset();
		$order   = blink_rerun_test_order( 132, 'blink', 'on-hold', true, 'CAPTURED-132' );
		$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
		$result  = $gateway->rerun_handler->process_rerun( $order );
		blink_rerun_assert_same( false, $result['success'], 'Already captured process result' );
		blink_rerun_assert_same( 0, count( $test_remote_post_calls ), 'Already captured direct request count' );
	},
	'success webhook before the HTTP response does not duplicate capture' => function () {
		global $test_remote_post_calls, $test_remote_response, $test_woocommerce;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 14 );
		$test_remote_response = function () use ( $order, &$test_woocommerce ) {
			$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
			$handler = new Blink_Transaction_Handler( $gateway );
			$handler->blink_handle_rerun_webhook(
				$order,
				array( 'event_type' => 'rerun_transaction', 'status' => 'Captured' ),
				'CAPTURE-RACE'
			);
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'success' => true, 'transaction_id' => 'CAPTURE-RACE', 'status' => 'Captured' ) ),
			);
		};

		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Race capture request count' );
		blink_rerun_assert_same( 'CAPTURE-RACE', $order->get_meta( 'blink_rerun_id', true ), 'Race capture ID' );
		blink_rerun_assert_same( 'PREAUTH-14', $order->get_meta( 'blink_res', true ), 'Race original transaction ID' );
		blink_rerun_assert_same( 'processing', $order->get_status(), 'Race final status' );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Race marker' );
		blink_rerun_assert_same( 1, blink_rerun_note_count( $order, 'captured successfully' ), 'Race success note count' );
		blink_rerun_assert_same( 1, $order->payment_complete_calls, 'Race payment completion count' );
	},
	'success webhook wins over a subsequent transport failure' => function () {
		global $test_remote_response, $test_woocommerce;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 141 );
		$test_remote_response = function () use ( $order, &$test_woocommerce ) {
			$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
			$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Captured' ), 'CAPTURE-RACE-ERROR' );
			return new WP_Error( 'timeout', 'Timeout' );
		};
		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 'CAPTURE-RACE-ERROR', $order->get_meta( 'blink_rerun_id', true ), 'Webhook capture retained' );
		blink_rerun_assert_same( 'processing', $order->get_status(), 'Webhook success status retained' );
		blink_rerun_assert_same( 1, $order->payment_complete_calls, 'Webhook completion count' );
	},
	'unrelated webhook cannot resolve unknown capture outcome' => function () {
		global $test_woocommerce;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 142 );
		$order->update_meta_data( '_blink_rerun_outcome_unknown', 'yes' );
		$order->update_meta_data( '_blink_rerun_reference', 'WC-142-R-expected' );
		$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
		$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Captured', 'reference' => 'WC-142-R-other' ), 'OTHER-142' );
		blink_rerun_assert_same( 'yes', $order->get_meta( '_blink_rerun_outcome_unknown', true ), 'Unknown marker retained' );
		blink_rerun_assert_same( '', $order->get_meta( 'blink_rerun_id', true ), 'No unrelated capture ID' );
		blink_rerun_assert_same( 0, $order->payment_complete_calls, 'No unrelated payment completion' );
	},
	'failed rerun webhook and HTTP response produce one note and never fail the order' => function () {
		global $test_remote_post_calls, $test_remote_response, $test_woocommerce;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 15 );
		$test_remote_response = function () use ( $order, &$test_woocommerce ) {
			$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
			$handler = new Blink_Transaction_Handler( $gateway );
			$handler->blink_handle_rerun_webhook(
				$order,
				array( 'event_type' => 'rerun_transaction', 'status' => 'Declined', 'message' => 'CARD DECLINED' ),
				'FAILED-RERUN-15'
			);
			return array(
				'response' => array( 'code' => 422 ),
				'body'     => json_encode( array( 'success' => false, 'transaction_id' => 'FAILED-RERUN-15', 'status' => 'Declined', 'message' => 'CARD DECLINED' ) ),
			);
		};

		Blink_Transaction_Handler::blink_capture_preauthorisation_action( $order );
		blink_rerun_assert_same( 1, count( $test_remote_post_calls ), 'Declined capture request count' );
		blink_rerun_assert_same( 'on-hold', $order->get_status(), 'Declined capture status' );
		blink_rerun_assert_same( false, in_array( 'failed', $order->status_history, true ), 'No failed transition/email trigger' );
		blink_rerun_assert_same( 'PREAUTH-15', $order->get_meta( 'blink_res', true ), 'Original pre-auth ID retained' );
		blink_rerun_assert_same( '', $order->get_meta( 'blink_rerun_id', true ), 'Failed rerun not captured' );
		blink_rerun_assert_same( 'FAILED-RERUN-15', $order->get_meta( '_blink_last_failed_rerun_id', true ), 'Failed rerun ID recorded separately' );
		blink_rerun_assert_same( 1, blink_rerun_note_count( $order, 'capture failed: CARD DECLINED' ), 'Failure note count' );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Declined marker' );
	},
	'failed rerun webhook only restores active legacy processing capture' => function () {
		global $test_woocommerce;
		foreach ( array( 'cancelled', 'refunded', 'completed' ) as $status ) {
			blink_rerun_test_reset();
			$order = blink_rerun_test_order( 150 + strlen( $status ), 'blink', $status, true );
			$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
			$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined' ), 'DELAYED-' . $status );
			blink_rerun_assert_same( $status, $order->get_status(), $status . ' remains unchanged' );
		}
		blink_rerun_test_reset();
		$on_hold = blink_rerun_test_order( 154 );
		$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
		$handler->blink_handle_rerun_webhook( $on_hold, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined' ), 'ON-HOLD-FAIL' );
		blink_rerun_assert_same( 'on-hold', $on_hold->get_status(), 'On hold remains unchanged' );

		blink_rerun_test_reset();
		$processing = blink_rerun_test_order( 155, 'blink', 'processing', true );
		$processing->update_meta_data( '_blink_rerun_in_progress', time() . ':legacy' );
		$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
		$handler->blink_handle_rerun_webhook( $processing, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined' ), 'LEGACY-FAIL' );
		blink_rerun_assert_same( 'on-hold', $processing->get_status(), 'Active legacy capture restored to on hold' );
	},
	'only distinct rerun_transaction events use capture webhook handling' => function () {
		global $test_woocommerce;
		blink_rerun_test_reset();
		$order   = blink_rerun_test_order( 16 );
		$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
		$handler = new Blink_Transaction_Handler( $gateway );
		blink_rerun_assert_same( false, $handler->blink_is_rerun_webhook( $order, array( 'event_type' => 'transaction', 'status' => 'Declined' ), 'SALE-16' ), 'Ordinary decline classification' );
		blink_rerun_assert_same( false, $handler->blink_is_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined' ), 'PREAUTH-16' ), 'Original pre-auth decline classification' );
		blink_rerun_assert_same( true, $handler->blink_is_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined' ), 'RERUN-16' ), 'Capture decline classification' );
	},
	'recorded capture matching failure is processed while unrelated IDs are ignored' => function () {
		global $test_woocommerce;
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 160, 'blink', 'processing', true, 'CAPTURE-160' );
		$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
		$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined', 'message' => 'REVERSAL' ), 'OTHER-160' );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_last_failed_rerun_id', true ), 'Unrelated rerun ignored' );
		$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Declined', 'message' => 'REVERSAL' ), 'CAPTURE-160' );
		blink_rerun_assert_same( 'CAPTURE-160', $order->get_meta( '_blink_last_failed_rerun_id', true ), 'Matching failure recorded' );
		blink_rerun_assert_same( 'failed', $order->get_status(), 'Recorded capture failure is non-paid' );
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 163, 'blink', 'processing', true, 'CAPTURE-163' );
		$handler = new Blink_Transaction_Handler( $test_woocommerce->payment_gateways->payment_gateways()['blink'] );
		$handler->blink_handle_rerun_webhook( $order, array( 'event_type' => 'rerun_transaction', 'status' => 'Reversed' ), 'CAPTURE-163' );
		blink_rerun_assert_same( 'failed', $order->get_status(), 'Recorded reversal is non-paid' );
	},
	'late failed rerun webhook cannot undo an existing capture' => function () {
		global $test_woocommerce, $test_options;
		blink_rerun_test_reset();
		$order   = blink_rerun_test_order( 161, 'blink', 'processing', true, 'CAPTURED-161' );
		$order->update_meta_data( '_blink_rerun_in_progress', time() . ':new-attempt' );
		$test_options['blink_rerun_lock_161'] = time() . ':new-attempt';
		$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
		$handler = new Blink_Transaction_Handler( $gateway );
		$handled = $handler->blink_handle_rerun_webhook(
			$order,
			array( 'event_type' => 'rerun_transaction', 'status' => 'Declined', 'message' => 'CARD DECLINED' ),
			'OLDER-FAILED-RERUN'
		);
		blink_rerun_assert_same( true, $handled, 'Late failure handled' );
		blink_rerun_assert_same( 'processing', $order->get_status(), 'Captured status retained' );
		blink_rerun_assert_same( 'CAPTURED-161', $order->get_meta( 'blink_rerun_id', true ), 'Captured ID retained' );
		blink_rerun_assert_same( 0, blink_rerun_note_count( $order, 'capture failed' ), 'No stale failure note' );
		blink_rerun_assert_same( true, '' !== $order->get_meta( '_blink_rerun_in_progress', true ), 'Newer marker retained' );
		blink_rerun_assert_same( true, '' !== get_option( 'blink_rerun_lock_161', '' ), 'Newer lock retained' );
	},
	'delayed failure webhook cannot clear a newer active capture lock' => function () {
		global $test_woocommerce, $test_options;
		blink_rerun_test_reset();
		$order   = blink_rerun_test_order( 162 );
		$gateway = $test_woocommerce->payment_gateways->payment_gateways()['blink'];
		$handler = new Blink_Transaction_Handler( $gateway );
		$order->update_meta_data( '_blink_rerun_in_progress', time() . ':attempt-b' );
		$test_options['blink_rerun_lock_162'] = time() . ':attempt-b';
		$handler->blink_handle_rerun_webhook(
			$order,
			array( 'event_type' => 'rerun_transaction', 'status' => 'Declined', 'message' => 'CARD DECLINED' ),
			'ATTEMPT-A-RERUN'
		);
		blink_rerun_assert_same( true, '' !== $order->get_meta( '_blink_rerun_in_progress', true ), 'Attempt B marker retained' );
		blink_rerun_assert_same( true, '' !== get_option( 'blink_rerun_lock_162', '' ), 'Attempt B lock retained' );
		blink_rerun_assert_same( array(), Blink_Transaction_Handler::blink_add_capture_order_action( array(), $order ), 'New capture remains blocked' );
	},
	'active capture marker hides action and stale marker self-clears' => function () {
		blink_rerun_test_reset();
		$order = blink_rerun_test_order( 17 );
		$order->update_meta_data( '_blink_rerun_in_progress', time() . ':active' );
		blink_rerun_assert_same( array(), Blink_Transaction_Handler::blink_add_capture_order_action( array(), $order ), 'Active marker actions' );
		$order->update_meta_data( '_blink_rerun_in_progress', ( time() - 301 ) . ':stale' );
		$actions = Blink_Transaction_Handler::blink_add_capture_order_action( array(), $order );
		blink_rerun_assert_same( 'Capture Blink pre-authorisation', $actions['blink_capture_preauthorisation'], 'Stale marker action' );
		blink_rerun_assert_same( '', $order->get_meta( '_blink_rerun_in_progress', true ), 'Stale marker cleared' );
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
