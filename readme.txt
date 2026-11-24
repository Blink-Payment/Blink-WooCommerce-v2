=== Blink Payment Gateway for WooCommerce ===
Contributors: blinkpayment
Tags: woocommerce payment gateway, credit card, open banking, apple pay, google pay
Requires at least: 6.5
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take credit card, direct debit, Apple Pay, and Google Pay payments on your WooCommerce store using Blink Payment Gateway.

== Description ==

The Blink Payment Gateway plugin allows WooCommerce store owners to accept payments via credit cards, direct debit, Apple Pay, and Google Pay. It provides a seamless checkout experience for customers and supports refunds, preauthorization payments, and other WooCommerce features.

**Features:**
- Accept payments via credit cards, direct debit, Apple Pay, and Google Pay.
- Supports WooCommerce Blocks for Cart and Checkout.
- Securely processes payments using the Blink Payment API.
- Supports refunds directly from the WooCommerce admin panel.
- **Preauthorization (Preauth) payments** - Authorize payments at checkout and capture funds later.
- **Manual capture** - Control when to charge customers by changing order status.
- **Rerun API integration** - Automatically capture preauthorized funds when orders are processed.
- Fully compatible with the latest version of WooCommerce.

The plugin sends payment data to Blink and loads payment scripts from Blink, Apple and Google. See External services below for what is sent and when.

== Installation ==

= Requirements =

* WordPress 6.5 or later
* WooCommerce 7.6 or later
* PHP 7.4 or later
* A Blink account and its API keys

= Install from the plugin directory =

1. In WordPress admin, go to Plugins > Add New Plugin and search for "Blink Payment Gateway for WooCommerce".
2. Click Install Now, then Activate.
3. Go to WooCommerce > Settings > Payments and enable Blink.
4. Enter your Blink API keys and choose your payment methods.

To install by hand instead, upload the plugin zip at Plugins > Add New Plugin > Upload Plugin, or copy the `blink-payment-gateway-for-woocommerce` folder to `/wp-content/plugins/`. Then follow steps 2 to 4.

= Moving from the GitHub version =

If you installed Blink from GitHub, its folder is usually `Blink-WooCommerce-v2-master`. Remove it before you install from the directory:

1. Go to Plugins. Deactivate the Blink Payment Gateway for WooCommerce plugin, then delete it.
2. Install and activate the plugin from the directory, as above.

Your Blink settings, API keys and orders are kept. Don't run the GitHub version and this one at the same time: checkout stops working.

== Frequently Asked Questions ==

= Does this plugin support refunds? =
Yes, you can process refunds directly from the WooCommerce admin panel.

= Is this plugin compatible with WooCommerce Blocks? =
Yes, the plugin supports WooCommerce Blocks for Cart and Checkout.

= What payment methods are supported? =
The plugin supports credit cards, direct debit, Apple Pay, and Google Pay.

= Is this plugin secure? =
Yes, the plugin uses the Blink Payment API to securely process payments. All sensitive data is handled securely and in compliance with industry standards.

= What are preauthorization (preauth) payments? =
Preauth payments allow you to authorize a customer's payment method at checkout without immediately charging them. The funds are reserved and can be captured later when you're ready to fulfill the order. This is useful for businesses that need to verify inventory or prepare orders before charging customers.

= How do preauth payments work? =
1. Enable preauth in the Blink Payment Gateway settings
2. Customers complete checkout - their payment method is authorized but not charged
3. Orders are placed in "On Hold" status with a preauth note
4. When ready to fulfill, change the order status to "Processing"
5. The system automatically captures the preauthorized funds using the rerun API

= Can I use preauth with all payment methods? =
No, preauth is only available with credit card payments. When preauth is enabled, other payment methods (direct debit, Apple Pay, Google Pay) are automatically disabled to ensure compatibility.

= What happens if I don't capture a preauth payment? =
Preauthorized funds are typically held for a limited time (usually 7-30 days depending on your payment processor). If not captured within this period, the authorization will expire and the funds will be released back to the customer.

= Where is the source code for the block checkout script? =
The block checkout script, `dist/blink-block.js`, is built from the files in the plugin's `src/` folder. To build it yourself, run `npm install` and then `npm run build` in the plugin folder.

