<?php
/**
 * Vendor Document Download API
 *
 * Decrypts and serves vendor documents stored in the vendor_documents table.
 * Files are fetched by UUID, decrypted in memory, and streamed to the browser
 * with appropriate MIME types and cache headers.
 */

require_once '../includes/init.php';

$safeMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/octet-stream',
];

requireAuth();

$auth = Auth::getInstance();
$session = Session::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');

if (!$isAdmin && !$isProcurement && !$isCyberTPRM) {
    http_response_code(403);
    die('Access denied. Only administrators, procurement, and Cyber TPRM team members can access vendor documents.');
}

$uuid = $_GET['uuid'] ?? '';

if (empty($uuid)) {
    http_response_code(400);
    die('Missing parameters');
}

$db = Database::getInstance();

// Fetch the document record (encrypted_data is large, but we need it for decryption)
$doc = $db->fetchOne(
    "SELECT * FROM vendor_documents WHERE file_uuid = ?",
    [$uuid]
);

if (!$doc) {
    http_response_code(404);
    die('Document not found');
}

try {
    $encryption = new Encryption();
    $decryptedContent = $encryption->decryptRaw($doc['encrypted_data']);

    $mimeType = $doc['mime_type'];
    if (!in_array($mimeType, $safeMimeTypes, true)) {
        $mimeType = 'application/octet-stream';
    }

    $safeFilename = str_replace(["\r", "\n", "\0", '"'], '', $doc['original_filename']);
    $viewInline = isset($_GET['view']) && $_GET['view'] === '1'
        && in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true);
    $disposition = $viewInline ? 'inline' : 'attachment';

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
    header('Content-Length: ' . strlen($decryptedContent));
    header('Cache-Control: private, max-age=0, must-revalidate');

    echo $decryptedContent;
    exit;
} catch (Exception $e) {
    error_log('Vendor document decryption error: ' . $e->getMessage());
    http_response_code(500);
    die('Failed to decrypt document');
}
