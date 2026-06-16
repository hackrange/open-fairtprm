<?php
/**
 * GRC Task Inbox - Assigned Tasks Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Standalone task inbox page where users see all GRC assessment tasks assigned
 * to them across all assessments. Supports filtering, status updates,
 * reassignment, and deletion (cyber_grc only).
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

// Access check — any authenticated user can see their own tasks
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

// Who can manage (reassign/delete) tasks?
$canManage = $isAdmin || $isCyberGRC;

// Who can see ALL tasks (not just their own)?
$canViewAll = $isAdmin || $isCyberGRC;

$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc-tasks.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $taskId = (int)($_POST['task_id'] ?? 0);

        if ($action === 'update_status' && $taskId > 0) {
            $newStatus = $_POST['status'] ?? '';
            $validStatuses = ['open', 'in_progress', 'completed', 'cancelled'];
            if (in_array($newStatus, $validStatuses)) {
                // Any user can update status of tasks assigned to them
                $task = $db->fetchOne('SELECT id, assigned_to FROM grc_assessment_tasks WHERE id = :id', [':id' => $taskId]);
                if ($task && ((int)$task['assigned_to'] === (int)$user['id'] || $canManage)) {
                    $update = ['status' => $newStatus];
                    if ($newStatus === 'completed') {
                        $update['completed_at'] = date('Y-m-d H:i:s');
                        $update['completed_by'] = (int)$user['id'];
                    }
                    $db->update('grc_assessment_tasks', $update, 'id = :id', [':id' => $taskId]);
                    $msg = t('grc-tasks.status_updated');
                    $msgType = 'success';
                }
            }
        } elseif ($action === 'reassign' && $taskId > 0 && $canManage) {
            $newAssignee = (int)($_POST['assigned_to'] ?? 0);
            if ($newAssignee > 0) {
                $db->update('grc_assessment_tasks', ['assigned_to' => $newAssignee], 'id = :id', [':id' => $taskId]);
                $msg = t('grc-tasks.task_reassigned');
                $msgType = 'success';
            }
        } elseif ($action === 'delete' && $taskId > 0 && $canManage) {
            $db->query('DELETE FROM grc_assessment_tasks WHERE id = :id', [':id' => $taskId]);
            $msg = t('grc-tasks.task_deleted');
            $msgType = 'success';
        }
    }
}

$csrfToken = $security->generateCSRFToken();

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterView = $_GET['view'] ?? 'mine'; // 'mine' or 'all'

// Build query
$where = [];
$params = [];

if ($filterView === 'all' && $canViewAll) {
    // Show all tasks (admin/grc/auditor)
} else {
    // Default: show only tasks assigned to current user
    $where[] = 't.assigned_to = :uid';
    $params[':uid'] = $user['id'];
    $filterView = 'mine';
}

if ($filterStatus !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $filterStatus;
} else {
    // Default: show open/in_progress tasks
    $where[] = "t.status IN ('open', 'in_progress')";
}

if ($filterPriority !== '') {
    $where[] = 't.priority = :priority';
    $params[':priority'] = $filterPriority;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$tasks = $db->fetchAll(
    "SELECT t.*, a.title as assessment_title, a.assessment_ref,
            u.full_name as assigned_to_name,
            ab.full_name as assigned_by_name,
            q.question_ref
     FROM grc_assessment_tasks t
     JOIN grc_assessments a ON a.id = t.assessment_id
     LEFT JOIN users u ON u.id = t.assigned_to
     LEFT JOIN users ab ON ab.id = t.assigned_by
     LEFT JOIN grc_unified_questions q ON q.id = t.question_id
     $whereClause
     ORDER BY FIELD(t.priority, 'critical', 'high', 'medium', 'low'), t.due_date ASC",
    $params
);

// Count stats
$today = date('Y-m-d');
$totalCount = count($tasks);
$lateCount = 0;
foreach ($tasks as $t) {
    if (!empty($t['due_date']) && $t['due_date'] < $today && in_array($t['status'], ['open', 'in_progress'])) {
        $lateCount++;
    }
}

// Users for reassignment dropdown (only administrator, cyber_grc, grc_contributors)
$users = $db->fetchAll(
    "SELECT DISTINCT u.id, u.full_name
     FROM users u
     JOIN user_acl_groups uag ON uag.user_id = u.id
     JOIN acl_groups ag ON ag.id = uag.group_id
     WHERE u.is_active = 1
       AND ag.group_name IN ('administrator', 'cyber_grc', 'grc_contributors')
     ORDER BY u.full_name"
);

$currentPage = 'grc_tasks';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-tasks.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        a { text-decoration: none; }
        a:hover { text-decoration: none; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }
        .top-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px; display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px; background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px; }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar { width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important; max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important; padding: 0; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5; padding: 0 20px; margin-bottom: 10px; }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-font-color); opacity: 0.85; text-decoration: none; font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .filter-bar select { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-warning { background: #f59e0b; color: #fff; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .task-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; margin-bottom: 10px; transition: box-shadow 0.2s; }
        .task-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .task-card.late { border-left: 3px solid #dc3545; }

        .task-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
        .task-ref { font-weight: 600; font-size: 13px; color: #374151; }

        .priority-badge { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 12px; }
        .priority-critical { background: #fef2f2; color: #dc3545; }
        .priority-high { background: #fffbeb; color: #d97706; }
        .priority-medium { background: #eff6ff; color: #3b82f6; }
        .priority-low { background: #f3f4f6; color: #6b7280; }

        .status-badge { font-size: 11px; font-weight: 500; padding: 2px 10px; border-radius: 12px; }
        .status-open { background: #eff6ff; color: #1e40af; }
        .status-in_progress { background: #fef3c7; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #f3f4f6; color: #6b7280; }
        .status-blocked { background: #fee2e2; color: #991b1b; }

        .late-badge { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 12px; background: #fef2f2; color: #dc3545; }

        .task-title { font-size: 15px; font-weight: 500; color: #111827; margin-bottom: 6px; }
        .task-meta { font-size: 12px; color: #6b7280; display: flex; gap: 16px; flex-wrap: wrap; }
        .task-actions { display: flex; gap: 8px; align-items: center; margin-top: 10px; flex-wrap: wrap; }

        .stat-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; text-align: center; }
        .stat-card .value { font-size: 28px; font-weight: 700; color: #111827; }
        .stat-card .label { font-size: 12px; color: #6b7280; margin-top: 4px; }

        .view-toggle { display: inline-flex; border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; margin-bottom: 16px; }
        .view-toggle a { padding: 6px 16px; font-size: 13px; color: #374151; text-decoration: none; border-right: 1px solid #d1d5db; }
        .view-toggle a:last-child { border-right: none; }
        .view-toggle a.active { background: var(--theme-button-color, #ff6543); color: #fff; }

        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }

        @media (max-width: 900px) {
            .filter-bar { flex-direction: column; }
            .task-meta { flex-direction: column; gap: 4px; }
            .stat-cards { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <span style="margin-right:auto;font-size:14px;color:#333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
            <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
            <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
        </div>
    </div>

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

        <main class="main-content">
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-tasks.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 24px;"><?php echo e(t('grc-tasks.subtitle')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <!-- View Toggle -->
            <?php if ($canViewAll): ?>
            <div class="view-toggle">
                <a href="grc-tasks.php?view=mine<?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterPriority ? '&priority=' . urlencode($filterPriority) : ''; ?>"
                   class="<?php echo $filterView === 'mine' ? 'active' : ''; ?>"><?php echo e(t('grc-tasks.my_tasks')); ?></a>
                <a href="grc-tasks.php?view=all<?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterPriority ? '&priority=' . urlencode($filterPriority) : ''; ?>"
                   class="<?php echo $filterView === 'all' ? 'active' : ''; ?>"><?php echo e(t('grc-tasks.all_tasks')); ?></a>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stat-cards">
                <div class="stat-card">
                    <div class="value"><?php echo $totalCount; ?></div>
                    <div class="label"><?php echo e($filterView === 'all' ? t('grc-tasks.total_tasks') : t('grc-tasks.assigned_to_you')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="value" style="color: #dc3545;"><?php echo $lateCount; ?></div>
                    <div class="label"><?php echo e(t('grc-tasks.past_due')); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" class="filter-bar">
                <input type="hidden" name="view" value="<?php echo e($filterView); ?>">
                <div>
                    <label><?php echo e(t('grc-tasks.status')); ?></label>
                    <select name="status" onchange="this.form.submit()">
                        <option value=""><?php echo e(t('grc-tasks.open_in_progress')); ?></option>
                        <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>><?php echo e(t('grc-tasks.open')); ?></option>
                        <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>><?php echo e(t('grc-tasks.in_progress')); ?></option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>><?php echo e(t('grc-tasks.completed')); ?></option>
                        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>><?php echo e(t('grc-tasks.cancelled')); ?></option>
                    </select>
                </div>
                <div>
                    <label><?php echo e(t('grc-tasks.priority')); ?></label>
                    <select name="priority" onchange="this.form.submit()">
                        <option value=""><?php echo e(t('grc-tasks.all_priorities')); ?></option>
                        <?php foreach (['critical', 'high', 'medium', 'low'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $filterPriority === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;"><?php echo e(t('grc-tasks.filter')); ?></button>
            </form>

            <!-- Task List -->
            <?php if (empty($tasks)): ?>
            <div style="text-align:center;padding:60px 20px;color:#6b7280;">
                <div style="font-size:48px;margin-bottom:12px;">&#9989;</div>
                <h3 style="color:#374151;margin:0 0 6px;"><?php echo e(t('grc-tasks.no_tasks_found')); ?></h3>
                <p style="margin:0;font-size:14px;"><?php echo e($filterView === 'mine' ? t('grc-tasks.no_open_tasks') : t('grc-tasks.no_match_filters')); ?></p>
            </div>
            <?php else: ?>
            <?php foreach ($tasks as $task):
                $isLate = !empty($task['due_date']) && $task['due_date'] < $today && in_array($task['status'], ['open', 'in_progress']);
                $isOwner = (int)$task['assigned_to'] === (int)$user['id'];
            ?>
            <div class="task-card<?php echo $isLate ? ' late' : ''; ?>">
                <div class="task-header">
                    <span class="task-ref"><?php echo e($task['task_ref']); ?></span>
                    <span class="priority-badge priority-<?php echo e($task['priority']); ?>"><?php echo ucfirst($task['priority']); ?></span>
                    <span class="status-badge status-<?php echo e($task['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?></span>
                    <?php if ($isLate): ?>
                    <span class="late-badge"><?php echo e(t('grc-tasks.past_due')); ?></span>
                    <?php endif; ?>
                    <span style="font-size:11px;color:#6b7280;padding:2px 8px;background:#f3f4f6;border-radius:10px;"><?php echo e(ucfirst(str_replace('_', ' ', $task['task_type'] ?? 'evidence_request'))); ?></span>
                </div>

                <div class="task-title"><?php echo e($task['title']); ?></div>

                <?php if (!empty($task['description'])): ?>
                <p style="font-size:13px;color:#4b5563;margin:0 0 8px;"><?php echo e($task['description']); ?></p>
                <?php endif; ?>

                <div class="task-meta">
                    <span><?php echo e(t('grc-tasks.assessment_label')); ?> <a href="grc-assessment.php?view=<?php echo (int)$task['assessment_id']; ?>&my_tasks=1" style="color:#3b82f6;"><?php echo e($task['assessment_title']); ?></a></span>
                    <?php if (!empty($task['question_ref'])): ?>
                    <span><?php echo e(t('grc-tasks.question_label')); ?> <?php echo e($task['question_ref']); ?></span>
                    <?php endif; ?>
                    <?php if ($filterView === 'all' && !empty($task['assigned_to_name'])): ?>
                    <span><?php echo e(t('grc-tasks.assigned_to_label')); ?> <?php echo e($task['assigned_to_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($task['assigned_by_name'])): ?>
                    <span><?php echo e(t('grc-tasks.from_label')); ?> <?php echo e($task['assigned_by_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($task['due_date'])): ?>
                    <span><?php echo e(t('grc-tasks.due_label')); ?> <?php echo date('M j, Y', strtotime($task['due_date'])); ?></span>
                    <?php endif; ?>
                    <span><?php echo e(t('grc-tasks.created_label')); ?> <?php echo date('M j, Y', strtotime($task['created_at'])); ?></span>
                </div>

                <?php if ($isOwner || $canManage): ?>
                <div class="task-actions">
                    <?php if (in_array($task['status'], ['open', 'in_progress'])): ?>
                    <!-- Status Update -->
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                        <?php if ($task['status'] === 'open'): ?>
                        <button type="submit" name="status" value="in_progress" class="btn btn-sm btn-warning"><?php echo e(t('grc-tasks.start_working')); ?></button>
                        <?php endif; ?>
                        <button type="submit" name="status" value="completed" class="btn btn-sm btn-success"><?php echo e(t('grc-tasks.mark_complete')); ?></button>
                    </form>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                    <!-- Reassign -->
                    <form method="post" style="display:inline-flex;align-items:center;gap:4px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="reassign">
                        <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                        <select name="assigned_to" onchange="this.form.submit()" style="padding:3px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                            <option value=""><?php echo e(t('grc-tasks.reassign_placeholder')); ?></option>
                            <?php foreach ($users as $u): ?>
                            <?php if ((int)$u['id'] !== (int)$task['assigned_to']): ?>
                            <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <!-- Delete -->
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this task permanently?')"><?php echo e(t('grc-tasks.delete')); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </main>
    </div>

    <!-- Footer -->
    <footer class="section footer-modern bg-gray-13">
        <div class="footer-modern-body">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($theme['footer_logo_url'])): ?>
                        <a class="brand" href="index.php">
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc-tasks.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc-tasks.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
