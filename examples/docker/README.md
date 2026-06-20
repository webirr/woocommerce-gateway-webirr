# WeBirr WooCommerce Docker Example

This example starts a local WordPress/WooCommerce store with the WeBirr gateway
mounted from the repository.

## Run

```bash
cp .env.example .env
# Fill WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY in .env
docker compose up -d
docker compose run --rm cli sh /scripts/bootstrap.sh
```

Open the demo product URL printed by the bootstrap script, add the product to
cart, and choose WeBirr at checkout.

The bootstrap script configures WooCommerce's generated cart and checkout pages
to use the classic shortcodes. This keeps the example screenshot flow stable.
The plugin also registers WeBirr for WooCommerce Checkout Blocks when the
Blocks payment API is available.

The example uses TestEnv credentials from environment variables. It does not
write credentials into tracked files.

## Screenshots

![WeBirr checkout payment method](screenshots/woocommerce-checkout-selection.png)

![WeBirr payment code](screenshots/woocommerce-payment-code.png)

## Notes

- The production plugin supports only `TestEnv` and `ProdEnv`.
- Mocking belongs in tests or standalone examples, not in production settings.
- Payment instructions are loaded from the merchant-supported banks endpoint.
