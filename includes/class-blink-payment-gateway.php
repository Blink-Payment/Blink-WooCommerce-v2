<?php
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Blink_Payment_Gateway extends WC_Payment_Gateway
{

	public $token;
	public $intent;
	public $paymentMethods = array();
	public $paymentSource;
	public $paymentStatus;

	public $fields_handler;
	public $payment_handler;
	public $settings_handler;
	public $utils;
	public $refund_handler;
	public $transaction_handler;

	public $api_key;
	public $secret_key;
	public $testmode;
	public $apple_pay_enabled;
	public $configs;
	public $host_url;
	public $integration_type;
	public $version;


	public function __construct()
	{

		$this->configs            = include __DIR__ . '/../config.php';
		$this->id                 = str_replace(' ', '', strtolower($this->configs['method_title']));
		$this->icon               = plugins_url('/../assets/img/blink_logo_sml.svg', __FILE__);
		$this->has_fields         = true; // in case you need a custom credit card form
		$this->method_title       = $this->configs['method_title'];
		$this->method_description = $this->configs['method_description'];
		$this->host_url           = $this->configs['host_url'] . '/api';
		$this->version            = $this->configs['version'];
		$this->supports           = array(
			'products',
			'refunds',
		);

		// Load the settings.
		$this->init_settings();
		$this->title             = $this->get_option('title');
		$this->description       = $this->get_option('description');
		$this->enabled           = $this->get_option('enabled');
		$this->integration_type  = $this->get_option('integration_type');
		$this->testmode          = 'yes' === $this->get_option('testmode');
		$this->apple_pay_enabled = 'yes' === $this->get_option('apple_pay_enabled');
		$this->api_key           = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
		$this->secret_key        = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');
		$token                   = get_option('blink_admin_token');

		$this->fields_handler      = new Blink_Payment_Fields_Handler($this);
		$this->payment_handler     = new Blink_Payment_Handler($this);
		$this->settings_handler    = new Blink_Settings_Handler($this->api_key, $this->secret_key);
		$this->utils               = new Blink_Payment_Utils($this);
		$this->refund_handler      = new Blink_Refund_Handler($this);
		$this->transaction_handler = new Blink_Transaction_Handler($this);

		// Method with all the options fields
		$this->init_form_fields();

		$selectedMethods = array();
		if (is_array($token) && isset($token['payment_types'])) {
			foreach ($token['payment_types'] as $type) {
				$selectedMethods[] = ('yes' === $this->get_option($type)) ? $type : '';
			}
		}
		$this->paymentMethods = array_filter($selectedMethods);
		$this->add_error_notices();

		// This action hook saves the settings
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'blink_process_admin_options'), 99);
		// if needed we can use this webhook
		add_action('woocommerce_api_wc_blink_gateway', array($this->transaction_handler, 'webhook'));
		add_action('woocommerce_thankyou_blink', array($this->transaction_handler, 'check_response_for_order'));
		add_filter('woocommerce_endpoint_order-received_title', array($this, 'change_title'), 99);

		add_filter('woocommerce_admin_order_should_render_refunds', array($this->refund_handler, 'should_render_refunds'), 10, 3);
		add_filter('woocommerce_order_item_add_action_buttons', array($this->refund_handler, 'add_cancel_button'), 10);
		add_action('admin_enqueue_scripts', array($this, 'blink_enqueue_scripts'), 10);
		add_action('wp_ajax_cancel_transaction', array($this->transaction_handler, 'blink_cancel_transaction'));
		add_action('admin_footer', array($this, 'clear_admin_notice'));
		add_action('woocommerce_before_thankyou', array($this, 'print_custom_notice'));

		// We need custom JavaScript to obtain a token
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
	}

	public function payment_fields()
	{
		$this->fields_handler->render_payment_fields();
	}

	public function process_payment($order_id)
	{
		return $this->payment_handler->handle_payment($order_id);
	}

	public function blink_process_admin_options()
	{
		$this->api_key = isset($_POST['woocommerce_blink_testmode']) && $_POST['woocommerce_blink_testmode'] === '1'
			? (isset($_POST['woocommerce_blink_test_api_key']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_blink_test_api_key'])) : '')
			: (isset($_POST['woocommerce_blink_api_key']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_blink_api_key'])) : '');

		$this->secret_key = isset($_POST['woocommerce_blink_testmode']) && $_POST['woocommerce_blink_testmode'] === '1'
			? (isset($_POST['woocommerce_blink_test_secret_key']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_blink_test_secret_key'])) : '')
			: (isset($_POST['woocommerce_blink_secret_key']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_blink_secret_key'])) : '');

		$token = $this->utils->blink_generate_access_token();
		update_option('blink_admin_token', $token);
		$this->utils->destroy_session_tokens();
	}

	public function process_refund($order_id, $amount = null, $reason = '__')
	{
		return $this->refund_handler->handle_refund($order_id, $amount, $reason);
	}

	public function blink_enqueue_scripts($hook)
	{
		wp_enqueue_script('woocommerce_blink_payment_admin_scripts', plugins_url('/../assets/js/admin-scripts.js', __FILE__), array('jquery'), $this->version, true);
		wp_enqueue_style('woocommerce_blink_payment_admin_css', plugins_url('/../assets/css/admin.css', __FILE__), array(), $this->version);

		wp_localize_script(
			'woocommerce_blink_payment_admin_scripts',
			'blinkOrders',
			array(
				'ajaxurl'        => admin_url('admin-ajax.php'),
				'cancel_order'   => wp_create_nonce('cancel_order_nonce'),
				'spin_gif'       => plugins_url('/../assets/img/wpspin.gif', __FILE__),
				'apihost'        => $this->host_url,
				'security'       => wp_create_nonce('generate_access_token_nonce'),
				'apple_security' => wp_create_nonce('generate_applepay_domains_nonce'),
				'remoteAddress'  => get_client_ipv4_address(),
			)
		);
	}

	public function clear_admin_notice()
	{
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->remove_notice('blink-error');
		$adminnotice->remove_notice('no-api');
		$adminnotice->remove_notice('no-payment-type-selected');
		$adminnotice->remove_notice('no-payment-types');
	}

	public function add_error_notices($payment_types = array())
	{

		if (blink_is_in_admin_section()) {

			$adminnotice = new WC_Admin_Notices();
			$token       = get_option('blink_admin_token');

			if (empty($this->api_key) || empty($this->secret_key)) {
				$live = $this->testmode ? __('Test', 'blink-payment-checkout') : __('Live', 'blink-payment-checkout');
				if (! $adminnotice->has_notice('no-api')) {
					/* translators: %s is either "Test" or "Live" depending on the mode. */
					$adminnotice->add_custom_notice('no-api', '<div>' . sprintf(__('Please add %s API key and Secret Key', 'blink-payment-checkout'), $live) . '</div>');
				}
			} else {
				$adminnotice->remove_notice('no-api');
				if (! empty($token['payment_types'])) {
					if (empty($this->paymentMethods)) {
						if (! $adminnotice->has_notice('no-payment-type-selected')) {
							$adminnotice->add_custom_notice('no-payment-type-selected', '<div>' . __('Please select the Payment Methods and save the configuration!', 'blink-payment-checkout') . '</div>');
						}
					} else {
						$adminnotice->remove_notice('no-payment-type-selected');
					}
					$adminnotice->remove_notice('no-payment-types');
				} elseif (! $adminnotice->has_notice('no-payment-types')) {
					$adminnotice->add_custom_notice('no-payment-types', '<div>' . __('There is no Payment Types Available.', 'blink-payment-checkout') . '</div>');
				}
			}
		}
	}


	/**
	 * Get the return url (thank you page).
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function get_return_url($order = null)
	{
		if ($order) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url('order-received', '', wc_get_checkout_url());
		}
		return apply_filters('blink_get_return_url', $return_url, $order);
	}



	/**
	 * Plugin options,
	 */
	public function init_form_fields($payment_types = array())
	{
		if (! blink_is_in_admin_section()) {
			return;
		}

		$this->form_fields = $this->settings_handler->get_form_fields();
	}


	public function payment_scripts()
	{
		// we need JavaScript to process a token only on cart/checkout pages, right?
		if (! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order']) && !blink_is_checkout_block()) {
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
		if (! $this->testmode && ! is_ssl()) {
			return;
		}

		wp_add_inline_script('jquery', '$ = jQuery.noConflict();');
		wp_enqueue_style(
			'hostedfield-css',
			plugin_dir_url(__FILE__) . '../assets/css/hostedfields.css',
			array(),
			$this->version
		);
		wp_enqueue_script('blink_hosted_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js', array('jquery'), $this->version, false);
		wp_register_style('woocommerce_blink_payment_style', plugins_url('../assets/css/style.css', __FILE__), array(), $this->version);
		// and this is our custom JS in your plugin directory that works with token.js
		if (is_wc_endpoint_url('order-pay')) {
			wp_register_script('woocommerce_blink_payment_order_pay', plugins_url('../assets/js/order-pay.js', __FILE__), array('jquery'), $this->version, true);

			$order = wc_get_order(get_query_var('order-pay'));
			wp_localize_script(
				'woocommerce_blink_payment_order_pay',
				'order_params',
				array(
					'billing_first_name' => $order->get_billing_first_name(),
					'billing_last_name'  => $order->get_billing_last_name(),
					'billing_email'      => $order->get_billing_email(),
					'billing_address_1'  => $order->get_billing_address_1(),
					'billing_city'       => $order->get_billing_city(),
					'billing_postcode'   => $order->get_billing_postcode(),
					'billing_country'    => $order->get_billing_country(),
					'billing_phone'      => $order->get_billing_phone(),
					'order_id'           => $order->get_id(),
					'ajaxurl'            => admin_url('admin-ajax.php'),
					'remoteAddress'      => get_client_ipv4_address(),
				)
			);
			wp_enqueue_script('woocommerce_blink_payment_order_pay');
		} else {
			wp_register_script('woocommerce_blink_payment_checkout', plugins_url('../assets/js/checkout.js', __FILE__), array('jquery'), $this->version, true);
			wp_localize_script(
				'woocommerce_blink_payment_checkout',
				'blink_params',
				array(
					'ajaxurl'       => admin_url('admin-ajax.php'),
					'remoteAddress' => get_client_ipv4_address(),
				)
			);

			wp_enqueue_script('woocommerce_blink_payment_checkout');
		}
		wp_enqueue_style('woocommerce_blink_payment_style');
		$custom_css = $this->get_option('custom_style');
		if ($custom_css) {
			wp_add_inline_style('woocommerce_blink_payment_style', $custom_css);
		}
		do_action('blink_custom_script');
		do_action('blink_custom_style');
	}

	public function validate_fields()
	{

		return $this->fields_handler->validate_fields();
	}


	public function change_title($title)
	{
		global $wp;
		$order_id = $wp->query_vars['order-received'];
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order->has_status('failed')) {
				return __('Order Failed', 'blink-payment-checkout');
			}
		}

		return $title;
	}

	public function capture_payment()
	{
		return false;
	}

	public function print_custom_notice()
	{
		$status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
		$note   = isset($_GET['note']) ? sanitize_text_field(wp_unslash($_GET['note'])) : '';

		if ('failed' === $status && ! get_transient('custom_notice_shown')) {
			wc_print_notice($note, 'error');
			set_transient('custom_notice_shown', true, 15);
		}
	}

	public function is_hosted()
	{
		return ($this->integration_type !== 'direct');
	}
}