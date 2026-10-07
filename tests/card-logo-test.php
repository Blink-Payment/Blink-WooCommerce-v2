<?php

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) {
	return $text;
}

function get_option( $key, $default = false ) {
	return $default;
}

function plugins_url( $path, $file = '' ) {
	return 'https://shop.example/wp-content/plugins/plugin-woocommerce/includes/' . ltrim( $path, '/' );
}

function plugin_dir_url( $file ) {
	return 'https://shop.example/wp-content/plugins/plugin-woocommerce/includes/';
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function absint( $value ) {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-settings-handler.php';

function blink_assert_card_logos( $expected_ids, $settings, $message ) {
	$actual_ids = array_column( blink_get_card_logos( $settings ), 'id' );
	if ( $expected_ids !== $actual_ids ) {
		throw new RuntimeException( $message . ': got ' . var_export( $actual_ids, true ) );
	}
}

$tests = array(
	'gateway settings use independently persisted checkboxes defaulting off' => function () {
		$fields = ( new Blink_Settings_Handler( '', '' ) )->blink_get_form_fields();
		foreach ( array( 'card_logo_visa', 'card_logo_mastercard', 'card_logo_american_express' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) || 'checkbox' !== $fields[ $key ]['type'] || 'no' !== $fields[ $key ]['default'] ) {
				throw new RuntimeException( $key . ' is not a standard checkbox defaulting to no' );
			}
		}
	},
	'no stored settings shows no logos' => function () {
		blink_assert_card_logos( array(), array(), 'Missing settings must preserve the existing presentation' );
	},
	'all brands disabled shows no logos' => function () {
		blink_assert_card_logos(
			array(),
			array(
				'card_logo_visa'             => 'no',
				'card_logo_mastercard'       => 'no',
				'card_logo_american_express' => 'no',
			),
			'Disabled brands must not be returned'
		);
	},
	'each brand can be enabled independently' => function () {
		blink_assert_card_logos( array( 'visa' ), array( 'card_logo_visa' => 'yes' ), 'Visa selection' );
		blink_assert_card_logos( array( 'mastercard' ), array( 'card_logo_mastercard' => 'yes' ), 'Mastercard selection' );
		blink_assert_card_logos( array( 'american_express' ), array( 'card_logo_american_express' => 'yes' ), 'American Express selection' );
	},
	'multiple brands retain catalogue order' => function () {
		blink_assert_card_logos(
			array( 'visa', 'american_express' ),
			array( 'card_logo_american_express' => 'yes', 'card_logo_visa' => 'yes' ),
			'Multiple selection'
		);
	},
	'all brands can be enabled' => function () {
		$settings = array(
			'card_logo_visa'             => 'yes',
			'card_logo_mastercard'       => 'yes',
			'card_logo_american_express' => 'yes',
		);
		blink_assert_card_logos(
			array( 'visa', 'mastercard', 'american_express' ),
			$settings,
			'All-brand selection'
		);

		$logos = blink_get_card_logos( $settings );
		if (
			array( 'Visa', 'Mastercard', 'American Express' ) !== array_column( $logos, 'label' ) ||
			array( 36, 36, 37 ) !== array_column( $logos, 'width' ) ||
			array( 24, 24, 24 ) !== array_column( $logos, 'height' )
		) {
			throw new RuntimeException( 'Card metadata did not match the fixed catalogue' );
		}
		foreach ( $logos as $logo ) {
			if ( false === strpos( $logo['url'], '/assets/img/' ) ) {
				throw new RuntimeException( 'Card logo did not use the local asset path' );
			}
		}
	},
	'unknown and malformed values are ignored' => function () {
		blink_assert_card_logos(
			array(),
			array(
				'card_logo_visa'       => true,
				'card_logo_mastercard' => '<img src=x>',
				'card_logo_unknown'    => 'yes',
			),
			'Only strict yes values from the fixed catalogue are allowed'
		);
	},
	'classic output uses local assets and accessible labels' => function () {
		$html = blink_get_card_logos_html(
			array(
				'card_logo_visa'             => 'yes',
				'card_logo_american_express' => 'yes',
			)
		);
		if ( false === strpos( $html, 'assets/img/visa.svg' ) || false === strpos( $html, 'alt="Visa"' ) ) {
			throw new RuntimeException( 'Classic Visa markup is incomplete' );
		}
		if ( false === strpos( $html, 'assets/img/american-express.svg' ) || false === strpos( $html, 'alt="American Express"' ) ) {
			throw new RuntimeException( 'Classic American Express markup is incomplete' );
		}
		if ( false !== strpos( $html, 'mastercard.svg' ) ) {
			throw new RuntimeException( 'Classic output included an unselected brand' );
		}
	},
	'direct and hosted modes do not affect selection' => function () {
		$direct = blink_get_card_logos( array( 'integration_type' => 'direct', 'card_logo_mastercard' => 'yes' ) );
		$hosted = blink_get_card_logos( array( 'integration_type' => 'hosted', 'card_logo_mastercard' => 'yes' ) );
		if ( $direct !== $hosted ) {
			throw new RuntimeException( 'Integration type changed the logo data' );
		}
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
