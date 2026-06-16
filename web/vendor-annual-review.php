<?php
/**
 * Vendor Annual Review Form - The Yearly Interrogation
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The actual review form where stakeholders answer the important questions
 * about their vendors once a year. It's basically a wellness check for vendor
 * relationships -- "Are you still the person responsible for this vendor?
 * Did anything change? Is the contact info still good?" Three questions,
 * once a year, and somehow people still forget to do it. If you're not
 * the stakeholder for this vendor, you get bounced faster than a bad check.
 * Features a slick autocomplete for reassigning stakeholders so you can
 * pass the buck with style.
 */

// Standard init -- you know the drill by now
require_once 'includes/init.php';
requireAuth(); // No anonymous vendor reviews, thank you very much

// Grab our trusty singletons -- the usual suspects
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// ============================================================================
// PERMISSION CHECK
// You need the annual_review.create permission to be here.
// If you don't have it, you get a 403 and a stern message.
// ============================================================================
$canCreate = $acl->hasPermission('annual_review.create');
$canReadAll = $acl->hasPermission('annual_review.read');
$canReadAssigned = $acl->hasPermission('annual_review.read_assigned');

if (!$canCreate) {
    http_response_code(403);
    die(e(t('vendor-annual-review.access_denied')));
}

// ============================================================================
// GET THE VENDOR REQUEST
// Grab the request ID from the URL. No ID = back to the list with you.
// ============================================================================
$requestId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($requestId <= 0) {
    redirect('vendor-annual-reviews-list.php?error=not_found');
}

// Fetch the vendor request from the database, including who created it
$request = $db->fetchOne(
    "SELECT r.*, u.full_name as created_by_name, u.email as created_by_email
     FROM vendor_onboarding_requests r
     LEFT JOIN users u ON r.created_by = u.id
     WHERE r.id = :id",
    [':id' => $requestId]
);

// No request found? Back to the list. Do not pass Go, do not collect $200.
if (!$request) {
    redirect('vendor-annual-reviews-list.php?error=not_found');
}

// ============================================================================
// STAKEHOLDER ACCESS CHECK
// If you can't read all reviews, we check if you're a stakeholder for this
// specific vendor. No stakeholder relationship = no entry. It's like a VIP list.
// ============================================================================
if (!$canReadAll) {
    $isStakeholder = $db->fetchOne(
        "SELECT 1 FROM vendor_onboarding_stakeholders WHERE request_id = :request_id AND user_id = :user_id",
        [':request_id' => $requestId, ':user_id' => $user['id']]
    );

    if (!$isStakeholder) {
        redirect('vendor-annual-reviews-list.php?error=permission_denied');
    }
}

// Only approved vendors get annual reviews -- if it's not approved, bail out
if ($request['status'] !== 'approved') {
    redirect('vendor-annual-reviews-list.php?error=not_approved');
}

// Fetch the current stakeholders for this vendor -- we show them in the context box
$stakeholders = $db->fetchAll(
    "SELECT s.*, u.full_name, u.email
     FROM vendor_onboarding_stakeholders s
     LEFT JOIN users u ON s.user_id = u.id
     WHERE s.request_id = :request_id
     ORDER BY u.full_name",
    [':request_id' => $requestId]
);

// Get the last review for this vendor, if one exists.
// We pre-fill some fields from the last review because we're nice like that.
$lastReview = $db->fetchOne(
    "SELECT r.*, u.full_name as reviewer_name
     FROM vendor_annual_reviews r
     LEFT JOIN users u ON r.reviewer_user_id = u.id
     WHERE r.vendor_request_id = :request_id
     ORDER BY r.review_date DESC
     LIMIT 1",
    [':request_id' => $requestId]
);

