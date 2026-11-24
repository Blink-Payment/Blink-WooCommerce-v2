<?php
/**
 * Refund and cancel handling for Blink transactions.
 *
 * @package Blink_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Refunds and cancels Blink transactions and controls the order screen's refund and cancel buttons.
 */
class Blink_PG_Refund_Handler {

	/**
	 * Blink gateway instance.
	 *
	 * @var Blink_PG_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * Access token response from the Blink API.
	 *
	 * @var array
	 */
	protected $token;

	/**
	 * Constructor.
	 *
	 * @param Blink_PG_Payment_Gateway $gateway Blink gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Refund all or part of an order's Blink transaction and add order notes.
	 *
	 * @param int|string $order_id WooCommerce order ID.
	 * @param float|null $amount   Amount to refund.
	 * @param string     $reason   Refund reason, sent to Blink as the reference.
	 * @return true|WP_Error True on success.
	 */
	public function blink_handle_refund( $order_id, $amount = null, $reason = '__' ) {
		$order = wc_get_order( $order_id );

		// Get the transaction ID from order meta.
		$transaction_id = blink_pg_get_charge_transaction_id( $order );

		// Exit if transaction ID is not found.
		if ( ! $transaction_id ) {
			$order->add_order_note( __( 'Transaction ID not found.', 'blink-payment-gateway-for-woocommerce' ) );
			return new WP_Error( 'invalid_order', __( 'Transaction ID not found.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		// Check if there were previous partial refunds.
		// Get the total refunded amount from the order.
		$previous_refund_amount = $order->get_total_refunded();

		// Determine if it's a partial refund.
		$partial_refund = ( $amount < $order->get_total() );

		// Prepare refund request data.
		$request_data = array(
			'partial_refund' => $partial_refund,
			'amount'         => $amount,
			'reference'      => $reason,
		);

		$this->token = $this->gateway->utils->blink_generate_access_token();
		if ( empty( $this->token ) ) {
			$order->add_order_note( __( 'Refund request failed: check payment settings', 'blink-payment-gateway-for-woocommerce' ) );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		// Prepare request headers.
		$headers = array( 'Authorization' => 'Bearer ' . $this->token['access_token'] );

		// Send refund request.
		$url      = $this->gateway->host_url . '/pay/v1/transactions/' . $transaction_id . '/refunds';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => Blink_PG_Payment_Utils::API_TIMEOUT,
				'headers' => $headers,
				'body'    => $request_data,
			)
		);

		// Check if the refund request was successful.
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Refund request failed: ', 'blink-payment-gateway-for-woocommerce' ) . $response->get_error_message() );
			return new WP_Error( 'refund_failed', __( 'Refund request failed.', 'blink-payment-gateway-for-woocommerce' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			/* translators: %d is the HTTP status code. */
			$message = sprintf( __( 'Unexpected response from Blink (HTTP %d).', 'blink-payment-gateway-for-woocommerce' ), (int) wp_remote_retrieve_response_code( $response ) );
			$order->add_order_note( __( 'Refund request failed: ', 'blink-payment-gateway-for-woocommerce' ) . $message );
			return new WP_Error( 'refund_failed', __( 'Refund request failed. ', 'blink-payment-gateway-for-woocommerce' ) . $message );
		}

		// Add refund notes to the order.
		if ( ! empty( $data['success'] ) ) {
			$refund_note = blink_pg_api_message( $data, __( 'Refund processed.', 'blink-payment-gateway-for-woocommerce' ) );
			if ( ! empty( $data['transaction_id'] ) && is_scalar( $data['transaction_id'] ) ) {
				$refund_note .= ' (Transaction ID: ' . $data['transaction_id'] . ')';
			}
			$order->add_order_note( $refund_note );
			$refund_type = $partial_refund ? __( 'Partial', 'blink-payment-gateway-for-woocommerce' ) : __( 'Full', 'blink-payment-gateway-for-woocommerce' );
			$order->add_order_note( $refund_type . ' ' . __( 'refund of', 'blink-payment-gateway-for-woocommerce' ) . ' ' . wc_price( $amount ) . ' ' . __( 'processed successfully. Reason:', 'blink-payment-gateway-for-woocommerce' ) . ' ' . $reason );
			if ( ( $amount + $previous_refund_amount ) >= $order->get_total() ) {
				$order->update_status( 'refunded' );
			}
		} else {
			$message = blink_pg_api_message( $data, __( 'Blink did not say why the refund failed.', 'blink-payment-gateway-for-woocommerce' ) );
			$order->add_order_note( __( 'Refund request failed: ', 'blink-payment-gateway-for-woocommerce' ) . $message );
			return new WP_Error( 'refund_failed', __( 'Refund request failed. ', 'blink-payment-gateway-for-woocommerce' ) . $message );
		}

		// Return true on successful refund.
		return true;
	}

	/**
	 * Output a Cancel Order button for a captured card payment less than 24 hours old.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function blink_add_cancel_button( $order ) {
		if ( ! $order instanceof WC_Order || 'blink' !== $order->get_payment_method() ) {
			return;
		}

		$transaction_id = blink_pg_get_charge_transaction_id( $order );

		if ( ! $transaction_id ) {
			return; // Exit if transaction ID is not found.
		}

		if ( blink_pg_check_cc_payment( $this->gateway->payment_source ) ) {
			if ( strtolower( $this->gateway->payment_status ) === 'captured' && blink_pg_get_time_diff( $order ) !== true ) {
				// If status is captured, display cancel button.
				echo '<div class="cancel-order-container">';
				echo '<button type="button" class="button cancel-order" data-order-id="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Cancel Order', 'blink-payment-gateway-for-woocommerce' ) . '</button>';
				echo '<span class="cancel-order-tooltip" data-tip="' . esc_attr__( 'It will cancel the transaction.', 'blink-payment-gateway-for-woocommerce' ) . '">' . esc_html__( 'It will cancel the transaction.', 'blink-payment-gateway-for-woocommerce' ) . '</span>';
				echo '</div>';
			}
		}
	}

	/**
	 * Decide whether the order screen shows WooCommerce's refund button.
	 *
	 * Only changes Blink orders. Hidden for a captured card payment less than 24 hours old
	 * (it can be cancelled instead), and for cancelled orders.
	 *
	 * @param bool          $render_refunds Whether refunds would be shown.
	 * @param int           $order_id       WooCommerce order ID.
	 * @param WC_Order|null $wc_order       WooCommerce order.
	 * @return bool
	 */
	public function blink_should_render_refunds( $render_refunds, $order_id, $wc_order ) {
		$wc_order = $wc_order instanceof WC_Order ? $wc_order : wc_get_order( $order_id );
		// Leave other gateways' orders alone. Versions up to 1.3.7 wrote their transaction
		// IDs to blink_res, so that meta alone doesn't make an order a Blink one.
		if ( ! $wc_order || 'blink' !== $wc_order->get_payment_method() ) {
			return $render_refunds;
		}
		$transaction_id = blink_pg_get_charge_transaction_id( $wc_order );

		if ( ! $transaction_id ) {
			return $render_refunds; // No Blink transaction, use default behavior.
		}

		$this->gateway->transaction_handler->blink_get_transaction_status( $transaction_id );

		if ( blink_pg_check_cc_payment( $this->gateway->payment_source ) ) {
			if ( strtolower( $this->gateway->payment_status ) === 'captured' ) {
				$render_refunds = false; // Hide default refund if captured.
			}
			if ( blink_pg_get_time_diff( $wc_order ) === true ) {
				$render_refunds = true;
			}
		}

		if ( $wc_order->has_status( array( 'cancelled' ) ) ) {
			$render_refunds = false;
		}

		return $render_refunds;
	}
}
