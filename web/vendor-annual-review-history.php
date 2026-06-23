<?php
/**
 * Vendor Annual Review History - The Time Machine
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * A timeline view showing all the past reviews for a specific vendor.
 * Think of it as the vendor's permanent record -- who reviewed it, when,
 * whether they were fashionably late, and what they had to say. Great for
 * audit trails and for proving to auditors that yes, we actually do review
 * our vendors. Each review gets a nice timeline dot and an on-time/late badge
 * so you can visually see which stakeholders need a reminder about deadlines.
 */

// Standard init -- authentication required, no exceptions
require_once 'includes/init.php';
requireAuth();

// Grab our singletons -- the gang's all here
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// ============================================================================
// PERMISSION CHECK
// You need either read-all or read-assigned permissions.
// If you have neither, you're not welcome here.
// ============================================================================
$canReadAll = $acl->hasPermission('annual_review.read');
$canReadAssigned = $acl->hasPermission('annual_review.read_assigned');

if (!$canReadAll && !$canReadAssigned) {
    http_response_code(403);
    die(e(t('vendor-annual-review-history.access_denied')));
}

// Grab the vendor request ID from the URL -- no ID means something went wrong
$requestId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($requestId <= 0) {
    redirect('vendor-annual-reviews-list.php?error=not_found');
}

// Fetch the vendor request -- just to make sure it exists and to get the name
$request = $db->fetchOne(
    "SELECT * FROM vendor_onboarding_requests WHERE id = :id",
    [':id' => $requestId]
);

if (!$request) {
    redirect('vendor-annual-reviews-list.php?error=not_found');
}

// ============================================================================
// STAKEHOLDER ACCESS CHECK
// If you can't see all reviews, verify you're a stakeholder for this vendor.
// It's the same pattern we use everywhere -- check the junction table.
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

