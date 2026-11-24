<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: logger tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$lg_options = array();
$lg_lines   = array();
$lg_uploads = '';

function get_option( $name, $default = false ) {
	global $lg_options;
	return array_key_exists( $name, $lg_options ) ? $lg_options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	global $lg_options;
	$lg_options[ $name ] = $value;
	return true;
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? $response['response']['code'] : ''; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? $response['body'] : ''; }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function admin_url( $path = '' ) { return 'https://shop.example.com/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_upload_dir( $time = null, $create = true ) {
	global $lg_uploads;
	return array( 'basedir' => $lg_uploads );
}
function wp_delete_file( $file ) { unlink( $file ); }

class WP_Error {
	private $message;
	public function __construct( $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

class LG_Logger {
	public function info( $message, $context ) {
		global $lg_lines;
		$lg_lines[] = array( 'message' => $message, 'context' => $context );
	}
	public function warning( $message, $context ) {
		global $lg_lines;
		$lg_lines[] = array( 'message' => $message, 'context' => $context, 'level' => 'warning' );
	}
}
function wc_get_logger() { return new LG_Logger(); }

$lg_rmdir_works = true;
class WP_Filesystem_Direct {
	public function __construct( $arg ) {}
	public function rmdir( $dir ) {
		global $lg_rmdir_works;
		return $lg_rmdir_works ? rmdir( $dir ) : false;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-blink-pg-logger.php';

function lg_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

function lg_assert_absent( $needle, $haystack, $message ) {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $message . ' Found: ' . $needle . ' in ' . $haystack );
	}
}

function lg_reset() {
	global $lg_options, $lg_lines, $lg_rmdir_works;
	$lg_options     = array( 'woocommerce_blink_settings' => array( 'debug_mode' => 'yes' ) );
	$lg_rmdir_works = true;
	$lg_lines   = array();
	$_REQUEST   = array();
	$reset      = function () { self::$order_id = ''; };
	$reset->bindTo( null, 'Blink_PG_Logger' )();
}

function lg_last_line() {
	global $lg_lines;
	return end( $lg_lines );
}

$tests = array();

$tests['lines go to the WooCommerce logger under the plugin source'] = function () {
	Blink_PG_Logger::log( 'hello' );
	lg_assert( array( 'source' => 'blink-payment-gateway' ), lg_last_line()['context'], 'Lines use the plugin log source.' );
};

$tests['nothing is logged when debug mode and WordPress debugging are off'] = function () {
	global $lg_options, $lg_lines;
	if ( Blink_PG_Logger::is_wp_debug_enabled() ) {
		return; // WP_DEBUG cannot be undefined once set.
	}
	$lg_options['woocommerce_blink_settings'] = array( 'debug_mode' => 'no' );
	Blink_PG_Logger::log( 'hello' );
	lg_assert( array(), $lg_lines, 'No line is written.' );
};

$tests['lines are tagged with the order ID, never the customer email'] = function () {
	Blink_PG_Logger::log( 'paying', array( 'order_id' => 42, 'billing_email' => 'someone@example.com', 'customer_email' => 'someone@example.com' ) );
	$line = lg_last_line()['message'];
	lg_assert( 0, strpos( $line, '42 ' ), 'The line starts with the order ID.' );
	lg_assert_absent( 'someone@example.com', $line, 'The email is not logged.' );
};

$tests['the order ID is read from the request, but nothing else from it is logged'] = function () {
	$_REQUEST = array( 'reference' => 'WC-77', 'billing_email' => 'someone@example.com' );
	Blink_PG_Logger::log( 'return' );
	$line = lg_last_line()['message'];
	lg_assert( 0, strpos( $line, '77 ' ), 'The request reference tags the line.' );
	lg_assert_absent( 'someone@example.com', $line, 'Request values are not logged.' );
};

$tests['customer and secret keys are removed from context at any depth'] = function () {
	Blink_PG_Logger::log( 'ctx', array(
		'status'   => 'Captured',
		'secret_key' => 'sk',
		'access_token' => 'tok',
		'payer_info' => array( 'customer_id' => 5 ),
		'nested'   => array(
			'customer_name'     => 'Test Person',
			'billing_address_1' => '1 Test Street',
			'billing_phone'     => '01234567890',
			'postcode'          => 'AB1 2CD',
			'card_number'       => '4242',
			'transaction_id'    => 'BL-1',
		),
	) );
	$line = lg_last_line()['message'];
	foreach ( array( 'sk', 'tok', 'payer_info', 'Test Person', '1 Test Street', '01234567890', 'AB1 2CD', '4242' ) as $value ) {
		lg_assert_absent( $value, $line, 'Redacted value must not appear.' );
	}
	lg_assert( true, false !== strpos( $line, '"transaction_id":"BL-1"' ) && false !== strpos( $line, '"status":"Captured"' ), 'Other keys stay.' );
};

$tests['API responses keep only the diagnostic fields'] = function () {
	$response = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success' => true,
			'data'    => array(
				'transaction_id'   => 'BL-9',
				'status'           => 'Captured',
				'amount'           => 18,
				'customer_name'    => 'Test Person',
				'customer_email'   => 'someone@example.com',
				'customer_address' => '1 Test Street',
				'notes'            => 'Leave with neighbour at 1 Test Street',
				'element'          => array( 'ccElement' => '<form>' ),
			),
		) ),
	);
	lg_assert(
		array( 'code' => 200, 'body' => array( 'success' => true, 'data' => array( 'transaction_id' => 'BL-9', 'status' => 'Captured', 'amount' => 18 ) ) ),
		Blink_PG_Logger::http_response_context( $response ),
		'Only RESPONSE_KEYS survive.'
	);
};

