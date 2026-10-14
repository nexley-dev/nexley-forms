<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles Privacy, GDPR Compliance, and Entry Retention:
 * - IP anonymization / hashing.
 * - Automatic retention cleanup via WP-Cron.
 * - WordPress Core Privacy Data Exporter & Eraser hooks.
 */
final class Privacy {

    public const CRON_HOOK = 'nexley_forms_cleanup_entries';

    /**
     * Register privacy hooks and cron tasks.
     */
    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'cleanup_expired_entries']);

        // Schedule daily cleanup cron if not scheduled
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }

        // WordPress Core Privacy integration
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    /**
     * Anonymize or hash an IP address if privacy setting is enabled.
     *
     * @param string $ip
     * @param bool   $anonymize
     * @return string
     */
    public static function process_ip(string $ip, bool $anonymize = false): string {
        if (!$anonymize) {
            return $ip;
        }

        if (function_exists('wp_privacy_anonymize_data')) {
            return wp_privacy_anonymize_data('ip', $ip);
        }

        return substr($ip, 0, (int) strrpos($ip, '.')) . '.0';
    }

    /**
     * Clean up expired entries based on per-form retention_days setting.
     */
    public static function cleanup_expired_entries(): void {
        global $wpdb;

        $table = DB::get_table_name();
        $forms = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($forms as $form_id) {
            $schema = Form_Schema::get_form_schema($form_id);
            $retention_days = isset($schema['settings']['retention_days']) ? absint($schema['settings']['retention_days']) : 0;

            if ($retention_days > 0) {
                $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table} WHERE form_id = %d AND created_at < %s",
                        $form_id,
                        $cutoff
                    )
                );
            }
        }
    }

    /**
     * Register WordPress Core Privacy Exporter.
     *
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public static function register_exporter(array $exporters): array {
        $exporters['nexley-forms-entries'] = [
            'exporter_friendly_name' => __('Nexley Forms Submissions', 'nexley-forms'),
            'callback'               => [self::class, 'export_personal_data'],
        ];
        return $exporters;
    }

    /**
     * Export personal data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public static function export_personal_data(string $email_address, int $page = 1): array {
        global $wpdb;

        $table = DB::get_table_name();
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $like = '%' . $wpdb->esc_like($email_address) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE data LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $like,
                $per_page,
                $offset
            )
        );

        $export_items = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                if (!is_array($data)) {
                    continue;
                }

                $item_data = [
                    ['name' => __('Form ID', 'nexley-forms'), 'value' => (string) $row->form_id],
                    ['name' => __('Submission Date', 'nexley-forms'), 'value' => (string) $row->created_at],
                    ['name' => __('IP Address', 'nexley-forms'), 'value' => (string) $row->ip_address],
                ];

                foreach ($data as $k => $v) {
                    $val_str = is_array($v) ? implode(', ', $v) : (string) $v;
                    $item_data[] = [
                        'name'  => ucwords(str_replace(['-', '_'], ' ', (string) $k)),
                        'value' => $val_str,
                    ];
                }

                $export_items[] = [
                    'group_id'    => 'nexley_form_entries',
                    'group_label' => __('Form Submissions', 'nexley-forms'),
                    'item_id'     => 'nexley-entry-' . $row->id,
                    'data'        => $item_data,
                ];
            }
        }

        $done = count($rows) < $per_page;

        return [
            'data' => $export_items,
            'done' => $done,
        ];
    }

    /**
     * Register WordPress Core Privacy Eraser.
     *
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public static function register_eraser(array $erasers): array {
        $erasers['nexley-forms-entries'] = [
            'eraser_friendly_name' => __('Nexley Forms Submissions', 'nexley-forms'),
            'callback'             => [self::class, 'erase_personal_data'],
        ];
        return $erasers;
    }

    /**
     * Erase personal data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array{items_removed: int, items_retained: int, messages: list<string>, done: bool}
     */
    public static function erase_personal_data(string $email_address, int $page = 1): array {
        global $wpdb;

        $table = DB::get_table_name();
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $like = '%' . $wpdb->esc_like($email_address) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE data LIKE %s LIMIT %d OFFSET %d",
                $like,
                $per_page,
                $offset
            )
        );

        $removed = 0;
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $deleted = DB::delete_entry((int) $r->id);
                if ($deleted) {
                    $removed++;
                }
            }
        }

        $done = count($rows) < $per_page;

        return [
            'items_removed'  => $removed,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => $done,
        ];
    }
}
