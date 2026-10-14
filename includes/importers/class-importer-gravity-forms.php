<?php
declare(strict_types=1);

namespace Nexley_Forms\Importers;

use Nexley_Forms\Form_Schema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Forms Importer Adapter.
 * Status: Documented, unverified.
 * Converts Gravity Forms JSON exports and direct database records (gf_form, gf_form_meta).
 * Maps layoutGridColumnSpan directly to Nexley 12-column layout.
 */
class Importer_Gravity_Forms implements Importer_Interface {

    public function get_id(): string {
        return 'gravity-forms';
    }

    public function get_title(): string {
        return 'Gravity Forms';
    }

    public function get_description(): string {
        return __('Imports forms from Gravity Forms JSON export files or direct gf_form / gf_form_meta tables, preserving 12-column grid spans.', 'nexley-forms');
    }

    public function get_verification_status(): string {
        return 'Documented, unverified';
    }

    /**
     * Check if Gravity Forms is active or if its database tables exist.
     */
    public function is_available(): bool {
        global $wpdb;

        if (class_exists('GFAPI') || class_exists('GFForms')) {
            return true;
        }

        $table_name = $wpdb->prefix . 'gf_form';
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return !empty($found);
    }

    /**
     * Get available forms from Gravity Forms.
     */
    public function get_available_forms(): array {
        global $wpdb;

        if (!$this->is_available()) {
            return [];
        }

        $table_forms = $wpdb->prefix . 'gf_form';
        $forms = [];

        $results = $wpdb->get_results("SELECT id, title FROM {$table_forms} WHERE is_trash = 0 ORDER BY id ASC");
        if (is_array($results)) {
            foreach ($results as $row) {
                $forms[(int) $row->id] = (string) ($row->title ?: "Gravity Form #{$row->id}");
            }
        }

        return $forms;
    }

    /**
     * Convert Gravity Forms data into Nexley Form Schema v1.
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
                // If it's an export array containing multiple forms, take first
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    $decoded = $decoded[0];
                }
                return $this->convert_from_array($decoded);
            }
        }

        if (is_array($source)) {
            if (isset($source[0]) && is_array($source[0])) {
                $source = $source[0];
            }
            return $this->convert_from_array($source);
        }

        return Form_Schema::get_default_schema();
    }

    /**
     * Convert from Gravity Forms database tables.
     */
    protected function convert_from_database(int $form_id): array {
        global $wpdb;

        $table_forms = $wpdb->prefix . 'gf_form';
        $table_meta  = $wpdb->prefix . 'gf_form_meta';

        $meta_row = $wpdb->get_row($wpdb->prepare("SELECT display_meta FROM {$table_meta} WHERE form_id = %d LIMIT 1", $form_id));
        if ($meta_row && !empty($meta_row->display_meta)) {
            $decoded = json_decode((string) $meta_row->display_meta, true);
            if (is_array($decoded)) {
                return $this->convert_from_array($decoded);
            }
        }

        $form_row = $wpdb->get_row($wpdb->prepare("SELECT title FROM {$table_forms} WHERE id = %d LIMIT 1", $form_id));
        $title = $form_row ? (string) $form_row->title : "Gravity Form #{$form_id}";

        return Form_Schema::get_default_schema(0, $title);
    }

