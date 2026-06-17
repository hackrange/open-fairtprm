<?php
/**
 * Procurement Notes List API (read-only)
 *
 * Returns the 5 most recent procurement "status notes" for a vendor onboarding
 * request, for the read-only "Status Notes" pill on vendor-onboarding-list.php.
 * Visibility mirrors the list page: admins/cyber_tprm/procurement (or anyone
 * with onboarding.read) see any vendor; everyone else (e.g. stakeholders) may
 * only view notes for vendors they created or are assigned to.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAuth();
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!$security->validateCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}
$newToken = $security->getCSRFToken();

$requestId = intval($input['request_id'] ?? 0);
if ($requestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing vendor.', 'csrf_token' => $newToken]);
    exit;
}

// Visibility check — identical scoping to vendor-onboarding-list.php.
$canReadAll = $acl->hasPermission('onboarding.read') || $acl->hasGroup(['administrator', 'cyber_tprm', 'procurement']);
if ($canReadAll) {
    $visible = (bool)$db->fetchOne('SELECT id FROM vendor_onboarding_requests WHERE id = :id', [':id' => $requestId]);
} else {
    $visible = (bool)$db->fetchOne(
        'SELECT r.id FROM vendor_onboarding_requests r
         WHERE r.id = :id AND (r.created_by = :uid OR EXISTS (
             SELECT 1 FROM vendor_onboarding_stakeholders s WHERE s.request_id = r.id AND s.user_id = :uid2
         ))',
        [':id' => $requestId, ':uid' => $user['id'], ':uid2' => $user['id']]
    );
}
if (!$visible) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.', 'csrf_token' => $newToken]);
    exit;
}

$rows = $db->fetchAll(
    'SELECT pu.update_text, pu.status_at_update, pu.created_at, u.full_name AS author_name
     FROM vendor_procurement_updates pu
     LEFT JOIN users u ON pu.created_by = u.id
     WHERE pu.request_id = :rid
     ORDER BY pu.created_at DESC, pu.id DESC
     LIMIT 5',
    [':rid' => $requestId]
);

$statusLabels = [
    'draft' => 'Draft', 'submitted' => 'Submitted', 'in_review' => 'In Review',
    'ai_review' => 'AI Review', 'evaluation' => 'Evaluation', 'approved' => 'Approved',
    'rejected' => 'Rejected', 'inactive' => 'Inactive',
];

$notes = [];
foreach ($rows as $r) {
    $notes[] = [
        'date'   => date('M j, Y g:i A', strtotime($r['created_at'])),
        'author' => $r['author_name'] ?: 'System',
        'status' => $r['status_at_update'] ? ($statusLabels[$r['status_at_update']] ?? $r['status_at_update']) : '',
        'text'   => $r['update_text'],
    ];
}

echo json_encode(['success' => true, 'notes' => $notes, 'csrf_token' => $newToken]);
