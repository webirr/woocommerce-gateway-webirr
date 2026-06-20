<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require_once __DIR__ . '/../includes/class-logger.php';
require_once __DIR__ . '/../includes/class-response-normalizer.php';
require_once __DIR__ . '/../includes/class-client.php';
require_once __DIR__ . '/../includes/class-supported-banks.php';
require_once __DIR__ . '/../includes/class-order-service.php';

use WeBirr\WooCommerceGateway\Client;
use WeBirr\WooCommerceGateway\Logger;
use WeBirr\WooCommerceGateway\Order_Service;
use WeBirr\WooCommerceGateway\Response_Normalizer;
use WeBirr\WooCommerceGateway\Supported_Banks;

function get_current_blog_id(): int {
    return 1;
}

function get_bloginfo(string $key): string {
    return $key === 'name' ? 'Test Store' : '';
}

function current_time(string $format = 'mysql'): string {
    return $format === 'mysql' ? '2026-06-20 12:00:00' : date($format, strtotime('2026-06-20 12:00:00'));
}

$GLOBALS['webirr_test_options'] = [];

function add_option(string $name, $value = '', string $deprecated = '', string $autoload = 'yes'): bool {
    if (array_key_exists($name, $GLOBALS['webirr_test_options'])) {
        return false;
    }

    $GLOBALS['webirr_test_options'][$name] = $value;
    return true;
}

function get_option(string $name, $default = false) {
    return $GLOBALS['webirr_test_options'][$name] ?? $default;
}

