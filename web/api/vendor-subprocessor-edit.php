<?php
/**
 * Vendor Subprocessor Edit API
 *
 * Updates global subprocessor fields (name, domain, country, linked_vendor_id)
 * and per-mapping fields (service_description, data_shared) by mapping_id.
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
        "SELECT m.id, m.subprocessor_id, m.vendor_onboarding_id, s.subprocessor_name
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
    $subprocessorId = (int)$mapping['subprocessor_id'];

    // Update global subprocessor fields
    $subName = trim($_POST['subprocessor_name'] ?? '');
    if ($subName === '') {
        echo json_encode(['success' => false, 'message' => 'Subprocessor name is required']);
        exit;
    }

    // Check for name uniqueness (excluding self)
    $duplicate = $db->fetchOne(
        "SELECT id FROM vendor_subprocessors WHERE subprocessor_name = ? AND id != ?",
        [$subName, $subprocessorId]
    );
    if ($duplicate) {
        echo json_encode(['success' => false, 'message' => 'A subprocessor with that name already exists']);
        exit;
    }

    $linkedVendorId = intval($_POST['linked_vendor_id'] ?? 0);

    // Database::update() builds the SET clause with NAMED placeholders (col = :col),
    // so the WHERE must use a named placeholder too. Mixing a positional '?' here
    // triggers PDO's "Invalid parameter number: mixed named and positional parameters".
    $db->update('vendor_subprocessors', [
        'subprocessor_name' => $subName,
        'subprocessor_domain' => trim($_POST['subprocessor_domain'] ?? '') ?: null,
        'country' => trim($_POST['country'] ?? '') ?: null,
        'linked_vendor_id' => $linkedVendorId ?: null,
    ], 'id = :id', [':id' => $subprocessorId]);

    // Update per-mapping fields
    $db->update('vendor_subprocessor_mappings', [
        'service_description' => trim($_POST['service_description'] ?? '') ?: null,
        'data_shared' => trim($_POST['data_shared'] ?? '') ?: null,
    ], 'id = :id', [':id' => $mappingId]);

    $auth->audit($user['id'], 'subprocessor_edit', 'vendor_subprocessor_mappings', $mappingId, [
        'new' => ['subprocessor_name' => $subName, 'vendor_id' => $mapping['vendor_onboarding_id']]
    ]);

    echo json_encode([
        'success' => true,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor subprocessor edit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update subprocessor']);
}
