<?php
// phpcs:ignoreFile

class WC_Blink_Gateway extends WC_Payment_Gateway {

		public $token;
		public $intent;
		public $paymentMethods = array();
		public $paymentSource;
		public $paymentStatus;

	public function __construct() {

		$this->configs            = include __DIR__ . '/../config.php';
		$this->id                 = str_replace( ' ', '', strtolower( $this->configs['method_title'] ) );
		$this->icon               = plugins_url( '/../assets/img/logo.png', __FILE__ );
		$this->has_fields         = true; // in case you need a custom credit card form
		$this->method_title       = $this->configs['method_title'];
		$this->method_description = $this->configs['method_description'];
		$this->host_url           = $this->configs['host_url'] . '/api';
		$this->version            = $this->configs['version'];
		// gateways can support subscriptions, saved payment methods,
		// but in this tutorial we begin with simple payments and refunds
		$this->supports = array(
			'products',
			'refunds',
		);
		// Load the settings.
		$this->init_settings();
		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->enabled           = $this->get_option( 'enabled' );
		$this->testmode          = 'yes' === $this->get_option( 'testmode' );
		$this->apple_pay_enabled = 'yes' === $this->get_option( 'apple_pay_enabled' );
		$this->api_key           = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
		$this->secret_key        = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );
		$token                   = get_option( 'blink_admin_token' );

		$selectedMethods = array();
		if ( is_array( $token ) && isset( $token['payment_types'] ) ) {
			foreach ( $token['payment_types'] as $type ) {
				$selectedMethods[] = ( 'yes' === $this->get_option( $type ) ) ? $type : '';
			}
		}
		$this->paymentMethods = array_filter( $selectedMethods );
		$this->add_error_notices();

		// Method with all the options fields
		$this->init_form_fields();
		// This action hook saves the settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'blink_process_admin_options' ), 99 );
		// if needed we can use this webhook
		add_action( 'woocommerce_api_wc_blink_gateway', array( $this, 'webhook' ) );
		add_action( 'woocommerce_thankyou_blink', array( $this, 'check_response_for_order' ) );
		add_filter( 'woocommerce_endpoint_order-received_title', array( $this, 'change_title' ), 99 );

		add_filter( 'woocommerce_admin_order_should_render_refunds', array( $this, 'should_render_refunds' ), 10, 3 );
		add_filter( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_cancel_button' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'blink_enqueue_scripts' ), 10 );
		add_action( 'wp_ajax_cancel_transaction', array( $this, 'blink_cancel_transaction' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_hostedfieldcss_to_head' ) );
		add_action( 'admin_footer', array( $this, 'clear_admin_notice' ) );
		add_action( 'woocommerce_before_thankyou', array( $this, 'print_custom_notice' ) );

		// We need custom JavaScript to obtain a token
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	public function blink_process_admin_options() {
		if ( isset( $_POST['woocommerce_blink_testmode'] ) && $_POST['woocommerce_blink_testmode'] == '1' ) {
			$this->api_key    = $_POST['woocommerce_blink_test_api_key'];
			$this->secret_key = $_POST['woocommerce_blink_test_secret_key'];
		} else {
			$this->api_key    = $_POST['woocommerce_blink_api_key'];
			$this->secret_key = $_POST['woocommerce_blink_secret_key'];
		}
		$token = $this->generate_access_token();
		update_option( 'blink_admin_token', $token );
		$this->destroy_session_tokens();
	}

	public function process_refund( $order_id, $amount = null, $reason = '__' ) {
		$order = wc_get_order( $order_id );

		// Get the transaction ID from order meta
		$transaction_id = $order->get_meta( 'blink_res' );

		// Exit if transaction ID is not found
		if ( ! $transaction_id ) {
			$order->add_order_note( 'Transaction ID not found.' );
			return new WP_Error( 'invalid_order', __( 'Transaction ID not found.', 'woocommerce' ) );
		}

		// Check if there were previous partial refunds
		$previous_refund_amount = isset( $_POST['refunded_amount'] ) ? wc_format_decimal( $_POST['refunded_amount'] ) : 0;

		// Determine if it's a partial refund
		$partial_refund = ! empty( $previous_refund_amount ) ? true : ( $amount < $order->get_total() );

		// Prepare refund request data
		$request_data = array(
			'partial_refund' => $partial_refund,
			'amount'         => $amount,
			'reference'      => $reason,
		);

		$this->token = $this->generate_access_token();
		if ( empty( $this->token ) ) {
			$order->add_order_note( 'Refund request failed: check payment settings' );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'woocommerce' ) );
		}
		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		// Send refund request
		$url      = $this->host_url . '/pay/v1/transactions/' . $transaction_id . '/refunds';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $request_data,
			)
		);

