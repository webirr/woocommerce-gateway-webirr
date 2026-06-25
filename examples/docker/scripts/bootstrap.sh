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

FIRST_PRODUCT_ID=""
seed_product() {
  slug="$1"
  title="$2"
  price="$3"

  product_id=$(wp post list --post_type=product --name="$slug" --field=ID --format=ids)
  if [ -z "$product_id" ]; then
    product_id=$(wp post create \
      --post_type=product \
      --post_status=publish \
      --post_title="$title" \
      --post_name="$slug" \
      --post_content="Digital audio book purchase." \
      --porcelain)
  fi

  wp post meta update "$product_id" _regular_price "$price" >/dev/null
  wp post meta update "$product_id" _price "$price" >/dev/null
  wp post meta update "$product_id" _stock_status "instock" >/dev/null
  wp post meta update "$product_id" _virtual "yes" >/dev/null
  wp post meta update "$product_id" _downloadable "yes" >/dev/null
  wp post meta update "$product_id" _sold_individually "yes" >/dev/null

  if [ -z "$FIRST_PRODUCT_ID" ]; then
    FIRST_PRODUCT_ID="$product_id"
  fi
}

seed_product webirr-modern-business-audio-book "Modern Business Audio Book" "640"
seed_product webirr-leadership-field-notes "Leadership Field Notes" "580"
seed_product webirr-practical-finance-basics "Practical Finance Basics" "720"
seed_product webirr-startup-operations-guide "Startup Operations Guide" "690"
seed_product webirr-customer-service-playbook "Customer Service Playbook" "510"
seed_product webirr-digital-commerce-lessons "Digital Commerce Lessons" "760"
seed_product webirr-project-delivery-habits "Project Delivery Habits" "550"
seed_product webirr-retail-growth-stories "Retail Growth Stories" "615"
seed_product webirr-resilient-teams "Resilient Teams" "675"
seed_product webirr-merchant-payments-101 "Merchant Payments 101" "705"

wp rewrite flush

echo "Demo product: $SITE_URL/?add-to-cart=$FIRST_PRODUCT_ID"
echo "Admin: $SITE_URL/wp-admin admin/admin"
