<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles REST API submissions at /nexley/v1/submit:
 * - Security & nonce verification
 * - Honeypot anti-spam check
 * - Input validation & sanitization
 * - Database logging into nexley_entry CPT
 * - Triggering email notifications & auto-responders
 * - Standardized JSON responses
 */
final class REST_Controller {

    public const REST_NAMESPACE = 'nexley/v1';
    public const REST_ROUTE     = '/submit';

    /**
     * Register REST API routes.
     */
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register the /nexley/v1/submit endpoint.
     */
    public static function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => \WP_REST_Server::CREATABLE, // POST
            'callback'            => [self::class, 'handle_submission'],
            'permission_callback' => '__return_true', // Public submission, secured via nonces & honeypot.
        ]);
    }

    /**
     * Submission handler.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle_submission(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_params();

        // 1. Honeypot Anti-Spam Check.
        $honeypot = isset($params['_nexley_hp']) ? (string) $params['_nexley_hp'] : '';
        if ($honeypot !== '') {
            // Silently discard bot submission with decoy success.
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
            ], 200);
        }

        // 2. Nonce Verification.
        $nonce = $request->get_header('x_wp_nonce') ?: ($params['_nexley_nonce'] ?? '');
        if (empty($nonce) || !wp_verify_nonce((string) $nonce, 'wp_rest')) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Security verification failed. Please refresh the page and try again.', 'nexley-forms'),
                'errors'  => ['_security' => __('Invalid security token.', 'nexley-forms')],
            ], 403);
        }

        // 3. Form ID Verification.
        $form_id = isset($params['_nexley_form_id']) ? absint($params['_nexley_form_id']) : 0;
        if ($form_id <= 0) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Invalid form identifier.', 'nexley-forms'),
            ], 400);
        }

        $form_post = get_post($form_id);
        if (!$form_post || $form_post->post_type !== Post_Types::CPT_FORM) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('The requested form does not exist.', 'nexley-forms'),
            ], 404);
        }

        // 4. Extract expected fields and validate inputs.
        $template       = (string) get_post_meta($form_id, '_nexley_form_content', true);
        $expected_fields = Form_Parser::extract_form_fields($template);

        $sanitized_data = [];
        $errors         = [];

        // Loop through all incoming params to sanitize them.
        foreach ($params as $raw_key => $raw_value) {
            $key = sanitize_key($raw_key);

            // Skip internal meta keys.
            if (strpos($key, '_nexley_') === 0) {
                continue;
            }

            $field_config = $expected_fields[$key] ?? null;
            $type         = $field_config['type'] ?? 'text';

            if (is_array($raw_value)) {
                $sanitized_data[$key] = array_map('sanitize_text_field', $raw_value);
            } elseif ($type === 'email') {
                $clean_email = sanitize_email((string) $raw_value);
                $sanitized_data[$key] = $clean_email;
                if ($clean_email !== '' && !is_email($clean_email)) {
                    $errors[$key] = __('Please provide a valid email address.', 'nexley-forms');
                }
            } elseif ($type === 'url') {
                $sanitized_data[$key] = esc_url_raw((string) $raw_value);
            } elseif ($type === 'textarea') {
                $sanitized_data[$key] = sanitize_textarea_field((string) $raw_value);
            } else {
                $sanitized_data[$key] = sanitize_text_field((string) $raw_value);
            }
        }

        // Check required fields based on template configuration.
        foreach ($expected_fields as $field_name => $config) {
            if ($config['required']) {
                $val = $sanitized_data[$field_name] ?? '';
                if ($val === '' || (is_array($val) && empty($val))) {
                    $errors[$field_name] = sprintf(
                        /* translators: %s: Field label */
                        __('This field is required: %s', 'nexley-forms'),
                        ucwords(str_replace(['-', '_'], ' ', $field_name))
                    );
                }
            }
        }

        if (!empty($errors)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Please fill out all required fields correctly.', 'nexley-forms'),
                'errors'  => $errors,
            ], 422);
        }

        // 5. Save submission to the database as `nexley_entry` CPT.
        $form_title  = !empty($form_post->post_title)
            ? $form_post->post_title
            : sprintf(
                /* translators: %d: Form ID number */
                __('Form #%d', 'nexley-forms'),
                $form_id
            );
        $entry_title = sprintf('Entry for %s (ID %d)', $form_title, $form_id);

        // Optionally append submitter name if available.
        $client_name = $sanitized_data['client-name'] ?? $sanitized_data['name'] ?? '';
        if (!empty($client_name)) {
            $entry_title .= ' - ' . sanitize_text_field((string) $client_name);
        }

        $entry_id = wp_insert_post([
            'post_type'    => Post_Types::CPT_ENTRY,
            'post_title'   => $entry_title,
            'post_status'  => 'publish',
            'post_author'  => 0,
        ], true);

        if (is_wp_error($entry_id)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Unable to save submission. Please try again later.', 'nexley-forms'),
            ], 500);
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // Save entry metadata.
        update_post_meta($entry_id, '_nexley_form_id', $form_id);
        update_post_meta($entry_id, '_nexley_form_title', $form_title);
        update_post_meta($entry_id, '_nexley_submitted_fields', $sanitized_data);
        update_post_meta($entry_id, '_nexley_user_ip', Mailer::get_user_ip());
        update_post_meta($entry_id, '_nexley_user_agent', $user_agent);
        update_post_meta($entry_id, '_nexley_submission_time', current_time('mysql'));

        // 6. Process emails: Admin Notification and Client Auto-Responder.
        $mail_results = Mailer::process_submission($form_id, $sanitized_data);

        // 7. Return JSON response.
        return new \WP_REST_Response([
            'success'            => true,
            'message'            => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
            'entry_id'           => $entry_id,
            'admin_mail_sent'    => $mail_results['admin_mail_sent'],
            'autoresponder_sent' => $mail_results['autoresponder_sent'],
        ], 200);
    }
}
