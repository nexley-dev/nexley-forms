<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Orchestrator.
 */
final class Plugin {

    private static ?self $instance = null;

    /**
     * Singleton instance accessor.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Initialize plugin subsystems.
     */
    public function init(): void {
        // Custom Database & Migrations.
        DB::init();

        // Field Registry.
        Field_Registry::init();

        // Post Types.
        Post_Types::init();

        // Admin Meta Boxes & Management.
        if (is_admin()) {
            Meta_Boxes::init();
            Entries_Page::init();
            Migration_Wizard::init();
            Admin_Docs::init();

            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_filter('admin_body_class', [$this, 'add_admin_body_class']);
            add_action('admin_notices', [$this, 'display_upload_fallback_notice']);
        }

        // Shortcode.
        Shortcode::init();

        // Gutenberg Dynamic Block.
        Block::init();

        // Privacy & Retention.
        Privacy::init();

        // REST API.
        REST_Controller::init();
    }

    /**
     * Determines whether the current screen belongs strictly to Nexley Forms.
     * Screens:
     * - Forms list table (edit.php?post_type=nexley_form)
     * - Form editor (post.php / post-new.php?post_type=nexley_form)
     * - Entries list table / details (edit.php?post_type=nexley_form&page=nexley-entries)
     * - Import/Export Wizard (edit.php?post_type=nexley_form&page=nexley-import-export)
     * - Legacy entries list table (edit.php?post_type=nexley_entry)
     * - Documentation page (edit.php?post_type=nexley_form&page=nexley-forms-docs)
     *
     * @param \WP_Screen|null $screen
     * @return bool
     */
    public static function is_nexley_screen(?\WP_Screen $screen = null): bool {
        if (!$screen) {
            $screen = get_current_screen();
        }

        if (!$screen) {
            return false;
        }

        // 1. Post type check for Form and Entry screens.
        if (in_array($screen->post_type, [Post_Types::CPT_FORM, Post_Types::CPT_ENTRY], true)) {
            return true;
        }

        // 2. Submenu / Entries, Importers, & Documentation page check.
        if (
            $screen->id === 'nexley_form_page_nexley-forms-docs' ||
            strpos($screen->id, Admin_Docs::PAGE_SLUG) !== false ||
            $screen->id === 'nexley_form_page_nexley-entries' ||
            strpos($screen->id, Entries_Page::PAGE_SLUG) !== false ||
            $screen->id === 'nexley_form_page_nexley-import-export' ||
            strpos($screen->id, Migration_Wizard::PAGE_SLUG) !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Add scoped body class for strict CSS isolation.
     *
     * @param string $classes
     * @return string
     */
    public function add_admin_body_class(string $classes): string {
        if (self::is_nexley_screen()) {
            $classes .= ' nexley-admin-theme ';
        }
        return $classes;
    }

    /**
     * Enqueue Admin JS & CSS exclusively on Nexley Forms edit, listing, and documentation screens.
     * Guarantees zero CSS bleed into the global WordPress dashboard.
     *
     * @param string $hook_suffix
     */
    public function enqueue_admin_assets(string $hook_suffix): void {
        if (!self::is_nexley_screen()) {
            return;
        }

        // Scoped Nexley Admin Stylesheet (Light Theme Engineering).
        wp_enqueue_style(
            'nexley-forms-admin-theme',
            NEXLEY_FORMS_URL . 'assets/css/admin-nexley.css',
            [],
            NEXLEY_FORMS_VERSION
        );

        // Admin JavaScript.
        wp_enqueue_script(
            'nexley-forms-admin',
            NEXLEY_FORMS_URL . 'assets/js/admin.js',
            ['jquery'],
            NEXLEY_FORMS_VERSION,
            true
        );

        $screen = get_current_screen();
        if ($screen && $screen->post_type === Post_Types::CPT_FORM && in_array($screen->base, ['post', 'post-new'], true)) {
            // Frontend base and presets stylesheets for authentic builder Live Preview
            wp_enqueue_style(
                'nexley-forms-base',
                NEXLEY_FORMS_URL . 'assets/css/frontend-base.css',
                [],
                NEXLEY_FORMS_VERSION
            );
            wp_enqueue_style(
                'nexley-forms-presets',
                NEXLEY_FORMS_URL . 'assets/css/frontend-presets.css',
                ['nexley-forms-base'],
                NEXLEY_FORMS_VERSION
            );

            // Drag-and-Drop Builder JavaScript (Vanilla JS, loaded strictly on builder screen)
            wp_enqueue_script(
                'nexley-forms-builder',
                NEXLEY_FORMS_URL . 'assets/js/admin-builder.js',
                [],
                NEXLEY_FORMS_VERSION,
                true
            );
        }

        wp_localize_script('nexley-forms-admin', 'nexleyAdminConfig', [
            'i18n' => [
                'copied'          => __('Copied!', 'nexley-forms'),
                'copy'            => __('Copy', 'nexley-forms'),
                'insertTag'       => __('Insert Tag', 'nexley-forms'),
                'missingName'     => __('Please enter a field name.', 'nexley-forms'),
                'confirmDelete'   => __('Are you sure you want to delete this?', 'nexley-forms'),
            ],
        ]);
    }

    /**
     * Display Nginx deny snippet notice if uploads are forced to public fallback directory.
     */
    public function display_upload_fallback_notice(): void {
        if (!get_option('nexley_forms_upload_fallback_notice') || !current_user_can('manage_options') || !self::is_nexley_screen()) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Nexley Forms Upload Protection Notice:', 'nexley-forms') . '</strong> ';
        esc_html_e('Uploaded form attachments are currently stored inside the public uploads folder because an external directory outside the web root was not writable. For Nginx servers, protect this directory by adding the following rule to your site configuration:', 'nexley-forms');
        echo '</p><pre style="background:#f1f5f9;padding:10px;border-radius:4px;overflow-x:auto;"><code>location ~* /wp-content/uploads/nexley-forms/.* {
    deny all;
    return 403;
}</code></pre></div>';
    }
}
