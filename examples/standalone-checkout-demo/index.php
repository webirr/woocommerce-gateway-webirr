<?php

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', dirname(__DIR__, 2) . '/');

$includes = is_dir(__DIR__ . '/../../includes') ? __DIR__ . '/../../includes' : '/plugin/includes';
$assets = is_dir(__DIR__ . '/../../assets') ? __DIR__ . '/../../assets' : '/plugin/assets';

require_once $includes . '/class-logger.php';
require_once $includes . '/class-response-normalizer.php';
require_once $includes . '/class-client.php';
require_once $includes . '/class-supported-banks.php';

use WeBirr\WooCommerceGateway\Client;
use WeBirr\WooCommerceGateway\Logger;
use WeBirr\WooCommerceGateway\Response_Normalizer;
use WeBirr\WooCommerceGateway\Supported_Banks;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (serve_asset($path, $assets)) {
    return;
}

if (strpos($path, '/api/') === 0) {
    handle_api($path);
    return;
}

render_page();

function serve_asset(string $path, string $assets): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        return false;
    }

    if (strpos($path, '/assets/') !== 0) {
        return false;
    }

    $requested = realpath($assets . substr($path, strlen('/assets')));
    $root = realpath($assets);
    if ($requested === false || $root === false || strpos($requested, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($requested)) {
        http_response_code(404);
        return true;
    }

    $contenttype = function_exists('mime_content_type') ? mime_content_type($requested) : false;
    header('Content-Type: ' . ($contenttype ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($requested));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        return true;
    }

    readfile($requested);
    return true;
}

function handle_api(string $path): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'error' => 'POST required'], 405);
    }

    try {
        if ($path === '/api/create-bill') {
            create_bill();
            return;
        }

        if ($path === '/api/payment-status') {
            payment_status();
            return;
        }

        json_response(['success' => false, 'error' => 'Unknown endpoint'], 404);
    } catch (Throwable $throwable) {
        json_response(['success' => false, 'error' => $throwable->getMessage()], 500);
    }
}

function create_bill(): void {
    $payload = read_json_payload();
    $book = find_demo_book((string)($payload['bookId'] ?? ''));
    if (!$book) {
        json_response(['success' => false, 'error' => 'Choose a valid audio book.'], 400);
    }

    $amount = format_amount((string)$book['amount']);
    $customername = trim((string)($payload['customerName'] ?? ''));
    if ($customername === '') {
        json_response(['success' => false, 'error' => 'Customer name is required.'], 400);
    }

    $description = (string)$book['title'] . ' - ' . (string)$book['description'];
    $merchantreference = normalize_merchant_reference((string)($payload['merchantReference'] ?? default_merchant_reference()));
    $billreference = build_demo_bill_reference($merchantreference);
    $detailshash = details_hash($customername, $amount, $description);

    $bill = [
        'amount' => $amount,
        'customerCode' => 'WOO-DEMO',
        'customerName' => $customername !== '' ? $customername : 'Elias',
        'customerPhone' => '',
        'time' => date('Y-m-d H:i'),
        'description' => $description !== '' ? $description : 'Sample audio book purchase',
        'billReference' => $billreference,
        'extras' => [
            'source' => 'woocommerce-standalone-demo',
            'merchantReference' => $merchantreference,
        ],
    ];

    $client = create_webirr_client();
    $db = demo_db();
    $existing = find_demo_payment($db, $billreference);

    if ($existing && !empty($existing['payment_code'])) {
        $payment = reuse_demo_payment($db, $existing, $bill, $merchantreference, $detailshash, $client);
        json_response(payment_code_response($payment, (string)($payment['operation'] ?? 'reused'), $client));
    }

    $recovered = $client->get_bill_by_reference($billreference);
    $recoveryerror = Response_Normalizer::error($recovered);
    if ($recoveryerror === '') {
        $paymentcode = Response_Normalizer::payment_code($recovered);
        if ($paymentcode === '') {
            json_response(['success' => false, 'error' => 'Invalid bill lookup response from WeBirr'], 502);
        }

        $status = Response_Normalizer::payment_status($recovered);
        $operation = 'recovered';
        if ($status !== 2 && bill_details_changed($recovered, $bill)) {
            $updated = $client->update_bill($bill);
            $updatederror = Response_Normalizer::error($updated);
            if ($updatederror !== '') {
                json_response(['success' => false, 'error' => $updatederror], 502);
            }
            $operation = 'updated';
        }

        $payment = save_demo_payment(
            $db,
            null,
            $merchantreference,
            $billreference,
            $paymentcode,
            $amount,
            (string)$bill['customerName'],
            (string)$bill['description'],
            $detailshash,
            $status
        );
        $payment['operation'] = $operation;
        json_response(payment_code_response($payment, $operation, $client));
    }

    if (Response_Normalizer::is_transport_error($recoveryerror)) {
        json_response(['success' => false, 'error' => $recoveryerror], 502);
    }

    $created = $client->create_bill($bill);
    $createderror = Response_Normalizer::error($created);
    if ($createderror !== '') {
        json_response(['success' => false, 'error' => $createderror], 502);
    }

    $paymentcode = Response_Normalizer::payment_code($created);
    if ($paymentcode === '') {
        json_response(['success' => false, 'error' => 'WeBirr did not return a payment code.'], 502);
    }

    $payment = save_demo_payment(
        $db,
        null,
        $merchantreference,
        $billreference,
        $paymentcode,
        $amount,
        (string)$bill['customerName'],
        (string)$bill['description'],
        $detailshash,
        0
    );
    $payment['operation'] = 'created';
    json_response(payment_code_response($payment, 'created', $client));
}

