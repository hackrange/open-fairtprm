<?php
/**
 * AJAX endpoint: Create/link/list controls for an audit requirement assessment
 *
 * Actions:
 *   list   - Get controls linked to a requirement + all available controls
 *   create - Create a new control and map it to the requirement
 *   link   - Link an existing control to the requirement
 *   unlink - Remove a control-requirement mapping
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
$user = $auth->getUser();
$security = Security::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();
$grc = GRCService::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!$security->validateCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$newToken = $security->getCSRFToken();
$action = $input['action'] ?? 'list';
$requirementId = (int)($input['requirement_id'] ?? 0);

if ($requirementId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Requirement ID is required.', 'csrf_token' => $newToken]);
    exit;
}

if ($action === 'list') {
    echo json_encode([
        'success' => true,
        'linked' => getLinkedControls($db, $requirementId),
        'available' => getAvailableControls($db, $requirementId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'create' && !$readOnly) {
    $title = trim($input['title'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Title is required.', 'csrf_token' => $newToken]);
        exit;
    }

    $controlId = $grc->createControl([
        'title' => $title,
        'description' => trim($input['description'] ?? ''),
        'control_type' => $input['control_type'] ?? 'preventive',
        'control_category' => $input['control_category'] ?? 'technical',
        'implementation_status' => $input['implementation_status'] ?? 'planned',
        'frequency' => $input['frequency'] ?? 'ad_hoc',
        'risk_level' => $input['risk_level'] ?? 'medium',
        'notes' => trim($input['notes'] ?? ''),
    ], (int)$user['id']);

    // Auto-map to the requirement
    $grc->mapControlToRequirement($controlId, $requirementId, ['coverage' => 'full'], (int)$user['id']);

    echo json_encode([
        'success' => true,
        'control_id' => $controlId,
        'linked' => getLinkedControls($db, $requirementId),
        'available' => getAvailableControls($db, $requirementId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'link' && !$readOnly) {
    $controlId = (int)($input['control_id'] ?? 0);
    if ($controlId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Control ID is required.', 'csrf_token' => $newToken]);
        exit;
    }
    $grc->mapControlToRequirement($controlId, $requirementId, ['coverage' => $input['coverage'] ?? 'full'], (int)$user['id']);

    echo json_encode([
        'success' => true,
        'linked' => getLinkedControls($db, $requirementId),
        'available' => getAvailableControls($db, $requirementId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'unlink' && !$readOnly) {
    $controlId = (int)($input['control_id'] ?? 0);
    if ($controlId > 0) {
        $grc->unmapControlFromRequirement($controlId, $requirementId);
    }

    echo json_encode([
        'success' => true,
        'linked' => getLinkedControls($db, $requirementId),
        'available' => getAvailableControls($db, $requirementId),
        'csrf_token' => $newToken,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action or insufficient permissions.', 'csrf_token' => $newToken]);

/**
 * Get controls mapped to a requirement
 */
function getLinkedControls($db, $requirementId) {
    return $db->fetchAll(
        'SELECT ic.id, ic.control_ref, ic.title, ic.control_type, ic.control_category,
                ic.implementation_status, ic.frequency, ic.risk_level, crm.coverage
         FROM grc_control_requirement_map crm
         JOIN grc_internal_controls ic ON ic.id = crm.control_id
         WHERE crm.requirement_id = :rid
         ORDER BY ic.control_ref',
        [':rid' => $requirementId]
    );
}

/**
 * Get all active controls NOT yet linked to this requirement
 */
function getAvailableControls($db, $requirementId) {
    return $db->fetchAll(
        'SELECT ic.id, ic.control_ref, ic.title
         FROM grc_internal_controls ic
         WHERE ic.is_active = 1
           AND ic.id NOT IN (
               SELECT control_id FROM grc_control_requirement_map WHERE requirement_id = :rid
           )
         ORDER BY ic.control_ref',
        [':rid' => $requirementId]
    );
}
