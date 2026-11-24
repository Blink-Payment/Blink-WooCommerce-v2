<?php
/**
 * The Blink WooCommerce payment gateway.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers Blink as a WooCommerce payment gateway and wires up its handlers, settings and scripts.
 */
class Blink_PG_Payment_Gateway extends WC_Payment_Gateway {


	/**
	 * Access token data from the Blink API.
	 *
	 * @var array|null
	 */
	public $token;

	/**
	 * Payment intent data from the Blink API.
	 *
	 * @var array|null
	 */
	public $intent;

	/**
	 * Payment methods the merchant has enabled and Blink allows.
	 *
	 * @var array
	 */
	public $payment_methods = array();

	/**
	 * Payment source of the transaction being processed.
	 *
	 * @var string|null
	 */
	public $payment_source;

	/**
	 * Status of the transaction being processed.
	 *
	 * @var string|null
	 */
	public $payment_status;

	/**
	 * Renders and validates the checkout payment fields.
	 *
	 * @var Blink_PG_Payment_Fields_Handler
	 */
	public $fields_handler;

	/**
	 * Takes payments for orders.
	 *
	 * @var Blink_PG_Payment_Handler
	 */
	public $payment_handler;

	/**
	 * Builds the gateway settings form.
	 *
	 * @var Blink_PG_Settings_Handler
	 */
	public $settings_handler;

	/**
	 * Talks to the Blink API for tokens and payment intents.
	 *
	 * @var Blink_PG_Payment_Utils
	 */
	public $utils;

	/**
	 * Handles refunds and cancellations.
	 *
	 * @var Blink_PG_Refund_Handler
	 */
	public $refund_handler;

	/**
	 * Handles re-running failed payments.
	 *
	 * @var Blink_PG_Rerun_Handler
	 */
	public $rerun_handler;

	/**
	 * Handles webhooks and payment returns.
	 *
	 * @var Blink_PG_Transaction_Handler
	 */
	public $transaction_handler;

	/**
	 * API key for the current mode.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Secret key for the current mode.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Whether test mode is on.
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Whether Apple Pay is enabled.
	 *
	 * @var bool
	 */
	public $apple_pay_enabled;

	/**
	 * Whether card payments are pre-authorised rather than captured.
	 *
	 * @var bool
	 */
	public $preauthorize_payments;

	/**
	 * Plugin configuration loaded from config.php.
	 *
	 * @var array
	 */
	public $configs;

	/**
	 * Base URL of the Blink API.
	 *
	 * @var string
	 */
	public $host_url;

	/**
	 * Integration type, either 'direct' or 'hosted'.
	 *
	 * @var string
	 */
	public $integration_type;

	/**
	 * Plugin version, used for asset cache busting.
	 *
	 * @var string
	 */
	public $version;


	/**
	 * Load the settings, create the handlers and register the gateway's hooks.
	 */
	public function __construct() {

		$this->configs            = include __DIR__ . '/../config.php';
		$this->id                 = str_replace( ' ', '', strtolower( $this->configs['method_title'] ) );
		$this->icon               = plugins_url( '/../assets/img/blink_logo_sml.svg', __FILE__ );
		$this->has_fields         = true; // in case you need a custom credit card form.
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
		$this->title                 = $this->get_option( 'title' );
		$this->description           = $this->get_option( 'description' );
		$this->enabled               = $this->get_option( 'enabled' );
		$this->integration_type      = $this->get_option( 'integration_type' );
		$this->testmode              = 'yes' === $this->get_option( 'testmode' );
		$this->apple_pay_enabled     = 'yes' === $this->get_option( 'apple_pay_enabled' );
		$this->preauthorize_payments = 'yes' === $this->get_option( 'preauthorize_payments' );
		$this->api_key               = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
		$this->secret_key            = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );
		$token                       = get_option( 'blink_pg_admin_token' );

