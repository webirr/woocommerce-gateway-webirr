<?php
/**
 * WeBirr API client using WordPress HTTP APIs.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Dependency-free WeBirr client for WordPress/WooCommerce hosts.
 */
final class Client {
    private const TEST_BASE_URL = 'https://api.webirr.net';
    private const PROD_BASE_URL = 'https://api.webirr.net:8080';

    /** @var string */
    private string $merchant_id;

    /** @var string */
    private string $api_key;

    /** @var string */
    private string $base_url;

    /** @var Logger */
    private Logger $logger;

    /** @var callable|null */
    private $transport;

    /**
     * @param string $merchant_id Merchant ID.
     * @param string $api_key API key.
     * @param string $environment TestEnv or ProdEnv.
     * @param Logger|null $logger Logger.
     * @param callable|null $transport Optional transport used by tests.
     */
    public function __construct(
        string $merchant_id,
        string $api_key,
        string $environment,
        ?Logger $logger = null,
        ?callable $transport = null
    ) {
        $this->merchant_id = trim($merchant_id);
        $this->api_key = trim($api_key);
        $this->base_url = $environment === 'ProdEnv' ? self::PROD_BASE_URL : self::TEST_BASE_URL;
        $this->logger = $logger ?: new Logger(false, $this->api_key);
        $this->transport = $transport;
    }

    /**
     * Create a bill.
     *
     * @param array<string, mixed> $bill Bill fields.
     * @return \stdClass
     */
    public function create_bill(array $bill): \stdClass {
        return $this->request('POST', 'einvoice/api/bill', [], $this->bill_payload($bill));
    }

    /**
     * Update an unpaid bill.
     *
     * @param array<string, mixed> $bill Bill fields.
     * @return \stdClass
     */
    public function update_bill(array $bill): \stdClass {
        return $this->request('PUT', 'einvoice/api/bill', [], $this->bill_payload($bill));
    }

    /**
     * Get one bill by merchant reference.
     *
     * @param string $bill_reference Merchant reference.
     * @return \stdClass
     */
    public function get_bill_by_reference(string $bill_reference): \stdClass {
        return $this->request('GET', 'einvoice/api/bill', ['bill_reference' => $bill_reference]);
    }

    /**
     * Get one bill by payment code.
     *
     * @param string $payment_code WeBirr payment code.
     * @return \stdClass
     */
    public function get_bill_by_payment_code(string $payment_code): \stdClass {
        return $this->request('GET', 'einvoice/api/bill', ['wbc_code' => $payment_code]);
    }

    /**
     * Get single payment status.
     *
     * @param string $payment_code WeBirr payment code.
     * @return \stdClass
     */
    public function get_payment_status(string $payment_code): \stdClass {
        return $this->request('GET', 'einvoice/api/paymentStatus', ['wbc_code' => $payment_code]);
    }

    /**
     * Get merchant-supported banks and wallets.
     *
     * @return \stdClass
     */
    public function get_supported_banks(): \stdClass {
        return $this->request('GET', 'einvoice/api/banks');
    }

    /**
     * Build the full URL. Public for unit tests and diagnostics.
     *
     * @param string $path Gateway path.
     * @param array<string, mixed> $params Extra query parameters.
     * @return string
     */
    public function build_url(string $path, array $params = []): string {
        $query = ['api_key' => $this->api_key];
        if ($this->merchant_id !== '') {
            $query['merchant_id'] = $this->merchant_id;
        }

        $query = array_merge($query, $params);

        return $this->base_url . '/' . ltrim($path, '/') . '?' .
            http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Convert bill fields to gateway payload.
     *
     * @param array<string, mixed> $bill Bill fields.
     * @return array<string, mixed>
     */
    public function bill_payload(array $bill): array {
        $merchant_id = trim((string)($bill['merchantID'] ?? ''));
        if ($this->merchant_id !== '') {
            $merchant_id = $this->merchant_id;
        }

        $extras = $bill['extras'] ?? [];
        if (is_array($extras) && $extras === []) {
            $extras = new \stdClass();
        }

        return [
            'amount' => (string)($bill['amount'] ?? ''),
            'customerCode' => (string)($bill['customerCode'] ?? ''),
            'customerName' => (string)($bill['customerName'] ?? ''),
            'customerPhone' => (string)($bill['customerPhone'] ?? ''),
            'time' => (string)($bill['time'] ?? ''),
            'description' => (string)($bill['description'] ?? ''),
            'billReference' => (string)($bill['billReference'] ?? ''),
            'merchantID' => $merchant_id,
            'extras' => $extras,
        ];
    }

    /**
     * Send request and decode gateway response.
     *
     * @param string $method HTTP method.
     * @param string $path Gateway path.
     * @param array<string, mixed> $params Extra query parameters.
     * @param array<string, mixed>|null $payload JSON payload.
     * @return \stdClass
     */
    private function request(string $method, string $path, array $params = [], ?array $payload = null): \stdClass {
        $url = $this->build_url($path, $params);
        $this->logger->info('WeBirr request', ['method' => $method, 'url' => $this->logger->redact($url)]);

        $response = $this->transport
            ? call_user_func($this->transport, $method, $url, $payload)
            : $this->send_with_wordpress($method, $url, $payload);

        return $this->decode_response($response);
    }

    /**
     * Use WordPress HTTP APIs for real requests.
     *
     * @param string $method HTTP method.
     * @param string $url URL.
     * @param array<string, mixed>|null $payload JSON payload.
     * @return mixed
     */
    private function send_with_wordpress(string $method, string $url, ?array $payload) {
        if (!function_exists('wp_remote_request')) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'WordPress HTTP API is not available',
            ];
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ($payload !== null) {
            $body = wp_json_encode($payload);
            if ($body === false) {
                return [
                    'status' => 0,
                    'body' => '',
                    'error' => 'Unable to encode WeBirr request payload',
                ];
            }

            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $body;
        }

        return wp_remote_request($url, $args);
    }

    /**
     * Decode WordPress/test transport response.
     *
     * @param mixed $response Transport response.
     * @return \stdClass
     */
    private function decode_response($response): \stdClass {
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return $this->error_response('WordPress HTTP error: ' . $response->get_error_message());
        }

        $status = 200;
        $body = $response;
        $transport_error = '';

        if (is_array($response)) {
            if (isset($response['response']['code'])) {
                $status = (int)$response['response']['code'];
                $body = $response['body'] ?? '';
            } else {
                $status = (int)($response['status'] ?? 200);
                $body = $response['body'] ?? '';
            }
            $transport_error = (string)($response['error'] ?? '');
        }

        if ($transport_error !== '') {
            return $this->error_response($transport_error);
        }

        if ($status < 200 || $status >= 300) {
            return $this->error_response('http error ' . $status);
        }

        if (is_object($body)) {
            return $body;
        }

        $decoded = json_decode((string)$body);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
            return $this->error_response('invalid response from WeBirr');
        }

        return $decoded;
    }

    /**
     * Build gateway-style error response.
     *
     * @param string $message Error message.
     * @return \stdClass
     */
    private function error_response(string $message): \stdClass {
        $this->logger->error('WeBirr error', ['message' => $message]);

        return (object)[
            'error' => $message,
            'res' => null,
        ];
    }
}

