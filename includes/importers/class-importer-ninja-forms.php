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
        return 'Documented, unverified';
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
            'title'   => $title,
            'fields'  => $nf_fields,
            'actions' => $nf_actions,
        ]);
    }

    /**
     * Convert from parsed Ninja Forms structure.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convert_from_array(array $data): array {
        $title = !empty($data['title']) ? sanitize_text_field((string) $data['title']) : 'Imported Ninja Form';

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

        $fields_source = $data['fields'] ?? [];
        if (!is_array($fields_source)) {
            $fields_source = [];
        }

        $row_index = 1;

        foreach ($fields_source as $nf_f) {
            $nf_type  = strtolower((string) ($nf_f['type'] ?? 'textbox'));
            $nf_label = (string) ($nf_f['label'] ?? '');
            $nf_key   = sanitize_key((string) ($nf_f['key'] ?? ''));
            $nf_req   = !empty($nf_f['required']);

            $nexley_type = $this->map_field_type($nf_type);
            $field_id    = 'f_' . ($nf_key ?: uniqid('nf_'));

            $field_def = [
                'id'       => $field_id,
                'type'     => $nexley_type,
                'name'     => $nf_key ?: str_replace('-', '_', $field_id),
                'label'    => $nf_label ?: ucwords(str_replace('_', ' ', $nf_key)),
                'span'     => 12,
                'required' => $nf_req,
            ];

            if (!empty($nf_f['placeholder'])) {
                $field_def['placeholder'] = (string) $nf_f['placeholder'];
            }

            if (!empty($nf_f['default_value'])) {
                $field_def['default_value'] = (string) $nf_f['default_value'];
            }

            // Options for select, radio, checkbox
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
            if ($nexley_type === 'html' && !empty($nf_f['default_value'])) {
                $field_def['content'] = wp_kses_post((string) $nf_f['default_value']);
            }

            $schema['fields'][] = $field_def;

            // Model row structure
            $schema['rows'][] = [
                'id'      => 'row_' . $row_index,
                'columns' => [
                    [
                        'id'     => 'col_' . $row_index . '_1',
                        'span'   => 12,
                        'fields' => [$field_id],
                    ],
                ],
            ];
            $row_index++;
        }

        // Process Actions (Notifications, Confirmations)
        $actions = $data['actions'] ?? [];
        if (is_array($actions)) {
            foreach ($actions as $act) {
                $act_type     = strtolower((string) ($act['type'] ?? ''));
                $act_settings = $act['settings'] ?? [];

                if ($act_type === 'email') {
                    $to      = (string) ($act_settings['to'] ?? get_option('admin_email'));
                    $subject = (string) ($act_settings['email_subject'] ?? $act_settings['subject'] ?? 'New Form Submission');
                    $msg     = (string) ($act_settings['email_message'] ?? $act_settings['message'] ?? '{_all_fields}');
                    $reply   = (string) ($act_settings['reply_to'] ?? '');

                    // Map Ninja Forms merge tags {field:key} to Nexley {key}
                    $msg   = preg_replace('/\{field:([a-zA-Z0-9_\-]+)\}/', '{$1}', $msg);
                    $to    = preg_replace('/\{field:([a-zA-Z0-9_\-]+)\}/', '{$1}', $to);
                    $reply = preg_replace('/\{field:([a-zA-Z0-9_\-]+)\}/', '{$1}', $reply);

                    $schema['notifications'][] = [
                        'id'       => 'notif_' . uniqid(),
                        'name'     => (string) ($act['label'] ?? 'Email Notification'),
                        'to'       => $to,
                        'subject'  => $subject,
                        'message'  => $msg,
                        'reply_to' => $reply,
                    ];
                } elseif (in_array($act_type, ['successmessage', 'redirect'], true)) {
                    if ($act_type === 'redirect' && !empty($act_settings['redirect_url'])) {
                        $schema['confirmations'][] = [
                            'id'           => 'conf_redirect',
                            'type'         => 'redirect',
                            'redirect_url' => esc_url_raw((string) $act_settings['redirect_url']),
                        ];
                    } else {
                        $msg = (string) ($act_settings['message'] ?? 'Thank you for your submission.');
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

            case 'textarea':
                return 'textarea';

            case 'listselect':
                return 'select';

            case 'listradio':
                return 'radio';

            case 'listcheckbox':
                return 'checkbox';

            case 'phone':
                return 'phone';

            case 'date':
                return 'date';

            case 'number':
            case 'quantity':
                return 'number';

            case 'html':
                return 'html';

            case 'divider':
            case 'hr':
                return 'divider';

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
