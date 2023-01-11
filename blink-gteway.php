<?php
/*
 * Plugin Name: WooCommerce Blink Payment Gateway
 * Plugin URI: https://www.blinkpayment.co.uk/
 * Description: Take credit card payments on your store.
 * Author: Gourab Kirtania
 * Author URI: https://www.capitalnumbers.com/
 * Version: 1.0.1
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'blink_add_gateway_class' );
function blink_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Blink_Gateway'; // your class name is here
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'blink_init_gateway_class' );
add_action( 'the_content', 'blink_3d_form_submission' );

function blink_3d_form_submission($content)
{

    if(isset($_GET['blink3dprocess']) && $_GET['blink3dprocess'] !== '')
    {
        $script = '<script nonce="2020">
        jQuery(document).ready(function(){
    
            jQuery(\'#btnSubmit\').on(\'click\',function(){
                jQuery(this).val(\'Please Wait...\');
                setTimeout(function(){jQuery(\'#btnSubmit\').val(\'Process Payment\');},300);
            });
    
            jQuery(\'#form3ds22\').submit();
            setTimeout(function(){jQuery(\'#btnSubmit\').val(\'Process Payment\');},300);
        });
    </script>';
        $token = get_transient( 'blink3dProcess'.$_GET['blink3dprocess']);
        return $token.$script;
    }

    return $content;
}

function blink_init_gateway_class() {

	class WC_Blink_Gateway extends WC_Payment_Gateway {

        public function __construct() {

            $this->id = 'blink-v2'; // payment gateway plugin ID
            $this->icon = plugins_url('/logo.png', __FILE__ );
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Blink v2';
            $this->method_description = 'Description of Blink payment gateway'; // will be displayed on the options page
            $this->host_url = 'https://dev.blinkpayment.co.uk/api';

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
            $this->api_key = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
            $this->secret_key = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            // if needed we can use this webhook
            add_action( 'woocommerce_api_wc_blink_gateway', array( $this, 'webhook' ) );
            //add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
            //add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
            add_action( 'woocommerce_thankyou_blink-v2', array( $this, 'check_response' ) );

            // We need custom JavaScript to obtain a token
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

            $this->accessToken  = $this->generate_access_token();
            $paymentIntent = '';
            $formElements = '';
         }

         public function generate_form_element()
         {

            $requestData = [
            	'payment_intent' => $this->paymentIntent,
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
            $url = $this->host_url.'/v1/pay/token';
            $response = $response = wp_remote_post( $url, array(
                'method'      => 'POST',
                'body'        => array(
                    'apiKey' => $this->api_key,
                    'secretKey' => $this->secret_key
                )
                )
            );

            if ( ! is_wp_error( $response ) ) {
                $apiBody = json_decode( wp_remote_retrieve_body( $response ), true );
                return $apiBody['access_token'];
            } else {
                $error_message = $response->get_error_message();
                throw new Exception( $error_message );
            }
         }

         /**
         * Get the return url (thank you page).
         *
         * @param WC_Order|null $order Order object.
         * @return string
         */
        public function get_return_url( $order = null ) {
            if ( $order ) {
                $return_url = $order->get_checkout_order_received_url();
            } else {
                $return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
            }

            return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
        }

         public function create_payment_intent()
         {
            global $woocommerce;

            $requestData = [
            	'amount' => $woocommerce->cart->total,
            	'payment_type' => 'card_payment',
            	'currency' => 'GBP',
            	'return_url' => $this->get_return_url(),
            	'notification_url' => WC()->api_request_url( 'WC_Blink_Gateway' ),
            	'user_metadata' => json_encode(['user_email' => 'blinktestuser@yopmail.com',
            		'user_name' => 'Test User'
                ]
                ),
            ];

            $url = $this->host_url.'/v1/pay/intent';
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
                return $apiBody['payment_intent'];
            } else {
                $error_message = $response->get_error_message();
                throw new Exception( $error_message );
            }
         }

		/**
 		 * Plugin options, 
 		 */
        public function init_form_fields(){

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
                )
            );
        }

		/**
		 * credit card form
		 */
		public function payment_fields() {

            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
            $this->paymentIntent = $this->create_payment_intent();
            $this->formElements = $this->generate_form_element();
            echo $this->formElements['element']['ccElement'];
            ?> 
                       
            <input type="hidden" name="transaction_unique" value="<?php echo $this->formElements['transaction_unique']?>">
                <input type="hidden" name="amount" value="<?php echo $this->formElements['raw_amount']?>">
                <br>
                <input type="hidden" name="device_timezone" value="0">
                <input type="hidden" name="device_capabilities" value="">
                <input type="hidden" name="device_accept_language" value="">
                <input type="hidden" name="device_screen_resolution" value="1x1x1">
                <input type="hidden" name="remote_address" value="<?php echo $_SERVER["REMOTE_ADDR"]?>">

            <?php
				 
		}

		public function payment_scripts() {

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
            // Currently SSL checking is commented out
            // && ! is_ssl() 
            if ( ! $this->testmode ) {
                return;
            }
        
            // let's suppose it is our payment processor JavaScript that allows to obtain a token
            wp_enqueue_script( 'blinkv2_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js' );
        
            // and this is our custom JS in your plugin directory that works with token.js
            wp_register_script( 'woocommerce_blinkv2_payment', plugins_url( 'custom.js', __FILE__ ), array( 'jquery', 'blinkv2_js' ) );
        
            // in most payment processors you have to use API KEY and SECRET KEY to obtain a token
            wp_localize_script( 'woocommerce_blinkv2_payment', 'blinkv2_params', array(
                'apiKey' => $this->api_key,
                'secretKey' => $this->secret_key
            ) );
        
            wp_enqueue_script( 'woocommerce_blinkv2_payment' );
        
        }

		/*
 		 * Fields validation, more in Step 5
		 */
		public function validate_fields() {


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
                'fee_total' => wc_format_decimal($fee_total, 2),
                'fee_tax_total' => wc_format_decimal($fee_tax_total, 2),
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

            $url = $this->host_url.'/v1/pay/refresh-intent';

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
                return $apiBody['payment_intent'];
            } else {
                $error_message = $response->get_error_message();
                throw new Exception( $error_message );
            }
        }

		/*
		 * We're processing the payments here
		 */
		public function process_payment( $order_id ) {

            global $woocommerce;
 
            // we need it to get any order detailes
            $order = wc_get_order( $order_id );
            $request = $_POST;


            $updatedIntent = $this->update_payment_information($order,$request);


            $requestData = ['merchant_id' => $request['merchantID'],
    		'payment_intent' => $updatedIntent ?? $request['payment_intent'],
    		'paymentToken' => $request['paymentToken'],
    		'raw_amount' => $request['amount'],
    		'customer_email' => $request['billing_email'],
    		'customer_name' => $request['billing_first_name'].' '.$request['billing_last_name'],
    		'transaction_unique' => $request['transaction_unique']
    	];

                if(isset($request['remote_address'])) {
                $requestData['remote_address'] = $request['remote_address'];
                $requestData['device_timezone'] = $request['device_timezone'];
                $requestData['device_capabilities'] = $request['device_capabilities'];
                $requestData['device_screen_resolution'] = $request['device_screen_resolution'];
                $requestData['device_accept_language'] = $request['device_accept_language'];
            }

            

            $url = $this->host_url.'/v1/pay/process';
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
                $threedToken = $apiBody['acsform'];
                set_transient( 'blink3dProcess'.$order_id, $threedToken, 300 );

                return array(
                    'result'   => 'success',
                    'redirect' => site_url('checkout').'/?blink3dprocess='.$order_id,
                );
            } else {
                $error_message = $response->get_error_message();
                throw new Exception( $error_message );
            }
					
	 	}

		/*
		 * In case we need a webhook, like PayPal IPN etc
		 */
		public function webhook() {

		//...
					
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
                return $apiBody['data'];
            } else {
                $error_message = $response->get_error_message();
                throw new Exception( $error_message );
            }
            
        }

        public function check_response()
        {
            global $wp;
		    $order_id = apply_filters( 'woocommerce_thankyou_order_id', absint( $wp->query_vars['order-received'] ) );
		    $this->check_response_for_order( $order_id );
        }



        public function check_response_for_order( $order_id ) 
        {
            
            if ( empty( $_REQUEST['res'] ) ) {
                return;
            }
    
            $wc_order = wc_get_order( $order_id );
            if ( ! $wc_order->needs_payment() ) {
                return;
            }
    
            $transaction        = wc_clean( wp_unslash( $_REQUEST['res'] ) );
            $transaction_result = $this->validate_transaction( $transaction );
    
            if ( $transaction_result ) {
                $status = strtolower( $transaction_result['state'] );
    
                $wc_order->add_meta_data( '_blink_status', $status );
                $wc_order->set_transaction_id( $transaction_result['transactionID'] );
    
                if ( 'received' === $status ) {
                        $this->payment_complete( $wc_order, $transaction_result['transactionID'], __( 'Blink payment completed', 'woocommerce' ) );
                    
                } else {
                    
                        $this->payment_on_hold( $wc_order, sprintf( __( 'Payment pending (%s).', 'woocommerce' ), '' ) );
                    }
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
        public function payment_on_hold( $order, $reason = '' ) {
            $order->update_status( 'on-hold', $reason );

            if ( isset( WC()->cart ) ) {
                WC()->cart->empty_cart();
            }
        }
        

        public function capture_payment()
        {
            return false;
        }
 	}
}