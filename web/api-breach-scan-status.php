<?php
/**
 * AJAX endpoint: Check breach scan status.
 * Returns JSON { "status": "scanning"|null, "result": "...", "started_at": "..." }
 */
require_once 'includes/init.php';
requireAuth();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$breachService = BreachAlertService::getInstance();
$scan = $breachService->getScanStatus();

echo json_encode([
    'status'     => $scan['status'] ?: null,
    'result'     => $scan['result'] ?: null,
    'started_at' => $scan['started_at'] ?: null,
]);
