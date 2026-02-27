<?php
/**
 * Minimal 3DS challenge page template.
 *
 * Used when redirecting for 3D Secure.
 * Variables: $blink_3ds_token (string|false) - 3DS form HTML or false if missing.
 *
 * @package Blink_Payment_Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Complete verification', 'blink-payment-gateway-for-woocommerce' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $blink_3ds_challenge_css_url ); ?>?ver=<?php echo esc_attr( $blink_3ds_challenge_css_version ); ?>" type="text/css" media="all">
</head>
<body>
<?php if ( $blink_3ds_token ) : ?>
	<div class="blink-3d-container">
		<div class="blink-loading"><?php esc_html_e( 'Processing...', 'blink-payment-gateway-for-woocommerce' ); ?></div>
		<div class="blink-3d-content"><?php echo wp_kses( $blink_3ds_token, blink_3d_allow_html() ); ?></div>
	</div>
	<script>var f=document.getElementById('form3ds22');if(f)f.submit();</script>
<?php else : ?>
	<div class="blink-error"><?php esc_html_e( 'Error: 3D Secure token not found.', 'blink-payment-gateway-for-woocommerce' ); ?></div>
	<p><a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Return to checkout', 'blink-payment-gateway-for-woocommerce' ); ?></a></p>
<?php endif; ?>
</body>
</html>
