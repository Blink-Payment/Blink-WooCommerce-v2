<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
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
	public $rerun_handler;
	public $transaction_handler;

	public $api_key;
	public $secret_key;
	public $testmode;
	public $apple_pay_enabled;
	public $preauthorize_payments;
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
		$this->integration_type      = $this->get_option('integration_type');
		$this->testmode              = 'yes' === $this->get_option('testmode');
		$this->apple_pay_enabled     = 'yes' === $this->get_option('apple_pay_enabled');
		$this->preauthorize_payments = 'yes' === $this->get_option('preauthorize_payments');
		$this->api_key               = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('api_key');
		$this->secret_key            = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');
		$token                   = get_option('blink_admin_token');

		$this->fields_handler      = new Blink_Payment_Fields_Handler($this);
		$this->payment_handler     = new Blink_Payment_Handler($this);
		$this->settings_handler    = new Blink_Settings_Handler($this->api_key, $this->secret_key);
		$this->utils               = new Blink_Payment_Utils($this);
		$this->refund_handler      = new Blink_Refund_Handler($this);
		$this->rerun_handler       = new Blink_Rerun_Handler($this);
		$this->transaction_handler = new Blink_Transaction_Handler($this);

		// Method with all the options fields
		$this->blink_init_form_fields();

		$selectedMethods = array();
		if (is_array($token) && isset($token['payment_types'])) {
			foreach ($token['payment_types'] as $type) {
				// If preauth is enabled, only allow credit-card payment method
				if ($this->preauthorize_payments && $type !== 'credit-card') {
					continue;
				}
				$selectedMethods[] = ('yes' === $this->get_option($type)) ? $type : '';
			}
		}
		$this->paymentMethods = array_filter($selectedMethods);
		$this->blink_add_error_notices();

		// This action hook saves the settings
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'blink_process_admin_options'), 99);
		// if needed we can use this webhook
		add_action('woocommerce_api_blink_gateway', array($this->transaction_handler, 'blink_webhook'));
		add_action('woocommerce_thankyou_blink', array($this->transaction_handler, 'blink_check_response_for_order'));
		add_filter('woocommerce_endpoint_order-received_title', array($this, 'blink_change_title'), 99);

		add_filter('woocommerce_admin_order_should_render_refunds', array($this->refund_handler, 'blink_should_render_refunds'), 10, 3);
		add_filter('woocommerce_order_item_add_action_buttons', array($this->refund_handler, 'blink_add_cancel_button'), 10);
		add_action('admin_enqueue_scripts', array($this, 'blink_enqueue_scripts'), 10);
		add_action('wp_ajax_cancel_transaction', array($this->transaction_handler, 'blink_cancel_transaction'));
		add_action('admin_footer', array($this, 'blink_clear_admin_notice'));
		add_action('woocommerce_before_thankyou', array($this, 'blink_print_custom_notice'));

		// We need custom JavaScript to obtain a token
		add_action('wp_enqueue_scripts', array($this, 'blink_payment_scripts'));
	}

	public function payment_fields()
	{
		$this->fields_handler->blink_render_payment_fields();
	}

	public function process_payment($order_id)
	{
		return $this->payment_handler->blink_handle_payment($order_id);
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
	}

	public function process_refund($order_id, $amount = null, $reason = '__')
	{
		return $this->refund_handler->blink_handle_refund($order_id, $amount, $reason);
	}

	public function blink_enqueue_scripts($hook)
	{
		// Only load admin scripts on relevant pages and for users with proper permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

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

	public function blink_clear_admin_notice()
	{
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->remove_notice('blink-error');
		$adminnotice->remove_notice('no-api');
		$adminnotice->remove_notice('no-payment-type-selected');
		$adminnotice->remove_notice('no-payment-types');
	}

	public function blink_add_error_notices($payment_types = array())
	{

		if (blink_is_in_admin_section()) {

			$adminnotice = new WC_Admin_Notices();
			$token       = get_option('blink_admin_token');

			if (empty($this->api_key) || empty($this->secret_key)) {
				$live = $this->testmode ? __('Test', 'blink-payment-gateway-for-woocommerce') : __('Live', 'blink-payment-gateway-for-woocommerce');
				if (! $adminnotice->has_notice('no-api')) {
					/* translators: %s is either "Test" or "Live" depending on the mode. */
					$adminnotice->add_custom_notice('no-api', '<div>' . sprintf(__('Please add %s API key and Secret Key', 'blink-payment-gateway-for-woocommerce'), $live) . '</div>');
				}
			} else {
				$adminnotice->remove_notice('no-api');
				if (! empty($token['payment_types'])) {
					if (empty($this->paymentMethods)) {
						if (! $adminnotice->has_notice('no-payment-type-selected')) {
							$adminnotice->add_custom_notice('no-payment-type-selected', '<div>' . __('Please select the Payment Methods and save the configuration!', 'blink-payment-gateway-for-woocommerce') . '</div>');
						}
					} else {
						$adminnotice->remove_notice('no-payment-type-selected');
					}
					$adminnotice->remove_notice('no-payment-types');
				} elseif (! $adminnotice->has_notice('no-payment-types')) {
					$adminnotice->add_custom_notice('no-payment-types', '<div>' . __('There is no Payment Types Available.', 'blink-payment-gateway-for-woocommerce') . '</div>');
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
	public function blink_get_return_url($order = null)
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
	public function blink_init_form_fields($payment_types = array())
	{
		if (! blink_is_in_admin_section()) {
			return;
		}

		$this->form_fields = $this->settings_handler->blink_get_form_fields();
	}


	public function blink_payment_scripts()
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
					'security'           => wp_create_nonce('blink_payment_fields_nonce'),
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

		return $this->fields_handler->blink_validate_fields();
	}


	public function blink_change_title($title)
	{
		global $wp;
		$order_id = $wp->query_vars['order-received'];
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order->has_status('failed')) {
				return __('Order Failed', 'blink-payment-gateway-for-woocommerce');
			}
		}

		return $title;
	}

	public function blink_print_custom_notice()
	{
		$status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
		$note   = isset($_GET['note']) ? sanitize_text_field(wp_unslash($_GET['note'])) : '';

		if ('failed' === $status && ! get_transient('blink_custom_notice_shown')) {
			wc_print_notice($note, 'error');
			set_transient('blink_custom_notice_shown', true, 15);
		}
	}

	public function blink_is_hosted()
	{
		return ($this->integration_type !== 'direct');
	}
}