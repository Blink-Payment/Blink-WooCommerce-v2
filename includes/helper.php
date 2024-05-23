<?php
function insertArrayAtPosition( $array, $insert, $position ) { 
	/*
	$array : The initial array i want to modify
	$insert : the new array i want to add, eg array('key' => 'value') or array('value')
	$position : the position where the new array will be inserted into. Please mind that arrays start at 0
	*/
	return array_slice($array, 0, $position, true) + $insert + array_slice($array, $position, null, true);
}

function isSafari() {
    // Check if the User-Agent header is set
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        // Check if the User-Agent string contains "Safari" and not "Chrome"
        if (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) {
            return true;
        }
    }
    return false;
}

function transformWord($word) {
	// Define transformation rules
	$transformations = array(
		"credit-card" => "Card",
		"direct-debit" => "Direct Debit",
		"open-banking" => "Open Banking"
	);
	
	// Check if the word exists in the transformation rules
	if (array_key_exists($word, $transformations)) {
		return $transformations[$word];
	} else {
		return $word; // Return the original word if no transformation is found
	}
}

function isTimestampExpired($timestamp) {
	$current_time = time(); // Get the current Unix timestamp
	$expiry_time = strtotime($timestamp); // Convert the provided timestamp to Unix timestamp

	if ($current_time > $expiry_time) {
		return true;
	} 

	false;
}

function get_time_diff($order)
{
	$order_date_time = new DateTime($order->get_date_created()->date('Y-m-d H:i:s'));
	$current_date_time = new DateTime();
	$time_difference = $current_date_time->diff($order_date_time);

	if ($time_difference->days > 0 || $time_difference->h >= 24) {
		return true;
	} 

	return false;
}

function checkCCPayment($source)
{
	$payment_types = ['direct debit', 'open banking'];
	foreach ($payment_types as $type) {
		if (preg_match('/\b' . strtolower($type) . '\b/i', $source)) {
			// Payment method matches one of the specified types
			return false; // Or handle the case here and break the loop
		}
	}

	return true;
}
function get_customer_data( $order ) { 
	return ['customer_id' => $order->get_user_id(), 'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'customer_email' => $order->get_billing_email(), 'billing_first_name' => $order->get_billing_first_name(), 'billing_last_name' => $order->get_billing_last_name(), 'billing_company' => $order->get_billing_company(), 'billing_email' => $order->get_billing_email(), 'billing_phone' => $order->get_billing_phone(), 'billing_address_1' => $order->get_billing_address_1(), 'billing_address_2' => $order->get_billing_address_2(), 'billing_postcode' => $order->get_billing_postcode(), 'billing_city' => $order->get_billing_city(), 'billing_state' => $order->get_billing_state(), 'billing_country' => $order->get_billing_country(), ];
}
function get_order_data( $order ) { 
	return ['order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'order_date' => gmdate('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)), 'shipping_total' => $order->get_total_shipping(), 'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2), 'tax_total' => wc_format_decimal($order->get_total_tax(), 2), 'cart_discount' => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2), 'order_discount' => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2), 'discount_total' => wc_format_decimal($order->get_total_discount(), 2), 'order_total' => wc_format_decimal($order->get_total(), 2), 'order_currency' => $order->get_currency(), 'customer_note' => $order->get_customer_note(), ];
}
function get_payment_information( $order_id ) { 
	$order = wc_get_order($order_id);
	return json_encode(['payer_info' => get_customer_data($order), 'order_info' => get_order_data($order), ]);
}

function error_payment_process()
{
	$notices = wc_get_notices('error');
	if(empty($notices)){
		$notices[] = ['notice' => 'Something went wrong! Payment not completed.'];
	}
	$html = '<ul class="woocommerce-error" role="alert">';
		foreach ( $notices as $notice ){
			$html .= '<li'.wc_get_notice_data_attr( $notice ).'>
						'.wc_kses_notice( $notice['notice'] ).'
					</li>';
		}
	$html .='</ul>';

	$response = [
		"result"=> "failure",
		"messages"=> $html,
		"refresh"=> false,
		"reload"=> false
	];
	wc_clear_notices();
	echo json_encode($response); wp_die();
}

function change_status( $wc_order, $transaction_id, $status = '', $source = '', $note = null ) { 
	if ('tendered' === strtolower($status) || 'captured' === strtolower($status) || 'success' === strtolower($status) || 'accept' === strtolower($status)) {
		$wc_order->add_order_note('Transaction status - ' . $status);
		payment_complete($wc_order, $transaction_id, !empty($note) ? $note : 'Blink payment completed');
	} elseif (strpos(strtolower($source), 'direct debit') !== false || 'pending submission' === strtolower($status)) {
		payment_on_hold($wc_order, !empty($note) ? $note : 'Payment Pending (Transaction status - ' . $status . ')');
	} else {
		payment_failed($wc_order, !empty($note) ? $note : 'Payment Failed (Transaction status - ' . $status . ')');
	}
}

/**
 * Complete order, add transaction ID and note.
 *
 * @param  WC_Order $order Order object.
 * @param  string   $txn_id Transaction ID.
 * @param  string   $note Payment note.
 */
function payment_complete( $order, $txn_id = '', $note = '' ) { 
	if (!$order->has_status(['processing', 'completed'])) {
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
function payment_on_hold( $order, $reason = '' ) { 
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
function payment_failed( $order, $reason = '' ) { 
	$order->update_status('failed', $reason);
	if ($reason) {
		$order->add_order_note($reason);
	}
}

function get_element_key($method)
{
	$key = '';
	if($method == 'credit-card')
	{
		$key = 'ccElement';
	}
	if($method == 'direct-debit')
	{
		$key = 'ddElement';
	}
	if($method == 'open-banking')
	{
		$key = 'obElement';
	}

	return $key;
}

function is_in_admin_section()
{
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' && isset( $_GET['section'] ) && $_GET['section'] === 'blink' ) 
	{
		return true;
	}

 return false;
}

function blink_add_notice($apiBody=[])
{
	// Initialize $error with a default value
	$error = 'Unknown error! Something went wrong.';

	// Check if $apiBody is an array and not null
	if (is_array($apiBody) && !empty($apiBody)) {
		// Check if the 'success' key exists and its value is false
		if (isset($apiBody['success']) && $apiBody['success'] === false) {
			// Check if the 'message' key exists and is not null
			if (isset($apiBody['message'])) {
				$error = $apiBody['message'];
			} elseif (isset($apiBody['error'])) {
				// If 'message' key does not exist, check if 'error' key exists and is not null
				$error = $apiBody['error'];
			}
		} elseif (isset($apiBody['error'])) {
			// If 'success' key does not exist or its value is not false, check if 'error' key exists and is not null
			$error = $apiBody['error'];
		}
	}

	if(!is_in_admin_section()){
		wc_add_notice($error, 'error');
	} else {
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->add_custom_notice('blink-error', $error);
	}

}

?>

