<?php
/**
 * User Search API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Powers the type-ahead dropdowns for assigning stakeholders to vendor requests.
 * When someone starts typing a name in the "assign stakeholder" field, this guy
 * wakes up and rummages through the users table looking for matches. It searches
 * by username, full name, and email -- and it's smart enough to prioritize exact
 * prefix matches over substring matches, because "john" should find "John Smith"
 * before it finds "Eltonjohn McWeirdname." Limited to 10 results because nobody
 * is scrolling through 500 users in a dropdown. Nobody.
 */

// JSON all day every day
header('Content-Type: application/json');

// Fire up the application -- load configs, classes, session, etc.
require_once '../includes/init.php';

// ---------------------------------------------------------------
// AUTHENTICATION CHECK
// You shall not pass (without a valid session)
// ---------------------------------------------------------------
if (!Auth::getInstance()->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Round up the usual suspects: auth, database, ACL, session, and the current user
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();

// ---------------------------------------------------------------
// PERMISSION GAUNTLET
// Who's allowed to search for users to assign? Let's see:
//   - Admins (obviously, they can do anything)
//   - Procurement folks (they need to assign people to their requests)
//   - Anyone with the magic 'assign_stakeholder' permission
//   - Current stakeholders on the request (they can hand off their duties)
// Basically, if you have a legit reason to reassign someone, you're in.
// ---------------------------------------------------------------
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || $acl->hasGroup('administrator');
$isProcurement = $acl->hasGroup('procurement');
// SECURITY: a bare 'stakeholder' must NOT be able to enumerate the full user directory.
// Only roles that legitimately assign stakeholders (admin / procurement / explicit
// onboarding.assign_stakeholder permission) get unscoped search. A stakeholder who needs
// to reassign their own role still qualifies via the request_id-scoped check below
// (they must already be a stakeholder on that specific request).
$canAssignStakeholders = $acl->hasPermission('onboarding.assign_stakeholder') || $isAdmin || $isProcurement;

// Sneaky backdoor: if you're already a stakeholder on this specific request,
// you can search for users too (so you can reassign your role to someone else)
$requestId = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;
if ($requestId > 0 && !$canAssignStakeholders) {
    $stakeholderCheck = $db->fetchOne(
        'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :request_id AND user_id = :user_id',
        [':request_id' => $requestId, ':user_id' => $user['id']]
    );
    if (!empty($stakeholderCheck)) {
        $canAssignStakeholders = true;
    }
}

if (!$canAssignStakeholders) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// ---------------------------------------------------------------
// METHOD ENFORCEMENT
// This is a read operation, so GET only. If you POST here,
// you clearly didn't read the documentation. (There is no documentation.)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Need at least 2 characters before we start querying. One character would
// return half the company and bring the DB to its knees.
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'users' => []]);
    exit;
}

try {
    // ---------------------------------------------------------------
    // USER SEARCH QUERY
    // Searches across username, full_name, and email with LIKE wildcards.
    // The CASE statement in ORDER BY gives priority to prefix matches
    // (starts-with) over substring matches (contains). So if you type "tim",
    // "Tim Rice" beats "Overtime Timothy" every time. As it should.
    //
    // Optional `groups` parameter filters results to users in specific ACL groups.
    // Used by case management to restrict assignee selection by role.
    // ---------------------------------------------------------------
    $query = str_replace(['%', '_'], ['\\%', '\\_'], $query);
    $searchTerm = '%' . $query . '%';

    $filterGroups = isset($_GET['groups']) ? array_filter(array_map('trim', explode(',', $_GET['groups']))) : [];

    if (!empty($filterGroups)) {
        // Build individual placeholders for each group
        $groupPlaceholders = [];
        $params = [];
        foreach ($filterGroups as $i => $group) {
            $key = ':g' . $i;
            $groupPlaceholders[] = $key;
            $params[$key] = $group;
        }
        $groupIn = implode(', ', $groupPlaceholders);

        $params[':search1'] = $searchTerm;
        $params[':search2'] = $searchTerm;
        $params[':search3'] = $searchTerm;
        $params[':exact1'] = $query . '%';
        $params[':exact2'] = $query . '%';

        $users = $db->fetchAll(
            "SELECT DISTINCT u.id, u.username, u.full_name, u.email
             FROM users u
             INNER JOIN user_acl_groups uag ON u.id = uag.user_id
             INNER JOIN acl_groups ag ON uag.group_id = ag.id
             WHERE u.is_active = 1 AND ag.is_active = 1
               AND ag.group_name IN ($groupIn)
               AND (u.username LIKE :search1 OR u.full_name LIKE :search2 OR u.email LIKE :search3)
             ORDER BY
               CASE
                 WHEN u.username LIKE :exact1 THEN 1
                 WHEN u.full_name LIKE :exact2 THEN 2
                 ELSE 3
               END,
               u.full_name ASC
             LIMIT 10",
            $params
        );
    } else {
        $users = $db->fetchAll(
            "SELECT id, username, full_name, email
             FROM users
             WHERE is_active = 1
               AND (username LIKE :search1 OR full_name LIKE :search2 OR email LIKE :search3)
             ORDER BY
               CASE
                 WHEN username LIKE :exact1 THEN 1
                 WHEN full_name LIKE :exact2 THEN 2
                 ELSE 3
               END,
               full_name ASC
             LIMIT 10",
            [
                ':search1' => $searchTerm,
                ':search2' => $searchTerm,
                ':search3' => $searchTerm,
                ':exact1' => $query . '%',
                ':exact2' => $query . '%'
            ]
        );
    }

    // ---------------------------------------------------------------
    // FORMAT RESULTS FOR THE DROPDOWN
    // Build a nice display string like "Jane Doe (jdoe)" so the user
    // can tell apart the three different Janes in the company.
    // ---------------------------------------------------------------
    $results = [];
    foreach ($users as $u) {
        $displayName = !empty($u['full_name'])
            ? $u['full_name'] . ' (' . $u['username'] . ')'
            : $u['username'];

        $results[] = [
            'id' => $u['id'],
            'username' => $u['username'],
            'full_name' => $u['full_name'] ?? '',
            'email' => $u['email'] ?? '',
            'display' => $displayName
        ];
    }

    echo json_encode(['success' => true, 'users' => $results]);

} catch (Exception $e) {
    // Database went on vacation. Log it and send a generic "oops" back.
    error_log('User search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