// ============================================================================
// DUE DATE CALCULATOR
// Same logic as the list page -- figures out when the review is actually due.
// Priority: stored due date > last review + 365 days > approval date + 365 days.
// Because apparently "one year" means 365 days, not "next January" or whatever.
// ============================================================================
function calculateReviewDueDate($request) {
    if ($request['status'] !== 'approved') return null;

    // Check if there's a manually set due date first
    if (!empty($request['last_annual_review_due'])) {
        return $request['last_annual_review_due'];
    }

    // Last review date + 365 days -- the most common scenario
    if (!empty($request['last_annual_review'])) {
        return date('Y-m-d', strtotime($request['last_annual_review'] . ' +365 days'));
    }

    // No reviews yet? Use the approval/submission date as day zero
    $approvalDate = $request['submitted_at'] ?? $request['created_at'];
    if (empty($approvalDate)) return null;

    return date('Y-m-d', strtotime($approvalDate . ' +365 days'));
}

// Calculate how many days until the review is due (negative = overdue)
$dueDate = calculateReviewDueDate($request);
$isOverdue = false;
$daysUntilDue = 0;

if ($dueDate) {
    $now = strtotime(date('Y-m-d'));
    $due = strtotime($dueDate);
    $daysUntilDue = floor(($due - $now) / 86400); // 86400 seconds in a day, for the uninitiated
    $isOverdue = $daysUntilDue < 0;
}

// Parse vendor contact information -- might be JSON, might not, who knows
$contactInfo = [];
if (!empty($request['vendor_contacts'])) {
    $decoded = json_decode($request['vendor_contacts'], true);
    if (is_array($decoded)) {
        $contactInfo = $decoded;
    }
}

