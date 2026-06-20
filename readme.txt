=== WeBirr Gateway for WooCommerce ===
Contributors: webirr
Tags: woocommerce, payments, webirr, ethiopia
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept WeBirr payments in WooCommerce using a server-side payment-code flow.

== Description ==

WeBirr Gateway for WooCommerce lets WooCommerce stores create a WeBirr payment
code for an order, show merchant-supported payment instructions, poll payment
status from the WordPress server, and complete the WooCommerce order after
WeBirr confirms payment.

Merchant ID and API key stay in WordPress admin settings and are never exposed
to browser JavaScript.

Features:

* WooCommerce payment method for classic checkout.
* WooCommerce Checkout Blocks registration when the Blocks payment API is available.
* Server-side WeBirr bill creation, bill recovery, and payment-code handling.
* Merchant-supported payment instructions from WeBirr's supported-banks API.
* Browser polling through a WordPress REST endpoint.
* Idempotent WooCommerce order completion after payment is confirmed.
* TestEnv and ProdEnv gateway modes.

== WeBirr Payment Flow ==

At a glance, the payment flow is:

1. The customer places a WooCommerce order and chooses WeBirr.
2. WooCommerce creates a pending order.
3. The plugin creates or resumes a WeBirr bill/invoice using a stable merchant reference.
4. WeBirr returns a WeBirr Payment Code.
5. The payment page displays the code and the merchant-supported banking or wallet instructions.
6. The customer pays in a supported banking or wallet app using the general path:
   {Banking App} -> WeBirr menu -> Enter Payment Code -> Pay.
7. Browser JavaScript polls WordPress for payment status.
8. WordPress checks WeBirr from the server side.
9. Once WeBirr reports the payment as paid, WooCommerce stores the payment reference and paid-via value.
10. WooCommerce completes the order and continues its normal fulfillment flow.

The payment page shows only banks and wallets returned by WeBirr for the configured merchant.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woocommerce-gateway-webirr`.
2. Activate the plugin in WordPress.
3. Go to WooCommerce -> Settings -> Payments -> WeBirr.
4. Enter your merchant ID, API key, and environment.
5. Enable the gateway.

== Frequently Asked Questions ==

= Does the browser call WeBirr APIs directly? =

No. The browser only calls the WordPress status endpoint. WordPress calls WeBirr
from the server side.

= Which payment channels are shown to customers? =

The payment page renders only the banks and wallets returned by WeBirr for the
configured merchant.

== Changelog ==

= 0.1.0 =

Initial private plugin build.
