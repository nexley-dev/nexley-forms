<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines, normalizes, and validates the single-source-of-truth JSON Form Schema (version 1).
 *
 * Layout Rule:
 * The builder/schema models rows and columns.
 * The renderer flattens them into sibling children of the form, each with --span.
 * No row or column wrapper divs exist in the renderer.
 * Semantic <fieldset> (compound fields, radio/checkbox groups) and step sections are allowed.
 * The schema validator computes each row's width and reports warnings when a row does not sum to 12.
 */
final class Form_Schema {

    public const SCHEMA_VERSION = 1;
    public const DEFAULT_GRID_COLUMNS = 12;
    public const DEFAULT_BREAKPOINT = 640;

    /**
     * Get default schema structure for a brand new form.
     *
     * @param int    $form_id
     * @param string $title
     * @return array<string, mixed>
     */
    public static function get_default_schema(int $form_id = 0, string $title = ''): array {
        Field_Registry::init();

        $form_title = $title ?: ($form_id > 0 ? sprintf(__('Form #%d', 'nexley-forms'), $form_id) : __('New Form', 'nexley-forms'));

        return [
            'version'       => self::SCHEMA_VERSION,
            'id'            => $form_id,
            'title'         => $form_title,
            'layout'        => [
                'columns'    => self::DEFAULT_GRID_COLUMNS,
                'breakpoint' => self::DEFAULT_BREAKPOINT,
            ],
            'rows'          => [
                [
                    'id'      => 'row_1',
                    'columns' => [
                        ['id' => 'col_1_1', 'span' => 6, 'fields' => ['f_client_name']],
                        ['id' => 'col_1_2', 'span' => 6, 'fields' => ['f_client_email']],
                    ],
                ],
                [
                    'id'      => 'row_2',
                    'columns' => [
                        ['id' => 'col_2_1', 'span' => 12, 'fields' => ['f_subject']],
                    ],
                ],
                [
                    'id'      => 'row_3',
                    'columns' => [
                        ['id' => 'col_3_1', 'span' => 12, 'fields' => ['f_message']],
                    ],
                ],
                [
                    'id'      => 'row_4',
                    'columns' => [
                        ['id' => 'col_4_1', 'span' => 12, 'fields' => ['f_submit']],
                    ],
                ],
            ],
            'fields'        => [
                [
                    'id'          => 'f_client_name',
                    'type'        => 'text',
                    'name'        => 'client-name',
                    'label'       => __('Your Name', 'nexley-forms'),
                    'placeholder' => __('John Doe', 'nexley-forms'),
                    'default'     => '',
                    'required'    => true,
                    'span'        => 6,
                    'help_text'   => '',
                    'css_class'   => '',
                    'logic'       => null,
                ],
                [
                    'id'          => 'f_client_email',
                    'type'        => 'email',
                    'name'        => 'client-email',
                    'label'       => __('Your Email', 'nexley-forms'),
                    'placeholder' => __('john@example.com', 'nexley-forms'),
                    'default'     => '',
                    'required'    => true,
                    'span'        => 6,
                    'help_text'   => '',
                    'css_class'   => '',
                    'logic'       => null,
                ],
                [
                    'id'          => 'f_subject',
                    'type'        => 'text',
                    'name'        => 'subject',
                    'label'       => __('Subject', 'nexley-forms'),
                    'placeholder' => __('How can we help?', 'nexley-forms'),
                    'default'     => '',
                    'required'    => false,
                    'span'        => 12,
                    'help_text'   => '',
                    'css_class'   => '',
                    'logic'       => null,
                ],
                [
                    'id'          => 'f_message',
                    'type'        => 'textarea',
                    'name'        => 'your-message',
                    'label'       => __('Your Message', 'nexley-forms'),
                    'placeholder' => __('Write your message here...', 'nexley-forms'),
                    'default'     => '',
                    'required'    => false,
                    'span'        => 12,
                    'help_text'   => '',
                    'css_class'   => '',
                    'logic'       => null,
                ],
                [
                    'id'          => 'f_submit',
                    'type'        => 'submit',
                    'name'        => 'submit',
                    'label'       => __('Send Message', 'nexley-forms'),
                    'placeholder' => '',
                    'default'     => '',
                    'required'    => false,
                    'span'        => 12,
                    'help_text'   => '',
                    'css_class'   => '',
                    'logic'       => null,
                ],
            ],
            'steps'         => [],
            'settings'      => [
                'style_mode'           => 'base', // 'raw', 'base', 'preset'
                'preset'               => 'minimal', // 'minimal', 'rounded', 'outlined', 'dark'
                'honeypot'             => true,
                'time_trap'            => true,
                'rate_limit'           => 10,
                'ajax_submit'          => true,
                'success_message'      => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
                'redirect_url'         => '',
                'captcha_type'         => 'none', // 'none', 'turnstile', 'hcaptcha'
                'captcha_site_key'     => '',
                'captcha_secret_key'   => '',
                'privacy_anonymize_ip' => false,
                'retention_days'       => 0, // 0 = keep indefinitely
            ],
            'notifications' => [
                [
                    'id'         => 'admin_notice',
                    'name'       => __('Admin Notification', 'nexley-forms'),
                    'active'     => true,
                    'to'         => (string) get_option('admin_email'),
                    'reply_to'   => '{client-email}',
                    'subject'    => sprintf('[%s] New submission: {form_title}', get_bloginfo('name')),
                    'body'       => "You have received a new form submission from {site_title}:\n\n{_all_fields}\n\n--\nSent from {site_url}",
                    'conditions' => [],
                ],
            ],
            'actions'       => [
                [
                    'id'      => 'action_message',
                    'type'    => 'message',
                    'message' => __('Thank you! Your message has been sent successfully.', 'nexley-forms'),
                ],
            ],
        ];
    }