		// Check if the refund request was successful
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( 'Refund request failed: ' . $response->get_error_message() );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'woocommerce' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Add refund notes to the order
		if ( $data['success'] ) {
			$refund_note = $data['message'] . ' (Transaction ID: ' . $data['transaction_id'] . ')';
			$order->add_order_note( $refund_note );
			$refund_type = $partial_refund ? 'Partial' : 'Full';
			$order->add_order_note( $refund_type . ' refund of ' . wc_price( $amount ) . ' processed successfully. Reason: ' . $reason );
			if ( ( $amount + $previous_refund_amount ) >= $order->get_total() ) {
				$order->update_status( 'refunded' );
			}
		} else {
			$message = $data['error'] ? $data['error'] : $data['message'];
			$order->add_order_note( 'Refund request failed: ' . $message );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.' . $message, 'woocommerce' ) );
		}

		// Return true on successful refund
		return true;
	}
	public function cancel_transaction( $transaction_id ) {
		$url = $this->host_url . '/pay/v1/transactions/' . $transaction_id . '/cancels';

		$this->token = $this->generate_access_token();
		if ( empty( $this->token ) ) {
			return array( 'message' => 'Error creating access token' );
		}
		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		$response = wp_remote_post( $url, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
				wc_add_notice( 'Error fetching transaction status: ' . $response->get_error_message(), 'error' );
				return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return $data;
	}
	public function blink_enqueue_scripts( $hook ) {
			wp_enqueue_script( 'woocommerce_blink_payment_admin_scripts', plugins_url( '/../assets/js/admin-scripts.js', __FILE__ ), array( 'jquery' ), $this->version, true );
			wp_enqueue_style( 'woocommerce_blink_payment_admin_css', plugins_url( '/../assets/css/admin.css', __FILE__ ), array(), $this->version );

			wp_localize_script(
				'woocommerce_blink_payment_admin_scripts',
				'blinkOrders',
				array(
					'ajaxurl'        => admin_url( 'admin-ajax.php' ),
					'cancel_order'   => wp_create_nonce( 'cancel_order_nonce' ),
					'spin_gif'       => plugins_url( '/../assets/img/wpspin.gif', __FILE__ ),
					'apihost'        => $this->host_url,
					'security'       => wp_create_nonce( 'generate_access_token_nonce' ),
					'apple_security' => wp_create_nonce( 'generate_applepay_domains_nonce' ),
				)
			);
	}
	public function add_cancel_button( $order ) {

		$transaction_id = $order->get_meta( 'blink_res' );

		if ( ! $transaction_id ) {
			return; // Exit if transaction ID is not found
		}

		if ( checkCCPayment( $this->paymentSource ) ) {

			if ( strtolower( $this->paymentStatus ) === 'captured' && get_time_diff( $order ) !== true ) {
				// If status is captured, display cancel button

				echo '<div class="cancel-order-container">';
				echo '<button type="button" class="button cancel-order" data-order-id="' . $order->get_id() . '">' . __( 'Cancel Order', 'woocommerce' ) . '</button>';
				echo '<span class="cancel-order-tooltip" data-tip="' . __( 'It will cancel the transaction.', 'woocommerce' ) . '">' . __( 'It will cancel the transaction.', 'woocommerce' ) . '</span>';
				echo '</div>';

			}
		}

		return;
	}
	// New function to fetch transaction status
	private function get_transaction_status( $transaction_id, $order = null ) {
		$url         = $this->host_url . '/pay/v1/transactions/' . $transaction_id;
		$data        = array();
		$this->token = $this->generate_access_token();
		if ( $this->token ) {
			// Prepare request headers
			$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

			$response = wp_remote_get( $url, array( 'headers' => $headers ) );

			if ( is_wp_error( $response ) ) {
					wc_add_notice( 'Error fetching transaction status: ' . $response->get_error_message(), 'error' );
					return;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ) );
		}

		$this->paymentSource = ! empty( $data->data->payment_source ) ? $data->data->payment_source : '';
		$this->paymentStatus = ! empty( $data->data->status ) ? $data->data->status : '';
	}

	public function should_render_refunds( $render_refunds, $order, $wc_order ) {
		$transaction_id = get_post_meta( $order, 'blink_res', true );
		$WCOrder        = wc_get_order( $order );

		if ( ! $transaction_id ) {
			return $render_refunds; // No Blink transaction, use default behavior
		}

		$this->transactionID = $transaction_id;

		$this->get_transaction_status( $transaction_id );

		if ( checkCCPayment( $this->paymentSource ) ) {
			if ( strtolower( $this->paymentStatus ) === 'captured' ) {
				$render_refunds = false; // Hide default refund if captured

			}
			if ( get_time_diff( $WCOrder ) === true ) {
				$render_refunds = true;
			}
			if ( empty( $this->paymentStatus ) ) {
				$render_refunds = false;
			}
		}

		if ( $WCOrder->has_status( array( 'cancelled' ) ) ) {
			$render_refunds = false;
		}

		return $render_refunds;
	}

	public function clear_admin_notice() {
		$adminnotice = new WC_Admin_Notices();
		$adminnotice->remove_notice( 'blink-error' );
		$adminnotice->remove_notice( 'no-api' );
		$adminnotice->remove_notice( 'no-payment-type-selected' );
		$adminnotice->remove_notice( 'no-payment-types' );
	}

	public function add_error_notices( $payment_types = array() ) {

		if ( is_in_admin_section() ) {

			$adminnotice = new WC_Admin_Notices();
			$token       = get_option( 'blink_admin_token' );

			if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
				$live = $this->testmode ? 'Test' : 'Live';
				if ( ! $adminnotice->has_notice( 'no-api' ) ) {
					$adminnotice->add_custom_notice( 'no-api', '<div>Please add ' . $live . ' API key and Secret Key</div>' );
				}
			} else {
				$adminnotice->remove_notice( 'no-api' );
				if ( ! empty( $token['payment_types'] ) ) {
					if ( empty( $this->paymentMethods ) ) {
						if ( ! $adminnotice->has_notice( 'no-payment-type-selected' ) ) {
							$adminnotice->add_custom_notice( 'no-payment-type-selected', '<div>Please select the Payment Methods and save the configuration!</div>' );
						}
					} else {
						$adminnotice->remove_notice( 'no-payment-type-selected' );
					}
					$adminnotice->remove_notice( 'no-payment-types' );
				} elseif ( ! $adminnotice->has_notice( 'no-payment-types' ) ) {
					$adminnotice->add_custom_notice( 'no-payment-types', '<div>There is no Payment Types Available.</div>' );
				}
			}
		}
	}

	public function generate_access_token() {

		$url          = $this->host_url . '/pay/v1/tokens';
		$request_data = array(
			'api_key'                 => $this->api_key,
			'secret_key'              => $this->secret_key,
			'source_site'             => get_bloginfo( 'name' ),
			'application_name'        => 'Woocommerce Blink ' . $this->version,
			'application_description' => 'WP-' . get_bloginfo( 'version' ) . ' WC-' . WC_VERSION,
		);
		$response     = wp_remote_post(
			$url,
			array(
				'method' => 'POST',
				'body'   => $request_data,
			)
		);

		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {

			$retry_after = $headers['retry-after'] + 2;
			sleep( $retry_after );
			$response = wp_remote_post(
				$url,
				array(
					'method' => 'POST',
					'body'   => $request_data,
				)
			);
		}

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 == wp_remote_retrieve_response_code( $response ) ) {

			return $api_body;
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
			blink_add_notice( $error );
		}

		return array();
	}
	/**
	 * Get the return url (thank you page).
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function get_return_url( $order = null ) {
		if ( $order ) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
		}
		return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
	}
	public function create_payment_intent( $method = 'credit-card', $order = null ) {

		if ( $this->token ) {

			$cart_amount = WC()->cart->get_total( 'raw' );
			$cart_amount = ! empty( $cart_amount ) ? $cart_amount : '1.0';

			$amount       = ! empty( $order ) ? $order->get_total() : $cart_amount;
			$request_data = array(
				'card_layout'      => 'single-line',
				'amount'           => $amount,
				'payment_type'     => $method,
				'currency'         => get_woocommerce_currency(),
				'return_url'       => $this->get_return_url( $order ),
				'notification_url' => WC()->api_request_url( 'wc_blink_gateway' ),
			);
			$url          = $this->host_url . '/pay/v1/intents';
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
					'body'    => $request_data,
				)
			);

			$headers = wp_remote_retrieve_headers( $response );
			if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {
				$retry_after = $headers['retry-after'] + 2;
				sleep( $retry_after );
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'POST',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);
			}

			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 201 == wp_remote_retrieve_response_code( $response ) ) {
				return $api_body;
			} else {

				$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
			}
		}

		return array();
	}

	public function update_payment_intent( $method = 'credit-card', $order = null, $id = null ) {

		if ( $this->token ) {

			$request_data = array(
				'payment_type' => $method,
				'amount'       => $order->get_total(),
				'return_url'   => $this->get_return_url( $order ),
			);
			if ( $id ) {
				$url      = $this->host_url . '/pay/v1/intents/' . $id;
				$response = wp_remote_post(
					$url,
					array(
						'method'  => 'PATCH',
						'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
						'body'    => $request_data,
					)
				);

				$headers = wp_remote_retrieve_headers( $response );
				if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {

					$retry_after = $headers['retry-after'] + 2;
					sleep( $retry_after );
					$response = wp_remote_post(
						$url,
						array(
							'method'  => 'PATCH',
							'headers' => array( 'Authorization' => 'Bearer ' . $this->token['access_token'] ),
							'body'    => $request_data,
						)
					);
				}

				$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( 200 == wp_remote_retrieve_response_code( $response ) ) {

					return $api_body;
				} else {
					$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];

					return $this->create_payment_intent( $method, $order );
				}
			}
		}

		return array();
	}
	/**
	 * Plugin options,
	 */
	public function init_form_fields( $payment_types = array() ) {
		// call in front end
		if ( ! is_in_admin_section() ) {
			return;
		}

		$fields = array(
			'enabled'         => array(
				'title'       => 'Enable/Disable',
				'label'       => 'Enable Blink Gateway',
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'           => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default'     => 'Blink v2',
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'This controls the description which the user sees during checkout.',
				'default'     => 'Pay with your credit card or direct debit at your convenience.',
			),
			'testmode'        => array(
				'title'       => 'Test mode',
				'label'       => 'Enable Test Mode',
				'type'        => 'checkbox',
				'description' => 'Place the payment gateway in test mode using test API keys.',
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key'    => array(
				'title' => 'Test API Key',
				'type'  => 'text',
			),
			'test_secret_key' => array(
				'title' => 'Test Secret Key',
				'type'  => 'password',
			),
			'api_key'         => array(
				'title' => 'Live API Key',
				'type'  => 'text',
			),
			'secret_key'      => array(
				'title' => 'Live Secret Key',
				'type'  => 'password',
			),
			'custom_style'    => array(
				'title'       => 'Custom Style',
				'type'        => 'textarea',
				'description' => 'Do not include style tag',
			),
		);
		if ( $this->api_key && $this->secret_key ) {

			$token = get_option( 'blink_admin_token' );

			if ( ! empty( $token ) && ! empty( $token['payment_types'] ) ) {
				$pay_methods['pay_methods'] = array(
					'title'       => 'Payment Methods',
					'label'       => '',
					'type'        => 'hidden',
					'description' => '',
					'default'     => '',
				);
				$fields                     = insertArrayAtPosition( $fields, $pay_methods, count( $fields ) - 1 );
				foreach ( $token['payment_types'] as $types ) {
					$payment[ $types ] = array(
						'title'       => '',
						'label'       => ucwords( str_replace( '-', ' ', $types ) ),
						'type'        => 'checkbox',
						'description' => '',
						'default'     => 'no',
					);
					$fields            = insertArrayAtPosition( $fields, $payment, count( $fields ) - 1 );
				}
			}
		}

		$new_settings['apple_pay_enrollment'] = array(
			'title'       => __( 'Apple Pay Enrollment', 'woocommerce' ),
			'type'        => 'title',
			'description' => __(
				'To enable Apple Pay please:<br>
						 Download the domain verification file (DVF) <a href="' . plugin_dir_url( __FILE__ ) . 'download-apple-pay-dvf.php" target="_blank">here</a>.<br>
						 Upload it to your domain as follows: "https://' . $_SERVER['SERVER_NAME'] . '/.well-known/apple-developer-merchantid-domain-association".
						 <button id="enable-apple-pay" class="button">Click here to enable</button>',
				'woocommerce'
			),
			'id'          => 'apple_pay_enrollment',
		);

		$apple_domain_auth = ! empty( get_option( 'apple_domain_auth' ) );
		$disabled          = array(
			'disabled' => 'disabled',  // Disable the checkbox by default
		);

		$new_settings['apple_pay_enabled'] = array(
			'title'             => __( 'Apple Pay Enabled', 'woocommerce' ),
			'type'              => 'checkbox',
			'description'       => __( 'Enable this option once Apple Pay is successfully registered.', 'woocommerce' ),
			'id'                => 'woocommerce_apple_pay_enabled',
			'default'           => 'yes',
			'custom_attributes' => $apple_domain_auth ? '' : $disabled,
		);

		$this->form_fields = array_merge( $fields, $new_settings );
	}
	/**
	 * Credit card form
	 */
	public function payment_fields() {

		$request        = $_POST;
		$blink3dprocess = isset( $_GET['blink3dprocess'] ) ? sanitize_text_field( $_GET['blink3dprocess'] ) : '';

		if ( ! empty( $blink3dprocess ) ) {
			return;
		}

		if ( ( empty( $request['payment_method'] ) || $request['payment_method'] != $this->id ) ) {
			return;
		}

		$token   = $this->setTokens();
		$intent  = $this->setIntents( $request );
		$element = ! empty( $intent ) ? $intent['element'] : array();

		$parsed_data = array();
		if ( ! empty( $request['post_data'] ) ) {
			parse_str( $request['post_data'], $parsed_data );
		} else {
			$parsed_data = $request;
		}

		$payment_by = ! empty( $parsed_data['payment_by'] ) ? $parsed_data['payment_by'] : '';
		if ( empty( $payment_by ) ) {
			foreach ( $this->paymentMethods as $method ) {
				$key = get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					$payment_by = $method;
					break;
				}
			}
		}

		if ( empty( $this->paymentMethods ) || empty( $payment_by ) ) {
			echo '<p> Unable to process any payment at this moment! </p>';
		} elseif ( $this->description ) {
				echo '<p>' . esc_html( $this->description ) . '</p>';
		}

		if ( ! empty( $this->paymentMethods ) && ! empty( $payment_by ) ) {
				$showGP = true;

				$count = 0;
			foreach ( $this->paymentMethods as $method ) {
				$key = get_element_key( $method );
				if ( ! empty( $element[ $key ] ) ) {
					++$count;
				}
			}
				$class = $count == 1 ? 'one' : ( $count == 2 ? 'two' : '' );

			?>
			
			<div class="form-container">
				<?php
				if ( isSafari() ) {
					if ( ! empty( $element['apElement'] ) && ! empty( $this->apple_pay_enabled ) ) {
						$showGP = false;
						echo $element['apElement'];
					}
				}
				if ( $showGP && ! empty( $element['gpElement'] ) ) {
					echo $element['gpElement'];
				}
				?>
				<div class="batch-upload-wrap pb-3">
						
						<div class="form-group mb-4">
							<div class="form-group mb-4">
								<div class="select-batch" style="width:100%;">
									<div class="switches-container <?php echo $class; ?>" id="selectBatch">
										<?php foreach ( $this->paymentMethods as $method ) : ?>
											<?php
											$key = get_element_key( $method );
											if ( ! empty( $element[ $key ] ) ) :
												?>
												
											<input type="radio" id="<?php echo $method; ?>" name="switchPayment" value="<?php echo $method; ?>" 
																				<?php
																				if ( $method == $payment_by ) {
																					echo 'checked="checked"';}
																				?>
												>
											<?php endif; ?>

											<?php
										endforeach;
										foreach ( $this->paymentMethods as $method ) :
											?>

											<?php
											$key = get_element_key( $method );
											if ( ! empty( $element[ $key ] ) ) :
												?>
												
											<label for="<?php echo $method; ?>"><?php echo transformWord( $method ); ?></label>

											<?php endif; ?>
						
										<?php endforeach; ?>
										<div class="switch-wrapper <?php echo $class; ?>">
											<div class="switch">
											<?php foreach ( $this->paymentMethods as $method ) : ?>
												<?php
													$key = get_element_key( $method );
												if ( ! empty( $element[ $key ] ) ) :
													?>
												
													<div><?php echo transformWord( $method ); ?></div>
												<?php endif; ?>

											<?php endforeach; ?>
											</div>
										</div>
									</div>
								</div>
							</div>
							<?php foreach ( $this->paymentMethods as $method ) : ?>

										
								<?php

									$key = get_element_key( $method );

								if ( $method == $payment_by && ! empty( $element[ $key ] ) ) {
									if ( 'credit-card' == $payment_by ) {

										echo '<form name="blink-credit" action="" method="">' . $element[ $key ] . '
											<div style="display:none"><input type="submit" name="submit" id="blink-credit-submit" value="check" /></div>
											</form>
											<input type="hidden" name="credit-card-data" id="credit-card-data" value="" />
											';
									} else {
										echo $element[ $key ];
									}
								}

								?>

								<?php endforeach; ?>		
								<input type="hidden" name="payment_by" id="payment_by" value="<?php echo $payment_by; ?>">

							<!--  -->
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

	public function add_hostedfieldcss_to_head() {
		echo '<link rel="stylesheet" href="' . plugins_url( '/../assets/css/hostedfields.css', __FILE__ ) . '" class="hostedfield">';
	}

	public function payment_scripts() {
		// we need JavaScript to process a token only on cart/checkout pages, right?
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}
		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ( 'no' === $this->enabled ) {
			return;
		}
		// no reason to enqueue JavaScript if API keys are not set
		if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
			return;
		}
		// do not work with card detailes without SSL unless your website is in a test mode
		if ( ! $this->testmode && ! is_ssl() ) {
			return;
		}
		wp_enqueue_script( 'blink_l', 'https://code.jquery.com/jquery-3.6.3.min.js', array(), $this->version );
		// let's suppose it is our payment processor JavaScript that allows to obtain a token
		wp_enqueue_script( 'blink_hosted_js', 'https://gateway2.blinkpayment.co.uk/sdk/web/v1/js/hostedfields.min.js', array( 'jquery' ), $this->version, false );
		wp_register_style( 'woocommerce_blink_payment_style', plugins_url( '../assets/css/style.css', __FILE__ ), array(), $this->version );
		// and this is our custom JS in your plugin directory that works with token.js
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			wp_register_script( 'woocommerce_blink_payment', plugins_url( '../assets/js/order-pay.js', __FILE__ ), array( 'jquery' ), rand(), false );      } else {
			wp_register_script( 'woocommerce_blink_payment', plugins_url( '../assets/js/checkout.js', __FILE__ ), array( 'jquery' ), rand(), false );       }
			// in most payment processors you have to use API KEY and SECRET KEY to obtain a token
			wp_localize_script(
				'woocommerce_blink_payment',
				'blink_params',
				array(
					'ajaxurl'       => admin_url( 'admin-ajax.php' ),
					'remoteAddress' => ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '',
				)
			);
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( get_query_var( 'order-pay' ) );

			wp_localize_script(
				'woocommerce_blink_payment',
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
				)
			);
		}
		wp_enqueue_script( 'woocommerce_blink_payment' );
		wp_enqueue_style( 'woocommerce_blink_payment_style' );
		$custom_css = $this->get_option( 'custom_style' );
		if ( $custom_css ) {
			wp_add_inline_style( 'woocommerce_blink_payment_style', $custom_css );
		}
		do_action( 'wc_blink_custom_script' );
		do_action( 'wc_blink_custom_style' );
	}

	public function validate_fields() {

		if ( 'direct-debit' == $_POST['payment_by'] ) {
			if ( empty( $_POST['given_name'] ) ) {
				wc_add_notice( 'Given name is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['family_name'] ) ) {
				wc_add_notice( 'Family name is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['email'] ) ) {
				wc_add_notice( 'Email is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['account_holder_name'] ) ) {
				wc_add_notice( 'Account holder name is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['branch_code'] ) ) {
				wc_add_notice( 'Branch code is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['account_number'] ) ) {
				wc_add_notice( 'Account number is required! for Direct Debit Payment with Blink', 'error' );
				return false;
			}
		}
		if ( 'open-banking' == $_POST['payment_by'] ) {
			if ( empty( $_POST['customer_name'] ) ) {
				wc_add_notice( 'User name is required! for Open Banking Payment with Blink', 'error' );
				return false;
			}
			if ( empty( $_POST['customer_email'] ) ) {
				wc_add_notice( 'User Email is required! for Open Banking Payment with Blink', 'error' );
				return false;
			}
		}
		if ( 'credit-card' == $_POST['payment_by'] ) {
			$request = $_POST;
			parse_str( $_REQUEST['credit-card-data'], $parsed_data );
			$data = array_merge( $request, $parsed_data );
			if ( empty( $data['customer_name'] ) ) {
				wc_add_notice( 'Name on the Card is required! for Card Payment with Blink', 'error' );
				return false;
			}
		}
		return true;
	}
	public function process_open_banking( $order, $request ) {

		$order_id   = $order->get_id();
		$return_arr = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {
			$request_data = array(
				'merchant_id'       => $this->intent['merchant_id'],
				'payment_intent'    => $this->intent['payment_intent'],
				'user_name'         => ! empty( $request['customer_name'] ) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'user_email'        => ! empty( $request['customer_email'] ) ? $request['customer_email'] : $order->get_billing_email(),
				'customer_address'  => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode' => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'merchant_data'     => get_payment_information( $order_id ),
			);
			$url          = $this->host_url . '/pay/v1/openbankings';
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( $_SERVER['HTTP_ACCEPT'] ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			$api_body     = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;
				if ( $api_body['url'] ) {
					$return_arr['redirect_url'] = $api_body['url']; // redirect_url
				} elseif ( $api_body['redirect_url'] ) {
					$return_arr['redirect_url'] = $api_body['redirect_url']; // redirect_url
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}
	public function process_direct_debit( $order, $request ) {

		$order_id   = $order->get_id();
		$return_arr = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {

			$request_data = array(
				'payment_intent'      => $this->intent['payment_intent'],
				'given_name'          => ! empty( $request['given_name'] ) ? $request['given_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'family_name'         => $request['family_name'],
				'company_name'        => $request['company_name'],
				'email'               => ! empty( $request['email'] ) ? $request['email'] : $order->get_billing_email(),
				'country_code'        => get_woocommerce_currency(),
				'account_holder_name' => $request['account_holder_name'],
				'branch_code'         => $request['branch_code'],
				'account_number'      => $request['account_number'],
				'customer_address'    => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode'   => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'merchant_data'       => get_payment_information( $order_id ),
			);
			$url          = $this->host_url . '/pay/v1/directdebits';
			$response     = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( $_SERVER['HTTP_ACCEPT'] ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			$api_body     = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;

				if ( $api_body['url'] ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}

	public function process_credit_card( $order, $request, $endpoint = 'creditcards' ) {

		$cart_amount = WC()->cart->get_total( 'raw' );
		$cart_amount = ! empty( $cart_amount ) ? $cart_amount : '1.0';
		$amount      = ! empty( $order ) ? $order->get_total() : $cart_amount;
		$return_arr  = array(
			'success'      => false,
			'redirect_url' => false,
			'error'        => false,
		);

		$order_id = $order->get_id();
		if ( ! empty( $this->token['access_token'] ) && ! empty( $this->intent['payment_intent'] ) ) {

			$request_data = array(
				'resource'           => $request['resource'],
				'payment_intent'     => $this->intent['payment_intent'],
				'paymentToken'       => $request['paymentToken'],
				'type'               => $request['type'],
				'raw_amount'         => $amount,
				'customer_email'     => ! empty( $request['customer_email'] ) ? $request['customer_email'] : $order->get_billing_email(),
				'customer_name'      => ! empty( $request['customer_name'] ) ? $request['customer_name'] : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'customer_address'   => ! empty( $request['customer_address'] ) ? $request['customer_address'] : $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(),
				'customer_postcode'  => ! empty( $request['customer_postcode'] ) ? $request['customer_postcode'] : $order->get_billing_postcode(),
				'transaction_unique' => 'WC-' . $request['transaction_unique'],
				'merchant_data'      => get_payment_information( $order_id ),
			);
			if ( isset( $request['remote_address'] ) ) {
				$request_data['device_timezone']          = $request['device_timezone'];
				$request_data['device_capabilities']      = $request['device_capabilities'];
				$request_data['device_accept_language']   = $request['device_accept_language'];
				$request_data['device_screen_resolution'] = $request['device_screen_resolution'];
				$request_data['remote_address']           = $request['remote_address'];
			}

			$url = $this->host_url . '/pay/v1/' . $endpoint;

			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->token['access_token'],
						'user-agent'      => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
						'accept'          => ! empty( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( $_SERVER['HTTP_ACCEPT'] ) : '',
						'accept-encoding' => 'gzip, deflate, br',
						'accept-charset'  => 'charset=utf-8',
					),
					'body'    => $request_data,
				)
			);
			$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
				$return_arr['success'] = true;

				if ( isset( $api_body['acsform'] ) ) {
					$threedToken = $api_body['acsform'];
					set_transient( 'blink3dProcess' . $order_id, $threedToken, 300 );
					if ( is_wc_endpoint_url( 'order-pay' ) ) {
						$return_arr['redirect_url'] = add_query_arg( 'blink3dprocess', $order_id, $order->get_checkout_payment_url() );
					} else {
						$return_arr['redirect_url'] = add_query_arg( 'blink3dprocess', $order_id, wc_get_checkout_url() );
					}
				} elseif ( isset( $api_body['url'] ) ) {
					$return_arr['redirect_url'] = $api_body['url'];
				}
			} else {
				$error                 = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
				$return_arr['success'] = false;
				$return_arr['error']   = $error;
			}
		}

		return $return_arr;
	}

	public function setTokens() {

		$this->token = get_transient( 'blink_token' );
		$expired     = 0;
		if ( ! empty( $this->token ) ) {
			$expired = checkTimestampExpired( $this->token['expired_on'] );
		}
		if ( empty( $expired ) ) {
			$this->token = $this->generate_access_token();
		}
		set_transient( 'blink_token', $this->token, 15 * MINUTE_IN_SECONDS );

		return $this->token;
	}

	public function setIntents( $request = '', $order = null ) {

		if ( empty( $request['payment_by'] ) || $request['payment_by'] == 'google-pay' || $request['payment_by'] == 'apple-pay' ) {
			$request['payment_by'] = 'credit-card';
		}

		$this->intent   = get_transient( 'blink_intent' );
		$intent_expired = 0;
		if ( ! empty( $this->intent ) ) {
			$this->intent['expiry_date'];
			$intent_expired = checkTimestampExpired( $this->intent['expiry_date'] );

		}
		if ( empty( $intent_expired ) ) {
			$this->intent = $this->create_payment_intent( $request['payment_by'], $order );
		} elseif ( ! empty( $order ) ) {
				$this->intent = $this->update_payment_intent( $request['payment_by'], $order, $this->intent['id'] );
		}
		set_transient( 'blink_intent', $this->intent, 15 * MINUTE_IN_SECONDS );

		return $this->intent;
	}

	public function destroy_session_tokens() {
		delete_transient( 'blink_token' );
		delete_transient( 'blink_intent' );
	}
	/*
	 * We're processing the payments here
	*/
	public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			$request = wp_unslash( $_POST );

			$token  = $this->setTokens();
			$intent = $this->setIntents( $request, $order );

		if ( empty( $intent ) || empty( $token ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) ) {
				return;
			}
			return error_payment_process();
		}

			$response = array();

		if ( $request['payment_by'] == 'credit-card' ) {
			parse_str( $_REQUEST['credit-card-data'], $parsed_data );
			$response = $this->process_credit_card( $order, array_merge( $request, $parsed_data ) );

		}
		if ( $request['payment_by'] == 'google-pay' ) {
			$response = $this->process_credit_card( $order, $request, 'googlepay' );

		}
		if ( $request['payment_by'] == 'apple-pay' ) {
			$response = $this->process_credit_card( $order, $request, 'applepay' );

		}
		if ( $request['payment_by'] == 'direct-debit' ) {
			$response = $this->process_direct_debit( $order, $request );

		}
		if ( $request['payment_by'] == 'open-banking' ) {
			$response = $this->process_open_banking( $order, $request );

		}

		if ( ! $response['success'] ) {
			$this->destroy_session_tokens();
			return error_payment_process( $response['error'] );
		}

		return array(
			'result'   => 'success',
			'redirect' => $response['redirect_url'],
		);
	}
	/*
	 * In case we need a webhook, like PayPal IPN etc
	*/
	public function webhook() {
		global $wpdb;
		$order_id = '';
		$request  = isset( $_REQUEST['transaction_id'] ) ? $_REQUEST : file_get_contents( 'php://input' );
		if ( is_array( $request ) ) {
			$data                     = isset( $request['merchant_data'] ) ? stripslashes( $request['merchant_data'] ) : '';
			$request['merchant_data'] = json_decode( $data, true );
		} else {
			$request = json_decode( $request, true );
		}
		$transaction_id = ! empty( $request['transaction_id'] ) ? sanitize_text_field( $request['transaction_id'] ) : '';
		if ( $transaction_id ) {
			$marchant_data = $request['merchant_data'];
			if ( ! empty( $marchant_data ) ) {
				$order_id = ! empty( $marchant_data['order_info']['order_id'] ) ? sanitize_text_field( $marchant_data['order_info']['order_id'] ) : '';
			}
			if ( ! $order_id ) {
				$order_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT `post_id`
						FROM {$wpdb->postmeta}
						WHERE (`meta_key` = %s AND `meta_value` = %s) OR (`meta_key` = %s AND `meta_value` = %s)",
						array( '_transaction_id', $transaction_id, 'blink_res', $transaction_id )
					)
				);
			}
			$status = ! empty( $request['status'] ) ? $request['status'] : '';
			$note   = ! empty( $request['note'] ) ? $request['note'] : '';
			$order  = wc_get_order( $order_id );
			if ( $order ) {
				change_status( $order, $transaction_id, $status, '', $note );
				$order->update_meta_data( '_debug', $request );
				$response = array(
					'order_id'     => $order_id,
					'order_status' => $status,
				);
				echo json_encode( $response );
				exit();
			}
		}
		$response = array(
			'transaction_id' => ! empty( $transaction_id ) ? $transaction_id : null,
			'error'          => 'No order found with this transaction ID',
		);
		echo json_encode( $response );
		exit();
	}
	public function validate_transaction( $order, $transaction ) {

		$token        = $this->generate_access_token();
		$responseCode = ! empty( $transaction ) ? $transaction : '';
		$url          = $this->host_url . '/pay/v1/transactions/' . $responseCode;
		$response     = wp_remote_get(
			$url,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
			)
		);
		$redirect     = trailingslashit( wc_get_checkout_url() );

		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['retry-after'] ) && 429 == wp_remote_retrieve_response_code( $response ) ) {

			$retry_after = $headers['retry-after'] + 2;
			sleep( $retry_after );
			$response = wp_remote_get(
				$url,
				array(
					'method'  => 'GET',
					'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
				)
			);
		}

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->destroy_session_tokens();
		if ( 200 == wp_remote_retrieve_response_code( $response ) ) {
			return ! empty( $api_body['data'] ) ? $api_body['data'] : array();
		} else {
			$error = ! empty( $api_body['error'] ) ? $api_body : $response['response'];
		}

		return array();
	}

	public function check_response_for_order( $order_id ) {

		if ( $order_id ) {

			$wc_order = wc_get_order( $order_id );
			if ( ! $wc_order->needs_payment() ) {
				return;
			}
			if ( 'true' == $wc_order->get_meta( '_blink_res_expired', true ) ) {
				return;
			}
			$transaction        = $wc_order->get_meta( 'blink_res', true );
			$transaction_result = $this->validate_transaction( $wc_order, $transaction );
			$status             = ! empty( $transaction_result['status'] ) ? $transaction_result['status'] : $_GET['status'];
			$source             = ! empty( $transaction_result['payment_source'] ) ? $transaction_result['payment_source'] : '';
			$message            = ! empty( $transaction_result['message'] ) ? $transaction_result['message'] : $_GET['note'];
			$wc_order->update_meta_data( '_blink_status', $status );
			$wc_order->update_meta_data( 'payment_type', $source );
			$wc_order->update_meta_data( '_blink_res_expired', 'true' );
			$wc_order->set_transaction_id( $transaction_result['transaction_id'] );
			$wc_order->add_order_note( 'Pay by ' . $source );
			$wc_order->add_order_note( 'Transaction Note: ' . $message );
			$wc_order->save();
			change_status( $wc_order, $transaction_result['transaction_id'], $status, $source, $message );

		}
	}

	public function change_title( $title ) {
		global $wp;
		$order_id = $wp->query_vars['order-received'];
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order->has_status( 'failed' ) ) {
				return 'Order Failed';
			}
		}

		return $title;
	}

	public function capture_payment() {
		return false;
	}

	public function print_custom_notice() {
		$status = $_GET['status'];
		$note   = urldecode( $_GET['note'] );
		if ( get_blink_status( $status ) === 'failed' ) {
			wc_print_notice( $note, 'error' );
		}
	}
}
