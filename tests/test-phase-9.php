<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Block;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 9 Automated Verification Script.
 * Verifies Gutenberg Dynamic Block registration, server-side render callback, and fallback handling.
 */
class Phase9_Test {

    public static function run(): array {
        $results = [
            'phase'  => 9,
            'passed' => true,
            'checks' => [],
        ];

        // 1. Verify Block Registration
        $registry = \WP_Block_Type_Registry::get_instance();
        $is_registered = $registry->is_registered(Block::BLOCK_NAME);
        $results['checks']['block_is_registered'] = $is_registered;

        if ($is_registered) {
            $block_type = $registry->get_registered(Block::BLOCK_NAME);
            $has_attributes = isset(
                $block_type->attributes['formId'],
                $block_type->attributes['displayTitle'],
                $block_type->attributes['stylePreset']
            );
            $results['checks']['block_has_required_attributes'] = $has_attributes;
        } else {
            $results['checks']['block_has_required_attributes'] = false;
        }

        // 2. Create Mock Form for Rendering Verification
        $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admin_user)) {
            wp_set_current_user($admin_user[0]->ID);
        }

        $form_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => 'Block Test Form',
            'post_status' => 'publish',
        ]);

        $mock_schema = [
            'version'  => 1,
            'id'       => $form_id,
            'title'    => 'Block Test Form',
            'layout'   => ['columns' => 12, 'breakpoint' => 640],
            'fields'   => [
                ['id' => 'f_name', 'name' => 'client_name', 'label' => 'Full Name', 'type' => 'text', 'span' => 12, 'required' => true],
                ['id' => 'f_submit', 'name' => 'submit_btn', 'label' => '', 'type' => 'submit', 'button_text' => 'Submit Block Form', 'span' => 12],
            ],
            'rows'     => [
                [
                    'id'      => 'row_1',
                    'columns' => [
                        ['id' => 'c1', 'span' => 12, 'fields' => ['f_name']],
                    ],
                ],
                [
                    'id'      => 'row_2',
                    'columns' => [
                        ['id' => 'c2', 'span' => 12, 'fields' => ['f_submit']],
                    ],
                ],
            ],
            'settings' => ['style_mode' => 'preset', 'preset' => 'rounded'],
        ];
        Form_Schema::save_form_schema($form_id, $mock_schema);

        // 3. Test Block Rendering with Valid Form ID
        $render_output = Block::render_block([
            'formId'       => $form_id,
            'displayTitle' => true,
        ]);

        $renders_form = (
            strpos($render_output, '<form') !== false &&
            strpos($render_output, 'nexley-forms') !== false &&
            strpos($render_output, 'client_name') !== false &&
            strpos($render_output, 'Block Test Form') !== false
        );
        $results['checks']['block_renders_form_markup'] = $renders_form;

        // 4. Test Preset Override
        $dark_render = Block::render_block([
            'formId'      => $form_id,
            'stylePreset' => 'dark',
        ]);
        $has_dark_preset = (strpos($dark_render, 'nexley-forms--preset-dark') !== false);
        $results['checks']['block_supports_preset_override'] = $has_dark_preset;

        // 5. Test Fallbacks for Empty or Invalid Form IDs
        $empty_render = Block::render_block(['formId' => 0]);
        $invalid_render = Block::render_block(['formId' => 9999999]);

        $safe_fallbacks = ($empty_render === '' && $invalid_render === '');
        $results['checks']['block_handles_invalid_ids_safely'] = $safe_fallbacks;

        // Cleanup
        wp_delete_post($form_id, true);

        // Check overall pass
        foreach ($results['checks'] as $c) {
            if ($c !== true) {
                $results['passed'] = false;
                break;
            }
        }

        return $results;
    }
}

$res = Phase9_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
