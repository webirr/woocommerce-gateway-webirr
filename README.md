# WeBirr Gateway for WooCommerce

Accept WeBirr payments in WooCommerce with a server-side payment-code checkout
flow.

![WeBirr WooCommerce payment code](examples/docker/screenshots/woocommerce-payment-code.png)

## Payment Flow

1. Customer chooses **WeBirr** during WooCommerce checkout.
2. WooCommerce creates a pending order.
3. The gateway creates or recovers a WeBirr bill using a stable merchant
   reference such as `wc_1_42`.
4. The customer sees the **WeBirr Payment Code**.
5. Payment instructions are shown from the merchant-supported banks returned by
   WeBirr, for example `CBE Mobile -> WeBirr -> Payment Code`.
6. The customer pays in their banking or wallet app.
7. The browser polls the WordPress status endpoint.
8. WordPress checks WeBirr from the server side and completes the WooCommerce
   order once payment is confirmed.

## Requirements

- WordPress 6.4 or newer
- WooCommerce 8.0 or newer
- PHP 7.4 or newer
- WeBirr merchant ID and API key

## Installation

1. Copy the `woocommerce-gateway-webirr` folder into
   `wp-content/plugins/woocommerce-gateway-webirr`.
2. Activate **WeBirr Gateway for WooCommerce** in WordPress.
3. Go to **WooCommerce -> Settings -> Payments -> WeBirr**.
4. Enable the gateway and set:
   - Merchant ID
   - API Key
   - Environment: `TestEnv` or `ProdEnv`
   - Optional debug logging

The plugin keeps merchant credentials on the server. Browser JavaScript never
calls WeBirr merchant APIs directly.

## Local Docker Example

The Docker example starts WordPress, installs WooCommerce, mounts this plugin,
and configures WeBirr from environment variables.

```bash
cd examples/docker
cp .env.example .env
# Fill WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY in .env
docker compose up -d
docker compose run --rm cli sh /scripts/bootstrap.sh
```

Open `http://localhost:8097` and test a checkout with the demo product.
If that port is busy, set `WEBIRR_WOOCOMMERCE_PORT` to another local port.

The Docker example switches the generated WooCommerce cart and checkout pages
to classic shortcodes so the screenshot flow validates the classic WooCommerce
payment flow. The plugin also registers WeBirr for WooCommerce Checkout Blocks
when the Blocks payment API is available.

![WeBirr payment method at checkout](examples/docker/screenshots/woocommerce-checkout-selection.png)

## Development Checks

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
```

## Packaging

Build a plugin ZIP whose top-level folder is `woocommerce-gateway-webirr`:

```bash
./scripts/build-zip.sh
```

The ZIP is created under `dist/`.
