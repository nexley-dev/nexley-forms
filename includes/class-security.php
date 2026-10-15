<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles submission security, anti-spam, and secure file handling:
 * - Cache-safe bot defense: Honeypot, client-stamped time-trap, and transient IP rate-limiting.
 * - Cloudflare Turnstile / hCaptcha verification.
 * - Protected file upload directory with execution barriers (.htaccess + index.php).
 */
final class Security {

    /**
     * Hook WordPress actions for authenticated uploads.
     */
    public static function init(): void {
        add_action('admin_post_nexley_download_upload', [self::class, 'handle_authenticated_download']);
    }

    /**
     * Minimum interaction threshold in milliseconds for client time-trap.
     */
    public const TIME_TRAP_MIN_MS = 3000; // 3 seconds

    /**
     * Maximum interaction threshold in milliseconds (24 hours).
     */
    public const TIME_TRAP_MAX_MS = 86400000;

    /**
     * Validate honeypot, time-trap, and rate-limiting.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $settings
     * @return array{passed: bool, is_bot: bool, error: string}
     */
    public static function verify_submission(array $params, array $settings): array {
        // 1. Honeypot check: If filled, silently discard as bot
        if (!empty($settings['honeypot'])) {
            $hp = isset($params['_nexley_hp']) ? trim((string) $params['_nexley_hp']) : '';
            if ($hp !== '') {
                return [
                    'passed' => false,
                    'is_bot' => true,
                    'error'  => __('Bot submission detected.', 'nexley-forms'),
                ];
            }
        }

        // 2. Client-stamped Time-Trap check:
        // Cache-safe rule: If JS is enabled, client stamped Date.now().
        // If JS is disabled (standard POST fallback), _nexley_tt is empty, so we skip it.
        if (!empty($settings['time_trap']) && !empty($params['_nexley_tt'])) {
            $client_time = (float) $params['_nexley_tt'];
            $now_ms      = microtime(true) * 1000;
            $elapsed_ms  = $now_ms - $client_time;

            if ($elapsed_ms < self::TIME_TRAP_MIN_MS) {
                return [
                    'passed' => false,
                    'is_bot' => true,
                    'error'  => __('Form was submitted too quickly. Please take your time.', 'nexley-forms'),
                ];
            }

            if ($elapsed_ms > self::TIME_TRAP_MAX_MS) {
                return [
                    'passed' => false,
                    'is_bot' => true,
                    'error'  => __('Form session expired. Please refresh the page.', 'nexley-forms'),
                ];
            }
        }

        // 3. Transient IP Rate Limiting
        $rate_limit = isset($settings['rate_limit']) ? absint($settings['rate_limit']) : 10;
        if ($rate_limit > 0) {
            $ip = self::get_client_ip();
            $transient_key = 'nexley_rate_' . md5($ip);
            $current_count = (int) get_transient($transient_key);

            if ($current_count >= $rate_limit) {
                return [
                    'passed' => false,
                    'is_bot' => false,
                    'error'  => __('Too many submissions from your IP address. Please wait a while before trying again.', 'nexley-forms'),
                ];
            }

            set_transient($transient_key, $current_count + 1, HOUR_IN_SECONDS);
        }

        // 4. Captcha verification if configured (Turnstile or hCaptcha)
        $captcha_type = $settings['captcha_type'] ?? 'none';
        if ($captcha_type === 'turnstile') {
            $token = (string) ($params['cf-turnstile-response'] ?? '');
            $secret = (string) ($settings['captcha_secret_key'] ?? '');
            if (!self::verify_turnstile($token, $secret)) {
                return [
                    'passed' => false,
                    'is_bot' => true,
                    'error'  => __('Cloudflare Turnstile verification failed. Please try again.', 'nexley-forms'),
                ];
            }
        } elseif ($captcha_type === 'hcaptcha') {
            $token = (string) ($params['h-captcha-response'] ?? '');
            $secret = (string) ($settings['captcha_secret_key'] ?? '');
            if (!self::verify_hcaptcha($token, $secret)) {
                return [
                    'passed' => false,
                    'is_bot' => true,
                    'error'  => __('hCaptcha verification failed. Please try again.', 'nexley-forms'),
                ];
            }
        }

        return [
            'passed' => true,
            'is_bot' => false,
            'error'  => '',
        ];
    }

