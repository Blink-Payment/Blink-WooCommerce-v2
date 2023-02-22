<?php
/*
 * Plugin Name: WooCommerce - Blink
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card and direct debit payments on your store.
 * Author: Blink Payment
 * Author URI: https://blinkpayment.co.uk/
 * Version: 1.0.3
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
function blink_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Blink_Gateway'; // your class name is here
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'blink_init_gateway_class' );
add_action( 'the_content', 'blink_3d_form_submission' );
add_action( 'the_content', 'checkBlinkPaymentMethod' );
add_action( 'init', 'checkFromSubmission' );

function checkOrderPayment($order_id)
{
    $order = wc_get_order($order_id);
    if ( ! $order->needs_payment() ) {
        wc_add_notice(  'Something Wrong! Please initate the payment from checkout page', 'error' );
        wp_redirect(wc_get_checkout_url());
    }
    return $order;
}

function checkFromSubmission()
{
            

    if(isset($_POST['action']) && $_POST['action'] == 'blinkSubmitPayment')
    {
        $gateWay = new WC_Blink_Gateway();
        $request = $_POST;
        $order_id = $_POST['order_id'];
        $order = checkOrderPayment($_POST['order_id']);
        $gateWay->update_payment_information($order, $request);
        if($request['payment_by'] == 'credit-card'){
          $gateWay->processCreditCard($order_id, $request);
        }

        if($request['payment_by'] == 'direct-debit'){
            $gateWay->processDirectDebit($order_id, $request);
        }

        if($request['payment_by'] == 'open-banking'){
            $gateWay->processOpenBanking($order_id, $request);
        }
        
    }

        
}

function checkBlinkPaymentMethod($content)
{
    if(isset($_GET['blinkPay']) && $_GET['blinkPay'] !== '')
    {
            checkOrderPayment($_GET['blinkPay']);
            $gateWay = new WC_Blink_Gateway();
            if(isset($_GET['p']) && in_array($_GET['p'],$gateWay->paymentMethods)){

            
                $gateWay->accessToken = $gateWay->generate_access_token();
                $gateWay->paymentIntent = $gateWay->create_payment_intent($_GET['p']);
                if(isset($gateWay->paymentIntent['payment_intent']))
                {
                $gateWay->formElements = $gateWay->generate_form_element();
                $string = implode(' ', array_map('ucfirst', explode('-', $_GET['p'])));
                $html =    wc_print_notices();
                $html .= '<section class="blink-api-section">
                            <div class="blink-api-form-stracture">
                                <h2 class="heading-text">Pay with '.$string.'</h2>
                                <section class="blink-api-tabs-content">';

                                    if( $_GET['p'] == 'credit-card' && $gateWay->formElements['element']['ccElement'])
                                    {

                                        $html .='<div id="tab1" class="tab-contents active">
                                            <form name="blink-card" id="blink-card" method="POST" action="">
                                                '.$gateWay->formElements['element']['ccElement'].'
                                                <input type="hidden" name="type" value="1">
                                                <input type="hidden" name="device_timezone" value="0">
                                                <input type="hidden" name="device_capabilities" value="">
                                                <input type="hidden" name="device_accept_language" value="">
                                                <input type="hidden" name="device_screen_resolution" value="">
                                                <input type="hidden" name="remote_address" value="'.$_SERVER['REMOTE_ADDR'].'">';

                                    }
                                    if( $_GET['p'] == 'direct-debit' && $gateWay->formElements['element']['ddElement'])
                                    {
                                        $html .='<div id="tab1" class="tab-contents active">
                                            <form name="blink-debit" id="blink-debit" method="POST" action="">
                                                '.$gateWay->formElements['element']['ddElement'].'
                                                <input type="hidden" name="type" value="1">
                                                <input type="hidden" name="remote_address" value="'.$_SERVER['REMOTE_ADDR'].'">';

                                    }

                                    if( $_GET['p'] == 'open-banking' && $gateWay->formElements['element']['obElement'])
                                    {
                                        $html .='<div id="tab1" class="tab-contents active">
                                            <form name="blink-open" id="blink-open" method="POST" action="">
                                                '.$gateWay->formElements['element']['obElement'].'
                                                <input type="hidden" name="type" value="1">
                                                <input type="hidden" name="remote_address" value="'.$_SERVER['REMOTE_ADDR'].'">';

                                    }

                                    $html .='<input type="hidden" name="transaction_unique" value="'.$gateWay->formElements['transaction_unique'].'">
                                                <input type="hidden" name="amount" value="'.$gateWay->formElements['raw_amount'].'">
                                                <input type="hidden" name="intent_id" value="'.$gateWay->paymentIntent['id'].'">
                                                <input type="hidden" name="intent" value="'.$gateWay->paymentIntent['payment_intent'].'">
                                                <input type="hidden" name="access_token" value="'.$gateWay->accessToken.'">
                                                <input type="hidden" name="payment_by" id="payment_by" value="'.$_GET['p'].'">
                                                <input type="hidden" name="action" value="blinkSubmitPayment">
                                                <input type="hidden" name="order_id" value="'.$_GET['blinkPay'].'">
                                                <input type="submit" value="Pay now" name="blink-submit" />
                                            </form>
                                        </div>';
                                $html .='</section>
                            </div>
                        </section>';
            
                return $html;
                }
                else{
                    wc_add_notice(  $gateWay->paymentIntent['error'] ?? 'Something Wrong! Please initate the payment from checkout page', 'error' );
                    wp_redirect(wc_get_checkout_url());
            
                }
            }
    }

    return $content;

}

function blink_3d_form_submission($content)
{

    if(isset($_GET['blink3dprocess']) && $_GET['blink3dprocess'] !== '')
    {
        $token = get_transient( 'blink3dProcess'.$_GET['blink3dprocess']);
        $html = '<div class="blink-loading">Loading&#8230;</div><div class="3d-content">'.$token.'</div>';
        $script = '<script nonce="2020">
        jQuery(document).ready(function(){
    
            jQuery(\'#form3ds22\').submit();
            setTimeout(function(){jQuery(\'#btnSubmit\').val(\'Please Wait...\');},100);
        });
    </script>';
        return $html.$script;
    }

    return $content;
}
/**
 * WooCommerce fallback notice.
 */
function wc_blink_missing_notice() {
	/* translators: 1. URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Blink requires WooCommerce to be installed and active. You can download %s here.', 'blink' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

function add_wc_blink_payment_action_plugin($actions, $plugin_file)
    {
        static $plugin;

        if (!isset($plugin))
        {
            $plugin = plugin_basename(__FILE__);
        }

        if ($plugin == $plugin_file)
        {
            $configs = include(dirname(__FILE__) . '/config.php');

            $section = str_replace(' ', '', strtolower($configs['method_title']));
    
            $actions = array_merge(array('settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section='.$section) . '">' . __('Settings', 'General') . '</a>'), $actions);
        }

        return $actions;
    }

function blink_init_gateway_class() {

    if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_blink_missing_notice' );
		return;
	}

    add_filter('plugin_action_links', 'add_wc_blink_payment_action_plugin', 10, 5);

    include(dirname(__FILE__) . '/includes/wc-blink-gateway-class.php');

	add_filter( 'woocommerce_payment_gateways', 'blink_add_gateway_class' );

}
?>