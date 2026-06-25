<?php
/**
 * API: Generate Cybersecurity Program Overview PDF Report
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Generates a professional landscape PDF Cyber Report from a completed
 * CSF Maturity Assessment. Uses application branding colors from app_config.
 * The report title follows the pattern: "{CompanyName} Cyber Report"
 *
 * Actions:
 *   generate  - Creates the PDF, encrypts (AES-256-CBC), stores in grc_assessments
 *   download  - Streams the decrypted PDF for download
 *
 * Security:
 *   - CSRF validated on generate, session validated on download
 *   - ACL: administrator, cyber_grc, auditor, grc_contributors
 *   - PDF encrypted at rest in database LONGBLOB
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

$auth     = Auth::getInstance();
$user     = $auth->getUser();
$security = Security::getInstance();
$session  = Session::getInstance();
$db       = Database::getInstance();

// ACL
$isAdmin       = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC    = hasGroup('cyber_grc');
$isAuditor     = hasGroup('auditor');
$isContributor = hasGroup('grc_contributors');

if (!$isAdmin && !$isCyberGRC && !$isAuditor && !$isContributor) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// ─── DOWNLOAD action (GET with ?action=download&id=N) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download') {
    $assessmentId = (int)($_GET['id'] ?? 0);
    if ($assessmentId <= 0) {
        http_response_code(400);
        echo 'Assessment ID required.';
        exit;
    }

    $row = $db->fetchOne(
        'SELECT report_encrypted_data, report_file_name, report_file_mime FROM grc_assessments WHERE id = :id',
        [':id' => $assessmentId]
    );

    if (!$row || empty($row['report_encrypted_data'])) {
        http_response_code(404);
        echo 'No report found. Generate the report first.';
        exit;
    }

    $encryption = new Encryption();
    $pdfData = $encryption->decryptRaw($row['report_encrypted_data']);
    if (empty($pdfData)) {
        http_response_code(500);
        echo 'Failed to decrypt report.';
        exit;
    }

    $fileName = $row['report_file_name'] ?: 'Cyber_Report.pdf';
    // Header-injection hygiene: strip CR/LF/quote/NUL and any path component.
    $fileName = str_replace(["\r", "\n", '"', "\0"], '', basename($fileName));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdfData));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $pdfData;
    exit;
}

// ─── GENERATE action (POST JSON) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// BOLA fix: the auditor role is read-only and must not author/overwrite report
// content (the report page renders these fields read-only for auditors). Download
// (GET, above) stays open to all reviewers; generation requires an editor role.
if ($isAuditor && !$isAdmin && !$isCyberGRC && !$isContributor) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Read-only access: report generation requires editor privileges.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
    exit;
}

if (!$security->validateCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newToken = $security->getCSRFToken();
$assessmentId = (int)($input['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Assessment ID required.', 'csrf_token' => $newToken]);
    exit;
}

// Load assessment
$assessment = $db->fetchOne(
    'SELECT a.*, u.full_name as lead_auditor_name
     FROM grc_assessments a
     LEFT JOIN users u ON u.id = a.lead_auditor_id
     WHERE a.id = :id',
    [':id' => $assessmentId]
);

if (!$assessment) {
    echo json_encode(['success' => false, 'error' => 'Assessment not found.', 'csrf_token' => $newToken]);
    exit;
}

// Load company name — use user-provided name if given, otherwise fall back to admin settings
$orgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
$defaultOrgName = ($orgRow && !empty($orgRow['config_value'])) ? $orgRow['config_value'] : 'Organization';
$userOrgName = trim($input['company_name'] ?? '');
$orgName = ($userOrgName !== '') ? $userOrgName : $defaultOrgName;

$headerColorRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'header_color'");
$brandHex = ($headerColorRow && preg_match('/^#[0-9a-fA-F]{6}$/', $headerColorRow['config_value'] ?? '')) ? $headerColorRow['config_value'] : '#35a0a3';

// Convert hex to RGB floats (0-1) for dompdf filled_rectangle
function hexToRgbFloat(string $hex): array {
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

// Darken a hex color by a factor (0.0 = black, 1.0 = unchanged)
function darkenHex(string $hex, float $factor): string {
    $hex = ltrim($hex, '#');
    $r = max(0, (int)(hexdec(substr($hex, 0, 2)) * $factor));
    $g = max(0, (int)(hexdec(substr($hex, 2, 2)) * $factor));
    $b = max(0, (int)(hexdec(substr($hex, 4, 2)) * $factor));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$brandRgb = hexToRgbFloat($brandHex);
$brandDark = darkenHex($brandHex, 0.6);   // Darker variant for table headers, navy elements
$brandDarkRgb = hexToRgbFloat($brandDark);

// Editable sections from the report editor (if provided)
$sections = $input['sections'] ?? [];
$execSummary    = trim($sections['executive_summary'] ?? '');
$scopeNarrative = trim($sections['scope'] ?? '');
$methodology    = trim($sections['methodology'] ?? '');
$recommendations = trim($sections['recommendations'] ?? '');
$conclusion     = trim($sections['conclusion'] ?? '');

// Load service
$uas = UnifiedAssessmentService::getInstance();

// Load data
$progress   = $uas->getAssessmentProgress($assessmentId);
$domains    = $uas->getDomainScores($assessmentId);
$frameworks = $uas->calculateFrameworkCompliance($assessmentId);
$gaps       = $uas->getGapAnalysis($assessmentId);
$responses  = $uas->getResponses($assessmentId);

// Calculate summary stats
$totalQuestions = (int)($progress['total_questions'] ?? 0);
$answered       = (int)($progress['answered'] ?? 0);
$overallScore   = number_format((float)($assessment['overall_fairscore'] ?? 0), 1);
$compliancePct  = (int)($assessment['overall_compliance_pct'] ?? 0);
$gapCount       = count($gaps);
$reportDate     = date('F j, Y');
$fileName       = str_replace(' ', '_', $orgName) . '_Cyber_Report_' . date('Ymd') . '.pdf';

// ─── Group responses by domain ──────────────────────────────────────────────
$responsesByDomain = [];
foreach ($responses as $r) {
    $dCode = $r['domain_code'] ?? 'UNKNOWN';
    $responsesByDomain[$dCode][] = $r;
}

// Group responses by maturity tier
$byTier = [1 => [], 2 => [], 3 => [], 4 => []];
foreach ($responses as $r) {
    $rating = (int)($r['maturity_rating'] ?? 0);
    if ($rating >= 1 && $rating <= 4) {
        $byTier[$rating][] = $r;
    }
}

// Normalize domain data with consistent keys
$domainData = [];
foreach ($domains as $d) {
    $score = (float)($d['average_score'] ?? $d['avg_score'] ?? $d['score'] ?? 0);
    $domainData[] = [
        'code' => $d['domain_code'] ?? '',
        'name' => $d['domain_name'] ?? '',
        'score' => $score,
    ];
}

// If no domain scores from DB, try to build from responses
if (empty($domainData) && !empty($responses)) {
    $domainScoreCalc = [];
    foreach ($responses as $r) {
        $dCode = $r['domain_code'] ?? '';
        if ($dCode === '') continue;
        if (!isset($domainScoreCalc[$dCode])) {
            $domainScoreCalc[$dCode] = ['code' => $dCode, 'name' => $r['domain_name'] ?? '', 'sum' => 0, 'count' => 0];
        }
        if (!empty($r['maturity_rating'])) {
            $domainScoreCalc[$dCode]['sum'] += (int)$r['maturity_rating'];
            $domainScoreCalc[$dCode]['count']++;
        }
    }
    foreach ($domainScoreCalc as $dc) {
        $domainData[] = [
            'code' => $dc['code'],
            'name' => $dc['name'],
            'score' => $dc['count'] > 0 ? round($dc['sum'] / $dc['count'], 2) : 0,
        ];
    }
}

// ─── Build PDF HTML ─────────────────────────────────────────────────────────

$h = function($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };

// Score color helper
function scoreColor(float $score): string {
    if ($score >= 3.5) return '#059669';
    if ($score >= 2.5) return '#3b82f6';
    if ($score >= 1.5) return '#f59e0b';
    return '#dc3545';
}

function scoreTier(float $score): string {
    if ($score >= 3.5) return 'Adaptive (Tier 4)';
    if ($score >= 2.5) return 'Repeatable (Tier 3)';
    if ($score >= 1.5) return 'Risk-Informed (Tier 2)';
    if ($score >= 0.5) return 'Partial (Tier 1)';
    return 'Not Assessed';
}


// ─── CSS ────────────────────────────────────────────────────────────────────

$pdfHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">';
$pdfHtml .= '<style>
    @page { size: letter landscape; margin: 50pt 36pt 48pt 36pt; }
    body { font-family: Helvetica, sans-serif; font-size: 9pt; color: #1a1a2e; line-height: 1.4; }
    h2 { font-size: 14pt; color: ' . $brandDark . '; border-bottom: 2pt solid ' . $brandHex . '; padding-bottom: 4pt; margin: 16pt 0 8pt; }
    h3 { font-size: 11pt; color: #374151; margin: 12pt 0 6pt; }
    table { width: 100%; border-collapse: collapse; font-size: 8pt; }
    th { background: ' . $brandDark . '; color: #fff; padding: 5pt 6pt; text-align: left; }
    td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e5e7eb; vertical-align: top; }
    .page-break { page-break-before: always; }
    .page-title { font-size: 18pt; font-weight: bold; color: ' . $brandDark . '; margin: 0 0 12pt; }
    .score-badge { display: inline-block; padding: 2pt 7pt; border-radius: 8pt; color: #fff; font-weight: bold; font-size: 8pt; }
    .bar-outer { background: #e5e7eb; height: 8pt; }
    .bar-inner { height: 8pt; }
    .stat-box { display: inline-block; width: 22%; text-align: center; border: 0.5pt solid #e5e7eb; padding: 10pt 4pt; margin-right: 2%; vertical-align: top; }
    .stat-value { font-size: 22pt; font-weight: bold; }
    .stat-label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; }
    .gap-row td { background: #fef2f2; }
    .domain-header { padding: 5pt 8pt; color: #fff; font-weight: bold; font-size: 9pt; }
    .tier-box { padding: 10pt; border-top: 3pt solid #ccc; text-align: center; }
    p { margin: 4pt 0 6pt; }
</style></head><body>';

// ─── PAGE 1: COVER ──────────────────────────────────────────────────────────

$pdfHtml .= '<div style="text-align:center;padding-top:100pt;">';
$pdfHtml .= '<div style="font-size:10pt;color:' . $brandHex . ';text-transform:uppercase;letter-spacing:3pt;">CSF Maturity Assessment &middot; Final Report</div>';
$pdfHtml .= '<div style="font-size:32pt;font-weight:bold;color:' . $brandDark . ';margin:12pt 0;">' . $h($orgName) . '</div>';
$pdfHtml .= '<div style="font-size:18pt;color:#374151;">Cyber Report</div>';
$pdfHtml .= '<div style="border-top:3pt solid ' . $brandHex . ';width:100pt;margin:20pt auto;"></div>';

// Metadata table
$pdfHtml .= '<table style="width:400pt;margin:0 auto;font-size:10pt;"><tbody>';
$pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Assessment Title</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h($assessment['title'] ?? '') . '</td></tr>';
$pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Reference</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h($assessment['assessment_ref'] ?? '') . '</td></tr>';
$pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Type</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h(ucfirst(str_replace('_', ' ', $assessment['assessment_type'] ?? ''))) . '</td></tr>';
if (!empty($assessment['lead_auditor_name'])) {
    $pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Lead Auditor</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h($assessment['lead_auditor_name']) . '</td></tr>';
}
$pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Report Date</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h($reportDate) . '</td></tr>';
$pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Status</td><td style="text-align:left;font-weight:bold;border:none;padding:4pt 10pt;">' . $h(ucfirst(str_replace('_', ' ', $assessment['status'] ?? ''))) . '</td></tr>';
if (!empty($assessment['scope'])) {
    $pdfHtml .= '<tr><td style="text-align:right;width:140pt;color:#6b7280;border:none;padding:4pt 10pt;">Scope</td><td style="text-align:left;border:none;padding:4pt 10pt;font-size:9pt;">' . $h($assessment['scope']) . '</td></tr>';
}
$pdfHtml .= '</tbody></table>';
$pdfHtml .= '</div>';

// ─── PAGE 2: SCORING METHODOLOGY ────────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Scoring Methodology</h2>';
$pdfHtml .= '<p style="font-size:9pt;color:#374151;margin-bottom:10pt;">CSF Maturity Components: 14 Security Domains &middot; 146 Unified Questions &middot; 8 Framework Mappings</p>';

$pdfHtml .= '<table style="width:100%;"><tbody><tr>';

// Tier 1 - Initial
$pdfHtml .= '<td style="width:25%;vertical-align:top;padding:0 4pt;border:none;">';
$pdfHtml .= '<div class="tier-box" style="border-top-color:#dc3545;background:#fee2e2;">';
$pdfHtml .= '<div style="font-size:11pt;font-weight:bold;color:#dc3545;">Tier 1</div>';
$pdfHtml .= '<div style="font-size:9pt;font-weight:bold;color:#1a1a2e;margin:4pt 0;">Initial</div>';
$pdfHtml .= '<div style="font-size:7pt;color:#374151;text-align:left;">Ad-hoc and undocumented practices. Security controls are reactive and inconsistently applied. No formal policies or procedures govern cybersecurity activities.</div>';
$pdfHtml .= '</div></td>';

// Tier 2 - Risk-Informed
$pdfHtml .= '<td style="width:25%;vertical-align:top;padding:0 4pt;border:none;">';
$pdfHtml .= '<div class="tier-box" style="border-top-color:#f59e0b;background:#fef3c7;">';
$pdfHtml .= '<div style="font-size:11pt;font-weight:bold;color:#f59e0b;">Tier 2</div>';
$pdfHtml .= '<div style="font-size:9pt;font-weight:bold;color:#1a1a2e;margin:4pt 0;">Risk-Informed</div>';
$pdfHtml .= '<div style="font-size:7pt;color:#374151;text-align:left;">Partially documented practices with some risk awareness. Policies exist but are not consistently followed. Security activities are partially integrated into organizational processes.</div>';
$pdfHtml .= '</div></td>';

// Tier 3 - Repeatable
$pdfHtml .= '<td style="width:25%;vertical-align:top;padding:0 4pt;border:none;">';
$pdfHtml .= '<div class="tier-box" style="border-top-color:#3b82f6;background:#dbeafe;">';
$pdfHtml .= '<div style="font-size:11pt;font-weight:bold;color:#3b82f6;">Tier 3</div>';
$pdfHtml .= '<div style="font-size:9pt;font-weight:bold;color:#1a1a2e;margin:4pt 0;">Repeatable</div>';
$pdfHtml .= '<div style="font-size:7pt;color:#374151;text-align:left;">Documented and consistently implemented controls. Formal policies and procedures are established and followed. Security practices are integrated into business operations.</div>';
$pdfHtml .= '</div></td>';

// Tier 4 - Adaptive
$pdfHtml .= '<td style="width:25%;vertical-align:top;padding:0 4pt;border:none;">';
$pdfHtml .= '<div class="tier-box" style="border-top-color:#059669;background:#dcfce7;">';
$pdfHtml .= '<div style="font-size:11pt;font-weight:bold;color:#059669;">Tier 4</div>';
$pdfHtml .= '<div style="font-size:9pt;font-weight:bold;color:#1a1a2e;margin:4pt 0;">Adaptive</div>';
$pdfHtml .= '<div style="font-size:7pt;color:#374151;text-align:left;">Continuously monitored and optimized controls. Organization adapts cybersecurity practices based on threat intelligence, lessons learned, and predictive analytics.</div>';
$pdfHtml .= '</div></td>';

$pdfHtml .= '</tr></tbody></table>';

$pdfHtml .= '<p style="font-size:8pt;color:#6b7280;margin-top:14pt;">Each of the 146 unified security questions is scored on a 1&ndash;4 maturity scale aligned with NIST Cybersecurity Framework 2.0 Implementation Tiers. Domain scores represent the average maturity rating across all assessed questions within the domain. The overall CSF Maturity Score is the weighted average across all 14 security domains. Framework compliance percentages reflect the proportion of mapped requirements that are conforming (with partial conformity weighted at 50%).</p>';

// ─── PAGE 3: CSF MATURITY SCORE — GD Radial Chart ───────────────────────────

$pdfHtml .= '<div class="page-break"></div>';

$sc = (float)$overallScore;

// Distinct vibrant colors for each domain (CyQu-style palette)
$domainColors = [
    'GOV' => '#1e3a5f', 'IAM' => '#dc2626', 'DSP' => '#ea580c', 'EPS' => '#d97706',
    'NET' => '#059669', 'APS' => '#db2777', 'OPS' => '#7c3aed', 'INC' => '#2563eb',
    'SCM' => '#0891b2', 'PHY' => '#0d9488', 'HRS' => '#c026d3', 'BCP' => '#e11d48',
    'CRY' => '#4f46e5', 'CMP' => '#0369a1',
];

// Helper: hex to GD int RGB array
function hexToRgbInt(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

// ── Generate modern radial chart as PNG via GD ──
$chartBase64 = '';
if (!empty($domainData) && extension_loaded('gd')) {
    $imgW = 1600;
    $imgH = 1000;
    $img = imagecreatetruecolor($imgW, $imgH);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    imageantialias($img, true);

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontNorm = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    // Soft off-white background
    $bgColor = imagecolorallocate($img, 252, 252, 254);
    imagefill($img, 0, 0, $bgColor);

    // Chart geometry — chart on left side, legend panel on right
    $cx = 520;
    $cy = 470;
    $maxR = 310;
    $innerR = 90;
    $n = count($domainData);
    $sliceAngle = 360.0 / max(1, $n);
    $gap = 2.0; // wider gap for cleaner separation

    $sortedChart = $domainData;
    usort($sortedChart, function($a, $b) { return strcmp($a['code'], $b['code']); });

    // ── Subtle shadow behind chart ──
    for ($sh = 6; $sh >= 1; $sh--) {
        $alpha = 120 - ($sh * 8);
        $shadowColor = imagecolorallocatealpha($img, 80, 80, 100, $alpha);
        imagefilledellipse($img, $cx + 3, $cy + 3, ($maxR + $sh) * 2, ($maxR + $sh) * 2, $shadowColor);
    }

    // White disc behind chart area (covers shadow center)
    $gdWhite = imagecolorallocate($img, 255, 255, 255);
    imagefilledellipse($img, $cx, $cy, $maxR * 2 + 4, $maxR * 2 + 4, $gdWhite);

    // ── Tier reference rings (subtle dashed appearance) ──
    $tierLabels = ['', 'Initial', 'Risk-Informed', 'Repeatable', 'Adaptive'];
    for ($tier = 1; $tier <= 4; $tier++) {
        $sr = (int)(($tier / 4) * $maxR);
        // Draw dotted ring: small arcs with gaps
        $dotColor = imagecolorallocate($img, 225, 228, 235);
        for ($a = 0; $a < 360; $a += 4) {
            imagefilledarc($img, $cx, $cy, $sr * 2, $sr * 2, $a, $a + 2, $dotColor, IMG_ARC_NOFILL | IMG_ARC_EDGED);
        }
        // Subtle tier label at 80 degrees (upper-right)
        $labelAngle = deg2rad(-80 + ($tier * 2));
        $tlx = $cx + ($sr + 2) * cos($labelAngle);
        $tly = $cy + ($sr + 2) * sin($labelAngle);
        $tierGrey = imagecolorallocate($img, 185, 190, 200);
        imagettftext($img, 8, 0, (int)$tlx + 2, (int)$tly - 1, $tierGrey, $fontNorm, (string)$tier);
    }

    // ── Draw domain wedges ──
    foreach ($sortedChart as $i => $d) {
        $score = (float)$d['score'];
        $dCode = $d['code'];
        $colorHex = $domainColors[$dCode] ?? '#6b7280';
        $rgb = hexToRgbInt($colorHex);
        $gdColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);

        // Radius proportional to score (minimum shows a visible wedge)
        $r = max($innerR + 12, (int)(($score / 4) * $maxR));

        $startDeg = -90 + ($i * $sliceAngle) + $gap;
        $endDeg = -90 + (($i + 1) * $sliceAngle) - $gap;

        // Subtle lighter version behind for depth
        $lightColor = imagecolorallocate($img, min(255, $rgb[0] + 40), min(255, $rgb[1] + 40), min(255, $rgb[2] + 40));
        imagefilledarc($img, $cx, $cy, ($r + 3) * 2, ($r + 3) * 2, (int)$startDeg, (int)$endDeg, $lightColor, IMG_ARC_PIE);

        // Main wedge
        imagefilledarc($img, $cx, $cy, $r * 2, $r * 2, (int)$startDeg, (int)$endDeg, $gdColor, IMG_ARC_PIE);

        // ── Domain label (code + score) positioned outside chart ──
        $midAngleDeg = ($startDeg + $endDeg) / 2;
        $midAngleRad = deg2rad($midAngleDeg);
        $labelR = $maxR + 28;
        $lx = $cx + $labelR * cos($midAngleRad);
        $ly = $cy + $labelR * sin($midAngleRad);

        // Build label: "GOV  4.0"
        $scoreLabel = $dCode . '  ' . number_format($score, 1);
        $bbox = imagettfbbox(11, 0, $fontBold, $scoreLabel);
        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[1] - $bbox[7];

        // Horizontal alignment
        if (cos($midAngleRad) < -0.3) {
            $lx -= $tw + 6;
        } elseif (abs(cos($midAngleRad)) <= 0.3) {
            $lx -= $tw / 2;
        } else {
            $lx += 6;
        }
        // Vertical alignment
        if (sin($midAngleRad) < -0.3) {
            $ly -= 4;
        } elseif (sin($midAngleRad) > 0.3) {
            $ly += $th + 4;
        } else {
            $ly += $th / 2;
        }

        imagettftext($img, 11, 0, (int)$lx, (int)$ly, $gdColor, $fontBold, $scoreLabel);

        // Domain name (smaller, below code)
        $nameGrey = imagecolorallocate($img, 110, 115, 125);
        $nameBox = imagettfbbox(8, 0, $fontNorm, $d['name']);
        $ntw = $nameBox[2] - $nameBox[0];
        $nlx = $lx;
        if (cos($midAngleRad) < -0.3) {
            $nlx = $lx + $tw - $ntw;
        }
        imagettftext($img, 8, 0, (int)$nlx, (int)$ly + 14, $nameGrey, $fontNorm, $d['name']);
    }

    // ── Center circle with modern styling ──
    // Outer glow rings
    for ($g = 8; $g >= 1; $g--) {
        $glowAlpha = 115 - ($g * 6);
        $glowColor = imagecolorallocatealpha($img, 240, 240, 245, $glowAlpha);
        imagefilledellipse($img, $cx, $cy, ($innerR + $g) * 2, ($innerR + $g) * 2, $glowColor);
    }

    // White center disc
    imagefilledellipse($img, $cx, $cy, $innerR * 2, $innerR * 2, $gdWhite);

    // Score-colored accent ring around center
    $scRgb = hexToRgbInt(scoreColor($sc));
    $gdScoreColor = imagecolorallocate($img, $scRgb[0], $scRgb[1], $scRgb[2]);
    imagesetthickness($img, 4);
    imageellipse($img, $cx, $cy, $innerR * 2 + 2, $innerR * 2 + 2, $gdScoreColor);
    imagesetthickness($img, 1);

    // Score value
    $scoreStr = number_format($sc, 1);
    $scoreBbox = imagettfbbox(32, 0, $fontBold, $scoreStr);
    $stw = $scoreBbox[2] - $scoreBbox[0];
    imagettftext($img, 32, 0, $cx - (int)($stw / 2), $cy + 8, $gdScoreColor, $fontBold, $scoreStr);

    // "of 4.0" subtitle
    $subGrey = imagecolorallocate($img, 140, 145, 155);
    $subStr = 'of 4.0';
    $subBbox = imagettfbbox(11, 0, $fontNorm, $subStr);
    $subW = $subBbox[2] - $subBbox[0];
    imagettftext($img, 11, 0, $cx - (int)($subW / 2), $cy + 28, $subGrey, $fontNorm, $subStr);

    // Tier name
    $tierName = scoreTier($sc);
    $tnBbox = imagettfbbox(8, 0, $fontNorm, $tierName);
    $tnW = $tnBbox[2] - $tnBbox[0];
    imagettftext($img, 8, 0, $cx - (int)($tnW / 2), $cy + 44, $subGrey, $fontNorm, $tierName);

    // ── Title at top ──
    $titleColor = imagecolorallocate($img, 30, 30, 50);
    $titleStr = 'CSF Maturity Score';
    $titleBbox = imagettfbbox(24, 0, $fontBold, $titleStr);
    $titleW = $titleBbox[2] - $titleBbox[0];
    imagettftext($img, 24, 0, $cx - (int)($titleW / 2), 42, $titleColor, $fontBold, $titleStr);

    // Subtitle
    $subTitle = 'Overall cyber security maturity: ' . number_format($sc, 1) . ' / 4.0';
    $stBbox = imagettfbbox(12, 0, $fontNorm, $subTitle);
    $stW = $stBbox[2] - $stBbox[0];
    imagettftext($img, 12, 0, $cx - (int)($stW / 2), 68, $subGrey, $fontNorm, $subTitle);

    // ── Right-side legend panel ──
    $panelX = 1080;
    $panelY = 100;

    // Panel title
    $panelTitle = 'Domain Scores';
    imagettftext($img, 14, 0, $panelX, $panelY, $titleColor, $fontBold, $panelTitle);

    // Thin separator line
    $sepColor = imagecolorallocate($img, 220, 225, 235);
    imageline($img, $panelX, $panelY + 8, $panelX + 450, $panelY + 8, $sepColor);

    $ly = $panelY + 32;
    foreach ($sortedChart as $d) {
        $dCode = $d['code'];
        $score = (float)$d['score'];
        $colorHex = $domainColors[$dCode] ?? '#6b7280';
        $rgb = hexToRgbInt($colorHex);
        $lColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);

        // Color dot (rounded rectangle simulated with filled circle)
        imagefilledellipse($img, $panelX + 6, $ly - 4, 10, 10, $lColor);

        // Domain code (bold)
        imagettftext($img, 10, 0, $panelX + 16, $ly, $lColor, $fontBold, $dCode);

        // Domain name
        imagettftext($img, 9, 0, $panelX + 58, $ly, $nameGrey, $fontNorm, $d['name']);

        // Score value (right-aligned)
        $scoreStr2 = number_format($score, 1);
        $s2Bbox = imagettfbbox(10, 0, $fontBold, $scoreStr2);
        $s2w = $s2Bbox[2] - $s2Bbox[0];
        imagettftext($img, 10, 0, $panelX + 430 - $s2w, $ly, $lColor, $fontBold, $scoreStr2);

        // Mini bar
        $barX = $panelX + 310;
        $barW = 100;
        $barH = 6;
        $barBg = imagecolorallocate($img, 235, 238, 245);
        imagefilledrectangle($img, $barX, $ly - 8, $barX + $barW, $ly - 8 + $barH, $barBg);
        $fillW = max(1, (int)(($score / 4) * $barW));
        imagefilledrectangle($img, $barX, $ly - 8, $barX + $fillW, $ly - 8 + $barH, $lColor);

        $ly += 24;
    }

    // Tier scale legend at bottom of panel
    $ly += 12;
    imagettftext($img, 9, 0, $panelX, $ly, $subGrey, $fontBold, 'Maturity Tiers');
    $ly += 18;
    $tierColors = [1 => '#dc3545', 2 => '#f59e0b', 3 => '#3b82f6', 4 => '#059669'];
    $tierNames = [1 => 'Initial', 2 => 'Risk-Informed', 3 => 'Repeatable', 4 => 'Adaptive'];
    foreach ($tierColors as $tn => $tc) {
        $tRgb = hexToRgbInt($tc);
        $tGdColor = imagecolorallocate($img, $tRgb[0], $tRgb[1], $tRgb[2]);
        imagefilledrectangle($img, $panelX, $ly - 8, $panelX + 18, $ly - 1, $tGdColor);
        imagettftext($img, 9, 0, $panelX + 24, $ly, $subGrey, $fontNorm, $tn . ' — ' . $tierNames[$tn]);
        $ly += 18;
    }

    // Convert to base64 PNG
    ob_start();
    imagepng($img, null, 6);
    $pngData = ob_get_clean();
    @imagedestroy($img);
    $chartBase64 = base64_encode($pngData);
}

// Page 3 HTML — full width chart image
$pdfHtml .= '<div style="text-align:center;">';
if ($chartBase64 !== '') {
    $pdfHtml .= '<img src="data:image/png;base64,' . $chartBase64 . '" style="width:100%;max-height:480pt;" />';
} else {
    $pdfHtml .= '<h2>CSF Maturity Score</h2>';
    $pdfHtml .= '<p style="color:#6b7280;">No domain score data available to generate chart.</p>';
}
$pdfHtml .= '</div>';

// ─── PAGE 4: DETAILED DOMAIN SCORES TABLE ────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';

// 4 stat boxes at top of page
$pdfHtml .= '<div style="margin-bottom:12pt;">';
$pdfHtml .= '<div class="stat-box"><div class="stat-label">CSF Maturity</div><div class="stat-value" style="color:' . scoreColor($sc) . ';">' . $h($overallScore) . '</div><div class="stat-label">' . scoreTier($sc) . '</div></div>';
$pdfHtml .= '<div class="stat-box"><div class="stat-label">Compliance</div><div class="stat-value" style="color:#3b82f6;">' . $compliancePct . '%</div><div class="stat-label">Overall Rate</div></div>';
$pdfHtml .= '<div class="stat-box"><div class="stat-label">Questions</div><div class="stat-value" style="color:#374151;">' . $answered . '/' . $totalQuestions . '</div><div class="stat-label">Answered</div></div>';
$pdfHtml .= '<div class="stat-box"><div class="stat-label">Gaps Found</div><div class="stat-value" style="color:' . ($gapCount > 0 ? '#dc3545' : '#059669') . ';">' . $gapCount . '</div><div class="stat-label">Non-Conformities</div></div>';
$pdfHtml .= '</div>';

$pdfHtml .= '<h2>Security Domain Scores</h2>';
$pdfHtml .= '<p style="font-size:8pt;color:#374151;">The following table shows the maturity score for each of the 14 unified security domains, scored on a 1&ndash;4 scale aligned with NIST CSF 2.0 Implementation Tiers.</p>';

$pdfHtml .= '<table><thead><tr><th style="width:50pt;">Code</th><th>Domain Name</th><th style="width:45pt;">Score</th><th style="width:100pt;">Tier</th><th style="width:130pt;">Bar</th></tr></thead><tbody>';

if (!empty($domainData)) {
    $sortedDomains2 = $domainData;
    usort($sortedDomains2, function($a, $b) { return strcmp($a['code'], $b['code']); });
    foreach ($sortedDomains2 as $d) {
        $ds = $d['score'];
        $dCode = $d['code'];
        $rowColor = $domainColors[$dCode] ?? scoreColor($ds);
        $barWidth = max(1, ($ds / 4) * 100);
        $pdfHtml .= '<tr>';
        $pdfHtml .= '<td><span style="display:inline-block;width:6pt;height:6pt;background:' . $rowColor . ';margin-right:3pt;"></span><strong>' . $h($dCode) . '</strong></td>';
        $pdfHtml .= '<td>' . $h($d['name']) . '</td>';
        $pdfHtml .= '<td><span class="score-badge" style="background:' . $rowColor . ';">' . number_format($ds, 1) . '</span></td>';
        $pdfHtml .= '<td style="font-size:7pt;">' . scoreTier($ds) . '</td>';
        $pdfHtml .= '<td><div class="bar-outer"><div class="bar-inner" style="width:' . $barWidth . '%;background:' . $rowColor . ';"></div></div></td>';
        $pdfHtml .= '</tr>';
    }
} else {
    $pdfHtml .= '<tr><td colspan="5" style="text-align:center;color:#6b7280;">No domain scores calculated yet.</td></tr>';
}

$pdfHtml .= '</tbody></table>';

// ─── PAGE 5: FRAMEWORK COMPLIANCE ───────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Framework Compliance</h2>';
$pdfHtml .= '<p style="font-size:8pt;color:#374151;">The unified assessment maps each security question to specific framework requirements. The compliance percentage below reflects the proportion of mapped requirements that have been satisfied (conforming, with partial conformity weighted at 50%).</p>';

if (!empty($frameworks)) {
    $pdfHtml .= '<table><thead><tr><th>Framework</th><th style="width:90pt;">Total Requirements</th><th style="width:80pt;">Conforming</th><th style="width:80pt;">Compliance %</th><th style="width:130pt;">Bar</th></tr></thead><tbody>';
    foreach ($frameworks as $fw) {
        $fwPct = (int)($fw['compliance_pct'] ?? 0);
        $barColor = $fwPct >= 80 ? '#059669' : ($fwPct >= 50 ? '#f59e0b' : '#dc3545');
        $fwCode = $h($fw['framework_code'] ?? $fw['code'] ?? '');
        $fwName = $h($fw['framework_name'] ?? $fw['name'] ?? '');
        $totalMapped = (int)($fw['total_mapped'] ?? $fw['total_requirements'] ?? $fw['total'] ?? 0);
        $conformingCount = (int)($fw['conforming'] ?? 0);
        $pdfHtml .= '<tr>';
        $pdfHtml .= '<td><strong>' . $fwCode . '</strong> ' . $fwName . '</td>';
        $pdfHtml .= '<td style="text-align:center;">' . $totalMapped . '</td>';
        $pdfHtml .= '<td style="text-align:center;">' . $conformingCount . '</td>';
        $pdfHtml .= '<td style="text-align:center;"><strong>' . $fwPct . '%</strong></td>';
        $pdfHtml .= '<td><div class="bar-outer"><div class="bar-inner" style="width:' . max(1, $fwPct) . '%;background:' . $barColor . ';"></div></div></td>';
        $pdfHtml .= '</tr>';
    }
    $pdfHtml .= '</tbody></table>';
} else {
    $pdfHtml .= '<p style="color:#6b7280;">No framework compliance data available.</p>';
}

// ─── PAGE 6-7: CATEGORY DETAIL ──────────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Category Detail &mdash; Question Scores</h2>';
$pdfHtml .= '<p style="font-size:8pt;color:#374151;">Compact view of all assessed questions grouped by security domain, showing individual question references and maturity scores.</p>';

if (!empty($domainData)) {
    // Split domains into two halves
    $sortedDD = $domainData;
    usort($sortedDD, function($a, $b) { return strcmp($a['code'], $b['code']); });
    $firstHalf = array_slice($sortedDD, 0, 7);
    $secondHalf = array_slice($sortedDD, 7);

    // Render domain cards in a 3-column table layout
    $renderDomainCards = function(array $domainsSubset) use ($responsesByDomain, $h) {
        $html = '<table style="width:100%;border:none;"><tbody><tr>';
        $col = 0;
        foreach ($domainsSubset as $idx => $d) {
            if ($col > 0 && $col % 3 === 0) {
                $html .= '</tr><tr>';
            }
            $dCode = $d['code'];
            $dScore = $d['score'];
            $bgColor = scoreColor($dScore);

            $html .= '<td style="width:33%;vertical-align:top;border:none;padding:3pt;">';
            // Domain card
            $html .= '<table style="width:100%;margin:0;"><tbody>';
            // Header
            $html .= '<tr><td colspan="2" class="domain-header" style="background:' . $bgColor . ';">' . $h($dCode) . ' &mdash; ' . $h($d['name']) . ' <span style="float:right;">' . number_format($dScore, 1) . '</span></td></tr>';

            // Questions
            $domainResponses = $responsesByDomain[$dCode] ?? [];
            if (!empty($domainResponses)) {
                foreach ($domainResponses as $r) {
                    $mr = (int)($r['maturity_rating'] ?? 0);
                    $qRef = $h($r['question_ref'] ?? '');
                    $badgeColor = $mr > 0 ? scoreColor((float)$mr) : '#9ca3af';
                    $html .= '<tr><td style="font-size:7pt;padding:2pt 4pt;border-bottom:0.5pt solid #f3f4f6;">' . $qRef . '</td>';
                    $html .= '<td style="width:30pt;text-align:right;padding:2pt 4pt;border-bottom:0.5pt solid #f3f4f6;"><span class="score-badge" style="background:' . $badgeColor . ';font-size:7pt;padding:1pt 5pt;">' . ($mr > 0 ? $mr : '&mdash;') . '</span></td></tr>';
                }
            } else {
                $html .= '<tr><td colspan="2" style="font-size:7pt;color:#9ca3af;padding:3pt 4pt;">No responses</td></tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</td>';
            $col++;
        }
        // Close remaining cells
        while ($col % 3 !== 0) {
            $html .= '<td style="border:none;"></td>';
            $col++;
        }
        $html .= '</tr></tbody></table>';
        return $html;
    };

    $pdfHtml .= $renderDomainCards($firstHalf);

    // Page 7 - second half
    if (!empty($secondHalf)) {
        $pdfHtml .= '<div class="page-break"></div>';
        $pdfHtml .= '<h2>Category Detail &mdash; Question Scores (cont.)</h2>';
        $pdfHtml .= $renderDomainCards($secondHalf);
    }
} else {
    $pdfHtml .= '<p style="color:#6b7280;">No domain data available for category detail.</p>';
}

// ─── PAGE 8: CONTROL PERFORMANCE OVERVIEW ───────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Control Performance Overview</h2>';
$pdfHtml .= '<p style="font-size:8pt;color:#374151;">Questions grouped by their assigned maturity tier rating, providing a quick view of control maturity distribution.</p>';

$tierLabels = [
    1 => ['name' => 'Initial (Tier 1)', 'color' => '#dc3545', 'bg' => '#fee2e2'],
    2 => ['name' => 'Risk-Informed (Tier 2)', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
    3 => ['name' => 'Repeatable (Tier 3)', 'color' => '#3b82f6', 'bg' => '#dbeafe'],
    4 => ['name' => 'Adaptive (Tier 4)', 'color' => '#059669', 'bg' => '#dcfce7'],
];

$pdfHtml .= '<table style="width:100%;"><tbody><tr>';
foreach ($tierLabels as $tier => $meta) {
    $tierCount = count($byTier[$tier]);
    $pdfHtml .= '<td style="width:25%;vertical-align:top;border:none;padding:0 3pt;">';
    $pdfHtml .= '<table style="width:100%;margin:0;"><tbody>';
    $pdfHtml .= '<tr><th style="background:' . $meta['color'] . ';font-size:8pt;text-align:center;">' . $meta['name'] . ' &mdash; ' . $tierCount . ' questions</th></tr>';
    $pdfHtml .= '<tr><td style="background:' . $meta['bg'] . ';font-size:7pt;padding:6pt;">';
    if ($tierCount > 0) {
        $refs = [];
        foreach ($byTier[$tier] as $r) {
            $refs[] = $h($r['question_ref'] ?? '');
        }
        $pdfHtml .= implode(', ', $refs);
    } else {
        $pdfHtml .= '<span style="color:#9ca3af;">None</span>';
    }
    $pdfHtml .= '</td></tr>';
    $pdfHtml .= '</tbody></table>';
    $pdfHtml .= '</td>';
}
$pdfHtml .= '</tr></tbody></table>';

// ─── PAGE 9: GAP ANALYSIS ───────────────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Gap Analysis</h2>';

if (!empty($gaps)) {
    $pdfHtml .= '<p style="font-size:8pt;color:#374151;">' . $gapCount . ' gap(s) identified &mdash; questions where the conformity status is Non-Conforming or Partial.</p>';
    $pdfHtml .= '<table><thead><tr><th style="width:50pt;">Ref</th><th>Question</th><th style="width:65pt;">Conformity</th><th style="width:40pt;">Score</th><th style="width:140pt;">Affected Frameworks</th></tr></thead><tbody>';

    foreach ($gaps as $gap) {
        $gapStatus = $gap['conformity_status'] ?? 'partial';
        $statusLabel = ucfirst(str_replace('_', ' ', $gapStatus));
        $statusColor = $gapStatus === 'non_conforming' ? '#dc3545' : '#f59e0b';

        // Build affected frameworks string
        $affectedFw = '';
        if (!empty($gap['framework_impact']) && is_array($gap['framework_impact'])) {
            $fwCodes = [];
            foreach ($gap['framework_impact'] as $fwMap) {
                $fwCodes[] = $h($fwMap['framework_code'] ?? '');
            }
            $affectedFw = implode(', ', array_unique($fwCodes));
        } elseif (!empty($gap['affected_frameworks'])) {
            $affectedFw = $h(is_array($gap['affected_frameworks']) ? implode(', ', $gap['affected_frameworks']) : $gap['affected_frameworks']);
        } elseif (!empty($gap['frameworks'])) {
            $affectedFw = $h(is_array($gap['frameworks']) ? implode(', ', $gap['frameworks']) : $gap['frameworks']);
        }

        $pdfHtml .= '<tr class="gap-row">';
        $pdfHtml .= '<td><strong>' . $h($gap['question_ref'] ?? '') . '</strong></td>';
        $pdfHtml .= '<td style="font-size:8pt;">' . $h($gap['question_text'] ?? '') . '</td>';
        $pdfHtml .= '<td><span class="score-badge" style="background:' . $statusColor . ';">' . $h($statusLabel) . '</span></td>';
        $pdfHtml .= '<td style="text-align:center;">' . ($gap['maturity_rating'] ?? '&mdash;') . '</td>';
        $pdfHtml .= '<td style="font-size:7pt;">' . $affectedFw . '</td>';
        $pdfHtml .= '</tr>';
    }
    $pdfHtml .= '</tbody></table>';
} else {
    $pdfHtml .= '<p style="color:#059669;font-size:10pt;text-align:center;margin-top:40pt;"><strong>No gaps identified.</strong> All assessed questions are conforming or not applicable.</p>';
}

// ─── PAGE 10: RECOMMENDATIONS ───────────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<h2>Improvement Opportunities &amp; Recommendations</h2>';

if ($recommendations !== '') {
    foreach (explode("\n\n", $recommendations) as $para) {
        $para = trim($para);
        if ($para !== '') $pdfHtml .= '<p style="font-size:9pt;">' . $h($para) . '</p>';
    }
} elseif (!empty($domainData)) {
    $sortedRec = $domainData;
    usort($sortedRec, function($a, $b) { return $a['score'] <=> $b['score']; });
    $weakDomains = array_filter($sortedRec, function($d) { return $d['score'] < 3.0; });

    if (!empty($weakDomains)) {
        $pdfHtml .= '<p style="font-size:8pt;color:#374151;">Based on the assessment results, the following domains require attention to improve the overall cybersecurity posture. Domains are listed in priority order (lowest scores first).</p>';
        $pdfHtml .= '<table><thead><tr><th style="width:40pt;">Priority</th><th>Domain</th><th style="width:60pt;">Score</th><th>Recommendation</th></tr></thead><tbody>';

        $priority = 1;
        foreach (array_slice(array_values($weakDomains), 0, 14) as $wd) {
            $wds = $wd['score'];
            if ($wds < 1.5) {
                $rec = 'Critical: Establish foundational controls and formalize policies immediately. Implement basic security measures and begin documenting procedures.';
            } elseif ($wds < 2.5) {
                $rec = 'High: Document and standardize existing practices. Implement consistent processes and establish formal security procedures across the organization.';
            } else {
                $rec = 'Medium: Optimize and automate existing controls. Establish metrics, KPIs, and continuous monitoring capabilities for ongoing improvement.';
            }
            $pdfHtml .= '<tr>';
            $pdfHtml .= '<td style="text-align:center;font-weight:bold;">' . $priority++ . '</td>';
            $pdfHtml .= '<td><strong>' . $h($wd['code']) . '</strong> &mdash; ' . $h($wd['name']) . '</td>';
            $pdfHtml .= '<td style="text-align:center;"><span class="score-badge" style="background:' . scoreColor($wds) . ';">' . number_format($wds, 1) . '</span></td>';
            $pdfHtml .= '<td style="font-size:8pt;">' . $rec . '</td>';
            $pdfHtml .= '</tr>';
        }
        $pdfHtml .= '</tbody></table>';
    } else {
        $pdfHtml .= '<p style="color:#059669;font-size:10pt;text-align:center;margin-top:40pt;"><strong>All domains score 3.0 or above.</strong> Continue maintaining and optimizing current cybersecurity practices.</p>';
    }
} else {
    $pdfHtml .= '<p style="color:#6b7280;">Run "Recalculate" in the assessment to generate domain scores before producing recommendations.</p>';
}

// ─── CONCLUSION (optional, before scorecards) ───────────────────────────────

if ($conclusion !== '') {
    $pdfHtml .= '<div class="page-break"></div>';
    $pdfHtml .= '<h2>Conclusion</h2>';
    foreach (explode("\n\n", $conclusion) as $para) {
        $para = trim($para);
        if ($para !== '') $pdfHtml .= '<p style="font-size:9pt;">' . $h($para) . '</p>';
    }
}

// ─── PAGE 11: SCORECARDS DIVIDER ────────────────────────────────────────────

$pdfHtml .= '<div class="page-break"></div>';
$pdfHtml .= '<div style="text-align:center;padding-top:160pt;">';
$pdfHtml .= '<div style="font-size:28pt;font-weight:bold;color:' . $brandDark . ';">Domain Scorecards</div>';
$pdfHtml .= '<div style="font-size:12pt;color:#374151;margin-top:10pt;">Detailed Question Responses by Security Domain</div>';
$pdfHtml .= '<div style="border-top:3pt solid ' . $brandHex . ';width:120pt;margin:24pt auto;"></div>';
$pdfHtml .= '</div>';

// ─── PAGES 12+: PER-DOMAIN SCORECARDS ───────────────────────────────────────

// If domainData is empty but we have responses, build domain list from responses
$scorecardDomains = $domainData;
if (empty($scorecardDomains) && !empty($responsesByDomain)) {
    foreach ($responsesByDomain as $dCode => $dResps) {
        $dSum = 0; $dCnt = 0;
        foreach ($dResps as $dr) {
            if (!empty($dr['maturity_rating'])) { $dSum += (int)$dr['maturity_rating']; $dCnt++; }
        }
        $scorecardDomains[] = [
            'code' => $dCode,
            'name' => $dResps[0]['domain_name'] ?? $dCode,
            'score' => $dCnt > 0 ? round($dSum / $dCnt, 2) : 0,
        ];
    }
}

if (!empty($scorecardDomains)) {
    $sortedForCards = $scorecardDomains;
    usort($sortedForCards, function($a, $b) { return strcmp($a['code'], $b['code']); });

    foreach ($sortedForCards as $d) {
        $dCode = $d['code'];
        $dName = $d['name'];
        $dScore = $d['score'];
        $bgColor = scoreColor($dScore);

        $pdfHtml .= '<div class="page-break"></div>';

        // Domain header bar
        $pdfHtml .= '<table style="width:100%;margin-bottom:10pt;"><tbody>';
        $pdfHtml .= '<tr><td style="background:' . $bgColor . ';color:#fff;font-weight:bold;font-size:11pt;padding:8pt 12pt;border:none;">';
        $pdfHtml .= $h($dCode) . ' &mdash; ' . $h($dName);
        $pdfHtml .= '<span style="float:right;font-size:10pt;">CSF Maturity Score: ' . number_format($dScore, 1) . '</span>';
        $pdfHtml .= '</td></tr></tbody></table>';

        // Questions table
        $domainResponses = $responsesByDomain[$dCode] ?? [];

        if (!empty($domainResponses)) {
            $pdfHtml .= '<table><thead><tr>';
            $pdfHtml .= '<th style="width:55pt;">Ref</th>';
            $pdfHtml .= '<th>Question Text</th>';
            $pdfHtml .= '<th style="width:55pt;">Maturity</th>';
            $pdfHtml .= '<th style="width:70pt;">Conformity</th>';
            $pdfHtml .= '<th style="width:170pt;">Notes</th>';
            $pdfHtml .= '</tr></thead><tbody>';

            foreach ($domainResponses as $r) {
                $mr = (int)($r['maturity_rating'] ?? 0);
                $conf = $r['conformity_status'] ?? 'not_assessed';
                $confLabel = ucfirst(str_replace('_', ' ', $conf));
                $confColor = '#9ca3af';
                if ($conf === 'conforming') $confColor = '#059669';
                elseif ($conf === 'partial') $confColor = '#f59e0b';
                elseif ($conf === 'non_conforming') $confColor = '#dc3545';

                $badgeColor = $mr > 0 ? scoreColor((float)$mr) : '#9ca3af';

                // Truncate notes to 150 chars
                $notes = $r['notes'] ?? '';
                if (strlen($notes) > 150) {
                    $notes = substr($notes, 0, 147) . '...';
                }
                // Strip HTML attributes from any user content in notes
                $notes = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $notes);

                $pdfHtml .= '<tr>';
                $pdfHtml .= '<td><strong>' . $h($r['question_ref'] ?? '') . '</strong></td>';
                $pdfHtml .= '<td style="font-size:8pt;">' . $h($r['question_text'] ?? '') . '</td>';
                $pdfHtml .= '<td style="text-align:center;"><span class="score-badge" style="background:' . $badgeColor . ';">' . ($mr > 0 ? $mr : '&mdash;') . '</span></td>';
                $pdfHtml .= '<td><span class="score-badge" style="background:' . $confColor . ';font-size:7pt;">' . $h($confLabel) . '</span></td>';
                $pdfHtml .= '<td style="font-size:7pt;font-style:italic;color:#4b5563;">' . $h($notes) . '</td>';
                $pdfHtml .= '</tr>';
            }

            $pdfHtml .= '</tbody></table>';
        } else {
            $pdfHtml .= '<p style="color:#6b7280;text-align:center;">No responses recorded for this domain.</p>';
        }
    }
}

// ─── HEADER/FOOTER SCRIPT ───────────────────────────────────────────────────

// SECURITY: $orgName comes from the user-supplied company_name. The header/footer
// runs inside a Dompdf <script type="text/php"> block with isPhpEnabled=true, where
// addslashes() is NOT sufficient — PHP evaluates ${...}/{$...} interpolation inside
// double-quoted strings, allowing arbitrary code execution (SQLi/SSRF/RCE). Encode
// user-controlled values as Base64 and decode them at render time so the bytes are
// passed to page_text() as inert data and never re-parsed as PHP. The Base64 alphabet
// ([A-Za-z0-9+/=]) contains none of " \ $ { }, so it cannot break out of the literal.
$orgNameHeaderB64  = base64_encode($orgName . " \xc2\xb7 Cyber Report");
$reportDateB64     = base64_encode($reportDate);

// Brand colors for the page script (RGB 0-1 floats)
$brandR = $brandRgb[0]; $brandG = $brandRgb[1]; $brandB = $brandRgb[2];
$brandDkR = $brandDarkRgb[0]; $brandDkG = $brandDarkRgb[1]; $brandDkB = $brandDarkRgb[2];

$pdfHtml .= '<script type="text/php">
if (isset($pdf)) {
    $fontNormal = $fontMetrics->getFont("Helvetica", "normal");
    $fontBold = $fontMetrics->getFont("Helvetica", "bold");
    $white = array(1, 1, 1);
    $grey = array(0.6, 0.65, 0.7);
    $lightGrey = array(0.9, 0.91, 0.92);
    $brand = array(' . $brandR . ', ' . $brandG . ', ' . $brandB . ');
    $brandDk = array(' . $brandDkR . ', ' . $brandDkG . ', ' . $brandDkB . ');

    if ($PAGE_NUM == 1) {
        // Cover decorations: left brand stripe + bottom brand bar
        $pdf->filled_rectangle(0, 0, 6, 612, $brandDk);
        $pdf->filled_rectangle(0, 572, 792, 40, $brandDk);
        $pdf->page_text(36, 582, "Prepared by FairTPRM Cyber Report Engine", $fontNormal, 7, $white);
        $confText = "Proprietary & Confidential";
        $confW = $fontMetrics->getTextWidth($confText, $fontNormal, 7);
        $pdf->page_text(756 - $confW, 582, $confText, $fontNormal, 7, $white);
    } else {
        // Header bar (brand color, 28pt tall, full width)
        $pdf->filled_rectangle(0, 0, 792, 28, $brand);
        $pdf->page_text(36, 9, base64_decode("' . $orgNameHeaderB64 . '"), $fontBold, 7, $white);
        $fairText = "FairTPRM";
        $fairW = $fontMetrics->getTextWidth($fairText, $fontBold, 8);
        $pdf->page_text(756 - $fairW, 8, $fairText, $fontBold, 8, $white);

        // Footer
        $pdf->line(36, 578, 756, 578, $lightGrey, 0.5);
        $pdf->page_text(36, 586, "Proprietary & Confidential", $fontNormal, 6.5, $grey);
        $pageText = "Page {PAGE_NUM} of {PAGE_COUNT}";
        $pdf->page_text(370, 586, $pageText, $fontNormal, 6.5, $grey);
        $genText = base64_decode("' . $reportDateB64 . '");
        $genW = $fontMetrics->getTextWidth($genText, $fontNormal, 6.5);
        $pdf->page_text(756 - $genW, 586, $genText, $fontNormal, 6.5, $grey);
    }
}
</script>';

$pdfHtml .= '</body></html>';

// ─── Generate PDF ───────────────────────────────────────────────────────────
try {
    require_once '/var/www/html/vendor/autoload.php';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $options->set('isPhpEnabled', true);
    $options->set('isJavascriptEnabled', false);
    $options->set('chroot', '/var/www/html');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('letter', 'landscape');
    $dompdf->render();

    $pdfData = $dompdf->output();

    if (empty($pdfData)) {
        echo json_encode(['success' => false, 'error' => 'PDF generation produced empty output.', 'csrf_token' => $newToken]);
        exit;
    }

    // Encrypt the PDF
    $encryption = new Encryption();
    $encryptedData = $encryption->encryptRaw($pdfData);

    if (empty($encryptedData)) {
        echo json_encode(['success' => false, 'error' => 'PDF encryption failed.', 'csrf_token' => $newToken]);
        exit;
    }

    // Store in grc_assessments — need columns for report storage
    // Check if columns exist, add them if not
    try {
        $db->query("ALTER TABLE grc_assessments ADD COLUMN report_encrypted_data LONGBLOB DEFAULT NULL");
    } catch (Exception $e) { /* column may already exist */ }
    try {
        $db->query("ALTER TABLE grc_assessments ADD COLUMN report_file_name VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) { /* column may already exist */ }
    try {
        $db->query("ALTER TABLE grc_assessments ADD COLUMN report_file_mime VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) { /* column may already exist */ }

    $db->query(
        'UPDATE grc_assessments SET report_encrypted_data = :data, report_file_name = :fname, report_file_mime = :mime WHERE id = :id',
        [':data' => $encryptedData, ':fname' => $fileName, ':mime' => 'application/pdf', ':id' => $assessmentId]
    );

    echo json_encode([
        'success'   => true,
        'message'   => 'Report generated: ' . $fileName,
        'file_name' => $fileName,
        'download_url' => 'api/grc-assessment-report.php?action=download&id=' . $assessmentId,
        'csrf_token' => $newToken,
    ]);

} catch (Exception $ex) {
    error_log('Assessment report PDF error: ' . $ex->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'PDF generation failed. Please try again.',
        'csrf_token' => $newToken,
    ]);
}