    /**
     * Validate and normalize a form schema.
     * Computes row widths, detects nested columns (disallowed), and warns if row != 12.
     *
     * @param array<string, mixed> $schema
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     row_stats: list<array{row_id: string, total_span: int, remaining: int, is_complete: bool}>,
     *     schema: array<string, mixed>
     * }
     */
    public static function validate_and_normalize(array $schema): array {
        Field_Registry::init();

        $errors    = [];
        $warnings  = [];
        $row_stats = [];

        // 1. Version check.
        $version = (int) ($schema['version'] ?? 1);
        if ($version < 1) {
            $errors[] = __('Schema version must be 1 or higher.', 'nexley-forms');
        }

        // 2. Normalize layout settings.
        $layout = is_array($schema['layout'] ?? null) ? $schema['layout'] : [];
        $grid_cols = isset($layout['columns']) ? max(1, min(12, (int) $layout['columns'])) : self::DEFAULT_GRID_COLUMNS;
        $breakpoint = isset($layout['breakpoint']) ? max(320, min(1920, (int) $layout['breakpoint'])) : self::DEFAULT_BREAKPOINT;
        $schema['layout'] = [
            'columns'    => $grid_cols,
            'breakpoint' => $breakpoint,
        ];

        // 3. Normalize fields dictionary.
        $raw_fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $normalized_fields = [];
        $field_map = [];

        foreach ($raw_fields as $idx => $f) {
            if (!is_array($f)) {
                continue;
            }

            $type = sanitize_key($f['type'] ?? 'text');
            $type_def = Field_Registry::get_field_type($type);

            if (!$type_def) {
                // If unknown field type, fall back to text
                $type = 'text';
                $type_def = Field_Registry::get_field_type('text');
            }

            $name = sanitize_key($f['name'] ?? ('field_' . ($idx + 1)));
            $id   = sanitize_key($f['id'] ?? ('f_' . $name));
            $span = isset($f['span']) ? max(1, min($grid_cols, (int) $f['span'])) : $grid_cols;

            $field_entry = [
                'id'                 => $id,
                'type'               => $type,
                'name'               => $name,
                'label'              => sanitize_text_field((string) ($f['label'] ?? '')),
                'placeholder'        => sanitize_text_field((string) ($f['placeholder'] ?? '')),
                'default'            => $f['default'] ?? '',
                'required'           => !empty($f['required']),
                'span'               => $span,
                'help_text'          => sanitize_text_field((string) ($f['help_text'] ?? '')),
                'css_class'          => sanitize_text_field((string) ($f['css_class'] ?? '')),
                'custom_attributes'  => is_array($f['custom_attributes'] ?? null) ? $f['custom_attributes'] : [],
                'validation_message' => sanitize_text_field((string) ($f['validation_message'] ?? '')),
                'logic'              => is_array($f['logic'] ?? null) ? $f['logic'] : null,
                'step'               => isset($f['step']) ? sanitize_key((string) $f['step']) : null,
            ];

            // Specific options for select / radio / checkbox
            if (isset($f['options']) && is_array($f['options'])) {
                $opts = [];
                foreach ($f['options'] as $opt) {
                    if (is_array($opt)) {
                        $opts[] = [
                            'value' => sanitize_text_field((string) ($opt['value'] ?? '')),
                            'label' => sanitize_text_field((string) ($opt['label'] ?? '')),
                        ];
                    }
                }
                $field_entry['options'] = $opts;
            }

            // Specific content for HTML field (wp_kses sanitized)
            if ($type === 'html') {
                $raw_html = (string) ($f['content'] ?? '');
                if (current_user_can('unfiltered_html')) {
                    $field_entry['content'] = $raw_html;
                } else {
                    $field_entry['content'] = wp_kses_post($raw_html);
                }
            }

            // Sub-fields for repeater or compound
            if (isset($f['sub_fields']) && is_array($f['sub_fields'])) {
                $field_entry['sub_fields'] = $f['sub_fields'];
            }

            $normalized_fields[] = $field_entry;
            $field_map[$id] = $field_entry;
        }

        $schema['fields'] = $normalized_fields;

        // 4. Validate Rows and Columns (Row remaining width check & no nested columns)
        $raw_rows = is_array($schema['rows'] ?? null) ? $schema['rows'] : [];
        $normalized_rows = [];

        // If no rows were provided, automatically derive rows from fields
        if (empty($raw_rows) && !empty($normalized_fields)) {
            $raw_rows = self::derive_rows_from_fields($normalized_fields, $grid_cols);
        }

        foreach ($raw_rows as $row_idx => $row) {
            $row_id = sanitize_key($row['id'] ?? ('row_' . ($row_idx + 1)));
            $columns = is_array($row['columns'] ?? null) ? $row['columns'] : [];

            $row_span_sum = 0;
            $norm_cols    = [];

            foreach ($columns as $col_idx => $col) {
                // Rule: No nested columns!
                if (isset($col['columns']) && is_array($col['columns'])) {
                    $errors[] = sprintf(
                        /* translators: 1: Row ID, 2: Column ID */
                        __('Row %1$s Column %2$s has nested columns. Nested columns are strictly disallowed.', 'nexley-forms'),
                        $row_id,
                        $col['id'] ?? (string) $col_idx
                    );
                }

                $col_id    = sanitize_key($col['id'] ?? ('col_' . $row_id . '_' . ($col_idx + 1)));
                $col_span  = isset($col['span']) ? max(1, min($grid_cols, (int) $col['span'])) : $grid_cols;
                $col_field_ids = is_array($col['fields'] ?? null) ? array_map('sanitize_key', $col['fields']) : [];

                // Keep field span synced with column span
                foreach ($col_field_ids as $fid) {
                    if (isset($field_map[$fid])) {
                        $field_map[$fid]['span'] = $col_span;
                    }
                }

                $row_span_sum += $col_span;
                $norm_cols[] = [
                    'id'     => $col_id,
                    'span'   => $col_span,
                    'fields' => $col_field_ids,
                ];
            }

            $remaining = $grid_cols - $row_span_sum;
            $is_complete = ($remaining === 0);

            if (!$is_complete) {
                if ($remaining > 0) {
                    $warnings[] = sprintf(
                        /* translators: 1: Row ID, 2: Current total span, 3: Target span (12) */
                        __('Row "%1$s" total span is %2$d/%3$d. Remaining: %4$d column(s).', 'nexley-forms'),
                        $row_id,
                        $row_span_sum,
                        $grid_cols,
                        $remaining
                    );
                } else {
                    $warnings[] = sprintf(
                        /* translators: 1: Row ID, 2: Current total span, 3: Target span (12) */
                        __('Row "%1$s" total span exceeds %3$d (currently %2$d). Fields will wrap in grid.', 'nexley-forms'),
                        $row_id,
                        $row_span_sum,
                        $grid_cols
                    );
                }
            }

            $row_stats[] = [
                'row_id'      => $row_id,
                'total_span'  => $row_span_sum,
                'remaining'   => $remaining,
                'is_complete' => $is_complete,
            ];

            $row_entry = [
                'id'      => $row_id,
                'columns' => $norm_cols,
            ];
            if (!empty($row['step'])) {
                $row_entry['step'] = sanitize_key((string) $row['step']);
                foreach ($norm_cols as $c) {
                    foreach ($c['fields'] as $fid) {
                        if (isset($field_map[$fid])) {
                            $field_map[$fid]['step'] = $row_entry['step'];
                        }
                    }
                }
            }
            $normalized_rows[] = $row_entry;
        }

        $schema['rows'] = $normalized_rows;

        // Re-sync fields in schema with updated spans
        $schema['fields'] = array_values($field_map);

        // 5. Normalize Settings
        $raw_settings = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];
        $style_mode   = isset($raw_settings['style_mode']) && in_array($raw_settings['style_mode'], ['raw', 'base', 'preset'], true) ? $raw_settings['style_mode'] : 'base';
        $preset       = isset($raw_settings['preset']) && in_array($raw_settings['preset'], ['minimal', 'rounded', 'outlined', 'dark'], true) ? $raw_settings['preset'] : 'minimal';
        $captcha_type = isset($raw_settings['captcha_type']) && in_array($raw_settings['captcha_type'], ['none', 'turnstile', 'hcaptcha'], true) ? $raw_settings['captcha_type'] : 'none';

