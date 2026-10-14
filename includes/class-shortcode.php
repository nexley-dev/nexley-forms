<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the [nexley_form id="X"] shortcode, parsing, and conditional script enqueueing.
 * Strictly guarantees ZERO frontend CSS stylesheets are loaded.
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
     * Register (do not enqueue yet) frontend JavaScript.
     * Note: NO CSS is registered or enqueued here.
     */
    public static function register_frontend_assets(): void {
        wp_register_script(
            'nexley-forms-runtime',
            NEXLEY_FORMS_URL . 'assets/js/frontend.js',
            [],
            NEXLEY_FORMS_VERSION,
            true // Load in footer for optimal performance.
        );

        wp_localize_script('nexley-forms-runtime', 'nexleyFormsConfig', [
            'endpoint' => esc_url_raw(rest_url('nexley/v1/submit')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'i18n'     => [
                'submitting'    => __('Sending...', 'nexley-forms'),
                'generic_error' => __('Something went wrong. Please check the fields and try again.', 'nexley-forms'),
                'network_error' => __('Network error. Please try again.', 'nexley-forms'),
            ],
        ]);
    }

    /**
     * Render the shortcode.
     *
     * @param array<string, mixed>|string $atts
     * @return string
     */
    public static function render_shortcode($atts): string {
        $parsed_atts = shortcode_atts([
            'id' => 0,
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

        // Conditionally enqueue frontend JS ONLY when shortcode is actively being rendered!
        wp_enqueue_script('nexley-forms-runtime');

        // Render semantic, accessible form markup without any external CSS bloat.
        return Form_Parser::render($form_id);
    }
}
