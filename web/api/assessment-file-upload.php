<?php
/**
 * Assessment File Upload API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Handles file uploads for individual assessment questions. When a question asks
 * the vendor to "upload your SOC2 report" or "attach your security policy," this
 * is the endpoint that catches the file. Validation, encryption, and storage are
 * handled by FileUploadService (because copy-pasting crypto code between files
 * is how security incidents start). The encrypted "db:{id}" reference gets saved
 * as the "answer" to the question.
 */

// JSON responses for our AJAX upload handler
header('Content-Type: application/json');

// Initialize the application
require_once '../includes/init.php';

$security = Security::getInstance();

// ---------------------------------------------------------------
// METHOD ENFORCEMENT
// File uploads are POST requests. Always. Forever.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// CSRF CHECK
// Even file uploads need CSRF protection. Especially file uploads,
// actually. We don't want someone tricking a vendor into uploading
// files to the wrong assessment.
// ---------------------------------------------------------------
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'], false)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please try again.',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Load the assessment service for validation and persistence
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// Which assessment, which question?
$uuid = $_POST['assessment_uuid'] ?? '';
$questionId = intval($_POST['question_id'] ?? 0);

if (empty($uuid) || !$questionId) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// ---------------------------------------------------------------
// ASSESSMENT ACCESS CHECK
// Make sure this UUID is legit and the assessment is still active.
// No uploading files to completed or expired assessments.
// ---------------------------------------------------------------
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment || !$assessmentService->canAccess($assessment)) {
    echo json_encode(['success' => false, 'message' => 'Assessment not accessible']);
    exit;
}

// SECURITY (IDOR): the UUID token authorizes exactly ONE assessment, but the
// question_id arrives separately from the client. Reject any question that does
// not belong to this assessment's template so a token holder cannot attach files
// to off-template / cross-template questions.
if (!$assessmentService->questionBelongsToTemplate($questionId, $assessment['template_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid question for this assessment']);
    exit;
}

// ---------------------------------------------------------------
// VALIDATE AND STORE THE FILE
// FileUploadService handles the boring-but-critical stuff: MIME
// checks, size limits, encryption, and BLOB storage. One service,
// one set of rules, zero copy-paste crypto.
// ---------------------------------------------------------------
$uploadService = new FileUploadService();
$result = $uploadService->validate('file');

if ($result['error']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

try {
    $dbPath = $uploadService->encryptAndStore(
        $result['file'],
        (int)$assessment['id'],
        'attachment',
        $questionId
    );

    // Save the original filename (for display) and the db reference (for retrieval)
    $assessmentService->saveResponse($assessment['id'], $questionId, $security->cleanInput(basename($result['file']['name'])), $dbPath);

    // If this is the first interaction, bump status to in_progress
    if ($assessment['status'] === 'pending') {
        $assessmentService->updateStatus($assessment['id'], 'in_progress');
    }

    echo json_encode([
        'success' => true,
        'file_path' => $dbPath,
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (Exception $e) {
    error_log('File upload save error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save file record',
        'csrf_token' => $security->getCSRFToken()
    ]);
}
