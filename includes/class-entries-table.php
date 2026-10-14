<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom WP List Table for managing Nexley Forms entries from the dedicated database table.
 */
class Entries_Table extends \WP_List_Table {

    /**
     * Currently active form ID filter.
     */
    protected int $current_form_id = 0;

    /**
     * Currently active status filter.
     */
    protected string $current_status = 'all';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'entry',
            'plural'   => 'entries',
            'ajax'     => false,
        ]);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $this->current_form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $this->current_status  = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all';
    }

    /**
     * Define table columns.
     *
     * @return array<string, string>
     */
    public function get_columns(): array {
        return [
            'cb'           => '<input type="checkbox" />',
            'id'           => __('ID', 'nexley-forms'),
            'form'         => __('Form', 'nexley-forms'),
            'data_preview' => __('Submitted Details', 'nexley-forms'),
            'status'       => __('Status', 'nexley-forms'),
            'ip_address'   => __('IP Address', 'nexley-forms'),
            'created_at'   => __('Date Submitted', 'nexley-forms'),
        ];
    }

    /**
     * Define sortable columns.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array {
        return [
            'id'         => ['id', true],
            'form'       => ['form_id', false],
            'status'     => ['status', false],
            'created_at' => ['created_at', false],
        ];
    }

    /**
     * Define view filters (All, Unread, Read, Spam, Trash).
     *
     * @return array<string, string>
     */
    protected function get_views(): array {
        $counts = DB::get_status_counts($this->current_form_id);
        $views  = [];

        $base_url = admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=nexley-entries');
        if ($this->current_form_id > 0) {
            $base_url = add_query_arg('form_id', $this->current_form_id, $base_url);
        }

        $labels = [
            'all'    => __('All', 'nexley-forms'),
            'unread' => __('Unread', 'nexley-forms'),
            'read'   => __('Read', 'nexley-forms'),
            'spam'   => __('Spam', 'nexley-forms'),
            'trash'  => __('Trash', 'nexley-forms'),
        ];

        foreach ($labels as $status_key => $label) {
            $count = $counts[$status_key] ?? 0;
            $class = ($this->current_status === $status_key) ? 'current' : '';
            $url   = ($status_key === 'all') ? $base_url : add_query_arg('status', $status_key, $base_url);

            $views[$status_key] = sprintf(
                '<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
                esc_url($url),
                esc_attr($class),
                esc_html($label),
                $count
            );
        }

        return $views;
    }

    /**
     * Define bulk actions.
     *
     * @return array<string, string>
     */
    protected function get_bulk_actions(): array {
        if ($this->current_status === 'trash') {
            return [
                'untrash' => __('Restore', 'nexley-forms'),
                'delete'  => __('Delete Permanently', 'nexley-forms'),
            ];
        }

        return [
            'mark_read'   => __('Mark as Read', 'nexley-forms'),
            'mark_unread' => __('Mark as Unread', 'nexley-forms'),
            'mark_spam'   => __('Mark as Spam', 'nexley-forms'),
            'trash'       => __('Move to Trash', 'nexley-forms'),
        ];
    }

    /**
     * Extra navigation controls (Form filter, Date filters, Export button).
     *
     * @param string $which 'top' or 'bottom'
     */
    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }

        $forms = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');

        $export_url = wp_nonce_url(
            add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => 'nexley-entries',
                'action'    => 'export_csv',
                'form_id'   => $this->current_form_id,
                'status'    => $this->current_status,
                'date_from' => $date_from,
                'date_to'   => $date_to,
            ], admin_url('edit.php')),
            CSV_Exporter::NONCE_ACTION,
            CSV_Exporter::NONCE_NAME
        );
        ?>
        <div class="alignleft actions">
            <label for="filter-by-form" class="screen-reader-text"><?php esc_html_e('Filter by form', 'nexley-forms'); ?></label>
            <select name="form_id" id="filter-by-form">
                <option value="0"><?php esc_html_e('All Forms', 'nexley-forms'); ?></option>
                <?php foreach ($forms as $f) : ?>
                    <option value="<?php echo esc_attr((string) $f->ID); ?>" <?php selected($this->current_form_id, $f->ID); ?>>
                        <?php echo esc_html($f->post_title ?: "#{$f->ID}"); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="filter-date-from" class="screen-reader-text"><?php esc_html_e('From date', 'nexley-forms'); ?></label>
            <input type="date" id="filter-date-from" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="YYYY-MM-DD" title="<?php esc_attr_e('From date', 'nexley-forms'); ?>" />

            <label for="filter-date-to" class="screen-reader-text"><?php esc_html_e('To date', 'nexley-forms'); ?></label>
            <input type="date" id="filter-date-to" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="YYYY-MM-DD" title="<?php esc_attr_e('To date', 'nexley-forms'); ?>" />

            <?php submit_button(__('Filter', 'nexley-forms'), '', 'filter_action', false, ['id' => 'post-query-submit']); ?>

            <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary nexley-export-csv-btn">
                <span class="dashicons dashicons-download" style="margin-top: 3px; font-size: 16px;"></span>
                <?php esc_html_e('Export to CSV', 'nexley-forms'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Checkbox column.
     *
     * @param object $item
     * @return string
     */
    protected function column_cb($item): string {
        return sprintf(
            '<input type="checkbox" name="entry_id[]" value="%d" />',
            (int) $item->id
        );
    }

    /**
     * ID column with row actions.
     *
     * @param object $item
     * @return string
     */
    protected function column_id($item): string {
        $view_url = add_query_arg([
            'post_type' => Post_Types::CPT_FORM,
            'page'      => 'nexley-entries',
            'action'    => 'view',
            'id'        => $item->id,
        ], admin_url('edit.php'));

        $actions = [];

        $actions['view'] = sprintf(
            '<a href="%s"><strong>%s</strong></a>',
            esc_url($view_url),
            esc_html__('View Details', 'nexley-forms')
        );

        if ($item->status !== 'trash') {
            if ($item->status === 'unread') {
                $mark_read_url = wp_nonce_url(add_query_arg([
                    'post_type' => Post_Types::CPT_FORM,
                    'page'      => 'nexley-entries',
                    'action'    => 'mark_read',
                    'id'        => $item->id,
                ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

                $actions['mark_read'] = sprintf('<a href="%s">%s</a>', esc_url($mark_read_url), esc_html__('Mark Read', 'nexley-forms'));
            } else {
                $mark_unread_url = wp_nonce_url(add_query_arg([
                    'post_type' => Post_Types::CPT_FORM,
                    'page'      => 'nexley-entries',
                    'action'    => 'mark_unread',
                    'id'        => $item->id,
                ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

                $actions['mark_unread'] = sprintf('<a href="%s">%s</a>', esc_url($mark_unread_url), esc_html__('Mark Unread', 'nexley-forms'));
            }

            if ($item->status === 'spam') {
                $not_spam_url = wp_nonce_url(add_query_arg([
                    'post_type' => Post_Types::CPT_FORM,
                    'page'      => 'nexley-entries',
                    'action'    => 'mark_read',
                    'id'        => $item->id,
                ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

                $actions['spam'] = sprintf('<a href="%s">%s</a>', esc_url($not_spam_url), esc_html__('Not Spam', 'nexley-forms'));
            } else {
                $spam_url = wp_nonce_url(add_query_arg([
                    'post_type' => Post_Types::CPT_FORM,
                    'page'      => 'nexley-entries',
                    'action'    => 'mark_spam',
                    'id'        => $item->id,
                ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

                $actions['spam'] = sprintf('<a href="%s">%s</a>', esc_url($spam_url), esc_html__('Spam', 'nexley-forms'));
            }

            $trash_url = wp_nonce_url(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => 'nexley-entries',
                'action'    => 'trash',
                'id'        => $item->id,
            ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

            $actions['trash'] = sprintf(
                '<a href="%s" class="submitdelete">%s</a>',
                esc_url($trash_url),
                esc_html__('Trash', 'nexley-forms')
            );
        } else {
            $untrash_url = wp_nonce_url(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => 'nexley-entries',
                'action'    => 'untrash',
                'id'        => $item->id,
            ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

            $actions['untrash'] = sprintf('<a href="%s">%s</a>', esc_url($untrash_url), esc_html__('Restore', 'nexley-forms'));

            $delete_url = wp_nonce_url(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => 'nexley-entries',
                'action'    => 'delete',
                'id'        => $item->id,
            ], admin_url('edit.php')), 'nexley_entry_action_' . $item->id);

            $actions['delete'] = sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_url),
                esc_js(__('Are you sure you want to permanently delete this entry?', 'nexley-forms')),
                esc_html__('Delete Permanently', 'nexley-forms')
            );
        }

        $id_label = sprintf('<strong><a href="%s">#%d</a></strong>', esc_url($view_url), $item->id);
        return $id_label . $this->row_actions($actions);
    }

    /**
     * Form column.
     *
     * @param object $item
     * @return string
     */
    protected function column_form($item): string {
        $form_post = get_post($item->form_id);
        if ($form_post) {
            $filter_url = add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => 'nexley-entries',
                'form_id'   => $item->form_id,
            ], admin_url('edit.php'));

            return sprintf(
                '<a href="%s"><strong>%s</strong></a>',
                esc_url($filter_url),
                esc_html($form_post->post_title ?: "#{$item->form_id}")
            );
        }

        return sprintf('<span class="description">#%d</span>', $item->form_id);
    }

    /**
     * Data preview column.
     *
     * @param object $item
     * @return string
     */
    protected function column_data_preview($item): string {
        if (!is_array($item->data) || empty($item->data)) {
            return '<span class="description">&mdash;</span>';
        }

        $lines = [];
        $count = 0;

        foreach ($item->data as $k => $v) {
            if ($count >= 3) {
                break;
            }
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            $val_str = (string) $v;
            if (strlen($val_str) > 40) {
                $val_str = substr($val_str, 0, 37) . '...';
            }

            $label = ucwords(str_replace(['_', '-'], ' ', (string) $k));
            $lines[] = '<strong>' . esc_html($label) . ':</strong> ' . esc_html($val_str);
            $count++;
        }

        return implode('<br>', $lines);
    }

    /**
     * Status column.
     *
     * @param object $item
     * @return string
     */
    protected function column_status($item): string {
        $status = sanitize_key($item->status);
        $badge_class = 'nexley-status-pill nexley-status--' . $status;
        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr($badge_class),
            esc_html(ucfirst($status))
        );
    }

    /**
     * IP Address column.
     *
     * @param object $item
     * @return string
     */
    protected function column_ip_address($item): string {
        return !empty($item->ip_address) ? '<code>' . esc_html($item->ip_address) . '</code>' : '&mdash;';
    }

    /**
     * Submission date column.
     *
     * @param object $item
     * @return string
     */
    protected function column_created_at($item): string {
        if (empty($item->created_at) || $item->created_at === '0000-00-00 00:00:00') {
            return '&mdash;';
        }

        $time = strtotime($item->created_at);
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $time);
        $time_diff = human_time_diff($time, current_time('timestamp'));

        return sprintf(
            '<span title="%s">%s ago<br><small class="description">%s</small></span>',
            esc_attr($item->created_at),
            esc_html($time_diff),
            esc_html($date)
        );
    }

    /**
     * Prepare query items and pagination.
     */
    public function prepare_items(): void {
        $per_page     = 20;
        $current_page = $this->get_pagenum();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search    = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_to   = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby   = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'id';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order     = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'DESC';

        $query_args = [
            'per_page'  => $per_page,
            'offset'    => ($current_page - 1) * $per_page,
            'orderby'   => $orderby,
            'order'     => $order,
        ];

        if ($this->current_form_id > 0) {
            $query_args['form_id'] = $this->current_form_id;
        }

        if ($this->current_status !== 'all') {
            $query_args['status'] = $this->current_status;
        }

        if (!empty($search)) {
            $query_args['search'] = $search;
        }

        if (!empty($date_from)) {
            $query_args['date_from'] = $date_from;
        }

        if (!empty($date_to)) {
            $query_args['date_to'] = $date_to;
        }

        $result = DB::get_entries($query_args);

        $this->items = $result['entries'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($result['total'] / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'id',
        ];
    }
}
