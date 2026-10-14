<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Builder;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 6 Automated Verification Script.
 * Verifies Drag-and-Drop Builder UI markup, tabs, palette categories, canvas structure, and schema saving.
 */
class Phase6_Test {

    public static function run(): array {
        $results = [
            'phase'  => 6,
            'passed' => true,
            'checks' => [],
        ];

        // 1. Create mock form post
        $post_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => 'Builder Test Form',
            'post_status' => 'publish',
        ]);

        $post = get_post($post_id);

        // 2. Render Builder UI and inspect output markup
        ob_start();
        Builder::render($post);
        $ui_output = ob_get_clean();

        // Check Top-Level Tabs
        $has_build_tab    = (strpos($ui_output, 'data-tab="build"') !== false);
        $has_actions_tab  = (strpos($ui_output, 'data-tab="actions"') !== false);
        $has_settings_tab = (strpos($ui_output, 'data-tab="settings"') !== false);
        $has_preview_tab  = (strpos($ui_output, 'data-tab="preview"') !== false);

        $results['checks']['has_all_4_builder_tabs'] = ($has_build_tab && $has_actions_tab && $has_settings_tab && $has_preview_tab);

        // Check Palette and Categorization
        $has_core_cat       = (strpos($ui_output, 'data-category="core"') !== false);
        $has_structural_cat = (strpos($ui_output, 'data-category="structural"') !== false);
        $has_compound_cat   = (strpos($ui_output, 'data-category="compound"') !== false);
        $has_advanced_cat   = (strpos($ui_output, 'data-category="advanced"') !== false);

        $results['checks']['has_all_4_palette_categories'] = ($has_core_cat && $has_structural_cat && $has_compound_cat && $has_advanced_cat);

        // Check Canvas & Drawer
        $has_canvas = (strpos($ui_output, 'id="nexley-canvas-rows"') !== false);
        $has_drawer = (strpos($ui_output, 'id="nexley-field-drawer"') !== false);
        $has_copy_btn = (strpos($ui_output, 'nexley-copy-shortcode-btn') !== false);
        $has_save_btn = (strpos($ui_output, 'nexley-save-builder-btn') !== false);

        $results['checks']['has_canvas_and_settings_drawer'] = ($has_canvas && $has_drawer);
        $results['checks']['has_copy_and_save_controls']     = ($has_copy_btn && $has_save_btn);

        // 3. Test Builder Save Operation
        $mock_schema = [
            'version'  => 1,
            'id'       => $post_id,
            'title'    => 'Saved via Builder',
            'layout'   => ['columns' => 12],
            'fields'   => [
                [
                    'id'       => 'f_custom_1',
                    'type'     => 'text',
                    'name'     => 'custom_field',
                    'label'    => 'Custom Field',
                    'span'     => 6,
                    'required' => true,
                ],
            ],
            'rows'     => [
                [
                    'id'      => 'row_1',
                    'columns' => [
                        ['id' => 'col_1', 'span' => 6, 'fields' => ['f_custom_1']],
                    ],
                ],
            ],
            'settings' => ['style_mode' => 'preset', 'preset' => 'rounded'],
        ];

        // Set current user as administrator so capabilities pass
        $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admin_user)) {
            wp_set_current_user($admin_user[0]->ID);
        }

        $_POST[Builder::NONCE_NAME] = wp_create_nonce(Builder::NONCE_ACTION);
        $_POST['nexley_form_schema_json'] = wp_json_encode($mock_schema);

        Builder::save_form_builder_data($post_id, $post);

        $saved_schema = Form_Schema::get_form_schema($post_id);
        $save_succeeded = ($saved_schema['title'] === 'Builder Test Form' // title comes from post title
            && isset($saved_schema['fields'][0])
            && $saved_schema['fields'][0]['name'] === 'custom_field'
            && $saved_schema['settings']['preset'] === 'rounded');

        $results['checks']['builder_save_schema_succeeded'] = $save_succeeded;

        // Cleanup
        wp_delete_post($post_id, true);
        unset($_POST[Builder::NONCE_NAME], $_POST['nexley_form_schema_json']);

        // Overall check
        foreach ($results['checks'] as $c) {
            if ($c !== true) {
                $results['passed'] = false;
                break;
            }
        }

        return $results;
    }
}

$res = Phase6_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
