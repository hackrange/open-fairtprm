<?php
/**
 * CSRF Token Refresh API
 *
 * Lightweight authenticated endpoint that generates and returns a fresh
 * CSRF token. Called by a periodic JS timer to keep the token alive on
 * long-lived forms (e.g. vendor onboarding).
 */

header('Content-Type: application/json');

require_once '../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireAuth();

$security = Security::getInstance();
$token = $security->generateCSRFToken();

echo json_encode([
    'success' => true,
    'csrf_token' => $token
]);
