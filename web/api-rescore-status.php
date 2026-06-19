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

// SECURITY (IDOR): this poller previously had no authorization beyond requireAuth(),
// letting any authenticated user read rescore workflow state and enumerate vendor
// records they cannot view on vendor-srs-details.php. Apply the same SRS-view gate:
// org-wide reviewers (admin/cyber_tprm/auditor/super_admin) OR a stakeholder assigned
// to this specific vendor.
$acl = ACL::getInstance();
$canView = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm')
        || $acl->hasGroup('auditor') || Session::getInstance()->get('is_super_admin');
if (!$canView && $acl->hasGroup('stakeholder')) {
    $st = $db->fetchOne(
        'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
        [':rid' => $vendorId, ':uid' => Auth::getInstance()->getUserId()]
    );
    $canView = !empty($st);
}
if (!$canView) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

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
