<?php
/**
 * Helper functions shared by the Blink gateway.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'blink_pg_migrate_options' ) ) {
	/**
	 * Move options saved by 1.4.1 and earlier to their prefixed names, so
	 * merchants keep their admin token and Apple Pay domain verification.
	 *
	 * @return void
	 */
	function blink_pg_migrate_options() {
		$renamed = array(
			'blink_admin_token'       => 'blink_pg_admin_token',
			'blink_apple_domain_auth' => 'blink_pg_apple_domain_auth',
		);
		foreach ( $renamed as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( null === get_option( $new, null ) ) {
				update_option( $new, $value );
			}
			delete_option( $old );
		}
	}
}

if ( ! function_exists( 'blink_pg_get_card_logos' ) ) {
	/**
	 * Return the locally hosted card logos enabled in the gateway settings.
	 *
	 * The catalogue is deliberately fixed so stored settings cannot provide an
	 * arbitrary URL, label or HTML. Missing settings retain the legacy display
	 * behaviour: no card-brand logos.
	 *
	 * @param array $settings Blink gateway settings.
	 * @return array[]
	 */
	function blink_pg_get_card_logos( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$brands   = array(
			'visa'             => array(
				'label'    => 'Visa',
				'filename' => 'visa.svg',
			),
			'mastercard'       => array(
				'label'    => 'Mastercard',
				'filename' => 'mastercard.svg',
			),
			'american_express' => array(
				'label'    => 'American Express',
				'filename' => 'american-express.svg',
			),
		);
		$logos    = array();

		foreach ( $brands as $brand => $data ) {
			if ( ! isset( $settings[ 'card_logo_' . $brand ] ) || 'yes' !== $settings[ 'card_logo_' . $brand ] ) {
				continue;
			}

			$logos[] = array(
				'id'     => $brand,
				'label'  => $data['label'],
				'url'    => esc_url_raw( plugins_url( '../assets/img/' . $data['filename'], __FILE__ ) ),
				'width'  => 'american_express' === $brand ? 37 : 36,
				'height' => 24,
			);
		}

		return $logos;
	}
}

if ( ! function_exists( 'blink_pg_get_card_logos_html' ) ) {
	/**
	 * Render enabled card logos for WooCommerce Classic Checkout.
	 *
	 * @param array $settings Blink gateway settings.
	 * @return string
	 */
	function blink_pg_get_card_logos_html( $settings ) {
		$logos = blink_pg_get_card_logos( $settings );
		if ( empty( $logos ) ) {
			return '';
		}

		$html = '<span class="blink-card-logos">';
		foreach ( $logos as $logo ) {
			$html .= sprintf(
				'<img class="blink-card-logo" src="%1$s" alt="%2$s" width="%3$d" height="%4$d" />',
				esc_url( $logo['url'] ),
				esc_attr( $logo['label'] ),
				absint( $logo['width'] ),
				absint( $logo['height'] )
			);
		}
		$html .= '</span>';

		return $html;
	}
}

