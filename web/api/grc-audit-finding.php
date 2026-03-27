<?php
/**
 * AJAX endpoint: Create audit findings from within the Notes modal
 *
 * Actions:
 *   create - Create a new audit finding linked to requirement/control
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

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;
if ($readOnly) {
    echo json_encode(['success' => false, 'error' => 'Read-only access.']);
    exit;
}

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
$action = $input['action'] ?? '';
$auditId = (int)($input['audit_id'] ?? 0);

if ($auditId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Audit ID is required.', 'csrf_token' => $newToken]);
    exit;
}

// Verify audit exists
$audit = $db->fetchOne('SELECT id FROM grc_audits WHERE id = :id', [':id' => $auditId]);
if (!$audit) {
    echo json_encode(['success' => false, 'error' => 'Audit not found.', 'csrf_token' => $newToken]);
    exit;
}

if ($action === 'create') {
    $title = trim($input['title'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Finding title is required.', 'csrf_token' => $newToken]);
        exit;
    }

    // Validate severity
    $validSeverities = ['informational', 'low', 'medium', 'high', 'critical'];
    $severity = in_array($input['severity'] ?? '', $validSeverities) ? $input['severity'] : 'medium';

    // Validate finding_type
    $validTypes = ['nonconformity', 'observation', 'opportunity', 'strength'];
    $findingType = in_array($input['finding_type'] ?? '', $validTypes) ? $input['finding_type'] : 'nonconformity';

    // Generate finding ref
    $last = $db->fetchOne("SELECT finding_ref FROM grc_audit_findings WHERE finding_ref LIKE 'FND-%' ORDER BY id DESC LIMIT 1");
    $fref = $last ? 'FND-' . str_pad((int)substr($last['finding_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'FND-001';

    $insertData = [
        'finding_ref' => $fref,
        'audit_id' => $auditId,
        'title' => $title,
        'description' => trim($input['description'] ?? '') ?: null,
        'severity' => $severity,
        'finding_type' => $findingType,
        'status' => 'open',
        'remediation_plan' => trim($input['recommendation'] ?? '') ?: null,
        'assigned_to' => !empty($input['assigned_to']) ? (int)$input['assigned_to'] : null,
    ];

    // Link to requirement if provided
    if (!empty($input['requirement_id'])) {
        $insertData['requirement_id'] = (int)$input['requirement_id'];
    }

    // Link to control if provided
    if (!empty($input['control_id'])) {
        $insertData['control_id'] = (int)$input['control_id'];
    }

    // Due date
    if (!empty($input['due_date'])) {
        $dueDate = date('Y-m-d', strtotime($input['due_date']));
        if ($dueDate) {
            $insertData['due_date'] = $dueDate;
        }
    }

    $db->insert('grc_audit_findings', $insertData);
    $findingId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'finding_id' => $findingId,
        'finding_ref' => $fref,
        'csrf_token' => $newToken,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.', 'csrf_token' => $newToken]);
