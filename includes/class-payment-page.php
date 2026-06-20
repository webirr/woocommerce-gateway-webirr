<?php
/**
 * Plugin-owned payment-code page.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Renders the pending WeBirr payment screen.
 */
final class Payment_Page {
    /**
     * Register frontend hook.
     *
     * @return void
     */
    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_render']);
    }

    /**
     * Build payment page URL for an order.
     *
     * @param object $order WooCommerce order.
     * @return string
     */
    public static function url($order): string {
        return add_query_arg(
            [
                'webirr-pay-order' => method_exists($order, 'get_id') ? $order->get_id() : 0,
                'key' => method_exists($order, 'get_order_key') ? $order->get_order_key() : '',
            ],
            home_url('/')
        );
    }

    /**
     * Render the page when requested.
     *
     * @return void
     */
    public static function maybe_render(): void {
        $order_id = isset($_GET['webirr-pay-order']) ? absint(wp_unslash($_GET['webirr-pay-order'])) : 0;
        if ($order_id <= 0) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (!$order || !method_exists($order, 'get_order_key') || !hash_equals((string)$order->get_order_key(), $key)) {
            status_header(403);
            wp_die(esc_html__('This WeBirr payment link is invalid.', 'woocommerce-gateway-webirr'));
        }

        if (!class_exists('\\WC_Gateway_WeBirr')) {
            status_header(500);
            wp_die(esc_html__('WeBirr gateway is not available.', 'woocommerce-gateway-webirr'));
        }

        $gateway = new \WC_Gateway_WeBirr();
        $service = $gateway->get_order_service();
        $state = $service->state($order, true);
        if (empty($state['paymentCode']) && !$order->is_paid()) {
            $state = $service->prepare_payment($order);
        }

        $banks = $service->supported_banks();
        self::enqueue_assets($order, $banks);

        get_header();
        echo self::render_markup($order, $state, $banks); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        get_footer();
        exit;
    }

    /**
     * Enqueue styles and script.
     *
     * @param object $order WooCommerce order.
     * @param array<int, array<string, string>> $banks Supported banks.
     * @return void
     */
    private static function enqueue_assets($order, array $banks): void {
        wp_enqueue_style(
            'webirr-woocommerce-checkout',
            WEBIRR_WC_GATEWAY_URL . 'assets/css/frontend.css',
            [],
            WEBIRR_WC_GATEWAY_VERSION
        );

        wp_enqueue_script(
            'webirr-woocommerce-payment',
            WEBIRR_WC_GATEWAY_URL . 'assets/js/payment-status.js',
            [],
            WEBIRR_WC_GATEWAY_VERSION,
            true
        );

        wp_localize_script(
            'webirr-woocommerce-payment',
            'webirrWooCommerce',
            [
                'statusUrl' => esc_url_raw(rest_url('webirr/v1/orders/' . $order->get_id() . '/payment-status')),
                'orderKey' => method_exists($order, 'get_order_key') ? $order->get_order_key() : '',
                'nonce' => wp_create_nonce('wp_rest'),
                'successUrl' => method_exists($order, 'get_checkout_order_received_url')
                    ? $order->get_checkout_order_received_url()
                    : home_url('/'),
                'pollIntervalMs' => 5000,
                'supportedBanks' => $banks,
            ]
        );
    }

    /**
     * Build payment page HTML.
     *
     * @param object $order WooCommerce order.
     * @param array<string, mixed> $state Payment state.
     * @param array<int, array<string, string>> $banks Supported banks.
     * @return string
     */
    private static function render_markup($order, array $state, array $banks): string {
        $amount = method_exists($order, 'get_formatted_order_total') ? $order->get_formatted_order_total() : '';
        $customer = trim(
            (method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '') . ' ' .
            (method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '')
        );

        $instructions = Supported_Banks::instructions($banks);
        if ($instructions === []) {
            $instructions = [__('Use one of this merchant\'s supported WeBirr banking or wallet apps.', 'woocommerce-gateway-webirr')];
        }

        ob_start();
        ?>
        <main class="webirr-wc-shell">
            <section class="webirr-wc-panel" data-webirr-payment-panel>
                <div class="webirr-wc-brand">
                    <img src="<?php echo esc_url(WEBIRR_WC_GATEWAY_URL . 'assets/images/webirr-cute-logo.png'); ?>" alt="WeBirr" />
                    <h1><?php echo esc_html__('WeBirr Online Checkout', 'woocommerce-gateway-webirr'); ?></h1>
                </div>

                <dl class="webirr-wc-summary">
                    <dt><?php echo esc_html__('Customer', 'woocommerce-gateway-webirr'); ?></dt>
                    <dd><?php echo esc_html($customer); ?></dd>
                    <dt><?php echo esc_html__('Amount', 'woocommerce-gateway-webirr'); ?></dt>
                    <dd><?php echo wp_kses_post($amount); ?></dd>
                    <dt><?php echo esc_html__('Merchant reference', 'woocommerce-gateway-webirr'); ?></dt>
                    <dd data-webirr-merchant-reference><?php echo esc_html((string)($state['merchantReference'] ?? '')); ?></dd>
                </dl>

                <div class="webirr-wc-payment-code">
                    <div class="webirr-wc-payment-code-title"><?php echo esc_html__('WeBirr Payment Code', 'woocommerce-gateway-webirr'); ?></div>
                    <div class="webirr-wc-payment-code-value" data-webirr-payment-code>
                        <?php echo esc_html((string)($state['paymentCode'] ?? '')); ?>
                    </div>
                </div>

                <div class="webirr-wc-status webirr-wc-status-info" data-webirr-status>
                    <span class="webirr-wc-spinner" aria-hidden="true"></span>
                    <span data-webirr-status-text><?php echo esc_html__('Waiting for payment confirmation...', 'woocommerce-gateway-webirr'); ?></span>
                </div>

                <div class="webirr-wc-instructions">
                    <h2><?php echo esc_html__('Payment Instruction', 'woocommerce-gateway-webirr'); ?></h2>
                    <ul>
                        <?php foreach ($instructions as $instruction) : ?>
                            <li><?php echo esc_html($instruction); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <button type="button" class="webirr-wc-refresh" data-webirr-refresh hidden>
                    <?php echo esc_html__('Refresh', 'woocommerce-gateway-webirr'); ?>
                </button>

                <div class="webirr-wc-confirmation" data-webirr-confirmation hidden>
                    <div class="webirr-wc-check" aria-hidden="true">✓</div>
                    <h2><?php echo esc_html__('Payment Confirmed', 'woocommerce-gateway-webirr'); ?></h2>
                    <dl class="webirr-wc-summary">
                        <dt><?php echo esc_html__('Payment Reference', 'woocommerce-gateway-webirr'); ?></dt>
                        <dd data-webirr-payment-reference></dd>
                        <dt><?php echo esc_html__('Paid Via', 'woocommerce-gateway-webirr'); ?></dt>
                        <dd data-webirr-paid-via></dd>
                    </dl>
                </div>
            </section>
        </main>
        <?php
        return (string)ob_get_clean();
    }
}

