<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Field_Registry;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\Migration;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 2 Automated Verification Script.
 * Verifies Field Registry types, schema validation/normalization, layout rules, and legacy form migration.
 */
class Phase2_Test {

    public static function run(): array {
        Field_Registry::init();

        $results = [
            'phase'  => 2,
            'passed' => true,
            'checks' => [],
        ];

        // 1. Verify Field Types in Registry
        $types = Field_Registry::get_field_types();
        $expected_types = [
            'text', 'email', 'url', 'phone', 'number', 'textarea',
            'select', 'radio', 'checkbox', 'consent', 'date', 'hidden', 'submit',
            'heading', 'paragraph', 'divider', 'spacer', 'html',
            'name', 'address', 'file', 'range', 'rating', 'repeater'
        ];

        $missing_types = [];
        foreach ($expected_types as $t) {
            if (!isset($types[$t])) {
                $missing_types[] = $t;
            }
        }

        $results['checks']['all_24_field_types_registered'] = empty($missing_types);
        $results['checks']['missing_types'] = $missing_types;

        if (!empty($missing_types)) {
            $results['passed'] = false;
        }

        // 2. Test Layout Rule: Row span sum warning & nested columns disallow
        $test_schema_unbalanced = [
            'version' => 1,
            'layout'  => ['columns' => 12],
            'rows'    => [
                [
                    'id'      => 'row_1',
                    'columns' => [
                        ['id' => 'col_1', 'span' => 4, 'fields' => ['f1']],
                        ['id' => 'col_2', 'span' => 4, 'fields' => ['f2']], // Sum = 8 (remaining 4)
                    ],
                ],
            ],
            'fields'  => [
                ['id' => 'f1', 'type' => 'text', 'name' => 'f1'],
                ['id' => 'f2', 'type' => 'text', 'name' => 'f2'],
            ],
        ];

        $unbalanced_result = Form_Schema::validate_and_normalize($test_schema_unbalanced);
        $has_row_warning = !empty($unbalanced_result['warnings']) && strpos($unbalanced_result['warnings'][0], 'Remaining: 4') !== false;
        $results['checks']['row_remaining_width_warning_detected'] = $has_row_warning;

        // Disallowed nested columns
        $test_schema_nested = [
            'version' => 1,
            'layout'  => ['columns' => 12],
            'rows'    => [
                [
                    'id'      => 'row_nested',
                    'columns' => [
                        [
                            'id'      => 'col_outer',
                            'span'    => 12,
                            'columns' => [
                                ['id' => 'col_inner', 'span' => 6]
                            ],
                        ],
                    ],
                ],
            ],
            'fields'  => [],
        ];

        $nested_result = Form_Schema::validate_and_normalize($test_schema_nested);
        $has_nested_error = !empty($nested_result['errors']) && strpos($nested_result['errors'][0], 'nested columns') !== false;
        $results['checks']['disallow_nested_columns_detected'] = $has_nested_error;

        // 3. Test HTML Field Sanitization (wp_kses)
        $test_html_schema = [
            'version' => 1,
            'fields'  => [
                [
                    'id'      => 'f_html',
                    'type'    => 'html',
                    'content' => '<div>Safe</div><script>alert("xss")</script><p>Paragraph</p>',
                ],
            ],
        ];
        $html_result = Form_Schema::validate_and_normalize($test_html_schema);
        $cleaned_html = $html_result['schema']['fields'][0]['content'] ?? '';
        $html_sanitized = (strpos($cleaned_html, '<script>') === false && strpos($cleaned_html, 'Safe') !== false);
        $results['checks']['html_field_wp_kses_sanitized'] = $html_sanitized;

        // 4. Test Legacy Form Migration to Schema
        $legacy_form_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => 'Legacy Test Form',
            'post_status' => 'publish',
        ]);

        $legacy_template = "<label>Name\n[text* client-name placeholder \"Your Name\"]\n</label>\n"
            . "<label>Email\n[email* client-email placeholder \"Email\"]\n</label>\n"
            . "[submit \"Send Message\"]";

        update_post_meta($legacy_form_id, '_nexley_form_content', $legacy_template);
        update_post_meta($legacy_form_id, '_nexley_mail_to', 'admin@example.com');
        update_post_meta($legacy_form_id, '_nexley_mail_subject', 'New Contact Message');

        $migrated_count = Migration::migrate_legacy_forms();
        $results['checks']['forms_migrated_count_at_least_1'] = ($migrated_count >= 1);

        $saved_schema = get_post_meta($legacy_form_id, '_nexley_form_schema', true);
        $backup_content = get_post_meta($legacy_form_id, '_nexley_form_content_backup', true);

        $schema_valid = is_array($saved_schema)
            && isset($saved_schema['fields'])
            && count($saved_schema['fields']) === 3 // name, email, submit
            && $saved_schema['fields'][0]['name'] === 'client-name'
            && $saved_schema['fields'][1]['name'] === 'client-email'
            && $saved_schema['fields'][2]['type'] === 'submit';

        $backup_valid = ($backup_content === $legacy_template);

        $results['checks']['legacy_form_converted_to_schema'] = $schema_valid;
        $results['checks']['legacy_template_backup_preserved'] = $backup_valid;

        // Idempotency: second run shouldn't re-migrate
        $second_migrated_count = Migration::migrate_legacy_forms();
        $results['checks']['migration_idempotent_zero_on_repeat'] = ($second_migrated_count === 0);

        // Cleanup
        wp_delete_post($legacy_form_id, true);

        if (!$has_row_warning || !$has_nested_error || !$html_sanitized || !$schema_valid || !$backup_valid) {
            $results['passed'] = false;
        }

        return $results;
    }
}

$res = Phase2_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
