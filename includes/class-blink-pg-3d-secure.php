<?php
/**
 * 3D Secure challenge page and form handling.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Blink_PG_3D_Secure
 *
 * Handles the 3D Secure form submission and rendering process.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */
class Blink_PG_3D_Secure {

	const MINIMAL_PAGE_QUERY_VAR = 'blink_3ds_challenge';

	/**
	 * Register rewrite rule and query var for the minimal 3DS page.
	 */
	public static function register_endpoint() {
		add_rewrite_rule( '^blink-3ds-challenge/?$', 'index.php?' . self::MINIMAL_PAGE_QUERY_VAR . '=1', 'top' );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
	}

	/**
	 * Add the 3DS challenge page's query var to the public list.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = self::MINIMAL_PAGE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the minimal 3DS challenge page. Exits after output.
	 */
	public static function serve_minimal_3ds_page() {
		if ( ! get_query_var( self::MINIMAL_PAGE_QUERY_VAR ) ) {
			return;
		}

		self::send_no_cache_headers();

		// The page is reached from Blink's 3DS redirect, so there is no WordPress nonce.
		// blink_3d_nonce is a one-time token checked with hash_equals() below.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$process_key = isset( $_GET['blink_3d_process'] ) ? sanitize_text_field( wp_unslash( $_GET['blink_3d_process'] ) ) : '';
		if ( empty( $process_key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$challenge_token = isset( $_GET['blink_3d_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['blink_3d_nonce'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		$stored_token = get_transient( 'blink_pg_3d_challenge_token_' . $process_key );
		$tokens_match = function_exists( 'hash_equals' )
			? hash_equals( (string) $stored_token, (string) $challenge_token )
			: ( (string) $stored_token === (string) $challenge_token );

		if ( empty( $challenge_token ) || empty( $stored_token ) || ! $tokens_match ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$token = get_transient( 'blink_pg_3d_process_' . $process_key );
		self::output_minimal_3ds_page( $token );
		exit;
	}

	/**
	 * Mark the 3DS challenge route as non-cacheable for WordPress and upstream caches.
	 *
	 * @return void
	 */
	private static function send_no_cache_headers() {
		// Caching plugins read these exact names, so they cannot be prefixed.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Get path to the minimal 3DS challenge template (plugin only; no theme override).
	 *
	 * @return string
	 */
	public static function get_minimal_template_path() {
		return dirname( __DIR__ ) . '/templates/blink-3ds-challenge.php';
	}

	/**
	 * Output minimal HTML page by loading the plugin template.
	 *
	 * @param string|false $token Transient 3DS form HTML, or false if missing.
	 */
	public static function output_minimal_3ds_page( $token ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}
		$css_path = dirname( __DIR__ ) . '/assets/css/blink-3ds-challenge.css';
		wp_register_style(
			'blink-pg-3ds-challenge',
			dirname( plugin_dir_url( __FILE__ ) ) . '/assets/css/blink-3ds-challenge.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1'
		);
		load_template( self::get_minimal_template_path(), false, array( 'token' => $token ) );
	}
}
