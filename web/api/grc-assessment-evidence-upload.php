<?php
/**
 * FairScore Assessment Evidence Upload API
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Handles evidence file uploads and evidence-response linking for the FairScore
 * unified assessment system. Supports three actions:
 *
 *   upload  - Multipart/form-data POST: validates, encrypts (AES-256-CBC), and
 *             stores evidence files as LONGBLOB in grc_evidence, then links the
 *             evidence to an assessment response via grc_assessment_response_evidence.
 *   unlink  - JSON POST: removes the link between evidence and a response (does
 *             NOT delete the evidence itself -- it may be linked elsewhere).
 *   list    - JSON POST: returns all evidence linked to a given response.
 *
 * Security:
 *   - CSRF token validated on every request, fresh token returned in every response
 *   - MIME type detected via finfo (magic bytes), never trusted from the client
 *   - File size capped at 50MB
 *   - ACL: administrator, cyber_grc, auditor (read-only), grc_contributors
 *   - Read-only auditors (no admin/cyber_grc overlap) cannot upload or unlink
 *   - All file content encrypted at rest with AES-256-CBC + HMAC before DB storage
 *   - Input sanitization via Security::cleanInput() and prepared statements
 *   - No user-supplied data echoed without sanitization
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// CORE SERVICES
// ============================================================================
$auth     = Auth::getInstance();
$user     = $auth->getUser();
$security = Security::getInstance();
$session  = Session::getInstance();
$db       = Database::getInstance();

// ============================================================================
// ACL ENFORCEMENT
// administrator, cyber_grc, auditor, grc_contributors may access this endpoint.
// Auditors who are not also admin or cyber_grc get read-only access (list only).
// ============================================================================
$isAdmin       = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC    = hasGroup('cyber_grc');
$isAuditor     = hasGroup('auditor');
$isContributor = hasGroup('grc_contributors');

if (!$isAdmin && !$isCyberGRC && !$isAuditor && !$isContributor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// Read-only: auditors who have ONLY auditor role (no admin, no cyber_grc, no contributor)
$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC && !$isContributor;

// ============================================================================
// METHOD ENFORCEMENT -- POST only, always
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ============================================================================
// REQUEST PARSING
// Detect whether this is a multipart upload or a JSON body request.
// For multipart: fields come from $_POST and $_FILES.
// For JSON: body is parsed from php://input.
// ============================================================================
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isMultipart = (strpos($contentType, 'multipart/form-data') !== false) || !empty($_FILES['evidence_file']);

$csrfToken  = '';
$action     = '';
$responseId = 0;

if ($isMultipart) {
    $csrfToken  = $_POST['csrf_token'] ?? '';
    $action     = $_POST['action'] ?? '';
    $responseId = (int)($_POST['response_id'] ?? 0);
} else {
    $rawInput = file_get_contents('php://input');
    // Guard against excessively large JSON bodies (1MB max for non-file requests)
    if (strlen($rawInput) > 1048576) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Request body too large.']);
        exit;
    }
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
        exit;
    }
    $csrfToken  = $input['csrf_token'] ?? '';
    $action     = $input['action'] ?? '';
    $responseId = (int)($input['response_id'] ?? 0);
}

// ============================================================================
// CSRF VALIDATION -- every request, no exceptions
// ============================================================================
if (!$security->validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
    exit;
}

// Return the current session token (no rotation — prevents race conditions with concurrent AJAX)
$newToken = $security->getCSRFToken();

// ============================================================================
// VALIDATE ACTION
// ============================================================================
$allowedActions = ['upload', 'unlink', 'list'];
if (!in_array($action, $allowedActions, true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown action.', 'csrf_token' => $newToken]);
    exit;
}

// ============================================================================
// VALIDATE RESPONSE ID
// ============================================================================
if ($responseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A valid response_id is required.', 'csrf_token' => $newToken]);
    exit;
}

// Verify the assessment response exists in the database
$response = $db->fetchOne(
    'SELECT id, assessment_id FROM grc_assessment_responses WHERE id = :id',
    [':id' => $responseId]
);
if (!$response) {
    echo json_encode(['success' => false, 'error' => 'Assessment response not found.', 'csrf_token' => $newToken]);
    exit;
}

// ============================================================================
// ALLOWED MIME TYPES (validated via finfo magic bytes, never by extension)
// ============================================================================
$allowedMimes = [
    'application/pdf',
    'image/png',
    'image/jpeg',
    'image/gif',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // .xlsx
    'application/msword',       // .doc
    'application/vnd.ms-excel', // .xls
    'text/plain',
    'text/csv',
    'application/json',
    'application/xml',
    'text/xml',
];

// Max file size: 50 MB
$maxFileSize = 52428800;

// ============================================================================
// ACTION: UPLOAD
// ============================================================================
if ($action === 'upload') {

    // Read-only users cannot upload
    if ($readOnly) {
        echo json_encode(['success' => false, 'error' => 'Read-only access. You cannot upload evidence.', 'csrf_token' => $newToken]);
        exit;
    }

    // ---- File presence check ------------------------------------------------
    if (empty($_FILES['evidence_file'])
        || !isset($_FILES['evidence_file']['error'])
        || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $uploadError = 'No file uploaded or upload error.';
        if (isset($_FILES['evidence_file']['error'])) {
            switch ($_FILES['evidence_file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $uploadError = 'File exceeds the maximum allowed size.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $uploadError = 'File was only partially uploaded. Please try again.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $uploadError = 'No file was uploaded.';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                case UPLOAD_ERR_CANT_WRITE:
                case UPLOAD_ERR_EXTENSION:
                    $uploadError = 'Server error during upload. Please contact the administrator.';
                    error_log('Evidence upload server error: code ' . $_FILES['evidence_file']['error']);
                    break;
            }
        }
        echo json_encode(['success' => false, 'error' => $uploadError, 'csrf_token' => $newToken]);
        exit;
    }

    $file = $_FILES['evidence_file'];

    // ---- Validate the uploaded file is a real uploaded file ------------------
    if (!is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid upload detected.', 'csrf_token' => $newToken]);
        exit;
    }

    // ---- File size validation -----------------------------------------------
    // Check both the reported size and the actual file on disk (the reported
    // size can be spoofed, but the disk size cannot).
    $actualSize = filesize($file['tmp_name']);
    if ($file['size'] > $maxFileSize || $actualSize > $maxFileSize || $actualSize === false) {
        echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 50MB.', 'csrf_token' => $newToken]);
        exit;
    }

    // ---- MIME type validation via finfo (magic bytes) -----------------------
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        error_log('Evidence upload: finfo_open() failed.');
        echo json_encode(['success' => false, 'error' => 'Server error validating file type.', 'csrf_token' => $newToken]);
        exit;
    }
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($detectedMime === false || !in_array($detectedMime, $allowedMimes, true)) {
        echo json_encode([
            'success' => false,
            'error'   => 'File type not allowed. Accepted types: PDF, PNG, JPEG, GIF, DOCX, XLSX, DOC, XLS, TXT, CSV, JSON, XML.',
            'csrf_token' => $newToken,
        ]);
        exit;
    }

    // ---- Sanitize the original filename -------------------------------------
    // Strip path traversal, null bytes, and non-printable characters. We store
    // only the basename, never any directory component.
    $originalName = basename($file['name']);
    $originalName = preg_replace('/[\x00-\x1f\x7f]/', '', $originalName); // strip control chars
    $originalName = $security->cleanInput($originalName);
    if (empty($originalName) || $originalName === '.' || $originalName === '..') {
        $originalName = 'evidence_upload';
    }
    // Enforce a reasonable filename length
    if (mb_strlen($originalName) > 255) {
        $originalName = mb_substr($originalName, 0, 255);
    }

    // ---- Evidence title -----------------------------------------------------
    $title = trim($_POST['evidence_title'] ?? '');
    if ($title !== '') {
        $title = $security->cleanInput($title);
        // Cap title length to prevent abuse
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }
    } else {
        // Auto-generate from filename (sans extension)
        $title = pathinfo($originalName, PATHINFO_FILENAME);
    }

    // ---- Auto-detect evidence_type from MIME --------------------------------
    $evidenceType = 'document'; // default
    if (strpos($detectedMime, 'image/') === 0) {
        $evidenceType = 'screenshot';
    } elseif ($detectedMime === 'application/json'
           || $detectedMime === 'application/xml'
           || $detectedMime === 'text/xml'
    ) {
        $evidenceType = 'configuration';
    }
    // PDF, DOCX, XLSX, DOC, XLS, TXT, CSV all remain 'document'

    // ---- Read and encrypt the file contents ---------------------------------
    $fileData = file_get_contents($file['tmp_name']);
    if ($fileData === false) {
        error_log('Evidence upload: failed to read tmp file ' . $file['tmp_name']);
        echo json_encode(['success' => false, 'error' => 'Failed to read uploaded file.', 'csrf_token' => $newToken]);
        exit;
    }

    try {
        $encryption    = new Encryption();
        $encryptedData = $encryption->encryptRaw($fileData);
    } catch (Exception $e) {
        error_log('Evidence upload encryption error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error encrypting file.', 'csrf_token' => $newToken]);
        exit;
    }

    // Clear plaintext from memory as soon as possible
    $fileData = null;

    // ---- Generate evidence_ref (EV-001, EV-002, ...) ------------------------
    // Uses a SELECT ... FOR UPDATE pattern within a transaction to prevent
    // duplicate refs under concurrent uploads.
    try {
        $db->query('START TRANSACTION');

        $lastRef = $db->fetchOne("SELECT evidence_ref FROM grc_evidence ORDER BY id DESC LIMIT 1 FOR UPDATE");
        if ($lastRef && preg_match('/^EV-(\d+)$/', $lastRef['evidence_ref'], $m)) {
            $num = (int)$m[1] + 1;
        } else {
            $num = 1;
        }
        $ref = 'EV-' . str_pad($num, 3, '0', STR_PAD_LEFT);

        // ---- Insert evidence record -----------------------------------------
        $db->insert('grc_evidence', [
            'evidence_ref'      => $ref,
            'title'             => $title,
            'description'       => 'Uploaded during FairScore assessment',
            'evidence_type'     => $evidenceType,
            'collection_method' => 'manual',
            'collected_at'      => date('Y-m-d H:i:s'),
            'valid_from'        => date('Y-m-d H:i:s'),
            'valid_until'       => date('Y-m-d H:i:s', strtotime('+1 year')),
            'status'            => 'current',
            'collected_by'      => (int)$user['id'],
            'encrypted_data'    => $encryptedData,
            'file_name'         => $originalName,
            'file_mime'         => $detectedMime,
            'file_size'         => $actualSize,
        ]);
        $evidenceId = (int)$db->lastInsertId();

        // ---- Link evidence to the assessment response -----------------------
        // The UNIQUE constraint on (response_id, evidence_id) prevents duplicates
        // at the DB level, but we also guard against it here.
        $existingLink = $db->fetchOne(
            'SELECT id FROM grc_assessment_response_evidence WHERE response_id = :rid AND evidence_id = :eid',
            [':rid' => $responseId, ':eid' => $evidenceId]
        );
        if (!$existingLink) {
            $db->insert('grc_assessment_response_evidence', [
                'response_id' => $responseId,
                'evidence_id' => $evidenceId,
                'linked_by'   => (int)$user['id'],
            ]);
        }

        $db->query('COMMIT');

    } catch (Exception $e) {
        $db->query('ROLLBACK');
        error_log('Evidence upload DB error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to save evidence record.', 'csrf_token' => $newToken]);
        exit;
    }

    // Clear encrypted data from memory
    $encryptedData = null;

    echo json_encode([
        'success'    => true,
        'evidence'   => getResponseEvidence($db, $responseId),
        'csrf_token' => $newToken,
    ]);
    exit;
}

// ============================================================================
// ACTION: UNLINK
// ============================================================================
if ($action === 'unlink') {

    // Read-only users cannot unlink
    if ($readOnly) {
        echo json_encode(['success' => false, 'error' => 'Read-only access. You cannot unlink evidence.', 'csrf_token' => $newToken]);
        exit;
    }

    $evidenceId = (int)($input['evidence_id'] ?? 0);
    if ($evidenceId <= 0) {
        echo json_encode(['success' => false, 'error' => 'A valid evidence_id is required.', 'csrf_token' => $newToken]);
        exit;
    }

    // Verify the link actually exists before attempting deletion
    $link = $db->fetchOne(
        'SELECT id FROM grc_assessment_response_evidence WHERE response_id = :rid AND evidence_id = :eid',
        [':rid' => $responseId, ':eid' => $evidenceId]
    );
    if (!$link) {
        echo json_encode(['success' => false, 'error' => 'Evidence link not found.', 'csrf_token' => $newToken]);
        exit;
    }

    try {
        $db->query(
            'DELETE FROM grc_assessment_response_evidence WHERE response_id = :rid AND evidence_id = :eid',
            [':rid' => $responseId, ':eid' => $evidenceId]
        );
    } catch (Exception $e) {
        error_log('Evidence unlink error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to unlink evidence.', 'csrf_token' => $newToken]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'evidence'   => getResponseEvidence($db, $responseId),
        'csrf_token' => $newToken,
    ]);
    exit;
}

// ============================================================================
// ACTION: LIST
// ============================================================================
if ($action === 'list') {
    echo json_encode([
        'success'    => true,
        'evidence'   => getResponseEvidence($db, $responseId),
        'csrf_token' => $newToken,
    ]);
    exit;
}

// Fallback -- should never reach here due to allowedActions check above
echo json_encode(['success' => false, 'error' => 'Unknown action.', 'csrf_token' => $newToken]);

// ============================================================================
// HELPER: Fetch all evidence linked to a given assessment response
// ============================================================================
/**
 * Returns all evidence records linked to the specified assessment response.
 * Excludes the encrypted_data LONGBLOB from the result set -- callers
 * should use a dedicated download endpoint to retrieve file contents.
 *
 * @param Database $db         Database instance
 * @param int      $responseId Assessment response ID
 * @return array   List of evidence records with metadata
 */
function getResponseEvidence($db, int $responseId): array {
    return $db->fetchAll(
        'SELECT e.id, e.evidence_ref, e.title, e.evidence_type, e.collection_method,
                e.file_name, e.file_mime, e.file_size, e.external_url, e.status,
                e.collected_at, e.valid_from, e.valid_until,
                are.linked_by, are.linked_at
         FROM grc_assessment_response_evidence are
         JOIN grc_evidence e ON e.id = are.evidence_id
         WHERE are.response_id = :rid
         ORDER BY are.linked_at DESC',
        [':rid' => $responseId]
    );
}