== Screenshots ==

1. Blink Payment Gateway settings page.
2. Blink Payment options on the checkout page.
3. Apple Pay and Google Pay buttons on the checkout page.

== Changelog ==

= 1.4.2 =
* Debug logs now go to WooCommerce › Status › Logs and no longer contain customer details. Log files from older versions are deleted, as they could be read over the web.
* Improved security of block checkout payment field loading, Apple Pay setup and webhook handling.
* Block checkout card fields now reload after the order total changes, for example when a coupon is applied. Before, the shopper had to reload the page to pay by card.
* Block checkout card fields now load on sites installed in a subfolder or using plain permalinks.
* The pay-for-order page no longer shows a critical error for an order that doesn't exist.
* On the classic checkout and the pay-for-order page, Blink no longer takes over the Place order button when the shopper picks another payment method. Before, gateways that open their own popup from that button, such as Klarna, failed with an error.
* On the pay-for-order page, choosing another payment method no longer leaves the form greyed out with a Pay for order button that can't be clicked.
* WooCommerce's Refund button shows again on orders paid through other gateways. Versions up to 1.3.7 tagged those orders as Blink ones, which hid it. On Blink orders, the button now also shows when Blink can't be reached to check the payment.
* The plugin no longer changes the timeout of other plugins' and WordPress's HTTP requests. Its own requests to Blink wait up to 30 seconds, so a slow cancel or refund is less likely to fail.
* If Blink doesn't answer a Cancel Order request, the plugin checks the payment with Blink and cancels the order if Blink has cancelled it, instead of noting that the cancel failed.
* Cancel and refund errors from Blink show Blink's reason, without PHP warnings.
* The block checkout script can be cached by browsers.
* Now requires WordPress 6.5 or later and WooCommerce 7.6 or later. The installation steps cover moving from the GitHub version.
* For developers: the plugin's functions, classes, hooks, options and REST route now start with blink_pg_ (REST: blink-payment-gateway/v1). The old hook names still work but are deprecated; use blink_pg_get_return_url, blink_pg_hostedfield_css, blink_pg_custom_script, blink_pg_custom_style and blink_pg_hosted_return_recheck_delay instead.

= 1.4.1 =
* Improved webhook verification.
* Improved payment return verification.
* Hosted payment retries reuse the order's open Paylink, and a returning customer is told when a payment is still being confirmed.
* A declined Hosted payment no longer fails the order: it stays pending so the customer can pay again on the same Paylink.
* The card form no longer shows the email, address or postcode fields when the store's theme styles form fields.
* Card form fields line up on classic checkout and the pay-for-order page, with matching sizes and fonts on mobile, and the plugin's styles no longer change the rest of the checkout page.

= 1.4.0 =
* Apple Pay available on checkout for Windows devices.
* Decline reasons show on customer checkout.
* Additional capture option for Preauthorised orders.
* Card scheme logos optionally shown at checkout.
* Cleaner font for card fields.
* Improved UI of admin settings.
* Removed unnecessary information from merchant_data.
* Declared compatibility with WooCommerce High-Performance Order Storage (HPOS).
* Default checkout title changed to "Blink Payment" (existing saved titles are unchanged).


= 1.3.7 =
* Fixed WooCommerce mapping orders for failed preauth transactions

= 1.3.6 =
* Fixed Blink card fields not reloading after checkout total changes from £0.00 back to a payable amount.
* Fixed malformed checkout layout caused by nested Blink card form markup.
* Improved hosted card field reinitialisation during WooCommerce checkout updates.
* Improved validation for missing Blink payment tokens during card checkout.

= 1.3.5 =

* Removed phone number requirement from Paylink API in Hosted version.
* Reattempting failed errors update order successfully.

= 1.3.4 =

* Fix to update status for reattempted order

= 1.3.3 =

* Added handling for nonce except wp session.

= 1.3.2 =

* Added No-Cache Headers to blink-challenge pages.

= 1.3.1 =

* Fix for duplicate email receipts
* Optimise checkout flow

