<?php
/**
 * API: Save or Delete Audit Report as Encrypted PDF
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Accepts POST JSON with edited report sections, generates a professional PDF
 * via dompdf, encrypts with AES-256-CBC, and stores in grc_audits. Also handles
 * report deletion.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
$user = $auth->getUser();
$security = Security::getInstance();
$db = Database::getInstance();
$session = Session::getInstance();

// Access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();

// SECURITY: auditors are read-only across the GRC module (matches every sibling
// GRC write endpoint, e.g. grc-audit-finding.php). Block them from saving/forging
// or deleting audit reports; only administrators and cyber_grc may write.
$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;
if ($readOnly) {
    echo json_encode(['success' => false, 'error' => 'Read-only access.', 'csrf_token' => $newCsrfToken]);
    exit;
}

$auditId = (int)($input['audit_id'] ?? 0);

if ($auditId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Audit ID is required.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load audit
$audit = $db->fetchOne(
    'SELECT a.*, f.code as framework_code, f.name as framework_name, f.version as framework_version,
            u.full_name as lead_auditor_name, gs.name as scope_name
     FROM grc_audits a
     LEFT JOIN grc_frameworks f ON f.id = a.framework_id
     LEFT JOIN users u ON u.id = a.lead_auditor_user_id
     LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
     WHERE a.id = :id',
    [':id' => $auditId]
);

if (!$audit) {
    echo json_encode(['success' => false, 'error' => 'Audit not found.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Handle delete action
if (($input['action'] ?? '') === 'delete') {
    $db->update('grc_audits', [
        'report_encrypted_data' => null,
        'report_file_name' => null,
        'report_file_mime' => null,
    ], 'id = :id', [':id' => $auditId]);

    echo json_encode(['success' => true, 'message' => 'Report deleted.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Validate sections for PDF generation
$sections = $input['sections'] ?? [];
if (empty($sections) || !is_array($sections)) {
    echo json_encode(['success' => false, 'error' => 'No report sections provided.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Sanitize section content - allow safe HTML tags only (no div/span to prevent styled containers)
$allowedTags = '<p><br><strong><em><b><i><u><ul><ol><li><table><thead><tbody><tr><th><td><h1><h2><h3><h4><h5><h6><a><blockquote><hr><sub><sup>';
$sanitizedSections = [];
foreach ($sections as $section) {
    if (!is_array($section) || empty($section['key'])) continue;
    $sanitizedSections[] = [
        'key' => preg_replace('/[^a-zA-Z0-9_]/', '', $section['key']),
        'title' => htmlspecialchars(strip_tags($section['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'content' => strip_tags($section['content'] ?? '', $allowedTags),
    ];
}

if (empty($sanitizedSections)) {
    echo json_encode(['success' => false, 'error' => 'No valid sections to render.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Get company name
$orgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
$orgName = ($orgRow && !empty($orgRow['config_value'])) ? htmlspecialchars($orgRow['config_value'], ENT_QUOTES, 'UTF-8') : 'Organization';

$frameworkName = htmlspecialchars($audit['framework_name'] ?? $audit['framework_code'] ?? 'Compliance', ENT_QUOTES, 'UTF-8');
$frameworkCode = htmlspecialchars($audit['framework_code'] ?? '', ENT_QUOTES, 'UTF-8');
$auditRef = htmlspecialchars($audit['audit_ref'] ?? '', ENT_QUOTES, 'UTF-8');
$auditorName = htmlspecialchars($audit['lead_auditor_name'] ?? '', ENT_QUOTES, 'UTF-8');
$scopeName = htmlspecialchars($audit['scope_name'] ?? '', ENT_QUOTES, 'UTF-8');

$auditPeriod = '';
if (!empty($audit['planned_start']) && !empty($audit['planned_end'])) {
    $auditPeriod = date('F j, Y', strtotime($audit['planned_start'])) . ' to ' . date('F j, Y', strtotime($audit['planned_end']));
} else {
    $auditPeriod = date('F j, Y');
}

$reportDate = date('F j, Y');

// Build clean filename: SOC2_Type2_AuditTitle_YYYY-MM-DD.pdf
$codeSlug = preg_replace('/[^A-Za-z0-9_]/', '_', $audit['framework_code'] ?? 'Audit');
$titleSlug = preg_replace('/[^A-Za-z0-9_]/', '_', $audit['title'] ?? 'Report');
$titleSlug = preg_replace('/_+/', '_', trim($titleSlug, '_'));
$fileName = $codeSlug . '_Type2_' . $titleSlug . '_' . date('Y-m-d') . '.pdf';

// Build PDF HTML
$pdfHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';

// Professional PDF stylesheet
$pdfHtml .= '
@page {
    size: letter;
    margin: 72pt 36pt 72pt 36pt;
}
body {
    font-family: "Helvetica", "Arial", sans-serif;
    font-size: 10pt;
    line-height: 1.6;
    color: #1a1a1a;
    margin: 0;
    padding: 0;
}
p, li, td, th {
    max-width: 100%;
}
h1, h2, h3, h4, h5, h6 {
    font-family: "Helvetica", "Arial", sans-serif;
    color: #1a1a2e;
    page-break-after: avoid;
}

/* Cover page */
.cover-page {
    text-align: center;
    padding-top: 160pt;
    page-break-after: always;
}
.cover-page .confidential {
    font-size: 9pt;
    font-weight: bold;
    letter-spacing: 4pt;
    text-transform: uppercase;
    color: #6b7280;
    display: block;
    margin-bottom: 50pt;
}
.cover-page .org-name {
    font-size: 22pt;
    font-weight: bold;
    color: #1a1a2e;
    margin-bottom: 12pt;
}
.cover-page .report-title {
    font-size: 18pt;
    color: #374151;
    margin-bottom: 8pt;
}
.cover-page .report-subtitle {
    font-size: 13pt;
    color: #6b7280;
    margin-bottom: 40pt;
}
.cover-page .cover-detail {
    font-size: 11pt;
    color: #374151;
    margin-bottom: 6pt;
}
.cover-page .cover-line {
    width: 200pt;
    height: 1pt;
    background: #d1d5db;
    margin: 30pt auto;
}

