<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the [nexley_form id="X"] shortcode and conditional asset registration.
 *
 * Guarantees:
 * - ZERO CSS and ZERO JS are loaded on pages without a form.
 * - Raw mode loads 0 CSS.
 * - Base/Preset modes load tiny scoped base CSS wrapped in @layer nexley with zero specificity.
 * - Conditional and Multi-step JS modules are registered and enqueued ONLY if the specific form uses them.
 */
final class Shortcode {

    public const SHORTCODE_TAG = 'nexley_form';

    /**
     * Register WordPress hooks.
     */
    public static function init(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'register_frontend_assets']);
    }

    /**
     * Register (do not enqueue yet) frontend assets.
     */
    public static function register_frontend_assets(): void {
        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';

        // 1. Base Stylesheet (wrapped in @layer nexley, zero specificity)
        wp_register_style(
            'nexley-forms-base',
            NEXLEY_FORMS_URL . 'assets/css/frontend-base' . $suffix . '.css',
            [],
            NEXLEY_FORMS_VERSION
        );

        // 2. Core Frontend Runtime JS (< 2 KB gzipped)
        wp_register_script(
            'nexley-forms-runtime',
            NEXLEY_FORMS_URL . 'assets/js/frontend' . $suffix . '.js',
            [],
            NEXLEY_FORMS_VERSION,
            true // Load in footer for optimal performance
        );

        wp_localize_script('nexley-forms-runtime', 'nexleyFormsConfig', [
            'endpoint' => esc_url_raw(rest_url('nexley/v1/submit')),
            'i18n'     => [
                'submitting'    => __('Sending...', 'nexley-forms'),
                'generic_error' => __('Something went wrong. Please check the fields and try again.', 'nexley-forms'),
                'network_error' => __('Network connection error. Please try again.', 'nexley-forms'),
            ],
        ]);

        // 3. Lazy Conditional Logic Module (< 1.5 KB gzipped)
        wp_register_script(
            'nexley-forms-conditional',
            NEXLEY_FORMS_URL . 'assets/js/frontend-conditional' . $suffix . '.js',
            ['nexley-forms-runtime'],
            NEXLEY_FORMS_VERSION,
            true
        );

        // 4. Lazy Multi-step Module (< 2 KB gzipped)
        wp_register_script(
            'nexley-forms-multistep',
            NEXLEY_FORMS_URL . 'assets/js/frontend-multistep' . $suffix . '.js',
            ['nexley-forms-runtime'],
            NEXLEY_FORMS_VERSION,
            true
        );
    }

    /**
     * Render the shortcode.
     *
     * @param array<string, mixed>|string $atts
     * @return string
     */
    public static function render_shortcode($atts): string {
        $parsed_atts = shortcode_atts([
            'id'         => 0,
            'preset'     => '',
            'style_mode' => '',
        ], (array) $atts, self::SHORTCODE_TAG);

        $form_id = absint($parsed_atts['id']);
        if ($form_id <= 0) {
            if (current_user_can('edit_posts')) {
                return '<div class="nexley-forms-error" style="border:1px solid #dc2626;padding:12px;color:#dc2626;border-radius:4px;">'
                    . esc_html__('[Nexley Forms]: Please specify a valid form ID (e.g. [nexley_form id="123"]).', 'nexley-forms')
                    . '</div>';
            }
            return '';
        }

        $form_post = get_post($form_id);
        if (!$form_post || $form_post->post_type !== Post_Types::CPT_FORM) {
            if (current_user_can('edit_posts')) {
                return '<div class="nexley-forms-error" style="border:1px solid #dc2626;padding:12px;color:#dc2626;border-radius:4px;">'
                    . sprintf(
                        /* translators: %d: Form ID number */
                        esc_html__('[Nexley Forms]: Form #%d was not found or is not published.', 'nexley-forms'),
                        $form_id
                    )
                    . '</div>';
            }
            return '';
        }

        $schema = Form_Schema::get_form_schema($form_id);

        if (!empty($parsed_atts['preset'])) {
            $schema['settings']['style_mode'] = 'preset';
            $schema['settings']['preset']     = sanitize_key($parsed_atts['preset']);
        } elseif (!empty($parsed_atts['style_mode'])) {
            $schema['settings']['style_mode'] = sanitize_key($parsed_atts['style_mode']);
        }

        // Render fully accessible form markup and conditionally enqueue only needed assets
        return Form_Renderer::render($schema);
    }
}
