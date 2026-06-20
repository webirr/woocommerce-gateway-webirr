#!/bin/sh
set -eu

SITE_URL="http://localhost:${WEBIRR_WOOCOMMERCE_PORT:-8097}"

until wp core is-installed >/dev/null 2>&1; do
  if wp core install \
    --url="$SITE_URL" \
    --title="WeBirr WooCommerce Demo" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.test \
    --skip-email >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

wp plugin install woocommerce --activate
wp plugin activate woocommerce-gateway-webirr

CHECKOUT_PAGE_ID=$(wp option get woocommerce_checkout_page_id)
CART_PAGE_ID=$(wp option get woocommerce_cart_page_id)
if [ -n "$CHECKOUT_PAGE_ID" ]; then
  wp post update "$CHECKOUT_PAGE_ID" --post_content='[woocommerce_checkout]' >/dev/null
fi
if [ -n "$CART_PAGE_ID" ]; then
  wp post update "$CART_PAGE_ID" --post_content='[woocommerce_cart]' >/dev/null
fi

wp eval '
$settings = [
  "enabled" => "yes",
  "title" => "WeBirr",
  "description" => "Pay with WeBirr using your banking or wallet app.",
  "merchant_id" => getenv("WEBIRR_TEST_ENV_MERCHANT_ID") ?: "",
  "api_key" => getenv("WEBIRR_TEST_ENV_API_KEY") ?: "",
  "environment" => "TestEnv",
  "debug" => "no",
  "paid_order_status" => "",
];
$existing = get_option("woocommerce_webirr_settings", []);
if (!is_array($existing)) {
  delete_option("woocommerce_webirr_settings");
}
update_option("woocommerce_webirr_settings", $settings);
echo "Configured WeBirr settings\n";
'

PRODUCT_ID=$(wp post list --post_type=product --name=webirr-demo-audio-book --field=ID --format=ids)
if [ -z "$PRODUCT_ID" ]; then
  PRODUCT_ID=$(wp post create \
    --post_type=product \
    --post_status=publish \
    --post_title="Sample Audio Book" \
    --post_name=webirr-demo-audio-book \
    --porcelain)
fi

wp post meta update "$PRODUCT_ID" _regular_price "640"
wp post meta update "$PRODUCT_ID" _price "640"
wp post meta update "$PRODUCT_ID" _stock_status "instock"
wp post meta update "$PRODUCT_ID" _virtual "yes"
wp post meta update "$PRODUCT_ID" _sold_individually "yes"

wp rewrite flush

echo "Demo product: $SITE_URL/?add-to-cart=$PRODUCT_ID"
echo "Admin: $SITE_URL/wp-admin admin/admin"
