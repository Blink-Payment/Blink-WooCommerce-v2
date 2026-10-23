# Pre-authorisation Payments

Blink's WooCommerce plugin supports pre-authorisation payments.

Pre-authorisation allows you to authorise a customer's card first and capture the payment later.

This is useful when you want to confirm that funds are available before taking payment, for example when:

- payment should only be taken once an order is ready to process
- stock or fulfilment needs to be confirmed first
- the final decision to take payment happens after checkout

## How pre-authorisation works

When pre-authorisation is enabled, a successful checkout does not immediately capture the payment.

Instead:

1. The customer's card is authorised by Blink.
2. The WooCommerce order is placed **On hold**.
3. The merchant can later capture the authorised payment.
4. Once capture succeeds, the WooCommerce order moves to **Processing**.

In simple terms:

```text
Customer checkout
      ↓
Pre-authorisation approved
      ↓
WooCommerce: On hold
      ↓
Merchant captures payment
      ↓
WooCommerce: Processing