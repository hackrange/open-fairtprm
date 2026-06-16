<?php
/**
 * GRC Unified Compliance Engine - Continuous Monitors
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Lists continuous monitors with pass/fail status, configure monitors
 * (integration, check type, frequency), view execution history per monitor,
 * and trigger manual runs. Uses ContinuousMonitor::getInstance().
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

// GRC access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die('Access denied. GRC module requires cyber_grc, administrator, or auditor role.');
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$cm = ContinuousMonitor::getInstance();
$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc_monitors.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'run_monitor') {
            $monitorId = (int)($_POST['monitor_id'] ?? 0);
            if ($monitorId > 0) {
                try {
                    $result = $cm->runMonitor($monitorId);
                    $msg = 'Monitor executed. Result: ' . ucfirst($result['result']);
                    $msgType = $result['result'] === 'pass' ? 'success' : 'danger';
                } catch (Exception $ex) {
                    $msg = 'Monitor execution failed: ' . $ex->getMessage();
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'create_monitor') {
            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                $db->insert('grc_continuous_monitors', [
                    'name' => $name,
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'integration_id' => !empty($_POST['integration_id']) ? (int)$_POST['integration_id'] : null,
                    'check_type' => $_POST['check_type'] ?? 'custom',
                    'collector_class' => trim($_POST['collector_class'] ?? ''),
                    'collector_config' => !empty($_POST['collector_config']) ? $_POST['collector_config'] : '{}',
                    'frequency' => $_POST['frequency'] ?? 'daily',
                    'control_ids' => !empty($_POST['control_ids']) ? json_encode(array_map('intval', $_POST['control_ids'])) : '[]',
                    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
                    'created_by' => (int)$user['id'],
                ]);
                $msg = t('grc_monitors.monitor_created');
                $msgType = 'success';
            } else {
                $msg = t('grc_monitors.monitor_name_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'update_monitor') {
            $monitorId = (int)($_POST['monitor_id'] ?? 0);
            if ($monitorId > 0) {
                $db->update('grc_continuous_monitors', [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'integration_id' => !empty($_POST['integration_id']) ? (int)$_POST['integration_id'] : null,
                    'check_type' => $_POST['check_type'] ?? 'custom',
                    'collector_class' => trim($_POST['collector_class'] ?? ''),
                    'collector_config' => !empty($_POST['collector_config']) ? $_POST['collector_config'] : '{}',
                    'frequency' => $_POST['frequency'] ?? 'daily',
                    'control_ids' => !empty($_POST['control_ids']) ? json_encode(array_map('intval', $_POST['control_ids'])) : '[]',
                    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
                ], 'id = :id', [':id' => $monitorId]);
                $msg = t('grc_monitors.monitor_updated');
                $msgType = 'success';
            }
        } elseif ($action === 'toggle_monitor') {
            $monitorId = (int)($_POST['monitor_id'] ?? 0);
            $enabled = (int)($_POST['is_enabled'] ?? 0);
            if ($monitorId > 0) {
                $db->update('grc_continuous_monitors', ['is_enabled' => $enabled ? 0 : 1], 'id = :id', [':id' => $monitorId]);
                $msg = 'Monitor ' . ($enabled ? 'disabled' : 'enabled') . '.';
                $msgType = 'success';
            }
        }
        $csrfToken = $security->generateCSRFToken();
    }
}

// Generate CSRF token for GET requests (POST handler above generates its own after validation)
if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Load monitors
$monitors = $cm->getMonitors(false);

// Detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$monitorDetail = null;
$monitorHistory = [];
if ($viewId > 0) {
    $monitorDetail = $db->fetchOne(
        'SELECT m.*, i.name as integration_name, i.integration_type
         FROM grc_continuous_monitors m
         LEFT JOIN grc_integrations i ON i.id = m.integration_id
         WHERE m.id = :id',
        [':id' => $viewId]
    );
    if ($monitorDetail) {
        $monitorHistory = $cm->getMonitorHistory($viewId, 50);
    }
}

// Edit mode
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editMonitor = null;
if ($editId > 0 && !$readOnly) {
    $editMonitor = $db->fetchOne('SELECT * FROM grc_continuous_monitors WHERE id = :id', [':id' => $editId]);
}

// Get integrations and controls for forms
$integrations = $db->fetchAll('SELECT id, name, integration_type FROM grc_integrations ORDER BY name');
$allControls = $db->fetchAll('SELECT id, control_ref, title FROM grc_internal_controls WHERE is_active = 1 ORDER BY control_ref');

$currentPage = 'grc_monitors';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc_monitors.page_title')); ?></title>
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

        .section-header { font-size: 18px; font-weight: 600; color: #333; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .section-header:first-of-type { margin-top: 0; }

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: middle; }
        .grc-table tr:hover td { background: #f9fafb; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grc-form .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-warning { background: #f59e0b; color: #fff; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .monitor-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .monitor-dot.pass { background: #28a745; }
        .monitor-dot.fail { background: #dc3545; }
        .monitor-dot.warning { background: #f59e0b; }
        .monitor-dot.error { background: #dc3545; }
        .monitor-dot.not_run { background: #9ca3af; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        .history-result { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }
        .history-result.pass { background: #d1fae5; color: #065f46; }
        .history-result.fail { background: #fee2e2; color: #991b1b; }
        .history-result.warning { background: #fef3c7; color: #92400e; }
        .history-result.error { background: #fee2e2; color: #991b1b; }

        .enabled-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .enabled-badge.on { background: #d1fae5; color: #065f46; }
        .enabled-badge.off { background: #f3f4f6; color: #6b7280; }

        @media (max-width: 900px) {
            .grc-form .form-row, .grc-form .form-row-3 { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
        }
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc_monitors.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc_monitors.subheading')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($monitorDetail): ?>
            <!-- Monitor Detail -->
            <a href="grc-monitors.php" class="back-link">&larr; <?php echo e(t('grc_monitors.back_to_monitors')); ?></a>

            <div class="detail-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <h3>
                        <span class="monitor-dot <?php echo e($monitorDetail['last_result'] ?? 'not_run'); ?>"></span>
                        <?php echo e($monitorDetail['name']); ?>
                    </h3>
                    <div style="display:flex;gap:8px;">
                        <?php if (!$readOnly): ?>
                        <a href="grc-monitors.php?edit=<?php echo (int)$monitorDetail['id']; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc_monitors.configure')); ?></a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="run_monitor">
                            <input type="hidden" name="monitor_id" value="<?php echo (int)$monitorDetail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Run this monitor now?')"><?php echo e(t('grc_monitors.run_now')); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_status')); ?></div><div class="value"><span class="enabled-badge <?php echo $monitorDetail['is_enabled'] ? 'on' : 'off'; ?>"><?php echo e($monitorDetail['is_enabled'] ? t('grc_monitors.enabled') : t('grc_monitors.disabled')); ?></span></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_last_result')); ?></div><div class="value"><?php echo e(ucfirst($monitorDetail['last_result'] ?? 'Not run')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_frequency')); ?></div><div class="value"><?php echo e(ucfirst($monitorDetail['frequency'] ?? '-')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_integration')); ?></div><div class="value"><?php echo e($monitorDetail['integration_name'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_check_type')); ?></div><div class="value"><?php echo e($monitorDetail['check_type'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_last_run')); ?></div><div class="value"><?php echo $monitorDetail['last_run_at'] ? e(date('M j, Y g:i A', strtotime($monitorDetail['last_run_at']))) : e(t('grc_monitors.never')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_next_run')); ?></div><div class="value"><?php echo $monitorDetail['next_run_at'] ? e(date('M j, Y g:i A', strtotime($monitorDetail['next_run_at']))) : '-'; ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_failure_count')); ?></div><div class="value"><?php echo (int)($monitorDetail['failure_count'] ?? 0); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_monitors.label_collector')); ?></div><div class="value" style="font-size:11px;"><?php echo e($monitorDetail['collector_class'] ?? '-'); ?></div></div>
                </div>
                <?php if (!empty($monitorDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;"><?php echo e($monitorDetail['description']); ?></p>
                <?php endif; ?>
            </div>

            <!-- Execution History -->
            <div class="detail-card">
                <h3><?php echo e(t('grc_monitors.execution_history')); ?></h3>
                <?php if (!empty($monitorHistory)): ?>
                <table class="grc-table">
                    <thead><tr><th><?php echo e(t('grc_monitors.col_run_at')); ?></th><th><?php echo e(t('grc_monitors.col_result')); ?></th><th><?php echo e(t('grc_monitors.col_duration')); ?></th><th><?php echo e(t('grc_monitors.col_error')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($monitorHistory as $h): ?>
                    <tr>
                        <td><?php echo e(date('M j, Y g:i A', strtotime($h['run_at']))); ?></td>
                        <td><span class="history-result <?php echo e($h['result']); ?>"><?php echo e(ucfirst($h['result'])); ?></span></td>
                        <td><?php echo $h['duration_ms'] ? e($h['duration_ms'] . 'ms') : '-'; ?></td>
                        <td style="font-size:12px;color:#6b7280;"><?php echo e($h['error_message'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc_monitors.no_history')); ?></p>
                <?php endif; ?>
            </div>

            <?php elseif (($editMonitor || isset($_GET['new'])) && !$readOnly): ?>
            <!-- Create/Edit Monitor -->
            <a href="grc-monitors.php" class="back-link">&larr; <?php echo e(t('grc_monitors.back_to_monitors')); ?></a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo e($editMonitor ? t('grc_monitors.configure_monitor') : t('grc_monitors.create_monitor')); ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editMonitor ? 'update_monitor' : 'create_monitor'; ?>">
                    <?php if ($editMonitor): ?>
                    <input type="hidden" name="monitor_id" value="<?php echo (int)$editMonitor['id']; ?>">
                    <?php endif; ?>

                    <label for="name"><?php echo e(t('grc_monitors.monitor_name')); ?></label>
                    <input type="text" id="name" name="name" value="<?php echo e($editMonitor['name'] ?? ''); ?>" required>

                    <label for="description"><?php echo e(t('grc_monitors.description')); ?></label>
                    <textarea id="description" name="description"><?php echo e($editMonitor['description'] ?? ''); ?></textarea>

                    <div class="form-row-3">
                        <div>
                            <label for="integration_id"><?php echo e(t('grc_monitors.integration')); ?></label>
                            <select id="integration_id" name="integration_id">
                                <option value="">-- <?php echo e(t('grc_monitors.none')); ?> --</option>
                                <?php foreach ($integrations as $intg): ?>
                                <option value="<?php echo (int)$intg['id']; ?>" <?php echo ((int)($editMonitor['integration_id'] ?? 0)) === (int)$intg['id'] ? 'selected' : ''; ?>><?php echo e($intg['name']); ?> (<?php echo e($intg['integration_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="check_type"><?php echo e(t('grc_monitors.check_type')); ?></label>
                            <input type="text" id="check_type" name="check_type" value="<?php echo e($editMonitor['check_type'] ?? 'custom'); ?>" placeholder="<?php echo e(t('grc_monitors.check_type_placeholder')); ?>">
                        </div>
                        <div>
                            <label for="frequency"><?php echo e(t('grc_monitors.frequency')); ?></label>
                            <select id="frequency" name="frequency">
                                <?php foreach (['hourly', 'daily', 'weekly', 'monthly'] as $f): ?>
                                <option value="<?php echo e($f); ?>" <?php echo ($editMonitor['frequency'] ?? 'daily') === $f ? 'selected' : ''; ?>><?php echo e(ucfirst($f)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div>
                            <label for="collector_class"><?php echo e(t('grc_monitors.collector_class')); ?></label>
                            <input type="text" id="collector_class" name="collector_class" value="<?php echo e($editMonitor['collector_class'] ?? ''); ?>" placeholder="<?php echo e(t('grc_monitors.collector_class_placeholder')); ?>">
                        </div>
                        <div>
                            <label for="collector_config"><?php echo e(t('grc_monitors.collector_config')); ?></label>
                            <textarea id="collector_config" name="collector_config" style="min-height:60px;font-family:monospace;font-size:12px;"><?php echo e($editMonitor['collector_config'] ?? '{}'); ?></textarea>
                        </div>
                    </div>

                    <label><?php echo e(t('grc_monitors.linked_controls')); ?></label>
                    <?php
                    $selectedControlIds = [];
                    if ($editMonitor && !empty($editMonitor['control_ids'])) {
                        $selectedControlIds = json_decode($editMonitor['control_ids'], true) ?: [];
                    }
                    ?>
                    <select name="control_ids[]" multiple style="min-height:100px;">
                        <?php foreach ($allControls as $ac): ?>
                        <option value="<?php echo (int)$ac['id']; ?>" <?php echo in_array((int)$ac['id'], $selectedControlIds) ? 'selected' : ''; ?>><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin:12px 0;">
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="is_enabled" <?php echo ($editMonitor['is_enabled'] ?? 1) ? 'checked' : ''; ?> style="width:auto;margin:0;">
                            <?php echo e(t('grc_monitors.enabled')); ?>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo e($editMonitor ? t('grc_monitors.update_monitor') : t('grc_monitors.create_monitor')); ?></button>
                    <a href="grc-monitors.php" class="btn btn-outline"><?php echo e(t('grc_monitors.cancel')); ?></a>
                </form>
            </div>

            <?php else: ?>
            <!-- Monitor List -->
            <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
                <?php if (!$readOnly): ?>
                <a href="grc-monitors.php?new=1" class="btn btn-primary">+ <?php echo e(t('grc_monitors.new_monitor')); ?></a>
                <?php endif; ?>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('grc_monitors.col_status')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_name')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_integration')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_check_type')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_frequency')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_last_run')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_last_result')); ?></th>
                        <th><?php echo e(t('grc_monitors.col_enabled')); ?></th>
                        <?php if (!$readOnly): ?><th><?php echo e(t('grc_monitors.col_actions')); ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                    <tr><td colspan="<?php echo $readOnly ? 8 : 9; ?>" style="text-align:center;color:#6b7280;padding:30px;"><?php echo e(t('grc_monitors.no_monitors')); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($monitors as $mon): ?>
                    <tr>
                        <td><span class="monitor-dot <?php echo e($mon['last_result'] ?? 'not_run'); ?>"></span></td>
                        <td><a href="grc-monitors.php?view=<?php echo (int)$mon['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($mon['name']); ?></a></td>
                        <td><?php echo e($mon['integration_name'] ?? '-'); ?></td>
                        <td><?php echo e($mon['check_type'] ?? '-'); ?></td>
                        <td><?php echo e(ucfirst($mon['frequency'] ?? '-')); ?></td>
                        <td><?php echo $mon['last_run_at'] ? e(date('M j, g:i A', strtotime($mon['last_run_at']))) : 'Never'; ?></td>
                        <td><span class="history-result <?php echo e($mon['last_result'] ?? 'not_run'); ?>"><?php echo e(ucfirst($mon['last_result'] ?? 'Not run')); ?></span></td>
                        <td><span class="enabled-badge <?php echo $mon['is_enabled'] ? 'on' : 'off'; ?>"><?php echo e($mon['is_enabled'] ? t('grc_monitors.on') : t('grc_monitors.off')); ?></span></td>
                        <?php if (!$readOnly): ?>
                        <td style="white-space:nowrap;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="run_monitor">
                                <input type="hidden" name="monitor_id" value="<?php echo (int)$mon['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success" title="<?php echo e(t('grc_monitors.run_now_title')); ?>"><?php echo e(t('grc_monitors.run')); ?></button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="toggle_monitor">
                                <input type="hidden" name="monitor_id" value="<?php echo (int)$mon['id']; ?>">
                                <input type="hidden" name="is_enabled" value="<?php echo (int)$mon['is_enabled']; ?>">
                                <button type="submit" class="btn btn-sm btn-<?php echo $mon['is_enabled'] ? 'warning' : 'outline'; ?>"><?php echo e($mon['is_enabled'] ? t('grc_monitors.disable') : t('grc_monitors.enable')); ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc_monitors.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc_monitors.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