        $schema['settings'] = [
            'style_mode'           => $style_mode,
            'preset'               => $preset,
            'honeypot'             => !isset($raw_settings['honeypot']) || !empty($raw_settings['honeypot']),
            'time_trap'            => !isset($raw_settings['time_trap']) || !empty($raw_settings['time_trap']),
            'rate_limit'           => isset($raw_settings['rate_limit']) ? max(0, absint($raw_settings['rate_limit'])) : 10,
            'ajax_submit'          => !isset($raw_settings['ajax_submit']) || !empty($raw_settings['ajax_submit']),
            'success_message'      => sanitize_text_field((string) ($raw_settings['success_message'] ?? __('Thank you! Your message has been sent successfully.', 'nexley-forms'))),
            'redirect_url'         => esc_url_raw((string) ($raw_settings['redirect_url'] ?? '')),
            'captcha_type'         => $captcha_type,
            'captcha_site_key'     => sanitize_text_field((string) ($raw_settings['captcha_site_key'] ?? '')),
            'captcha_secret_key'   => sanitize_text_field((string) ($raw_settings['captcha_secret_key'] ?? '')),
            'privacy_anonymize_ip' => !empty($raw_settings['privacy_anonymize_ip']),
            'retention_days'       => isset($raw_settings['retention_days']) ? max(0, absint($raw_settings['retention_days'])) : 0,
        ];