function delete_option(string $name): bool {
    unset($GLOBALS['webirr_test_options'][$name]);
    return true;
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

final class FakeOrder {
    public array $meta = [];
    public array $notes = [];
    public int $saves = 0;
    public int $paymentCompleteCalls = 0;
    public bool $paid = false;
    public string $transactionId = '';
    public string $status = 'pending';

    public function get_id(): int {
        return 42;
    }

    public function get_meta(string $key, bool $single = true): string {
        return (string)($this->meta[$key] ?? '');
    }

    public function update_meta_data(string $key, string $value): void {
        $this->meta[$key] = $value;
    }

    public function save(): void {
        $this->saves++;
    }

    public function get_total(): string {
        return '640.00';
    }

    public function get_billing_first_name(): string {
        return 'Elias';
    }

    public function get_billing_last_name(): string {
        return '';
    }

    public function get_customer_id(): int {
        return 7;
    }

    public function get_billing_email(): string {
        return 'elias@example.test';
    }

    public function get_billing_phone(): string {
        return '0911000000';
    }

    public function add_order_note(string $message): void {
        $this->notes[] = $message;
    }

    public function is_paid(): bool {
        return $this->paid;
    }

    public function payment_complete(string $reference = ''): void {
        $this->paymentCompleteCalls++;
        $this->paid = true;
        $this->transactionId = $reference;
    }

    public function set_transaction_id(string $reference): void {
        $this->transactionId = $reference;
    }

    public function update_status(string $status, string $note = ''): void {
        $this->status = $status;
        if ($note !== '') {
            $this->notes[] = $note;
        }
    }
}

function client_with_transport(array &$calls, array $responses, string $merchantId = '0305'): Client {
    $index = 0;
    $transport = static function(string $method, string $url, ?array $payload) use (&$calls, $responses, &$index) {
        $calls[] = [
            'method' => $method,
            'url' => $url,
            'payload' => $payload,
        ];
        $response = $responses[$index] ?? '{"error":"unexpected request","res":null}';
        $index++;

        return is_array($response) ? $response : ['status' => 200, 'body' => $response];
    };

    return new Client($merchantId, 'test-api-key', 'TestEnv', new Logger(false, 'test-api-key'), $transport);
}

function test_client_query_and_bill_payload(): void {
    $client = new Client('0305', 'secret key', 'TestEnv', new Logger(false, 'secret key'));
    $url = $client->build_url('einvoice/api/paymentStatus', ['wbc_code' => '123 456']);
    assert_true(str_contains($url, 'https://api.webirr.net/einvoice/api/paymentStatus?'), 'TestEnv base URL should be api.webirr.net.');
    assert_true(str_contains($url, 'api_key=secret%20key'), 'API key query parameter should be encoded.');
    assert_true(str_contains($url, 'merchant_id=0305'), 'merchant_id query parameter should be sent when configured.');
    assert_true(str_contains($url, 'wbc_code=123%20456'), 'endpoint query parameter should be encoded.');

    $emptyMerchant = new Client('', 'x', 'ProdEnv', new Logger(false, 'x'));
    assert_true(!str_contains($emptyMerchant->build_url('einvoice/api/banks'), 'merchant_id='), 'Empty merchant ID must not be sent.');

    $payload = $client->bill_payload([
        'amount' => '640.00',
        'customerCode' => '7',
        'customerName' => 'Elias',
        'customerPhone' => '0911000000',
        'time' => '2026-06-20 12:00',
        'description' => 'WooCommerce order 42',
        'billReference' => 'wc_1_42_5f2f8d12-7e31-4e4e-a614-4be3b4e06c91',
        'merchantID' => 'from-bill',
        'extras' => [],
    ]);

    assert_same('0305', $payload['merchantID'], 'Configured merchant ID should override bill merchantID.');
    assert_same('0911000000', $payload['customerPhone'], 'Customer phone should be preserved.');
    assert_same('{}', json_encode($payload['extras']), 'Empty extras should serialize as JSON object.');
}

function test_prepare_payment_create_and_reuse(): void {
    $calls = [];
    $client = client_with_transport($calls, [
        '{"error":"not found","res":null}',
        '{"error":null,"res":"PAY123"}',
    ]);
    $order = new FakeOrder();
    $service = new Order_Service($client);

    $result = $service->prepare_payment($order);

    assert_true((bool)$result['success'], 'Create flow should succeed.');
    assert_true(
        preg_match('/^wc_1_42_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string)$result['merchantReference']) === 1,
        'Stable merchant reference should include site, order, and UUID-style suffix.'
    );
    assert_same('PAY123', $result['paymentCode'], 'Created payment code should be stored.');
    assert_same('GET', $calls[0]['method'], 'Recovery lookup should happen before create.');
    assert_same('POST', $calls[1]['method'], 'Create bill should be the last resort.');

    $reuse = $service->prepare_payment($order);
    assert_true((bool)$reuse['success'], 'Reuse flow should succeed.');
    assert_same('PAY123', $reuse['paymentCode'], 'Existing payment code should be reused.');
}

function test_update_unpaid_changed_existing_bill(): void {
    $calls = [];
    $client = client_with_transport($calls, [
        '{"error":null,"res":{"status":0}}',
        '{"error":null,"res":{"amount":"100.00","customerName":"Elias","customerPhone":"0911000000","description":"Old","paymentStatus":0,"wbcCode":"PAY123"}}',
        '{"error":null,"res":"OK"}',
    ]);
    $order = new FakeOrder();
    $order->meta[Order_Service::META_MERCHANT_REFERENCE] = 'wc_1_42';
    $order->meta[Order_Service::META_PAYMENT_CODE] = 'PAY123';
    $service = new Order_Service($client);

    $service->prepare_payment($order);

    assert_same('GET', $calls[0]['method'], 'Existing-code flow should check payment status.');
    assert_same('GET', $calls[1]['method'], 'Existing-code flow should load bill details.');
    assert_same('PUT', $calls[2]['method'], 'Changed unpaid bill should be updated.');
}

function test_idempotent_paid_completion(): void {
    $GLOBALS['webirr_test_options'] = [];
    $calls = [];
    $client = client_with_transport($calls, [
        '{"error":null,"res":{"status":2,"paymentReference":"TX123","bankName":"CBE Mobile","paymentDate":"2026-06-20 12:05:00"}}',
        '{"error":null,"res":{"status":2,"paymentReference":"TX123","bankName":"CBE Mobile","paymentDate":"2026-06-20 12:05:00"}}',
    ]);
    $order = new FakeOrder();
    $order->meta[Order_Service::META_MERCHANT_REFERENCE] = 'wc_1_42';
    $order->meta[Order_Service::META_PAYMENT_CODE] = 'PAY123';
    $service = new Order_Service($client);

    $first = $service->check_and_complete($order);
    $second = $service->check_and_complete($order);

    assert_true((bool)$first['complete'], 'First paid status should complete.');
    assert_true((bool)$second['complete'], 'Second paid status should remain complete.');
    assert_same(1, $order->paymentCompleteCalls, 'Repeated paid checks must call payment_complete once.');
    assert_same('TX123', $order->meta[Order_Service::META_PAYMENT_REFERENCE], 'Payment reference should be stored.');
    assert_same('CBE Mobile', $order->meta[Order_Service::META_PAID_VIA], 'Paid-via issuer should be stored.');
}

function test_completion_lock_blocks_parallel_completion(): void {
    $GLOBALS['webirr_test_options'] = [];
    $calls = [];
    $client = client_with_transport($calls, []);
    $order = new FakeOrder();
    $order->meta[Order_Service::META_MERCHANT_REFERENCE] = 'wc_1_42';
    $order->meta[Order_Service::META_PAYMENT_CODE] = 'PAY123';
    $lockkey = 'webirr_wc_completion_lock_' . md5('42|PAY123|TX123');
    add_option($lockkey, (string)time(), '', 'no');
    $service = new Order_Service($client);

    $service->complete_order_if_paid($order, (object)[
        'res' => (object)[
            'status' => 2,
            'paymentReference' => 'TX123',
            'bankName' => 'CBE Mobile',
        ],
    ]);

    assert_same(0, $order->paymentCompleteCalls, 'Held completion lock should block payment_complete.');
    assert_same('', $order->meta[Order_Service::META_COMPLETED_AT] ?? '', 'Held completion lock should not mark the order completed.');
    delete_option($lockkey);

    $service->complete_order_if_paid($order, (object)[
        'res' => (object)[
            'status' => 2,
            'paymentReference' => 'TX123',
            'bankName' => 'CBE Mobile',
        ],
    ]);

    assert_same(1, $order->paymentCompleteCalls, 'Released completion lock should allow one payment_complete call.');
    assert_true(($order->meta[Order_Service::META_COMPLETED_AT] ?? '') !== '', 'Released completion lock should mark completion.');
}

function test_supported_banks_and_payment_helpers(): void {
    $response = (object)[
        'error' => null,
        'res' => [
            (object)['bankID' => 'cbe_mobile', 'name' => 'CBE Mobile'],
            (object)['bankID' => 'insa_test', 'name' => ''],
        ],
    ];

    $banks = Response_Normalizer::supported_banks($response);
    assert_same(1, count($banks), 'Only complete supported-bank rows should be returned.');
    assert_same('CBE Mobile -> WeBirr -> Payment Code', Supported_Banks::instructions($banks)[0], 'Supported bank instructions should use WeBirr payment-code format.');

    $payment = (object)[
        'res' => (object)[
            'status' => 2,
            'data' => (object)[
                'paymentReference' => 'TX999',
                'bankID' => 'cbe_mobile',
            ],
        ],
    ];

    assert_true(Response_Normalizer::is_paid($payment), 'Status 2 should be paid.');
    assert_same('TX999', Response_Normalizer::payment_reference($payment), 'Nested payment reference should be extracted.');
    assert_same('CBE Mobile', Response_Normalizer::payment_issuer($payment), 'Bank ID should become display text.');
}

$tests = [
    'client query and bill payload' => 'test_client_query_and_bill_payload',
    'prepare payment create and reuse' => 'test_prepare_payment_create_and_reuse',
    'update unpaid changed existing bill' => 'test_update_unpaid_changed_existing_bill',
    'idempotent paid completion' => 'test_idempotent_paid_completion',
    'completion lock blocks parallel completion' => 'test_completion_lock_blocks_parallel_completion',
    'supported banks and payment helpers' => 'test_supported_banks_and_payment_helpers',
];

foreach ($tests as $name => $test) {
    $test();
    echo "PASS {$name}\n";
}

echo "All tests passed.\n";
