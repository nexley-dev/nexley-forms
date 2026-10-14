<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\DB;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\CSV_Exporter;
use Nexley_Forms\Entries_Table;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 7 Automated Verification Script.
 * Verifies Submissions Management, Custom DB queries/filtering, Formula-Safe CSV Export, and List Table.
 */
class Phase7_Test {

    public static function run(): array {
        $results = [
            'phase'  => 7,
            'passed' => true,
            'checks' => [],
        ];

        // -------------------------------------------------------------
        // 1. Verify Formula-Safe CSV Escaping (Formula Injection / CWE-1236)
        // -------------------------------------------------------------
        $dangerous_vectors = [
            '=cmd|\' /C calc\'!A0' => "'=cmd|' /C calc'!A0",
            '+12345'              => "'+12345",
            '-9876'               => "'-9876",
            '@SUM(A1:A10)'        => "'@SUM(A1:A10)",
            "\tTabbedPrefix"      => "'\tTabbedPrefix",
            "\rCarriagePrefix"    => "'\rCarriagePrefix",
            'Safe String'         => 'Safe String',
            'user@example.com'    => 'user@example.com',
            ''                    => '',
        ];

        $escaping_passed = true;
        foreach ($dangerous_vectors as $input => $expected) {
            $escaped = CSV_Exporter::escape_formula($input);
            if ($escaped !== $expected) {
                $escaping_passed = false;
                break;
            }
        }
        $results['checks']['formula_injection_escaping_verified'] = $escaping_passed;

        // -------------------------------------------------------------
        // 2. Setup Mock Form and Entries in Dedicated DB Table
        // -------------------------------------------------------------
        $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admin_user)) {
            wp_set_current_user($admin_user[0]->ID);
        }

        $form_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => 'Entries Test Form',
            'post_status' => 'publish',
        ]);

        $mock_schema = [
            'version'  => 1,
            'id'       => $form_id,
            'title'    => 'Entries Test Form',
            'layout'   => ['columns' => 12],
            'fields'   => [
                ['id' => 'f1', 'name' => 'client_name', 'label' => 'Full Name', 'type' => 'text'],
                ['id' => 'f2', 'name' => 'client_email', 'label' => 'Email Address', 'type' => 'email'],
                ['id' => 'f3', 'name' => 'comment', 'label' => 'Comment', 'type' => 'textarea'],
            ],
            'rows'     => [],
            'settings' => [],
        ];
        Form_Schema::save_form_schema($form_id, $mock_schema);

        $entry1_id = DB::insert_entry([
            'form_id'    => $form_id,
            'status'     => 'unread',
            'data'       => [
                'client_name'  => 'Alice Smith',
                'client_email' => 'alice@example.com',
                'comment'      => 'Unique search term: ALPHA_NEEDLE',
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestBrowser/1.0',
            'created_at' => '2026-10-01 10:00:00',
        ]);

        $entry2_id = DB::insert_entry([
            'form_id'    => $form_id,
            'status'     => 'unread',
            'data'       => [
                'client_name'  => '=cmd|\' /C calc\'!A0', // Dangerous injection payload
                'client_email' => 'bob@example.com',
                'comment'      => 'Normal text',
            ],
            'ip_address' => '127.0.0.2',
            'user_agent' => 'TestBrowser/2.0',
            'created_at' => '2026-10-02 11:00:00',
        ]);

        $entry3_id = DB::insert_entry([
            'form_id'    => $form_id,
            'status'     => 'spam',
            'data'       => [
                'client_name'  => 'Spam Bot',
                'client_email' => 'spam@bot.net',
                'comment'      => 'Buy pharmaceuticals now',
            ],
            'ip_address' => '192.168.1.1',
            'user_agent' => 'SpamAgent/1.0',
            'created_at' => '2026-10-03 12:00:00',
        ]);

        $entry4_id = DB::insert_entry([
            'form_id'    => $form_id,
            'status'     => 'trash',
            'data'       => [
                'client_name'  => 'Deleted Lead',
                'client_email' => 'deleted@lead.com',
                'comment'      => 'Trash data',
            ],
            'ip_address' => '192.168.1.2',
            'user_agent' => 'TestBrowser/1.0',
            'created_at' => '2026-10-04 13:00:00',
        ]);

        // -------------------------------------------------------------
        // 3. Verify Status Counts and Filtering
        // -------------------------------------------------------------
        $counts = DB::get_status_counts($form_id);
        $counts_match = ($counts['unread'] === 2 && $counts['spam'] === 1 && $counts['trash'] === 1 && $counts['all'] === 3);
        $results['checks']['status_counts_match'] = $counts_match;

        // Query by search keyword
        $search_res = DB::get_entries(['form_id' => $form_id, 'search' => 'ALPHA_NEEDLE']);
        $search_passed = ($search_res['total'] === 1 && $search_res['entries'][0]->id === $entry1_id);
        $results['checks']['search_filtering_works'] = $search_passed;

        // Query by date range
        $date_res = DB::get_entries([
            'form_id'   => $form_id,
            'date_from' => '2026-10-01',
            'date_to'   => '2026-10-01',
        ]);
        $date_passed = ($date_res['total'] === 1 && $date_res['entries'][0]->id === $entry1_id);
        $results['checks']['date_range_filtering_works'] = $date_passed;

        // -------------------------------------------------------------
        // 4. Verify Batch Operations (Status update & Delete)
        // -------------------------------------------------------------
        $batch_updated = DB::update_entries_status([$entry1_id, $entry2_id], 'read');
        $after_counts = DB::get_status_counts($form_id);
        $batch_update_passed = ($batch_updated === 2 && $after_counts['read'] === 2 && $after_counts['unread'] === 0);
        $results['checks']['batch_status_update_works'] = $batch_update_passed;

        $batch_deleted = DB::delete_entries([$entry4_id]);
        $after_delete_entry = DB::get_entry($entry4_id);
        $batch_delete_passed = ($batch_deleted === 1 && $after_delete_entry === null);
        $results['checks']['batch_delete_works'] = $batch_delete_passed;

        // -------------------------------------------------------------
        // 5. Verify CSV Exporter Output, BOM, and Formula Escaping
        // -------------------------------------------------------------
        $stream = fopen('php://temp', 'r+');
        $rows_exported = CSV_Exporter::write_to_stream($stream, ['form_id' => $form_id]);
        rewind($stream);
        $csv_content = stream_get_contents($stream);
        fclose($stream);

        // Check UTF-8 BOM at start
        $has_bom = (substr($csv_content, 0, 3) === "\xEF\xBB\xBF");
        $results['checks']['csv_has_utf8_bom'] = $has_bom;

        // Check header row contains schema field labels
        $has_headers = (strpos($csv_content, 'Full Name') !== false && strpos($csv_content, 'Email Address') !== false);
        $results['checks']['csv_contains_form_labels'] = $has_headers;

        // Check dangerous payload was escaped in the CSV
        $has_escaped_formula = (strpos($csv_content, "'+cmd|") !== false || strpos($csv_content, "'=cmd|") !== false);
        $results['checks']['csv_escaped_dangerous_formula'] = $has_escaped_formula;
        $results['checks']['csv_rows_exported_count'] = ($rows_exported >= 2);

        // -------------------------------------------------------------
        // 6. Verify Entries_Table (WP_List_Table) Initialization
        // -------------------------------------------------------------
        $_GET['form_id'] = $form_id;
        $table = new Entries_Table();
        $table->prepare_items();

        $table_columns = $table->get_columns();
        $has_columns = isset($table_columns['cb'], $table_columns['id'], $table_columns['form'], $table_columns['data_preview'], $table_columns['status']);
        $has_items = !empty($table->items);
        $results['checks']['entries_list_table_rendered'] = ($has_columns && $has_items);

        // -------------------------------------------------------------
        // Cleanup Mock Post and Remaining Entries
        // -------------------------------------------------------------
        DB::delete_entries([$entry1_id, $entry2_id, $entry3_id]);
        wp_delete_post($form_id, true);
        unset($_GET['form_id']);

        // Check all passed
        foreach ($results['checks'] as $c) {
            if ($c !== true) {
                $results['passed'] = false;
                break;
            }
        }

        return $results;
    }
}

$res = Phase7_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
