<?php
/**
 * Send Assessment Email API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Sends a vendor security assessment request email via the configured SMTP
 * server instead of relying on mailto: links. This endpoint is called by the
 * "Email" button on the vendor assessments page when email notifications are
 * enabled in Admin > Email Settings. Returns JSON with success/failure status
 * so the frontend can show appropriate feedback.
 */

header('Content-Type: application/json');
require_once '../includes/init.php';

requireAuth();

$auth = Auth::getInstance();
$session = Session::getInstance();
$security = Security::getInstance();

// Same permission check as vendor-assessments.php
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// CSRF validation
$csrfToken = $input['csrf_token'] ?? '';
if (!$security->validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$uuid = trim($input['uuid'] ?? '');
$vendorName = trim($input['vendor_name'] ?? '');
$vendorEmail = trim($input['vendor_email'] ?? '');

if (empty($uuid) || empty($vendorName) || empty($vendorEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

if (!filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Build the assessment URL from the UUID
$assessmentUrl = baseUrl("vendor-assessment.php?token={$uuid}");

// Look up contact name, template ID, and vendor full name from the assessment + onboarding records
$db = Database::getInstance();
$assessment = $db->fetchOne(
    "SELECT va.id as assessment_id, va.vendor_contact_name, va.template_id, va.vendor_request_id, va.expires_at,
            vor.primary_contact_details as full_name,
            at.name as assessment_name
     FROM vendor_assessments va
     LEFT JOIN vendor_onboarding_requests vor ON va.vendor_request_id = vor.id
     LEFT JOIN assessment_templates at ON va.template_id = at.id
     WHERE va.uuid = ?",
    [$uuid]
);
$contactName = !empty($assessment['vendor_contact_name']) ? $assessment['vendor_contact_name'] : null;
$templateId = !empty($assessment['template_id']) ? (int)$assessment['template_id'] : null;
$fullName = !empty($assessment['full_name']) ? $assessment['full_name'] : null;
$expiresAt = !empty($assessment['expires_at']) ? $assessment['expires_at'] : null;
$assessmentName = !empty($assessment['assessment_name']) ? $assessment['assessment_name'] : null;

try {
    require_once __DIR__ . '/../includes/classes/EmailService.php';
    $encryption = new Encryption();
    $emailService = new EmailService($db, $encryption);

    if (!$emailService->isEnabled()) {
        echo json_encode(['success' => false, 'message' => 'Email notifications are not enabled.']);
        exit;
    }

    $result = $emailService->sendAssessmentEmail($vendorEmail, $vendorName, $assessmentUrl, $contactName, $templateId, $fullName, $expiresAt, $assessmentName);

    if (!empty($result['success'])) {
        $user = $auth->getUser();
        $assessmentId = !empty($assessment['assessment_id']) ? (int)$assessment['assessment_id'] : null;
        $auth->audit($user['id'], 'assessment_email_sent', 'vendor_assessments', $assessmentId, [
            'new' => ['uuid' => $uuid, 'vendor_name' => $vendorName, 'vendor_email' => $vendorEmail]
        ]);

        // Record in tracking table so cron won't re-send
        if (!empty($assessment['assessment_id'])) {
            try {
                $expiresDate = $expiresAt ? date('Y-m-d', strtotime($expiresAt)) : date('Y-m-d', strtotime('+30 days'));
                $db->query(
                    "INSERT INTO vendor_assessment_reminders (assessment_id, reminder_type, expires_at, sent_at, email_sent_to, status)
                     VALUES (?, 'initial', ?, NOW(), ?, 'sent')
                     ON DUPLICATE KEY UPDATE status = 'sent', sent_at = NOW(), email_sent_to = VALUES(email_sent_to)",
                    [$assessment['assessment_id'], $expiresDate, $vendorEmail]
                );
            } catch (Exception $trackEx) {
                error_log('Assessment reminder tracking insert failed: ' . $trackEx->getMessage());
            }
        }
    }

    // Include refreshed CSRF token so the caller can update its state
    $result['csrf_token'] = $security->getCSRFToken();
    echo json_encode($result);

} catch (Exception $e) {
    error_log('Send assessment email API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
}
