<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\DB;
use Nexley_Forms\Form_Renderer;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\Importer_Exporter;
use Nexley_Forms\Mailer;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 8 Automated Verification Script.
 * Verifies Native .nex envelope round-trip, third-party importer adapters (NF, GF, CF7),
 * status label compliance ("Documented, unverified"), and shortcode replacer with rollback.
 */
class Phase8_Test {

    public static function run(): array {
        $results = [
            'phase'  => 8,
            'passed' => true,
            'checks' => [],
        ];

        // Set admin user for capability checks
        $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admin_user)) {
            wp_set_current_user($admin_user[0]->ID);
        }

        // -------------------------------------------------------------
        // 1. Verify Importer Verification Status Labels
        // -------------------------------------------------------------
        $importers = Importer_Exporter::get_importers();

        $nf_status  = $importers['ninja-forms']->get_verification_status();
        $gf_status  = $importers['gravity-forms']->get_verification_status();
        $cf7_status = $importers['cf7']->get_verification_status();

        $status_labels_correct = (
            strpos($nf_status, 'unverified') !== false &&
            $gf_status === 'Documented, unverified' &&
            $cf7_status === 'Direct source code inspected'
        );
        $results['checks']['status_labels_compliant'] = $status_labels_correct;

        // -------------------------------------------------------------
        // 2. Verify Native .nex Envelope Export & Import Round-Trip
        // -------------------------------------------------------------
        $orig_form_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => 'Native Nex Test Form',
            'post_status' => 'publish',
        ]);

        $orig_schema = [
            'version'  => 1,
            'id'       => $orig_form_id,
            'title'    => 'Native Nex Test Form',
            'layout'   => ['columns' => 12, 'breakpoint' => 640],
            'fields'   => [
                ['id' => 'f_name', 'name' => 'user_name', 'label' => 'Your Name', 'type' => 'text', 'span' => 6],
                ['id' => 'f_email', 'name' => 'user_email', 'label' => 'Your Email', 'type' => 'email', 'span' => 6],
            ],
            'rows'     => [
                [
                    'id'      => 'row_1',
                    'columns' => [
                        ['id' => 'c1', 'span' => 6, 'fields' => ['f_name']],
                        ['id' => 'c2', 'span' => 6, 'fields' => ['f_email']],
                    ],
                ],
            ],
            'settings' => ['style_mode' => 'preset', 'preset' => 'outlined'],
        ];
        Form_Schema::save_form_schema($orig_form_id, $orig_schema);

        // Export .nex envelope
        $nex_json = Importer_Exporter::export_form_nex($orig_form_id);
        $decoded_envelope = json_decode($nex_json, true);

        $envelope_valid = (
            is_array($decoded_envelope) &&
            ($decoded_envelope['format'] ?? '') === 'nexley-form' &&
            ($decoded_envelope['schema'] ?? 0) === 1 &&
            isset($decoded_envelope['form']['fields'][1]['name']) &&
            $decoded_envelope['form']['fields'][1]['name'] === 'user_email'
        );
        $results['checks']['nex_envelope_export_valid'] = $envelope_valid;

        // Import .nex envelope into new form
        $imported_form_id = Importer_Exporter::import_form_nex($nex_json);
        $imported_schema  = Form_Schema::get_form_schema($imported_form_id);

        $import_successful = (
            $imported_form_id > 0 &&
            $imported_form_id !== $orig_form_id &&
            strpos($imported_schema['title'], 'Native Nex Test Form') === 0 &&
            count($imported_schema['fields']) === 2 &&
            $imported_schema['settings']['preset'] === 'outlined'
        );
        $results['checks']['nex_envelope_import_roundtrip'] = $import_successful;

        // -------------------------------------------------------------
        // 3. Verify Ninja Forms Conversion
        // -------------------------------------------------------------
        $nf_fixture_path = __DIR__ . '/fixtures/fixture-ninja-forms.json';
        $nf_json = file_get_contents($nf_fixture_path);
        $nf_schema = $importers['ninja-forms']->convert($nf_json);

        $nf_fields_ok = (
            $nf_schema['title'] === 'Ninja Test Contact Form' &&
            count($nf_schema['fields']) === 6 &&
            $nf_schema['fields'][0]['type'] === 'text' &&
            $nf_schema['fields'][2]['type'] === 'email' &&
            $nf_schema['fields'][3]['type'] === 'select' &&
            count($nf_schema['fields'][3]['options']) === 2 &&
            $nf_schema['fields'][4]['type'] === 'textarea' &&
            $nf_schema['fields'][5]['type'] === 'submit'
        );

        $nf_actions_ok = (
            !empty($nf_schema['notifications']) &&
            strpos($nf_schema['notifications'][0]['message'], '{first_name}') !== false &&
            $nf_schema['notifications'][0]['reply_to'] === '{email_address}' &&
            !empty($nf_schema['confirmations'])
        );

        // Verify Real Ninja Forms .nff Export File Conversion
        $nf_real_path = __DIR__ . '/fixtures/fixture-ninja-forms-real.nff';
        $nf_real_content = file_get_contents($nf_real_path);
        $nf_real_schema = $importers['ninja-forms']->convert($nf_real_content);

        $real_rows_sum_12 = true;
        foreach ($nf_real_schema['rows'] as $r) {
            $sum = array_sum(array_column($r['columns'], 'span'));
            if ($sum !== 12) {
                $real_rows_sum_12 = false;
                break;
            }
        }

        $real_spans_ok = (
            count($nf_real_schema['rows']) === 5 &&
            $nf_real_schema['rows'][0]['columns'][0]['span'] === 6 &&
            $nf_real_schema['rows'][0]['columns'][1]['span'] === 6 &&
            $nf_real_schema['rows'][1]['columns'][0]['span'] === 6 &&
            $nf_real_schema['rows'][1]['columns'][1]['span'] === 6 &&
            $nf_real_schema['rows'][2]['columns'][0]['span'] === 6 &&
            $nf_real_schema['rows'][2]['columns'][1]['span'] === 6 &&
            $nf_real_schema['rows'][3]['columns'][0]['span'] === 12 &&
            $nf_real_schema['rows'][4]['columns'][0]['span'] === 12
        );

        $real_fields_ok = (
            $nf_real_schema['title'] === 'Contact page form' &&
            count($nf_real_schema['fields']) === 8 &&
            // Rule 2: Cleaned labels (leading *, trailing :, surrounding whitespace removed)
            $nf_real_schema['fields'][0]['label'] === 'First Name' &&
            $nf_real_schema['fields'][0]['type'] === 'text' &&
            $nf_real_schema['fields'][0]['name'] === 'fname' &&
            $nf_real_schema['fields'][0]['required'] === true &&
            $nf_real_schema['fields'][1]['label'] === 'Last Name' &&
            $nf_real_schema['fields'][1]['type'] === 'text' &&
            $nf_real_schema['fields'][1]['name'] === 'lname' &&
            $nf_real_schema['fields'][2]['label'] === 'Company' &&
            $nf_real_schema['fields'][3]['label'] === 'Email' &&
            $nf_real_schema['fields'][3]['type'] === 'email' &&
            $nf_real_schema['fields'][3]['name'] === 'email' &&
            $nf_real_schema['fields'][4]['label'] === 'Phone Number' &&
            $nf_real_schema['fields'][4]['type'] === 'phone' &&
            $nf_real_schema['fields'][5]['label'] === 'Message' &&
            $nf_real_schema['fields'][5]['type'] === 'textarea' &&
            // Rule 5: Single checkbox mapped to consent
            $nf_real_schema['fields'][6]['type'] === 'consent' &&
            $nf_real_schema['fields'][6]['label'] === 'Keep me in the loop with EO product new, launches and events' &&
            $nf_real_schema['fields'][7]['type'] === 'submit'
        );

        // Rule 4: Verify settings mapped vs reported skipped
        $settings_reported_ok = (
            $nf_real_schema['settings']['show_title'] === true &&
            !empty($nf_real_schema['skipped_settings']) &&
            !empty($nf_real_schema['handled_actions']) &&
            $nf_real_schema['handled_actions'][0]['type'] === 'save' &&
            $nf_real_schema['handled_actions'][0]['status'] === 'handled natively'
        );

        $real_actions_ok = (
            !empty($nf_real_schema['notifications']) &&
            $nf_real_schema['notifications'][0]['to'] === '{admin_email}' &&
            $nf_real_schema['notifications'][0]['message'] === '{_all_fields}' &&
            !empty($nf_real_schema['confirmations'])
        );

        // Rule 5 Verification: Single checkbox renders as ONE checkbox with its label, NOT a group
        $rendered_checkbox = Form_Renderer::render_single_field($nf_real_schema['fields'][6], 9999);
        $single_checkbox_renders_ok = (
            strpos($rendered_checkbox, 'type="checkbox"') !== false &&
            strpos($rendered_checkbox, '<fieldset') === false &&
            strpos($rendered_checkbox, '<legend') === false &&
            strpos($rendered_checkbox, 'Keep me in the loop with EO product new, launches and events') !== false &&
            substr_count($rendered_checkbox, '<input') === 1
        );

        // Rule 3: Largest-Remainder Rounding Algorithm Tests (Hare-Niemeyer)
        // Case A: 33/33/33
        $spans_33 = \Nexley_Forms\Importers\Importer_Ninja_Forms::allocate_row_spans([
            ['width' => 33.33],
            ['width' => 33.33],
            ['width' => 33.33],
        ]);
        $spans_33_ok = ($spans_33 === [4, 4, 4] && array_sum($spans_33) === 12);

        // Case B: 25/25/50
        $spans_25_50 = \Nexley_Forms\Importers\Importer_Ninja_Forms::allocate_row_spans([
            ['width' => 25],
            ['width' => 25],
            ['width' => 50],
        ]);
        $spans_25_50_ok = ($spans_25_50 === [3, 3, 6] && array_sum($spans_25_50) === 12);

        // Case C: 20x5 (five columns with 20 width)
        $spans_20x5 = \Nexley_Forms\Importers\Importer_Ninja_Forms::allocate_row_spans([
            ['width' => 20],
            ['width' => 20],
            ['width' => 20],
            ['width' => 20],
            ['width' => 20],
        ]);
        $spans_20x5_ok = ($spans_20x5 === [3, 3, 2, 2, 2] && array_sum($spans_20x5) === 12);

        // Case D: String "100"
        $spans_str100 = \Nexley_Forms\Importers\Importer_Ninja_Forms::allocate_row_spans([
            ['width' => '100'],
        ]);
        $spans_str100_ok = ($spans_str100 === [12] && array_sum($spans_str100) === 12);

        // Case E: Multi-field cell conversion
        $multi_cell_data = [
            'settings' => [
                'title' => 'Multi Cell Form',
                'formContentData' => [
                    [
                        'order' => 1,
                        'cells' => [
                            ['order' => 0, 'width' => 50, 'fields' => ['f1', 'f2']],
                            ['order' => 1, 'width' => 50, 'fields' => ['f3']],
                        ],
                    ],
                ],
            ],
            'fields' => [
                ['key' => 'f1', 'type' => 'textbox', 'label' => 'First in Cell'],
                ['key' => 'f2', 'type' => 'textbox', 'label' => 'Second in Cell'],
                ['key' => 'f3', 'type' => 'textbox', 'label' => 'Right Cell'],
            ],
        ];
        $multi_cell_schema = $importers['ninja-forms']->convert($multi_cell_data);
        $multi_cell_ok = (
            count($multi_cell_schema['rows']) === 1 &&
            count($multi_cell_schema['rows'][0]['columns']) === 2 &&
            $multi_cell_schema['rows'][0]['columns'][0]['span'] === 6 &&
            $multi_cell_schema['rows'][0]['columns'][0]['fields'] === ['f_f1', 'f_f2'] &&
            $multi_cell_schema['rows'][0]['columns'][1]['span'] === 6 &&
            $multi_cell_schema['rows'][0]['columns'][1]['fields'] === ['f_f3'] &&
            $multi_cell_schema['fields'][0]['span'] === 6 &&
            $multi_cell_schema['fields'][1]['span'] === 6 &&
            $multi_cell_schema['fields'][2]['span'] === 6 &&
            (array_sum(array_column($multi_cell_schema['rows'][0]['columns'], 'span')) === 12)
        );

        $results['checks']['largest_remainder_layout_verified'] = (
            $spans_33_ok &&
            $spans_25_50_ok &&
            $spans_20x5_ok &&
            $spans_str100_ok &&
            $multi_cell_ok
        );

        // Rule 1: Merge Tag Tests ({admin_email}, filter, unknown tags)
        $expected_admin_email = (string) get_option('admin_email');
        $resolved_to = Mailer::replace_tags('{admin_email}', ['fname' => 'Alice'], 0);
        $resolved_subject = Mailer::replace_tags('New lead for {admin_email}', ['fname' => 'Alice'], 0);
        $resolved_body = Mailer::replace_tags('Hello {fname}, contact {admin_email}. Unknown: {some_mystery_tag}', ['fname' => 'Alice'], 0);

        // Custom filter test: nexley_forms_merge_tags
        add_filter('nexley_forms_merge_tags', function($tags) {
            $tags['{custom_hook_tag}'] = 'HookResolvedValue';
            return $tags;
        });
        $resolved_hook_tag = Mailer::replace_tags('Tag: {custom_hook_tag}', [], 0);

        // Ensure {admin_email} NEVER leaks into rendered form HTML
        $rendered_form_html = Form_Renderer::render($nf_real_schema, 8888);
        $admin_email_absent_from_render = (strpos($rendered_form_html, $expected_admin_email) === false);

        $results['checks']['merge_tags_admin_email_and_filter_verified'] = (
            $resolved_to === $expected_admin_email &&
            $resolved_subject === 'New lead for ' . $expected_admin_email &&
            strpos($resolved_body, $expected_admin_email) !== false &&
            strpos($resolved_body, 'Unknown: ') !== false &&
            strpos($resolved_body, '{some_mystery_tag}') === false && // Unknown tags resolved to empty string
            $resolved_hook_tag === 'Tag: HookResolvedValue' &&
            $admin_email_absent_from_render
        );

        // Rule 6: Unique Custom Names and merge tag translation test
        $dup_names_data = [
            'settings' => ['title' => 'Duplicate Names Form'],
            'fields' => [
                ['key' => 'f_a', 'type' => 'textbox', 'label' => 'First', 'custom_name_attribute' => 'my_name'],
                ['key' => 'f_b', 'type' => 'textbox', 'label' => 'Second', 'custom_name_attribute' => 'my_name'],
            ],
            'actions' => [
                [
                    'type' => 'email',
                    'to' => '{wp:admin_email}',
                    'email_subject' => 'Hello {field:f_a}',
                    'email_message' => 'First: {field:my_name}, Second: {field:f_b}',
                ],
            ],
        ];
        $dup_schema = $importers['ninja-forms']->convert($dup_names_data);
        $dup_names_ok = (
            $dup_schema['fields'][0]['name'] === 'my_name' &&
            $dup_schema['fields'][1]['name'] === 'my_name_2' &&
            $dup_schema['notifications'][0]['to'] === '{admin_email}' &&
            $dup_schema['notifications'][0]['subject'] === 'Hello {my_name}' &&
            $dup_schema['notifications'][0]['message'] === 'First: {my_name}, Second: {my_name_2}'
        );
        $results['checks']['custom_names_uniqueness_and_merge_tags_verified'] = $dup_names_ok;

        $real_import_id = Importer_Exporter::import_from_importer('ninja-forms', $nf_real_content);
        $real_imported_title = get_the_title($real_import_id);
        $real_title_ok = (strpos($real_imported_title, 'Contact page form (Ninja Forms)') === 0);
        if ($real_import_id > 0) {
            wp_delete_post($real_import_id, true);
        }

        $results['checks']['ninja_forms_conversion_verified'] = (
            $nf_fields_ok &&
            $nf_actions_ok &&
            $real_rows_sum_12 &&
            $real_spans_ok &&
            $real_fields_ok &&
            $real_actions_ok &&
            $settings_reported_ok &&
            $single_checkbox_renders_ok &&
            $real_title_ok
        );

        // -------------------------------------------------------------
        // 4. Verify Gravity Forms Conversion & Grid Span Mapping
        // -------------------------------------------------------------
        $gf_fixture_path = __DIR__ . '/fixtures/fixture-gravity-forms.json';
        $gf_json = file_get_contents($gf_fixture_path);
        $gf_schema = $importers['gravity-forms']->convert($gf_json);

        $gf_spans_ok = (
            $gf_schema['title'] === 'Gravity Forms Test Contact' &&
            $gf_schema['fields'][0]['span'] === 6 &&
            $gf_schema['fields'][1]['span'] === 6 &&
            $gf_schema['fields'][2]['span'] === 12
        );

        // Row reconstruction: row 1 must have 2 cols (span 6 + 6), row 2 has col span 12
        $gf_rows_ok = (
            isset($gf_schema['rows'][0]['columns'][0]['span'], $gf_schema['rows'][0]['columns'][1]['span']) &&
            $gf_schema['rows'][0]['columns'][0]['span'] === 6 &&
            $gf_schema['rows'][0]['columns'][1]['span'] === 6 &&
            $gf_schema['rows'][1]['columns'][0]['span'] === 12
        );

        $gf_notif_ok = (
            !empty($gf_schema['notifications']) &&
            $gf_schema['notifications'][0]['reply_to'] === '{email_address}' &&
            !empty($gf_schema['confirmations'])
        );
        $results['checks']['gravity_forms_conversion_and_spans_verified'] = ($gf_spans_ok && $gf_rows_ok && $gf_notif_ok);

        // -------------------------------------------------------------
        // 5. Verify Contact Form 7 Conversion
        // -------------------------------------------------------------
        $cf7_fixture_path = __DIR__ . '/fixtures/fixture-cf7.txt';
        $cf7_template = file_get_contents($cf7_fixture_path);
        $cf7_schema = $importers['cf7']->convert([
            'title'    => 'CF7 Inquiries',
            'template' => $cf7_template,
            'mail'     => [
                'recipient'          => 'leads@company.com',
                'subject'            => 'Website lead from [client-name]',
                'body'               => 'Message from [client-name]: [client-message]',
                'additional_headers' => 'Reply-To: [client-email]',
            ],
        ]);

        $cf7_fields_ok = (
            $cf7_schema['title'] === 'CF7 Inquiries' &&
            count($cf7_schema['fields']) === 5 &&
            $cf7_schema['fields'][0]['name'] === 'client-name' &&
            $cf7_schema['fields'][0]['required'] === true &&
            $cf7_schema['fields'][1]['type'] === 'email' &&
            $cf7_schema['fields'][2]['type'] === 'select' &&
            count($cf7_schema['fields'][2]['options']) === 3 &&
            $cf7_schema['fields'][3]['type'] === 'textarea' &&
            $cf7_schema['fields'][4]['type'] === 'submit' &&
            $cf7_schema['fields'][4]['button_text'] === 'Transmit Inquiry'
        );

        $cf7_mail_ok = (
            !empty($cf7_schema['notifications']) &&
            $cf7_schema['notifications'][0]['reply_to'] === '{client-email}' &&
            strpos($cf7_schema['notifications'][0]['message'], '{client-name}') !== false
        );
        $results['checks']['cf7_conversion_verified'] = ($cf7_fields_ok && $cf7_mail_ok);

        // -------------------------------------------------------------
        // 6. Verify Shortcode Replacer and Rollback
        // -------------------------------------------------------------
        $test_page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_title'   => 'Contact Landing Page',
            'post_content' => '<p>Please reach out below:</p>[ninja_form id=42]<p>Footer info</p>',
            'post_status'  => 'publish',
        ]);

        $found_shortcodes = Importer_Exporter::find_shortcodes();
        $target_found = false;
        foreach ($found_shortcodes as $sc) {
            if ($sc['post_id'] === $test_page_id && $sc['shortcode'] === '[ninja_form id=42]') {
                $target_found = true;
                break;
            }
        }
        $results['checks']['shortcode_scanner_detected'] = $target_found;

        // Perform replacement
        $replacement_success = Importer_Exporter::replace_shortcode($test_page_id, '[ninja_form id=42]', $imported_form_id);
        $updated_page = get_post($test_page_id);
        $expected_sc = sprintf('[nexley_form id="%d"]', $imported_form_id);
        $replaced_ok = ($replacement_success && strpos($updated_page->post_content, $expected_sc) !== false);
        $results['checks']['shortcode_replaced_with_backup'] = $replaced_ok;

        // Rollback replacement
        $rollback_success = Importer_Exporter::rollback_shortcode_replacement($test_page_id);
        $reverted_page = get_post($test_page_id);
        $rollback_ok = ($rollback_success && strpos($reverted_page->post_content, '[ninja_form id=42]') !== false);
        $results['checks']['shortcode_rollback_verified'] = $rollback_ok;

        // -------------------------------------------------------------
        // Cleanup Mock Posts
        // -------------------------------------------------------------
        wp_delete_post($orig_form_id, true);
        wp_delete_post($imported_form_id, true);
        wp_delete_post($test_page_id, true);

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

$res = Phase8_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
