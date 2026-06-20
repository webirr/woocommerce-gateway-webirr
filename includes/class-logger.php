<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package WeBirr\WooCommerceGateway
 */

namespace WeBirr\WooCommerceGateway;

defined('ABSPATH') || exit;

/**
 * Logs diagnostic messages while redacting credentials.
 */
final class Logger {
    /** @var bool */
    private bool $enabled;

    /** @var string */
    private string $api_key;

    /**
     * @param bool $enabled Whether debug logging is enabled.
     * @param string $api_key API key to redact when present.
     */
    public function __construct(bool $enabled = false, string $api_key = '') {
        $this->enabled = $enabled;
        $this->api_key = $api_key;
    }

    /**
     * Log an informational message when enabled.
     *
     * @param string $message Message text.
     * @param array<string, mixed> $context Optional context.
     * @return void
     */
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    /**
     * Log an error message when enabled.
     *
     * @param string $message Message text.
     * @param array<string, mixed> $context Optional context.
     * @return void
     */
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    /**
     * Redact known sensitive values.
     *
     * @param string $value Text to redact.
     * @return string
     */
    public function redact(string $value): string {
        $redacted = $value;

        if ($this->api_key !== '') {
            $redacted = str_replace($this->api_key, '[redacted]', $redacted);
        }

        $redacted = preg_replace('/api_key=([^&\s]+)/i', 'api_key=[redacted]', $redacted) ?? $redacted;
        $redacted = preg_replace('/("api_key"\s*:\s*")([^"]+)(")/i', '$1[redacted]$3', $redacted) ?? $redacted;

        return $redacted;
    }

    /**
     * Write to WooCommerce logger.
     *
     * @param string $level Log level.
     * @param string $message Message text.
     * @param array<string, mixed> $context Optional context.
     * @return void
     */
    private function log(string $level, string $message, array $context): void {
        if (!$this->enabled || !function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $payload = $context === [] ? '' : ' ' . wp_json_encode($context);
        $logger->log(
            $level,
            $this->redact($message . $payload),
            ['source' => 'webirr']
        );
    }
}

