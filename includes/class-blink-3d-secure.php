<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Blink_3D_Secure
 *
 * Handles the 3D Secure form submission and rendering process.
 *
 * @package Blink_Payment_Checkout
 */
class Blink_3D_Secure {

	/**
	 * Handles the 3D Secure form submission process.
	 *
	 * @return void
	 */
	public static function form_submission() {
		$process_key = isset( $_GET['blink3dprocess'] ) ? sanitize_text_field( wp_unslash( $_GET['blink3dprocess'] ) ) : '';

		if ( empty( $process_key ) ) {
			return;
		}

		$token = get_transient( 'blink3dProcess' . $process_key );

		echo '<div class="blink-3d-container">';
		echo $token ? wp_kses( self::render_secure_form( $token ), blink_3d_allow_html() ) : wp_kses( self::render_error_message(), blink_3d_allow_html() );
		echo '</div>';
	}

	/**
	 * Renders the 3D Secure form if the token is valid.
	 *
	 * @param string $token The 3D Secure form HTML content.
	 * @return string The rendered HTML content.
	 */
	private static function render_secure_form( $token ) {
		ob_start();
		?>
		<div class="blink-loading"><?php esc_html_e( 'Loading...', 'blink-payment-checkout' ); ?></div>
		<div class="blink-3d-content">
			<?php echo wp_kses( $token, blink_3d_allow_html() ); ?>
		</div>
		<script nonce="2020">
			jQuery(document).ready(function () {
				jQuery('#form3ds22').submit();
			});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the error message when no valid token is found.
	 *
	 * @return string The error message HTML.
	 */
	private static function render_error_message() {
		return '<div class="blink-error">' . esc_html__( 'Error: 3D Secure token not found.', 'blink-payment-checkout' ) . '</div>';
	}
}

add_action( 'wp_footer', array( 'Blink_3D_Secure', 'form_submission' ) );
