<?php
declare(strict_types=1);

namespace Nexley_Forms;

use Nexley_Forms\Importers\Importer_Interface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI for Third-Party Form Migration Wizard, Native .nex Import/Export, and Shortcode Replacer.
 */
final class Migration_Wizard {

    public const PAGE_SLUG = 'nexley-import-export';

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
            __('Import & Export', 'nexley-forms'),
            __('Import / Export', 'nexley-forms'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Process import, export, and shortcode replacement actions.
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

        // 1. Export Native .nex file download
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action']) && $_GET['action'] === 'export_nex' && !empty($_GET['form_id'])) {
            check_admin_referer('nexley_export_nex_' . absint($_GET['form_id']));

            $form_id = absint($_GET['form_id']);
            $post    = get_post($form_id);
            if (!$post) {
                wp_die(esc_html__('Form not found.', 'nexley-forms'));
            }

            $nex_content = Importer_Exporter::export_form_nex($form_id);
            $slug        = sanitize_title($post->post_title ?: "form-{$form_id}");
            $filename    = "{$slug}.nex";

            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            echo $nex_content;
            exit;
        }

        // 2. Import Native .nex file upload
        if (isset($_POST['action']) && $_POST['action'] === 'import_nex_file') {
            check_admin_referer('nexley_import_nex');

            if (!empty($_FILES['nex_file']['tmp_name'])) {
                $file_content = file_get_contents($_FILES['nex_file']['tmp_name']);
                if ($file_content) {
                    $new_id = Importer_Exporter::import_form_nex($file_content);
                    if ($new_id > 0) {
                        wp_safe_redirect(add_query_arg([
                            'post_type' => Post_Types::CPT_FORM,
                            'page'      => self::PAGE_SLUG,
                            'tab'       => 'native',
                            'imported'  => $new_id,
                        ], admin_url('edit.php')));
                        exit;
                    }
                }
            }

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'tab'       => 'native',
                'error'     => 'import_failed',
            ], admin_url('edit.php')));
            exit;
        }

        // 3. Migrate from Third-Party Importer (Supports Dry Run & Real Migration)
        if (isset($_POST['action']) && $_POST['action'] === 'migrate_third_party') {
            check_admin_referer('nexley_migrate_third_party');

            $importer_id = sanitize_key($_POST['importer_id'] ?? '');
            $source_id   = sanitize_text_field($_POST['source_id'] ?? '');
            $is_dry_run  = !empty($_POST['is_dry_run']);

            $importer = Importer_Exporter::get_importer($importer_id);

            // Handle file upload if provided
            if (!empty($_FILES['import_file']['tmp_name'])) {
                $source = file_get_contents($_FILES['import_file']['tmp_name']);
            } else {
                $source = $source_id;
            }

            if ($importer && !empty($source)) {
                $schema = $importer->convert($source);
                if (!empty($schema)) {
                    $fields_count = count($schema['fields'] ?? []);
                    $spans_count  = 0;
                    foreach (($schema['fields'] ?? []) as $f) {
                        if (($f['span'] ?? 12) < 12) {
                            $spans_count++;
                        }
                    }
                    $notifs_count = count($schema['notifications'] ?? []);
                    $report = [
                        'importer_id'   => $importer_id,
                        'importer_name' => $importer->get_title(),
                        'form_title'    => $schema['title'] ?? 'Imported Form',
                        'fields_count'  => $fields_count,
                        'spans_count'   => $spans_count,
                        'notifs_count'  => $notifs_count,
                        'status'        => 'Converted',
                        'is_dry_run'    => $is_dry_run,
                        'source_id'     => is_string($source) && strlen($source) < 100 ? $source : '',
                    ];

                    if ($is_dry_run) {
                        set_transient('nexley_migration_report_' . get_current_user_id(), $report, 300);
                        wp_safe_redirect(add_query_arg([
                            'post_type' => Post_Types::CPT_FORM,
                            'page'      => self::PAGE_SLUG,
                            'tab'       => 'importers',
                            'dry_run'   => '1',
                        ], admin_url('edit.php')));
                        exit;
                    }

                    $new_id = Importer_Exporter::import_from_importer($importer_id, $source);
                    if ($new_id > 0) {
                        $report['form_id'] = $new_id;
                        set_transient('nexley_migration_report_' . get_current_user_id(), $report, 300);
                        wp_safe_redirect(add_query_arg([
                            'post_type' => Post_Types::CPT_FORM,
                            'page'      => self::PAGE_SLUG,
                            'tab'       => 'importers',
                            'migrated'  => $new_id,
                        ], admin_url('edit.php')));
                        exit;
                    }
                }
            }

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'tab'       => 'importers',
                'error'     => 'migration_failed',
            ], admin_url('edit.php')));
            exit;
        }

        // 4. Shortcode Replacement Trigger
        if (isset($_POST['action']) && $_POST['action'] === 'replace_shortcode') {
            check_admin_referer('nexley_replace_shortcode');

            $post_id       = absint($_POST['target_post_id'] ?? 0);
            $old_shortcode = sanitize_text_field(wp_unslash($_POST['old_shortcode'] ?? ''));
            $new_form_id   = absint($_POST['new_form_id'] ?? 0);

            if ($post_id > 0 && !empty($old_shortcode) && $new_form_id > 0) {
                $success = Importer_Exporter::replace_shortcode($post_id, $old_shortcode, $new_form_id);
                if ($success) {
                    wp_safe_redirect(add_query_arg([
                        'post_type' => Post_Types::CPT_FORM,
                        'page'      => self::PAGE_SLUG,
                        'tab'       => 'shortcodes',
                        'replaced'  => '1',
                    ], admin_url('edit.php')));
                    exit;
                }
            }

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'tab'       => 'shortcodes',
                'error'     => 'replace_failed',
            ], admin_url('edit.php')));
            exit;
        }

        // 5. Shortcode Rollback
        if (isset($_GET['action']) && $_GET['action'] === 'rollback_shortcode' && !empty($_GET['post_id'])) {
            $post_id = absint($_GET['post_id']);
            check_admin_referer('nexley_rollback_' . $post_id);

            Importer_Exporter::rollback_shortcode_replacement($post_id);

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'tab'       => 'shortcodes',
                'rolledback'=> '1',
            ], admin_url('edit.php')));
            exit;
        }
    }

    /**
     * Render the Import / Export Dashboard.
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'nexley-forms'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = sanitize_key($_GET['tab'] ?? 'importers');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $migrated_id = isset($_GET['migrated']) ? absint($_GET['migrated']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $imported_id = isset($_GET['imported']) ? absint($_GET['imported']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_replaced = isset($_GET['replaced']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_rolledback = isset($_GET['rolledback']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error = sanitize_key($_GET['error'] ?? '');

        $tab_url = admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap nexley-import-export-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Form Import & Migration Wizard', 'nexley-forms'); ?></h1>
            <hr class="wp-header-end">

            <?php if ($migrated_id > 0) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: New Form ID, 2: Edit URL */
                            wp_kses_post(__('Form successfully migrated as <strong>Nexley Form #%1$d</strong>! <a href="%2$s">Edit in Builder &rarr;</a>', 'nexley-forms')),
                            $migrated_id,
                            esc_url(get_edit_post_link($migrated_id) ?: '')
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ($imported_id > 0) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: New Form ID, 2: Edit URL */
                            wp_kses_post(__('Native .nex form imported successfully as <strong>Nexley Form #%1$d</strong>! <a href="%2$s">Edit in Builder &rarr;</a>', 'nexley-forms')),
                            $imported_id,
                            esc_url(get_edit_post_link($imported_id) ?: '')
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ($is_replaced) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Shortcode successfully replaced in content! A backup was preserved for instant rollback.', 'nexley-forms'); ?></p>
                </div>
            <?php elseif ($is_rolledback) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Shortcode replacement rolled back to previous content.', 'nexley-forms'); ?></p>
                </div>
            <?php elseif (!empty($error)) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Operation failed. Please verify the source data format and try again.', 'nexley-forms'); ?></p>
                </div>
            <?php endif; ?>

            <?php
            $report = get_transient('nexley_migration_report_' . get_current_user_id());
            if ($report && is_array($report)) :
                delete_transient('nexley_migration_report_' . get_current_user_id());
            ?>
                <div class="notice notice-info" style="border-left-color: #2563eb; padding: 16px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-analytics" style="color: #2563eb;"></span>
                        <?php echo !empty($report['is_dry_run']) ? esc_html__('Dry-Run Conversion Report (Preview Only)', 'nexley-forms') : esc_html__('Form Conversion Report', 'nexley-forms'); ?>
                        <span class="nexley-status-pill nexley-status--read" style="margin-left: auto;"><?php echo esc_html($report['status']); ?></span>
                    </h3>
                    <p style="margin-bottom: 12px; color: #475569; font-size: 13px;">
                        <?php echo !empty($report['is_dry_run'])
                            ? esc_html__('Dry-run preview simulation completed. Source forms were NOT touched or altered. Inspect the conversion summary below:', 'nexley-forms')
                            : esc_html__('Form was successfully imported and converted into a native 12-column CSS Grid schema.', 'nexley-forms'); ?>
                    </p>
                    <table class="widefat striped" style="max-width: 650px; margin-bottom: 14px;">
                        <tbody>
                            <tr>
                                <th style="width: 220px;"><strong><?php esc_html_e('Source System', 'nexley-forms'); ?></strong></th>
                                <td><?php echo esc_html($report['importer_name']); ?></td>
                            </tr>
                            <tr>
                                <th><strong><?php esc_html_e('Form Title', 'nexley-forms'); ?></strong></th>
                                <td><strong><?php echo esc_html($report['form_title']); ?></strong></td>
                            </tr>
                            <tr>
                                <th><strong><?php esc_html_e('Fields Converted', 'nexley-forms'); ?></strong></th>
                                <td><?php echo esc_html((string) $report['fields_count']); ?> <?php esc_html_e('fields normalized to Nexley schema', 'nexley-forms'); ?></td>
                            </tr>
                            <tr>
                                <th><strong><?php esc_html_e('Multi-Column Grid Spans', 'nexley-forms'); ?></strong></th>
                                <td><?php echo esc_html((string) $report['spans_count']); ?> <?php esc_html_e('columns mapped to 12-column --span', 'nexley-forms'); ?></td>
                            </tr>
                            <tr>
                                <th><strong><?php esc_html_e('Notifications / Emails', 'nexley-forms'); ?></strong></th>
                                <td><?php echo esc_html((string) $report['notifs_count']); ?> <?php esc_html_e('email routing rules converted', 'nexley-forms'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php if (!empty($report['is_dry_run']) && !empty($report['source_id'])) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" style="display: inline-block;">
                            <?php wp_nonce_field('nexley_migrate_third_party'); ?>
                            <input type="hidden" name="action" value="migrate_third_party" />
                            <input type="hidden" name="importer_id" value="<?php echo esc_attr($report['importer_id']); ?>" />
                            <input type="hidden" name="source_id" value="<?php echo esc_attr($report['source_id']); ?>" />
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Confirm & Migrate Form Now &rarr;', 'nexley-forms'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 24px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'importers', $tab_url)); ?>" class="nav-tab <?php echo $current_tab === 'importers' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-migrate" style="margin-top: 3px;"></span> <?php esc_html_e('Third-Party Importers', 'nexley-forms'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'native', $tab_url)); ?>" class="nav-tab <?php echo $current_tab === 'native' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-database-export" style="margin-top: 3px;"></span> <?php esc_html_e('Native .nex Transfer', 'nexley-forms'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'shortcodes', $tab_url)); ?>" class="nav-tab <?php echo $current_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-links" style="margin-top: 3px;"></span> <?php esc_html_e('Shortcode Replacer', 'nexley-forms'); ?>
                </a>
            </nav>

            <!-- TAB 1: THIRD-PARTY IMPORTERS -->
            <?php if ($current_tab === 'importers') : ?>
                <?php self::render_importers_tab(); ?>
            <?php elseif ($current_tab === 'native') : ?>
                <?php self::render_native_tab(); ?>
            <?php elseif ($current_tab === 'shortcodes') : ?>
                <?php self::render_shortcodes_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Third-Party Importers Tab.
     */
    private static function render_importers_tab(): void {
        $importers = Importer_Exporter::get_importers();
        ?>
        <div class="nexley-importers-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 24px;">
            <?php foreach ($importers as $id => $importer) : ?>
                <?php
                $is_available = $importer->is_available();
                $forms        = $is_available ? $importer->get_available_forms() : [];
                $status_label = $importer->get_verification_status();
                $is_unverified = (strpos(strtolower($status_label), 'unverified') !== false);
                ?>
                <div class="postbox nexley-importer-card" style="margin: 0; padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <h2 style="margin: 0; font-size: 18px; font-weight: 700;"><?php echo esc_html($importer->get_title()); ?></h2>
                        <span class="nexley-status-pill <?php echo $is_unverified ? 'nexley-status--spam' : 'nexley-status--read'; ?>" title="<?php esc_attr_e('Compatibility & verification status', 'nexley-forms'); ?>">
                            <?php echo esc_html($status_label); ?>
                        </span>
                    </div>

                    <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin-bottom: 16px;">
                        <?php echo esc_html($importer->get_description()); ?>
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 16px;">
                        <?php if ($is_available && !empty($forms)) : ?>
                            <p style="margin: 0 0 10px 0; font-weight: 600; color: #0f172a;">
                                <span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
                                <?php
                                printf(
                                    /* translators: %d: Form count */
                                    esc_html(_n('%d form detected in database', '%d forms detected in database', count($forms), 'nexley-forms')),
                                    count($forms)
                                );
                                ?>
                            </p>

                            <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
                                <?php wp_nonce_field('nexley_migrate_third_party'); ?>
                                <input type="hidden" name="action" value="migrate_third_party" />
                                <input type="hidden" name="importer_id" value="<?php echo esc_attr($id); ?>" />

                                <label for="source_id_<?php echo esc_attr($id); ?>" class="screen-reader-text"><?php esc_html_e('Select form to migrate', 'nexley-forms'); ?></label>
                                <select name="source_id" id="source_id_<?php echo esc_attr($id); ?>" style="width: 100%; margin-bottom: 10px;">
                                    <?php foreach ($forms as $f_id => $f_title) : ?>
                                        <option value="<?php echo esc_attr((string) $f_id); ?>">
                                            <?php echo esc_html($f_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div style="display: flex; gap: 8px;">
                                    <button type="submit" name="is_dry_run" value="1" class="button button-secondary" style="flex: 1;">
                                        <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> <?php esc_html_e('Dry Run', 'nexley-forms'); ?>
                                    </button>
                                    <button type="submit" class="button button-primary" style="flex: 1;">
                                        <?php esc_html_e('Migrate &rarr;', 'nexley-forms'); ?>
                                    </button>
                                </div>
                            </form>
                        <?php else : ?>
                            <p style="margin: 0; color: #64748b; font-size: 12.5px;">
                                <span class="dashicons dashicons-info" style="color: #94a3b8;"></span>
                                <?php esc_html_e('No active forms detected in database. You can still import from an export file below:', 'nexley-forms'); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- File Upload Alternative -->
                    <details style="font-size: 12.5px;">
                        <summary style="cursor: pointer; color: #2563eb; font-weight: 600;">
                            <?php esc_html_e('Or import from export file (.json / .nff)', 'nexley-forms'); ?>
                        </summary>
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" style="margin-top: 12px;">
                            <?php wp_nonce_field('nexley_migrate_third_party'); ?>
                            <input type="hidden" name="action" value="migrate_third_party" />
                            <input type="hidden" name="importer_id" value="<?php echo esc_attr($id); ?>" />

                            <input type="file" name="import_file" accept=".json,.nff,.txt" required style="margin-bottom: 8px; width: 100%;" />
                            <div style="display: flex; gap: 8px;">
                                <button type="submit" name="is_dry_run" value="1" class="button button-secondary" style="flex: 1;">
                                    <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> <?php esc_html_e('Dry Run', 'nexley-forms'); ?>
                                </button>
                                <button type="submit" class="button button-primary" style="flex: 1;">
                                    <?php esc_html_e('Convert &rarr;', 'nexley-forms'); ?>
                                </button>
                            </div>
                        </form>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render Native .nex Envelope Tab.
     */
    private static function render_native_tab(): void {
        $forms = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; max-width: 1000px;">
            <!-- Export Card -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin: 0 0 10px 0; font-size: 16px; font-weight: 700;"><?php esc_html_e('Export to .nex Envelope', 'nexley-forms'); ?></h2>
                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin-bottom: 16px;">
                    <?php esc_html_e('Export a Nexley form schema, styling configuration, and email routing into a portable, versioned .nex envelope.', 'nexley-forms'); ?>
                </p>

                <?php if (!empty($forms)) : ?>
                    <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr(Post_Types::CPT_FORM); ?>" />
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                        <input type="hidden" name="action" value="export_nex" />

                        <p>
                            <label for="export_form_select"><strong><?php esc_html_e('Select Form to Export:', 'nexley-forms'); ?></strong></label><br>
                            <select name="form_id" id="export_form_select" style="width: 100%; margin-top: 6px;">
                                <?php foreach ($forms as $f) : ?>
                                    <option value="<?php echo esc_attr((string) $f->ID); ?>">
                                        <?php echo esc_html($f->post_title ?: "#{$f->ID}"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <?php
                        $first_id = $forms[0]->ID;
                        wp_nonce_field('nexley_export_nex_' . $first_id);
                        ?>

                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            <?php esc_html_e('Download .nex File', 'nexley-forms'); ?>
                        </button>
                    </form>
                <?php else : ?>
                    <p style="color: #64748b;"><?php esc_html_e('No Nexley forms found to export. Create a form first.', 'nexley-forms'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Import Card -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin: 0 0 10px 0; font-size: 16px; font-weight: 700;"><?php esc_html_e('Import .nex Envelope', 'nexley-forms'); ?></h2>
                <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin-bottom: 16px;">
                    <?php esc_html_e('Restore or migrate a previously exported .nex form envelope onto this WordPress site.', 'nexley-forms'); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
                    <?php wp_nonce_field('nexley_import_nex'); ?>
                    <input type="hidden" name="action" value="import_nex_file" />

                    <p>
                        <label for="nex_file_input"><strong><?php esc_html_e('Choose .nex File:', 'nexley-forms'); ?></strong></label><br>
                        <input type="file" name="nex_file" id="nex_file_input" accept=".nex,.json" required style="margin-top: 6px; width: 100%;" />
                    </p>

                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-upload" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Upload & Import Form', 'nexley-forms'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Shortcode Replacer Tab.
     */
    private static function render_shortcodes_tab(): void {
        $shortcodes = Importer_Exporter::find_shortcodes();
        $nex_forms  = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <div class="postbox" style="padding: 20px;">
            <h2 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700;"><?php esc_html_e('Shortcode Migration & Replacement Scanner', 'nexley-forms'); ?></h2>
            <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin-bottom: 20px;">
                <?php esc_html_e('Scans your published posts and pages for legacy shortcodes ([ninja_form], [gravityform], [contact-form-7]) and safely swaps them for Nexley shortcodes with full rollback support.', 'nexley-forms'); ?>
            </p>

            <?php if (!empty($shortcodes)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Post / Page', 'nexley-forms'); ?></th>
                            <th><?php esc_html_e('Detected Shortcode', 'nexley-forms'); ?></th>
                            <th><?php esc_html_e('Source System', 'nexley-forms'); ?></th>
                            <th><?php esc_html_e('Replace With Nexley Form', 'nexley-forms'); ?></th>
                            <th><?php esc_html_e('Actions', 'nexley-forms'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shortcodes as $item) : ?>
                            <?php
                            $backups = get_post_meta($item['post_id'], '_nexley_shortcode_replacement_backup', true);
                            $has_backup = is_array($backups) && !empty($backups);
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url(get_edit_post_link($item['post_id']) ?: ''); ?>"><?php echo esc_html($item['post_title']); ?></a></strong>
                                    <br><small class="description"><?php echo esc_html(ucfirst($item['post_type'])); ?> (ID: <?php echo esc_html((string) $item['post_id']); ?>)</small>
                                </td>
                                <td>
                                    <code style="background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html($item['shortcode']); ?></code>
                                </td>
                                <td>
                                    <span class="nexley-status-pill nexley-status--read"><?php echo esc_html($item['system']); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($nex_forms)) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" style="display: flex; gap: 8px; align-items: center;">
                                            <?php wp_nonce_field('nexley_replace_shortcode'); ?>
                                            <input type="hidden" name="action" value="replace_shortcode" />
                                            <input type="hidden" name="target_post_id" value="<?php echo esc_attr((string) $item['post_id']); ?>" />
                                            <input type="hidden" name="old_shortcode" value="<?php echo esc_attr($item['shortcode']); ?>" />

                                            <select name="new_form_id" style="max-width: 200px;">
                                                <?php foreach ($nex_forms as $nf) : ?>
                                                    <option value="<?php echo esc_attr((string) $nf->ID); ?>">
                                                        <?php echo esc_html($nf->post_title ?: "#{$nf->ID}"); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" class="button button-small button-primary">
                                                <?php esc_html_e('Replace', 'nexley-forms'); ?>
                                            </button>
                                        </form>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e('No Nexley forms available.', 'nexley-forms'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($has_backup) : ?>
                                        <?php
                                        $rollback_url = wp_nonce_url(add_query_arg([
                                            'post_type' => Post_Types::CPT_FORM,
                                            'page'      => self::PAGE_SLUG,
                                            'tab'       => 'shortcodes',
                                            'action'    => 'rollback_shortcode',
                                            'post_id'   => $item['post_id'],
                                        ], admin_url('edit.php')), 'nexley_rollback_' . $item['post_id']);
                                        ?>
                                        <a href="<?php echo esc_url($rollback_url); ?>" class="button button-small">
                                            <?php esc_html_e('Rollback', 'nexley-forms'); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="description">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 24px; text-align: center;">
                    <span class="dashicons dashicons-yes-alt" style="font-size: 32px; color: #10b981; height: 32px; width: 32px;"></span>
                    <h3 style="margin: 8px 0 4px 0;"><?php esc_html_e('Zero Legacy Shortcodes Found', 'nexley-forms'); ?></h3>
                    <p style="margin: 0; color: #64748b; font-size: 13px;">
                        <?php esc_html_e('No embedded Ninja Forms, Gravity Forms, or CF7 shortcodes were found across your content.', 'nexley-forms'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
