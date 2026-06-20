<?php
/**
 * WooCommerce Checkout Blocks payment method integration.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

if (!class_exists(AbstractPaymentMethodType::class)) {
    return;
}

/**
 * Exposes the WeBirr gateway to WooCommerce Checkout Blocks.
 */
final class Blocks_Payment_Method extends AbstractPaymentMethodType {
    /**
     * Gateway/payment method name.
     *
     * @var string
     */
    protected $name = 'webirr';

    /**
     * Initialize settings.
     *
     * @return void
     */
    public function initialize(): void {
        $settings = get_option('woocommerce_webirr_settings', []);
        $this->settings = is_array($settings) ? $settings : [];
    }

    /**
     * Whether the payment method should be available in blocks.
     *
     * @return bool
     */
    public function is_active(): bool {
        return filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN) &&
            trim((string)$this->get_setting('merchant_id', '')) !== '' &&
            trim((string)$this->get_setting('api_key', '')) !== '';
    }

    /**
     * Register frontend script.
     *
     * @return array<int, string>
     */
    public function get_payment_method_script_handles(): array {
        $handle = 'webirr-woocommerce-blocks';

        wp_register_script(
            $handle,
            WEBIRR_WC_GATEWAY_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            WEBIRR_WC_GATEWAY_VERSION,
            true
        );

        return [$handle];
    }

    /**
     * Data exposed to the block checkout frontend.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array {
        return [
            'title' => $this->get_setting('title', __('WeBirr', 'woocommerce-gateway-webirr')),
            'description' => $this->get_setting(
                'description',
                __('Pay with WeBirr using your banking or wallet app.', 'woocommerce-gateway-webirr')
            ),
            'supports' => $this->get_supported_features(),
        ];
    }
}

