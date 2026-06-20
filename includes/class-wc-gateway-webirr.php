<?php
/**
 * WooCommerce payment gateway class.
 *
 * @package WeBirr\WooCommerceGateway
 */

defined('ABSPATH') || exit;

use WeBirr\WooCommerceGateway\Client;
use WeBirr\WooCommerceGateway\Logger;
use WeBirr\WooCommerceGateway\Order_Service;
use WeBirr\WooCommerceGateway\Payment_Page;

if (!class_exists('WC_Payment_Gateway')) {
    return;
}

/**
 * WooCommerce gateway implementation for WeBirr.
 */
class WC_Gateway_WeBirr extends WC_Payment_Gateway {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id = 'webirr';
        $this->method_title = __('WeBirr', 'woocommerce-gateway-webirr');
        $this->method_description = __(
            'Accept WeBirr payments using a server-side payment code flow.',
            'woocommerce-gateway-webirr'
        );
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->icon = WEBIRR_WC_GATEWAY_URL . 'assets/images/webirr-cute-logo.png';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('WeBirr', 'woocommerce-gateway-webirr'));
        $this->description = $this->get_option(
            'description',
            __('Pay with WeBirr using your banking or wallet app.', 'woocommerce-gateway-webirr')
        );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    /**
     * Settings fields.
     *
     * @return void
     */
    public function init_form_fields(): void {
        $paid_status_options = [
            '' => __('Use WooCommerce default', 'woocommerce-gateway-webirr'),
        ];

        if (function_exists('wc_get_order_statuses')) {
            foreach (wc_get_order_statuses() as $status_key => $status_label) {
                $paid_status_options[str_replace('wc-', '', $status_key)] = $status_label;
            }
        }

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce-gateway-webirr'),
                'type' => 'checkbox',
                'label' => __('Enable WeBirr payments', 'woocommerce-gateway-webirr'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'woocommerce-gateway-webirr'),
                'type' => 'text',
                'default' => __('WeBirr', 'woocommerce-gateway-webirr'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce-gateway-webirr'),
                'type' => 'textarea',
                'default' => __('Pay with WeBirr using your banking or wallet app.', 'woocommerce-gateway-webirr'),
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'woocommerce-gateway-webirr'),
                'type' => 'text',
                'default' => '',
            ],
            'api_key' => [
                'title' => __('API Key', 'woocommerce-gateway-webirr'),
                'type' => 'password',
                'default' => '',
            ],
            'environment' => [
                'title' => __('Environment', 'woocommerce-gateway-webirr'),
                'type' => 'select',
                'default' => 'TestEnv',
                'options' => [
                    'TestEnv' => __('TestEnv', 'woocommerce-gateway-webirr'),
                    'ProdEnv' => __('ProdEnv', 'woocommerce-gateway-webirr'),
                ],
            ],
            'debug' => [
                'title' => __('Debug logging', 'woocommerce-gateway-webirr'),
                'type' => 'checkbox',
                'label' => __('Enable WooCommerce logs for WeBirr diagnostics', 'woocommerce-gateway-webirr'),
                'default' => 'no',
            ],
            'paid_order_status' => [
                'title' => __('Paid order status', 'woocommerce-gateway-webirr'),
                'type' => 'select',
                'default' => '',
                'options' => $paid_status_options,
            ],
        ];
    }

    /**
     * Gateway is available only when enabled and configured.
     *
     * @return bool
     */
    public function is_available(): bool {
        if (!parent::is_available()) {
            return false;
        }

        return trim((string)$this->get_option('merchant_id')) !== '' &&
            trim((string)$this->get_option('api_key')) !== '';
    }

    /**
     * Render a compact gateway icon in checkout.
     *
     * @return string
     */
    public function get_icon(): string {
        return sprintf(
            '<img src="%1$s" alt="%2$s" style="max-height:32px;width:auto;" />',
            esc_url($this->icon),
            esc_attr__('WeBirr', 'woocommerce-gateway-webirr')
        );
    }

    /**
     * Process WooCommerce checkout.
     *
     * @param int $order_id Order ID.
     * @return array<string, string>|void
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Unable to load the WooCommerce order.', 'woocommerce-gateway-webirr'), 'error');
            return;
        }

        $result = $this->get_order_service()->prepare_payment($order);
        if (empty($result['success'])) {
            wc_add_notice(
                sprintf(
                    /* translators: %s is the payment error. */
                    __('Unable to start WeBirr payment: %s', 'woocommerce-gateway-webirr'),
                    esc_html((string)($result['error'] ?? 'unknown error'))
                ),
                'error'
            );
            return;
        }

        if (method_exists($order, 'update_status') && !$order->is_paid()) {
            $order->update_status('on-hold', __('Awaiting WeBirr payment.', 'woocommerce-gateway-webirr'));
        }

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        return [
            'result' => 'success',
            'redirect' => Payment_Page::url($order),
        ];
    }

    /**
     * Create an order service.
     *
     * @return Order_Service
     */
    public function get_order_service(): Order_Service {
        return new Order_Service($this->get_client(), (string)$this->get_option('paid_order_status', ''));
    }

    /**
     * Create a WeBirr client.
     *
     * @return Client
     */
    public function get_client(): Client {
        $api_key = (string)$this->get_option('api_key', '');
        $logger = new Logger($this->get_option('debug', 'no') === 'yes', $api_key);

        return new Client(
            (string)$this->get_option('merchant_id', ''),
            $api_key,
            (string)$this->get_option('environment', 'TestEnv'),
            $logger
        );
    }
}
