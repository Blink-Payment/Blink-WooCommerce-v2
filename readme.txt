=== Blink Payment Gateway for WooCommerce ===
Contributors: BlinkPayment
Tags: woocommerce payment gateway, credit card, open banking, apple pay, google pay
Requires at least: 5.8
Requires Plugins: woocommerce
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.1
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

**External Services:**
This plugin connects to the Blink Payment API to process transactions. The following external services are used:
- Blink Payment API: Used to process payments, refunds, and cancellations.
  - Data sent: Transaction details, customer information, and order details.
  - Terms of Service: [https://www.blinkpayment.co.uk/terms/terms-of-service](https://www.blinkpayment.co.uk/terms/terms-of-service)
  - Privacy Policy: [https://www.blinkpayment.co.uk/terms/privacy-policy](https://www.blinkpayment.co.uk/terms/privacy-policy)
- Google Pay API: Used to enable Google Pay functionality.
  - Data sent: Payment details.
  - Terms of Service: [https://pay.google.com/about/terms/](https://pay.google.com/about/terms/)
  - Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)
- Apple Pay API: Used to enable Apple Pay functionality.
  - Data sent: Payment details.
  - Terms of Service: [https://developer.apple.com/apple-pay/](https://developer.apple.com/apple-pay/)
  - Privacy Policy: [https://www.apple.com/legal/privacy/](https://www.apple.com/legal/privacy/)

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/blink-payment` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Settings > Payments and enable the "Blink Payment Gateway."
4. Configure the API keys and other settings in the Blink Payment Gateway settings page.

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

== Screenshots ==

1. Blink Payment Gateway settings page.
2. Blink Payment options on the checkout page.
3. Apple Pay and Google Pay buttons on the checkout page.

== Changelog ==

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

== External Services ==

This plugin connects to the following external services:

1. **Blink Payment API**:
   - Purpose: To process payments, refunds, and cancellations.
   - Data sent: Transaction details, customer information, and order details.
   - Terms of Service: [https://www.blinkpayment.co.uk/terms/terms-of-service](https://www.blinkpayment.co.uk/terms/terms-of-service)
   - Privacy Policy: [https://www.blinkpayment.co.uk/terms/privacy-policy](https://www.blinkpayment.co.uk/terms/privacy-policy)

2. **Google Pay API**:
   - Purpose: To enable Google Pay functionality.
   - Data sent: Payment details.
   - Terms of Service: [https://pay.google.com/about/terms/](https://pay.google.com/about/terms/)
   - Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

3. **Apple Pay API**:
   - Purpose: To enable Apple Pay functionality.
   - Data sent: Payment details.
   - Terms of Service: [https://developer.apple.com/apple-pay/](https://developer.apple.com/apple-pay/)
   - Privacy Policy: [https://www.apple.com/legal/privacy/](https://www.apple.com/legal/privacy/)

== Notes ==

- Ensure that you have valid API keys from Blink Payment to use this plugin.
- For Apple Pay and Google Pay, additional setup may be required. Refer to the Blink Payment documentation for details.
- If you encounter any issues, please contact [support@blinkpayment.co.uk](mailto:support@blinkpayment.co.uk).