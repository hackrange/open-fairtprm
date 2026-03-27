<?php
/**
 * AJAX endpoint: Upload/link evidence to an audit requirement assessment
 *
 * Accepts multipart/form-data with file upload or JSON for linking existing evidence.
 * Returns JSON with the updated evidence list for the assessment.
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

// Determine request type: multipart (upload) or JSON (list/unlink)
$isMultipart = !empty($_FILES['evidence_file']);
$csrfToken = '';
$action = '';
$auditId = 0;
$requirementId = 0;

if ($isMultipart) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'upload';
    $auditId = (int)($_POST['audit_id'] ?? 0);
    $requirementId = (int)($_POST['requirement_id'] ?? 0);
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }
    $csrfToken = $input['csrf_token'] ?? '';
    $action = $input['action'] ?? 'list';
    $auditId = (int)($input['audit_id'] ?? 0);
    $requirementId = (int)($input['requirement_id'] ?? 0);
}

if (!$security->validateCSRFToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$newToken = $security->getCSRFToken();

if ($auditId <= 0 || $requirementId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Audit and requirement IDs are required.', 'csrf_token' => $newToken]);
    exit;
}

// Verify audit exists and user has access
$audit = $db->fetchOne('SELECT id FROM grc_audits WHERE id = :id', [':id' => $auditId]);
if (!$audit) {
    echo json_encode(['success' => false, 'error' => 'Audit not found.', 'csrf_token' => $newToken]);
    exit;
}

// Get or create the assessment row
$assessment = $db->fetchOne(
    'SELECT id FROM grc_audit_requirement_assessments WHERE audit_id = :aid AND requirement_id = :rid',
    [':aid' => $auditId, ':rid' => $requirementId]
);
if (!$assessment) {
    $db->insert('grc_audit_requirement_assessments', [
        'audit_id' => $auditId,
        'requirement_id' => $requirementId,
        'assessment_status' => 'not_assessed',
    ]);
    $assessmentId = (int)$db->lastInsertId();
} else {
    $assessmentId = (int)$assessment['id'];
}

if ($action === 'upload') {
    // Upload new evidence file
    if (empty($_FILES['evidence_file']['tmp_name']) || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error.', 'csrf_token' => $newToken]);
        exit;
    }

    $file = $_FILES['evidence_file'];

    // Validate file size (max 50MB)
    if ($file['size'] > 52428800) {
        echo json_encode(['success' => false, 'error' => 'File too large. Maximum 50MB.', 'csrf_token' => $newToken]);
        exit;
    }

    // Validate MIME type
    $allowedMimes = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/gif',
        'text/plain', 'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword', 'application/vnd.ms-excel',
        'application/json', 'application/xml', 'text/xml',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedMime, $allowedMimes)) {
        echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $detectedMime, 'csrf_token' => $newToken]);
        exit;
    }

    $title = trim($_POST['evidence_title'] ?? '');
    if ($title === '') {
        // Auto-generate title from filename
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
    }

    // Generate evidence ref
    $lastRef = $db->fetchOne("SELECT evidence_ref FROM grc_evidence ORDER BY id DESC LIMIT 1");
    if ($lastRef) {
        $num = (int)substr($lastRef['evidence_ref'], 3) + 1;
        $ref = 'EV-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    } else {
        $ref = 'EV-001';
    }

    // Encrypt and store
    $encryption = new Encryption();
    $fileData = file_get_contents($file['tmp_name']);
    $encryptedData = $encryption->encryptRaw($fileData);

    $evidenceType = 'document';
    if (strpos($detectedMime, 'image/') === 0) $evidenceType = 'screenshot';
    elseif ($detectedMime === 'application/pdf') $evidenceType = 'document';
    elseif (strpos($detectedMime, 'json') !== false || strpos($detectedMime, 'xml') !== false) $evidenceType = 'configuration';

    $db->insert('grc_evidence', [
        'evidence_ref' => $ref,
        'title' => $title,
        'description' => 'Uploaded during audit assessment',
        'evidence_type' => $evidenceType,
        'collection_method' => 'manual',
        'collected_at' => date('Y-m-d H:i:s'),
        'valid_from' => date('Y-m-d H:i:s'),
        'valid_until' => date('Y-m-d H:i:s', strtotime('+1 year')),
        'status' => 'current',
        'collected_by' => (int)$user['id'],
        'encrypted_data' => $encryptedData,
        'file_name' => basename($file['name']),
        'file_mime' => $detectedMime,
        'file_size' => $file['size'],
    ]);
    $evidenceId = (int)$db->lastInsertId();

    // Link to assessment
    $db->insert('grc_assessment_evidence', [
        'assessment_id' => $assessmentId,
        'evidence_id' => $evidenceId,
        'linked_by' => (int)$user['id'],
    ]);

    // Auto-link evidence to controls mapped to this requirement
    $mappedControls = $db->fetchAll(
        'SELECT control_id FROM grc_control_requirement_map WHERE requirement_id = :rid',
        [':rid' => $requirementId]
    );
    foreach ($mappedControls as $mc) {
        $existing = $db->fetchOne(
            'SELECT id FROM grc_evidence_control_map WHERE evidence_id = :eid AND control_id = :cid',
            [':eid' => $evidenceId, ':cid' => (int)$mc['control_id']]
        );
        if (!$existing) {
            $db->insert('grc_evidence_control_map', [
                'evidence_id' => $evidenceId,
                'control_id' => (int)$mc['control_id'],
                'linked_by' => (int)$user['id'],
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'evidence' => getAssessmentEvidence($db, $assessmentId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'unlink') {
    $evidenceId = (int)($input['evidence_id'] ?? 0);
    if ($evidenceId > 0) {
        $db->query(
            'DELETE FROM grc_assessment_evidence WHERE assessment_id = :aid AND evidence_id = :eid',
            [':aid' => $assessmentId, ':eid' => $evidenceId]
        );
    }
    echo json_encode([
        'success' => true,
        'evidence' => getAssessmentEvidence($db, $assessmentId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'add_link') {
    $externalUrl = trim($input['external_url'] ?? '');
    if ($externalUrl === '' || !preg_match('#^https?://#i', $externalUrl)) {
        echo json_encode(['success' => false, 'error' => 'A valid URL starting with http:// or https:// is required.', 'csrf_token' => $newToken]);
        exit;
    }

    $title = trim($input['evidence_title'] ?? '');
    if ($title === '') {
        // Extract domain as fallback title
        $parsed = parse_url($externalUrl);
        $title = $parsed['host'] ?? 'External Link';
    }

    // Generate evidence ref
    $lastRef = $db->fetchOne("SELECT evidence_ref FROM grc_evidence ORDER BY id DESC LIMIT 1");
    if ($lastRef) {
        $num = (int)substr($lastRef['evidence_ref'], 3) + 1;
        $ref = 'EV-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    } else {
        $ref = 'EV-001';
    }

    $db->insert('grc_evidence', [
        'evidence_ref' => $ref,
        'title' => $title,
        'description' => 'External link added during audit assessment',
        'evidence_type' => 'document',
        'collection_method' => 'manual',
        'collected_at' => date('Y-m-d H:i:s'),
        'valid_from' => date('Y-m-d H:i:s'),
        'valid_until' => date('Y-m-d H:i:s', strtotime('+1 year')),
        'status' => 'current',
        'collected_by' => (int)$user['id'],
        'external_url' => $externalUrl,
    ]);
    $evidenceId = (int)$db->lastInsertId();

    // Link to assessment
    $db->insert('grc_assessment_evidence', [
        'assessment_id' => $assessmentId,
        'evidence_id' => $evidenceId,
        'linked_by' => (int)$user['id'],
    ]);

    // Auto-link to mapped controls
    $mappedControls = $db->fetchAll(
        'SELECT control_id FROM grc_control_requirement_map WHERE requirement_id = :rid',
        [':rid' => $requirementId]
    );
    foreach ($mappedControls as $mc) {
        $existing = $db->fetchOne(
            'SELECT id FROM grc_evidence_control_map WHERE evidence_id = :eid AND control_id = :cid',
            [':eid' => $evidenceId, ':cid' => (int)$mc['control_id']]
        );
        if (!$existing) {
            $db->insert('grc_evidence_control_map', [
                'evidence_id' => $evidenceId,
                'control_id' => (int)$mc['control_id'],
                'linked_by' => (int)$user['id'],
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'evidence' => getAssessmentEvidence($db, $assessmentId),
        'csrf_token' => $newToken,
    ]);
    exit;

} elseif ($action === 'list') {
    echo json_encode([
        'success' => true,
        'evidence' => getAssessmentEvidence($db, $assessmentId),
        'csrf_token' => $newToken,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.', 'csrf_token' => $newToken]);

/**
 * Get all evidence linked to an assessment
 */
function getAssessmentEvidence($db, $assessmentId) {
    return $db->fetchAll(
        'SELECT e.id, e.evidence_ref, e.title, e.evidence_type, e.file_name, e.file_size, e.external_url, e.status, e.collected_at, ae.linked_at
         FROM grc_assessment_evidence ae
         JOIN grc_evidence e ON e.id = ae.evidence_id
         WHERE ae.assessment_id = :aid
         ORDER BY ae.linked_at DESC',
        [':aid' => $assessmentId]
    );
}
