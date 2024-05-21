<?php
class WC_Blink_Gateway extends WC_Payment_Gateway {

		public $token;
		public $intent;
		public $paymentMethods = [];
		public $paymentSource;
		public $paymentStatus;

	public function __construct() {

		$this->configs = include dirname(__FILE__) . '/../config.php';
		$this->id = str_replace(' ', '', strtolower($this->configs['method_title']));
		$this->icon = plugins_url('/../assets/img/logo.png', __FILE__);
		$this->has_fields = true; // in case you need a custom credit card form
		$this->method_title = $this->configs['method_title'];
		$this->method_description = $this->configs['method_description'];
		$this->host_url = $this->configs['host_url'] . '/api';
		$this->version = $this->configs['version'];
		// gateways can support subscriptions, saved payment methods,
		// but in this tutorial we begin with simple payments and refunds
		$this->supports    = [
			'products',
			'refunds',
		];
		// Load the settings.
		$this->init_settings();
		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = $this->get_option('enabled');
		$this->testmode = 'yes' === $this->get_option('testmode');
		$this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
		$this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');
		$token = get_option('blink_admin_token');
		
		$selectedMethods = [];
		if (is_array($token) && isset($token['payment_types'])) {
			foreach ($token['payment_types'] as $type) {
				$selectedMethods[] = ('yes' === $this->get_option($type)) ? $type : '';
			}
		}
		$this->paymentMethods = array_filter($selectedMethods);
		$this->add_error_notices();
		
		// Method with all the options fields
		$this->init_form_fields();
		// This action hook saves the settings
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options' ]);
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'blink_process_admin_options' ], 99);
		// if needed we can use this webhook
		add_action('woocommerce_api_wc_blink_gateway', [$this, 'webhook']);
		add_action('woocommerce_thankyou_blink', [$this, 'check_response_for_order', ]);
		add_filter('woocommerce_endpoint_order-received_title', [$this, 'change_title'], 99);

		add_filter('woocommerce_admin_order_should_render_refunds', array($this, 'should_render_refunds'), 10, 3);
		add_filter('woocommerce_order_item_add_action_buttons', array($this, 'add_cancel_button'), 10);
		add_action('admin_enqueue_scripts',  array($this,'blink_enqueue_scripts'), 10);
		add_action('wp_ajax_cancel_transaction', array($this,'blink_cancel_transaction'));
		// We need custom JavaScript to obtain a token
		add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
		delete_transient('blink_token');
		delete_transient('blink_intent');

	}

	public function blink_process_admin_options()
	{
		if(isset($_POST['woocommerce_blink_testmode']) && $_POST['woocommerce_blink_testmode'] == '1')
		{
			$this->api_key = $_POST['woocommerce_blink_test_api_key'];
			$this->secret_key = $_POST['woocommerce_blink_test_secret_key'];
		}
		else{
			$this->api_key = $_POST['woocommerce_blink_api_key'];
			$this->secret_key = $_POST['woocommerce_blink_secret_key'];
		}
		$token = $this->generate_access_token();
		update_option('blink_admin_token',$token);
	}

	public function get_time_diff($order)
	{
		$order_date_time = new DateTime($order->get_date_created()->date('Y-m-d H:i:s'));
		$current_date_time = new DateTime();
		$time_difference = $current_date_time->diff($order_date_time);

		if ($time_difference->days > 0 || $time_difference->h >= 24) {
			return true;
		} 

		return false;
	}
	public function process_refund($order_id, $amount = null, $reason = '__') {
		$order = wc_get_order($order_id);
	
		// Get the transaction ID from order meta
		$transaction_id = $order->get_meta('blink_res');
	
		// Exit if transaction ID is not found
		if (!$transaction_id) {
			$order->add_order_note('Transaction ID not found.');
			return new WP_Error('invalid_order', __('Transaction ID not found.', 'woocommerce'));
		}
	
		// Check if there were previous partial refunds
		$previous_refund_amount = isset($_POST['refunded_amount']) ? wc_format_decimal($_POST['refunded_amount']) : 0;
	
		// Determine if it's a partial refund
		$partial_refund = !empty($previous_refund_amount) ? true : ($amount < $order->get_total());
	
		// Prepare refund request data
		$requestData = array(
			'partial_refund' => $partial_refund,
			'amount' => $amount,
			'reference' => $reason
		);
	
		$this->token = $this->generate_access_token();
		// Prepare request headers
		$headers = array('Authorization' => 'Bearer ' . $this->token['access_token']);
	
		// Send refund request
		$url = $this->host_url . '/pay/v1/transactions/' . $transaction_id . '/refunds';
		$response = wp_remote_post($url, array(
			'headers' => $headers,
			'body' => $requestData,
		));
	
		// Check if the refund request was successful
		if (is_wp_error($response)) {
			$order->add_order_note('Refund request failed: ' . $response->get_error_message());
			return new WP_Error('refund_failed', __('Refund request failed.', 'woocommerce'));
		}
	
		$data = json_decode(wp_remote_retrieve_body($response), true);
	
		// Add refund notes to the order
		if ($data['success']) {
			$refund_note = $data['message'] . ' (Transaction ID: ' . $data['transaction_id'] . ')';
			$order->add_order_note($refund_note);
			$refund_type = $partial_refund ? 'Partial' : 'Full';
			$order->add_order_note($refund_type . ' refund of ' . wc_price($amount) . ' processed successfully. Reason: '.$reason);
			if(($amount + $previous_refund_amount) >= $order->get_total()){
				$order->update_status('refunded');
			}
		} else {
			$message = $data['error'] ? $data['error'] : $data['message'];
			$order->add_order_note('Refund request failed: ' . $message);
			return new WP_Error('refund_failed', __('Refund request failed.' . $message, 'woocommerce'));
		}
	
		// Return true on successful refund
		return true;
	}
	public function cancel_transaction($transaction_id) {
                $url = $this->host_url . '/pay/v1/transactions/' . $transaction_id . '/cancels';

				$this->token = $this->generate_access_token();
				// Prepare request headers
				$headers = array('Authorization' => 'Bearer ' . $this->token['access_token']);
		
				$response = wp_remote_post($url, ['headers' => $headers]);

                if (is_wp_error($response)) {
                        wc_add_notice('Error fetching transaction status: ' . $response->get_error_message(), 'error');
                        return;
                }

                $data = json_decode(wp_remote_retrieve_body($response), true);

                return $data;
	}
	public function blink_enqueue_scripts($hook) {
		if ($hook === 'post.php') {
			wp_enqueue_script('woocommerce_blink_payment_admin_scripts', plugins_url('/../assets/js/admin-scripts.js', __FILE__), ['jquery'], $this->version, true);
			wp_localize_script('woocommerce_blink_payment_admin_scripts', 'blinkOrders', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'cancel_order' => wp_create_nonce('cancel_order_nonce'),
				'spin_gif' => plugins_url('/../assets/img/wpspin.gif', __FILE__)
			));
		}
	}
	public function add_cancel_button($order) {

		$transaction_id = $order->get_meta('blink_res');

		if (!$transaction_id) {
			return; // Exit if transaction ID is not found
		}

		if($this->checkCCPayment($this->paymentSource))
		{
			echo '<style>
			.cancel-order-container {
				position: relative;
				display: inline-block;
				float: left;
			}
			
			.cancel-order-tooltip {
				position: absolute;
				top: -30px; /* Adjust as needed */
				left: 100%; /* Adjust as needed */
				width: auto;
				background-color: #555;
				color: #fff;
				padding: 5px;
				border-radius: 5px;
				font-size: 12px;
				white-space: nowrap;
				z-index: 999;
				display: none;
			}
			
			.cancel-order-container:hover .cancel-order-tooltip {
				display: block;
			}
			

			</style>
			';

			if (strtolower($this->paymentStatus) === 'captured' && $this->get_time_diff($order) !== true) {
				// If status is captured, display cancel button
				
				echo '<div class="cancel-order-container">';
				echo '<button type="button" class="button cancel-order" data-order-id="' . $order->get_id() . '">' . __('Cancel Order', 'woocommerce') . '</button>';
				echo '<span class="cancel-order-tooltip" data-tip="' . __('It will cancel the transaction.', 'woocommerce') . '">' . __('It will cancel the transaction.', 'woocommerce') . '</span>';
				echo '</div>';
				
			}
		}
	
		return;
	}
	// New function to fetch transaction status
	private function get_transaction_status($transaction_id, $order = null) {
		$url = $this->host_url . '/pay/v1/transactions/'.$transaction_id;

		$this->token = $this->generate_access_token();
		// Prepare request headers
		$headers = array('Authorization' => 'Bearer ' . $this->token['access_token']);


		$response = wp_remote_get($url, ['headers' => $headers]);
		
				if (is_wp_error($response)) {
						wc_add_notice('Error fetching transaction status: ' . $response->get_error_message(), 'error');
						return;
				}

				$data = json_decode(wp_remote_retrieve_body($response));

		$this->paymentSource = $data->data->payment_source ? $data->data->payment_source : '';
		$this->paymentStatus = $data->data->status ? $data->data->status : '';
	}

	public function should_render_refunds($render_refunds, $order, $wc_order) {
		$transaction_id = get_post_meta($order, 'blink_res', true);
		$WCOrder = wc_get_order($order);

		if (!$transaction_id) {
			return $render_refunds; // No Blink transaction, use default behavior
		}

		$this->transactionID = $transaction_id;

	
		$this->get_transaction_status($transaction_id);
	
		if ($this->checkCCPayment($this->paymentSource)) {
			if(strtolower($this->paymentStatus) === 'captured')
			{
				$render_refunds = false; // Hide default refund if captured

			}
			if($this->get_time_diff($WCOrder) === true)
			{
				$render_refunds = true;
			}
			if(empty($this->paymentStatus))
			{
				$render_refunds = false;
			}
		}

		if ($WCOrder->has_status(['cancelled'])) {
			$render_refunds = false;
		}
	
		return $render_refunds;
	}
	private function checkCCPayment($source)
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
	public function add_error_notices( $payment_types = [] ) { 
		
		if ( !is_admin() && !wp_doing_ajax() ) {
			return;
		}
		
		$adminnotice = new WC_Admin_Notices();
		$token = get_option('blink_admin_token');
		
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' && isset( $_GET['section'] ) && $_GET['section'] === $this->id ) {

			if (empty($this->api_key) || empty($this->secret_key))
		    {
				$live = $this->testmode ? 'Test' : 'Live';
				if (!$adminnotice->has_notice('no-api')) {
					$adminnotice->add_custom_notice('no-api', '<div>Please add '.$live.' API key and Secret Key</div>');
				}

			}
			if (!empty($this->api_key) && !empty($this->secret_key)){
				$adminnotice->remove_notice('no-api');
				if (!empty($token['payment_types'])) {
					if (empty($this->paymentMethods)) {
						if (!$adminnotice->has_notice('no-payment-type-selected')) {
							$adminnotice->add_custom_notice('no-payment-type-selected', '<div>Please select the Payment Methods and save the configuration!</div>');
						}
					} else {
						$adminnotice->remove_notice('no-payment-type-selected');
					}
					$adminnotice->remove_notice('no-payment-types');
				} else {
					if (!$adminnotice->has_notice('no-payment-types')) {
						$adminnotice->add_custom_notice('no-payment-types', '<div>There is no Payment Types Available.</div>');
					}
				}
			}

		}else{
			$adminnotice->remove_notice('no-api');
			$adminnotice->remove_notice('no-payment-type-selected');
			$adminnotice->remove_notice('no-payment-types');

		}
	}
	public function checkAPIException( $response, $redirect ) { 
		if (isset($response['error'])) {
			wc_add_notice($response['error'], 'error');
			wp_redirect($redirect, 302);
			exit();
		}
		return;
	}
	public function generate_access_token() { 
		    
		$url = $this->host_url . '/pay/v1/tokens';
		$response = wp_remote_post($url, ['method' => 'POST', 'body' => ['api_key' => $this->api_key, 'secret_key' => $this->secret_key, ], ]);
		if (201 == wp_remote_retrieve_response_code($response)) {
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);
			return $apiBody;
		}

		return [];
			
	}
	/**
	 * Get the return url (thank you page).
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function get_return_url( $order = null ) { 
		if ($order) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url('order-received', '', wc_get_checkout_url());
		}
		return apply_filters('woocommerce_get_return_url', $return_url, $order);
	}
	public function create_payment_intent($access_token='', $method = '', $order = null) {

        $cartAmount = WC()->cart->get_total('raw');
        $cartAmount = !empty($cartAmount) ? $cartAmount : '1.0';
		
		$amount = !empty($order) ? $order->get_total() : $cartAmount;
		$requestData = ['card_layout' => 'single-line', 'amount' => $amount, 'payment_type' => 'credit-card', 'currency' => get_woocommerce_currency(), 'return_url' => $this->get_return_url($order), 'notification_url' => WC()->api_request_url('wc_blink_gateway'), ];
		$url = $this->host_url . '/pay/v1/intents';
		$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $access_token ], 'body' => $requestData, ]);
		$redirect = trailingslashit(wc_get_checkout_url());
		if (201 == wp_remote_retrieve_response_code($response)) {
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);
			return $apiBody;
		}

		return [];
	}

	public function update_payment_intent($access_token='', $method = '', $order = null, $id = null)
	{
		$this->token = $this->generate_access_token();
		
		$requestData = ['amount' => $order->get_total(), 'return_url' => $this->get_return_url($order) ];
		if($id){
			$url = $this->host_url . '/pay/v1/intents/'.$id;
			$response = wp_remote_post($url, ['method' => 'PATCH', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], ], 'body' => $requestData, ]);
			if (200 == wp_remote_retrieve_response_code($response)) {
				$apiBody = json_decode(wp_remote_retrieve_body($response), true);
				return $apiBody;
			}
		}

		return [];

	}

	public function isTimestampExpired($timestamp) {
		$current_time = time(); // Get the current Unix timestamp
		$expiry_time = strtotime($timestamp); // Convert the provided timestamp to Unix timestamp
	
		if ($current_time > $expiry_time) {
			return true;
		} 

		false;
	}
	/**
	 * Plugin options,
	 */
	public function init_form_fields( $payment_types = [] ) { // call in front end
		if ( !is_admin() && !wp_doing_ajax() ) {
			return;
		}
		$fields = ['enabled' => ['title' => 'Enable/Disable', 'label' => 'Enable Blink Gateway', 'type' => 'checkbox', 'description' => '', 'default' => 'no', ], 'title' => ['title' => 'Title', 'type' => 'text', 'description' => 'This controls the title which the user sees during checkout.', 'default' => 'Blink v2', 'desc_tip' => true, ], 'description' => ['title' => 'Description', 'type' => 'textarea', 'description' => 'This controls the description which the user sees during checkout.', 'default' => 'Pay with your credit card or direct debit at your convenience.', ], 'testmode' => ['title' => 'Test mode', 'label' => 'Enable Test Mode', 'type' => 'checkbox', 'description' => 'Place the payment gateway in test mode using test API keys.', 'default' => 'yes', 'desc_tip' => true, ], 'test_api_key' => ['title' => 'Test API Key', 'type' => 'text', ], 'test_secret_key' => ['title' => 'Test Secret Key', 'type' => 'password', ], 'api_key' => ['title' => 'Live API Key', 'type' => 'text', ], 'secret_key' => ['title' => 'Live Secret Key', 'type' => 'password', ], 'custom_style' => ['title' => 'Custom Style', 'type' => 'textarea', 'description' => 'Do not include style tag', ], ];
		if ($this->api_key && $this->secret_key) {

			$token = get_option('blink_admin_token');


			if (!empty($token) && !empty($token['payment_types'])) {
				$pay_methods['pay_methods'] = ['title' => 'Payment Methods', 'label' => '', 'type' => 'hidden', 'description' => '', 'default' => '', ];
				$fields = insertArrayAtPosition($fields, $pay_methods, count($fields) - 1);
				foreach ($token['payment_types'] as $types) {
					$payment[$types] = ['title' => '', 'label' => ucwords(str_replace('-', ' ', $types)), 'type' => 'checkbox', 'description' => '', 'default' => 'no', ];
					$fields = insertArrayAtPosition($fields, $payment, count($fields) - 1);
				}
			}
		}


		$this->form_fields = $fields;
	}

	public function transformWord($word) {
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
	/**
	 * Credit card form
	 */
	
	 public function payment_fields() {

		if(empty($_REQUEST['payment_method']) || $_REQUEST['payment_method'] != $this->id){
			return;
		}

		$intent = get_transient('blink_intent');
		$element = !empty($intent) ? $intent['element'] : [];
		if(empty($element))
		{
			$token = get_transient('blink_token');
			$expired = true;
			if(!empty($token))
			{
				$expired = $this->isTimestampExpired($token['expired_on']);
			}
			if($expired){
				$token = $this->generate_access_token();
				set_transient( 'blink_token', $token, 15 * MINUTE_IN_SECONDS );
			}
			$intent = $this->create_payment_intent($token['access_token']);
			set_transient( 'blink_intent', $intent, 15 * MINUTE_IN_SECONDS );
			$element = !empty($intent) ? $intent['element'] : [];

		}

		if (is_array($this->paymentMethods) && empty($this->paymentMethods)) {
			echo '<p> Unable to process any payment at this moment! </p>';
		} else {
			if ($this->description) {
				echo '<p>' . esc_html($this->description) . '</p>';
			}
		}

		if (is_array($this->paymentMethods) && !empty($this->paymentMethods)) 
			{
				if(!empty($_REQUEST['post_data'])){
					parse_str($_REQUEST['post_data'], $parsed_data);	
				}else{
					$parsed_data = $_REQUEST;
				}
                $payment_by = !empty($parsed_data['payment_by']) ? $parsed_data['payment_by'] : current($this->paymentMethods);
				if(!empty($element['gpElement'])){
					echo $element['gpElement'];
				}
			?>
			
				<section class="blink-api-section">
					<div class="blink-api-form-stracture">


					    <div class="blink-tabs">
						<?php if(count($this->paymentMethods) > 1): ?>

							<?php foreach ($this->paymentMethods as $method) : ?>
									<div class="blink-pay-options <?php if ($method == $payment_by) echo 'active';?>" data-tab="<?php echo $method; ?>">
										<a href="javascript:void(0);" onclick="updatePaymentBy('<?php echo $method; ?>')"><?php echo $this->transformWord($method); ?></a>
									</div>
								<?php endforeach; ?>
								<?php endif; ?>

						</div>
						
						<section class="blink-api-tabs-content">

						<?php foreach ($this->paymentMethods as $method) : ?>

							
							<div id="tab-<?php echo $method; ?> " class="tab-content <?php if ($method == $payment_by) echo 'active';?>">
							<?php 
								if ($method == $payment_by && 'credit-card' == $payment_by && !empty($element['ccElement'])) {
									echo '<form name="blink-credit" action="" method="">'.$element['ccElement'].'
									 <div style="display:none"><input type="submit" name="submit" id="blink-credit-submit" value="check" /></div>
									</form>
									<input type="hidden" name="credit-card-data" id="credit-card-data" value="" />
									';
								}
								if ($method == $payment_by && 'direct-debit' == $payment_by && !empty($element['ddElement'])) {
									echo $element['ddElement'];
								}
								if ($method == $payment_by && 'open-banking' == $payment_by && !empty($element['obElement'])) {
									echo $element['obElement'];
								}
								
								
								
								?>

							</div>
							<?php endforeach; ?>		
							<input type="hidden" name="payment_by" id="payment_by" value="<?php echo $payment_by; ?>">
						</section>

				</section>
				
			<?php
			} else {
				?>
				<section class="blink-api-section">
					<div class="blink-api-form-stracture">
						<input type="hidden" name="payment_by" value="" />
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
		wp_enqueue_script('blink_l', 'https://code.jquery.com/jquery-3.6.3.min.js', [], $this->version);
		wp_enqueue_script('blink_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js', [], $this->version);
		wp_register_style('woocommerce_blink_payment_style', plugins_url('/../assets/css/style.css', __FILE__), [], $this->version);
		// and this is our custom JS in your plugin directory that works with token.js
		wp_register_script('woocommerce_blink_payment', plugins_url('/../assets/js/custom.js', __FILE__), ['jquery'], $this->version); //
		// in most payment processors you have to use API KEY and SECRET KEY to obtain a token
		wp_localize_script('woocommerce_blink_payment', 'blink_params', ['card'=>in_array('credit-card',$this->paymentMethods),'checkout_url'=> esc_url(add_query_arg(array('method' => 'blink'), wc_get_checkout_url())),'ajaxurl' => admin_url('admin-ajax.php'), 'apiKey' => $this->api_key, 'secretKey' => $this->secret_key, 'remoteAddress' => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '', ]);
		$blinkPay = !empty($_GET['blinkPay']) ? sanitize_text_field($_GET['blinkPay']) : '';
		wp_enqueue_script('woocommerce_blink_payment');
		wp_enqueue_style('woocommerce_blink_payment_style');
		$custom_css = $this->get_option('custom_style');
		if ($custom_css) {
			wp_add_inline_style('woocommerce_blink_payment_style', $custom_css);
		}
		do_action('wc_blink_custom_script');
		do_action('wc_blink_custom_style');
	}
	public function get_customer_data( $order ) { 
		return ['customer_id' => $order->get_user_id(), 'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'customer_email' => $order->get_billing_email(), 'billing_first_name' => $order->get_billing_first_name(), 'billing_last_name' => $order->get_billing_last_name(), 'billing_company' => $order->get_billing_company(), 'billing_email' => $order->get_billing_email(), 'billing_phone' => $order->get_billing_phone(), 'billing_address_1' => $order->get_billing_address_1(), 'billing_address_2' => $order->get_billing_address_2(), 'billing_postcode' => $order->get_billing_postcode(), 'billing_city' => $order->get_billing_city(), 'billing_state' => $order->get_billing_state(), 'billing_country' => $order->get_billing_country(), ];
	}
	public function get_order_data( $order ) { 
		return ['order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'order_date' => gmdate('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)), 'shipping_total' => $order->get_total_shipping(), 'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2), 'tax_total' => wc_format_decimal($order->get_total_tax(), 2), 'cart_discount' => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2), 'order_discount' => defined('WC_VERSION') && WC_VERSION >= 2.3 ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2), 'discount_total' => wc_format_decimal($order->get_total_discount(), 2), 'order_total' => wc_format_decimal($order->get_total(), 2), 'order_currency' => $order->get_currency(), 'customer_note' => $order->get_customer_note(), ];
	}
	public function get_payment_information( $order_id ) { 
		$order = wc_get_order($order_id);
		return json_encode(['payer_info' => $this->get_customer_data($order), 'order_info' => $this->get_order_data($order), ]);
	}
	public function validate_fields() { 
		
			if ('direct-debit' == $_POST['payment_by']) {
				if (empty($_POST['given_name'])) {
					wc_add_notice('Given name is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['family_name'])) {
					wc_add_notice('Family name is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['email'])) {
					wc_add_notice('Email is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['account_holder_name'])) {
					wc_add_notice('Account holder name is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['branch_code'])) {
					wc_add_notice('Branch code is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['account_number'])) {
					wc_add_notice('Account number is required! for Direct Debit Payment with Blink', 'error');
					return false;
				}
			}
			if ('open-banking' == $_POST['payment_by']) {
				if (empty($_POST['customer_name'])) {
					wc_add_notice('User name is required! for Open Banking Payment with Blink', 'error');
					return false;
				}
				if (empty($_POST['customer_email'])) {
					wc_add_notice('User Email is required! for Open Banking Payment with Blink', 'error');
					return false;
				}
			}
			if ('credit-card' == $_POST['payment_by']) {
				if (empty($_POST['customer_name'])) {
					wc_add_notice('Name on the Card is required! for Card Payment with Blink', 'error');
					return false;
				}
			}
		return true;
	}
	public function processOpenBanking( $order, $request ) { 

		 $order_id = $order->get_id();
		 $redirect = trailingslashit(wc_get_checkout_url()) . '?payment_by=open-banking&gateway=blink';

		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){
			$requestData = ['merchant_id' => $this->intent['merchant_id'], 'payment_intent' => $this->intent['payment_intent'], 'user_name' => !empty($request['customer_name']) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'user_email' => !empty($request['customer_email']) ? $request['customer_email'] : $order->get_billing_email(), 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'merchant_data' => $this->get_payment_information($order_id), ];
			$url = $this->host_url . '/pay/v1/openbankings';
			$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], 'user-agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '', 'accept' => !empty($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT']) : '', 'accept-encoding' => 'gzip, deflate, br', 'accept-charset' => 'charset=utf-8', ], 'body' => $requestData, ]);
			if (200 == wp_remote_retrieve_response_code($response)) {
				$apiBody = json_decode(wp_remote_retrieve_body($response), true);
				if ($apiBody['url']) {
					return $apiBody['url']; //redirect_url
				} elseif ($apiBody['redirect_url']) {
					return $apiBody['redirect_url']; //redirect_url
				}
			}
		}
			
		return $redirect;
	}
	public function processDirectDebit( $order, $request ) { 

		$order_id = $order->get_id();
		$redirect = trailingslashit(wc_get_checkout_url()) . '?payment_by=direct-debit&blinkPay=' . $order_id;

		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){

			$requestData = ['payment_intent' => $this->intent['payment_intent'], 'given_name' => !empty($request['given_name']) ? $request['given_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'family_name' => $request['family_name'], 'company_name' => $request['company_name'], 'email' => !empty($request['email']) ? $request['email'] : $order->get_billing_email(), 'country_code' => 'GB', 'account_holder_name' => $request['account_holder_name'], 'branch_code' => $request['branch_code'], 'account_number' => $request['account_number'], 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'merchant_data' => $this->get_payment_information($order_id), ];
			$url = $this->host_url . '/pay/v1/directdebits';
			$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], 'user-agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '', 'accept' => !empty($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT']) : '', 'accept-encoding' => 'gzip, deflate, br', 'accept-charset' => 'charset=utf-8', ], 'body' => $requestData, ]);
			if (200 == wp_remote_retrieve_response_code($response)) {
				$apiBody = json_decode(wp_remote_retrieve_body($response), true);
				$this->checkAPIException($apiBody, $redirect);
				if ($apiBody['url']) {
					$redirect = $apiBody['url'];
				} else {
					wc_add_notice('Something is wrong! Please try again', 'error');
				}
			}

		}


		
		return $redirect;
	}

	public function processCreditCard( $order, $request, $endpoint = 'creditcards' ) { 

		$order_id = $order->get_id();
		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){

			$requestData = ['resource'=> $request['resource'],'payment_intent' => $this->intent['payment_intent'], 'paymentToken' => wp_unslash($request['paymentToken']), 'type' => $request['type'], 'raw_amount' => $request['amount'], 'customer_email' => !empty($request['customer_email']) ? $request['customer_email'] : $order->get_billing_email(), 'customer_name' => !empty($request['customer_name']) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'transaction_unique' => $request['transaction_unique'], 'merchant_data' => $this->get_payment_information($order_id), ];
			if (isset($request['remote_address'])) {
				$requestData['device_timezone'] = $request['device_timezone'];
				$requestData['device_capabilities'] = $request['device_capabilities'];
				$requestData['device_accept_language'] = $request['device_accept_language'];
				$requestData['device_screen_resolution'] = $request['device_screen_resolution'];
				$requestData['remote_address'] = $request['remote_address'];
			}
			 $url = $this->host_url . '/pay/v1/'.$endpoint;
			$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], 'user-agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '', 'accept' => !empty($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT']) : '', 'accept-encoding' => 'gzip, deflate, br', 'accept-charset' => 'charset=utf-8', ], 'body' => $requestData, ]);
			$redirect = trailingslashit(wc_get_checkout_url()) . '?p=credit-card&blinkPay=' . $order_id;
			if (200 == wp_remote_retrieve_response_code($response)) {
				$apiBody = json_decode(wp_remote_retrieve_body($response), true);
				$this->checkAPIException($apiBody, $redirect);
				if (isset($apiBody['acsform'])) {
					$threedToken = $apiBody['acsform'];
					set_transient('blink3dProcess' . $order_id, $threedToken, 300);
					$redirect = trailingslashit(wc_get_checkout_url()) . '?blink3dprocess=' . $order_id;
				} elseif ($apiBody['url']) {
					$redirect = $apiBody['url'];
				} else {
					wc_add_notice('Something is wrong! Please try again', 'error');
				}
			} 
		}
	   
		

		return $redirect;
	}
	/*
	 * We're processing the payments here
	*/
	public function process_payment( $order_id ) { 


			$order = wc_get_order($order_id);
			
			$request = $_POST;
			$result = 'success';

			$this->token = get_transient('blink_token');
			$expired = true;
			if(!empty($this->token))
			{
				$expired = $this->isTimestampExpired($this->token['expired_on']);
			}
			if($expired){
				$this->token = $this->generate_access_token();
			}
			set_transient( 'blink_token', $this->token, 15 * MINUTE_IN_SECONDS );

			$this->intent = get_transient('blink_intent');
			$intent_expired = true;
			if(!empty($this->intent))
			{
				$intent_expired = $this->isTimestampExpired($this->intent['expiry_date']);
			}
			if($intent_expired){
				$this->intent = $this->create_payment_intent($this->token['access_token'], $request['payment_by'], $order);
			}
			else{
				$this->intent = $this->update_payment_intent($this->token['access_token'], $request['payment_by'], $order, $this->intent['id']);
			}
			set_transient( 'blink_intent', $this->intent, 15 * MINUTE_IN_SECONDS );


			$redirect = '';

			if($request['payment_by'] == 'credit-card')
			{
				parse_str($_REQUEST['credit-card-data'], $parsed_data);	

				$redirect = $this->processCreditCard( $order, array_merge($request,$parsed_data));

			}
			if($request['payment_by'] == 'google-pay')
			{
				$redirect = $this->processCreditCard( $order, $request, 'googlepay' );

			}
			if($request['payment_by'] == 'direct-debit')
			{
				$redirect = $this->processDirectDebit( $order, $request );

			}
			if($request['payment_by'] == 'open-banking')
			{
				$redirect = $this->processOpenBanking( $order, $request );

			}
			
		return ['result' => $result, 'redirect' => $redirect, ];
	}
	public function change_status( $wc_order, $transaction_id, $status = '', $source = '', $note = null ) { 
		if ('tendered' === strtolower($status) || 'captured' === strtolower($status) || 'success' === strtolower($status) || 'accept' === strtolower($status)) {
			$wc_order->add_order_note('Transaction status - ' . $status);
			$this->payment_complete($wc_order, $transaction_id, !empty($note) ? $note : 'Blink payment completed');
		} elseif (strpos(strtolower($source), 'direct debit') !== false || 'pending submission' === strtolower($status)) {
			$this->payment_on_hold($wc_order, !empty($note) ? $note : 'Payment Pending (Transaction status - ' . $status . ')');
		} else {
			$this->payment_failed($wc_order, !empty($note) ? $note : 'Payment Failed (Transaction status - ' . $status . ')');
		}
	}
	/*
	 * In case we need a webhook, like PayPal IPN etc
	*/
	public function webhook() { 
		global $wpdb;
		$order_id = '';
		$request = isset($_REQUEST['transaction_id']) ? $_REQUEST : file_get_contents('php://input');
		if (is_array($request)) {
			$data = isset($request['merchant_data']) ? stripslashes($request['merchant_data']) : '';
			$request['merchant_data'] = json_decode($data, true);
		} else {
			$request = json_decode($request, true);
		}
		$transaction_id = !empty($request['transaction_id']) ? sanitize_text_field($request['transaction_id']) : '';
		if ($transaction_id) {
			$marchant_data = $request['merchant_data'];
			if (!empty($marchant_data)) {
				$order_id = !empty($marchant_data['order_info']['order_id']) ? sanitize_text_field($marchant_data['order_info']['order_id']) : '';
			}
			if (!$order_id) {
				$order_id = $wpdb->get_var(
						$wpdb->prepare(
						"SELECT `post_id`
						FROM {$wpdb->postmeta}
						WHERE (`meta_key` = %s AND `meta_value` = %s) OR (`meta_key` = %s AND `meta_value` = %s)",
						array( '_transaction_id', $transaction_id, 'blink_res', $transaction_id )
						)
					);
			}
			$status = !empty($request['status']) ? $request['status'] : '';
			$note = !empty($request['note']) ? $request['note'] : '';
			$order = wc_get_order($order_id);
			if ($order) {
				$this->change_status($order, $transaction_id, $status, '', $note);
				$order->update_meta_data('_debug', $request);
				$response = ['order_id' => $order_id, 'order_status' => $status, ];
				echo json_encode($response);
				exit();
			}
		}
		$response = ['transaction_id' => !empty($transaction_id) ? $transaction_id : null, 'error' => 'No order found with this transaction ID', ];
		echo json_encode($response);
		exit();
	}
	public function validate_transaction( $order, $transaction ) { 

		$this->token = $this->generate_access_token();
		$responseCode = !empty($transaction) ? $transaction : '';
		$url = $this->host_url . '/pay/v1/transactions/' . $responseCode;
		$response = wp_remote_get($url, ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], ], ]);
		$redirect = trailingslashit(wc_get_checkout_url());
		if (200 == wp_remote_retrieve_response_code($response)) {
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);
			$this->checkAPIException($apiBody, $redirect);
			return !empty($apiBody['data']) ? $apiBody['data'] : [];
		} else {
			$error_message = wp_remote_retrieve_response_message($response);
			wc_add_notice($error_message, 'error');
		}
		wp_redirect($redirect, 302);
		exit();
	}
	public function change_title( $title ) { 
		global $wp;
		$order_id = $wp->query_vars['order-received'];
		$order = wc_get_order($order_id);
		if ($order->has_status('failed')) {
			return 'Order Failed';
		}
		return $title;
	}
	public function check_response_for_order( $order_id ) { 
		if ($order_id) {
			$wc_order = wc_get_order($order_id);
			if (!$wc_order->needs_payment()) {
				return;
			}
			if ('true' == $wc_order->get_meta('_blink_res_expired', true)) {
				return;
			}
			$transaction = $wc_order->get_meta('blink_res', true);
			$transaction_result = $this->validate_transaction($wc_order, $transaction);
			if (!empty($transaction_result)) {
				$status = !empty($transaction_result['status']) ? $transaction_result['status'] : '';
				$source = !empty($transaction_result['payment_source']) ? $transaction_result['payment_source'] : '';
				$message = !empty($transaction_result['message']) ? $transaction_result['message'] : '';
				$wc_order->update_meta_data('_blink_status', $status);
				$wc_order->update_meta_data('payment_type', $source);
				$wc_order->update_meta_data('_blink_res_expired', 'true');
				$wc_order->set_transaction_id($transaction_result['transaction_id']);
				$wc_order->add_order_note('Pay by ' . $source);
				$wc_order->add_order_note('Transaction Note: ' . $message);
				$wc_order->save();
				$this->change_status($wc_order, $transaction_result['transaction_id'], $status, $source);
			} else {
				$this->payment_failed($wc_order, 'Payment Failed (Transaction status - Null).');
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
	public function payment_on_hold( $order, $reason = '' ) { 
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
	public function payment_failed( $order, $reason = '' ) { 
		$order->update_status('failed', $reason);
		if ($reason) {
			$order->add_order_note($reason);
		}
	}
	public function capture_payment() { 
		return false;
	}
}
