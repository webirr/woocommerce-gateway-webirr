<?php
/**
 * WooCommerce order orchestration for WeBirr payments.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Owns merchant reference, bill lifecycle, and idempotent completion.
 */
final class Order_Service {
    public const META_MERCHANT_REFERENCE = '_webirr_merchant_reference';
    public const META_PAYMENT_CODE = '_webirr_payment_code';
    public const META_PAYMENT_STATUS = '_webirr_payment_status';
    public const META_PAYMENT_REFERENCE = '_webirr_payment_reference';
    public const META_PAID_VIA = '_webirr_paid_via';
    public const META_COMPLETED_AT = '_webirr_completed_at';
    public const META_LAST_ERROR = '_webirr_last_error';

    private const GATEWAY_UNAVAILABLE_MESSAGE = 'WeBirr gateway is not available.';

    private const COMPLETION_LOCK_TTL_SECONDS = 120;

    /** @var Client */
    private Client $client;

    /** @var string */
    private string $paid_order_status;

    /**
     * @param Client $client WeBirr client.
     * @param string $paid_order_status Optional final status.
     */
    public function __construct(Client $client, string $paid_order_status = '') {
        $this->client = $client;
        $this->paid_order_status = $paid_order_status;
    }

    /**
     * Prepare a WeBirr payment code for an order.
     *
     * @param object $order WooCommerce order.
     * @return array<string, mixed>
     */
    public function prepare_payment($order): array {
        try {
            $merchant_reference = $this->ensure_merchant_reference($order);
            $bill = $this->build_bill_from_order($order, $merchant_reference);
            $payment_code = $this->get_meta($order, self::META_PAYMENT_CODE);

            if ($payment_code !== '') {
                $this->maybe_update_existing_bill($order, $payment_code, $bill);
                return $this->state($order, true);
            }

            $recovered = $this->client->get_bill_by_reference($merchant_reference);
            $recovery_error = Response_Normalizer::error($recovered);
            if ($recovery_error === '') {
                $recovered_code = Response_Normalizer::payment_code($recovered);
                if ($recovered_code !== '') {
                    $this->set_meta($order, self::META_PAYMENT_CODE, $recovered_code);
                    $this->set_meta($order, self::META_PAYMENT_STATUS, (string)Response_Normalizer::payment_status($recovered));

                    if (!Response_Normalizer::is_paid($recovered) && $this->bill_details_changed($recovered, $bill)) {
                        $updated = $this->client->update_bill($bill);
                        $updated_error = Response_Normalizer::error($updated);
                        if ($updated_error !== '') {
                            return $this->failure($order, $updated_error);
                        }
                    }

                    $this->save($order);
                    return $this->state($order, true);
                }

                return $this->failure($order, 'WeBirr did not return a payment code for the recovered bill.');
            }

            $created = $this->client->create_bill($bill);
            $created_error = Response_Normalizer::error($created);
            if ($created_error !== '') {
                return $this->failure($order, $created_error);
            }

            $created_code = Response_Normalizer::payment_code($created);
            if ($created_code === '') {
                return $this->failure($order, 'WeBirr did not return a payment code.');
            }

            $this->set_meta($order, self::META_PAYMENT_CODE, $created_code);
            $this->set_meta($order, self::META_PAYMENT_STATUS, '0');
            $this->set_meta($order, self::META_LAST_ERROR, '');
            $this->add_note($order, 'WeBirr payment code created.');
            $this->save($order);

            return $this->state($order, true);
        } catch (\RuntimeException $exception) {
            return $this->failure($order, self::GATEWAY_UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * Poll WeBirr and complete the order when paid.
     *
     * @param object $order WooCommerce order.
     * @return array<string, mixed>
     */
    public function check_and_complete($order): array {
        $payment_code = $this->get_meta($order, self::META_PAYMENT_CODE);
        if ($payment_code === '') {
            return $this->failure($order, 'Missing WeBirr payment code.');
        }

        try {
            $status = $this->client->get_payment_status($payment_code);
            $error = Response_Normalizer::error($status);
            if ($error !== '') {
                return $this->failure($order, $error);
            }
        } catch (\RuntimeException $exception) {
            return $this->failure($order, self::GATEWAY_UNAVAILABLE_MESSAGE);
        }

        $status_value = Response_Normalizer::payment_status($status);
        $this->set_meta($order, self::META_PAYMENT_STATUS, (string)$status_value);

        if ($status_value === 2) {
            $this->complete_order_if_paid($order, $status);
        } else {
            $this->save($order);
        }

        return $this->state($order, true);
    }

    /**
     * Complete the order exactly once if the gateway says paid.
     *
     * @param object $order WooCommerce order.
     * @param mixed $status_response Gateway status response.
     * @return void
     */
    public function complete_order_if_paid($order, $status_response): void {
        if (!Response_Normalizer::is_paid($status_response)) {
            return;
        }

        $payment_reference = Response_Normalizer::payment_reference($status_response);
        $paid_via = Response_Normalizer::payment_issuer($status_response);
        $paid_at = Response_Normalizer::payment_date($status_response);
        if ($paid_at === '') {
            $paid_at = $this->current_time();
        }

        $already_completed = $this->get_meta($order, self::META_COMPLETED_AT) !== '';
        $already_paid = method_exists($order, 'is_paid') && $order->is_paid();
        $needs_completion = !$already_completed && !$already_paid;
        $lock_acquired = false;

        if ($needs_completion) {
            $lock_acquired = $this->acquire_completion_lock($order, $payment_reference);
            if (!$lock_acquired) {
                return;
            }
        }

        try {
            if ($payment_reference !== '') {
                $this->set_meta($order, self::META_PAYMENT_REFERENCE, $payment_reference);
                if (method_exists($order, 'set_transaction_id')) {
                    $order->set_transaction_id($payment_reference);
                }
            }

            if ($paid_via !== '') {
                $this->set_meta($order, self::META_PAID_VIA, $paid_via);
            }

            if ($needs_completion) {
                if (method_exists($order, 'payment_complete')) {
                    $order->payment_complete($payment_reference);
                }

                if ($this->paid_order_status !== '' && method_exists($order, 'update_status')) {
                    $order->update_status($this->paid_order_status, 'WeBirr payment confirmed.');
                }

                $this->set_meta($order, self::META_COMPLETED_AT, $paid_at);
                $this->add_note($order, 'WeBirr payment confirmed.');
            } elseif (!$already_completed) {
                $this->set_meta($order, self::META_COMPLETED_AT, $paid_at);
            }

            $this->set_meta($order, self::META_PAYMENT_STATUS, '2');
            $this->set_meta($order, self::META_LAST_ERROR, '');
            $this->save($order);
        } finally {
            if ($lock_acquired) {
                $this->release_completion_lock($order, $payment_reference);
            }
        }
    }

    /**
     * Fetch and normalize supported banks.
     *
     * @return array<int, array{bankid: string, bankID: string, name: string}>
     */
    public function supported_banks(): array {
        $response = $this->client->get_supported_banks();
        if (Response_Normalizer::error($response) !== '') {
            return [];
        }

        return Supported_Banks::from_response($response);
    }

    /**
     * Return current safe order payment state.
     *
     * @param object $order WooCommerce order.
     * @param bool $success Whether the operation succeeded.
     * @param string $error Error message.
     * @return array<string, mixed>
     */
    public function state($order, bool $success = true, string $error = ''): array {
        $status = (int)$this->get_meta($order, self::META_PAYMENT_STATUS);
        $complete = $status === 2 || (method_exists($order, 'is_paid') && $order->is_paid());

        return [
            'success' => $success,
            'complete' => $complete,
            'status' => $complete ? 2 : $status,
            'paymentCode' => $this->get_meta($order, self::META_PAYMENT_CODE),
            'merchantReference' => $this->get_meta($order, self::META_MERCHANT_REFERENCE),
            'paymentReference' => $this->get_meta($order, self::META_PAYMENT_REFERENCE),
            'paidVia' => $this->get_meta($order, self::META_PAID_VIA),
            'error' => $error,
        ];
    }

    /**
     * Build or return the stable merchant reference.
     *
     * @param object $order WooCommerce order.
     * @return string
     */
    public function ensure_merchant_reference($order): string {
        $reference = $this->get_meta($order, self::META_MERCHANT_REFERENCE);
        if ($reference !== '') {
            return $reference;
        }

        $site_id = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;
        $order_id = method_exists($order, 'get_id') ? (int)$order->get_id() : 0;
        $reference = 'wc_' . $site_id . '_' . $order_id . '_' . $this->uuid();

        if (function_exists('apply_filters')) {
            $reference = (string)apply_filters('webirr_woocommerce_merchant_reference', $reference, $order);
        }

        $this->set_meta($order, self::META_MERCHANT_REFERENCE, $reference);
        $this->save($order);

        return $reference;
    }

    /**
     * Generate a UUID-style suffix for new merchant references.
     *
     * @return string
     */
    private function uuid(): string {
        if (function_exists('wp_generate_uuid4')) {
            return (string)wp_generate_uuid4();
        }

        try {
            $bytes = random_bytes(16);
        } catch (\Exception $exception) {
            $bytes = md5(uniqid('', true), true);
        }

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Build a WeBirr bill payload from a WooCommerce order.
     *
     * @param object $order WooCommerce order.
     * @param string $merchant_reference Stable merchant reference.
     * @return array<string, mixed>
     */
    public function build_bill_from_order($order, string $merchant_reference): array {
        $amount = method_exists($order, 'get_total') ? $order->get_total() : '0';
        if (function_exists('wc_format_decimal')) {
            $amount = wc_format_decimal($amount, 2);
        } else {
            $amount = number_format((float)$amount, 2, '.', '');
        }

        $customer_name = trim(
            (method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '') . ' ' .
            (method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : '')
        );
        if ($customer_name === '' && method_exists($order, 'get_formatted_billing_full_name')) {
            $customer_name = trim((string)$order->get_formatted_billing_full_name());
        }

        $customer_code = method_exists($order, 'get_customer_id') && $order->get_customer_id()
            ? (string)$order->get_customer_id()
            : (string)(method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '');

        $phone = method_exists($order, 'get_billing_phone') ? (string)$order->get_billing_phone() : '';

        return [
            'amount' => $amount,
            'customerCode' => $customer_code,
            'customerName' => $customer_name,
            'customerPhone' => $phone,
            'time' => $this->current_time('Y-m-d H:i'),
            'description' => $this->order_description($order),
            'billReference' => $merchant_reference,
            'extras' => [
                'source' => 'woocommerce',
                'order_id' => (string)(method_exists($order, 'get_id') ? $order->get_id() : ''),
            ],
        ];
    }

    /**
     * Return a failure state and persist the last error.
     *
     * @param object $order WooCommerce order.
     * @param string $error Error message.
     * @return array<string, mixed>
     */
    private function failure($order, string $error): array {
        $this->set_meta($order, self::META_LAST_ERROR, $error);
        $this->save($order);

        return $this->state($order, false, $error);
    }

    /**
     * If a locally stored code exists, update the remote bill only when unpaid and changed.
     *
     * @param object $order WooCommerce order.
     * @param string $payment_code WeBirr payment code.
     * @param array<string, mixed> $bill Current bill data.
     * @return void
     */
    private function maybe_update_existing_bill($order, string $payment_code, array $bill): void {
        try {
            $status = $this->client->get_payment_status($payment_code);
            if (Response_Normalizer::error($status) === '') {
                $status_value = Response_Normalizer::payment_status($status);
                $this->set_meta($order, self::META_PAYMENT_STATUS, (string)$status_value);

                if ($status_value === 2) {
                    $this->complete_order_if_paid($order, $status);
                    return;
                }
            }

            $existing_bill = $this->client->get_bill_by_payment_code($payment_code);
            if (
                Response_Normalizer::error($existing_bill) === '' &&
                !Response_Normalizer::is_paid($existing_bill) &&
                $this->bill_details_changed($existing_bill, $bill)
            ) {
                $updated = $this->client->update_bill($bill);
                $error = Response_Normalizer::error($updated);
                if ($error !== '') {
                    $this->set_meta($order, self::META_LAST_ERROR, $error);
                }
            }
        } catch (\RuntimeException $exception) {
            $this->set_meta($order, self::META_LAST_ERROR, self::GATEWAY_UNAVAILABLE_MESSAGE);
        }

        $this->save($order);
    }

    /**
     * Compare gateway bill details to current order bill.
     *
     * @param mixed $gateway_bill Gateway get-bill response.
     * @param array<string, mixed> $bill Current bill payload.
     * @return bool
     */
    private function bill_details_changed($gateway_bill, array $bill): bool {
        $amount = Response_Normalizer::bill_value($gateway_bill, 'amount');
        if ($amount !== '' && abs((float)$amount - (float)$bill['amount']) > 0.001) {
            return true;
        }

        foreach (['customerName', 'customerPhone', 'description'] as $key) {
            $remote = Response_Normalizer::bill_value($gateway_bill, $key);
            if ($remote !== '' && $remote !== (string)($bill[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a readable order description.
     *
     * @param object $order WooCommerce order.
     * @return string
     */
    private function order_description($order): string {
        $order_id = method_exists($order, 'get_id') ? (string)$order->get_id() : '';
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'WooCommerce';

        return trim($site_name . ' order ' . $order_id);
    }

    /**
     * Get order metadata through WooCommerce CRUD.
     *
     * @param object $order WooCommerce order.
     * @param string $key Meta key.
     * @return string
     */
    private function get_meta($order, string $key): string {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }

        return trim((string)$order->get_meta($key, true));
    }

    /**
     * Set order metadata through WooCommerce CRUD.
     *
     * @param object $order WooCommerce order.
     * @param string $key Meta key.
     * @param string $value Meta value.
     * @return void
     */
    private function set_meta($order, string $key, string $value): void {
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($key, $value);
        }
    }

    /**
     * Persist order changes.
     *
     * @param object $order WooCommerce order.
     * @return void
     */
    private function save($order): void {
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * Add a non-secret order note.
     *
     * @param object $order WooCommerce order.
     * @param string $message Note text.
     * @return void
     */
    private function add_note($order, string $message): void {
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note($message);
        }
    }

    /**
     * Acquire a short-lived durable completion lock before calling payment_complete.
     *
     * @param object $order WooCommerce order.
     * @param string $payment_reference Bank payment reference.
     * @return bool Whether the completion path may continue.
     */
    private function acquire_completion_lock($order, string $payment_reference): bool {
        if (!function_exists('add_option') || !function_exists('get_option') || !function_exists('delete_option')) {
            return true;
        }

        $key = $this->completion_lock_key($order, $payment_reference);
        $now = time();
        if (add_option($key, (string)$now, '', 'no')) {
            return true;
        }

        $created = (int)get_option($key, 0);
        if ($created > 0 && $created < ($now - self::COMPLETION_LOCK_TTL_SECONDS)) {
            delete_option($key);
            return add_option($key, (string)$now, '', 'no');
        }

        return false;
    }

    /**
     * Release the completion lock.
     *
     * @param object $order WooCommerce order.
     * @param string $payment_reference Bank payment reference.
     * @return void
     */
    private function release_completion_lock($order, string $payment_reference): void {
        if (function_exists('delete_option')) {
            delete_option($this->completion_lock_key($order, $payment_reference));
        }
    }

    /**
     * Build a compact option key for the paid-completion lock.
     *
     * @param object $order WooCommerce order.
     * @param string $payment_reference Bank payment reference.
     * @return string
     */
    private function completion_lock_key($order, string $payment_reference): string {
        $order_id = method_exists($order, 'get_id') ? (string)$order->get_id() : '';
        $payment_code = $this->get_meta($order, self::META_PAYMENT_CODE);

        return 'webirr_wc_completion_lock_' . md5($order_id . '|' . $payment_code . '|' . $payment_reference);
    }

    /**
     * Return current time in local WordPress timezone when available.
     *
     * @param string $format Date format.
     * @return string
     */
    private function current_time(string $format = 'mysql'): string {
        if (function_exists('current_time')) {
            return current_time($format);
        }

        return date($format === 'mysql' ? 'Y-m-d H:i:s' : $format);
    }
}
