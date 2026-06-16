<?php
/**
 * Vendor Document Toggle Status API
 *
 * Toggles a vendor document's active/inactive status.
 * Admin, procurement, and cyber_tprm users can toggle status.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireAuth();
$auth = Auth::getInstance();
$session = Session::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');

if (!$isAdmin && !$isProcurement && !$isCyberTPRM) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$documentId = intval($_POST['document_id'] ?? 0);

if (!$documentId) {
    echo json_encode(['success' => false, 'message' => 'Missing document ID']);
    exit;
}

$db = Database::getInstance();

try {
    $doc = $db->fetchOne(
        "SELECT id, is_active FROM vendor_documents WHERE id = ?",
        [$documentId]
    );

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    $newStatus = $doc['is_active'] ? 0 : 1;

    $db->update('vendor_documents', ['is_active' => $newStatus], 'id = :doc_id', [':doc_id' => $documentId]);

    $user = $auth->getUser();
    $auth->audit($user['id'], 'document_toggle_status', 'vendor_documents', $documentId, [
        'old' => ['is_active' => $doc['is_active']],
        'new' => ['is_active' => $newStatus]
    ]);

    echo json_encode([
        'success' => true,
        'is_active' => $newStatus,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor document toggle status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update document status']);
}