function payment_status(): void {
    $payload = read_json_payload();
    $paymentid = (int)($payload['paymentId'] ?? 0);

    if ($paymentid <= 0) {
        json_response(['success' => false, 'error' => 'paymentId is required'], 400);
    }

    $db = demo_db();
    $stmt = $db->prepare('SELECT * FROM demo_payments WHERE id = :id');
    $stmt->execute([':id' => $paymentid]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        json_response(['success' => false, 'error' => 'Payment record not found'], 404);
    }

    $client = create_webirr_client();
    $result = $client->get_payment_status((string)$payment['payment_code']);
    $error = Response_Normalizer::error($result);
    if ($error !== '') {
        json_response(['success' => false, 'error' => $error], 502);
    }

    $status = Response_Normalizer::payment_status($result);
    update_demo_payment_status($db, $paymentid, $status, $result);

    json_response([
        'success' => true,
        'status' => $status,
        'complete' => $status === 2,
        'paymentId' => $paymentid,
        'paymentCode' => (string)$payment['payment_code'],
        'billReference' => (string)$payment['bill_reference'],
        'paymentReference' => Response_Normalizer::payment_reference($result),
        'paidVia' => Response_Normalizer::payment_issuer($result),
    ]);
}

function reuse_demo_payment(
    PDO $db,
    array $existing,
    array $bill,
    string $merchantreference,
    string $detailshash,
    Client $client
): array {
    $operation = 'reused';
    $status = (int)$existing['status'];
    $statusresult = $client->get_payment_status((string)$existing['payment_code']);
    $statuserror = Response_Normalizer::error($statusresult);
    if ($statuserror !== '') {
        throw new RuntimeException($statuserror);
    }

    $status = Response_Normalizer::payment_status($statusresult);
    update_demo_payment_status($db, (int)$existing['id'], $status, $statusresult);

    if ($status !== 2 && (string)($existing['details_hash'] ?? '') !== $detailshash) {
        $bill['billReference'] = (string)$existing['bill_reference'];
        $updated = $client->update_bill($bill);
        $updatederror = Response_Normalizer::error($updated);
        if ($updatederror !== '') {
            throw new RuntimeException($updatederror);
        }
        $operation = 'updated';
        $existing = save_demo_payment(
            $db,
            $existing,
            $merchantreference,
            (string)$existing['bill_reference'],
            (string)$existing['payment_code'],
            (string)$bill['amount'],
            (string)$bill['customerName'],
            (string)$bill['description'],
            $detailshash,
            $status
        );
    } else {
        $existing = find_demo_payment($db, (string)$existing['bill_reference']) ?: $existing;
    }

    $existing['operation'] = $operation;
    return $existing;
}

function payment_code_response(array $payment, string $operation, Client $client): array {
    return [
        'success' => true,
        'paymentId' => (int)$payment['id'],
        'paymentCode' => (string)$payment['payment_code'],
        'billReference' => (string)$payment['bill_reference'],
        'merchantReference' => (string)$payment['merchant_reference'],
        'amount' => (string)$payment['amount'],
        'customerName' => (string)$payment['customer_name'],
        'description' => (string)$payment['description'],
        'itemTitle' => item_title_from_description((string)$payment['description']),
        'status' => (int)$payment['status'],
        'operation' => $operation,
        'supportedBanks' => supported_banks_response($client),
    ];
}

function supported_banks_response(Client $client): array {
    $response = $client->get_supported_banks();
    if (Response_Normalizer::error($response) !== '') {
        return [];
    }

    return Supported_Banks::from_response($response);
}

