<?php
/**
 * Assessment Autosave API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Saves individual question responses as the vendor fills out their assessment.
 * Unlike most of our endpoints, this one does NOT require traditional authentication
 * because vendors are external users -- they access their assessment via a UUID token
 * in the URL. Think of the UUID as a really long, really random password that we
 * email to the vendor. Each time they answer a question, this endpoint fires off and
 * saves that single answer. It also bumps the assessment status from "pending" to
 * "in_progress" on the first save, so we know someone actually started working on it
 * instead of letting it collect dust in their inbox.
 */

// JSON all the way down
header('Content-Type: application/json');

// Boot the app
require_once '../includes/init.php';

// No traditional auth required here -- the UUID IS the auth.
// But we still need CSRF protection because we're not savages.
$security = Security::getInstance();

// ---------------------------------------------------------------
// METHOD CHECK
// Saving data = POST. This is non-negotiable.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// CSRF VALIDATION
// Even though we don't require login, we still validate CSRF tokens
// because cross-site request forgery doesn't care about your auth model.
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

// Load up the assessment service for all our data operations
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// Pull the three things we need: which assessment, which question, what answer
$uuid = $_POST['assessment_uuid'] ?? '';
$questionId = intval($_POST['question_id'] ?? 0);
$value = $security->cleanInput($_POST['value'] ?? '');

// "Clear previous answers" action: wipe EVERY saved response for this assessment
// (used by the button shown when a form was pre-populated from a prior assessment).
// No question_id needed. Auth is the UUID token + CSRF, same as a normal save.
if (($_POST['action'] ?? '') === 'clear_all') {
    if (empty($uuid)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    $clearAssessment = $assessmentService->getAssessmentByUUID($uuid);
    if (!$clearAssessment || !$assessmentService->canAccess($clearAssessment)) {
        echo json_encode(['success' => false, 'message' => 'Assessment is not accessible']);
        exit;
    }
    Database::getInstance()->query(
        'DELETE FROM vendor_assessment_responses WHERE assessment_id = :id',
        [':id' => $clearAssessment['id']]
    );
    // Form is now blank again -- drop it back to 'pending'.
    $assessmentService->updateStatus($clearAssessment['id'], 'pending');
    echo json_encode([
        'success' => true,
        'message' => 'Previous answers cleared',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Can't save without knowing WHAT to save and WHERE to save it
if (empty($uuid) || !$questionId) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// ---------------------------------------------------------------
// ASSESSMENT VALIDATION
// Look up the assessment by UUID. If it doesn't exist or the
// access window has closed (expired, already completed, etc.),
// reject the save. No point saving answers to a dead assessment.
// ---------------------------------------------------------------
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment) {
    echo json_encode(['success' => false, 'message' => 'Assessment not found']);
    exit;
}

if (!$assessmentService->canAccess($assessment)) {
    echo json_encode(['success' => false, 'message' => 'Assessment is not accessible']);
    exit;
}

// SECURITY (IDOR): the UUID token authorizes exactly ONE assessment, but the
// question_id arrives separately from the client. Reject any question that does
// not belong to this assessment's template so a token holder cannot write
// off-template / cross-template responses.
if (!$assessmentService->questionBelongsToTemplate($questionId, $assessment['template_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid question for this assessment',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// ---------------------------------------------------------------
// EMAIL VALIDATION
// If the question is an email type, validate the value server-side
// before saving. Client-side validation is nice, but server-side
// is where the buck stops.
// ---------------------------------------------------------------
if (!empty($value)) {
    $question = $assessmentService->getQuestion($questionId);
    if ($question && $question['question_type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid email address',
            'csrf_token' => $security->getCSRFToken()
        ]);
        exit;
    }
    // Canonicalise type-specific values server-side so the stored answer is
    // uniform regardless of what the browser sent. Phone -> E.164 ("+13144445544");
    // VAT -> uppercased, punctuation-stripped ("DE123456789"). VAT validity against
    // VIES is advisory and handled client-side, so we never block the save here.
    if ($question && $question['question_type'] === 'phone') {
        $value = phone_normalize_e164($value);
    } elseif ($question && $question['question_type'] === 'vat') {
        $value = vat_normalize($value);
    }
}

// ---------------------------------------------------------------
// SAVE THE RESPONSE
// Persist the answer and optionally flip the status from "pending"
// to "in_progress" if this is the first answer being saved. That
// way, the internal team can see that the vendor has started working
// on it instead of wondering if they're ghosting us.
// ---------------------------------------------------------------
try {
    $assessmentService->saveResponse($assessment['id'], $questionId, $value);

    // First answer? Let's update the status so everyone knows
    // the vendor is actually alive and doing the thing.
    if ($assessment['status'] === 'pending') {
        $assessmentService->updateStatus($assessment['id'], 'in_progress');
    }

    // Sync field-mapped responses to the vendor record immediately
    // so changes show up on the vendor dashboard without re-submitting
    if ($assessment['vendor_request_id']) {
        $assessmentService->syncFieldToVendor($assessment['id'], $questionId, $value);
    }

    // Recalculate completion percentage so the progress bar updates live
    $completionStatus = $assessmentService->getCompletionStatus($assessment['id']);

    // Return the new CSRF token so subsequent requests can use it.
    // The token was consumed during validation and regenerated,
    // so the client needs the fresh one.
    echo json_encode([
        'success' => true,
        'csrf_token' => $security->getCSRFToken(),
        'progress' => $completionStatus['percentage'] ?? 0
    ]);
} catch (Exception $e) {
    // Something went wrong saving a single answer. Could be a constraint
    // violation, could be the DB having a bad hair day. Either way, log it.
    error_log('Autosave error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save response',
        'csrf_token' => $security->getCSRFToken()
    ]);
}
