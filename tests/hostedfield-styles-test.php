<?php

define( 'ABSPATH', __DIR__ . '/' );

$test_filters = array();

class WC_Payment_Gateway {
}

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function add_filter( $hook, $callback ) {
	global $test_filters;
	$test_filters[ $hook ][] = $callback;
}

function apply_filters( $hook, $value ) {
	global $test_filters;
	foreach ( isset( $test_filters[ $hook ] ) ? $test_filters[ $hook ] : array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	return trim( strip_tags( $text ) );
}

require_once dirname( __DIR__ ) . '/includes/helper.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-payment-gateway.php';

function blink_hf_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function blink_hf_print() {
	$reflection = new ReflectionClass( 'Blink_Payment_Gateway' );
	$gateway    = $reflection->newInstanceWithoutConstructor();
	ob_start();
	$gateway->blink_print_hostedfield_styles();
	return ob_get_clean();
}

// Declarations of the first rule in $css whose selector is exactly $selector.
function blink_hf_rule( $css, $selector ) {
	if ( preg_match( '/(?:^|\}|\*\/)\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $match ) ) {
		return $match[1];
	}
	return null;
}

$tests = array(
	'prints hostedfields.css in the style tag the SDK reads' => function () {
		$GLOBALS['test_filters'] = array();
		$output                  = blink_hf_print();
		blink_hf_assert( 0 === strpos( $output, '<style class="hostedfield">' ), 'Output starts with <style class="hostedfield">' );
		blink_hf_assert( false !== strpos( $output, '.hostedfield-frame' ), 'Structural iframe rule is printed' );
	},
	'iframe text uses system fonts at 16px' => function () {
		$GLOBALS['test_filters'] = array();
		$rule                    = blink_hf_rule( blink_hf_print(), '.hostedfield' );
		blink_hf_assert( null !== $rule, '.hostedfield rule exists' );
		blink_hf_assert( false !== strpos( $rule, '-apple-system' ), '.hostedfield uses the system font stack' );
		blink_hf_assert( false === stripos( $rule, 'Cabin' ), '.hostedfield does not ask for Cabin, which the iframe cannot load' );
		blink_hf_assert( false !== strpos( $rule, 'font-size: 16px' ), '.hostedfield text is 16px' );
	},
	'printed CSS leaves the field box to style.css' => function () {
		$GLOBALS['test_filters'] = array();
		$rule                    = blink_hf_rule( blink_hf_print(), '.hostedfield-wrapper' );
		blink_hf_assert( null !== $rule, '.hostedfield-wrapper rule exists' );
		preg_match_all( '/(?:^|;)\s*([a-z-]+)\s*:/', $rule, $matches );
		foreach ( $matches[1] as $property ) {
			blink_hf_assert( ! preg_match( '/^(border|padding|display|background)|radius/', $property ), ".hostedfield-wrapper must not set {$property}; it prints after style.css and would override it" );
		}
		blink_hf_assert( in_array( 'overflow', $matches[1], true ), 'Declarations were parsed' );
	},
	'blink_hostedfield_css can add rules' => function () {
		$GLOBALS['test_filters'] = array();
		add_filter(
			'blink_hostedfield_css',
			function ( $css ) {
				return $css . "\n.hostedfield { color: #222222; }";
			}
		);
		blink_hf_assert( false !== strpos( blink_hf_print(), '.hostedfield { color: #222222; }' ), 'Filtered rule is printed' );
	},
	'empty or non-string filter result prints nothing' => function () {
		foreach ( array( '', '   ', null, false ) as $value ) {
			$GLOBALS['test_filters'] = array();
			add_filter(
				'blink_hostedfield_css',
				function () use ( $value ) {
					return $value;
				}
			);
			blink_hf_assert( '' === blink_hf_print(), 'No style tag for ' . var_export( $value, true ) );
		}
	},
	'filtered CSS cannot close the style tag' => function () {
		$GLOBALS['test_filters'] = array();
		add_filter(
			'blink_hostedfield_css',
			function ( $css ) {
				return $css . '</style><script>alert(1)</script>';
			}
		);
		$output = blink_hf_print();
		blink_hf_assert( false === stripos( $output, '<script' ), 'Script tag is stripped' );
		blink_hf_assert( 1 === substr_count( $output, '</style>' ), 'Only the plugin closes the style tag' );
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
