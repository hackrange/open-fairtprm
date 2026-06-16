<?php
/**
 * API: Reorder template sections
 */
header('Content-Type: application/json');
require_once '../includes/init.php';
requireAuth();

$security = Security::getInstance();
$session = Session::getInstance();
$auth = Auth::getInstance();

$isAllowed = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator') || hasGroup('cyber_tprm');
if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$svc = new VendorAssessmentService();

$templateId = intval($_POST['template_id'] ?? 0);
$sectionIds = json_decode($_POST['section_ids'] ?? '[]', true);

if (!$templateId || !is_array($sectionIds) || empty($sectionIds)) {
    echo json_encode(['success' => false, 'message' => 'Template ID and section IDs required', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

// Sanitize IDs
$sectionIds = array_map('intval', $sectionIds);

try {
    $svc->reorderSections($templateId, $sectionIds);
    echo json_encode(['success' => true, 'csrf_token' => $security->getCSRFToken()]);
} catch (Exception $e) {
    error_log('Section reorder error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to reorder sections', 'csrf_token' => $security->getCSRFToken()]);
}
