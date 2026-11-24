# Pre-authorisation payments

With pre-authorisation on, the plugin authorises the customer's card at checkout and you capture the payment later from the WooCommerce order. This is useful when you only want to take payment once an order is ready, for example after confirming stock.

## How it works

```text
Customer checks out
      ↓
Blink authorises the card
      ↓
WooCommerce order: On hold
      ↓
You capture the payment
      ↓
WooCommerce order: Processing
```

## Turn it on

1. Go to **WooCommerce > Settings > Payments > Blink**.
2. Under **Payment Options**, tick **Preauthorise Payments**.
3. Save.

Pre-authorisation works with card payments only. While it's on, the plugin turns off the other payment methods (Apple Pay, Google Pay, open banking and direct debit) in its settings.

## Capture a payment

Open the order, which will be **On hold**, and either:

- choose **Capture Blink pre-authorisation** from the **Order actions** list and click the arrow next to it, or
- change the order status from **On hold** to **Processing** and click **Update**.

When the capture succeeds, the order moves to **Processing** and a "Blink pre-authorisation captured successfully" note is added.

If the capture fails, the order stays on, or goes back to, **On hold**, and a "Blink pre-authorisation capture failed" note gives Blink's reason. Try again once you've fixed the cause.

If Blink doesn't answer the capture request, the plugin can't tell whether the payment was taken. It adds an order note saying so and blocks another capture until Blink's payment notification confirms the outcome. This stops the customer being charged twice.

## Uncaptured payments

An authorisation isn't held for ever. Card schemes usually release it after 7 to 30 days, after which it can no longer be captured and the customer's funds are released.

The plugin doesn't cancel an authorisation you've decided not to capture. Cancelling the WooCommerce order doesn't contact Blink: the authorisation stays open until it expires. You can void it sooner in the Blink portal, but the WooCommerce order isn't updated when you do, so cancel the order in WooCommerce too.

## Troubleshooting

**Capture Blink pre-authorisation isn't in the Order actions list.** The action only appears when the order:

- was paid with Blink and pre-authorisation was on at checkout
- is **On hold**
- hasn't been captured already
- has no capture in progress, and no capture with an unknown outcome waiting for Blink to confirm it

**Changing the status to Processing didn't capture the payment.** The status change only captures when the order moves from **On hold** straight to **Processing**. Check the order notes for Blink's reason.

**More detail.** Turn on **Debug mode** in the Blink settings and check **WooCommerce > Status > Logs** for the `blink-payment-gateway` source.
