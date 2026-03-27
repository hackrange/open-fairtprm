<?php
/**
 * Access Control List (ACL) Manager - The Velvet Rope of Permissions
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Group-based permissions system that figures out who can do what. Users get
 * assigned to groups, groups get permissions, and this class is the middleman
 * that answers the eternal question: "am I allowed to do this?" It's like a
 * nightclub bouncer with a clipboard, except the clipboard is a database and
 * the nightclub is your application. Features an in-memory cache so we're not
 * hammering the DB with permission checks on every single request. Because
 * nobody likes a slow bouncer.
 */

class ACL {
    // Singleton -- one permissions gatekeeper to rule them all
    private static $instance = null;

    // Database and session references
    private $db;
    private $session;

    // In-memory cache for user groups and permissions.
    // Saves us from hitting the DB repeatedly in a single request.
    // Gets cleared when group memberships or permissions change.
    private $cache = [];

    /**
     * Private constructor -- grabs DB and session instances.
     * Nothing fancy here, just wiring up dependencies.
     */
    private function __construct() {
        $this->db = Database::getInstance();
        $this->session = Session::getInstance();
    }

    /**
     * Singleton accessor. Same pattern as everywhere else in this codebase.
     * One ACL instance per request is plenty.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if the current user belongs to a specific group (or any of several groups).
     *
     * You can pass a single group name or an array of group names. Returns true
     * if the user is in ANY of the specified groups (OR logic, not AND). The
     * comparison is case-insensitive because nobody should have to remember
     * whether it's "Admins" or "admins" or "ADMINS".
     *
     * @param string|array $groupNames Single group name or array of group names
     * @return bool True if the user belongs to at least one of the specified groups
     */
    public function hasGroup($groupNames) {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return false;
        }

        // Normalize to array so we can handle both single and multiple groups
        if (!is_array($groupNames)) {
            $groupNames = [$groupNames];
        }

        $userGroups = $this->getUserGroups($userId);

