<?php
/**
 * Public Vendor Assessment Form - The Questionnaire From the Outside
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the public-facing assessment form that vendors fill out -- no login required.
 * Access is controlled via a unique UUID token in the URL, so basically a secret link.
 * It's a multi-step wizard with autosave (because nobody wants to lose 30 minutes of
 * typing to a browser crash), section navigation, progress tracking, and even an
 * option to upload an ISO 27001 certificate to skip the whole questionnaire.
 * Vendors can drag-and-drop files, navigate between sections, and submit when done.
 * Think of it as a really polished Google Form, but for security questionnaires.
 */

// Boot up the app. No auth required here -- this is a public form.
require_once 'includes/init.php';

// No authentication required - this is a public form accessed via UUID token
$security = Security::getInstance();
$db = Database::getInstance();

// Fire up the assessment service and make sure the tables exist
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();
$assessmentService->initializeTables();

// ============================================================================
// TOKEN VALIDATION
// The UUID token in the URL is the vendor's "key" to this assessment.
// No token = no assessment. Wrong token = also no assessment.
// ============================================================================
$uuid = $_GET['token'] ?? '';
if (empty($uuid)) {
    http_response_code(404);
    die(e(t('vendor-assessment.not_found')));
}

// Look up the assessment by UUID
$assessment = $assessmentService->getAssessmentByUUID($uuid);
if (!$assessment) {
    http_response_code(404);
    die(e(t('vendor-assessment.not_found')));
}

// Check if the link has expired -- we don't want vendors filling out stale assessments
if ($assessmentService->isExpired($assessment)) {
    die(e(t('vendor-assessment.link_expired')));
}

// Check if they already submitted this thing. If so, show a "thanks" message.
// The ?edit=1 parameter allows re-editing a completed assessment (used by internal Edit button)
// without changing the assessment status -- status stays completed until re-submitted.
$forceEdit = isset($_GET['edit']) && $_GET['edit'] === '1';
if ($assessment['status'] === 'completed' && !$forceEdit) {
    $completedView = true;
} else {
    $completedView = false;
    // First time opening? Mark it as in_progress so the admin knows someone's working on it
    if ($assessment['status'] === 'pending') {
        $assessmentService->updateStatus($assessment['id'], 'in_progress');
        $assessment['status'] = 'in_progress';
    }
}

// Load up the sections, figure out how far along the vendor is, and grab existing responses
$sections = $assessmentService->getSections($assessment['template_id']);
$completionStatus = $assessmentService->getCompletionStatus($assessment['id']);
$responses = $assessmentService->getResponses($assessment['id']);

// Figure out which section we're currently viewing
$currentSectionIndex = isset($_GET['section']) ? intval($_GET['section']) : 0;

// Section index -1 is the special "upload your ISO cert" section
$showCertificateUpload = $assessment['allow_certificate_upload'] && !$assessment['certificate_uploaded'] && $currentSectionIndex === -1;

// Clamp the section index to valid bounds (no going off the rails)
if ($currentSectionIndex >= count($sections)) {
    $currentSectionIndex = count($sections) - 1;
}
if ($currentSectionIndex < -1) {
    $currentSectionIndex = $assessment['allow_certificate_upload'] ? -1 : 0;
}

// Load the questions for whatever section we're on
$currentSection = null;
$currentQuestions = [];
if ($currentSectionIndex >= 0 && isset($sections[$currentSectionIndex])) {
    $currentSection = $sections[$currentSectionIndex];
    $currentQuestions = $assessmentService->getQuestions($currentSection['id']);
}

// Load app-level theme from app_config (no user is logged in on this public page)
$themeKeys = ['logo_url', 'footer_logo_url', 'header_color', 'footer_color', 'button_color'];
$theme = [
    'logo_url' => 'app/images/logo-default-418x78.png',
    'footer_logo_url' => 'app/images/logo-inverse-416x78.png',
    'header_color' => '#35a0a3',
    'footer_color' => '#1a365d',
    'button_color' => '#35a0a3',
];
foreach ($themeKeys as $tk) {
    $val = getAppConfig($tk);
    if (!empty($val)) $theme[$tk] = $val;
}
$companyName = getAppConfig('company_name', '');

// CSRF token for form submissions -- yes, even public forms need this
$csrfToken = $security->generateCSRFToken();

