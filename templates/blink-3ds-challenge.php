<?php
/**
 * Minimal 3DS challenge page template.
 *
 * Loaded by Blink_PG_3D_Secure::output_minimal_3ds_page(), which has already
 * sent the no-cache headers and registered the page's stylesheet.
 * $args['token'] (string|false) is the 3DS form HTML, or false if missing.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blink_pg_3ds_token = isset( $args['token'] ) ? $args['token'] : false;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Complete verification', 'blink-payment-gateway-for-woocommerce' ); ?></title>
	<?php wp_print_styles( 'blink-pg-3ds-challenge' ); ?>
</head>
<body>
<?php if ( $blink_pg_3ds_token ) : ?>
	<div class="blink-3d-container">
		<div class="blink-loading"><?php esc_html_e( 'Processing...', 'blink-payment-gateway-for-woocommerce' ); ?></div>
		<div class="blink-3d-content"><?php echo wp_kses( $blink_pg_3ds_token, blink_pg_3d_allow_html() ); ?></div>
	</div>
	<?php
	wp_print_inline_script_tag(
		"(function () {
			var form = document.getElementById('form3ds22') || document.getElementById('form3ds');
			if (form) {
				form.submit();
			}
		})();"
	);
	?>
<?php else : ?>
	<div class="blink-error"><?php esc_html_e( 'Error: 3D Secure token not found.', 'blink-payment-gateway-for-woocommerce' ); ?></div>
	<p><a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Return to checkout', 'blink-payment-gateway-for-woocommerce' ); ?></a></p>
<?php endif; ?>
</body>
</html>