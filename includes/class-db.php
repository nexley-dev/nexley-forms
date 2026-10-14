<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages custom database tables for Nexley Forms entries.
 * Moves submissions out of the posts table into a dedicated, high-performance table.
 */
final class DB {

    public const DB_VERSION = '2.0.0';
    public const DB_VERSION_OPTION = 'nexley_forms_db_version';
    public const TABLE_ENTRIES = 'nexley_entries';

    /**
     * Get the full table name with WordPress prefix.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_ENTRIES;
    }

    /**
     * Initialize DB subsystem on plugins_loaded and run migrations if version changed.
     */
    public static function init(): void {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
        if (version_compare((string) $installed_version, self::DB_VERSION, '<')) {
            self::install_or_upgrade();
        }
    }

    /**
     * Create or upgrade custom database tables using dbDelta.
     */
    public static function install_or_upgrade(): void {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Schema with composite index on (form_id, status, created_at) and unique legacy_post_id
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            legacy_post_id bigint(20) unsigned DEFAULT NULL,
            form_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'unread',
            data longtext NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent text NOT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY legacy_post_id (legacy_post_id),
            KEY form_status_date (form_id, status, created_at),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        // Run automatic CPT-entries migration.
        Migration::run_migrations();
    }

    /**
     * Insert a new entry.
     *
     * @param array{
     *     form_id: int,
     *     legacy_post_id?: int|null,
     *     status?: string,
     *     data: array<string, mixed>,
     *     ip_address?: string,
     *     user_agent?: string,
     *     created_at?: string
     * } $args
     * @return int Entry ID or 0 on failure.
     */
    public static function insert_entry(array $args): int {
        global $wpdb;

        $table_name     = self::get_table_name();
        $form_id        = absint($args['form_id'] ?? 0);
        $legacy_post_id = !empty($args['legacy_post_id']) ? absint($args['legacy_post_id']) : null;
        $status         = sanitize_key($args['status'] ?? 'unread');
        $data_json      = wp_json_encode($args['data'] ?? []);
        $ip_address     = sanitize_text_field($args['ip_address'] ?? '');
        $user_agent     = sanitize_text_field($args['user_agent'] ?? '');
        $now            = current_time('mysql');
        $created_at     = !empty($args['created_at']) ? sanitize_text_field($args['created_at']) : $now;

        $data_row = [
            'form_id'    => $form_id,
            'status'     => $status,
            'data'       => $data_json,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'created_at' => $created_at,
            'updated_at' => $now,
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($legacy_post_id !== null) {
            $data_row['legacy_post_id'] = $legacy_post_id;
            $formats[] = '%d';
        }

        $result = $wpdb->insert($table_name, $data_row, $formats);

        if ($result === false) {
            return 0;
        }

        $entry_id = (int) $wpdb->insert_id;

        /**
         * Fires immediately after a new form entry is stored in the database.
         *
         * @param int                  $entry_id Newly created entry ID.
         * @param int                  $form_id  Associated form ID.
         * @param array<string, mixed> $data     Submitted field key-value pairs.
         */
        do_action('nexley_forms_entry_created', $entry_id, $form_id, $args['data'] ?? []);

        return $entry_id;
    }

    /**
     * Get a single entry by ID.
     *
     * @param int $entry_id
     * @return object|null
     */
    public static function get_entry(int $entry_id): ?object {
        global $wpdb;

        $table_name = self::get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d LIMIT 1", $entry_id)
        );

        if (!$row) {
            return null;
        }

        $row->id      = (int) $row->id;
        $row->form_id = (int) $row->form_id;
        $row->legacy_post_id = $row->legacy_post_id !== null ? (int) $row->legacy_post_id : null;
        $decoded      = json_decode((string) $row->data, true);
        $row->data    = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * Update an entry status (unread, read, spam, trash).
     *
     * @param int $entry_id
     * @param string $status
     * @return bool
     */
    public static function update_entry_status(int $entry_id, string $status): bool {
        global $wpdb;

        $allowed_statuses = ['unread', 'read', 'spam', 'trash'];
        $status = sanitize_key($status);
        if (!in_array($status, $allowed_statuses, true)) {
            return false;
        }

        $table_name = self::get_table_name();
        $updated = $wpdb->update(
            $table_name,
            [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $entry_id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * Delete an entry permanently.
     *
     * @param int $entry_id
     * @return bool
     */
    public static function delete_entry(int $entry_id): bool {
        global $wpdb;

        $table_name = self::get_table_name();
        $deleted = $wpdb->delete($table_name, ['id' => $entry_id], ['%d']);

        if ($deleted) {
            /**
             * Fires after an entry is permanently removed from the database.
             *
             * @param int $entry_id Deleted entry ID.
             */
            do_action('nexley_forms_entry_deleted', $entry_id);
            return true;
        }

        return false;
    }

    /**
     * Query entries with pagination, status filter, form filter, search, and date range.
     *
     * @param array{
     *     form_id?: int,
     *     status?: string,
     *     search?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     per_page?: int,
     *     offset?: int,
     *     orderby?: string,
     *     order?: string
     * } $args
     * @return array{entries: list<object>, total: int}
     */
    public static function get_entries(array $args = []): array {
        global $wpdb;

        $table_name = self::get_table_name();
        $where      = ['1=1'];
        $params     = [];

        if (!empty($args['form_id'])) {
            $where[]  = 'form_id = %d';
            $params[] = absint($args['form_id']);
        }

        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where[]  = 'status = %s';
            $params[] = sanitize_key($args['status']);
        } else {
            // Default: exclude trash unless explicitly queried
            if (empty($args['status'])) {
                $where[] = "status != 'trash'";
            }
        }

        if (!empty($args['search'])) {
            $where[]  = 'data LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        if (!empty($args['date_from'])) {
            $where[]  = 'created_at >= %s';
            $params[] = sanitize_text_field($args['date_from']) . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[]  = 'created_at <= %s';
            $params[] = sanitize_text_field($args['date_to']) . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where);

        // Count total matching records.
        $count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        $orderby = !empty($args['orderby']) && in_array(strtolower($args['orderby']), ['id', 'created_at', 'status', 'form_id'], true)
            ? strtolower($args['orderby'])
            : 'id';
        $order   = !empty($args['order']) && strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : 20;
        $offset   = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;

        if ($per_page === -1) {
            $query_sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT 50000";
            $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($query_sql, $params)) : $wpdb->get_results($query_sql);
        } else {
            $per_page = max(1, $per_page);
            $query_sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
            $query_params = array_merge($params, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($query_sql, $query_params));
        }

        $entries = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $row->id      = (int) $row->id;
                $row->form_id = (int) $row->form_id;
                $row->legacy_post_id = $row->legacy_post_id !== null ? (int) $row->legacy_post_id : null;
                $decoded      = json_decode((string) $row->data, true);
                $row->data    = is_array($decoded) ? $decoded : [];
                $entries[]    = $row;
            }
        }

        return [
            'entries' => $entries,
            'total'   => $total,
        ];
    }

