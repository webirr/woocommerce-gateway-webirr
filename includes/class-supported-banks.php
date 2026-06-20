<?php
/**
 * Supported bank rendering helpers.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Formats merchant-supported bank instructions.
 */
final class Supported_Banks {
    /**
     * Return safe browser rows from a gateway response.
     *
     * @param mixed $response Gateway response.
     * @return array<int, array{bankid: string, bankID: string, name: string}>
     */
    public static function from_response($response): array {
        return Response_Normalizer::supported_banks($response);
    }

    /**
     * Build instruction text rows.
     *
     * @param array<int, array<string, string>> $banks Bank rows.
     * @return array<int, string>
     */
    public static function instructions(array $banks): array {
        $items = [];
        foreach ($banks as $bank) {
            $name = trim((string)($bank['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $items[] = $name . ' -> WeBirr -> Payment Code';
        }

        return $items;
    }
}

