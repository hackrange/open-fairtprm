<?php
/**
 * Unified Question Bank Seeder
 *
 * Seeds the 146 unified assessment questions and their framework mappings
 * into grc_unified_questions and grc_question_framework_map tables.
 * Safe to run repeatedly — only inserts questions/mappings that don't exist.
 * Called from the Docker entrypoint after catalog framework seeding.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/init.php';

$uas = UnifiedAssessmentService::getInstance();

// 1. Seed questions from catalog
$qResult = $uas->seedQuestionsFromCatalog();
if ($qResult['success']) {
    if ($qResult['inserted'] > 0) {
        echo "    - Seeded {$qResult['inserted']} question(s), skipped {$qResult['skipped']} existing.\n";
    } else {
        echo "    - All {$qResult['skipped']} question(s) already exist.\n";
    }
} else {
    echo "    - Question seeding failed: {$qResult['error']}\n";
}

// 2. Seed framework mappings
$mResult = $uas->seedFrameworkMappings();
if ($mResult['success']) {
    if ($mResult['inserted'] > 0) {
        echo "    - Seeded {$mResult['inserted']} mapping(s), skipped {$mResult['skipped']} existing.";
        if ($mResult['requirements_created'] > 0) {
            echo " Created {$mResult['requirements_created']} requirement stub(s).";
        }
        echo "\n";
    } else {
        echo "    - All {$mResult['skipped']} mapping(s) already exist.\n";
    }
} else {
    echo "    - Mapping seeding failed: {$mResult['error']}\n";
}
