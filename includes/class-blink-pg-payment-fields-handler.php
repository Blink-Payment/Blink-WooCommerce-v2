<?php
/**
 * Checkout payment fields for the Blink gateway.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renders the Blink payment fields at checkout and validates what the customer submits.
 */
class Blink_PG_Payment_Fields_Handler {

	/**
	 * The gateway these fields belong to.
	 *
	 * @var Blink_PG_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * Set up the fields handler for a gateway.
	 *
	 * @param Blink_PG_Payment_Gateway $gateway The Blink gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Runs from WC_Payment_Gateway::validate_fields() during checkout.
	 *
	 * @return bool
	 */
	public function blink_validate_fields() {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verified the checkout nonce (woocommerce-process_checkout, woocommerce-pay or the Store API nonce) before calling the gateway.
		$payment_by = isset( $_POST['payment_by'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_by'] ) ) : '';

		switch ( $payment_by ) {
			case 'direct-debit':
				$this->blink_validate_field( 'given_name', __( 'Given name is required for Direct Debit Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				$this->blink_validate_field( 'family_name', __( 'Family name is required for Direct Debit Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				$this->blink_validate_email( 'email' );
				$this->blink_validate_field( 'account_holder_name', __( 'Account holder name is required for Direct Debit Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				$this->blink_validate_field( 'branch_code', __( 'Branch code is required for Direct Debit Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				$this->blink_validate_field( 'account_number', __( 'Account number is required for Direct Debit Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				break;
			case 'open-banking':
				$this->blink_validate_field( 'customer_name', __( 'User name is required for Open Banking Payment with Blink', 'blink-payment-gateway-for-woocommerce' ) );
				$this->blink_validate_email( 'customer_email' );
				break;
			case 'credit-card':
				$parsed_data = array();
				parse_str( sanitize_text_field( wp_unslash( $_REQUEST['credit-card-data'] ?? '' ) ), $parsed_data );
				$this->blink_validate_field( 'customer_name', __( 'Name on the Card is required for Card Payment with Blink', 'blink-payment-gateway-for-woocommerce' ), $parsed_data );
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return true;
	}

	/**
	 * Add a checkout error if a required field is empty.
	 *
	 * @param string     $field         Field name.
	 * @param string     $error_message Error to show if the field is empty.
	 * @param array|null $source        Data to read the field from; null to read it from $_POST.
	 * @return bool Whether the field has a value.
	 */
	private function blink_validate_field( $field, $error_message, $source = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from blink_validate_fields(); WooCommerce verified the checkout nonce (woocommerce-process_checkout, woocommerce-pay or the Store API nonce) before calling the gateway.
		$value = isset( $source[ $field ] ) ? $source[ $field ] : ( isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '' );
		if ( empty( $value ) ) {
			wc_add_notice( $error_message, 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Add a checkout error if a posted email field is empty or not a valid email address.
	 *
	 * @param string $field Field name.
	 * @return bool Whether the email is valid.
	 */
	private function blink_validate_email( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from blink_validate_fields(); WooCommerce verified the checkout nonce (woocommerce-process_checkout, woocommerce-pay or the Store API nonce) before calling the gateway.
		$email = isset( $_POST[ $field ] ) ? sanitize_email( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			wc_add_notice( __( 'A valid email is required.', 'blink-payment-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Output the payment method switcher and payment forms for the methods Blink returned, or the description for Hosted.
	 */
	public function blink_render_payment_fields() {

		if ( $this->gateway->blink_is_hosted() ) {
			echo '<p>' . esc_html( $this->gateway->description ) . '</p>';
			return;
		}
		// Payment fields are rendered on the checkout page, in WooCommerce's update_order_review
		// AJAX (nonce checked by WooCommerce), and on pay-for-order. Values are only used to
		// prefill fields and are escaped when output.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above.
		$request     = $_POST;
		$parsed_data = $this->blink_get_parsed_checkout_data( $request );

		// Only render on checkout pages or order pay pages.
		$is_order_pay = is_wc_endpoint_url( 'order-pay' ) || ( ! empty( $request['order'] ) && is_numeric( $request['order'] ) );
		if ( ! is_checkout() && ! $is_order_pay ) {
			return;
		}

		$selected_payment_method = '';
		if ( ! empty( $request['payment_method'] ) ) {
			$selected_payment_method = sanitize_text_field( wp_unslash( $request['payment_method'] ) );
		} elseif ( ! empty( $parsed_data['payment_method'] ) ) {
			$selected_payment_method = sanitize_text_field( $parsed_data['payment_method'] );
		}

		if ( ! empty( $selected_payment_method ) && $selected_payment_method !== $this->gateway->id ) {
			return;
		}

		if ( empty( $selected_payment_method ) && isset( $this->gateway->chosen ) && ! $this->gateway->chosen ) {
			return;
		}

		$order = null;
		if ( ! empty( $request['order'] ) ) {
			$order = wc_get_order( sanitize_text_field( $request['order'] ) );
		}
		$cart_amount = null;
		if ( ! empty( $order ) ) {
			$cart_amount = (float) $order->get_total();
		} elseif ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
			$cart_amount = WC()->cart->get_total( 'raw' );
		}

		if ( null !== $cart_amount && (float) $cart_amount <= 0 ) {
			return;
		}

		$intent_request     = array_merge( $parsed_data, $request );
		$intent             = $this->gateway->utils->blink_set_intents( $intent_request, $order, $cart_amount );
		$element            = ! empty( $intent ) ? $intent['element'] : array();
		$intent_id          = ! empty( $intent ) ? $intent['id'] : '';
		$intent_expiry_date = ! empty( $intent ) ? $intent['expiry_date'] : '';

		$payment_by = '';
		if ( ! empty( $parsed_data['payment_by'] ) ) {
			$candidate = sanitize_text_field( $parsed_data['payment_by'] );
			// Only use if not google-pay or apple-pay.
			if ( ! in_array( $candidate, array( 'google-pay', 'apple-pay' ), true ) && $this->blink_method_has_element( $candidate, $element ) ) {
				$payment_by = $candidate;
			}
		}

		if ( empty( $payment_by ) ) {
			foreach ( $this->gateway->payment_methods as $method ) {
				$key = $this->blink_get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					$payment_by = $method;
					break;
				}
			}
		}

		if ( empty( $this->gateway->payment_methods ) || empty( $payment_by ) ) {
			echo '<p>' . esc_html__( 'Unable to process any payment at this moment!', 'blink-payment-gateway-for-woocommerce' ) . '</p>';
		} elseif ( $this->gateway->description ) {
			echo '<p>' . esc_html( $this->gateway->description ) . '</p>';
		}

		if ( ! empty( $this->gateway->payment_methods ) && ! empty( $payment_by ) ) {
			// Filter payment methods if preauthorization is enabled.
			$available_methods = $this->gateway->payment_methods;
			if ( $this->gateway->preauthorize_payments ) {
				$available_methods = array_filter(
					$this->gateway->payment_methods,
					function ( $method ) {
						return 'credit-card' === $method;
					}
				);

				// Show preauthorization notice to customer.
				echo '<div class="blink-preauth-notice notice notice-info" style="background: #f0f6fc; border: 1px solid #c3d9ff; border-radius: 4px; padding: 15px; margin: 15px 0;">
					<p style="margin: 0; font-weight: 600; color: #0073aa;"><strong>Preauthorization Mode:</strong></p>
					<p style="margin: 5px 0 0 0;">Your payment will be preauthorized at checkout and charged when your order is processed. This ensures your payment method is valid and reserves the funds.</p>
				</div>';
			}

			$wallet_availability = blink_pg_get_wallet_availability( $this->gateway->apple_pay_enabled, $element );
			$count               = 0;
			foreach ( $available_methods as $method ) {
				$key = $this->blink_get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					++$count;
				}
			}
			$class = 1 === $count ? 'one' : ( 2 === $count ? 'two' : '' );

			?>
			<div class="form-container">
				<?php
				if ( $wallet_availability['showWalletRow'] ) {
					echo '<div class="blink-wallet-row" data-blink-wallet-row>';
					if ( $wallet_availability['showApplePay'] ) {
						echo '<div class="blink-wallet" data-blink-wallet="apple">';
						echo wp_kses( $element['apElement'], $this->blink_get_payment_box_allowed_html() );
						echo '</div>';
					}
					if ( $wallet_availability['showGooglePay'] ) {
						echo '<div class="blink-wallet" data-blink-wallet="google">';
						echo wp_kses( $element['gpElement'], $this->blink_get_payment_box_allowed_html() );
						echo '</div>';
					}
					echo '</div>';
				}
				?>
				<div class="batch-upload-wrap pb-3">
					<div class="form-group mb-4">
						<div class="form-group mb-4">
							<div class="select-batch" style="width:100%;">
								<div class="switches-container <?php echo esc_attr( $class ); ?>" id="selectBatch">
									<?php foreach ( $available_methods as $method ) : ?>
										<?php
										$key = $this->blink_get_element_key( $method );
										if ( ! empty( $element[ $key ] ) ) :
											?>
											<input type="radio" id="<?php echo esc_attr( $method ); ?>" name="switchPayment" value="<?php echo esc_attr( $method ); ?>"
												<?php
												if ( $method === $payment_by ) {
													echo 'checked="checked"';}
												?>
											>
										<?php endif; ?>
									<?php endforeach; ?>
									<?php foreach ( $available_methods as $method ) : ?>
										<?php
										$key = $this->blink_get_element_key( $method );
										if ( ! empty( $element[ $key ] ) ) :
											?>
											<label for="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( blink_pg_transform_word( $method ) ); ?></label>
										<?php endif; ?>
									<?php endforeach; ?>
									<div class="switch-wrapper <?php echo esc_attr( $class ); ?>">
										<div class="switch">
											<?php foreach ( $available_methods as $method ) : ?>
												<?php
												$key = $this->blink_get_element_key( $method );
												if ( ! empty( $element[ $key ] ) ) :
													?>
													<div><?php echo esc_html( blink_pg_transform_word( $method ) ); ?></div>
												<?php endif; ?>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php foreach ( $available_methods as $method ) : ?>
							<?php
							$key = $this->blink_get_element_key( $method );
							if ( $method === $payment_by && ! empty( $element[ $key ] ) ) {
								if ( 'credit-card' === $payment_by ) {
									echo '<div class="blink-credit" data-blink-credit="1">' . wp_kses( $element[ $key ], $this->blink_get_payment_box_allowed_html() ) . '
                                        </div>
                                        <input type="hidden" name="credit-card-data" id="credit-card-data" value="" />
                                        ';
								} else {
									echo wp_kses( $element[ $key ], $this->blink_get_payment_box_allowed_html() );
								}
							}
							?>
						<?php endforeach; ?>
						<input type="hidden" name="payment_by" id="payment_by" value="<?php echo esc_attr( $payment_by ); ?>">
						<input type="hidden" name="intent_id" id="intent_id" value="<?php echo esc_attr( $intent_id ); ?>">
						<input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value="<?php echo esc_attr( $intent_expiry_date ); ?>">
					</div>
				</div>
			</div>
			<?php
		} else {
			?>
			<input type="hidden" name="payment_by" value="" />
			<input type="hidden" name="intent_id" id="intent_id" value="<?php echo esc_attr( $intent_id ); ?>">
			<input type="hidden" name="intent_expiry_date" id="intent_expiry_date" value="<?php echo esc_attr( $intent_expiry_date ); ?>">
			<?php
		}
	}

	/**
	 * Get the checkout form data, parsing it from post_data when WooCommerce sends it serialised.
	 *
	 * @param array $request Request data.
	 * @return array
	 */
	private function blink_get_parsed_checkout_data( $request ) {
		$parsed_data = array();
		if ( ! empty( $request['post_data'] ) ) {
			parse_str( sanitize_text_field( wp_unslash( $request['post_data'] ) ), $parsed_data );
			return is_array( $parsed_data ) ? $parsed_data : array();
		}

		return is_array( $request ) ? $request : array();
	}

	/**
	 * Whether a payment method is enabled and the intent has a form element for it.
	 *
	 * @param string $method  Payment method, such as credit-card.
	 * @param array  $element Form elements from the payment intent.
	 * @return bool
	 */
	private function blink_method_has_element( $method, $element ) {
		if ( empty( $method ) || empty( $element ) || ! in_array( $method, $this->gateway->payment_methods, true ) ) {
			return false;
		}

		$key = $this->blink_get_element_key( $method );
		return ! empty( $key ) && ! empty( $element[ $key ] );
	}

	/**
	 * Get the HTML tags and attributes allowed in the payment forms.
	 *
	 * @return array Allowed HTML for wp_kses().
	 */
	private function blink_get_payment_box_allowed_html() {
		$allowed_html = blink_pg_3d_allow_html();
		unset( $allowed_html['form'] );

		if ( isset( $allowed_html['div'] ) ) {
			$allowed_html['div']['data-blink-credit'] = true;
		}

		if ( isset( $allowed_html['input'] ) ) {
			$allowed_html['input']['autocomplete']              = true;
			$allowed_html['input']['disabled']                  = true;
			$allowed_html['input']['readonly']                  = true;
			$allowed_html['input']['required']                  = true;
			$allowed_html['input']['style']                     = true;
			$allowed_html['input']['data-hostedfield']          = true;
			$allowed_html['input']['data-hostedfield-type']     = true;
			$allowed_html['input']['data-hostedfield-tokenise'] = true;
			$allowed_html['input']['data-validation-message']   = true;
		}

		return $allowed_html;
	}

	/**
	 * Get the intent element key for a payment method.
	 *
	 * @param string $method Payment method, such as credit-card.
	 * @return string Element key, or an empty string for an unknown method.
	 */
	private function blink_get_element_key( $method ) {
		$key = '';
		if ( 'credit-card' === $method ) {
			$key = 'ccElement';
		}
		if ( 'direct-debit' === $method ) {
			$key = 'ddElement';
		}
		if ( 'open-banking' === $method ) {
			$key = 'obElement';
		}

		return $key;
	}
}
