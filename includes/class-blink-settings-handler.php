<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Blink_Settings_Handler {

	protected $api_key;
	protected $secret_key;

	public function __construct( $api_key, $secret_key ) {
		$this->api_key    = $api_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * Generate form fields for the settings.
	 *
	 * Field keys are persisted by WC_Settings_API in the existing
	 * woocommerce_blink_settings option. Title fields only create sections.
	 *
	 * @return array
	 */
	public function blink_get_form_fields() {
		$fields = array(
			'general'         => array(
				'title'       => __( 'General', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-section',
				'description' => __( 'Control how Blink appears to customers at checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'blink_first_section' => true,
			),
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'blink-payment-gateway-for-woocommerce' ),
				'label'   => __( 'Enable Blink Gateway', 'blink-payment-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Checkout title', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the customer sees during checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => __( 'Blink v2', 'blink-payment-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the customer sees during checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => __( 'Pay with your credit card or direct debit at your convenience.', 'blink-payment-gateway-for-woocommerce' ),
			),
			'integration_type' => array(
				'title'       => __( 'Integration Type', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Payment fields are embedded directly within the WooCommerce checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'blink_descriptions' => array(
					'direct' => __( 'Payment fields are embedded directly within the WooCommerce checkout.', 'blink-payment-gateway-for-woocommerce' ),
					'hosted' => __( 'The customer is redirected to Blink to complete payment. Hosted provides greater compatibility where theme, plugin or checkout customisations affect Direct.', 'blink-payment-gateway-for-woocommerce' ),
				),
				'default'     => 'direct',
				'options'     => array(
					'direct' => __( 'Direct', 'blink-payment-gateway-for-woocommerce' ),
					'hosted' => __( 'Hosted', 'blink-payment-gateway-for-woocommerce' ),
				),
			),
			'api_connection'  => array(
				'title'       => __( 'API Connection', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-section',
				'description' => __( 'Choose the environment Blink should use and enter the corresponding credentials.', 'blink-payment-gateway-for-woocommerce' ),
			),
			'testmode'        => array(
				'title'             => __( 'Test Mode', 'blink-payment-gateway-for-woocommerce' ),
				'label'             => __( 'Enable Test Mode', 'blink-payment-gateway-for-woocommerce' ),
				'type'              => 'checkbox',
				'description'       => __( 'When enabled, Blink uses the test credentials below. Live credentials are retained and are used again when Test Mode is disabled.', 'blink-payment-gateway-for-woocommerce' ),
				'default'           => 'yes',
				'desc_tip'          => true,
				'blink_mode_status' => true,
			),
			'test_credentials' => array(
				'title'       => __( 'Test credentials', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-subsection blink-settings-subsection--test',
				'description' => __( 'Used only while Test Mode is enabled.', 'blink-payment-gateway-for-woocommerce' ),
				'blink_subheading' => true,
			),
			'test_api_key'    => array(
				'title' => __( 'Test API Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'text',
				'class' => 'blink-api-test-field',
			),
			'test_secret_key' => array(
				'title' => __( 'Test Secret Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'password',
				'class' => 'blink-api-test-field',
			),
			'live_credentials' => array(
				'title'       => __( 'Live credentials', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-subsection blink-settings-subsection--live',
				'description' => __( 'Used for live payments when Test Mode is disabled.', 'blink-payment-gateway-for-woocommerce' ),
				'blink_subheading' => true,
			),
			'api_key'         => array(
				'title' => __( 'Live API Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'text',
				'class' => 'blink-api-live-field',
			),
			'secret_key'      => array(
				'title' => __( 'Live Secret Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'password',
				'class' => 'blink-api-live-field',
			),
		);

		// Payment methods are supplied by Blink for the active credentials.
		$token = get_option( 'blink_admin_token' );
		$has_payment_methods = false;
		if ( $this->api_key && $this->secret_key && ! empty( $token['payment_types'] ) ) {
			$has_payment_methods = true;
			$fields['pay_methods'] = array(
				'title'       => __( 'Payment Methods', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-section blink-settings-section--choices',
				'description' => __( 'Choose the payment methods offered by Blink at checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'blink_choice_section' => 'start',
				'blink_choice_kind'    => 'payment-methods',
			);

			$settings        = get_option( 'woocommerce_blink_settings', array() );
			$is_preauth_mode = isset( $settings['preauthorize_payments'] ) && 'yes' === $settings['preauthorize_payments'];

			foreach ( $token['payment_types'] as $type ) {
				$disabled_attributes = array();
				if ( $is_preauth_mode && 'credit-card' !== $type ) {
					$disabled_attributes = array( 'disabled' => 'disabled' );
				}

				$fields[ $type ] = array(
					'title'             => '',
					'label'             => ucwords( str_replace( '-', ' ', $type ) ),
					'type'              => 'checkbox',
					'default'           => 'no',
					'custom_attributes' => $disabled_attributes,
					'blink_group'       => 'payment-methods',
				);
			}
		}

		$fields['card_logos'] = array(
			'title'       => __( 'Accepted Card Logos', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'title',
			'class'       => 'blink-settings-section blink-settings-section--choices',
			'description' => __( 'Choose the card-brand logos displayed alongside Blink at checkout.', 'blink-payment-gateway-for-woocommerce' ),
			'blink_choice_section' => $has_payment_methods ? 'middle' : '',
			'blink_choice_kind'    => 'card-logos',
		);
		$fields['card_logo_visa'] = array(
			'title'       => '',
			'label'       => __( 'Visa', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'default'     => 'no',
			'blink_group' => 'card-logos',
		);
		$fields['card_logo_mastercard'] = array(
			'title'       => '',
			'label'       => __( 'Mastercard', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'default'     => 'no',
			'blink_group' => 'card-logos',
		);
		$fields['card_logo_american_express'] = array(
			'title'       => '',
			'label'       => __( 'American Express', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'default'     => 'no',
			'blink_group' => 'card-logos',
		);

		$blink_apple_domain_auth = ! empty( get_option( 'blink_apple_domain_auth' ) );
		$disabled                = $blink_apple_domain_auth ? array() : array( 'disabled' => 'disabled' );
		$server_name             = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		$verification_path       = 'https://' . $server_name . '/.well-known/apple-developer-merchantid-domain-association';

		$fields['apple_pay_enrollment'] = array(
			'title'       => __( 'Apple Pay', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'title',
			'class'       => 'blink-settings-section blink-settings-section--apple-pay',
			'description' => sprintf(
				'<div class="blink-apple-pay-instructions"><p>%1$s</p><ol><li><a class="button button-secondary" href="%2$s">%3$s</a></li><li>%4$s <code>%5$s</code>.</li><li><button id="enable-apple-pay" type="button" class="button button-primary">%6$s</button></li></ol></div>',
				esc_html__( 'To enable Apple Pay for this store:', 'blink-payment-gateway-for-woocommerce' ),
				esc_url( plugin_dir_url( __FILE__ ) . 'download-apple-pay-dvf.php' ),
				esc_html__( 'Download domain verification file', 'blink-payment-gateway-for-woocommerce' ),
				esc_html__( 'Upload the file to', 'blink-payment-gateway-for-woocommerce' ),
				esc_html( $verification_path ),
				esc_html__( 'Verify domain and enable Apple Pay', 'blink-payment-gateway-for-woocommerce' )
			),
			'blink_apple_pay'         => true,
			'blink_status_key'        => 'apple_pay_enabled',
			'blink_registered'        => $blink_apple_domain_auth,
			'blink_status_attributes' => $disabled,
			'blink_choices_wrapper_open' => $has_payment_methods,
		);
		$fields['apple_pay_enabled'] = array(
			'title'             => __( 'Status', 'blink-payment-gateway-for-woocommerce' ),
			'label'             => __( 'Apple Pay enabled', 'blink-payment-gateway-for-woocommerce' ),
			'type'              => 'checkbox',
			'default'           => 'yes',
			'custom_attributes' => $disabled,
			'blink_status'      => true,
			'blink_registered'  => $blink_apple_domain_auth,
		);

		$fields['payment_options'] = array(
			'title'       => __( 'Payment Options', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'title',
			'class'       => 'blink-settings-section blink-settings-section--payment-options',
			'description' => __( 'Configure how Blink handles customer payments.', 'blink-payment-gateway-for-woocommerce' ),
			'blink_after_standalone' => true,
		);
		$fields['preauthorize_payments'] = array(
			'title'       => __( 'Preauthorise Payments', 'blink-payment-gateway-for-woocommerce' ),
			'label'       => __( 'Enable preauthorisation and manual capture', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __( 'Preauthorise your customer\'s order at checkout and charge later.', 'blink-payment-gateway-for-woocommerce' ),
			'default'     => 'no',
			'desc_tip'    => true,
			'custom_attributes' => array(
				'data-preauth-toggle' => 'true',
			),
		);

		// Keep the existing notice condition and behaviour unchanged.
		if ( $this->api_key && $this->secret_key ) {
			$preauth_enabled = get_option( 'woocommerce_blink_preauthorize_payments' );
			if ( 'yes' === $preauth_enabled ) {
				$fields['preauth_notice'] = array(
					'title'       => __( 'Preauthorization Active', 'blink-payment-gateway-for-woocommerce' ),
					'type'        => 'title',
					'class'       => 'blink-settings-notice',
					'description' => sprintf(
						'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
						__( 'Preauthorization Mode Enabled:', 'blink-payment-gateway-for-woocommerce' ),
						__( 'Only credit card payments are available. Open Banking and Direct Debit are disabled. Customers will be charged when you manually process their orders.', 'blink-payment-gateway-for-woocommerce' )
					),
				);
			}
		}

		$fields['advanced'] = array(
			'title'       => __( 'Advanced', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'title',
			'class'       => 'blink-settings-section blink-settings-section--advanced',
			'description' => __( 'Checkout customisation and troubleshooting.', 'blink-payment-gateway-for-woocommerce' ),
			'blink_advanced_start' => true,
		);
		$fields['custom_style'] = array(
			'title'       => __( 'Custom Style', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'textarea',
			'description' => __( 'Enter checkout CSS without a style tag.', 'blink-payment-gateway-for-woocommerce' ),
		);
		$fields['debug_mode'] = array(
			'title'       => __( 'Debug mode', 'blink-payment-gateway-for-woocommerce' ),
			'label'       => __( 'Enable debug logging', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __( 'When enabled, the plugin writes diagnostic logs to a file under Uploads/blink-logs. Use only for troubleshooting.', 'blink-payment-gateway-for-woocommerce' ),
			'default'     => 'no',
			'desc_tip'    => true,
		);

		if ( class_exists( 'Blink_Logger' ) && Blink_Logger::is_enabled() ) {
			$download_url = Blink_Logger::get_download_url();
			$fields['debug_download'] = array(
				'title'       => __( 'Download debug log', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'title',
				'class'       => 'blink-settings-subsection',
				'description' => sprintf(
					/* translators: 1: download URL. */
					__( 'Download today\'s log file <a href="%1$s">here</a>.', 'blink-payment-gateway-for-woocommerce' ),
					esc_url( $download_url )
				),
			);
		}

		return $fields;
	}
}
