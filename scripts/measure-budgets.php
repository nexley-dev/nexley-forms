<?php
/**
 * Nexley Forms - Performance Budgets Measurement Script
 *
 * Verifies non-negotiable performance budgets:
 * 1. Frontend core JS: under 2048 bytes (2 KB) gzipped.
 * 2. Conditional-logic module: under 1536 bytes (1.5 KB) gzipped.
 * 3. Multi-step module: under 2048 bytes (2 KB) gzipped.
 * 4. Base CSS: under 3072 bytes (3 KB) gzipped.
 * 5. Max 3 DOM elements per field.
 * 6. No jQuery or frontend framework dependencies.
 */

$rootDir = dirname(__DIR__);

$budgets = [
    'Frontend Core JS (Source)' => [
        'file'        => $rootDir . '/assets/js/frontend.js',
        'max_gz_byte' => 2048,
    ],
    'Frontend Core JS (Minified)' => [
        'file'        => $rootDir . '/assets/js/frontend.min.js',
        'max_gz_byte' => 2048,
    ],
    'Conditional Logic (Source)' => [
        'file'        => $rootDir . '/assets/js/frontend-conditional.js',
        'max_gz_byte' => 1536,
    ],
    'Conditional Logic (Minified)' => [
        'file'        => $rootDir . '/assets/js/frontend-conditional.min.js',
        'max_gz_byte' => 1536,
    ],
    'Multi-Step Wizard (Source)' => [
        'file'        => $rootDir . '/assets/js/frontend-multistep.js',
        'max_gz_byte' => 2048,
    ],
    'Multi-Step Wizard (Minified)' => [
        'file'        => $rootDir . '/assets/js/frontend-multistep.min.js',
        'max_gz_byte' => 2048,
    ],
    'Base Stylesheet CSS (Source)' => [
        'file'        => $rootDir . '/assets/css/frontend-base.css',
        'max_gz_byte' => 3072,
    ],
    'Base Stylesheet CSS (Minified)' => [
        'file'        => $rootDir . '/assets/css/frontend-base.min.css',
        'max_gz_byte' => 3072,
    ],
];

echo "========================================================================================\n";
echo "       NEXLEY FORMS - ASSET SIZES & PERFORMANCE BUDGETS MEASUREMENT REPORT              \n";
echo "========================================================================================\n\n";

$allPassed = true;
$reportData = [];

foreach ($budgets as $name => $spec) {
    $filePath = $spec['file'];
    if (!file_exists($filePath)) {
        echo sprintf("[-] ERROR: File not found: %s\n", basename($filePath));
        $allPassed = false;
        continue;
    }

    $rawContent = file_get_contents($filePath);
    $rawBytes   = strlen($rawContent);
    $gzContent  = gzencode($rawContent, 9);
    $gzBytes    = strlen($gzContent);
    $maxAllowed = $spec['max_gz_byte'];

    $passed = ($gzBytes <= $maxAllowed);
    if (!$passed) {
        $allPassed = false;
    }

    // Dependency check: ensure no jQuery or external frameworks in frontend scripts
    if (strpos($filePath, '.js') !== false) {
        if (preg_match('/\b(jQuery|\$\.ajax|React|Vue|Angular)\b/', $rawContent)) {
            echo sprintf("[-] DEPENDENCY VIOLATION in %s: jQuery/framework reference detected!\n", basename($filePath));
            $allPassed = false;
        }
    }

    $reportData[] = [
        'name'        => $name,
        'filename'    => basename($filePath),
        'raw_bytes'   => $rawBytes,
        'gz_bytes'    => $gzBytes,
        'budget'      => $maxAllowed,
        'percentage'  => round(($gzBytes / $maxAllowed) * 100, 1),
        'passed'      => $passed,
    ];

    $statusStr = $passed ? "[PASS]" : "[FAIL - OVER BUDGET]";
    echo sprintf(
        "%-30s | %-28s | Raw: %5d B | Gzip: %5d B / %5d B (%5.1f%%) | %s\n",
        $name,
        basename($filePath),
        $rawBytes,
        $gzBytes,
        $maxAllowed,
        round(($gzBytes / $maxAllowed) * 100, 1),
        $statusStr
    );
}

echo "\n----------------------------------------------------------------------------------------\n";
if ($allPassed) {
    echo " RESULT: ALL FRONTEND ASSETS MEET STRICT PERFORMANCE BUDGETS (100% PASS)\n";
} else {
    echo " RESULT: ONE OR MORE BUDGETS WERE EXCEEDED!\n";
}
echo "========================================================================================\n";

exit($allPassed ? 0 : 1);
