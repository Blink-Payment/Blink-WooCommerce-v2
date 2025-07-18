<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Payment_Fields_Handler {

	protected $gateway;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function validate_fields() {
		$payment_by = isset( $_POST['payment_by'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_by'] ) ) : '';

		switch ( $payment_by ) {
			case 'direct-debit':
				$this->blink_validate_field( 'given_name', __( 'Given name is required for Direct Debit Payment with Blink', 'blink-payment-checkout' ) );
				$this->blink_validate_field( 'family_name', __( 'Family name is required for Direct Debit Payment with Blink', 'blink-payment-checkout' ) );
				$this->blink_validate_email( 'email' );
				$this->blink_validate_field( 'account_holder_name', __( 'Account holder name is required for Direct Debit Payment with Blink', 'blink-payment-checkout' ) );
				$this->blink_validate_field( 'branch_code', __( 'Branch code is required for Direct Debit Payment with Blink', 'blink-payment-checkout' ) );
				$this->blink_validate_field( 'account_number', __( 'Account number is required for Direct Debit Payment with Blink', 'blink-payment-checkout' ) );
				break;
			case 'open-banking':
				$this->blink_validate_field( 'customer_name', __( 'User name is required for Open Banking Payment with Blink', 'blink-payment-checkout' ) );
				$this->blink_validate_email( 'customer_email' );
				break;
			case 'credit-card':
				$parsed_data = array();
				parse_str( sanitize_text_field( wp_unslash( $_REQUEST['credit-card-data'] ?? '' ) ), $parsed_data );
				$this->blink_validate_field( 'customer_name', __( 'Name on the Card is required for Card Payment with Blink', 'blink-payment-checkout' ), $parsed_data );
				break;
		}

		return true;
	}

	private function blink_validate_field( $field, $error_message, $source = null ) {
		$value = isset( $source[ $field ] ) ? $source[ $field ] : ( isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '' );
		if ( empty( $value ) ) {
			wc_add_notice( $error_message, 'error' );
			return false;
		}
		return true;
	}

	private function blink_validate_email( $field ) {
		$email = isset( $_POST[ $field ] ) ? sanitize_email( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			wc_add_notice( __( 'A valid email is required.', 'blink-payment-checkout' ), 'error' );
			return false;
		}
		return true;
	}

	public function render_payment_fields() {

		if ( $this->gateway->is_hosted() ) {
			echo '<p>' . esc_html( $this->gateway->description ) . '</p>';
			return;
		}

		$request        = $_POST;
		$blink3dprocess = isset( $_GET['blink3dprocess'] ) ? sanitize_text_field( wp_unslash( $_GET['blink3dprocess'] ) ) : '';

		if ( ! empty( $blink3dprocess ) ) {
			return;
		}

		if ( empty( $request['payment_method'] ) || $request['payment_method'] !== $this->gateway->id ) {
			return;
		}

		$order = null;
		if ( ! empty( $request['order'] ) ) {
			$order = wc_get_order( sanitize_text_field( $request['order'] ) );
		}
		$cart_amount = null; 
		if ( WC()->cart && method_exists( WC()->cart, 'get_total' ) ) {
			$cart_amount = WC()->cart->get_total( 'raw' );
		}

		$intent  = $this->gateway->utils->setIntents( $request, $order, $cart_amount );
		$element = ! empty( $intent ) ? $intent['element'] : array();

		$parsed_data = array();
		if ( ! empty( $request['post_data'] ) ) {
			parse_str( sanitize_text_field( $request['post_data'] ), $parsed_data );
		} else {
			$parsed_data = $request;
		}

		$payment_by = '';
		if ( ! empty( $parsed_data['payment_by'] ) ) {
			$candidate = sanitize_text_field( $parsed_data['payment_by'] );
			// Only use if not google-pay or apple-pay
			if ( ! in_array( $candidate, array( 'google-pay', 'apple-pay' ), true ) ) {
				$payment_by = $candidate;
			}
		}

		if ( empty( $payment_by ) ) {
			foreach ( $this->gateway->paymentMethods as $method ) {
				$key = $this->get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					$payment_by = $method;
					break;
				}
			}
		}

		if ( empty( $this->gateway->paymentMethods ) || empty( $payment_by ) ) {
			echo '<p>' . esc_html__( 'Unable to process any payment at this moment!', 'blink-payment-checkout' ) . '</p>';
		} elseif ( $this->gateway->description ) {
			echo '<p>' . esc_html( $this->gateway->description ) . '</p>';
		}

		if ( ! empty( $this->gateway->paymentMethods ) && ! empty( $payment_by ) ) {
			$showGP = true;
			$count  = 0;
			foreach ( $this->gateway->paymentMethods as $method ) {
				$key = $this->get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					++$count;
				}
			}
			$class = $count === 1 ? 'one' : ( $count === 2 ? 'two' : '' );

			?>
			<div class="form-container">
				<?php
				if ( blink_is_safari() ) {
					if ( ! empty( $element['apElement'] ) && ! empty( $this->gateway->apple_pay_enabled ) ) {
						$showGP = false;
						echo wp_kses( $element['apElement'], blink_3d_allow_html() );
					}
				}
				if ( $showGP && ! empty( $element['gpElement'] ) ) {
					echo wp_kses( $element['gpElement'], blink_3d_allow_html() );
				}
				?>
				<div class="batch-upload-wrap pb-3">
					<div class="form-group mb-4">
						<div class="form-group mb-4">
							<div class="select-batch" style="width:100%;">
								<div class="switches-container <?php echo esc_attr( $class ); ?>" id="selectBatch">
									<?php foreach ( $this->gateway->paymentMethods as $method ) : ?>
										<?php
										$key = $this->get_element_key( $method );
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
									<?php foreach ( $this->gateway->paymentMethods as $method ) : ?>
										<?php
										$key = $this->get_element_key( $method );
										if ( ! empty( $element[ $key ] ) ) :
											?>
											<label for="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( blink_transform_word( $method ) ); ?></label>
										<?php endif; ?>
									<?php endforeach; ?>
									<div class="switch-wrapper <?php echo esc_attr( $class ); ?>">
										<div class="switch">
											<?php foreach ( $this->gateway->paymentMethods as $method ) : ?>
												<?php
												$key = $this->get_element_key( $method );
												if ( ! empty( $element[ $key ] ) ) :
													?>
													<div><?php echo esc_html( blink_transform_word( $method ) ); ?></div>
												<?php endif; ?>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php foreach ( $this->gateway->paymentMethods as $method ) : ?>
							<?php
							$key = $this->get_element_key( $method );
							if ( $method === $payment_by && ! empty( $element[ $key ] ) ) {
								if ( 'credit-card' === $payment_by ) {
									echo '<form name="blink-credit" action="" method="">' . wp_kses( $element[ $key ], blink_3d_allow_html() ) . '
                                        <div style="display:none"><input type="submit" name="submit" id="blink-credit-submit" value="check" /></div>
                                        </form>
                                        <input type="hidden" name="credit-card-data" id="credit-card-data" value="" />
                                        ';
								} else {
									echo wp_kses( $element[ $key ], blink_3d_allow_html() );
								}
							}
							?>
						<?php endforeach; ?>
						<input type="hidden" name="payment_by" id="payment_by" value="<?php echo esc_attr( $payment_by ); ?>">
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

	private function get_element_key( $method ) {
		$key = '';
		if ( $method === 'credit-card' ) {
			$key = 'ccElement';
		}
		if ( $method === 'direct-debit' ) {
			$key = 'ddElement';
		}
		if ( $method === 'open-banking' ) {
			$key = 'obElement';
		}

		return $key;
	}
}
