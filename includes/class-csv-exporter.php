<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles high-performance, formula-safe CSV exports of form entries.
 * Escapes CSV formula injection vectors (=, +, -, @, \t, \r) and streams output.
 */
final class CSV_Exporter {

    public const NONCE_ACTION = 'nexley_export_entries';
    public const NONCE_NAME   = 'nexley_export_nonce';

    /**
     * Escape formula injection vectors for a single cell.
     * Prevents CSV Injection / CWE-1236 in Microsoft Excel, Google Sheets, and LibreOffice.
     *
     * @param mixed $value
     * @return string
     */
    public static function escape_formula(mixed $value): string {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        $first_char = $str[0];
        if (in_array($first_char, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Write entries CSV to a stream resource.
     *
     * @param resource             $stream     Output stream (e.g. php://output or temp handle).
     * @param array<string, mixed> $query_args Filter arguments for DB::get_entries.
     * @return int Number of data rows written.
     */
    public static function write_to_stream($stream, array $query_args = []): int {
        if (!is_resource($stream)) {
            return 0;
        }

        // Write UTF-8 BOM so Microsoft Excel renders international characters properly.
        fwrite($stream, "\xEF\xBB\xBF");

        $form_id = !empty($query_args['form_id']) ? absint($query_args['form_id']) : 0;
        $schema  = $form_id > 0 ? Form_Schema::get_form_schema($form_id) : null;

        // Build header row and mapping
        $headers   = [];
        $field_map = []; // field_name => label

        if ($schema && !empty($schema['fields'])) {
            $headers = [
                __('Entry ID', 'nexley-forms'),
                __('Status', 'nexley-forms'),
                __('Submission Date', 'nexley-forms'),
            ];

            foreach ($schema['fields'] as $field) {
                $name  = $field['name'] ?? '';
                $label = !empty($field['label']) ? $field['label'] : $name;
                $type  = $field['type'] ?? '';

                // Skip purely visual presentation fields
                if (in_array($type, ['html', 'divider', 'heading', 'step'], true)) {
                    continue;
                }

                if (!empty($name)) {
                    $field_map[$name] = $label;
                    $headers[]        = self::escape_formula($label);
                }
            }

            $headers[] = __('IP Address', 'nexley-forms');
            $headers[] = __('User Agent', 'nexley-forms');
        } else {
            // Generic export for multiple forms or form without schema
            $headers = [
                __('Entry ID', 'nexley-forms'),
                __('Form ID', 'nexley-forms'),
                __('Form Title', 'nexley-forms'),
                __('Status', 'nexley-forms'),
                __('Submission Date', 'nexley-forms'),
                __('Submitted Data', 'nexley-forms'),
                __('IP Address', 'nexley-forms'),
                __('User Agent', 'nexley-forms'),
            ];
        }

        fputcsv($stream, $headers);

        $batch_size = 500;
        $offset     = 0;
        $total_rows = 0;

        $form_titles = [];

        while (true) {
            $batch_args = array_merge($query_args, [
                'per_page' => $batch_size,
                'offset'   => $offset,
            ]);

            $result  = DB::get_entries($batch_args);
            $entries = $result['entries'];

            if (empty($entries)) {
                break;
            }

            foreach ($entries as $entry) {
                $row = [];

                if ($schema && !empty($field_map)) {
                    $row[] = (string) $entry->id;
                    $row[] = self::escape_formula($entry->status);
                    $row[] = self::escape_formula($entry->created_at);

                    foreach ($field_map as $field_name => $field_label) {
                        $val = $entry->data[$field_name] ?? '';
                        if (is_array($val)) {
                            $val = implode(', ', array_map('strval', $val));
                        }
                        $row[] = self::escape_formula($val);
                    }

                    $row[] = self::escape_formula($entry->ip_address);
                    $row[] = self::escape_formula($entry->user_agent);
                } else {
                    $f_id = $entry->form_id;
                    if (!isset($form_titles[$f_id])) {
                        $form_post = get_post($f_id);
                        $form_titles[$f_id] = $form_post ? $form_post->post_title : "Form #{$f_id}";
                    }

                    $row[] = (string) $entry->id;
                    $row[] = (string) $entry->form_id;
                    $row[] = self::escape_formula($form_titles[$f_id]);
                    $row[] = self::escape_formula($entry->status);
                    $row[] = self::escape_formula($entry->created_at);
                    $row[] = self::escape_formula(wp_json_encode($entry->data));
                    $row[] = self::escape_formula($entry->ip_address);
                    $row[] = self::escape_formula($entry->user_agent);
                }

                fputcsv($stream, $row);
                $total_rows++;
            }

            $offset += count($entries);
            if ($offset >= $result['total']) {
                break;
            }
        }

        return $total_rows;
    }

    /**
     * Trigger browser CSV file download.
     *
     * @param array<string, mixed> $query_args
     */
    public static function export_download(array $query_args = []): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized export access.', 'nexley-forms'), 403);
        }

        $form_id  = !empty($query_args['form_id']) ? absint($query_args['form_id']) : 0;
        $date_str = gmdate('Y-m-d');
        $slug     = $form_id > 0 ? "form-{$form_id}" : 'all-forms';
        $filename = "nexley-entries-{$slug}-{$date_str}.csv";

        // Disable compression / output buffering for streaming
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('zlib.output_compression', 'Off');

        // Clear output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        if ($output) {
            self::write_to_stream($output, $query_args);
            fclose($output);
        }

        exit;
    }
}
