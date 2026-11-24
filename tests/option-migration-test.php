<?php

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function( function () use ( &$tests_completed ) {
	if ( ! $tests_completed ) {
		fwrite( STDERR, "FAIL: option migration tests did not complete (unexpected exit or fatal error).\n" );
		exit( 1 );
	}
} );

$om_options = array();

function get_option( $name, $default = false ) {
	global $om_options;
	return array_key_exists( $name, $om_options ) ? $om_options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	global $om_options;
	$om_options[ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	global $om_options;
	unset( $om_options[ $name ] );
	return true;
}

require_once dirname( __DIR__ ) . '/includes/helper.php';

function om_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ' Expected: ' . var_export( $expected, true ) . ' Actual: ' . var_export( $actual, true ) );
	}
}

$tests = array();

$tests['a 1.4.1 admin token and Apple Pay verification move to the new names'] = function () {
	global $om_options;
	$token      = array( 'access_token' => 'tok', 'payment_types' => array( 'credit-card' ) );
	$om_options = array(
		'blink_admin_token'       => $token,
		'blink_apple_domain_auth' => 1,
	);
	blink_pg_migrate_options();
	om_assert(
		array(
			'blink_pg_admin_token'       => $token,
			'blink_pg_apple_domain_auth' => 1,
		),
		$om_options,
		'Values are under the new names and the old keys are gone.'
	);
};

$tests['a value already saved under the new name is kept'] = function () {
	global $om_options;
	$om_options = array(
		'blink_admin_token'    => array( 'access_token' => 'old' ),
		'blink_pg_admin_token' => array( 'access_token' => 'new' ),
	);
	blink_pg_migrate_options();
	om_assert( array( 'blink_pg_admin_token' => array( 'access_token' => 'new' ) ), $om_options, 'The newer value wins and the old key is removed.' );
};

$tests['nothing changes for a new install or once migrated'] = function () {
	global $om_options;
	$om_options = array( 'blink_pg_apple_domain_auth' => 1 );
	blink_pg_migrate_options();
	om_assert( array( 'blink_pg_apple_domain_auth' => 1 ), $om_options, 'Options are untouched.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
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
