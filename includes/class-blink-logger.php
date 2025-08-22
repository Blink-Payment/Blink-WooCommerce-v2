<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Blink_Logger {

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
     * @param array  $context
     */
    public static function log( $message, $context = array() ) {
        $plugin_debug_enabled = self::is_enabled();
        $wp_debug_enabled     = self::is_wp_debug_enabled();

        if ( ! $plugin_debug_enabled && ! $wp_debug_enabled ) {
            return;
        }

        $time = current_time( 'Y-m-d H:i:s' );

        // Redact sensitive values before logging anywhere
        if ( ! empty( $context ) ) {
            if ( isset( $context['secret_key'] ) ) {
                unset( $context['secret_key'] );
            }
            if ( isset( $context['api_key'] ) ) {
                unset( $context['api_key'] );
            }
            if ( isset( $context['paymentToken'] ) ) {
                unset( $context['paymentToken'] );
            }
            if ( isset( $context['paymenttoken'] ) ) {
                unset( $context['paymenttoken'] );
            }
        }

        $line = '[' . $time . '] ' . (string) $message . ( ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '' );

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
            wp_die( esc_html__( 'Unauthorized', 'blink-payment-checkout' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'blink-payment-checkout' ) );
        }

        check_admin_referer( 'blink_download_log' );

        $date     = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : current_time( 'Y-m-d' );
        $filepath = self::get_log_file_path( $date );

        if ( ! file_exists( $filepath ) ) {
            wp_die( esc_html__( 'Log file not found.', 'blink-payment-checkout' ) );
        }

        nocache_headers();
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename=' . basename( $filepath ) );
        header( 'Content-Length: ' . filesize( $filepath ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        readfile( $filepath );
        exit;
    }
}


