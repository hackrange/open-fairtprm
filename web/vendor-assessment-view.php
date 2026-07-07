<?php
/**
 * Vendor Assessment View - The Admin's Crystal Ball
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the admin-side read-only view of a vendor's assessment responses.
 * Think of it as the teacher grading the test -- you can see everything the
 * vendor submitted, section by section, question by question. It shows summary
 * cards with status, completion percentage, timestamps, and whether the vendor
 * took the shortcut by uploading an ISO cert instead of answering 50 questions.
 * Access is locked down to admins, cyber TPRM, and procurement groups.
 */

// Standard init -- gotta log in and have the right permissions for this one
require_once 'includes/init.php';
requireAuth();

// Grab our trusty singletons
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// ============================================================================
// PERMISSION CHECK
// Only admins, cyber TPRM folks, and procurement can view assessments.
// Everyone else gets the boot. Sorry, stakeholders.
// ============================================================================
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isProcurement && !$isAuditor) {
    http_response_code(403);
    die(e(t('vendor-assessment-view.access_denied')));
}

// Load the assessment service
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// Get the assessment ID from the URL -- if it's missing or invalid, redirect
$assessmentId = intval($_GET['id'] ?? 0);
if (!$assessmentId) {
    header('Location: vendor-assessments.php');
    exit;
}

// Fetch the assessment. If it doesn't exist, back to the list you go.
$assessment = $assessmentService->getAssessmentById($assessmentId);
if (!$assessment) {
    header('Location: vendor-assessments.php');
    exit;
}

// Load sections, responses, and completion status for this assessment
$sections = $assessmentService->getSections($assessment['template_id']);
// Onboarding role visibility: hide role-restricted sections from this reviewer.
$avViewerGroups = ACL::getInstance()->getUserGroups();
$avViewerSuper = (bool)$session->get('is_super_admin');
$avCustomMaps = $assessmentService->getOnboardingCustomMaps($assessment['template_id']);
$sections = array_values(array_filter($sections, function($s) use ($avViewerGroups, $avViewerSuper, $avCustomMaps) {
    return VendorAssessmentService::roleCanSee($s['visible_roles'] ?? null, $s['editable_roles'] ?? null, $avViewerGroups, $avViewerSuper, isset($avCustomMaps['sections'][(int)$s['id']]));
}));
$responses = $assessmentService->getResponses($assessment['id']);
$completionStatus = $assessmentService->getCompletionStatus($assessment['id']);

// Figure out if this assessment is expired (but completed ones are fine)
$isExpired = $assessment['expires_at'] && strtotime($assessment['expires_at']) < time() && $assessment['status'] !== 'completed';

// Build a nice display name for the page title
$assessmentName = $assessment['vendor_name'] . ' - ' . date('M j, Y', strtotime($assessment['created_at']));