/* Table of contents */
.toc-page {
    page-break-after: always;
}
.toc-page h2 {
    font-size: 16pt;
    border-bottom: 2pt solid #1a1a2e;
    padding-bottom: 6pt;
    margin-top: 0;
    margin-bottom: 20pt;
}
.toc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11pt;
}
.toc-table td {
    padding: 6pt 0;
    border: none;
    border-bottom: 1px dotted #d1d5db;
    color: #1a1a2e;
    vertical-align: top;
    font-family: "Helvetica", "Arial", sans-serif;
    font-size: 11pt;
    background: none;
}
.toc-table td.toc-number {
    width: 30pt;
    font-weight: bold;
    text-align: left;
    padding-right: 4pt;
}

/* Section pages */
.section-page {
    page-break-before: always;
}
.section-page:first-of-type {
    page-break-before: auto;
}
.section-page h2 {
    font-size: 16pt;
    border-bottom: 2pt solid #1a1a2e;
    padding-bottom: 6pt;
    margin-top: 0;
    margin-bottom: 16pt;
    color: #1a1a2e;
}
.section-page h3 {
    font-size: 13pt;
    margin-top: 16pt;
    margin-bottom: 8pt;
}
.section-page h4 {
    font-size: 12pt;
    color: #1e40af;
    margin-top: 14pt;
    margin-bottom: 6pt;
}

/* Content */
p {
    margin: 0 0 8pt 0;
    padding: 0;
    text-align: justify;
    overflow-wrap: break-word;
}
ul, ol {
    padding-left: 20pt;
    margin: 6pt 0 12pt;
}
li {
    margin-bottom: 3pt;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
    margin: 10pt 0 14pt;
    page-break-inside: auto;
}
table th {
    background: #f3f4f6;
    padding: 5pt 6pt;
    text-align: left;
    font-weight: bold;
    border: 1px solid #d1d5db;
    font-family: "Helvetica", "Arial", sans-serif;
    font-size: 8.5pt;
}
table td {
    padding: 4pt 6pt;
    border: 1px solid #e5e7eb;
    vertical-align: top;
    font-family: "Helvetica", "Arial", sans-serif;
    font-size: 8.5pt;
}
table tr {
    page-break-inside: avoid;
}
strong, b { font-weight: bold; }
em, i { font-style: italic; }