if ( ! function_exists( 'blink_pg_is_safari' ) ) {
	/**
	 * Whether the shopper's user agent looks like Safari rather than Chrome.
	 *
	 * @return bool
	 */
	function blink_pg_is_safari() {
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			if ( strpos( $user_agent, 'Safari' ) !== false && strpos( $user_agent, 'Chrome' ) === false ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'blink_pg_should_render_apple_pay' ) ) {
	/**
	 * Whether the Apple Pay element supplied by Blink should be rendered.
	 *
	 * Blink's Apple Pay script owns shopper browser/device eligibility. The
	 * WooCommerce integration only applies the merchant setting and confirms
	 * that Blink supplied an Apple Pay element for the intent.
	 *
	 * @param bool  $apple_pay_enabled Whether Apple Pay is enabled by the merchant.
	 * @param array $elements          Elements returned with the Blink intent.
	 * @return bool
	 */
	function blink_pg_should_render_apple_pay( $apple_pay_enabled, $elements ) {
		return ! empty( $apple_pay_enabled )
			&& is_array( $elements )
			&& ! empty( $elements['apElement'] );
	}
}

if ( ! function_exists( 'blink_pg_get_wallet_availability' ) ) {
	/**
	 * Determine which wallet elements Blink supplied for WooCommerce to render.
	 *
	 * Shopper browser and device eligibility remains owned by Blink and Apple.
	 *
	 * @param bool  $apple_pay_enabled Whether Apple Pay is enabled by the merchant.
	 * @param array $elements          Elements returned with the Blink intent.
	 * @return array
	 */
	function blink_pg_get_wallet_availability( $apple_pay_enabled, $elements ) {
		$show_apple_pay  = blink_pg_should_render_apple_pay( $apple_pay_enabled, $elements );
		$show_google_pay = is_array( $elements ) && ! empty( $elements['gpElement'] );

		return array(
			'showApplePay'  => $show_apple_pay,
			'showGooglePay' => $show_google_pay,
			'showWalletRow' => $show_apple_pay || $show_google_pay,
		);
	}
}

if ( ! function_exists( 'blink_pg_transform_word' ) ) {
	/**
	 * Map a payment method slug to its display label.
	 *
	 * @param string $word Payment method slug, such as credit-card.
	 * @return string The label, or the word unchanged when it has no mapping.
	 */
	function blink_pg_transform_word( $word ) {
		$transformations = array(
			'credit-card'  => 'Card',
			'direct-debit' => 'Direct Debit',
			'open-banking' => 'Open Banking',
		);
		return array_key_exists( $word, $transformations ) ? $transformations[ $word ] : $word;
	}
}

if ( ! function_exists( 'blink_pg_check_timestamp_expired' ) ) {
	/**
	 * Whether a UTC timestamp is in the past.
	 *
	 * @param string $timestamp Date/time string, read as UTC.
	 * @return int 1 when expired, 0 otherwise.
	 */
	function blink_pg_check_timestamp_expired( $timestamp ) {
		// Ensure both timestamps are in UTC.
		$now_utc    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$expiry_utc = new DateTime( $timestamp, new DateTimeZone( 'UTC' ) );

		return $now_utc > $expiry_utc ? 1 : 0;
	}
}


if ( ! function_exists( 'blink_pg_get_time_diff' ) ) {
	/**
	 * Whether the order was created at least 24 hours ago.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	function blink_pg_get_time_diff( $order ) {
		$order_date_time   = new DateTime( $order->get_date_created()->date( 'Y-m-d H:i:s' ) );
		$current_date_time = new DateTime();
		$time_difference   = $current_date_time->diff( $order_date_time );
		return $time_difference->days > 0 || $time_difference->h >= 24;
	}
}

if ( ! function_exists( 'blink_pg_check_cc_payment' ) ) {
	/**
	 * Whether a payment source is a card payment, not direct debit or open banking.
	 *
	 * @param string $source Payment source reported by Blink.
	 * @return bool
	 */
	function blink_pg_check_cc_payment( $source ) {
		$payment_types = array( 'direct debit', 'open banking' );
		foreach ( $payment_types as $type ) {
			if ( preg_match( '/\b' . strtolower( $type ) . '\b/i', $source ) ) {
				return false;
			}
		}
		return true;
	}
}

if ( ! function_exists( 'blink_pg_get_customer_data' ) ) {
	/**
	 * Collect the customer and billing details of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	function blink_pg_get_customer_data( $order ) {
		return array(
			'customer_id'        => $order->get_user_id(),
			'customer_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customer_email'     => $order->get_billing_email(),
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_company'    => $order->get_billing_company(),
			'billing_email'      => $order->get_billing_email(),
			'billing_phone'      => $order->get_billing_phone(),
			'billing_address_1'  => $order->get_billing_address_1(),
			'billing_address_2'  => $order->get_billing_address_2(),
			'billing_postcode'   => $order->get_billing_postcode(),
			'billing_city'       => $order->get_billing_city(),
			'billing_state'      => $order->get_billing_state(),
			'billing_country'    => $order->get_billing_country(),
		);
	}
}

if ( ! function_exists( 'blink_pg_get_order_data' ) ) {
	/**
	 * Collect the ID, number, date, total and currency of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	function blink_pg_get_order_data( $order ) {
		$order_date = $order->get_date_created();
		return array(
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'order_date'     => $order_date ? $order_date->format( 'Y-m-d H:i:s' ) : '',
			'order_total'    => wc_format_decimal( $order->get_total(), 2 ),
			'order_currency' => $order->get_currency(),
		);
	}
}

if ( ! function_exists( 'blink_pg_get_payment_information' ) ) {
	/**
	 * Build the merchant data JSON sent to Blink for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string|false JSON string, or false on encoding failure.
	 */
	function blink_pg_get_payment_information( $order_id ) {
		$order = wc_get_order( $order_id );
		// Blink can echo merchant_data in browser return URLs. Keep only the order identifier.
		return wp_json_encode(
			array(
				'order_info' => array(
					'order_id' => $order->get_id(),
				),
			)
		);
	}
}

if ( ! function_exists( 'blink_pg_error_payment_process' ) ) {
	/**
	 * Add a checkout error notice and return a failed payment result.
	 *
	 * @param string $error Error message; a generic message is used when empty.
	 * @return array
	 */
	function blink_pg_error_payment_process( $error = '' ) {
		$error = $error ? $error : __( 'Error! Something went wrong.', 'blink-payment-gateway-for-woocommerce' );
		wc_add_notice( $error, 'error' );

		return array(
			'result'   => 'failure',
			'messages' => $error,
			'refresh'  => true,
			'reload'   => false,
		);
	}
}

if ( ! function_exists( 'blink_pg_is_preauth_transaction' ) ) {
	/**
	 * Whether a Blink order is (or will be) taken as a pre-authorisation.
	 *
	 * Uses the order's _blink_preauth meta, falling back to the gateway setting.
	 *
	 * @param WC_Order|null $order WooCommerce order.
	 * @return bool
	 */
	function blink_pg_is_preauth_transaction( $order ) {
		if ( ! $order || $order->get_payment_method() !== 'blink' ) {
			return false;
		}

		// Check if _blink_preauth meta is set.
		$blink_preauth = $order->get_meta( '_blink_preauth', true );
		if ( '' !== $blink_preauth ) {
			return 'yes' === $blink_preauth;
		}

		// Fallback: check gateway settings if meta not set.
		$gateways = WC()->payment_gateways->payment_gateways();
		$gateway  = isset( $gateways['blink'] ) ? $gateways['blink'] : null;
		if ( $gateway && isset( $gateway->preauthorize_payments ) ) {
			return $gateway->preauthorize_payments;
		}

		return false;
	}
}

if ( ! function_exists( 'blink_pg_get_charge_transaction_id' ) ) {
	/**
	 * Return the transaction that represents the charge currently held by Blink.
	 *
	 * A captured pre-authorisation keeps its original ID in blink_res and stores
	 * the capture ID separately in blink_rerun_id.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	function blink_pg_get_charge_transaction_id( $order ) {
		if ( ! is_object( $order ) ) {
			return '';
		}

		$capture_id = $order->get_meta( 'blink_rerun_id', true );
		return $capture_id ? (string) $capture_id : (string) $order->get_meta( 'blink_res', true );
	}
}

if ( ! function_exists( 'blink_pg_get_status' ) ) {
	/**
	 * Map a Blink transaction status to complete, hold or failed.
	 *
	 * @param string        $status Transaction status reported by Blink.
	 * @param string        $source Payment source reported by Blink.
	 * @param WC_Order|null $order  WooCommerce order, used to detect pre-authorisation.
	 * @return string complete, hold or failed.
	 */
	function blink_pg_get_status( $status = '', $source = '', $order = null ) {
		$status = strtolower( trim( urldecode( (string) $status ) ) );
		$status = preg_replace( '/[\s_-]+/', ' ', $status );
		$source = strtolower( trim( urldecode( (string) $source ) ) );

		// Check if this order was processed with preauth.
		$is_preauth_mode = blink_pg_is_preauth_transaction( $order );

		// Successful authorisation and pending states still need to be captured.
		if ( $is_preauth_mode && in_array( $status, array( 'paid', 'approved', 'authorized', 'authorised', 'pending', 'pending submission', 'processing', 'submitted', 'awaiting capture', 'preauthorized', 'pre authorized', 'pre auth', 'preauth', 'reversed' ), true ) ) {
			return 'hold';
		}

		if ( in_array( $status, array( 'tendered', 'captured', 'settled', 'success', 'successful', 'completed', 'accept', 'accepted', 'paid', 'approved', 'received', 'payment attempted' ), true ) ) {
			return 'complete';
		} elseif ( strpos( $source, 'direct debit' ) !== false || in_array( $status, array( 'pending submission', 'authorized', 'authorised', 'reversed' ), true ) ) {
			return 'hold';
		}
		return 'failed';
	}
}

if ( ! function_exists( 'blink_pg_resolve_payment_message' ) ) {
	/**
	 * Sanitize and resolve transaction-processing messages.
	 *
	 * A request-derived fallback must not be used as a customer-facing notice.
	 *
	 * @param string $transaction_message Message from the Blink transaction.
	 * @param string $redirect_note       Note from the return redirect, used when the transaction message is empty.
	 * @return string
	 */
	function blink_pg_resolve_payment_message( $transaction_message = '', $redirect_note = '' ) {
		$transaction_message = is_scalar( $transaction_message ) ? trim( sanitize_text_field( (string) $transaction_message ) ) : '';
		$redirect_note       = is_scalar( $redirect_note ) ? trim( sanitize_text_field( (string) $redirect_note ) ) : '';

		return '' !== $transaction_message ? $transaction_message : $redirect_note;
	}
}

if ( ! function_exists( 'blink_pg_change_status' ) ) {
	/**
	 * Record a Blink transaction status on an order and update the order to match.
	 *
	 * @param WC_Order    $wc_order       WooCommerce order.
	 * @param string      $transaction_id Blink transaction ID.
	 * @param string      $status         Transaction status reported by Blink.
	 * @param string      $source         Payment source reported by Blink.
	 * @param string|null $note           Order note to use instead of the default.
	 * @return void
	 */
	function blink_pg_change_status( $wc_order, $transaction_id, $status = '', $source = '', $note = null ) {
		// Set _gateway_status meta field.
		$wc_order->update_meta_data( '_gateway_status', $status );

		// Check if this is a preauth transaction and set _blink_preauth meta.
		$is_preauth = blink_pg_is_preauth_transaction( $wc_order );
		$wc_order->update_meta_data( '_blink_preauth', $is_preauth ? 'yes' : 'no' );

		$wc_order->save();

		$wc_order->add_order_note( __( 'Transaction status - ', 'blink-payment-gateway-for-woocommerce' ) . $status );
		$mapped_status = blink_pg_get_status( $status, $source, $wc_order );
		if ( 'complete' === $mapped_status ) {
			blink_pg_payment_complete( $wc_order, $transaction_id, $note ? $note : __( 'Blink payment completed', 'blink-payment-gateway-for-woocommerce' ) );
		} elseif ( 'hold' === $mapped_status ) {
			blink_pg_payment_on_hold( $wc_order, $note ? $note : __( 'Payment Pending (Transaction status - ', 'blink-payment-gateway-for-woocommerce' ) . $status . ')' );
		} else {
			blink_pg_payment_failed( $wc_order, $note ? $note : __( 'Payment Failed (Transaction status - ', 'blink-payment-gateway-for-woocommerce' ) . $status . ')' );
		}
	}
}

if ( ! function_exists( 'blink_pg_payment_complete' ) ) {
	/**
	 * Complete order, add transaction ID and note.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $txn_id Transaction ID.
	 * @param string   $note             Payment note.
	 * @param bool     $allow_processing Allow core completion when status is already Processing.
	 */
	function blink_pg_payment_complete( $order, $txn_id = '', $note = '', $allow_processing = false ) {
		$order_id = $order->get_id();
		$opt_key  = 'blink_pg_payment_done_' . $order_id;
		if ( ! add_option( $opt_key, 'yes' ) ) {
			return;
		}

		$fresh_order = wc_get_order( $order_id );
		if ( $fresh_order ) {
			$order = $fresh_order;
		}

		if ( $order->get_meta( '_blink_payment_complete_done', true ) === 'yes' ) {
			delete_option( $opt_key );
			return;
		}
		$order->update_meta_data( '_blink_payment_complete_done', 'yes' );
		$order->save();

		if ( ! $allow_processing && $order->has_status( array( 'processing', 'completed' ) ) ) {
			delete_option( $opt_key );
			return;
		}

		if ( $note ) {
			$order->add_order_note( $note );
		}
		$processing_completion = $allow_processing && $order->has_status( 'processing' );
		$processing_filter     = null;
		if ( $processing_completion ) {
			$processing_order_id = (int) $order->get_id();
			$processing_filter   = static function ( $statuses, $filter_order ) use ( $processing_order_id ) {
				if ( is_object( $filter_order ) && (int) $filter_order->get_id() === $processing_order_id && ! in_array( 'processing', $statuses, true ) ) {
					$statuses[] = 'processing';
				}
				return $statuses;
			};
			add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $processing_filter, 10, 2 );
		}
		try {
			$order->payment_complete( $txn_id );
		} finally {
			if ( $processing_completion ) {
				remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $processing_filter, 10 );
			}
		}
		delete_option( $opt_key );

		if ( isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}
	}
}