// CSRF token in case we need it for any actions
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e($assessmentName); ?> - Assessment View</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }

        /* Top header bar with assessment name and status badge */
        .header {
            background: white;
            border-bottom: 3px solid var(--theme-header-color);
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 { font-size: 20px; margin: 0; color: #333; }
        .header-meta { color: #666; font-size: 13px; }

        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }

        /* Back link -- the escape hatch */
        .back-link {
            display: inline-flex; align-items: center; gap: 5px;
            color: var(--theme-header-color); text-decoration: none;
            font-size: 14px; margin-bottom: 20px;
        }
        .back-link:hover { text-decoration: underline; }

        /* Summary cards -- at-a-glance info about the assessment */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .summary-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
        }
        .summary-card label {
            display: block;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .summary-card .value {
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }

        /* Status badges -- color-coded for your viewing pleasure */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-in-progress { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-expired { background: #fef2f2; color: #991b1b; }

        /* Progress bar in the summary cards */
        .progress-bar {
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.3s;
        }

        /* Section cards -- each one holds a group of questions and responses */
        .section-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }
        .section-status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
        }
        .section-complete { background: #dcfce7; color: #166534; }
        .section-incomplete { background: #fef3c7; color: #92400e; }

        .section-body { padding: 20px; }

        /* Individual question/response pairs */
        .question-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        .question-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .question-text {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .question-text .required { color: #ef4444; }
        .response-value {
            background: #f9fafb;
            padding: 12px 15px;
            border-radius: 6px;
            color: #333;
            font-size: 14px;
        }
        .response-value.empty {
            color: #999;
            font-style: italic;
        }
        .response-file a {
            color: var(--theme-header-color);
            text-decoration: none;
        }
        .response-file a:hover { text-decoration: underline; }

        /* Special section for when the vendor uploaded an ISO certificate */
        .certificate-section {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: center;
        }
        .certificate-section h3 {
            color: #166534;
            margin: 0 0 10px 0;
        }
        .certificate-section a {
            color: #166534;
            font-weight: 500;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: var(--theme-header-color); color: white; }

        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .summary-cards { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>

    <!-- Page header with assessment name, vendor email, and status -->
    <div class="header">
        <div>
            <h1><?php echo e($assessmentName); ?></h1>
            <div class="header-meta">
                <?php echo e($assessment['template_name']); ?> |
                <?php echo e($assessment['vendor_email']); ?>
                <?php if ($assessment['vendor_contact_name']): ?>
                | Contact: <?php echo e($assessment['vendor_contact_name']); ?>
                <?php if ($assessment['vendor_contact_email']): ?>
                    (<?php echo e($assessment['vendor_contact_email']); ?>)
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <span class="badge badge-<?php echo $isExpired ? 'expired' : $assessment['status']; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $isExpired ? 'expired' : $assessment['status'])); ?>
            </span>
        </div>
    </div>

    <div class="container">
        <a href="vendor-assessments.php" class="back-link">&larr; <?php echo e(t('vendor-assessment-view.back_to_assessments')); ?></a>

        <!-- Summary Cards -- the TL;DR section -->
        <div class="summary-cards">
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.status')); ?></label>
                <span class="badge badge-<?php echo $isExpired ? 'expired' : $assessment['status']; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $isExpired ? 'expired' : $assessment['status'])); ?>
                </span>
            </div>
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.completion')); ?></label>
                <div class="value"><?php echo $completionStatus['percentage']; ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $completionStatus['percentage']; ?>%; background: <?php echo $completionStatus['percentage'] == 100 ? '#22c55e' : '#3b82f6'; ?>;"></div>
                </div>
            </div>
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.created')); ?></label>
                <div class="value"><?php echo date('M j, Y', strtotime($assessment['created_at'])); ?></div>
            </div>
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.expires')); ?></label>
                <div class="value" style="<?php echo $isExpired ? 'color:#ef4444;' : ''; ?>">
                    <?php echo $assessment['expires_at'] ? date('M j, Y', strtotime($assessment['expires_at'])) : e(t('vendor-assessment-view.never')); ?>
                </div>
            </div>
            <?php if ($assessment['started_at']): ?>
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.started')); ?></label>
                <div class="value"><?php echo date('M j, Y g:i A', strtotime($assessment['started_at'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($assessment['completed_at']): ?>
            <div class="summary-card">
                <label><?php echo e(t('vendor-assessment-view.completed')); ?></label>
                <div class="value"><?php echo date('M j, Y g:i A', strtotime($assessment['completed_at'])); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin || $isCyberTPRM): ?>
        <!-- Submitter attestation -- recorded at final submission. Admin + cyber TPRM only. -->
        <div class="attestation-panel" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 20px; margin-bottom:24px;">
            <h3 style="margin:0 0 12px 0; font-size:1.05em; display:flex; align-items:center; gap:8px;">
                <?php echo e(t('vendor-assessment-view.submitter_attestation')); ?>
                <?php if (!empty($assessment['submitter_attested'])): ?>
                <span class="badge badge-completed" style="font-size:0.7em;"><?php echo e(t('vendor-assessment-view.attested')); ?></span>
                <?php endif; ?>
            </h3>
            <?php if (!empty($assessment['submitter_name']) || !empty($assessment['submitter_attested'])): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px 24px;">
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_name')); ?></label><div><?php echo e($assessment['submitter_name'] ?: '—'); ?></div></div>
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_title')); ?></label><div><?php echo e($assessment['submitter_title'] ?: '—'); ?></div></div>
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_email')); ?></label><div><?php echo e($assessment['submitter_email'] ?: '—'); ?></div></div>
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_phone')); ?></label><div><?php echo e($assessment['submitter_phone'] ?: '—'); ?></div></div>
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_ip')); ?></label><div><?php echo e($assessment['submitter_ip_address'] ?: '—'); ?></div></div>
                <div><label style="display:block; font-size:12px; color:#6b7280;"><?php echo e(t('vendor-assessment-view.att_attested_at')); ?></label><div><?php echo $assessment['attested_at'] ? e(date('M j, Y g:i A', strtotime($assessment['attested_at']))) : '—'; ?></div></div>
            </div>
            <p style="margin:14px 0 0 0; font-size:13px; color:#374151;">
                <?php if (!empty($assessment['submitter_attested'])): ?>
                &#10003; <?php echo e(t('vendor-assessment-view.attestation_certified')); ?>
                <?php else: ?>
                <?php echo e(t('vendor-assessment-view.attestation_not_confirmed')); ?>
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p style="margin:0; color:#6b7280; font-size:13px;"><?php echo e(t('vendor-assessment-view.no_attestation')); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        // Check AI availability and cert doc count for auto-fill button
        $showAiAutofill = false;
        if (($isAdmin || $isCyberTPRM) && $assessment['vendor_request_id'] && $assessment['status'] !== 'completed') {
            if (AIPlatformService::getInstance()->isEnabled()) {
                $certDocCount = $db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM vendor_documents
                     WHERE vendor_request_id = :id AND document_type = 'certification'
                       AND is_active = 1
                       AND mime_type IN ('application/pdf', 'text/csv', 'application/vnd.ms-excel',
                                         'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                       AND (certification_expiration_date IS NULL OR certification_expiration_date >= CURDATE())",
                    [':id' => $assessment['vendor_request_id']]
                );
                $showAiAutofill = ($certDocCount && $certDocCount['cnt'] > 0);
            }
        }
        ?>
        <?php
        // Import-from-PDF is available whenever the assessment is not yet at
        // 100% completion and not yet marked completed. The endpoint will also
        // re-verify the PDF Reference against this assessment's UUID before
        // writing anything.
        $showImportPdf = !$isAuditor
            && $assessment['status'] !== 'completed'
            && (int)($completionStatus['percentage'] ?? 0) < 100;
        ?>
        <!-- Download the assessment as a fillable PDF or Excel workbook. Both
             carry this assessment's Reference so the unified importer below can
             match answers back. Available to anyone who can view the page. -->
        <div style="margin-bottom: 12px; text-align: right; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
            <a href="api/assessment-download-pdf.php?assessment_id=<?php echo (int)$assessment['id']; ?>"
               class="btn" style="background:#b91c1c; color:white; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration:none; display: inline-flex; align-items: center; gap: 8px;">
                <?php echo e(t('vendor-assessment-view.download_pdf')); ?>
            </a>
            <a href="api/assessment-download-xlsx.php?assessment_id=<?php echo (int)$assessment['id']; ?>"
               class="btn" style="background:#15803d; color:white; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; text-decoration:none; display: inline-flex; align-items: center; gap: 8px;">
                <?php echo e(t('vendor-assessment-view.download_excel')); ?>
            </a>
        </div>

        <?php if ($showAiAutofill || $showImportPdf): ?>
        <div style="margin-bottom: 20px; text-align: right; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
            <?php if ($showAiAutofill): ?>
            <button type="button" id="aiAutofillBtn" data-action="aiAutofillAssessment"
                    class="btn" style="background: var(--theme-header-color, #2563eb); color: white; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px;">
                <img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> <?php echo e(t('vendor-assessment-view.autofill_button')); ?>
            </button>
            <?php endif; ?>

            <?php if ($showImportPdf): ?>
            <button type="button" id="importPdfBtn"
                    class="btn" style="background:#0f766e; color:white; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px;"
                    title="<?php echo e(t('vendor-assessment-view.import_assessment_title')); ?>">
                <?php echo e(t('vendor-assessment-view.import_assessment')); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php if ($showAiAutofill): ?>
        <div id="aiAutofillStatus" style="display:none; margin-top: 8px; font-size: 13px; text-align:right;"></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($showImportPdf): ?>
        <!-- Import PDF modal -->
        <div id="importPdfModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
            <div style="background:white; padding:24px; border-radius:8px; max-width:520px; width:90%; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <h3 style="margin:0; color:#333; font-size:18px;"><?php echo e(t('vendor-assessment-view.import_assessment')); ?></h3>
                    <button type="button" id="importPdfCloseX" style="background:none; border:none; font-size:22px; cursor:pointer; color:#999; line-height:1;">&times;</button>
                </div>
                <p style="margin:0 0 12px; color:#555; font-size:14px;">
                    <?php echo e(t('vendor-assessment-view.import_assessment_desc')); ?>
                </p>
                <form id="importPdfForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="assessment_id" value="<?php echo (int)$assessment['id']; ?>">
                    <input type="file" name="file" accept=".pdf,.xlsx,.csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                           style="display:block; width:100%; padding:10px; border:1px dashed #d1d5db; border-radius:6px; background:#f9fafb; font-size:13px;">
                    <div id="importPdfStatus" style="display:none; margin-top:12px; padding:10px; border-radius:4px; font-size:13px;"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
                        <button type="button" id="importPdfCancelBtn" class="btn btn-secondary" style="padding:8px 18px;"><?php echo e(t('vendor-assessment-view.cancel')); ?></button>
                        <button type="submit" id="importPdfSubmitBtn" class="btn btn-primary" style="padding:8px 18px;"><?php echo e(t('vendor-assessment-view.import')); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($assessment['certificate_uploaded']): ?>
        <!-- Certificate section -- vendor took the express lane with their ISO cert -->
        <div class="certificate-section">
            <h3>&#9989; <?php echo e(t('vendor-assessment-view.cert_uploaded_title')); ?></h3>
            <p><?php echo e(t('vendor-assessment-view.cert_uploaded_desc')); ?></p>
            <?php if ($assessment['certificate_path']): ?>
            <p><a href="api/assessment-file-download.php?assessment_id=<?php echo $assessment['id']; ?>&file=<?php echo urlencode($assessment['certificate_path']); ?>" target="_blank"><?php echo e(t('vendor-assessment-view.view_certificate')); ?></a></p>
            <?php endif; ?>
            <?php if ($assessment['certificate_expiry']): ?>
            <p><?php echo e(t('vendor-assessment-view.cert_expiry', date('M j, Y', strtotime($assessment['certificate_expiry'])))); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Sections and Responses -- the meat of the assessment view -->
        <?php foreach ($sections as $section): ?>
        <?php
            // Load questions for this section and check completion
            $questions = $assessmentService->getQuestions($section['id']);
            // Drop role-restricted questions this reviewer can't see.
            $questions = array_values(array_filter($questions, function($q) use ($avViewerGroups, $avViewerSuper, $avCustomMaps) {
                return VendorAssessmentService::roleCanSee($q['visible_roles'] ?? null, $q['editable_roles'] ?? null, $avViewerGroups, $avViewerSuper, isset($avCustomMaps['questions'][(int)$q['id']]));
            }));
            $sectionStatus = $completionStatus['sections'][$section['id']] ?? ['complete' => false];
        ?>
        <div class="section-card">
            <div class="section-header">
                <h3><?php echo e($section['name']); ?></h3>
                <span class="section-status <?php echo $sectionStatus['complete'] ? 'section-complete' : 'section-incomplete'; ?>">
                    <?php echo $sectionStatus['complete'] ? e(t('vendor-assessment-view.complete')) : e(t('vendor-assessment-view.incomplete')); ?>
                </span>
            </div>
            <div class="section-body">
                <?php foreach ($questions as $q): ?>
                <?php
                    // Get the response for this question, handling different types
                    $response = $responses[$q['id']] ?? null;
                    $value = $response ? $response['response_value'] : '';
                    $filePath = $response ? $response['file_path'] : '';

                    // Checkboxes are stored as JSON arrays -- decode for display
                    if (in_array($q['question_type'], ['checkbox', 'button_group_multi']) && $value) {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            $value = implode(', ', $decoded);
                        }
                    }
                ?>
                <div class="question-item">
                    <div class="question-text">
                        <?php echo e($q['question_text']); ?>
                        <?php if ($q['is_required']): ?><span class="required">*</span><?php endif; ?>
                    </div>
                    <?php if ($q['question_type'] === 'file'): ?>
                    <!-- File type question -- show download link or "no file" -->
                    <div class="response-value <?php echo empty($filePath) ? 'empty' : ''; ?>">
                        <?php if ($filePath): ?>
                        <span class="response-file">
                            <a href="api/assessment-file-download.php?assessment_id=<?php echo $assessment['id']; ?>&file=<?php echo urlencode($filePath); ?>" target="_blank"><?php echo e($value ?: t('vendor-assessment-view.view_file')); ?></a>
                        </span>
                        <?php else: ?>
                        <?php echo e(t('vendor-assessment-view.no_file_uploaded')); ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Text/select/radio/checkbox response -->
                    <div class="response-value <?php echo empty($value) ? 'empty' : ''; ?>">
                        <?php echo !empty($value) ? nl2br(e($value)) : e(t('vendor-assessment-view.no_response')); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php if ($showAiAutofill): ?>
    <script nonce="<?php echo cspNonce(); ?>">
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    window.aiAutofillAssessment = function() {
        if (!confirm('This will analyze the vendor\'s certification documents and pre-fill unanswered questions. Existing answers will not be changed. Continue?')) return;

        var btn = document.getElementById('aiAutofillBtn');
        var status = document.getElementById('aiAutofillStatus');
        btn.disabled = true;
        btn.innerHTML = '\u23F3 Analyzing documents...';
        status.style.display = 'block';
        status.style.color = '#6b7280';
        status.textContent = 'Analyzing certification documents (PDF, CSV, Excel). This may take a minute...';

        fetch('api/assessment-ai-autofill.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: csrfToken,
                assessment_id: <?php echo $assessment['id']; ?>
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) csrfToken = data.csrf_token;
            if (data.error) {
                btn.disabled = false;
                btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
                status.style.color = '#dc2626';
                status.textContent = data.error || 'Auto-fill failed.';
                return;
            }
            if (data.queued && data.job_id) {
                status.textContent = 'Analyzing certification documents. This may take a minute...';
                var pollInterval = setInterval(function() {
                    fetch('api/ai-job-status.php?id=' + data.job_id)
                    .then(function(r) { return r.json(); })
                    .then(function(poll) {
                        if (poll.csrf_token) csrfToken = poll.csrf_token;
                        if (poll.status === 'completed' && poll.result) {
                            clearInterval(pollInterval);
                            // Apply the validated answers to the assessment
                            fetch('api/assessment-ai-apply.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ csrf_token: csrfToken, job_id: data.job_id })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(applyData) {
                                if (applyData.csrf_token) csrfToken = applyData.csrf_token;
                                if (applyData.success) {
                                    status.style.color = '#16a34a';
                                    status.textContent = 'Filled ' + applyData.filled + ' of ' + applyData.total + ' unanswered questions. Reloading...';
                                    setTimeout(function() { location.reload(); }, 1500);
                                } else {
                                    btn.disabled = false;
                                    btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
                                    status.style.color = '#dc2626';
                                    status.textContent = applyData.error || 'Failed to apply answers.';
                                }
                            })
                            .catch(function() {
                                btn.disabled = false;
                                btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
                                status.style.color = '#dc2626';
                                status.textContent = 'Network error applying answers. Please try again.';
                            });
                        } else if (poll.status === 'failed') {
                            clearInterval(pollInterval);
                            btn.disabled = false;
                            btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
                            status.style.color = '#dc2626';
                            status.textContent = poll.error || 'Processing failed. Please try again.';
                        }
                    })
                    .catch(function() {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
                        status.style.color = '#dc2626';
                        status.textContent = 'Network error. Please try again.';
                    });
                }, 3000);
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" style="width: 16px; height: 16px; filter: brightness(0) invert(1);"> Auto-Fill from Certifications';
            status.style.display = 'block';
            status.style.color = '#dc2626';
            status.textContent = 'Network error. Please try again.';
        });
    };
    </script>
    <?php endif; ?>

    <?php if ($showImportPdf): ?>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var modal   = document.getElementById('importPdfModal');
        var btn     = document.getElementById('importPdfBtn');
        var closeX  = document.getElementById('importPdfCloseX');
        var cancel  = document.getElementById('importPdfCancelBtn');
        var form    = document.getElementById('importPdfForm');
        var submit  = document.getElementById('importPdfSubmitBtn');
        var status  = document.getElementById('importPdfStatus');
        if (!modal || !btn || !form) return;

        function open()  { status.style.display = 'none'; form.reset(); modal.style.display = 'flex'; }
        function close() { modal.style.display = 'none'; }

        btn.addEventListener('click', open);
        closeX.addEventListener('click', close);
        cancel.addEventListener('click', close);
        modal.addEventListener('click', function(e) { if (e.target === modal) close(); });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submit.disabled = true;
            submit.textContent = 'Importing...';
            status.style.display = 'block';
            status.style.background = '#eff6ff';
            status.style.color = '#1e40af';
            status.textContent = 'Reading the file and matching answers...';

            var data = new FormData(form);
            fetch('api/assessment-import.php', { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (json && json.success) {
                        status.style.background = '#f0fdf4';
                        status.style.color = '#166534';
                        status.textContent = json.message || 'Imported successfully.';
                        setTimeout(function() { window.location.reload(); }, 1200);
                    } else {
                        status.style.background = '#fef2f2';
                        status.style.color = '#991b1b';
                        status.textContent = (json && json.message) || 'Import failed.';
                        submit.disabled = false;
                        submit.textContent = 'Import';
                    }
                })
                .catch(function() {
                    status.style.background = '#fef2f2';
                    status.style.color = '#991b1b';
                    status.textContent = 'Network error during import.';
                    submit.disabled = false;
                    submit.textContent = 'Import';
                });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
