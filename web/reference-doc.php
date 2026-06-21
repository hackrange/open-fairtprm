<?php
/**
 * Admin-only reference-document server.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Serves the bundled API reference files (OpenAPI / Swagger, Postman) behind the
 * admin session instead of as world-readable static assets. The static
 * app/{grip,hero}-reference/ directories are denied at the Apache layer; these
 * files are only reachable through this endpoint, which requires an authenticated
 * admin. Whitelisted filenames only — no arbitrary path access.
 *
 * Usage: reference-doc.php?file=hero-openapi.yaml[&download=1]
 */

require_once 'includes/init.php';

// Must be a logged-in admin (redirects to login / 403 otherwise).
requireAdmin();

// Strict allow-list: logical name => [absolute path, content type]. No user
// input ever reaches the filesystem path.
$allowed = [
    'grip-openapi.yaml'            => [__DIR__ . '/app/grip-reference/grip-openapi.yaml',            'application/yaml'],
    'grip-postman-collection.json' => [__DIR__ . '/app/grip-reference/grip-postman-collection.json', 'application/json'],
    'hero-openapi.yaml'            => [__DIR__ . '/app/hero-reference/hero-openapi.yaml',            'application/yaml'],
];

$key = (string)($_GET['file'] ?? '');
if (!isset($allowed[$key]) || !is_file($allowed[$key][0])) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Reference document not found.';
    exit;
}

[$path, $contentType] = $allowed[$key];

header('Content-Type: ' . $contentType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if (isset($_GET['download'])) {
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
}
readfile($path);
exit;
