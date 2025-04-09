<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Blink_Refund_Handler {

	protected $gateway;
	protected $token;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function handle_refund( $order_id, $amount = null, $reason = '__' ) {
		$order = wc_get_order( $order_id );

		// Get the transaction ID from order meta
		$transaction_id = $order->get_meta( 'blink_res' );

		// Exit if transaction ID is not found
		if ( ! $transaction_id ) {
			$order->add_order_note( __( 'Transaction ID not found.', 'blink-payment-checkout' ) );
			return new WP_Error( 'invalid_order', __( 'Transaction ID not found.', 'blink-payment-checkout' ) );
		}

		// Check if there were previous partial refunds
		$previous_refund_amount = isset( $_POST['refunded_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['refunded_amount'] ) ) : 0;

		// Determine if it's a partial refund
		$partial_refund = ! empty( $previous_refund_amount ) ? true : ( $amount < $order->get_total() );

		// Prepare refund request data
		$request_data = array(
			'partial_refund' => $partial_refund,
			'amount'         => $amount,
			'reference'      => $reason,
		);

		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $this->token ) ) {
			$order->add_order_note( __( 'Refund request failed: check payment settings', 'blink-payment-checkout' ) );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'blink-payment-checkout' ) );
		}

		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		// Send refund request
		$url      = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/refunds';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $request_data,
			)
		);

		// Check if the refund request was successful
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Refund request failed: ', 'blink-payment-checkout' ) . $response->get_error_message() );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'blink-payment-checkout' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Add refund notes to the order
		if ( $data['success'] ) {
			$refund_note = $data['message'] . ' (Transaction ID: ' . $data['transaction_id'] . ')';
			$order->add_order_note( $refund_note );
			$refund_type = $partial_refund ? __( 'Partial', 'blink-payment-checkout' ) : __( 'Full', 'blink-payment-checkout' );
			$order->add_order_note( $refund_type . ' ' . __( 'refund of', 'blink-payment-checkout' ) . ' ' . wc_price( $amount ) . ' ' . __( 'processed successfully. Reason:', 'blink-payment-checkout' ) . ' ' . $reason );
			if ( ( $amount + $previous_refund_amount ) >= $order->get_total() ) {
				$order->update_status( 'refunded' );
			}
		} else {
			$message = ! empty( $data['error'] ) ? $data['error'] : $data['message'];
			$order->add_order_note( __( 'Refund request failed: ', 'blink-payment-checkout' ) . $message );
			return new WP_Error( 'refund_failed', __( 'Refund request failed. ', 'blink-payment-checkout' ) . $message );
		}

		// Return true on successful refund
		return true;
	}

	public function add_cancel_button( $order ) {
		$transaction_id = $order->get_meta( 'blink_res' );

		if ( ! $transaction_id ) {
			return; // Exit if transaction ID is not found
		}

		if ( blink_check_CCPayment( $this->gateway->paymentSource ) ) {
			if ( strtolower( $this->gateway->paymentStatus ) === 'captured' && blink_get_time_diff( $order ) !== true ) {
				// If status is captured, display cancel button
				echo '<div class="cancel-order-container">';
				echo '<button type="button" class="button cancel-order" data-order-id="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Cancel Order', 'blink-payment-checkout' ) . '</button>';
				echo '<span class="cancel-order-tooltip" data-tip="' . esc_attr__( 'It will cancel the transaction.', 'blink-payment-checkout' ) . '">' . esc_html__( 'It will cancel the transaction.', 'blink-payment-checkout' ) . '</span>';
				echo '</div>';
			}
		}

		return;
	}

	public function cancel_order( $order_id ) {
		$order = wc_get_order( $order_id );

		$transaction_id = $order->get_meta( 'blink_res' );

		if ( ! $transaction_id ) {
			return; // Exit if transaction ID is not found
		}

		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $this->token ) ) {
			$order->add_order_note( __( 'Cancel request failed: check payment settings', 'blink-payment-checkout' ) );
			return new WP_Error( 'cancel_failed', __( 'Cancel request failed.', 'blink-payment-checkout' ) );
		}

		// Prepare request headers
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		// Send cancel request
		$url      = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/cancel';
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
			)
		);

		// Check if the cancel request was successful
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Cancel request failed: ', 'blink-payment-checkout' ) . $response->get_error_message() );
			return new WP_Error( 'cancel_failed', __( 'Cancel request failed.', 'blink-payment-checkout' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Add cancel notes to the order
		if ( $data['success'] ) {
			$order->add_order_note( __( 'Order cancelled successfully.', 'blink-payment-checkout' ) );
			$order->update_status( 'cancelled' );
		} else {
			$message = ! empty( $data['error'] ) ? $data['error'] : $data['message'];
			$order->add_order_note( __( 'Cancel request failed: ', 'blink-payment-checkout' ) . $message );
			return new WP_Error( 'cancel_failed', __( 'Cancel request failed. ', 'blink-payment-checkout' ) . $message );
		}

		// Return true on successful cancel
		return true;
	}

	public function should_render_refunds( $render_refunds, $order, $wc_order ) {
		$transaction_id = get_post_meta( $order, 'blink_res', true );
		$WCOrder        = wc_get_order( $order );

		if ( ! $transaction_id ) {
			return $render_refunds; // No Blink transaction, use default behavior
		}

		$this->transactionID = $transaction_id;

		$this->gateway->transaction_handler->get_transaction_status( $transaction_id );

		if ( blink_check_CCPayment( $this->gateway->paymentSource ) ) {
			if ( strtolower( $this->gateway->paymentStatus ) === 'captured' ) {
				$render_refunds = false; // Hide default refund if captured
			}
			if ( blink_get_time_diff( $WCOrder ) === true ) {
				$render_refunds = true;
			}
			if ( empty( $this->gateway->paymentStatus ) ) {
				$render_refunds = false;
			}
		}

		if ( $WCOrder->has_status( array( 'cancelled' ) ) ) {
			$render_refunds = false;
		}

		return $render_refunds;
	}
}
