<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles generic webhook dispatch with non-blocking execution, retry/backoff, and logging.
 */
final class Webhooks {

    /**
     * Dispatch all configured webhooks for a form submission.
     *
     * @param int                  $form_id
     * @param array<string, mixed> $entry_data
     * @param array<string, mixed> $schema
     * @return list<array{url: string, success: bool, code: int, error: string}>
     */
    public static function process_submission(int $form_id, array $entry_data, array $schema = []): array {
        if (empty($schema)) {
            $schema = Form_Schema::get_form_schema($form_id);
        }

        $actions = $schema['actions'] ?? [];
        $results = [];

        foreach ($actions as $action) {
            if (($action['type'] ?? '') !== 'webhook') {
                continue;
            }

            $url = esc_url_raw((string) ($webhook_cfg['url'] ?? ''));
            if ($url === '' || !self::is_safe_webhook_url($url)) {
                continue;
            }

            $method  = strtoupper((string) ($webhook_cfg['method'] ?? 'POST'));
            $headers = is_array($webhook_cfg['headers'] ?? null) ? $webhook_cfg['headers'] : [];

            $payload = [
                'form_id'    => $form_id,
                'form_title' => $schema['title'] ?? sprintf('Form #%d', $form_id),
                'timestamp'  => current_time('mysql'),
                'fields'     => $entry_data,
            ];

            $res = self::dispatch_with_retry($url, $method, $payload, $headers);
            $results[] = $res;

            /**
             * Fires after a webhook has been dispatched.
             *
             * @param array{url: string, success: bool, code: int, error: string} $res Result info.
             * @param int                  $form_id Form post ID.
             * @param array<string, mixed> $payload Webhook request body.
             */
            do_action('nexley_forms_webhook_dispatched', $res, $form_id, $payload);
        }

        return $results;
    }