    /**
     * Verify Cloudflare Turnstile token via Cloudflare API.
     *
     * @param string $token
     * @param string $secret
     * @return bool
     */
    public static function verify_turnstile(string $token, string $secret): bool {
        if ($token === '' || $secret === '') {
            return false;
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => self::get_client_ip(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($body['success']);
    }

    /**
     * Verify hCaptcha token via hCaptcha API.
     *
     * @param string $token
     * @param string $secret
     * @return bool
     */
    public static function verify_hcaptcha(string $token, string $secret): bool {
        if ($token === '' || $secret === '') {
            return false;
        }

        $response = wp_remote_post('https://hcaptcha.com/siteverify', [
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => self::get_client_ip(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($body['success']);
    }

    /**
     * Get protected upload directory path outside the public web root.
     * Falls back to wp-content/uploads/nexley-forms only if external path is unwritable.
     *
     * @return string Absolute directory path.
     */
    public static function get_upload_dir(): string {
        $custom_dir = apply_filters('nexley_forms_upload_dir', defined('NEXLEY_FORMS_UPLOAD_DIR') ? NEXLEY_FORMS_UPLOAD_DIR : null);

        if (!empty($custom_dir) && is_string($custom_dir)) {
            $dir = untrailingslashit($custom_dir);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (is_dir($dir) && wp_is_writable($dir)) {
                delete_option('nexley_forms_upload_fallback_notice');
                return $dir;
            }
        }

        // Default secure path: sibling of public document root (outside public web server path)
        $sibling_dir = dirname(untrailingslashit(ABSPATH)) . '/nexley-forms-uploads';
        if (!is_dir($sibling_dir)) {
            @mkdir($sibling_dir, 0755, true);
        }

        if (is_dir($sibling_dir) && wp_is_writable($sibling_dir)) {
            delete_option('nexley_forms_upload_fallback_notice');
            self::ensure_directory_barriers($sibling_dir);
            return $sibling_dir;
        }

        // Fallback: inside wp-content/uploads/nexley-forms (requires web server execution denial)
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/nexley-forms';

        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        update_option('nexley_forms_upload_fallback_notice', 1);
        self::ensure_directory_barriers($target_dir);

        return $target_dir;
    }

    /**
     * Ensure execution barriers (.htaccess + index.php) exist in a directory.
     *
     * @param string $dir
     */
    private static function ensure_directory_barriers(string $dir): void {
        $htaccess_file = $dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_rules = "# Deny direct access and execution of scripts\n"
                . "<IfModule mod_authz_core.c>\n"
                . "Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "Deny from all\n"
                . "</IfModule>\n"
                . "<FilesMatch \"\\.(php|phtml|php[0-9]|phps|phar|pl|py|cgi)$\">\n"
                . "Deny from all\n"
                . "</FilesMatch>\n"
                . "Options -Indexes\n";
            @file_put_contents($htaccess_file, $htaccess_rules);
        }

        $index_file = $dir . '/index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n// Silence is golden.\nexit;\n");
        }
    }

    /**
     * Handle single secure file upload.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file_info
     * @param array<string, mixed> $field_config
     * @return array{success: bool, url: string, path: string, file_name: string, original_name: string, error: string}
     */
    public static function handle_file_upload(array $file_info, array $field_config): array {
        if (!isset($file_info['tmp_name']) || !is_uploaded_file($file_info['tmp_name'])) {
            return ['success' => false, 'url' => '', 'path' => '', 'file_name' => '', 'original_name' => '', 'error' => __('Invalid file upload.', 'nexley-forms')];
        }

        // Size check (field limit vs max upload)
        $max_mb = isset($field_config['max_size_mb']) ? (float) $field_config['max_size_mb'] : 5.0;
        $max_bytes = (int) ($max_mb * 1048576);

        if ($file_info['size'] > $max_bytes) {
            return [
                'success'       => false,
                'url'           => '',
                'path'          => '',
                'file_name'     => '',
                'original_name' => '',
                'error'         => sprintf(__('File size exceeds the allowed limit of %s MB.', 'nexley-forms'), $max_mb),
            ];
        }

        // Strict extension and MIME check using wp_check_filetype_and_ext
        $allowed_ext_str = (string) ($field_config['allowed_ext'] ?? 'pdf,doc,docx,jpg,jpeg,png,webp,zip');
        $allowed_exts    = array_map('trim', explode(',', strtolower($allowed_ext_str)));

        // Build mimes map from WP allowed mimes
        $wp_mimes = get_allowed_mime_types();
        $filtered_mimes = [];
        foreach ($allowed_exts as $ext) {
            foreach ($wp_mimes as $ext_pattern => $mime) {
                $exts = explode('|', $ext_pattern);
                if (in_array($ext, $exts, true)) {
                    $filtered_mimes[$ext] = $mime;
                }
            }
        }

        $checked = wp_check_filetype_and_ext($file_info['tmp_name'], $file_info['name'], $filtered_mimes);
        if (empty($checked['ext']) || empty($checked['type'])) {
            return [
                'success'       => false,
                'url'           => '',
                'path'          => '',
                'file_name'     => '',
                'original_name' => '',
                'error'         => __('File type not permitted for security reasons.', 'nexley-forms'),
            ];
        }

        // Generate randomized secure filename
        $safe_ext = sanitize_key($checked['ext']);
        $random_name = bin2hex(random_bytes(16)) . '.' . $safe_ext;

        $target_dir  = self::get_upload_dir();
        $target_path = $target_dir . '/' . $random_name;

        if (!move_uploaded_file($file_info['tmp_name'], $target_path)) {
            return [
                'success'       => false,
                'url'           => '',
                'path'          => '',
                'file_name'     => '',
                'original_name' => '',
                'error'         => __('Failed to store uploaded file on server.', 'nexley-forms'),
            ];
        }

        return [
            'success'       => true,
            'url'           => '', // Resolved via entry ID + field key
            'path'          => $target_path,
            'file_name'     => $random_name,
            'original_name' => sanitize_file_name($file_info['name']),
            'error'         => '',
        ];
    }

    /**
     * Authenticated download handler for stored file uploads.
     * Requires entries screen capability (default: manage_options) and resolves files
     * exclusively by entry ID + field key from the database, preventing path exposure.
     */
    public static function handle_authenticated_download(): void {
        $capability = apply_filters('nexley_forms_download_capability', apply_filters('nexley_forms_entries_capability', 'manage_options'));
        if (!is_user_logged_in() || !current_user_can($capability)) {
            wp_die(esc_html__('Unauthorized access to upload.', 'nexley-forms'), 403);
        }

        $entry_id  = isset($_GET['entry_id']) ? absint($_GET['entry_id']) : 0;
        $field_key = isset($_GET['field']) ? sanitize_key($_GET['field']) : '';

        if ($entry_id === 0 || empty($field_key)) {
            wp_die(esc_html__('Invalid download request: entry ID and field key are required.', 'nexley-forms'), 400);
        }

        $entry = DB::get_entry($entry_id);
        if (!$entry) {
            wp_die(esc_html__('Entry not found.', 'nexley-forms'), 404);
        }

        $fields = $entry->data;
        if (!isset($fields[$field_key])) {
            wp_die(esc_html__('File field not found in entry.', 'nexley-forms'), 404);
        }

        $file_meta = $fields[$field_key];
        $file_name = '';
        $original_name = '';

        if (is_array($file_meta)) {
            $file_name     = basename((string) ($file_meta['file_name'] ?? ''));
            $original_name = sanitize_file_name((string) ($file_meta['original_name'] ?? $file_name));
        } elseif (is_string($file_meta)) {
            $file_name     = basename(parse_url($file_meta, PHP_URL_PATH) ?: $file_meta);
            $original_name = $file_name;
        }

        if (empty($file_name)) {
            wp_die(esc_html__('No file recorded for this field.', 'nexley-forms'), 404);
        }

        $upload_dir  = self::get_upload_dir();
        $target_path = $upload_dir . '/' . $file_name;

        $real_dir  = realpath($upload_dir);
        $real_path = realpath($target_path);

        if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0 || !file_exists($real_path)) {
            wp_die(esc_html__('File not found on server.', 'nexley-forms'), 404);
        }

        $filetype = wp_check_filetype($real_path);
        $mime     = !empty($filetype['type']) ? $filetype['type'] : 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . esc_attr($original_name ?: $file_name) . '"');
        header('Content-Length: ' . (string) filesize($real_path));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($real_path);
        exit;
    }

    /**
     * Get client IP address for rate limiting and audit logging.
     * Defaults strictly to REMOTE_ADDR. Honors trusted proxy headers only when
     * REMOTE_ADDR is in a configured trusted proxy range.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
        $ip = $remote_addr;

        $trusted_header = apply_filters('nexley_forms_trusted_proxy_header', (string) get_option('nexley_forms_trusted_proxy_header', ''));
        $trusted_proxies = apply_filters('nexley_forms_trusted_proxies', (array) get_option('nexley_forms_trusted_proxies', []));

        if (!empty($trusted_header) && !empty($trusted_proxies) && self::is_ip_in_trusted_range($remote_addr, $trusted_proxies)) {
            $header_key = 'HTTP_' . strtoupper(str_replace('-', '_', $trusted_header));
            if (!empty($_SERVER[$header_key])) {
                $raw_val = sanitize_text_field(wp_unslash($_SERVER[$header_key]));
                // In comma-separated lists (e.g. X-Forwarded-For: client, proxy1), pick first non-empty IP
                $parts = array_map('trim', explode(',', $raw_val));
                foreach ($parts as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        $ip = $candidate;
                        break;
                    }
                }
            }
        }

        /**
         * Filters the detected client IP address.
         *
         * @param string $ip      Detected client IP address.
         * @param array  $_SERVER Server superglobal copy.
         */
        return (string) apply_filters('nexley_forms_client_ip', $ip, $_SERVER);
    }

    /**
     * Helper to verify if an IP is within an array of trusted IPs or CIDR blocks.
     *
     * @param string $ip
     * @param array<int, string> $trusted_ranges
     * @return bool
     */
    public static function is_ip_in_trusted_range(string $ip, array $trusted_ranges): bool {
        $ip_long = ip2long($ip);
        if ($ip_long === false) {
            return false;
        }

        foreach ($trusted_ranges as $range) {
            $range = trim($range);
            if (empty($range)) {
                continue;
            }

            if (strpos($range, '/') === false) {
                if ($ip === $range) {
                    return true;
                }
                continue;
            }

            [$subnet, $bits] = explode('/', $range, 2);
            $subnet_long = ip2long($subnet);
            $bits = (int) $bits;

            if ($subnet_long !== false && $bits >= 0 && $bits <= 32) {
                $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
                if (($ip_long & $mask) === ($subnet_long & $mask)) {
                    return true;
                }
            }
        }

        return false;
    }
}
