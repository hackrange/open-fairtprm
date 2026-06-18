<?php
/**
 * Assessment Certificate Upload API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "get out of questionnaire free" card. If a vendor has an ISO 27001 certificate
 * (or similar), they can upload it here instead of answering 200 individual questions.
 * Validation, encryption, and BLOB storage are handled by FileUploadService (because
 * duplicating crypto code across files is a rookie move). The upload auto-completes
 * the assessment, sets the certificate expiry date, and everyone goes home happy.
 */

// JSON responses for our frontend upload handler
header('Content-Type: application/json');

// Boot the application
require_once '../includes/init.php';

$security = Security::getInstance();

// ---------------------------------------------------------------
// METHOD CHECK
// Uploads are POST. This will never change. Ever.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// CSRF PROTECTION
// Standard cross-site request forgery check. The basics matter.
// ---------------------------------------------------------------
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Load the assessment service for certificate handling
require_once __DIR__ . '/../includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// Get the assessment UUID and optional certificate expiry date
$uuid = $_POST['assessment_uuid'] ?? '';
$expiryDate = $_POST['certificate_expiry'] ?? null;
if (!empty($expiryDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid expiry date format']);
    exit;
}

if (empty($uuid)) {
    echo json_encode(['success' => false, 'message' => 'Missing assessment ID']);
    exit;
}

// ---------------------------------------------------------------
// ASSESSMENT ACCESS VALIDATION
// Make sure this assessment exists and the person uploading
// actually has the right to modify it.
// ---------------------------------------------------------------
$assessment = $assessmentService->getAssessmentByUUID($uuid);

if (!$assessment || !$assessmentService->canAccess($assessment)) {
    echo json_encode(['success' => false, 'message' => 'Assessment not accessible']);
    exit;
}

// ---------------------------------------------------------------
// CERTIFICATE UPLOAD PERMISSION CHECK
// Not all assessments allow certificate uploads. This is configured
// per-assessment by the admin. If it's not enabled, tough luck --
// you're filling out the questionnaire like everyone else.
// ---------------------------------------------------------------
if (!$assessment['allow_certificate_upload']) {
    echo json_encode(['success' => false, 'message' => 'Certificate upload not allowed for this assessment']);
    exit;
}

// Certificate-upload mode for this template: skip (the certificate completes the
// assessment) or minimal (the certificate is one required step; the selected
// questions and the attestation are completed afterwards via the normal submit).
$certMode = $assessmentService->getEffectiveCertificateMode($assessment);
$isMinimalUpload = ($certMode === 'minimal');

// ---------------------------------------------------------------
// SUBMITTER ATTESTATION -- in skip mode the certificate finalizes the assessment,
// so we capture and require the attestation here. In minimal mode the attestation
// is collected at final submission, so we only store the certificate now.
// ---------------------------------------------------------------
$submitterName  = trim($_POST['submitter_name'] ?? '');
$submitterTitle = trim($_POST['submitter_title'] ?? '');
$submitterEmail = trim($_POST['submitter_email'] ?? '');
$submitterPhone = trim($_POST['submitter_phone'] ?? '');
$submitterAttested = ($_POST['submitter_attested'] ?? '') === '1';
if (!$isMinimalUpload && ($submitterName === '' || $submitterTitle === '' || $submitterPhone === ''
    || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) || !$submitterAttested)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide your name, title, valid email, phone number, and confirm the truthfulness attestation.',
        'csrf_token' => $security->getCSRFToken()
    ]);
    exit;
}

// ---------------------------------------------------------------
// VALIDATE AND STORE THE CERTIFICATE
// FileUploadService handles MIME checks, size limits, encryption,
// and BLOB storage. Same bouncer, different door.
// ---------------------------------------------------------------
$uploadService = new FileUploadService();
$result = $uploadService->validate('certificate');

if ($result['error']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

try {
    $dbPath = $uploadService->encryptAndStore(
        $result['file'],
        (int)$assessment['id'],
        'certificate'
    );

    // Update the assessment with the certificate reference and expiry. In minimal
    // mode we store the certificate WITHOUT completing the assessment -- the vendor
    // still has to answer the selected questions and attest at final submission.
    $assessmentService->saveCertificate(
        $assessment['id'],
        $dbPath,
        $expiryDate ?: null,
        !$isMinimalUpload
    );

    if (!$isMinimalUpload) {
        $assessmentService->saveSubmitterAttestation($assessment['id'], [
            'submitter_name'       => $submitterName,
            'submitter_title'      => $submitterTitle,
            'submitter_email'      => $submitterEmail,
            'submitter_phone'      => $submitterPhone,
            'submitter_ip_address' => $security->getClientIP(),
            'submitter_attested'   => 1,
        ]);
    }

    $auth = Auth::getInstance();
    $userId = $auth->getUserId();
    $auth->audit($userId, 'certificate_upload', 'vendor_assessments', $assessment['id'], [
        'new' => ['uuid' => $uuid, 'expiry_date' => $expiryDate]
    ]);

    echo json_encode([
        'success' => true,
        'message' => $isMinimalUpload
            ? 'Certificate uploaded. Please complete the remaining questions to finish.'
            : 'Certificate uploaded and encrypted successfully',
        'csrf_token' => $security->getCSRFToken()
    ]);

} catch (Exception $e) {
    // Something went wrong during encryption or DB storage. This is bad
    // because the vendor thinks they're done but their cert didn't save.
    // Log the full error, but give the user enough info to know it failed.
    error_log('Certificate upload error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save certificate. Please try again.']);
}