function supported_banks_preview(): array {
    return [
        ['name' => 'CBE Mobile'],
        ['name' => 'CBE Birr'],
        ['name' => 'Awash Birr'],
        ['name' => 'Telebirr'],
        ['name' => 'M-Pesa'],
        ['name' => 'Coopay Ebirr'],
    ];
}

function render_payment_instruction_items(array $banks): void {
    if ($banks === []) {
        ?>
                    <li>Use one of this merchant's supported WeBirr banking or wallet apps.</li>
        <?php
        return;
    }

    foreach ($banks as $bank) {
        $name = trim((string)($bank['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        ?>
                    <li><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?> -&gt; WeBirr -&gt; Payment Code</li>
        <?php
    }
}

function find_demo_payment(PDO $db, string $billreference): ?array {
    $stmt = $db->prepare('SELECT * FROM demo_payments WHERE bill_reference = :bill_reference');
    $stmt->execute([':bill_reference' => $billreference]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($record) ? $record : null;
}

function save_demo_payment(
    PDO $db,
    ?array $existing,
    string $merchantreference,
    string $billreference,
    string $paymentcode,
    string $amount,
    string $customername,
    string $description,
    string $detailshash,
    int $status
): array {
    $now = gmdate('c');

    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE demo_payments
                SET merchant_reference = :merchant_reference,
                    payment_code = :payment_code,
                    amount = :amount,
                    customer_name = :customer_name,
                    description = :description,
                    details_hash = :details_hash,
                    status = :status,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':id' => (int)$existing['id'],
            ':merchant_reference' => $merchantreference,
            ':payment_code' => $paymentcode,
            ':amount' => $amount,
            ':customer_name' => $customername,
            ':description' => $description,
            ':details_hash' => $detailshash,
            ':status' => $status,
            ':updated_at' => $now,
        ]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO demo_payments
                (merchant_reference, bill_reference, payment_code, amount, customer_name, description, details_hash, status, created_at, updated_at)
             VALUES
                (:merchant_reference, :bill_reference, :payment_code, :amount, :customer_name, :description, :details_hash, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':merchant_reference' => $merchantreference,
            ':bill_reference' => $billreference,
            ':payment_code' => $paymentcode,
            ':amount' => $amount,
            ':customer_name' => $customername,
            ':description' => $description,
            ':details_hash' => $detailshash,
            ':status' => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $payment = find_demo_payment($db, $billreference);
    if (!$payment) {
        throw new RuntimeException('Payment record was not saved.');
    }

    return $payment;
}

function update_demo_payment_status(PDO $db, int $paymentid, int $status, object $rawstatus): void {
    $stmt = $db->prepare(
        'UPDATE demo_payments
            SET status = :status, raw_status = :raw_status, updated_at = :updated_at
          WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $paymentid,
        ':status' => $status,
        ':raw_status' => json_encode($rawstatus, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
    ]);
}

function demo_db(): PDO {
    $datadir = __DIR__ . '/data';
    if (!is_dir($datadir)) {
        mkdir($datadir, 0770, true);
    }

    $db = new PDO('sqlite:' . $datadir . '/webirr-woocommerce-demo.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS demo_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merchant_reference TEXT NOT NULL DEFAULT "",
            bill_reference TEXT NOT NULL UNIQUE,
            payment_code TEXT NOT NULL,
            amount TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            details_hash TEXT NOT NULL DEFAULT "",
            status INTEGER NOT NULL DEFAULT 0,
            raw_status TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    return $db;
}

function normalize_merchant_reference(string $merchantreference): string {
    $merchantreference = trim($merchantreference);

    return $merchantreference !== '' ? $merchantreference : default_merchant_reference();
}

function default_merchant_reference(): string {
    return 'ord_' . substr(str_replace('-', '', demo_uuid()), 0, 8);
}

function demo_uuid(): string {
    try {
        $bytes = random_bytes(16);
    } catch (Exception $exception) {
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

function demo_catalog(): array {
    return [
        ['id' => 'audio-book-001', 'title' => 'Modern Business Audio Book', 'description' => 'Digital audio book purchase', 'amount' => '640.00'],
        ['id' => 'audio-book-002', 'title' => 'Leadership Field Notes', 'description' => 'Digital audio book purchase', 'amount' => '580.00'],
        ['id' => 'audio-book-003', 'title' => 'Practical Finance Basics', 'description' => 'Digital audio book purchase', 'amount' => '720.00'],
        ['id' => 'audio-book-004', 'title' => 'Startup Operations Guide', 'description' => 'Digital audio book purchase', 'amount' => '690.00'],
        ['id' => 'audio-book-005', 'title' => 'Customer Service Playbook', 'description' => 'Digital audio book purchase', 'amount' => '510.00'],
        ['id' => 'audio-book-006', 'title' => 'Digital Commerce Lessons', 'description' => 'Digital audio book purchase', 'amount' => '760.00'],
        ['id' => 'audio-book-007', 'title' => 'Project Delivery Habits', 'description' => 'Digital audio book purchase', 'amount' => '550.00'],
        ['id' => 'audio-book-008', 'title' => 'Retail Growth Stories', 'description' => 'Digital audio book purchase', 'amount' => '615.00'],
        ['id' => 'audio-book-009', 'title' => 'Resilient Teams', 'description' => 'Digital audio book purchase', 'amount' => '675.00'],
        ['id' => 'audio-book-010', 'title' => 'Merchant Payments 101', 'description' => 'Digital audio book purchase', 'amount' => '705.00'],
    ];
}

function find_demo_book(string $bookid): ?array {
    foreach (demo_catalog() as $book) {
        if ($book['id'] === $bookid) {
            return $book;
        }
    }

    return null;
}

function item_title_from_description(string $description): string {
    $parts = explode(' - ', $description, 2);
    return trim($parts[0]) !== '' ? trim($parts[0]) : 'Audio Book';
}

function build_demo_bill_reference(string $merchantreference): string {
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $merchantreference));
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'order_' . date('Ymd');
    }

    return 'woo_demo_' . $slug;
}

function details_hash(string $customername, string $amount, string $description): string {
    return hash('sha256', implode("\n", [$customername, $amount, $description]));
}

function bill_details_changed(object $result, array $bill): bool {
    $amount = Response_Normalizer::bill_value($result, 'amount');
    if ($amount !== '' && format_amount($amount) !== (string)$bill['amount']) {
        return true;
    }

    $customername = Response_Normalizer::bill_value($result, 'customerName');
    if ($customername !== '' && $customername !== (string)$bill['customerName']) {
        return true;
    }

    $description = Response_Normalizer::bill_value($result, 'description');
    if ($description !== '' && $description !== (string)$bill['description']) {
        return true;
    }

    return false;
}

function create_webirr_client(): Client {
    $merchantid = getenv('WEBIRR_TEST_ENV_MERCHANT_ID') ?: '';
    $apikey = getenv('WEBIRR_TEST_ENV_API_KEY') ?: '';

    if ($merchantid === '' || $apikey === '') {
        throw new RuntimeException('Set WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY before starting the demo.');
    }

    return new Client($merchantid, $apikey, 'TestEnv', new Logger(false, $apikey), demo_transport());
}

function demo_transport(): callable {
    return static function(string $method, string $url, ?array $payload): array {
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payload !== null && $body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Unable to encode request payload',
            ];
        }

        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responsebody = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'status' => $status,
                'body' => $responsebody === false ? '' : (string)$responsebody,
                'error' => $error,
            ];
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $responsebody = file_get_contents($url, false, stream_context_create($options));
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => $responsebody === false ? '' : (string)$responsebody,
            'error' => $responsebody === false ? 'HTTP request failed' : '',
        ];
    };
}

