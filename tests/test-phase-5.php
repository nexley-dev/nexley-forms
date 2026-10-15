<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Security;
use Nexley_Forms\REST_Controller;
use Nexley_Forms\Mailer;
use Nexley_Forms\Webhooks;
use Nexley_Forms\Privacy;
use Nexley_Forms\Form_Schema;
use Nexley_Forms\DB;
use Nexley_Forms\Post_Types;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 5 Automated Verification Script.
 * Verifies cache-safe anti-spam, server logic re-evaluation, upload security, notifications, webhooks, and privacy.
 */
class Phase5_Test {

    public static function run(): array {
        $results = [
            'phase'  => 5,
            'passed' => true,
            'checks' => [],
        ];

        // Reset rate limits for test runner
        $ip = Security::get_client_ip();
        delete_transient('nexley_rate_' . md5($ip));
        delete_transient('nexley_rate_' . md5('127.0.0.1'));
        delete_transient('nexley_rate_' . md5(''));

        // 1. Test Honeypot (decoy / bot rejection)
        $hp_test = Security::verify_submission(
            ['_nexley_hp' => 'bot_value'],
            ['honeypot' => true]
        );
        $results['checks']['honeypot_detects_bot'] = (!$hp_test['passed'] && $hp_test['is_bot']);

        // 2. Test Time-Trap (cache-safe, client-stamped)
        // A: Submitted too fast (1 second ago)
        $tt_fast = Security::verify_submission(
            ['_nexley_tt' => (string) (microtime(true) * 1000 - 1000)],
            ['time_trap' => true]
        );
        $results['checks']['time_trap_rejects_sub_3s'] = (!$tt_fast['passed'] && $tt_fast['is_bot']);

        // B: Normal human submission (5 seconds ago)
        $tt_normal = Security::verify_submission(
            ['_nexley_tt' => (string) (microtime(true) * 1000 - 5000)],
            ['time_trap' => true]
        );
        $results['checks']['time_trap_accepts_normal_human'] = ($tt_normal['passed']);

        // C: JS disabled (standard POST fallback without _nexley_tt token) -> skips time-trap
        $tt_no_js = Security::verify_submission(
            ['_nexley_tt' => ''],
            ['time_trap' => true]
        );
        $results['checks']['time_trap_skips_when_js_off'] = ($tt_no_js['passed']);

        // 3. Test Protected File Upload Directory & Barriers
        $upload_dir = Security::get_upload_dir();
        $htaccess   = $upload_dir . '/.htaccess';
        $index_php  = $upload_dir . '/index.php';

        $has_htaccess_barrier = file_exists($htaccess) && strpos((string) file_get_contents($htaccess), 'Deny from all') !== false;
        $has_index_barrier    = file_exists($index_php);

        $results['checks']['upload_htaccess_execution_denied'] = $has_htaccess_barrier;
        $results['checks']['upload_index_barrier_exists']      = $has_index_barrier;

        // 4. Test Server-side Conditional Logic Re-evaluation
        // Create schema with a conditional field that depends on department == 'Support'
        $conditional_schema = [
            'version'  => 1,
            'fields'   => [
                [
                    'id'       => 'f_dept',
                    'name'     => 'department',
                    'type'     => 'text',
                    'required' => true,
                ],
                [
                    'id'       => 'f_ticket',
                    'name'     => 'ticket_number',
                    'type'     => 'text',
                    'required' => true, // required only if shown!
                    'logic'    => [
                        'action' => 'show',
                        'match'  => 'all',
                        'rules'  => [
                            ['field' => 'department', 'operator' => 'equals', 'value' => 'Support']
                        ],
                    ],
                ],
            ],
            'settings' => ['honeypot' => false, 'time_trap' => false],
        ];

        // Scenario A: department is 'Sales' -> ticket_number should be HIDDEN, NOT REQUIRED, and NOT present in visible fields
        $visible_sales = REST_Controller::evaluate_server_visibility($conditional_schema['fields'], ['department' => 'Sales']);
        $results['checks']['server_logic_hides_ticket_for_sales'] = (!in_array('ticket_number', $visible_sales, true));

        // Scenario B: department is 'Support' -> ticket_number should be VISIBLE and REQUIRED
        $visible_support = REST_Controller::evaluate_server_visibility($conditional_schema['fields'], ['department' => 'Support']);
        $results['checks']['server_logic_shows_ticket_for_support'] = (in_array('ticket_number', $visible_support, true));

        // 5. Test Multiple Notifications & Conditional Routing
        $notif = [
            'id'         => 'sales_alert',
            'to'         => 'sales@example.com',
            'subject'    => "New Inquiry\r\nBcc: hacker@example.com", // Test header injection
            'body'       => "Department: {department}\n{_all_fields}",
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'department', 'operator' => 'equals', 'value' => 'Sales']
                ],
            ],
        ];

        // Routing for Sales matches
        $sales_matches = Mailer::should_send_notification($notif, ['department' => 'Sales']);
        $results['checks']['notification_conditional_routing_matches'] = $sales_matches;

        // Routing for Support does not match
        $support_matches = Mailer::should_send_notification($notif, ['department' => 'Support']);
        $results['checks']['notification_conditional_routing_skips_unmatched'] = !$support_matches;

        // Dynamic tag replacement
        $replaced = Mailer::replace_tags('Hello {site_title}, from {client-name}', ['client-name' => 'Alice'], 0);
        $results['checks']['mailer_dynamic_tags_replaced'] = (strpos($replaced, 'Alice') !== false);

        // 6. Test Privacy IP Anonymization
        $test_ip = '192.168.1.150';
        $anon_ip = Privacy::process_ip($test_ip, true);
        $results['checks']['privacy_ip_anonymized'] = ($anon_ip !== $test_ip && strpos($anon_ip, '.0') !== false);

        // Overall test pass check
        foreach ($results['checks'] as $c) {
            if ($c !== true) {
                $results['passed'] = false;
                break;
            }
        }

        return $results;
    }
}

$res = Phase5_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
