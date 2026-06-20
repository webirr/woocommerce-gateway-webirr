<?php
/**
 * Plugin Name: WeBirr Gateway for WooCommerce
 * Plugin URI: https://webirr.net/
 * Description: Accept WeBirr payments in WooCommerce using a server-side payment-code checkout flow.
 * Version: 0.1.0
 * Author: WeBirr
 * Author URI: https://webirr.net/
 * Text Domain: woocommerce-gateway-webirr
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.8
 * Requires Plugins: woocommerce
 *
 * @package WeBirr\WooCommerceGateway
 */

defined('ABSPATH') || exit;

define('WEBIRR_WC_GATEWAY_VERSION', '0.1.0');
define('WEBIRR_WC_GATEWAY_FILE', __FILE__);
define('WEBIRR_WC_GATEWAY_DIR', plugin_dir_path(__FILE__));
define('WEBIRR_WC_GATEWAY_URL', plugin_dir_url(__FILE__));

require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-logger.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-response-normalizer.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-client.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-supported-banks.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-order-service.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-payment-page.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-rest-controller.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-blocks-support.php';
require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-plugin.php';

add_action(
    'before_woocommerce_init',
    ['WeBirr\\WooCommerceGateway\\Plugin', 'declare_woocommerce_features']
);

add_action(
    'plugins_loaded',
    ['WeBirr\\WooCommerceGateway\\Plugin', 'init']
);
