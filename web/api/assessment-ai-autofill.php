<?php
/**
 * API: AI Auto-Fill Assessment from Certification Documents
 *
 * Called via AJAX from vendor-assessment-view.php.
 * Extracts text from vendor's non-expired certification PDFs,
 * sends to AI to answer unanswered assessment questions,
 * and saves the responses.
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

// Validate assessment_id
$assessmentId = intval($input['assessment_id'] ?? 0);
if (!$assessmentId) {
    echo json_encode(['error' => 'Missing assessment ID', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load assessment
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessment = $assessmentService->getAssessmentById($assessmentId);

if (!$assessment) {
    echo json_encode(['error' => 'Assessment not found', 'csrf_token' => $newCsrfToken]);
    exit;
}

if (!$assessment['vendor_request_id']) {
    echo json_encode(['error' => 'Assessment is not linked to a vendor', 'csrf_token' => $newCsrfToken]);
    exit;
}

if ($assessment['status'] === 'completed') {
    echo json_encode(['error' => 'Cannot auto-fill a completed assessment', 'csrf_token' => $newCsrfToken]);
    exit;
}

// AI Platform Service
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Encryption instance needed for decrypting vendor documents
$encryption = new Encryption();

// ============================================================================
// Step 1: Load assessment questions and identify unanswered ones
// ============================================================================
$sections = $assessmentService->getSections($assessment['template_id']);
$responses = $assessmentService->getResponses($assessment['id']);

$unansweredQuestions = [];
foreach ($sections as $section) {
    $questions = $assessmentService->getQuestions($section['id']);
    foreach ($questions as $q) {
        // Skip file-type questions
        if ($q['question_type'] === 'file') continue;

        // Skip already-answered questions
        $response = $responses[$q['id']] ?? null;
        if ($response && $response['response_value'] !== null && $response['response_value'] !== '') {
            continue;
        }

        $unansweredQuestions[$q['id']] = $q;
    }
}

if (empty($unansweredQuestions)) {
    echo json_encode(['error' => 'All questions are already answered', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Step 2: Query non-expired certification documents (PDF, CSV, XLS, XLSX)
// ============================================================================
require_once __DIR__ . '/../includes/classes/DocumentTextExtractor.php';

$certDocs = $db->fetchAll(
    "SELECT id, certification_type, certification_expiration_date,
            original_filename, mime_type, encrypted_data
     FROM vendor_documents
     WHERE vendor_request_id = :id
       AND document_type = 'certification'
       AND is_active = 1
       AND (certification_expiration_date IS NULL OR certification_expiration_date >= CURDATE())
       AND mime_type IN ('application/pdf', 'text/csv', 'application/vnd.ms-excel',
                         'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
     ORDER BY created_at DESC
     LIMIT 5",
    [':id' => $assessment['vendor_request_id']]
);

if (empty($certDocs)) {
    echo json_encode(['error' => 'No valid certification documents found for this vendor.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Step 3: Extract text from each certification document
// ============================================================================
$maxCharsPerDoc = 4000;
$maxTotalChars = 12000;
$documentTexts = [];
$totalChars = 0;
foreach ($certDocs as $doc) {
    if ($totalChars >= $maxTotalChars) break;

    try {
        $decrypted = $encryption->decryptRaw($doc['encrypted_data']);
        if (empty($decrypted)) continue;

        $remaining = $maxTotalChars - $totalChars;
        $limit = min($maxCharsPerDoc, $remaining);
        $extractedText = DocumentTextExtractor::extract($decrypted, $doc['mime_type'], $limit);
        unset($decrypted);

        if ($extractedText !== null) {
            $certLabel = $doc['certification_type'] ?: $doc['original_filename'];
            $expiryStr = $doc['certification_expiration_date']
                ? date('Y-m-d', strtotime($doc['certification_expiration_date']))
                : 'No expiration';
            $entry = "=== {$certLabel} (expires: {$expiryStr}) — {$doc['original_filename']} ===\n{$extractedText}";
            $documentTexts[] = $entry;
            $totalChars += mb_strlen($entry);
        }
    } catch (Exception $e) {
        // Skip this document
    }
}

if (empty($documentTexts)) {
    echo json_encode(['error' => 'Could not extract text from any certification documents.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Step 4: Build AI prompt
// ============================================================================
$vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $assessment['vendor_request_id']]);
$vendorName = $vendor ? ($vendor['vendor_name'] ?? '') : '';
$vendorDomain = $vendor ? ($vendor['vendor_domain'] ?? '') : '';

$certTexts = implode("\n\n", $documentTexts);

// Build question list for the prompt
$questionLines = [];
foreach ($unansweredQuestions as $q) {
    $line = "Q[{$q['id']}] ({$q['question_type']}";
    if (!empty($q['options']) && is_array($q['options'])) {
        $optionValues = array_map(function($opt) {
            return is_array($opt) ? ($opt['value'] ?? $opt['label'] ?? '') : $opt;
        }, $q['options']);
        $line .= ', options: ' . json_encode($optionValues);
    }
    $line .= "): {$q['question_text']}";
    $questionLines[] = $line;
}

$questionsText = implode("\n", $questionLines);

$vendorContext = "VENDOR: {$vendorName}";
if ($vendorDomain) {
    $vendorContext .= "\nDOMAIN: {$vendorDomain}";
}

$prompt = "You are a security analyst pre-filling a vendor security assessment questionnaire using information found in the vendor's certification documents.

{$vendorContext}

CERTIFICATION DOCUMENTS:
{$certTexts}

QUESTIONS TO ANSWER:
{$questionsText}

INSTRUCTIONS:
- Answer ONLY based on information explicitly found in the documents above.
- For select/radio/button_group questions: your answer MUST exactly match one of the provided options.
- For checkbox/button_group_multi questions: provide a JSON array of matching option values.
- For text/textarea questions: provide a clear, concise answer.
- For textarea answers, append the source document on a new line: [Source: {certification type name}]
- For number questions: provide only a numeric value.
- For date questions: use YYYY-MM-DD format.
- For negative answers (e.g. \"No\", \"None\", \"Not implemented\"): only select these if the documents explicitly confirm the negative. If a topic is simply not mentioned, skip the question instead.
- Skip any question you cannot confidently answer from the provided documents. Leave it out of the response entirely. Do NOT include it with values like \"Not specified\", \"N/A\", \"Not found\", or similar placeholders.
- Do NOT fabricate or assume information not present in the documents.

Return ONLY valid JSON in this exact format (no markdown fences, no commentary):
{\"answers\":[{\"id\":123,\"value\":\"answer text\"},{\"id\":456,\"value\":[\"option1\",\"option2\"]}]}";

// ============================================================================
// Step 5: Send to AI
// ============================================================================
$messages = [
    ['role' => 'user', 'content' => $prompt]
];

$result = $ai->chatCompletion($messages, [
    'purpose' => 'fair',
    'temperature' => 0.3,
    'max_tokens' => max($ai->getMaxTokens(), 4000),
]);

if (!$result['success']) {
    error_log("Assessment AI auto-fill error: " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$content = $result['content'];

// ============================================================================
// Step 6: Parse response and save answers
// ============================================================================

// Strip markdown fences if present
$content = preg_replace('/```json\s*/i', '', $content);
$content = preg_replace('/```\s*$/s', '', $content);
$content = preg_replace('/```/', '', $content);
$content = trim($content);