function read_json_payload(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_response(['success' => false, 'error' => 'Invalid JSON request'], 400);
    }

    return $payload;
}

function format_amount(string $value): string {
    $amount = (float)$value;
    if ($amount <= 0) {
        $amount = 1.00;
    }

    return number_format($amount, 2, '.', '');
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function render_page(): void {
    $preview = (string)($_GET['preview'] ?? '');
    $defaultreference = default_merchant_reference();
    $catalog = demo_catalog();
    $previewbanks = $preview === 'journey' ? supported_banks_preview() : [];
    $previewissuer = trim((string)($previewbanks[0]['name'] ?? '')) ?: 'Supported WeBirr App';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WeBirr Online Checkout</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --ink: #1f2933;
            --muted: #64748b;
            --line: #d7dee8;
            --panel: #ffffff;
            --primary: #145c9e;
            --primary-dark: #0f4779;
            --success-bg: #edf9f1;
            --success-border: #b9e6c8;
            --info-bg: #eaf4ff;
            --info-border: #b6d7ff;
            --danger-bg: #fdecec;
            --danger-border: #e5a3a3;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }
        .shell {
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 20px;
        }
        .shell-wide {
            max-width: 1240px;
        }
        .brand {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }
        .brand img {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }
        .brand h1 {
            margin: 0;
            font-size: 26px;
            letter-spacing: 0;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 22px;
        }
        .journey-layout {
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) 24px minmax(0, 0.9fr) 24px minmax(280px, 1.25fr) 24px minmax(0, 1.05fr);
            gap: 8px;
            align-items: stretch;
        }
        .journey-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 24px;
            font-weight: 700;
        }
        .panel-title {
            margin: 0 0 16px;
            font-size: 16px;
            font-weight: 700;
        }
        .stage[hidden] {
            display: none;
        }
        .field {
            margin-bottom: 14px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }
        input {
            width: 100%;
            min-height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 10px;
            font: inherit;
        }
        .summary {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 10px 14px;
            margin: 0;
            padding: 0;
        }
        .summary dt {
            color: var(--muted);
            font-weight: 700;
        }
        .summary dd {
            margin: 0;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 18px;
        }
        button {
            border: 1px solid transparent;
            border-radius: 6px;
            min-height: 40px;
            padding: 9px 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        a.primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border-radius: 6px;
            padding: 9px 14px;
            text-decoration: none;
            font-weight: 700;
        }
        button:disabled {
            opacity: 0.55;
            cursor: wait;
        }
        .primary {
            background: var(--primary);
            color: white;
        }
        .primary:hover:not(:disabled) {
            background: var(--primary-dark);
        }
        .secondary {
            background: white;
            color: var(--ink);
            border-color: var(--line);
        }
        .payment-code {
            margin: 14px 0;
            border: 1px solid #cbd9ec;
            border-radius: 8px;
            background: #f8fbff;
            padding: 18px;
            text-align: center;
        }
        .payment-code-title {
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #334155;
        }
        .payment-code-value {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: 1px;
            overflow-wrap: anywhere;
        }
        .status {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--info-border);
            border-radius: 6px;
            background: var(--info-bg);
            padding: 12px;
            font-weight: 700;
        }
        .status.success {
            background: var(--success-bg);
            border-color: var(--success-border);
        }
        .status.danger {
            background: var(--danger-bg);
            border-color: var(--danger-border);
        }
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        .instructions {
            margin-top: 16px;
            border: 1px solid #e4e8ee;
            border-radius: 8px;
            background: #fbfcfe;
            padding: 16px;
        }
        .instructions h2 {
            margin: 0 0 10px;
            font-size: 15px;
        }
        .instructions ul {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .instructions li {
            border: 1px solid #edf0f4;
            border-radius: 6px;
            background: white;
            padding: 8px 10px;
            font-weight: 700;
        }
        .confirmation {
            margin-top: 16px;
            border: 1px solid var(--success-border);
            border-radius: 8px;
            background: var(--success-bg);
            padding: 20px;
        }
        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .book-card {
            display: grid;
            gap: 14px;
            align-content: space-between;
            min-height: 210px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            background: white;
        }
        .book-card h2 {
            margin: 0 0 8px;
            font-size: 18px;
        }
        .book-card p {
            margin: 0 0 10px;
            color: var(--muted);
        }
        .confirmation h2 {
            margin: 0 0 12px;
            font-size: 22px;
        }
        .journey-panel {
            min-height: 360px;
        }
        .journey-panel .summary {
            grid-template-columns: 1fr;
            gap: 4px;
        }
        .journey-panel .summary dt {
            font-size: 13px;
        }
        .journey-panel .summary dd {
            margin-bottom: 8px;
            font-size: 14px;
        }
        .journey-panel .payment-code {
            margin-top: 0;
        }
        .journey-panel .payment-code-value {
            font-size: 30px;
        }
        .journey-panel .instructions {
            padding: 10px;
            font-size: 12px;
        }
        .journey-panel .instructions li {
            padding: 6px 8px;
        }
        .journey-confirmed .confirmation {
            margin-top: 0;
        }
        .journey-confirmed .confirmation h2 {
            font-size: 20px;
        }
        .journey-confirmed .summary dd {
            font-size: 13px;
            white-space: nowrap;
        }
        .success-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            margin-bottom: 12px;
            border-radius: 50%;
            background: #198754;
            color: #fff;
            font-size: 24px;
            font-weight: 700;
        }
        .error {
            margin-top: 14px;
            color: #991b1b;
            font-weight: 700;
        }
        @media (max-width: 640px) {
            .shell {
                padding: 18px 12px;
            }
            .summary {
                grid-template-columns: 1fr;
                gap: 4px;
            }
            .payment-code-value {
                font-size: 28px;
            }
            .journey-layout {
                grid-template-columns: 1fr;
            }
            .journey-arrow {
                min-height: 24px;
                transform: rotate(90deg);
            }
        }
    </style>
