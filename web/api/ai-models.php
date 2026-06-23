<?php
/**
 * API: List available models from the active AI platform
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Admin-only AJAX endpoint used by the AI Platform settings page to populate
 * the Model / FAIR AI Model dropdowns. Queries the active platform's model
 * list (derived from the saved API URL + token) so admins pick a valid model
 * instead of typing one by hand. Uses the SAVED config, so the URL and token
 * must be saved first.
 */
header('Content-Type: application/json');
require_once '../includes/init.php';
requireAuth();

$security = Security::getInstance();
$session = Session::getInstance();
$auth = Auth::getInstance();

// Access check: administrators only (this reads AI platform credentials).
$isAllowed = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

require_once __DIR__ . '/../includes/classes/AIPlatformService.php';

// Panel-aware: the settings page sends the platform whose model list the admin
// is editing. Validate it; an unknown/empty value falls back to the active one.
$platform = $_POST['platform'] ?? '';
if (!in_array($platform, ['openwebui', 'librechat', 'custom', 'anthropic', 'openai'], true)) {
    $platform = null;
}
$ai = AIPlatformService::getInstance();
$result = $ai->fetchAvailableModels($platform);

echo json_encode([
    'success' => $result['success'],
    'models'  => $result['models'],
    'error'   => $result['error'],
]);