// Find the JSON object in the response
$firstBrace = strpos($content, '{');
$lastBrace = strrpos($content, '}');
if ($firstBrace === false || $lastBrace === false) {
    error_log("Assessment AI auto-fill error: No JSON object found in AI response");
    echo json_encode(['error' => 'AI returned an invalid response format. Please try again.', 'csrf_token' => $newCsrfToken]);
    exit;
}

$jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
$parsed = json_decode($jsonStr, true);

if (!$parsed || !isset($parsed['answers']) || !is_array($parsed['answers'])) {
    error_log("Assessment AI auto-fill error: Invalid JSON structure in AI response");
    echo json_encode(['error' => 'AI returned an invalid response format. Please try again.', 'csrf_token' => $newCsrfToken]);
    exit;
}

$filled = 0;
$skipped = 0;
$totalUnanswered = count($unansweredQuestions);

foreach ($parsed['answers'] as $answer) {
    $questionId = intval($answer['id'] ?? 0);
    $value = $answer['value'] ?? null;

    // Validate question exists in our unanswered set
    if (!$questionId || !isset($unansweredQuestions[$questionId])) {
        $skipped++;
        continue;
    }

    $q = $unansweredQuestions[$questionId];
    $qType = $q['question_type'];

    // Skip empty values and non-answers the AI returns when it doesn't have real data
    if ($value === null || $value === '') {
        $skipped++;
        continue;
    }
    if (is_string($value)) {
        $lowerVal = strtolower(trim($value));
        if (in_array($lowerVal, [
            'n/a', 'na', 'not specified', 'not specified in document',
            'not specified in documents', 'not available', 'not provided',
            'not found', 'not found in document', 'not found in documents',
            'not mentioned', 'not mentioned in document', 'not mentioned in documents',
            'unknown', 'none', 'none specified', 'not applicable',
        ]) || preg_match('/^not\s+(specified|provided|found|mentioned|available|stated|indicated)/i', $lowerVal)) {
            $skipped++;
            continue;
        }
    }

    // Validate based on question type
    $validOptions = [];
    if (!empty($q['options']) && is_array($q['options'])) {
        $validOptions = array_map(function($opt) {
            return is_array($opt) ? ($opt['value'] ?? $opt['label'] ?? '') : $opt;
        }, $q['options']);
    }

    if (in_array($qType, ['select', 'radio', 'button_group'])) {
        // Single-select: value must match an option exactly
        if (!empty($validOptions) && !in_array($value, $validOptions, true)) {
            $skipped++;
            continue;
        }
    } elseif (in_array($qType, ['checkbox', 'button_group_multi'])) {
        // Multi-select: value must be an array, each matching an option
        if (!is_array($value)) {
            $skipped++;
            continue;
        }
        if (!empty($validOptions)) {
            $allValid = true;
            foreach ($value as $v) {
                if (!in_array($v, $validOptions, true)) {
                    $allValid = false;
                    break;
                }
            }
            if (!$allValid) {
                $skipped++;
                continue;
            }
        }
        $value = json_encode($value);
    }

    // Save the response
    $assessmentService->saveResponse($assessmentId, $questionId, $value);
    $filled++;
}

// Update assessment status from pending to in_progress if we filled anything
if ($filled > 0 && $assessment['status'] === 'pending') {
    $assessmentService->updateStatus($assessmentId, 'in_progress');
}

echo json_encode([
    'success' => true,
    'filled' => $filled,
    'skipped' => $totalUnanswered - $filled,
    'total' => $totalUnanswered,
    'csrf_token' => $newCsrfToken,
]);
