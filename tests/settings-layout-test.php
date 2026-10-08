<?php

define( 'ABSPATH', __DIR__ . '/' );

$blink_test_options = array();

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function wp_kses_post( $text ) {
	return $text;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}

function plugin_dir_url( $file ) {
	return 'https://shop.example/wp-content/plugins/plugin-woocommerce/includes/';
}

function get_option( $key, $default = false ) {
	global $blink_test_options;
	return array_key_exists( $key, $blink_test_options ) ? $blink_test_options[ $key ] : $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args );
}

function disabled( $disabled, $current = true ) {
	if ( $disabled === $current ) {
		echo 'disabled="disabled"';
	}
}

function checked( $checked, $current = true ) {
	if ( $checked === $current ) {
		echo 'checked="checked"';
	}
}

class WC_Payment_Gateway {
	public $id = 'blink';
	public $plugin_id = 'woocommerce_';
	public $settings = array();

	public function get_field_key( $key ) {
		return $this->plugin_id . $this->id . '_' . $key;
	}

	public function get_option( $key ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
	}

	public function get_custom_attribute_html( $data ) {
		$html = '';
		foreach ( $data['custom_attributes'] as $key => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return $html;
	}

	public function get_description_html( $data ) {
		return empty( $data['description'] ) ? '' : '<p class="description">' . $data['description'] . '</p>';
	}

	public function get_tooltip_html( $data ) {
		return '';
	}

	public function generate_checkbox_html( $key, $data ) {
		return 'parent-checkbox';
	}

	// Match WooCommerce's field dispatch so the real grouped HTML transform runs.
	public function generate_settings_html( $fields = array(), $echo = true ) {
		$html = '';
		foreach ( $fields as $key => $data ) {
			$html .= $this->{'generate_' . $data['type'] . '_html'}( $key, $data );
		}
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}

	public function generate_title_html( $key, $data ) {
		return 'parent-title';
	}

	public function generate_select_html( $key, $data ) {
		return '<select' . $this->get_custom_attribute_html( $data ) . '></select><p class="description">' . $data['description'] . '</p>';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-blink-settings-handler.php';
require_once dirname( __DIR__ ) . '/includes/class-blink-payment-gateway.php';

function blink_settings_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function blink_settings_fields( $options = array(), $api_key = 'api', $secret_key = 'secret' ) {
	global $blink_test_options;
	$blink_test_options = $options;
	return ( new Blink_Settings_Handler( $api_key, $secret_key ) )->blink_get_form_fields();
}

function blink_settings_xpath( $html ) {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return new DOMXPath( $document );
}

$tests = array(
	'existing stored setting keys and field types remain compatible' => function () {
		$fields = blink_settings_fields();
		$types  = array(
			'enabled'                    => 'checkbox',
			'title'                      => 'text',
			'description'                => 'textarea',
			'integration_type'           => 'select',
			'testmode'                   => 'checkbox',
			'test_api_key'               => 'text',
			'test_secret_key'            => 'password',
			'api_key'                    => 'text',
			'secret_key'                 => 'password',
			'custom_style'               => 'textarea',
			'card_logo_visa'             => 'checkbox',
			'card_logo_mastercard'       => 'checkbox',
			'card_logo_american_express' => 'checkbox',
			'apple_pay_enabled'          => 'checkbox',
			'preauthorize_payments'      => 'checkbox',
			'debug_mode'                 => 'checkbox',
		);

		foreach ( $types as $key => $type ) {
			blink_settings_assert( isset( $fields[ $key ] ), "Missing existing setting {$key}" );
			blink_settings_assert( $type === $fields[ $key ]['type'], "Changed field type for {$key}" );
		}
		blink_settings_assert( array( 'direct', 'hosted' ) === array_keys( $fields['integration_type']['options'] ), 'Integration values changed' );
	},
	'test and live credentials are separate labelled sections' => function () {
		$fields = blink_settings_fields();
		blink_settings_assert( 'title' === $fields['test_credentials']['type'], 'Test credentials section missing' );
		blink_settings_assert( 'title' === $fields['live_credentials']['type'], 'Live credentials section missing' );
		blink_settings_assert( true === $fields['test_credentials']['blink_subheading'], 'Test credentials should render within API Connection' );
		blink_settings_assert( true === $fields['live_credentials']['blink_subheading'], 'Live credentials should render within API Connection' );
		blink_settings_assert( false !== strpos( $fields['testmode']['description'], 'Live credentials are retained' ), 'Test Mode retention guidance missing' );
	},
	'advanced section uses native disclosure markup' => function () {
		$fields  = blink_settings_fields();
		$gateway = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$html    = $gateway->generate_title_html( 'advanced', $fields['advanced'] );
		blink_settings_assert( true === $fields['advanced']['blink_advanced_start'], 'Advanced disclosure marker missing' );
		blink_settings_assert( false !== strpos( $html, '<details class="blink-settings-advanced">' ), 'Advanced does not use native disclosure markup' );
		blink_settings_assert( false !== strpos( $html, 'Checkout customisation and troubleshooting.' ), 'Advanced purpose is not visible when collapsed' );
	},
	'integration type renders only the selected explanation with both JS fallbacks' => function () {
		$fields            = blink_settings_fields();
		$gateway           = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$gateway->settings = array( 'integration_type' => 'hosted' );
		$html              = $gateway->generate_select_html( 'integration_type', $fields['integration_type'] );
		blink_settings_assert( false !== strpos( $html, 'The customer is redirected to Blink' ), 'Hosted fallback explanation missing' );
		blink_settings_assert( false !== strpos( $html, 'data-direct-description=' ), 'Direct JS explanation missing' );
		blink_settings_assert( false !== strpos( $html, 'data-hosted-description=' ), 'Hosted JS explanation missing' );
		blink_settings_assert( 1 === substr_count( $html, 'Payment fields are embedded directly' ), 'Direct explanation should only exist in its data attribute for Hosted mode' );
	},
	'dynamic payment methods remain token driven' => function () {
		$fields = blink_settings_fields(
			array(
				'blink_admin_token' => array(
					'payment_types' => array( 'credit-card', 'mobile-wallet-custom' ),
				),
			)
		);
		blink_settings_assert( 'checkbox' === $fields['credit-card']['type'], 'Credit card was not generated' );
		blink_settings_assert( 'checkbox' === $fields['mobile-wallet-custom']['type'], 'Unknown Blink payment type was not generated dynamically' );
		blink_settings_assert( 'payment-methods' === $fields['mobile-wallet-custom']['blink_group'], 'Dynamic method was not grouped' );
	},
	'preauthorisation keeps non-card methods disabled' => function () {
		$fields = blink_settings_fields(
			array(
				'blink_admin_token'          => array( 'payment_types' => array( 'credit-card', 'direct-debit', 'open-banking' ) ),
				'woocommerce_blink_settings' => array( 'preauthorize_payments' => 'yes' ),
			)
		);
		blink_settings_assert( array() === $fields['credit-card']['custom_attributes'], 'Credit card should remain enabled' );
		blink_settings_assert( 'disabled' === $fields['direct-debit']['custom_attributes']['disabled'], 'Direct Debit should be disabled' );
		blink_settings_assert( 'disabled' === $fields['open-banking']['custom_attributes']['disabled'], 'Open Banking should be disabled' );
		blink_settings_assert( 'true' === $fields['preauthorize_payments']['custom_attributes']['data-preauth-toggle'], 'Preauth JS hook changed' );
	},
	'apple pay markup keeps enrollment hooks and structured instructions' => function () {
		$fields = blink_settings_fields();
		$html   = $fields['apple_pay_enrollment']['description'];
		blink_settings_assert( false !== strpos( $html, 'id="enable-apple-pay"' ), 'Apple Pay enrollment hook changed' );
		blink_settings_assert( false !== strpos( $html, '<ol>' ), 'Apple Pay instructions are not structured' );
		blink_settings_assert( false === strpos( $html, 'please:<br>' ), 'Legacy line-break instructions remain' );
	},
	'apple pay status retains the existing checkbox id' => function () {
		$gateway           = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$gateway->settings = array( 'apple_pay_enabled' => 'yes' );
		$fields            = blink_settings_fields( array( 'blink_apple_domain_auth' => true ) );
		$html              = $gateway->generate_title_html( 'apple_pay_enrollment', $fields['apple_pay_enrollment'] );
		blink_settings_assert( false !== strpos( $html, 'id="woocommerce_blink_apple_pay_enabled"' ), 'Apple Pay checkbox ID changed' );
		blink_settings_assert( false !== strpos( $html, 'data-blink-apple-pay-status' ), 'Apple Pay visible status missing' );
		blink_settings_assert( false !== strpos( $html, 'checked="checked"' ), 'Configured Apple Pay state did not render' );
		blink_settings_assert( false !== strpos( $html, '<section class="blink-apple-pay-card"' ), 'Apple Pay content is not in one section' );
		blink_settings_assert( false === strpos( $html, '<p><div' ), 'Apple Pay contains an invalid paragraph wrapper' );
	},
	'registered merchants have a visible editable Apple Pay checkbox in both saved states' => function () {
		$gateway = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$fields  = blink_settings_fields( array( 'blink_apple_domain_auth' => true ) );
		foreach ( array( 'yes', 'no' ) as $saved ) {
			$gateway->settings = array( 'apple_pay_enabled' => $saved );
			$html = $gateway->generate_title_html( 'apple_pay_enrollment', $fields['apple_pay_enrollment'] );
			$xpath = blink_settings_xpath( $html );
			$inputs = $xpath->query( '//input[@id="woocommerce_blink_apple_pay_enabled"]' );
			blink_settings_assert( 1 === $inputs->length, 'Apple Pay must have exactly one control' );
			$input = $inputs->item( 0 );
			blink_settings_assert( 'checkbox' === $input->getAttribute( 'type' ) && '1' === $input->getAttribute( 'value' ), 'WooCommerce checkbox submission changed' );
			blink_settings_assert( 'woocommerce_blink_apple_pay_enabled' === $input->getAttribute( 'name' ), 'Apple Pay setting name changed' );
			blink_settings_assert( ! $input->hasAttribute( 'disabled' ), 'Registered merchant cannot edit Apple Pay' );
			blink_settings_assert( ( 'yes' === $saved ) === $input->hasAttribute( 'checked' ), 'Saved Apple Pay preference was not respected' );
			blink_settings_assert( ! $input->hasAttribute( 'tabindex' ), 'Apple Pay must remain keyboard accessible' );
			blink_settings_assert( 0 === $xpath->query( 'ancestor-or-self::*[@hidden or @aria-hidden="true" or @style or contains(@class, "blink-settings-state-control") or contains(@class, "screen-reader-text")]', $input )->length, 'Apple Pay control must be visible and accessible' );
			blink_settings_assert( 1 === $xpath->query( '//label[@for="woocommerce_blink_apple_pay_enabled" and contains(., "Enable Apple Pay at checkout")]' )->length, 'Apple Pay needs a visible accessible label' );
			$status = $xpath->query( '//*[@data-blink-apple-pay-status]' )->item( 0 );
			blink_settings_assert( ( 'yes' === $saved ? 'Enabled' : 'Not enabled' ) === trim( $status->textContent ), 'Status badge does not match the saved preference' );
		}
	},
	'general section uses the first form table without an empty table' => function () {
		$gateway = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$fields  = blink_settings_fields();
		$html    = $gateway->generate_title_html( 'general', $fields['general'] );
		blink_settings_assert( false !== strpos( $html, '<caption class="blink-settings-caption">' ), 'General section is not part of the first table' );
		blink_settings_assert( false === strpos( $html, '</table>' ), 'General renderer closes an empty first table' );
	},
	'unconfigured apple pay renders status and keeps its save control disabled' => function () {
		$fields = blink_settings_fields( array( 'blink_apple_domain_auth' => false ) );
		blink_settings_assert( 'disabled' === $fields['apple_pay_enabled']['custom_attributes']['disabled'], 'Unconfigured Apple Pay control should remain disabled' );
		blink_settings_assert( false === $fields['apple_pay_enabled']['blink_registered'], 'Unconfigured Apple Pay status is incorrect' );
		$gateway = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$xpath = blink_settings_xpath( $gateway->generate_title_html( 'apple_pay_enrollment', $fields['apple_pay_enrollment'] ) );
		blink_settings_assert( 1 === $xpath->query( '//input[@id="woocommerce_blink_apple_pay_enabled" and @disabled]' )->length, 'Unregistered rendered control must be disabled' );
	},
	'complete grouped settings transformation preserves controls and section boundaries' => function () {
		foreach ( array( array(), array( 'credit-card', 'custom-method' ) ) as $methods ) {
			$fields = blink_settings_fields( array(
				'blink_admin_token' => array( 'payment_types' => $methods ),
				'blink_apple_domain_auth' => true,
			) );
			// Include the whole grouped region and its surrounding section boundaries.
			$keys = array_merge( array( 'general', 'pay_methods' ), $methods, array( 'card_logos', 'card_logo_visa', 'card_logo_mastercard', 'card_logo_american_express', 'apple_pay_enrollment', 'apple_pay_enabled', 'payment_options', 'advanced' ) );
			$fields = array_intersect_key( $fields, array_flip( $keys ) );
			$gateway = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
			$gateway->settings = array( 'apple_pay_enabled' => 'no', 'credit-card' => 'yes', 'card_logo_visa' => 'yes' );
			ob_start();
			$html = $gateway->generate_settings_html( $fields, false );
			blink_settings_assert( '' === ob_get_clean(), 'Non-echo rendering unexpectedly printed output' );
			ob_start();
			$echoed = $gateway->generate_settings_html( $fields, true );
			blink_settings_assert( $html === ob_get_clean() && $html === $echoed, 'Echo and return rendering differ' );
			blink_settings_assert( false === strpos( $html, 'class="blink-settings-choice-row"' ), 'Individual choice rows were not transformed' );
			blink_settings_assert( substr_count( '<table>' . $html, '<table' ) === substr_count( $html, '</table>' ), 'Settings tables are unbalanced' );
			$xpath = blink_settings_xpath( '<table>' . $html );
			$groups = $xpath->query( '//tr[@class="blink-settings-choice-group-row"]/td[@colspan="2"]/fieldset[@class="blink-settings-choice-group"]' );
			blink_settings_assert( ( empty( $methods ) ? 1 : 2 ) === $groups->length, 'Payment methods and card logos must form separate groups' );
			foreach ( array_merge( $methods, array( 'card_logo_visa', 'card_logo_mastercard', 'card_logo_american_express', 'apple_pay_enabled' ) ) as $key ) {
				$inputs = $xpath->query( '//input[@name="woocommerce_blink_' . $key . '"]' );
				blink_settings_assert( 1 === $inputs->length, "Lost or duplicated control {$key}" );
				blink_settings_assert( ( 'yes' === $gateway->get_option( $key ) ) === $inputs->item( 0 )->hasAttribute( 'checked' ), "Changed saved value for {$key}" );
			}
			blink_settings_assert( 3 === $xpath->query( './/input', $groups->item( $groups->length - 1 ) )->length, 'Card logo group contains unrelated controls' );
			if ( ! empty( $methods ) ) {
				blink_settings_assert( 2 === $xpath->query( './/input', $groups->item( 0 ) )->length, 'Payment method group contains unrelated controls' );
			}
			blink_settings_assert( 1 === $xpath->query( '//section[@class="blink-apple-pay-card"]//input[@id="woocommerce_blink_apple_pay_enabled" and not(@disabled)]' )->length, 'Apple Pay must remain editable outside the grouped rows' );
			blink_settings_assert( 0 === $xpath->query( '//section[@class="blink-apple-pay-card"]/ancestor::table | //section[@class="blink-apple-pay-card"]/ancestor::section' )->length, 'Apple Pay section is nested in the grouped layout' );
			blink_settings_assert( 1 === $xpath->query( '//details[@class="blink-settings-advanced"]/table' )->length, 'Advanced disclosure lost its settings table' );
		}
	},
	'grouped controls keep individual WooCommerce input names and values' => function () {
		$gateway           = ( new ReflectionClass( 'Blink_Payment_Gateway' ) )->newInstanceWithoutConstructor();
		$gateway->settings = array( 'custom-method' => 'yes' );
		$html              = $gateway->generate_checkbox_html(
			'custom-method',
			array(
				'label'             => 'Custom Method',
				'custom_attributes' => array(),
				'blink_group'       => 'payment-methods',
			)
		);
		blink_settings_assert( false !== strpos( $html, 'name="woocommerce_blink_custom-method"' ), 'Dynamic setting input name changed' );
		blink_settings_assert( false !== strpos( $html, 'value="1"' ), 'WooCommerce checkbox post value changed' );
		blink_settings_assert( false !== strpos( $html, 'checked="checked"' ), 'Stored dynamic setting did not render' );
	},
	'admin scripts retain enrollment and preauthorisation behavior hooks' => function () {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-scripts.js' );
		foreach ( array( '#enable-apple-pay', '#woocommerce_blink_apple_pay_enabled', 'input[data-preauth-toggle="true"]', 'input[name*="credit-card"]', 'input[name*="direct-debit"]', 'input[name*="open-banking"]' ) as $hook ) {
			blink_settings_assert( false !== strpos( $script, $hook ), "Missing admin JS hook {$hook}" );
		}

		$settings_script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-settings.js' );
		blink_settings_assert( false !== strpos( $settings_script, '#woocommerce_blink_testmode' ), 'Test Mode status hook missing' );
		blink_settings_assert( false !== strpos( $settings_script, '#woocommerce_blink_integration_type' ), 'Integration Type description hook missing' );
		blink_settings_assert( false !== strpos( $settings_script, '#woocommerce_blink_apple_pay_enabled' ), 'Apple Pay status hook missing' );
	},
);

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $error ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$name} - {$error->getMessage()}\n" );
	}
}

echo count( $tests ) . ' tests, ' . $failures . " failures\n";
exit( 0 === $failures ? 0 : 1 );
