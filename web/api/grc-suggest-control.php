<?php
/**
 * API: AI-powered control suggestion for a framework requirement
 *
 * Takes a requirement reference, title, framework code/name and returns
 * a JSON object with suggested control title, description, type, category,
 * status, frequency, and risk level.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

// SECURITY: limit this AI helper to GRC roles (matches grc-assessment-control.php);
// otherwise any authenticated user could drive AI completions (token/cost abuse).
if (!hasGroup(['administrator', 'cyber_grc', 'auditor']) && !Auth::getInstance()->isAdmin() && !Session::getInstance()->get('is_super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$auth = Auth::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$db = Database::getInstance();

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

$requirementId = (int)($input['requirement_id'] ?? 0);
$requirementRef = trim($input['requirement_ref'] ?? '');
$requirementTitle = trim($input['requirement_title'] ?? '');
$frameworkCode = trim($input['framework_code'] ?? '');
$frameworkName = trim($input['framework_name'] ?? '');

if ($requirementRef === '' || $requirementTitle === '') {
    echo json_encode(['error' => 'Requirement reference and title are required.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load requirement description from DB if available
$reqDescription = '';
if ($requirementId > 0) {
    $req = $db->fetchOne(
        'SELECT description FROM grc_framework_requirements WHERE id = :id',
        [':id' => $requirementId]
    );
    if ($req && !empty($req['description'])) {
        $reqDescription = $req['description'];
    }
}

require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured.', 'csrf_token' => $newCsrfToken]);
    exit;
}

$frameworkLabel = $frameworkCode;
if ($frameworkName) $frameworkLabel .= ' (' . $frameworkName . ')';

$descContext = '';
if ($reqDescription !== '') {
    $descContext = "\nRequirement Description: {$reqDescription}";
}

$messages = [
    [
        'role' => 'system',
        'content' => 'You are a senior GRC (Governance, Risk, Compliance) auditor and internal control design specialist. Your task is to suggest an internal control for a specific compliance framework requirement.

WRITING STYLE - CRITICAL:
- Write the description in the AFFIRMATIVE. State what the organization DOES, not what it should do.
- Use present tense, declarative statements: "The organization maintains...", "Management reviews...", "The system enforces..."
- Write as if documenting a control that is already in place and operating effectively.
- Use formal auditor-friendly language suitable for SOC 2 reports, ISO 27001 Statements of Applicability, or audit workpapers.
- Do NOT use conditional language ("should", "would", "could", "may"). Use definitive language ("maintains", "performs", "enforces", "validates").

You must respond with ONLY a valid JSON object (no markdown fences, no commentary) with these exact keys:
- "title": A concise control title (max 80 chars). Should describe what the control does, not restate the requirement. Example: "Quarterly Access Review and Recertification Process"
- "description": A detailed description (2-4 paragraphs) written in the affirmative that includes:
  * What the control does and how it operates, stated as fact (e.g., "The organization performs quarterly access reviews across all critical systems.")
  * Specific control activities with concrete examples (e.g., "System administrators generate access reports from the IAM platform. Department managers review and certify each user account within 10 business days.")
  * Evidence produced by the control (e.g., "Completed access review reports with manager sign-off are retained for the audit period.", "SIEM alerts are logged and tracked through the incident management system.")
  * How the control addresses the compliance requirement
- "control_type": One of: "preventive", "detective", "corrective", "directive"
- "control_category": One of: "technical", "administrative", "physical"
- "implementation_status": One of: "planned", "in_progress", "implemented", "not_applicable"
- "frequency": One of: "continuous", "daily", "weekly", "monthly", "quarterly", "annually", "ad_hoc"
- "risk_level": One of: "low", "medium", "high", "critical"

Guidelines for field selection:
- control_type: "preventive" for controls that stop issues before they occur (access controls, encryption), "detective" for controls that identify issues (monitoring, auditing), "corrective" for controls that fix issues after detection, "directive" for policies/standards
- control_category: "technical" for technology-based controls (firewalls, encryption, SIEM), "administrative" for process/policy controls (training, reviews, approvals), "physical" for physical security controls (locks, badges, cameras)
- frequency: Match to how often the control should execute (continuous for automated, quarterly for reviews, annually for audits)
- risk_level: Based on the criticality of the requirement to the overall framework compliance
- implementation_status: Default to "planned" for new controls

Be specific to the framework. SOC 2 controls should reference Trust Services Criteria, ISO 27001 should reference Annex A controls, PCI DSS should reference specific DSS requirements, NIST CSF should reference function/category/subcategory.'
    ],
    [
        'role' => 'user',
        'content' => "Suggest an internal control for the following compliance requirement:

Framework: {$frameworkLabel}
Requirement Reference: {$requirementRef}
Requirement Title: {$requirementTitle}{$descContext}

Respond with ONLY the JSON object."
    ]
];

$result = $ai->chatCompletion($messages, [
    'max_tokens' => max($ai->getMaxTokens(), 1500),
    'temperature' => 0.4,
]);

if (!$result['success']) {
    error_log("AI Platform API error (grc-suggest-control): " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$content = trim($result['content'] ?? '');

// Strip markdown fences if present
$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
$content = preg_replace('/```\s*$/s', '', $content);
$content = trim($content);

$suggestion = json_decode($content, true);

if (!$suggestion || !is_array($suggestion)) {
    error_log("AI returned invalid JSON for control suggestion: " . substr($content, 0, 500));
    echo json_encode(['error' => 'AI returned an invalid response. Please try again.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Validate enum values
$validTypes = ['preventive', 'detective', 'corrective', 'directive'];
$validCategories = ['technical', 'administrative', 'physical'];
$validStatuses = ['planned', 'in_progress', 'implemented', 'not_applicable'];
$validFrequencies = ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annually', 'ad_hoc'];
$validRiskLevels = ['low', 'medium', 'high', 'critical'];

$clean = [
    'title' => substr(trim($suggestion['title'] ?? $requirementTitle), 0, 500),
    'description' => trim($suggestion['description'] ?? ''),
    'control_type' => in_array($suggestion['control_type'] ?? '', $validTypes) ? $suggestion['control_type'] : 'preventive',
    'control_category' => in_array($suggestion['control_category'] ?? '', $validCategories) ? $suggestion['control_category'] : 'technical',
    'implementation_status' => in_array($suggestion['implementation_status'] ?? '', $validStatuses) ? $suggestion['implementation_status'] : 'planned',
    'frequency' => in_array($suggestion['frequency'] ?? '', $validFrequencies) ? $suggestion['frequency'] : 'ad_hoc',
    'risk_level' => in_array($suggestion['risk_level'] ?? '', $validRiskLevels) ? $suggestion['risk_level'] : 'medium',
];

echo json_encode([
    'success' => true,
    'suggestion' => $clean,
    'csrf_token' => $newCsrfToken,
]);
