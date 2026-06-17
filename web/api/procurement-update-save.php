<?php
/**
 * Procurement Update Save API
 *
 * Records a dated procurement "status note" for one or more vendor onboarding
 * requests that are currently In Review / AI Review, and optionally changes
 * their onboarding status at the same time. Restricted to administrator /
 * cyber_tprm. Consumed by the bulk "Provide Procurement with Update" modal on
 * vendor-onboarding-list.php (JSON body; rotating CSRF token).
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
$session = Session::getInstance();
$db = Database::getInstance();
$user = $auth->getUser();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$canApprove = $isAdmin || hasGroup('cyber_tprm');
if (!$canApprove) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

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

$updateText = trim((string)($input['update_text'] ?? ''));
$vendorIds  = $input['vendor_ids'] ?? [];
$newStatus  = trim((string)($input['new_status'] ?? ''));

if ($updateText === '') {
    echo json_encode(['success' => false, 'error' => 'Update text is required.', 'csrf_token' => $newToken]);
    exit;
}
if (!is_array($vendorIds) || empty($vendorIds)) {
    echo json_encode(['success' => false, 'error' => 'No vendors selected.', 'csrf_token' => $newToken]);
    exit;
}

// Statuses the modal is allowed to set. (Only vendors currently In Review /
// AI Review are eligible to receive an update at all.)
$validStatuses = ['in_review', 'ai_review', 'evaluation', 'approved', 'rejected', 'inactive'];
if ($newStatus !== '' && !in_array($newStatus, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status.', 'csrf_token' => $newToken]);
    exit;
}

$updated = 0;
$skipped = 0;
try {
    foreach ($vendorIds as $vid) {
        $rid = intval($vid);
        if ($rid <= 0) { $skipped++; continue; }

        $req = $db->fetchOne('SELECT id, status, vendor_name FROM vendor_onboarding_requests WHERE id = :id', [':id' => $rid]);
        if (!$req || !in_array($req['status'], ['in_review', 'ai_review'], true)) {
            $skipped++;
            continue;
        }

        $statusAtUpdate = ($newStatus !== '') ? $newStatus : $req['status'];

        $db->query(
            'INSERT INTO vendor_procurement_updates (request_id, update_text, status_at_update, created_by) VALUES (:rid, :txt, :st, :by)',
            [':rid' => $rid, ':txt' => $updateText, ':st' => $statusAtUpdate, ':by' => $user['id']]
        );
        $auth->audit($user['id'], 'procurement_update_added', 'vendor_onboarding_requests', $rid, [
            'update_text' => $updateText,
            'status'      => $statusAtUpdate,
        ]);

        if ($newStatus !== '' && $newStatus !== $req['status']) {
            $db->update('vendor_onboarding_requests', ['status' => $newStatus], 'id = :id', [':id' => $rid]);
            $auth->audit($user['id'], 'vendor_status_change', 'vendor_onboarding_requests', $rid, [
                'old' => ['status' => $req['status']],
                'new' => ['status' => $newStatus, 'vendor_name' => $req['vendor_name'] ?? ''],
            ]);
        }

        $updated++;
    }
} catch (Exception $e) {
    error_log('procurement-update-save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to save updates. Please try again.', 'csrf_token' => $newToken]);
    exit;
}

echo json_encode(['success' => true, 'updated' => $updated, 'skipped' => $skipped, 'csrf_token' => $newToken]);
