<?php
/**
 * The plugin must not change the site-wide HTTP timeout, and each of its own
 * requests must set a timeout so it doesn't fall back to WordPress's 5 seconds.
 */

define( 'ABSPATH', __DIR__ . '/' );

$tests_completed = false;
register_shutdown_function(
	function () use ( &$tests_completed ) {
		if ( ! $tests_completed ) {
			fwrite( STDERR, "FAIL: HTTP timeout tests did not complete (unexpected exit or fatal error).\n" );
			exit( 1 );
		}
	}
);

$ht_filters = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	global $ht_filters;
	$ht_filters[] = $hook;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	add_filter( $hook, $callback, $priority, $args );
}

require_once dirname( __DIR__ ) . '/includes/class-blink-pg-payment-utils.php';

function ht_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * The argument list of each wp_remote_post/wp_remote_get call in the plugin's PHP.
 *
 * @return array<string, string> Call arguments keyed by "file:line".
 */
function ht_remote_calls() {
	$calls = array();
	$files = array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/*.php' ) );
	foreach ( $files as $file ) {
		$tokens = token_get_all( file_get_contents( $file ) );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], array( 'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'wp_safe_remote_post', 'wp_safe_remote_get' ), true ) ) {
				continue;
			}
			$line  = $tokens[ $i ][2];
			$depth = 0;
			$args  = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				if ( '(' === $text ) {
					++$depth;
				} elseif ( ')' === $text && 0 === --$depth ) {
					break;
				}
				$args .= $text;
			}
			$calls[ basename( $file ) . ':' . $line ] = $args;
		}
	}
	return $calls;
}

$tests = array(
	'does not hook http_request_timeout' => function () {
		global $ht_filters;
		ht_assert( ! in_array( 'http_request_timeout', $ht_filters, true ), 'http_request_timeout is not filtered' );
	},
	'defines the Blink request timeouts' => function () {
		ht_assert( 30 === Blink_PG_Payment_Utils::API_TIMEOUT, 'API timeout is 30 seconds' );
		ht_assert( 45 === Blink_PG_Payment_Utils::TOKEN_TIMEOUT, 'Token timeout is 45 seconds' );
	},
	'every outbound request sets its own timeout' => function () {
		$calls = ht_remote_calls();
		ht_assert( count( $calls ) >= 15, 'Found the plugin\'s requests (' . count( $calls ) . ')' );
		$missing = array();
		foreach ( $calls as $where => $args ) {
			if ( false === strpos( $args, "'timeout'" ) ) {
				$missing[] = $where;
			}
		}
		ht_assert( array() === $missing, 'Requests without a timeout: ' . implode( ', ', $missing ) );
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