/* Force all section content to use full width */
.section-page p, .section-page ul, .section-page ol, .section-page blockquote {
    width: 100%;
    max-width: none;
}
.section-page table {
    width: 100%;
}
.section-page td, .section-page th {
    width: auto;
}

/* Header and footer rendered via dompdf page_text() API — no fixed-position HTML elements */
';

$pdfHtml .= '</style></head><body>';

// Header/footer rendered via page_text() API in the PHP script block below
// No fixed-position HTML elements — this prevents dompdf layout interference

// Cover page
$pdfHtml .= '<div class="cover-page">';
$pdfHtml .= '<div class="confidential">Confidential</div>';
$pdfHtml .= '<div class="org-name">' . $orgName . '</div>';
$pdfHtml .= '<div class="report-title">' . $frameworkName . ' Audit Report</div>';
if (!empty($audit['title'])) {
    $pdfHtml .= '<div class="report-subtitle">' . htmlspecialchars($audit['title'], ENT_QUOTES, 'UTF-8') . '</div>';
}
$pdfHtml .= '<div class="cover-line"></div>';
$pdfHtml .= '<div class="cover-detail"><strong>Audit Reference:</strong> ' . $auditRef . '</div>';
$pdfHtml .= '<div class="cover-detail"><strong>Audit Period:</strong> ' . htmlspecialchars($auditPeriod, ENT_QUOTES, 'UTF-8') . '</div>';
if ($scopeName) {
    $pdfHtml .= '<div class="cover-detail"><strong>System/Scope:</strong> ' . $scopeName . '</div>';
}
if ($auditorName) {
    $pdfHtml .= '<div class="cover-detail"><strong>Lead Auditor:</strong> ' . $auditorName . '</div>';
}
$pdfHtml .= '<div class="cover-detail"><strong>Report Date:</strong> ' . $reportDate . '</div>';
$pdfHtml .= '<div style="margin-top:40pt;font-size:8pt;color:#6b7280;font-style:italic;text-align:center;line-height:1.4;">'
    . 'Self-Assessment &mdash; This report has been prepared internally and has not been examined or certified<br>'
    . 'by a licensed CPA firm or authorized third-party attestation organization.'
    . '</div>';
$pdfHtml .= '</div>';

// Table of contents
$pdfHtml .= '<div class="toc-page">';
$pdfHtml .= '<h2>Table of Contents</h2>';
$pdfHtml .= '<table class="toc-table">';
$sectionNum = 0;
foreach ($sanitizedSections as $s) {
    $sectionNum++;
    $pdfHtml .= '<tr><td class="toc-number">' . $sectionNum . '.</td><td>' . $s['title'] . '</td></tr>';
}
$pdfHtml .= '</table>';
$pdfHtml .= '</div>';

// Section pages
$sectionNum = 0;
foreach ($sanitizedSections as $s) {
    $sectionNum++;
    $pdfHtml .= '<div class="section-page">';
    $pdfHtml .= '<h2>' . $sectionNum . '. ' . $s['title'] . '</h2>';

    // For the disclaimer section, render a clean PDF version without heavy inline styles
    if ($s['key'] === 'disclaimer') {
        $pdfHtml .= '<p style="font-size:10pt;color:#555;font-style:italic;padding:8pt 0;margin:6pt 0 12pt;">'
            . '<strong>Self-Assessment Notice:</strong> This report has been prepared by ' . $orgName . ' as a self-assessment of its internal controls. '
            . 'This report has NOT been examined, reviewed, or certified by a licensed CPA firm, an accredited independent auditor, or any authorized third-party attestation organization. '
            . 'A SOC 2 Type II report, as defined by the AICPA, requires examination by an independent service auditor in accordance with AT-C Section 205. '
            . 'This self-assessment does not constitute a SOC 2 Type II attestation report. '
            . 'Organizations seeking an official SOC 2 Type II report should engage a licensed CPA firm authorized to perform such attestation engagements.</p>';
    } else {
        // Strip ALL HTML attributes from content for clean PDF rendering
        // This removes style, width, height, class, align, cellpadding, etc.
        $cleanContent = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $s['content']);
        $pdfHtml .= $cleanContent;
    }
    $pdfHtml .= '</div>';
}

// Render header, footer, and page numbers via dompdf page_text/page_line API
// This avoids position:fixed HTML elements which can interfere with content width
$headerLeft = $orgName . ' — ' . $frameworkName;
$headerRight = 'CONFIDENTIAL';
$footerLeft = $auditRef;
$footerRight = 'Generated: ' . $reportDate;

