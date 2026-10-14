<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automated, versioned, idempotent, and non-destructive migrations:
 * - Phase 1: Migrates legacy nexley_entry posts to the custom database table, preserving the original posts.
 * - Phase 2: Migrates legacy pseudo-shortcode form templates to the JSON schema, backing up the original template.
 */
final class Migration {

    public const MIGRATION_VERSION = '2.1.0';
    public const MIGRATION_OPTION  = 'nexley_forms_migration_version';

    /**
     * Run all pending migrations idempotently.
     */
    public static function run_migrations(): void {
        $installed_ver = get_option(self::MIGRATION_OPTION, '0.0.0');

        if (version_compare((string) $installed_ver, self::MIGRATION_VERSION, '<')) {
            self::migrate_legacy_entries();
            self::migrate_legacy_forms();
            update_option(self::MIGRATION_OPTION, self::MIGRATION_VERSION);
        }
    }

    /**
     * Migrate legacy nexley_entry posts to the custom database table in batches.
     * Idempotent: Skips entries that already have a record matching legacy_post_id.
     * Does NOT delete or alter the original CPT posts.
     *
     * @param int $batch_size Maximum entries to migrate per run.
     * @return int Number of remaining unmigrated legacy entries.
     */
    public static function migrate_legacy_entries(int $batch_size = 100): int {
        global $wpdb;

        $table_name = DB::get_table_name();

        // Find legacy nexley_entry posts not yet in nexley_entries table.
        $sql = "
            SELECT p.ID, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$table_name} e ON p.ID = e.legacy_post_id
            WHERE p.post_type = %s
              AND e.legacy_post_id IS NULL
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        $unmigrated = $wpdb->get_results(
            $wpdb->prepare($sql, Post_Types::CPT_ENTRY, $batch_size)
        );

        if (empty($unmigrated)) {
            return 0;
        }

        foreach ($unmigrated as $post_row) {
            $post_id    = (int) $post_row->ID;
            $form_id    = (int) get_post_meta($post_id, '_nexley_form_id', true);
            $fields     = get_post_meta($post_id, '_nexley_submitted_fields', true);
            $user_ip    = (string) get_post_meta($post_id, '_nexley_user_ip', true);
            $user_agent = (string) get_post_meta($post_id, '_nexley_user_agent', true);
            $time       = (string) get_post_meta($post_id, '_nexley_submission_time', true) ?: $post_row->post_date;

            $entry_id = DB::insert_entry([
                'form_id'        => $form_id,
                'legacy_post_id' => $post_id,
                'status'         => 'read', // Legacy entries default to 'read'
                'data'           => is_array($fields) ? $fields : [],
                'ip_address'     => $user_ip,
                'user_agent'     => $user_agent,
                'created_at'     => $time,
            ]);

            if ($entry_id > 0) {
                update_post_meta($post_id, '_nexley_migrated_to_db', $entry_id);
            }
        }

