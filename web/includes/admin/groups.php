<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: ACL Group Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The ACL groups overview page. Currently read-only -- groups are managed
 * through the user management section (because adding/removing users from
 * groups felt more natural over there). This page just shows you what
 * groups exist, what they're for, and how many people are in each one.
 *
 * No POST handlers here. It's basically a lookup table with a nice UI.
 * Sometimes the simplest pages are the most satisfying.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// Data Loading
// ============================================================================
$allGroups = $acl->getAllGroups();

// Bulk fetch all user group assignments in a single query (avoids N+1 problem)
$userGroupAssignments = $acl->getAllUserGroups();

// ============================================================================
// HTML: Group Listing
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title">ACL Group Management</h1>
    <p>View and manage access control groups.</p>
</div>
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Group Name</th>
                <th>Display Name</th>
                <th>Description</th>
                <th>Members</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allGroups as $group): ?>
            <tr>
                <td><strong><?php echo e($group['group_name']); ?></strong></td>
                <td><?php echo e($group['display_name']); ?></td>
                <td><?php echo e($group['description']); ?></td>
                <td>
                    <?php
                    $memberCount = 0;
                    foreach ($userGroupAssignments as $groups) {
                        if (in_array($group['group_name'], $groups)) $memberCount++;
                    }
                    echo $memberCount . ' user' . ($memberCount !== 1 ? 's' : '');
                    ?>
                </td>
                <td><?php echo $group['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
