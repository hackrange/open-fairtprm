<?php
/**
 * API: Reorder questions within a section
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

$sectionId = intval($_POST['section_id'] ?? 0);
$questionIds = json_decode($_POST['question_ids'] ?? '[]', true);

if (!$sectionId || !is_array($questionIds) || empty($questionIds)) {
    echo json_encode(['success' => false, 'message' => 'Section ID and question IDs required', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$questionIds = array_map('intval', $questionIds);

try {
    $svc->reorderQuestions($sectionId, $questionIds);
    echo json_encode(['success' => true, 'csrf_token' => $security->getCSRFToken()]);
} catch (Exception $e) {
    error_log('Question reorder error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to reorder questions', 'csrf_token' => $security->getCSRFToken()]);
}
