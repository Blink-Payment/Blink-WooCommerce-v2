<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Blink_Rerun_Handler {

	const IN_PROGRESS_META = '_blink_rerun_in_progress';
	const IN_PROGRESS_TTL  = 300;
	const LOCK_PREFIX      = 'blink_rerun_lock_';

	private $gateway;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Capture a pre-authorised order using Blink's rerun endpoint.
	 *
	 * @param WC_Order $order The order to capture.
	 * @return array Result of the capture operation.
	 */
	public function process_rerun( $order ) {
		$order_id = $order->get_id();
		Blink_Logger::log( 'Processing rerun for order', array( 'order_id' => $order_id ) );

		if ( ! $this->is_eligible_for_rerun( $order ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This pre-authorisation is not eligible for capture', 'blink-payment-gateway-for-woocommerce' ),
			);
		}

		$transaction_id = $order->get_meta( 'blink_res', true );
		$marker         = time() . ':' . wp_generate_uuid4();
		if ( ! $this->acquire_rerun_lock( $order, $marker ) ) {
			return array(
				'success' => false,
				'error'   => __( 'A capture request is already in progress', 'blink-payment-gateway-for-woocommerce' ),
			);
		}
		try {
			$order->update_meta_data( self::IN_PROGRESS_META, $marker );
			// A new attempt gets its own merchant-facing failure result. These fields
			// are then shared by its synchronous response and asynchronous webhook.
			$order->update_meta_data( '_blink_last_failed_rerun_id', '' );
			$order->update_meta_data( '_blink_last_failed_rerun_message', '' );
			$order->save();

			$token = $this->gateway->utils->blink_set_tokens();
			if ( empty( $token ) || empty( $token['access_token'] ) ) {
				$error_message = __( 'Failed to authenticate with payment gateway', 'blink-payment-gateway-for-woocommerce' );
				Blink_Logger::log( 'Failed to get access token for rerun', array( 'order_id' => $order_id ) );
				$this->record_rerun_failure( $order, $error_message );
				return array( 'success' => false, 'error' => $error_message );
			}

			$rerun_data = array(
				'amount'    => $order->get_total(),
				'currency'  => $order->get_currency(),
				'reference' => 'WC-' . $order_id . '-R-' . wp_generate_uuid4(),
			);
			$order->update_meta_data( '_blink_rerun_reference', $rerun_data['reference'] );
			$order->save();
			$url        = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/reruns';

			Blink_Logger::log( 'process_rerun() POST reruns', $rerun_data );
			$response = wp_remote_post(
				$url,
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token['access_token'],
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $rerun_data ),
					'timeout' => 30,
				)
			);
			Blink_Logger::log( 'process_rerun() POST reruns', Blink_Logger::http_response_context( $response ) );

			// A webhook can complete while wp_remote_post() is waiting. Reload the
			// order so its persisted capture/failure state wins over this request's
			// pre-request object state.
			$fresh_order = wc_get_order( $order_id );
			if ( $fresh_order ) {
				$order = $fresh_order;
			}
			// A success webhook may have completed the capture while the request
			// was in flight. Persisted capture state is authoritative, even when
			// the synchronous response is an error or timeout.
			$webhook_capture_id = (string) $order->get_meta( 'blink_rerun_id', true );
			if ( '' !== $webhook_capture_id ) {
				return array(
					'success'        => true,
					'transaction_id' => $webhook_capture_id,
					'amount'         => $order->get_total(),
					'message'        => '',
				);
			}
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				Blink_Logger::log( 'process_rerun() POST reruns', array( 'error' => $error_message ) );
				$this->record_rerun_unknown( $order, $error_message );
				return array( 'success' => false, 'error' => $error_message );
			}

			$http_code           = (int) wp_remote_retrieve_response_code( $response );
			$raw_body            = wp_remote_retrieve_body( $response );
			$decoded_body        = json_decode( $raw_body, true );
			$valid_json          = is_array( $decoded_body );
			$api_body            = $valid_json ? $decoded_body : array();
			$new_transaction_id = ! empty( $api_body['transaction_id'] ) && is_scalar( $api_body['transaction_id'] )
				? sanitize_text_field( (string) $api_body['transaction_id'] )
				: '';
			$is_success         = 200 === $http_code
				&& isset( $api_body['success'] )
				&& in_array( $api_body['success'], array( true, 1, '1', 'true' ), true );
			$response_status    = ! empty( $api_body['status'] ) && is_scalar( $api_body['status'] )
				? sanitize_text_field( (string) $api_body['status'] )
				: 'captured';
			$is_success         = $is_success && $this->is_successful_rerun_status( $response_status );
			$explicit_failure   = $valid_json && isset( $api_body['success'] ) && in_array( $api_body['success'], array( false, 0, '0', 'false' ), true )
				&& ( $this->is_failed_rerun_status( $response_status ) || ! empty( $api_body['message'] ) || ! empty( $api_body['error'] ) );
			$confirmed_success = $is_success && '' !== $new_transaction_id;
			$ambiguous_response = ! $valid_json
				|| in_array( $http_code, array( 408, 429 ), true )
				|| $http_code >= 500
				|| ( $http_code < 200 || $http_code >= 300 ) && ! $explicit_failure
				|| ( ! $confirmed_success && ! $explicit_failure );
			if ( $ambiguous_response ) {
				$error_message = __( 'Blink capture outcome is unknown; awaiting webhook confirmation', 'blink-payment-gateway-for-woocommerce' );
				$this->record_rerun_unknown( $order, $error_message );
				return array( 'success' => false, 'error' => $error_message );
			}
			$failed_webhook_id = (string) $order->get_meta( '_blink_last_failed_rerun_id', true );
			$failed_webhook_status = (string) $order->get_meta( '_blink_last_failed_rerun_status', true );
			if ( $is_success && '' !== $new_transaction_id && $new_transaction_id === $failed_webhook_id && 'failed' === $failed_webhook_status ) {
				$failure_message = (string) $order->get_meta( '_blink_last_failed_rerun_message', true );
				return array( 'success' => false, 'error' => $failure_message ?: __( 'Capture failed', 'blink-payment-gateway-for-woocommerce' ) );
			}

			if ( $confirmed_success ) {
				$this->record_rerun_success( $order, $new_transaction_id, $response_status );

				return array(
					'success'        => true,
					'transaction_id' => $new_transaction_id,
					'amount'         => isset( $api_body['amount'] ) ? $api_body['amount'] : $order->get_total(),
					'message'        => isset( $api_body['message'] ) ? $api_body['message'] : '',
				);
			}

			if ( $is_success ) {
				$error_message = __( 'Blink returned an invalid capture response without a transaction ID', 'blink-payment-gateway-for-woocommerce' );
			} elseif ( ! empty( $api_body['message'] ) && is_scalar( $api_body['message'] ) ) {
				$error_message = sanitize_text_field( (string) $api_body['message'] );
			} elseif ( ! empty( $api_body['error'] ) && is_scalar( $api_body['error'] ) ) {
				$error_message = sanitize_text_field( (string) $api_body['error'] );
			} else {
				$error_message = __( 'Unknown error occurred', 'blink-payment-gateway-for-woocommerce' );
			}

			Blink_Logger::log(
				'Rerun failed',
				array(
					'order_id'       => $order_id,
					'error'          => $error_message,
					'transaction_id' => $new_transaction_id,
					'status'         => isset( $api_body['status'] ) ? $api_body['status'] : '',
				)
			);
			$this->record_rerun_failure( $order, $error_message, $new_transaction_id );

			return array( 'success' => false, 'error' => $error_message );
		} catch ( Throwable $error ) {
			$error_message = sanitize_text_field( $error->getMessage() );
			Blink_Logger::log( 'Rerun exception for order', array( 'order_id' => $order_id, 'error' => $error_message ) );
			$this->record_rerun_failure( $order, $error_message );
			return array( 'success' => false, 'error' => $error_message );
		} finally {
			$this->clear_rerun_in_progress( $order, $marker );
		}
	}

	/**
	 * Persist successful capture state before any WooCommerce status transition.
	 *
	 * @param WC_Order $order          Order being captured.
	 * @param string   $transaction_id Blink capture transaction ID.
	 * @param string   $status         Blink capture status.
	 * @param bool     $clear_marker   Whether synchronous cleanup owns the marker.
	 * @return void
	 */
	public function record_rerun_success( $order, $transaction_id, $status = 'captured', $clear_marker = true ) {
		$transaction_id = sanitize_text_field( (string) $transaction_id );
		if ( '' === $transaction_id ) {
			return;
		}

		$already_recorded = $transaction_id === (string) $order->get_meta( 'blink_rerun_id', true );
		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( 'blink_rerun_id', $transaction_id );
		$order->update_meta_data( '_gateway_status', sanitize_text_field( (string) $status ) );
		if ( $clear_marker ) {
			$order->update_meta_data( self::IN_PROGRESS_META, '' );
		}
		$order->save();

		if ( ! $already_recorded ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s is the Blink capture transaction ID. */
					__( 'Blink pre-authorisation captured successfully (Transaction ID: %s)', 'blink-payment-gateway-for-woocommerce' ),
					$transaction_id
				)
			);
		}
	}

	/**
	 * Classify a final successful rerun response independently of initial
	 * pre-authorisation status semantics.
	 *
	 * @param string $status Blink status returned for the rerun transaction.
	 * @return bool
	 */
	public function is_successful_rerun_status( $status ) {
		$status = strtolower( trim( urldecode( (string) $status ) ) );
		$status = preg_replace( '/[\s_-]+/', ' ', $status );
		return in_array(
			$status,
			array( 'captured', 'settled', 'success', 'successful', 'completed', 'complete', 'paid', 'approved', 'accepted', 'tendered' ),
			true
		);
	}

	public function is_failed_rerun_status( $status ) {
		$status = strtolower( trim( urldecode( (string) $status ) ) );
		$status = preg_replace( '/[\s_-]+/', ' ', $status );
		return in_array( $status, array( 'declined', 'failed', 'reversed', 'reversal', 'voided', 'cancelled', 'canceled' ), true );
	}

	/**
	 * Complete WooCommerce payment bookkeeping for a confirmed capture.
	 *
	 * @param WC_Order $order          Captured order.
	 * @param string   $transaction_id Blink capture transaction ID.
	 * @return void
	 */
	public function complete_rerun_payment( $order, $transaction_id ) {
		if ( 'yes' === $order->get_meta( '_blink_payment_complete_done', true ) ) {
			return;
		}
		if ( function_exists( 'blink_payment_complete' ) ) {
			blink_payment_complete( $order, $transaction_id, '', $order->has_status( 'processing' ) );
		} elseif ( method_exists( $order, 'payment_complete' ) ) {
			// The fallback is only useful to lightweight integrations/tests where
			// the plugin helper has not been loaded.
			$order->payment_complete( $transaction_id );
		}
	}

	/**
	 * Record a capture failure without changing the order or original transaction.
	 *
	 * @param WC_Order $order          Order whose capture failed.
	 * @param string   $message        Safe failure reason.
	 * @param string   $transaction_id Optional failed rerun transaction ID.
	 * @return void
	 */
	public function record_rerun_failure( $order, $message, $transaction_id = '' ) {
		$message        = sanitize_text_field( is_scalar( $message ) ? (string) $message : '' );
		$message        = '' !== $message ? $message : __( 'Unknown error occurred', 'blink-payment-gateway-for-woocommerce' );
		$transaction_id = sanitize_text_field( is_scalar( $transaction_id ) ? (string) $transaction_id : '' );
		$last_id        = (string) $order->get_meta( '_blink_last_failed_rerun_id', true );
		$last_message   = (string) $order->get_meta( '_blink_last_failed_rerun_message', true );
		$is_duplicate   = ( '' !== $transaction_id && $transaction_id === $last_id )
			|| ( $message === $last_message && ( '' === $transaction_id || '' === $last_id ) );

		$order->update_meta_data( '_blink_last_failed_rerun_id', $transaction_id );
		$order->update_meta_data( '_blink_last_failed_rerun_message', $message );
		$order->update_meta_data( '_blink_last_failed_rerun_status', 'failed' );
		$order->save();

		if ( ! $is_duplicate ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s is the capture failure reason returned by Blink. */
					__( 'Blink pre-authorisation capture failed: %s', 'blink-payment-gateway-for-woocommerce' ),
					$message
				)
			);
		}
	}

	public function record_rerun_unknown( $order, $message ) {
		$message = sanitize_text_field( is_scalar( $message ) ? (string) $message : __( 'Capture outcome unknown', 'blink-payment-gateway-for-woocommerce' ) );
		$order->update_meta_data( '_blink_rerun_outcome_unknown', 'yes' );
		$order->save();
		$order->add_order_note( __( 'Blink pre-authorisation capture outcome is unknown; capture retry is blocked pending webhook reconciliation.', 'blink-payment-gateway-for-woocommerce' ) . ' ' . $message );
	}

	/**
	 * Check whether an order can be captured.
	 *
	 * @param WC_Order $order           Order to check.
	 * @param bool     $require_on_hold Whether this is an explicit admin action.
	 * @return bool
	 */
	public function is_eligible_for_rerun( $order, $require_on_hold = false ) {
		if ( ! is_object( $order ) || 'blink' !== $order->get_payment_method() ) {
			return false;
		}
		if ( ! blink_is_preauth_transaction( $order ) || empty( $order->get_meta( 'blink_res', true ) ) ) {
			return false;
		}
		if ( 'yes' === $order->get_meta( '_blink_rerun_outcome_unknown', true ) ) {
			return false;
		}
		if ( ! empty( $order->get_meta( 'blink_rerun_id', true ) ) || $this->is_rerun_in_progress( $order ) ) {
			return false;
		}
		if ( $require_on_hold && ! $order->has_status( 'on-hold' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine whether a non-stale capture request is active.
	 *
	 * @param WC_Order $order Order to check.
	 * @return bool
	 */
	public function is_rerun_in_progress( $order ) {
		$marker_started = (int) $order->get_meta( self::IN_PROGRESS_META, true );
		$lock_started   = (int) get_option( self::LOCK_PREFIX . $order->get_id(), '' );
		if ( 0 === $marker_started && 0 === $lock_started ) {
			return false;
		}
		if ( ( 0 < $marker_started && ( time() - $marker_started ) <= self::IN_PROGRESS_TTL )
			|| ( 0 < $lock_started && ( time() - $lock_started ) <= self::IN_PROGRESS_TTL ) ) {
			return true;
		}

		$this->clear_rerun_in_progress( $order );
		return false;
	}

	/**
	 * Acquire an atomic, order-scoped lock in addition to the visible order meta.
	 *
	 * @param WC_Order $order  Order being captured.
	 * @param string   $marker Unique request marker.
	 * @return bool
	 */
	private function acquire_rerun_lock( $order, $marker ) {
		$key = self::LOCK_PREFIX . $order->get_id();
		if ( add_option( $key, $marker, '', 'no' ) ) {
			return true;
		}

		$current = (string) get_option( $key, '' );
		if ( 0 < (int) $current && ( time() - (int) $current ) > self::IN_PROGRESS_TTL ) {
			delete_option( $key );
			return add_option( $key, $marker, '', 'no' );
		}

		return false;
	}

	/**
	 * Clear this request's marker without erasing a newer request's marker.
	 *
	 * @param WC_Order $order  Order being captured.
	 * @param string   $marker Marker owned by this request; empty clears any marker.
	 * @return void
	 */
	public function clear_rerun_in_progress( $order, $marker = '' ) {
		$current = (string) $order->get_meta( self::IN_PROGRESS_META, true );
		if ( '' !== $marker && '' !== $current && $marker !== $current ) {
			return;
		}
		if ( '' !== $current ) {
			$order->update_meta_data( self::IN_PROGRESS_META, '' );
			$order->save();
		}

		$lock_key = self::LOCK_PREFIX . $order->get_id();
		$lock     = (string) get_option( $lock_key, '' );
		if ( '' !== $lock && ( '' === $marker || $marker === $lock ) ) {
			delete_option( $lock_key );
		}
	}
}