if ( ! function_exists( 'blink_pg_payment_on_hold' ) ) {
	/**
	 * Hold order and add note.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reason Reason why the payment is on hold.
	 */
	function blink_pg_payment_on_hold( $order, $reason = '' ) {
		$order_id = $order->get_id();
		$opt_key  = 'blink_pg_payment_done_' . $order_id;
		if ( ! add_option( $opt_key, 'yes' ) ) {
			return;
		}
		if ( $order->get_meta( '_blink_payment_hold_done', true ) === 'yes' ) {
			delete_option( $opt_key );
			return;
		}
		$order->update_meta_data( '_blink_payment_hold_done', 'yes' );
		$order->save();

		if ( $order->has_status( array( 'on-hold' ) ) ) {
			delete_option( $opt_key );
			return;
		}

		$order->update_status( 'on-hold', $reason );
		if ( $reason ) {
			$order->add_order_note( $reason );
		}
		delete_option( $opt_key );

		if ( isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}
	}
}

if ( ! function_exists( 'blink_pg_payment_failed' ) ) {
	/**
	 * Mark order as failed and add note.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reason Reason why the payment failed.
	 */
	function blink_pg_payment_failed( $order, $reason = '' ) {
		$order_id = $order->get_id();
		$opt_key  = 'blink_pg_payment_done_' . $order_id;
		if ( ! add_option( $opt_key, 'yes' ) ) {
			return;
		}
		if ( $order->has_status( array( 'failed' ) ) ) {
			delete_option( $opt_key );
			return;
		}
		$order->update_status( 'failed', $reason );
		if ( $reason ) {
			$order->add_order_note( $reason );
		}
		delete_option( $opt_key );
	}
}

