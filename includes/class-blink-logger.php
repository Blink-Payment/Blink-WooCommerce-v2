<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log	
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Blink_Logger {
    /**
     * Order identifier discovered during current PHP request.
     *
     * @var string
     */
    private static $order_id = '';

    /**
     * Email identifier discovered during current PHP request.
     *
     * @var string
     */
    private static $email = '';

    /**
     * Check if debug mode is enabled in plugin settings.
     */
    public static function is_enabled() {
        $settings = get_option( 'woocommerce_blink_settings', array() );
        return isset( $settings['debug_mode'] ) && $settings['debug_mode'] === 'yes';
    }

    /**
     * Check if WordPress debug logging is enabled via wp-config.
     */
    public static function is_wp_debug_enabled() {
        return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
    }

    /**
     * Get the directory where logs are stored, ensure it exists.
     */
    public static function get_logs_dir() {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . 'blink-logs';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    /**
     * Get the current log file path.
     */
    public static function get_log_file_path( $date = null ) {
        if ( empty( $date ) ) {
            $date = current_time( 'Y-m-d' );
        }
        $filename = 'blink-' . $date . '.log';
        return trailingslashit( self::get_logs_dir() ) . $filename;
    }

    /**
     * Write a line to the log if debug is enabled.
     *
     * @param string $message
     * @param mixed  $context
     */
    public static function log( $message, $context = array() ) {
        $plugin_debug_enabled = self::is_enabled();
        $wp_debug_enabled     = self::is_wp_debug_enabled();

        if ( ! $plugin_debug_enabled && ! $wp_debug_enabled ) {
            return;
        }

        $time = current_time( 'Y-m-d H:i:s' );
        self::set_context( $context );
        self::capture_identifier_from_request();

        $identifier      = self::get_prefix_identifier();
        $message_with_fn = self::with_caller_method_prefix( $message );
        $suffix          = self::format_log_context( $context );
        $line            = '[' . $time . '] ' . $identifier . ' ' . $message_with_fn . $suffix;

        // Write to plugin log file if plugin debug is enabled
        if ( $plugin_debug_enabled ) {
            $path = self::get_log_file_path();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            @file_put_contents( $path, $line . PHP_EOL, FILE_APPEND );
        }

        // Also mirror to WordPress debug log if enabled in wp-config
        if ( $wp_debug_enabled ) {
            if ( function_exists( 'blink_write_log' ) ) {
                blink_write_log( $line );
            } else {
                // Fallback to error_log to avoid missing logs
                error_log( $line );
            }
        }
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

        $email = self::extract_email( $context );
        if ( '' !== $email ) {
            self::$email = $email;
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
     * Remove sensitive values recursively before writing logs.
     *
     * @param array $context Context array.
     * @return array
     */
    private static function redact_context( $context ) {
        $sensitive_keys = array(
            'secret_key',
            'api_key',
            'paymenttoken',
            'paymentToken',
            'access_token',
        );

        foreach ( $context as $key => $value ) {
            if ( is_string( $key ) && in_array( $key, $sensitive_keys, true ) ) {
                unset( $context[ $key ] );
                continue;
            }

            if ( is_array( $value ) ) {
                $context[ $key ] = self::redact_context( $value );
            }
        }

        return $context;
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
            $reference = self::find_value_by_keys( $context, array( 'reference', 'transaction_unique' ) );
            if ( preg_match( '/WC-(\d+)/', $reference, $matches ) ) {
                $order_id = $matches[1];
            }
        }

        return $order_id;
    }

    /**
     * Extract email identifier from context data.
     *
     * @param mixed $context Input context.
     * @return string
     */
    private static function extract_email( $context ) {
        if ( is_object( $context ) ) {
            $context = (array) $context;
        }

        if ( ! is_array( $context ) ) {
            return '';
        }

        $email = self::find_value_by_keys( $context, array( 'customer_email', 'billing_email', 'email', 'user_email' ) );
        if ( '' === $email ) {
            return '';
        }

        $email = sanitize_email( $email );
        return ! empty( $email ) ? $email : '';
    }

    /**
     * Capture identifiers from request payload.
     *
     * @return void
     */
    private static function capture_identifier_from_request() {
        if ( empty( $_REQUEST ) || ! is_array( $_REQUEST ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $request = wp_unslash( $_REQUEST );
        self::set_context( $request );
    }

    /**
     * Build final prefix identifier.
     *
     * @return string
     */
    private static function get_prefix_identifier() {
        if ( ! empty( self::$email ) && ! empty( self::$order_id ) ) {
            return self::$email . ' (' . self::$order_id . ')';
        }

        if ( ! empty( self::$order_id ) ) {
            return self::$order_id;
        }

        if ( ! empty( self::$email ) ) {
            return self::$email;
        }

        return '0';
    }

    /**
     * Build standard HTTP response context for logs.
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

        if ( JSON_ERROR_NONE === json_last_error() ) {
            $context['body'] = $decoded;
            return $context;
        }

        $context['body'] = $body;
        return $context;
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

        $value = sanitize_text_field( wp_unslash( (string) $value ) );
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
     * Build a secure download URL for the current log file.
     */
    public static function get_download_url() {
        $nonce = wp_create_nonce( 'blink_download_log' );
        return add_query_arg(
            array(
                'action'   => 'blink_download_log',
                '_wpnonce' => $nonce,
                'date'     => current_time( 'Y-m-d' ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Handle admin-post download of the debug log.
     */
    public static function handle_download() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Unauthorized', 'blink-payment-gateway-for-woocommerce' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'blink-payment-gateway-for-woocommerce' ) );
        }

        check_admin_referer( 'blink_download_log' );

        $date     = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : current_time( 'Y-m-d' );
        $filepath = self::get_log_file_path( $date );

        if ( ! file_exists( $filepath ) ) {
            wp_die( esc_html__( 'Log file not found.', 'blink-payment-gateway-for-woocommerce' ) );
        }

        nocache_headers();
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . basename( $filepath ) );
        header( 'Content-Length: ' . filesize( $filepath ) );

        $file_content = false;
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( is_object( $wp_filesystem ) && $wp_filesystem->exists( $filepath ) ) {
            $file_content = $wp_filesystem->get_contents( $filepath );
        }

        if ( false === $file_content ) {
            // Fallback when WP_Filesystem is unavailable (common on local/dev setups).
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $file_content = @file_get_contents( $filepath );
        }

        if ( false === $file_content ) {
            wp_die( esc_html__( 'Unable to read log file.', 'blink-payment-gateway-for-woocommerce' ) );
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $file_content;
        exit;
    }
}
