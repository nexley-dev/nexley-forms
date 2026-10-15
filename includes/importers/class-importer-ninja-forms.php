<?php
declare(strict_types=1);

namespace Nexley_Forms\Importers;

use Nexley_Forms\Form_Schema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ninja Forms 3 Importer Adapter.
 * Status: Documented, unverified.
 * Accounts for nf3_forms, nf3_fields, nf3_actions, and nf3_*_meta tables.
 */
class Importer_Ninja_Forms implements Importer_Interface {

    public function get_id(): string {
        return 'ninja-forms';
    }

    public function get_title(): string {
        return 'Ninja Forms';
    }

    public function get_description(): string {
        return __('Imports forms from Ninja Forms 3 database tables or .nff / JSON export files.', 'nexley-forms');
    }

    public function get_verification_status(): string {
        return 'JSON export: Verified against real .nff fixture. Database import: Documented, unverified (an export file does not reveal DB table structure).';
    }

    /**
     * Check if Ninja Forms is active or if its database tables exist.
     */
    public function is_available(): bool {
        global $wpdb;

        if (class_exists('Ninja_Forms')) {
            return true;
        }

        $table_name = $wpdb->prefix . 'nf3_forms';
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return !empty($found);
    }

    /**
     * Get available forms from the database.
     */
    public function get_available_forms(): array {
        global $wpdb;

        if (!$this->is_available()) {
            return [];
        }

        $table_forms = $wpdb->prefix . 'nf3_forms';
        $forms = [];

        $results = $wpdb->get_results("SELECT id, title FROM {$table_forms} ORDER BY id ASC");
        if (is_array($results)) {
            foreach ($results as $row) {
                $forms[(int) $row->id] = (string) ($row->title ?: "Ninja Form #{$row->id}");
            }
        }

        return $forms;
    }

    /**
     * Convert Ninja Forms data into Nexley Form Schema v1.
     *
     * @param mixed $source Form ID or parsed array.
     * @return array<string, mixed>
     */
    public function convert(mixed $source): array {
        if (is_numeric($source)) {
            return $this->convert_from_database((int) $source);
        }

        if (is_string($source)) {
            $decoded = json_decode($source, true);
            if (is_array($decoded)) {
                return $this->convert_from_array($decoded);
            }
        }

        if (is_array($source)) {
            return $this->convert_from_array($source);
        }

        return Form_Schema::get_default_schema();
    }

    /**
     * Convert from Ninja Forms database tables.
     *
     * UNVERIFIED PATH: An export file does not reveal DB table structure.
     * This path is documented and implemented against Ninja Forms 3 schema conventions,
     * but remains unverified until tested against a live Ninja Forms 3 MySQL install.
     */
    protected function convert_from_database(int $form_id): array {
        global $wpdb;

        $table_forms       = $wpdb->prefix . 'nf3_forms';
        $table_form_meta  = $wpdb->prefix . 'nf3_form_meta';
        $table_fields      = $wpdb->prefix . 'nf3_fields';
        $table_field_meta  = $wpdb->prefix . 'nf3_field_meta';
        $table_actions     = $wpdb->prefix . 'nf3_actions';
        $table_action_meta = $wpdb->prefix . 'nf3_action_meta';

        // 1. Form title & settings
        $form_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_forms} WHERE id = %d LIMIT 1", $form_id));
        $title = $form_row ? (string) ($form_row->title ?: "Ninja Form #{$form_id}") : "Ninja Form #{$form_id}";

