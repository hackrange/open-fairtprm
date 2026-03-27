<?php
/**
 * API: Download Encrypted Audit Report as PDF
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Fetches the encrypted report from grc_audits, decrypts it, and serves
 * the PDF with proper Content-Type and Content-Disposition headers.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$session = Session::getInstance();

// Access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die('Access denied.');
}

$auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : 0;
if ($auditId <= 0) {
    http_response_code(400);
    die('Audit ID is required.');
}

$db = Database::getInstance();

// Fetch encrypted report data
$audit = $db->fetchOne(
    'SELECT report_encrypted_data, report_file_name, report_file_mime FROM grc_audits WHERE id = :id',
    [':id' => $auditId]
);

if (!$audit || empty($audit['report_encrypted_data'])) {
    http_response_code(404);
    die('No report found for this audit.');
}

// Decrypt
try {
    $encryption = new Encryption();
    $pdfData = $encryption->decryptRaw($audit['report_encrypted_data']);
} catch (Exception $ex) {
    error_log('GRC Report decryption error for audit ' . $auditId . ': ' . $ex->getMessage());
    http_response_code(500);
    die('Failed to decrypt report.');
}

if (empty($pdfData)) {
    http_response_code(500);
    die('Decrypted report is empty.');
}

$fileName = $audit['report_file_name'] ?: ('Audit_Report_' . $auditId . '.pdf');
$mimeType = $audit['report_file_mime'] ?: 'application/pdf';

// Sanitize filename for Content-Disposition
$safeFileName = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $fileName);

// Serve the PDF
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
header('Content-Length: ' . strlen($pdfData));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

echo $pdfData;
exit;
