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
		$this->apple_pay_enabled = 'yes' === $this->get_option('apple_pay_enabled');
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
		add_action('wp_enqueue_scripts', array($this,'add_hostedfieldcss_to_head'));
		add_action('admin_footer', array($this,'clear_admin_notice'));
		
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
		if(empty($this->token))
		{
			$order->add_order_note('Refund request failed: check payment settings');
			return new WP_Error('refund_failed', __('Refund request failed.', 'woocommerce'));
		}
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
		if(empty($this->token))
		{
			return ['message'=>'Error creating access token'];
		}
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
			wp_enqueue_script('woocommerce_blink_payment_admin_scripts', plugins_url('/../assets/js/admin-scripts.js', __FILE__), ['jquery'], $this->version, true);
			wp_enqueue_style('woocommerce_blink_payment_admin_css', plugins_url('/../assets/css/admin.css', __FILE__), [], $this->version);

			wp_localize_script('woocommerce_blink_payment_admin_scripts', 'blinkOrders', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'cancel_order' => wp_create_nonce('cancel_order_nonce'),
				'spin_gif' => plugins_url('/../assets/img/wpspin.gif', __FILE__),
				'apihost' => $this->host_url,
				'security' => wp_create_nonce( 'generate_access_token_nonce' ),
				'apple_security' => wp_create_nonce( 'generate_applepay_domains_nonce' ),
			));
	}
	public function add_cancel_button($order) {

		$transaction_id = $order->get_meta('blink_res');

		if (!$transaction_id) {
			return; // Exit if transaction ID is not found
		}

		if(checkCCPayment($this->paymentSource))
		{

			if (strtolower($this->paymentStatus) === 'captured' && get_time_diff($order) !== true) {
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
		$data = [];
		$this->token = $this->generate_access_token();
		if($this->token){
		// Prepare request headers
			$headers = array('Authorization' => 'Bearer ' . $this->token['access_token']);


			$response = wp_remote_get($url, ['headers' => $headers]);
			
				if (is_wp_error($response)) {
						wc_add_notice('Error fetching transaction status: ' . $response->get_error_message(), 'error');
						return;
				}

			$data = json_decode(wp_remote_retrieve_body($response));
		}

		$this->paymentSource = !empty($data->data->payment_source) ? $data->data->payment_source : '';
		$this->paymentStatus = !empty($data->data->status) ? $data->data->status : '';
	}

	public function should_render_refunds($render_refunds, $order, $wc_order) {
		$transaction_id = get_post_meta($order, 'blink_res', true);
		$WCOrder = wc_get_order($order);

		if (!$transaction_id) {
			return $render_refunds; // No Blink transaction, use default behavior
		}

		$this->transactionID = $transaction_id;

	
		$this->get_transaction_status($transaction_id);
	
		if (checkCCPayment($this->paymentSource)) {
			if(strtolower($this->paymentStatus) === 'captured')
			{
				$render_refunds = false; // Hide default refund if captured

			}
			if(get_time_diff($WCOrder) === true)
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

	public function clear_admin_notice()
	{
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->remove_notice('blink-error');
		$adminnotice->remove_notice('no-api');
		$adminnotice->remove_notice('no-payment-type-selected');
		$adminnotice->remove_notice('no-payment-types');
	}

	public function add_error_notices( $payment_types = [] ) { 
		
		if ( is_in_admin_section() ) {

			$adminnotice = new WC_Admin_Notices();
			$token = get_option('blink_admin_token');

			if (empty($this->api_key) || empty($this->secret_key))
		    {
				$live = $this->testmode ? 'Test' : 'Live';
				if (!$adminnotice->has_notice('no-api')) {
					$adminnotice->add_custom_notice('no-api', '<div>Please add '.$live.' API key and Secret Key</div>');
				}

			} else {
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

		} 
	}
	
	public function generate_access_token() { 
		    
		$url = $this->host_url . '/pay/v1/tokens';
		$requestData = [ 
			'api_key' => $this->api_key, 
			'secret_key' => $this->secret_key, 
			'source_site' => get_bloginfo( 'name' ), 
			'application_name' => 'Woocommerce Blink '.$this->version, 
			'application_description' => 'WP-'.get_bloginfo('version').' WC-'.WC_VERSION, 
		];
		$response = wp_remote_post($url, ['method' => 'POST', 'body' => $requestData, ]);
		$apiBody = json_decode(wp_remote_retrieve_body($response), true);

		if (201 == wp_remote_retrieve_response_code($response)) {
			return $apiBody;
		} else {
			blink_add_notice($apiBody);
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
		$apiBody = json_decode(wp_remote_retrieve_body($response), true);
		if (201 == wp_remote_retrieve_response_code($response)) {
			return $apiBody;
		} else {
			blink_add_notice($apiBody);
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
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);

			if (200 == wp_remote_retrieve_response_code($response)) {
				return $apiBody;
			} else {
				blink_add_notice($apiBody);
			}
		}

		return [];

	}
	/**
	 * Plugin options,
	 */
	public function init_form_fields( $payment_types = [] ) { // call in front end
		if(!is_in_admin_section())
		{
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

		$new_settings['apple_pay_enrollment'] = array(
			'title' => __('Apple Pay Enrollment', 'woocommerce'),
			'type' => 'title',
			'description' => __('To enable Apple Pay please:<br>
						 Download the domain verification file (DVF) <a href="' . plugin_dir_url(__FILE__) . 'download-apple-pay-dvf.php" target="_blank">here</a>.<br>
						 Upload it to your domain as follows: "https://'.$_SERVER['SERVER_NAME'].'/.well-known/apple-developer-merchantid-domain-association".
						 <button id="enable-apple-pay" class="button">Click here to enable</button>', 'woocommerce'),
			'id' => 'apple_pay_enrollment'
		);
	
		$apple_domain_auth = !empty(get_option('apple_domain_auth'));
		$disabled = [
			'disabled' => 'disabled'  // Disable the checkbox by default
		];

		$new_settings['apple_pay_enabled'] = array(
			'title' => __('Apple Pay Enabled', 'woocommerce'),
			'type' => 'checkbox',
			'description' => __('Enable this option once Apple Pay is successfully registered.', 'woocommerce'),
			'id' => 'woocommerce_apple_pay_enabled',
			'default' => 'yes',
			'custom_attributes' => $apple_domain_auth ? '' : $disabled,
		);

		$this->form_fields = array_merge($fields, $new_settings);

	}
	/**
	 * Credit card form
	 */
	
	public function payment_fields() {

		if((empty($_REQUEST['payment_method']) || $_REQUEST['payment_method'] != $this->id)){
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
				$expired = isTimestampExpired($token['expired_on']);
			}
			if($expired){
				$token = $this->generate_access_token();
				set_transient( 'blink_token', $token, 15 * MINUTE_IN_SECONDS );
			}
			$intent = $this->create_payment_intent($token['access_token']);
			set_transient( 'blink_intent', $intent, 15 * MINUTE_IN_SECONDS );
			$element = !empty($intent) ? $intent['element'] : [];
		}

		$parsed_data = [];
		if(!empty($_REQUEST['post_data'])){
			parse_str($_REQUEST['post_data'], $parsed_data);	
		}else{
			$parsed_data = $_REQUEST;
		}

		$payment_by = !empty($parsed_data['payment_by']) ? $parsed_data['payment_by'] : '';
		if(empty($payment_by))
		{
			foreach ($this->paymentMethods as $method){
				$key = get_element_key($method);
				if(!empty($element[$key])){
					$payment_by = $method;	
					break;
				}
			}
		}

		if (empty($this->paymentMethods) || empty($payment_by)) {
			echo '<p> Unable to process any payment at this moment! </p>';
		} else {
			if ($this->description) {
				echo '<p>' . esc_html($this->description) . '</p>';
			}
		}

		if (!empty($this->paymentMethods) && !empty($payment_by)) 
			{                
				$showGP = true;

				$count = 0;
				foreach ($this->paymentMethods as $method){
					 $key = get_element_key($method);
					if(!empty($element[$key])){
						$count++;	
					}
				}
				 $class = $count == 1 ? 'one' : ($count == 2 ? 'two' : '');

			?>
			
			<div class="form-container">
				<?php
					if(isSafari()){
						if(!empty($element['apElement']) && !empty($this->apple_pay_enabled)){
							$showGP = false;
							echo $element['apElement'];
						}
					}
					if($showGP && !empty($element['gpElement'])){
						echo $element['gpElement'];
					}
				?>
				<div class="batch-upload-wrap pb-3">
						
						<div class="form-group mb-4">
							<div class="form-group mb-4">
								<div class="select-batch" style="width:100%;">
									<div class="switches-container <?php echo  $class; ?>" id="selectBatch">
										<?php foreach ($this->paymentMethods as $method) : ?>
											<?php 
											$key = get_element_key($method);
											if(!empty($element[$key])): ?>
												
											<input type="radio" id="<?php echo $method; ?>" name="switchPayment" value="<?php echo $method; ?>" <?php if ($method == $payment_by) echo 'checked="checked"';?>>
											<?php endif; ?>

										<?php endforeach; 										
										foreach ($this->paymentMethods as $method) : ?>

										    <?php 
											$key = get_element_key($method);
											if(!empty($element[$key])): ?>
												
											<label for="<?php echo $method; ?>"><?php echo transformWord($method); ?></label>

											<?php endif; ?>
						
										<?php endforeach; ?>
										<div class="switch-wrapper <?php echo  $class; ?>">
											<div class="switch">
											<?php foreach ($this->paymentMethods as $method) : ?>
												<?php 
											        $key = get_element_key($method);
													if(!empty($element[$key])): ?>
												
												    <div><?php echo transformWord($method); ?></div>
												<?php endif; ?>

											<?php endforeach; ?>
											</div>
										</div>
									</div>
								</div>
							</div>
							<?php foreach ($this->paymentMethods as $method) : ?>

										
								<?php 
									
									$key = get_element_key($method);

									if ($method == $payment_by && !empty($element[$key])) {
										if('credit-card' == $payment_by){

											echo '<form name="blink-credit" action="" method="">'.$element[$key].'
											<div style="display:none"><input type="submit" name="submit" id="blink-credit-submit" value="check" /></div>
											</form>
											<input type="hidden" name="credit-card-data" id="credit-card-data" value="" />
											';
										}else{
											echo $element[$key];
										}
										
									}				
									
									
									?>

								<?php endforeach; ?>		
								<input type="hidden" name="payment_by" id="payment_by" value="<?php echo $payment_by; ?>">

							<!--  -->
						</div>
					</div>
				</div>
				
			<?php
			} else {
				?>
				<input type="hidden" name="payment_by" value="" />

				<?php
			}
			  
			
	}

	public function add_hostedfieldcss_to_head() {
		echo '<link rel="stylesheet" href="'.plugins_url('/../assets/css/hostedfields.css', __FILE__).'" class="hostedfield">';
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
				parse_str($_REQUEST['credit-card-data'], $parsed_data);	
				if (empty($parsed_data['customer_name'])) {
					wc_add_notice('Name on the Card is required! for Card Payment with Blink', 'error');
					return false;
				}
			}
		return true;
	}
	public function processOpenBanking( $order, $request ) { 

		 $order_id = $order->get_id();
		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){
			$requestData = ['merchant_id' => $this->intent['merchant_id'], 'payment_intent' => $this->intent['payment_intent'], 'user_name' => !empty($request['customer_name']) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'user_email' => !empty($request['customer_email']) ? $request['customer_email'] : $order->get_billing_email(), 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'merchant_data' => get_payment_information($order_id), ];
			$url = $this->host_url . '/pay/v1/openbankings';
			$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], 'user-agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '', 'accept' => !empty($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT']) : '', 'accept-encoding' => 'gzip, deflate, br', 'accept-charset' => 'charset=utf-8', ], 'body' => $requestData, ]);
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);

			if (200 == wp_remote_retrieve_response_code($response)) {
				if ($apiBody['url']) {
					return $apiBody['url']; //redirect_url
				} elseif ($apiBody['redirect_url']) {
					return $apiBody['redirect_url']; //redirect_url
				}
			} else {
				blink_add_notice($apiBody);
			}
		}
			
		return false;
	}
	public function processDirectDebit( $order, $request ) { 

		$order_id = $order->get_id();
		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){

			$requestData = ['payment_intent' => $this->intent['payment_intent'], 'given_name' => !empty($request['given_name']) ? $request['given_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'family_name' => $request['family_name'], 'company_name' => $request['company_name'], 'email' => !empty($request['email']) ? $request['email'] : $order->get_billing_email(), 'country_code' => get_woocommerce_currency(), 'account_holder_name' => $request['account_holder_name'], 'branch_code' => $request['branch_code'], 'account_number' => $request['account_number'], 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'merchant_data' => get_payment_information($order_id), ];
			$url = $this->host_url . '/pay/v1/directdebits';
			$response = wp_remote_post($url, ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->token['access_token'], 'user-agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '', 'accept' => !empty($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field($_SERVER['HTTP_ACCEPT']) : '', 'accept-encoding' => 'gzip, deflate, br', 'accept-charset' => 'charset=utf-8', ], 'body' => $requestData, ]);
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);

			if (200 == wp_remote_retrieve_response_code($response)) {
				if ($apiBody['url']) {
					return $apiBody['url'];
				}
			} else {
				blink_add_notice($apiBody);
			}

		}


		
		return false;
	}

	public function processCreditCard( $order, $request, $endpoint = 'creditcards' ) { 

		$cartAmount = WC()->cart->get_total('raw');
        $cartAmount = !empty($cartAmount) ? $cartAmount : '1.0';
		$amount = !empty($order) ? $order->get_total() : $cartAmount;
		
		$order_id = $order->get_id();
		if(!empty($this->token['access_token']) && !empty($this->intent['payment_intent'])){

			$requestData = ['resource'=> $request['resource'],'payment_intent' => $this->intent['payment_intent'], 'paymentToken' => wp_unslash($request['paymentToken']), 'type' => $request['type'], 'raw_amount' => $amount, 'customer_email' => !empty($request['customer_email']) ? $request['customer_email'] : $order->get_billing_email(), 'customer_name' => !empty($request['customer_name']) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 'customer_address' => !empty($request['customer_address']) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), 'customer_postcode' => !empty($request['customer_postcode']) ? $request['customer_postcode'] : $order->get_billing_postcode(), 'transaction_unique' => 'WC-'.$request['transaction_unique'], 'merchant_data' => get_payment_information($order_id), ];
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
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);
			if (200 == wp_remote_retrieve_response_code($response)) {
				if (isset($apiBody['acsform'])) {
					$threedToken = $apiBody['acsform'];
					set_transient('blink3dProcess' . $order_id, $threedToken, 300);
					return trailingslashit(wc_get_checkout_url()) . '?blink3dprocess=' . $order_id;
				} else if (isset($apiBody['url'])) {
					return $apiBody['url'];
				}
			} else {
				blink_add_notice($apiBody);
			} 
		}
	   
		

		return false;
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
				$expired = isTimestampExpired($this->token['expired_on']);
			}
			if($expired){
				$this->token = $this->generate_access_token();
			}
			if(empty($this->token))
			{
				return wp_send_json(error_payment_process());
			}
			set_transient( 'blink_token', $this->token, 15 * MINUTE_IN_SECONDS );

			$this->intent = get_transient('blink_intent');
			$intent_expired = true;
			if(!empty($this->intent))
			{
				$intent_expired = isTimestampExpired($this->intent['expiry_date']);
			}
			if($intent_expired){
				$this->intent = $this->create_payment_intent($this->token['access_token'], $request['payment_by'], $order);
			}
			else{
				$this->intent = $this->update_payment_intent($this->token['access_token'], $request['payment_by'], $order, $this->intent['id']);
			}
			if(empty($this->intent))
			{
				return wp_send_json(error_payment_process());
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
			if($request['payment_by'] == 'apple-pay')
			{
				$redirect = $this->processCreditCard( $order, $request, 'applepay' );

			}
			if($request['payment_by'] == 'direct-debit')
			{
				$redirect = $this->processDirectDebit( $order, $request );

			}
			if($request['payment_by'] == 'open-banking')
			{
				$redirect = $this->processOpenBanking( $order, $request );

			}

			if(empty($redirect)){
				return wp_send_json(error_payment_process());
			}

			
		return wp_send_json(['result' => $result, 'redirect' => $redirect]);
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
				change_status($order, $transaction_id, $status, '', $note);
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
		$apiBody = json_decode(wp_remote_retrieve_body($response), true);
		if (200 == wp_remote_retrieve_response_code($response)) {
			return !empty($apiBody['data']) ? $apiBody['data'] : [];
		} else {
			blink_add_notice($apiBody);
		}
		wp_redirect($redirect, 302);
		exit();
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
				change_status($wc_order, $transaction_result['transaction_id'], $status, $source, $message);
			} else {
				payment_failed($wc_order, 'Payment Failed (Transaction status - Null).');
			}
		}
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
	
	public function capture_payment() { 
		return false;
	}
}
 