		$this->fields_handler      = new Blink_PG_Payment_Fields_Handler( $this );
		$this->payment_handler     = new Blink_PG_Payment_Handler( $this );
		$this->settings_handler    = new Blink_PG_Settings_Handler( $this->api_key, $this->secret_key );
		$this->utils               = new Blink_PG_Payment_Utils( $this );
		$this->refund_handler      = new Blink_PG_Refund_Handler( $this );
		$this->rerun_handler       = new Blink_PG_Rerun_Handler( $this );
		$this->transaction_handler = new Blink_PG_Transaction_Handler( $this );

		// Method with all the options fields.
		$this->blink_init_form_fields();

		$selected_methods = array();
		if ( is_array( $token ) && isset( $token['payment_types'] ) ) {
			foreach ( $token['payment_types'] as $type ) {
				// If preauth is enabled, only allow credit-card payment method.
				if ( $this->preauthorize_payments && 'credit-card' !== $type ) {
					continue;
				}
				$selected_methods[] = ( 'yes' === $this->get_option( $type ) ) ? $type : '';
			}
		}
		$this->payment_methods = array_filter( $selected_methods );
		$this->blink_add_error_notices();

		// This action hook saves the settings.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'blink_process_admin_options' ), 99 );
		// if needed we can use this webhook.
		add_action( 'woocommerce_api_blink_gateway', array( $this->transaction_handler, 'blink_webhook' ) );
		add_action( 'woocommerce_thankyou_blink', array( $this->transaction_handler, 'blink_check_response_for_order' ) );
		add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'blink_change_title' ), 99 );

		add_filter( 'woocommerce_admin_order_should_render_refunds', array( $this->refund_handler, 'blink_should_render_refunds' ), 10, 3 );
		add_filter( 'woocommerce_order_item_add_action_buttons', array( $this->refund_handler, 'blink_add_cancel_button' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'blink_enqueue_scripts' ), 10 );
		add_action( 'admin_footer', array( $this, 'blink_clear_admin_notice' ) );
		add_action( 'woocommerce_before_thankyou', array( $this, 'blink_print_custom_notice' ) );

		// We need custom JavaScript to obtain a token.
		add_action( 'wp_enqueue_scripts', array( $this, 'blink_payment_scripts' ) );
	}

	/**
	 * Render the Blink payment fields at checkout.
	 */
	public function payment_fields() {
		$this->fields_handler->blink_render_payment_fields();
	}

	/**
	 * Output the Blink gateway settings inside a settings-specific wrapper.
	 *
	 * The wrapper keeps presentation changes scoped to this gateway while the
	 * underlying fields continue to use WC_Settings_API rendering and saving.
	 */
	public function admin_options() {
		echo '<div class="blink-settings">';
		parent::admin_options();
		echo '</div>';
	}

	/**
	 * Close the native Advanced disclosure after WooCommerce has generated its
	 * fields, before the outer settings table is closed.
	 *
	 * @param array $form_fields Form fields.
	 * @param bool  $output      Whether to echo the markup.
	 * @return string
	 */
	public function generate_settings_html( $form_fields = array(), $output = true ) {
		$fields = empty( $form_fields ) ? $this->get_form_fields() : $form_fields;
		$html   = parent::generate_settings_html( $fields, false );
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

		if ( isset( $fields['advanced']['blink_advanced_start'] ) ) {
			$html .= '</table></details>';
		}

		if ( $output ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by WC_Settings_API::generate_settings_html(), which escapes each field.
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
	public function generate_title_html( $key, $data ) {
		if ( empty( $data['blink_first_section'] ) && empty( $data['blink_subheading'] ) && empty( $data['blink_apple_pay'] ) && empty( $data['blink_after_standalone'] ) && empty( $data['blink_choice_section'] ) && empty( $data['blink_advanced_start'] ) ) {
			return parent::generate_title_html( $key, $data );
		}

		$field_key      = $this->get_field_key( $key );
		$data           = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'class'       => '',
				'description' => '',
			)
		);
		$choice_section = isset( $data['blink_choice_section'] ) ? $data['blink_choice_section'] : '';

		ob_start();
		if ( ! empty( $data['blink_first_section'] ) ) {
			?>
			<caption class="blink-settings-caption">
				<div>
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p><?php echo wp_kses_post( $data['description'] ); ?></p>
					<?php endif; ?>
				</div>
			</caption>
			<?php
		} elseif ( ! empty( $data['blink_subheading'] ) ) {
			?>
			<tr class="blink-settings-subheading">
				<th colspan="2" scope="colgroup">
					<h4 id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h4>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p><?php echo wp_kses_post( $data['description'] ); ?></p>
					<?php endif; ?>
				</th>
			</tr>
			<?php
		} elseif ( 'start' === $choice_section ) {
			?>
			</table>
			<div class="blink-settings-choice-columns">
				<section class="blink-settings-choice-section blink-settings-choice-section--<?php echo esc_attr( $data['blink_choice_kind'] ); ?>" aria-labelledby="<?php echo esc_attr( $field_key ); ?>">
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p><?php echo wp_kses_post( $data['description'] ); ?></p>
					<?php endif; ?>
					<table class="form-table">
			<?php
		} elseif ( 'middle' === $choice_section ) {
			?>
					</table>
				</section>
				<section class="blink-settings-choice-section blink-settings-choice-section--<?php echo esc_attr( $data['blink_choice_kind'] ); ?>" aria-labelledby="<?php echo esc_attr( $field_key ); ?>">
					<h3 class="wc-settings-sub-title blink-settings-choice-heading" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p><?php echo wp_kses_post( $data['description'] ); ?></p>
					<?php endif; ?>
					<table class="form-table">
			<?php
		} elseif ( ! empty( $data['blink_apple_pay'] ) ) {
			$status_key        = $data['blink_status_key'];
			$status_field_key  = $this->get_field_key( $status_key );
			$status_attributes = array(
				'custom_attributes' => $data['blink_status_attributes'],
			);
			$is_enabled        = ! empty( $data['blink_registered'] ) && 'yes' === $this->get_option( $status_key );
			?>
			</table>
			<?php if ( ! empty( $data['blink_choices_wrapper_open'] ) ) : ?>
			</section>
			</div>
			<?php endif; ?>
			<section class="blink-apple-pay-card" aria-labelledby="<?php echo esc_attr( $field_key ); ?>">
				<div class="blink-apple-pay-header">
					<h3 class="wc-settings-sub-title" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
					<span class="blink-settings-status <?php echo $is_enabled ? 'is-enabled' : 'is-disabled'; ?>" data-blink-apple-pay-status data-enabled-label="<?php echo esc_attr( __( 'Enabled', 'blink-payment-gateway-for-woocommerce' ) ); ?>" data-disabled-label="<?php echo esc_attr( __( 'Not enabled', 'blink-payment-gateway-for-woocommerce' ) ); ?>" aria-live="polite">
						<?php echo esc_html( $is_enabled ? __( 'Enabled', 'blink-payment-gateway-for-woocommerce' ) : __( 'Not enabled', 'blink-payment-gateway-for-woocommerce' ) ); ?>
					</span>
				</div>
				<p>
					<label for="<?php echo esc_attr( $status_field_key ); ?>">
						<input type="checkbox" name="<?php echo esc_attr( $status_field_key ); ?>" id="<?php echo esc_attr( $status_field_key ); ?>" value="1" <?php checked( $this->get_option( $status_key ), 'yes' ); ?> <?php echo $this->get_custom_attribute_html( $status_attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes each attribute. ?> />
						<?php echo esc_html__( 'Enable Apple Pay at checkout', 'blink-payment-gateway-for-woocommerce' ); ?>
					</label>
				</p>
				<?php echo wp_kses_post( $data['description'] ); ?>
			</section>
			<?php
		} elseif ( ! empty( $data['blink_advanced_start'] ) ) {
			?>
			</table>
			<details class="blink-settings-advanced">
				<summary>
					<span class="blink-settings-advanced-title"><?php echo wp_kses_post( $data['title'] ); ?></span>
					<span class="blink-settings-advanced-description"><?php echo wp_kses_post( $data['description'] ); ?></span>
				</summary>
				<table class="form-table">
			<?php
		} else {
			?>
			<h3 class="wc-settings-sub-title <?php echo esc_attr( $data['class'] ); ?>" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
			<?php if ( ! empty( $data['description'] ) ) : ?>
				<p><?php echo wp_kses_post( $data['description'] ); ?></p>
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
	public function generate_select_html( $key, $data ) {
		if ( empty( $data['blink_descriptions'] ) ) {
			return parent::generate_select_html( $key, $data );
		}

		$value                     = $this->get_option( $key );
		$descriptions              = $data['blink_descriptions'];
		$data['description']       = isset( $descriptions[ $value ] ) ? $descriptions[ $value ] : $descriptions['direct'];
		$data['custom_attributes'] = isset( $data['custom_attributes'] ) ? $data['custom_attributes'] : array();
		$data['custom_attributes']['data-direct-description'] = $descriptions['direct'];
		$data['custom_attributes']['data-hosted-description'] = $descriptions['hosted'];

		return parent::generate_select_html( $key, $data );
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
	public function generate_checkbox_html( $key, $data ) {
		if ( ! empty( $data['blink_status'] ) ) {
			return '';
		}

		if ( empty( $data['blink_group'] ) && empty( $data['blink_mode_status'] ) ) {
			return parent::generate_checkbox_html( $key, $data );
		}

		$field_key = $this->get_field_key( $key );
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
		$data      = wp_parse_args( $data, $defaults );

		if ( ! $data['label'] ) {
			$data['label'] = $data['title'];
		}

		ob_start();
		if ( ! empty( $data['blink_group'] ) ) {
			?>
			<tr valign="top" class="blink-settings-choice-row" data-blink-settings-group="<?php echo esc_attr( $data['blink_group'] ); ?>">
				<th scope="row" class="screen-reader-text"><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['label'] ); ?></label></th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['label'] ); ?></span></legend>
						<label class="blink-settings-choice" for="<?php echo esc_attr( $field_key ); ?>">
							<input <?php disabled( $data['disabled'], true ); ?> class="<?php echo esc_attr( $data['class'] ); ?>" type="checkbox" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="1" <?php checked( $this->get_option( $key ), 'yes' ); ?> <?php echo $this->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes each attribute. ?> />
							<span><?php echo wp_kses_post( $data['label'] ); ?></span>
						</label>
						<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API runs the description through wp_kses_post(). ?>
					</fieldset>
				</td>
			</tr>
			<?php
		} else {
			$is_test_mode = 'yes' === $this->get_option( $key );
			?>
			<tr valign="top" class="blink-settings-mode-row">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes the tooltip via wc_help_tip(). ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
						<label for="<?php echo esc_attr( $field_key ); ?>">
							<input <?php disabled( $data['disabled'], true ); ?> class="<?php echo esc_attr( $data['class'] ); ?>" type="checkbox" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="1" <?php checked( $this->get_option( $key ), 'yes' ); ?> <?php echo $this->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API escapes each attribute. ?> /> <?php echo wp_kses_post( $data['label'] ); ?>
						</label>
						<span class="blink-settings-mode-status <?php echo $is_test_mode ? 'is-test' : 'is-live'; ?>" data-blink-test-mode-status data-test-label="<?php echo esc_attr( __( 'Using test credentials', 'blink-payment-gateway-for-woocommerce' ) ); ?>" data-live-label="<?php echo esc_attr( __( 'Using live credentials', 'blink-payment-gateway-for-woocommerce' ) ); ?>" aria-live="polite"><?php echo esc_html( $is_test_mode ? __( 'Using test credentials', 'blink-payment-gateway-for-woocommerce' ) : __( 'Using live credentials', 'blink-payment-gateway-for-woocommerce' ) ); ?></span>
						<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC_Settings_API runs the description through wp_kses_post(). ?>
					</fieldset>
				</td>
			</tr>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * Take payment for an order through the payment handler.
	 *
	 * @param int $order_id Order ID.
	 * @return array|null Result and redirect URL for WooCommerce, or null on the order-pay page when there is no intent or token.
	 */
	public function process_payment( $order_id ) {
		return $this->payment_handler->blink_handle_payment( $order_id );
	}

	/**
	 * Render the Blink icon and the merchant-selected card logos in Classic Checkout.
	 *
	 * WooCommerce's icon property accepts only one URL, so the fixed local card
	 * logos are appended to its standard escaped Blink icon markup.
	 *
	 * @return string
	 */
	public function get_icon() {
		return parent::get_icon() . blink_pg_get_card_logos_html( $this->settings );
	}

	/**
	 * Refresh the access token with the keys just saved. Runs on
	 * woocommerce_update_options_payment_gateways_blink, after WooCommerce has
	 * checked the settings form nonce (WC_Admin_Settings::save()).
	 */
	public function blink_process_admin_options() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce before this hook.
		$testmode = isset( $_POST['woocommerce_blink_testmode'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['woocommerce_blink_testmode'] ) );
		$prefix   = $testmode ? 'woocommerce_blink_test_' : 'woocommerce_blink_';

		$this->api_key    = isset( $_POST[ $prefix . 'api_key' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'api_key' ] ) ) : '';
		$this->secret_key = isset( $_POST[ $prefix . 'secret_key' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'secret_key' ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$token = $this->utils->blink_generate_access_token();
		update_option( 'blink_pg_admin_token', $token );
	}

	/**
	 * Refund an order through the refund handler.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount to refund.
	 * @param string     $reason   Reason for the refund.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '__' ) {
		return $this->refund_handler->blink_handle_refund( $order_id, $amount, $reason );
	}

	/**
	 * Enqueue the admin scripts and styles for users who can manage WooCommerce.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function blink_enqueue_scripts( $hook ) {
		// Only load admin scripts on relevant pages and for users with proper permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_enqueue_script( 'woocommerce_blink_payment_admin_scripts', plugins_url( '/../assets/js/admin-scripts.js', __FILE__ ), array( 'jquery' ), $this->version, true );
		wp_enqueue_style( 'woocommerce_blink_payment_admin_css', plugins_url( '/../assets/css/admin.css', __FILE__ ), array(), $this->version );
		if ( blink_pg_is_in_admin_section() ) {
			wp_enqueue_script( 'woocommerce_blink_payment_admin_settings', plugins_url( '/../assets/js/admin-settings.js', __FILE__ ), array( 'jquery' ), $this->version, true );
		}

		wp_localize_script(
			'woocommerce_blink_payment_admin_scripts',
			'blinkOrders',
			array(
				'ajaxurl'        => admin_url( 'admin-ajax.php' ),
				'cancel_order'   => wp_create_nonce( 'cancel_order_nonce' ),
				'spin_gif'       => admin_url( 'images/spinner.gif' ),
				'apihost'        => $this->host_url,
				'security'       => wp_create_nonce( 'generate_access_token_nonce' ),
				'apple_security' => wp_create_nonce( 'generate_applepay_domains_nonce' ),
				'remoteAddress'  => blink_pg_get_client_ip(),
			)
		);
	}

	/**
	 * Remove the Blink admin notices once they have been shown.
	 */
	public function blink_clear_admin_notice() {
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->remove_notice( 'blink-error' );
		$adminnotice->remove_notice( 'no-api' );
		$adminnotice->remove_notice( 'no-payment-type-selected' );
		$adminnotice->remove_notice( 'no-payment-types' );
	}

	/**
	 * Print the hosted-fields CSS in a <style class="hostedfield"> tag.
	 */
	public function blink_print_hostedfield_styles() {
		$css_path = plugin_dir_path( __FILE__ ) . '../assets/css/hostedfields.css';

		if ( ! file_exists( $css_path ) ) {
			return;
		}

		$css = file_get_contents( $css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.

		if ( false === $css ) {
			return;
		}

		/**
		 * Filters the CSS printed as <style class="hostedfield">.
		 *
		 * The hosted-fields SDK copies the rules whose selector includes
		 * .hostedfield into the Card Number, Expiry and CVV iframes, so this is
		 * the only way to style the text inside them.
		 *
		 * @param string $css Contents of assets/css/hostedfields.css.
		 * @since 1.4.2 Renamed from blink_hostedfield_css, which still runs.
		 */
		$css = apply_filters_deprecated( 'blink_hostedfield_css', array( $css ), '1.4.2', 'blink_pg_hostedfield_css' );
		$css = apply_filters( 'blink_pg_hostedfield_css', $css );

		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return;
		}

		echo '<style class="hostedfield">' . "\n";
		echo wp_strip_all_tags( $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n</style>\n";
	}

	/**
	 * Add admin notices for missing API keys or payment methods.
	 *
	 * @param array $payment_types Not used.
	 */
	public function blink_add_error_notices( $payment_types = array() ) {

		if ( blink_pg_is_in_admin_section() ) {

			$adminnotice = new WC_Admin_Notices();
			$token       = get_option( 'blink_pg_admin_token' );

			if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
				$live = $this->testmode ? __( 'Test', 'blink-payment-gateway-for-woocommerce' ) : __( 'Live', 'blink-payment-gateway-for-woocommerce' );
				if ( ! $adminnotice->has_notice( 'no-api' ) ) {
					/* translators: %s is either "Test" or "Live" depending on the mode. */
					$adminnotice->add_custom_notice( 'no-api', '<div>' . sprintf( __( 'Please add %s API key and Secret Key', 'blink-payment-gateway-for-woocommerce' ), $live ) . '</div>' );
				}
			} else {
				$adminnotice->remove_notice( 'no-api' );
				if ( ! empty( $token['payment_types'] ) ) {
					if ( empty( $this->payment_methods ) ) {
						if ( ! $adminnotice->has_notice( 'no-payment-type-selected' ) ) {
							$adminnotice->add_custom_notice( 'no-payment-type-selected', '<div>' . __( 'Please select the Payment Methods and save the configuration!', 'blink-payment-gateway-for-woocommerce' ) . '</div>' );
						}
					} else {
						$adminnotice->remove_notice( 'no-payment-type-selected' );
					}
					$adminnotice->remove_notice( 'no-payment-types' );
				} elseif ( ! $adminnotice->has_notice( 'no-payment-types' ) ) {
					$adminnotice->add_custom_notice( 'no-payment-types', '<div>' . __( 'There is no Payment Types Available.', 'blink-payment-gateway-for-woocommerce' ) . '</div>' );
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
	public function blink_get_return_url( $order = null ) {
		if ( $order ) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
		}
		$return_url = apply_filters_deprecated( 'blink_get_return_url', array( $return_url, $order ), '1.4.2', 'blink_pg_get_return_url' );
		return apply_filters( 'blink_pg_get_return_url', $return_url, $order );
	}



	/**
	 * Plugin options.
	 *
	 * @param array $payment_types Not used.
	 */
	public function blink_init_form_fields( $payment_types = array() ) {
		if ( ! blink_pg_is_in_admin_section() ) {
			return;
		}

		$this->form_fields = $this->settings_handler->blink_get_form_fields();
	}


	/**
	 * Enqueue the checkout and pay-for-order scripts and styles when the gateway can take payments.
	 */
	public function blink_payment_scripts() {
		// we need JavaScript to process a token only on cart/checkout pages, right?
		$is_order_pay_endpoint = is_wc_endpoint_url( 'order-pay' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which page is loading scripts.
		$is_order_pay      = $is_order_pay_endpoint || isset( $_GET['pay_for_order'] );
		$is_checkout_block = function_exists( 'blink_pg_is_checkout_block' ) && blink_pg_is_checkout_block();

		if ( ! is_cart() && ! is_checkout() && ! $is_order_pay && ! $is_checkout_block ) {
			return;
		}
		// if our payment gateway is disabled, we do not have to enqueue JS too.
		if ( 'no' === $this->enabled ) {
			return;
		}
		// no reason to enqueue JavaScript if API keys are not set.
		if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
			return;
		}
		// do not work with card details without SSL unless your website is in a test mode.
		if ( ! $this->testmode && ! is_ssl() ) {
			return;
		}
		$order = $is_order_pay_endpoint ? wc_get_order( absint( get_query_var( 'order-pay' ) ) ) : false;
		if ( $is_order_pay_endpoint && ! blink_pg_can_view_order_pay( $order ) ) {
			// WooCommerce shows its own invalid order message, so there is nothing to pay, and the
			// script below would print the order's billing details into the page.
			return;
		}

		wp_add_inline_script( 'jquery', '$ = jQuery.noConflict();' );

		// Blink's own hosted card fields SDK. It can't be bundled: card data must go
		// straight to Blink for PCI DSS. No version argument, because the plugin's
		// version says nothing about the SDK's; Blink's server handles its caching.
		wp_enqueue_script( 'blink_hosted_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js', array( 'jquery' ), null, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Remote SDK versioned by Blink, see above.
		wp_register_style( 'woocommerce_blink_payment_style', plugins_url( '../assets/css/style.css', __FILE__ ), array(), $this->version );
		wp_register_script( 'woocommerce_blink_wallet_submit', plugins_url( '../assets/js/wallet-submit.js', __FILE__ ), array( 'jquery' ), $this->version, true );
		wp_register_script( 'woocommerce_blink_card_form_fields', plugins_url( '../assets/js/card-form-fields.js', __FILE__ ), array(), $this->version, true );
		// and this is our custom JS in your plugin directory that works with token.js.
		if ( $is_order_pay_endpoint ) {
			wp_register_script( 'woocommerce_blink_payment_order_pay', plugins_url( '../assets/js/order-pay.js', __FILE__ ), array( 'jquery', 'woocommerce_blink_wallet_submit', 'woocommerce_blink_card_form_fields' ), $this->version, true );

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
					'ajaxurl'            => admin_url( 'admin-ajax.php' ),
					'remoteAddress'      => blink_pg_get_client_ip(),
					'security'           => wp_create_nonce( 'blink_payment_fields_nonce' ),
				)
			);
			wp_enqueue_script( 'woocommerce_blink_payment_order_pay' );
		} elseif ( is_checkout() && ! $is_checkout_block ) {
			wp_register_script( 'woocommerce_blink_payment_checkout', plugins_url( '../assets/js/checkout.js', __FILE__ ), array( 'jquery', 'woocommerce_blink_wallet_submit', 'woocommerce_blink_card_form_fields' ), $this->version, true );
			wp_localize_script(
				'woocommerce_blink_payment_checkout',
				'blink_params',
				array(
					'ajaxurl'       => admin_url( 'admin-ajax.php' ),
					'remoteAddress' => blink_pg_get_client_ip(),
				)
			);

			wp_enqueue_script( 'woocommerce_blink_payment_checkout' );
		}
		wp_enqueue_style( 'woocommerce_blink_payment_style' );
		$custom_css = $this->get_option( 'custom_style' );
		if ( $custom_css ) {
			wp_add_inline_style( 'woocommerce_blink_payment_style', $custom_css );
		}

		do_action_deprecated( 'blink_custom_script', array(), '1.4.2', 'blink_pg_custom_script' );
		do_action( 'blink_pg_custom_script' );
		do_action_deprecated( 'blink_custom_style', array(), '1.4.2', 'blink_pg_custom_style' );
		do_action( 'blink_pg_custom_style' );
		add_action(
			'wp_head',
			array( $this, 'blink_print_hostedfield_styles' ),
			20
		);
	}

	/**
	 * Validate the checkout payment fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {

		return $this->fields_handler->blink_validate_fields();
	}


	/**
	 * Show "Order Failed" as the order-received page title when the order has failed.
	 *
	 * @param string $title Page title.
	 * @return string
	 */
	public function blink_change_title( $title ) {
		global $wp;
		$order_id = $wp->query_vars['order-received'];
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order->has_status( 'failed' ) ) {
				return __( 'Order Failed', 'blink-payment-gateway-for-woocommerce' );
			}
		}

		return $title;
	}

	/**
	 * Print the stored payment message as an error notice on the thank-you page of a failed Blink order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function blink_print_custom_notice( $order_id = 0 ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || 'blink' !== $order->get_payment_method() || ! $order->has_status( 'failed' ) ) {
			return;
		}

		$message = blink_pg_resolve_payment_message( $order->get_meta( '_blink_payment_message', true ) );
		if ( '' !== $message ) {
			wc_print_notice( $message, 'error' );
		}
	}

	/**
	 * Whether the gateway uses the Hosted (Paylink) integration rather than Direct.
	 *
	 * @return bool
	 */
	public function blink_is_hosted() {
		return ( 'direct' !== $this->integration_type );
	}
}
