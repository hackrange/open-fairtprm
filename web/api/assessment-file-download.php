<?php
/**
 * Assessment File Download API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The reverse of the upload process -- this endpoint decrypts and serves encrypted
 * assessment files back to authorized users. It handles two storage backends:
 * database BLOB storage (files prefixed with "db:") and filesystem storage (the
 * traditional uploads folder). There's also a legacy path for unencrypted files
 * from before we added encryption, because backward compatibility is the gift that
 * keeps on giving. Only admins and cyber_tprm members can download these files,
 * because vendor security documents are not exactly water cooler reading material.
 * Files are served inline with proper MIME types so PDFs open in the browser and
 * images display correctly. Cache headers are set to "don't even think about caching
 * this" because these are sensitive documents.
 */

// No JSON header here -- we're serving actual file content, not JSON
require_once '../includes/init.php';

// ---------------------------------------------------------------
// SAFE MIME TYPES
// Only serve files inline if they're known-safe types. Everything
// else gets Content-Disposition: attachment to prevent browsers
// from interpreting potentially malicious content (HTML, SVG, etc.)
// ---------------------------------------------------------------
$safeMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/octet-stream',
];

// Must be logged in. The requireAuth() function handles the redirect.
requireAuth();

$auth = Auth::getInstance();
$session = Session::getInstance();

// ---------------------------------------------------------------
// PERMISSION LOCKDOWN
// Only administrators and cyber_tprm team members can access
// assessment files. These documents contain vendor security details
// and potentially sensitive information. Random employees don't
// get to browse through other companies' SOC2 reports for fun.
// ---------------------------------------------------------------
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');

if (!$isAdmin && !$isCyberTPRM) {
    http_response_code(403);
    die('Access denied. Only administrators and Cyber TPRM team members can access assessment files.');
}

// We need to know which file and which assessment. Both are required.
$filePath = $_GET['file'] ?? '';
$assessmentId = intval($_GET['assessment_id'] ?? 0);

if (empty($filePath) || !$assessmentId) {
    http_response_code(400);
    die('Missing parameters');
}

$db = Database::getInstance();

// ---------------------------------------------------------------
// DATABASE BLOB STORAGE PATH (files stored as "db:{uuid}")
// If the file path starts with "db:", the file is stored as an
// encrypted BLOB in the assessment_files table. We fetch it,
// decrypt the contents in memory, and stream them back. No temp
// files, no disk I/O for the actual content -- it all happens in RAM.
// The UUID prevents sequential ID enumeration attacks.
// ---------------------------------------------------------------
if (strpos($filePath, 'db:') === 0) {
    $fileUuid = substr($filePath, 3);

    // Fetch the file record, making sure it belongs to the right assessment
    // (so you can't request a file from assessment 5 using assessment 3's URL)
    $fileRecord = $db->fetchOne(
        "SELECT * FROM assessment_files WHERE file_uuid = ? AND assessment_id = ?",
        [$fileUuid, $assessmentId]
    );

    if (!$fileRecord) {
        http_response_code(404);
        die('File not found');
    }

    try {
        // Decrypt the BLOB data back into usable file content
        $encryption = new Encryption();
        $decryptedContent = $encryption->decryptRaw($fileRecord['encrypted_data']);

        // Validate the MIME type from the database against our whitelist.
        // If it's not in the whitelist, force application/octet-stream to
        // prevent browsers from rendering potentially malicious content.
        $mimeType = $fileRecord['mime_type'];
        if (!in_array($mimeType, $safeMimeTypes, true)) {
            $mimeType = 'application/octet-stream';
        }

        // Serve the file with proper headers. Only use "inline" for safe types
        // (PDFs, images). Everything else gets "attachment" to force download.
        // Sanitize filename to prevent HTTP header injection (\r\n in filename)
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
        // Decryption failed -- could be corrupted data, key rotation issues, etc.
        error_log('File decryption error: ' . $e->getMessage());
        if (strpos($e->getMessage(), 'HMAC verification failed') !== false) {
            http_response_code(410);
            die('This file was encrypted with a previous encryption key and can no longer be decrypted. Please re-upload the file.');
        }
        http_response_code(500);
        die('Failed to decrypt file');
    }
}

