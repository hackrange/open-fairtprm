<?php
/**
 * Preview SQL Update File
 *
 * Returns the contents of a SQL update file for preview in the admin panel.
 * Admin-only access. No direct access to the file system -- only reads from
 * the protected sql_updates/ directory.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/init.php';

// Only admins get to peek at SQL files
requireAdmin();

// Only GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// No CSRF check needed for read-only GET -- admin auth is sufficient.
// Consuming the token here would invalidate form tokens on the calling page.

$requestedFile = basename($_GET['file'] ?? '');

if (empty($requestedFile)) {
    echo json_encode(['success' => false, 'error' => 'No file specified']);
    exit;
}

// Strict filename validation
if (!preg_match('/^[a-zA-Z0-9._-]+\.sql$/i', $requestedFile)) {
    echo json_encode(['success' => false, 'error' => 'Invalid filename']);
    exit;
}

$updatesDir = __DIR__ . '/../sql_updates';
$filePath = $updatesDir . '/' . $requestedFile;

if (!file_exists($filePath) || !is_file($filePath)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit;
}

// Cap preview size at 500 KB to avoid blowing up the browser
$maxPreview = 512 * 1024;
$fileSize = filesize($filePath);
$content = file_get_contents($filePath, false, null, 0, $maxPreview);

if ($content === false) {
    echo json_encode(['success' => false, 'error' => 'Could not read file']);
    exit;
}

if ($fileSize > $maxPreview) {
    $content .= "\n\n-- [Preview truncated. File is " . round($fileSize / 1024, 2) . " KB total.] --";
}

echo json_encode([
    'success' => true,
    'content' => $content,
    'filename' => $requestedFile,
    'size' => $fileSize,
]);
