<?php
/**
 * AJAX endpoint: Check vendor rescore status.
 * Returns JSON { "status": "rescoring"|null, "result": "..." }
 */
require_once 'includes/init.php';
requireAuth();

header('Content-Type: application/json');

$vendorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($vendorId <= 0) {
    echo json_encode(['error' => 'Invalid vendor ID']);
    exit;
}

$db = Database::getInstance();
$row = $db->fetchOne(
    'SELECT rescore_status, rescore_result FROM vendor_onboarding_requests WHERE id = ?',
    [$vendorId]
);

if (!$row) {
    echo json_encode(['error' => 'Vendor not found']);
    exit;
}

echo json_encode([
    'status' => $row['rescore_status'],
    'result' => $row['rescore_result'],
]);
