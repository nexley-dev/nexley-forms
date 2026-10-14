<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles multiple email notifications with conditional routing, dynamic tag replacement,
 * header-injection protection, and WP Mail SMTP compatibility.
 */
final class Mailer {

    /**
     * Process and dispatch all notifications configured for a form submission.
     *
     * @param int                  $form_id
     * @param array<string, mixed> $submitted_data
     * @param array<string, mixed> $schema
     * @return array{sent_count: int, failed_count: int}
     */
    public static function process_submission(int $form_id, array $submitted_data, array $schema = [], string $override_to = ''): array {
        if (empty($schema)) {
            $schema = Form_Schema::get_form_schema($form_id);
        }

        $notifications = $schema['notifications'] ?? [];
        $sent_count    = 0;
        $failed_count  = 0;

        foreach ($notifications as $notification) {
            // Check if notification is active
            if (isset($notification['active']) && !$notification['active']) {
                continue;
            }

            // Check conditional routing (skip condition if manual resend with override_to)
            if ($override_to === '' && !self::should_send_notification($notification, $submitted_data)) {
                continue;
            }

            if (!empty($override_to)) {
                $notification['to'] = $override_to;
            }

            $sent = self::send_notification($notification, $form_id, $submitted_data);
            if ($sent) {
                $sent_count++;
            } else {
                $failed_count++;
            }
        }

        return [
            'sent_count'   => $sent_count,
            'failed_count' => $failed_count,
        ];
    }

    /**
     * Evaluate conditional logic for a notification.
     *
     * @param array<string, mixed> $notification
     * @param array<string, mixed> $submitted_data
     * @return bool
     */
    public static function should_send_notification(array $notification, array $submitted_data): bool {
        $conditions = $notification['conditions'] ?? [];
        if (empty($conditions)) {
            return true;
        }

        $match_all = ($conditions['match'] ?? 'all') === 'all';
        $rules     = is_array($conditions['rules'] ?? null) ? $conditions['rules'] : [];

        if (empty($rules)) {
            return true;
        }

        $results = [];
        foreach ($rules as $rule) {
            $field_name = sanitize_key($rule['field'] ?? '');
            $operator   = $rule['operator'] ?? 'equals';
            $expected   = (string) ($rule['value'] ?? '');
            $actual     = $submitted_data[$field_name] ?? '';

            if (is_array($actual)) {
                $actual = implode(', ', $actual);
            }
            $actual = (string) $actual;

            $pass = false;
            switch ($operator) {
                case 'equals':
                    $pass = (strcasecmp($actual, $expected) === 0);
                    break;
                case 'not_equals':
                    $pass = (strcasecmp($actual, $expected) !== 0);
                    break;
                case 'contains':
                    $pass = (stripos($actual, $expected) !== false);
                    break;
                case 'is_empty':
                    $pass = (trim($actual) === '');
                    break;
                case 'is_not_empty':
                    $pass = (trim($actual) !== '');
                    break;
                default:
                    $pass = ($actual === $expected);
            }
            $results[] = $pass;
        }

        return $match_all ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    /**
     * Send a single notification email.
     *
     * @param array<string, mixed> $notification
     * @param int                  $form_id
     * @param array<string, mixed> $submitted_data
     * @return bool
     */
    public static function send_notification(array $notification, int $form_id, array $submitted_data): bool {
        $to_template      = (string) ($notification['to'] ?? '{admin_email}');
        $subject_template = (string) ($notification['subject'] ?? sprintf('[%s] New submission: {form_title}', get_bloginfo('name')));
        $body_template    = (string) ($notification['body'] ?? "{_all_fields}");
        $reply_to_tag     = (string) ($notification['reply_to'] ?? '');

        // Tag replacement
        $to_resolved      = self::replace_tags($to_template, $submitted_data, $form_id);
        $subject_resolved = self::replace_tags($subject_template, $submitted_data, $form_id);
        $body_resolved    = self::replace_tags($body_template, $submitted_data, $form_id);

        // Header injection defense: strip \r and \n from headers
        $clean_subject = sanitize_text_field(str_replace(["\r", "\n"], '', $subject_resolved));

        // Parse recipients
        $recipients = self::parse_recipients($to_resolved);
        if (empty($recipients)) {
            $recipients = [(string) get_option('admin_email')];
        }

        // Determine Reply-To
        $reply_email = '';
        if ($reply_to_tag !== '') {
            $resolved_reply = self::replace_tags($reply_to_tag, $submitted_data, $form_id);
            if (is_email($resolved_reply)) {
                $reply_email = sanitize_email($resolved_reply);
            }
        }

        // Automatic fallback for reply-to from submitted email if not explicitly matched
        if ($reply_email === '') {
            foreach ($submitted_data as $key => $val) {
                if (is_string($val) && is_email($val) && stripos((string) $key, 'email') !== false) {
                    $reply_email = sanitize_email($val);
                    break;
                }
            }
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        if ($reply_email !== '') {
            $headers[] = sprintf('Reply-To: <%s>', str_replace(["\r", "\n", '"', "'"], '', $reply_email));
        }

        $formatted_body = self::format_html_message($body_resolved, $clean_subject);

        /**
         * Filters notification parameters immediately before calling wp_mail().
         *
         * @param array{to: list<string>, subject: string, message: string, headers: list<string>} $args
         * @param int                  $form_id
         * @param array<string, mixed> $notification
         */
        $mail_args = apply_filters('nexley_forms_pre_send_notification', [
            'to'      => $recipients,
            'subject' => $clean_subject,
            'message' => $formatted_body,
            'headers' => $headers,
        ], $form_id, $notification);

        $sent = wp_mail(
            $mail_args['to'],
            $mail_args['subject'],
            $mail_args['message'],
            $mail_args['headers']
        );

        if ($sent) {
            /**
             * Fires after an email notification has been dispatched successfully.
             *
             * @param array<string, mixed> $notification
             * @param int                  $form_id
             * @param array<string, mixed> $submitted_data
             */
            do_action('nexley_forms_notification_sent', $notification, $form_id, $submitted_data);
        }

        return (bool) $sent;
    }

    /**
     * Replace dynamic {field} tags and system tags in template text.
     *
     * @param string               $template
     * @param array<string, mixed> $data
     * @param int                  $form_id
     * @return string
     */
    public static function replace_tags(string $template, array $data, int $form_id = 0): string {
        if ($template === '') {
            return '';
        }

        // Format all fields table
        $all_fields_html = self::generate_all_fields_list($data);

        // System tags
        $system_tags = [
            '{site_title}'   => get_bloginfo('name'),
            '{site_url}'     => home_url(),
            '{admin_email}'  => (string) get_option('admin_email'),
            '{form_id}'      => (string) $form_id,
            '{form_title}'   => get_the_title($form_id) ?: sprintf('Form #%d', $form_id),
            '{date}'         => wp_date('Y-m-d'),
            '{time}'         => wp_date('H:i:s'),
            '{ip}'           => Security::get_client_ip(),
            '{_all_fields}'  => $all_fields_html,
        ];

        $output = str_replace(array_keys($system_tags), array_values($system_tags), $template);

        // Dynamic field tags: {field_name}
        $output = (string) preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', static function (array $matches) use ($data): string {
            $key = $matches[1];
            if (isset($data[$key])) {
                $val = $data[$key];
                if (is_array($val)) {
                    return implode(', ', array_map('sanitize_text_field', $val));
                }
                return (string) $val;
            }
            return '';
        }, $output);

        return $output;
    }

