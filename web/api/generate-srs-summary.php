<?php
/**
 * API: Generate SRS Executive Summary via AI Platform
 *
 * Called via AJAX from vendor-srs-details.php.
 * Collects vendor data and sends it to the AI Platform for AI-generated executive summary.
 * Returns JSON with the generated HTML summary.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

// CSRF validation
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

$vendorId = intval($input['vendor_id'] ?? 0);
if (!$vendorId) {
    echo json_encode(['error' => 'Missing vendor ID', 'csrf_token' => $newCsrfToken]);
    exit;
}

// AI Platform Service
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load vendor data
$vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
if (!$vendor) {
    echo json_encode(['error' => 'Vendor not found', 'csrf_token' => $newCsrfToken]);
    exit;
}

// SECURITY (IDOR): this endpoint previously had no authorization beyond requireAuth(),
// letting any authenticated user (e.g. a scoped stakeholder) pull a full executive /
// security / FAIR / financial summary for any vendor by id. Enforce the same SRS-view
// model as vendor-srs-details.php: org-wide reviewer roles, or a stakeholder assigned
// to this specific vendor.
$acl = ACL::getInstance();
$canView = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm')
        || $acl->hasGroup('auditor') || $acl->hasGroup('procurement')
        || Session::getInstance()->get('is_super_admin');
if (!$canView && $acl->hasGroup('stakeholder')) {
    $st = $db->fetchOne(
        'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
        [':rid' => $vendorId, ':uid' => $user['id']]
    );
    $canView = !empty($st);
}
if (!$canView) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load UpGuard data
require_once __DIR__ . '/../includes/classes/SRSService.php';
$srsService = new SRSService();
$latestScore = $srsService->getLatestScore($vendorId);
$risks = $latestScore ? $srsService->getRisksForScore($latestScore['id']) : [];
$ugConfig = $srsService->getScoringConfig();
$upguardName = $ugConfig['display_name'] ?? 'UpGuard';

// Load Shodan data
require_once __DIR__ . '/../includes/classes/ShodanService.php';
$shodanService = new ShodanService();
$shodanLatestScore = null;
$shodanFindings = [];
$shodanName = $shodanService->getScoringConfig()['display_name'] ?? 'Shodan';
try {
    $shodanLatestScore = $shodanService->getLatestScore($vendorId);
    if ($shodanLatestScore) {
        $shodanFindings = $shodanService->getFindingsForScore($shodanLatestScore['id']);
    }
} catch (Exception $e) {
    // Shodan tables may not exist
}

// Build prompt data
$promptParts = [];
$promptParts[] = "Vendor: {$vendor['vendor_name']}";
$promptParts[] = "Domain: " . ($vendor['vendor_domain'] ?? 'Not specified');
$promptParts[] = "Vendor Type: " . ($vendor['vendor_type'] ?? 'Not specified');
$promptParts[] = "Status: " . ucfirst(str_replace('_', ' ', $vendor['status'] ?? 'Unknown'));
$promptParts[] = "Tier: " . ($vendor['vendor_tier'] ? "Tier {$vendor['vendor_tier']}" : 'Not tiered');
if (!empty($vendor['product_service_description'])) {
    $promptParts[] = "Product/Service Description: " . $vendor['product_service_description'];
}

if ($latestScore) {
    $grade = $srsService->calculateGrade(intval($latestScore['score']));
    $promptParts[] = "\n{$upguardName} Score: {$latestScore['score']} (Grade: {$grade})";
    $promptParts[] = "Scored: " . date('M j, Y', strtotime($latestScore['scored_at']));
    $promptParts[] = "Risk Counts: Critical={$latestScore['critical_risks']}, High={$latestScore['high_risks']}, Medium={$latestScore['medium_risks']}, Low={$latestScore['low_risks']}, Info={$latestScore['info_risks']}";

    if (!empty($latestScore['category_scores'])) {
        $catScores = is_string($latestScore['category_scores'])
            ? json_decode($latestScore['category_scores'], true) : $latestScore['category_scores'];
        if (!empty($catScores)) {
            $parts = [];
            foreach ($catScores as $cat => $val) {
                $parts[] = ucwords(str_replace('_', ' ', $cat)) . ": {$val}";
            }
            $promptParts[] = "{$upguardName} Categories: " . implode(', ', $parts);
        }
    }
}

if (!empty($risks)) {
    $riskSummary = [];
    $sevCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
    foreach ($risks as $r) {
        $sev = $r['severity'] ?? 'info';
        $sevCounts[$sev] = ($sevCounts[$sev] ?? 0) + 1;
    }
    $promptParts[] = "Identified Risks: " . count($risks) . " total (Critical: {$sevCounts['critical']}, High: {$sevCounts['high']}, Medium: {$sevCounts['medium']}, Low: {$sevCounts['low']}, Info: {$sevCounts['info']})";

    // Include top 10 critical/high risks
    $topRisks = array_filter($risks, fn($r) => in_array($r['severity'] ?? '', ['critical', 'high']));
    $topRisks = array_slice($topRisks, 0, 10);
    if (!empty($topRisks)) {
        $promptParts[] = "Top Critical/High Risks:";
        foreach ($topRisks as $r) {
            $promptParts[] = "  - [{$r['severity']}] {$r['risk_name']}: {$r['description']}";
        }
    }
}

if ($shodanLatestScore) {
    $shGrade = $shodanService->calculateGrade(intval($shodanLatestScore['score']));
    $promptParts[] = "\n{$shodanName} Score: {$shodanLatestScore['score']}% (Grade: {$shGrade})";
    $promptParts[] = "Traffic Light: " . ($shodanLatestScore['traffic_light'] ?? 'N/A');
    $promptParts[] = "Open Ports: " . intval($shodanLatestScore['open_ports_count'] ?? 0);
    $promptParts[] = "Vulnerabilities: " . intval($shodanLatestScore['vuln_count'] ?? 0) . " (Critical: " . intval($shodanLatestScore['critical_vulns'] ?? 0) . ", High: " . intval($shodanLatestScore['high_vulns'] ?? 0) . ")";

    if (!empty($shodanLatestScore['category_scores'])) {
        $shCatScores = is_string($shodanLatestScore['category_scores'])
            ? json_decode($shodanLatestScore['category_scores'], true) : $shodanLatestScore['category_scores'];
        if (!empty($shCatScores)) {
            $parts = [];
            foreach ($shCatScores as $cat => $val) {
                $parts[] = ucwords(str_replace('_', ' ', $cat)) . ": {$val}/100";
            }
            $promptParts[] = "{$shodanName} Categories: " . implode(', ', $parts);
        }
    }

    // Summarize key negative findings
    $negFindings = array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'negative');
    if (!empty($negFindings)) {
        $promptParts[] = "Key Negative Findings:";
        $count = 0;
        foreach ($negFindings as $f) {
            if ($count >= 15) break;
            $promptParts[] = "  - " . ($f['description'] ?? $f['service_name'] ?? 'Unknown');
            $count++;
        }
    }

    // Summarize key positive findings
    $posFindings = array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'positive');
    $promptParts[] = "Positive Security Signals: " . count($posFindings);
}

// Load FAIR analysis data if available
$fairEncryption = new Encryption();
if (!empty($vendor['vendor_name'])) {
    $fairAnalysis = $db->fetchOne(
        "SELECT status, risk_output, loss_event_frequency, ale,
                primary_loss_magnitude, secondary_loss_magnitude, recommended_liability,
                vendor_cyber_insurance_coverage, scope_of_work
         FROM tprm_results
         WHERE vendor_name = :vendor_name
         ORDER BY created_at DESC
         LIMIT 1",
        [':vendor_name' => $vendor['vendor_name']]
    );
    if ($fairAnalysis) {
        $fairEncFields = ['loss_event_frequency', 'ale', 'primary_loss_magnitude',
            'secondary_loss_magnitude', 'recommended_liability', 'vendor_cyber_insurance_coverage', 'scope_of_work'];
        foreach ($fairEncFields as $field) {
            if (!empty($fairAnalysis[$field])) {
                $fairAnalysis[$field] = $fairEncryption->decrypt($fairAnalysis[$field]);
            }
        }
        $promptParts[] = "\nFAIR (Factor Analysis of Information Risk) Assessment:";
        $promptParts[] = "Risk Level: " . ($fairAnalysis['risk_output'] ?? 'N/A');
        $promptParts[] = "Status: " . ucfirst($fairAnalysis['status'] ?? 'Draft');
        if (!empty($fairAnalysis['ale'])) {
            $promptParts[] = "Annual Loss Expectancy (ALE): $" . number_format((float)$fairAnalysis['ale'], 0);
        }
        if (!empty($fairAnalysis['loss_event_frequency'])) {
            $promptParts[] = "Loss Event Frequency (LEF): " . $fairAnalysis['loss_event_frequency'];
        }
        if (!empty($fairAnalysis['primary_loss_magnitude'])) {
            $promptParts[] = "Primary Loss Magnitude (PLM): $" . number_format((float)$fairAnalysis['primary_loss_magnitude'], 0);
        }
        if (!empty($fairAnalysis['secondary_loss_magnitude'])) {
            $promptParts[] = "Secondary Loss Magnitude (SLM): $" . number_format((float)$fairAnalysis['secondary_loss_magnitude'], 0);
        }
        if (!empty($fairAnalysis['recommended_liability'])) {
            $promptParts[] = "Recommended Cyber Insurance Coverage: $" . number_format((float)$fairAnalysis['recommended_liability'], 0);
        }
        if (!empty($fairAnalysis['vendor_cyber_insurance_coverage'])) {
            $promptParts[] = "Current Vendor Cyber Insurance: $" . number_format((float)$fairAnalysis['vendor_cyber_insurance_coverage'], 0);
        }
        if (!empty($fairAnalysis['scope_of_work'])) {
            $promptParts[] = "Scope of Work: " . $fairAnalysis['scope_of_work'];
        }
    }
}

$vendorData = implode("\n", $promptParts);

$prompt = "You are a cybersecurity analyst writing an executive summary for a Third-Party Risk Management (TPRM) vendor security assessment report.

CRITICAL INSTRUCTIONS:
- Write a professional, comprehensive executive summary suitable for C-level executives and board presentations
- Use the EXACT data values provided - do not fabricate or estimate any numbers
- Output clean HTML using <h4> for section headers, <p> for paragraphs, <ul>/<li> for lists, <strong> for emphasis
- DO NOT include any meta-commentary, character counts, or notes about the response
- DO NOT include markdown code fences
- Keep the total response under 3000 characters

Generate the following sections:

1. **Executive Overview** - 3-4 sentences summarizing what the vendor does (from the Product/Service Description), their overall security posture, scores, and risk level
2. **Security Posture Analysis** - Analysis of the scoring data from both providers (if available), highlighting strengths and weaknesses
3. **Key Risk Findings** - The most significant risks and vulnerabilities discovered, organized by severity
4. **Infrastructure Assessment** - Summary of network security, TLS configuration, application hardening, and email security (if Shodan data available)
5. **Financial Risk Analysis** - If FAIR data is provided, summarize the Annual Loss Expectancy, Loss Event Frequency, loss magnitudes, and cyber insurance coverage adequacy. Compare recommended coverage to actual coverage.
6. **Recommendations** - 4-6 actionable recommendations prioritized by impact

VENDOR DATA:
{$vendorData}

Output ONLY the HTML content. No preamble, no disclaimers, no meta-commentary.";

$messages = [
    ['role' => 'user', 'content' => $prompt]
];

$result = $ai->chatCompletion($messages, [
    'max_tokens' => max($ai->getMaxTokens(), 2000),
]);

if (!$result['success']) {
    error_log("AI Platform API error (srs-summary): " . $result['error']);
    echo json_encode(['error' => 'AI service error: ' . $result['error'], 'csrf_token' => $newCsrfToken]);
    exit;
}

$content = $result['content'];

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
$allowed = '<h3><h4><h5><p><ul><ol><li><strong><em><b><i><br><table><thead><tbody><tr><th><td>';
$content = strip_tags(trim($content), $allowed);
$content = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $content);

echo json_encode([
    'success' => true,
    'summary' => $content,
    'csrf_token' => $newCsrfToken,
]);
