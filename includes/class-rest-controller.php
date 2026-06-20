<?php
/**
 * REST endpoints for WeBirr checkout.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Handles safe browser polling endpoints.
 */
final class Rest_Controller {
    /**
     * Register routes.
     *
     * @return void
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            'webirr/v1',
            '/orders/(?P<order_id>\d+)/payment-status',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'payment_status'],
                'permission_callback' => '__return_true',
                'args' => [
                    'order_id' => [
                        'validate_callback' => static function($value): bool {
                            return (int)$value > 0;
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Poll payment status for an order.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function payment_status($request) {
        $order_id = absint($request['order_id']);
        $key = sanitize_text_field((string)$request->get_param('key'));
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (!$order || !method_exists($order, 'get_order_key') || !hash_equals((string)$order->get_order_key(), $key)) {
            return new \WP_Error(
                'webirr_forbidden',
                __('This WeBirr payment status request is invalid.', 'woocommerce-gateway-webirr'),
                ['status' => 403]
            );
        }

        if (!class_exists('\\WC_Gateway_WeBirr')) {
            return new \WP_Error(
                'webirr_gateway_missing',
                __('WeBirr gateway is not available.', 'woocommerce-gateway-webirr'),
                ['status' => 500]
            );
        }

        $gateway = new \WC_Gateway_WeBirr();
        $result = $gateway->get_order_service()->check_and_complete($order);

        $safe = [
            'success' => (bool)($result['success'] ?? false),
            'complete' => (bool)($result['complete'] ?? false),
            'status' => (int)($result['status'] ?? 0),
            'paymentReference' => (string)($result['paymentReference'] ?? ''),
            'paidVia' => (string)($result['paidVia'] ?? ''),
            'error' => (string)($result['error'] ?? ''),
        ];

        return rest_ensure_response($safe);
    }
}

