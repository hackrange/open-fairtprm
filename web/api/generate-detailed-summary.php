<?php
/**
 * API: Generate Detailed Summary AI Analysis via AI Platform
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Called via AJAX from vendor-detailed-summary.php. Collects ALL vendor data
 * from every source (UpGuard, Shodan, FAIR, technologies, assessments) plus
 * computed NIST CSF 2.0 scores and sends it to the AI Platform for narrative
 * analysis. Returns sanitized HTML with executive summary, gap analysis,
 * strengths, weaknesses, and recommendations — all mapped to NIST CSF.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json');

// Global error handler — convert real errors to exceptions (skip deprecations/notices)
set_error_handler(function($severity, $message, $file, $line) {
    if ($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) {
        return false; // let PHP handle these normally
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try { // wrap entire endpoint

$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();

// ACL check
$acl = ACL::getInstance();
$canView = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm') || $session->get('is_super_admin');
if (!$canView) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

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

$vendorId = intval($input['vendor_id'] ?? 0);
if (!$vendorId) {
    echo json_encode(['error' => 'Missing vendor ID', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Load AI Config
// ============================================================================
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'AI service is not configured', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Encryption instance needed for decrypting FAIR data and vendor documents
$encryption = new Encryption();

// ============================================================================
// Collect ALL Vendor Data
// ============================================================================
$vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
if (!$vendor) {
    echo json_encode(['error' => 'Vendor not found', 'csrf_token' => $newCsrfToken]);
    exit;
}

require_once __DIR__ . '/../includes/classes/SRSService.php';
require_once __DIR__ . '/../includes/classes/ShodanService.php';

$srsService = new SRSService();
$shodanService = new ShodanService();

$ugConfig = $srsService->getScoringConfig();
$shConfig = $shodanService->getScoringConfig();
$upguardName = $ugConfig['display_name'] ?? 'UpGuard';
$shodanName = $shConfig['display_name'] ?? 'Shodan';
$upguardMaxScore = (int)($ugConfig['max_score'] ?? 950);

// UpGuard
$latestScore = $srsService->getLatestScore($vendorId);
$risks = $latestScore ? $srsService->getRisksForScore($latestScore['id']) : [];
$ugCategoryScores = [];
if ($latestScore && !empty($latestScore['category_scores'])) {
    $ugCategoryScores = is_string($latestScore['category_scores'])
        ? json_decode($latestScore['category_scores'], true) : $latestScore['category_scores'];
    if (!is_array($ugCategoryScores)) $ugCategoryScores = [];
}

// Shodan
$shodanLatestScore = null;
$shodanFindings = [];
try {
    $shodanLatestScore = $shodanService->getLatestScore($vendorId);
    if ($shodanLatestScore) {
        $shodanFindings = $shodanService->getFindingsForScore($shodanLatestScore['id']);
    }
} catch (Exception $e) {}

$shCategoryScores = [];
if ($shodanLatestScore && !empty($shodanLatestScore['category_scores'])) {
    $shCategoryScores = is_string($shodanLatestScore['category_scores'])
        ? json_decode($shodanLatestScore['category_scores'], true) : $shodanLatestScore['category_scores'];
    if (!is_array($shCategoryScores)) $shCategoryScores = [];
}

// Combined
$combinedScore = $srsService->getCombinedScore($vendor);

// FAIR
$fairAnalysis = null;
if (!empty($vendor['vendor_name'])) {
    $fairRow = $db->fetchOne(
        "SELECT status, risk_output, loss_event_frequency, ale,
                primary_loss_magnitude, secondary_loss_magnitude, recommended_liability,
                vendor_cyber_insurance_coverage, scope_of_work
         FROM tprm_results
         WHERE vendor_name = :vendor_name
         ORDER BY created_at DESC LIMIT 1",
        [':vendor_name' => $vendor['vendor_name']]
    );
    if ($fairRow) {
        $fairEncFields = ['loss_event_frequency', 'ale', 'primary_loss_magnitude',
            'secondary_loss_magnitude', 'recommended_liability', 'vendor_cyber_insurance_coverage', 'scope_of_work'];
        foreach ($fairEncFields as $field) {
            if (!empty($fairRow[$field])) {
                $fairRow[$field] = $encryption->decrypt($fairRow[$field]);
            }
        }
        $fairAnalysis = $fairRow;
    }
}

// Technologies (deduplicated, filter version-only names, normalize aliases)
$rawTechs = $db->fetchAll(
    'SELECT DISTINCT technology_name, technology_category
     FROM vendor_technologies
     WHERE vendor_onboarding_id = :id AND is_current = 1
     ORDER BY technology_category, technology_name',
    [':id' => $vendorId]
);
$techAliases = [
    'gcp' => 'Google Cloud', 'google cloud platform' => 'Google Cloud',
    'aws' => 'Amazon Web Services', 'ms azure' => 'Microsoft Azure', 'azure' => 'Microsoft Azure',
    'letsencrypt' => "Let's Encrypt", "let's encrypt" => "Let's Encrypt",
    'cloudflare cdn' => 'Cloudflare', 'cloudflare dns' => 'Cloudflare',
];
$technologies = [];
$seenTechNames = [];
foreach ($rawTechs as $t) {
    $name = trim($t['technology_name'] ?? '');
    if ($name === '' || preg_match('/^\d[\d.]*$/', $name)) continue;
    $key = strtolower($name);
    if (isset($techAliases[$key])) {
        $name = $techAliases[$key];
        $t['technology_name'] = $name;
        $key = strtolower($name);
    }
    if (isset($seenTechNames[$key])) continue;
    $seenTechNames[$key] = true;
    $technologies[] = $t;
}

// Assessments
$assessmentCount = 0;
try {
    $aRow = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM vendor_assessments WHERE vendor_request_id = :id AND status = 'completed'",
        [':id' => $vendorId]
    );
    $assessmentCount = (int)($aRow['cnt'] ?? 0);
} catch (Exception $e) {}

// ============================================================================
// Compute NIST CSF Scores (same logic as vendor-detailed-summary.php)
// ============================================================================
$nistIdentify = 0;
if (count($technologies) > 0) $nistIdentify += 40;
if ($fairAnalysis) $nistIdentify += 30;
if (!empty($vendor['vendor_tier'])) $nistIdentify += 30;

$nistProtect = 0;
if (!empty($shCategoryScores)) {
    $protectCats = ['tls_crypto', 'app_hardening', 'email_security', 'network_security'];
    $protectSum = 0;
    $protectCount = 0;
    foreach ($protectCats as $cat) {
        if (isset($shCategoryScores[$cat])) {
            $protectSum += (int)$shCategoryScores[$cat];
            $protectCount++;
        }
    }
    $nistProtect = $protectCount > 0 ? round($protectSum / $protectCount) : 0;
}

$detectVuln = isset($shCategoryScores['vuln_exposure']) ? (int)$shCategoryScores['vuln_exposure'] : 0;
$detectMonitor = $latestScore ? 100 : 0;
$nistDetect = round($detectVuln * 0.6 + $detectMonitor * 0.4);

$nistRespond = 0;
if ($assessmentCount > 0) $nistRespond += 50;
$govAnswered = 0;
try {
    $govRow = $db->fetchOne(
        "SELECT COUNT(DISTINCT ar.question_id) as cnt
         FROM vendor_assessment_responses ar
         JOIN vendor_assessments a ON ar.assessment_id = a.id
         JOIN assessment_questions q ON ar.question_id = q.id
         WHERE a.vendor_request_id = :id AND a.status = 'completed'
           AND ar.response_value IS NOT NULL AND ar.response_value != ''",
        [':id' => $vendorId]
    );
    $govAnswered = (int)($govRow['cnt'] ?? 0);
} catch (Exception $e) {}
if ($govAnswered > 0) $nistRespond += 50;

$nistRecover = 0;
if ($fairAnalysis) {
    $recommended = (float)($fairAnalysis['recommended_liability'] ?? 0);
    $actual = (float)($fairAnalysis['vendor_cyber_insurance_coverage'] ?? 0);
    if ($recommended > 0 && $actual > 0) {
        $coverageRatio = min(1.0, $actual / $recommended);
        $nistRecover += round($coverageRatio * 50);
    }
    $nistRecover += 50;
} elseif ($assessmentCount > 0) {
    $nistRecover += 25;
}

// ============================================================================
// Build Comprehensive Prompt
// ============================================================================
$promptParts = [];
$promptParts[] = "=== VENDOR PROFILE ===";
$promptParts[] = "Vendor: {$vendor['vendor_name']}";
$promptParts[] = "Domain: " . ($vendor['vendor_domain'] ?? 'Not specified');
$promptParts[] = "Type: " . ($vendor['vendor_type'] ?? 'Not specified');
$promptParts[] = "Tier: " . ($vendor['vendor_tier'] ? "Tier {$vendor['vendor_tier']}" : 'Not tiered');
$promptParts[] = "Status: " . ucfirst(str_replace('_', ' ', $vendor['status'] ?? 'Unknown'));
if (!empty($vendor['product_service_description'])) {
    $promptParts[] = "Product/Service Description: " . $vendor['product_service_description'];
}

// UpGuard
if ($latestScore) {
    $ugGrade = $srsService->calculateGrade(intval($latestScore['score']));
    $ugNorm = $upguardMaxScore > 0 ? (int)floor(($latestScore['score'] / $upguardMaxScore) * 100) : 0;
    $promptParts[] = "\n=== {$upguardName} SCORES ===";
    $promptParts[] = "Score: {$latestScore['score']} / {$upguardMaxScore} ({$ugNorm}%, Grade: {$ugGrade})";
    $promptParts[] = "Scored: " . date('M j, Y', strtotime($latestScore['scored_at']));
    $promptParts[] = "Risk Counts: Critical={$latestScore['critical_risks']}, High={$latestScore['high_risks']}, Medium={$latestScore['medium_risks']}, Low={$latestScore['low_risks']}, Info={$latestScore['info_risks']}";

    if (!empty($ugCategoryScores)) {
        $parts = [];
        foreach ($ugCategoryScores as $cat => $val) {
            $parts[] = ucwords(str_replace('_', ' ', $cat)) . ": {$val}";
        }
        $promptParts[] = "Categories: " . implode(', ', $parts);
    }

    // Top critical/high risks (limit 5 for prompt size)
    $topRisks = array_filter($risks, fn($r) => in_array($r['severity'] ?? '', ['critical', 'high']));
    $topRisks = array_slice($topRisks, 0, 5);
    if (!empty($topRisks)) {
        $promptParts[] = "Top Critical/High Risks:";
        foreach ($topRisks as $r) {
            $promptParts[] = "  - [{$r['severity']}] {$r['risk_name']}: " . mb_substr($r['description'] ?? '', 0, 80);
        }
    }
}

// Shodan
if ($shodanLatestScore) {
    $shGrade = $shodanService->calculateGrade(intval($shodanLatestScore['score']));
    $promptParts[] = "\n=== {$shodanName} SCORES ===";
    $promptParts[] = "Score: {$shodanLatestScore['score']}% (Grade: {$shGrade})";
    $promptParts[] = "Traffic Light: " . ($shodanLatestScore['traffic_light'] ?? 'N/A');
    $promptParts[] = "Open Ports: " . intval($shodanLatestScore['open_ports_count'] ?? 0);
    $promptParts[] = "Vulns: " . intval($shodanLatestScore['vuln_count'] ?? 0)
        . " (Critical: " . intval($shodanLatestScore['critical_vulns'] ?? 0)
        . ", High: " . intval($shodanLatestScore['high_vulns'] ?? 0) . ")";

    if (!empty($shCategoryScores)) {
        $parts = [];
        foreach ($shCategoryScores as $cat => $val) {
            $parts[] = ucwords(str_replace('_', ' ', $cat)) . ": {$val}/100";
        }
        $promptParts[] = "Categories: " . implode(', ', $parts);
    }

    // Top negative signals (limit 5)
    $negFindings = array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'negative');
    if (!empty($negFindings)) {
        $promptParts[] = "Key Negative Signals:";
        $count = 0;
        foreach ($negFindings as $f) {
            if ($count >= 5) break;
            $promptParts[] = "  - " . ($f['description'] ?? $f['service_name'] ?? 'Unknown');
            $count++;
        }
    }

    $posFindings = array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'positive');
    $promptParts[] = "Positive Signals: " . count($posFindings) . ", Negative Signals: " . count(array_values($negFindings));

    // High CVEs (limit 5)
    $highCVEs = array_filter($shodanFindings, fn($f) =>
        !empty($f['cve_id']) && ($f['cvss_score'] ?? 0) >= 7.0
    );
    if (!empty($highCVEs)) {
        $promptParts[] = "CVEs (CVSS >= 7.0):";
        $count = 0;
        foreach ($highCVEs as $c) {
            if ($count >= 5) break;
            $promptParts[] = "  - {$c['cve_id']} (CVSS: {$c['cvss_score']})";
            $count++;
        }
    }
}

// Combined Score
if ($combinedScore['score'] !== null) {
    $promptParts[] = "\n=== COMBINED SCORE ===";
    $promptParts[] = "Combined: {$combinedScore['score']}% (Grade: {$combinedScore['grade']}, {$combinedScore['source_count']} sources)";
}

// NIST CSF Scores
$promptParts[] = "\n=== NIST CSF 2.0 COMPUTED SCORES ===";
$promptParts[] = "Identify: {$nistIdentify}/100 (asset management, risk assessment, governance)";
$promptParts[] = "Protect: {$nistProtect}/100 (TLS, app hardening, email security, network security)";
$promptParts[] = "Detect: {$nistDetect}/100 (vulnerability exposure, continuous monitoring)";
$promptParts[] = "Respond: {$nistRespond}/100 (assessments completed, governance answers)";
$promptParts[] = "Recover: {$nistRecover}/100 (insurance adequacy, business continuity)";

// FAIR
if ($fairAnalysis) {
    $promptParts[] = "\n=== FAIR ANALYSIS ===";
    $promptParts[] = "Risk Level: " . ($fairAnalysis['risk_output'] ?? 'N/A');
    $promptParts[] = "Status: " . ucfirst($fairAnalysis['status'] ?? 'Draft');
    if (!empty($fairAnalysis['ale'])) {
        $promptParts[] = "Annual Loss Expectancy (ALE): $" . number_format((float)$fairAnalysis['ale'], 0);
    }
    if (!empty($fairAnalysis['loss_event_frequency'])) {
        $promptParts[] = "Loss Event Frequency: " . $fairAnalysis['loss_event_frequency'] . " events/year";
    }
    if (!empty($fairAnalysis['primary_loss_magnitude'])) {
        $promptParts[] = "Primary Loss Magnitude: $" . number_format((float)$fairAnalysis['primary_loss_magnitude'], 0);
    }
    if (!empty($fairAnalysis['secondary_loss_magnitude'])) {
        $promptParts[] = "Secondary Loss Magnitude: $" . number_format((float)$fairAnalysis['secondary_loss_magnitude'], 0);
    }
    if (!empty($fairAnalysis['recommended_liability'])) {
        $promptParts[] = "Recommended Insurance: $" . number_format((float)$fairAnalysis['recommended_liability'], 0);
    }
    if (!empty($fairAnalysis['vendor_cyber_insurance_coverage'])) {
        $promptParts[] = "Current Insurance: $" . number_format((float)$fairAnalysis['vendor_cyber_insurance_coverage'], 0);
    }
}

// Technologies
if (!empty($technologies)) {
    $promptParts[] = "\n=== TECHNOLOGY INVENTORY ===";
    $promptParts[] = "Total detected: " . count($technologies);
    $techByCat = [];
    foreach ($technologies as $t) {
        $cat = $t['technology_category'] ?? 'other';
        $techByCat[$cat][] = $t['technology_name'];
    }
    foreach ($techByCat as $cat => $names) {
        $promptParts[] = ucwords(str_replace('_', ' ', $cat)) . ": " . implode(', ', array_slice($names, 0, 5));
    }
}

// Vendor Provided Evidence (certifications + assessment data)
$promptParts[] = "\n=== VENDOR PROVIDED EVIDENCE ===";

// Certifications
$certifications = [];
try {
    $certifications = $db->fetchAll(
        "SELECT certification_type, certification_expiration_date
         FROM vendor_documents
         WHERE vendor_request_id = :id AND document_type = 'certification' AND is_active = 1
         ORDER BY certification_expiration_date DESC",
        [':id' => $vendorId]
    );
} catch (Exception $e) {}

if (!empty($certifications)) {
    $promptParts[] = "Active Certifications:";
    foreach ($certifications as $cert) {
        $type = $cert['certification_type'] ?? 'Unknown';
        $expDate = $cert['certification_expiration_date'] ?? null;
        $status = 'Unknown';
        if ($expDate) {
            $daysLeft = (strtotime($expDate) - time()) / 86400;
            $status = $daysLeft < 0 ? 'EXPIRED' : ($daysLeft < 30 ? 'Expiring Soon' : 'Valid');
        }
        $promptParts[] = "  - {$type}: expires " . ($expDate ? date('Y-m-d', strtotime($expDate)) : 'N/A') . " ({$status})";
    }
} else {
    $promptParts[] = "Certifications: None on file";
}

$promptParts[] = "Completed vendor assessments: {$assessmentCount}";
$promptParts[] = "Governance questions answered: {$govAnswered}";

// Assessment section-level stats and key Q&A pairs
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
try {
    $completedAssessments = $db->fetchAll(
        "SELECT a.id, a.completed_at, t.name as template_name, t.id as template_id
         FROM vendor_assessments a
         JOIN assessment_templates t ON a.template_id = t.id
         WHERE a.vendor_request_id = :id AND a.status = 'completed' AND t.category = 'vendor_assessment'
         ORDER BY a.completed_at DESC LIMIT 3",
        [':id' => $vendorId]
    );
    $keyQA = [];
    foreach ($completedAssessments as $ca) {
        $promptParts[] = "\nAssessment: {$ca['template_name']}";
        $sections = $assessmentService->getSections($ca['template_id']);
        $responses = $assessmentService->getResponses($ca['id']);
        foreach ($sections as $section) {
            $questions = $assessmentService->getQuestions($section['id']);
            $answered = 0;
            $total = count($questions);
            foreach ($questions as $q) {
                if (isset($responses[$q['id']]) && ($responses[$q['id']]['response_value'] ?? '') !== '') {
                    $answered++;
                    // Collect short answers for key Q&A (only from most recent assessment, limit 15)
                    if (count($keyQA) < 15 && $ca === $completedAssessments[0]) {
                        $val = $responses[$q['id']]['response_value'];
                        if (in_array($q['question_type'] ?? '', ['checkbox', 'button_group_multi'])) {
                            $decoded = json_decode($val, true);
                            if (is_array($decoded)) $val = implode(', ', $decoded);
                        }
                        if (mb_strlen($val) <= 100) {
                            $keyQA[] = ['q' => $q['question_text'] ?? '', 'a' => $val];
                        }
                    }
                }
            }
            $sectionName = $section['name'] ?? 'Section';
            $promptParts[] = "  - {$sectionName}: {$answered}/{$total} answered";
        }
    }
    if (!empty($keyQA)) {
        $promptParts[] = "\nKey Vendor Responses:";
        foreach ($keyQA as $pair) {
            $promptParts[] = "  Q: {$pair['q']} A: {$pair['a']}";
        }
    }
} catch (Exception $e) {}

// VSM: Extract text from vendor-supplied documents for AI context
require_once __DIR__ . '/../includes/classes/DocumentTextExtractor.php';
try {
    $vsmDocs = $db->fetchAll(
        "SELECT id, document_type, certification_type, contract_name,
                original_filename, mime_type, encrypted_data
         FROM vendor_documents
         WHERE vendor_request_id = :id AND is_active = 1
           AND document_type = 'certification'
           AND mime_type IN ('application/pdf', 'text/csv', 'application/vnd.ms-excel',
                             'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
         ORDER BY created_at DESC LIMIT 5",
        [':id' => $vendorId]
    );
    if (!empty($vsmDocs)) {
        $domainKeywords = [
            'tls_crypto'       => ['encrypt', 'tls', 'ssl', 'crypto', 'certificate', 'https'],
            'network_security' => ['firewall', 'network', 'port', 'ids', 'ips', 'intrusion', 'segmentation'],
            'app_hardening'    => ['harden', 'waf', 'application security', 'secure development', 'sdlc', 'code review'],
            'email_security'   => ['email', 'phishing', 'spf', 'dkim', 'dmarc', 'spam'],
            'vuln_exposure'    => ['vulnerab', 'scan', 'patch', 'remediat', 'penetration', 'pentest', 'cve'],
            'certifications'   => ['certif', 'soc', 'iso', 'compliance', 'audit', 'attestation'],
        ];
        $vsmParts = [];
        foreach ($vsmDocs as $doc) {
            try {
                $decrypted = $encryption->decryptRaw($doc['encrypted_data']);
                if (empty($decrypted)) continue;

                $extractedText = DocumentTextExtractor::extract($decrypted, $doc['mime_type'], 5000);
                unset($decrypted);

                if ($extractedText !== null) {
                    $docLabel = $doc['original_filename'];
                    if (!empty($doc['certification_type'])) {
                        $docLabel = $doc['certification_type'];
                    } elseif (!empty($doc['contract_name'])) {
                        $docLabel = $doc['contract_name'];
                    }

                    // Collect keyword-matched excerpts for this document
                    $excerpts = [];
                    $lowerText = strtolower($extractedText);
                    foreach ($domainKeywords as $domain => $keywords) {
                        foreach ($keywords as $kw) {
                            $pos = stripos($lowerText, $kw);
                            if ($pos !== false) {
                                $start = max(0, $pos - 40);
                                $snippet = mb_substr($extractedText, $start, 100);
                                $snippet = trim($snippet);
                                if ($start > 0) $snippet = '...' . $snippet;
                                if ($start + 100 < mb_strlen($extractedText)) $snippet .= '...';
                                $excerpts[] = "  [{$domain}] \"{$snippet}\"";
                                break; // one per domain
                            }
                        }
                    }
                    if (!empty($excerpts)) {
                        $vsmParts[] = "Document: {$docLabel} (type: {$doc['document_type']})";
                        foreach ($excerpts as $exc) {
                            $vsmParts[] = $exc;
                        }
                    }
                }
            } catch (Exception $e2) {
                // Skip this document
            }
        }
        if (!empty($vsmParts)) {
            $promptParts[] = "\n=== VSM DOCUMENT EXCERPTS ===";
            $promptParts[] = "The following excerpts were extracted from vendor-supplied documents.";
            $promptParts[] = "Use these to cross-reference vendor claims in the Reality Check section.";
            foreach ($vsmParts as $part) {
                $promptParts[] = $part;
            }
        }
    }
} catch (Exception $e) {}

$vendorData = implode("\n", $promptParts);

// ============================================================================
// AI Prompt — NIST-focused detailed analysis
// ============================================================================
$prompt = "You are a cybersecurity analyst. Write a concise NIST CSF 2.0 gap analysis for a TPRM vendor assessment.

RULES: Use EXACT data values. Output clean HTML only (<h4>, <p>, <ul>/<li>, <strong>, <table>/<tr>/<th>/<td>). No markdown fences. No meta-commentary. Keep under 3000 characters total. Be concise.

Generate these sections:

1. <h4>Executive Summary</h4> — 3-4 sentences summarizing what the vendor does (from the Product/Service Description), their overall security posture, scores, and risk level.

2. <h4>NIST CSF 2.0 Gap Analysis</h4> — Table with columns: Function | Score | Key Gaps | Priority (Critical/High/Medium/Low). One row per function (Identify, Protect, Detect, Respond, Recover).

3. <h4>Key Strengths</h4> — 3-4 bullet points.

4. <h4>Key Weaknesses</h4> — 3-4 bullet points, de-duplicated across sources.

5. <h4>Vendor Claims Reality Check</h4> — Cross-reference vendor assessment responses AND vendor-supplied material (VSM) document excerpts against external {$shodanName}/{$upguardName} scores. Identify discrepancies where vendor claims strong security but external evidence shows otherwise (e.g., vendor says TLS 1.2 but {$shodanName} shows TLS 1.1, or vendor claims weekly scanning but critical unpatched CVEs exist). Note any expired/missing certifications, incomplete assessments, or contradictions. When VSM documents are available, note whether document content supports or contradicts external findings. 2-4 sentences.

6. <h4>Recommendations</h4> — 4-6 items as: <strong>[NIST Function]:</strong> Action.

VENDOR DATA:
{$vendorData}

Output ONLY HTML.";

// ============================================================================
// Call AI Platform API
// ============================================================================
$messages = [
    ['role' => 'user', 'content' => $prompt]
];

$result = $ai->chatCompletion($messages, [
    'purpose' => 'fair',
    'max_tokens' => max($ai->getMaxTokens(), 3000),
]);

if (!$result['success']) {
    error_log("AI Platform API error (detailed-summary): " . $result['error']);
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

// Sanitize: allow only safe tags (same whitelist as generate-srs-summary.php + table tags)
$allowed = '<h3><h4><h5><p><ul><ol><li><strong><em><b><i><br><table><thead><tbody><tr><th><td>';
$content = strip_tags(trim($content), $allowed);
$content = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $content);

echo json_encode([
    'success' => true,
    'summary' => $content,
    'csrf_token' => $newCsrfToken,
]);

} catch (Throwable $e) {
    error_log("generate-detailed-summary error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $token = '';
    try { $token = Security::getInstance()->generateCSRFToken(); } catch (Throwable $ignored) {}
    echo json_encode(['error' => 'Server error: ' . $e->getMessage(), 'csrf_token' => $token]);
}
