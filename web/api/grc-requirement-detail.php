<?php
/**
 * API: Generate GRC Framework Requirement Detail via AI Platform
 *
 * Called via AJAX from grc-audits.php when clicking the "?" icon on a requirement.
 * Generates factual, framework-based detail about the requirement with examples.
 * Returns JSON with HTML content.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

// SECURITY: limit this GRC helper to GRC roles (matches grc-assessment-control.php);
// otherwise any authenticated user could enumerate framework requirement data.
if (!hasGroup(['administrator', 'cyber_grc', 'auditor']) && !Auth::getInstance()->isAdmin() && !Session::getInstance()->get('is_super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$auth = Auth::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();
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

$requirementId = intval($input['requirement_id'] ?? 0);
if (!$requirementId) {
    echo json_encode(['error' => 'Missing requirement ID', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load requirement and framework data
$requirement = $db->fetchOne(
    'SELECT fr.*, f.code as framework_code, f.name as framework_name, f.version as framework_version
     FROM grc_framework_requirements fr
     LEFT JOIN grc_frameworks f ON f.id = fr.framework_id
     WHERE fr.id = :id',
    [':id' => $requirementId]
);

if (!$requirement) {
    echo json_encode(['error' => 'Requirement not found', 'csrf_token' => $newCsrfToken]);
    exit;
}

// AI Platform Service
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

$frameworkLabel = trim(($requirement['framework_code'] ?? '') . ' ' . ($requirement['framework_name'] ?? ''));
$frameworkVersion = $requirement['framework_version'] ?? '';

$messages = [
    [
        'role' => 'system',
        'content' => 'You are a GRC (Governance, Risk, Compliance) subject matter expert with deep knowledge of compliance frameworks including SOC 2, ISO 27001, SOX, PCI DSS, NIST CSF 2.0, CMMC, and NIST 800-171.

Your task is to provide factual, accurate detail about a specific framework requirement. You MUST:
- Be strictly factual based on the actual published framework standard
- Explain what the requirement means in practice
- Provide 2-3 concrete examples of evidence or controls that satisfy this requirement
- Note common audit pitfalls or areas where organizations often fall short
- Keep explanations clear and useful for auditors performing assessments
- Output clean HTML using <h5> for sub-headers, <p> for paragraphs, <ul>/<li> for lists, <strong> for emphasis
- DO NOT fabricate requirements or details that are not part of the actual framework
- DO NOT include markdown code fences
- Keep the total response under 2000 characters'
    ],
    [
        'role' => 'user',
        'content' => "Provide detailed, factual information about this compliance framework requirement:

Framework: {$frameworkLabel}" . ($frameworkVersion ? " (Version: {$frameworkVersion})" : '') . "
Requirement Reference: {$requirement['requirement_ref']}
Requirement Title: {$requirement['title']}
Description: " . ($requirement['description'] ?? 'Not specified') . "

Generate the following sections:
1. **What This Requirement Means** - Plain-language explanation of what this requirement entails
2. **Examples of Compliance Evidence** - 2-3 specific examples of evidence or controls that demonstrate compliance
3. **Common Pitfalls** - 2-3 common mistakes organizations make with this requirement

Output ONLY the HTML content. No preamble, no disclaimers."
    ]
];

$result = $ai->chatCompletion($messages, [
    'max_tokens' => max($ai->getMaxTokens(), 1500),
]);

if (!$result['success']) {
    error_log("AI Platform API error (grc-requirement-detail): " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$content = trim($result['content'] ?? '');

// Clean up markdown fences and stray HTML wrappers
$content = preg_replace('/```html\s*/i', '', $content);
$content = preg_replace('/```\s*$/s', '', $content);
$content = preg_replace('/```/s', '', $content);
$content = preg_replace('/<\!DOCTYPE[^>]*>/i', '', $content);
$content = preg_replace('/<\/?html[^>]*>/i', '', $content);
$content = preg_replace('/<\/?head[^>]*>/i', '', $content);
$content = preg_replace('/<\/?body[^>]*>/i', '', $content);
$content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);

// Sanitize: allow only safe tags
$allowed = '<h4><h5><p><ul><ol><li><strong><em><b><i><br><table><thead><tbody><tr><th><td>';
$content = strip_tags(trim($content), $allowed);
$content = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $content);

echo json_encode([
    'success' => true,
    'content' => $content,
    'requirement_ref' => $requirement['requirement_ref'],
    'requirement_title' => $requirement['title'],
    'framework' => $frameworkLabel,
    'csrf_token' => $newCsrfToken,
]);