        // Case-insensitive check -- because capitalization shouldn't be a dealbreaker
        foreach ($groupNames as $groupName) {
            if (in_array(strtolower($groupName), array_map('strtolower', $userGroups))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current user has a specific permission.
     *
     * This is the main "can I do this?" method. Short-circuits for super admins
     * and regular admins (they can do everything -- with great power and all that).
     * For mere mortals, it checks their merged permissions from all their group
     * memberships.
     *
     * @param string $permissionCode Permission code (e.g., 'vendor.create', 'analysis.read')
     * @return bool True if the user has the permission (or is admin/super admin)
     */
    public function hasPermission($permissionCode) {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return false;
        }

        // Super admins get the keys to the kingdom -- no questions asked
        if ($this->session->get('is_super_admin')) {
            return true;
        }

        // Legacy admin flag -- kept for backward compatibility with older code
        // that predates the ACL system
        if ($this->session->get('is_admin')) {
            return true;
        }

        // For everyone else, check their actual permissions from their groups
        $permissions = $this->getUserPermissions($userId);
        return in_array($permissionCode, $permissions);
    }

    /**
     * Get all group names for a user.
     *
     * Looks up which active ACL groups a user belongs to. Results are cached
     * in memory for the duration of the request so you can call this as many
     * times as you want without extra DB queries. Pass null to check the
     * currently logged-in user.
     *
     * @param int|null $userId User ID (null = current user from session)
     * @return array Array of group name strings
     */
    public function getUserGroups($userId = null) {
        // Default to current logged-in user if no ID provided
        if ($userId === null) {
            $userId = $this->session->get('user_id');
        }

        if (!$userId) {
            return [];
        }

        // Check the cache first -- maybe we already looked this up
        $cacheKey = 'user_groups_' . $userId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Hit the DB: join through the user_acl_groups pivot table
        // Only include active groups (is_active = 1)
        $query = "
            SELECT g.group_name
            FROM acl_groups g
            INNER JOIN user_acl_groups ug ON g.id = ug.group_id
            WHERE ug.user_id = :user_id AND g.is_active = 1
            ORDER BY g.group_name
        ";

        $result = $this->db->fetchAll($query, [':user_id' => $userId]);
        $groups = array_column($result, 'group_name');

        // Stash in cache for later
        $this->cache[$cacheKey] = $groups;

        return $groups;
    }

    /**
     * Get all permissions for a user, merged from all their group memberships.
     *
     * A user might be in multiple groups, each with different permissions. This
     * method unions all of them together into one flat array of permission codes.
     * Uses DISTINCT in the query because if two groups both grant 'vendor.read',
     * we only need it once. Cached per-user per-request.
     *
     * @param int|null $userId User ID (null = current user from session)
     * @return array Array of permission code strings
     */
    public function getUserPermissions($userId = null) {
        if ($userId === null) {
            $userId = $this->session->get('user_id');
        }

        if (!$userId) {
            return [];
        }

        // Check cache before querying
        $cacheKey = 'user_permissions_' . $userId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Three-way join: permissions -> group_permissions -> user_acl_groups
        // This merges permissions from ALL of the user's groups
        $query = "
            SELECT DISTINCT p.permission_code
            FROM acl_permissions p
            INNER JOIN acl_group_permissions gp ON p.id = gp.permission_id
            INNER JOIN user_acl_groups ug ON gp.group_id = ug.group_id
            WHERE ug.user_id = :user_id
            ORDER BY p.permission_code
        ";

        $result = $this->db->fetchAll($query, [':user_id' => $userId]);
        $permissions = array_column($result, 'permission_code');

        // Cache it
        $this->cache[$cacheKey] = $permissions;

        return $permissions;
    }

    /**
     * Assign a user to a group.
     *
     * Creates a row in the user_acl_groups pivot table. If the user is already
     * in the group, we just return true quietly (idempotent operation -- calling
     * it twice doesn't cause problems). Clears the cache for that user because
     * their permissions just changed.
     *
     * @param int $userId User ID to assign
     * @param int $groupId Group ID to assign them to
     * @param int|null $assignedBy Who's making this change (null = current user)
     * @return bool True on success
     */
    public function assignUserToGroup($userId, $groupId, $assignedBy = null) {
        // Default to current user as the one making the assignment
        if ($assignedBy === null) {
            $assignedBy = $this->session->get('user_id');
        }

        try {
            // Check if they're already in the group -- if so, nothing to do
            $existing = $this->db->fetchOne(
                'SELECT id FROM user_acl_groups WHERE user_id = :user_id AND group_id = :group_id',
                [':user_id' => $userId, ':group_id' => $groupId]
            );

            if ($existing) {
                return true; // Already assigned, no drama
            }

            // Create the group membership
            $this->db->insert('user_acl_groups', [
                'user_id' => $userId,
                'group_id' => $groupId,
                'assigned_by' => $assignedBy
            ]);

            // Bust the cache for this user since their permissions just changed
            $this->clearCache($userId);

            return true;
        } catch (Exception $e) {
            error_log('ACL Error: Failed to assign user to group - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a user from a group.
     *
     * Deletes the row from the pivot table and clears the user's permission cache.
     * Simple, clean, no questions asked. If they weren't in the group to begin with,
     * the delete just affects zero rows and life goes on.
     *
     * @param int $userId User ID
     * @param int $groupId Group ID
     * @return bool True on success
     */
    public function removeUserFromGroup($userId, $groupId) {
        try {
            $this->db->delete(
                'user_acl_groups',
                'user_id = :user_id AND group_id = :group_id',
                [':user_id' => $userId, ':group_id' => $groupId]
            );

            // Bust the cache since their permissions changed
            $this->clearCache($userId);

            return true;
        } catch (Exception $e) {
            error_log('ACL Error: Failed to remove user from group - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Look up a group by its name.
     * Returns the full group record or null if not found. Handy when you
     * have the group name but need the ID for other operations.
     *
     * @param string $groupName The group name to look up
     * @return array|null Group record or null
     */
    public function getGroupByName($groupName) {
        return $this->db->fetchOne(
            'SELECT * FROM acl_groups WHERE group_name = :group_name',
            [':group_name' => $groupName]
        );
    }

    /**
     * Get all active groups in the system.
     * Returns them sorted by display_name for nice UI rendering.
     * Only includes active groups -- deactivated ones are effectively invisible.
     *
     * @return array Array of group records
     */
    public function getAllGroups() {
        return $this->db->fetchAll(
            'SELECT * FROM acl_groups WHERE is_active = 1 ORDER BY display_name'
        );
    }

    /**
     * Get group assignments for ALL users in a single query.
     *
     * This is the bulk version of getUserGroups() -- instead of hitting the DB
     * once per user (N+1 problem), we grab everything in one shot and organize
     * it into a nice associative array keyed by user_id. Essential for pages
     * that display a list of users with their groups (like the admin user list).
     *
     * @return array Associative array: [user_id => [group_name, group_name, ...], ...]
     */
    public function getAllUserGroups() {
        $query = "
            SELECT ug.user_id, g.group_name
            FROM acl_groups g
            INNER JOIN user_acl_groups ug ON g.id = ug.group_id
            WHERE g.is_active = 1
            ORDER BY ug.user_id, g.group_name
        ";

        $results = $this->db->fetchAll($query);

        // Organize into [user_id => [groups...]] format
        $userGroups = [];
        foreach ($results as $row) {
            $userId = $row['user_id'];
            if (!isset($userGroups[$userId])) {
                $userGroups[$userId] = [];
            }
            $userGroups[$userId][] = $row['group_name'];
        }

        return $userGroups;
    }

    /**
     * Get all permissions assigned to a specific group.
     * Joins through the acl_group_permissions pivot table to get the full
     * permission records. Sorted by module, resource, action for organized display.
     *
     * @param int $groupId Group ID
     * @return array Array of permission records
     */
    public function getGroupPermissions($groupId) {
        $query = "
            SELECT p.*
            FROM acl_permissions p
            INNER JOIN acl_group_permissions gp ON p.id = gp.permission_id
            WHERE gp.group_id = :group_id
            ORDER BY p.module, p.resource, p.action
        ";

        return $this->db->fetchAll($query, [':group_id' => $groupId]);
    }

    /**
     * Grant a permission to a group.
     *
     * Creates a row in acl_group_permissions. Idempotent -- granting an
     * already-granted permission is a no-op. Clears the cache for ALL users
     * in the affected group, because all their permissions just changed.
     * That's the beauty and the cost of group-based permissions.
     *
     * @param int $groupId Group ID
     * @param int $permissionId Permission ID
     * @param int|null $grantedBy Who's granting this (null = current user)
     * @return bool True on success
     */
    public function grantPermissionToGroup($groupId, $permissionId, $grantedBy = null) {
        if ($grantedBy === null) {
            $grantedBy = $this->session->get('user_id');
        }

        try {
            // Already granted? Cool, we're done here.
            $existing = $this->db->fetchOne(
                'SELECT 1 FROM acl_group_permissions WHERE group_id = :group_id AND permission_id = :permission_id',
                [':group_id' => $groupId, ':permission_id' => $permissionId]
            );

            if ($existing) {
                return true; // Already granted, no need to double-dip
            }

            $this->db->insert('acl_group_permissions', [
                'group_id' => $groupId,
                'permission_id' => $permissionId,
                'granted_by' => $grantedBy
            ]);

            // Clear cache for ALL users in this group -- their permissions just changed
            $this->clearCacheForGroup($groupId);

            return true;
        } catch (Exception $e) {
            error_log('ACL Error: Failed to grant permission to group - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoke a permission from a group.
     *
     * Removes the permission-to-group mapping and clears cache for all
     * affected users. This is the "you can't do that anymore" operation.
     *
     * @param int $groupId Group ID
     * @param int $permissionId Permission ID to revoke
     * @return bool True on success
     */
    public function revokePermissionFromGroup($groupId, $permissionId) {
        try {
            $this->db->delete(
                'acl_group_permissions',
                'group_id = :group_id AND permission_id = :permission_id',
                [':group_id' => $groupId, ':permission_id' => $permissionId]
            );

            // Nuke the cache for everyone in this group
            $this->clearCacheForGroup($groupId);

            return true;
        } catch (Exception $e) {
            error_log('ACL Error: Failed to revoke permission from group - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Synchronize a user's ACL groups based on SAML assertion group claims.
     *
     * Looks up saml_group_mappings to determine which ACL groups should be
     * assigned based on IdP groups. Groups that are mapped but not present in
     * the SAML assertion are removed. Groups that are NOT mapped to any SAML
     * group are left untouched (preserving any manually-assigned roles).
     *
     * @param int $userId The user to sync groups for
     * @param array $samlGroups Array of group names from the SAML assertion
     * @return array ['added' => [...], 'removed' => [...]]
     */
    public function syncSamlGroups($userId, array $samlGroups) {
        $added = [];
        $removed = [];

        try {
            // Get all active SAML group mappings with auto_assign enabled
            $mappings = $this->db->fetchAll(
                'SELECT sgm.saml_group_name, sgm.acl_group_id, ag.group_name, ag.display_name
                 FROM saml_group_mappings sgm
                 JOIN acl_groups ag ON ag.id = sgm.acl_group_id AND ag.is_active = 1
                 WHERE sgm.auto_assign = 1'
            );

            if (empty($mappings)) {
                return ['added' => $added, 'removed' => $removed];
            }

            // Build lookup: which ACL group IDs are mapped to SAML groups
            $allMappedGroupIds = [];
            $shouldHaveGroupIds = [];

            foreach ($mappings as $mapping) {
                $aclGroupId = (int)$mapping['acl_group_id'];
                $allMappedGroupIds[$aclGroupId] = $mapping['display_name'];

                // If the user has this SAML group in their assertion, they should have the ACL group
                if (in_array($mapping['saml_group_name'], $samlGroups, true)) {
                    $shouldHaveGroupIds[$aclGroupId] = $mapping['display_name'];
                }
            }

            // Get user's current group IDs
            $currentAssignments = $this->db->fetchAll(
                'SELECT ug.group_id FROM user_acl_groups ug WHERE ug.user_id = :user_id',
                [':user_id' => $userId]
            );
            $currentGroupIds = array_column($currentAssignments, 'group_id');
            $currentGroupIds = array_map('intval', $currentGroupIds);

            // Add groups the user should have but doesn't
            foreach ($shouldHaveGroupIds as $groupId => $displayName) {
                if (!in_array($groupId, $currentGroupIds, true)) {
                    $this->assignUserToGroup($userId, $groupId);
                    $added[] = $displayName;
                }
            }

            // Remove SAML-mapped groups the user should no longer have
            // Only remove groups that ARE in the SAML mapping table (leave manual assignments alone)
            foreach ($currentGroupIds as $currentGroupId) {
                if (isset($allMappedGroupIds[$currentGroupId]) && !isset($shouldHaveGroupIds[$currentGroupId])) {
                    $this->removeUserFromGroup($userId, $currentGroupId);
                    $removed[] = $allMappedGroupIds[$currentGroupId];
                }
            }

            if (!empty($added) || !empty($removed)) {
                $this->clearCache($userId);
            }

        } catch (Exception $e) {
            error_log('ACL Error: Failed to sync SAML groups for user ' . $userId . ' - ' . $e->getMessage());
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Clear cached groups and permissions for a specific user.
     * Called after any change to a user's group memberships so stale
     * data doesn't hang around causing confusion.
     *
     * @param int $userId User ID whose cache needs clearing
     */
    private function clearCache($userId) {
        unset($this->cache['user_groups_' . $userId]);
        unset($this->cache['user_permissions_' . $userId]);
    }

    /**
     * Clear cache for ALL users in a given group.
     *
     * When a group's permissions change, every user in that group might be
     * affected. So we look up all users in the group and clear each of their
     * caches. It's the nuclear option for cache invalidation, but correctness
     * beats performance when it comes to security permissions. You really
     * don't want someone keeping a permission after it's been revoked because
     * of a stale cache.
     *
     * @param int $groupId Group ID whose users need cache clearing
     */
    private function clearCacheForGroup($groupId) {
        // Find all users in this group
        $users = $this->db->fetchAll(
            'SELECT user_id FROM user_acl_groups WHERE group_id = :group_id',
            [':group_id' => $groupId]
        );

        // Clear cache for each one
        foreach ($users as $user) {
            $this->clearCache($user['user_id']);
        }
    }
}
