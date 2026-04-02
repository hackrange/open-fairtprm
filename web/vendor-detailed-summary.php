<?php
/**
 * Vendor Detailed Assessment Summary — NIST CSF 2.0 Gap Analysis Report
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Standalone, print-optimized report page that consolidates data from all
 * vendor security sources (UpGuard, Shodan, FAIR, technologies, assessments)
 * and maps findings to the NIST Cybersecurity Framework 2.0. Includes
 * Chart.js visualizations and optional AI-generated narrative analysis.
 *
 * Designed for browser print-to-PDF (Ctrl+P). No sidebar, no app chrome.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();

// ACL: admins, cyber_tprm, super admins, and assigned stakeholders
$vendorId = intval($_GET['id'] ?? 0);
if (!$vendorId) {
    redirect('vendor-srs-list.php');
}

$canView = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm') || $acl->hasGroup('auditor') || $session->get('is_super_admin');
$isAuditor = $acl->hasGroup('auditor');
$isStakeholderAccess = false;

if (!$canView) {
    if ($acl->hasGroup('stakeholder') && $vendorId) {
        $stCheck = $db->fetchOne(
            'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
            [':rid' => $vendorId, ':uid' => $auth->getUser()['id']]
        );
        if (!empty($stCheck)) {
            $canView = true;
            $isStakeholderAccess = true;
        }
    }
}

if (!$canView) {
    http_response_code(403);
    die('Access denied.');
}

$vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
if (!$vendor) {
    redirect('vendor-srs-list.php?error=not_found');
}

// ============================================================================
// Data Collection — Pull everything we need from all sources
// ============================================================================

require_once __DIR__ . '/includes/classes/SRSService.php';
require_once __DIR__ . '/includes/classes/ShodanService.php';

$srsService = new SRSService();
$shodanService = new ShodanService();
$encryption = new Encryption();

$ugConfig = $srsService->getScoringConfig();
$shConfig = $shodanService->getScoringConfig();
$upguardName = $ugConfig['display_name'] ?? 'UpGuard';
$shodanName = $shConfig['display_name'] ?? 'Shodan';
$upguardMaxScore = (int)($ugConfig['max_score'] ?? 950);
$upguardDisplayMode = $ugConfig['display_mode'] ?? 'raw';

// --- UpGuard ---
$latestScore = $srsService->getLatestScore($vendorId);
$risks = $latestScore ? $srsService->getRisksForScore($latestScore['id']) : [];
$ugCategoryScores = [];
if ($latestScore && !empty($latestScore['category_scores'])) {
    $ugCategoryScores = is_string($latestScore['category_scores'])
        ? json_decode($latestScore['category_scores'], true) : $latestScore['category_scores'];
    if (!is_array($ugCategoryScores)) $ugCategoryScores = [];
}

// --- Shodan ---
$shodanLatestScore = null;
$shodanFindings = [];
try {
    $shodanLatestScore = $shodanService->getLatestScore($vendorId);
    if ($shodanLatestScore) {
        $shodanFindings = $shodanService->getFindingsForScore($shodanLatestScore['id']);
    }
} catch (Exception $e) {}

// Build waiver lookup to exclude waived Shodan risks from the report
$shodanWaiverLookup = [];
try {
    $waivers = $shodanService->getWaiversForVendor($vendorId);
    foreach ($waivers as $w) {
        $shodanWaiverLookup[($w['signal_name'] ?? '') . ':' . ($w['subdomain'] ?? '')] = true;
    }
} catch (Exception $e) {}

$shCategoryScores = [];
if ($shodanLatestScore && !empty($shodanLatestScore['category_scores'])) {
    $shCategoryScores = is_string($shodanLatestScore['category_scores'])
        ? json_decode($shodanLatestScore['category_scores'], true) : $shodanLatestScore['category_scores'];
    if (!is_array($shCategoryScores)) $shCategoryScores = [];
}

// --- Combined Score ---
$combinedScore = $srsService->getCombinedScore($vendor);

// --- Score Trends (1 year) ---
$ugTrend = $srsService->getScoreTrend($vendorId, 365);
$shTrend = [];
try {
    $shTrend = $shodanService->getScoreTrend($vendorId, 365);
} catch (Exception $e) {}

// --- FAIR Analysis ---
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

// --- Technologies (deduplicated, filter out version-only names like "1.2.28") ---
$rawTechs = $db->fetchAll(
    'SELECT DISTINCT technology_name, technology_category, technology_version
     FROM vendor_technologies
     WHERE vendor_onboarding_id = :id AND is_current = 1
     ORDER BY technology_category, technology_name',
    [':id' => $vendorId]
);
// Normalize aliases so "Google Cloud" and "GCP" don't both appear
$techAliases = [
    'gcp' => 'Google Cloud', 'google cloud platform' => 'Google Cloud',
    'aws' => 'Amazon Web Services', 'msft' => 'Microsoft',
    'ms azure' => 'Microsoft Azure', 'azure' => 'Microsoft Azure',
    'letsencrypt' => "Let's Encrypt", "let's encrypt" => "Let's Encrypt",
    'cloudflare cdn' => 'Cloudflare', 'cloudflare dns' => 'Cloudflare',
    'bigip' => 'F5 BIG-IP', 'big-ip' => 'F5 BIG-IP', 'f5 big-ip' => 'F5 BIG-IP',
    'akamaighost' => 'Akamai', 'akamai ghost' => 'Akamai',
    'amazon elb' => 'AWS ELB', 'awselb' => 'AWS ELB',
];
// Override category for known CDN/WAF products stored as "other"
$techCategoryOverrides = [
    'f5 big-ip' => 'cdn_waf', 'akamai' => 'cdn_waf', 'aws elb' => 'cdn_waf',
    'cloudflare' => 'cdn_waf', 'cloudfront' => 'cdn_waf', 'fastly' => 'cdn_waf',
    'imperva' => 'cdn_waf', 'incapsula' => 'cdn_waf', 'sucuri' => 'cdn_waf',
    'stackpath' => 'cdn_waf',
];
$technologies = [];
$seenTechNames = [];
foreach ($rawTechs as $t) {
    $name = trim($t['technology_name'] ?? '');
    // Skip entries that are just version numbers (digits and dots only)
    if ($name === '' || preg_match('/^\d[\d.]*$/', $name)) continue;
    // Normalize via alias map
    $key = strtolower($name);
    if (isset($techAliases[$key])) {
        $name = $techAliases[$key];
        $t['technology_name'] = $name;
        $key = strtolower($name);
    }
    // Fix miscategorized CDN/WAF products
    if (isset($techCategoryOverrides[$key])) {
        $t['technology_category'] = $techCategoryOverrides[$key];
    }
    // Deduplicate by name (case-insensitive)
    if (isset($seenTechNames[$key])) continue;
    $seenTechNames[$key] = true;
    $technologies[] = $t;
}

// --- Completed Assessments Count ---
$assessmentCount = 0;
try {
    $assessmentRow = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM vendor_assessments WHERE vendor_request_id = :id AND status = 'completed'",
        [':id' => $vendorId]
    );
    $assessmentCount = (int)($assessmentRow['cnt'] ?? 0);
} catch (Exception $e) {}

// --- Certifications ---
$certifications = [];
try {
    $certifications = $db->fetchAll(
        "SELECT certification_type, certification_expiration_date, original_filename
         FROM vendor_documents
         WHERE vendor_request_id = :id AND document_type = 'certification' AND is_active = 1
         ORDER BY certification_expiration_date DESC",
        [':id' => $vendorId]
    );
} catch (Exception $e) {}

// --- Completed Assessment Metadata + Vendor Claims for Reality Check ---
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentMeta = [];
$vendorClaims = []; // keyword → ['answer' => ..., 'source' => ...]
// Keywords mapped to security domains (matches Shodan category score keys)
$domainKeywords = [
    'tls_crypto'        => ['encrypt', 'tls', 'ssl', 'crypto', 'certificate', 'https'],
    'network_security'  => ['firewall', 'network', 'port', 'ids', 'ips', 'intrusion', 'segmentation'],
    'app_hardening'     => ['harden', 'waf', 'application security', 'secure development', 'sdlc', 'code review'],
    'email_security'    => ['email', 'phishing', 'spf', 'dkim', 'dmarc', 'spam'],
    'vuln_exposure'     => ['vulnerab', 'scan', 'patch', 'remediat', 'penetration', 'pentest', 'cve'],
    'certifications'    => ['certif', 'soc', 'iso', 'compliance', 'audit', 'attestation'],
];
try {
    $completedAssessments = $db->fetchAll(
        "SELECT a.id, a.completed_at, t.name as template_name, t.id as template_id
         FROM vendor_assessments a
         JOIN assessment_templates t ON a.template_id = t.id
         WHERE a.vendor_request_id = :id AND a.status = 'completed' AND t.category = 'vendor_assessment'
         ORDER BY a.completed_at DESC LIMIT 5",
        [':id' => $vendorId]
    );
    foreach ($completedAssessments as $ca) {
        $sections = $assessmentService->getSections($ca['template_id']);
        $responses = $assessmentService->getResponses($ca['id']);
        $totalAnswered = 0;
        $totalQuestions = 0;
        $sectionCount = count($sections);
        foreach ($sections as $section) {
            $questions = $assessmentService->getQuestions($section['id']);
            $totalQuestions += count($questions);
            $sectionName = strtolower($section['name'] ?? '');
            foreach ($questions as $q) {
                $qId = $q['id'];
                $hasAnswer = isset($responses[$qId]) && ($responses[$qId]['response_value'] ?? '') !== '';
                if ($hasAnswer) $totalAnswered++;
                if (!$hasAnswer) continue;
                $rawVal = $responses[$qId]['response_value'];
                if (in_array($q['question_type'] ?? '', ['checkbox', 'button_group_multi'])) {
                    $decoded = json_decode($rawVal, true);
                    if (is_array($decoded)) $rawVal = implode(', ', $decoded);
                }
                // Only collect short answers for keyword matching
                if (mb_strlen($rawVal) > 100) continue;
                $qText = strtolower($q['question_text'] ?? '');
                $searchText = $sectionName . ' ' . $qText;
                foreach ($domainKeywords as $domain => $keywords) {
                    foreach ($keywords as $kw) {
                        if (stripos($searchText, $kw) !== false) {
                            // Keep first match per domain (most relevant)
                            if (!isset($vendorClaims[$domain])) {
                                $vendorClaims[$domain] = [
                                    'answer' => $rawVal,
                                    'question' => $q['question_text'] ?? '',
                                ];
                            }
                            break 2;
                        }
                    }
                }
            }
        }
        $assessmentMeta[] = [
            'template_name' => $ca['template_name'],
            'completed_at'  => $ca['completed_at'],
            'sections'      => $sectionCount,
            'answered'      => $totalAnswered,
            'total'         => $totalQuestions,
        ];
    }
} catch (Exception $e) {}

// --- VSM: Extract text from vendor-supplied PDF documents ---
$vsmExcerpts = []; // ['doc_name' => ..., 'doc_type' => ..., 'text' => ...]
try {
    $vsmDocs = $db->fetchAll(
        "SELECT id, document_type, certification_type, contract_name, description,
                original_filename, mime_type, encrypted_data
         FROM vendor_documents
         WHERE vendor_request_id = :id AND is_active = 1
           AND mime_type = 'application/pdf'
         ORDER BY created_at DESC LIMIT 5",
        [':id' => $vendorId]
    );
    foreach ($vsmDocs as $doc) {
        $tmpFile = null;
        $tmpOut = null;
        try {
            $decrypted = $encryption->decryptRaw($doc['encrypted_data']);
            if (empty($decrypted)) continue;

            $tmpFile = tempnam(sys_get_temp_dir(), 'vsm_');
            file_put_contents($tmpFile, $decrypted);
            unset($decrypted); // free memory

            $tmpOut = $tmpFile . '.txt';
            // Use proc_open (exec is disabled in php-hardening.ini)
            $cmd = ['/usr/bin/pdftotext', '-l', '5', '-enc', 'UTF-8', $tmpFile, $tmpOut];
            $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
            $proc = proc_open($cmd, $desc, $pipes);
            $retCode = is_resource($proc) ? proc_close($proc) : 1;

            if ($retCode === 0 && file_exists($tmpOut)) {
                $extractedText = file_get_contents($tmpOut);
                $extractedText = mb_substr($extractedText, 0, 5000);
                $extractedText = preg_replace('/\s+/', ' ', trim($extractedText));

                if (mb_strlen($extractedText) > 50) {
                    // Determine document label
                    $docLabel = $doc['original_filename'];
                    if (!empty($doc['certification_type'])) {
                        $docLabel = $doc['certification_type'];
                    } elseif (!empty($doc['contract_name'])) {
                        $docLabel = $doc['contract_name'];
                    }

                    $vsmExcerpts[] = [
                        'doc_name' => $docLabel,
                        'doc_type' => $doc['document_type'],
                        'text'     => $extractedText,
                    ];

                    // Keyword-match extracted text against security domains
                    $lowerText = strtolower($extractedText);
                    foreach ($domainKeywords as $domain => $keywords) {
                        foreach ($keywords as $kw) {
                            $pos = stripos($lowerText, $kw);
                            if ($pos !== false) {
                                // Extract ~100 char snippet around keyword
                                $start = max(0, $pos - 40);
                                $snippet = mb_substr($extractedText, $start, 100);
                                $snippet = trim($snippet);
                                if ($start > 0) $snippet = '...' . $snippet;
                                if ($start + 100 < mb_strlen($extractedText)) $snippet .= '...';

                                // Only set VSM if not already set for this domain
                                if (!isset($vendorClaims[$domain]['vsm_answer'])) {
                                    if (!isset($vendorClaims[$domain])) {
                                        $vendorClaims[$domain] = [
                                            'answer'   => '',
                                            'question' => '',
                                            'source'   => 'VSM',
                                        ];
                                    }
                                    $vendorClaims[$domain]['vsm_answer'] = $snippet;
                                    $vendorClaims[$domain]['vsm_source'] = $docLabel;
                                }
                                break; // one match per domain per document is enough
                            }
                        }
                    }
                }
            }
        } catch (Exception $e2) {
            // Skip this document on error
        } finally {
            if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
            if ($tmpOut && file_exists($tmpOut)) @unlink($tmpOut);
        }
    }
} catch (Exception $e) {}

// Tag existing assessment claims with source
foreach ($vendorClaims as $domain => &$claim) {
    if (!isset($claim['source'])) {
        $claim['source'] = 'Assessment';
    }
}
unset($claim);

// --- AI Enabled Check (supports OpenWebUI or LibreChat) ---
$aiEnabled = false;
try {
    $aiEnabled = AIPlatformService::getInstance()->isEnabled();
} catch (Exception $e) {}

// ============================================================================
// NIST CSF 2.0 Score Computation
// ============================================================================

// Identify: Tech count, FAIR exists, tier assigned
$nistIdentify = 0;
if (count($technologies) > 0) $nistIdentify += 40;
if ($fairAnalysis) $nistIdentify += 30;
if (!empty($vendor['vendor_tier'])) $nistIdentify += 30;

// Protect: Shodan category scores (tls_crypto, app_hardening, email_security, network_security)
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

// Detect: Shodan vuln_exposure (60%) + UpGuard monitoring active (40%)
$nistDetect = 0;
$detectVuln = isset($shCategoryScores['vuln_exposure']) ? (int)$shCategoryScores['vuln_exposure'] : 0;
$detectMonitor = $latestScore ? 100 : 0; // UpGuard monitoring is active if we have a score
$nistDetect = round($detectVuln * 0.6 + $detectMonitor * 0.4);

// Respond: Completed assessments + governance signals
$nistRespond = 0;
if ($assessmentCount > 0) $nistRespond += 50;
// Check for governance-related assessment responses
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

// Recover: Insurance adequacy + business continuity
$nistRecover = 0;
if ($fairAnalysis) {
    $recommended = (float)($fairAnalysis['recommended_liability'] ?? 0);
    $actual = (float)($fairAnalysis['vendor_cyber_insurance_coverage'] ?? 0);
    if ($recommended > 0 && $actual > 0) {
        $coverageRatio = min(1.0, $actual / $recommended);
        $nistRecover += round($coverageRatio * 50);
    }
    // Business continuity: FAIR analysis existing means some continuity planning
    $nistRecover += 50;
} elseif ($assessmentCount > 0) {
    // Some partial credit for having completed assessments
    $nistRecover += 25;
}

$nistScores = [
    'Identify' => $nistIdentify,
    'Protect'  => $nistProtect,
    'Detect'   => $nistDetect,
    'Respond'  => $nistRespond,
    'Recover'  => $nistRecover,
];

// ============================================================================
// Risk Severity Distribution (combined UpGuard + Shodan)
// ============================================================================
$severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
foreach ($risks as $r) {
    $sev = strtolower($r['severity'] ?? 'info');
    if (isset($severityCounts[$sev])) $severityCounts[$sev]++;
}
if ($shodanLatestScore) {
    $severityCounts['critical'] += (int)($shodanLatestScore['critical_vulns'] ?? 0);
    $severityCounts['high'] += (int)($shodanLatestScore['high_vulns'] ?? 0);
    $severityCounts['medium'] += (int)($shodanLatestScore['medium_vulns'] ?? 0);
    $severityCounts['low'] += (int)($shodanLatestScore['low_vulns'] ?? 0);
}

// ============================================================================
// Prepare chart data for JSON
// ============================================================================
$trendLabels = [];
$ugTrendData = [];
$shTrendData = [];

// Merge trend dates
$allDates = [];
foreach ($ugTrend as $t) $allDates[$t['date']] = true;
foreach ($shTrend as $t) $allDates[$t['date']] = true;
ksort($allDates);

$ugTrendMap = [];
foreach ($ugTrend as $t) {
    $normalizedScore = $upguardMaxScore > 0 ? (int)floor(($t['score'] / $upguardMaxScore) * 100) : 0;
    $ugTrendMap[$t['date']] = $normalizedScore;
}
$shTrendMap = [];
foreach ($shTrend as $t) $shTrendMap[$t['date']] = $t['score'];

foreach ($allDates as $date => $_) {
    $trendLabels[] = $date;
    $ugTrendData[] = $ugTrendMap[$date] ?? null;
    $shTrendData[] = $shTrendMap[$date] ?? null;
}

// Negative Shodan signals
$negativeSignals = array_values(array_filter($shodanFindings, function($f) use ($shodanWaiverLookup) {
    if (($f['signal_type'] ?? '') !== 'negative') return false;
    $key = ($f['service_name'] ?? '') . ':' . ($f['subdomain'] ?? '');
    return !isset($shodanWaiverLookup[$key]);
}));
$positiveSignals = array_values(array_filter($shodanFindings, fn($f) => ($f['signal_type'] ?? '') === 'positive'));

// CVEs with CVSS >= 7.0 (exclude waived)
$highCVEs = array_values(array_filter($shodanFindings, function($f) use ($shodanWaiverLookup) {
    if (empty($f['cve_id']) || ($f['cvss_score'] ?? 0) < 7.0) return false;
    $key = ($f['service_name'] ?? '') . ':' . ($f['subdomain'] ?? '');
    return !isset($shodanWaiverLookup[$key]);
}));
usort($highCVEs, fn($a, $b) => ($b['cvss_score'] ?? 0) <=> ($a['cvss_score'] ?? 0));

// Top critical/high UpGuard risks
$topRisks = array_values(array_filter($risks, fn($r) => in_array($r['severity'] ?? '', ['critical', 'high'])));

// Grade helpers
$ugGrade = $latestScore ? $srsService->calculateGrade(intval($latestScore['score'])) : null;
$shGrade = $shodanLatestScore ? $shodanService->calculateGrade(intval($shodanLatestScore['score'])) : null;

// Theme + Branding
$theme = getUserTheme();
$headerColor = $theme['header_color'] ?? '#35a0a3';
$logoUrl = $theme['logo_url'];
$csrfToken = $security->generateCSRFToken();
$reportDate = date('F j, Y');
$reportTime = date('g:i A');

// Helper: score display for UpGuard
function displayUgScore(int $rawScore, string $mode, int $maxScore): string {
    if ($mode === 'percentage') {
        $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
        return $pct . '%';
    }
    return (string)$rawScore;
}

// Helper: grade CSS class
function gradeClass(string $grade): string {
    $map = ['A' => '#22c55e', 'B' => '#3b82f6', 'C' => '#f59e0b', 'D' => '#f97316', 'F' => '#ef4444'];
    return $map[$grade] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Summary - <?php echo e($vendor['vendor_name']); ?></title>
    <script src="app/js/chart.min.js" nonce="<?php echo cspNonce(); ?>"></script>
    <style>
        /* === Print Styles === */
        @media print {
            @page {
                size: letter;
                margin: 0.6in 0.5in 0.8in 0.5in;
                @bottom-center {
                    content: "Confidential — <?php echo e($vendor['vendor_name']); ?> — Page " counter(page) " of " counter(pages);
                    font-size: 7pt;
                    color: #9ca3af;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }
            }
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
            .avoid-break { page-break-inside: avoid; }
            .report-footer { display: none; }
            canvas { max-width: 100% !important; max-height: 250px !important; }
        }

        /* === Base === */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #1f2937;
            background: #fff;
        }
        .container { max-width: 8.5in; margin: 0 auto; padding: 20px; }

        /* === Header === */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            border-bottom: 3px solid <?php echo e($headerColor); ?>;
            margin-bottom: 16px;
        }
        .report-header .logo { width: 200px; height: 38px; object-fit: contain; }
        .report-header .report-info { text-align: right; }
        .report-header .report-info h1 { font-size: 18pt; color: #111827; margin-bottom: 2px; }
        .report-header .report-info .subtitle { font-size: 11pt; color: #4b5563; font-weight: 600; }
        .report-header .report-info p { font-size: 8pt; color: #9ca3af; margin-top: 2px; }

        /* === Section Headers === */
        .section-title {
            font-size: 13pt;
            font-weight: 700;
            color: #111827;
            border-bottom: 2px solid <?php echo e($headerColor); ?>;
            padding-bottom: 4px;
            margin: 18px 0 10px 0;
        }
        .section-title-sm {
            font-size: 11pt;
            font-weight: 700;
            color: #374151;
            margin: 14px 0 8px 0;
        }

        /* === Vendor Info Bar === */
        .vendor-bar {
            background: #111827;
            color: white;
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 16px;
            text-align: center;
        }
        .vendor-bar h2 { font-size: 16pt; margin-bottom: 2px; }
        .vendor-bar .sub { font-size: 9pt; opacity: 0.85; }

        /* === Info Grid === */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .info-item { background: #f9fafb; padding: 8px 10px; border-radius: 4px; border-left: 3px solid <?php echo e($headerColor); ?>; }
        .info-item .label { font-size: 7pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; }
        .info-item .value { font-size: 10pt; font-weight: 600; color: #111827; }

        /* === Score Cards === */
        .score-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .score-card {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px;
            text-align: center;
        }
        .score-card .source { font-size: 8pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; margin-bottom: 6px; }
        .score-card .score-value { font-size: 24pt; font-weight: 800; color: #111827; }
        .score-card .grade {
            display: inline-block;
            font-size: 14pt;
            font-weight: 800;
            width: 36px; height: 36px;
            line-height: 36px;
            border-radius: 50%;
            color: white;
            margin-top: 4px;
        }
        .score-card .scored-date { font-size: 7pt; color: #9ca3af; margin-top: 4px; }

        /* === Charts === */
        .chart-container { margin-bottom: 16px; }
        .chart-container canvas { max-height: 260px; }
        .chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .chart-half { position: relative; }
        .chart-half canvas { max-height: 220px; width: 100% !important; height: auto !important; }

        /* === NIST Table === */
        table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 12px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 700; color: #374151; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3px; }
        td { color: #4b5563; }
        .severity-critical { color: #dc2626; font-weight: 700; }
        .severity-high { color: #ea580c; font-weight: 700; }
        .severity-medium { color: #d97706; font-weight: 600; }
        .severity-low { color: #2563eb; }
        .severity-info { color: #6b7280; }

        /* === Score Bar === */
        .score-bar {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .score-bar-fill {
            height: 10px;
            border-radius: 5px;
            background: <?php echo e($headerColor); ?>;
        }
        .score-bar-bg {
            flex: 1;
            height: 10px;
            border-radius: 5px;
            background: #e5e7eb;
            overflow: hidden;
        }

        /* === AI Section === */
        .ai-section {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 14px;
        }
        .ai-section h4 { font-size: 11pt; color: #1e293b; margin-bottom: 6px; }
        .ai-section p { margin-bottom: 8px; font-size: 9.5pt; color: #475569; }
        .ai-section ul { margin: 6px 0 8px 18px; }
        .ai-section li { margin-bottom: 3px; font-size: 9.5pt; color: #475569; }
        .ai-placeholder { color: #94a3b8; font-style: italic; font-size: 9pt; padding: 12px; text-align: center; }
        .ai-spinner { display: none; text-align: center; padding: 20px; color: #64748b; }
        .ai-spinner.active { display: block; }

        /* === FAIR Box === */
        .fair-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .fair-metric {
            background: #fff;
            padding: 10px 12px;
            border-radius: 4px;
            border-left: 4px solid <?php echo e($headerColor); ?>;
            border: 1px solid #e5e7eb;
        }
        .fair-metric .label { font-size: 7pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.3px; }
        .fair-metric .value { font-size: 13pt; font-weight: 700; color: #111827; }
        .fair-metric .desc { font-size: 7pt; color: #9ca3af; font-style: italic; }

        /* === Technology Pills === */
        .tech-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
        .tech-category { font-size: 8pt; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 3px; letter-spacing: 0.3px; }
        .tech-item { font-size: 8.5pt; color: #4b5563; padding: 2px 0; }

        /* === Buttons === */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 10pt;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: <?php echo e($headerColor); ?>; color: white; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .btn-secondary:hover { background: #e5e7eb; }
        .action-bar { display: flex; gap: 8px; margin-bottom: 16px; }

        /* === Footer === */
        .report-footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 2px solid #e5e7eb;
            font-size: 7pt;
            color: #9ca3af;
            text-align: center;
        }

        /* === Page Break === */
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
<div class="container">

    <!-- Action Bar (screen only) -->
    <div class="action-bar no-print">
        <a href="<?php echo $isStakeholderAccess ? 'vendor-onboarding.php?id=' . $vendorId . '&tab=score' : 'vendor-srs-details.php?id=' . $vendorId; ?>" class="btn btn-secondary">Back to Details</a>
        <?php if ($aiEnabled && !$isStakeholderAccess): ?>
        <button type="button" class="btn btn-primary" id="generateAiBtn">
            Generate Commentary
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" id="printBtn">Print / Save as PDF</button>
    </div>

    <!-- Report Header -->
    <div class="report-header">
        <img src="<?php echo e($logoUrl); ?>" alt="Logo" class="logo">
        <div class="report-info">
            <h1>Vendor Detailed Assessment</h1>
            <div class="subtitle"><?php echo e($vendor['vendor_name']); ?> | <?php echo e($vendor['vendor_domain'] ?? 'No domain'); ?></div>
            <p><?php echo e($reportDate); ?> at <?php echo e($reportTime); ?> | Confidential</p>
        </div>
    </div>

    <!-- Vendor Information -->
    <div class="vendor-bar">
        <h2><?php echo e($vendor['vendor_name']); ?></h2>
        <div class="sub"><?php echo e($vendor['vendor_domain'] ?? 'No domain specified'); ?></div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <div class="label">Vendor Type</div>
            <div class="value"><?php echo e($vendor['vendor_type'] ?? 'Not set'); ?></div>
        </div>
        <div class="info-item">
            <div class="label">Tier</div>
            <div class="value"><?php echo $vendor['vendor_tier'] ? 'Tier ' . e($vendor['vendor_tier']) : 'Not tiered'; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Status</div>
            <div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $vendor['status'] ?? 'Unknown'))); ?></div>
        </div>
        <div class="info-item">
            <div class="label">Assessments</div>
            <div class="value"><?php echo $assessmentCount; ?> completed</div>
        </div>
        <div class="info-item">
            <div class="label">Technologies</div>
            <div class="value"><?php echo count($technologies); ?> detected</div>
        </div>
    </div>

    <!-- Score Overview -->
    <h3 class="section-title">Score Overview</h3>
    <div class="score-grid">
        <?php if ($latestScore): ?>
        <div class="score-card">
            <div class="source"><?php echo e($upguardName); ?></div>
            <div class="score-value"><?php echo displayUgScore(intval($latestScore['score']), $upguardDisplayMode, $upguardMaxScore); ?></div>
            <div class="grade" style="background: <?php echo gradeClass($ugGrade); ?>"><?php echo $ugGrade; ?></div>
            <div class="scored-date">Scored <?php echo date('M j, Y', strtotime($latestScore['scored_at'])); ?></div>
        </div>
        <?php else: ?>
        <div class="score-card"><div class="source"><?php echo e($upguardName); ?></div><div class="ai-placeholder">No score available</div></div>
        <?php endif; ?>

        <?php if ($shodanLatestScore): ?>
        <div class="score-card">
            <div class="source"><?php echo e($shodanName); ?></div>
            <div class="score-value"><?php echo intval($shodanLatestScore['score']); ?>%</div>
            <div class="grade" style="background: <?php echo gradeClass($shGrade); ?>"><?php echo $shGrade; ?></div>
            <div class="scored-date">Scored <?php echo date('M j, Y', strtotime($shodanLatestScore['scored_at'])); ?></div>
        </div>
        <?php else: ?>
        <div class="score-card"><div class="source"><?php echo e($shodanName); ?></div><div class="ai-placeholder">No score available</div></div>
        <?php endif; ?>

        <div class="score-card">
            <div class="source">Combined Score</div>
            <?php if ($combinedScore['score'] !== null): ?>
            <div class="score-value"><?php echo $combinedScore['score']; ?>%</div>
            <div class="grade" style="background: <?php echo gradeClass($combinedScore['grade']); ?>"><?php echo $combinedScore['grade']; ?></div>
            <div class="scored-date"><?php echo $combinedScore['source_count']; ?> source(s)</div>
            <?php else: ?>
            <div class="ai-placeholder">No scores available</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- NIST CSF 2.0 Analysis -->
    <h3 class="section-title" style="page-break-before: always;">NIST CSF 2.0 Framework Analysis</h3>

    <div class="chart-row avoid-break">
        <div class="chart-half">
            <canvas id="nistRadarChart"></canvas>
        </div>
        <div class="chart-half">
            <table>
                <thead>
                    <tr><th>NIST Function</th><th>Score</th><th>Key Data Sources</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Identify</strong></td>
                        <td>
                            <div class="score-bar">
                                <span><?php echo $nistScores['Identify']; ?></span>
                                <div class="score-bar-bg"><div class="score-bar-fill" style="width: <?php echo $nistScores['Identify']; ?>%"></div></div>
                            </div>
                        </td>
                        <td>Techs: <?php echo count($technologies); ?>, FAIR: <?php echo $fairAnalysis ? 'Yes' : 'No'; ?>, Tier: <?php echo $vendor['vendor_tier'] ?? 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Protect</strong></td>
                        <td>
                            <div class="score-bar">
                                <span><?php echo $nistScores['Protect']; ?></span>
                                <div class="score-bar-bg"><div class="score-bar-fill" style="width: <?php echo $nistScores['Protect']; ?>%"></div></div>
                            </div>
                        </td>
                        <td>TLS, App Hardening, Email, Network</td>
                    </tr>
                    <tr>
                        <td><strong>Detect</strong></td>
                        <td>
                            <div class="score-bar">
                                <span><?php echo $nistScores['Detect']; ?></span>
                                <div class="score-bar-bg"><div class="score-bar-fill" style="width: <?php echo $nistScores['Detect']; ?>%"></div></div>
                            </div>
                        </td>
                        <td>Vuln Exposure, Continuous Monitoring</td>
                    </tr>
                    <tr>
                        <td><strong>Respond</strong></td>
                        <td>
                            <div class="score-bar">
                                <span><?php echo $nistScores['Respond']; ?></span>
                                <div class="score-bar-bg"><div class="score-bar-fill" style="width: <?php echo $nistScores['Respond']; ?>%"></div></div>
                            </div>
                        </td>
                        <td>Assessments: <?php echo $assessmentCount; ?>, Governance: <?php echo $govAnswered > 0 ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Recover</strong></td>
                        <td>
                            <div class="score-bar">
                                <span><?php echo $nistScores['Recover']; ?></span>
                                <div class="score-bar-bg"><div class="score-bar-fill" style="width: <?php echo $nistScores['Recover']; ?>%"></div></div>
                            </div>
                        </td>
                        <td>Insurance: <?php echo $fairAnalysis && !empty($fairAnalysis['vendor_cyber_insurance_coverage']) ? 'Yes' : 'No'; ?>, BC Plan: <?php echo $fairAnalysis ? 'Yes' : 'N/A'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Score Trend -->
    <?php if (!empty($trendLabels)): ?>
    <div class="avoid-break">
        <h4 class="section-title-sm">Score Trend (1 Year)</h4>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Risk Severity Distribution -->
    <?php if (array_sum($severityCounts) > 0): ?>
    <div class="chart-row avoid-break">
        <div class="chart-half">
            <h4 class="section-title-sm">Risk Severity Distribution</h4>
            <canvas id="severityChart"></canvas>
        </div>
        <div class="chart-half">
            <h4 class="section-title-sm">Severity Breakdown</h4>
            <table>
                <thead><tr><th>Severity</th><th>Count</th><th>Percentage</th></tr></thead>
                <tbody>
                    <?php
                    $totalRisks = array_sum($severityCounts);
                    foreach ($severityCounts as $sev => $count):
                        $pct = $totalRisks > 0 ? round(($count / $totalRisks) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><span class="severity-<?php echo $sev; ?>"><?php echo ucfirst($sev); ?></span></td>
                        <td><?php echo $count; ?></td>
                        <td><?php echo $pct; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: 700; border-top: 2px solid #d1d5db;">
                        <td>Total</td>
                        <td><?php echo $totalRisks; ?></td>
                        <td>100%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- AI-Generated Analysis Sections (hidden until populated) -->
    <div id="aiSection" style="display: none;">
        <h3 class="section-title" style="page-break-before: always;">Summary</h3>
        <div class="ai-spinner" id="aiSpinner">
            Generating AI analysis... This may take 30-60 seconds.
        </div>
        <div id="aiResults"></div>
    </div>

    <!-- Risk Findings Summary -->
    <h3 class="section-title">Risk Findings Summary</h3>

    <?php if (!empty($topRisks)): ?>
    <div class="avoid-break">
        <h4 class="section-title-sm">Top Critical &amp; High <?php echo e($upguardName); ?> Risks</h4>
        <table>
            <thead><tr><th>Severity</th><th>Risk</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($topRisks, 0, 10) as $r): ?>
                <tr>
                    <td><span class="severity-<?php echo e($r['severity'] ?? 'info'); ?>"><?php echo ucfirst(e($r['severity'] ?? 'Info')); ?></span></td>
                    <td><strong><?php echo e($r['risk_name'] ?? 'Unknown'); ?></strong></td>
                    <td><?php echo e(mb_substr($r['description'] ?? '', 0, 120)); ?><?php echo strlen($r['description'] ?? '') > 120 ? '...' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($negativeSignals)): ?>
    <div class="avoid-break">
        <h4 class="section-title-sm">Top Negative <?php echo e($shodanName); ?> Signals</h4>
        <table>
            <thead><tr><th>Category</th><th>Signal</th><th>Points</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($negativeSignals, 0, 10) as $f): ?>
                <tr>
                    <td><?php echo e(ucwords(str_replace('_', ' ', $f['category'] ?? 'General'))); ?></td>
                    <td><?php echo e($f['description'] ?? $f['service_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo (int)($f['points'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($highCVEs)): ?>
    <div class="avoid-break">
        <h4 class="section-title-sm">High-Severity CVEs (CVSS &ge; 7.0)</h4>
        <table>
            <thead><tr><th>CVE ID</th><th>CVSS</th><th>Service</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($highCVEs, 0, 10) as $c): ?>
                <tr>
                    <td><strong><?php echo e($c['cve_id']); ?></strong></td>
                    <td><span class="severity-<?php echo ($c['cvss_score'] ?? 0) >= 9 ? 'critical' : 'high'; ?>"><?php echo number_format((float)($c['cvss_score'] ?? 0), 1); ?></span></td>
                    <td><?php echo e($c['service_name'] ?? 'N/A'); ?></td>
                    <td><?php echo e(mb_substr($c['description'] ?? '', 0, 100)); ?><?php echo strlen($c['description'] ?? '') > 100 ? '...' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (empty($topRisks) && empty($negativeSignals) && empty($highCVEs)): ?>
    <div class="ai-placeholder">No critical or high-severity risk findings recorded.</div>
    <?php endif; ?>

    <!-- Financial Impact (FAIR) -->
    <h3 class="section-title" style="page-break-before: always;">Financial Risk Impact (FAIR)</h3>
    <?php if ($fairAnalysis): ?>
    <div class="avoid-break">
        <div class="fair-grid">
            <div class="fair-metric">
                <div class="label">Risk Level</div>
                <div class="value"><?php echo e($fairAnalysis['risk_output'] ?? 'N/A'); ?></div>
                <div class="desc">Overall FAIR risk output</div>
            </div>
            <div class="fair-metric">
                <div class="label">Annual Loss Expectancy</div>
                <div class="value"><?php echo !empty($fairAnalysis['ale']) ? '$' . number_format((float)$fairAnalysis['ale'], 0) : 'N/A'; ?></div>
                <div class="desc">Expected annual financial loss</div>
            </div>
            <div class="fair-metric">
                <div class="label">Loss Event Frequency</div>
                <div class="value"><?php echo !empty($fairAnalysis['loss_event_frequency']) ? $fairAnalysis['loss_event_frequency'] . ' events/yr' : 'N/A'; ?></div>
                <div class="desc">Estimated events per year</div>
            </div>
            <div class="fair-metric">
                <div class="label">Primary Loss Magnitude</div>
                <div class="value"><?php echo !empty($fairAnalysis['primary_loss_magnitude']) ? '$' . number_format((float)$fairAnalysis['primary_loss_magnitude'], 0) : 'N/A'; ?></div>
                <div class="desc">Direct financial impact</div>
            </div>
            <div class="fair-metric">
                <div class="label">Secondary Loss Magnitude</div>
                <div class="value"><?php echo !empty($fairAnalysis['secondary_loss_magnitude']) ? '$' . number_format((float)$fairAnalysis['secondary_loss_magnitude'], 0) : 'N/A'; ?></div>
                <div class="desc">Indirect/reputational costs</div>
            </div>
            <div class="fair-metric">
                <div class="label">Recommended Insurance</div>
                <div class="value"><?php echo !empty($fairAnalysis['recommended_liability']) ? '$' . number_format((float)$fairAnalysis['recommended_liability'], 0) : 'N/A'; ?></div>
                <div class="desc">Recommended coverage level</div>
            </div>
        </div>
        <?php
        $recIns = (float)($fairAnalysis['recommended_liability'] ?? 0);
        $actIns = (float)($fairAnalysis['vendor_cyber_insurance_coverage'] ?? 0);
        if ($recIns > 0):
            $gap = $recIns - $actIns;
            $gapPct = round(($gap / $recIns) * 100);
        ?>
        <div style="margin-top: 10px; padding: 10px; background: <?php echo $gap > 0 ? '#fef2f2' : '#f0fdf4'; ?>; border-radius: 6px; border: 1px solid <?php echo $gap > 0 ? '#fecaca' : '#bbf7d0'; ?>;">
            <strong>Insurance Gap Analysis:</strong>
            Current coverage: $<?php echo number_format($actIns, 0); ?> |
            Recommended: $<?php echo number_format($recIns, 0); ?>
            <?php if ($gap > 0): ?>
            | <span style="color: #dc2626; font-weight: 700;">Gap: $<?php echo number_format($gap, 0); ?> (<?php echo $gapPct; ?>% undercovered)</span>
            <?php else: ?>
            | <span style="color: #16a34a; font-weight: 700;">Adequately covered</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ai-placeholder">No FAIR analysis has been completed for this vendor.<?php if (!$isAuditor): ?> <a href="fair-analysis.php?from_onboarding=<?php echo $vendorId; ?>" class="no-print">Create FAIR Assessment</a><?php endif; ?></div>
    <?php endif; ?>

    <!-- Vendor Provided Assessment Overview -->
    <?php if (!empty($certifications) || !empty($assessmentMeta) || !empty($shCategoryScores)): ?>
    <div class="avoid-break">
        <h3 class="section-title" style="page-break-before: always;">Vendor Provided Assessment Overview</h3>

        <?php if (!empty($certifications)): ?>
        <h4 class="section-title-sm">Active Certifications</h4>
        <table class="risk-table">
            <thead>
                <tr><th>Certification</th><th>Expiration</th><th>Status</th><th>Document</th></tr>
            </thead>
            <tbody>
                <?php foreach ($certifications as $cert):
                    $expDate = $cert['certification_expiration_date'] ?? null;
                    $certStatus = 'Unknown';
                    $certColor = '#6b7280';
                    if ($expDate) {
                        $expTs = strtotime($expDate);
                        $now = time();
                        $daysLeft = ($expTs - $now) / 86400;
                        if ($daysLeft < 0) {
                            $certStatus = 'Expired';
                            $certColor = '#dc2626';
                        } elseif ($daysLeft < 30) {
                            $certStatus = 'Expiring Soon';
                            $certColor = '#d97706';
                        } else {
                            $certStatus = 'Valid';
                            $certColor = '#16a34a';
                        }
                    }
                ?>
                <tr>
                    <td><?php echo e($cert['certification_type'] ?? 'N/A'); ?></td>
                    <td><?php echo $expDate ? date('M j, Y', strtotime($expDate)) : 'N/A'; ?></td>
                    <td><span style="color: <?php echo $certColor; ?>; font-weight: 600;"><?php echo $certStatus; ?></span></td>
                    <td><?php echo e($cert['original_filename'] ?? '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($assessmentMeta)): ?>
        <h4 class="section-title-sm" style="margin-top: 18px;">Assessment Completion Summary</h4>
        <table class="risk-table">
            <thead>
                <tr><th>Assessment</th><th>Completed</th><th>Sections</th><th>Questions Answered</th></tr>
            </thead>
            <tbody>
                <?php foreach ($assessmentMeta as $am): ?>
                <tr>
                    <td><?php echo e($am['template_name']); ?></td>
                    <td><?php echo date('M j, Y', strtotime($am['completed_at'])); ?></td>
                    <td><?php echo (int)$am['sections']; ?></td>
                    <td><?php echo (int)$am['answered']; ?> / <?php echo (int)$am['total']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php
        // --- Vendor Claims vs. External Evidence (Reality Check Matrix) ---
        $domainLabels = [
            'tls_crypto'       => 'Encryption & TLS',
            'network_security' => 'Network Security',
            'app_hardening'    => 'Application Hardening',
            'email_security'   => 'Email Security',
            'vuln_exposure'    => 'Vulnerability Management',
        ];

        // Build external evidence summaries per domain from Shodan findings (exclude waived)
        $domainEvidence = [];
        if (!empty($shodanFindings)) {
            foreach ($shodanFindings as $f) {
                $cat = $f['category'] ?? $f['finding_type'] ?? '';
                if (!isset($domainLabels[$cat])) continue;
                $waiverKey = ($f['service_name'] ?? '') . ':' . ($f['subdomain'] ?? '');
                if (isset($shodanWaiverLookup[$waiverKey])) continue;
                $type = $f['signal_type'] ?? '';
                if (!isset($domainEvidence[$cat])) {
                    $domainEvidence[$cat] = ['positive' => 0, 'negative' => 0, 'details' => []];
                }
                if ($type === 'positive') {
                    $domainEvidence[$cat]['positive']++;
                } elseif ($type === 'negative') {
                    $domainEvidence[$cat]['negative']++;
                    if (count($domainEvidence[$cat]['details']) < 3) {
                        $detail = trim($f['description'] ?? $f['service_name'] ?? '');
                        if ($detail !== '') {
                            $domainEvidence[$cat]['details'][] = mb_substr($detail, 0, 80);
                        }
                    }
                }
            }
        }

        // UpGuard risk summary for vuln_exposure
        $ugRiskSummary = '';
        if (!empty($risks)) {
            $sevCounts = ['critical' => 0, 'high' => 0];
            foreach ($risks as $r) {
                $sev = strtolower($r['severity'] ?? '');
                if (isset($sevCounts[$sev])) $sevCounts[$sev]++;
            }
            $parts = [];
            if ($sevCounts['critical'] > 0) $parts[] = $sevCounts['critical'] . ' critical';
            if ($sevCounts['high'] > 0) $parts[] = $sevCounts['high'] . ' high';
            if (!empty($parts)) $ugRiskSummary = $upguardName . ': ' . implode(', ', $parts) . ' risks';
        }

        // Certifications summary for the matrix
        $certValid = 0;
        $certExpired = 0;
        $certTypes = [];
        foreach ($certifications as $cert) {
            $certTypes[] = $cert['certification_type'] ?? 'Unknown';
            $expDate = $cert['certification_expiration_date'] ?? null;
            if ($expDate && strtotime($expDate) < time()) {
                $certExpired++;
            } else {
                $certValid++;
            }
        }

        $hasExternalData = !empty($shCategoryScores) || !empty($risks);
        $hasAnyClaims = !empty($vendorClaims) || !empty($assessmentMeta);
        ?>

        <?php if ($hasExternalData || $hasAnyClaims): ?>
        <h4 class="section-title-sm" style="margin-top: 18px;">Vendor Claims vs. External Evidence</h4>
        <table class="risk-table" style="font-size: 0.85em;">
            <thead>
                <tr>
                    <th style="width: 16%;">Security Domain</th>
                    <th style="width: 10%; text-align: center;">Score</th>
                    <th style="width: 26%;">External Evidence</th>
                    <th style="width: 28%;">Vendor Claim</th>
                    <th style="width: 20%; text-align: center;">Assessment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domainLabels as $domainKey => $domainLabel):
                    $score = isset($shCategoryScores[$domainKey]) ? (int)$shCategoryScores[$domainKey] : null;

                    // Build external evidence text
                    $evidenceText = '';
                    if (isset($domainEvidence[$domainKey])) {
                        $ev = $domainEvidence[$domainKey];
                        $evidenceParts = [];
                        if ($ev['negative'] > 0) $evidenceParts[] = $ev['negative'] . ' negative signal' . ($ev['negative'] > 1 ? 's' : '');
                        if ($ev['positive'] > 0) $evidenceParts[] = $ev['positive'] . ' positive signal' . ($ev['positive'] > 1 ? 's' : '');
                        $evidenceText = $shodanName . ': ' . implode(', ', $evidenceParts);
                        if (!empty($ev['details'])) {
                            $evidenceText .= ' — ' . implode('; ', $ev['details']);
                        }
                    }
                    // Add UpGuard risk data for vuln_exposure
                    if ($domainKey === 'vuln_exposure' && !empty($ugRiskSummary)) {
                        $evidenceText = $evidenceText ? $evidenceText . '. ' . $ugRiskSummary : $ugRiskSummary;
                    }
                    if (empty($evidenceText) && $score !== null) {
                        $evidenceText = $shodanName . ': Score ' . $score . '/100';
                    }

                    // Vendor claim — combine Assessment + VSM sources
                    $claimText = '(No response)';
                    $hasPositiveClaim = false;
                    $isNegative = false;
                    if (isset($vendorClaims[$domainKey])) {
                        $claim = $vendorClaims[$domainKey];
                        $claimParts = [];

                        // Assessment-sourced claim
                        if (!empty($claim['answer'])) {
                            $claimParts[] = '[Assessment] ' . $claim['answer'];
                        }
                        // VSM-sourced claim
                        if (!empty($claim['vsm_answer'])) {
                            $vsmLabel = !empty($claim['vsm_source']) ? $claim['vsm_source'] : 'Document';
                            $claimParts[] = '[VSM] "' . $claim['vsm_answer'] . '" (' . $vsmLabel . ')';
                        }

                        $claimText = !empty($claimParts) ? implode("\n", $claimParts) : '(No response)';

                        // Determine if positive claim exists (from either source)
                        $assessAnswer = strtolower($claim['answer'] ?? '');
                        $negativeIndicators = ['no', 'n/a', 'none', 'not applicable', 'not implemented', 'false'];
                        $isNegative = in_array($assessAnswer, $negativeIndicators);
                        $hasPositiveClaim = (!$isNegative && $assessAnswer !== '' && $assessAnswer !== '(no response)')
                                         || !empty($claim['vsm_answer']);
                    }

                    // Alignment assessment — considers score + negative evidence count
                    $negCount = isset($domainEvidence[$domainKey]) ? $domainEvidence[$domainKey]['negative'] : 0;
                    $alignIcon = '— No Data';
                    $alignColor = '#6b7280';
                    if ($score !== null && isset($vendorClaims[$domainKey])) {
                        if ($score < 30 && $hasPositiveClaim) {
                            $alignIcon = '&#10007; Contradiction';
                            $alignColor = '#dc2626';
                        } elseif (($score < 50 || $negCount >= 3) && $hasPositiveClaim) {
                            $alignIcon = '&#9888; Discrepancy';
                            $alignColor = '#d97706';
                        } elseif ($score >= 70 && $hasPositiveClaim) {
                            $alignIcon = '&#10003; Aligned';
                            $alignColor = '#16a34a';
                        } elseif ($hasPositiveClaim) {
                            $alignIcon = '&#9888; Partial';
                            $alignColor = '#d97706';
                        } elseif ($isNegative ?? false) {
                            $alignIcon = '&#10003; Acknowledged';
                            $alignColor = '#6b7280';
                        } else {
                            $alignIcon = '— Review';
                            $alignColor = '#6b7280';
                        }
                    } elseif ($score !== null && !isset($vendorClaims[$domainKey])) {
                        $alignIcon = '— Unverified';
                        $alignColor = '#6b7280';
                    }
                ?>
                <tr>
                    <td style="font-weight: 600;"><?php echo $domainLabel; ?></td>
                    <td style="text-align: center;">
                        <?php if ($score !== null): ?>
                        <span style="font-weight: 700; color: <?php echo $score >= 70 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626'); ?>;"><?php echo $score; ?>/100</span>
                        <?php else: ?>
                        <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.9em; color: #374151;"><?php echo !empty($evidenceText) ? e($evidenceText) : '<span style="color:#9ca3af;">No data</span>'; ?></td>
                    <td style="font-size: 0.9em;"><?php echo nl2br(e($claimText)); ?></td>
                    <td style="text-align: center; font-weight: 600; color: <?php echo $alignColor; ?>;"><?php echo $alignIcon; ?></td>
                </tr>
                <?php endforeach; ?>

                <!-- Certifications row -->
                <tr>
                    <td style="font-weight: 600;">Certifications</td>
                    <td style="text-align: center;"><span style="color: #9ca3af;">—</span></td>
                    <td style="font-size: 0.9em; color: #374151;">
                        <?php if (!empty($certifications)):
                            $certParts = [];
                            if ($certValid > 0) $certParts[] = $certValid . ' valid';
                            if ($certExpired > 0) $certParts[] = $certExpired . ' expired';
                            echo e(implode(', ', $certParts) . ' on file');
                        else: ?>
                        <span style="color:#9ca3af;">None on file</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.9em;">
                        <?php
                        $certClaimParts = [];
                        if (isset($vendorClaims['certifications'])) {
                            if (!empty($vendorClaims['certifications']['answer'])) {
                                $certClaimParts[] = '[Assessment] ' . $vendorClaims['certifications']['answer'];
                            }
                            if (!empty($vendorClaims['certifications']['vsm_answer'])) {
                                $vsmSrc = $vendorClaims['certifications']['vsm_source'] ?? 'Document';
                                $certClaimParts[] = '[VSM] "' . $vendorClaims['certifications']['vsm_answer'] . '" (' . $vsmSrc . ')';
                            }
                        }
                        if (!empty($certClaimParts)):
                            echo nl2br(e(implode("\n", $certClaimParts)));
                        elseif (!empty($certTypes)):
                            echo e(implode(', ', array_slice($certTypes, 0, 3)) . ' provided');
                        else: ?>
                        (No response)
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: 600; color: <?php
                        if (!empty($certifications) && $certExpired > 0 && $certValid > 0) {
                            echo '#d97706;">&#9888; Partial';
                        } elseif (!empty($certifications) && $certExpired === 0) {
                            echo '#16a34a;">&#10003; Aligned';
                        } elseif (!empty($certifications) && $certValid === 0) {
                            echo '#dc2626;">&#10007; Expired';
                        } else {
                            echo '#6b7280;">— No Data';
                        }
                    ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 6px; font-size: 0.8em; color: #6b7280;">
            <strong>Legend:</strong>
            <span style="color: #16a34a;">&#10003; Aligned</span> = Score &ge; 70 &amp; positive claim |
            <span style="color: #d97706;">&#9888; Discrepancy</span> = Score &lt; 50 &amp; positive claim |
            <span style="color: #dc2626;">&#10007; Contradiction</span> = Score &lt; 30 &amp; positive claim |
            <span style="color: #6b7280;">— Unverified</span> = No vendor response
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Category Scores (Horizontal Bars) -->
    <?php if (!empty($ugCategoryScores) || !empty($shCategoryScores)): ?>
    <div class="avoid-break">
        <h3 class="section-title">Category Scores</h3>
        <div class="chart-container">
            <canvas id="categoryChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Technology Inventory -->
    <?php if (!empty($technologies)): ?>
    <div class="avoid-break">
        <h3 class="section-title">Technology Inventory (Top 20)</h3>
        <?php
        $techByCategory = [];
        foreach (array_slice($technologies, 0, 20) as $tech) {
            $cat = $tech['technology_category'] ?? 'other';
            $techByCategory[$cat][] = $tech;
        }
        ?>
        <div class="tech-grid">
            <?php foreach ($techByCategory as $cat => $techs): ?>
            <div>
                <div class="tech-category"><?php echo e(ucwords(str_replace('_', ' ', $cat))); ?></div>
                <?php foreach ($techs as $t): ?>
                <div class="tech-item"><?php echo e($t['technology_name']); ?><?php echo !empty($t['technology_version']) ? ' <span style="color:#9ca3af;">v' . e($t['technology_version']) . '</span>' : ''; ?></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="report-footer">
        Confidential — <?php echo e($reportDate); ?> — Generated by Open TPRM<br>
        This assessment references the NIST Cybersecurity Framework (CSF) 2.0. NIST CSF is a trademark of the National Institute of Standards and Technology.
    </div>

</div>

<!-- Charts JavaScript -->
<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var themeColor = <?php echo json_encode($headerColor); ?>;
    var themeColorAlpha = themeColor + '33';

    // === NIST CSF Radar Chart ===
    var nistCtx = document.getElementById('nistRadarChart');
    if (nistCtx) {
        new Chart(nistCtx, {
            type: 'radar',
            data: {
                labels: ['Identify', 'Protect', 'Detect', 'Respond', 'Recover'],
                datasets: [{
                    label: 'NIST CSF Score',
                    data: <?php echo json_encode(array_values($nistScores)); ?>,
                    borderColor: themeColor,
                    backgroundColor: themeColorAlpha,
                    borderWidth: 2,
                    pointBackgroundColor: themeColor,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 1,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { stepSize: 20, font: { size: 9 } },
                        pointLabels: { font: { size: 11, weight: 'bold' } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'NIST CSF 2.0 Maturity', font: { size: 13 } }
                }
            }
        });
    }

    // === Score Trend Line Chart ===
    <?php if (!empty($trendLabels)): ?>
    var trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        var datasets = [];
        <?php if (!empty($ugTrendData)): ?>
        datasets.push({
            label: <?php echo json_encode($upguardName . ' (normalized %)'); ?>,
            data: <?php echo json_encode($ugTrendData); ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.1)',
            fill: false,
            tension: 0.3,
            pointRadius: 2,
            spanGaps: true
        });
        <?php endif; ?>
        <?php if (!empty($shTrendData)): ?>
        datasets.push({
            label: <?php echo json_encode($shodanName . ' (%)'); ?>,
            data: <?php echo json_encode($shTrendData); ?>,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.1)',
            fill: false,
            tension: 0.3,
            pointRadius: 2,
            spanGaps: true
        });
        <?php endif; ?>
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendLabels); ?>,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score (%)' } },
                    x: { ticks: { maxTicksToShow: 12, font: { size: 8 } } }
                },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 9 } } }
                }
            }
        });
    }
    <?php endif; ?>

    // === Severity Doughnut Chart ===
    <?php if (array_sum($severityCounts) > 0): ?>
    var sevCtx = document.getElementById('severityChart');
    if (sevCtx) {
        new Chart(sevCtx, {
            type: 'doughnut',
            data: {
                labels: ['Critical', 'High', 'Medium', 'Low', 'Info'],
                datasets: [{
                    data: <?php echo json_encode(array_values($severityCounts)); ?>,
                    backgroundColor: ['#dc2626', '#ea580c', '#d97706', '#2563eb', '#6b7280'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 1.4,
                plugins: {
                    legend: { position: 'right', labels: { font: { size: 9 }, padding: 8 } }
                }
            }
        });
    }
    <?php endif; ?>

    // === Category Scores Horizontal Bar Chart ===
    <?php if (!empty($ugCategoryScores) || !empty($shCategoryScores)): ?>
    var catCtx = document.getElementById('categoryChart');
    if (catCtx) {
        var allCats = {};
        <?php foreach ($ugCategoryScores as $cat => $val): ?>
        allCats[<?php echo json_encode(ucwords(str_replace('_', ' ', $cat))); ?>] = { ug: <?php echo (int)$val; ?>, sh: 0 };
        <?php endforeach; ?>
        <?php foreach ($shCategoryScores as $cat => $val): ?>
        var catLabel = <?php echo json_encode(ucwords(str_replace('_', ' ', $cat))); ?>;
        if (!allCats[catLabel]) allCats[catLabel] = { ug: 0, sh: 0 };
        allCats[catLabel].sh = <?php echo (int)$val; ?>;
        <?php endforeach; ?>

        var catLabels = Object.keys(allCats);
        var ugData = catLabels.map(function(c) { return allCats[c].ug; });
        var shData = catLabels.map(function(c) { return allCats[c].sh; });

        var catDatasets = [];
        if (ugData.some(function(v) { return v > 0; })) {
            catDatasets.push({
                label: <?php echo json_encode($upguardName); ?>,
                data: ugData,
                backgroundColor: 'rgba(59,130,246,0.7)',
                borderRadius: 3
            });
        }
        if (shData.some(function(v) { return v > 0; })) {
            catDatasets.push({
                label: <?php echo json_encode($shodanName); ?>,
                data: shData,
                backgroundColor: 'rgba(245,158,11,0.7)',
                borderRadius: 3
            });
        }

        new Chart(catCtx, {
            type: 'bar',
            data: { labels: catLabels, datasets: catDatasets },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    x: { beginAtZero: true, max: 100, title: { display: true, text: 'Score' } }
                },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 9 } } }
                }
            }
        });
    }
    <?php endif; ?>
})();

// === AI Generation (AJAX) ===
var _detailedCsrf = <?php echo json_encode($csrfToken); ?>;

function generateAiAnalysis() {
    var btn = document.getElementById('generateAiBtn');
    var section = document.getElementById('aiSection');
    var spinner = document.getElementById('aiSpinner');
    var results = document.getElementById('aiResults');
    var origText = btn ? btn.innerHTML : '';

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Generating...';
    }
    // Show section with spinner
    if (section) section.style.display = 'block';
    if (spinner) spinner.classList.add('active');
    if (results) results.innerHTML = '';

    fetch('api/generate-detailed-summary.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            vendor_id: <?php echo (int)$vendorId; ?>,
            csrf_token: _detailedCsrf
        })
    })
    .then(function(r) {
        if (!r.ok) {
            return r.text().then(function(t) {
                throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200));
            });
        }
        return r.json();
    })
    .then(function(data) {
        if (data.csrf_token) _detailedCsrf = data.csrf_token;

        if (data.error) {
            if (spinner) spinner.classList.remove('active');
            if (section) section.style.display = 'none';
            alert('AI analysis failed: ' + data.error);
            if (btn) { btn.disabled = false; btn.innerHTML = origText; }
            return;
        }

        if (data.queued && data.job_id) {
            var pollInterval = setInterval(function() {
                fetch('api/ai-job-status.php?id=' + data.job_id)
                .then(function(r) { return r.json(); })
                .then(function(poll) {
                    if (poll.csrf_token) _detailedCsrf = poll.csrf_token;

                    if (poll.status === 'completed' && poll.result) {
                        clearInterval(pollInterval);
                        if (spinner) spinner.classList.remove('active');
                        if (poll.result.summary) {
                            if (results) results.innerHTML = poll.result.summary;
                        } else {
                            if (section) section.style.display = 'none';
                        }
                        if (btn) { btn.disabled = false; btn.innerHTML = origText; }
                    } else if (poll.status === 'failed') {
                        clearInterval(pollInterval);
                        if (spinner) spinner.classList.remove('active');
                        if (section) section.style.display = 'none';
                        alert('AI analysis failed: ' + (poll.error || 'Processing failed. Please try again.'));
                        if (btn) { btn.disabled = false; btn.innerHTML = origText; }
                    }
                })
                .catch(function(err) {
                    clearInterval(pollInterval);
                    console.error('AI poll error:', err);
                    if (spinner) spinner.classList.remove('active');
                    if (section) section.style.display = 'none';
                    alert('Failed to check AI analysis status.');
                    if (btn) { btn.disabled = false; btn.innerHTML = origText; }
                });
            }, 3000);
        }
    })
    .catch(function(err) {
        console.error('AI generation error:', err);
        if (spinner) spinner.classList.remove('active');
        if (section) section.style.display = 'none';
        alert('AI analysis request failed: ' + err.message);
        if (btn) { btn.disabled = false; btn.innerHTML = origText; }
    });
}

// Bind buttons via JS (inline onclick blocked by CSP)
document.getElementById('printBtn').addEventListener('click', function() { window.print(); });
var aiBtn = document.getElementById('generateAiBtn');
if (aiBtn) aiBtn.addEventListener('click', generateAiAnalysis);
</script>

</body>
</html>
