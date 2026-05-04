<?php
/**
 * API: Refine GRC Audit Requirement Notes via AI Platform
 *
 * Called via AJAX from grc-audits.php.
 * Takes auditor notes and rewrites them in professional auditor-friendly language.
 * Returns JSON with the refined HTML content for preview/accept flow.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !$security->validateCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();

$notes = trim($input['notes'] ?? '');
$requirementRef = trim($input['requirement_ref'] ?? '');
$requirementTitle = trim($input['requirement_title'] ?? '');
$assessmentStatus = trim($input['assessment_status'] ?? '');

if ($notes === '') {
    echo json_encode(['error' => 'No notes provided to refine', 'csrf_token' => $newCsrfToken]);
    exit;
}

// AI Platform Service
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'Service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

$contextParts = [];
if ($requirementRef) {
    $contextParts[] = "Requirement Reference: {$requirementRef}";
}
if ($requirementTitle) {
    $contextParts[] = "Requirement: {$requirementTitle}";
}
if ($assessmentStatus) {
    $contextParts[] = "Assessment Status: " . ucfirst(str_replace('_', ' ', $assessmentStatus));
}
$contextStr = !empty($contextParts) ? "\n\nCONTEXT:\n" . implode("\n", $contextParts) : '';

$messages = [
    [
        'role' => 'system',
        'content' => 'You are a senior GRC (Governance, Risk, Compliance) auditor. Your task is to rewrite auditor notes into professional, auditor-friendly language suitable for formal audit reports and compliance documentation. Follow these rules:
- Use precise, formal audit language (e.g., "The control was observed to be...", "Evidence reviewed indicates...", "Based on the assessment performed...")
- Be factual and objective — do not speculate or add information not present in the original notes
- Maintain the original meaning and intent
- Use proper audit terminology (e.g., "finding", "observation", "exception", "remediation", "compensating control")
- Keep the refined text concise but thorough
- Output plain text only — no HTML, no markdown, no bullet points unless the original uses them
- Do not add disclaimers, preambles, or meta-commentary about the refinement'
    ],
    [
        'role' => 'user',
        'content' => "Rewrite the following auditor notes in professional, auditor-friendly language for a formal audit report:{$contextStr}\n\nORIGINAL NOTES:\n{$notes}\n\nREFINED NOTES:"
    ]
];

$db = Database::getInstance();
$jobId = $db->insert('ai_job_queue', [
    'job_type' => 'grc_assistant',
    'status' => 'pending',
    'user_id' => $user['id'],
    'request_payload' => json_encode([
        'messages' => $messages,
        'ai_options' => ['max_tokens' => max($ai->getMaxTokens(), 1000)],
        'context' => ['action' => 'refine_notes'],
    ]),
]);

echo json_encode([
    'success' => true,
    'queued' => true,
    'job_id' => $jobId,
    'csrf_token' => $newCsrfToken,
]);
