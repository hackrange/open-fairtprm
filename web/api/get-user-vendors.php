<?php
/**
 * Get User Vendors API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This endpoint answers the question "what vendors does this user own or manage?"
 * It's used by the admin panel's delete-user modal to show all the vendor
 * relationships that would be orphaned if you nuke the user. Think of it as
 * the "are you SURE you want to do this?" data behind the confirmation dialog.
 * Returns two lists: vendors the user created, and vendor stakeholder assignments.
 * Because deleting a user who owns 47 vendors without reassigning them first is
 * what we in the industry call "a very bad time."
 */

// Pull in the app framework and require admin privileges.
// requireAdmin() is the nuclear option of auth checks -- admin or GTFO.
require_once __DIR__ . '/../includes/init.php';
requireAdmin();

// JSON responses for our lovely frontend consumer
header('Content-Type: application/json');

$db = Database::getInstance();

// Get the target user's ID. We intval() it because trusting user input
// is how you end up on the news.
$userId = intval($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

try {
    // ---------------------------------------------------------------
    // QUERY 1: VENDORS CREATED BY THIS USER
    // Find all vendor onboarding requests where this user is the
    // original creator. Capped at 50 because if someone created more
    // than 50 vendors, they're either very productive or something
    // has gone terribly wrong.
    // ---------------------------------------------------------------
    $vendors = $db->fetchAll(
        "SELECT id, vendor_name, status
         FROM vendor_onboarding_requests
         WHERE created_by = :user_id
         ORDER BY vendor_name ASC
         LIMIT 50",
        [':user_id' => $userId]
    );

    // ---------------------------------------------------------------
    // QUERY 2: STAKEHOLDER ASSIGNMENTS
    // Find all vendors where this user is assigned as a stakeholder.
    // This is different from ownership -- a stakeholder is someone
    // responsible for managing the ongoing vendor relationship.
    // Also capped at 50 because, again, there are limits to sanity.
    // ---------------------------------------------------------------
    $stakeholderAssignments = $db->fetchAll(
        "SELECT s.role, v.id as vendor_id, v.vendor_name
         FROM vendor_onboarding_stakeholders s
         INNER JOIN vendor_onboarding_requests v ON s.request_id = v.id
         WHERE s.user_id = :user_id
         ORDER BY v.vendor_name ASC
         LIMIT 50",
        [':user_id' => $userId]
    );

    // Ship both lists plus counts so the frontend can display
    // "This user owns X vendors and is stakeholder on Y vendors"
    echo json_encode([
        'success' => true,
        'vendors' => $vendors,
        'stakeholder_assignments' => $stakeholderAssignments,
        'vendor_count' => count($vendors),
        'stakeholder_count' => count($stakeholderAssignments)
    ]);

} catch (Exception $e) {
    // Database had a moment. Log it, return empty arrays so the
    // frontend doesn't explode, and hope nobody notices.
    error_log('Get user vendors error: ' . $e->getMessage());
    echo json_encode([
        'error' => 'Failed to fetch vendor data',
        'vendors' => [],
        'stakeholder_assignments' => []
    ]);
}
