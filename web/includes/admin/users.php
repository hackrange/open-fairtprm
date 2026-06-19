<?php
/**
 * Admin Section: User Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The people wrangler. Create users, edit them, reset their passwords, assign
 * them to ACL groups, impersonate them (for debugging, we promise), and
 * delete them when they leave the company. Features a typeahead search,
 * group filter tags, pagination, and more modals than a luxury car dealership.
 *
 * The impersonation feature lets admins see the app from another user's
 * perspective -- super useful for support tickets. Super admins can impersonate
 * anyone; regular admins can't impersonate super admins (nice try though).
 *
 * Deleting a user with vendors? No problem -- we'll show you what they own
 * and let you reassign everything to someone else before pulling the trigger.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handlers: create_user, update_user, assign/remove group,
//                impersonate, stop_impersonation, delete_user
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: privilege-tier enforcement. Only super administrators may grant/revoke
    // admin & super-admin status, assign the administrator group, or act on a super-admin
    // account. requireAdmin() (which gates this page) is satisfied by ordinary admins too,
    // so these checks must be made explicitly here.
    $callerIsSuperAdmin = (bool)$session->get('is_super_admin');
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['create_user'])) {
        try {
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            // SECURITY: only super administrators may create privileged accounts.
            $isAdmin = (isset($_POST['is_admin']) && $callerIsSuperAdmin) ? 1 : 0;
            $isSuperAdmin = (isset($_POST['is_super_admin']) && $callerIsSuperAdmin) ? 1 : 0;

            if (empty($username) || empty($fullName) || empty($email) || empty($password)) {
                $error = 'All fields are required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters';
            } else {
                $existing = $db->fetchOne('SELECT id FROM users WHERE username = :username', [':username' => $username]);
                if ($existing) {
                    $error = 'Username already exists';
                } else {
                    $newUserId = $db->insert('users', [
                        'username' => $username,
                        'full_name' => $fullName,
                        'email' => $email,
                        'password_hash' => (new Encryption())->hashPassword($password),
                        'is_admin' => $isAdmin,
                        'is_super_admin' => $isSuperAdmin,
                        'is_active' => 1
                    ]);

                    // Auto-assign ACL groups based on admin flags
                    if ($isSuperAdmin) {
                        $adminGroup = $acl->getGroupByName('administrator');
                        if ($adminGroup) {
                            $acl->assignUserToGroup($newUserId, $adminGroup['id']);
                        }
                    }
                    if ($isAdmin) {
                        $cyberGroup = $acl->getGroupByName('cyber_tprm');
                        if ($cyberGroup) {
                            $acl->assignUserToGroup($newUserId, $cyberGroup['id']);
                        }
                    }

                    $auth->audit($user['id'], 'user_create', 'users', $newUserId, [
                        'new' => ['username' => $username, 'email' => $email, 'full_name' => $fullName, 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin]
                    ]);
                    $success = 'User created successfully';
                }
            }
        } catch (Exception $e) {
            error_log('Error creating user: ' . $e->getMessage());
            $error = 'Failed to create user. Check the error log for details.';
        }
    } elseif (isset($_POST['update_user'])) {
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $isSuperAdmin = isset($_POST['is_super_admin']) ? 1 : 0;
            $newPassword = $_POST['new_password'] ?? '';

            if (empty($fullName) || empty($email)) {
                $error = 'Full name and email are required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address';
            } elseif (!empty($newPassword) && strlen($newPassword) < 8) {
                $error = 'Password must be at least 8 characters';
            } elseif (!$callerIsSuperAdmin && ($__t = $db->fetchOne('SELECT is_super_admin FROM users WHERE id = ?', [$userId])) && !empty($__t['is_super_admin'])) {
                // SECURITY: only super administrators may modify a super-admin account.
                $error = 'Only super administrators can modify a super administrator account.';
            } else {
                $oldUser = $db->fetchOne('SELECT full_name, email, is_admin, is_super_admin FROM users WHERE id = ?', [$userId]);
                // SECURITY: non-super-admins cannot grant or revoke admin / super-admin
                // privileges -- preserve the target's existing flags regardless of POST.
                if (!$callerIsSuperAdmin) {
                    $isAdmin = (int)($oldUser['is_admin'] ?? 0);
                    $isSuperAdmin = (int)($oldUser['is_super_admin'] ?? 0);
                }
                $updateData = [
                    'full_name' => $fullName,
                    'email' => $email,
                    'is_admin' => $isAdmin,
                    'is_super_admin' => $isSuperAdmin
                ];

                if (!empty($newPassword)) {
                    $updateData['password_hash'] = (new Encryption())->hashPassword($newPassword);
                }

                $db->update('users', $updateData, 'id = :id', [':id' => $userId]);
                $auditNew = ['full_name' => $fullName, 'email' => $email, 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin];
                if (!empty($newPassword)) { $auditNew['password_reset'] = true; }
                $auth->audit($user['id'], 'user_update', 'users', $userId, [
                    'old' => $oldUser ?: [],
                    'new' => $auditNew
                ]);

                // Sync ACL groups based on admin flags
                $adminGroup = $acl->getGroupByName('administrator');
                $cyberGroup = $acl->getGroupByName('cyber_tprm');

                if ($isSuperAdmin && $adminGroup) {
                    $acl->assignUserToGroup($userId, $adminGroup['id']);
                } elseif (!$isSuperAdmin && $adminGroup) {
                    $acl->removeUserFromGroup($userId, $adminGroup['id']);
                }

                if ($isAdmin && $cyberGroup) {
                    $acl->assignUserToGroup($userId, $cyberGroup['id']);
                } elseif (!$isAdmin && $cyberGroup) {
                    $acl->removeUserFromGroup($userId, $cyberGroup['id']);
                }

                $success = 'User updated successfully' . (!empty($newPassword) ? ' (password reset)' : '');
            }
        } catch (Exception $e) {
            error_log('Error updating user: ' . $e->getMessage());
            $error = 'Failed to update user';
        }
    } elseif (isset($_POST['sync_user_groups'])) {
        // Batch group sync: receives desired group IDs and computes the diff server-side.
        // Single POST avoids CSRF token rotation race condition.
        try {
            $userId = intval($_POST['user_id'] ?? 0);
            $desiredGroupIds = array_map('intval', json_decode($_POST['group_ids'] ?? '[]', true) ?: []);

            // SECURITY: only super administrators may modify a super-admin's groups.
            $__targetSuper = $db->fetchOne('SELECT is_super_admin FROM users WHERE id = ?', [$userId]);

            // Fetch current group assignments
            $currentRows = $db->fetchAll(
                'SELECT g.id, g.group_name FROM user_acl_groups ug JOIN acl_groups g ON g.id = ug.group_id WHERE ug.user_id = ?',
                [$userId]
            );
            $currentGroupIds = array_column($currentRows, 'id');
            $currentGroupMap = array_column($currentRows, 'group_name', 'id');

            $toAdd = array_diff($desiredGroupIds, $currentGroupIds);
            $toRemove = array_diff($currentGroupIds, $desiredGroupIds);

            // SECURITY: enforce privilege tiering for non-super-admin callers.
            if (!$callerIsSuperAdmin) {
                if ($__targetSuper && !empty($__targetSuper['is_super_admin'])) {
                    // Cannot touch a super-admin's groups at all.
                    $toAdd = [];
                    $toRemove = [];
                    $error = 'Only super administrators can modify a super administrator\'s groups.';
                } else {
                    // Cannot grant the administrator group (privilege escalation).
                    $adminGroup = $acl->getGroupByName('administrator');
                    if ($adminGroup) {
                        $toAdd = array_diff($toAdd, [$adminGroup['id']]);
                    }
                }
            }

            foreach ($toRemove as $groupId) {
                $acl->removeUserFromGroup($userId, $groupId);
                $auth->audit($user['id'], 'user_group_remove', 'user_acl_groups', $userId, [
                    'old' => ['user_id' => $userId, 'group_id' => $groupId, 'group_name' => $currentGroupMap[$groupId] ?? '']
                ]);
            }

            foreach ($toAdd as $groupId) {
                $acl->assignUserToGroup($userId, $groupId);
                $group = $db->fetchOne('SELECT group_name FROM acl_groups WHERE id = ?', [$groupId]);
                $auth->audit($user['id'], 'user_group_assign', 'user_acl_groups', $userId, [
                    'new' => ['user_id' => $userId, 'group_id' => $groupId, 'group_name' => $group['group_name'] ?? '']
                ]);
            }

            if (empty($error)) {
                $changes = count($toAdd) + count($toRemove);
                $success = $changes > 0 ? 'User groups updated successfully' : 'No group changes needed';
            }
        } catch (Exception $e) {
            error_log('Error syncing user groups: ' . $e->getMessage());
            $error = 'Failed to update user groups';
        }
    } elseif (isset($_POST['impersonate_user'])) {
        try {
            $targetUserId = intval($_POST['target_user_id'] ?? 0);
            $result = $auth->impersonate($targetUserId);
            if ($result['success']) {
                header('Location: index.php');
                exit;
            } else {
                $error = $result['message'];
            }
        } catch (Exception $e) {
            error_log('Error impersonating user: ' . $e->getMessage());
            $error = 'Failed to impersonate user';
        }
    } elseif (isset($_POST['stop_impersonation'])) {
        try {
            $result = $auth->stopImpersonation();
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        } catch (Exception $e) {
            error_log('Error stopping impersonation: ' . $e->getMessage());
            $error = 'Failed to stop impersonation';
        }
    } elseif (isset($_POST['toggle_user_active'])) {
        try {
            $toggleUserId = intval($_POST['toggle_user_id'] ?? 0);
            $currentUserId = $auth->getUserId();

            if ($toggleUserId <= 0) {
                $error = 'Invalid user ID';
            } elseif ($toggleUserId == $currentUserId) {
                $error = 'You cannot deactivate your own account';
            } else {
                $targetUser = $db->fetchOne('SELECT id, username, full_name, is_active, is_super_admin FROM users WHERE id = :id', [':id' => $toggleUserId]);
                if (!$targetUser) {
                    $error = 'User not found';
                } elseif (!$callerIsSuperAdmin && !empty($targetUser['is_super_admin'])) {
                    // SECURITY: only super administrators may activate/deactivate a super-admin.
                    $error = 'Only super administrators can change a super administrator account.';
                } else {
                    $newActive = $targetUser['is_active'] ? 0 : 1;
                    $db->update('users', ['is_active' => $newActive], 'id = :id', [':id' => $toggleUserId]);
                    $auth->audit($user['id'], $newActive ? 'user_activate' : 'user_deactivate', 'users', $toggleUserId, [
                        'old' => ['is_active' => $targetUser['is_active']],
                        'new' => ['is_active' => $newActive]
                    ]);
                    $success = 'User "' . $targetUser['full_name'] . '" has been ' . ($newActive ? 'activated' : 'deactivated');
                }
            }
        } catch (Exception $e) {
            error_log('Error toggling user active status: ' . $e->getMessage());
            $error = 'Failed to update user status';
        }
    } elseif (isset($_POST['delete_user'])) {
        try {
            $deleteUserId = intval($_POST['delete_user_id'] ?? 0);
            $reassignUserId = intval($_POST['reassign_user_id'] ?? 0);
            $currentUserId = $auth->getUserId();

            if ($deleteUserId <= 0) {
                $error = 'Invalid user ID';
            } elseif ($deleteUserId == $currentUserId) {
                $error = 'You cannot delete your own account';
            } else {
                // Get user info for confirmation message
                $userToDelete = $db->fetchOne('SELECT username, full_name, is_super_admin FROM users WHERE id = :id', [':id' => $deleteUserId]);

                if (!$userToDelete) {
                    $error = 'User not found';
                } elseif (!$callerIsSuperAdmin && !empty($userToDelete['is_super_admin'])) {
                    // SECURITY: only super administrators may delete a super-admin.
                    $error = 'Only super administrators can delete a super administrator account.';
                } else {
                    $db->beginTransaction();

                    // Reassign vendors if a reassign user is specified
                    $reassignedVendors = 0;
                    $reassignedStakeholders = 0;

                    if ($reassignUserId > 0 && $reassignUserId != $deleteUserId) {
                        // Reassign vendor ownership (created_by)
                        $stmt = $db->query(
                            'UPDATE vendor_onboarding_requests SET created_by = :new_user WHERE created_by = :old_user',
                            [':new_user' => $reassignUserId, ':old_user' => $deleteUserId]
                        );
                        $reassignedVendors = $stmt->rowCount();

                        // Reassign stakeholder assignments
                        // First, delete any duplicate assignments that would occur
                        $db->query(
                            'DELETE s1 FROM vendor_onboarding_stakeholders s1
                             INNER JOIN vendor_onboarding_stakeholders s2
                             ON s1.request_id = s2.request_id AND s2.user_id = :new_user
                             WHERE s1.user_id = :old_user',
                            [':new_user' => $reassignUserId, ':old_user' => $deleteUserId]
                        );

                        // Then reassign remaining stakeholder entries
                        $stmt = $db->query(
                            'UPDATE vendor_onboarding_stakeholders SET user_id = :new_user WHERE user_id = :old_user',
                            [':new_user' => $reassignUserId, ':old_user' => $deleteUserId]
                        );
                        $reassignedStakeholders = $stmt->rowCount();
                    }

                    // Delete user's group assignments
                    $db->query('DELETE FROM user_acl_groups WHERE user_id = :user_id', [':user_id' => $deleteUserId]);

                    // Delete the user
                    $db->query('DELETE FROM users WHERE id = :id', [':id' => $deleteUserId]);

                    $db->commit();

                    $auth->audit($user['id'], 'user_delete', 'users', $deleteUserId, [
                        'old' => ['username' => $userToDelete['username'], 'full_name' => $userToDelete['full_name'], 'reassigned_to' => $reassignUserId ?: null, 'reassigned_vendors' => $reassignedVendors, 'reassigned_stakeholders' => $reassignedStakeholders]
                    ]);
                    $success = 'User "' . $userToDelete['full_name'] . '" (' . $userToDelete['username'] . ') has been deleted';
                    if ($reassignedVendors > 0 || $reassignedStakeholders > 0) {
                        $success .= '. Reassigned ' . $reassignedVendors . ' vendor(s) and ' . $reassignedStakeholders . ' stakeholder assignment(s).';
                    }
                }
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error deleting user: ' . $e->getMessage());
            $error = 'Failed to delete user. Check the error log for details.';
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$allUsers = $db->fetchAll('SELECT id, username, email, full_name, is_active, is_admin, is_super_admin, totp_enabled, last_login FROM users ORDER BY id');
$allGroups = $acl->getAllGroups();

// Bulk fetch all user group assignments in a single query (avoids N+1 problem)
$userGroupAssignments = $acl->getAllUserGroups();

// ============================================================================
// HTML: User Management Table + Modals + JavaScript
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title">User Management</h1>
    <p>Create and manage user accounts.</p>
</div>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
        <div style="position: relative; flex: 1; min-width: 250px; max-width: 400px;">
            <input type="text" id="userSearchInput" placeholder="Search users by name, email, or username..."
                   class="focus-ring"
                   style="width: 100%; padding: 10px 70px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                   autocomplete="off">
            <span id="userSearchClear" data-action="clearUserSearch" style="position: absolute; right: 36px; top: 50%; transform: translateY(-50%); color: #999; cursor: pointer; display: none; font-size: 18px;">&times;</span>
            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">🔍</span>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="admin-users-import.php" class="btn" style="background: #6c757d; color: white;">Import Users</a>
            <button class="btn btn-primary" data-action="openCreateUserModal">+ Create User</button>
        </div>
    </div>
    <div style="margin-bottom: 15px;">
        <span style="font-size: 13px; color: #666; margin-right: 10px;">Filter:</span>
        <span class="group-filter-tag" data-group="__superadmin__" data-action="filterByGroup" data-arg="__superadmin__" style="display: inline-block; padding: 4px 12px; margin: 2px 4px 2px 0; background: #9333ea; color: white; border-radius: 16px; font-size: 12px; cursor: pointer; transition: all 0.2s;">Super Admin</span>
        <?php foreach ($allGroups as $group): ?>
        <span class="group-filter-tag" data-group="<?php echo e($group['group_name']); ?>" data-action="filterByGroup" data-arg="<?php echo e($group['group_name']); ?>" style="display: inline-block; padding: 4px 12px; margin: 2px 4px 2px 0; background: #e5e7eb; color: #374151; border-radius: 16px; font-size: 12px; cursor: pointer; transition: all 0.2s;"><?php echo e($group['display_name'] ?: $group['group_name']); ?></span>
        <?php endforeach; ?>
        <span style="margin-left: 10px; border-left: 1px solid #ddd; padding-left: 10px;"></span>
        <span class="group-filter-tag" data-group="__active__" data-action="filterByGroup" data-arg="__active__" style="display: inline-block; padding: 4px 12px; margin: 2px 4px 2px 0; background: #10b981; color: white; border-radius: 16px; font-size: 12px; cursor: pointer; transition: all 0.2s;">Active</span>
        <span class="group-filter-tag" data-group="__inactive__" data-action="filterByGroup" data-arg="__inactive__" style="display: inline-block; padding: 4px 12px; margin: 2px 4px 2px 0; background: #ef4444; color: white; border-radius: 16px; font-size: 12px; cursor: pointer; transition: all 0.2s;">Inactive</span>
        <style>
            .group-filter-tag:hover { opacity: 0.8; }
            .group-filter-tag.active { box-shadow: 0 0 0 3px rgba(0,0,0,0.2); }
        </style>
    </div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div id="userSearchResults" style="color: #666; font-size: 13px;">
            Showing <span id="userShowingRange">1-<?php echo min(25, count($allUsers)); ?></span> of <span id="userTotalCount"><?php echo count($allUsers); ?></span> users
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <label for="perPageSelect" style="font-size: 13px; color: #666;">Show:</label>
                <select id="perPageSelect" data-action="changePerPage" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div id="paginationControls" style="display: flex; align-items: center; gap: 5px;">
                <button data-action="goToPage" data-arg="first" id="pageFirst" class="btn btn-sm" style="padding: 4px 8px; background: #f3f4f6; border: 1px solid #ddd;" title="First page">«</button>
                <button data-action="goToPage" data-arg="prev" id="pagePrev" class="btn btn-sm" style="padding: 4px 8px; background: #f3f4f6; border: 1px solid #ddd;" title="Previous page">‹</button>
                <span id="pageInfo" style="font-size: 13px; color: #666; padding: 0 10px;">Page 1 of 1</span>
                <button data-action="goToPage" data-arg="next" id="pageNext" class="btn btn-sm" style="padding: 4px 8px; background: #f3f4f6; border: 1px solid #ddd;" title="Next page">›</button>
                <button data-action="goToPage" data-arg="last" id="pageLast" class="btn btn-sm" style="padding: 4px 8px; background: #f3f4f6; border: 1px solid #ddd;" title="Last page">»</button>
            </div>
        </div>
    </div>
    <table id="usersTable">
        <thead>
            <tr>
                <th>Username</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Groups</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="usersTableBody">
            <?php foreach ($allUsers as $u): ?>
            <tr class="user-row" data-username="<?php echo e(strtolower($u['username'])); ?>" data-fullname="<?php echo e(strtolower($u['full_name'])); ?>" data-email="<?php echo e(strtolower($u['email'])); ?>" data-groups="<?php echo e(strtolower(implode(' ', $userGroupAssignments[$u['id']] ?? []))); ?>" data-superadmin="<?php echo $u['is_super_admin'] ? '1' : '0'; ?>" data-active="<?php echo $u['is_active'] ? '1' : '0'; ?>">
                <td><strong><?php echo e($u['username']); ?></strong></td>
                <td><?php echo e($u['full_name']); ?></td>
                <td><?php echo e($u['email']); ?></td>
                <td>
                    <?php if ($u['is_super_admin']): ?>
                        <span class="badge badge-purple">Super Admin</span>
                    <?php endif; ?>
                    <?php
                    $groups = $userGroupAssignments[$u['id']] ?? [];
                    foreach ($groups as $groupName) {
                        echo '<span class="badge badge-blue">' . e($groupName) . '</span> ';
                    }
                    if (empty($groups) && !$u['is_super_admin']) {
                        echo '<span style="color: #999;">None</span>';
                    }
                    ?>
                </td>
                <td>
                    <?php echo $u['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'; ?>
                    <?php if ($u['totp_enabled']): ?>
                        <span class="badge badge-success">2FA</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['last_login']): ?>
                        <?php echo date('M j, Y g:i A', strtotime($u['last_login'])); ?>
                    <?php else: ?>
                        <span style="color: #999;">Never</span>
                    <?php endif; ?>
                </td>
                <td style="white-space: nowrap;">
                    <button class="btn btn-primary btn-sm" data-action="openEditModal" data-args="[<?php echo (int)$u['id']; ?>, <?php echo e(json_encode($u['full_name'])); ?>, <?php echo e(json_encode($u['email'])); ?>, <?php echo $u['is_admin'] ? 'true' : 'false'; ?>, <?php echo $u['is_super_admin'] ? 'true' : 'false'; ?>]">Edit</button>
                    <button class="btn btn-sm" style="background: #3b82f6; color: white;" data-action="openAssignGroupsModal" data-args="[<?php echo $u['id']; ?>, <?php echo e(json_encode($u['full_name'])); ?>, <?php echo e(json_encode($userGroupAssignments[$u['id']] ?? [])); ?>]">Groups</button>
                    <?php
                    // Show impersonate button if:
                    // - Not impersonating currently
                    // - User is active
                    // - Not the current user
                    // - Current admin can impersonate (super admin can impersonate anyone, regular admin cannot impersonate super admins)
                    $canImpersonate = !$auth->isImpersonating()
                        && $u['is_active']
                        && $u['id'] != $auth->getUserId()
                        && ($session->get('is_super_admin') || !$u['is_super_admin']);
                    if ($canImpersonate):
                    ?>
                    <button class="btn btn-sm" style="background: #f59e0b; color: white;" data-action="confirmImpersonate" data-args="[<?php echo (int)$u['id']; ?>, <?php echo htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES, 'UTF-8'); ?>]">Impersonate</button>
                    <?php endif; ?>
                    <?php if ($u['id'] != $user['id']): ?>
                    <form method="POST" action="admin.php?section=users" style="display: inline;" onsubmit="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> user <?php echo e($u['full_name']); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="toggle_user_id" value="<?php echo (int)$u['id']; ?>">
                        <?php if ($u['is_active']): ?>
                            <button type="submit" name="toggle_user_active" class="btn btn-sm" style="background: #ef4444; color: white;">Deactivate</button>
                        <?php else: ?>
                            <button type="submit" name="toggle_user_active" class="btn btn-sm" style="background: #10b981; color: white;">Activate</button>
                        <?php endif; ?>
                    </form>
                    <button class="btn btn-sm" style="background: #dc2626; color: white;" data-action="confirmDeleteUser" data-args="[<?php echo (int)$u['id']; ?>, <?php echo htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($u['username']), ENT_QUOTES, 'UTF-8'); ?>]">Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="modal">
    <div class="modal-content">
        <span class="close" data-action="closeCreateUserModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);">Create New User</h3>
        <form method="POST" action="admin.php?section=users" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div class="form-group">
                <label for="create_username">Username *</label>
                <input type="text" id="create_username" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="create_full_name">Full Name *</label>
                <input type="text" id="create_full_name" name="full_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="create_email">Email Address *</label>
                <input type="email" id="create_email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="create_password">Password *</label>
                <input type="password" id="create_password" name="password" class="form-control" required minlength="8">
                <div class="form-help">Minimum 8 characters</div>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="is_admin" value="1"><span>Administrator</span></label>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="is_super_admin" value="1"><span>Super Administrator</span></label>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                <button type="button" class="btn btn-secondary" data-action="closeCreateUserModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <span class="close" data-action="closeEditModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);">Edit User</h3>
        <form method="POST" action="admin.php?section=users" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="edit_user_id" name="user_id" value="">
            <div class="form-group">
                <label for="edit_full_name">Full Name</label>
                <input type="text" id="edit_full_name" name="full_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="edit_email">Email Address</label>
                <input type="email" id="edit_email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="edit_new_password">New Password (Optional)</label>
                <input type="password" id="edit_new_password" name="new_password" class="form-control" minlength="8">
                <div class="form-help">Leave blank to keep current password</div>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" id="edit_is_admin" name="is_admin" value="1"><span>Administrator</span></label>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" id="edit_is_super_admin" name="is_super_admin" value="1"><span>Super Administrator</span></label>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="update_user" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" data-action="closeEditModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Assign Groups Modal -->
<div id="assignGroupsModal" class="modal">
    <div class="modal-content">
        <span class="close" data-action="closeAssignGroupsModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);">Assign ACL Groups</h3>
        <p id="assign_groups_username" style="color: #666; margin-bottom: 15px;"></p>
        <form id="assignGroupsForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="assign_user_id" value="">
            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                <?php foreach ($allGroups as $group): ?>
                <div class="form-group" style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0;">
                    <label class="checkbox-label" style="align-items: flex-start;">
                        <input type="checkbox" class="group-checkbox" data-group-id="<?php echo $group['id']; ?>" data-group-name="<?php echo e($group['group_name']); ?>" style="margin-top: 3px;">
                        <div>
                            <strong><?php echo e($group['display_name']); ?></strong>
                            <div class="form-help"><?php echo e($group['description']); ?></div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" data-action="saveGroupAssignments">Save Assignments</button>
                <button type="button" class="btn btn-secondary" data-action="closeAssignGroupsModal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal -->
<div id="deleteUserModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" data-action="closeDeleteUserModal">&times;</span>
        <h3 style="margin-top: 0; color: #dc2626;">Delete User</h3>
        <p id="delete_user_info" style="color: #666; margin-bottom: 15px;"></p>

        <div id="delete_user_vendors_section" style="display: none; margin-bottom: 20px;">
            <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                <div style="display: flex; align-items: start; gap: 10px;">
                    <span style="font-size: 20px;">⚠️</span>
                    <div>
                        <strong style="color: #92400e;">This user has associated vendors</strong>
                        <p style="margin: 5px 0 0; font-size: 13px; color: #78350f;">
                            Please select a user to reassign the vendors to, or they will be orphaned.
                        </p>
                    </div>
                </div>
            </div>

            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                <h4 style="margin: 0 0 10px; font-size: 14px; color: #333;">Vendors Created By This User:</h4>
                <div id="delete_user_vendor_list" style="max-height: 150px; overflow-y: auto; font-size: 13px; color: #666;"></div>
            </div>

            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; margin-bottom: 15px;" id="delete_user_stakeholder_section">
                <h4 style="margin: 0 0 10px; font-size: 14px; color: #333;">Stakeholder Assignments:</h4>
                <div id="delete_user_stakeholder_list" style="max-height: 100px; overflow-y: auto; font-size: 13px; color: #666;"></div>
            </div>

            <div class="form-group">
                <label for="reassign_user_input" style="font-weight: 500;">Reassign vendors to:</label>
                <div style="position: relative; margin-top: 5px;">
                    <input type="text" id="reassign_user_input" class="form-control"
                           placeholder="Search for a user to reassign to..."
                           autocomplete="off"
                           style="padding-right: 30px;">
                    <span id="reassign_clear_btn" data-action="clearReassignUser"
                          style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #999; display: none; font-size: 16px;">&times;</span>
                    <div id="reassign_user_dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000;">
                    </div>
                </div>
                <div id="reassign_user_selected" style="margin-top: 8px; padding: 8px 12px; background: #dbeafe; border-radius: 4px; display: none; font-size: 13px;">
                    <span style="color: #1e40af;">Reassigning to: </span>
                    <strong id="reassign_user_selected_name"></strong>
                    <span data-action="clearReassignUser" style="margin-left: 10px; cursor: pointer; color: #1e40af;">&times; Remove</span>
                </div>
                <div style="margin-top: 5px; font-size: 12px; color: #666;">
                    Leave empty to not reassign (vendors will be orphaned)
                </div>
            </div>
            <!-- User data for typeahead -->
            <script type="application/json" id="reassign_users_data">
            <?php
            $usersForReassign = [];
            foreach ($allUsers as $u) {
                $usersForReassign[] = [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'full_name' => $u['full_name'],
                    'email' => $u['email']
                ];
            }
            echo json_encode($usersForReassign);
            ?>
            </script>
        </div>

        <div id="delete_user_no_vendors" style="display: none; background: #dcfce7; border: 1px solid #22c55e; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 20px;">✓</span>
                <div style="color: #166534; font-size: 13px;">
                    This user has no vendors or stakeholder assignments to reassign.
                </div>
            </div>
        </div>

        <form method="POST" action="admin.php?section=users" id="deleteUserForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="delete_user_id" name="delete_user_id" value="">
            <input type="hidden" id="reassign_user_id" name="reassign_user_id" value="0">
            <input type="hidden" name="delete_user" value="1">

            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary" data-action="closeDeleteUserModal">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626; color: white;" id="confirmDeleteBtn">Delete User</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for impersonation -->
<form id="impersonateForm" method="POST" action="admin.php?section=users" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
    <input type="hidden" id="impersonate_target_user_id" name="target_user_id" value="">
    <input type="hidden" name="impersonate_user" value="1">
</form>

<script nonce="<?php echo cspNonce(); ?>">
    let currentUserGroups = [];
    let currentUserId = null;

    function openCreateUserModal() { document.getElementById('createUserModal').style.display = 'block'; }
    function closeCreateUserModal() { document.getElementById('createUserModal').style.display = 'none'; }

    function openEditModal(userId, fullName, email, isAdmin, isSuperAdmin) {
        document.getElementById('edit_user_id').value = userId;
        document.getElementById('edit_full_name').value = fullName;
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_is_admin').checked = isAdmin;
        document.getElementById('edit_is_super_admin').checked = isSuperAdmin;
        document.getElementById('editUserModal').style.display = 'block';
    }
    function closeEditModal() { document.getElementById('editUserModal').style.display = 'none'; }

    function openAssignGroupsModal(userId, fullName, userGroups) {
        currentUserId = userId;
        currentUserGroups = userGroups || [];
        document.getElementById('assign_user_id').value = userId;
        document.getElementById('assign_groups_username').textContent = 'Assigning groups for: ' + fullName;
        document.querySelectorAll('.group-checkbox').forEach(cb => {
            cb.checked = currentUserGroups.includes(cb.getAttribute('data-group-name'));
        });
        document.getElementById('assignGroupsModal').style.display = 'block';
    }
    function closeAssignGroupsModal() { document.getElementById('assignGroupsModal').style.display = 'none'; }

    let _savingGroups = false;
    async function saveGroupAssignments() {
        if (_savingGroups) return;
        _savingGroups = true;
        const checkboxes = document.querySelectorAll('.group-checkbox');
        const selectedGroupIds = [];

        checkboxes.forEach(cb => {
            if (cb.checked) {
                selectedGroupIds.push(cb.getAttribute('data-group-id'));
            }
        });

        // Single batch POST — server computes the diff, avoids CSRF token rotation issues
        const resp = await fetch('admin.php?section=users', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: '<?php echo e($csrfToken); ?>',
                user_id: currentUserId,
                group_ids: JSON.stringify(selectedGroupIds),
                sync_user_groups: '1'
            })
        });

        window.location.reload();
    }

    function confirmImpersonate(userId, fullName) {
        if (confirm('Are you sure you want to impersonate "' + fullName + '"?\n\nYou will be logged in as this user and see the application from their perspective. You can stop impersonation at any time from the admin panel.')) {
            document.getElementById('impersonate_target_user_id').value = userId;
            document.getElementById('impersonateForm').submit();
        }
    }

    window.onclick = function(event) {
        ['editUserModal', 'createUserModal', 'assignGroupsModal'].forEach(id => {
            if (event.target == document.getElementById(id)) {
                document.getElementById(id).style.display = 'none';
            }
        });
    }
</script>

<script nonce="<?php echo cspNonce(); ?>">
// Delete user modal functions
let reassignUsersData = [];
let deleteUserIdExclude = 0;

// Load user data for typeahead
try {
    reassignUsersData = JSON.parse(document.getElementById('reassign_users_data').textContent);
} catch (e) {
    console.error('Failed to load users data:', e);
}

function confirmDeleteUser(userId, fullName, username) {
    // Set basic info
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('delete_user_info').innerHTML = 'You are about to delete <strong>' + escapeHtml(fullName) + '</strong> (' + escapeHtml(username) + '). This action cannot be undone.';

    // Store the user being deleted to exclude from typeahead
    deleteUserIdExclude = userId;

    // Clear any previous selection
    clearReassignUser();

    // Fetch vendor data for this user
    fetch('api/get-user-vendors.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            const vendorsSection = document.getElementById('delete_user_vendors_section');
            const noVendorsSection = document.getElementById('delete_user_no_vendors');
            const vendorList = document.getElementById('delete_user_vendor_list');
            const stakeholderList = document.getElementById('delete_user_stakeholder_list');
            const stakeholderSection = document.getElementById('delete_user_stakeholder_section');

            if (data.vendors && data.vendors.length > 0) {
                // Show vendors
                let vendorHtml = '<ul style="margin: 0; padding-left: 20px;">';
                data.vendors.forEach(v => {
                    vendorHtml += '<li>' + escapeHtml(v.vendor_name) + ' <span style="color: #999;">(' + v.status + ')</span></li>';
                });
                vendorHtml += '</ul>';
                vendorList.innerHTML = vendorHtml;
                vendorsSection.style.display = 'block';
                noVendorsSection.style.display = 'none';
            } else {
                vendorList.innerHTML = '<em>None</em>';
            }

            if (data.stakeholder_assignments && data.stakeholder_assignments.length > 0) {
                // Show stakeholder assignments
                let stakeholderHtml = '<ul style="margin: 0; padding-left: 20px;">';
                data.stakeholder_assignments.forEach(s => {
                    stakeholderHtml += '<li>' + escapeHtml(s.vendor_name) + ' <span style="color: #999;">(' + s.role + ')</span></li>';
                });
                stakeholderHtml += '</ul>';
                stakeholderList.innerHTML = stakeholderHtml;
                stakeholderSection.style.display = 'block';
            } else {
                stakeholderSection.style.display = 'none';
            }

            // Show appropriate section
            if ((data.vendors && data.vendors.length > 0) || (data.stakeholder_assignments && data.stakeholder_assignments.length > 0)) {
                vendorsSection.style.display = 'block';
                noVendorsSection.style.display = 'none';
            } else {
                vendorsSection.style.display = 'none';
                noVendorsSection.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error fetching vendor data:', error);
            document.getElementById('delete_user_vendors_section').style.display = 'none';
            document.getElementById('delete_user_no_vendors').style.display = 'block';
        });

    // Open modal
    document.getElementById('deleteUserModal').style.display = 'flex';
}

function closeDeleteUserModal() {
    document.getElementById('deleteUserModal').style.display = 'none';
    document.getElementById('reassign_user_dropdown').style.display = 'none';
}

// Typeahead for reassign user
const reassignInput = document.getElementById('reassign_user_input');
const reassignDropdown = document.getElementById('reassign_user_dropdown');
const reassignClearBtn = document.getElementById('reassign_clear_btn');
let reassignDebounce = null;

reassignInput.addEventListener('input', function() {
    clearTimeout(reassignDebounce);
    const query = this.value.toLowerCase().trim();

    reassignClearBtn.style.display = query.length > 0 ? 'block' : 'none';

    if (query.length < 1) {
        reassignDropdown.style.display = 'none';
        return;
    }

    reassignDebounce = setTimeout(() => {
        const matches = reassignUsersData.filter(u => {
            if (u.id == deleteUserIdExclude) return false;
            return u.full_name.toLowerCase().includes(query) ||
                   u.username.toLowerCase().includes(query) ||
                   u.email.toLowerCase().includes(query);
        }).slice(0, 10);

        if (matches.length > 0) {
            let html = '';
            matches.forEach(u => {
                html += '<div class="reassign-option hover-bg-gray" data-id="' + u.id + '" data-name="' + escapeHtml(u.full_name) + '" style="padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s;">';
                html += '<div style="font-weight: 500; color: #333;">' + escapeHtml(u.full_name) + '</div>';
                html += '<div style="font-size: 12px; color: #666;">' + escapeHtml(u.username) + ' &bull; ' + escapeHtml(u.email) + '</div>';
                html += '</div>';
            });
            reassignDropdown.innerHTML = html;
            reassignDropdown.style.display = 'block';

            // Add click handlers
            reassignDropdown.querySelectorAll('.reassign-option').forEach(opt => {
                opt.addEventListener('click', function() {
                    selectReassignUser(this.dataset.id, this.dataset.name);
                });
            });
        } else {
            reassignDropdown.innerHTML = '<div style="padding: 10px 12px; color: #999; font-size: 13px;">No users found</div>';
            reassignDropdown.style.display = 'block';
        }
    }, 150);
});

reassignInput.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        reassignDropdown.style.display = 'none';
    }
});

function selectReassignUser(userId, fullName) {
    document.getElementById('reassign_user_id').value = userId;
    document.getElementById('reassign_user_selected_name').textContent = fullName;
    document.getElementById('reassign_user_selected').style.display = 'block';
    reassignInput.value = '';
    reassignInput.style.display = 'none';
    reassignDropdown.style.display = 'none';
    reassignClearBtn.style.display = 'none';
}

function clearReassignUser() {
    document.getElementById('reassign_user_id').value = '0';
    document.getElementById('reassign_user_selected').style.display = 'none';
    reassignInput.value = '';
    reassignInput.style.display = 'block';
    reassignDropdown.style.display = 'none';
    reassignClearBtn.style.display = 'none';
}

// Escape HTML helper
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal when clicking outside
document.getElementById('deleteUserModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteUserModal();
    }
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!reassignInput.contains(e.target) && !reassignDropdown.contains(e.target)) {
        reassignDropdown.style.display = 'none';
    }
});
</script>

<script nonce="<?php echo cspNonce(); ?>">
// User search and pagination functionality
(function() {
    const searchInput = document.getElementById('userSearchInput');
    const clearBtn = document.getElementById('userSearchClear');
    const tableBody = document.getElementById('usersTableBody');

    if (!searchInput || !tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('.user-row'));
    let filteredRows = [...allRows];
    let currentPage = 1;
    let perPage = 25;
    let activeGroupFilter = null;
    let debounceTimer;

    function getFilteredRows() {
        const query = searchInput.value.toLowerCase().trim();

        let rows = allRows;

        // Apply group/status filter first
        if (activeGroupFilter) {
            rows = rows.filter(row => {
                // Handle special filters
                if (activeGroupFilter === '__superadmin__') {
                    return row.dataset.superadmin === '1';
                } else if (activeGroupFilter === '__active__') {
                    return row.dataset.active === '1';
                } else if (activeGroupFilter === '__inactive__') {
                    return row.dataset.active === '0';
                } else {
                    // Regular group filter
                    const groups = row.dataset.groups || '';
                    return groups.includes(activeGroupFilter.toLowerCase());
                }
            });
        }

        // Then apply search filter
        if (query.length > 0) {
            const queryParts = query.split(/\s+/);
            rows = rows.filter(row => {
                const username = row.dataset.username || '';
                const fullname = row.dataset.fullname || '';
                const email = row.dataset.email || '';
                const groups = row.dataset.groups || '';
                const searchText = `${username} ${fullname} ${email} ${groups}`;
                return queryParts.every(part => searchText.includes(part));
            });
        }

        return rows;
    }

    function updateDisplay() {
        filteredRows = getFilteredRows();
        const totalFiltered = filteredRows.length;
        const totalPages = Math.ceil(totalFiltered / perPage) || 1;

        // Ensure current page is valid
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIndex = (currentPage - 1) * perPage;
        const endIndex = Math.min(startIndex + perPage, totalFiltered);

        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');

        // Show only rows for current page
        for (let i = startIndex; i < endIndex; i++) {
            filteredRows[i].style.display = '';
        }

        // Update showing range
        const showingRange = document.getElementById('userShowingRange');
        const totalCount = document.getElementById('userTotalCount');
        if (showingRange) {
            if (totalFiltered === 0) {
                showingRange.textContent = '0';
            } else {
                showingRange.textContent = `${startIndex + 1}-${endIndex}`;
            }
        }
        if (totalCount) {
            totalCount.textContent = totalFiltered;
        }

        // Update page info
        const pageInfo = document.getElementById('pageInfo');
        if (pageInfo) {
            pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        }

        // Update button states
        const pageFirst = document.getElementById('pageFirst');
        const pagePrev = document.getElementById('pagePrev');
        const pageNext = document.getElementById('pageNext');
        const pageLast = document.getElementById('pageLast');

        const disabledStyle = { opacity: '0.5', cursor: 'not-allowed' };
        const enabledStyle = { opacity: '1', cursor: 'pointer' };

        if (pageFirst) Object.assign(pageFirst.style, currentPage === 1 ? disabledStyle : enabledStyle);
        if (pagePrev) Object.assign(pagePrev.style, currentPage === 1 ? disabledStyle : enabledStyle);
        if (pageNext) Object.assign(pageNext.style, currentPage >= totalPages ? disabledStyle : enabledStyle);
        if (pageLast) Object.assign(pageLast.style, currentPage >= totalPages ? disabledStyle : enabledStyle);

        // Show/hide clear button
        const query = searchInput.value.trim();
        if (clearBtn) {
            clearBtn.style.display = (query.length > 0 || activeGroupFilter) ? 'block' : 'none';
        }
    }

    // Expose functions globally
    window.goToPage = function(action) {
        const totalPages = Math.ceil(filteredRows.length / perPage) || 1;
        switch(action) {
            case 'first': currentPage = 1; break;
            case 'prev': if (currentPage > 1) currentPage--; break;
            case 'next': if (currentPage < totalPages) currentPage++; break;
            case 'last': currentPage = totalPages; break;
        }
        updateDisplay();
    };

    window.changePerPage = function(value) {
        perPage = parseInt(value);
        currentPage = 1;
        updateDisplay();
    };

    window.filterByGroup = function(groupName) {
        if (activeGroupFilter === groupName) {
            // Toggle off
            activeGroupFilter = null;
            document.querySelectorAll('.group-filter-tag').forEach(tag => {
                tag.classList.remove('active');
            });
        } else {
            activeGroupFilter = groupName;
            document.querySelectorAll('.group-filter-tag').forEach(tag => {
                tag.classList.toggle('active', tag.dataset.group === groupName);
            });
        }
        currentPage = 1;
        updateDisplay();
    };

    window.clearUserSearch = function() {
        searchInput.value = '';
        activeGroupFilter = null;
        document.querySelectorAll('.group-filter-tag').forEach(tag => {
            tag.classList.remove('active');
        });
        currentPage = 1;
        searchInput.focus();
        updateDisplay();
    };

    // Debounced search
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            currentPage = 1;
            updateDisplay();
        }, 150);
    });

    // Immediate search on Enter, clear on Escape
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(debounceTimer);
            currentPage = 1;
            updateDisplay();
        }
        if (e.key === 'Escape') {
            clearUserSearch();
        }
    });

    // Initial display
    updateDisplay();
})();
</script>