    /**
     * Convert from parsed Gravity Forms structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convert_from_array(array $data): array {
        $title = !empty($data['title']) ? sanitize_text_field((string) $data['title']) : 'Imported Gravity Form';

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

        $gf_fields = $data['fields'] ?? [];
        if (!is_array($gf_fields)) {
            $gf_fields = [];
        }

        $current_row_columns = [];
        $current_row_span    = 0;
        $row_index           = 1;

        $id_to_name_map = [];

        foreach ($gf_fields as $gf_f) {
            $gf_id    = (string) ($gf_f['id'] ?? uniqid());
            $gf_type  = strtolower((string) ($gf_f['type'] ?? 'text'));
            $gf_label = (string) ($gf_f['label'] ?? '');
            $gf_req   = !empty($gf_f['isRequired']);

            // Gravity Forms 2.5+ 12-column span
            $span = isset($gf_f['layoutGridColumnSpan']) ? max(1, min(12, (int) $gf_f['layoutGridColumnSpan'])) : 12;

            $nexley_type = $this->map_field_type($gf_type);
            $field_name  = sanitize_key(!empty($gf_f['adminLabel']) ? $gf_f['adminLabel'] : ($gf_label ? sanitize_title($gf_label) : "field_{$gf_id}"));
            $field_id    = 'f_gf_' . $gf_id;

            $id_to_name_map[$gf_id] = $field_name;

            $field_def = [
                'id'       => $field_id,
                'type'     => $nexley_type,
                'name'     => $field_name,
                'label'    => $gf_label ?: ucwords(str_replace('_', ' ', $field_name)),
                'span'     => $span,
                'required' => $gf_req,
            ];

            if (!empty($gf_f['placeholder'])) {
                $field_def['placeholder'] = (string) $gf_f['placeholder'];
            }

            if (!empty($gf_f['defaultValue'])) {
                $field_def['default_value'] = (string) $gf_f['defaultValue'];
            }

            // Choices / Options
            if (!empty($gf_f['choices']) && is_array($gf_f['choices'])) {
                $opts = [];
                foreach ($gf_f['choices'] as $choice) {
                    $c_text  = (string) ($choice['text'] ?? '');
                    $c_val   = (string) ($choice['value'] ?? $c_text);
                    if ($c_text !== '') {
                        $opts[] = ['label' => $c_text, 'value' => $c_val];
                    }
                }
                if (!empty($opts)) {
                    $field_def['options'] = $opts;
                }
            }

            if ($nexley_type === 'html' && !empty($gf_f['content'])) {
                $field_def['content'] = wp_kses_post((string) $gf_f['content']);
            }

            $schema['fields'][] = $field_def;

            // Reconstruct 12-column grid rows
            if ($current_row_span + $span > 12 && !empty($current_row_columns)) {
                $schema['rows'][] = [
                    'id'      => 'row_' . $row_index,
                    'columns' => $current_row_columns,
                ];
                $row_index++;
                $current_row_columns = [];
                $current_row_span    = 0;
            }

            $col_index = count($current_row_columns) + 1;
            $current_row_columns[] = [
                'id'     => 'col_' . $row_index . '_' . $col_index,
                'span'   => $span,
                'fields' => [$field_id],
            ];
            $current_row_span += $span;
        }

        // Add final row if not empty
        if (!empty($current_row_columns)) {
            $schema['rows'][] = [
                'id'      => 'row_' . $row_index,
                'columns' => $current_row_columns,
            ];
            $row_index++;
        }

        // Append Submit button if not present
        $has_submit = false;
        foreach ($schema['fields'] as $f) {
            if ($f['type'] === 'submit') {
                $has_submit = true;
                break;
            }
        }
        if (!$has_submit) {
            $submit_btn_text = !empty($data['button']['text']) ? (string) $data['button']['text'] : 'Submit';
            $schema['fields'][] = [
                'id'          => 'f_submit',
                'type'        => 'submit',
                'name'        => 'submit_btn',
                'label'       => '',
                'button_text' => $submit_btn_text,
                'span'        => 12,
            ];
            $schema['rows'][] = [
                'id'      => 'row_' . $row_index,
                'columns' => [
                    [
                        'id'     => 'col_' . $row_index . '_1',
                        'span'   => 12,
                        'fields' => ['f_submit'],
                    ],
                ],
            ];
        }

        // Process Notifications
        if (!empty($data['notifications']) && is_array($data['notifications'])) {
            foreach ($data['notifications'] as $notif) {
                $to      = (string) ($notif['to'] ?? get_option('admin_email'));
                $subject = (string) ($notif['subject'] ?? 'New Gravity Forms Submission');
                $msg     = (string) ($notif['message'] ?? '{_all_fields}');
                $reply   = (string) ($notif['replyTo'] ?? '');

                // Map GF merge tags {Field:1} or {all_fields}
                $msg = str_replace('{all_fields}', '{_all_fields}', $msg);
                foreach ($id_to_name_map as $gf_id => $nex_name) {
                    $msg   = preg_replace('/\{[^:]+:' . preg_quote((string) $gf_id, '/') . '\}/', '{' . $nex_name . '}', $msg);
                    $to    = preg_replace('/\{[^:]+:' . preg_quote((string) $gf_id, '/') . '\}/', '{' . $nex_name . '}', $to);
                    $reply = preg_replace('/\{[^:]+:' . preg_quote((string) $gf_id, '/') . '\}/', '{' . $nex_name . '}', $reply);
                }

                $schema['notifications'][] = [
                    'id'       => 'notif_' . uniqid(),
                    'name'     => (string) ($notif['name'] ?? 'Admin Notification'),
                    'to'       => $to,
                    'subject'  => $subject,
                    'message'  => $msg,
                    'reply_to' => $reply,
                ];
            }
        }

        // Process Confirmations
        if (!empty($data['confirmations']) && is_array($data['confirmations'])) {
            foreach ($data['confirmations'] as $conf) {
                $c_type = strtolower((string) ($conf['type'] ?? 'message'));
                if ($c_type === 'redirect' && !empty($conf['url'])) {
                    $schema['confirmations'][] = [
                        'id'           => 'conf_redirect',
                        'type'         => 'redirect',
                        'redirect_url' => esc_url_raw((string) $conf['url']),
                    ];
                } else {
                    $msg = (string) ($conf['message'] ?? 'Thank you for contacting us.');
                    $schema['confirmations'][] = [
                        'id'      => 'conf_message',
                        'type'    => 'message',
                        'message' => wp_kses_post($msg),
                    ];
                }
            }
        }

        return $schema;
    }

    /**
     * Map Gravity Forms field types to Nexley types.
     */
    protected function map_field_type(string $gf_type): string {
        switch ($gf_type) {
            case 'text':
            case 'name':
            case 'address':
                return 'text';

            case 'email':
                return 'email';

            case 'textarea':
                return 'textarea';

            case 'select':
            case 'multiselect':
                return 'select';

            case 'radio':
                return 'radio';

            case 'checkbox':
                return 'checkbox';

            case 'number':
            case 'quantity':
                return 'number';

            case 'phone':
                return 'phone';

            case 'date':
                return 'date';

            case 'time':
                return 'time';

            case 'website':
                return 'url';

            case 'fileupload':
                return 'file';

            case 'section':
                return 'heading';

            case 'page':
                return 'step';

            case 'html':
                return 'html';

            case 'hidden':
                return 'hidden';

            default:
                return 'text';
        }
    }
}