    /**
     * Format an email body into clean, responsive HTML with inline styling.
     *
     * @param string $content
     * @param string $title
     * @return string
     */
    public static function format_html_message(string $content, string $title): string {
        $clean_content = wp_kses_post(wpautop($content));

        return '<!DOCTYPE html>'
            . '<html>'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . esc_html($title) . '</title>'
            . '</head>'
            . '<body style="margin:0;padding:24px 0;background-color:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;line-height:1.6;">'
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
            . '<tr>'
            . '<td align="center">'
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">'
            . '<tr><td style="padding:24px 32px;background:#1e293b;color:#ffffff;font-size:18px;font-weight:600;">' . esc_html($title) . '</td></tr>'
            . '<tr><td style="padding:32px;font-size:15px;line-height:1.6;color:#334155;">' . $clean_content . '</td></tr>'
            . '<tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;">'
            . sprintf(
                /* translators: %s: Website name */
                __('Sent by %s via Nexley Forms', 'nexley-forms'),
                esc_html(get_bloginfo('name'))
            )
            . '</td></tr>'
            . '</table>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</body>'
            . '</html>';
    }

    /**
     * Generate HTML table for {_all_fields} tag.
     *
     * @param array<string, mixed> $data
     * @return string
     */
    private static function generate_all_fields_list(array $data): string {
        if (empty($data)) {
            return '';
        }

        $html = '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">';
        foreach ($data as $key => $val) {
            if (strpos((string) $key, '_nexley_') === 0) {
                continue;
            }

            $label = ucwords(str_replace(['-', '_'], ' ', (string) $key));
            if (is_array($val)) {
                $value_str = implode(', ', array_map('strval', $val));
            } else {
                $value_str = (string) $val;
            }

            $html .= sprintf(
                '<tr>'
                . '<td style="padding:8px 12px;border:1px solid #e2e8f0;background-color:#f8fafc;font-weight:600;width:35%%;">%s</td>'
                . '<td style="padding:8px 12px;border:1px solid #e2e8f0;word-break:break-word;">%s</td>'
                . '</tr>',
                esc_html($label),
                nl2br(esc_html($value_str))
            );
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * Parse and validate comma-separated recipient emails.
     *
     * @param string $string
     * @return list<string>
     */
    private static function parse_recipients(string $string): array {
        $emails = array_map('trim', explode(',', $string));
        $valid  = [];
        foreach ($emails as $email) {
            if (is_email($email)) {
                $valid[] = $email;
            }
        }
        return array_values(array_unique($valid));
    }
}
