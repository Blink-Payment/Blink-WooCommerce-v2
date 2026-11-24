<?php
/**
 * Debug logging with customer details redacted.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Debug logging through WooCommerce's logger (WooCommerce › Status › Logs).
 *
 * Lines are tagged with the order ID only. Customer details are never logged:
 * Blink API responses are reduced to RESPONSE_KEYS, and context keys that name
 * personal or secret data are removed.
 */
class Blink_PG_Logger {

	/**
	 * WooCommerce log source.
	 */
	const SOURCE = 'blink-payment-gateway';

	/**
	 * Set once the log files older versions wrote to uploads/blink-logs are gone.
	 */
	const LEGACY_LOGS_OPTION = 'blink_pg_legacy_logs_removed';

	/**
	 * Blink API response fields worth logging. Everything else is dropped.
	 */
	const RESPONSE_KEYS = array(
		'success',
		'message',
		'error',
		'errors',
		'status',
		'code',
		'id',
		'transaction_id',
		'paylink_id',
		'intent_id',
		'expiry_date',
		'transaction_type',
		'payment_source',
		'payment_type',
		'event_type',
		'amount',
		'currency',
		'reference',
	);

	/**
	 * Context keys removed before logging: secrets, and fragments of key names
	 * that hold customer or card details.
	 */
	const SECRET_KEYS            = array( 'secret_key', 'api_key', 'paymenttoken', 'access_token' );
	const PERSONAL_KEY_FRAGMENTS = array( 'email', 'name', 'phone', 'address', 'postcode', 'post_code', 'zip', 'billing', 'shipping', 'payer', 'card', 'cvv', 'iban', 'account_number', 'sort_code' );

	/**
	 * Order identifier discovered during current PHP request.
	 *
	 * @var string
	 */
	private static $order_id = '';

	/**
	 * Check if debug mode is enabled in plugin settings.
	 */
	public static function is_enabled() {
		$settings = get_option( 'woocommerce_blink_settings', array() );
		return isset( $settings['debug_mode'] ) && 'yes' === $settings['debug_mode'];
	}

