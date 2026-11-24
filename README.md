# Blink Payment Gateway for WooCommerce

Take card, Apple Pay, Google Pay, open banking and direct debit payments on your WooCommerce store with [Blink](https://www.blinkpayment.co.uk/).

Each payment method has to be enabled on your Blink merchant account before it appears in the plugin. Ask Blink to set up any method you don't see.

- [Blink API documentation](https://api-docs.blinkpayment.co.uk/)
- [Your Blink API keys](https://secure.blinkpayment.co.uk/admin/customer-centre/blink-pages)
- [Sign up for a sandbox account](https://www.blinkpayment.co.uk/developer-signup)

## Requirements

- WordPress 6.5 or later
- WooCommerce 7.6 or later
- PHP 7.4 or later
- A Blink account and its API keys

## Install

1. Download the zip of the [latest release](https://github.com/Blink-Payment/Blink-WooCommerce-v2/releases/latest).
2. In WordPress admin, go to **Plugins > Add New Plugin > Upload Plugin**.
3. Choose the zip, click **Install Now**, then **Activate**.

To update, upload the new release's zip the same way and choose **Replace current with uploaded**. Your settings, API keys and orders are kept.

## Set up

1. Go to **WooCommerce > Settings > Payments** and open **Blink**.
2. Tick **Enable Blink Gateway**.
3. Enter your **Live API Key** and **Live Secret Key** from the [Blink portal](https://secure.blinkpayment.co.uk/admin/customer-centre/blink-pages). For a sandbox account, enter its keys as the **Test API Key** and **Test Secret Key** and tick **Enable Test Mode**.
4. Save. The plugin then loads the payment methods on your Blink account. Tick the ones to offer at checkout, and save again.

Other settings:

- **Checkout title** and **Description**: what the customer sees at checkout.
- **Integration Type**: **Direct** shows the card fields in your checkout. **Hosted** sends the customer to a Blink payment page. Use Hosted if your theme or other plugins stop Direct from working.
- **Accepted Card Logos**: show the Visa, Mastercard and American Express logos next to Blink at checkout.
- **Apple Pay**: follow the steps on the settings page to verify your domain with Apple, then turn Apple Pay on.
- **Preauthorise Payments**: authorise card payments at checkout and capture them later. See the [pre-authorisation guide](docs/preauthorisation.md).
- **Custom Style**: CSS for the checkout page. Don't include a `<style>` tag. It can't style the text inside the card fields; use the `blink_pg_hostedfield_css` filter below for that.
- **Debug mode**: writes logs to **WooCommerce > Status > Logs** (source `blink-payment-gateway`). Turn it on only while troubleshooting.

## Payment notifications

Blink tells your store about payment updates by posting to `https://your-store.example/wc-api/blink_gateway/`. The plugin sends this address to Blink with each payment, so there's nothing to set up. Make sure nothing blocks it, such as a firewall, maintenance mode or password protection.

The plugin doesn't trust the notification itself. Before it changes an order, it asks Blink for the payment and checks that it belongs to that order.

## Hooks for developers

| Hook | Type | Use |
| --- | --- | --- |
| `blink_pg_custom_script` | action | Runs after the plugin's checkout scripts are enqueued. Enqueue your own scripts here. |
| `blink_pg_custom_style` | action | Runs after the plugin's checkout styles are enqueued. Enqueue your own styles here. |
| `blink_pg_hostedfield_css` | filter | CSS for the text inside the card number, expiry and CVV fields. Only rules whose selector includes `.hostedfield` are used, for example `.hostedfield { color: #222; }`. Fonts must already be on the customer's device. |
| `blink_pg_get_return_url` | filter | The page Blink returns the customer to after paying. Receives the URL and the order. |
| `blink_pg_hosted_return_recheck_delay` | filter | Seconds to wait before checking a Hosted payment again when the customer comes back before Blink has confirmed it. Default `1`. |

```php
add_action( 'blink_pg_custom_script', function () {
	wp_enqueue_script( 'my-checkout-script' );
} );

add_action( 'blink_pg_custom_style', function () {
	wp_enqueue_style( 'my-checkout-style' );
} );
```

Before version 1.4.2 these hooks started with `blink_` instead of `blink_pg_`, for example `blink_custom_script`. The old names still work but are deprecated, and log a notice when `WP_DEBUG` is on.

## Building the block checkout script

`dist/blink-block.js` is built from the files in `src/`. To build it yourself, run `npm install` and then `npm run build`.

## External services

The plugin sends payment data to Blink, and the checkout loads scripts from Blink, Apple and Google. The External services section of [readme.txt](readme.txt) lists what is sent, when, and the terms and privacy policy for each service.

## Changelog

See [changelog.txt](changelog.txt).
