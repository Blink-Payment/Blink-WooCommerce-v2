<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
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
	 */
	public function blink_get_form_fields() {
		// Basic fields
		$fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable/Disable', 'blink-payment-gateway-for-woocommerce' ),
				'label'       => __( 'Enable Blink Gateway', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => __( 'Blink v2', 'blink-payment-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => __( 'Pay with your credit card or direct debit at your convenience.', 'blink-payment-gateway-for-woocommerce' ),
			),
			'integration_type' => array(
				'title'       => __( 'Integration Type', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Choose the integration type for the payment gateway.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => 'checkout',
				'options'     => array(
            			'direct'  => __( 'Direct', 'blink-payment-gateway-for-woocommerce' ),
            			'hosted'  => __( 'Hosted', 'blink-payment-gateway-for-woocommerce' ),
				),
				'default' => 'direct',

			),
			'testmode'        => array(
				'title'       => __( 'Test mode', 'blink-payment-gateway-for-woocommerce' ),
				'label'       => __( 'Enable Test Mode', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in test mode using test API keys.', 'blink-payment-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key'    => array(
				'title' => __( 'Test API Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'text',
			),
			'test_secret_key' => array(
				'title' => __( 'Test Secret Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'password',
			),
			'api_key'         => array(
				'title' => __( 'Live API Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'text',
			),
			'secret_key'      => array(
				'title' => __( 'Live Secret Key', 'blink-payment-gateway-for-woocommerce' ),
				'type'  => 'password',
			),
			'custom_style'    => array(
				'title'       => __( 'Custom Style', 'blink-payment-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Do not include style tag', 'blink-payment-gateway-for-woocommerce' ),
			),
		);

		// Payment methods
		$token = get_option( 'blink_admin_token' );
		if ( $this->api_key && $this->secret_key && ! empty( $token['payment_types'] ) ) {
			$pay_methods = array(
				'pay_methods' => array(
					'title'       => __( 'Payment Methods', 'blink-payment-gateway-for-woocommerce' ),
					'type'        => 'hidden',
					'description' => '',
					'default'     => '',
				),
			);

			$fields = array_merge( $fields, $pay_methods );
			foreach ( $token['payment_types'] as $type ) {
				// Check if preauth is enabled
				$preauth_enabled = get_option( 'woocommerce_blink_settings' );
				$is_preauth_mode = isset($preauth_enabled['preauthorize_payments']) && 'yes' === $preauth_enabled['preauthorize_payments'];
				
				// If preauth is enabled, disable non-credit card methods
				$disabled_attributes = array();
				if ( $is_preauth_mode && $type !== 'credit-card' ) {
					$disabled_attributes = array( 'disabled' => 'disabled' );
				}
				
				$fields[ $type ] = array(
					'title'             => '',
					'label'             => ucwords( str_replace( '-', ' ', $type ) ),
					'type'              => 'checkbox',
					'default'           => 'no',
					'custom_attributes' => $disabled_attributes,
				);
			}
		}

		// Apple Pay Settings
		$blink_apple_domain_auth = ! empty( get_option( 'blink_apple_domain_auth' ) );
		$disabled                = $blink_apple_domain_auth ? array() : array( 'disabled' => 'disabled' );

		$fields['apple_pay_enrollment'] = array(
			'title'       => __( 'Apple Pay Enrollment', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'title',
			'description' => sprintf(
				/* translators: 1: URL to download the domain verification file, 2: Server domain name. */
				__(
					'To enable Apple Pay please:<br>
                Download the domain verification file (DVF) <a href="%1$s">here</a>.<br>
                Upload it to your domain as follows: "https://%2$s/.well-known/apple-developer-merchantid-domain-association".<br>
                <button id="enable-apple-pay" class="button">Click here to enable</button>',
					'blink-payment-gateway-for-woocommerce'
				),
				esc_url( plugin_dir_url( __FILE__ ) . 'download-apple-pay-dvf.php' ),
				isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : ''
			),
		);

		$fields['apple_pay_enabled'] = array(
			'title'             => __( 'Apple Pay Enabled', 'blink-payment-gateway-for-woocommerce' ),
			'type'              => 'checkbox',
			'default'           => 'yes',
			'custom_attributes' => $disabled,
		);

		// Preauthorization settings
		$fields['preauthorize_payments'] = array(
			'title'       => __( 'Preauthorise Payments', 'blink-payment-gateway-for-woocommerce' ),
			'label'       => __( 'Enable preauthorisation and manual capture', 'blink-payment-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __( 'Preauthorise your customer\'s order at checkout and charge later', 'blink-payment-gateway-for-woocommerce' ),
			'default'     => 'no',
			'desc_tip'    => true,
			'custom_attributes' => array(
				'data-preauth-toggle' => 'true'
			),
		);

		// Add admin notice when preauthorization is enabled
		if ( $this->api_key && $this->secret_key ) {
			$preauth_enabled = get_option( 'woocommerce_blink_preauthorize_payments' );
			if ( 'yes' === $preauth_enabled ) {
				$fields['preauth_notice'] = array(
					'title'       => __( 'Preauthorization Active', 'blink-payment-gateway-for-woocommerce' ),
					'type'        => 'title',
					'description' => sprintf(
						'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
						__( 'Preauthorization Mode Enabled:', 'blink-payment-gateway-for-woocommerce' ),
						__( 'Only credit card payments are available. Open Banking and Direct Debit are disabled. Customers will be charged when you manually process their orders.', 'blink-payment-gateway-for-woocommerce' )
					),
				);
			}
		}

		// Debug settings
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
				'description' => sprintf(
					/* translators: 1: download URL */
					__( 'Download today\'s log file <a href="%1$s">here</a>.', 'blink-payment-gateway-for-woocommerce' ),
					esc_url( $download_url )
				),
			);
		}

		return $fields;
	}
}