        $form_meta_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT `key`, `value` FROM {$table_form_meta} WHERE parent_id = %d", $form_id)
        );
        $form_settings = [];
        if (is_array($form_meta_rows)) {
            foreach ($form_meta_rows as $fm) {
                $form_settings[$fm->key] = maybe_unserialize($fm->value);
            }
        }
        $form_settings['title'] = $form_settings['title'] ?? $title;

        // 2. Fields with meta
        $field_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table_fields} WHERE form_id = %d ORDER BY `order` ASC, id ASC", $form_id)
        );

        $nf_fields = [];
        if (is_array($field_rows)) {
            foreach ($field_rows as $f) {
                $f_id = (int) $f->id;
                // Query field meta
                $meta_rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT `key`, `value` FROM {$table_field_meta} WHERE parent_id = %d", $f_id)
                );

                $field_data = [
                    'id'    => $f_id,
                    'type'  => (string) ($f->type ?? 'textbox'),
                    'label' => (string) ($f->label ?? ''),
                    'key'   => (string) ($f->key ?? "field_{$f_id}"),
                    'order' => (int) ($f->order ?? 0),
                ];

                if (is_array($meta_rows)) {
                    foreach ($meta_rows as $m) {
                        $field_data[$m->key] = maybe_unserialize($m->value);
                    }
                }

                $nf_fields[] = $field_data;
            }
        }

        // 3. Actions with meta
        $action_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table_actions} WHERE form_id = %d ORDER BY id ASC", $form_id)
        );

        $nf_actions = [];
        if (is_array($action_rows)) {
            foreach ($action_rows as $a) {
                $a_id = (int) $a->id;
                $action_meta_rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT `key`, `value` FROM {$table_action_meta} WHERE parent_id = %d", $a_id)
                );

                $action_settings = [];
                if (is_array($action_meta_rows)) {
                    foreach ($action_meta_rows as $am) {
                        $action_settings[$am->key] = maybe_unserialize($am->value);
                    }
                }

                $nf_actions[] = [
                    'id'       => $a_id,
                    'type'     => (string) ($a->type ?? ''),
                    'label'    => (string) ($a->label ?? ''),
                    'settings' => $action_settings,
                ];
            }
        }

        return $this->convert_from_array([
            'title'    => $title,
            'settings' => $form_settings,
            'fields'   => $nf_fields,
            'actions'  => $nf_actions,
        ]);
    }

    /**
     * Convert from parsed Ninja Forms structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    /**
     * Allocate column spans for a row using the Largest Remainder Method (Hare-Niemeyer).
     *
     * Ensures the sum of all cell spans in the row equals exactly 12.
     * Supports numeric and string widths (e.g., 50, "100", 33.33, "20").
     *
     * @param array<int, array<string, mixed>> $cells
     * @return array<int, int> Map of cell_index => span (sum = 12)
     */
    public static function allocate_row_spans(array $cells): array {
        $count = count($cells);
        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [0 => 12];
        }

        // 1. Parse widths
        $widths = [];
        foreach ($cells as $idx => $cell) {
            $w = isset($cell['width']) ? (float) $cell['width'] : 0.0;
            if ($w <= 0.0) {
                $w = 100.0 / $count;
            }
            $widths[$idx] = $w;
        }

        $total_width = array_sum($widths);
        if ($total_width <= 0.0) {
            $total_width = 100.0;
        }

        // 2. Compute exact target share and integer floor (min 1)
        $floors     = [];
        $remainders = [];
        foreach ($widths as $idx => $w) {
            $exact = ($w / $total_width) * 12.0;
            $floor = max(1, (int) floor($exact));
            $floors[$idx]     = $floor;
            $remainders[$idx] = $exact - $floor;
        }

        $allocated = array_sum($floors);
        $deficit   = 12 - $allocated;

        if ($deficit > 0) {
            // Distribute remaining units to cells with largest remainder
            $indices = array_keys($remainders);
            usort($indices, function ($a, $b) use ($remainders) {
                if ($remainders[$a] == $remainders[$b]) {
                    return $a <=> $b;
                }
                return ($remainders[$a] > $remainders[$b]) ? -1 : 1;
            });

            for ($i = 0; $i < $deficit; $i++) {
                $target_idx = $indices[$i % $count];
                $floors[$target_idx]++;
            }
        } elseif ($deficit < 0) {
            // Deduct surplus from cells with smallest remainder while keeping span >= 1
            $surplus = abs($deficit);
            $indices = array_keys($remainders);
            usort($indices, function ($a, $b) use ($remainders) {
                if ($remainders[$a] == $remainders[$b]) {
                    return $b <=> $a;
                }
                return ($remainders[$a] < $remainders[$b]) ? -1 : 1;
            });

            $deducted = 0;
            foreach ($indices as $idx) {
                if ($deducted >= $surplus) {
                    break;
                }
                if ($floors[$idx] > 1) {
                    $floors[$idx]--;
                    $deducted++;
                }
            }
        }

        return $floors;
    }

    /**
     * Convert from parsed Ninja Forms structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convert_from_array(array $data): array {
        // Resolve form title from settings or top-level
        $raw_title = $data['settings']['title'] ?? $data['title'] ?? '';
        $title = !empty($raw_title) ? sanitize_text_field((string) $raw_title) : 'Imported Ninja Form';

        $schema = [
            'version'       => Form_Schema::SCHEMA_VERSION,
            'title'         => $title,
            'layout'        => [
                'columns'    => Form_Schema::DEFAULT_GRID_COLUMNS,
                'breakpoint' => Form_Schema::DEFAULT_BREAKPOINT,
            ],
            'fields'        => [],
            'rows'          => [],
            'settings'      => [
                'style_mode' => 'preset',
                'preset'     => 'rounded',
            ],
            'notifications' => [],
            'confirmations' => [],
            'webhooks'      => [],
        ];

        // Process settings and track mapped vs skipped settings
        $settings_source = $data['settings'] ?? [];
        if (isset($settings_source['show_title'])) {
            $schema['settings']['show_title'] = !empty($settings_source['show_title']);
        }

        $skipped_settings = [];
        if (isset($settings_source['hide_complete'])) {
            $skipped_settings[] = 'hide_complete (handled natively upon submission)';
        }
        if (isset($settings_source['clear_complete'])) {
            $skipped_settings[] = 'clear_complete (handled natively upon submission)';
        }
        if (isset($settings_source['logged_in'])) {
            $skipped_settings[] = 'logged_in (per-form login restriction not in core schema)';
        }
        if (isset($settings_source['not_logged_in_msg'])) {
            $skipped_settings[] = 'not_logged_in_msg (tied to skipped logged_in setting)';
        }
        if (isset($settings_source['sub_limit_msg'])) {
            $skipped_settings[] = 'sub_limit_msg (submission limits not in core schema)';
        }
        if (isset($settings_source['default_label_pos'])) {
            $skipped_settings[] = 'default_label_pos (standard accessible top alignment enforced)';
        }
        if (!empty($skipped_settings)) {
            $schema['skipped_settings'] = $skipped_settings;
        }

        // Process fields into map
        $fields_source = $data['fields'] ?? [];
        if (!is_array($fields_source)) {
            $fields_source = [];
        }

        // Field key to name mapping (used for merge tags)
        $field_key_to_name = [];
        $fields_by_key     = [];
        $used_field_names  = [];

        foreach ($fields_source as $nf_f) {
            $nf_type   = strtolower((string) ($nf_f['type'] ?? 'textbox'));
            $raw_label = trim((string) ($nf_f['label'] ?? ''));

            // Rule 2: Strip leading "*", trailing ":", and surrounding whitespace from imported labels
            $nf_label = preg_replace('/^[\s*]+|[\s:]+$/u', '', $raw_label);
            $nf_label = trim($nf_label);

            $nf_key    = sanitize_key((string) ($nf_f['key'] ?? ''));
            $nf_req    = !empty($nf_f['required']) && $nf_f['required'] !== '0';

            $nexley_type = $this->map_field_type($nf_type);
            $field_id    = 'f_' . ($nf_key ?: uniqid('nf_'));

            // Rule 5: A single standalone checkbox without options maps to 'consent'
            // so it renders as ONE checkbox with its label, not a choice group <fieldset>.
            if ($nexley_type === 'checkbox' && empty($nf_f['options'])) {
                $nexley_type = 'consent';
            }

            // Rule 6: Custom names (fname, email) kept unique within the form
            $custom_name = !empty($nf_f['custom_name_attribute']) ? sanitize_key((string) $nf_f['custom_name_attribute']) : '';
            $base_name   = $custom_name ?: ($nf_key ?: str_replace('-', '_', $field_id));
            $field_name  = $base_name;
            $suffix = 2;
            while (isset($used_field_names[$field_name])) {
                $field_name = $base_name . '_' . $suffix;
                $suffix++;
            }
            $used_field_names[$field_name] = true;

            if ($nf_key !== '') {
                $field_key_to_name[$nf_key] = $field_name;
            }
            if ($custom_name !== '' && !isset($field_key_to_name[$custom_name])) {
                $field_key_to_name[$custom_name] = $field_name;
            }
            $field_key_to_name[$field_id] = $field_name;

            $field_def = [
                'id'       => $field_id,
                'type'     => $nexley_type,
                'name'     => $field_name,
                'label'    => $nf_label ?: ucwords(str_replace('_', ' ', $nf_key)),
                'span'     => 12,
                'required' => $nf_req,
            ];

            if (!empty($nf_f['placeholder'])) {
                $field_def['placeholder'] = (string) $nf_f['placeholder'];
            }

            $default_val = $nf_f['default'] ?? $nf_f['default_value'] ?? '';
            if ($default_val !== '') {
                $field_def['default_value'] = (string) $default_val;
            }

            if (!empty($nf_f['help_text'])) {
                $field_def['help_text'] = (string) $nf_f['help_text'];
            }

            $css_class = trim((string) (($nf_f['element_class'] ?? '') . ' ' . ($nf_f['container_class'] ?? '')));
            if ($css_class !== '') {
                $field_def['css_class'] = $css_class;
            }

            // Options for select, radio, checkbox group
            if (!empty($nf_f['options']) && is_array($nf_f['options'])) {
                $opts = [];
                foreach ($nf_f['options'] as $o) {
                    $opt_label = (string) ($o['label'] ?? $o['value'] ?? '');
                    $opt_val   = (string) ($o['value'] ?? $o['label'] ?? '');
                    if ($opt_label !== '') {
                        $opts[] = ['label' => $opt_label, 'value' => $opt_val];
                    }
                }
                if (!empty($opts)) {
                    $field_def['options'] = $opts;
                }
            }

            // HTML content
            if ($nexley_type === 'html' && !empty($default_val)) {
                $field_def['content'] = wp_kses_post((string) $default_val);
            }

            if ($nf_key !== '') {
                $fields_by_key[$nf_key] = $field_def;
            } else {
                $fields_by_key[$field_id] = $field_def;
            }
        }

        // Layout parsing: derive rows and spans using Largest Remainder Method
        $form_content = $data['settings']['formContentData'] ?? $data['formContentData'] ?? null;
        $placed_keys  = [];

        if (is_array($form_content) && !empty($form_content)) {
            // Sort rows by order
            usort($form_content, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

            $row_index = 1;
            foreach ($form_content as $row_data) {
                $cells = $row_data['cells'] ?? [];
                if (!is_array($cells) || empty($cells)) {
                    continue;
                }

                // Sort cells by order
                usort($cells, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

                // Filter to active cells with existing fields to ensure 12-span balance
                $active_cells = [];
                foreach ($cells as $cell) {
                    $c_fields = (array) ($cell['fields'] ?? []);
                    $valid_fields = [];
                    foreach ($c_fields as $fk) {
                        if (isset($fields_by_key[$fk])) {
                            $valid_fields[] = $fk;
                        }
                    }
                    if (!empty($valid_fields)) {
                        $cell['valid_fields'] = $valid_fields;
                        $active_cells[] = $cell;
                    }
                }

                if (empty($active_cells)) {
                    continue;
                }

                // Rule 3: Largest-Remainder Rounding so every row sums to exactly 12
                $allocated_spans = self::allocate_row_spans($active_cells);

                $row_columns = [];
                $col_index   = 1;

                foreach ($active_cells as $c_idx => $cell) {
                    $span = $allocated_spans[$c_idx] ?? 12;

                    // Several fields in one cell: all valid fields share the cell column
                    $col_field_ids = [];
                    foreach ($cell['valid_fields'] as $f_key) {
                        $fields_by_key[$f_key]['span'] = $span;
                        $col_field_ids[] = $fields_by_key[$f_key]['id'];
                        $placed_keys[$f_key] = true;
                    }

                    if (!empty($col_field_ids)) {
                        $row_columns[] = [
                            'id'     => 'col_' . $row_index . '_' . $col_index,
                            'span'   => $span,
                            'fields' => $col_field_ids,
                        ];
                        $col_index++;
                    }
                }

                if (!empty($row_columns)) {
                    $schema['rows'][] = [
                        'id'      => 'row_' . $row_index,
                        'columns' => $row_columns,
                    ];
                    $row_index++;
                }
            }
        }

        // Any remaining fields not in formContentData get added as span 12 rows
        $row_index = count($schema['rows']) + 1;
        foreach ($fields_by_key as $k => $f_def) {
            if (!isset($placed_keys[$k])) {
                $f_def['span'] = 12;
                $schema['rows'][] = [
                    'id'      => 'row_' . $row_index,
                    'columns' => [
                        [
                            'id'     => 'col_' . $row_index . '_1',
                            'span'   => 12,
                            'fields' => [$f_def['id']],
                        ],
                    ],
                ];
                $row_index++;
            }
        }

        // Final fields list preserves layout/form order
        $ordered_fields  = [];
        $added_field_ids = [];
        foreach ($schema['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $f_id) {
                    if (!isset($added_field_ids[$f_id])) {
                        foreach ($fields_by_key as $f_def) {
                            if ($f_def['id'] === $f_id) {
                                $ordered_fields[] = $f_def;
                                $added_field_ids[$f_id] = true;
                                break;
                            }
                        }
                    }
                }
            }
        }
        $schema['fields'] = $ordered_fields;

        // Process Actions (Notifications, Confirmations)
        $actions = $data['actions'] ?? [];
        if (is_array($actions)) {
            foreach ($actions as $act) {
                $act_type = strtolower((string) ($act['type'] ?? ''));

                // Action settings may be flat or inside 'settings' key
                $get_val = function(string $key) use ($act): string {
                    if (isset($act[$key]) && is_scalar($act[$key])) {
                        return (string) $act[$key];
                    }
                    if (isset($act['settings'][$key]) && is_scalar($act['settings'][$key])) {
                        return (string) $act['settings'][$key];
                    }
                    return '';
                };

                // Rule 4: List the "save" action as "handled natively"
                if ($act_type === 'save') {
                    $schema['handled_actions'][] = [
                        'type'   => 'save',
                        'label'  => (string) ($act['label'] ?? 'Record Submission'),
                        'status' => 'handled natively',
                    ];
                } elseif ($act_type === 'email') {
                    $to      = $get_val('to') ?: '{admin_email}';
                    $subject = $get_val('email_subject') ?: ($get_val('subject') ?: 'New Form Submission');
                    $msg     = $get_val('email_message') ?: ($get_val('message') ?: '{_all_fields}');
                    $reply   = $get_val('reply_to');
                    $from_name    = $get_val('from_name');
                    $from_address = $get_val('from_address');

                    // Translate merge tags
                    $translate_tags = function(string $str) use ($field_key_to_name): string {
                        $str = str_replace('{wp:admin_email}', '{admin_email}', $str);
                        $str = str_replace('{fields_table}', '{_all_fields}', $str);
                        $str = preg_replace_callback('/\{field:([a-zA-Z0-9_\-]+)\}/', function ($m) use ($field_key_to_name) {
                            $k = $m[1];
                            return isset($field_key_to_name[$k]) ? '{' . $field_key_to_name[$k] . '}' : '{' . $k . '}';
                        }, $str);
                        foreach ($field_key_to_name as $src_k => $dst_n) {
                            if ($src_k !== $dst_n) {
                                $str = str_replace('{' . $src_k . '}', '{' . $dst_n . '}', $str);
                            }
                        }
                        return $str;
                    };

                    $to      = $translate_tags($to);
                    $subject = $translate_tags($subject);
                    $msg     = $translate_tags($msg);
                    $reply   = $translate_tags($reply);

                    $notif_item = [
                        'id'       => 'notif_' . uniqid(),
                        'name'     => (string) ($act['label'] ?? 'Email Notification'),
                        'to'       => $to,
                        'subject'  => $subject,
                        'message'  => $msg,
                        'reply_to' => $reply,
                    ];
                    if ($from_name !== '') {
                        $notif_item['from_name'] = $from_name;
                    }
                    if ($from_address !== '') {
                        $notif_item['from_address'] = $from_address;
                    }

                    $schema['notifications'][] = $notif_item;
                } elseif (in_array($act_type, ['successmessage', 'redirect'], true)) {
                    $redir_url = $get_val('redirect_url');
                    if ($act_type === 'redirect' && !empty($redir_url)) {
                        $schema['confirmations'][] = [
                            'id'           => 'conf_redirect',
                            'type'         => 'redirect',
                            'redirect_url' => esc_url_raw($redir_url),
                        ];
                    } else {
                        $msg = $get_val('message') ?: ($get_val('success_msg') ?: 'Your form has been successfully submitted.');
                        $schema['confirmations'][] = [
                            'id'      => 'conf_message',
                            'type'    => 'message',
                            'message' => wp_kses_post($msg),
                        ];
                    }
                }
            }
        }

        return $schema;
    }

    /**
     * Map Ninja Forms field types to Nexley Form Schema types.
     */
    protected function map_field_type(string $nf_type): string {
        switch ($nf_type) {
            case 'textbox':
            case 'firstname':
            case 'lastname':
            case 'city':
            case 'zip':
            case 'address':
                return 'text';

            case 'email':
                return 'email';

            case 'url':
                return 'url';

            case 'textarea':
                return 'textarea';

            case 'listselect':
            case 'country':
                return 'select';

            case 'listradio':
                return 'radio';

            case 'checkbox':
            case 'listcheckbox':
                return 'checkbox';

            case 'terms':
            case 'consent':
                return 'consent';

            case 'phone':
                return 'phone';

            case 'date':
                return 'date';

            case 'number':
            case 'quantity':
                return 'number';

            case 'html':
                return 'html';

            case 'heading':
                return 'heading';

            case 'paragraph':
                return 'paragraph';

            case 'divider':
            case 'hr':
                return 'divider';

            case 'spacer':
                return 'spacer';

            case 'star_rating':
            case 'rating':
                return 'rating';

            case 'submit':
                return 'submit';

            case 'hidden':
                return 'hidden';

            case 'file':
            case 'file_upload':
                return 'file';

            default:
                return 'text';
        }
    }
}
