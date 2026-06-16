<?php
/**
 * API: Delete a template question
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

$questionId = intval($_POST['question_id'] ?? 0);
if (!$questionId) {
    echo json_encode(['success' => false, 'message' => 'Question ID required', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

try {
    $svc->deleteQuestion($questionId);
    echo json_encode(['success' => true, 'csrf_token' => $security->getCSRFToken()]);
} catch (Exception $e) {
    error_log('Question delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete question', 'csrf_token' => $security->getCSRFToken()]);
}
