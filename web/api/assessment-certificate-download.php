<?php
/**
 * Public Certificate Download API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Allows vendors to download their own uploaded certificate using the
 * assessment UUID token. No authentication required -- the UUID serves
 * as proof of access (same as the vendor assessment form itself).
 * Only serves the certificate file, not arbitrary assessment files.
 */

require_once '../includes/init.php';

// ---------------------------------------------------------------
// SAFE MIME TYPES
// Only serve files inline if they're known-safe types.
// ---------------------------------------------------------------
$safeMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

// ---------------------------------------------------------------
// VALIDATE UUID TOKEN
// The UUID is the vendor's proof of access -- same token they use
// to fill out the form.
// ---------------------------------------------------------------
$uuid = $_GET['token'] ?? '';

if (empty($uuid)) {
    http_response_code(400);
    die('Missing token');
}

require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment) {
    http_response_code(404);
    die('Assessment not found');
}

// ---------------------------------------------------------------
// COMPLETED / EXPIRED ASSESSMENTS ARE NOT PUBLIC
// The UUID grants public download only while the assessment is still
// open. Once it is completed (submitted) or expired, only authenticated
// administrators or cyber_tprm members may pull the certificate back;
// everyone else gets a 404 that is indistinguishable from a bad token,
// mirroring vendor-assessment.php. hasGroup()/isAdmin()/is_super_admin are
// all safe on this no-auth endpoint -- they read the session and return
// false for anonymous callers rather than redirecting.
// ---------------------------------------------------------------
$session = Session::getInstance();
$isPrivilegedViewer = $session->get('is_super_admin')
    || Auth::getInstance()->isAdmin()
    || hasGroup('administrator')
    || hasGroup('cyber_tprm');

if (($assessment['status'] === 'completed' || $assessmentService->isExpired($assessment)) && !$isPrivilegedViewer) {
    http_response_code(404);
    die('Assessment not found');
}

// Must have a certificate uploaded
if (!$assessment['certificate_uploaded'] || empty($assessment['certificate_path'])) {
    http_response_code(404);
    die('No certificate found for this assessment');
}

$filePath = $assessment['certificate_path'];
$db = Database::getInstance();

// ---------------------------------------------------------------
// DATABASE BLOB STORAGE PATH (files stored as "db:{uuid}")
// ---------------------------------------------------------------
if (strpos($filePath, 'db:') === 0) {
    $fileUuid = substr($filePath, 3);

    $fileRecord = $db->fetchOne(
        "SELECT * FROM assessment_files WHERE file_uuid = ? AND assessment_id = ?",
        [$fileUuid, (int)$assessment['id']]
    );

    if (!$fileRecord) {
        http_response_code(404);
        die('Certificate file not found');
    }

    try {
        $encryption = new Encryption();
        $decryptedContent = $encryption->decryptRaw($fileRecord['encrypted_data']);

        $mimeType = $fileRecord['mime_type'];
        if (!in_array($mimeType, $safeMimeTypes, true)) {
            $mimeType = 'application/octet-stream';
        }

        $safeFilename = str_replace(["\r", "\n", "\0", '"'], '', $fileRecord['original_filename']);
        $disposition = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true)
            ? 'inline' : 'attachment';
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($decryptedContent));
        header('Cache-Control: private, max-age=0, must-revalidate');


        echo $decryptedContent;
        exit;
    } catch (Exception $e) {
        error_log('Certificate decryption error: ' . $e->getMessage());
        if (strpos($e->getMessage(), 'HMAC verification failed') !== false) {
            http_response_code(410);
            die('This certificate was encrypted with a previous encryption key and can no longer be decrypted. Please re-upload the certificate.');
        }
        http_response_code(500);
        die('Failed to retrieve certificate');
    }
}

// ---------------------------------------------------------------
// FILESYSTEM STORAGE PATH
// ---------------------------------------------------------------

// Block protocol schemes to prevent SSRF
if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $filePath)) {
    http_response_code(403);
    die('Invalid file path');
}

// Block null bytes
if (strpos($filePath, "\0") !== false) {
    http_response_code(403);
    die('Invalid file path');
}

$assessmentId = (int)$assessment['id'];
if (strpos($filePath, 'uploads/assessments/' . $assessmentId . '/') !== 0) {
    http_response_code(403);
    die('Invalid file path');
}

$fullPath = __DIR__ . '/../' . $filePath;
$realPath = realpath($fullPath);
$allowedDir = realpath(__DIR__ . '/../uploads/assessments/' . $assessmentId);

if ($realPath === false || $allowedDir === false || strpos($realPath, $allowedDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    die('Invalid file path');
}

if (!is_file($realPath)) {
    http_response_code(403);
    die('Invalid file path');
}

// Legacy unencrypted files
if (substr($filePath, -4) !== '.enc') {
    $finfo = new \finfo(FILEINFO_MIME_TYPE); $mimeType = $finfo->file($realPath);
    if (!in_array($mimeType, $safeMimeTypes, true)) {
        $mimeType = 'application/octet-stream';
    }
    $safeBasename = str_replace(["\r", "\n", "\0", '"'], '', basename($filePath));
    $disposition = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true)
        ? 'inline' : 'attachment';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeBasename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($realPath);
    exit;
}

// Encrypted file
try {
    $encryption = new Encryption();
    $decryptedContent = $encryption->decryptFile($realPath);

    $originalName = basename($filePath, '.enc');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

    $safeOriginalName = str_replace(["\r", "\n", "\0", '"'], '', $originalName);
    $disposition = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)
        ? 'inline' : 'attachment';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeOriginalName . '"');
    header('Content-Length: ' . strlen($decryptedContent));
    header('Cache-Control: private, max-age=0, must-revalidate');


    echo $decryptedContent;
} catch (Exception $e) {
    error_log('Certificate decryption error: ' . $e->getMessage());
    if (strpos($e->getMessage(), 'HMAC verification failed') !== false) {
        http_response_code(410);
        die('This certificate was encrypted with a previous encryption key and can no longer be decrypted. Please re-upload the certificate.');
    }
    http_response_code(500);
    die('Failed to retrieve certificate');
}
