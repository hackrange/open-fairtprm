<?php
/**
 * API: Apply AI Auto-Fill Results to Assessment
 *
 * Called by the frontend after polling detects that an assessment_autofill
 * AI job has completed. Saves the validated answers to the assessment.
 *
 * Author: Tim Rice
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF validation
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();

// Permission check: admin or cyber_tprm only
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
if (!$isAdmin && !$isCyberTPRM) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Validate job_id
$jobId = intval($input['job_id'] ?? 0);
if (!$jobId) {
    echo json_encode(['error' => 'Missing job ID', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load completed job (must belong to this user)
$job = $db->fetchOne(
    "SELECT id, status, result_payload, request_payload FROM ai_job_queue
     WHERE id = ? AND user_id = ? AND job_type = 'assessment_autofill'",
    [$jobId, $user['id']]
);

if (!$job || $job['status'] !== 'completed') {
    echo json_encode(['error' => 'Job not found or not yet completed', 'csrf_token' => $newCsrfToken]);
    exit;
}

$result = json_decode($job['result_payload'], true);
$requestPayload = json_decode($job['request_payload'], true);

if (!$result || empty($result['answers'])) {
    echo json_encode([
        'success' => true,
        'filled' => 0,
        'skipped' => $result['total'] ?? 0,
        'total' => $result['total'] ?? 0,
        'csrf_token' => $newCsrfToken,
    ]);
    exit;
}

$assessmentId = intval($requestPayload['context']['assessment_id'] ?? 0);
$assessmentStatus = $requestPayload['context']['assessment_status'] ?? '';

if (!$assessmentId) {
    echo json_encode(['error' => 'Invalid assessment data in job', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Save each validated answer
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// SECURITY (IDOR): only write answers whose question_id actually belongs to this
// assessment's template, mirroring the autosave/batch-save/file-upload guards.
// Prevents off-template / cross-template writes (and field-mapped mutations such
// as vendor_name) if a malformed/poisoned answer id reaches the apply step.
$applyAssessment = $assessmentService->getAssessmentById($assessmentId);
if (!$applyAssessment) {
    echo json_encode(['error' => 'Assessment not found', 'csrf_token' => $newCsrfToken]);
    exit;
}
$applyTemplateId = $applyAssessment['template_id'];

$filled = 0;
foreach ($result['answers'] as $answer) {
    if (!$assessmentService->questionBelongsToTemplate($answer['id'] ?? 0, $applyTemplateId)) {
        continue;
    }
    $assessmentService->saveResponse($assessmentId, $answer['id'], $answer['value']);
    $filled++;
}

// Update assessment status from pending to in_progress if we filled anything
if ($filled > 0 && $assessmentStatus === 'pending') {
    $assessmentService->updateStatus($assessmentId, 'in_progress');
}

echo json_encode([
    'success' => true,
    'filled' => $filled,
    'skipped' => ($result['total'] ?? 0) - $filled,
    'total' => $result['total'] ?? 0,
    'csrf_token' => $newCsrfToken,
]);