// SECURITY: these strings are emitted into a Dompdf <script type="text/php"> block
// with isPhpEnabled=true. Neither htmlspecialchars() nor addslashes() neutralizes PHP
// ${...}/{$...} interpolation inside the double-quoted literal, so any attacker-influenced
// value (org name from app_config, framework/audit names from the DB) could execute PHP at
// render time. Encode as Base64 and decode at render time: the Base64 alphabet
// ([A-Za-z0-9+/=]) contains none of " \ $ { }, so the bytes reach page_text() as inert data.
$headerLeftB64  = base64_encode($headerLeft);
$headerRightB64 = base64_encode($headerRight);
$footerLeftB64  = base64_encode($footerLeft);
$footerRightB64 = base64_encode($footerRight);

$pdfHtml .= '<script type="text/php">
if (isset($pdf)) {
    $fontNormal = $fontMetrics->getFont("Helvetica", "normal");
    $fontBold = $fontMetrics->getFont("Helvetica", "bold");
    $grey = array(0.61, 0.64, 0.69);
    $dark = array(0.10, 0.10, 0.18);
    $lightGrey = array(0.90, 0.91, 0.92);

    // Page dimensions: letter = 612 x 792 pt
    // @page margins: top 72, right 36, bottom 72, left 36
    $leftX = 36;
    $rightX = 576; // 612 - 36
    $contentWidth = 540; // 612 - 36 - 36

    // --- HEADER: 50pt from top ---
    $headerY = 50;
    $pdf->page_text($leftX, $headerY, base64_decode("' . $headerLeftB64 . '"), $fontNormal, 7, $grey);
    // Right-align "CONFIDENTIAL"
    $confWidth = $fontMetrics->getTextWidth(base64_decode("' . $headerRightB64 . '"), $fontBold, 6.5);
    $pdf->page_text($rightX - $confWidth, $headerY, base64_decode("' . $headerRightB64 . '"), $fontBold, 6.5, $dark);

    // --- FOOTER LINE: 738pt from top ---
    $footerLineY = 738;
    $pdf->line($leftX, $footerLineY, $rightX, $footerLineY, $lightGrey, 0.5);

    // --- FOOTER TEXT: 746pt from top ---
    $footerY = 746;
    $pdf->page_text($leftX, $footerY, base64_decode("' . $footerLeftB64 . '"), $fontNormal, 7, $grey);
    $genWidth = $fontMetrics->getTextWidth(base64_decode("' . $footerRightB64 . '"), $fontNormal, 7);
    $pdf->page_text($rightX - $genWidth, $footerY, base64_decode("' . $footerRightB64 . '"), $fontNormal, 7, $grey);

    // --- PAGE NUMBER: centered at 758pt ---
    $pageText = "Page {PAGE_NUM} of {PAGE_COUNT}";
    $pdf->page_text(($leftX + $rightX) / 2 - 30, 758, $pageText, $fontNormal, 7, $grey);
}
</script>';

$pdfHtml .= '</body></html>';

// Generate PDF using dompdf
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
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    $pdfData = $dompdf->output();

    if (empty($pdfData)) {
        echo json_encode(['success' => false, 'error' => 'PDF generation produced empty output.', 'csrf_token' => $newCsrfToken]);
        exit;
    }

    // Encrypt the PDF
    $encryption = new Encryption();
    $encryptedData = $encryption->encryptRaw($pdfData);

    if (empty($encryptedData)) {
        echo json_encode(['success' => false, 'error' => 'PDF encryption failed.', 'csrf_token' => $newCsrfToken]);
        exit;
    }

    // Store in database
    $db->update('grc_audits', [
        'report_encrypted_data' => $encryptedData,
        'report_file_name' => $fileName,
        'report_file_mime' => 'application/pdf',
    ], 'id = :id', [':id' => $auditId]);

    echo json_encode([
        'success' => true,
        'message' => 'Report saved as ' . $fileName,
        'file_name' => $fileName,
        'csrf_token' => $newCsrfToken,
    ]);

} catch (Exception $ex) {
    error_log('GRC Report PDF generation error: ' . $ex->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'PDF generation failed. Please try again.',
        'csrf_token' => $newCsrfToken,
    ]);
}
