<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles email notifications and auto-responders with dynamic tag replacement
 * and strict compatibility with WP Mail SMTP.
 */
final class Mailer {

    /**
     * Send both Admin Notification and Client Auto-Responder (if enabled).
     *
     * @param int $form_id
     * @param array<string, mixed> $submitted_data
     * @return array{admin_mail_sent: bool, autoresponder_sent: bool}
     */
    public static function process_submission(int $form_id, array $submitted_data): array {
        $admin_sent = self::send_admin_notification($form_id, $submitted_data);
        $client_sent = self::send_client_autoresponder($form_id, $submitted_data);

        return [
            'admin_mail_sent'    => $admin_sent,
            'autoresponder_sent' => $client_sent,
        ];
    }

    /**
     * Send Admin Notification email.
     *
     * @param int $form_id
     * @param array<string, mixed> $submitted_data
     * @return bool
     */
    public static function send_admin_notification(int $form_id, array $submitted_data): bool {
        $to_template      = (string) get_post_meta($form_id, '_nexley_mail_to', true);
        $subject_template = (string) get_post_meta($form_id, '_nexley_mail_subject', true);
        $body_template    = (string) get_post_meta($form_id, '_nexley_mail_body', true);
        $reply_to_tag     = (string) get_post_meta($form_id, '_nexley_mail_reply_to', true);

        // Fallbacks if not set.
        if (trim($to_template) === '') {
            $to_template = (string) get_option('admin_email');
        }
        if (trim($subject_template) === '') {
            $subject_template = sprintf('[%s] New submission: {form_title}', get_bloginfo('name'));
        }
        if (trim($body_template) === '') {
            $body_template = "You have received a new form submission:\n\n{_all_fields}\n\n--\nThis e-mail was sent from a contact form on {site_title} ({site_url})";
        }

        // Replace tags.
        $to      = self::replace_tags($to_template, $submitted_data, $form_id);
        $subject = self::replace_tags($subject_template, $submitted_data, $form_id);
        $body    = self::replace_tags($body_template, $submitted_data, $form_id);

        // Ensure valid destination email(s).
        $recipients = self::parse_recipients($to);
        if (empty($recipients)) {
            $recipients = [(string) get_option('admin_email')];
        }

        // Build headers.
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Determine Reply-To.
        $reply_email = '';
        $reply_name  = '';

        if ($reply_to_tag !== '') {
            $resolved_reply = self::replace_tags($reply_to_tag, $submitted_data, $form_id);
            if (is_email($resolved_reply)) {
                $reply_email = $resolved_reply;
            }
        }

        // Automatic fallback for reply-to from submitted email if not explicitly set.
        if ($reply_email === '') {
            foreach (['client-email', 'email', 'your-email'] as $key) {
                if (!empty($submitted_data[$key]) && is_email((string) $submitted_data[$key])) {
                    $reply_email = (string) $submitted_data[$key];
                    break;
                }
            }
        }

        // Submitter name for reply-to.
        foreach (['client-name', 'name', 'your-name'] as $key) {
            if (!empty($submitted_data[$key])) {
                $reply_name = sanitize_text_field((string) $submitted_data[$key]);
                break;
            }
        }

        if ($reply_email !== '') {
            $reply_header = $reply_name !== ''
                ? sprintf('Reply-To: "%s" <%s>', str_replace(['"', "\r", "\n"], '', $reply_name), $reply_email)
                : sprintf('Reply-To: <%s>', $reply_email);
            $headers[] = $reply_header;
        }

        // Format HTML content.
        $formatted_body = self::format_html_message($body, $subject);

        // Execute wp_mail() with WP Mail SMTP compatibility.
        return wp_mail($recipients, $subject, $formatted_body, $headers);
    }

    /**
     * Send Client Auto-Responder email if enabled.
     *
     * @param int $form_id
     * @param array<string, mixed> $submitted_data
     * @return bool
     */
    public static function send_client_autoresponder(int $form_id, array $submitted_data): bool {
        $enabled = (bool) get_post_meta($form_id, '_nexley_autoresponder_enable', true);
        if (!$enabled) {
            return false;
        }

        $recipient_tag    = (string) get_post_meta($form_id, '_nexley_autoresponder_to', true);
        $subject_template = (string) get_post_meta($form_id, '_nexley_autoresponder_subject', true);
        $body_template    = (string) get_post_meta($form_id, '_nexley_autoresponder_body', true);

        // Determine recipient.
        $to_email = '';
        if ($recipient_tag !== '') {
            $resolved = self::replace_tags($recipient_tag, $submitted_data, $form_id);
            if (is_email($resolved)) {
                $to_email = $resolved;
            }
        }

        // Fallback to submitted email fields if no custom recipient specified.
        if ($to_email === '') {
            foreach (['client-email', 'email', 'your-email'] as $key) {
                if (!empty($submitted_data[$key]) && is_email((string) $submitted_data[$key])) {
                    $to_email = (string) $submitted_data[$key];
                    break;
                }
            }
        }

        if ($to_email === '' || !is_email($to_email)) {
            return false;
        }

        if (trim($subject_template) === '') {
            $subject_template = sprintf('Thank you for contacting %s', get_bloginfo('name'));
        }
        if (trim($body_template) === '') {
            $body_template = "Hi {client-name},\n\nThank you for reaching out to us. We have received your message and will get back to you shortly.\n\nBest regards,\n{site_title}";
        }

        $subject = self::replace_tags($subject_template, $submitted_data, $form_id);
        $body    = self::replace_tags($body_template, $submitted_data, $form_id);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $admin_email = (string) get_option('admin_email');
        if (is_email($admin_email)) {
            $headers[] = sprintf('Reply-To: "%s" <%s>', esc_attr(get_bloginfo('name')), $admin_email);
        }

        $formatted_body = self::format_html_message($body, $subject);

        return wp_mail($to_email, $subject, $formatted_body, $headers);
    }

    /**
     * Replace dynamic {field} tags and system tags in template text.
     *
     * @param string $template
     * @param array<string, mixed> $data
     * @param int $form_id
     * @return string
     */
    public static function replace_tags(string $template, array $data, int $form_id = 0): string {
        if ($template === '') {
            return '';
        }

        // Format all fields table / list.
        $all_fields_html = self::generate_all_fields_list($data);

        // System tags.
        $system_tags = [
            '{site_title}'   => get_bloginfo('name'),
            '{site_url}'     => home_url(),
            '{admin_email}'  => (string) get_option('admin_email'),
            '{form_id}'      => (string) $form_id,
            '{form_title}'   => get_the_title($form_id) ?: sprintf('Form #%d', $form_id),
            '{date}'         => wp_date('Y-m-d'),
            '{time}'         => wp_date('H:i:s'),
            '{ip}'           => self::get_user_ip(),
            '{_all_fields}'  => $all_fields_html,
        ];

        $output = str_replace(array_keys($system_tags), array_values($system_tags), $template);

        // Dynamic field tags: {field_name}.
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
        $clean_content = wpautop(make_clickable(esc_html($content)));

        // If content already contains HTML tags (like <table> or <div> from {_all_fields}), preserve allowed HTML.
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
            // Skip internal keys.
            if (strpos((string) $key, '_nexley_') === 0) {
                continue;
            }

            $label = ucwords(str_replace(['-', '_'], ' ', (string) $key));
            $value_str = is_array($val) ? implode(', ', $val) : (string) $val;

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

    /**
     * Helper to get user's client IP.
     *
     * @return string
     */
    public static function get_user_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
        return $ip;
    }
}