// ============================================================================
// FETCH ALL REVIEWS FOR THIS VENDOR
// Pull every review ever done, newest first. We join the users table twice --
// once for the reviewer name, once for the new stakeholder name (if reassigned).
// This gives us everything we need for the timeline display.
// ============================================================================
$reviews = $db->fetchAll(
    "SELECT r.*, u.full_name as reviewer_name, u.email as reviewer_email,
            ns.full_name as new_stakeholder_name
     FROM vendor_annual_reviews r
     LEFT JOIN users u ON r.reviewer_user_id = u.id
     LEFT JOIN users ns ON r.new_stakeholder_id = ns.id
     WHERE r.vendor_request_id = :request_id
     ORDER BY r.review_date DESC",
    [':request_id' => $requestId]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review History: <?= htmlspecialchars($request['vendor_name']) ?> - TPRM</title>
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        /* Theme variables from the database */
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
            max-width: 1000px;
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

        /* Timeline layout -- the vertical line with dots for each review */
        .timeline {
            position: relative;
            padding-left: 40px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }

        /* Individual review cards on the timeline */
        .review-item {
            position: relative;
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        /* The colored dot on the timeline -- positioned with pixel-perfect precision */
        .review-item::before {
            content: '';
            position: absolute;
            left: -29px;
            top: 30px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--theme-button-color);
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--theme-button-color);
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .review-meta {
            flex: 1;
        }
        .review-date {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .reviewer-info {
            font-size: 13px;
            color: #666;
        }

        /* On-time vs late badges -- the badge of honor (or shame) */
        .review-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-on-time {
            background: #28a745;
            color: white;
        }
        .badge-late {
            background: #dc3545;
            color: white;
        }

        /* Review body sections -- stakeholder status, scope changes, contacts, notes */
        .review-body {
            margin-top: 15px;
        }
        .review-section {
            margin-bottom: 20px;
        }
        .review-section:last-child {
            margin-bottom: 0;
        }
        .section-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .section-value {
            font-size: 14px;
            color: #333;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        /* Contact info grid -- name, email, phone in a responsive layout */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .contact-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .contact-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3px;
        }
        .contact-value {
            font-size: 13px;
            color: #333;
        }

        /* Empty state for vendors that have never been reviewed */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .empty-state h3 {
            color: #666;
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 600;
        }
        .empty-state p {
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Back link -- the exit from the time machine -->
        <a href="vendor-annual-reviews-list.php" class="back-link">&larr; <?php echo e(t('vendor-annual-review-history.back')); ?></a>

        <div class="page-header">
            <h1 class="page-title"><?php echo e(t('vendor-annual-review-history.heading')); ?></h1>
            <p class="page-subtitle"><?= htmlspecialchars($request['vendor_name']) ?></p>
        </div>

        <?php if (empty($reviews)): ?>
            <!-- No reviews yet -- the vendor has a clean slate -->
            <div class="empty-state">
                <h3><?php echo e(t('vendor-annual-review-history.no_reviews')); ?></h3>
                <p><?php echo e(t('vendor-annual-review-history.no_reviews_desc')); ?></p>
            </div>
        <?php else: ?>
            <!-- The timeline of reviews, newest first -->
            <div class="timeline">
                <?php foreach ($reviews as $review): ?>
                    <?php
                    // Figure out if the review was on time or late by comparing
                    // review_date vs due_date. Late reviews get the red badge of shame.
                    $reviewDate = strtotime($review['review_date']);
                    $dueDate = strtotime($review['due_date']);
                    $isLate = $reviewDate > $dueDate;

                    // Contact updates are stored as JSON -- decode them for display
                    $contactUpdates = json_decode($review['contact_updates'], true);
                    ?>
                    <div class="review-item">
                        <!-- Review header: date, reviewer name, and the on-time/late badge -->
                        <div class="review-header">
                            <div class="review-meta">
                                <div class="review-date">
                                    <?= date('F j, Y', $reviewDate) ?>
                                </div>
                                <div class="reviewer-info">
                                    Reviewed by <?= htmlspecialchars($review['reviewer_name']) ?>
                                    (<?= htmlspecialchars($review['reviewer_email']) ?>)
                                </div>
                            </div>
                            <span class="review-badge <?= $isLate ? 'badge-late' : 'badge-on-time' ?>">
                                <?= $isLate ? e(t('vendor-annual-review-history.badge_late')) : e(t('vendor-annual-review-history.badge_on_time')) ?>
                            </span>
                        </div>

                        <div class="review-body">
                            <!-- Stakeholder status -- confirmed or reassigned -->
                            <div class="review-section">
                                <div class="section-label"><?php echo e(t('vendor-annual-review-history.stakeholder_status')); ?></div>
                                <div class="section-value">
                                    <?php if ($review['is_still_stakeholder'] === 'yes'): ?>
                                        ✓ <?php echo e(t('vendor-annual-review-history.confirmed_stakeholder')); ?>
                                    <?php else: ?>
                                        ↻ Reassigned to <?= htmlspecialchars($review['new_stakeholder_name'] ?? 'Unknown') ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Scope changes -- what changed with the vendor's services -->
                            <?php if (!empty($review['scope_changes'])): ?>
                                <div class="review-section">
                                    <div class="section-label"><?php echo e(t('vendor-annual-review-history.scope_changes')); ?></div>
                                    <div class="section-value">
                                        <?= nl2br(htmlspecialchars($review['scope_changes'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Contact info updates -- only when actual contact fields were submitted.
                                 The stored JSON blob always carries an 'updated_at' timestamp (and the
                                 four keys, possibly empty), so we must check the fields themselves --
                                 not merely the presence of the blob -- or the header shows on every review. -->
                            <?php if ($contactUpdates && is_array($contactUpdates)
                                    && (!empty($contactUpdates['contact_name'])
                                        || !empty($contactUpdates['contact_title'])
                                        || !empty($contactUpdates['contact_email'])
                                        || !empty($contactUpdates['contact_phone']))): ?>
                                <div class="review-section">
                                    <div class="section-label"><?php echo e(t('vendor-annual-review-history.contact_updated')); ?></div>
                                    <div class="contact-grid">
                                        <?php if (!empty($contactUpdates['contact_name'])): ?>
                                            <div class="contact-item">
                                                <div class="contact-label"><?php echo e(t('vendor-annual-review-history.contact_name')); ?></div>
                                                <div class="contact-value">
                                                    <?= htmlspecialchars($contactUpdates['contact_name']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contactUpdates['contact_email'])): ?>
                                            <div class="contact-item">
                                                <div class="contact-label"><?php echo e(t('vendor-annual-review-history.contact_email')); ?></div>
                                                <div class="contact-value">
                                                    <?= htmlspecialchars($contactUpdates['contact_email']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contactUpdates['contact_phone'])): ?>
                                            <div class="contact-item">
                                                <div class="contact-label"><?php echo e(t('vendor-annual-review-history.contact_phone')); ?></div>
                                                <div class="contact-value">
                                                    <?= htmlspecialchars($contactUpdates['contact_phone']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Additional reviewer notes -- free-form comments -->
                            <?php if (!empty($review['review_notes'])): ?>
                                <div class="review-section">
                                    <div class="section-label"><?php echo e(t('vendor-annual-review-history.additional_notes')); ?></div>
                                    <div class="section-value">
                                        <?= nl2br(htmlspecialchars($review['review_notes'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