// CSRF token to protect the form submission -- because paranoia is a feature
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annual Review: <?= htmlspecialchars($request['vendor_name']) ?> - TPRM</title>
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        /* Theme variables -- pulled from the database because we're fancy */
        :root {
            --theme-header-color: <?= preg_match('/^#[0-9a-fA-F]{3,6}$/', $theme['header_color']) ? $theme['header_color'] : '#333' ?>;
            --theme-sidebar-color: <?= preg_match('/^#[0-9a-fA-F]{3,6}$/', $theme['sidebar_color']) ? $theme['sidebar_color'] : '#2c3e50' ?>;
            --theme-button-color: <?= preg_match('/^#[0-9a-fA-F]{3,6}$/', $theme['button_color']) ? $theme['button_color'] : '#3498db' ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--theme-header-color);
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { text-decoration: underline; }
        .page-header {
            background: white;
            padding: 25px 30px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .page-subtitle {
            font-size: 14px;
            color: #666;
        }

        /* Alert boxes -- traffic-light style warnings about due dates */
        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 14px;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Vendor context card -- the "here's what you're reviewing" summary */
        .vendor-context {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .vendor-context h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }
        .context-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        .context-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .context-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .context-value {
            font-size: 14px;
            color: #333;
        }

        /* The main review form -- where the magic happens */
        .review-form {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .form-section h3 {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .form-section p {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
        }
        .form-label .required {
            color: #dc3545;
        }

        /* Radio buttons for yes/no questions -- because dropdowns are overkill */
        .radio-group {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .radio-option label {
            font-size: 14px;
            color: #333;
            cursor: pointer;
        }
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--theme-button-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Stakeholder reassignment section -- hidden by default, shown when "No" is clicked */
        .stakeholder-reassign {
            display: none;
            margin-top: 15px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        .stakeholder-reassign.show {
            display: block;
        }

        /* Autocomplete for user search -- rolls its own because why use a library */
        .autocomplete-wrapper {
            position: relative;
        }
        .autocomplete-suggestions {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 6px 6px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
        }
        .autocomplete-suggestion {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }
        .autocomplete-suggestion:hover,
        .autocomplete-suggestion.highlighted {
            background: #f8f9fa;
        }
        .autocomplete-suggestion .name {
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .autocomplete-suggestion .email {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        .autocomplete-loading {
            padding: 12px 15px;
            text-align: center;
            color: #666;
            font-size: 13px;
        }

        /* The blue highlight box showing who you've selected as the new stakeholder */
        .selected-stakeholder {
            display: none;
            margin-top: 10px;
            padding: 12px;
            background: #e7f5ff;
            border: 1px solid #339af0;
            border-radius: 6px;
        }
        .selected-stakeholder.show {
            display: block;
        }
        .selected-stakeholder .name {
            font-weight: 500;
            color: #1864ab;
            font-size: 14px;
        }
        .selected-stakeholder .email {
            font-size: 12px;
            color: #495057;
            margin-top: 2px;
        }
        .contact-field {
            margin-bottom: 15px;
        }
        .help-text {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }

        /* Current scope display box -- shows the existing product/service description */
        .current-scope-box {
            background: #f0f7ff;
            border: 1px solid #b8d4f0;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .current-scope-header {
            background: #e1eef9;
            padding: 12px 15px;
            font-weight: 600;
            font-size: 14px;
            color: #1a5a96;
            border-bottom: 1px solid #b8d4f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .current-scope-icon {
            font-size: 16px;
        }
        .current-scope-content {
            padding: 15px;
            font-size: 14px;
            color: #333;
            line-height: 1.7;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .current-scope-content .no-scope {
            color: #666;
            font-style: italic;
        }

        /* Form action buttons at the bottom */
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            filter: brightness(1.1);
        }

        /* Error message box -- appears when form validation fails */
        .error-message {
            display: none;
            padding: 12px 15px;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            margin-top: 20px;
            font-size: 14px;
        }
        .error-message.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- The escape hatch back to the list -->
        <a href="vendor-annual-reviews-list.php" class="back-link">&larr; <?php echo e(t('vendor-annual-review.back_to_reviews')); ?></a>

        <?php // Overdue/due soon alerts -- the guilt trip section ?>
        <?php if ($isOverdue): ?>
            <div class="alert alert-danger">
                <strong>Overdue:</strong> This review was due <?= abs($daysUntilDue) ?> days ago on <?= date('F j, Y', strtotime($dueDate)) ?>.
            </div>
        <?php elseif ($daysUntilDue <= 7): ?>
            <div class="alert alert-warning">
                <strong>Due Soon:</strong> This review is due in <?= $daysUntilDue ?> days on <?= date('F j, Y', strtotime($dueDate)) ?>.
            </div>
        <?php endif; ?>

        <!-- Page header with the vendor name front and center -->
        <div class="page-header">
            <h1 class="page-title"><?php echo e(t('vendor-annual-review.page_title')); ?></h1>
            <p class="page-subtitle"><?= htmlspecialchars($request['vendor_name']) ?></p>
        </div>

        <!-- Vendor context card -- at-a-glance info so you know what you're reviewing -->
        <div class="vendor-context">
            <h3><?php echo e(t('vendor-annual-review.vendor_information')); ?></h3>
            <div class="context-grid">
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.vendor_name')); ?></div>
                    <div class="context-value"><?= htmlspecialchars($request['vendor_name']) ?></div>
                </div>
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.vendor_type')); ?></div>
                    <div class="context-value"><?= htmlspecialchars($request['vendor_type'] ?? 'N/A') ?></div>
                </div>
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.approval_date')); ?></div>
                    <div class="context-value">
                        <?= !empty($request['submitted_at']) ? date('M j, Y', strtotime($request['submitted_at'])) : 'N/A' ?>
                    </div>
                </div>
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.last_review')); ?></div>
                    <div class="context-value">
                        <?php if ($lastReview): ?>
                            <?= date('M j, Y', strtotime($lastReview['review_date'])) ?>
                            <br><small>by <?= htmlspecialchars($lastReview['reviewer_name']) ?></small>
                        <?php else: ?>
                            <?php echo e(t('vendor-annual-review.never_reviewed')); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.current_stakeholders')); ?></div>
                    <div class="context-value">
                        <?php if (!empty($stakeholders)): ?>
                            <?php foreach ($stakeholders as $idx => $sh): ?>
                                <?= htmlspecialchars($sh['full_name']) ?><?= $idx < count($stakeholders) - 1 ? ', ' : '' ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php echo e(t('vendor-annual-review.none_assigned')); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="context-item">
                    <div class="context-label"><?php echo e(t('vendor-annual-review.review_due_date')); ?></div>
                    <div class="context-value">
                        <?= $dueDate ? date('M j, Y', strtotime($dueDate)) : 'N/A' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================
             THE REVIEW FORM
             Three main questions plus optional notes:
             1. Are you still the stakeholder? (with reassignment option)
             2. Has the scope of services changed?
             3. Is the contact info still correct?
             Submitted via fetch() to the API endpoint. No page reloads here.
             ================================================================= -->
        <form id="reviewForm" class="review-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <input type="hidden" id="new_stakeholder_id" name="new_stakeholder_id" value="">

            <!-- Question 1: Still the stakeholder? Or passing the torch? -->
            <div class="form-section">
                <h3><?php echo e(t('vendor-annual-review.section1_title')); ?></h3>
                <p><?php echo e(t('vendor-annual-review.section1_question')); ?></p>

                <div class="form-group">
                    <div class="radio-group">
                        <div class="radio-option">
                            <input type="radio" id="stakeholder_yes" name="is_still_stakeholder" value="yes" required>
                            <label for="stakeholder_yes"><?php echo e(t('vendor-annual-review.still_stakeholder_yes')); ?></label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" id="stakeholder_no" name="is_still_stakeholder" value="no">
                            <label for="stakeholder_no"><?php echo e(t('vendor-annual-review.still_stakeholder_no')); ?></label>
                        </div>
                    </div>
                </div>

                <!-- Reassignment section -- only shows up when you click "No" above -->
                <div id="stakeholderReassign" class="stakeholder-reassign">
                    <div class="form-group">
                        <label class="form-label" for="new_stakeholder_search">
                            <?php echo e(t('vendor-annual-review.search_new_stakeholder')); ?> <span class="required">*</span>
                        </label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="new_stakeholder_search" class="form-control"
                                   placeholder="<?php echo e(t('vendor-annual-review.search_placeholder')); ?>" autocomplete="off">
                            <div id="stakeholderSuggestions" class="autocomplete-suggestions"></div>
                        </div>
                        <div id="selectedStakeholder" class="selected-stakeholder"></div>
                        <div class="help-text"><?php echo e(t('vendor-annual-review.search_help')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Question 2: Scope of services -- has anything changed? -->
            <div class="form-section">
                <h3><?php echo e(t('vendor-annual-review.section2_title')); ?></h3>
                <p><?php echo e(t('vendor-annual-review.section2_question')); ?></p>

                <!-- Show the current scope so reviewers can compare without guessing -->
                <div class="current-scope-box">
                    <div class="current-scope-header">
                        <span class="current-scope-icon">&#128196;</span>
                        <?php echo e(t('vendor-annual-review.current_scope_header')); ?>
                    </div>
                    <div class="current-scope-content">
                        <?php if (!empty($request['product_service_description'])): ?>
                            <?= nl2br(htmlspecialchars($request['product_service_description'])) ?>
                        <?php else: ?>
                            <em class="no-scope"><?php echo e(t('vendor-annual-review.no_scope_documented')); ?></em>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="scope_changes">
                        <?php echo e(t('vendor-annual-review.scope_changes_label')); ?> <span class="required">*</span>
                    </label>
                    <textarea id="scope_changes" name="scope_changes" class="form-control" required
                              placeholder="<?php echo e(t('vendor-annual-review.scope_changes_placeholder')); ?>"><?php
                        // Pre-fill from last review -- saves the reviewer some typing
                        if ($lastReview && !empty($lastReview['scope_changes'])) {
                            echo htmlspecialchars($lastReview['scope_changes']);
                        }
                    ?></textarea>
                    <div class="help-text">
                        <?php echo e(t('vendor-annual-review.scope_changes_help')); ?>
                    </div>
                </div>
            </div>

            <!-- Question 3: Contact info -- is it still current? -->
            <div class="form-section">
                <h3><?php echo e(t('vendor-annual-review.section3_title')); ?></h3>
                <p><?php echo e(t('vendor-annual-review.section3_desc')); ?></p>

                <?php
                $contactEmail = $request['primary_contact_email'] ?? '';
                $contactName = $request['primary_contact_details'] ?? '';
                $contactTitle = $request['primary_contact_title'] ?? '';
                $contactPhone = $request['primary_contact_phone'] ?? '';
                ?>

                <div class="contact-field">
                    <label class="form-label" for="contact_name"><?php echo e(t('vendor-annual-review.contact_name')); ?></label>
                    <input type="text" id="contact_name" name="contact_name" class="form-control"
                           value="<?= htmlspecialchars($contactName) ?>"
                           placeholder="<?php echo e(t('vendor-annual-review.contact_name_placeholder')); ?>">
                </div>

                <div class="contact-field">
                    <label class="form-label" for="contact_title"><?php echo e(t('vendor-annual-review.contact_title')); ?></label>
                    <input type="text" id="contact_title" name="contact_title" class="form-control"
                           value="<?= htmlspecialchars($contactTitle) ?>"
                           placeholder="Account Manager">
                </div>

                <div class="contact-field">
                    <label class="form-label" for="contact_email"><?php echo e(t('vendor-annual-review.contact_email')); ?></label>
                    <input type="email" id="contact_email" name="contact_email" class="form-control"
                           value="<?= htmlspecialchars($contactEmail) ?>"
                           placeholder="email@vendor.com">
                </div>

                <div class="contact-field">
                    <label class="form-label" for="contact_phone"><?php echo e(t('vendor-annual-review.contact_phone')); ?></label>
                    <input type="text" id="contact_phone" name="contact_phone" class="form-control"
                           value="<?= htmlspecialchars($contactPhone) ?>"
                           placeholder="(555) 123-4567">
                </div>
            </div>

            <!-- Additional notes -- for anything that doesn't fit the above questions -->
            <div class="form-section">
                <h3><?php echo e(t('vendor-annual-review.additional_notes_title')); ?></h3>
                <div class="form-group">
                    <label class="form-label" for="review_notes">
                        <?php echo e(t('vendor-annual-review.additional_notes_label')); ?>
                    </label>
                    <textarea id="review_notes" name="review_notes" class="form-control"
                              placeholder="<?php echo e(t('vendor-annual-review.additional_notes_placeholder')); ?>"></textarea>
                </div>
            </div>

            <div id="errorMessage" class="error-message"></div>

            <div class="form-actions">
                <button type="submit" id="submitBtn" class="btn btn-primary"><?php echo e(t('vendor-annual-review.submit_review')); ?></button>
                <a href="vendor-annual-reviews-list.php" class="btn btn-secondary"><?php echo e(t('vendor-annual-review.cancel')); ?></a>
            </div>
        </form>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        // ====================================================================
        // STAKEHOLDER REASSIGNMENT TOGGLE
        // Click "Yes" = hide the reassignment section. Click "No" = show it.
        // Simple as that. The hidden input gets cleared when you switch back
        // to "Yes" so we don't accidentally reassign someone.
        // ====================================================================
        const stakeholderYes = document.getElementById('stakeholder_yes');
        const stakeholderNo = document.getElementById('stakeholder_no');
        const reassignSection = document.getElementById('stakeholderReassign');

        stakeholderYes.addEventListener('change', () => {
            if (stakeholderYes.checked) {
                reassignSection.classList.remove('show');
                document.getElementById('new_stakeholder_id').value = '';
            }
        });

        stakeholderNo.addEventListener('change', () => {
            if (stakeholderNo.checked) {
                reassignSection.classList.add('show');
            }
        });

        // ====================================================================
        // STAKEHOLDER AUTOCOMPLETE
        // A homebrew autocomplete that hits the search-users API endpoint.
        // Debounced at 300ms because we don't want to DDoS our own server
        // every time someone types a letter. Supports keyboard navigation
        // (arrow keys + enter) because we're classy like that.
        // ====================================================================
        const searchInput = document.getElementById('new_stakeholder_search');
        const suggestionsBox = document.getElementById('stakeholderSuggestions');
        const selectedBox = document.getElementById('selectedStakeholder');
        const hiddenInput = document.getElementById('new_stakeholder_id');

        let currentResults = [];
        let highlightedIndex = -1;
        let searchTimeout = null;

        // Debounced search -- wait 300ms after the user stops typing
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();

            if (query.length < 2) {
                suggestionsBox.style.display = 'none';
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 300);
        });

        // Hit the API to find users matching the search query
        function performSearch(query) {
            suggestionsBox.innerHTML = '<div class="autocomplete-loading">Searching...</div>';
            suggestionsBox.style.display = 'block';

            const requestId = <?= $requestId ?>;
            fetch(`api/search-users.php?q=${encodeURIComponent(query)}&request_id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users.length > 0) {
                        currentResults = data.users;
                        highlightedIndex = -1;
                        renderSuggestions(data.users);
                    } else {
                        suggestionsBox.innerHTML = '<div class="autocomplete-loading">No users found</div>';
                    }
                })
                .catch(err => {
                    console.error('Search error:', err);
                    suggestionsBox.innerHTML = '<div class="autocomplete-loading">Error searching users</div>';
                });
        }

        // Render the dropdown list of user suggestions
        function renderSuggestions(users) {
            suggestionsBox.innerHTML = users.map((user, index) => `
                <div class="autocomplete-suggestion" data-index="${index}">
                    <div class="name">${escapeHtml(user.full_name)}</div>
                    <div class="email">${escapeHtml(user.email)}</div>
                </div>
            `).join('');

            // Attach click handlers to each suggestion
            document.querySelectorAll('.autocomplete-suggestion').forEach(el => {
                el.addEventListener('click', () => {
                    const index = parseInt(el.dataset.index);
                    selectUser(currentResults[index]);
                });
            });
        }

        // User clicked a suggestion -- set the hidden input and show the selection
        function selectUser(user) {
            hiddenInput.value = user.id;
            searchInput.value = '';
            suggestionsBox.style.display = 'none';

            selectedBox.innerHTML = `
                <div class="name">${escapeHtml(user.full_name)}</div>
                <div class="email">${escapeHtml(user.email)}</div>
            `;
            selectedBox.classList.add('show');
        }

        // XSS protection for user-generated content in the autocomplete
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close the suggestions dropdown when clicking outside of it
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                suggestionsBox.style.display = 'none';
            }
        });

        // Keyboard navigation for the autocomplete suggestions
        searchInput.addEventListener('keydown', (e) => {
            if (suggestionsBox.style.display === 'none') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightedIndex = Math.min(highlightedIndex + 1, currentResults.length - 1);
                updateHighlight();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightedIndex = Math.max(highlightedIndex - 1, -1);
                updateHighlight();
            } else if (e.key === 'Enter' && highlightedIndex >= 0) {
                e.preventDefault();
                selectUser(currentResults[highlightedIndex]);
            } else if (e.key === 'Escape') {
                suggestionsBox.style.display = 'none';
            }
        });

        // Visually highlight the currently selected suggestion
        function updateHighlight() {
            document.querySelectorAll('.autocomplete-suggestion').forEach((el, idx) => {
                el.classList.toggle('highlighted', idx === highlightedIndex);
            });
        }

        // ====================================================================
        // FORM SUBMISSION
        // Validates the form, then fires it off to the API via fetch().
        // If the reassignment option is selected but no new stakeholder was
        // picked, we yell at the user. On success, redirect to the list page.
        // ====================================================================
        const reviewForm = document.getElementById('reviewForm');
        const submitBtn = document.getElementById('submitBtn');
        const errorMessage = document.getElementById('errorMessage');

        reviewForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Can't say "no" to being the stakeholder and then not pick a replacement
            if (stakeholderNo.checked && !hiddenInput.value) {
                showError('Please select a new stakeholder or choose "Yes, I am still the stakeholder"');
                return;
            }

            // Disable the button to prevent double-submission (the classic footgun)
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            errorMessage.classList.remove('show');

            // Ship the form data off to the API
            const formData = new FormData(reviewForm);

            try {
                const response = await fetch('api/annual-review-submit.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Victory lap -- redirect back to the list with a success message
                    window.location.href = 'vendor-annual-reviews-list.php?success=reviewed';
                } else {
                    showError(result.message || 'Failed to submit review. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Review';
                }
            } catch (err) {
                console.error('Submission error:', err);
                showError('An error occurred while submitting the review. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Review';
            }
        });

        // Display an error message and scroll it into view
        function showError(message) {
            errorMessage.textContent = message;
            errorMessage.classList.add('show');
            errorMessage.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
