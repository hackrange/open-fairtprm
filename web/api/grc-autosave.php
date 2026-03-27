<?php
/**
 * GRC Form Autosave API Endpoint
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Saves form drafts to grc_autosave_drafts so nothing is lost mid-edit.
 * Works via AJAX POST from any GRC form with the .grc-autosave class.
 * Each user+form_type+form_id combination stores exactly one draft row.
 */

require_once dirname(__DIR__) . '/includes/init.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$security = Security::getInstance();

if (empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$formType = $input['form_type'] ?? '';
$formId = $input['form_id'] ?? null;
$draftData = $input['draft_data'] ?? [];

if (empty($formType) || empty($draftData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'form_type and draft_data required']);
    exit;
}

// Validate form_type against allowed values
$allowedTypes = [
    'control_edit', 'policy_edit', 'evidence_upload', 'audit_edit',
    'finding_edit', 'monitor_config', 'risk_edit', 'remediation_edit',
    'framework_requirement', 'integration_config',
    'assessment_response', 'assessment_task',
];
if (!in_array($formType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid form_type']);
    exit;
}

$db = Database::getInstance();
$auth = Auth::getInstance();
$user = $auth->getUser();
$userId = (int)$user['id'];

try {
    $existing = $db->fetchOne(
        'SELECT id FROM grc_autosave_drafts WHERE user_id = :uid AND form_type = :ft AND (form_id = :fid OR (form_id IS NULL AND :fid2 IS NULL))',
        [':uid' => $userId, ':ft' => $formType, ':fid' => $formId, ':fid2' => $formId]
    );

    $draftJson = json_encode($draftData);

    if ($existing) {
        $db->update('grc_autosave_drafts', [
            'draft_data' => $draftJson,
        ], 'id = :id', [':id' => $existing['id']]);
    } else {
        $db->insert('grc_autosave_drafts', [
            'user_id' => $userId,
            'form_type' => $formType,
            'form_id' => $formId,
            'draft_data' => $draftJson,
        ]);
    }

    echo json_encode([
        'success' => true,
        'saved_at' => date('c'),
        'csrf_token' => $security->getCSRFToken(),
    ]);
} catch (Exception $e) {
    error_log('GRC autosave error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed']);
}