    /**
     * Dispatch HTTP request with automatic retry/backoff.
     *
     * @param string               $url
     * @param string               $method
     * @param array<string, mixed> $payload
     * @param array<string, string> $custom_headers
     * @param int                  $max_retries
     * @return array{url: string, success: bool, code: int, error: string}
     */
    public static function dispatch_with_retry(
        string $url,
        string $method,
        array $payload,
        array $custom_headers = [],
        int $max_retries = 2
    ): array {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Nexley-Forms-Webhook/' . NEXLEY_FORMS_VERSION,
        ], $custom_headers);

        $body = wp_json_encode($payload);

        $attempt = 0;
        $last_error = '';
        $response_code = 0;

        while ($attempt <= $max_retries) {
            $response = wp_safe_remote_post($url, [
                'method'             => strtoupper($method),
                'timeout'            => 5, // Fast 5-second timeout
                'blocking'           => true,
                'redirection'        => 0, // Block redirects completely to prevent SSRF via open redirect
                'headers'            => $headers,
                'body'               => $body,
                'sslverify'          => apply_filters('https_ssl_verify', true),
                'reject_unsafe_urls' => true,
            ]);

            if (!is_wp_error($response)) {
                $response_code = (int) wp_remote_retrieve_response_code($response);
                if ($response_code >= 200 && $response_code < 300) {
                    return [
                        'url'     => $url,
                        'success' => true,
                        'code'    => $response_code,
                        'error'   => '',
                    ];
                }
                $last_error = sprintf('Server responded with HTTP %d', $response_code);
            } else {
                $last_error = $response->get_error_message();
            }

            $attempt++;
            if ($attempt <= $max_retries) {
                // Exponential backoff: 200ms, 400ms
                usleep(200000 * $attempt);
            }
        }

        return [
            'url'     => $url,
            'success' => false,
            'code'    => $response_code,
            'error'   => $last_error,
        ];
    }

    /**
     * Validate that a webhook URL is safe against SSRF attacks.
     * Rejects local, private, loopback, link-local (169.254.0.0/16),
     * decimal/hex IP literals, and reserved ranges after DNS resolution.
     *
     * @param string $url
     * @return bool
     */
    public static function is_safe_webhook_url(string $url): bool {
        if (apply_filters('nexley_forms_allow_local_webhooks', false)) {
            return true;
        }

        $validated = wp_http_validate_url($url);
        if (!$validated) {
            return false;
        }

        $parsed = wp_parse_url($validated);
        if (empty($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = trim(strtolower($parsed['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // Clean IPv6 brackets
        $clean_host = trim($host, '[]');

        // Check for numeric / hex / octal IP literals
        $normalized_ip = self::normalize_ip_literal($clean_host);
        if ($normalized_ip !== null) {
            return !self::is_ip_private_or_reserved($normalized_ip);
        }

        // Block obvious internal / local domain suffixes
        $unsafe_suffixes = ['.local', '.internal', '.lan', '.home', '.corp'];
        foreach ($unsafe_suffixes as $suf) {
            if (str_ends_with($host, $suf)) {
                return false;
            }
        }
        if (in_array($host, ['localhost', 'localhost.localdomain', 'broadcasthost'], true)) {
            return false;
        }

        // Resolve DNS records (IPv4 A and IPv6 AAAA)
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!empty($records) && is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        } else {
            $a_records = @gethostbynamel($host);
            if (!empty($a_records) && is_array($a_records)) {
                $ips = array_merge($ips, $a_records);
            }
        }

        if (empty($ips)) {
            // Cannot resolve host
            return false;
        }

        // All resolved IPs must pass private/link-local/reserved checks
        foreach ($ips as $ip) {
            if (self::is_ip_private_or_reserved($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize decimal, hexadecimal, or octal IP literals into standard dotted decimal notation.
     *
     * @param string $host
     * @return string|null Dotted IP string or null if not an IP literal.
     */
    public static function normalize_ip_literal(string $host): ?string {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // Decimal integer IP (e.g. 2130706433 -> 127.0.0.1)
        if (ctype_digit($host)) {
            $long = (float) $host;
            if ($long >= 0 && $long <= 4294967295) {
                return long2ip((int) $long);
            }
        }

        // Hexadecimal IP (e.g. 0x7f000001 -> 127.0.0.1)
        if (preg_match('/^0x[0-9a-fA-F]{1,8}$/', $host)) {
            $long = hexdec(substr($host, 2));
            if ($long >= 0 && $long <= 4294967295) {
                return long2ip((int) $long);
            }
        }

        // Dotted hex / octal (e.g. 0177.0.0.1 or 0x7f.0.0.1)
        if (strpos($host, '.') !== false) {
            $parts = explode('.', $host);
            if (count($parts) === 4) {
                $dec_parts = [];
                foreach ($parts as $p) {
                    if (preg_match('/^0x[0-9a-fA-F]+$/i', $p)) {
                        $dec_parts[] = hexdec($p);
                    } elseif (preg_match('/^0[0-7]+$/', $p)) {
                        $dec_parts[] = octdec($p);
                    } elseif (ctype_digit($p)) {
                        $dec_parts[] = (int) $p;
                    } else {
                        return null;
                    }
                }
                $candidate = implode('.', $dec_parts);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Check if an IP address falls into private, loopback, link-local, or reserved ranges.
     *
     * @param string $ip
     * @return bool True if private/reserved/link-local (UNSAFE), false if public (SAFE).
     */
    public static function is_ip_private_or_reserved(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Built-in PHP flags check RFC 1918 private and RFC 5735 reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // IPv4 explicit link-local, loopback, and cloud metadata checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            // Link-local: 169.254.0.0/16 (including AWS/GCP metadata 169.254.169.254)
            $link_local_start = ip2long('169.254.0.0');
            $link_local_end   = ip2long('169.254.255.255');
            if ($long >= $link_local_start && $long <= $link_local_end) {
                return true;
            }

            // Loopback: 127.0.0.0/8
            $loopback_start = ip2long('127.0.0.0');
            $loopback_end   = ip2long('127.255.255.255');
            if ($long >= $loopback_start && $long <= $loopback_end) {
                return true;
            }

            // Current network: 0.0.0.0/8
            if ($long >= 0 && $long <= ip2long('0.255.255.255')) {
                return true;
            }
        }

        // IPv6 link-local, loopback, and unique local checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || $lower === '::') {
                return true;
            }
            // Link-local: fe80::/10
            if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                return true;
            }
            // Unique local: fc00::/7
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return true;
            }
        }

        return false;
    }
}
