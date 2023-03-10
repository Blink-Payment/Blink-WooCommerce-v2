<?php

class WC_Blink_Gateway extends WC_Payment_Gateway 
{

    public function __construct() 
    {

        $configs = include(dirname(__FILE__) . '/../config.php');


        $this->id = str_replace(' ', '', strtolower($configs['method_title']));
        $this->icon = plugins_url('/../assets/img/logo.png', __FILE__ );
        $this->has_fields = true; // in case you need a custom credit card form
        $this->method_title = $configs['method_title'];
        $this->method_description = $configs['method_description'];
        $this->host_url = $configs['host_url'].'/api';

        // gateways can support subscriptions, refunds, saved payment methods,
        // but in this tutorial we begin with simple payments
        $this->supports = array(
            'products'
        );
    
        // Method with all the options fields
        $this->init_form_fields();
    
        // Load the settings.
        $this->init_settings();
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled = $this->get_option( 'enabled' );
        $this->testmode = 'yes' === $this->get_option( 'testmode' );
        $paymentMethods[] = ('yes' === $this->get_option( 'credit_card' )) ? 'credit-card' : '';
        $paymentMethods[] = ('yes' === $this->get_option( 'direct_debit' )) ? 'direct-debit': '';
        $paymentMethods[] = ('yes' === $this->get_option( 'open_banking' )) ? 'open-banking': '';
        $this->paymentMethods = array_filter($paymentMethods);

        $this->api_key = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
        $this->secret_key = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

        // This action hook saves the settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        // if needed we can use this webhook
        add_action( 'woocommerce_api_wc_'.$this->id, array( $this, 'webhook' ) );
        add_action( 'woocommerce_before_thankyou', array( $this, 'check_response' ) );
        add_action( 'woocommerce_thankyou_blink', array( $this, 'check_response' ) );
        add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'change_title' ), 99 );
        // We need custom JavaScript to obtain a token
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        
        $this->accessToken  = '';
        $this->paymentIntent = '';
        $this->formElements = '';
        $this->elementMap  = [
            'credit-card' => 'ccElement',
            'direct-debit' => 'ddElement',
            'open-banking' => 'obElement',
        ];

     }

     public function generate_form_element()
     {

        $requestData = [
            'payment_intent' => $this->paymentIntent['payment_intent'],
        ];

        $url = $this->host_url.'/v1/pay/element';
        $response = $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
            ),
            'body'        => $requestData
            )
        );
        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            return $apiBody;
        } else {
            $error_message = $response->get_error_message();
            throw new Exception( $error_message );
        }

     }

     public function generate_access_token()
     {
        $request = $_GET;
        $url = $this->host_url.'/v1/pay/token'; 
        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'body'        => array(
                'api_key' => $this->api_key,
                'secret_key' => $this->secret_key
            )
            )
        );

        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            return $apiBody['access_token'] ?: '';
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = site_url('checkout').'/?p='.$request['payment_by'].'&blinkPay='.$request['blinkPay'];
            wp_redirect($redirect,302);
            exit();
        }
     }

     /**
     * Get the return url (thank you page).
     *
     * @param WC_Order|null $order Order object.
     * @return string
     */
    public function get_return_url( $order = null ) 
    {
        if ( $order ) {
            $return_url = $order->get_checkout_order_received_url();
        } else {
            $return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
        }

        return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
    }

     public function create_payment_intent($payment_type)
     {
        global $woocommerce;
        $request = $_GET;

        $requestData = [
            'amount' => $woocommerce->cart->total,
            'payment_type' => $payment_type,
            'currency' => get_woocommerce_currency(),
            'return_url' => $this->get_return_url(),
            'notification_url' => WC()->api_request_url( 'wc_blink_gateway' ),
        ];

        $url = $this->host_url.'/v1/pay/intent';
        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
            ),
            'body'        => $requestData
            )
        );

        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            return $apiBody;
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = site_url('checkout').'/?p='.$request['payment_by'].'&blinkPay='.$request['blinkPay'];
            wp_redirect($redirect,302);
            exit();
        }
     }

    /**
      * Plugin options, 
      */
    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Blink Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Blink v2',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with your credit card or direct debit at your convenience.',
            ),
            'pay_methods' => array(
                'title'       => 'Payment Methods',
                'label'       => '',
                'type'        => 'hidden',
                'description' => '',
                'default'     => '',
            ),
            'credit_card' => array(
                'title'       => '',
                'label'       => 'Credit Card',
                'type'        => 'checkbox',
                'default'     => 'yes',
            ),
            'direct_debit' => array(
                'title'       => '',
                'label'       => 'Direct Debit',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
            ),
            'open_banking' => array(
                'title'       => '',
                'label'       => 'Open Banking',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
            ),
            'testmode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => 'Place the payment gateway in test mode using test API keys.',
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_api_key' => array(
                'title'       => 'Test API Key',
                'type'        => 'text'
            ),
            'test_secret_key' => array(
                'title'       => 'Test Secret Key',
                'type'        => 'password',
            ),
            'api_key' => array(
                'title'       => 'Live API Key',
                'type'        => 'text'
            ),
            'secret_key' => array(
                'title'       => 'Live Secret Key',
                'type'        => 'password'
            ),
            'custom_style' => array(
                'title'       => 'Custom Style',
                'type'        => 'textarea',
                'description' => 'Do not include style tag',
            )
        );
    }

    /**
     * credit card form
     */
    public function payment_fields() 
    {

        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        if(is_array($this->paymentMethods) && !empty($this->paymentMethods)) { ?> 

        <section class="blink-api-section">
                <div class="blink-api-form-stracture">
                    <section class="blink-api-tabs-content">
                        <?php if(in_array('credit-card',$this->paymentMethods)) { ?>
                        <div class="blink-pay-options">
                            <a href="javascript:void(0);" onclick="updatePaymentBy('credit-card')" > Pay with Credit Card</a>
                        </div>
                        <?php } ?>
                        <?php if(in_array('direct-debit',$this->paymentMethods)) { ?>
                        <div class="blink-pay-options">
                            <a href="javascript:void(0);" onclick="updatePaymentBy('direct-debit')" > Pay with Direct Debit</a>
                        </div>
                        <?php } ?>
                        <?php if(in_array('open-banking',$this->paymentMethods)) { ?>
                        <div class="blink-pay-options">
                         <a href="javascript:void(0);" onclick="updatePaymentBy('open-banking')" > Pay with Open Banking</a>
                        </div>   
                        <?php } ?> 
                        <input type="hidden" name="payment_by" id="payment_by" value="">
                    </section>
                </div>
            </section>

        <?php
        }
             
    }

    public function payment_scripts() 
    {

        // we need JavaScript to process a token only on cart/checkout pages, right?
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }
    
        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ( 'no' === $this->enabled ) {
            return;
        }
    
        // no reason to enqueue JavaScript if API keys are not set
        if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
            return;
        }
    
        // do not work with card detailes without SSL unless your website is in a test mode
        if ( ! $this->testmode && ! is_ssl()) {
            return;
        }
    
        // let's suppose it is our payment processor JavaScript that allows to obtain a token
        wp_enqueue_script( 'blink_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js' );
        wp_register_style( 'woocommerce_blink_payment_style', plugins_url( '/../assets/css/style.css', __FILE__ ), [] );

        // and this is our custom JS in your plugin directory that works with token.js
        wp_register_script( 'woocommerce_blink_payment', plugins_url( '/../assets/js/custom.js', __FILE__ ), array( 'jquery', 'blink_js' ) );
    
        // in most payment processors you have to use API KEY and SECRET KEY to obtain a token
        wp_localize_script( 'woocommerce_blink_payment', 'blink_params', array(
            'apiKey' => $this->api_key,
            'secretKey' => $this->secret_key
        ) );

        if(isset($_GET['blinkPay']) && $_GET['blinkPay'] !== '')
        {
           $order_id = $_GET['blinkPay'];
           $order = wc_get_order($order_id);
           wp_localize_script( 'woocommerce_blink_payment', 'order_params', $this->get_customer_data($order) );
        }
    
        wp_enqueue_script( 'woocommerce_blink_payment' );
        wp_enqueue_style( 'woocommerce_blink_payment_style' );

        $custom_css = $this->get_option( 'custom_style' );

            if($custom_css)
            { 
                wp_add_inline_style('woocommerce_blink_payment_style', $custom_css );

            }

        do_action('wc_blink_custom_script');
        do_action('wc_blink_custom_style');

    
    }

    public function get_customer_data($order)
    {
        return array(
            'user_name' => ($a = get_userdata($order->get_user_id() )) ? $a->user_email : $order->get_billing_first_name().' '.$order->get_billing_last_name(),
            'user_email' => ($a = get_userdata($order->get_user_id() )) ? $a->user_email : $order->get_billing_email(),
            'customer_id' => $order->get_user_id(),
            'customer_user' => $order->get_user_id(),
            'customer_name' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'customer_address' => $order->get_billing_address_1().','.$order->get_billing_address_2(),
            'customer_postcode' => $order->get_billing_postcode(),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_company' => $order->get_billing_company(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone(),
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_postcode' => $order->get_billing_postcode(),
            'billing_city' => $order->get_billing_city(),
            'billing_state' => $order->get_billing_state(),
            'billing_country' => $order->get_billing_country(),

        );
    }

    public function get_order_data($order)
    {
        return $order_data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_date' => date('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)),
            'status' => $order->get_status(),
            'shipping_total' => $order->get_total_shipping(),
            'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2),
            'tax_total' => wc_format_decimal($order->get_total_tax(), 2),
            'cart_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2),
            'order_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2),
            'discount_total' => wc_format_decimal($order->get_total_discount(), 2),
            'order_total' => wc_format_decimal($order->get_total(), 2),
            'order_currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'shipping_method' => $order->get_shipping_method(),
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name' => $order->get_shipping_last_name(),
            'shipping_company' => $order->get_shipping_company(),
            'shipping_address_1' => $order->get_shipping_address_1(),
            'shipping_address_2' => $order->get_shipping_address_2(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_country' => $order->get_shipping_country(),
            'customer_note' => $order->get_customer_note(),
            'download_permissions' => $order->is_download_permitted() ? $order->is_download_permitted() : 0,
       );
    }

    public function update_payment_information($order, $request)
    {
        $requestData = [
            'payment_intent' => $request['payment_intent'],
            'return_url' => $this->get_return_url($order),
            'notification_url' => WC()->api_request_url( 'wc_blink_gateway' ),
            'user_metadata' => json_encode(
                $this->get_customer_data($order)
            ),
            'item_metadata' => json_encode(
                $this->get_order_data($order)
            ),
        ];

        $url = $this->host_url.'/v1/pay/intent/'.$request['intent_id'];

        $response = wp_remote_request( $url, array(
            'method'      => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
            ),
            'body'        => $requestData
            )
        );
        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            return $apiBody['payment_intent'];
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = site_url('checkout').'/?p='.$request['payment_by'].'&blinkPay='.$order->get_id();
            wp_redirect($redirect,302);
            exit();
        }
    }

    public function validate_fields($request = [], $order_id = ''){

        if( !empty( $request ) && $order_id != '') {

            $errors = 0;

            if( $request['payment_by'] == 'direct-debit') {
                if( empty( $request['given_name'] )) {
                    wc_add_notice(  'Given name is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['family_name'] )) {
                    wc_add_notice(  'Family name is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['email'] )) {
                    wc_add_notice(  'email is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['account_holder_name'] )) {
                    wc_add_notice(  'Account holder name is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['branch_code'] )) {
                    wc_add_notice(  'Branch code is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['account_number'] )) {
                    wc_add_notice(  'Account number is required!', 'error' );
                    $errors++;
                }
            }
            if( $request['payment_by'] == 'open-banking') {
                if( empty( $request['user_name'] )) {
                    wc_add_notice(  'User name is required!', 'error' );
                    $errors++;
                }
                if( empty( $request['user_email'] )) {
                    wc_add_notice(  'User Email is required!', 'error' );
                    $errors++;
                }
            }
            
            if($errors > 0)
            {
                $redirect = site_url('checkout').'/?p='.$request['payment_by'].'&blinkPay='.$order_id;
                wp_redirect($redirect,302);
                exit();
    
            }
            
        }
        return true;
     
    }

    public function processOpenBanking($order_id,$request)
    {
        $this->validate_fields($request, $order_id);

        $requestData = [
        'merchant_id' => $request['merchant_id'],
        'payment_intent' => $request['payment_intent'],
        'raw_amount' => $request['amount'],
        'transaction_unique' => $request['transaction_unique'],
        'user_name' => $request['user_name'],
        'user_email' => $request['user_email'],
        'customer_address' => $request['customer_address'] ?? $request['billing_address_1'].', '.$request['billing_address_2'],
        'customer_postcode' => $request['customer_postcode'] ?? $request['billing_postcode'],
        ];
        


        $url = $this->host_url.'/v1/pay/ob/process';

        $response =  wp_remote_post( $url, array(
            'method'      => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
                'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                'accept' => $_SERVER['HTTP_ACCEPT'],
                'accept-encoding'=> 'gzip, deflate, br',
                'accept-charset' => 'charset=utf-8'

            ),
            'body'        => $requestData
            )
        );
        
        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            if($apiBody['redirect_url'])
            {
                $redirect = $apiBody['redirect_url'];
            }
            else{
                wc_add_notice(  'Something Wrong! Please try again', 'error' );
                $redirect = wc_get_checkout_url();

            }
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = wc_get_checkout_url();
        }

        wp_redirect($redirect,302);
        exit();
    }

    public function processDirectDebit($order_id,$request)
    {
        $this->validate_fields($request, $order_id);

        $requestData = [
        'payment_intent' => $request['payment_intent'],
        'raw_amount' => $request['amount'],
        'transaction_unique' => $request['transaction_unique'],
        'given_name' => $request['given_name'],
        'family_name' => $request['family_name'],
        'company_name' => $request['company_name'],
        'email' => $request['email'],
        'account_holder_name' => $request['account_holder_name'],
        'branch_code' => $request['branch_code'],
        'account_number' => $request['account_number'],
        'customer_address' => $request['customer_address'] ?? $request['billing_address_1'].', '.$request['billing_address_2'],
        'customer_postcode' => $request['customer_postcode'] ?? $request['billing_postcode'],
        'country_code' => 'GB',
        ];


        $url = $this->host_url.'/v1/pay/dd/process';

        $response =  wp_remote_post( $url, array(
            'method'      => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
                'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                'accept' => $_SERVER['HTTP_ACCEPT'],
                'accept-encoding'=> 'gzip, deflate, br',
                'accept-charset' => 'charset=utf-8'

            ),
            'body'        => $requestData
            )
        );
        
        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            if($apiBody['url'])
            {
                $redirect = $apiBody['url'];
            }
            else{
                wc_add_notice(  'Something Wrong! Please try again', 'error' );
                $redirect = wc_get_checkout_url();

            }
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = wc_get_checkout_url();
        }

        wp_redirect($redirect,302);
        exit();
    }

    public function processCreditCard($order_id,$request)
    {

        $requestData = ['merchant_id' => $request['merchantID'],
        'payment_intent' => $request['payment_intent'],
        'paymentToken' => $request['paymentToken'],
        'raw_amount' => $request['amount'],
        'customer_email' => $request['customer_email'] ?? $request['billing_email'],
        'customer_name' => $request['customer_name'] ?? $request['billing_first_name'].' '.$request['billing_last_name'],
        'customer_address' => $request['customer_address'] ?? $request['billing_address_1'].', '.$request['billing_address_2'],
        'customer_postcode' => $request['customer_postcode'] ?? $request['billing_postcode'],
        'transaction_unique' => $request['transaction_unique'],
        'type' => $request['type']
        ];

            if(isset($request['remote_address'])) {
            $requestData['remote_address'] = $request['remote_address'];
            $requestData['device_timezone'] = $request['device_timezone'];
            $requestData['device_capabilities'] = $request['device_capabilities'];
            $requestData['device_screen_resolution'] = $request['device_screen_resolution'];
            $requestData['device_accept_language'] = $request['device_accept_language'];
        }


        $url = $this->host_url.'/v1/pay/cc/process';
        $response =  wp_remote_post( $url, array(
            'method'      => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
                'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                'accept' => $_SERVER['HTTP_ACCEPT'],
                'accept-encoding'=> 'gzip, deflate, br',
                'accept-charset' => 'charset=utf-8'

            ),
            'body'        => $requestData
            )
        );

        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            if(isset($apiBody['acsform'])){
            $threedToken = $apiBody['acsform'];
            set_transient( 'blink3dProcess'.$order_id, $threedToken, 300 );
            $redirect = site_url('checkout').'/?blink3dprocess='.$order_id;
            }elseif($apiBody['url'])
            {
                $redirect = $apiBody['url'];
            }
            else{
                wc_add_notice(  'Something Wrong! Please try again', 'error' );
                $redirect = wc_get_checkout_url();
            }

        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = wc_get_checkout_url();
        }

        wp_redirect($redirect,302);
        exit();

    }

    /*
     * We're processing the payments here
     */
    public function process_payment( $order_id ) 
    {

        // we need it to get any order detailes
        $order = wc_get_order( $order_id );
        $request = $_POST;

        if ( count( WC()->cart->get_cart() ) == 0 ) {
            $items = $order->get_items();
            foreach ( $items as $item ) {
                $quantity = $item['quantity'];
                $product_id = $item['product_id'];
                $variation_id = $item['variation_id'];
                WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
            }
        }
        

        $redirect = site_url('checkout').'/?p='.$request['payment_by'].'&blinkPay='.$order_id;

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
                
    }

    /*
     * In case we need a webhook, like PayPal IPN etc
     */
    public function webhook() 
    {
      $headers = getallheaders();
      if($headers['Api-Key'] === $this->api_key && $headers['Secret-Key'] === $this->secret_key)
      { 
        $request  = $_POST; 
        $order_id = $request['order_id'] ?? '';
        $action = $request['action'] ?? '';
        $status = $request['status'] ?? '';
        $note = $request['note'] ?? '';

        if($order_id)
        {
            $order = wc_get_order($order_id);
            if($action == 'update_order_status') 
            {
                $order->update_status($status, $note);
                $response =  [
                    'order_id' => $order_id,
                    'order_status' => $status,
                ];
                echo json_encode($response); exit;

            }else{
                $order->update_meta_data( '_debug', $request );

            }
        }
      }
      else
      {
        $response =  [
            'error' => 'Invalid Api and Secret Key',
        ];
        echo json_encode($response); exit;
      }
                
    }

    public function validate_transaction( $transaction )
    {

        $responseCode = base64_decode($transaction);


        $url = $this->host_url.'/v1/pay/transaction/'.$responseCode;
        $response = wp_remote_get( $url, array(
            'method'      => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->accessToken,
            )
        ));
        if ( ! is_wp_error( $response ) ) {
            $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
            return $apiBody['data'] ?? [];
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice(  $error_message, 'error' );
            $redirect = wc_get_checkout_url();
        }

        wp_redirect($redirect,302);
        exit();
        
    }

    public function check_response()
    {
        global $wp;
        $order_id = apply_filters( 'woocommerce_thankyou_order_id', absint( $wp->query_vars['order-received'] ) );
        $this->check_response_for_order( $order_id );
    }

    public function change_title($title)
    {
        global $wp;
        $order_id = $wp->query_vars['order-received'];
        $this->check_response_for_order( $order_id );
        $order = wc_get_order($order_id);

        if ( $order->has_status( 'failed' ) )
        {
            return __( 'Order Failed', 'woocommerce' );
        }

        return $title;
    }



    public function check_response_for_order( $order_id ) 
    { 
        
        $wc_order = wc_get_order( $order_id );
        if ( ! $wc_order->needs_payment()) {
            return;
        }

        if ( $wc_order->get_meta('_blink_res_expired', true) == 'true') 
        {
            return;
        }
        // else
        // {
        //     $wc_order->update_meta_data( 'once executed', time() );

        // }

        $transaction = $wc_order->get_meta('blink_res', true);


        $transaction_result = $this->validate_transaction( $transaction );

        if ( !empty($transaction_result) ) {
            $status = $transaction_result['status'] ?? '';
            $source = $transaction_result['payment_source'] ?? '';
            $message = $transaction_result['message'] ?? '';

            $wc_order->update_meta_data( '_blink_status', $status );
            $wc_order->update_meta_data( 'payment_type', $source );
            $wc_order->update_meta_data( '_blink_res_expired', 'true' );
            $wc_order->set_transaction_id( $transaction_result['transaction_id'] );
            $wc_order->add_order_note( 'Pay by '. $source );
            $wc_order->add_order_note( 'Transaction Note: '. $message );
            $wc_order->save();

            if( 'captured' === strtolower($status) || 'success' === strtolower($status) || 'accept' === strtolower($status) ) 
            {
                    $wc_order->add_order_note( 'Transaction status - '. $status );
                    $this->payment_complete( $wc_order, $transaction_result['transaction_id'], __( 'Blink payment completed', 'woocommerce' ) );
            } 
            else if(strpos(strtolower($source),'direct debit') !== false)
            {
                    $this->payment_on_hold( $wc_order, sprintf( __( 'Payment pending (%s).', 'woocommerce' ), 'Transaction status - '.$status ) );

            }
            else 
            {
                    $this->payment_failed( $wc_order, sprintf( __( 'Payment Failed (%s).', 'woocommerce' ), 'Transaction status - '.$status ) );
            }
        }
        else
        {
            $this->payment_failed( $wc_order, sprintf( __( 'Payment Failed (%s).', 'woocommerce' ), 'Transaction status - Null' ) );

        }
    } 

    /**
     * Complete order, add transaction ID and note.
     *
     * @param  WC_Order $order Order object.
     * @param  string   $txn_id Transaction ID.
     * @param  string   $note Payment note.
     */
    public function payment_complete( $order, $txn_id = '', $note = '' ) {
        if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
            $order->add_order_note( $note );
            $order->payment_complete( $txn_id );

            if ( isset( WC()->cart ) ) {
                WC()->cart->empty_cart();
            }
        }
    }

    /**
     * Hold order and add note.
     *
     * @param  WC_Order $order Order object.
     * @param  string   $reason Reason why the payment is on hold.
     */
    public function payment_on_hold( $order, $reason = '' ) 
    {
        $order->update_status( 'on-hold', $reason );
        $order->add_order_note( $reason );


        if ( isset( WC()->cart ) ) {
            WC()->cart->empty_cart();
        }

    }

    /**
     * Hold order and add note.
     *
     * @param  WC_Order $order Order object.
     * @param  string   $reason Reason why the payment is on hold.
     */
    public function payment_failed( $order, $reason = '' ) {
        $order->update_status( 'failed', $reason );
        $order->add_order_note( $reason );

    }
    

    public function capture_payment()
    {
        return false;
    }
} 



?>