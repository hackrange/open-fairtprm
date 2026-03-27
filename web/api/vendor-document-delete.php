<?php
/**
 * Vendor Document Delete API
 *
 * Deletes a vendor document from the vendor_documents table along with
 * any associated contract reminders.
 * Only admin, cyber_tprm, and procurement (contract-type only) users can delete.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
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
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
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
        'SELECT id, original_filename, document_type FROM vendor_documents WHERE id = :id',
        [':id' => $documentId]
    );

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    // Procurement users can only delete contract-type documents
    if ($isProcurement && !$isAdmin && !$isCyberTPRM && $doc['document_type'] !== 'contract') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Procurement can only delete contract documents.']);
        exit;
    }

    $user = $auth->getUser();
    $auth->audit($user['id'], 'document_delete', 'vendor_documents', $documentId, [
        'old' => ['filename' => $doc['original_filename'], 'document_type' => $doc['document_type']]
    ]);

    $db->beginTransaction();

    // Explicitly delete any associated contract reminders first
    $db->delete('vendor_contract_reminders', 'vendor_document_id = :did', [':did' => $documentId]);

    // Delete the document record
    $db->delete('vendor_documents', 'id = :id', [':id' => $documentId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    try { $db->rollback(); } catch (Exception $ignore) {}
    error_log('Vendor document delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete document']);
}
