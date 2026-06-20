<?php
/**
 * Gateway response helpers.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Normalizes WeBirr API response variants used by current SDKs.
 */
final class Response_Normalizer {
    /**
     * Return the gateway error message when present.
     *
     * @param mixed $response Gateway response.
     * @return string
     */
    public static function error($response): string {
        $value = self::value($response, 'error');
        return trim((string)$value);
    }

    /**
     * Extract response data.
     *
     * @param mixed $response Gateway response.
     * @return mixed
     */
    public static function result($response) {
        if (is_object($response) && property_exists($response, 'res')) {
            return $response->res;
        }

        if (is_array($response) && array_key_exists('res', $response)) {
            return $response['res'];
        }

        return null;
    }

    /**
     * Extract a payment code from create or get-bill responses.
     *
     * @param mixed $response Gateway response.
     * @return string
     */
    public static function payment_code($response): string {
        $res = self::result($response);
        if (is_scalar($res)) {
            return trim((string)$res);
        }

        foreach (['wbcCode', 'paymentCode', 'wbc_code', 'paymentcode'] as $key) {
            $value = self::nested_value($response, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract payment status value.
     *
     * @param mixed $response Gateway response.
     * @return int
     */
    public static function payment_status($response): int {
        foreach (['status', 'paymentStatus'] as $key) {
            $value = self::nested_value($response, $key);
            if ($value !== '') {
                return (int)$value;
            }
        }

        $is_paid = self::nested_value($response, 'isPaid');
        if ($is_paid !== '') {
            return filter_var($is_paid, FILTER_VALIDATE_BOOLEAN) ? 2 : 0;
        }

        return 0;
    }

    /**
     * Determine whether a payment or bill is paid.
     *
     * @param mixed $response Gateway response.
     * @return bool
     */
    public static function is_paid($response): bool {
        return self::payment_status($response) === 2;
    }

    /**
     * Extract a payment reference after payment.
     *
     * @param mixed $response Gateway response.
     * @return string
     */
    public static function payment_reference($response): string {
        foreach (['paymentReference', 'reference', 'paymentRef', 'transactionId'] as $key) {
            $value = self::nested_value($response, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract paid-via issuer display text.
     *
     * @param mixed $response Gateway response.
     * @return string
     */
    public static function payment_issuer($response): string {
        foreach (['bankName', 'paymentIssuer', 'issuerName', 'bankID', 'bankId'] as $key) {
            $value = self::nested_value($response, $key);
            if ($value !== '') {
                return self::format_issuer($value);
            }
        }

        return '';
    }

    /**
     * Extract paid timestamp.
     *
     * @param mixed $response Gateway response.
     * @return string
     */
    public static function payment_date($response): string {
        foreach (['paymentDate', 'time', 'paidAt', 'updateTimeStamp'] as $key) {
            $value = self::nested_value($response, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract a scalar bill value.
     *
     * @param mixed $response Gateway response.
     * @param string $key Field name.
     * @return string
     */
    public static function bill_value($response, string $key): string {
        return self::nested_value($response, $key);
    }

    /**
     * Determine whether an error blocks retrying with create bill.
     *
     * @param string $error Gateway/client error.
     * @return bool
     */
    public static function is_transport_error(string $error): bool {
        return preg_match('/^(http error|invalid response|WordPress HTTP|Unable to encode|missing WeBirr)/i', $error) === 1;
    }

    /**
     * Normalize supported bank response rows.
     *
     * @param mixed $response Gateway response or result rows.
     * @return array<int, array{bankid: string, bankID: string, name: string}>
     */
    public static function supported_banks($response): array {
        $rows = self::result($response);
        if ($rows === null) {
            $rows = $response;
        }

        if (!is_array($rows)) {
            return [];
        }

        $banks = [];
        foreach ($rows as $row) {
            $bankid = trim((string)self::value($row, 'bankID'));
            if ($bankid === '') {
                $bankid = trim((string)self::value($row, 'bankid'));
            }

            $name = trim((string)self::value($row, 'name'));
            if ($bankid === '' || $name === '') {
                continue;
            }

            $banks[] = [
                'bankid' => $bankid,
                'bankID' => $bankid,
                'name' => $name,
            ];
        }

        return $banks;
    }

    /**
     * Read a direct value from object or array.
     *
     * @param mixed $node Object or array.
     * @param string $key Field name.
     * @return mixed
     */
    private static function value($node, string $key) {
        if (is_object($node) && isset($node->$key)) {
            return $node->$key;
        }

        if (is_array($node) && array_key_exists($key, $node)) {
            return $node[$key];
        }

        return '';
    }

    /**
     * Read a scalar from common response nesting shapes.
     *
     * @param mixed $response Gateway response.
     * @param string $key Field name.
     * @return string
     */
    private static function nested_value($response, string $key): string {
        $nodes = [$response];
        $res = self::result($response);
        if (is_object($res) || is_array($res)) {
            $nodes[] = $res;
            $data = self::value($res, 'data');
            if (is_object($data) || is_array($data)) {
                $nodes[] = $data;
            }
        }

        foreach ($nodes as $node) {
            $value = self::value($node, $key);
            if (is_scalar($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Convert bank IDs such as cbe_mobile into display text.
     *
     * @param string $issuer Raw issuer.
     * @return string
     */
    private static function format_issuer(string $issuer): string {
        $words = preg_split('/[\s_-]+/', trim($issuer)) ?: [];
        $formatted = array_map(
            static function(string $word): string {
                return strlen($word) <= 3 ? strtoupper($word) : ucfirst(strtolower($word));
            },
            $words
        );

        return implode(' ', $formatted);
    }
}