    /**
     * Batch update entry statuses.
     *
     * @param list<int> $entry_ids
     * @param string    $status
     * @return int Number of updated rows.
     */
    public static function update_entries_status(array $entry_ids, string $status): int {
        global $wpdb;

        $allowed_statuses = ['unread', 'read', 'spam', 'trash'];
        $status = sanitize_key($status);
        if (!in_array($status, $allowed_statuses, true)) {
            return 0;
        }

        $clean_ids = array_filter(array_map('absint', $entry_ids));
        if (empty($clean_ids)) {
            return 0;
        }

        $table_name = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));
        $now = current_time('mysql');

        $query = $wpdb->prepare(
            "UPDATE {$table_name} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
            array_merge([$status, $now], $clean_ids)
        );

        $result = $wpdb->query($query);
        return $result !== false ? (int) $result : 0;
    }

    /**
     * Batch permanently delete entries.
     *
     * @param list<int> $entry_ids
     * @return int Number of deleted rows.
     */
    public static function delete_entries(array $entry_ids): int {
        global $wpdb;

        $clean_ids = array_filter(array_map('absint', $entry_ids));
        if (empty($clean_ids)) {
            return 0;
        }

        $table_name = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));

        $query = $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
            $clean_ids
        );

        $result = $wpdb->query($query);
        if ($result) {
            foreach ($clean_ids as $id) {
                do_action('nexley_forms_entry_deleted', $id);
            }
        }

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Get counts grouped by status for a form (or all forms).
     *
     * @param int $form_id Optional form ID.
     * @return array<string, int>
     */
    public static function get_status_counts(int $form_id = 0): array {
        global $wpdb;

        $table_name = self::get_table_name();
        $where      = '';
        $params     = [];

        if ($form_id > 0) {
            $where    = 'WHERE form_id = %d';
            $params[] = $form_id;
        }

        $sql = "SELECT status, COUNT(*) as cnt FROM {$table_name} {$where} GROUP BY status";
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $results = $wpdb->get_results($sql);
        }

        $counts = [
            'all'    => 0,
            'unread' => 0,
            'read'   => 0,
            'spam'   => 0,
            'trash'  => 0,
        ];

        if (is_array($results)) {
            foreach ($results as $r) {
                $status = (string) $r->status;
                $cnt    = (int) $r->cnt;
                $counts[$status] = $cnt;
                if ($status !== 'trash') {
                    $counts['all'] += $cnt;
                }
            }
        }

        return $counts;
    }
}
