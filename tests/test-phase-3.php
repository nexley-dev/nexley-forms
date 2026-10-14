<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Form_Renderer;
use Nexley_Forms\Form_Schema;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 3 Automated Verification Script.
 * Verifies Form Renderer flat siblings, DOM element budget, BEM naming, styling modes, and CSS gzip budget.
 */
class Phase3_Test {

    public static function run(): array {
        $results = [
            'phase'  => 3,
            'passed' => true,
            'checks' => [],
            'metrics' => [],
        ];

        // 1. Measure Base CSS Gzip Size Budget (< 3 KB)
        $css_file = NEXLEY_FORMS_PATH . 'assets/css/frontend-base.css';
        $css_exists = file_exists($css_file);
        $css_raw_bytes = $css_exists ? filesize($css_file) : 0;
        $css_gz_bytes  = $css_exists ? strlen((string) gzencode((string) file_get_contents($css_file), 9)) : 0;

        $results['metrics']['base_css_raw_bytes'] = $css_raw_bytes;
        $results['metrics']['base_css_gz_bytes']  = $css_gz_bytes;
        $results['checks']['base_css_under_3kb_budget'] = ($css_gz_bytes > 0 && $css_gz_bytes < 3072);

        // 2. Test Form Render Output Structure
        $schema = Form_Schema::get_default_schema(101, 'Test Form 101');
        $html   = Form_Renderer::render($schema);

        // Check for no row or column wrapper divs
        $has_row_div = (bool) preg_match('/<div[^>]*class=["\'][^"\']*\b(row|col|column)\b/i', $html);
        $results['checks']['zero_row_column_wrapper_divs'] = !$has_row_div;

        // Check flat sibling fields with inline --span: N
        $has_span_style = (bool) preg_match('/style=["\'][^"\']*--span:\s*\d+/i', $html);
        $results['checks']['fields_have_inline_span_style'] = $has_span_style;

        // Check preservation of BEM class names: wrapper is nexley-forms__group ONLY, control is nexley-forms__field
        $has_bem_forms   = (strpos($html, 'class="nexley-forms') !== false);
        $has_bem_group   = (strpos($html, 'nexley-forms__group') !== false);
        $has_bem_field   = (strpos($html, 'nexley-forms__field') !== false);
        $has_bem_label   = (strpos($html, 'nexley-forms__label') !== false);
        $has_bem_control = (strpos($html, 'nexley-forms__control') !== false);
        $has_bem_submit  = (strpos($html, 'nexley-forms__submit') !== false);
        $has_bem_status  = (strpos($html, 'nexley-forms__status') !== false);
        $has_bem_hp      = (strpos($html, 'nexley-forms__hp') !== false);

        $wrapper_not_field = !preg_match('/<div[^>]*class=["\'][^"\']*\bnexley-forms__field\b[^"\']*["\'][^>]*style=["\'][^"\']*--span/i', $html);
        $control_has_field = (bool) preg_match('/<input[^>]*class=["\'][^"\']*\bnexley-forms__field\b[^"\']*\bnexley-forms__control\b/i', $html);

        $results['checks']['wrapper_is_group_only'] = $wrapper_not_field && $has_bem_group;
        $results['checks']['control_is_field_and_control'] = $control_has_field;
        $results['checks']['bem_classes_strictly_preserved'] = (
            $has_bem_forms && $has_bem_group && $has_bem_field && $has_bem_label &&
            $has_bem_control && $has_bem_submit && $has_bem_status && $has_bem_hp &&
            $wrapper_not_field && $control_has_field
        );

        // 3. Strict DOM Element Count Budget (Max 3 elements per field without hint)
        $single_field = [
            'id'          => 'f_test_count',
            'type'        => 'text',
            'name'        => 'test_count',
            'label'       => 'Test Field',
            'span'        => 6,
            'placeholder' => 'Enter text',
            'help_text'   => '', // No hint
        ];
        $field_html = Form_Renderer::render_single_field($single_field, 101);

        // Count opening HTML tags in field output
        preg_match_all('/<([a-z0-9]+)[\s>]/i', $field_html, $tag_matches);
        $dom_count = count($tag_matches[1] ?? []);
        $results['metrics']['single_field_dom_elements'] = $dom_count;
        $results['checks']['max_3_dom_elements_per_field'] = ($dom_count === 3); // wrapper, label, input

        // 4. Test Three Styling Modes
        // Mode A: Raw
        $raw_schema = $schema;
        $raw_schema['settings']['style_mode'] = 'raw';
        $raw_html = Form_Renderer::render($raw_schema);
        $results['checks']['raw_mode_class_applied'] = (strpos($raw_html, 'nexley-forms--raw') !== false);

        // Mode B: Base
        $base_schema = $schema;
        $base_schema['settings']['style_mode'] = 'base';
        $base_html = Form_Renderer::render($base_schema);
        $results['checks']['base_mode_class_applied'] = (strpos($base_html, 'nexley-forms--base') !== false);

        // Mode C: Preset Dark
        $preset_schema = $schema;
        $preset_schema['settings']['style_mode'] = 'preset';
        $preset_schema['settings']['preset']     = 'dark';
        $preset_html = Form_Renderer::render($preset_schema);
        $results['checks']['preset_dark_class_applied'] = (strpos($preset_html, 'nexley-forms--preset-dark') !== false);

        // 5. Test Progressive Enhancement & Time Trap
        $has_post_action   = (strpos($html, 'action="') !== false && strpos($html, 'admin-post.php') !== false);
        $has_rest_endpoint = (strpos($html, 'data-endpoint="') !== false && strpos($html, '/wp-json/nexley/v1/submit') !== false);
        $has_time_trap     = (strpos($html, 'name="_nexley_tt"') !== false);

        $results['checks']['progressive_enhancement_action_and_endpoint'] = ($has_post_action && $has_rest_endpoint);
        $results['checks']['cache_safe_time_trap_rendered'] = $has_time_trap;

        // Overall pass check
        foreach ($results['checks'] as $check) {
            if ($check !== true) {
                $results['passed'] = false;
                break;
            }
        }

        return $results;
    }
}

$res = Phase3_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