// Localization note: assessment content is admin-authored dynamic text, so
// instead of a paid/slow AI translation service we let vendors use their
// browser's built-in "Translate page" feature (Chrome/Edge/Safari/Firefox).
// The page declares its source language as English below, which is what lets
// browsers detect it and offer to translate into the vendor's language. Answer
// option VALUES stay original English (only the visible label gets translated
// by the browser), so submitted responses, scoring, and logic are unaffected.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($assessment['template_name']); ?> - Vendor Assessment</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        /* CSS variables from theme -- keeps colors consistent */
        :root {
            --primary-color: <?php echo $theme['header_color']; ?>;
            --button-color: <?php echo $theme['button_color']; ?>;
            --footer-color: <?php echo $theme['footer_color']; ?>;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Roboto', sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        /* Top header bar with logo, assessment name and vendor info */
        .header {
            background: white;
            border-bottom: 3px solid var(--primary-color);
            padding: 20px;
            text-align: center;
        }
        .header-logo {
            margin-bottom: 12px;
        }
        .header-logo img {
            max-height: 50px;
            max-width: 280px;
            object-fit: contain;
        }
        .header h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 5px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }

        /* Footer */
        .assessment-footer {
            background: var(--footer-color);
            color: rgba(255,255,255,0.7);
            padding: 20px 30px;
            font-size: 13px;
            margin-top: 40px;
        }
        .assessment-footer-body {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .assessment-footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .assessment-footer-brand img {
            max-height: 35px;
            object-fit: contain;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Progress bar -- lets vendors know how much torture remains */
        .progress-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .progress-header h3 {
            font-size: 14px;
            color: #666;
        }
        .progress-percentage {
            font-size: 18px;
            font-weight: 600;
            /* Color set dynamically via inline style based on progress */
        }
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #22c55e;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Section navigation pills -- click to jump between sections */
        .section-nav {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .section-nav h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .section-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .section-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 13px;
            text-decoration: none;
            color: #666;
            background: #f3f4f6;
            transition: all 0.2s;
        }
        .section-item:hover {
            background: #e5e7eb;
            color: #333;
        }
        .section-item.active {
            background: var(--primary-color);
            color: white;
        }
        .section-item.complete {
            background: #dcfce7;
            color: #166534;
        }
        .section-item.complete.active {
            background: #166534;
            color: white;
        }
        .section-item .check {
            font-size: 12px;
        }

        /* The main form card where questions live */
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .form-card h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 8px;
        }
        .form-card .section-desc {
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        /* Individual question styling */
        .question {
            margin-bottom: 25px;
            position: relative;
        }
        .question:last-child {
            margin-bottom: 0;
        }

        /* Green checkmark for answered questions */
        .question .input-wrapper {
            position: relative;
            display: block;
        }
        .question-check {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #22c55e;
            opacity: 0.5;
            pointer-events: none;
            display: none;
            z-index: 1;
            line-height: 1;
        }
        /* Size the checkmark to 40% of the input height */
        input[type="text"] + .question-check,
        input[type="email"] + .question-check,
        input[type="number"] + .question-check,
        input[type="date"] + .question-check,
        select + .question-check {
            font-size: calc(44px * 0.4); /* 44px is approx input height, 40% of that */
        }
        textarea + .question-check {
            top: 20px;
            transform: none;
            font-size: calc(100px * 0.4); /* textarea is taller */
        }
        .radio-group + .question-check,
        .checkbox-group + .question-check {
            top: 12px;
            transform: none;
            font-size: 16px;
        }
        .file-upload + .question-check {
            top: 50%;
            transform: translateY(-50%);
            font-size: calc(90px * 0.4);
        }
        .question.answered .question-check {
            display: block;
        }
        /* Error highlight for incomplete questions - only when NOT answered */
        .question.error:not(.answered) input:not([type="radio"]):not([type="checkbox"]),
        .question.error:not(.answered) textarea,
        .question.error:not(.answered) select {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
        }
        .question.error:not(.answered) .radio-option:not(.selected),
        .question.error:not(.answered) .checkbox-option:not(.selected),
        .question.error:not(.answered) .button-group-option:not(.selected) {
            border-color: #ef4444;
            background: rgba(239,68,68,0.05);
        }
        .question.error:not(.answered) .question-label {
            color: #ef4444;
        }
        .question-label {
            display: block;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .question-label .required {
            color: #ef4444;
            margin-left: 3px;
        }
        .question-help {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            font-style: italic;
        }

        /* Form inputs -- text, email, number, date, textarea, select */
        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255,101,67,0.1);
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }

        /* Radio and checkbox groups -- styled as clickable cards */
        .radio-group,
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .radio-option,
        .checkbox-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-option:hover,
        .checkbox-option:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .radio-option.selected,
        .checkbox-option.selected {
            background: rgba(34,197,94,0.08);
            border-color: #22c55e;
        }
        .radio-option input,
        .checkbox-option input {
            margin: 0;
        }

        /* Button group -- horizontal pill buttons */
        .button-group-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 8px 0;
        }
        .button-group-option {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-weight: normal;
            padding: 10px 18px;
            border: 2px solid #e5e7eb;
            border-radius: 25px;
            transition: all 0.2s;
            font-size: 14px;
            color: #333;
            background: white;
        }
        .button-group-option:hover {
            border-color: var(--theme-header-color, #22c55e);
            background: rgba(0,0,0,0.02);
        }
        .button-group-option input[type="radio"] { display: none; }
        .button-group-option input[type="checkbox"] { display: none; }
        .button-group-option.selected {
            border-color: var(--theme-header-color, #22c55e);
            background: rgba(34,197,94,0.08);
            font-weight: 500;
        }
        .button-group-container + .question-check {
            top: 12px;
        }

        /* File upload drop zone -- click or drag to upload */
        .file-upload {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-upload:hover {
            border-color: var(--primary-color);
            background: rgba(255,101,67,0.02);
        }
        .file-upload.has-file {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .file-upload input {
            display: none;
        }
        .file-upload-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .file-upload-text {
            color: #666;
            font-size: 14px;
        }
        .file-upload-text strong {
            color: var(--primary-color);
        }

        /* Navigation buttons (Previous / Next / Submit) */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e5e7eb;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .btn-success {
            background: #22c55e;
            color: white;
        }
        .btn-success:hover {
            background: #16a34a;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* The little floating "Saving..." indicator in the bottom-right corner */
        .autosave-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 10px 18px;
            border-radius: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
            z-index: 1000;
        }
        .autosave-indicator.show {
            opacity: 1;
            transform: translateY(0);
        }
        .autosave-indicator.saving {
            color: #f59e0b;
        }
        .autosave-indicator.saved {
            color: #22c55e;
        }
        .autosave-indicator.error {
            color: #ef4444;
        }

        /* Certificate upload modal -- pops up asking if they have an ISO cert */
        .cert-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .cert-modal-overlay.show {
            display: flex;
        }
        .cert-modal {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .cert-modal h2 {
            margin: 0 0 15px 0;
            color: #333;
        }
        .cert-modal p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        .cert-modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 20px;
        }
        .cert-modal-buttons .btn {
            min-width: 120px;
        }
        .cert-modal-upload {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .cert-modal-upload.show {
            display: block;
        }

        /* Incomplete questions modal */
        .incomplete-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .incomplete-modal-overlay.show {
            display: flex;
        }
        .incomplete-modal {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .incomplete-modal h2 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 1.3em;
        }
        .incomplete-modal .modal-subtitle {
            color: #666;
            margin: 0 0 20px 0;
            font-size: 0.95em;
        }
        .incomplete-modal .modal-body {
            overflow-y: auto;
            flex: 1;
            margin-bottom: 20px;
        }
        .incomplete-modal .section-group {
            margin-bottom: 16px;
        }
        .incomplete-modal .section-group-header {
            font-weight: 600;
            color: #1a365d;
            font-size: 0.95em;
            padding: 6px 0;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 6px;
        }
        .incomplete-modal .question-link {
            display: block;
            padding: 8px 12px;
            color: #2563eb;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9em;
            cursor: pointer;
            transition: background 0.15s;
        }
        .incomplete-modal .question-link:hover {
            background: #eff6ff;
        }
        .incomplete-modal .question-link::before {
            content: "\25cb";
            margin-right: 8px;
            color: #ef4444;
        }
        .incomplete-modal .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }

        /* Certificate upload section (the full-page version, not the modal) */
        .certificate-section {
            text-align: center;
            padding: 40px 20px;
        }
        .certificate-section h2 {
            margin-bottom: 15px;
        }
        .certificate-section p {
            color: #666;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .certificate-section .or-divider {
            display: flex;
            align-items: center;
            margin: 30px 0;
            color: #999;
        }
        .certificate-section .or-divider::before,
        .certificate-section .or-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }
        .certificate-section .or-divider span {
            padding: 0 15px;
            font-size: 14px;
        }

        /* Completed view -- the "you're done, go home" screen */
        .completed-message {
            text-align: center;
            padding: 60px 20px;
        }
        .completed-message .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        .completed-message h2 {
            color: #22c55e;
            margin-bottom: 10px;
        }
        .completed-message p {
            color: #666;
            max-width: 500px;
            margin: 0 auto;
        }

        /* Responsive styles -- make it work on phones too */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            .form-card {
                padding: 20px;
            }
            .section-list {
                flex-direction: column;
            }
            .section-item {
                justify-content: flex-start;
            }
            .nav-buttons {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <?php if (!empty($theme['logo_url'])): ?>
        <div class="header-logo">
            <img src="<?php echo htmlspecialchars($theme['logo_url']); ?>" alt="Logo">
        </div>
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($assessment['template_name']); ?></h1>
        <p><?php echo e(t('vendor-assessment.vendor_label', $assessment['vendor_name'])); ?></p>
    </header>

    <div class="container">
        <?php if ($completedView): ?>
        <!-- They already submitted -- show the "thanks" screen -->
        <div class="form-card">
            <div class="completed-message">
                <div class="icon">&#9989;</div>
                <h2><?php echo e(t('vendor-assessment.completed_title')); ?></h2>
                <p><?php echo e(t('vendor-assessment.completed_message')); ?></p>
                <?php if ($assessment['certificate_uploaded']): ?>
                <p style="margin-top: 15px; color: #22c55e;"><strong><?php echo e(t('vendor-assessment.cert_uploaded_success')); ?></strong></p>
                <p style="margin-top: 10px;"><a href="api/assessment-certificate-download.php?token=<?php echo urlencode($uuid); ?>" class="btn btn-secondary" style="display: inline-block;" target="_blank"><?php echo e(t('vendor-assessment.download_certificate')); ?></a></p>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <?php
        // Calculate progress color: red (0%) -> yellow (50%) -> green (100%)
        // Using HSL: hue 0 = red, hue 60 = yellow, hue 120 = green
        $progressHue = round(($completionStatus['percentage'] / 100) * 120);
        $progressColor = "hsl({$progressHue}, 70%, 45%)";
        ?>
        <!-- Progress bar showing overall completion percentage -->
        <div class="progress-container">
            <div class="progress-header">
                <h3><?php echo e(t('vendor-assessment.overall_progress')); ?></h3>
                <span class="progress-percentage" style="color: <?php echo $progressColor; ?>"><?php echo $completionStatus['percentage']; ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $completionStatus['percentage']; ?>%"></div>
            </div>
        </div>

        <!-- Section navigation -- jump between sections without losing your place -->
        <div class="section-nav">
            <h3><?php echo e(t('vendor-assessment.sections')); ?></h3>
            <div class="section-list">
                <?php if ($assessment['allow_certificate_upload']): ?>
                <a href="?token=<?php echo urlencode($uuid); ?>&section=-1"
                   class="section-item <?php echo $currentSectionIndex === -1 ? 'active' : ''; ?> <?php echo $assessment['certificate_uploaded'] ? 'complete' : ''; ?>">
                    <?php if ($assessment['certificate_uploaded']): ?>
                    <span class="check">&#10003;</span>
                    <?php endif; ?>
                    <?php echo e(t('vendor-assessment.upload_certificate')); ?>
                </a>
                <?php endif; ?>

                <?php foreach ($sections as $i => $section): ?>
                <?php
                    $sectionComplete = isset($completionStatus['sections'][$section['id']]) &&
                                       $completionStatus['sections'][$section['id']]['complete'];
                ?>
                <a href="?token=<?php echo urlencode($uuid); ?>&section=<?php echo $i; ?>"
                   class="section-item <?php echo $currentSectionIndex === $i ? 'active' : ''; ?> <?php echo $sectionComplete ? 'complete' : ''; ?>">
                    <?php if ($sectionComplete): ?>
                    <span class="check">&#10003;</span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($section['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main form content area -->
        <div class="form-card">
            <?php if ($showCertificateUpload): ?>
            <!-- Certificate upload section -- the "easy way out" for vendors with ISO certs -->
            <div class="certificate-section">
                <h2><?php echo e(t('vendor-assessment.upload_iso_title')); ?></h2>
                <p><?php echo htmlspecialchars($assessment['certificate_upload_prompt']); ?></p>

                <form id="certificateForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="assessment_uuid" value="<?php echo htmlspecialchars($uuid); ?>">

                    <div class="file-upload" id="certUploadZone">
                        <input type="file" name="certificate" id="certificateFile" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <div class="file-upload-icon">&#128196;</div>
                        <div class="file-upload-text">
                            <strong><?php echo e(t('vendor-assessment.click_to_upload')); ?></strong> <?php echo e(t('vendor-assessment.or_drag_drop')); ?><br>
                            <small><?php echo e(t('vendor-assessment.file_types_hint')); ?></small>
                        </div>
                    </div>

                    <div class="question" style="margin-top: 20px; text-align: left;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.cert_expiry_date')); ?></label>
                        <input type="date" name="certificate_expiry" id="certExpiry">
                    </div>

                    <div class="question" style="margin-top: 16px; text-align: left;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.full_name')); ?> <span class="required">*</span></label>
                        <input type="text" name="submitter_name" class="form-control" required autocomplete="name">
                    </div>
                    <div class="question" style="margin-top: 16px; text-align: left;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.title')); ?> <span class="required">*</span></label>
                        <input type="text" name="submitter_title" class="form-control" required autocomplete="organization-title">
                    </div>
                    <div class="question" style="margin-top: 16px; text-align: left;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.email_address')); ?> <span class="required">*</span></label>
                        <input type="email" name="submitter_email" class="form-control" required autocomplete="email" value="<?php echo htmlspecialchars(($assessment['vendor_contact_email'] ?? '') ?: ($assessment['vendor_email'] ?? '')); ?>">
                    </div>
                    <div class="question" style="margin-top: 16px; text-align: left;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.phone_number')); ?> <span class="required">*</span></label>
                        <input type="tel" name="submitter_phone" class="form-control" required autocomplete="tel">
                    </div>
                    <label style="display:flex; align-items:flex-start; gap:8px; margin-top:16px; text-align:left; font-size:14px; line-height:1.4;">
                        <input type="checkbox" name="submitter_attested" value="1" required style="margin-top:3px; flex-shrink:0;">
                        <span><?php echo e(t('vendor-assessment.attestation_certify')); ?></span>
                    </label>

                    <button type="submit" class="btn btn-success" style="margin-top: 20px;">
                        <?php echo e(t('vendor-assessment.upload_certificate')); ?>
                    </button>
                </form>

                <div class="or-divider">
                    <span><?php echo e(t('vendor-assessment.or')); ?></span>
                </div>

                <a href="?token=<?php echo urlencode($uuid); ?>&section=0" class="btn btn-secondary">
                    <?php echo e(t('vendor-assessment.complete_full_assessment')); ?>
                </a>
            </div>

            <?php elseif ($currentSection): ?>
            <!-- Section questions -- the main questionnaire content -->
            <h2><?php echo htmlspecialchars($currentSection['name']); ?></h2>
            <?php if ($currentSection['description']): ?>
            <p class="section-desc"><?php echo htmlspecialchars($currentSection['description']); ?></p>
            <?php endif; ?>

            <form id="assessmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="assessment_uuid" value="<?php echo htmlspecialchars($uuid); ?>">
                <input type="hidden" name="section_id" value="<?php echo $currentSection['id']; ?>">

                <?php foreach ($currentQuestions as $q): ?>
                <?php
                    // Check if there's an existing response for this question
                    $response = isset($responses[$q['id']]) ? $responses[$q['id']]['response_value'] : '';
                    $questionId = 'q_' . $q['id'];
                    // Determine if question is answered (for file uploads, check file_path)
                    $hasFileResponse = !empty($responses[$q['id']]['file_path']);
                    $isAnswered = !empty($response) || $hasFileResponse;
                    // Conditional logic: check if this question should be visible
                    $isConditional = !empty($q['depends_on_question_id']);
                    $isVisible = !$isConditional || $assessmentService->isQuestionVisible($q, $responses);
                ?>
                <div class="question<?php echo $isAnswered ? ' answered' : ''; ?><?php echo ($isConditional && !$isVisible) ? ' conditional-hidden' : ''; ?>" data-question-id="<?php echo $q['id']; ?>"<?php if ($isConditional): ?> data-depends-on="<?php echo (int)$q['depends_on_question_id']; ?>" data-depends-value="<?php echo htmlspecialchars($q['depends_on_value'] ?? ''); ?>"<?php endif; ?> style="<?php echo ($isConditional && !$isVisible) ? 'display:none;' : ''; ?>">
                    <label class="question-label" for="<?php echo $questionId; ?>">
                        <?php echo htmlspecialchars($q['question_text']); ?>
                        <?php if ($q['is_required']): ?>
                        <span class="required">*</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($q['help_text']): ?>
                    <div class="question-help"><?php echo htmlspecialchars($q['help_text']); ?></div>
                    <?php endif; ?>

                    <!-- Render the right input type based on question_type -->
                    <?php if ($q['question_type'] === 'text'): ?>
                    <div class="input-wrapper">
                        <input type="text" id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                               value="<?php echo htmlspecialchars($response); ?>"
                               class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'date'): ?>
                    <div class="input-wrapper">
                        <input type="date" id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                               value="<?php echo htmlspecialchars($response); ?>"
                               class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'email'): ?>
                    <div class="input-wrapper">
                        <input type="email" id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                               value="<?php echo htmlspecialchars($response); ?>"
                               placeholder="name@example.com"
                               class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                        <span class="email-validation-msg" style="display:none; font-size: 12px; color: #ef4444; margin-top: 4px;"></span>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'number'): ?>
                    <div class="input-wrapper">
                        <input type="number" id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                               value="<?php echo htmlspecialchars($response); ?>"
                               min="0" step="any"
                               class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'textarea'): ?>
                    <div class="input-wrapper">
                        <textarea id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                                  class="autosave-input" data-qid="<?php echo $q['id']; ?>"><?php echo htmlspecialchars($response); ?></textarea>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'select'): ?>
                    <div class="input-wrapper">
                        <select id="<?php echo $questionId; ?>" name="responses[<?php echo $q['id']; ?>]"
                                class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                            <option value=""><?php echo e(t('vendor-assessment.select_option')); ?></option>
                            <?php foreach ($q['options'] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $response === $opt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'radio'): ?>
                    <div class="input-wrapper">
                        <div class="radio-group">
                            <?php foreach ($q['options'] as $opt): ?>
                            <label class="radio-option <?php echo $response === $opt ? 'selected' : ''; ?>">
                                <input type="radio" name="responses[<?php echo $q['id']; ?>]"
                                       value="<?php echo htmlspecialchars($opt); ?>"
                                       <?php echo $response === $opt ? 'checked' : ''; ?>
                                       class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                                <?php echo htmlspecialchars($opt); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'button_group'): ?>
                    <div class="input-wrapper">
                        <div class="button-group-container">
                            <?php foreach ($q['options'] as $opt): ?>
                            <label class="button-group-option <?php echo $response === $opt ? 'selected' : ''; ?>">
                                <input type="radio" name="responses[<?php echo $q['id']; ?>]"
                                       value="<?php echo htmlspecialchars($opt); ?>"
                                       <?php echo $response === $opt ? 'checked' : ''; ?>
                                       class="autosave-input" data-qid="<?php echo $q['id']; ?>">
                                <?php echo htmlspecialchars($opt); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'button_group_multi'): ?>
                    <?php $selectedOptions = $response ? json_decode($response, true) : []; if (!is_array($selectedOptions)) $selectedOptions = []; ?>
                    <div class="input-wrapper">
                        <div class="button-group-container">
                            <?php foreach ($q['options'] as $opt): ?>
                            <label class="button-group-option <?php echo in_array($opt, $selectedOptions) ? 'selected' : ''; ?>">
                                <input type="checkbox" name="responses[<?php echo $q['id']; ?>][]"
                                       value="<?php echo htmlspecialchars($opt); ?>"
                                       <?php echo in_array($opt, $selectedOptions) ? 'checked' : ''; ?>
                                       class="autosave-checkbox" data-qid="<?php echo $q['id']; ?>">
                                <?php echo htmlspecialchars($opt); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'checkbox'): ?>
                    <?php $selectedOptions = $response ? json_decode($response, true) : []; ?>
                    <div class="input-wrapper">
                        <div class="checkbox-group">
                            <?php foreach ($q['options'] as $opt): ?>
                            <label class="checkbox-option <?php echo in_array($opt, $selectedOptions) ? 'selected' : ''; ?>">
                                <input type="checkbox" name="responses[<?php echo $q['id']; ?>][]"
                                       value="<?php echo htmlspecialchars($opt); ?>"
                                       <?php echo in_array($opt, $selectedOptions) ? 'checked' : ''; ?>
                                       class="autosave-checkbox" data-qid="<?php echo $q['id']; ?>">
                                <?php echo htmlspecialchars($opt); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="question-check">&#10003;</span>
                    </div>

                    <?php elseif ($q['question_type'] === 'file'): ?>
                    <div class="input-wrapper">
                        <div class="file-upload <?php echo !empty($responses[$q['id']]['file_path']) ? 'has-file' : ''; ?>"
                             data-qid="<?php echo $q['id']; ?>">
                            <input type="file" name="file_<?php echo $q['id']; ?>" id="file_<?php echo $q['id']; ?>"
                                   class="autosave-file" data-qid="<?php echo $q['id']; ?>">
                            <div class="file-upload-icon">&#128196;</div>
                            <div class="file-upload-text">
                                <?php if (!empty($responses[$q['id']]['file_path'])): ?>
                                <strong><?php echo e(t('vendor-assessment.file_uploaded')); ?></strong> <?php echo e(t('vendor-assessment.click_to_replace')); ?>
                                <?php else: ?>
                                <strong><?php echo e(t('vendor-assessment.click_to_upload')); ?></strong> <?php echo e(t('vendor-assessment.or_drag_drop')); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="question-check">&#10003;</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Navigation: Previous / Next / Submit buttons -->
                <div class="nav-buttons">
                    <?php if ($currentSectionIndex > 0 || $assessment['allow_certificate_upload']): ?>
                    <a href="?token=<?php echo urlencode($uuid); ?>&section=<?php echo $currentSectionIndex - 1; ?>"
                       class="btn btn-secondary">
                        &larr; <?php echo e(t('vendor-assessment.previous')); ?>
                    </a>
                    <?php else: ?>
                    <div></div>
                    <?php endif; ?>

                    <?php if ($currentSectionIndex < count($sections) - 1): ?>
                    <a href="?token=<?php echo urlencode($uuid); ?>&section=<?php echo $currentSectionIndex + 1; ?>"
                       class="btn btn-primary">
                        <?php echo e(t('vendor-assessment.next')); ?> &rarr;
                    </a>
                    <?php else: ?>
                    <button type="button" id="submitAssessment" class="btn btn-success">
                        <?php echo e(t('vendor-assessment.submit_assessment')); ?> &#10003;
                    </button>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($assessment['allow_certificate_upload'] && !$assessment['certificate_uploaded'] && !$completedView): ?>
    <!-- Certificate upload modal -- shown on first visit to ask if they have an ISO cert -->
    <div class="cert-modal-overlay" id="certModalOverlay">
        <div class="cert-modal">
            <h2><?php echo e(t('vendor-assessment.modal_cert_question')); ?></h2>
            <p><?php echo e(t('vendor-assessment.modal_cert_desc')); ?></p>

            <div class="cert-modal-buttons" id="certModalButtons">
                <button type="button" class="btn btn-success" id="certYesBtn"><?php echo e(t('vendor-assessment.modal_yes_upload')); ?></button>
                <button type="button" class="btn btn-secondary" id="certNoBtn"><?php echo e(t('vendor-assessment.modal_no_complete')); ?></button>
            </div>

            <div class="cert-modal-upload" id="certModalUpload">
                <form id="modalCertificateForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="assessment_uuid" value="<?php echo htmlspecialchars($uuid); ?>">

                    <div class="file-upload" id="modalCertUploadZone" style="margin-bottom: 15px;">
                        <input type="file" name="certificate" id="modalCertificateFile" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <div class="file-upload-icon">&#128196;</div>
                        <div class="file-upload-text">
                            <strong><?php echo e(t('vendor-assessment.click_to_upload')); ?></strong> <?php echo e(t('vendor-assessment.or_drag_drop')); ?><br>
                            <small><?php echo e(t('vendor-assessment.file_types_hint')); ?></small>
                        </div>
                    </div>

                    <div class="question" style="text-align: left; margin-bottom: 15px;">
                        <label class="question-label"><?php echo e(t('vendor-assessment.cert_expiry_date_optional')); ?></label>
                        <input type="date" name="certificate_expiry" id="modalCertExpiry" style="width: 100%;">
                    </div>

                    <button type="submit" class="btn btn-success" style="width: 100%;"><?php echo e(t('vendor-assessment.upload_certificate')); ?></button>
                    <button type="button" class="btn btn-secondary" id="certBackBtn" style="width: 100%; margin-top: 10px;"><?php echo e(t('vendor-assessment.back')); ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Autosave indicator -- the little floating toast that shows save status -->
    <div class="autosave-indicator" id="autosaveIndicator">
        <span class="indicator-icon"></span>
        <span class="indicator-text"></span>
    </div>

    <!-- Submit confirmation modal -->
    <div class="incomplete-modal-overlay" id="submitConfirmOverlay">
        <div class="incomplete-modal" style="max-width: 480px;">
            <h2><?php echo e(t('vendor-assessment.submit_assessment')); ?></h2>
            <p style="color: #666; margin: 0 0 16px 0; font-size: 0.95em;"><?php echo e(t('vendor-assessment.submit_modal_desc')); ?></p>
            <div style="text-align:left; display:grid; gap:12px;">
                <div>
                    <label for="att_name" style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;"><?php echo e(t('vendor-assessment.full_name')); ?> <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="att_name" class="form-control" style="width:100%;" autocomplete="name">
                </div>
                <div>
                    <label for="att_title" style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;"><?php echo e(t('vendor-assessment.title')); ?> <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="att_title" class="form-control" style="width:100%;" autocomplete="organization-title">
                </div>
                <div>
                    <label for="att_email" style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;"><?php echo e(t('vendor-assessment.email_address')); ?> <span style="color:#ef4444;">*</span></label>
                    <input type="email" id="att_email" class="form-control" style="width:100%;" autocomplete="email" value="<?php echo htmlspecialchars(($assessment['vendor_contact_email'] ?? '') ?: ($assessment['vendor_email'] ?? '')); ?>">
                </div>
                <div>
                    <label for="att_phone" style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;"><?php echo e(t('vendor-assessment.phone_number')); ?> <span style="color:#ef4444;">*</span></label>
                    <input type="tel" id="att_phone" class="form-control" style="width:100%;" autocomplete="tel">
                </div>
                <label style="display:flex; align-items:flex-start; gap:8px; font-size:13px; line-height:1.4;">
                    <input type="checkbox" id="att_agree" style="margin-top:3px; flex-shrink:0;">
                    <span><?php echo e(t('vendor-assessment.attestation_certify_assessment')); ?></span>
                </label>
                <div id="attError" style="display:none; color:#ef4444; font-size:13px;"></div>
            </div>
            <div class="modal-footer" style="margin-top:18px;">
                <button type="button" class="btn btn-secondary" id="cancelSubmitBtn"><?php echo e(t('vendor-assessment.cancel')); ?></button>
                <button type="button" class="btn btn-success" id="confirmSubmitBtn"><?php echo e(t('vendor-assessment.submit')); ?></button>
            </div>
        </div>
    </div>

    <!-- Incomplete questions modal -->
    <div class="incomplete-modal-overlay" id="incompleteModalOverlay">
        <div class="incomplete-modal">
            <h2><?php echo e(t('vendor-assessment.required_missing_title')); ?></h2>
            <p class="modal-subtitle" id="incompleteModalSubtitle"></p>
            <div class="modal-body" id="incompleteModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="incompleteModalClose"><?php echo e(t('vendor-assessment.close')); ?></button>
                <button type="button" class="btn btn-primary" id="incompleteModalGoFirst"><?php echo e(t('vendor-assessment.go_to_first_question')); ?> &rarr;</button>
            </div>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
    // ========================================================================
    // AUTOSAVE SYSTEM
    // Every time the vendor changes a field, we save it to the server after
    // a 1-second debounce. This way they never lose their work, even if their
    // browser crashes or their cat walks across the keyboard.
    // ========================================================================
    const assessmentUUID = <?php echo json_encode($uuid); ?>;
    let csrfToken = <?php echo json_encode($csrfToken); ?>;

    // If navigated here with ?highlight=questionId, scroll to and highlight that question
    (function() {
        var params = new URLSearchParams(window.location.search);
        var highlightId = params.get("highlight");
        if (highlightId) {
            var el = document.querySelector('.question[data-question-id="' + highlightId + '"]');
            if (el) {
                el.classList.add("error");
                el.style.scrollMarginTop = "20px";
                setTimeout(function() { el.scrollIntoView({ behavior: "smooth", block: "center" }); }, 300);
            }
        }
    })();
    let autosaveTimeout = null;
    let isSaving = false;
    let autosavePromise = null; // Track in-flight autosave requests

    // Update CSRF token when a new one is returned from the server
    function updateCsrfToken(newToken) {
        if (newToken) {
            csrfToken = newToken;
            // Update hidden form fields too
            document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                input.value = newToken;
            });
        }
    }

    // Mark a question as answered or not based on its value
    function updateQuestionAnswered(questionElement, hasValue) {
        if (hasValue) {
            questionElement.classList.add('answered');
        } else {
            questionElement.classList.remove('answered');
        }
        // Clear any error state when answered
        if (hasValue) {
            questionElement.classList.remove('error');
        }
    }

    // Check if a question has a valid answer
    function checkQuestionValue(questionElement) {
        const qid = questionElement.dataset.questionId;
        const input = questionElement.querySelector('.autosave-input, .autosave-checkbox, .autosave-file');

        if (!input) return false;

        // Check based on input type
        if (input.type === 'radio') {
            const checked = questionElement.querySelector('input[type="radio"]:checked');
            return checked !== null;
        } else if (input.type === 'checkbox' || input.classList.contains('autosave-checkbox')) {
            const checked = questionElement.querySelectorAll('input[type="checkbox"]:checked');
            return checked.length > 0;
        } else if (input.classList.contains('autosave-file')) {
            const fileUpload = questionElement.querySelector('.file-upload');
            return fileUpload && fileUpload.classList.contains('has-file');
        } else {
            return input.value.trim() !== '';
        }
    }

    // Initialize answered state for all questions on page load
    function initializeAnsweredState() {
        document.querySelectorAll('.question[data-question-id]').forEach(q => {
            updateQuestionAnswered(q, checkQuestionValue(q));
        });
    }

    // Show/hide the autosave indicator toast
    function showAutosave(status, text) {
        const indicator = document.getElementById('autosaveIndicator');
        indicator.className = 'autosave-indicator show ' + status;
        indicator.querySelector('.indicator-text').textContent = text;

        if (status === 'saved') {
            setTimeout(() => {
                indicator.classList.remove('show');
            }, 2000);
        }
    }

    // Send a single question's answer to the server
    async function autosave(questionId, value) {
        if (isSaving) return;
        isSaving = true;
        showAutosave('saving', 'Saving...');

        const doSave = async () => {
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('assessment_uuid', assessmentUUID);
                formData.append('question_id', questionId);
                formData.append('value', Array.isArray(value) ? JSON.stringify(value) : value);

                const response = await fetch('/api/assessment-autosave.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    showAutosave('saved', 'Saved');
                    // Update CSRF token for next request
                    updateCsrfToken(result.csrf_token);
                    // Update answered state
                    const questionEl = document.querySelector(`.question[data-question-id="${questionId}"]`);
                    if (questionEl) {
                        const hasValue = Array.isArray(value) ? value.length > 0 : value.trim() !== '';
                        updateQuestionAnswered(questionEl, hasValue);
                    }
                } else {
                    showAutosave('error', result.message || 'Error saving');
                }
            } catch (error) {
                console.error('Autosave error:', error);
                showAutosave('error', 'Error saving');
            }

            isSaving = false;
        };

        autosavePromise = doSave();
        await autosavePromise;
        autosavePromise = null;
    }

    // Debounced version for text inputs -- wait 1 second after typing stops
    function debouncedAutosave(questionId, value) {
        clearTimeout(autosaveTimeout);
        autosaveTimeout = setTimeout(() => {
            autosave(questionId, value);
        }, 1000);
    }

    // Wire up autosave on all input fields
    document.querySelectorAll('.autosave-input').forEach(input => {
        if (input.type === 'radio') {
            // Radios save immediately on click -- no debounce needed
            input.addEventListener('change', function() {
                // Immediately update UI state (don't wait for autosave)
                const questionEl = this.closest('.question');
                if (questionEl) {
                    questionEl.classList.remove('error');
                    questionEl.classList.add('answered');
                }
                // Handle both radio-group and button-group-container
                const radioGroup = this.closest('.radio-group');
                const buttonGroup = this.closest('.button-group-container');
                if (radioGroup) {
                    radioGroup.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));
                    this.closest('.radio-option')?.classList.add('selected');
                }
                if (buttonGroup) {
                    buttonGroup.querySelectorAll('.button-group-option').forEach(opt => opt.classList.remove('selected'));
                    this.closest('.button-group-option')?.classList.add('selected');
                }
                autosave(this.dataset.qid, this.value);
            });
        } else if (input.type === 'email') {
            // Email -- validate before saving
            input.addEventListener('input', function() {
                var msgEl = this.parentElement.querySelector('.email-validation-msg');
                if (this.value && !this.validity.valid) {
                    if (msgEl) { msgEl.textContent = 'Please enter a valid email address'; msgEl.style.display = 'block'; }
                    this.style.borderColor = '#ef4444';
                } else {
                    if (msgEl) { msgEl.style.display = 'none'; }
                    this.style.borderColor = '';
                    debouncedAutosave(this.dataset.qid, this.value);
                }
            });
            input.addEventListener('change', function() {
                var msgEl = this.parentElement.querySelector('.email-validation-msg');
                if (this.value && !this.validity.valid) {
                    if (msgEl) { msgEl.textContent = 'Please enter a valid email address'; msgEl.style.display = 'block'; }
                    this.style.borderColor = '#ef4444';
                } else {
                    if (msgEl) { msgEl.style.display = 'none'; }
                    this.style.borderColor = '';
                    autosave(this.dataset.qid, this.value);
                }
            });
        } else {
            // Text/textarea/select -- debounce on input, immediate on change (blur)
            input.addEventListener('input', function() {
                debouncedAutosave(this.dataset.qid, this.value);
            });
            input.addEventListener('change', function() {
                autosave(this.dataset.qid, this.value);
            });
        }
    });

    // Checkbox autosave -- collect all checked values and save as JSON array
    document.querySelectorAll('.autosave-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const qid = this.dataset.qid;
            const questionEl = this.closest('.question');
            const checkboxes = document.querySelectorAll(`input[data-qid="${qid}"]`);
            const values = [];
            checkboxes.forEach(cb => {
                var parentLabel = cb.closest('.checkbox-option') || cb.closest('.button-group-option');
                if (parentLabel) parentLabel.classList.toggle('selected', cb.checked);
                if (cb.checked) values.push(cb.value);
            });
            // Immediately update UI state (don't wait for autosave)
            if (questionEl) {
                if (values.length > 0) {
                    questionEl.classList.remove('error');
                    questionEl.classList.add('answered');
                } else {
                    questionEl.classList.remove('answered');
                }
            }
            autosave(qid, values);
        });
    });

    // File upload zones -- click to browse or drag and drop
    document.querySelectorAll('.file-upload').forEach(zone => {
        zone.addEventListener('click', () => {
            zone.querySelector('input[type="file"]').click();
        });

        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            zone.style.borderColor = 'var(--primary-color)';
        });

        zone.addEventListener('dragleave', () => {
            zone.style.borderColor = '#d1d5db';
        });

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            zone.style.borderColor = '#d1d5db';
            const input = zone.querySelector('input[type="file"]');
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        });
    });

    // Handle file uploads via the autosave file inputs
    document.querySelectorAll('.autosave-file').forEach(input => {
        input.addEventListener('change', async function() {
            if (!this.files.length) return;

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('assessment_uuid', assessmentUUID);
            formData.append('question_id', this.dataset.qid);
            formData.append('file', this.files[0]);

            showAutosave('saving', 'Uploading...');

            try {
                const response = await fetch('/api/assessment-file-upload.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    showAutosave('saved', 'File uploaded');
                    updateCsrfToken(result.csrf_token);
                    this.closest('.file-upload').classList.add('has-file');
                    this.closest('.file-upload').querySelector('.file-upload-text').innerHTML =
                        '<strong>File uploaded</strong> - Click to replace';
                    // Update answered state
                    const questionEl = this.closest('.question');
                    if (questionEl) {
                        updateQuestionAnswered(questionEl, true);
                    }
                } else {
                    showAutosave('error', result.message || 'Upload failed');
                }
            } catch (error) {
                showAutosave('error', 'Upload failed');
            }
        });
    });

    // ========================================================================
    // CERTIFICATE FORM (the in-page version, not the modal)
    // ========================================================================
    const certForm = document.getElementById('certificateForm');
    if (certForm) {
        const certInput = document.getElementById('certificateFile');
        const certZone = document.getElementById('certUploadZone');

        certInput.addEventListener('change', function() {
            if (this.files.length) {
                certZone.classList.add('has-file');
                var ftEl = certZone.querySelector('.file-upload-text');
                ftEl.textContent = '';
                var s1 = document.createElement('strong'); s1.textContent = this.files[0].name;
                ftEl.appendChild(s1); ftEl.appendChild(document.createElement('br'));
                var sm1 = document.createElement('small'); sm1.textContent = 'Click to change';
                ftEl.appendChild(sm1);
            }
        });

        certForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            if (!certInput.files.length) {
                alert('Please select a certificate file to upload.');
                return;
            }

            showAutosave('saving', 'Uploading certificate...');

            try {
                const response = await fetch('/api/assessment-certificate-upload.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                updateCsrfToken(result.csrf_token);
                if (result.success) {
                    showAutosave('saved', 'Certificate uploaded');
                    window.location.reload();
                } else {
                    showAutosave('error', result.message || 'Upload failed');
                    alert(result.message || 'Upload failed');
                }
            } catch (error) {
                showAutosave('error', 'Upload failed');
                alert('An error occurred while uploading the certificate.');
            }
        });
    }

    // ========================================================================
    // SAVE ALL RESPONSES
    // Called before navigating away or submitting -- makes sure everything
    // on the current page is saved. Belt AND suspenders.
    // ========================================================================
    async function saveAllResponses() {
        // Set isSaving to prevent concurrent autosave from firing
        isSaving = true;

        const inputs = document.querySelectorAll('.autosave-input, .autosave-checkbox');

        // Collect all unique question IDs and their current values
        const responses = {};
        inputs.forEach(input => {
            const qid = input.dataset.qid;
            if (!qid) return;

            if (input.type === 'checkbox') {
                if (!responses[qid]) responses[qid] = [];
                if (input.checked) responses[qid].push(input.value);
            } else if (input.type === 'radio') {
                if (input.checked) responses[qid] = input.value;
            } else {
                responses[qid] = input.value;
            }
        });

        // Save sequentially so each request uses the freshly rotated CSRF token
        for (const [qid, value] of Object.entries(responses)) {
            if (value === undefined || value === null) continue;
            if (Array.isArray(value) && value.length === 0) continue;

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('assessment_uuid', assessmentUUID);
            formData.append('question_id', qid);
            formData.append('value', Array.isArray(value) ? JSON.stringify(value) : value);

            try {
                const response = await fetch('/api/assessment-autosave.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.csrf_token) {
                    updateCsrfToken(result.csrf_token);
                }
            } catch (err) {
                console.error('Save error for question ' + qid, err);
            }
        }

        isSaving = false;
    }

    // Save before navigating to next/previous section -- don't lose anything
    document.querySelectorAll('.nav-buttons a').forEach(link => {
        link.addEventListener('click', async function(e) {
            e.preventDefault();
            clearTimeout(autosaveTimeout);
            showAutosave('saving', 'Saving...');
            await saveAllResponses();
            showAutosave('saved', 'Saved');
            window.location.href = this.href;
        });
    });

    // ========================================================================
    // FINAL SUBMISSION
    // The big moment -- save everything, then tell the server we're done.
    // No take-backs after this one.
    // ========================================================================
    // Validate the submitter attestation fields shown in the submit modal.
    function validateAttestation() {
        var name = (document.getElementById('att_name').value || '').trim();
        var title = (document.getElementById('att_title').value || '').trim();
        var email = (document.getElementById('att_email').value || '').trim();
        var phone = (document.getElementById('att_phone').value || '').trim();
        var agree = document.getElementById('att_agree').checked;
        var err = document.getElementById('attError');
        if (!name || !title || !email || !phone) {
            err.textContent = 'Please complete your name, title, email, and phone number.';
            err.style.display = 'block';
            return false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            err.textContent = 'Please enter a valid email address.';
            err.style.display = 'block';
            return false;
        }
        if (!agree) {
            err.textContent = 'Please confirm the truthfulness attestation to submit.';
            err.style.display = 'block';
            return false;
        }
        err.style.display = 'none';
        return true;
    }

    async function doFinalSubmit() {
        // Clear any previous error highlighting
        document.querySelectorAll('.question.error').forEach(q => q.classList.remove('error'));

        // Validate email fields before submitting
        var invalidEmails = document.querySelectorAll('input[type="email"].autosave-input:invalid');
        if (invalidEmails.length > 0) {
            invalidEmails.forEach(function(el) {
                var questionEl = el.closest('.question');
                if (questionEl) questionEl.classList.add('error');
                el.style.borderColor = '#ef4444';
                var msgEl = el.parentElement.querySelector('.email-validation-msg');
                if (msgEl) { msgEl.textContent = 'Please enter a valid email address'; msgEl.style.display = 'block'; }
            });
            invalidEmails[0].closest('.question')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            alert('Please fix invalid email addresses before submitting.');
            return;
        }

        // Cancel any pending debounced autosave
        clearTimeout(autosaveTimeout);

        // Wait for any in-flight autosave to finish before proceeding
        if (autosavePromise) {
            await autosavePromise;
        }

        showAutosave('saving', 'Saving responses...');

        // Save all responses first, then submit
        await saveAllResponses();

        showAutosave('saving', 'Submitting...');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('assessment_uuid', assessmentUUID);
            formData.append('submitter_name', (document.getElementById('att_name').value || '').trim());
            formData.append('submitter_title', (document.getElementById('att_title').value || '').trim());
            formData.append('submitter_email', (document.getElementById('att_email').value || '').trim());
            formData.append('submitter_phone', (document.getElementById('att_phone').value || '').trim());
            formData.append('submitter_attested', document.getElementById('att_agree').checked ? '1' : '0');

            const response = await fetch('/api/assessment-submit.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            // Update CSRF token regardless of success/failure
            updateCsrfToken(result.csrf_token);

            if (result.success) {
                showAutosave('saved', 'Submitted!');
                window.location.reload();
            } else if (result.unanswered && result.unanswered.length > 0) {
                showAutosave('error', 'Incomplete');
                showIncompleteModal(result);
            } else {
                showAutosave('error', 'Error');
                alert(result.message || 'An error occurred while submitting the assessment.');
            }
        } catch (error) {
            showAutosave('error', 'Submission failed');
            alert('An error occurred while submitting the assessment.');
        }
    }

    // Show confirmation modal when submit button is clicked
    const submitBtn = document.getElementById('submitAssessment');
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            document.getElementById('submitConfirmOverlay').classList.add('show');
        });
    }

    // Confirmation modal buttons
    const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', function() {
            if (!validateAttestation()) return;
            document.getElementById('submitConfirmOverlay').classList.remove('show');
            doFinalSubmit();
        });
    }
    const cancelSubmitBtn = document.getElementById('cancelSubmitBtn');
    if (cancelSubmitBtn) {
        cancelSubmitBtn.addEventListener('click', function() {
            document.getElementById('submitConfirmOverlay').classList.remove('show');
        });
    }

    // ========================================================================
    // INCOMPLETE QUESTIONS MODAL
    // Shows a friendly modal listing all unanswered required questions,
    // grouped by section, with clickable links to navigate directly there.
    // ========================================================================
    function showIncompleteModal(result) {
        var overlay = document.getElementById("incompleteModalOverlay");
        var body = document.getElementById("incompleteModalBody");
        var subtitle = document.getElementById("incompleteModalSubtitle");
        var goFirstBtn = document.getElementById("incompleteModalGoFirst");

        var unanswered = result.unanswered || [];
        var totalMissing = unanswered.length;

        subtitle.textContent = totalMissing + " required question" + (totalMissing !== 1 ? "s" : "") + " still need" + (totalMissing === 1 ? "s" : "") + " an answer.";

        // Group by section
        var groups = {};
        unanswered.forEach(function(q) {
            if (!groups[q.section_name]) {
                groups[q.section_name] = { index: q.section_index, questions: [] };
            }
            groups[q.section_name].questions.push(q);
        });

        var html = "";
        for (var sectionName in groups) {
            var group = groups[sectionName];
            html += '<div class="section-group">';
            html += '<div class="section-group-header">' + sectionName + ' (' + group.questions.length + ' remaining)</div>';
            group.questions.forEach(function(q) {
                html += '<a class="question-link" data-section="' + q.section_index + '" data-qid="' + q.id + '">' + q.text + '</a>';
            });
            html += '</div>';
        }
        body.innerHTML = html;

        // Set up "Go to First Question" button
        if (unanswered.length > 0) {
            var first = unanswered[0];
            goFirstBtn.style.display = "";
            goFirstBtn.onclick = function() {
                window.location.href = "?token=" + encodeURIComponent(assessmentUUID) + "&section=" + first.section_index + "&highlight=" + first.id;
            };
        } else {
            goFirstBtn.style.display = "none";
        }

        // Click handler for question links
        body.querySelectorAll(".question-link").forEach(function(link) {
            link.addEventListener("click", function() {
                var sectionIdx = this.getAttribute("data-section");
                var qid = this.getAttribute("data-qid");
                window.location.href = "?token=" + encodeURIComponent(assessmentUUID) + "&section=" + sectionIdx + "&highlight=" + qid;
            });
        });

        // Close button and backdrop click handlers
        document.getElementById("incompleteModalClose").onclick = function() {
            overlay.classList.remove("show");
        };
        overlay.onclick = function(e) {
            if (e.target === overlay) overlay.classList.remove("show");
        };

        overlay.classList.add("show");
    }



        // ========================================================================
    // CERTIFICATE MODAL
    // On first visit, we pop up a modal asking "got an ISO cert?" If yes,
    // they can upload it right there. If no, we dismiss and let them do
    // the full questionnaire. We remember their choice in sessionStorage.
    // ========================================================================
    const certModalOverlay = document.getElementById('certModalOverlay');
    if (certModalOverlay) {
        const certYesBtn = document.getElementById('certYesBtn');
        const certNoBtn = document.getElementById('certNoBtn');
        const certBackBtn = document.getElementById('certBackBtn');
        const certModalButtons = document.getElementById('certModalButtons');
        const certModalUpload = document.getElementById('certModalUpload');
        const modalCertForm = document.getElementById('modalCertificateForm');
        const modalCertInput = document.getElementById('modalCertificateFile');
        const modalCertZone = document.getElementById('modalCertUploadZone');

        // Only show the modal if they haven't already dismissed it
        const certPromptKey = 'certPromptDismissed_' + assessmentUUID;
        if (!sessionStorage.getItem(certPromptKey)) {
            certModalOverlay.classList.add('show');
        }

        certYesBtn.addEventListener('click', () => {
            certModalButtons.style.display = 'none';
            certModalUpload.classList.add('show');
        });

        certNoBtn.addEventListener('click', () => {
            sessionStorage.setItem(certPromptKey, 'true');
            certModalOverlay.classList.remove('show');
        });

        certBackBtn.addEventListener('click', () => {
            certModalButtons.style.display = 'flex';
            certModalUpload.classList.remove('show');
        });

        modalCertInput.addEventListener('change', function() {
            if (this.files.length) {
                modalCertZone.classList.add('has-file');
                var ftEl2 = modalCertZone.querySelector('.file-upload-text');
                ftEl2.textContent = '';
                var s2 = document.createElement('strong'); s2.textContent = this.files[0].name;
                ftEl2.appendChild(s2); ftEl2.appendChild(document.createElement('br'));
                var sm2 = document.createElement('small'); sm2.textContent = 'Click to change';
                ftEl2.appendChild(sm2);
            }
        });

        modalCertForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if (!modalCertInput.files.length) {
                alert('Please select a certificate file to upload.');
                return;
            }

            const formData = new FormData(this);
            showAutosave('saving', 'Uploading certificate...');

            try {
                const response = await fetch('/api/assessment-certificate-upload.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                updateCsrfToken(result.csrf_token);
                if (result.success) {
                    showAutosave('saved', 'Certificate uploaded');
                    alert('Thank you! Your ISO 27001:2022 certificate has been uploaded successfully. The assessment is now complete.');
                    window.location.reload();
                } else {
                    showAutosave('error', result.message || 'Upload failed');
                    alert(result.message || 'Upload failed');
                }
            } catch (error) {
                showAutosave('error', 'Upload failed');
                alert('An error occurred while uploading the certificate.');
            }
        });
    }

    // Pre-fill the company legal name field with the vendor name if it's empty.
    // Small convenience that saves the vendor a few keystrokes.
    const vendorName = <?php echo json_encode($assessment['vendor_name']); ?>;
    document.querySelectorAll('input[type="text"].autosave-input').forEach(input => {
        const label = input.closest('.question')?.querySelector('.question-label');
        if (label && label.textContent.toLowerCase().includes('legal name') && !input.value) {
            input.value = vendorName;
        }
    });

    // ========================================================================
    // CONDITIONAL LOGIC
    // Show/hide questions based on parent question values.
    // ========================================================================
    function evaluateConditionalQuestions() {
        document.querySelectorAll('.question[data-depends-on]').forEach(function(el) {
            var parentId = el.dataset.dependsOn;
            var requiredValue = el.dataset.dependsValue || '';
            var parentQuestion = document.querySelector('.question[data-question-id="' + parentId + '"]');
            var parentValue = '';

            if (!parentQuestion) {
                // Parent is on a different section -- trust the server-side
                // rendering which already checked the saved response in the DB
                return;
            }
            if (parentQuestion) {
                // Get the current value of the parent question
                var radio = parentQuestion.querySelector('input[type="radio"]:checked');
                if (radio) {
                    parentValue = radio.value;
                } else {
                    var select = parentQuestion.querySelector('select.autosave-input');
                    if (select) {
                        parentValue = select.value;
                    } else {
                        var textInput = parentQuestion.querySelector('input[type="text"].autosave-input, input[type="number"].autosave-input, input[type="date"].autosave-input, input[type="email"].autosave-input, textarea.autosave-input');
                        if (textInput) {
                            parentValue = textInput.value;
                        } else {
                            // Check checkboxes
                            var checked = parentQuestion.querySelectorAll('input[type="checkbox"]:checked');
                            if (checked.length > 0) {
                                var vals = [];
                                checked.forEach(function(cb) { vals.push(cb.value); });
                                // For checkboxes, check if required value is among checked values
                                if (vals.indexOf(requiredValue) !== -1) {
                                    parentValue = requiredValue;
                                }
                            }
                        }
                    }
                }
            }

            var shouldShow = (parentValue === requiredValue);
            el.style.display = shouldShow ? '' : 'none';
            if (shouldShow) {
                el.classList.remove('conditional-hidden');
            } else {
                el.classList.add('conditional-hidden');
            }
        });
    }

    // Evaluate on page load
    evaluateConditionalQuestions();

    // Re-evaluate when any input changes
    document.querySelectorAll('.autosave-input, .autosave-checkbox').forEach(function(input) {
        input.addEventListener('change', function() {
            setTimeout(evaluateConditionalQuestions, 50);
        });
        if (input.type !== 'radio' && input.type !== 'checkbox') {
            input.addEventListener('input', function() {
                setTimeout(evaluateConditionalQuestions, 50);
            });
        }
    });

    // Initialize answered state on page load
    initializeAnsweredState();
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>

    <footer class="assessment-footer">
        <div class="assessment-footer-body">
            <div class="assessment-footer-brand">
                <?php if (!empty($theme['footer_logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($theme['footer_logo_url']); ?>" alt="Logo">
                <?php endif; ?>
            </div>
            <div>
                <?php if ($companyName): ?>
                <span><?php echo htmlspecialchars($companyName); ?> &copy; <?php echo date('Y'); ?>. All Rights Reserved</span>
                <?php endif; ?>
            </div>
        </div>
    </footer>

</body>
</html>
