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
