<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: ACL Group Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The ACL groups page. The seven shipped groups (administrator, cyber_tprm,
 * procurement, stakeholder, auditor, cyber_grc, grc_contributors) are flagged
 * is_system = 1: they are kept as the defaults and CANNOT be renamed, deleted,
 * or have their permission set rewritten -- they always behave as designed.
 *
 * On top of those, super administrators can create CUSTOM groups (is_system = 0),
 * optionally cloning ("Copy permissions from") an existing group as a starting
 * point, then tune the per-permission matrix to build granular, least-privilege
 * roles. Each permission is tagged Read or Read/Write so the capability is clear,
 * and module-level quick presets let you grant Read-only or Read & Write in one
 * click. Custom groups are editable and deletable.
 *
 * Authorization: viewing is available to admins (read-only). All mutating actions
 * (create / edit / permissions / delete) are restricted to SUPER ADMINISTRATORS.
 * Every action is CSRF-protected and written to the audit log.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// Only super administrators may shape ACL groups. Admins can still view the page.
$canManage = (bool)$session->get('is_super_admin');

// ============================================================================
// POST Handlers: create_group, update_group, set_group_permissions, delete_group
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!$canManage) {
        // Server-side enforcement of the super-admin gate -- never trust the UI.
        $error = 'Only super administrators can manage ACL groups.';
    } elseif (isset($_POST['create_group'])) {
        try {
            $groupName   = $_POST['group_name'] ?? '';
            $displayName = $_POST['display_name'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $copyFrom    = (int)($_POST['copy_from_group_id'] ?? 0);

            // Guard: only copy from a group that actually exists.
            if ($copyFrom > 0 && !$acl->getGroupById($copyFrom)) {
                $copyFrom = 0;
            }

            $newId = $acl->createGroup($groupName, $displayName, $description, $copyFrom ?: null);
            $copiedNote = $copyFrom ? ' Permissions copied from the selected group.' : '';
            $success = 'Group "' . $displayName . '" created.' . $copiedNote;

            $auth->audit($user['id'], 'acl_group_create', 'acl_groups', $newId, [
                'new' => [
                    'group_name'   => strtolower(trim($groupName)),
                    'display_name' => $displayName,
                    'copied_from'  => $copyFrom ?: null,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Exception $e) {
            error_log('ACL group create failed: ' . $e->getMessage());
            $error = 'Could not create the group. Please try again.';
        }
    } elseif (isset($_POST['update_group'])) {
        try {
            $groupId     = (int)($_POST['group_id'] ?? 0);
            $displayName = $_POST['display_name'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            $target = $acl->getGroupById($groupId);
            if (!$target) {
                $error = 'Group not found.';
            } elseif ((int)$target['is_system'] === 1) {
                $error = 'System groups cannot be modified.';
            } else {
                $acl->updateGroup($groupId, $displayName, $description, $isActive);
                $success = 'Group "' . $displayName . '" updated.';
                $auth->audit($user['id'], 'acl_group_update', 'acl_groups', $groupId, [
                    'old' => ['display_name' => $target['display_name'], 'description' => $target['description'], 'is_active' => (int)$target['is_active']],
                    'new' => ['display_name' => trim($displayName), 'description' => $description, 'is_active' => $isActive],
                ]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['set_group_permissions'])) {
        try {
            $groupId = (int)($_POST['group_id'] ?? 0);
            $permIds = array_map('intval', (array)($_POST['permission_ids'] ?? []));

            $target = $acl->getGroupById($groupId);
            if (!$target) {
                $error = 'Group not found.';
            } elseif ((int)$target['is_system'] === 1) {
                $error = 'System group permissions are read-only.';
            } else {
                $acl->setGroupPermissions($groupId, $permIds);
                $success = 'Permissions updated for "' . $target['display_name'] . '".';
                $auth->audit($user['id'], 'acl_group_permissions_set', 'acl_groups', $groupId, [
                    'new' => ['permission_ids' => array_values($permIds)],
                ]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['delete_group'])) {
        try {
            $groupId = (int)($_POST['group_id'] ?? 0);
            $target = $acl->getGroupById($groupId);
            if (!$target) {
                $error = 'Group not found.';
            } elseif ((int)$target['is_system'] === 1) {
                $error = 'System groups cannot be deleted.';
            } else {
                $acl->deleteGroup($groupId);
                $success = 'Group "' . $target['display_name'] . '" deleted.';
                $auth->audit($user['id'], 'acl_group_delete', 'acl_groups', $groupId, [
                    'old' => ['group_name' => $target['group_name'], 'display_name' => $target['display_name']],
                ]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$allGroups      = $acl->getManagedGroups();          // all groups, system + custom
$allPermissions = $acl->getAllPermissions();         // full permission catalog

// Member counts per group in a single query (includes inactive groups).
$memberCounts = [];
foreach ($db->fetchAll('SELECT group_id, COUNT(*) AS c FROM user_acl_groups GROUP BY group_id') as $row) {
    $memberCounts[(int)$row['group_id']] = (int)$row['c'];
}

// Map of group_id => [permission_id, ...] so the JS matrix can pre-check boxes
// without a round-trip per group.
$groupPermMap = [];
foreach ($db->fetchAll('SELECT group_id, permission_id FROM acl_group_permissions') as $row) {
    $groupPermMap[(int)$row['group_id']][] = (int)$row['permission_id'];
}

// Group the permission catalog by module -> for matrix rendering.
$permsByModule = [];
foreach ($allPermissions as $p) {
    $mod = $p['module'] !== null && $p['module'] !== '' ? $p['module'] : 'other';
    $permsByModule[$mod][] = $p;
}
ksort($permsByModule);

// Lightweight metadata about each group for the JS layer (name lock, system flag).
$groupMeta = [];
foreach ($allGroups as $g) {
    $groupMeta[(int)$g['id']] = [
        'name'      => $g['group_name'],
        'display'   => $g['display_name'],
        'desc'      => (string)$g['description'],
        'active'    => (int)$g['is_active'],
        'is_system' => (int)$g['is_system'],
        'perm_ids'  => $groupPermMap[(int)$g['id']] ?? [],
    ];
}

$moduleLabels = [
    'onboarding'    => 'Vendor Onboarding',
    'analysis'      => 'FAIR Analysis',
    'assessment'    => 'Assessments',
    'srs'           => 'Security Rating (SRS)',
    'annual_review' => 'Annual Reviews',
    'grc'           => 'GRC',
    'other'         => 'Other',
];
?>
<div class="page-header-bar" style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
    <div>
        <h1 class="page-title">ACL Group Management</h1>
        <p>The seven shipped groups are system defaults and cannot be deleted or renamed.
           <?php if ($canManage): ?>Create custom groups, copy permissions from an existing group, and tune Read / Read&nbsp;&amp;&nbsp;Write access per module.<?php else: ?>Only super administrators can create or edit groups.<?php endif; ?></p>
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-primary" id="btnCreateGroup" type="button">+ Create Group</button>
    <?php endif; ?>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Group Name</th>
                <th>Display Name</th>
                <th>Description</th>
                <th>Members</th>
                <th>Type</th>
                <th>Status</th>
                <?php if ($canManage): ?><th style="text-align:right;">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allGroups as $group): ?>
            <?php
                $gid = (int)$group['id'];
                $isSystem = (int)$group['is_system'] === 1;
                $count = $memberCounts[$gid] ?? 0;
            ?>
            <tr>
                <td><strong><?php echo e($group['group_name']); ?></strong></td>
                <td><?php echo e($group['display_name']); ?></td>
                <td><?php echo e($group['description']); ?></td>
                <td><?php echo $count . ' user' . ($count !== 1 ? 's' : ''); ?></td>
                <td>
                    <?php if ($isSystem): ?>
                        <span class="badge badge-purple">System</span>
                    <?php else: ?>
                        <span class="badge badge-blue">Custom</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $group['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'; ?></td>
                <?php if ($canManage): ?>
                <td style="text-align:right; white-space:nowrap;">
                    <button type="button" class="btn btn-sm btn-secondary js-perms" data-group-id="<?php echo $gid; ?>">
                        <?php echo $isSystem ? 'View Permissions' : 'Permissions'; ?>
                    </button>
                    <?php if (!$isSystem): ?>
                    <button type="button" class="btn btn-sm btn-primary js-edit" data-group-id="<?php echo $gid; ?>">Edit</button>
                    <button type="button" class="btn btn-sm js-delete" data-group-id="<?php echo $gid; ?>" style="background:#dc2626; color:white;">Delete</button>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage): ?>
<!-- ===================== Create Group Modal ===================== -->
<div id="createGroupModal" class="modal">
    <div class="modal-content">
        <span class="close" id="closeCreateGroup">&times;</span>
        <h2 style="margin-top:0;">Create Custom Group</h2>
        <form method="POST" action="admin.php?section=groups">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="create_group" value="1">
            <div class="form-group">
                <label for="cg_group_name">Group Name (machine)</label>
                <input type="text" id="cg_group_name" name="group_name" required
                       pattern="[a-z][a-z0-9_]{1,63}"
                       title="Lowercase letters, numbers and underscores; 2-64 chars; must start with a letter."
                       placeholder="e.g. vendor_reviewer" style="width:100%;">
                <small style="color:#6b7280;">Lowercase, letters/numbers/underscores. Cannot be changed later.</small>
            </div>
            <div class="form-group">
                <label for="cg_display_name">Display Name</label>
                <input type="text" id="cg_display_name" name="display_name" required
                       placeholder="e.g. Vendor Reviewer" style="width:100%;">
            </div>
            <div class="form-group">
                <label for="cg_description">Description</label>
                <input type="text" id="cg_description" name="description"
                       placeholder="What is this group for?" style="width:100%;">
            </div>
            <div class="form-group">
                <label for="cg_copy_from">Copy permissions from</label>
                <select id="cg_copy_from" name="copy_from_group_id" style="width:100%;">
                    <option value="0">— Start with no permissions —</option>
                    <?php foreach ($allGroups as $group): ?>
                    <option value="<?php echo (int)$group['id']; ?>">
                        <?php echo e($group['display_name']); ?><?php echo (int)$group['is_system'] === 1 ? ' (System)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6b7280;">Clone an existing group as a starting point, then refine the permission matrix.</small>
            </div>
            <div style="text-align:right; margin-top:18px;">
                <button type="button" class="btn btn-secondary" id="cancelCreateGroup">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Group</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== Edit Group Modal ===================== -->
<div id="editGroupModal" class="modal">
    <div class="modal-content">
        <span class="close" id="closeEditGroup">&times;</span>
        <h2 style="margin-top:0;">Edit Group</h2>
        <form method="POST" action="admin.php?section=groups">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="update_group" value="1">
            <input type="hidden" name="group_id" id="eg_group_id" value="">
            <div class="form-group">
                <label>Group Name (machine)</label>
                <input type="text" id="eg_group_name" disabled style="width:100%; background:#f3f4f6;">
                <small style="color:#6b7280;">The machine name is fixed once created.</small>
            </div>
            <div class="form-group">
                <label for="eg_display_name">Display Name</label>
                <input type="text" id="eg_display_name" name="display_name" required style="width:100%;">
            </div>
            <div class="form-group">
                <label for="eg_description">Description</label>
                <input type="text" id="eg_description" name="description" style="width:100%;">
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" id="eg_is_active" name="is_active" value="1"><span>Active</span></label>
            </div>
            <div style="text-align:right; margin-top:18px;">
                <button type="button" class="btn btn-secondary" id="cancelEditGroup">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== Permissions Matrix Modal ===================== -->
<div id="permsModal" class="modal">
    <div class="modal-content" style="max-width:820px; max-height:86vh; overflow:auto;">
        <span class="close" id="closePerms">&times;</span>
        <h2 style="margin-top:0;">Permissions — <span id="pm_group_label"></span></h2>
        <p id="pm_system_note" style="display:none; color:#7c3aed; background:#f3e8ff; padding:8px 12px; border-radius:6px;">
            This is a system group. Its permissions are shown for reference and cannot be changed. Use “Copy permissions from” when creating a custom group to start from this set.
        </p>
        <form method="POST" action="admin.php?section=groups" id="permsForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="set_group_permissions" value="1">
            <input type="hidden" name="group_id" id="pm_group_id" value="">

            <?php foreach ($permsByModule as $mod => $perms): ?>
            <fieldset style="border:1px solid #e5e7eb; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                <legend style="padding:0 6px; font-weight:600;">
                    <?php echo e($moduleLabels[$mod] ?? ucfirst($mod)); ?>
                </legend>
                <div class="pm-quickset" style="margin-bottom:8px;" data-module="<?php echo e($mod); ?>">
                    <span style="color:#6b7280; font-size:12px; margin-right:6px;">Capability:</span>
                    <button type="button" class="btn btn-sm btn-secondary pm-preset" data-module="<?php echo e($mod); ?>" data-level="read">Read</button>
                    <button type="button" class="btn btn-sm btn-secondary pm-preset" data-module="<?php echo e($mod); ?>" data-level="write">Read &amp; Write</button>
                    <button type="button" class="btn btn-sm btn-secondary pm-preset" data-module="<?php echo e($mod); ?>" data-level="none">None</button>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px 18px;">
                    <?php foreach ($perms as $p): ?>
                    <?php $cap = ACL::capabilityForAction($p['action']); ?>
                    <label class="checkbox-label" style="align-items:flex-start;">
                        <input type="checkbox" class="pm-perm" name="permission_ids[]"
                               value="<?php echo (int)$p['id']; ?>"
                               data-module="<?php echo e($mod); ?>"
                               data-cap="<?php echo e($cap); ?>"
                               style="margin-top:3px;">
                        <span>
                            <strong><?php echo e($p['name']); ?></strong>
                            <?php if ($cap === 'read'): ?>
                                <span class="badge badge-blue" style="font-size:10px;">Read</span>
                            <?php else: ?>
                                <span class="badge badge-success" style="font-size:10px;">Read/Write</span>
                            <?php endif; ?>
                            <br><small style="color:#9ca3af;"><?php echo e($p['permission_code']); ?></small>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php endforeach; ?>

            <div style="text-align:right; margin-top:8px;" id="pm_actions">
                <button type="button" class="btn btn-secondary" id="cancelPerms">Close</button>
                <button type="submit" class="btn btn-primary" id="pm_save">Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="groupMetaData"><?php echo json_encode($groupMeta, JSON_UNESCAPED_SLASHES); ?></script>
<script nonce="<?php echo cspNonce(); ?>">
(function () {
    var META = {};
    try { META = JSON.parse(document.getElementById('groupMetaData').textContent || '{}'); } catch (e) { META = {}; }

    function show(id) { var m = document.getElementById(id); if (m) m.style.display = 'block'; }
    function hide(id) { var m = document.getElementById(id); if (m) m.style.display = 'none'; }

    // ---- Create ----
    var btnCreate = document.getElementById('btnCreateGroup');
    if (btnCreate) btnCreate.addEventListener('click', function () { show('createGroupModal'); });
    bindClose('closeCreateGroup', 'createGroupModal');
    bindClose('cancelCreateGroup', 'createGroupModal');

    // ---- Edit ----
    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var g = META[this.getAttribute('data-group-id')];
            if (!g) return;
            document.getElementById('eg_group_id').value = this.getAttribute('data-group-id');
            document.getElementById('eg_group_name').value = g.name;
            document.getElementById('eg_display_name').value = g.display;
            document.getElementById('eg_description').value = g.desc;
            document.getElementById('eg_is_active').checked = (g.active === 1);
            show('editGroupModal');
        });
    });
    bindClose('closeEditGroup', 'editGroupModal');
    bindClose('cancelEditGroup', 'editGroupModal');

    // ---- Delete (confirm) ----
    document.querySelectorAll('.js-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var g = META[this.getAttribute('data-group-id')];
            if (!g) return;
            if (!confirm('Delete the group "' + g.display + '"?\n\nThis removes the group and unassigns it from all of its members. This cannot be undone.')) return;
            postAction({ delete_group: '1', group_id: this.getAttribute('data-group-id') });
        });
    });

    // ---- Permissions matrix ----
    var perms = Array.prototype.slice.call(document.querySelectorAll('.pm-perm'));

    document.querySelectorAll('.js-perms').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var gid = this.getAttribute('data-group-id');
            var g = META[gid];
            if (!g) return;
            var readOnly = (g.is_system === 1);

            document.getElementById('pm_group_id').value = gid;
            document.getElementById('pm_group_label').textContent = g.display;
            document.getElementById('pm_system_note').style.display = readOnly ? 'block' : 'none';
            document.getElementById('pm_save').style.display = readOnly ? 'none' : '';

            var selected = {};
            (g.perm_ids || []).forEach(function (id) { selected[String(id)] = true; });
            perms.forEach(function (cb) {
                cb.checked = !!selected[cb.value];
                cb.disabled = readOnly;
            });
            document.querySelectorAll('.pm-preset').forEach(function (b) { b.disabled = readOnly; });

            show('permsModal');
        });
    });
    bindClose('closePerms', 'permsModal');
    bindClose('cancelPerms', 'permsModal');

    // Capability presets: Read = check read-cap only; Read & Write = check all;
    // None = clear. A read grant never implies write (write rows stay unchecked
    // for the Read preset); Read & Write includes the read rows too.
    document.querySelectorAll('.pm-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mod = this.getAttribute('data-module');
            var level = this.getAttribute('data-level');
            perms.forEach(function (cb) {
                if (cb.getAttribute('data-module') !== mod || cb.disabled) return;
                if (level === 'none') { cb.checked = false; }
                else if (level === 'read') { cb.checked = (cb.getAttribute('data-cap') === 'read'); }
                else if (level === 'write') { cb.checked = true; }
            });
        });
    });

    function bindClose(btnId, modalId) {
        var b = document.getElementById(btnId);
        if (b) b.addEventListener('click', function () { hide(modalId); });
    }

    // Build and submit a throwaway POST form (keeps CSRF token + single source of truth).
    function postAction(fields) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = 'admin.php?section=groups';
        var csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = 'csrf_token';
        csrf.value = <?php echo json_encode($csrfToken); ?>;
        f.appendChild(csrf);
        Object.keys(fields).forEach(function (k) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = k; i.value = fields[k];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    }

    // Click outside a modal closes it.
    window.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    });
})();
</script>
<?php endif; ?>