if ( ! function_exists( 'blink_pg_is_in_admin_section' ) ) {
	/**
	 * Check if the current page is in the admin section for Blink settings.
	 *
	 * @return bool
	 */
	function blink_pg_is_in_admin_section() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check of which admin page is open.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		return 'wc-settings' === $page && 'checkout' === $tab && 'blink' === $section;
	}
}

if ( ! function_exists( 'blink_pg_get_3ds_challenge_url' ) ) {
	/**
	 * URL for the minimal 3DS challenge page.
	 *
	 * @param int    $order_id Order ID (blink_3d_process).
	 * @param string $nonce    Nonce (blink_3d_nonce).
	 * @return string
	 */
	function blink_pg_get_3ds_challenge_url( $order_id, $nonce ) {
		return add_query_arg(
			array(
				'blink_3d_process' => $order_id,
				'blink_3d_nonce'   => $nonce,
			),
			home_url( '/blink-3ds-challenge/' )
		);
	}
}

if ( ! function_exists( 'blink_pg_add_notice' ) ) {
	/**
	 * Add an admin notice for Blink errors.
	 *
	 * @param array $api_body API response body.
	 */
	function blink_pg_add_notice( $api_body = array() ) {
		$error = __( 'Error! Something went wrong.', 'blink-payment-gateway-for-woocommerce' );
		if ( is_array( $api_body ) && ! empty( $api_body ) ) {
			if ( isset( $api_body['success'] ) && false === $api_body['success'] ) {
				$error = $api_body['message'] ?? $api_body['error'] ?? $error;
			} else {
				$error = $api_body['error'] ?? $api_body['message'] ?? $error;
			}
		}

		if ( ! blink_pg_is_in_admin_section() ) {
			$adminnotice = new WC_Admin_Notices();
			$adminnotice->add_custom_notice( 'blink-error', $error );
		}
	}
}

