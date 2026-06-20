<?php
/**
 * WooCommerce Checkout Blocks support.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Registers WeBirr with WooCommerce Checkout Blocks when available.
 */
final class Blocks_Support {
    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            [self::class, 'register_payment_method']
        );
    }

    /**
     * Register the payment method integration.
     *
     * @param mixed $payment_method_registry WooCommerce Blocks registry.
     * @return void
     */
    public static function register_payment_method($payment_method_registry): void {
        if (!class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
            return;
        }

        require_once WEBIRR_WC_GATEWAY_DIR . 'includes/class-blocks-payment-method.php';

        if (class_exists(__NAMESPACE__ . '\\Blocks_Payment_Method') && method_exists($payment_method_registry, 'register')) {
            $payment_method_registry->register(new Blocks_Payment_Method());
        }
    }
}

