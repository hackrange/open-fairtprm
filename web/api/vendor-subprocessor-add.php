<?php
/**
 * Vendor Subprocessor Add API
 *
 * Find-or-create a subprocessor entity, then create a mapping to the vendor.
 * Accepts either subprocessor_id (existing) or subprocessor_name (new/lookup).
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

$vendorId = intval($_POST['vendor_onboarding_id'] ?? 0);
$subprocessorId = intval($_POST['subprocessor_id'] ?? 0);
$subprocessorName = trim($_POST['subprocessor_name'] ?? '');
$subprocessorDomain = trim($_POST['subprocessor_domain'] ?? '');
$country = trim($_POST['country'] ?? '');
$linkedVendorId = intval($_POST['linked_vendor_id'] ?? 0);
$serviceDescription = trim($_POST['service_description'] ?? '');
$dataShared = trim($_POST['data_shared'] ?? '');

if (!$vendorId) {
    echo json_encode(['success' => false, 'message' => 'Missing vendor ID']);
    exit;
}

if (!$subprocessorId && $subprocessorName === '') {
    echo json_encode(['success' => false, 'message' => 'Provide a subprocessor name or select an existing one']);
    exit;
}

$db = Database::getInstance();
$user = $auth->getUser();

try {
    // Find or create the subprocessor entity
    if ($subprocessorId) {
        // Verify it exists
        $existing = $db->fetchOne("SELECT id FROM vendor_subprocessors WHERE id = ?", [$subprocessorId]);
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Subprocessor not found']);
            exit;
        }
    } else {
        // Look up by exact name first
        $existing = $db->fetchOne(
            "SELECT id FROM vendor_subprocessors WHERE subprocessor_name = ?",
            [$subprocessorName]
        );
        if ($existing) {
            $subprocessorId = (int)$existing['id'];
        } else {
            // Create new subprocessor entity
            $insertData = [
                'subprocessor_name' => $subprocessorName,
                'subprocessor_domain' => $subprocessorDomain ?: null,
                'country' => $country ?: null,
                'linked_vendor_id' => $linkedVendorId ?: null,
                'created_by' => $user['id'],
            ];
            $subprocessorId = (int)$db->insert('vendor_subprocessors', $insertData);
        }
    }

    // Check if mapping already exists
    $existingMapping = $db->fetchOne(
        "SELECT id FROM vendor_subprocessor_mappings WHERE vendor_onboarding_id = ? AND subprocessor_id = ?",
        [$vendorId, $subprocessorId]
    );
    if ($existingMapping) {
        echo json_encode(['success' => false, 'message' => 'This subprocessor is already linked to this vendor']);
        exit;
    }

    // Create the mapping
    $mappingId = (int)$db->insert('vendor_subprocessor_mappings', [
        'vendor_onboarding_id' => $vendorId,
        'subprocessor_id' => $subprocessorId,
        'service_description' => $serviceDescription ?: null,
        'data_shared' => $dataShared ?: null,
        'added_by' => $user['id'],
    ]);

    $auth->audit($user['id'], 'subprocessor_add', 'vendor_subprocessor_mappings', $mappingId, [
        'new' => ['vendor_id' => $vendorId, 'subprocessor_name' => $subprocessorName ?: "ID:$subprocessorId"]
    ]);

    echo json_encode([
        'success' => true,
        'subprocessor_id' => $subprocessorId,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('Vendor subprocessor add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to add subprocessor']);
}