$tests['a non-JSON response body is not logged'] = function () {
	$context = Blink_PG_Logger::http_response_context( array( 'response' => array( 'code' => 502 ), 'body' => '<html>someone@example.com</html>' ) );
	lg_assert( array( 'code' => 502, 'body' => '[non-JSON body, 32 bytes]' ), $context, 'Only the size is logged.' );
};

$tests['the settings link opens WooCommerce logs for the plugin source'] = function () {
	lg_assert( 'https://shop.example.com/wp-admin/admin.php?page=wc-status&tab=logs&source=blink-payment-gateway', Blink_PG_Logger::get_logs_url(), 'Logs URL.' );
};

$tests['old log files and their folder are deleted once'] = function () {
	global $lg_uploads, $lg_options;
	$lg_uploads = sys_get_temp_dir() . '/blink-logger-test-' . getmypid();
	$dir        = $lg_uploads . '/blink-logs';
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/blink-2026-11-01.log', "someone@example.com\n" );
	file_put_contents( $dir . '/.htaccess', "deny from all\n" );

	Blink_PG_Logger::maybe_remove_legacy_logs();
	lg_assert( false, is_dir( $dir ), 'The folder is gone.' );
	lg_assert( 1, $lg_options['blink_pg_legacy_logs_removed'], 'The cleanup is recorded.' );

	mkdir( $dir );
	Blink_PG_Logger::maybe_remove_legacy_logs();
	lg_assert( true, is_dir( $dir ), 'Once recorded, the cleanup does not run again.' );
	rmdir( $dir );
	rmdir( $lg_uploads );
};

$tests['a folder that cannot be removed is reported once, not retried on every request'] = function () {
	global $lg_uploads, $lg_options, $lg_lines, $lg_rmdir_works;
	$lg_uploads     = sys_get_temp_dir() . '/blink-logger-test-stuck-' . getmypid();
	$dir            = $lg_uploads . '/blink-logs';
	$lg_rmdir_works = false;
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/blink-2026-11-01.log', "someone@example.com\n" );

	Blink_PG_Logger::maybe_remove_legacy_logs();
	lg_assert( false, file_exists( $dir . '/blink-2026-11-01.log' ), 'The log file is still deleted.' );
	lg_assert( 1, $lg_options['blink_pg_legacy_logs_removed'], 'The attempt is recorded.' );
	lg_assert( 'warning', lg_last_line()['level'] ?? null, 'The merchant is warned.' );

	$count = count( $lg_lines );
	Blink_PG_Logger::maybe_remove_legacy_logs();
	lg_assert( $count, count( $lg_lines ), 'No second attempt or warning.' );
	rmdir( $dir );
	rmdir( $lg_uploads );
};

$tests['the cleanup is recorded when there is no old folder'] = function () {
	global $lg_uploads, $lg_options;
	$lg_uploads = sys_get_temp_dir() . '/blink-logger-test-missing-' . getmypid();
	Blink_PG_Logger::maybe_remove_legacy_logs();
	lg_assert( 1, $lg_options['blink_pg_legacy_logs_removed'], 'Nothing to delete still counts as done.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	lg_reset();
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
