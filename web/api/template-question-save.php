<?php
/**
 * API: Save (create or update) a template question
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
$sectionId = intval($_POST['section_id'] ?? 0);
$questionText = trim($_POST['question_text'] ?? '');
$questionType = $_POST['question_type'] ?? 'text';
$isRequired = intval($_POST['is_required'] ?? 1);
$helpText = trim($_POST['help_text'] ?? '');
$options = $_POST['options'] ?? '';
$fieldName = trim($_POST['field_name'] ?? '');
$dependsOnQuestionId = intval($_POST['depends_on_question_id'] ?? 0);
$dependsOnValue = trim($_POST['depends_on_value'] ?? '');
// Role visibility / edit grant (onboarding templates) — sanitized to known role slugs or null.
$visibleRoles = VendorAssessmentService::sanitizeVisibleRoles($_POST['visible_roles'] ?? '');
$editableRoles = VendorAssessmentService::sanitizeEditableRoles($_POST['editable_roles'] ?? '');

if (!$sectionId || empty($questionText)) {
    echo json_encode(['success' => false, 'message' => 'Section ID and question text are required', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

// Validate question type
$validTypes = ['text', 'textarea', 'select', 'radio', 'checkbox', 'file', 'number', 'date', 'button_group', 'button_group_multi', 'email', 'phone', 'vat'];
if (!in_array($questionType, $validTypes)) {
    $questionType = 'text';
}

$data = [
    'question_text' => $questionText,
    'question_type' => $questionType,
    'is_required' => $isRequired,
    'help_text' => $helpText ?: null,
    'options' => $options ?: null,
    'field_name' => $fieldName ?: null,
    'depends_on_question_id' => $dependsOnQuestionId ?: null,
    'depends_on_value' => $dependsOnValue ?: null,
    'visible_roles' => $visibleRoles,
    'editable_roles' => $editableRoles,
];

try {
    if ($questionId) {
        $svc->updateQuestion($questionId, $data);
        $resultId = $questionId;
    } else {
        $resultId = $svc->createQuestion($sectionId, $data);
    }

    echo json_encode(['success' => true, 'question_id' => $resultId, 'csrf_token' => $security->getCSRFToken()]);
} catch (Exception $e) {
    error_log('Question save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save question', 'csrf_token' => $security->getCSRFToken()]);
}