</head>
<body>
    <main class="shell<?php echo $preview === 'journey' ? ' shell-wide' : ''; ?>">
        <div class="brand">
            <img src="/assets/images/webirr-cute-logo.png" alt="WeBirr">
            <h1>WeBirr Online Checkout</h1>
        </div>

        <?php if ($preview === 'journey') { ?>
        <div class="journey-layout">
            <section class="panel journey-panel">
                <div class="panel-title">Audio Book Catalog</div>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd>Elias</dd>
                    <dt>Audio book</dt>
                    <dd>Modern Business Audio Book</dd>
                    <dt>Amount</dt>
                    <dd>640.00 ETB</dd>
                    <dt>Description</dt>
                    <dd>Digital audio book purchase</dd>
                    <dt>Merchant reference</dt>
                    <dd><?php echo htmlspecialchars($defaultreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>
                <div class="button-row">
                    <button class="primary" type="button">Buy</button>
                </div>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel">
                <div class="panel-title">Checkout</div>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd>Elias</dd>
                    <dt>Amount</dt>
                    <dd>640.00 ETB</dd>
                    <dt>Audio book</dt>
                    <dd>Modern Business Audio Book</dd>
                    <dt>Description</dt>
                    <dd>Digital audio book purchase</dd>
                    <dt>Merchant reference</dt>
                    <dd><?php echo htmlspecialchars($defaultreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>
                <div class="button-row">
                    <button class="primary" type="button">Continue to payment</button>
                    <button class="secondary" type="button">Back</button>
                </div>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel">
                <div class="payment-code">
                    <div class="payment-code-title">WeBirr Payment Code</div>
                    <div class="payment-code-value">175 431 619</div>
                </div>
                <div class="status">
                    <span class="spinner" aria-hidden="true"></span>
                    <span>Payment not received yet.</span>
                </div>
                <div class="instructions">
                    <h2>Payment Instruction</h2>
                    <ul>
                        <?php render_payment_instruction_items($previewbanks); ?>
                    </ul>
                </div>
                <dl class="summary">
                    <dt>Merchant reference</dt>
                    <dd><?php echo htmlspecialchars($defaultreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Payment Status</dt>
                    <dd>pending</dd>
                </dl>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel journey-confirmed">
                <div class="confirmation">
                    <div class="success-check" aria-hidden="true">&#10003;</div>
                    <h2>Payment Confirmed</h2>
                    <dl class="summary">
                        <dt>Customer</dt>
                        <dd>Elias</dd>
                        <dt>Amount</dt>
                        <dd>640.00 ETB</dd>
                        <dt>Payment Reference</dt>
                        <dd>TX70e78862148f4c249606</dd>
                        <dt>Paid Via</dt>
                        <dd><?php echo htmlspecialchars($previewissuer, ENT_QUOTES, 'UTF-8'); ?></dd>
                    </dl>
                </div>
                <div class="button-row">
                    <button class="primary" type="button">Download receipt</button>
                </div>
            </section>
        </div>
        <?php } else { ?>
        <section class="panel stage" data-stage="entry">
            <div class="field">
                <label for="customerName">Customer</label>
                <input id="customerName" value="Elias" autocomplete="name">
            </div>
            <div class="catalog-grid">
                <?php foreach ($catalog as $book): ?>
                    <article class="book-card">
                        <div>
                            <h2><?php echo htmlspecialchars((string)$book['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p><?php echo htmlspecialchars((string)$book['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <strong><?php echo htmlspecialchars((string)$book['amount'], ENT_QUOTES, 'UTF-8'); ?> ETB</strong>
                        </div>
                        <button
                            class="primary"
                            type="button"
                            data-action="review"
                            data-book-id="<?php echo htmlspecialchars((string)$book['id'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-book-title="<?php echo htmlspecialchars((string)$book['title'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-book-amount="<?php echo htmlspecialchars((string)$book['amount'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-book-description="<?php echo htmlspecialchars((string)$book['description'], ENT_QUOTES, 'UTF-8'); ?>"
                        >Buy</button>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel stage" data-stage="review" hidden>
            <dl class="summary">
                <dt>Customer</dt>
                <dd data-review="customerName"></dd>
                <dt>Amount</dt>
                <dd data-review="amount"></dd>
                <dt>Audio book</dt>
                <dd data-review="itemTitle"></dd>
                <dt>Description</dt>
                <dd data-review="description"></dd>
                <dt>Merchant reference</dt>
                <dd data-review="merchantReference"></dd>
            </dl>
            <div class="button-row">
                <button class="primary" type="button" data-action="create">Continue to payment</button>
                <button class="secondary" type="button" data-action="back">Back</button>
            </div>
            <div class="error" data-error hidden></div>
        </section>

        <section class="panel stage" data-stage="payment" hidden>
            <dl class="summary">
                <dt>Customer</dt>
                <dd data-payment="customerName"></dd>
                <dt>Amount</dt>
                <dd data-payment="amount"></dd>
                <dt>Audio book</dt>
                <dd data-payment="itemTitle"></dd>
                <dt>Merchant reference</dt>
                <dd data-payment="merchantReference"></dd>
            </dl>
            <div class="payment-code">
                <div class="payment-code-title">WeBirr Payment Code</div>
                <div class="payment-code-value" data-payment-code></div>
            </div>
            <div class="status" data-status>
                <span class="spinner" aria-hidden="true"></span>
                <span data-status-text>Waiting for payment confirmation...</span>
            </div>
            <div class="instructions">
                <h2>Payment Instruction</h2>
                <ul data-instructions>
                    <li>Use one of this merchant's supported WeBirr banking or wallet apps.</li>
                </ul>
            </div>
            <div class="button-row">
                <button class="secondary" type="button" data-action="refresh" disabled>Refresh</button>
                <button class="secondary" type="button" data-action="restart">New checkout</button>
            </div>
            <div class="error" data-payment-error hidden></div>
        </section>

        <section class="panel stage" data-stage="confirmed" hidden>
            <div class="confirmation">
                <h2>Payment Confirmed</h2>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd data-confirmed-customer></dd>
                    <dt>Amount</dt>
                    <dd data-confirmed-amount></dd>
                    <dt>Payment Reference</dt>
                    <dd data-confirmed-reference></dd>
                    <dt>Paid Via</dt>
                    <dd data-confirmed-paid-via></dd>
                </dl>
            </div>
            <div class="button-row">
                <a class="primary" href="#" data-receipt-download download="webirr-audiobook-receipt.txt">Download receipt</a>
                <button class="primary" type="button" data-action="restart">New checkout</button>
            </div>
        </section>
        <?php } ?>
    </main>

    <?php if ($preview !== 'journey') { ?>
    <script>
        (function () {
            var currentPaymentId = 0;
            var pollTimer = null;
            var selectedBook = null;
            var currentCheckout = null;
            var currentMerchantReference = '';

            function values() {
                return {
                    customerName: document.getElementById('customerName').value.trim(),
                    bookId: selectedBook ? selectedBook.id : '',
                    itemTitle: selectedBook ? selectedBook.title : '',
                    amount: selectedBook ? selectedBook.amount : '',
                    description: selectedBook ? selectedBook.description : '',
                    merchantReference: currentMerchantReference
                };
            }

            function show(stage) {
                document.querySelectorAll('[data-stage]').forEach(function (node) {
                    node.hidden = node.getAttribute('data-stage') !== stage;
                });
            }

            function fill(prefix, data) {
                Object.keys(data).forEach(function (key) {
                    var node = document.querySelector('[data-' + prefix + '="' + key + '"]');
                    if (node) {
                        node.textContent = data[key];
                    }
                });
            }

            function setError(selector, message) {
                var node = document.querySelector(selector);
                if (!node) {
                    return;
                }
                node.hidden = message === '';
                node.textContent = message;
            }

            function request(path, payload) {
                return fetch(path, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    return response.json().then(function (body) {
                        if (!response.ok || body.success === false) {
                            throw new Error(body.error || 'Request failed.');
                        }
                        return body;
                    });
                });
            }

            function review(button) {
                var customerName = document.getElementById('customerName').value.trim();
                if (!customerName) {
                    alert('Customer name is required.');
                    document.getElementById('customerName').focus();
                    return;
                }
                selectedBook = {
                    id: button.getAttribute('data-book-id') || '',
                    title: button.getAttribute('data-book-title') || '',
                    amount: button.getAttribute('data-book-amount') || '',
                    description: button.getAttribute('data-book-description') || ''
                };
                currentMerchantReference = newMerchantReference();
                var data = values();
                data.amount = data.amount + ' ETB';
                fill('review', data);
                setError('[data-error]', '');
                show('review');
            }

            function createPayment() {
                var data = values();
                var button = document.querySelector('[data-action="create"]');
                button.disabled = true;
                setError('[data-error]', '');
                request('/api/create-bill', data)
                    .then(function (body) {
                        currentPaymentId = body.paymentId;
                        currentCheckout = Object.assign({}, body, {
                            customerName: body.customerName || data.customerName,
                            amount: body.amount || data.amount,
                            itemTitle: body.itemTitle || data.itemTitle,
                            merchantReference: body.merchantReference || data.merchantReference
                        });
                        fill('payment', {
                            customerName: currentCheckout.customerName,
                            amount: currentCheckout.amount + ' ETB',
                            itemTitle: currentCheckout.itemTitle,
                            merchantReference: currentCheckout.merchantReference
                        });
                        document.querySelector('[data-payment-code]').textContent = body.paymentCode || '';
                        renderInstructions(body.supportedBanks || []);
                        setStatus('Waiting for payment confirmation...', 'info', true);
                        show('payment');
                        schedulePoll(750);
                    })
                    .catch(function (error) {
                        setError('[data-error]', error.message || 'Unable to create payment code.');
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            }

            function renderInstructions(banks) {
                var list = document.querySelector('[data-instructions]');
                list.innerHTML = '';
                if (!banks.length) {
                    var fallback = document.createElement('li');
                    fallback.textContent = "Use one of this merchant's supported WeBirr banking or wallet apps.";
                    list.appendChild(fallback);
                    return;
                }

                banks.forEach(function (bank) {
                    var name = (bank.name || '').trim();
                    if (!name) {
                        return;
                    }
                    var item = document.createElement('li');
                    item.textContent = name + ' -> WeBirr -> Payment Code';
                    list.appendChild(item);
                });
            }

            function setStatus(text, type, spinning) {
                var status = document.querySelector('[data-status]');
                var spinner = status.querySelector('.spinner');
                status.className = 'status' + (type && type !== 'info' ? ' ' + type : '');
                status.querySelector('[data-status-text]').textContent = text;
                spinner.style.display = spinning ? 'inline-block' : 'none';
            }

            function schedulePoll(delay) {
                clearTimeout(pollTimer);
                pollTimer = setTimeout(checkStatus, delay);
            }

            function checkStatus() {
                if (!currentPaymentId) {
                    return;
                }

                var refresh = document.querySelector('[data-action="refresh"]');
                refresh.disabled = true;
                setError('[data-payment-error]', '');
                request('/api/payment-status', {paymentId: currentPaymentId})
                    .then(function (body) {
                        if (body.complete) {
                            clearTimeout(pollTimer);
                            document.querySelector('[data-confirmed-customer]').textContent = currentCheckout.customerName || '';
                            document.querySelector('[data-confirmed-amount]').textContent = (currentCheckout.amount || '') + ' ETB';
                            document.querySelector('[data-confirmed-reference]').textContent = body.paymentReference || '';
                            document.querySelector('[data-confirmed-paid-via]').textContent = body.paidVia || '';
                            configureReceipt(body);
                            show('confirmed');
                            return;
                        }
                        setStatus('Payment not received yet.', 'info', true);
                        schedulePoll(5000);
                    })
                    .catch(function (error) {
                        setStatus('Unable to check payment status.', 'danger', false);
                        setError('[data-payment-error]', error.message || 'Unable to check payment status.');
                        refresh.disabled = false;
                    });
            }

            function restart() {
                clearTimeout(pollTimer);
                currentPaymentId = 0;
                currentCheckout = null;
                selectedBook = null;
                currentMerchantReference = '';
                show('entry');
            }

            function newMerchantReference() {
                var random = Math.floor(Math.random() * 0xffffffff).toString(16).padStart(8, '0');
                return 'ord_' + random;
            }

            function configureReceipt(status) {
                var lines = [
                    'WeBirr Online Checkout Demo',
                    '----------------------------',
                    'Digital Audio Book Purchase Receipt',
                    '',
                    'Customer Name: ' + (currentCheckout.customerName || ''),
                    'Audio Book Title: ' + (currentCheckout.itemTitle || ''),
                    'Amount: ' + (currentCheckout.amount || '') + ' ETB',
                    'Merchant Reference: ' + (currentCheckout.merchantReference || ''),
                    'WeBirr Payment Code: ' + (currentCheckout.paymentCode || ''),
                    'Payment Reference: ' + (status.paymentReference || ''),
                    'Paid Via: ' + (status.paidVia || ''),
                    'Demo Download Access: ' + (currentCheckout.itemTitle || ''),
                    ''
                ];
                var link = document.querySelector('[data-receipt-download]');
                link.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(lines.join('\\n'));
                link.download = (currentCheckout.merchantReference || 'webirr') + '-receipt.txt';
            }

            document.addEventListener('click', function (event) {
                var action = event.target && event.target.getAttribute('data-action');
                if (action === 'review') {
                    review(event.target);
                } else if (action === 'back') {
                    show('entry');
                } else if (action === 'create') {
                    createPayment();
                } else if (action === 'refresh') {
                    checkStatus();
                } else if (action === 'restart') {
                    restart();
                }
            });
        }());
    </script>
    <?php } ?>
</body>
</html>
    <?php
}
