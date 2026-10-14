<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\DB;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\Importer_Exporter;
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
            $nf_status === 'Documented, unverified' &&
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
            $imported_schema['title'] === 'Native Nex Test Form' &&
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
        $results['checks']['ninja_forms_conversion_verified'] = ($nf_fields_ok && $nf_actions_ok);

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
