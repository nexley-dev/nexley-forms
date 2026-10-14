<?php
namespace Nexley_Forms\Tests;

use Nexley_Forms\Shortcode;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Phase 4 Automated Verification Script.
 * Verifies frontend modular scripts registration, gzip budgets, and zero framework dependencies.
 */
class Phase4_Test {

    public static function run(): array {
        Shortcode::init();
        Shortcode::register_frontend_assets();

        $results = [
            'phase'   => 4,
            'passed'  => true,
            'checks'  => [],
            'metrics' => [],
        ];

        // 1. Verify script handles registered with WordPress
        $results['checks']['runtime_script_registered']     = wp_script_is('nexley-forms-runtime', 'registered');
        $results['checks']['conditional_script_registered'] = wp_script_is('nexley-forms-conditional', 'registered');
        $results['checks']['multistep_script_registered']   = wp_script_is('nexley-forms-multistep', 'registered');

        // 2. Measure Gzipped Sizes
        $files = [
            'core_js'        => [NEXLEY_FORMS_PATH . 'assets/js/frontend.js', 2048],
            'conditional_js' => [NEXLEY_FORMS_PATH . 'assets/js/frontend-conditional.js', 1536],
            'multistep_js'   => [NEXLEY_FORMS_PATH . 'assets/js/frontend-multistep.js', 2048],
        ];

        foreach ($files as $key => [$path, $limit]) {
            $content = (string) file_get_contents($path);
            $gz_size = strlen((string) gzencode($content, 9));
            $results['metrics'][$key . '_gz_bytes'] = $gz_size;
            $results['checks'][$key . '_within_budget'] = ($gz_size <= $limit);

            // Framework dependency check
            $has_jquery = (bool) preg_match('/\b(jQuery|\$\.ajax|React|Vue)\b/', $content);
            $results['checks'][$key . '_zero_jquery'] = !$has_jquery;

            if ($gz_size > $limit || $has_jquery) {
                $results['passed'] = false;
            }
        }

        return $results;
    }
}

$res = Phase4_Test::run();
echo wp_json_encode($res, JSON_PRETTY_PRINT) . "\n";