= 1.3.0 =
* Added preauthorization (preauth) payments support.
* Added manual capture functionality for preauth orders.
* Added automatic rerun API integration for capturing preauthorized funds.
* Added preauth notice on WooCommerce Blocks checkout.
* Enhanced order status handling for preauth transactions.
* Improved payment method filtering when preauth is enabled.

= 1.1.0 =
* Added support for Apple Pay and Google Pay.
* Improved compatibility with WooCommerce Blocks.
* Enhanced security and validation for payment fields.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Upgrade to this version to enable preauthorization payments, manual capture functionality, and automatic rerun API integration for better payment control and order management.

= 1.1.0 =
Upgrade to this version to enable Apple Pay and Google Pay support and improve compatibility with WooCommerce Blocks.

== External services ==

The plugin needs these services to take payments. They are only contacted once the gateway is enabled and its API keys are saved.

= Blink Payment API (secure.blinkpayment.co.uk) =

Blink processes the payments. The plugin's server talks to Blink:

* When a shopper opens the checkout or pay-for-order page, and when the cart total changes: the order or cart total, currency and order ID, to create a payment intent.
* When a shopper places an order: their billing name, email, address and postcode, their IP address, their browser's user agent, language, time zone and screen size (Blink uses these for 3D Secure), and the order ID and total. A card payment sends a one-time card token, not the card number. A direct debit sends the account holder's name, account number and sort code. An open banking payment sends the name, email and address.
* When a shopper uses Hosted checkout: the order total, billing name, email, address and postcode, to create a Blink payment page. The shopper is sent to that page, on Blink, to pay.
* When an admin refunds, cancels or captures a payment, or opens an order paid with Blink: the Blink transaction ID and, for a refund or capture, the amount.
* When Blink sends a payment notification, and when a shopper returns from paying: the transaction ID, to confirm the payment with Blink.
* Each time it needs an access token: the store's Blink API key and secret, the site name, and the WordPress, WooCommerce and plugin versions.
* When an admin enables Apple Pay: the site's domain name, to register it with Apple through Blink.

On the checkout and pay-for-order pages, the shopper's browser also loads Blink's Apple Pay and Google Pay scripts from this domain when those methods are offered.

During 3D Secure, Blink may send the shopper to their card issuer's authentication page. During an open banking payment, they are sent to their bank.

* Terms and conditions: https://www.blinkpayment.co.uk/terms-conditions/
* Privacy policy: https://www.blinkpayment.co.uk/privacy-policy/

= Blink hosted card fields (gateway2.blinkpayment.co.uk) =

On the cart, checkout and pay-for-order pages, the shopper's browser loads Blink's card fields script from this domain. The card number, expiry date and security code are typed into fields hosted by Blink, so they go from the shopper's browser straight to Blink and never reach the store's server. For PCI DSS compliance, this script has to come from Blink and can't be included in the plugin.

* Terms and conditions: https://www.blinkpayment.co.uk/terms-conditions/
* Privacy policy: https://www.blinkpayment.co.uk/privacy-policy/

= Apple Pay (applepay.cdn-apple.com) =

Only when Apple Pay is enabled in the plugin's settings. The shopper's browser loads Apple's Apple Pay script on the checkout and pay-for-order pages, and a shopper who pays with Apple Pay shares their payment details with Apple as part of that payment.

* Terms and conditions: https://developer.apple.com/terms/apple-pay-web/
* Privacy policy: https://www.apple.com/legal/privacy/data/en/apple-pay/

= Google Pay (pay.google.com) =

Only when Google Pay is offered at checkout. The shopper's browser loads Google's Google Pay script on the checkout and pay-for-order pages, and a shopper who pays with Google Pay shares their payment details with Google as part of that payment.

* Terms of service: https://payments.developers.google.com/terms/sellertos
* Privacy policy: https://policies.google.com/privacy

== Notes ==

- Ensure that you have valid API keys from Blink Payment to use this plugin.
- For Apple Pay and Google Pay, additional setup may be required. Refer to the Blink Payment documentation for details.
- If you encounter any issues, please contact [support@blinkpayment.co.uk](mailto:support@blinkpayment.co.uk).