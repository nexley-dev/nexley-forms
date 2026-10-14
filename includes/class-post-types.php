<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles registration and management of Custom Post Types:
 * - nexley_form: Form configuration and templates
 * - nexley_entry: Saved form submissions
 */
final class Post_Types {

    public const CPT_FORM  = 'nexley_form';
    public const CPT_ENTRY = 'nexley_entry';

    /**
     * Register WordPress hooks.
     */
    public static function init(): void {
        add_action('init', [self::class, 'register_post_types']);

        if (is_admin()) {
            add_action('admin_init', [self::class, 'redirect_legacy_cpt_entries_screen']);
        }

        // nexley_form list table columns.
        add_filter('manage_' . self::CPT_FORM . '_posts_columns', [self::class, 'form_table_columns']);
        add_action('manage_' . self::CPT_FORM . '_posts_custom_column', [self::class, 'render_form_column'], 10, 2);

        // nexley_entry list table columns.
        add_filter('manage_' . self::CPT_ENTRY . '_posts_columns', [self::class, 'entry_table_columns']);
        add_action('manage_' . self::CPT_ENTRY . '_posts_custom_column', [self::class, 'render_entry_column'], 10, 2);

        // Filter entries by form in admin list table.
        add_action('pre_get_posts', [self::class, 'filter_entries_by_form']);
        add_action('restrict_manage_posts', [self::class, 'render_entries_form_filter']);
    }

    /**
     * Register Custom Post Types.
     */
    public static function register_post_types(): void {
        // Register Form CPT.
        $form_labels = [
            'name'                  => _x('Nexley Forms', 'Post type general name', 'nexley-forms'),
            'singular_name'         => _x('Nexley Form', 'Post type singular name', 'nexley-forms'),
            'menu_name'             => _x('Nexley Forms', 'Admin Menu text', 'nexley-forms'),
            'name_admin_bar'        => _x('Nexley Form', 'Add New on Toolbar', 'nexley-forms'),
            'add_new'               => __('Add New Form', 'nexley-forms'),
            'add_new_item'          => __('Add New Nexley Form', 'nexley-forms'),
            'new_item'              => __('New Form', 'nexley-forms'),
            'edit_item'             => __('Edit Form', 'nexley-forms'),
            'view_item'             => __('View Form', 'nexley-forms'),
            'all_items'             => __('All Forms', 'nexley-forms'),
            'search_items'          => __('Search Forms', 'nexley-forms'),
            'parent_item_colon'     => __('Parent Forms:', 'nexley-forms'),
            'not_found'             => __('No forms found.', 'nexley-forms'),
            'not_found_in_trash'    => __('No forms found in Trash.', 'nexley-forms'),
        ];

        $form_args = [
            'labels'             => $form_labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 30,
            'menu_icon'          => 'dashicons-feedback',
            'supports'           => ['title'],
            'show_in_rest'       => false,
        ];

        register_post_type(self::CPT_FORM, $form_args);

        // Register Entry CPT.
        $entry_labels = [
            'name'                  => _x('Entries', 'Post type general name', 'nexley-forms'),
            'singular_name'         => _x('Entry', 'Post type singular name', 'nexley-forms'),
            'menu_name'             => _x('Entries', 'Admin Menu text', 'nexley-forms'),
            'name_admin_bar'        => _x('Entry', 'Add New on Toolbar', 'nexley-forms'),
            'add_new'               => __('Add New', 'nexley-forms'),
            'add_new_item'          => __('Add New Entry', 'nexley-forms'),
            'new_item'              => __('New Entry', 'nexley-forms'),
            'edit_item'             => __('View Entry', 'nexley-forms'),
            'view_item'             => __('View Entry', 'nexley-forms'),
            'all_items'             => __('Entries', 'nexley-forms'),
            'search_items'          => __('Search Entries', 'nexley-forms'),
            'parent_item_colon'     => __('Parent Entries:', 'nexley-forms'),
            'not_found'             => __('No entries found.', 'nexley-forms'),
            'not_found_in_trash'    => __('No entries found in Trash.', 'nexley-forms'),
        ];

        $entry_args = [
            'labels'             => $entry_labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'capabilities'       => [
                'create_posts' => 'do_not_allow', // Entries are only created via form submissions.
            ],
            'map_meta_cap'       => true,
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => ['title'],
            'show_in_rest'       => false,
        ];

        register_post_type(self::CPT_ENTRY, $entry_args);
    }

    /**
     * Add Shortcode and Entries count columns to nexley_form list table.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function form_table_columns(array $columns): array {
        $new_columns = [];
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'title') {
                $new_columns['shortcode'] = __('Shortcode', 'nexley-forms');
                $new_columns['entries']   = __('Entries', 'nexley-forms');
            }
        }
        return $new_columns;
    }

    /**
     * Render custom column output for nexley_form list table.
     *
     * @param string $column
     * @param int $post_id
     */
    public static function render_form_column(string $column, int $post_id): void {
        if ($column === 'shortcode') {
            $shortcode = sprintf('[nexley_form id="%d"]', $post_id);
            ?>
            <div class="nexley-shortcode-cell">
                <code class="nexley-shortcode-text"><?php echo esc_html($shortcode); ?></code>
                <button type="button" 
                        class="button button-small nexley-copy-shortcode" 
                        data-shortcode="<?php echo esc_attr($shortcode); ?>" 
                        title="<?php esc_attr_e('Copy shortcode to clipboard', 'nexley-forms'); ?>">
                    <?php Icons::render('copy', 14); ?>
                    <span class="nexley-copy-label"><?php esc_html_e('Copy', 'nexley-forms'); ?></span>
                </button>
            </div>
            <?php
        } elseif ($column === 'entries') {
            $count = self::get_form_entries_count($post_id);
            $url   = add_query_arg([
                'post_type' => self::CPT_FORM,
                'page'      => 'nexley-entries',
                'form_id'   => $post_id,
            ], admin_url('edit.php'));

            echo '<a href="' . esc_url($url) . '" class="nexley-entry-count-badge">';
            echo esc_html(
                sprintf(
                    /* translators: %d: Number of entries */
                    _n('%d entry', '%d entries', $count, 'nexley-forms'),
                    $count
                )
            );
            echo '</a>';
        }
    }

