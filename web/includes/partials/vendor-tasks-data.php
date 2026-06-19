<?php
/**
 * Vendor Tasks Data Layer - The Behind-the-Scenes Data Fetcher
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This partial handles all the data fetching for the "Vendor Tasks" tab on the
 * vendor onboarding list page. It builds permission-aware queries so users only
 * see assessments they're supposed to see (no peeking at other people's vendors),
 * calculates completion percentages for each assessment, and tallies up the
 * status counts for the summary stats at the top. It's the data buffet that
 * feeds the vendor-tasks-view.php template.
 *
 * Expects $db, $acl, and $user to already be available (set by the parent page).
 */

// This file is included by vendor-onboarding-list.php
// Assumes $db, $acl, $user are already available from the parent scope

require_once __DIR__ . '/../classes/VendorAssessmentService.php';
$assessmentService = new VendorAssessmentService();

// ============================================================================
// Permission-Based Filtering
// Build WHERE clauses based on what this user is allowed to see.
// Same permission logic as the vendor list page -- consistency is key.
// ============================================================================
$taskParams = [];
$taskWhereConditions = [];

if ($canReadAll) {
    // God mode -- can see everything. No user filter needed.
    // (admins and users with 'vendor.read.all' permission)
} elseif ($canReadOwn && $canReadAssigned) {
    // SECURITY (BOLA): scope on the parent vendor REQUEST the user owns or is a
    // stakeholder on -- the same object boundary the main onboarding list uses.
    // Scoping on va.created_by (the assessment creator) instead leaked vendor
    // requests (and their assessment UUIDs) to a user who created an assessment
    // on a request they neither own nor are assigned to.
    $taskWhereConditions[] = "(vor.created_by = :task_user_id OR EXISTS (
        SELECT 1 FROM vendor_onboarding_stakeholders s
        WHERE s.request_id = vor.id AND s.user_id = :task_user_id2
    ))";
    $taskParams[':task_user_id'] = $user['id'];
    $taskParams[':task_user_id2'] = $user['id'];
} elseif ($canReadOwn) {
    // Only see vendor requests they personally created. Tight leash.
    $taskWhereConditions[] = "vor.created_by = :task_user_id";
    $taskParams[':task_user_id'] = $user['id'];
} elseif ($canReadAssigned) {
    // Only see vendors they're assigned to as a stakeholder.
    // Good for read-only stakeholder roles.
    $taskWhereConditions[] = "EXISTS (
        SELECT 1 FROM vendor_onboarding_stakeholders s
        WHERE s.request_id = vor.id AND s.user_id = :task_user_id
    )";
    $taskParams[':task_user_id'] = $user['id'];
}

// Combine conditions into a WHERE clause (or leave empty for "see everything" users)
$taskWhereClause = !empty($taskWhereConditions) ? 'WHERE ' . implode(' AND ', $taskWhereConditions) : '';

// ============================================================================
// Main Query -- Fetch assessments with vendor details, template info, and creator name
// Sorted newest-first because recent tasks are usually the ones that matter.
// ============================================================================
$taskQuery = "
    SELECT
        va.id, va.uuid, va.vendor_name, va.vendor_contact_name,
        va.vendor_contact_email, va.status, va.created_at, va.expires_at,
        vor.id as vendor_request_id,
        vor.primary_contact_email, vor.primary_contact_details,
        at.name as assessment_type,
        u.full_name as created_by_name
    FROM vendor_assessments va
    INNER JOIN vendor_onboarding_requests vor ON va.vendor_request_id = vor.id
    LEFT JOIN assessment_templates at ON va.template_id = at.id
    LEFT JOIN users u ON va.created_by = u.id
    {$taskWhereClause}
    ORDER BY va.created_at DESC
";

$assessments = $db->fetchAll($taskQuery, $taskParams);

// ============================================================================
// Post-Processing -- Calculate completion % and normalize contact info
// This runs for each assessment, which means N+1 queries for completion status.
// Not ideal for 10,000 assessments, but fine for the typical hundreds.
// ============================================================================
foreach ($assessments as &$assessment) {
    // Get completion percentage from the assessment service
    $completionData = $assessmentService->getCompletionStatus($assessment['id']);
    $assessment['completion_percentage'] = $completionData ? $completionData['percentage'] : 0;

    // Normalize contact info -- prefer the assessment-specific contact,
    // fall back to the vendor's primary contact from the onboarding request
    $assessment['contact_email'] = $assessment['vendor_contact_email'] ?: $assessment['primary_contact_email'];
    $assessment['contact_name'] = $assessment['vendor_contact_name'] ?: $assessment['primary_contact_details'];
}
unset($assessment); // Break the reference from the foreach -- PHP gotcha that bites everyone once

// ============================================================================
// Status Summary -- Count assessments by status for the stat badges
// ============================================================================
$taskCounts = [
    'total' => count($assessments),
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'expired' => 0
];

// Tally up each status -- simple loop beats a GROUP BY since we already have the data
foreach ($assessments as $assessment) {
    if (isset($taskCounts[$assessment['status']])) {
        $taskCounts[$assessment['status']]++;
    }
}