if ( ! function_exists( 'blink_pg_is_valid_applepay_domain' ) ) {
	/**
	 * Whether a value is the "https://<host>" origin the Apple Pay settings
	 * button sends: https, a valid hostname, and no port, path or query.
	 *
	 * @param string $domain Candidate origin.
	 * @return bool
	 */
	function blink_pg_is_valid_applepay_domain( $domain ) {
		if ( ! is_string( $domain ) || '' === $domain ) {
			return false;
		}
		$parts = wp_parse_url( $domain );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', array( '', '/' ), true ) ) {
			return false;
		}
		return false !== filter_var( $parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) && false !== strpos( $parts['host'], '.' );
	}
}

if ( ! function_exists( 'blink_pg_generate_applepay_domains' ) ) {
	/**
	 * Generate Apple Pay domains.
	 */
	function blink_pg_generate_applepay_domains() {
		// Check user permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$configs    = include __DIR__ . '/../config.php';
		$host_url   = $configs['host_url'] . '/api';
		$settings   = get_option( 'woocommerce_blink_settings' );
		$testmode   = 'yes' === $settings['testmode'];
		$api_key    = $testmode ? $settings['test_api_key'] : $settings['api_key'];
		$secret_key = $testmode ? $settings['test_secret_key'] : $settings['secret_key'];

		// Check for nonce security.
		check_ajax_referer( 'generate_applepay_domains_nonce', 'security' );

		$url = $host_url . '/pay/v1/applepay/domains';
		// The settings page sends "https://<this site's host>" and the access token it was just given.
		$domain = isset( $_POST['domain'] ) ? esc_url_raw( wp_unslash( $_POST['domain'] ), array( 'https' ) ) : '';
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! blink_pg_is_valid_applepay_domain( $domain ) || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Invalid domain or access token.', 'blink-payment-gateway-for-woocommerce' ) ) );
		}

		$request_data = array(
			'domain_name' => $domain,
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => Blink_PG_Payment_Utils::API_TIMEOUT,
				'method'  => 'POST',
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'body'    => $request_data,
			)
		);

		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$message  = is_array( $api_body ) && ! empty( $api_body['message'] ) ? $api_body['message'] : '';

		// Blink sends success as a boolean or the string "true".
		if ( is_array( $api_body ) && isset( $api_body['success'] ) && in_array( $api_body['success'], array( true, 'true' ), true ) ) {
			update_option( 'blink_pg_apple_domain_auth', 1 );
			wp_send_json_success( array( 'message' => $message ) );
		}

		wp_send_json_error( array( 'message' => '' !== $message ? $message : __( 'Integration unsuccessful', 'blink-payment-gateway-for-woocommerce' ) ) );
	}
}

