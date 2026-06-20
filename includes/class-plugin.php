<?php
/**
 * Plugin bootstrap.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Wires the gateway into WordPress and WooCommerce.
 */
final class Plugin {
    /**
     * Start the plugin after WordPress loads other plugins.
     *
     * @return void
     */
    public static function init(): void {
        load_plugin_textdomain(
            'woocommerce-gateway-webirr',
            false,
            dirname(plugin_basename(WEBIRR_WC_GATEWAY_FILE)) . '/languages'
        );

        if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', [self::class, 'woocommerce_missing_notice']);
            return;
        }

        require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-wc-gateway-webirr.php';

        add_filter('woocommerce_payment_gateways', [self::class, 'register_gateway']);

        Payment_Page::init();
        Rest_Controller::init();
        Blocks_Support::init();
    }

    /**
     * Declare compatibility with WooCommerce features.
     *
     * @return void
     */
    public static function declare_woocommerce_features(): void {
        if (!class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WEBIRR_WC_GATEWAY_FILE,
            true
        );
    }

    /**
     * Register the WeBirr gateway.
     *
     * @param array<int, string> $gateways Existing payment gateway classes.
     * @return array<int, string>
     */
    public static function register_gateway(array $gateways): array {
        $gateways[] = 'WC_Gateway_WeBirr';
        return $gateways;
    }

    /**
     * Show a clear admin notice when WooCommerce is not active.
     *
     * @return void
     */
    public static function woocommerce_missing_notice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'WeBirr Gateway for WooCommerce requires WooCommerce to be installed and active.',
            'woocommerce-gateway-webirr'
        );
        echo '</p></div>';
    }
}
