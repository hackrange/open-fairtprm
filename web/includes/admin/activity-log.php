<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Activity Log
 *
 * Displays a paginated, filterable view of the audit_log table.
 * Shows all document operations and other audited actions.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// Auto-purge old log entries based on retention setting
// ============================================================================
$retentionRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'log_retention_days'");
$retentionDays = intval($retentionRow['config_value'] ?? 90);
if ($retentionDays > 0) {
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
    $db->query("DELETE FROM audit_log WHERE created_at < ?", [$cutoffDate]);
}

// ============================================================================
// CSV Export Handler (must run before any HTML output)
// ============================================================================
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$filterAction = trim($_GET['action_filter'] ?? '');
$filterUser = trim($_GET['user_filter'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');

// Build WHERE clauses
$where = [];
$params = [];

if ($filterAction !== '') {
    $where[] = 'a.action = ?';
    $params[] = $filterAction;
}
if ($filterUser !== '') {
    $where[] = 'a.user_id = ?';
    $params[] = intval($filterUser);
}
if ($filterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $where[] = 'a.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $where[] = 'a.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Handle CSV export — streams the file and exits before any HTML
if ($exportCsv) {
    $exportRecords = $db->fetchAll(
        "SELECT a.*, u.full_name as user_name, u.username
         FROM audit_log a
         LEFT JOIN users u ON a.user_id = u.id
         $whereClause
         ORDER BY a.created_at DESC",
        $params
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date/Time', 'User', 'Action', 'Table', 'Record ID', 'IP Address', 'Old Values', 'New Values', 'User Agent']);
    foreach ($exportRecords as $r) {
        fputcsv($output, [
            $r['created_at'],
            $r['user_name'] ?: ($r['username'] ?: 'System'),
            $r['action'],
            $r['table_name'] ?? '',
            $r['record_id'] ?? '',
            $r['ip_address'] ?? '',
            $r['old_values'] ?? '',
            $r['new_values'] ?? '',
            $r['user_agent'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// Get total count
$countRow = $db->fetchOne("SELECT COUNT(*) as cnt FROM audit_log a $whereClause", $params);
$totalRecords = $countRow['cnt'] ?? 0;
$totalPages = max(1, ceil($totalRecords / $perPage));

// Fetch records
$records = $db->fetchAll(
    "SELECT a.*, u.full_name as user_name, u.username
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     $whereClause
     ORDER BY a.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// Build user name lookup for resolving IDs in audit details
$_userNameCache = [];
$_allUsers = $db->fetchAll("SELECT id, full_name, username FROM users");
foreach ($_allUsers as $_u) {
    $_userNameCache[(int)$_u['id']] = $_u['full_name'] ?: $_u['username'];
}

/**
 * Enrich audit detail JSON values by resolving user IDs to names.
 * Fields ending in _id, _by, or named assigned_to/created_by/etc.
 * get " (User Name)" appended when the value is a numeric user ID.
 */
if (!function_exists('enrichAuditValues')) {
    function enrichAuditValues(?string $json, array $userCache): ?string {
        if (empty($json)) return $json;
        $data = json_decode($json, true);
        if (!is_array($data)) return $json;
        $userFields = ['user_id', 'assigned_to', 'created_by', 'closed_by', 'new_assignee_id',
                       'old_assignee_id', 'reviewer_user_id', 'new_stakeholder_id', 'uploaded_by',
                       'assigned_by', 'granted_by', 'marked_inactive_by'];
        array_walk_recursive($data, function (&$value, $key) use ($userCache, $userFields) {
            if (is_numeric($value) && (int)$value > 0) {
                if (in_array($key, $userFields) || str_ends_with($key, '_by') || str_ends_with($key, '_id')) {
                    $id = (int)$value;
                    if (isset($userCache[$id])) {
                        $value = "{$id} ({$userCache[$id]})";
                    }
                }
            }
        });
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

// Get distinct actions for filter dropdown
$actions = $db->fetchAll("SELECT DISTINCT action FROM audit_log ORDER BY action");

// Get distinct users for filter dropdown
$users = $db->fetchAll(
    "SELECT DISTINCT a.user_id, u.full_name, u.username
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     WHERE a.user_id IS NOT NULL
     ORDER BY u.full_name"
);
?>

<div class="page-header-bar">
    <h1><?php echo e(t('admin_activity-log.activity_log')); ?></h1>
    <p><?php echo e(t('admin_activity-log.audit_trail_desc')); ?></p>
</div>

<div class="card" style="margin-bottom: 20px;">
    <h3><?php echo e(t('admin_activity-log.filters')); ?></h3>
    <form method="GET" action="admin.php" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <input type="hidden" name="section" value="activity">

        <div style="flex: 1; min-width: 150px;">
            <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #333;"><?php echo e(t('admin_activity-log.action')); ?></label>
            <select name="action_filter" class="form-control" style="height: 38px;">
                <option value=""><?php echo e(t('admin_activity-log.all_actions')); ?></option>
                <?php foreach ($actions as $a): ?>
                <option value="<?php echo e($a['action']); ?>" <?php echo $filterAction === $a['action'] ? 'selected' : ''; ?>>
                    <?php echo e($a['action']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 150px;">
            <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #333;"><?php echo e(t('admin_activity-log.user')); ?></label>
            <select name="user_filter" class="form-control" style="height: 38px;">
                <option value=""><?php echo e(t('admin_activity-log.all_users')); ?></option>
                <?php foreach ($users as $u): ?>
                <option value="<?php echo intval($u['user_id']); ?>" <?php echo $filterUser == $u['user_id'] ? 'selected' : ''; ?>>
                    <?php echo e($u['full_name'] ?: $u['username'] ?: 'User #' . $u['user_id']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 140px;">
            <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #333;"><?php echo e(t('admin_activity-log.from_date')); ?></label>
            <input type="date" name="date_from" class="form-control" value="<?php echo e($filterDateFrom); ?>" style="height: 38px;">
        </div>

        <div style="flex: 1; min-width: 140px;">
            <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #333;"><?php echo e(t('admin_activity-log.to_date')); ?></label>
            <input type="date" name="date_to" class="form-control" value="<?php echo e($filterDateTo); ?>" style="height: 38px;">
        </div>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 38px;"><?php echo e(t('admin_activity-log.filter')); ?></button>
            <a href="admin.php?section=activity" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center; text-decoration: none; color: white;"><?php echo e(t('admin_activity-log.clear')); ?></a>
            <?php
            $exportParams = ['section' => 'activity', 'export' => 'csv'];
            if ($filterAction !== '') $exportParams['action_filter'] = $filterAction;
            if ($filterUser !== '') $exportParams['user_filter'] = $filterUser;
            if ($filterDateFrom !== '') $exportParams['date_from'] = $filterDateFrom;
            if ($filterDateTo !== '') $exportParams['date_to'] = $filterDateTo;
            ?>
            <a href="admin.php?<?php echo http_build_query($exportParams); ?>" class="btn" style="height: 38px; display: inline-flex; align-items: center; text-decoration: none; color: white; background: #059669;"><?php echo e(t('admin_activity-log.export_csv')); ?></a>
        </div>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_activity-log.log_entries')); ?> <span style="font-weight: 400; font-size: 13px; color: #666;">(<?php echo number_format($totalRecords); ?> <?php echo e(t('admin_activity-log.total')); ?>)</span></h3>

    <?php if (empty($records)): ?>
    <p style="color: #666; text-align: center; padding: 30px 0;"><?php echo e(t('admin_activity-log.no_entries_found')); ?></p>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th><?php echo e(t('admin_activity-log.date_time')); ?></th>
                    <th><?php echo e(t('admin_activity-log.user')); ?></th>
                    <th><?php echo e(t('admin_activity-log.action')); ?></th>
                    <th><?php echo e(t('admin_activity-log.table')); ?></th>
                    <th><?php echo e(t('admin_activity-log.record_id')); ?></th>
                    <th><?php echo e(t('admin_activity-log.ip_address')); ?></th>
                    <th><?php echo e(t('admin_activity-log.details')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo e(date('Y-m-d H:i:s', strtotime($record['created_at']))); ?></td>
                    <td><?php echo e($record['user_name'] ?: ($record['username'] ?: 'System')); ?></td>
                    <td>
                        <span class="badge <?php
                            $actionClass = 'badge-blue';
                            if (strpos($record['action'], 'delete') !== false) $actionClass = 'badge-danger';
                            elseif (strpos($record['action'], 'upload') !== false || strpos($record['action'], 'create') !== false) $actionClass = 'badge-success';
                            elseif (strpos($record['action'], 'assign') !== false) $actionClass = 'badge-blue';
                            elseif (strpos($record['action'], 'edit') !== false || strpos($record['action'], 'update') !== false) $actionClass = 'badge-purple';
                            echo $actionClass;
                        ?>">
                            <?php echo e($record['action']); ?>
                        </span>
                    </td>
                    <td><?php echo e($record['table_name'] ?? '-'); ?></td>
                    <td><?php echo $record['record_id'] ? intval($record['record_id']) : '-'; ?></td>
                    <td style="white-space: nowrap; font-size: 12px; color: #666;"><?php echo e($record['ip_address'] ?? '-'); ?></td>
                    <td>
                        <?php
                        $hasOld = !empty($record['old_values']);
                        $hasNew = !empty($record['new_values']);
                        if ($hasOld || $hasNew):
                        ?>
                        <button type="button" class="audit-toggle-btn" style="padding: 3px 10px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 4px; font-size: 11px; cursor: pointer;">
                            Show
                        </button>
                        <div class="audit-details" style="display: none; margin-top: 8px; font-size: 11px;">
                            <?php if ($hasOld): ?>
                            <div style="margin-bottom: 6px;">
                                <strong style="color: #dc2626;"><?php echo e(t('admin_activity-log.old')); ?></strong>
                                <pre style="margin: 4px 0; padding: 6px 10px; background: #fef2f2; border-radius: 4px; font-size: 11px; white-space: pre-wrap; word-break: break-all;"><?php echo e(enrichAuditValues($record['old_values'], $_userNameCache)); ?></pre>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasNew): ?>
                            <div>
                                <strong style="color: #16a34a;"><?php echo e(t('admin_activity-log.new')); ?></strong>
                                <pre style="margin: 4px 0; padding: 6px 10px; background: #f0fdf4; border-radius: 4px; font-size: 11px; white-space: pre-wrap; word-break: break-all;"><?php echo e(enrichAuditValues($record['new_values'], $_userNameCache)); ?></pre>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
        <?php
        // Build query string for pagination links
        $queryParams = [];
        if ($filterAction !== '') $queryParams['action_filter'] = $filterAction;
        if ($filterUser !== '') $queryParams['user_filter'] = $filterUser;
        if ($filterDateFrom !== '') $queryParams['date_from'] = $filterDateFrom;
        if ($filterDateTo !== '') $queryParams['date_to'] = $filterDateTo;
        $queryParams['section'] = 'activity';
        ?>

        <?php if ($page > 1): ?>
        <a href="admin.php?<?php echo http_build_query(array_merge($queryParams, ['page' => $page - 1])); ?>"
           style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;"><?php echo e(t('admin_activity-log.previous')); ?></a>
        <?php endif; ?>

        <span style="font-size: 13px; color: #666;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

        <?php if ($page < $totalPages): ?>
        <a href="admin.php?<?php echo http_build_query(array_merge($queryParams, ['page' => $page + 1])); ?>"
           style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px;"><?php echo e(t('admin_activity-log.next')); ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script nonce="<?php echo cspNonce(); ?>">
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.audit-toggle-btn');
    if (!btn) return;
    var details = btn.nextElementSibling;
    if (details.style.display === 'none') {
        details.style.display = 'block';
        btn.textContent = 'Hide';
    } else {
        details.style.display = 'none';
        btn.textContent = 'Show';
    }
});
</script>