if ( ! function_exists( 'blink_pg_generate_access_token' ) ) {
	/**
	 * Generate access token for Blink API.
	 */
	function blink_pg_generate_access_token() {
		// Check user permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$configs    = include __DIR__ . '/../config.php';
		$host_url   = $configs['host_url'] . '/api';
		$settings   = get_option( 'woocommerce_blink_settings' );
		$testmode   = 'yes' === $settings['testmode'];
		$api_key    = $testmode ? $settings['test_api_key'] : $settings['api_key'];
		$secret_key = $testmode ? $settings['test_secret_key'] : $settings['secret_key'];

		// Check for nonce security.
		check_ajax_referer( 'generate_access_token_nonce', 'security' );

		$url = $host_url . '/pay/v1/tokens';

		$request_data = array(
			'api_key'                 => $api_key,
			'secret_key'              => $secret_key,
			'source_site'             => get_bloginfo( 'name' ),
			'application_name'        => 'Woocommerce Blink ' . $configs['version'],
			'application_description' => 'WP-' . get_bloginfo( 'version' ) . ' WC-' . WC_VERSION,
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => Blink_PG_Payment_Utils::TOKEN_TIMEOUT,
				'method'  => 'POST',
				'body'    => $request_data,
			)
		);
		$api_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === (int) wp_remote_retrieve_response_code( $response ) && ! empty( $api_body['access_token'] ) ) {
			wp_send_json_success( array( 'access_token' => $api_body['access_token'] ) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to generate access token', 'blink-payment-gateway-for-woocommerce' ) ) );
	}
}

if ( ! function_exists( 'blink_pg_can_view_order_pay' ) ) {
	/**
	 * Whether this visitor may pay the order on the pay-for-order page.
	 *
	 * The same checks WooCommerce makes before it shows the payment form: the
	 * order exists, the URL has its order key, and the visitor may pay it
	 * (a guest order, or the logged-in customer's own).
	 *
	 * @param WC_Order|false $order Order from the order-pay URL.
	 * @return bool
	 */
	function blink_pg_can_view_order_pay( $order ) {
		if ( ! $order ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the check; no state changes.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return false;
		}
		return current_user_can( 'pay_for_order', $order->get_id() );
	}
}

