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
        // Post Types.
        Post_Types::init();

        // Admin Meta Boxes & Management.
        if (is_admin()) {
            Meta_Boxes::init();
            Admin_Docs::init();

            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_filter('admin_body_class', [$this, 'add_admin_body_class']);
        }

        // Shortcode.
        Shortcode::init();

        // REST API.
        REST_Controller::init();
    }

    /**
     * Determines whether the current screen belongs strictly to Nexley Forms.
     * Screens:
     * - Forms list table (edit.php?post_type=nexley_form)
     * - Form editor (post.php / post-new.php?post_type=nexley_form)
     * - Entries list table (edit.php?post_type=nexley_entry)
     * - Entry view screen (post.php?post=X for nexley_entry)
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

        // 2. Submenu / Documentation page check.
        if (
            $screen->id === 'nexley_form_page_nexley-forms-docs' ||
            strpos($screen->id, Admin_Docs::PAGE_SLUG) !== false
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
}
