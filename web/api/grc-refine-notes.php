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

// SECURITY: limit this AI helper to GRC roles (matches grc-assessment-control.php);
// otherwise any authenticated user (incl. a scoped stakeholder) could drive AI
// completions (token/cost abuse) and read framework requirement data.
if (!hasGroup(['administrator', 'cyber_grc', 'auditor']) && !Auth::getInstance()->isAdmin() && !Session::getInstance()->get('is_super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

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
if (!$input || empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
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
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
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

$result = $ai->chatCompletion($messages, [
    'max_tokens' => max($ai->getMaxTokens(), 1000),
]);

if (!$result['success']) {
    error_log("AI Platform API error (grc-refine-notes): " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$refined = trim($result['content'] ?? '');

// Strip any markdown fences or wrappers
$refined = preg_replace('/```[a-z]*\s*/i', '', $refined);
$refined = preg_replace('/```\s*$/s', '', $refined);
$refined = trim($refined);

echo json_encode([
    'success' => true,
    'refined' => $refined,
    'csrf_token' => $newCsrfToken,
]);
