<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles form submissions via both REST API and standard admin-post fallback.
 *
 * Security & Reliability:
 * - Cache-safe anti-spam: honeypot, time-trap, and transient IP rate limiting.
 * - Strict field whitelist based on form schema.
 * - Server re-evaluates conditional logic: hidden fields are never required and discarded.
 * - Secure file upload validation and execution barriers.
 * - Entry logging to custom database table.
 * - Multi-notification routing and asynchronous webhooks.
 * - Progressive enhancement fallback for non-JS browsers.
 */
final class REST_Controller {

    public const REST_NAMESPACE = 'nexley/v1';
    public const REST_ROUTE     = '/submit';

    /**
     * Register endpoints and fallback hooks.
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);

        // Progressive Enhancement POST fallbacks for JS-disabled browsers
        add_action('admin_post_nopriv_nexley_form_submit', [self::class, 'handle_admin_post_submission']);
        add_action('admin_post_nexley_form_submit', [self::class, 'handle_admin_post_submission']);
    }

    /**
     * Register REST API route.
     */
    public static function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_rest_submission'],
            'permission_callback' => '__return_true', // Public submission, secured via anti-spam & rate limits
        ]);
    }

    /**
     * Handle REST API submission.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle_rest_submission(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_params();
        $files  = $request->get_file_params();

        $result = self::process_form_submission($params, $files);

        $status_code = $result['status_code'] ?? ($result['success'] ? 200 : 400);

        return new \WP_REST_Response($result, $status_code);
    }

    /**
     * Handle standard admin-post.php POST fallback.
     */
    public static function handle_admin_post_submission(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $params = wp_unslash($_POST);
        $files  = $_FILES;

        $result = self::process_form_submission($params, $files);

        $redirect_to = wp_get_referer() ?: home_url();

        if (!empty($result['success'])) {
            if (!empty($result['redirect_url'])) {
                wp_safe_redirect($result['redirect_url']);
                exit;
            }
            $redirect_to = add_query_arg(['nexley_status' => 'success'], $redirect_to);
        } else {
            $redirect_to = add_query_arg(['nexley_status' => 'error'], $redirect_to);
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Core submission processor used by both REST and admin-post endpoints.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public static function process_form_submission(array $params, array $files = []): array {
        // 1. Form ID Verification
        $form_id = isset($params['_nexley_form_id']) ? absint($params['_nexley_form_id']) : 0;
        if ($form_id <= 0) {
            return [
                'success'     => false,
                'message'     => __('Invalid form identifier.', 'nexley-forms'),
                'status_code' => 400,
            ];
        }

        $form_post = get_post($form_id);
        if (!$form_post || $form_post->post_type !== Post_Types::CPT_FORM) {
            return [
                'success'     => false,
                'message'     => __('The requested form does not exist.', 'nexley-forms'),
                'status_code' => 404,
            ];
        }

        // 2. Load Form Schema
        $schema   = Form_Schema::get_form_schema($form_id);
        $settings = $schema['settings'] ?? [];

        // 3. Security, Honeypot, Time-Trap, and Rate Limiting
        $sec_check = Security::verify_submission($params, $settings);
        if (!$sec_check['passed']) {
            if ($sec_check['is_bot']) {
                // Silently discard bot submission with decoy success
                return [
                    'success'     => true,
                    'message'     => sanitize_text_field((string) ($settings['success_message'] ?? __('Thank you! Your message has been sent successfully.', 'nexley-forms'))),
                    'status_code' => 200,
                ];
            }
            return [
                'success'     => false,
                'message'     => $sec_check['error'],
                'status_code' => 429,
            ];
        }

        // 4. Extract Whitelist Fields from Schema
        $schema_fields = $schema['fields'] ?? [];
        $field_configs = [];
        foreach ($schema_fields as $f) {
            $name = sanitize_key($f['name'] ?? '');
            if ($name !== '' && Field_Registry::is_input_field($f['type'] ?? 'text')) {
                $field_configs[$name] = $f;
            }
        }

        // 5. Evaluate Server-side Conditional Logic
        // Determine which fields are visible. Hidden fields are not trusted and not required.
        $visible_fields = self::evaluate_server_visibility($schema_fields, $params);

        $sanitized_data = [];
        $errors         = [];

        // 6. Sanitize Whitelisted Inputs
        foreach ($field_configs as $name => $f_cfg) {
            if (!in_array($name, $visible_fields, true)) {
                // Field is conditionally hidden: discard input
                continue;
            }

            $type = $f_cfg['type'] ?? 'text';
            $val  = $params[$name] ?? null;

            if ($type === 'file') {
                if (isset($files[$name]) && !empty($files[$name]['name'])) {
                    $upload_res = Security::handle_file_upload($files[$name], $f_cfg);
                    if ($upload_res['success']) {
                        $sanitized_data[$name] = [
                            'file_name'     => $upload_res['file_name'],
                            'original_name' => $upload_res['original_name'],
                            'path'          => $upload_res['path'],
                        ];
                    } else {
                        $errors[$name] = $upload_res['error'];
                    }
                }
                continue;
            }

            $type_def = Field_Registry::get_field_type($type);
            if ($type_def && is_callable($type_def['sanitize_callback'] ?? null)) {
                $sanitized_data[$name] = call_user_func($type_def['sanitize_callback'], $val);
            } else {
                if (is_array($val)) {
                    $sanitized_data[$name] = array_map('sanitize_text_field', $val);
                } else {
                    $sanitized_data[$name] = sanitize_text_field((string) ($val ?? ''));
                }
            }

            // Custom type validation
            if ($type_def && is_callable($type_def['validate_callback'] ?? null)) {
                $err = call_user_func($type_def['validate_callback'], $sanitized_data[$name], $f_cfg);
                if ($err) {
                    $errors[$name] = $err;
                }
            }

            // Check required fields
            if (!empty($f_cfg['required'])) {
                $check_val = $sanitized_data[$name] ?? '';
                if ($check_val === '' || (is_array($check_val) && empty($check_val))) {
                    $custom_msg = !empty($f_cfg['validation_message']) ? $f_cfg['validation_message'] : '';
                    $errors[$name] = $custom_msg ?: sprintf(
                        /* translators: %s: Field label */
                        __('This field is required: %s', 'nexley-forms'),
                        esc_html($f_cfg['label'] ?? $name)
                    );
                }
            }
        }

        /**
         * Filters validation errors immediately before processing.
         *
         * @param array<string, string> $errors         Validation error messages.
         * @param int                   $form_id        Target form ID.
         * @param array<string, mixed>  $sanitized_data Sanitized submission data.
         */
        $errors = apply_filters('nexley_forms_validate_submission', $errors, $form_id, $sanitized_data);

        if (!empty($errors)) {
            return [
                'success'     => false,
                'message'     => __('Please fill out all required fields correctly.', 'nexley-forms'),
                'errors'      => $errors,
                'status_code' => 422,
            ];
        }

        // 7. Privacy: Process IP Address
        $raw_ip   = Security::get_client_ip();
        $clean_ip = Privacy::process_ip($raw_ip, !empty($settings['privacy_anonymize_ip']));

        // 8. Insert Entry into Custom Database Table
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $entry_id = DB::insert_entry([
            'form_id'    => $form_id,
            'status'     => 'unread',
            'data'       => $sanitized_data,
            'ip_address' => $clean_ip,
            'user_agent' => $user_agent,
        ]);

        if ($entry_id <= 0) {
            return [
                'success'     => false,
                'message'     => __('Unable to save submission. Please try again later.', 'nexley-forms'),
                'status_code' => 500,
            ];
        }

        // 9. Dispatch Multiple Email Notifications
        $mail_results = Mailer::process_submission($form_id, $sanitized_data, $schema);

        // 10. Dispatch Webhooks
        Webhooks::process_submission($form_id, $sanitized_data, $schema);

        // 11. Determine Post-Submit Action (Message vs Redirect)
        $success_msg  = sanitize_text_field((string) ($settings['success_message'] ?? __('Thank you! Your message has been sent successfully.', 'nexley-forms')));
        $redirect_url = esc_url_raw((string) ($settings['redirect_url'] ?? ''));

        foreach ($schema['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') === 'redirect' && !empty($action['redirect'])) {
                $redirect_url = esc_url_raw($action['redirect']);
            } elseif (($action['type'] ?? '') === 'message' && !empty($action['message'])) {
                $success_msg = sanitize_text_field($action['message']);
            }
        }

        return [
            'success'       => true,
            'message'       => $success_msg,
            'redirect_url'  => $redirect_url,
            'entry_id'      => $entry_id,
            'mail_sent'     => ($mail_results['sent_count'] > 0),
            'status_code'   => 200,
        ];
    }

    /**
     * Evaluate server-side visibility of fields based on their conditional rules.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $params
     * @return list<string> Names of visible fields.
     */
    public static function evaluate_server_visibility(array $fields, array $params): array {
        $visible = [];

        foreach ($fields as $field) {
            $name  = sanitize_key($field['name'] ?? '');
            $logic = $field['logic'] ?? null;

            if ($name === '') {
                continue;
            }

            if (empty($logic) || !is_array($logic) || empty($logic['rules'])) {
                $visible[] = $name;
                continue;
            }

            $action    = $logic['action'] ?? 'show';
            $match_all = ($logic['match'] ?? 'all') === 'all';
            $rules     = is_array($logic['rules']) ? $logic['rules'] : [];

            $rule_results = [];
            foreach ($rules as $r) {
                $dep_field = sanitize_key($r['field'] ?? '');
                $op        = $r['operator'] ?? 'equals';
                $expected  = (string) ($r['value'] ?? '');
                $actual    = $params[$dep_field] ?? '';

                if (is_array($actual)) {
                    $actual = implode(', ', $actual);
                }
                $actual = (string) $actual;

                $matched = false;
                switch ($op) {
                    case 'equals':
                        $matched = (strcasecmp($actual, $expected) === 0);
                        break;
                    case 'not_equals':
                        $matched = (strcasecmp($actual, $expected) !== 0);
                        break;
                    case 'contains':
                        $matched = (stripos($actual, $expected) !== false);
                        break;
                    case 'is_empty':
                        $matched = (trim($actual) === '');
                        break;
                    case 'is_not_empty':
                        $matched = (trim($actual) !== '');
                        break;
                    case 'greater_than':
                        $matched = (is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected);
                        break;
                    case 'less_than':
                        $matched = (is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected);
                        break;
                    default:
                        $matched = ($actual === $expected);
                }
                $rule_results[] = $matched;
            }

            $condition_met = $match_all ? !in_array(false, $rule_results, true) : in_array(true, $rule_results, true);
            $is_field_visible = ($action === 'show') ? $condition_met : !$condition_met;

            if ($is_field_visible) {
                $visible[] = $name;
            }
        }

        return $visible;
    }
}
