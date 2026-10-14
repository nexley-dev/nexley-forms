<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the Submissions dashboard page, bulk actions, and single submission view.
 */
final class Entries_Page {

    public const PAGE_SLUG = 'nexley-entries';

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'handle_actions']);
    }

    /**
     * Register Submenu Page under Nexley Forms.
     */
    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . Post_Types::CPT_FORM,
            __('Form Entries', 'nexley-forms'),
            __('Entries', 'nexley-forms'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Process actions (single row actions, bulk actions, and CSV export) prior to rendering.
     */
    public static function handle_actions(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key($_GET['action'] ?? $_POST['action'] ?? '');
        if (empty($action) && isset($_POST['action2'])) {
            $action = sanitize_key($_POST['action2']);
        }

        // 1. CSV Export Handler
        if ($action === 'export_csv') {
            check_admin_referer(CSV_Exporter::NONCE_ACTION, CSV_Exporter::NONCE_NAME);

            $query_args = [];
            if (!empty($_GET['form_id'])) {
                $query_args['form_id'] = absint($_GET['form_id']);
            }
            if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
                $query_args['status'] = sanitize_key($_GET['status']);
            }
            if (!empty($_GET['date_from'])) {
                $query_args['date_from'] = sanitize_text_field($_GET['date_from']);
            }
            if (!empty($_GET['date_to'])) {
                $query_args['date_to'] = sanitize_text_field($_GET['date_to']);
            }

            CSV_Exporter::export_download($query_args);
            exit;
        }

        // 2. Single Entry Status / Trash / Delete
        if (isset($_GET['id']) && in_array($action, ['mark_read', 'mark_unread', 'mark_spam', 'trash', 'untrash', 'delete'], true)) {
            $entry_id = absint($_GET['id']);
            check_admin_referer('nexley_entry_action_' . $entry_id);

            switch ($action) {
                case 'mark_read':
                    DB::update_entry_status($entry_id, 'read');
                    break;
                case 'mark_unread':
                    DB::update_entry_status($entry_id, 'unread');
                    break;
                case 'mark_spam':
                    DB::update_entry_status($entry_id, 'spam');
                    break;
                case 'trash':
                    DB::update_entry_status($entry_id, 'trash');
                    break;
                case 'untrash':
                    DB::update_entry_status($entry_id, 'read');
                    break;
                case 'delete':
                    DB::delete_entry($entry_id);
                    break;
            }

            $redirect_url = remove_query_arg(['action', 'id', '_wpnonce']);
            wp_safe_redirect(add_query_arg('message', $action, $redirect_url));
            exit;
        }

        // 3. Detail View Status Update
        if ($action === 'update_detail_status' && isset($_POST['entry_id'], $_POST['status'])) {
            $entry_id = absint($_POST['entry_id']);
            check_admin_referer('nexley_update_detail_status_' . $entry_id);

            $new_status = sanitize_key($_POST['status']);
            DB::update_entry_status($entry_id, $new_status);

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'action'    => 'view',
                'id'        => $entry_id,
                'updated'   => '1',
            ], admin_url('edit.php')));
            exit;
        }

        // 4. Resend Notification Action
        if ($action === 'resend_notification' && isset($_POST['entry_id'])) {
            $entry_id = absint($_POST['entry_id']);
            check_admin_referer('nexley_resend_notification_' . $entry_id);

            $entry = DB::get_entry($entry_id);
            if ($entry) {
                $custom_to = !empty($_POST['resend_to']) ? sanitize_email($_POST['resend_to']) : '';
                $schema    = Form_Schema::get_form_schema($entry->form_id);

                Mailer::process_submission($entry->form_id, $entry->data, $schema, $custom_to);

                wp_safe_redirect(add_query_arg([
                    'post_type' => Post_Types::CPT_FORM,
                    'page'      => self::PAGE_SLUG,
                    'action'    => 'view',
                    'id'        => $entry_id,
                    'resent'    => '1',
                ], admin_url('edit.php')));
                exit;
            }
        }

        // 5. Bulk Actions
        if (!empty($_POST['entry_id']) && is_array($_POST['entry_id']) && in_array($action, ['mark_read', 'mark_unread', 'mark_spam', 'trash', 'untrash', 'delete'], true)) {
            check_admin_referer('bulk-entries');

            $entry_ids = array_map('absint', $_POST['entry_id']);

            switch ($action) {
                case 'mark_read':
                    DB::update_entries_status($entry_ids, 'read');
                    break;
                case 'mark_unread':
                    DB::update_entries_status($entry_ids, 'unread');
                    break;
                case 'mark_spam':
                    DB::update_entries_status($entry_ids, 'spam');
                    break;
                case 'trash':
                    DB::update_entries_status($entry_ids, 'trash');
                    break;
                case 'untrash':
                    DB::update_entries_status($entry_ids, 'read');
                    break;
                case 'delete':
                    DB::delete_entries($entry_ids);
                    break;
            }

            $redirect_url = remove_query_arg(['action', 'action2', 'entry_id', '_wpnonce']);
            wp_safe_redirect(add_query_arg('message', 'bulk_' . $action, $redirect_url));
            exit;
        }
    }

    /**
     * Render the Entries view (either table listing or single entry detail).
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'nexley-forms'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key($_GET['action'] ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $id     = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if ($action === 'view' && $id > 0) {
            self::render_detail($id);
            return;
        }

        self::render_list();
    }

    /**
     * Render the List Table view.
     */
    private static function render_list(): void {
        $table = new Entries_Table();
        $table->prepare_items();

        // Notices
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $message = sanitize_key($_GET['message'] ?? '');
        ?>
        <div class="wrap nexley-entries-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Form Submissions', 'nexley-forms'); ?></h1>
            <hr class="wp-header-end">

            <?php if (!empty($message)) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        switch ($message) {
                            case 'mark_read':
                            case 'bulk_mark_read':
                                esc_html_e('Selected entries marked as read.', 'nexley-forms');
                                break;
                            case 'mark_unread':
                            case 'bulk_mark_unread':
                                esc_html_e('Selected entries marked as unread.', 'nexley-forms');
                                break;
                            case 'mark_spam':
                            case 'bulk_mark_spam':
                                esc_html_e('Selected entries marked as spam.', 'nexley-forms');
                                break;
                            case 'trash':
                            case 'bulk_trash':
                                esc_html_e('Selected entries moved to Trash.', 'nexley-forms');
                                break;
                            case 'untrash':
                            case 'bulk_untrash':
                                esc_html_e('Selected entries restored from Trash.', 'nexley-forms');
                                break;
                            case 'delete':
                            case 'bulk_delete':
                                esc_html_e('Selected entries permanently deleted.', 'nexley-forms');
                                break;
                            default:
                                esc_html_e('Action completed successfully.', 'nexley-forms');
                                break;
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $table->views(); ?>

            <form id="nexley-entries-form" method="get">
                <input type="hidden" name="post_type" value="<?php echo esc_attr(Post_Types::CPT_FORM); ?>" />
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <?php if (isset($_GET['status'])) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr(sanitize_key($_GET['status'])); ?>" />
                <?php endif; ?>

                <?php
                $table->search_box(__('Search Submissions', 'nexley-forms'), 'entry_search');
                ?>
            </form>

            <form id="nexley-entries-bulk-form" method="post">
                <?php
                wp_nonce_field('bulk-entries');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the Single Submission Detail view.
     *
     * @param int $entry_id
     */
    private static function render_detail(int $entry_id): void {
        $entry = DB::get_entry($entry_id);
        if (!$entry) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Entry Not Found', 'nexley-forms'); ?></h1>
                <p><?php esc_html_e('The requested submission does not exist or has been deleted.', 'nexley-forms'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" class="button button-primary"><?php esc_html_e('&larr; Back to Entries', 'nexley-forms'); ?></a></p>
            </div>
            <?php
            return;
        }

        // Auto mark as read if currently unread
        if ($entry->status === 'unread') {
            DB::update_entry_status($entry_id, 'read');
            $entry->status = 'read';
        }

        $form_post = get_post($entry->form_id);
        $form_title = $form_post ? $form_post->post_title : "#{$entry->form_id}";
        $schema = Form_Schema::get_form_schema($entry->form_id);

        // Map field names to labels
        $field_labels = [];
        if (!empty($schema['fields'])) {
            foreach ($schema['fields'] as $f) {
                if (!empty($f['name'])) {
                    $field_labels[$f['name']] = $f['label'] ?? $f['name'];
                }
            }
        }

        $back_url = admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG);
        if ($entry->form_id > 0) {
            $back_url = add_query_arg('form_id', $entry->form_id, $back_url);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_updated = isset($_GET['updated']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_resent  = isset($_GET['resent']);
        ?>
        <div class="wrap nexley-entry-detail-wrap">
            <h1 class="wp-heading-inline">
                <?php
                /* translators: %d: Entry ID number */
                printf(esc_html__('Submission #%d', 'nexley-forms'), $entry->id);
                ?>
            </h1>
            <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">&larr; <?php esc_html_e('Back to Entries', 'nexley-forms'); ?></a>
            <hr class="wp-header-end">

            <?php if ($is_updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Entry status updated successfully.', 'nexley-forms'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($is_resent) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Notification email(s) successfully resent for this submission.', 'nexley-forms'); ?></p>
                </div>
            <?php endif; ?>

            <div class="nexley-detail-grid">
                <!-- Main Column: Submitted Form Values -->
                <div class="nexley-detail-main">
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e('Submitted Information', 'nexley-forms'); ?></h2>
                        </div>
                        <div class="inside" style="padding: 0;">
                            <table class="widefat striped nexley-detail-table" style="border: 0; box-shadow: none;">
                                <thead>
                                    <tr>
                                        <th style="width: 30%; font-weight: 600;"><?php esc_html_e('Field', 'nexley-forms'); ?></th>
                                        <th style="font-weight: 600;"><?php esc_html_e('Value', 'nexley-forms'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($entry->data) && is_array($entry->data)) : ?>
                                        <?php foreach ($entry->data as $name => $val) : ?>
                                            <?php
                                            $label = $field_labels[$name] ?? ucwords(str_replace(['_', '-'], ' ', (string) $name));
                                            ?>
                                            <tr>
                                                <td class="nexley-detail-label">
                                                    <strong><?php echo esc_html($label); ?></strong>
                                                    <br><small class="description"><code><?php echo esc_html((string) $name); ?></code></small>
                                                </td>
                                                <td class="nexley-detail-value">
                                                    <?php
                                                    if (is_array($val) && isset($val['file_name'])) {
                                                        $download_url = add_query_arg([
                                                            'action'   => 'nexley_download_upload',
                                                            'entry_id' => $entry->id,
                                                            'field'    => $name,
                                                        ], admin_url('admin-post.php'));
                                                        $file_display = !empty($val['original_name']) ? $val['original_name'] : $val['file_name'];
                                                        echo '<a href="' . esc_url($download_url) . '" class="button button-secondary">' . Icons::get('download', 14) . ' ' . esc_html($file_display) . '</a>';
                                                    } elseif (is_array($val)) {
                                                        echo '<ul style="margin: 0; padding-left: 18px;">';
                                                        foreach ($val as $sub_val) {
                                                            echo '<li>' . esc_html((string) $sub_val) . '</li>';
                                                        }
                                                        echo '</ul>';
                                                    } elseif (filter_var($val, FILTER_VALIDATE_EMAIL)) {
                                                        echo '<a href="' . esc_url('mailto:' . (string) $val) . '">' . esc_html((string) $val) . '</a>';
                                                    } elseif (filter_var($val, FILTER_VALIDATE_URL)) {
                                                        echo '<a href="' . esc_url((string) $val) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) $val) . ' ' . Icons::get('external', 13) . '</a>';
                                                    } else {
                                                        echo nl2br(esc_html((string) $val));
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <tr>
                                            <td colspan="2"><?php esc_html_e('No field values recorded.', 'nexley-forms'); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sidebar Column: Metadata & Status Controls -->
                <div class="nexley-detail-sidebar">
                    <!-- Status Card -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e('Status & Actions', 'nexley-forms'); ?></h2>
                        </div>
                        <div class="inside">
                            <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
                                <?php wp_nonce_field('nexley_update_detail_status_' . $entry->id); ?>
                                <input type="hidden" name="action" value="update_detail_status" />
                                <input type="hidden" name="entry_id" value="<?php echo esc_attr((string) $entry->id); ?>" />

                                <p>
                                    <label for="entry-status-select"><strong><?php esc_html_e('Status:', 'nexley-forms'); ?></strong></label><br>
                                    <select name="status" id="entry-status-select" style="width: 100%; margin-top: 6px;">
                                        <option value="unread" <?php selected($entry->status, 'unread'); ?>><?php esc_html_e('Unread', 'nexley-forms'); ?></option>
                                        <option value="read" <?php selected($entry->status, 'read'); ?>><?php esc_html_e('Read', 'nexley-forms'); ?></option>
                                        <option value="spam" <?php selected($entry->status, 'spam'); ?>><?php esc_html_e('Spam', 'nexley-forms'); ?></option>
                                        <option value="trash" <?php selected($entry->status, 'trash'); ?>><?php esc_html_e('Trash', 'nexley-forms'); ?></option>
                                    </select>
                                </p>

                                <p style="margin-top: 15px;">
                                    <?php submit_button(__('Update Status', 'nexley-forms'), 'primary', 'submit', false, ['style' => 'width: 100%;']); ?>
                                </p>
                            </form>

                            <hr style="margin: 16px 0;">

                            <div class="nexley-entry-danger-zone">
                                <?php
                                $trash_url = wp_nonce_url(add_query_arg([
                                    'post_type' => Post_Types::CPT_FORM,
                                    'page'      => self::PAGE_SLUG,
                                    'action'    => 'trash',
                                    'id'        => $entry->id,
                                ], admin_url('edit.php')), 'nexley_entry_action_' . $entry->id);

                                $delete_url = wp_nonce_url(add_query_arg([
                                    'post_type' => Post_Types::CPT_FORM,
                                    'page'      => self::PAGE_SLUG,
                                    'action'    => 'delete',
                                    'id'        => $entry->id,
                                ], admin_url('edit.php')), 'nexley_entry_action_' . $entry->id);
                                ?>
                                <?php if ($entry->status !== 'trash') : ?>
                                    <a href="<?php echo esc_url($trash_url); ?>" class="button button-link-delete" style="color: #b32d2e;">
                                        <?php esc_html_e('Move to Trash', 'nexley-forms'); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-link-delete" style="color: #b32d2e;" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to permanently delete this entry?', 'nexley-forms')); ?>');">
                                        <?php esc_html_e('Delete Permanently', 'nexley-forms'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Resend Notifications Card -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e('Resend Notifications', 'nexley-forms'); ?></h2>
                        </div>
                        <div class="inside">
                            <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
                                <?php wp_nonce_field('nexley_resend_notification_' . $entry->id); ?>
                                <input type="hidden" name="action" value="resend_notification" />
                                <input type="hidden" name="entry_id" value="<?php echo esc_attr((string) $entry->id); ?>" />

                                <p>
                                    <label for="resend-to-input"><strong><?php esc_html_e('Recipient Override (optional):', 'nexley-forms'); ?></strong></label><br>
                                    <input type="email" name="resend_to" id="resend-to-input" placeholder="Leave empty for configured emails" style="width: 100%; margin-top: 6px;" />
                                </p>

                                <p style="margin-top: 15px;">
                                    <?php submit_button(__('Resend Notification', 'nexley-forms'), 'secondary', 'submit', false, ['style' => 'width: 100%;']); ?>
                                </p>
                            </form>
                        </div>
                    </div>

                    <!-- Meta Details Card -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e('Technical Meta', 'nexley-forms'); ?></h2>
                        </div>
                        <div class="inside">
                            <ul class="nexley-meta-list" style="margin: 0; list-style: none; padding: 0; line-height: 1.8;">
                                <li>
                                    <strong><?php esc_html_e('Form:', 'nexley-forms'); ?></strong>
                                    <?php if ($form_post) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($form_post->ID)); ?>">
                                            <?php echo esc_html($form_title); ?>
                                        </a>
                                    <?php else : ?>
                                        <code>#<?php echo esc_html((string) $entry->form_id); ?></code>
                                    <?php endif; ?>
                                </li>
                                <li>
                                    <strong><?php esc_html_e('Date Submitted:', 'nexley-forms'); ?></strong><br>
                                    <code><?php echo esc_html($entry->created_at); ?></code>
                                </li>
                                <li>
                                    <strong><?php esc_html_e('IP Address:', 'nexley-forms'); ?></strong><br>
                                    <code><?php echo esc_html($entry->ip_address ?: __('Not captured', 'nexley-forms')); ?></code>
                                </li>
                                <?php if (!empty($entry->user_agent)) : ?>
                                    <li>
                                        <strong><?php esc_html_e('User Agent:', 'nexley-forms'); ?></strong><br>
                                        <small style="word-break: break-all; color: #646970;"><?php echo esc_html($entry->user_agent); ?></small>
                                    </li>
                                <?php endif; ?>
                                <?php if (!empty($entry->legacy_post_id)) : ?>
                                    <li>
                                        <strong><?php esc_html_e('Legacy CPT ID:', 'nexley-forms'); ?></strong>
                                        <code>#<?php echo esc_html((string) $entry->legacy_post_id); ?></code>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
