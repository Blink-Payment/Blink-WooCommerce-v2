<?php
// phpcs:ignoreFile

if (!function_exists('blink_insert_array_at_position')) {
    function blink_insert_array_at_position($array, $insert, $position)
    {
        return array_slice($array, 0, $position, true) + $insert + array_slice($array, $position, null, true);
    }
}

if (!function_exists('blink_is_safari')) {
    function blink_is_safari()
    {
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            if (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('blink_transform_word')) {
    function blink_transform_word($word)
    {
        $transformations = array(
            'credit-card'  => 'Card',
            'direct-debit' => 'Direct Debit',
            'open-banking' => 'Open Banking',
        );
        return array_key_exists($word, $transformations) ? $transformations[$word] : $word;
    }
}

if (!function_exists('blink_check_timestamp_expired')) {
    function blink_check_timestamp_expired($timestamp)
    {
        $current_time = time();
        $expiry_time  = strtotime($timestamp);
        return $current_time > $expiry_time ? 0 : 1;
    }
}

if (!function_exists('blink_get_time_diff')) {
    function blink_get_time_diff($order)
    {
        $order_date_time   = new DateTime($order->get_date_created()->date('Y-m-d H:i:s'));
        $current_date_time = new DateTime();
        $time_difference   = $current_date_time->diff($order_date_time);
        return $time_difference->days > 0 || $time_difference->h >= 24;
    }
}

if (!function_exists('blink_check_CCPayment')) {
    function blink_check_CCPayment($source)
    {
        $payment_types = array('direct debit', 'open banking');
        foreach ($payment_types as $type) {
            if (preg_match('/\b' . strtolower($type) . '\b/i', $source)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('blink_get_customer_data')) {
    function blink_get_customer_data($order)
    {
        return array(
            'customer_id'        => $order->get_user_id(),
            'customer_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email'     => $order->get_billing_email(),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name'  => $order->get_billing_last_name(),
            'billing_company'    => $order->get_billing_company(),
            'billing_email'      => $order->get_billing_email(),
            'billing_phone'      => $order->get_billing_phone(),
            'billing_address_1'  => $order->get_billing_address_1(),
            'billing_address_2'  => $order->get_billing_address_2(),
            'billing_postcode'   => $order->get_billing_postcode(),
            'billing_city'       => $order->get_billing_city(),
            'billing_state'      => $order->get_billing_state(),
            'billing_country'    => $order->get_billing_country(),
        );
    }
}

if (!function_exists('blink_get_order_data')) {
    function blink_get_order_data($order)
    {
        return array(
            'order_id'           => $order->get_id(),
            'order_number'       => $order->get_order_number(),
            'order_date'         => gmdate('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)),
            'shipping_total'     => $order->get_total_shipping(),
            'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2),
            'tax_total'          => wc_format_decimal($order->get_total_tax(), 2),
            'cart_discount'      => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2),
            'order_discount'     => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2),
            'discount_total'     => wc_format_decimal($order->get_total_discount(), 2),
            'order_total'        => wc_format_decimal($order->get_total(), 2),
            'order_currency'     => $order->get_currency(),
            'customer_note'      => $order->get_customer_note(),
        );
    }
}

if (!function_exists('blink_get_payment_information')) {
    function blink_get_payment_information($order_id)
    {
        $order = wc_get_order($order_id);
        return wp_json_encode(
            array(
                'payer_info' => blink_get_customer_data($order),
                'order_info' => blink_get_order_data($order),
            )
        );
    }
}

if (!function_exists('blink_error_payment_process')) {
    function blink_error_payment_process($apiBody = array())
    {
        $error = __('Error! Something went wrong.', 'blink-payment-checkout');
        if (is_array($apiBody) && !empty($apiBody)) {
            if (isset($apiBody['success']) && $apiBody['success'] === false) {
                $error = $apiBody['message'] ?? $apiBody['error'] ?? $error;
            } else {
                $error = $apiBody['error'] ?? $apiBody['message'] ?? $error;
            }
        }
        return array(
            'result'   => 'failure',
            'messages' => $error,
            'refresh'  => true,
            'reload'   => false,
        );
    }
}

if (!function_exists('blink_get_status')) {
    function blink_get_status($status = '', $source = '')
    {
        $status = urldecode($status);
        if (in_array(strtolower($status), ['tendered', 'captured', 'success', 'accept', 'paid'], true)) {
            return 'complete';
        } elseif (strpos(strtolower($source), 'direct debit') !== false || strtolower($status) === 'pending submission') {
            return 'hold';
        }
        return 'failed';
    }
}

if (!function_exists('blink_change_status')) {
    function blink_change_status($wc_order, $transaction_id, $status = '', $source = '', $note = null)
    {
        $wc_order->add_order_note(__('Transaction status - ', 'blink-payment-checkout') . $status);
        if (blink_get_status($status, $source) === 'complete') {
            blink_payment_complete($wc_order, $transaction_id, $note ?: __('Blink payment completed', 'blink-payment-checkout'));
        } elseif (blink_get_status($status, $source) === 'hold') {
            blink_payment_on_hold($wc_order, $note ?: __('Payment Pending (Transaction status - ', 'blink-payment-checkout') . $status . ')');
        } else {
            blink_payment_failed($wc_order, $note ?: __('Payment Failed (Transaction status - ', 'blink-payment-checkout') . $status . ')');
        }
    }
}

if (!function_exists('blink_payment_complete')) {
    /**
     * Complete order, add transaction ID and note.
     *
     * @param WC_Order $order Order object.
     * @param string   $txn_id Transaction ID.
     * @param string   $note Payment note.
     */
    function blink_payment_complete($order, $txn_id = '', $note = '')
    {
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
}

if (!function_exists('blink_payment_on_hold')) {
    /**
     * Hold order and add note.
     *
     * @param WC_Order $order Order object.
     * @param string   $reason Reason why the payment is on hold.
     */
    function blink_payment_on_hold($order, $reason = '')
    {
        $order->update_status('on-hold', $reason);
        if ($reason) {
            $order->add_order_note($reason);
        }
        if (isset(WC()->cart)) {
            WC()->cart->empty_cart();
        }
    }
}

if (!function_exists('blink_payment_failed')) {
    /**
     * Mark order as failed and add note.
     *
     * @param WC_Order $order Order object.
     * @param string   $reason Reason why the payment failed.
     */
    function blink_payment_failed($order, $reason = '')
    {
        $order->update_status('failed', $reason);
        if ($reason) {
            $order->add_order_note($reason);
        }
    }
}

if (!function_exists('blink_is_in_admin_section')) {
    /**
     * Check if the current page is in the admin section for Blink settings.
     *
     * @return bool
     */
    function blink_is_in_admin_section()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'checkout' && isset($_GET['section']) && $_GET['section'] === 'blink') {
            return true;
        }
        return false;
    }
}

if (!function_exists('blink_add_notice')) {
    /**
     * Add an admin notice for Blink errors.
     *
     * @param array $apiBody API response body.
     */
    function blink_add_notice($apiBody = array())
    {
        $error = __('Error! Something went wrong.', 'blink-payment-checkout');
        if (is_array($apiBody) && !empty($apiBody)) {
            if (isset($apiBody['success']) && $apiBody['success'] === false) {
                $error = $apiBody['message'] ?? $apiBody['error'] ?? $error;
            } else {
                $error = $apiBody['error'] ?? $apiBody['message'] ?? $error;
            }
        }

        if (!blink_is_in_admin_section()) {
            $adminnotice = new WC_Admin_Notices();
            $adminnotice->add_custom_notice('blink-error', $error);
        }
    }
}

if (!function_exists('blink_generate_applepay_domains')) {
    /**
     * Generate Apple Pay domains.
     */
    function blink_generate_applepay_domains()
    {
        $configs    = include __DIR__ . '/../config.php';
        $host_url   = $configs['host_url'] . '/api';
        $settings   = get_option('woocommerce_blink_settings');
        $testmode   = 'yes' === $settings['testmode'];
        $api_key    = $testmode ? $settings['test_api_key'] : $settings['api_key'];
        $secret_key = $testmode ? $settings['test_secret_key'] : $settings['secret_key'];

        // Check for nonce security
        check_ajax_referer('generate_applepay_domains_nonce', 'security');

        $url         = $host_url . '/pay/v1/applepay/domains';
        $requestData = array(
            'domain_name' => sanitize_text_field($_POST['domain']),
        );

        $response = wp_remote_post(
            $url,
            array(
                'method'  => 'POST',
                'headers' => array('Authorization' => 'Bearer ' . sanitize_text_field($_POST['token'])),
                'body'    => $requestData,
            )
        );

        $apiBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($apiBody['success'] == 'true') {
            update_option('blink_apple_domain_auth', 1);
            return wp_send_json_success(array('message' => $apiBody['message']));
        }

        return wp_send_json_error(array('message' => $apiBody['message'] ? $apiBody['message'] : __('Integration unsuccessful', 'blink-payment-checkout')));

        wp_die();
    }
}

if (!function_exists('blink_generate_access_token')) {
    /**
     * Generate access token for Blink API.
     */
    function blink_generate_access_token()
    {
        $configs    = include __DIR__ . '/../config.php';
        $host_url   = $configs['host_url'] . '/api';
        $settings   = get_option('woocommerce_blink_settings');
        $testmode   = 'yes' === $settings['testmode'];
        $api_key    = $testmode ? $settings['test_api_key'] : $settings['api_key'];
        $secret_key = $testmode ? $settings['test_secret_key'] : $settings['secret_key'];

        // Check for nonce security
        check_ajax_referer('generate_access_token_nonce', 'security');

        $url = $host_url . '/pay/v1/tokens';

        $requestData = array(
            'api_key'                 => $api_key,
            'secret_key'              => $secret_key,
            'source_site'             => get_bloginfo('name'),
            'application_name'        => 'Woocommerce Blink ' . $configs['version'],
            'application_description' => 'WP-' . get_bloginfo('version') . ' WC-' . WC_VERSION,
            'address_postcode_required' => true,
            'send_blink_receipt' => false,
        );

        $response = wp_remote_post(
            $url,
            array(
                'method' => 'POST',
                'body'   => $requestData,
            )
        );
        $apiBody  = json_decode(wp_remote_retrieve_body($response), true);

        if (201 == wp_remote_retrieve_response_code($response)) {
            return wp_send_json_success(array('access_token' => $apiBody['access_token']));
        }

        return wp_send_json_error(array('message' => __('Failed to generate access token', 'blink-payment-checkout')));

        wp_die();
    }
}

if (!function_exists('blink_write_log')) {
    /**
     * Write debug logs.
     *
     * @param mixed $data Data to log.
     */
    function blink_write_log($data)
    {
        if (empty($data)) {
            return;
        }
        if (true === WP_DEBUG) {
            if (is_array($data) || is_object($data)) {
                error_log(print_r($data, true));
            } else {
                error_log($data);
            }
        }
    }
}

if (!function_exists('blink_is_checkout_block')) {
    /**
     * Check if the checkout page uses Checkout Blocks.
     *
     * @return bool
     */
    function blink_is_checkout_block()
    {
        return WC_Blocks_Utils::has_block_in_page(get_queried_object_id(), 'woocommerce/checkout');
    }
}

if (!function_exists('decodeUnicodeString')) {
    /**
     * Decode Unicode strings.
     *
     * @param string $string Unicode string.
     * @return string Decoded string.
     */
    function decodeUnicodeString($string)
    {
        $string = urldecode($string);
        $string = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
        }, $string);
        return $string;
    }
}

if (!function_exists('decodeUnicodeJSON')) {
    /**
     * Decode Unicode JSON strings.
     *
     * @param string $jsonString JSON string.
     * @return array Decoded array.
     */
    function decodeUnicodeJSON($jsonString)
    {
        $data = json_decode(wp_unslash($jsonString), true);

        array_walk_recursive($data, function (&$item) {
            if (is_string($item)) {
                $item = decodeUnicodeString($item);
            }
        });

        return $data;
    }
}

if (!function_exists('pay_action')) {
    /**
     * Process payment action.
     */
    function pay_action()
    {
        $checkout = WC_Checkout::instance();
        $response = $checkout->process_checkout();
        $result   = json_decode($response, true);
        if ($result['result'] === 'failure') {
            wp_redirect(trailingslashit(wc_get_checkout_url()));
        }
    }
}

if (!function_exists('blink_3d_allow_html')) {
    /**
     * Allow specific HTML tags and attributes.
     *
     * @return array Allowed HTML tags and attributes.
     */
    function blink_3d_allow_html()
    {
        return array(
            'form' => array(
                'id'     => true,
                'method' => true,
                'action' => true,
                'name'   => true,
            ),
            'input' => array(
                'type'  => true,
                'name'  => true,
                'value' => true,
                'id'    => true,
                'class' => true,
                'placeholder' => true,
            ),
            'script' => array(
                'nonce'      => true,
                'type'       => true,
                'src'        => true,
                'async'      => true,
                'onload'     => true,
                'defer'      => true,
                'integrity'  => true,
                'crossorigin' => true,
            ),
            'div' => array(
                'class' => true,
                'id'    => true,
                'style' => true,
            ),
            'label' => array(
                'class' => true,
                'for'   => true,
            ),
            'link' => array(
                'rel'  => true,
                'href' => true,
            ),
            'apple-pay-button' => array(
                'buttonstyle' => true,
                'type'        => true,
                'id'          => true,
                'locale'      => true,
                'onclick'     => true,
            ),
            'a' => array(
                'class' => true,
                'id'    => true,
                'href'  => true,
                'data-toggle' => true,
            ),
        );
    }
}
