<?php
/**
 * GRC Catalog Framework Seeder
 *
 * Auto-seeds all catalog framework templates into the database on startup.
 * Safe to run repeatedly — only creates frameworks that don't already exist.
 * Called from the Docker entrypoint after SQL migrations.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/init.php';

$db = Database::getInstance();
$grc = GRCService::getInstance();

$catalogDir = __DIR__ . '/data/catalog';
$seeded = 0;
$skipped = 0;

foreach (glob($catalogDir . '/*.php') as $file) {
    $basename = basename($file, '.php');
    if ($basename === 'crosswalk') continue;

    $data = include $file;
    if (!is_array($data) || empty($data['code']) || empty($data['requirements'])) continue;

    // Skip if framework already exists (by code or by generated_from reference)
    $existing = $grc->getFrameworkByCode($data['code']);
    if (!$existing) {
        $existing = $db->fetchOne(
            'SELECT id FROM grc_frameworks WHERE generated_from = :code LIMIT 1',
            [':code' => $data['code']]
        );
    }
    if ($existing) {
        // Ensure generated_from is set on existing frameworks
        if (!empty($existing['id']) && empty($existing['generated_from'])) {
            $db->update('grc_frameworks', ['generated_from' => $data['code']], 'id = :id', [':id' => $existing['id']]);
        }
        $skipped++;
        continue;
    }

    // Generate framework + requirements via the existing service method
    $result = $grc->generateFromCatalog($data['code'], null, null);
    if ($result['success']) {
        $seeded++;
        echo "    - Seeded: {$data['name']} ({$result['count']} requirements)\n";
    } else {
        echo "    - Failed: {$data['name']} — {$result['error']}\n";
    }
}

if ($seeded > 0) {
    echo "    - Seeded {$seeded} framework(s), skipped {$skipped} existing.\n";
} else {
    echo "    - All {$skipped} catalog framework(s) already exist.\n";
}
