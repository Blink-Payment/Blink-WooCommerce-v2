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
	public function get_form_fields() {
		// Basic fields
		$fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable/Disable', 'blink-payment-checkout' ),
				'label'       => __( 'Enable Blink Gateway', 'blink-payment-checkout' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'blink-payment-checkout' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'blink-payment-checkout' ),
				'default'     => __( 'Blink v2', 'blink-payment-checkout' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'blink-payment-checkout' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'blink-payment-checkout' ),
				'default'     => __( 'Pay with your credit card or direct debit at your convenience.', 'blink-payment-checkout' ),
			),
			'integration_type' => array(
				'title'       => __( 'Integration Type', 'blink-payment-checkout' ),
				'type'        => 'select',
				'description' => __( 'Choose the integration type for the payment gateway.', 'blink-payment-checkout' ),
				'default'     => 'checkout',
				'options'     => array(
            			'direct'  => __( 'Direct', 'blink-payment-checkout' ),
            			'hosted'  => __( 'Hosted', 'blink-payment-checkout' ),
				),
				'default' => 'direct',

			),
			'testmode'        => array(
				'title'       => __( 'Test mode', 'blink-payment-checkout' ),
				'label'       => __( 'Enable Test Mode', 'blink-payment-checkout' ),
				'type'        => 'checkbox',
				'description' => __( 'Place the payment gateway in test mode using test API keys.', 'blink-payment-checkout' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key'    => array(
				'title' => __( 'Test API Key', 'blink-payment-checkout' ),
				'type'  => 'text',
			),
			'test_secret_key' => array(
				'title' => __( 'Test Secret Key', 'blink-payment-checkout' ),
				'type'  => 'password',
			),
			'api_key'         => array(
				'title' => __( 'Live API Key', 'blink-payment-checkout' ),
				'type'  => 'text',
			),
			'secret_key'      => array(
				'title' => __( 'Live Secret Key', 'blink-payment-checkout' ),
				'type'  => 'password',
			),
			'custom_style'    => array(
				'title'       => __( 'Custom Style', 'blink-payment-checkout' ),
				'type'        => 'textarea',
				'description' => __( 'Do not include style tag', 'blink-payment-checkout' ),
			),
		);

		// Payment methods
		$token = get_option( 'blink_admin_token' );
		if ( $this->api_key && $this->secret_key && ! empty( $token['payment_types'] ) ) {
			$pay_methods = array(
				'pay_methods' => array(
					'title'       => __( 'Payment Methods', 'blink-payment-checkout' ),
					'type'        => 'hidden',
					'description' => '',
					'default'     => '',
				),
			);

			$fields = array_merge( $fields, $pay_methods );
			foreach ( $token['payment_types'] as $type ) {
				$fields[ $type ] = array(
					'title'   => '',
					'label'   => ucwords( str_replace( '-', ' ', $type ) ),
					'type'    => 'checkbox',
					'default' => 'no',
				);
			}
		}

		// Apple Pay Settings
		$blink_apple_domain_auth = ! empty( get_option( 'blink_apple_domain_auth' ) );
		$disabled                = $blink_apple_domain_auth ? array() : array( 'disabled' => 'disabled' );

		$fields['apple_pay_enrollment'] = array(
			'title'       => __( 'Apple Pay Enrollment', 'blink-payment-checkout' ),
			'type'        => 'title',
			'description' => sprintf(
				/* translators: 1: URL to download the domain verification file, 2: Server domain name. */
				__(
					'To enable Apple Pay please:<br>
                Download the domain verification file (DVF) <a href="%1$s" target="_blank">here</a>.<br>
                Upload it to your domain as follows: "https://%2$s/.well-known/apple-developer-merchantid-domain-association".<br>
                <button id="enable-apple-pay" class="button">Click here to enable</button>',
					'blink-payment-checkout'
				),
				esc_url( plugin_dir_url( __FILE__ ) . 'download-apple-pay-dvf.php' ),
				isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : ''
			),
		);

		$fields['apple_pay_enabled'] = array(
			'title'             => __( 'Apple Pay Enabled', 'blink-payment-checkout' ),
			'type'              => 'checkbox',
			'default'           => 'yes',
			'custom_attributes' => $disabled,
		);

		// Debug settings
		$fields['debug_mode'] = array(
			'title'       => __( 'Debug mode', 'blink-payment-checkout' ),
			'label'       => __( 'Enable debug logging', 'blink-payment-checkout' ),
			'type'        => 'checkbox',
			'description' => __( 'When enabled, the plugin writes diagnostic logs to a file under Uploads/blink-logs. Use only for troubleshooting.', 'blink-payment-checkout' ),
			'default'     => 'no',
			'desc_tip'    => true,
		);

		if ( class_exists( 'Blink_Logger' ) && Blink_Logger::is_enabled() ) {
			$download_url = Blink_Logger::get_download_url();
			$fields['debug_download'] = array(
				'title'       => __( 'Download debug log', 'blink-payment-checkout' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: download URL */
					__( 'Download today\'s log file <a href="%1$s">here</a>.', 'blink-payment-checkout' ),
					esc_url( $download_url )
				),
			);
		}

		return $fields;
	}
}
