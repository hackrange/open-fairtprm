<?php
/**
 * Assessment Submit API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "I'm done, grade my paper" endpoint for vendor security assessments. When a
 * vendor finishes filling out their assessment questionnaire and hits submit, this
 * is what catches it. It validates that all required fields are actually filled in
 * (because apparently "required" on the frontend is more of a suggestion), checks
 * if a certificate upload already handled completion, and if everything checks out,
 * marks the assessment as completed. If something's missing, it sends back a detailed
 * list of what's incomplete so the vendor can't claim they "didn't know what was missing."
 */

// JSON is our native tongue
header('Content-Type: application/json');

// Initialize the application framework
require_once '../includes/init.php';

$security = Security::getInstance();

// ---------------------------------------------------------------
// METHOD CHECK
// This is a submit action. POST or nothing. GET requests can go
// read a book or something.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// CSRF PROTECTION
// Verify the token so we know this request came from our form
// and not some shady cross-site shenanigans.
// ---------------------------------------------------------------
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please try again.',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Bring in the assessment service -- it does all the heavy lifting
// for assessment CRUD operations
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// Assessments are identified by UUID, not integer IDs, because
// we share these links with external vendors and sequential IDs
// are basically an invitation to go URL-surfing
$uuid = $_POST['assessment_uuid'] ?? '';

if (empty($uuid)) {
    echo json_encode(['success' => false, 'message' => 'Missing assessment ID']);
    exit;
}

// ---------------------------------------------------------------
// FETCH & VALIDATE ASSESSMENT
// Get the assessment by UUID and make sure the current user/vendor
// actually has permission to submit it. No submitting other people's
// homework around here.
// ---------------------------------------------------------------
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment || !$assessmentService->canAccess($assessment)) {
    echo json_encode(['success' => false, 'message' => 'Assessment not accessible']);
    exit;
}

// ---------------------------------------------------------------
// CERTIFICATE SHORTCUT CHECK
// In "skip" mode a certificate stands in for the whole questionnaire, so if one
// was uploaded the assessment is already complete -- just acknowledge it. In
// "minimal" mode the certificate is only one required step, so we must NOT
// short-circuit here; the vendor still has to answer the selected questions.
// ---------------------------------------------------------------
if ($assessment['certificate_uploaded']
    && $assessmentService->getEffectiveCertificateMode($assessment) !== 'minimal') {
    echo json_encode(['success' => true, 'message' => 'Assessment already completed via certificate upload']);
    exit;
}

// ---------------------------------------------------------------
// COMPLETENESS VALIDATION
// Check every section to make sure all required questions have
// answers. If anything's missing, build a shame list of incomplete
// sections with exact counts ("Security Controls 3/5 answered")
// so the vendor knows exactly what they skipped.
// ---------------------------------------------------------------
$completionStatus = $assessmentService->getCompletionStatus($assessment['id']);

// In minimal certificate mode the "required" set is the questions flagged
// include_in_minimal (not the template-wide is_required flag), and completion also
// requires the certificate itself -- handled explicitly below.
$certMode = $assessmentService->getEffectiveCertificateMode($assessment);
$minimalSubmit = ($certMode === 'minimal');

$missingQuestions = [];
$missingSections = [];
$firstIncompleteQuestion = null;
$allUnanswered = [];

// Build section index map so we can tell the frontend which ?section=N to navigate to
$allSections = $assessmentService->getSections($assessment['template_id']);
$sectionIndexMap = [];
foreach ($allSections as $idx => $sec) {
    $sectionIndexMap[$sec['id']] = $idx;
}

$responses = $assessmentService->getResponses($assessment['id']);

