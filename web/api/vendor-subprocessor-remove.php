<?php
/**
 * Vendor Subprocessor Remove API
 *
 * Deletes a vendor-subprocessor mapping. Does NOT delete the subprocessor entity.
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

$mappingId = intval($_POST['mapping_id'] ?? 0);

if (!$mappingId) {
    echo json_encode(['success' => false, 'message' => 'Missing mapping ID']);
    exit;
}

$db = Database::getInstance();

try {
    $mapping = $db->fetchOne(
        "SELECT m.id, s.subprocessor_name, m.vendor_onboarding_id
         FROM vendor_subprocessor_mappings m
         JOIN vendor_subprocessors s ON m.subprocessor_id = s.id
         WHERE m.id = ?",
        [$mappingId]
    );

    if (!$mapping) {
        echo json_encode(['success' => false, 'message' => 'Mapping not found']);
        exit;
    }

    $user = $auth->getUser();
    $auth->audit($user['id'], 'subprocessor_remove', 'vendor_subprocessor_mappings', $mappingId, [
        'old' => ['subprocessor_name' => $mapping['subprocessor_name'], 'vendor_id' => $mapping['vendor_onboarding_id']]
    ]);

    $db->delete('vendor_subprocessor_mappings', 'id = ?', [$mappingId]);

    echo json_encode([
        'success' => true,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor subprocessor remove error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to remove subprocessor']);
}
