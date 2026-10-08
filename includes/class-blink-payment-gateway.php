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

	/**
	 * Output the Blink gateway settings inside a settings-specific wrapper.
	 *
	 * The wrapper keeps presentation changes scoped to this gateway while the
	 * underlying fields continue to use WC_Settings_API rendering and saving.
	 */
	public function admin_options()
	{
		echo '<div class="blink-settings">';
		parent::admin_options();
		echo '</div>';
	}

	/**
	 * Close the native Advanced disclosure after WooCommerce has generated its
	 * fields, before the outer settings table is closed.
	 *
	 * @param array $form_fields Form fields.
	 * @param bool  $echo        Whether to echo the markup.
	 * @return string
	 */
	public function generate_settings_html($form_fields = array(), $echo = true)
	{
		$fields = empty($form_fields) ? $this->get_form_fields() : $form_fields;
		$html   = parent::generate_settings_html($fields, false);
		$html   = preg_replace_callback(
			'~(?:(<tr[^>]*class="[^"]*blink-settings-choice-row[^"]*"[^>]*>.*?</tr>)[\r\n\t ]*)+~s',
			function ( $matches ) {
				preg_match_all( '~<tr[^>]*class="[^"]*blink-settings-choice-row[^"]*"[^>]*>.*?</tr>~s', $matches[0], $rows );
				$controls = '';
				foreach ( $rows[0] as $row ) {
					if ( preg_match( '~<td[^>]*>(.*?)</td>~s', $row, $cell ) ) {
						$controls .= $cell[1];
					}
				}
				return '<tr class="blink-settings-choice-group-row"><td colspan="2"><fieldset class="blink-settings-choice-group">' . $controls . '</fieldset></td></tr>';
			},
			$html
		);

		if (isset($fields['advanced']['blink_advanced_start'])) {
			$html .= '</table></details>';
		}

		if ($echo) {
			echo $html; // WPCS: XSS ok.
		}

		return $html;
	}

	/**
	 * Render Blink section variants using valid table and section markup.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_title_html($key, $data)
	{
		if (empty($data['blink_first_section']) && empty($data['blink_subheading']) && empty($data['blink_apple_pay']) && empty($data['blink_after_standalone']) && empty($data['blink_choice_section']) && empty($data['blink_advanced_start'])) {
			return parent::generate_title_html($key, $data);
		}

		$field_key = $this->get_field_key($key);
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'class'       => '',
				'description' => '',
			)
		);
		$choice_section = isset($data['blink_choice_section']) ? $data['blink_choice_section'] : '';

		ob_start();
		if (! empty($data['blink_first_section'])) {
			?>
			<caption class="blink-settings-caption">
				<div>
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
					<?php if (! empty($data['description'])) : ?>
						<p><?php echo wp_kses_post($data['description']); ?></p>
					<?php endif; ?>
				</div>
			</caption>
			<?php
		} elseif (! empty($data['blink_subheading'])) {
			?>
			<tr class="blink-settings-subheading">
				<th colspan="2" scope="colgroup">
					<h4 id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h4>
					<?php if (! empty($data['description'])) : ?>
						<p><?php echo wp_kses_post($data['description']); ?></p>
					<?php endif; ?>
				</th>
			</tr>
			<?php
		} elseif ('start' === $choice_section) {
			?>
			</table>
			<div class="blink-settings-choice-columns">
				<section class="blink-settings-choice-section blink-settings-choice-section--<?php echo esc_attr($data['blink_choice_kind']); ?>" aria-labelledby="<?php echo esc_attr($field_key); ?>">
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
					<?php if (! empty($data['description'])) : ?>
						<p><?php echo wp_kses_post($data['description']); ?></p>
					<?php endif; ?>
					<table class="form-table">
			<?php
		} elseif ('middle' === $choice_section) {
			?>
					</table>
				</section>
				<section class="blink-settings-choice-section blink-settings-choice-section--<?php echo esc_attr($data['blink_choice_kind']); ?>" aria-labelledby="<?php echo esc_attr($field_key); ?>">
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
					<?php if (! empty($data['description'])) : ?>
						<p><?php echo wp_kses_post($data['description']); ?></p>
					<?php endif; ?>
					<table class="form-table">
			<?php
		} elseif (! empty($data['blink_apple_pay'])) {
			$status_key        = $data['blink_status_key'];
			$status_field_key  = $this->get_field_key($status_key);
			$status_attributes = array(
				'custom_attributes' => $data['blink_status_attributes'],
			);
			$is_enabled        = ! empty($data['blink_registered']) && 'yes' === $this->get_option($status_key);
			?>
			</table>
			<?php if (! empty($data['blink_choices_wrapper_open'])) : ?>
			</section>
			</div>
			<?php endif; ?>
			<section class="blink-apple-pay-card" aria-labelledby="<?php echo esc_attr($field_key); ?>">
				<div class="blink-apple-pay-header">
					<h3 class="wc-settings-sub-title" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
					<span class="blink-settings-status <?php echo $is_enabled ? 'is-enabled' : 'is-disabled'; ?>" data-blink-apple-pay-status data-enabled-label="<?php echo esc_attr(__('Enabled', 'blink-payment-gateway-for-woocommerce')); ?>" data-disabled-label="<?php echo esc_attr(__('Not enabled', 'blink-payment-gateway-for-woocommerce')); ?>" aria-live="polite">
						<?php echo esc_html($is_enabled ? __('Enabled', 'blink-payment-gateway-for-woocommerce') : __('Not enabled', 'blink-payment-gateway-for-woocommerce')); ?>
					</span>
				</div>
				<p>
					<label for="<?php echo esc_attr($status_field_key); ?>">
						<input type="checkbox" name="<?php echo esc_attr($status_field_key); ?>" id="<?php echo esc_attr($status_field_key); ?>" value="1" <?php checked($this->get_option($status_key), 'yes'); ?> <?php echo $this->get_custom_attribute_html($status_attributes); // WPCS: XSS ok. ?> />
						<?php echo esc_html__('Enable Apple Pay at checkout', 'blink-payment-gateway-for-woocommerce'); ?>
					</label>
				</p>
				<?php echo wp_kses_post($data['description']); ?>
			</section>
			<?php
		} elseif (! empty($data['blink_advanced_start'])) {
			?>
			</table>
			<details class="blink-settings-advanced">
				<summary>
					<span class="blink-settings-advanced-title"><?php echo wp_kses_post($data['title']); ?></span>
					<span class="blink-settings-advanced-description"><?php echo wp_kses_post($data['description']); ?></span>
				</summary>
				<table class="form-table">
			<?php
		} else {
			?>
			<h3 class="wc-settings-sub-title <?php echo esc_attr($data['class']); ?>" id="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></h3>
			<?php if (! empty($data['description'])) : ?>
				<p><?php echo wp_kses_post($data['description']); ?></p>
			<?php endif; ?>
			<table class="form-table">
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * Add mode-specific help to the standard Integration Type select.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_select_html($key, $data)
	{
		if (empty($data['blink_descriptions'])) {
			return parent::generate_select_html($key, $data);
		}

		$value                       = $this->get_option($key);
		$descriptions                = $data['blink_descriptions'];
		$data['description']         = isset($descriptions[$value]) ? $descriptions[$value] : $descriptions['direct'];
		$data['custom_attributes']   = isset($data['custom_attributes']) ? $data['custom_attributes'] : array();
		$data['custom_attributes']['data-direct-description'] = $descriptions['direct'];
		$data['custom_attributes']['data-hosted-description'] = $descriptions['hosted'];

		return parent::generate_select_html($key, $data);
	}

	/**
	 * Render Blink checkbox variants without changing their WC field type.
	 *
	 * Keeping these fields as checkboxes preserves WooCommerce's standard
	 * yes/no validation and the existing option keys.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field configuration.
	 * @return string
	 */
	public function generate_checkbox_html($key, $data)
	{
		if (! empty($data['blink_status'])) {
			return '';
		}

		if (empty($data['blink_group']) && empty($data['blink_mode_status'])) {
			return parent::generate_checkbox_html($key, $data);
		}

		$field_key = $this->get_field_key($key);
		$defaults  = array(
			'title'             => '',
			'label'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);
		$data = wp_parse_args($data, $defaults);

		if (! $data['label']) {
			$data['label'] = $data['title'];
		}

		ob_start();
		if (! empty($data['blink_group'])) {
			?>
			<tr valign="top" class="blink-settings-choice-row" data-blink-settings-group="<?php echo esc_attr($data['blink_group']); ?>">
				<th scope="row" class="screen-reader-text"><label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['label']); ?></label></th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['label']); ?></span></legend>
						<label class="blink-settings-choice" for="<?php echo esc_attr($field_key); ?>">
							<input <?php disabled($data['disabled'], true); ?> class="<?php echo esc_attr($data['class']); ?>" type="checkbox" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" value="1" <?php checked($this->get_option($key), 'yes'); ?> <?php echo $this->get_custom_attribute_html($data); // WPCS: XSS ok. ?> />
							<span><?php echo wp_kses_post($data['label']); ?></span>
						</label>
						<?php echo $this->get_description_html($data); // WPCS: XSS ok. ?>
					</fieldset>
				</td>
			</tr>
			<?php
		} else {
			$is_test_mode = 'yes' === $this->get_option($key);
			?>
			<tr valign="top" class="blink-settings-mode-row">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); // WPCS: XSS ok. ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
						<label for="<?php echo esc_attr($field_key); ?>">
							<input <?php disabled($data['disabled'], true); ?> class="<?php echo esc_attr($data['class']); ?>" type="checkbox" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" style="<?php echo esc_attr($data['css']); ?>" value="1" <?php checked($this->get_option($key), 'yes'); ?> <?php echo $this->get_custom_attribute_html($data); // WPCS: XSS ok. ?> /> <?php echo wp_kses_post($data['label']); ?>
						</label>
						<span class="blink-settings-mode-status <?php echo $is_test_mode ? 'is-test' : 'is-live'; ?>" data-blink-test-mode-status data-test-label="<?php echo esc_attr(__('Using test credentials', 'blink-payment-gateway-for-woocommerce')); ?>" data-live-label="<?php echo esc_attr(__('Using live credentials', 'blink-payment-gateway-for-woocommerce')); ?>" aria-live="polite"><?php echo esc_html($is_test_mode ? __('Using test credentials', 'blink-payment-gateway-for-woocommerce') : __('Using live credentials', 'blink-payment-gateway-for-woocommerce')); ?></span>
						<?php echo $this->get_description_html($data); // WPCS: XSS ok. ?>
					</fieldset>
				</td>
			</tr>
			<?php
		}

		return ob_get_clean();
	}

	public function process_payment($order_id)
	{
		return $this->payment_handler->blink_handle_payment($order_id);
	}

	/**
	 * Render the Blink icon and the merchant-selected card logos in Classic Checkout.
	 *
	 * WooCommerce's icon property accepts only one URL, so the fixed local card
	 * logos are appended to its standard escaped Blink icon markup.
	 *
	 * @return string
	 */
	public function get_icon()
	{
		return parent::get_icon() . blink_get_card_logos_html($this->settings);
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
		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		wp_enqueue_script('woocommerce_blink_payment_admin_scripts', plugins_url('/../assets/js/admin-scripts.js', __FILE__), array('jquery'), $this->version, true);
		wp_enqueue_style('woocommerce_blink_payment_admin_css', plugins_url('/../assets/css/admin.css', __FILE__), array(), $this->version);
		if (blink_is_in_admin_section()) {
			wp_enqueue_script('woocommerce_blink_payment_admin_settings', plugins_url('/../assets/js/admin-settings.js', __FILE__), array('jquery'), $this->version, true);
		}

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
	public function blink_print_hostedfield_styles()
	{
		$css_path = plugin_dir_path(__FILE__) . '../assets/css/hostedfields.css';

		if (! file_exists($css_path)) {
			return;
		}

		$css = file_get_contents($css_path);

		if (false === $css || '' === trim($css)) {
			return;
		}

		echo '<style class="hostedfield">' . "\n";
		echo wp_strip_all_tags($css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n</style>\n";
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
		$is_order_pay_endpoint = is_wc_endpoint_url('order-pay');
		$is_order_pay          = $is_order_pay_endpoint || isset($_GET['pay_for_order']);
		$is_checkout_block     = function_exists('blink_is_checkout_block') && blink_is_checkout_block();

		if (! is_cart() && ! is_checkout() && ! $is_order_pay && ! $is_checkout_block) {
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

		wp_enqueue_script('blink_hosted_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js', array('jquery'), $this->version, false);
		wp_register_style('woocommerce_blink_payment_style', plugins_url('../assets/css/style.css', __FILE__), array(), $this->version);
		wp_register_script('woocommerce_blink_wallet_submit', plugins_url('../assets/js/wallet-submit.js', __FILE__), array('jquery'), $this->version, true);
		// and this is our custom JS in your plugin directory that works with token.js
		if ($is_order_pay_endpoint) {
			wp_register_script('woocommerce_blink_payment_order_pay', plugins_url('../assets/js/order-pay.js', __FILE__), array('jquery', 'woocommerce_blink_wallet_submit'), $this->version, true);

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
		} elseif (is_checkout() && ! $is_checkout_block) {
			wp_register_script('woocommerce_blink_payment_checkout', plugins_url('../assets/js/checkout.js', __FILE__), array('jquery', 'woocommerce_blink_wallet_submit'), $this->version, true);
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
		add_action(
			'wp_head',
			array($this, 'blink_print_hostedfield_styles'),
			20
		);
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

	public function blink_print_custom_notice($order_id = 0)
	{
		$order = wc_get_order(absint($order_id));
		if (! $order || 'blink' !== $order->get_payment_method() || ! $order->has_status('failed')) {
			return;
		}

		$message = blink_resolve_payment_message($order->get_meta('_blink_payment_message', true));
		if ('' !== $message) {
			wc_print_notice($message, 'error');
		}
	}

	public function blink_is_hosted()
	{
		return ($this->integration_type !== 'direct');
	}
}