foreach ($completionStatus['sections'] as $sectionId => $section) {
    if (!$section['complete']) {
        $missingSections[] = $section['name'];
        $missingQuestions[] = $section['name'] . ' (' . $section['answered'] . '/' . $section['required'] . ' answered)';

        // Collect ALL unanswered required questions in this section
        $questions = $assessmentService->getQuestions($sectionId);
        foreach ($questions as $q) {
            // Skip conditional questions whose dependency is not met
            if (!$assessmentService->isQuestionVisible($q, $responses)) {
                continue;
            }
            $rv = $responses[$q['id']]['response_value'] ?? null;
            $fp = $responses[$q['id']]['file_path'] ?? null;
            $isAnswered = isset($responses[$q['id']]) && ($rv !== null && $rv !== '' || $fp !== null && $fp !== '');
            $isRequiredHere = $minimalSubmit ? !empty($q['include_in_minimal']) : !empty($q['is_required']);
            if ($isRequiredHere && !$isAnswered) {
                $item = [
                    'id' => $q['id'],
                    'text' => $q['question_text'],
                    'section_id' => $sectionId,
                    'section_index' => $sectionIndexMap[$sectionId] ?? 0,
                    'section_name' => $section['name']
                ];
                $allUnanswered[] = $item;
                if ($firstIncompleteQuestion === null) {
                    $firstIncompleteQuestion = $item;
                }
            }
        }
    }
}

// If there are incomplete sections, send back the list of unanswered questions
if (!empty($missingQuestions)) {
    error_log('Assessment ' . $assessment['id'] . ' incomplete sections: ' . json_encode($completionStatus['sections']));

    echo json_encode([
        'success' => false,
        'message' => 'Please complete all required questions before submitting.',
        'first_incomplete' => $firstIncompleteQuestion,
        'unanswered' => $allUnanswered,
        'incomplete_sections' => $missingSections,
        'details' => $completionStatus,
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Minimal certificate mode also requires the certificate itself, which isn't a
// question and so never appears in the section completion loop above.
if ($minimalSubmit && empty($assessment['certificate_uploaded'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please upload your certificate before submitting.',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// ---------------------------------------------------------------
// VALIDATE-ONLY PREFLIGHT
// The form calls this with validate_only=1 when the vendor clicks Submit, BEFORE
// collecting the attestation. If we reached this point, every required question
// (and, in minimal mode, the certificate) is satisfied -- so report completeness
// without saving attestation or marking the assessment completed. This lets the
// UI ask for attestation only once the answers are actually complete.
// ---------------------------------------------------------------
if (($_POST['validate_only'] ?? '') === '1') {
    echo json_encode([
        'success'   => true,
        'complete'  => true,
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// ---------------------------------------------------------------
// THE BIG MOMENT: MARK AS COMPLETED
// Everything checks out, flip the status to 'completed' and
// tell the vendor the good news. Party time.
// ---------------------------------------------------------------
try {
    // Capture and validate the submitter attestation (required to submit).
    $submitterName  = trim($_POST['submitter_name'] ?? '');
    $submitterTitle = trim($_POST['submitter_title'] ?? '');
    $submitterEmail = trim($_POST['submitter_email'] ?? '');
    // Attestation phone uses the Phone widget; canonicalise to E.164 server-side.
    $submitterPhone = phone_normalize_e164($_POST['submitter_phone'] ?? '');
    $submitterAttested = ($_POST['submitter_attested'] ?? '') === '1';
    if ($submitterName === '' || $submitterTitle === '' || $submitterPhone === ''
        || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) || !$submitterAttested) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide your name, title, valid email, phone number, and confirm the truthfulness attestation.',
            'csrf_token' => $security->getCSRFToken()
        ]);
        exit;
    }
    $assessmentService->saveSubmitterAttestation($assessment['id'], [
        'submitter_name'       => $submitterName,
        'submitter_title'      => $submitterTitle,
        'submitter_email'      => $submitterEmail,
        'submitter_phone'      => $submitterPhone,
        'submitter_ip_address' => $security->getClientIP(),
        'submitter_attested'   => 1,
    ]);

    $assessmentService->updateStatus($assessment['id'], 'completed');

    // Audit log -- wrapped in its own try/catch because this is a public form
    // and Auth may not have a logged-in user. Don't let audit failure block submission.
    try {
        $auth = Auth::getInstance();
        $userId = $auth->getUserId();
        $auth->audit($userId, 'assessment_submit', 'vendor_assessments', $assessment['id'], [
            'new' => ['uuid' => $uuid, 'status' => 'completed', 'vendor_request_id' => $assessment['vendor_request_id'] ?? null]
        ]);
    } catch (\Throwable $auditErr) {
        error_log('Assessment audit log failed (non-fatal): ' . $auditErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Assessment submitted successfully',
        'csrf_token' => $security->getCSRFToken()
    ]);
} catch (\Throwable $e) {
    error_log('Assessment submit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to submit assessment']);
}
