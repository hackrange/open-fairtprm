<?php
/**
 * API: Save (create or update) a template section
 */
header('Content-Type: application/json');
require_once '../includes/init.php';
requireAuth();

$security = Security::getInstance();
$session = Session::getInstance();
$auth = Auth::getInstance();

// Access check: admin or cyber_tprm
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
$sectionId = intval($_POST['section_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
// Role visibility / edit grant (onboarding templates) — sanitized to known role slugs or null.
$visibleRoles = VendorAssessmentService::sanitizeVisibleRoles($_POST['visible_roles'] ?? '');
$editableRoles = VendorAssessmentService::sanitizeEditableRoles($_POST['editable_roles'] ?? '');

if (!$templateId || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Template ID and name are required', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

try {
    $sectionData = ['name' => $name, 'description' => $description, 'visible_roles' => $visibleRoles, 'editable_roles' => $editableRoles];
    if ($sectionId) {
        $svc->updateSection($sectionId, $sectionData);
        $resultId = $sectionId;
    } else {
        $resultId = $svc->createSection($templateId, $sectionData);
    }

    echo json_encode(['success' => true, 'section_id' => $resultId, 'csrf_token' => $security->getCSRFToken()]);
} catch (Exception $e) {
    error_log('Section save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save section', 'csrf_token' => $security->getCSRFToken()]);
}
