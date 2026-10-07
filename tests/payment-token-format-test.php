<?php

define( 'ABSPATH', __DIR__ . '/' );

$test_http_requests = array();
$test_orders        = array();
$test_is_order_pay  = false;

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_email( $value ) {
	return (string) $value;
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return stripslashes( (string) $value );
}

function wc_get_order( $order_id ) {
	global $test_orders;
	return isset( $test_orders[ $order_id ] ) ? $test_orders[ $order_id ] : null;
}

function WC() {
	static $woocommerce;
	if ( ! $woocommerce ) {
		$woocommerce = (object) array(
			'cart' => null,
		);
	}

	return $woocommerce;
}

function is_wc_endpoint_url( $endpoint ) {
	global $test_is_order_pay;
	return 'order-pay' === $endpoint && $test_is_order_pay;
}

function blink_get_payment_information( $order_id ) {
	return wp_json_encode( array( 'order_id' => $order_id ) );
}

function blink_error_payment_process( $message ) {
	return array(
		'result' => 'failure',
		'error'  => $message,
	);
}

function get_woocommerce_currency() {
	return 'GBP';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_remote_post( $url, $args ) {
	global $test_http_requests;
	$test_http_requests[] = array(
		'url'  => $url,
		'args' => $args,
	);

	return array(
		'body'     => wp_json_encode( array( 'url' => 'https://example.com/complete' ) ),
		'response' => array( 'code' => 200 ),
	);
}

function is_wp_error( $response ) {
	return false;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function set_transient( $key, $value, $expiration ) {
	return true;
}

class Blink_Logger {
	public static function log( $message, $context = null ) {
	}

	public static function http_response_context( $response ) {
		return array();
	}
}

require_once dirname( __DIR__ ) . '/includes/class-blink-payment-handler.php';

class Blink_Payment_Token_Test_Order {
	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_total() {
		return 16.0;
	}

	public function get_billing_email() {
		return 'customer@example.com';
	}

	public function get_billing_first_name() {
		return 'Test';
	}

	public function get_billing_last_name() {
		return 'Customer';
	}

	public function get_billing_address_1() {
		return '1 Test Street';
	}

	public function get_billing_address_2() {
		return '';
	}

	public function get_billing_postcode() {
		return 'TE1 1ST';
	}

	public function add_meta_data( $key, $value ) {
	}

	public function save() {
	}
}

class Blink_Payment_Token_Test_Utils {
	public function blink_set_tokens( $intent_id ) {
		return array( 'access_token' => 'access-token' );
	}

	public function blink_set_intents( $request, $order ) {
		return array(
			'merchant_id'    => 'merchant-id',
			'payment_intent' => 'payment-intent',
		);
	}

	public function blink_destroy_session_tokens( $intent_id ) {
	}
}

class Blink_Payment_Token_Test_Gateway {
	public $host_url             = 'https://gateway.example.com';
	public $paymentMethods       = array( 'credit-card', 'direct-debit' );
	public $preauthorize_payments = false;
	public $utils;

	public function __construct() {
		$this->utils = new Blink_Payment_Token_Test_Utils();
	}

	public function blink_is_hosted() {
		return false;
	}

	public function blink_get_return_url( $order ) {
		return 'https://example.com/order/' . $order->get_id();
	}
}

function blink_payment_token_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

function blink_payment_token_request( $payment_by, $payment_token = null ) {
	$request = array(
		'payment_by'              => $payment_by,
		'intent_id'               => 'intent-id',
		'type'                    => '1',
		'device_timezone'         => '0',
		'device_capabilities'     => 'javascript',
		'device_accept_language'  => 'en-GB',
		'device_screen_resolution' => '1920x1080',
		'customer_name'           => 'Test Customer',
		'customer_email'          => 'customer@example.com',
	);

	if ( null !== $payment_token ) {
		$request['paymentToken'] = $payment_token;
	}

	return $request;
}

function blink_payment_token_run_request( $request, $is_order_pay = false ) {
	global $test_http_requests, $test_is_order_pay;

	$test_http_requests = array();
	$test_is_order_pay  = $is_order_pay;
	$_POST              = $request;
	$_REQUEST           = $request;

	$handler = new Blink_Payment_Handler( new Blink_Payment_Token_Test_Gateway() );
	$result  = $handler->blink_handle_payment( 17037 );

	blink_payment_token_assert_same( 'success', $result['result'], 'Expected checkout to succeed.' );
	blink_payment_token_assert_same( 1, count( $test_http_requests ), 'Expected one API request.' );

	return $test_http_requests[0];
}

$apple_payment_data = array(
	'data'      => 'encrypted-data',
	'signature' => 'signature-value',
	'header'    => array(
		'publicKeyHash'      => 'public-key-hash',
		'ephemeralPublicKey' => 'ephemeral-public-key',
		'transactionId'      => 'transaction-id',
	),
	'version'   => 'EC_v1',
);
$apple_payment_json = wp_json_encode( $apple_payment_data );
$test_orders[17037] = new Blink_Payment_Token_Test_Order( 17037 );

$tests = array(
	'Apple Pay keeps paymentData JSON text in the outgoing API body' => function () use ( $apple_payment_json ) {
		$request = blink_payment_token_run_request(
			blink_payment_token_request( 'apple-pay', addslashes( $apple_payment_json ) )
		);

		blink_payment_token_assert_same( 'https://gateway.example.com/pay/v1/applepay', $request['url'], 'Expected the Apple Pay endpoint.' );
		blink_payment_token_assert_same( $apple_payment_json, $request['args']['body']['paymentToken'], 'Expected Apple Pay token JSON to remain a scalar string.' );

		$encoded_body = http_build_query( $request['args']['body'] );
		blink_payment_token_assert_same( false, false !== strpos( $encoded_body, 'paymentToken%5B' ), 'Apple Pay token must not be expanded into bracketed fields.' );
	},
	'Google Pay keeps its existing decoded token representation' => function () use ( $apple_payment_json, $apple_payment_data ) {
		$request = blink_payment_token_run_request(
			blink_payment_token_request( 'google-pay', addslashes( $apple_payment_json ) )
		);

		blink_payment_token_assert_same( 'https://gateway.example.com/pay/v1/googlepay', $request['url'], 'Expected the Google Pay endpoint.' );
		blink_payment_token_assert_same( $apple_payment_data, $request['args']['body']['paymentToken'], 'Expected Google Pay token decoding to remain unchanged.' );
	},
	'credit card data keeps its existing token representation' => function () use ( $apple_payment_json ) {
		$request                     = blink_payment_token_request( 'credit-card' );
		$request['credit-card-data'] = http_build_query(
			array(
				'paymentToken' => $apple_payment_json,
				'type'         => '1',
			)
		);
		$http_request = blink_payment_token_run_request( $request );

		blink_payment_token_assert_same( 'https://gateway.example.com/pay/v1/creditcards', $http_request['url'], 'Expected the credit-card endpoint.' );
		blink_payment_token_assert_same( $apple_payment_json, $http_request['args']['body']['paymentToken'], 'Expected credit-card token handling to remain unchanged.' );
	},
	'Direct checkout still uses the direct debit request path' => function () {
		$request = blink_payment_token_request( 'direct-debit' );
		$request = array_merge(
			$request,
			array(
				'given_name'          => 'Test',
				'family_name'         => 'Customer',
				'company_name'        => '',
				'email'               => 'customer@example.com',
				'account_holder_name' => 'Test Customer',
				'branch_code'         => '123456',
				'account_number'      => '12345678',
			)
		);
		$http_request = blink_payment_token_run_request( $request );

		blink_payment_token_assert_same( 'https://gateway.example.com/pay/v1/directdebits', $http_request['url'], 'Expected the Direct Debit endpoint.' );
		blink_payment_token_assert_same( false, isset( $http_request['args']['body']['paymentToken'] ), 'Direct Debit must not gain a payment token.' );
	},
	'order-pay Apple Pay shares the scalar token path' => function () use ( $apple_payment_json ) {
		$request = blink_payment_token_run_request(
			blink_payment_token_request( 'apple-pay', addslashes( $apple_payment_json ) ),
			true
		);

		blink_payment_token_assert_same( $apple_payment_json, $request['args']['body']['paymentToken'], 'Expected order-pay Apple Pay token JSON to remain a scalar string.' );
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
