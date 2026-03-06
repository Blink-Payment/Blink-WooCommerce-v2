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

if ( ! defined( 'DONOTCACHEPAGE' ) ) {
    define( 'DONOTCACHEPAGE', true );
}
if ( ! defined( 'DONOTCACHEDB' ) ) {
    define( 'DONOTCACHEDB', true );
}
if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
    define( 'DONOTCACHEOBJECT', true );
}

if ( ! headers_sent() ) {
    nocache_headers();
    header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
    header( 'X-Robots-Tag: noindex, nofollow', true );
}

$blink_3ds_token                 = get_query_var( 'blink_3ds_token', false );
$blink_3ds_challenge_css_url     = get_query_var( 'blink_3ds_challenge_css_url', '' );
$blink_3ds_challenge_css_version = get_query_var( 'blink_3ds_challenge_css_version', '1' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e( 'Complete verification', 'blink-payment-gateway-for-woocommerce' ); ?></title>
    <?php if ( $blink_3ds_challenge_css_url ) : ?>
        <link rel="stylesheet" href="<?php echo esc_url( add_query_arg( 'ver', $blink_3ds_challenge_css_version, $blink_3ds_challenge_css_url ) ); ?>" type="text/css" media="all">
    <?php endif; ?>
</head>
<body>
<?php if ( $blink_3ds_token ) : ?>
    <div class="blink-3d-container">
        <div class="blink-loading"><?php esc_html_e( 'Processing...', 'blink-payment-gateway-for-woocommerce' ); ?></div>
        <div class="blink-3d-content"><?php echo wp_kses( $blink_3ds_token, blink_3d_allow_html() ); ?></div>
    </div>
    <script>
        (function () {
            var form = document.getElementById('form3ds22') || document.getElementById('form3ds');
            if (form) {
                form.submit();
            }
        })();
    </script>
<?php else : ?>
    <div class="blink-error"><?php esc_html_e( 'Error: 3D Secure token not found.', 'blink-payment-gateway-for-woocommerce' ); ?></div>
    <p><a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Return to checkout', 'blink-payment-gateway-for-woocommerce' ); ?></a></p>
<?php endif; ?>
</body>
</html>