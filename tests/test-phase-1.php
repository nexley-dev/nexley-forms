<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\DB;
use Nexley_Forms\Migration;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 1 Automated Verification Script.
 * Verifies custom table creation, index structure, batch legacy CPT migration, and idempotency.
 */
class Phase1_Test {

    public static function run(): array {
        global $wpdb;

        $results = [
            'phase'  => 1,
            'passed' => true,
            'checks' => [],
            'counts' => [],
        ];

        // 1. Check Table Exists
        DB::install_or_upgrade();
        $table = DB::get_table_name();
        $table_exists = (bool) $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        $results['checks']['table_exists'] = $table_exists;

        if (!$table_exists) {
            $results['passed'] = false;
            return $results;
        }

        // 2. Check Index Structure
        $indices = $wpdb->get_results("SHOW INDEX FROM {$table}");
        $index_keys = array_unique(array_map(static fn($i) => $i->Key_name, $indices));
        $has_composite = in_array('form_status_date', $index_keys, true);
        $has_legacy_unique = in_array('legacy_post_id', $index_keys, true);

        $results['checks']['has_composite_index_form_status_date'] = $has_composite;
        $results['checks']['has_unique_legacy_post_id']            = $has_legacy_unique;

        if (!$has_composite || !$has_legacy_unique) {
            $results['passed'] = false;
        }

        // 3. Setup Test Legacy CPT Posts
        $before_legacy_cpt_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s",
            Post_Types::CPT_ENTRY
        ));

        $before_custom_db_count = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table}");

        $created_legacy_ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $post_id = wp_insert_post([
                'post_type'    => Post_Types::CPT_ENTRY,
                'post_title'   => "Test Legacy Entry {$i}",
                'post_status'  => 'publish',
                'post_author'  => 1,
            ]);

            if ($post_id > 0) {
                update_post_meta($post_id, '_nexley_form_id', 999);
                update_post_meta($post_id, '_nexley_submitted_fields', [
                    'name'    => "Test User {$i}",
                    'email'   => "user{$i}@example.com",
                    'message' => "Legacy message content {$i}",
                ]);
                update_post_meta($post_id, '_nexley_user_ip', '192.168.1.' . (10 + $i));
                update_post_meta($post_id, '_nexley_user_agent', "Test-Agent-{$i}");
                update_post_meta($post_id, '_nexley_submission_time', '2026-01-01 12:00:0' . $i);
                $created_legacy_ids[] = $post_id;
            }
        }

        $mid_legacy_cpt_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s",
            Post_Types::CPT_ENTRY
        ));

        // 4. Run Migration
        $remaining = Migration::migrate_legacy_entries(100);
        $results['checks']['migration_remaining_zero'] = ($remaining === 0);

        $after_legacy_cpt_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s",
            Post_Types::CPT_ENTRY
        ));

        $after_custom_db_count = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table}");

        // 5. Verify Data Integrity
        $all_data_matched = true;
        foreach ($created_legacy_ids as $pid) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE legacy_post_id = %d", $pid));
            if (!$row) {
                $all_data_matched = false;
                break;
            }
            $data = json_decode((string) $row->data, true);
            if (!isset($data['name']) || (int) $row->form_id !== 999) {
                $all_data_matched = false;
                break;
            }
        }
        $results['checks']['migrated_data_integrity_verified'] = $all_data_matched;

        // 6. Test Idempotency: run migration again
        $second_run_remaining = Migration::migrate_legacy_entries(100);
        $idempotent_custom_db_count = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table}");
        $is_idempotent = ($idempotent_custom_db_count === $after_custom_db_count && $second_run_remaining === 0);
        $results['checks']['migration_idempotent'] = $is_idempotent;

        // 7. Cleanup mock test posts and migrated rows
        foreach ($created_legacy_ids as $pid) {
            wp_delete_post($pid, true);
            $wpdb->delete($table, ['legacy_post_id' => $pid], ['%d']);
        }

        $results['counts'] = [
            'legacy_cpt_before'       => $before_legacy_cpt_count,
            'legacy_cpt_with_fixtures' => $mid_legacy_cpt_count,
            'legacy_cpt_after_mig'    => $after_legacy_cpt_count,
            'custom_db_before'        => $before_custom_db_count,
            'custom_db_after_mig'     => $after_custom_db_count,
            'custom_db_after_repeat'  => $idempotent_custom_db_count,
        ];

        if (!$all_data_matched || !$is_idempotent) {
            $results['passed'] = false;
        }

        return $results;
    }
}

// Execute and output JSON
$res = Phase1_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