        // 6. Normalize Notifications
        $raw_notifs = is_array($schema['notifications'] ?? null) ? $schema['notifications'] : [];
        $norm_notifs = [];
        foreach ($raw_notifs as $n_idx => $n) {
            if (!is_array($n)) {
                continue;
            }
            $norm_notifs[] = [
                'id'         => sanitize_key($n['id'] ?? ('notif_' . ($n_idx + 1))),
                'name'       => sanitize_text_field((string) ($n['name'] ?? __('Notification', 'nexley-forms'))),
                'active'     => !isset($n['active']) || !empty($n['active']),
                'to'         => sanitize_text_field((string) ($n['to'] ?? '{admin_email}')),
                'reply_to'   => sanitize_text_field((string) ($n['reply_to'] ?? '')),
                'subject'    => sanitize_text_field((string) ($n['subject'] ?? __('New Submission', 'nexley-forms'))),
                'body'       => wp_kses_post((string) ($n['body'] ?? "{_all_fields}")),
                'conditions' => is_array($n['conditions'] ?? null) ? $n['conditions'] : [],
            ];
        }
        $schema['notifications'] = $norm_notifs;

        // 7. Normalize Actions
        $raw_actions = is_array($schema['actions'] ?? null) ? $schema['actions'] : [];
        $norm_actions = [];
        foreach ($raw_actions as $a_idx => $a) {
            if (!is_array($a)) {
                continue;
            }
            $act_type = sanitize_key($a['type'] ?? 'message');
            $norm_actions[] = [
                'id'       => sanitize_key($a['id'] ?? ('action_' . ($a_idx + 1))),
                'type'     => $act_type,
                'message'  => sanitize_text_field((string) ($a['message'] ?? '')),
                'redirect' => esc_url_raw((string) ($a['redirect'] ?? '')),
                'webhook'  => [
                    'url'     => esc_url_raw((string) ($a['webhook']['url'] ?? '')),
                    'method'  => strtoupper((string) ($a['webhook']['method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST',
                    'headers' => is_array($a['webhook']['headers'] ?? null) ? $a['webhook']['headers'] : [],
                ],
            ];
        }
        $schema['actions'] = $norm_actions;

        // 8. Normalize Steps (for multi-step)
        $raw_steps = is_array($schema['steps'] ?? null) ? $schema['steps'] : [];
        $norm_steps = [];
        foreach ($raw_steps as $s_idx => $s) {
            if (!is_array($s)) {
                continue;
            }
            $norm_steps[] = [
                'id'          => sanitize_key($s['id'] ?? ('step_' . ($s_idx + 1))),
                'title'       => sanitize_text_field((string) ($s['title'] ?? sprintf(__('Step %d', 'nexley-forms'), $s_idx + 1))),
                'description' => sanitize_text_field((string) ($s['description'] ?? '')),
                'field_ids'   => is_array($s['field_ids'] ?? null) ? array_map('sanitize_key', $s['field_ids']) : [],
            ];
        }
        $schema['steps'] = $norm_steps;

        return [
            'valid'     => empty($errors),
            'errors'    => $errors,
            'warnings'  => $warnings,
            'row_stats' => $row_stats,
            'schema'    => $schema,
        ];
    }

    /**
     * Derive row and column structure from a flat list of fields if not provided.
     * Groups consecutive fields into rows summing up to grid columns (12).
     *
     * @param list<array<string, mixed>> $fields
     * @param int $grid_cols
     * @return list<array<string, mixed>>
     */
    public static function derive_rows_from_fields(array $fields, int $grid_cols = 12): array {
        $rows = [];
        $current_cols = [];
        $current_span = 0;
        $row_counter  = 1;

        foreach ($fields as $field) {
            $span = isset($field['span']) ? max(1, min($grid_cols, (int) $field['span'])) : $grid_cols;

            if ($current_span + $span > $grid_cols && !empty($current_cols)) {
                $rows[] = [
                    'id'      => 'row_' . $row_counter++,
                    'columns' => $current_cols,
                ];
                $current_cols = [];
                $current_span = 0;
            }

            $current_cols[] = [
                'id'     => 'col_' . $field['id'],
                'span'   => $span,
                'fields' => [$field['id']],
            ];
            $current_span += $span;
        }

        if (!empty($current_cols)) {
            $rows[] = [
                'id'      => 'row_' . $row_counter,
                'columns' => $current_cols,
            ];
        }

        return $rows;
    }

    /**
     * Get a form's schema from post meta, or build default if not set.
     *
     * @param int $form_id
     * @return array<string, mixed>
     */
    public static function get_form_schema(int $form_id): array {
        $raw = get_post_meta($form_id, '_nexley_form_schema', true);
        if (is_array($raw) && !empty($raw)) {
            $validated = self::validate_and_normalize($raw);
            return $validated['schema'];
        }

        // Check if legacy raw template exists to convert.
        $legacy_content = (string) get_post_meta($form_id, '_nexley_form_content', true);
        $post = get_post($form_id);
        if ($post) {
            $title = $post->post_title;
        } else {
            $title = sprintf('Form #%d', $form_id);
        }

        $default = self::get_default_schema($form_id, $title);
        return $default;
    }

    /**
     * Save a form's schema to post meta after validation and sanitization.
     *
     * @param int $form_id
     * @param array<string, mixed> $schema
     * @return array{success: bool, errors: list<string>, warnings: list<string>}
     */
    public static function save_form_schema(int $form_id, array $schema): array {
        if (!current_user_can('edit_post', $form_id)) {
            return [
                'success'  => false,
                'errors'   => [__('You do not have permission to edit this form.', 'nexley-forms')],
                'warnings' => [],
            ];
        }

        $schema['id'] = $form_id;
        $validated = self::validate_and_normalize($schema);

        if (!$validated['valid']) {
            return [
                'success'  => false,
                'errors'   => $validated['errors'],
                'warnings' => $validated['warnings'],
            ];
        }

        /**
         * Filters the form schema immediately before saving to post meta.
         *
         * @param array<string, mixed> $schema  Normalized form schema.
         * @param int                  $form_id Target form ID.
         */
        $clean_schema = apply_filters('nexley_forms_pre_save_schema', $validated['schema'], $form_id);

        update_post_meta($form_id, '_nexley_form_schema', $clean_schema);

        /**
         * Fires immediately after a form schema is saved to post meta.
         *
         * @param int                  $form_id Form post ID.
         * @param array<string, mixed> $clean_schema Clean saved form schema.
         */
        do_action('nexley_forms_saved_schema', $form_id, $clean_schema);

        return [
            'success'  => true,
            'errors'   => [],
            'warnings' => $validated['warnings'],
        ];
    }
}