        // Count remaining unmigrated legacy posts.
        $count_sql = "
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$table_name} e ON p.ID = e.legacy_post_id
            WHERE p.post_type = %s
              AND e.legacy_post_id IS NULL
        ";

        return (int) $wpdb->get_var($wpdb->prepare($count_sql, Post_Types::CPT_ENTRY));
    }

    /**
     * Migrate legacy nexley_form posts from pseudo-shortcode content to JSON schema.
     * Backs up the original content in _nexley_form_content_backup.
     *
     * @return int Number of forms migrated.
     */
    public static function migrate_legacy_forms(): int {
        Field_Registry::init();

        $forms = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
        ]);

        if (empty($forms)) {
            return 0;
        }

        $migrated_count = 0;

        foreach ($forms as $form) {
            $form_id = $form->ID;

            // Check if already migrated to schema.
            $existing_schema = get_post_meta($form_id, '_nexley_form_schema', true);
            if (!empty($existing_schema) && is_array($existing_schema)) {
                continue;
            }

            $raw_content = (string) get_post_meta($form_id, '_nexley_form_content', true);
            if (trim($raw_content) === '') {
                $raw_content = Form_Parser::get_default_template();
            }

            // Convert raw template to standard schema.
            $schema = self::convert_template_to_schema($raw_content, $form);

            // Validate and normalize schema.
            $validated = Form_Schema::validate_and_normalize($schema);

            // Non-destructive backup of original content.
            update_post_meta($form_id, '_nexley_form_content_backup', $raw_content);

            // Store new single source of truth schema.
            update_post_meta($form_id, '_nexley_form_schema', $validated['schema']);

            /**
             * Fires after a legacy form has been successfully migrated to the JSON schema.
             *
             * @param int                  $form_id Migrated form ID.
             * @param array<string, mixed> $schema  Newly generated form schema.
             */
            do_action('nexley_forms_form_migrated', $form_id, $validated['schema']);
            $migrated_count++;
        }

        return $migrated_count;
    }

    /**
     * Convert pseudo-shortcode template string into a structured JSON schema array.
     *
     * @param string   $template
     * @param \WP_Post $form
     * @return array<string, mixed>
     */
    public static function convert_template_to_schema(string $template, \WP_Post $form): array {
        $form_id = $form->ID;
        $fields  = [];

        // Extract pseudo-shortcodes.
        $regex = '/\[([a-zA-Z0-9_-]+)(\*?)(?:\s+([^\]]+))?\]/';
        preg_match_all($regex, $template, $matches, PREG_SET_ORDER);

        $field_index = 1;
        $has_submit  = false;

        foreach ($matches as $match) {
            $type        = strtolower(trim($match[1]));
            $is_required = ($match[2] === '*');
            $raw_args    = isset($match[3]) ? trim($match[3]) : '';

            if ($type === 'submit') {
                $has_submit = true;
                $submit_label = trim($raw_args, " \t\n\r\0\x0B\"'") ?: __('Submit', 'nexley-forms');
                $fields[] = [
                    'id'          => 'f_submit',
                    'type'        => 'submit',
                    'label'       => $submit_label,
                    'span'        => 12,
                    'required'    => false,
                    'name'        => 'submit',
                    'placeholder' => '',
                    'default'     => '',
                    'help_text'   => '',
                    'css_class'   => '',
                ];
                continue;
            }

            $parsed = Form_Parser::parse_arguments($raw_args, $type);
            $name   = $parsed['name'] ?: 'field_' . $field_index;
            $label  = ucwords(str_replace(['-', '_'], ' ', $name));

            $field_def = [
                'id'          => 'f_' . sanitize_key($name),
                'type'        => self::normalize_field_type($type),
                'name'        => sanitize_key($name),
                'label'       => $label,
                'placeholder' => $parsed['placeholder'],
                'default'     => $parsed['default'],
                'required'    => $is_required,
                'span'        => 12,
                'help_text'   => '',
                'css_class'   => implode(' ', $parsed['classes']),
                'options'     => $parsed['options'],
            ];

            $fields[] = $field_def;
            $field_index++;
        }

        // Ensure submit button exists.
        if (!$has_submit) {
            $fields[] = [
                'id'          => 'f_submit',
                'type'        => 'submit',
                'label'       => __('Submit', 'nexley-forms'),
                'span'        => 12,
                'required'    => false,
                'name'        => 'submit',
                'placeholder' => '',
                'default'     => '',
                'help_text'   => '',
                'css_class'   => '',
            ];
        }

        // Pull notifications settings from existing postmeta.
        $notifications = [];
        $mail_to       = (string) get_post_meta($form_id, '_nexley_mail_to', true);
        $mail_subject  = (string) get_post_meta($form_id, '_nexley_mail_subject', true);
        $mail_body     = (string) get_post_meta($form_id, '_nexley_mail_body', true);
        $mail_reply_to = (string) get_post_meta($form_id, '_nexley_mail_reply_to', true);

        $notifications[] = [
            'id'         => 'admin_notice',
            'name'       => __('Admin Notification', 'nexley-forms'),
            'active'     => true,
            'to'         => $mail_to ?: (string) get_option('admin_email'),
            'reply_to'   => $mail_reply_to ?: '{client-email}',
            'subject'    => $mail_subject ?: sprintf('[%s] New submission: {form_title}', get_bloginfo('name')),
            'body'       => $mail_body ?: "You have received a new form submission:\n\n{_all_fields}\n\n--\nSent from {site_url}",
            'conditions' => [],
        ];

        // Auto-responder notification if enabled.
        $ar_enabled = (bool) get_post_meta($form_id, '_nexley_autoresponder_enable', true);
        if ($ar_enabled) {
            $notifications[] = [
                'id'         => 'client_autoresponder',
                'name'       => __('Client Auto-Responder', 'nexley-forms'),
                'active'     => true,
                'to'         => (string) get_post_meta($form_id, '_nexley_autoresponder_to', true) ?: '{client-email}',
                'reply_to'   => (string) get_option('admin_email'),
                'subject'    => (string) get_post_meta($form_id, '_nexley_autoresponder_subject', true) ?: sprintf('Thank you for contacting %s', get_bloginfo('name')),
                'body'       => (string) get_post_meta($form_id, '_nexley_autoresponder_body', true) ?: "Hi {client-name},\n\nThank you for reaching out. We have received your message.\n\nBest regards,\n{site_title}",
                'conditions' => [],
            ];
        }

        return [
            'version'       => Form_Schema::SCHEMA_VERSION,
            'id'            => $form_id,
            'title'         => $form->post_title ?: sprintf('Form #%d', $form_id),
            'layout'        => [
                'columns'    => Form_Schema::DEFAULT_GRID_COLUMNS,
                'breakpoint' => Form_Schema::DEFAULT_BREAKPOINT,
            ],
            'fields'        => $fields,
            'settings'      => [
                'style_mode'      => 'base',
                'preset'          => 'minimal',
                'honeypot'        => true,
                'time_trap'       => true,
                'rate_limit'      => 10,
                'ajax_submit'     => true,
                'success_message' => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
            ],
            'notifications' => $notifications,
            'actions'       => [
                [
                    'type'    => 'message',
                    'message' => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
                ],
            ],
        ];
    }

    /**
     * Map pseudo-shortcode tag types to canonical schema field types.
     *
     * @param string $type
     * @return string
     */
    private static function normalize_field_type(string $type): string {
        $map = [
            'text'     => 'text',
            'email'    => 'email',
            'url'      => 'url',
            'tel'      => 'phone',
            'phone'    => 'phone',
            'number'   => 'number',
            'textarea' => 'textarea',
            'select'   => 'select',
            'submit'   => 'submit',
        ];

        return $map[$type] ?? 'text';
    }
}