if ( ! function_exists( 'blink_pg_api_message' ) ) {
	/**
	 * The error or message text from a decoded Blink API response.
	 *
	 * Blink error responses don't always include a message, so callers get a
	 * fallback rather than reading a missing key.
	 *
	 * @param mixed  $data     Decoded JSON response, normally an array.
	 * @param string $fallback Text to use when the response has no message.
	 * @return string
	 */
	function blink_pg_api_message( $data, $fallback ) {
		if ( is_array( $data ) ) {
			foreach ( array( 'error', 'message' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
					return (string) $data[ $key ];
				}
			}
		}
		return $fallback;
	}
}

if ( ! function_exists( 'blink_pg_is_checkout_block' ) ) {
	/**
	 * Check if the checkout page uses Checkout Blocks.
	 *
	 * @return bool
	 */
	function blink_pg_is_checkout_block() {
		$page_id = get_queried_object_id();

		if ( empty( $page_id ) ) {
			return false;
		}

		if ( class_exists( 'WC_Blocks_Utils' ) && method_exists( 'WC_Blocks_Utils', 'has_block_in_page' ) ) {
			return WC_Blocks_Utils::has_block_in_page( $page_id, 'woocommerce/checkout' );
		}

		return function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $page_id );
	}
}

if ( ! function_exists( 'blink_pg_3d_allow_html' ) ) {
	/**
	 * Allow specific HTML tags and attributes.
	 *
	 * @return array Allowed HTML tags and attributes.
	 */
	function blink_pg_3d_allow_html() {
		return array(
			'form'             => array(
				'id'     => true,
				'method' => true,
				'action' => true,
				'name'   => true,
			),
			'input'            => array(
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'id'          => true,
				'class'       => true,
				'placeholder' => true,
			),
			'script'           => array(
				'nonce'       => true,
				'type'        => true,
				'src'         => true,
				'async'       => true,
				'onload'      => true,
				'defer'       => true,
				'integrity'   => true,
				'crossorigin' => true,
			),
			'div'              => array(
				'class' => true,
				'id'    => true,
				'style' => true,
			),
			'label'            => array(
				'class' => true,
				'for'   => true,
			),
			'link'             => array(
				'rel'  => true,
				'href' => true,
			),
			'apple-pay-button' => array(
				'buttonstyle' => true,
				'type'        => true,
				'id'          => true,
				'locale'      => true,
				'onclick'     => true,
			),
			'a'                => array(
				'class'       => true,
				'id'          => true,
				'href'        => true,
				'data-toggle' => true,
			),
		);
	}
}

if ( ! function_exists( 'blink_pg_get_full_address' ) ) {
	/**
	 * Join the order's billing address lines, city, state and country.
	 *
	 * @param WC_Order|null $order WooCommerce order.
	 * @return string Comma-separated address, or an empty string.
	 */
	function blink_pg_get_full_address( $order = null ) {
		if ( ! $order ) {
			return '';
		}

		$parts = array();

		if ( $order->get_billing_address_1() ) {
			$parts[] = $order->get_billing_address_1();
		}
		if ( $order->get_billing_address_2() ) {
			$parts[] = $order->get_billing_address_2();
		}
		if ( $order->get_billing_city() ) {
			$parts[] = $order->get_billing_city();
		}
		if ( $order->get_billing_state() ) {
			$parts[] = $order->get_billing_state();
		}
		if ( $order->get_billing_country() ) {
			$parts[] = $order->get_billing_country();
		}

		return implode( ', ', $parts );
	}
}

if ( ! function_exists( 'blink_pg_get_client_ip' ) ) {
	/**
	 * Return the shopper's IPv4 address from REMOTE_ADDR.
	 *
	 * @return string IPv4 address, or an empty string when it is not IPv4.
	 */
	function blink_pg_get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Strip IPv6-mapped IPv4 prefix if present.
		if ( strpos( $ip, '::ffff:' ) === 0 ) {
			$ip = substr( $ip, 7 );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? sanitize_text_field( $ip ) : '';
	}
}

if ( ! function_exists( 'blink_pg_is_rest_request' ) ) {
	/**
	 * Whether the current request is for the plugin's REST namespace.
	 *
	 * @return bool
	 */
	function blink_pg_is_rest_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, '/blink-payment-gateway/v1' );
	}
}
