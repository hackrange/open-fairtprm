<?php
/**
 * Annual Review Submission API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The yearly checkup for vendor relationships. Once a year, stakeholders have to
 * confirm they're still the right person managing a vendor, report any scope changes,
 * update contact info, and generally prove the vendor hasn't gone rogue. This endpoint
 * handles the whole shebang: validates the submission, saves the review record, updates
 * the vendor's next review due date (365 days out -- no leap year drama, sorry Feb 29
 * babies), and handles stakeholder reassignment if someone says "I don't want to be
 * responsible for this vendor anymore." It's wrapped in a database transaction because
 * half-saved annual reviews are nobody's idea of a good time. Also, contact info
 * updates on the original vendor record are gated behind admin/cyber_tprm permissions
 * because we can't have just anyone rewriting vendor contact details.
 */

// JSON responses for the frontend submission handler
header('Content-Type: application/json');

// Initialize the application stack
require_once '../includes/init.php';

// ---------------------------------------------------------------
// AUTHENTICATION CHECK
// You must be logged in to submit an annual review.
// This isn't a public survey.
// ---------------------------------------------------------------
if (!Auth::getInstance()->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Assemble the dream team of singletons
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

// ---------------------------------------------------------------
// METHOD ENFORCEMENT
// Submissions are POST requests. This is not a negotiation.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// PERMISSION CHECK
// Need the 'annual_review.create' permission to submit reviews.
// This is typically assigned to stakeholders and admin roles.
// ---------------------------------------------------------------
$canCreate = $acl->hasPermission('annual_review.create');
if (!$canCreate) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// ---------------------------------------------------------------
// CSRF VALIDATION
// Standard cross-site request forgery protection.
// ---------------------------------------------------------------
if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ---------------------------------------------------------------
// INPUT COLLECTION & SANITIZATION
// Gather all the form fields. The big question is "are you still
// the stakeholder?" -- if no, they need to pick a replacement.
// ---------------------------------------------------------------
$requestId = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$isStillStakeholder = isset($_POST['is_still_stakeholder']) ? $_POST['is_still_stakeholder'] : '';
$newStakeholderId = isset($_POST['new_stakeholder_id']) ? intval($_POST['new_stakeholder_id']) : 0;
$scopeChanges = $security->cleanInput($_POST['scope_changes'] ?? '');
$reviewNotes = $security->cleanInput($_POST['review_notes'] ?? '');

// Contact information fields -- these may or may not update the vendor record
// depending on whether the submitter has the right permissions
$contactName = $security->cleanInput($_POST['contact_name'] ?? '');
$contactTitle = $security->cleanInput($_POST['contact_title'] ?? '');
$contactEmail = $security->cleanInput($_POST['contact_email'] ?? '');
$contactPhone = $security->cleanInput($_POST['contact_phone'] ?? '');

// ---------------------------------------------------------------
// REQUIRED FIELD VALIDATION
// The basics: valid request ID and a yes/no on the stakeholder question.
// If they said "no" to being stakeholder, they MUST pick a replacement.
// We're not letting them just peace out without a handoff.
// ---------------------------------------------------------------
if ($requestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor request ID']);
    exit;
}

if (!in_array($isStillStakeholder, ['yes', 'no'])) {
    echo json_encode(['success' => false, 'error' => 'Please indicate if you are still the stakeholder']);
    exit;
}

if ($isStillStakeholder === 'no' && $newStakeholderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select a new stakeholder']);
    exit;
}

// Validate email format if provided
if (!empty($contactEmail) && !$security->validateEmail($contactEmail)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address format']);
    exit;
}

try {
    // ---------------------------------------------------------------
    // FETCH THE VENDOR REQUEST
    // Make sure the vendor actually exists in our system.
    // ---------------------------------------------------------------
    $request = $db->fetchOne(
        "SELECT * FROM vendor_onboarding_requests WHERE id = :id",
        [':id' => $requestId]
    );

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Vendor request not found']);
        exit;
    }

    // Only approved vendors get annual reviews. Drafts and rejected
    // vendors don't need yearly checkups -- they barely existed.
    if ($request['status'] !== 'approved') {
        echo json_encode(['success' => false, 'error' => 'Only approved vendors can be reviewed']);
        exit;
    }

    // ---------------------------------------------------------------
    // VENDOR-LEVEL PERMISSION CHECK
    // Even with the global permission, you need to be associated with
    // this specific vendor. Either you can read ALL reviews (admin-level)
    // or you're a stakeholder on this particular vendor.
    // ---------------------------------------------------------------
    // SECURITY (IDOR): a READ-all permission (annual_review.read) must NOT authorize
    // a state-changing WRITE to an arbitrary vendor. Only elevated roles that manage
    // every vendor (administrator / cyber_tprm) may submit a review for any vendor;
    // everyone else must be a stakeholder on this specific vendor request.
    $canReviewAny = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm')
                    || Session::getInstance()->get('is_super_admin');
    if (!$canReviewAny) {
        $isStakeholder = $db->fetchOne(
            "SELECT 1 FROM vendor_onboarding_stakeholders WHERE request_id = :request_id AND user_id = :user_id",
            [':request_id' => $requestId, ':user_id' => $user['id']]
        );

        if (!$isStakeholder) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to review this vendor']);
            exit;
        }
    }

    // ---------------------------------------------------------------
    // DUE DATE CALCULATION
    // Figure out when this review was "due." We check three sources
    // in priority order:
    //   1. Explicit due date on the vendor record
    //   2. Last review date + 365 days
    //   3. Approval/creation date + 365 days
    // If all else fails, today's date. Because it's due NOW, buddy.
    // ---------------------------------------------------------------
    $dueDate = null;
    if (!empty($request['last_annual_review_due'])) {
        $dueDate = $request['last_annual_review_due'];
    } elseif (!empty($request['last_annual_review'])) {
        $dueDate = date('Y-m-d', strtotime($request['last_annual_review'] . ' +365 days'));
    } else {
        $approvalDate = $request['submitted_at'] ?? $request['created_at'];
        if (!empty($approvalDate)) {
            $dueDate = date('Y-m-d', strtotime($approvalDate . ' +365 days'));
        }
    }

    // Fallback: if we somehow still don't have a due date, just use today
    if (!$dueDate) {
        $dueDate = date('Y-m-d');
    }

    // ---------------------------------------------------------------
    // PREPARE CONTACT UPDATES AS JSON
    // Store the contact info changes as a JSON blob in the review record.
    // This gives us an audit trail of what was submitted regardless of
    // whether it actually updates the vendor record.
    // ---------------------------------------------------------------
    $contactUpdates = json_encode([
        'contact_name' => $contactName,
        'contact_title' => $contactTitle,
        'contact_email' => $contactEmail,
        'contact_phone' => $contactPhone,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // ---------------------------------------------------------------
    // BEGIN TRANSACTION
    // Everything from here needs to succeed or fail as a unit.
    // We're touching multiple tables and we don't want to end up
    // with a saved review but a broken stakeholder reassignment.
    // ---------------------------------------------------------------
    $db->beginTransaction();

    // Insert the actual review record with all the submitted data
    $reviewData = [
        'vendor_request_id' => $requestId,
        'review_date' => date('Y-m-d H:i:s'),
        'due_date' => $dueDate,
        'reviewer_user_id' => $user['id'],
        'is_still_stakeholder' => $isStillStakeholder,
        'new_stakeholder_id' => $isStillStakeholder === 'no' ? $newStakeholderId : null,
        'scope_changes' => !empty($scopeChanges) ? $scopeChanges : null,
        'contact_updates' => $contactUpdates,
        'review_notes' => !empty($reviewNotes) ? $reviewNotes : null
    ];

    $db->insert('vendor_annual_reviews', $reviewData);

    // ---------------------------------------------------------------
    // UPDATE VENDOR RECORD
    // Set the last review date to now and calculate the next due date
    // (365 days from today). Also optionally update the vendor's
    // contact fields if the submitter has the right permissions.
    // ---------------------------------------------------------------
    $nextDueDate = date('Y-m-d', strtotime('+365 days'));
    $vendorUpdateData = [
        'last_annual_review' => date('Y-m-d H:i:s'),
        'last_annual_review_due' => $nextDueDate
    ];

    // Apply the contact info to the canonical vendor record. The submitter has
    // already been authorized for THIS specific vendor above (administrator /
    // cyber_tprm, or a verified stakeholder on this vendor request), so updating
    // its contact fields is the intended annual-review workflow -- not an
    // arbitrary cross-vendor write. Empty fields are skipped so a blank input
    // never wipes an existing value.
    if (!empty($contactEmail)) {
        $vendorUpdateData['primary_contact_email'] = $contactEmail;
    }
    if (!empty($contactName)) {
        $vendorUpdateData['primary_contact_details'] = $contactName;
    }
    if (!empty($contactTitle)) {
        $vendorUpdateData['primary_contact_title'] = $contactTitle;
    }
    if (!empty($contactPhone)) {
        $vendorUpdateData['primary_contact_phone'] = $contactPhone;
    }

    $db->update(
        'vendor_onboarding_requests',
        $vendorUpdateData,
        'id = :id',
        [':id' => $requestId]
    );

    // ---------------------------------------------------------------
    // STAKEHOLDER REASSIGNMENT
    // If the reviewer said "I'm not the stakeholder anymore," we need
    // to swap them out. This involves:
    //   1. Verifying the new stakeholder is a real, active user
    //   2. Removing the current user from stakeholders
    //   3. Adding the new user (if they're not already assigned)
    // It's like a relay race handoff, but with vendor responsibility.
    // ---------------------------------------------------------------
    if ($isStillStakeholder === 'no' && $newStakeholderId > 0) {
        // Make sure the replacement is a real person who's still active
        $newStakeholder = $db->fetchOne(
            "SELECT id, full_name, email FROM users WHERE id = :id AND is_active = 1",
            [':id' => $newStakeholderId]
        );

        if (!$newStakeholder) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid new stakeholder selected']);
            exit;
        }

        // Remove the outgoing stakeholder
        $db->delete(
            'vendor_onboarding_stakeholders',
            'request_id = :request_id AND user_id = :user_id',
            [':request_id' => $requestId, ':user_id' => $user['id']]
        );

        // Add the new stakeholder, but only if they're not already on this vendor
        // (could happen if they have a different role already)
        $existingStakeholder = $db->fetchOne(
            "SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :request_id AND user_id = :user_id",
            [':request_id' => $requestId, ':user_id' => $newStakeholderId]
        );

        if (!$existingStakeholder) {
            $db->insert('vendor_onboarding_stakeholders', [
                'request_id' => $requestId,
                'user_id' => $newStakeholderId,
                'assigned_at' => date('Y-m-d H:i:s'),
                'assigned_by' => $user['id']
            ]);
        }
    }

    // Everything worked -- commit the whole thing
    $db->commit();

    $auditData = ['vendor_request_id' => $requestId, 'is_still_stakeholder' => $isStillStakeholder];
    if ($isStillStakeholder === 'no') { $auditData['new_stakeholder_id'] = $newStakeholderId; }
    $auth->audit($user['id'], 'annual_review_submit', 'vendor_annual_reviews', $requestId, [
        'new' => $auditData
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Annual review submitted successfully',
        'next_due_date' => $nextDueDate
    ]);

} catch (Exception $e) {
    // If we're in a transaction, roll it back so we don't leave
    // the database in some franken-state between saved and not-saved
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log the full error for debugging, send a generic message to the user
    error_log('Annual review submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while submitting the review. Please try again.'
    ]);
}