// ---------------------------------------------------------------
// FILESYSTEM STORAGE PATH VALIDATION
// For files stored on disk, make sure the requested path actually
// belongs to this assessment's folder. This prevents path traversal
// attacks where someone tries "../../etc/passwd" as the file param.
// Also blocks protocol schemes (http://, ftp://, php://, etc.) to
// prevent SSRF via readfile/file_get_contents.
// ---------------------------------------------------------------

// Block any protocol scheme to prevent SSRF -- no remote URLs allowed
if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $filePath)) {
    http_response_code(403);
    die('Invalid file path');
}

// Block null bytes that could truncate paths in C-based functions
if (strpos($filePath, "\0") !== false) {
    http_response_code(403);
    die('Invalid file path');
}

if (strpos($filePath, 'uploads/assessments/' . $assessmentId . '/') !== 0) {
    http_response_code(403);
    die('Invalid file path');
}

// Build the full filesystem path and resolve it to catch traversal tricks
$fullPath = __DIR__ . '/../' . $filePath;
$realPath = realpath($fullPath);
$allowedDir = realpath(__DIR__ . '/../uploads/assessments/' . $assessmentId);

// Verify the resolved path is within the expected assessment directory
if ($realPath === false || $allowedDir === false || strpos($realPath, $allowedDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    die('Invalid file path');
}

// Final safety check: ensure the resolved path is a regular file, not a
// symlink, directory, or other special file type
if (!is_file($realPath)) {
    http_response_code(403);
    die('Invalid file path');
}

// ---------------------------------------------------------------
// LEGACY UNENCRYPTED FILES
// If the file doesn't end in .enc, it's from the pre-encryption era.
// Just serve it directly. These should eventually get migrated to
// encrypted storage, but "eventually" is a flexible word around here.
// ---------------------------------------------------------------
if (substr($filePath, -4) !== '.enc') {
    $finfo = new \finfo(FILEINFO_MIME_TYPE); $mimeType = $finfo->file($realPath);
    // Validate MIME type against whitelist; fall back to octet-stream for unknown types
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

// ---------------------------------------------------------------
// ENCRYPTED FILE DECRYPTION + SERVING
// Read the encrypted file, decrypt it in memory, figure out the
// original filename (strip the .enc extension), determine the MIME
// type from the extension, and serve it with appropriate headers.
// ---------------------------------------------------------------
try {
    $encryption = new Encryption();
    $decryptedContent = $encryption->decryptFile($realPath);

    // Strip the .enc to get the original filename
    $originalName = basename($filePath, '.enc');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // Map file extensions to MIME types. If we don't recognize the
    // extension, fall back to application/octet-stream (the "I dunno,
    // just download it" MIME type). This whitelist also prevents serving
    // dangerous content types like text/html or image/svg+xml.
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

    // Serve it up with anti-caching headers because you really
    // don't want decrypted security docs sitting in browser caches.
    // Only use "inline" for safe renderable types (PDF, images).
    $safeOriginalName = str_replace(["\r", "\n", "\0", '"'], '', $originalName);
    $disposition = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)
        ? 'inline' : 'attachment';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeOriginalName . '"');
    header('Content-Length: ' . strlen($decryptedContent));
    header('Cache-Control: private, max-age=0, must-revalidate');


    echo $decryptedContent;
} catch (Exception $e) {
    // Decryption went sideways. Log it for investigation.
    error_log('File decryption error: ' . $e->getMessage());
    if (strpos($e->getMessage(), 'HMAC verification failed') !== false) {
        http_response_code(410);
        die('This file was encrypted with a previous encryption key and can no longer be decrypted. Please re-upload the file.');
    }
    http_response_code(500);
    die('Failed to decrypt file');
}
