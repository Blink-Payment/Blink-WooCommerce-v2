<?php

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/helper.php';

function blink_assert_apple_pay_availability( $expected, $enabled, $elements, $message ) {
	$actual = blink_should_render_apple_pay( $enabled, $elements );
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

function blink_assert_wallet_availability( $expected, $enabled, $elements, $message ) {
	$actual = blink_get_wallet_availability( $enabled, $elements );
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

$tests = array(
	'eligible Blink element is rendered when enabled' => function () {
		blink_assert_apple_pay_availability( true, true, array( 'apElement' => '<div id="blinkApplePay"></div>' ), 'Expected Apple Pay to render.' );
	},
	'missing Blink element is not rendered' => function () {
		blink_assert_apple_pay_availability( false, true, array(), 'Expected missing Apple Pay element not to render.' );
	},
	'disabled Apple Pay is not rendered' => function () {
		blink_assert_apple_pay_availability( false, false, array( 'apElement' => '<div id="blinkApplePay"></div>' ), 'Expected disabled Apple Pay not to render.' );
	},
	'both Blink wallet elements are rendered together' => function () {
		blink_assert_wallet_availability(
			array( 'showApplePay' => true, 'showGooglePay' => true, 'showWalletRow' => true ),
			true,
			array( 'apElement' => '<div></div>', 'gpElement' => '<div></div>' ),
			'Expected both Blink wallets to render.'
		);
	},
	'Safari user agent does not suppress Google Pay' => function () {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Version/18.0 Safari/605.1.15';
		blink_assert_wallet_availability(
			array( 'showApplePay' => true, 'showGooglePay' => true, 'showWalletRow' => true ),
			true,
			array( 'apElement' => '<div></div>', 'gpElement' => '<div></div>' ),
			'Expected both wallets regardless of Safari user agent.'
		);
		unset( $_SERVER['HTTP_USER_AGENT'] );
	},
	'Apple Pay-only element renders a wallet row' => function () {
		blink_assert_wallet_availability(
			array( 'showApplePay' => true, 'showGooglePay' => false, 'showWalletRow' => true ),
			true,
			array( 'apElement' => '<div></div>' ),
			'Expected an Apple Pay-only wallet row.'
		);
	},
	'Google Pay-only element renders a wallet row' => function () {
		blink_assert_wallet_availability(
			array( 'showApplePay' => false, 'showGooglePay' => true, 'showWalletRow' => true ),
			true,
			array( 'gpElement' => '<div></div>' ),
			'Expected a Google Pay-only wallet row.'
		);
	},
	'disabled Apple Pay leaves Google Pay available' => function () {
		blink_assert_wallet_availability(
			array( 'showApplePay' => false, 'showGooglePay' => true, 'showWalletRow' => true ),
			false,
			array( 'apElement' => '<div></div>', 'gpElement' => '<div></div>' ),
			'Expected only Google Pay when Apple Pay is disabled.'
		);
	},
	'no wallet elements renders no wallet row' => function () {
		blink_assert_wallet_availability(
			array( 'showApplePay' => false, 'showGooglePay' => false, 'showWalletRow' => false ),
			true,
			array(),
			'Expected no wallet row.'
		);
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
