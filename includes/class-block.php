<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the Gutenberg Dynamic Block: nexley-forms/form-selector.
 * 100% server-rendered with zero frontend JavaScript block bloat.
 */
final class Block {

    public const BLOCK_NAME = 'nexley-forms/form-selector';

    /**
     * Hook into WordPress.
     */
    public static function init(): void {
        add_action('init', [self::class, 'register_block']);
        add_action('enqueue_block_editor_assets', [self::class, 'localize_editor_data']);
    }

    /**
     * Register Gutenberg Dynamic Block.
     */
    public static function register_block(): void {
        // Register Editor Script
        wp_register_script(
            'nexley-forms-block-editor',
            NEXLEY_FORMS_URL . 'assets/js/block-editor.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-i18n'],
            NEXLEY_FORMS_VERSION,
            true
        );

        // Register Dynamic Block
        register_block_type(self::BLOCK_NAME, [
            'api_version'     => 2,
            'editor_script'   => 'nexley-forms-block-editor',
            'render_callback' => [self::class, 'render_block'],
            'attributes'      => [
                'formId'       => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'displayTitle' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
                'stylePreset'  => [
                    'type'    => 'string',
                    'default' => '',
                ],
            ],
        ]);
    }

    /**
     * Localize available forms into the editor script.
     */
    public static function localize_editor_data(): void {
        wp_localize_script('nexley-forms-block-editor', 'nexleyBlockData', [
            'forms' => self::get_form_options(),
        ]);
    }

    /**
     * Get list of published/draft forms for the editor block selector.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function get_form_options(): array {
        $posts = get_posts([
            'post_type'      => Post_Types::CPT_FORM,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $options = [];
        if (is_array($posts)) {
            foreach ($posts as $p) {
                $options[] = [
                    'id'    => (int) $p->ID,
                    'title' => (string) ($p->post_title ?: "Form #{$p->ID}"),
                ];
            }
        }

        return $options;
    }

    /**
     * Server-side render callback for nexley-forms/form-selector block.
     *
     * @param array<string, mixed> $attributes
     * @param string               $content
     * @return string
     */
    public static function render_block(array $attributes, string $content = ''): string {
        $form_id = !empty($attributes['formId']) ? absint($attributes['formId']) : 0;

        if ($form_id <= 0) {
            if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
                return '<div class="nexley-block-placeholder" style="padding: 24px; text-align: center; border: 1px dashed #cbd5e1; border-radius: 6px; color: #64748b; font-family: sans-serif;"><strong>' . esc_html__('Nexley Form: No form selected.', 'nexley-forms') . '</strong></div>';
            }
            return '';
        }

        $form_post = get_post($form_id);
        if (!$form_post || $form_post->post_type !== Post_Types::CPT_FORM) {
            if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
                return '<div class="nexley-block-placeholder" style="padding: 24px; text-align: center; border: 1px dashed #f87171; border-radius: 6px; color: #b91c1c; font-family: sans-serif;"><strong>' . esc_html__('Nexley Form: Selected form not found.', 'nexley-forms') . '</strong></div>';
            }
            return '';
        }

        $schema = Form_Schema::get_form_schema($form_id);

        // Apply style preset override from block attributes
        if (!empty($attributes['stylePreset'])) {
            $schema['settings']['style_mode'] = 'preset';
            $schema['settings']['preset']     = sanitize_key($attributes['stylePreset']);
        }

        $form_html = Form_Renderer::render($schema);

        // Prepend title if displayTitle is true
        if (!empty($attributes['displayTitle'])) {
            $form_title = $form_post->post_title ?: ($schema['title'] ?? '');
            if (!empty($form_title)) {
                $title_html = '<h3 class="nexley-forms__title">' . esc_html($form_title) . '</h3>' . "\n";
                $form_html  = $title_html . $form_html;
            }
        }

        return $form_html;
    }
}
