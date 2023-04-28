<?php

class WC_Blink_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->configs = include(dirname(__FILE__) . '/../config.php');
        $this->id = str_replace(' ', '', strtolower($this->configs['method_title']));
        $this->icon = plugins_url('/../assets/img/logo.png', __FILE__);
        $this->has_fields = true; // in case you need a custom credit card form
        $this->method_title = $this->configs['method_title'];
        $this->method_description = $this->configs['method_description'];
        $this->host_url = $this->configs['host_url'] . '/api';

        // gateways can support subscriptions, refunds, saved payment methods,
        // but in this tutorial we begin with simple payments
        $this->supports = array(
            'products'
        );

        // Load the settings.
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
        $this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');

        $payment_types = $this->generate_access_token('payment_types') ?? [];
        $paymentMethods = [];
        foreach($payment_types as $type)
        {
            $paymentMethods[] = ('yes' === $this->get_option($type)) ? $type : '';
        }
        $this->paymentMethods = array_filter($paymentMethods);

        $this->add_error_notices($payment_types);


        // Method with all the options fields
        $this->init_form_fields($payment_types);

        // This action hook saves the settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // if needed we can use this webhook
        add_action('woocommerce_api_wc_blink_gateway', array($this, 'webhook'));
        add_action('woocommerce_thankyou_blink', array($this, 'check_response_for_order'));
        add_filter('woocommerce_endpoint_order-received_title', array($this, 'change_title'), 99);
        // We need custom JavaScript to obtain a token
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        $this->accessToken  = '';
        $this->paymentIntent = '';
        $this->elementMap  = [
            'credit-card' => 'ccElement',
            'direct-debit' => 'ddElement',
            'open-banking' => 'obElement',
        ];
    }

    public function add_error_notices($payment_types = []) {
        if($this->api_key && $this->secret_key) {
            $adminnotice = new WC_Admin_Notices();
            if(!empty($payment_types)) { 
                if(empty($this->paymentMethods)) {
                    if(!$adminnotice->has_notice('no-payment-type-selected')){
                        $adminnotice->add_custom_notice("no-payment-type-selected","<div>Please select the Payment Methods and save the configuration!</div>");
                        $adminnotice->output_custom_notices();
                    }
                } else {
                    $adminnotice->remove_notice('no-payment-type-selected');
                }
                $adminnotice->remove_notice('no-payment-types');

            } else {

                if (!$adminnotice->has_notice('no-payment-types')) {
                $adminnotice->add_custom_notice("no-payment-types","<div>There is no Payment Types Available</div>");
                $adminnotice->output_custom_notices();
                }

            }
        }
    }

    public function checkAPIException($response, $redirect) {
        if (isset($response['error'])) {
            wc_add_notice($response['error'], 'error');
            wp_redirect($redirect, 302);
            exit();
        }

        return;
    }

    public function generate_access_token($returnVar = 'access_token') {
        $request = $_GET;
        $url = $this->host_url . '/pay/v1/tokens';
        $response = wp_remote_post (
            $url,
            array(
                'method'      => 'POST',
                'body'        => array(
                    'api_key' => $this->api_key,
                    'secret_key' => $this->secret_key
                )
            )
        );

        $redirect = trailingslashit(wc_get_checkout_url());

        if (wp_remote_retrieve_response_code($response) == 200) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            return $apiBody[$returnVar] ?: '';
        } else {
            $error_message = wp_remote_retrieve_response_message($response);
            if(is_admin()){
                return '';
            }
            wc_add_notice($error_message, 'error');
            wp_redirect($redirect, 302);
            exit();
            
        }
    }

    /**
     * Get the return url (thank you page).
     *
     * @param WC_Order|null $order Order object.
     * @return string
     */
    public function get_return_url($order = null) {
        if ($order) {
            $return_url = $order->get_checkout_order_received_url();
        } else {
            $return_url = wc_get_endpoint_url('order-received', '', wc_get_checkout_url());
        }

        return apply_filters('woocommerce_get_return_url', $return_url, $order);
    }

    public function create_payment_intent() {
        global $woocommerce;
        $request = $_GET;
        $order = wc_get_order($request['blinkPay'] ?? '');
        $payment_type = $request['p'] ?? '';

        $requestData = [
            'amount' => $woocommerce->cart->total,
            'payment_type' => $payment_type,
            'currency' => get_woocommerce_currency(),
            'return_url' => $this->get_return_url($order),
            'notification_url' => WC()->api_request_url('wc_blink_gateway'),
        ];

        $url = $this->host_url . '/pay/v1/intents';
        $response = wp_remote_post (
            $url,
            array(
                'method'      => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ),
                'body'        => $requestData
            )
        );

        $redirect = trailingslashit(wc_get_checkout_url());

        if (!is_wp_error($response)) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            return $apiBody;
        } else {
            $error_message = $response->get_error_message();
            wc_add_notice($error_message, 'error');
            wp_redirect($redirect, 302);
            exit();
        }
    }

    /**
     * Plugin options, 
     */
    public function init_form_fields($payment_types = []) {
        $fields =  array(
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
            ),
            'custom_style' => array(
                'title'       => 'Custom Style',
                'type'        => 'textarea',
                'description' => 'Do not include style tag',
            )
        );

        if ($this->api_key && $this->secret_key) {
            if (!empty($payment_types)) { 
                $pay_methods['pay_methods'] = array(
                    'title'       => 'Payment Methods',
                    'label'       => '',
                    'type'        => 'hidden',
                    'description' => '',
                    'default'     => '',
                 );
                $fields = insertArrayAtPosition($fields, $pay_methods,count($fields)-1);
                foreach ($payment_types as $types) {
                    $payment[$types] = array(
                            'title'       => '',
                            'label'       => ucwords(str_replace('-',' ',$types)),
                            'type'        => 'checkbox',
                            'description' => '',
                            'default'     => 'no',
                    );
                    $fields = insertArrayAtPosition($fields, $payment,count($fields)-1);
                }

            }
        }

        $this->form_fields = $fields;
    }

    /**
     * credit card form
     */
    public function payment_fields() {
        if (is_array($this->paymentMethods) && empty($this->paymentMethods)) {
            echo wpautop(wp_kses_post('Unable to process any payment at this moment!'));
        } else {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
        } 

        if (is_array($this->paymentMethods) && !empty($this->paymentMethods)) { ?>
<section class="blink-api-section">
    <div class="blink-api-form-stracture">
        <section class="blink-api-tabs-content">
            <?php if (in_array('credit-card', $this->paymentMethods)) { ?>
            <div class="blink-pay-options">
                <a href="javascript:void(0);" onclick="updatePaymentBy('credit-card')"> Pay with Credit Card</a>
            </div>
            <?php } ?>
            <?php if (in_array('direct-debit', $this->paymentMethods)) { ?>
            <div class="blink-pay-options">
                <a href="javascript:void(0);" onclick="updatePaymentBy('direct-debit')"> Pay with Direct Debit</a>
            </div>
            <?php } ?>
            <?php if (in_array('open-banking', $this->paymentMethods)) { ?>
            <div class="blink-pay-options">
                <a href="javascript:void(0);" onclick="updatePaymentBy('open-banking')"> Pay with Open Banking</a>
            </div>
            <?php } ?>
            <input type="hidden" name="payment_by" id="payment_by" value="" />
        </section>
    </div>
</section>

<?php } else { ?>

<section class="blink-api-section">
    <div class="blink-api-form-stracture">
        <input type="hidden" name="payment_by" id="payment_by" value="" />
    </div>
</section>

<?php 
        }
    }

    public function payment_scripts() {
        // we need JavaScript to process a token only on cart/checkout pages, right?
        if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ('no' === $this->enabled) {
            return;
        }

        // no reason to enqueue JavaScript if API keys are not set
        if (empty($this->api_key) || empty($this->secret_key)) {
            return;
        }

        // do not work with card detailes without SSL unless your website is in a test mode
        if (!$this->testmode && !is_ssl()) {
            return;
        }

        // let's suppose it is our payment processor JavaScript that allows to obtain a token
        wp_enqueue_script('blink_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js');
        wp_register_style('woocommerce_blink_payment_style', plugins_url('/../assets/css/style.css', __FILE__), []);

        // and this is our custom JS in your plugin directory that works with token.js
        wp_register_script('woocommerce_blink_payment', plugins_url('/../assets/js/custom.js', __FILE__), array('jquery', 'blink_js'));

        // in most payment processors you have to use API KEY and SECRET KEY to obtain a token
        wp_localize_script('woocommerce_blink_payment', 'blink_params', array(
            'apiKey' => $this->api_key,
            'secretKey' => $this->secret_key,
            'remoteAddress' => $_SERVER['REMOTE_ADDR']
        ));

        if (isset($_GET['blinkPay']) && $_GET['blinkPay'] !== '') {
            $order_id = $_GET['blinkPay'];
            $order = wc_get_order($order_id);
            wp_localize_script('woocommerce_blink_payment', 'order_params', $this->get_customer_data($order));
        }

        wp_enqueue_script('woocommerce_blink_payment');
        wp_enqueue_style('woocommerce_blink_payment_style');

        $custom_css = $this->get_option('custom_style');

        if ($custom_css) {
            wp_add_inline_style('woocommerce_blink_payment_style', $custom_css);
        }

        do_action('wc_blink_custom_script');
        do_action('wc_blink_custom_style');
    }

    public function get_customer_data($order) {
        return array(
            'customer_id' => $order->get_user_id(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
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

    public function get_order_data($order) {
        return $order_data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_date' => date('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)),
            'shipping_total' => $order->get_total_shipping(),
            'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2),
            'tax_total' => wc_format_decimal($order->get_total_tax(), 2),
            'cart_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2),
            'order_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2),
            'discount_total' => wc_format_decimal($order->get_total_discount(), 2),
            'order_total' => wc_format_decimal($order->get_total(), 2),
            'order_currency' => $order->get_currency(),
            'customer_note' => $order->get_customer_note(),
        );
    }

    public function get_payment_information($order_id) {
        $order = wc_get_order($order_id);
        return json_encode(['payer_info' => $this->get_customer_data($order), 'order_info' => $this->get_order_data($order)]);
    }

    public function validate_fields($request = [], $order_id = '') {
        if (!empty($request) && $order_id != '') {
            $errors = 0;

            if ($request['payment_by'] == 'direct-debit') {
                if (empty($request['given_name'])) {
                    wc_add_notice('Given name is required!', 'error');
                    $errors++;
                }
                if (empty($request['family_name'])) {
                    wc_add_notice('Family name is required!', 'error');
                    $errors++;
                }
                if (empty($request['email'])) {
                    wc_add_notice('email is required!', 'error');
                    $errors++;
                }
                if (empty($request['account_holder_name'])) {
                    wc_add_notice('Account holder name is required!', 'error');
                    $errors++;
                }
                if (empty($request['branch_code'])) {
                    wc_add_notice('Branch code is required!', 'error');
                    $errors++;
                }
                if (empty($request['account_number'])) {
                    wc_add_notice('Account number is required!', 'error');
                    $errors++;
                }
            }

            if ($request['payment_by'] == 'open-banking') {
                if (empty($request['user_name'])) {
                    wc_add_notice('User name is required!', 'error');
                    $errors++;
                }
                if (empty($request['user_email'])) {
                    wc_add_notice('User Email is required!', 'error');
                    $errors++;
                }
            }

            if ($errors > 0) {
                $redirect = trailingslashit(wc_get_checkout_url()) . '?p=' . $request['payment_by'] . '&blinkPay=' . $order_id;
                wp_redirect($redirect, 302);
                exit();
            }
        }
        return true;
    }

    public function processOpenBanking($order_id, $request) {
        $this->validate_fields($request, $order_id);
        $order = wc_get_order($order_id);

        $requestData = [
            'merchant_id' => $request['merchant_id'],
            'payment_intent' => $request['payment_intent'],
            'user_name' => $request['user_name'] ?? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'user_email' => $request['user_email'] ?? $order->get_billing_email(),
            'customer_address' => $request['customer_address'] ?? $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
            'customer_postcode' => $request['customer_postcode'] ?? $order->get_billing_postcode(),
            'merchant_data' => $this->get_payment_information($order_id),
        ];

        $url = $this->host_url . '/pay/v1/openbankings';

        $response =  wp_remote_post(
            $url,
            array(
                'method'      => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                    'accept' => $_SERVER['HTTP_ACCEPT'],
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-charset' => 'charset=utf-8'
                ),
                'body'        => $requestData
            )
        );
        

        $redirect = trailingslashit(wc_get_checkout_url()) . '?p=open-banking&blinkPay=' . $order_id;

        if (wp_remote_retrieve_response_code($response) == 200) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            if ($apiBody['redirect_url']) {
                $redirect = $apiBody['redirect_url'];
            } else {
                wc_add_notice('Something Wrong! Please try again', 'error');
            }
        } else {
            $error_message = wp_remote_retrieve_response_message($response);
            wc_add_notice($error_message, 'error');
        }

        wp_redirect($redirect, 302);
        exit();
    }

    public function processDirectDebit($order_id, $request) {
        $this->validate_fields($request, $order_id);
        $order = wc_get_order($order_id);

        $requestData = [
            'payment_intent' => $request['payment_intent'],
            'given_name' => $request['given_name'] ?? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'family_name' => $request['family_name'],
            'company_name' => $request['company_name'],
            'email' => $request['email'] ?? $order->get_billing_email(),
            'country_code' => 'GB',
            'account_holder_name' => $request['account_holder_name'],
            'branch_code' => $request['branch_code'],
            'account_number' => $request['account_number'],
            'customer_address' => $request['customer_address'] ?? $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
            'customer_postcode' => $request['customer_postcode'] ?? $order->get_billing_postcode(),
            'merchant_data' => $this->get_payment_information($order_id),

        ];

        $url = $this->host_url . '/pay/v1/directdebits';

        $response =  wp_remote_post(
            $url,
            array(
                'method'      => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                    'accept' => $_SERVER['HTTP_ACCEPT'],
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-charset' => 'charset=utf-8'

                ),
                'body'        => $requestData
            )
        );

        $redirect = trailingslashit(wc_get_checkout_url()) . '?p=direct-debit&blinkPay=' . $order_id;

        if (wp_remote_retrieve_response_code($response) == 200) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            if ($apiBody['url']) {
                $redirect = $apiBody['url'];
            } else {
                wc_add_notice('Something Wrong! Please try again', 'error');
            }
        } else {
            $error_message = wp_remote_retrieve_response_message($response);
            wc_add_notice($error_message, 'error');
        }

        wp_redirect($redirect, 302);
        exit();
    }

    public function processCreditCard($order_id, $request) {
        $order = wc_get_order($order_id);

        $requestData = [
            'payment_intent' => $request['payment_intent'],
            'paymentToken' => $request['paymentToken'],
            'type' => $request['type'],
            'raw_amount' => $request['amount'],
            'customer_email' => $request['customer_email'] ?? $order->get_billing_email(),
            'customer_name' => $request['customer_name'] ?? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_address' => $request['customer_address'] ?? $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
            'customer_postcode' => $request['customer_postcode'] ?? $order->get_billing_postcode(),
            'transaction_unique' => $request['transaction_unique'],
            'merchant_data' => $this->get_payment_information($order_id),
        ];

        if (isset($request['remote_address'])) {
            $requestData['device_timezone'] = $request['device_timezone'];
            $requestData['device_capabilities'] = $request['device_capabilities'];
            $requestData['device_accept_language'] = $request['device_accept_language'];
            $requestData['device_screen_resolution'] = $request['device_screen_resolution'];
            $requestData['remote_address'] = $request['remote_address'];
        }

        $url = $this->host_url . '/pay/v1/creditcards';
        $response =  wp_remote_post (
            $url,
            array(
                'method'      => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'user-agent'    => $_SERVER['HTTP_USER_AGENT'],
                    'accept' => $_SERVER['HTTP_ACCEPT'],
                    'accept-encoding' => 'gzip, deflate, br',
                    'accept-charset' => 'charset=utf-8'

                ),
                'body'        => $requestData
            )
        );

        $redirect = trailingslashit(wc_get_checkout_url()) . '?p=credit-card&blinkPay=' . $order_id;

        if (wp_remote_retrieve_response_code($response) == 200) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            if (isset($apiBody['acsform'])) {
                $threedToken = $apiBody['acsform'];
                set_transient('blink3dProcess' . $order_id, $threedToken, 300);
                $redirect = trailingslashit(wc_get_checkout_url()) . '?blink3dprocess=' . $order_id;
            } elseif ($apiBody['url']) {
                $redirect = $apiBody['url'];
            } else {
                wc_add_notice('Something Wrong! Please try again', 'error');
            }
        } else {
            $error_message = wp_remote_retrieve_response_message($response);
            wc_add_notice($error_message, 'error');
        }

        wp_redirect($redirect, 302);
        exit();
    }

    /*
     * We're processing the payments here
     */
    public function process_payment($order_id) {
        // we need it to get any order detailes
        $order = wc_get_order($order_id);
        $request = $_POST;

        if (count(WC()->cart->get_cart()) == 0) {
            $items = $order->get_items();
            foreach ($items as $item) {
                $quantity = $item['quantity'];
                $product_id = $item['product_id'];
                $variation_id = $item['variation_id'];
                WC()->cart->add_to_cart($product_id, $quantity, $variation_id);
            }
        }

        $redirect = trailingslashit(wc_get_checkout_url()) . '?p=' . $request['payment_by'] . '&blinkPay=' . $order_id;

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    public function change_status($wc_order, $transaction_id, $status = '', $source = '', $note = null ) {
        if ('captured' === strtolower($status) || 'success' === strtolower($status) || 'accept' === strtolower($status)) {
            $wc_order->add_order_note('Transaction status - ' . $status);
            $this->payment_complete($wc_order, $transaction_id, $note ?? __('Blink payment completed', 'woocommerce'));
        } else if (strpos(strtolower($source), 'direct debit') !== false || 'pending submission' === strtolower($status)) {
            $this->payment_on_hold($wc_order, $note ?? sprintf(__('Payment pending (%s).', 'woocommerce'), 'Transaction status - ' . $status));
        } else {
            $this->payment_failed($wc_order, $note ?? sprintf(__('Payment Failed (%s).', 'woocommerce'), 'Transaction status - ' . $status));
        }
    } 

    /*
     * In case we need a webhook, like PayPal IPN etc
     */
    public function webhook() {
       global $wpdb;
       $order_id = '';

       $body  =  file_get_contents('php://input');
       if ($body) {
           $request = json_decode($body, true);
       }
       $transaction_id = $request['transaction_id'] ?? '';
       if ($transaction_id) {
            $marchant_data = $request['merchant_data'];
            if (!empty($marchant_data)) {
                $order_id = $marchant_data['order_info']['order_id'] ?? '';
            }
            if (!$order_id) {
                $order_id = $wpdb->get_var("SELECT `post_id`
                FROM ".$wpdb->postmeta."
                WHERE (`meta_key` = '_transaction_id' AND `meta_value` = '".$transaction_id."') OR (`meta_key` = 'blink_res' AND `meta_value` = '".$transaction_id."')");
            }

            $status = $request['status'] ?? '';
            $note = $request['note'] ?? '';
            $order = wc_get_order($order_id);
            if ($order) {
                $this->change_status($order, $transaction_id, $status, '', $note);
                $order->update_meta_data('_debug', $body);

                    $response =  [
                        'order_id' => $order_id,
                        'order_status' => $status,
                    ];
                    echo json_encode($response);
                    exit;
            }
        }
        $response =  [
            'transaction_id' => $transaction_id ?? null,
            'error' => 'no order found with this transaction id',
        ];
       echo json_encode($response);
       exit;
    }

    public function validate_transaction($transaction) {
        $responseCode = $transaction ?? '';

        $url = $this->host_url . '/pay/v1/transactions/' . $responseCode;
        $response = wp_remote_get($url, array(
            'method'      => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->accessToken,
            )
        ));

        $redirect = trailingslashit(wc_get_checkout_url());

        if (wp_remote_retrieve_response_code($response) == 200) {
            $apiBody = json_decode(wp_remote_retrieve_body($response), true);
            $this->checkAPIException($apiBody, $redirect);
            return $apiBody['data'] ?? [];
        } else {
            $error_message = wp_remote_retrieve_response_message($response);
            wc_add_notice($error_message, 'error');
        }

        wp_redirect($redirect, 302);
        exit();
    }

    public function change_title($title) {
        global $wp;
        $order_id = $wp->query_vars['order-received'];
        $order = wc_get_order($order_id);

        if ($order->has_status('failed')) {
            return __('Order Failed', 'woocommerce');
        }

        return $title;
    }



    public function check_response_for_order($order_id) {
        if ($order_id) {
            $wc_order = wc_get_order($order_id);
            if (!$wc_order->needs_payment()) {
                return;
            }

            if ($wc_order->get_meta('_blink_res_expired', true) == 'true') {
                return;
            }

            $transaction = $wc_order->get_meta('blink_res', true);
            $transaction_result = $this->validate_transaction($transaction);

            if (!empty($transaction_result)) {
                $status = $transaction_result['status'] ?? '';
                $source = $transaction_result['payment_source'] ?? '';
                $message = $transaction_result['message'] ?? '';

                $wc_order->update_meta_data('_blink_status', $status);
                $wc_order->update_meta_data('payment_type', $source);
                $wc_order->update_meta_data('_blink_res_expired', 'true');
                $wc_order->set_transaction_id($transaction_result['transaction_id']);
                $wc_order->add_order_note('Pay by ' . $source);
                $wc_order->add_order_note('Transaction Note: ' . $message);
                $wc_order->save();

                $this->change_status($wc_order, $transaction_result['transaction_id'], $status, $source);

            } else {
                $this->payment_failed($wc_order, sprintf(__('Payment Failed (%s).', 'woocommerce'), 'Transaction status - Null'));
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
    public function payment_complete($order, $txn_id = '', $note = '') {
        if (!$order->has_status(array('processing', 'completed'))) {
            if ($note) {
                $order->add_order_note($note);
            }

            $order->payment_complete($txn_id);

            if (isset(WC()->cart)) {
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
    public function payment_on_hold($order, $reason = '') {
        $order->update_status('on-hold', $reason);
        if ($reason) {
            $order->add_order_note($reason);
        }

        if (isset(WC()->cart)) {
            WC()->cart->empty_cart();
        }
    }

    /**
     * Hold order and add note.
     *
     * @param  WC_Order $order Order object.
     * @param  string   $reason Reason why the payment is on hold.
     */
    public function payment_failed($order, $reason = '') {
        $order->update_status('failed', $reason);
        if ($reason) {
            $order->add_order_note($reason);
        }
    }

    public function capture_payment() {
        return false;
    }
}