    /**
     * Add custom columns for nexley_entry list table.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function entry_table_columns(array $columns): array {
        return [
            'cb'         => $columns['cb'] ?? '<input type="checkbox" />',
            'title'      => __('Entry Title', 'nexley-forms'),
            'form'       => __('Associated Form', 'nexley-forms'),
            'submitter'  => __('Submitter Details', 'nexley-forms'),
            'date'       => __('Submission Date', 'nexley-forms'),
        ];
    }

    /**
     * Render custom column output for nexley_entry list table.
     *
     * @param string $column
     * @param int $post_id
     */
    public static function render_entry_column(string $column, int $post_id): void {
        if ($column === 'form') {
            $form_id    = (int) get_post_meta($post_id, '_nexley_form_id', true);
            $form_title = (string) get_post_meta($post_id, '_nexley_form_title', true);

            if ($form_id > 0) {
                $edit_url = get_edit_post_link($form_id);
                if ($edit_url) {
                    echo '<strong><a href="' . esc_url($edit_url) . '">' . esc_html($form_title ?: "#{$form_id}") . '</a></strong>';
                } else {
                    echo esc_html($form_title ?: "#{$form_id}");
                }
            } else {
                echo '<span class="description">' . esc_html__('Unknown', 'nexley-forms') . '</span>';
            }
        } elseif ($column === 'submitter') {
            $data = get_post_meta($post_id, '_nexley_submitted_fields', true);
            if (is_array($data)) {
                $name  = $data['client-name'] ?? $data['name'] ?? $data['your-name'] ?? '';
                $email = $data['client-email'] ?? $data['email'] ?? $data['your-email'] ?? '';

                $output = [];
                if (!empty($name)) {
                    $output[] = '<strong>' . esc_html((string) $name) . '</strong>';
                }
                if (!empty($email)) {
                    $output[] = '<a href="' . esc_url('mailto:' . (string) $email) . '">' . esc_html((string) $email) . '</a>';
                }

                if (!empty($output)) {
                    echo wp_kses_post(implode('<br>', $output));
                } else {
                    echo '<span class="description">' . esc_html__('No contact info captured', 'nexley-forms') . '</span>';
                }
            } else {
                echo '<span class="description">&mdash;</span>';
            }
        }
    }

    /**
     * Filter entry query by form ID if present in query arguments.
     *
     * @param \WP_Query $query
     */
    public static function filter_entries_by_form(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== self::CPT_ENTRY) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form_id = isset($_GET['nexley_form_id']) ? absint($_GET['nexley_form_id']) : 0;
        if ($form_id > 0) {
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key'     => '_nexley_form_id',
                'value'   => $form_id,
                'compare' => '=',
            ];
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Add dropdown filter for forms on the Entries list table.
     *
     * @param string $post_type
     */
    public static function render_entries_form_filter(string $post_type): void {
        if ($post_type !== self::CPT_ENTRY) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_form = isset($_GET['nexley_form_id']) ? absint($_GET['nexley_form_id']) : 0;

        $forms = get_posts([
            'post_type'      => self::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        if (empty($forms)) {
            return;
        }
        ?>
        <select name="nexley_form_id" id="nexley_filter_form_id">
            <option value=""><?php esc_html_e('All Nexley Forms', 'nexley-forms'); ?></option>
            <?php foreach ($forms as $form) : ?>
                <option value="<?php echo esc_attr((string) $form->ID); ?>" <?php selected($current_form, $form->ID); ?>>
                    <?php
                    $form_fallback_title = sprintf(
                        /* translators: %d: Form ID number */
                        __('Form #%d', 'nexley-forms'),
                        $form->ID
                    );
                    echo esc_html($form->post_title ?: $form_fallback_title);
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Get count of entries for a given form ID using high-performance custom DB table.
     *
     * @param int $form_id
     * @return int
     */
    public static function get_form_entries_count(int $form_id): int {
        $counts = DB::get_status_counts($form_id);
        return $counts['all'] ?? 0;
    }

    /**
     * Redirect direct navigation from legacy CPT entries screen to custom DB table entries screen.
     */
    public static function redirect_legacy_cpt_entries_screen(): void {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === self::CPT_ENTRY) {
            $target_url = admin_url('edit.php?post_type=' . self::CPT_FORM . '&page=nexley-entries');
            if (!empty($_GET['nexley_form_id'])) {
                $target_url = add_query_arg('form_id', absint($_GET['nexley_form_id']), $target_url);
            }
            wp_safe_redirect($target_url);
            exit;
        }
    }
}