	/**
	 * Check if WordPress debug logging is enabled via wp-config.
	 */
	public static function is_wp_debug_enabled() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
	}

	/**
	 * Write a line to the WooCommerce log when the plugin's debug mode or
	 * WordPress debugging is on.
	 *
	 * @param string $message Message to log.
	 * @param mixed  $context Extra data appended as JSON after redaction.
	 */
	public static function log( $message, $context = array() ) {
		if ( ! self::is_enabled() && ! self::is_wp_debug_enabled() ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		self::set_context( $context );
		self::capture_identifier_from_request();

		$line = self::get_prefix_identifier() . ' ' . self::with_caller_method_prefix( $message ) . self::format_log_context( $context );
		wc_get_logger()->info( $line, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Manually set order context for current request.
	 *
	 * @param array|object $context Source context.
	 * @return void
	 */
	public static function set_context( $context ) {
		$order_id = self::extract_order_id( $context );
		if ( '' !== $order_id ) {
			self::$order_id = $order_id;
		}
	}

	/**
	 * Prepare log context for output.
	 *
	 * @param mixed $context Context passed to logger.
	 * @return string
	 */
	private static function format_log_context( $context ) {
		if ( empty( $context ) && 0 !== $context && '0' !== $context ) {
			return '';
		}

		if ( is_object( $context ) ) {
			$context = (array) $context;
		}

		if ( is_array( $context ) ) {
			$context = self::redact_context( $context );
			return ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		}

		return ' ' . wp_json_encode( $context );
	}

	/**
	 * Remove secrets and customer details recursively before writing logs.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	private static function redact_context( $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && self::is_redacted_key( $key ) ) {
				unset( $context[ $key ] );
				continue;
			}

			if ( is_object( $value ) ) {
				$value = (array) $value;
			}
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			}
		}

		return $context;
	}

	/**
	 * Check whether a context key names secret or personal data.
	 *
	 * @param string $key Context key.
	 * @return bool
	 */
	private static function is_redacted_key( $key ) {
		$key = strtolower( $key );
		if ( in_array( $key, self::SECRET_KEYS, true ) ) {
			return true;
		}
		foreach ( self::PERSONAL_KEY_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract order identifier from context data.
	 *
	 * @param mixed $context Input context.
	 * @return string
	 */
	private static function extract_order_id( $context ) {
		if ( is_object( $context ) ) {
			$context = (array) $context;
		}

		if ( ! is_array( $context ) ) {
			return '';
		}

		$order_id = self::find_value_by_keys( $context, array( 'order_id', 'blink_3d_process' ) );
		if ( '' === $order_id ) {
			// normalize_order_value() has already reduced "WC-123" to "123".
			$reference = self::find_value_by_keys( $context, array( 'reference', 'transaction_unique' ) );
			if ( ctype_digit( $reference ) ) {
				$order_id = $reference;
			}
		}

		return $order_id;
	}

	/**
	 * Tag the line with the order the current request is about, when the request
	 * names one. Only an order ID is read, and nothing from the request is logged.
	 *
	 * @return void
	 */
	private static function capture_identifier_from_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup of an order ID for the log tag.
		if ( empty( $_REQUEST ) || ! is_array( $_REQUEST ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only lookup; extract_order_id() sanitises the one value it keeps.
		self::set_context( wp_unslash( $_REQUEST ) );
	}

	/**
	 * Build final prefix identifier.
	 *
	 * @return string
	 */
	private static function get_prefix_identifier() {
		return '' !== self::$order_id ? self::$order_id : '0';
	}

	/**
	 * Build standard HTTP response context for logs: the status code and the
	 * RESPONSE_KEYS fields of a JSON body.
	 *
	 * @param mixed $response Result from wp_remote_get/wp_remote_post.
	 * @return array
	 */
	public static function http_response_context( $response ) {
		$context = array(
			'code' => wp_remote_retrieve_response_code( $response ),
			'body' => '',
		);

		if ( is_wp_error( $response ) ) {
			$context['body'] = array(
				'error' => $response->get_error_message(),
			);
			return $context;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			$context['body'] = self::response_summary( $decoded );
			return $context;
		}

		$context['body'] = '' === $body ? '' : sprintf( '[non-JSON body, %d bytes]', strlen( $body ) );
		return $context;
	}

	/**
	 * Keep only RESPONSE_KEYS from a decoded Blink response, looking inside
	 * 'data' and lists.
	 *
	 * @param array $body Decoded response.
	 * @return array
	 */
	private static function response_summary( $body ) {
		$summary = array();
		foreach ( $body as $key => $value ) {
			if ( is_array( $value ) && ( 'data' === $key || is_int( $key ) ) ) {
				$summary[ $key ] = self::response_summary( $value );
			} elseif ( is_string( $key ) && in_array( $key, self::RESPONSE_KEYS, true ) ) {
				$summary[ $key ] = ( is_array( $value ) && 'errors' !== $key ) ? self::response_summary( $value ) : $value;
			}
		}
		return $summary;
	}

	/**
	 * Prefix the log message with caller method name.
	 *
	 * @param mixed $message Message content.
	 * @return string
	 */
	private static function with_caller_method_prefix( $message ) {
		$message      = (string) $message;
		$method_label = self::get_caller_method_label();

		if ( empty( $method_label ) ) {
			return $message;
		}

		// Avoid duplicates when message already starts with the same method label.
		if ( 0 === strpos( $message, $method_label ) ) {
			return $message;
		}

		return $method_label . ' ' . $message;
	}

	/**
	 * Resolve caller method/function label from stack.
	 *
	 * @return string
	 */
	private static function get_caller_method_label() {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Names the calling function in the log line.
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 );
		foreach ( $trace as $frame ) {
			$function = isset( $frame['function'] ) ? (string) $frame['function'] : '';
			$class    = isset( $frame['class'] ) ? (string) $frame['class'] : '';

			if ( '' === $function ) {
				continue;
			}

			if ( __CLASS__ === $class ) {
				continue;
			}

			if ( in_array( $function, array( 'call_user_func', 'call_user_func_array' ), true ) ) {
				continue;
			}

			return $function . '()';
		}

		return '';
	}

	/**
	 * Find first scalar value in a context array by key (recursive).
	 *
	 * @param array $context Context array.
	 * @param array $keys    Candidate key names.
	 * @return string
	 */
	private static function find_value_by_keys( $context, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$value = self::normalize_order_value( $context[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		foreach ( $context as $value ) {
			if ( is_object( $value ) ) {
				$value = (array) $value;
			}
			if ( is_array( $value ) ) {
				$found = self::find_value_by_keys( $value, $keys );
				if ( '' !== $found ) {
					return $found;
				}
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( self::looks_like_json( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$found = self::find_value_by_keys( $decoded, $keys );
					if ( '' !== $found ) {
						return $found;
					}
				}
			}

			if ( self::looks_like_query_string( $value ) ) {
				$parsed = array();
				parse_str( $value, $parsed );
				if ( is_array( $parsed ) ) {
					$found = self::find_value_by_keys( $parsed, $keys );
					if ( '' !== $found ) {
						return $found;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Normalize order-related values to a safe string.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string
	 */
	private static function normalize_order_value( $value ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || 'null' === strtolower( $value ) ) {
			return '';
		}

		if ( preg_match( '/WC-(\d+)/', $value, $matches ) ) {
			return $matches[1];
		}

		return $value;
	}

	/**
	 * Determine whether a string appears to be JSON.
	 *
	 * @param string $value Candidate string.
	 * @return bool
	 */
	private static function looks_like_json( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		return ( '{' === $value[0] && '}' === substr( $value, -1 ) )
			|| ( '[' === $value[0] && ']' === substr( $value, -1 ) );
	}

	/**
	 * Determine whether a string appears to be a query string.
	 *
	 * @param string $value Candidate string.
	 * @return bool
	 */
	private static function looks_like_query_string( $value ) {
		return false !== strpos( $value, '=' ) && false !== strpos( $value, '&' );
	}

	/**
	 * Where an admin reads the plugin's logs: WooCommerce › Status › Logs,
	 * filtered to this source.
	 *
	 * @return string
	 */
	public static function get_logs_url() {
		return add_query_arg(
			array(
				'page'   => 'wc-status',
				'tab'    => 'logs',
				'source' => self::SOURCE,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Delete the log files older versions wrote to uploads/blink-logs.
	 * They held customer details and could be fetched over HTTP. Runs once; if the folder
	 * cannot be removed, a warning is logged for the merchant.
	 *
	 * @return void
	 */
	public static function maybe_remove_legacy_logs() {
		if ( get_option( self::LEGACY_LOGS_OPTION ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'blink-logs';

		if ( is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $name ) {
				$file = trailingslashit( $dir ) . $name;
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			// The files were just deleted as the PHP user, so the direct method can
			// remove the folder. WP_Filesystem() could pick FTP/SSH here and leave an
			// unconnected object that throws when used.
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$filesystem = new WP_Filesystem_Direct( null );
			$filesystem->rmdir( $dir );

			// Do not retry on every request: if the host blocks the delete, a later
			// request will not do better. Tell the merchant instead.
			if ( is_dir( $dir ) ) {
				wc_get_logger()->warning(
					'Old Blink log folder could not be fully deleted. Please delete ' . $dir . ' manually; it may contain customer details.',
					array( 'source' => self::SOURCE )
				);
			}
		}

		update_option( self::LEGACY_LOGS_OPTION, 1, true );
	}
}
