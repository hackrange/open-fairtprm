<?php
/**
 * Assessment Batch Save API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Saves multiple question responses in a single request. This replaces the
 * sequential one-at-a-time save approach that was causing CSRF token chain
 * breaks. Instead of N requests each rotating the token, we do ONE request
 * that validates the token once, saves everything, and returns one fresh token.
 * Much less fragile, much happier vendors.
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

$security = Security::getInstance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF validation -- one check for all responses
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'], false)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please refresh the page.',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

$uuid = $security->cleanInput($_POST['assessment_uuid'] ?? '');
$responsesJson = $_POST['responses'] ?? '{}';

if (empty($uuid)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing assessment ID',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

$responses = json_decode($responsesJson, true);
if (!is_array($responses)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid responses format',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Validate the assessment exists and is accessible
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment) {
    echo json_encode([
        'success' => false,
        'message' => 'Assessment not found',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

if (!$assessmentService->canAccess($assessment)) {
    echo json_encode([
        'success' => false,
        'message' => 'Assessment is not accessible',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// Save all responses
$savedCount = 0;
$errors = [];

foreach ($responses as $questionId => $value) {
    $questionId = intval($questionId);
    if (!$questionId) continue;

    // SECURITY (IDOR): the UUID token authorizes exactly ONE assessment. Skip any
    // question that does not belong to this assessment's template so a token holder
    // cannot bulk-write off-template / cross-template responses. A poisoned key is
    // recorded as an error rather than aborting the whole legitimate batch.
    if (!$assessmentService->questionBelongsToTemplate($questionId, $assessment['template_id'])) {
        $errors[] = ['question_id' => $questionId, 'message' => 'Invalid question for this assessment'];
        continue;
    }

    $cleanValue = $security->cleanInput(is_array($value) ? json_encode($value) : $value);

    // Validate email fields server-side; canonicalise phone/VAT values so the
    // stored answer is uniform no matter what the client posted.
    if ($cleanValue !== '') {
        $question = $assessmentService->getQuestion($questionId);
        if ($question && $question['question_type'] === 'email' && !filter_var($cleanValue, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['question_id' => $questionId, 'message' => 'Invalid email address'];
            continue;
        }
        if ($question && $question['question_type'] === 'phone') {
            $cleanValue = phone_normalize_e164($cleanValue);
        } elseif ($question && $question['question_type'] === 'vat') {
            $cleanValue = vat_normalize($cleanValue);
        }
    }

    try {
        $assessmentService->saveResponse($assessment['id'], $questionId, $cleanValue);
        $savedCount++;

        // Sync field-mapped responses to vendor record
        if ($assessment['vendor_request_id']) {
            $assessmentService->syncFieldToVendor($assessment['id'], $questionId, $cleanValue);
        }
    } catch (Exception $e) {
        error_log('Batch save error for question ' . $questionId . ': ' . $e->getMessage());
        $errors[] = ['question_id' => $questionId, 'message' => 'Failed to save'];
    }
}

// Update status if this is the first interaction
if ($assessment['status'] === 'pending' && $savedCount > 0) {
    $assessmentService->updateStatus($assessment['id'], 'in_progress');
}

// Recalculate completion percentage so the progress bar updates live
$completionStatus = $assessmentService->getCompletionStatus($assessment['id']);

echo json_encode([
    'success' => empty($errors),
    'saved' => $savedCount,
    'errors' => $errors,
    'csrf_token' => $security->getCSRFToken(),
    'progress' => $completionStatus['percentage'] ?? 0
]);
