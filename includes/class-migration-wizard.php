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
            $is_dry_run  = !empty($_POST['is_dry_run']);
            $importer    = Importer_Exporter::get_importer($importer_id);

            // Handle file upload if provided
            if (!empty($_FILES['import_file']['tmp_name'])) {
                $source = file_get_contents($_FILES['import_file']['tmp_name']);
                if ($importer && !empty($source)) {
                    $schema = $importer->convert($source);
                    if (!empty($schema)) {
                        $raw_title   = !empty($schema['title']) ? (string) $schema['title'] : 'Imported Form';
                        $final_title = Importer_Exporter::generate_import_title($raw_title, $importer_id);
                        $schema['title'] = $final_title;

                        $report = self::build_conversion_report($importer_id, $importer->get_title(), $schema, $is_dry_run);

                        if ($is_dry_run) {
                            set_transient('nexley_migration_report_' . get_current_user_id(), [$report], 300);
                            wp_safe_redirect(add_query_arg([
                                'post_type' => Post_Types::CPT_FORM,
                                'page'      => self::PAGE_SLUG,
                                'tab'       => 'third-party',
                                'provider'  => $importer_id,
                                'dry_run'   => '1',
                            ], admin_url('edit.php')));
                            exit;
                        }

                        $new_id = Importer_Exporter::import_from_importer($importer_id, $source);
                        if ($new_id > 0) {
                            $report['form_id'] = $new_id;
                            set_transient('nexley_migration_report_' . get_current_user_id(), [$report], 300);
                            wp_safe_redirect(add_query_arg([
                                'post_type' => Post_Types::CPT_FORM,
                                'page'      => self::PAGE_SLUG,
                                'tab'       => 'third-party',
                                'provider'  => $importer_id,
                                'migrated'  => $new_id,
                            ], admin_url('edit.php')));
                            exit;
                        }
                    }
                }
            }

            // Handle database form selection (selected_forms or source_id)
            $selected = [];
            if (!empty($_POST['selected_forms']) && is_array($_POST['selected_forms'])) {
                $selected = array_map('sanitize_text_field', $_POST['selected_forms']);
            } elseif (!empty($_POST['source_id'])) {
                $selected = [sanitize_text_field($_POST['source_id'])];
            }

            if ($importer && !empty($selected)) {
                $reports = [];
                $migrated_ids = [];

                foreach ($selected as $source_id) {
                    $schema = $importer->convert($source_id);
                    if (!empty($schema)) {
                        $raw_title   = !empty($schema['title']) ? (string) $schema['title'] : 'Imported Form';
                        $final_title = Importer_Exporter::generate_import_title($raw_title, $importer_id);
                        $schema['title'] = $final_title;

                        $rep = self::build_conversion_report($importer_id, $importer->get_title(), $schema, $is_dry_run, $source_id);

                        if ($is_dry_run) {
                            $reports[] = $rep;
                        } else {
                            $new_id = Importer_Exporter::import_from_importer($importer_id, $source_id);
                            if ($new_id > 0) {
                                $rep['form_id'] = $new_id;
                                $migrated_ids[] = $new_id;
                                $reports[] = $rep;
                            }
                        }
                    }
                }

                if (!empty($reports)) {
                    set_transient('nexley_migration_report_' . get_current_user_id(), $reports, 300);
                    $redirect_args = [
                        'post_type' => Post_Types::CPT_FORM,
                        'page'      => self::PAGE_SLUG,
                        'tab'       => 'third-party',
                        'provider'  => $importer_id,
                    ];
                    if ($is_dry_run) {
                        $redirect_args['dry_run'] = '1';
                    } elseif (!empty($migrated_ids)) {
                        $redirect_args['migrated'] = implode(',', $migrated_ids);
                    }
                    wp_safe_redirect(add_query_arg($redirect_args, admin_url('edit.php')));
                    exit;
                }
            }

            wp_safe_redirect(add_query_arg([
                'post_type' => Post_Types::CPT_FORM,
                'page'      => self::PAGE_SLUG,
                'tab'       => 'third-party',
                'provider'  => $importer_id ?: 'ninja-forms',
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
     * Build structured conversion report array.
     *
     * @param string               $importer_id
     * @param string               $importer_name
     * @param array<string, mixed> $schema
     * @param bool                 $is_dry_run
     * @param string               $source_id
     * @return array<string, mixed>
     */
    private static function build_conversion_report(string $importer_id, string $importer_name, array $schema, bool $is_dry_run, string $source_id = ''): array {
        $fields_count = count($schema['fields'] ?? []);
        $spans_count  = 0;
        $converted_fields = [];
        $changed_fields   = [];
        $skipped_fields   = [];

        foreach (($schema['fields'] ?? []) as $f) {
            $label = !empty($f['label']) ? $f['label'] : ($f['name'] ?? $f['id'] ?? 'Field');
            $type  = $f['type'] ?? 'text';
            $span  = $f['span'] ?? 12;
            if ($span < 12) {
                $spans_count++;
                $changed_fields[] = sprintf('%s (%s) — multi-column span set to %d/12', $label, $type, $span);
            } else {
                $converted_fields[] = sprintf('%s (%s)', $label, $type);
            }
        }

        if (!empty($schema['skipped_fields']) && is_array($schema['skipped_fields'])) {
            foreach ($schema['skipped_fields'] as $sk) {
                if (is_array($sk)) {
                    $skipped_fields[] = ($sk['name'] ?? 'Unknown') . ': ' . ($sk['reason'] ?? 'Unsupported field type');
                } else {
                    $skipped_fields[] = (string) $sk;
                }
            }
        }

        $notifs_count = count($schema['notifications'] ?? []);

        return [
            'importer_id'   => $importer_id,
            'importer_name' => $importer_name,
            'form_title'    => $schema['title'] ?? 'Imported Form',
            'fields_count'  => $fields_count,
            'spans_count'   => $spans_count,
            'notifs_count'  => $notifs_count,
            'converted'     => $converted_fields,
            'changed'       => $changed_fields,
            'skipped'       => $skipped_fields,
            'status'        => 'Converted',
            'is_dry_run'    => $is_dry_run,
            'source_id'     => is_string($source_id) && strlen($source_id) < 100 ? $source_id : '',
        ];
    }

    /**
     * Render the Import / Export Dashboard.
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access.', 'nexley-forms'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw_tab = sanitize_key($_GET['tab'] ?? 'native');
        $selected_provider = sanitize_key($_GET['provider'] ?? 'ninja-forms');

        if (in_array($raw_tab, ['ninja-forms', 'gravity-forms', 'cf7', 'importers', 'foreign-forms'], true)) {
            if (in_array($raw_tab, ['ninja-forms', 'gravity-forms', 'cf7'], true)) {
                $selected_provider = $raw_tab;
            }
            $raw_tab = 'third-party';
        }

        if (!in_array($selected_provider, ['ninja-forms', 'gravity-forms', 'cf7'], true)) {
            $selected_provider = 'ninja-forms';
        }

        $valid_tabs = ['native', 'third-party', 'shortcodes'];
        $current_tab = in_array($raw_tab, $valid_tabs, true) ? $raw_tab : 'native';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $migrated_raw  = isset($_GET['migrated']) ? sanitize_text_field(wp_unslash($_GET['migrated'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $imported_id   = isset($_GET['imported']) ? absint($_GET['imported']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_replaced   = isset($_GET['replaced']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_rolledback = isset($_GET['rolledback']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $error         = sanitize_key($_GET['error'] ?? '');

        $tab_url = admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG);

        $tabs = [
            'native'      => [
                'title' => __('Nexley (.nex)', 'nexley-forms'),
                'icon'  => 'download',
            ],
            'third-party' => [
                'title' => __('Third-Party Forms', 'nexley-forms'),
                'icon'  => 'migrate',
            ],
            'shortcodes'  => [
                'title' => __('Replace Shortcodes', 'nexley-forms'),
                'icon'  => 'url',
            ],
        ];
        ?>
        <div class="wrap nexley-import-export-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Form Import & Migration Wizard', 'nexley-forms'); ?></h1>
            <hr class="wp-header-end">

            <?php if (!empty($migrated_raw)) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        $ids = array_filter(array_map('absint', explode(',', $migrated_raw)));
                        if (count($ids) === 1) {
                            $m_id = reset($ids);
                            printf(
                                /* translators: 1: Form Title, 2: New Form ID, 3: Edit URL */
                                wp_kses_post(__('Form <strong>%1$s</strong> (#%2$d) successfully imported! <a href="%3$s">Edit in Builder &rarr;</a>', 'nexley-forms')),
                                esc_html(get_the_title($m_id)),
                                $m_id,
                                esc_url(get_edit_post_link($m_id) ?: '')
                            );
                        } else {
                            printf(
                                /* translators: %d: Number of forms */
                                esc_html(_n('%d form imported successfully!', '%d forms imported successfully!', count($ids), 'nexley-forms')),
                                count($ids)
                            );
                        }
                        ?>
                    </p>
                </div>
            <?php elseif ($imported_id > 0) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: Form Title, 2: New Form ID, 3: Edit URL */
                            wp_kses_post(__('Native .nex form <strong>%1$s</strong> (#%2$d) imported successfully! <a href="%3$s">Edit in Builder &rarr;</a>', 'nexley-forms')),
                            esc_html(get_the_title($imported_id)),
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
                    <p><?php esc_html_e('Operation failed. Please verify the source file format and try again.', 'nexley-forms'); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // Display Conversion Report (Dry Run Preview or Real Migration summary)
            $reports_data = get_transient('nexley_migration_report_' . get_current_user_id());
            if ($reports_data) :
                delete_transient('nexley_migration_report_' . get_current_user_id());
                $reports_list = isset($reports_data[0]) && is_array($reports_data[0]) ? $reports_data : [$reports_data];
                foreach ($reports_list as $report) :
                    self::render_conversion_report($report);
                endforeach;
            endif;
            ?>

            <!-- Accessible Plain Text Tab Navigation -->
            <div class="nexley-import-tabs" role="tablist" aria-label="<?php esc_attr_e('Import and Export Tabs', 'nexley-forms'); ?>">
                <?php foreach ($tabs as $slug => $tab_info) : ?>
                    <?php $is_active = ($current_tab === $slug); ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $slug, $tab_url)); ?>"
                       class="nexley-import-tab-link <?php echo $is_active ? 'is-active' : ''; ?>"
                       role="tab"
                       aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                       tabindex="<?php echo $is_active ? '0' : '-1'; ?>">
                        <?php Icons::render($tab_info['icon'], 16); ?>
                        <span><?php echo esc_html($tab_info['title']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Tab Panels -->
            <div class="nexley-import-tab-content">
                <?php if ($current_tab === 'native') : ?>
                    <div role="tabpanel" aria-label="<?php esc_attr_e('Nexley (.nex)', 'nexley-forms'); ?>">
                        <?php self::render_native_tab(); ?>
                    </div>
                <?php elseif ($current_tab === 'shortcodes') : ?>
                    <div role="tabpanel" aria-label="<?php esc_attr_e('Replace Shortcodes', 'nexley-forms'); ?>">
                        <?php self::render_shortcodes_tab(); ?>
                    </div>
                <?php else : ?>
                    <div role="tabpanel" aria-label="<?php esc_attr_e('Third-Party Forms', 'nexley-forms'); ?>">
                        <?php self::render_combined_importers_tab($selected_provider); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a clean, unbadged conversion report table.
     *
     * @param array<string, mixed> $report
     */
    private static function render_conversion_report(array $report): void {
        $is_dry = !empty($report['is_dry_run']);
        ?>
        <div class="postbox" style="padding: 20px; margin-bottom: 24px; border-left: 3px solid var(--wp-admin-theme-color, #3858e9);">
            <h2 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: var(--nx-text-main);">
                <?php echo $is_dry ? esc_html__('Dry-Run Conversion Preview', 'nexley-forms') : esc_html__('Form Conversion Report', 'nexley-forms'); ?>
            </h2>
            <p style="margin: 0 0 14px 0; color: var(--nx-text-muted); font-size: 13px;">
                <?php echo $is_dry
                    ? esc_html__('Dry-run simulation completed. No posts were saved to the database. Review the conversion breakdown below:', 'nexley-forms')
                    : esc_html__('Form converted and saved to the database. Conversion breakdown:', 'nexley-forms'); ?>
            </p>

            <table class="widefat striped nexley-report-table" style="max-width: 760px; margin-bottom: 16px;">
                <tbody>
                    <tr>
                        <th style="width: 220px;"><strong><?php esc_html_e('Form Title', 'nexley-forms'); ?></strong></th>
                        <td><strong><?php echo esc_html($report['form_title']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><strong><?php esc_html_e('Source System', 'nexley-forms'); ?></strong></th>
                        <td><?php echo esc_html($report['importer_name']); ?></td>
                    </tr>
                    <tr>
                        <th><strong><?php esc_html_e('Converted Fields', 'nexley-forms'); ?></strong></th>
                        <td>
                            <?php echo esc_html(sprintf(__('%d fields normalized to Nexley schema', 'nexley-forms'), $report['fields_count'])); ?>
                            <?php if (!empty($report['converted']) && count($report['converted']) <= 8) : ?>
                                <div style="font-size: 12px; color: var(--nx-text-muted); margin-top: 4px;">
                                    <?php echo esc_html(implode(', ', $report['converted'])); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><strong><?php esc_html_e('Multi-Column Grid Spans', 'nexley-forms'); ?></strong></th>
                        <td>
                            <?php echo esc_html(sprintf(__('%d fields mapped to 12-column layout', 'nexley-forms'), $report['spans_count'])); ?>
                            <?php if (!empty($report['changed'])) : ?>
                                <div style="font-size: 12px; color: var(--nx-text-muted); margin-top: 4px;">
                                    <?php echo esc_html(implode('; ', $report['changed'])); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><strong><?php esc_html_e('Notifications / Routing', 'nexley-forms'); ?></strong></th>
                        <td>
                            <?php echo esc_html(sprintf(__('%d notification / email rules converted', 'nexley-forms'), $report['notifs_count'])); ?>
                        </td>
                    </tr>
                    <?php if (!empty($report['skipped'])) : ?>
                        <tr>
                            <th><strong><?php esc_html_e('Skipped Fields / Reasons', 'nexley-forms'); ?></strong></th>
                            <td>
                                <ul style="margin: 0; padding-left: 18px; font-size: 12.5px; color: var(--nx-text-muted);">
                                    <?php foreach ($report['skipped'] as $skipped_item) : ?>
                                        <li><?php echo esc_html($skipped_item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($is_dry && !empty($report['source_id'])) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
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
        <?php
    }

    /**
     * Render the combined third-party importers tab with a provider dropdown selector.
     *
     * @param string $selected_provider
     */
    private static function render_combined_importers_tab(string $selected_provider = 'ninja-forms'): void {
        $providers = [
            'ninja-forms'   => __('Ninja Forms', 'nexley-forms'),
            'gravity-forms' => __('Gravity Forms', 'nexley-forms'),
            'cf7'           => __('Contact Form 7', 'nexley-forms'),
        ];
        ?>
        <div style="max-width: 860px;">
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 600; color: var(--nx-text-main);"><?php esc_html_e('Import Third-Party Forms', 'nexley-forms'); ?></h2>
                <p style="margin: 0; color: var(--nx-text-muted); font-size: 13.5px; line-height: 1.5;">
                    <?php esc_html_e('Convert forms created in other WordPress form plugins into native Nexley forms. Select the form plugin type below to upload an export file or migrate detected forms directly from your database.', 'nexley-forms'); ?>
                </p>
            </div>

            <!-- Provider Selection Dropdown -->
            <div class="postbox" style="padding: 16px 20px; margin-bottom: 24px; background: #f8fafc; border: 1px solid var(--nx-border); border-radius: var(--nx-radius);">
                <label for="nexley-provider-select" style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--nx-text-main);">
                    <?php esc_html_e('Select Form Type to Import:', 'nexley-forms'); ?>
                </label>
                <select id="nexley-provider-select" class="regular-text" style="max-width: 320px; height: 36px; font-size: 14px; font-weight: 500;">
                    <?php foreach ($providers as $id => $name) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_provider, $id); ?>>
                            <?php echo esc_html($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Provider Content Panels -->
            <?php foreach ($providers as $id => $name) : ?>
                <div id="nexley-provider-panel-<?php echo esc_attr($id); ?>" class="nexley-provider-panel" style="<?php echo ($selected_provider === $id) ? '' : 'display: none;'; ?>">
                    <?php self::render_importer_tab($id); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a dedicated third-party importer tab (Ninja Forms, Gravity Forms, CF7).
     *
     * Requirements:
     * - No accordions!
     * - Block 1: File import drop zone (always visible).
     * - Block 2: Detected forms on this site (if any).
     * - No "unverified" or "documented, unverified" badge.
     *
     * @param string $importer_id
     */
    private static function render_importer_tab(string $importer_id): void {
        $importer = Importer_Exporter::get_importer($importer_id);
        if (!$importer) {
            ?>
            <p><?php esc_html_e('Importer not found.', 'nexley-forms'); ?></p>
            <?php
            return;
        }

        $is_available = $importer->is_available();
        $forms        = $is_available ? $importer->get_available_forms() : [];

        // File extensions and description per provider
        $accepted_exts   = '.json';
        $accepted_labels = '.json';
        $file_desc       = __('Upload an export file from your computer to convert into a native Nexley form.', 'nexley-forms');

        if ($importer_id === 'ninja-forms') {
            $accepted_exts   = '.nff,.json,.txt';
            $accepted_labels = '.nff, .json, .txt';
            $file_desc       = __('Export your form from Ninja Forms (Forms &rarr; Export) and upload the .nff or .json file here.', 'nexley-forms');
        } elseif ($importer_id === 'gravity-forms') {
            $accepted_exts   = '.json';
            $accepted_labels = '.json';
            $file_desc       = __('Export your form from Gravity Forms (Forms &rarr; Import/Export &rarr; Export Forms) as a .json file.', 'nexley-forms');
        } elseif ($importer_id === 'cf7') {
            $accepted_exts   = '.txt,.html,.xml';
            $accepted_labels = '.txt, .html, .xml';
            $file_desc       = __('Upload a raw CF7 form template text file or WXR XML export to parse tags into schema.', 'nexley-forms');
        }
        ?>
        <div class="nexley-importer-tab-panel" style="max-width: 860px;">
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 600; color: var(--nx-text-main);"><?php echo esc_html($importer->get_title()); ?></h2>
                <p style="margin: 0; color: var(--nx-text-muted); font-size: 13.5px; line-height: 1.5;">
                    <?php echo esc_html($importer->get_description()); ?>
                </p>
            </div>

            <!-- BLOCK 1: FILE IMPORT DROP ZONE (Always visible - NO ACCORDIONS) -->
            <div class="postbox" style="padding: 24px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600;"><?php esc_html_e('Import from File', 'nexley-forms'); ?></h3>
                <p style="margin: 0 0 16px 0; color: var(--nx-text-muted); font-size: 13px;">
                    <?php echo esc_html($file_desc); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" class="nexley-dropzone-form">
                    <?php wp_nonce_field('nexley_migrate_third_party'); ?>
                    <input type="hidden" name="action" value="migrate_third_party" />
                    <input type="hidden" name="importer_id" value="<?php echo esc_attr($importer_id); ?>" />

                    <div class="nexley-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e('File upload drop zone', 'nexley-forms'); ?>">
                        <input type="file" name="import_file" accept="<?php echo esc_attr($accepted_exts); ?>" class="screen-reader-text nexley-file-input" required />

                        <div class="nexley-dropzone-prompt">
                            <div class="nexley-dropzone-icon">
                                <?php Icons::render('upload', 24); ?>
                            </div>
                            <p class="nexley-dropzone-text"><?php esc_html_e('Drop a file here or choose one', 'nexley-forms'); ?></p>
                            <p class="nexley-dropzone-subtext"><?php printf(esc_html__('Accepted file types: %s', 'nexley-forms'), esc_html($accepted_labels)); ?></p>
                        </div>

                        <div class="nexley-dropzone-selected-file">
                            <?php Icons::render('check', 16); ?>
                            <span class="nexley-filename"></span>
                            <span class="nexley-filesize"></span>
                            <button type="button" class="nexley-dropzone-remove-file"><?php esc_html_e('Remove', 'nexley-forms'); ?></button>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; margin-top: 14px;">
                        <button type="submit" name="is_dry_run" value="1" class="button button-secondary nexley-dropzone-submit" disabled>
                            <?php Icons::render('preview', 14); ?> <span><?php esc_html_e('Preview Import (Dry Run)', 'nexley-forms'); ?></span>
                        </button>
                        <button type="submit" class="button button-primary nexley-dropzone-submit" disabled>
                            <?php esc_html_e('Upload & Convert Form', 'nexley-forms'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- BLOCK 2: DETECTED FORMS ON THIS SITE -->
            <div class="postbox" style="padding: 24px;">
                <h3 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600;"><?php esc_html_e('Or import from this site', 'nexley-forms'); ?></h3>

                <?php if ($is_available && !empty($forms)) : ?>
                    <p style="margin: 0 0 16px 0; color: var(--nx-text-muted); font-size: 13px;">
                        <?php printf(esc_html(_n('%d form detected in your database. Select one or more to migrate:', '%d forms detected in your database. Select one or more to migrate:', count($forms), 'nexley-forms')), count($forms)); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>">
                        <?php wp_nonce_field('nexley_migrate_third_party'); ?>
                        <input type="hidden" name="action" value="migrate_third_party" />
                        <input type="hidden" name="importer_id" value="<?php echo esc_attr($importer_id); ?>" />

                        <table class="widefat striped nexley-detected-forms-table" style="margin-bottom: 16px;">
                            <thead>
                                <tr>
                                    <th style="width: 32px;"><input type="checkbox" class="nexley-select-all-forms" title="<?php esc_attr_e('Select all forms', 'nexley-forms'); ?>" /></th>
                                    <th><?php esc_html_e('Form Title', 'nexley-forms'); ?></th>
                                    <th style="width: 120px;"><?php esc_html_e('Source ID', 'nexley-forms'); ?></th>
                                    <th style="width: 140px;"><?php esc_html_e('Status', 'nexley-forms'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forms as $f_id => $f_title) : ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_forms[]" value="<?php echo esc_attr((string) $f_id); ?>" class="nexley-form-checkbox" />
                                        </td>
                                        <td><strong><?php echo esc_html($f_title); ?></strong></td>
                                        <td><code>#<?php echo esc_html((string) $f_id); ?></code></td>
                                        <td><span style="color: var(--nx-text-muted); font-size: 13px;"><?php esc_html_e('Ready', 'nexley-forms'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button type="submit" name="is_dry_run" value="1" class="button button-secondary">
                                <?php Icons::render('preview', 14); ?> <span><?php esc_html_e('Preview Import (Dry Run)', 'nexley-forms'); ?></span>
                            </button>
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e('Import Selected Forms', 'nexley-forms'); ?>
                            </button>
                        </div>
                    </form>
                <?php else : ?>
                    <p style="margin: 8px 0 0 0; color: var(--nx-text-muted); font-size: 13px;">
                        <?php printf(esc_html__('No %s forms detected in this WordPress database. You can import from an export file above.', 'nexley-forms'), esc_html($importer->get_title())); ?>
                    </p>
                <?php endif; ?>
            </div>
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
        <div style="max-width: 860px;">
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 600; color: var(--nx-text-main);"><?php esc_html_e('Native .nex Form Transfer', 'nexley-forms'); ?></h2>
                <p style="margin: 0; color: var(--nx-text-muted); font-size: 13.5px; line-height: 1.5;">
                    <?php esc_html_e('Transfer forms between WordPress sites using Nexley\'s native .nex envelope format. Preserves full 12-column grid schema, conditional rules, presets, and notification actions.', 'nexley-forms'); ?>
                </p>
            </div>

            <!-- BLOCK 1: FILE IMPORT DROP ZONE -->
            <div class="postbox" style="padding: 24px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600;"><?php esc_html_e('Import .nex Envelope', 'nexley-forms'); ?></h3>
                <p style="margin: 0 0 16px 0; color: var(--nx-text-muted); font-size: 13px;">
                    <?php esc_html_e('Upload a previously exported .nex form envelope file to import it onto this site.', 'nexley-forms'); ?>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" class="nexley-dropzone-form">
                    <?php wp_nonce_field('nexley_import_nex'); ?>
                    <input type="hidden" name="action" value="import_nex_file" />

                    <div class="nexley-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e('File upload drop zone', 'nexley-forms'); ?>">
                        <input type="file" name="nex_file" accept=".nex,.json" class="screen-reader-text nexley-file-input" required />

                        <div class="nexley-dropzone-prompt">
                            <div class="nexley-dropzone-icon">
                                <?php Icons::render('upload', 24); ?>
                            </div>
                            <p class="nexley-dropzone-text"><?php esc_html_e('Drop a file here or choose one', 'nexley-forms'); ?></p>
                            <p class="nexley-dropzone-subtext"><?php esc_html_e('Accepted file types: .nex, .json', 'nexley-forms'); ?></p>
                        </div>

                        <div class="nexley-dropzone-selected-file">
                            <?php Icons::render('check', 16); ?>
                            <span class="nexley-filename"></span>
                            <span class="nexley-filesize"></span>
                            <button type="button" class="nexley-dropzone-remove-file"><?php esc_html_e('Remove', 'nexley-forms'); ?></button>
                        </div>
                    </div>

                    <div style="margin-top: 14px;">
                        <button type="submit" class="button button-primary nexley-dropzone-submit" disabled>
                            <?php Icons::render('upload', 14); ?>
                            <span><?php esc_html_e('Upload & Import Form', 'nexley-forms'); ?></span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- BLOCK 2: EXPORT CARD -->
            <div class="postbox" style="padding: 24px;">
                <h3 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 600;"><?php esc_html_e('Export Form to .nex Envelope', 'nexley-forms'); ?></h3>
                <p style="margin: 0 0 16px 0; color: var(--nx-text-muted); font-size: 13px;">
                    <?php esc_html_e('Select any Nexley form to download as a portable .nex envelope file.', 'nexley-forms'); ?>
                </p>

                <?php if (!empty($forms)) : ?>
                    <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr(Post_Types::CPT_FORM); ?>" />
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                        <input type="hidden" name="action" value="export_nex" />

                        <div style="display: flex; gap: 12px; align-items: center; max-width: 500px;">
                            <label for="export_form_select" class="screen-reader-text"><?php esc_html_e('Select Form to Export', 'nexley-forms'); ?></label>
                            <select name="form_id" id="export_form_select" style="flex: 1;">
                                <?php foreach ($forms as $f) : ?>
                                    <option value="<?php echo esc_attr((string) $f->ID); ?>">
                                        <?php echo esc_html($f->post_title ?: "#{$f->ID}"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php
                            $first_id = $forms[0]->ID;
                            wp_nonce_field('nexley_export_nex_' . $first_id);
                            ?>

                            <button type="submit" class="button button-secondary">
                                <?php Icons::render('download', 14); ?>
                                <span><?php esc_html_e('Download .nex', 'nexley-forms'); ?></span>
                            </button>
                        </div>
                    </form>
                <?php else : ?>
                    <p style="color: var(--nx-text-muted); margin: 0;"><?php esc_html_e('No Nexley forms found to export. Create a form first.', 'nexley-forms'); ?></p>
                <?php endif; ?>
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
        <div class="postbox" style="padding: 24px; max-width: 1000px;">
            <h2 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 600; color: var(--nx-text-main);"><?php esc_html_e('Shortcode Migration & Replacement Scanner', 'nexley-forms'); ?></h2>
            <p style="color: var(--nx-text-muted); font-size: 13px; line-height: 1.5; margin-bottom: 20px;">
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
                                    <code style="background: #f1f5f9; color: #b91c1c; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html($item['shortcode']); ?></code>
                                </td>
                                <td>
                                    <span><?php echo esc_html($item['system']); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($nex_forms)) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=' . Post_Types::CPT_FORM . '&page=' . self::PAGE_SLUG)); ?>" style="display: flex; gap: 8px; align-items: center;">
                                            <?php wp_nonce_field('nexley_replace_shortcode'); ?>
                                            <input type="hidden" name="action" value="replace_shortcode" />
                                            <input type="hidden" name="target_post_id" value="<?php echo esc_attr((string) $item['post_id']); ?>" />
                                            <input type="hidden" name="old_shortcode" value="<?php echo esc_attr($item['shortcode']); ?>" />

                                            <select name="new_form_id" style="max-width: 220px;">
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
                <div style="background: #f8fafc; border: 1px solid var(--nx-border); border-radius: 6px; padding: 24px; text-align: center;">
                    <div style="margin-bottom: 8px;"><span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:#ecfdf5;color:#059669;"><?php Icons::render('check', 20); ?></span></div>
                    <h3 style="margin: 8px 0 4px 0;"><?php esc_html_e('Zero Legacy Shortcodes Found', 'nexley-forms'); ?></h3>
                    <p style="margin: 0; color: var(--nx-text-muted); font-size: 13px;">
                        <?php esc_html_e('No embedded Ninja Forms, Gravity Forms, or CF7 shortcodes were found across your content.', 'nexley-forms'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
