<?php
/**
 * GRC Unified Compliance Engine - Policy Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Full policy lifecycle management: list policies with status/owner/review date,
 * create/edit with Markdown editor (TinyMCE), version history with diff,
 * approve/publish workflow, and acknowledgment tracking. Supports autosave
 * for policy content editing via AJAX to /api/grc-autosave.php.
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

$policyService = PolicyService::getInstance();
$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc_policies.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' && !$readOnly) {
            $title = trim($_POST['title'] ?? '');
            if ($title !== '') {
                $policyId = $policyService->createPolicy([
                    'title' => $title,
                    'description' => trim($_POST['description'] ?? ''),
                    'category' => $_POST['category'] ?? 'security',
                    'owner_user_id' => !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : (int)$user['id'],
                    'review_frequency_months' => (int)($_POST['review_frequency_months'] ?? 12),
                    'requires_acknowledgment' => isset($_POST['requires_acknowledgment']) ? 1 : 0,
                    'acknowledgment_deadline_days' => (int)($_POST['acknowledgment_deadline_days'] ?? 30),
                    'content' => $_POST['content'] ?? '',
                ], (int)$user['id']);
                $msg = t('grc_policies.policy_created');
                $msgType = 'success';
                header('Location: grc-policies.php?view=' . $policyId . '&msg=created');
                exit;
            } else {
                $msg = t('grc_policies.title_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'new_version' && !$readOnly) {
            $policyId = (int)($_POST['policy_id'] ?? 0);
            $content = $_POST['content'] ?? '';
            $changeSummary = trim($_POST['change_summary'] ?? 'Updated policy content');
            if ($policyId > 0) {
                $policyService->createVersion($policyId, $content, $changeSummary, (int)$user['id']);
                $msg = t('grc_policies.version_created');
                $msgType = 'success';
            }
        } elseif ($action === 'approve' && !$readOnly) {
            $versionId = (int)($_POST['version_id'] ?? 0);
            if ($versionId > 0) {
                $policyService->approveVersion($versionId, (int)$user['id']);
                $msg = t('grc_policies.version_approved');
                $msgType = 'success';
            }
        } elseif ($action === 'publish' && !$readOnly) {
            $versionId = (int)($_POST['version_id'] ?? 0);
            if ($versionId > 0) {
                $policyService->publishVersion($versionId);
                $msg = t('grc_policies.version_published');
                $msgType = 'success';
            }
        } elseif ($action === 'acknowledge') {
            $policyId = (int)($_POST['policy_id'] ?? 0);
            if ($policyId > 0) {
                $policyService->acknowledgePolicy(
                    $policyId,
                    (int)$user['id'],
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                );
                $msg = t('grc_policies.policy_acknowledged');
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

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $msg = t('grc_policies.policy_created');
    $msgType = 'success';
}

// Filters
$filters = [];
if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];
if (!empty($_GET['needs_review'])) $filters['needs_review'] = true;

$policies = $policyService->getPolicies($filters);

// Detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$policyDetail = null;
if ($viewId > 0) {
    $policyDetail = $policyService->getPolicy($viewId);
}

// Edit mode
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editPolicy = null;
if ($editId > 0 && !$readOnly) {
    $editPolicy = $policyService->getPolicy($editId);
}

// Users for owner dropdown
$users = $db->fetchAll('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');

$currentPage = 'grc_policies';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc_policies.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
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
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .filter-bar select, .filter-bar input[type="text"] { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 200px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grc-form .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-info { background: #3b82f6; color: #fff; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize; }
        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-published { background: #d1fae5; color: #065f46; }
        .status-retired { background: #fee2e2; color: #991b1b; }
        .status-superseded { background: #fef3c7; color: #92400e; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        .version-list { list-style: none; padding: 0; margin: 0; }
        .version-list li { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 12px; }
        .version-list li:last-child { border-bottom: none; }

        .ack-progress { background: #e5e7eb; border-radius: 8px; height: 8px; overflow: hidden; margin: 8px 0; }
        .ack-progress-fill { height: 100%; background: #28a745; border-radius: 8px; }

        .autosave-status { font-size: 12px; color: #28a745; margin-left: 12px; }

        .policy-content { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; font-size: 14px; line-height: 1.6; }

        @media (max-width: 900px) {
            .grc-form .form-row, .grc-form .form-row-3 { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc_policies.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc_policies.subheading')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($policyDetail): ?>
            <!-- Policy Detail -->
            <a href="grc-policies.php" class="back-link">&larr; <?php echo e(t('grc_policies.back_to_policies')); ?></a>

            <div class="detail-card">
                <h3><?php echo e($policyDetail['policy_ref']); ?> - <?php echo e($policyDetail['title']); ?></h3>
                <div class="detail-grid">
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_status')); ?></div><div class="value"><span class="status-badge status-<?php echo e($policyDetail['status']); ?>"><?php echo e(ucfirst($policyDetail['status'])); ?></span></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_category')); ?></div><div class="value"><?php echo e(ucfirst($policyDetail['category'] ?? '-')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_owner')); ?></div><div class="value"><?php echo e($policyDetail['owner_name'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_current_version')); ?></div><div class="value">v<?php echo (int)$policyDetail['current_version']; ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_next_review')); ?></div><div class="value"><?php echo $policyDetail['next_review_date'] ? e(date('M j, Y', strtotime($policyDetail['next_review_date']))) : '-'; ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc_policies.label_approver')); ?></div><div class="value"><?php echo e($policyDetail['approver_name'] ?? '-'); ?></div></div>
                </div>
                <?php if (!empty($policyDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;"><?php echo e($policyDetail['description']); ?></p>
                <?php endif; ?>
                <?php if (!$readOnly): ?>
                <div style="margin-top:12px;">
                    <a href="grc-policies.php?edit=<?php echo (int)$policyDetail['id']; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc_policies.edit_new_version')); ?></a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Current Policy Content -->
            <?php
            $currentContent = '';
            foreach ($policyDetail['versions'] as $v) {
                if ($v['status'] === 'published' || ($currentContent === '' && $v['status'] !== 'superseded')) {
                    $currentContent = $v['content_markdown'] ?? '';
                    break;
                }
            }
            if ($currentContent === '' && !empty($policyDetail['versions'])) {
                $currentContent = $policyDetail['versions'][0]['content_markdown'] ?? '';
            }
            ?>
            <?php if ($currentContent): ?>
            <div class="policy-content"><?php echo nl2br(e($currentContent)); ?></div>
            <?php endif; ?>

            <!-- Version History -->
            <div class="detail-card">
                <h3><?php echo e(t('grc_policies.version_history')); ?></h3>
                <?php if (!empty($policyDetail['versions'])): ?>
                <ul class="version-list">
                    <?php foreach ($policyDetail['versions'] as $v): ?>
                    <li>
                        <div style="flex:1;">
                            <strong>v<?php echo (int)$v['version_number']; ?></strong>
                            <span class="status-badge status-<?php echo e($v['status']); ?>" style="margin-left:8px;"><?php echo e(ucfirst($v['status'])); ?></span>
                            <div style="font-size:12px;color:#6b7280;margin-top:4px;">
                                <?php echo e($v['change_summary'] ?? ''); ?>
                                &middot; by <?php echo e($v['created_by_name'] ?? 'Unknown'); ?>
                                <?php if ($v['approved_by_name']): ?>
                                &middot; Approved by <?php echo e($v['approved_by_name']); ?>
                                <?php endif; ?>
                                <?php if ($v['published_at']): ?>
                                &middot; Published <?php echo e(date('M j, Y', strtotime($v['published_at']))); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$readOnly): ?>
                        <div style="display:flex;gap:6px;">
                            <?php if ($v['status'] === 'draft'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success"><?php echo e(t('grc_policies.approve')); ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if ($v['status'] === 'approved'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="publish">
                                <input type="hidden" name="version_id" value="<?php echo (int)$v['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-info"><?php echo e(t('grc_policies.publish')); ?></button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc_policies.no_versions')); ?></p>
                <?php endif; ?>
            </div>

            <!-- Acknowledgment Tracking -->
            <?php if ($policyDetail['requires_acknowledgment'] ?? false): ?>
            <div class="detail-card">
                <h3><?php echo e(t('grc_policies.acknowledgment_tracking')); ?></h3>
                <?php
                $ackCount = count($policyDetail['acknowledgments'] ?? []);
                $totalUsers = (int)($policyDetail['versions'][0]['total_users'] ?? 0);
                if ($totalUsers === 0) {
                    $totalUsers = (int)($db->fetchOne('SELECT COUNT(*) as c FROM users WHERE is_active = 1')['c'] ?? 1);
                }
                $ackPct = $totalUsers > 0 ? round(($ackCount / $totalUsers) * 100) : 0;
                ?>
                <div style="font-size:13px;color:#374151;margin-bottom:8px;">
                    <strong><?php echo $ackCount; ?></strong> of <strong><?php echo $totalUsers; ?></strong> users acknowledged (<?php echo $ackPct; ?>%)
                </div>
                <div class="ack-progress"><div class="ack-progress-fill" style="width:<?php echo $ackPct; ?>%"></div></div>

                <!-- Current user can acknowledge -->
                <?php
                $userHasAcked = false;
                foreach ($policyDetail['acknowledgments'] as $ack) {
                    if ((int)$ack['user_id'] === (int)$user['id']) {
                        $userHasAcked = true;
                        break;
                    }
                }
                ?>
                <?php if (!$userHasAcked && $policyDetail['status'] === 'published'): ?>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="acknowledge">
                    <input type="hidden" name="policy_id" value="<?php echo (int)$policyDetail['id']; ?>">
                    <button type="submit" class="btn btn-success"><?php echo e(t('grc_policies.acknowledge_button')); ?></button>
                </form>
                <?php elseif ($userHasAcked): ?>
                <p style="color:#065f46;font-size:13px;margin-top:8px;"><?php echo e(t('grc_policies.you_acknowledged')); ?></p>
                <?php endif; ?>

                <?php if (!empty($policyDetail['acknowledgments'])): ?>
                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;font-size:13px;font-weight:500;color:#374151;"><?php echo e(t('grc_policies.view_acknowledgments')); ?></summary>
                    <table class="grc-table" style="margin-top:8px;">
                        <thead><tr><th><?php echo e(t('grc_policies.col_user')); ?></th><th><?php echo e(t('grc_policies.col_email')); ?></th><th><?php echo e(t('grc_policies.col_acknowledged_at')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($policyDetail['acknowledgments'] as $ack): ?>
                        <tr>
                            <td><?php echo e($ack['full_name']); ?></td>
                            <td><?php echo e($ack['email']); ?></td>
                            <td><?php echo e(date('M j, Y g:i A', strtotime($ack['acknowledged_at']))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>

                <?php if (!empty($policyDetail['pending_ack_users'])): ?>
                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;font-size:13px;font-weight:500;color:#374151;">Pending Acknowledgments (<?php echo count($policyDetail['pending_ack_users']); ?>)</summary>
                    <table class="grc-table" style="margin-top:8px;">
                        <thead><tr><th><?php echo e(t('grc_policies.col_user')); ?></th><th><?php echo e(t('grc_policies.col_email')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($policyDetail['pending_ack_users'] as $pu): ?>
                        <tr>
                            <td><?php echo e($pu['full_name']); ?></td>
                            <td><?php echo e($pu['email']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($editPolicy && !$readOnly): ?>
            <!-- Edit / New Version -->
            <a href="grc-policies.php?view=<?php echo (int)$editPolicy['id']; ?>" class="back-link">&larr; <?php echo e(t('grc_policies.back_to_policy')); ?></a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo e(t('grc_policies.edit_policy_new_version')); ?><span class="autosave-status"></span></h3>
                <?php
                $latestContent = '';
                if (!empty($editPolicy['versions'])) {
                    $latestContent = $editPolicy['versions'][0]['content_markdown'] ?? '';
                }
                ?>
                <form method="post" class="grc-autosave-form" data-form-type="policy_edit" data-form-id="<?php echo (int)$editPolicy['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="new_version">
                    <input type="hidden" name="policy_id" value="<?php echo (int)$editPolicy['id']; ?>">

                    <label for="change_summary"><?php echo e(t('grc_policies.change_summary')); ?></label>
                    <input type="text" id="change_summary" name="change_summary" placeholder="<?php echo e(t('grc_policies.change_summary_placeholder')); ?>" required>

                    <label for="content"><?php echo e(t('grc_policies.policy_content')); ?></label>
                    <textarea id="content" name="content"><?php echo e($latestContent); ?></textarea>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn btn-primary"><?php echo e(t('grc_policies.save_new_version')); ?></button>
                        <a href="grc-policies.php?view=<?php echo (int)$editPolicy['id']; ?>" class="btn btn-outline"><?php echo e(t('grc_policies.cancel')); ?></a>
                    </div>
                </form>
            </div>

            <?php elseif (isset($_GET['new']) && !$readOnly): ?>
            <!-- Create Policy -->
            <a href="grc-policies.php" class="back-link">&larr; <?php echo e(t('grc_policies.back_to_policies')); ?></a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo e(t('grc_policies.create_policy')); ?><span class="autosave-status"></span></h3>
                <form method="post" class="grc-autosave-form" data-form-type="policy_edit" data-form-id="new">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="create">

                    <label for="title"><?php echo e(t('grc_policies.title')); ?></label>
                    <input type="text" id="title" name="title" required placeholder="<?php echo e(t('grc_policies.title_placeholder')); ?>">

                    <label for="description"><?php echo e(t('grc_policies.description')); ?></label>
                    <input type="text" id="description" name="description" placeholder="<?php echo e(t('grc_policies.description_placeholder')); ?>">

                    <div class="form-row-3">
                        <div>
                            <label for="category"><?php echo e(t('grc_policies.category')); ?></label>
                            <select id="category" name="category">
                                <?php foreach (['security', 'privacy', 'compliance', 'hr', 'it', 'operations', 'other'] as $cat): ?>
                                <option value="<?php echo e($cat); ?>"><?php echo e(ucfirst($cat)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="owner_user_id"><?php echo e(t('grc_policies.owner')); ?></label>
                            <select id="owner_user_id" name="owner_user_id">
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo ((int)$user['id']) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="review_frequency_months"><?php echo e(t('grc_policies.review_frequency')); ?></label>
                            <input type="number" id="review_frequency_months" name="review_frequency_months" value="12" min="1" max="60">
                        </div>
                    </div>

                    <div class="form-row">
                        <div>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                <input type="checkbox" name="requires_acknowledgment" checked style="width:auto;margin:0;">
                                <?php echo e(t('grc_policies.require_acknowledgment')); ?>
                            </label>
                        </div>
                        <div>
                            <label for="acknowledgment_deadline_days"><?php echo e(t('grc_policies.acknowledgment_deadline')); ?></label>
                            <input type="number" id="acknowledgment_deadline_days" name="acknowledgment_deadline_days" value="30" min="1" max="365">
                        </div>
                    </div>

                    <label for="content"><?php echo e(t('grc_policies.policy_content')); ?></label>
                    <textarea id="content" name="content" placeholder="<?php echo e(t('grc_policies.content_placeholder')); ?>"></textarea>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn btn-primary"><?php echo e(t('grc_policies.create_policy')); ?></button>
                        <a href="grc-policies.php" class="btn btn-outline"><?php echo e(t('grc_policies.cancel')); ?></a>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <!-- Policy List -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <form method="get" class="filter-bar">
                    <div>
                        <label><?php echo e(t('grc_policies.filter_status')); ?></label>
                        <select name="status" onchange="this.form.submit()">
                            <option value=""><?php echo e(t('grc_policies.all_statuses')); ?></option>
                            <?php foreach (['draft', 'approved', 'published', 'retired'] as $s): ?>
                            <option value="<?php echo e($s); ?>" <?php echo ($filters['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo e(ucfirst($s)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php echo e(t('grc_policies.filter_category')); ?></label>
                        <select name="category" onchange="this.form.submit()">
                            <option value=""><?php echo e(t('grc_policies.all_categories')); ?></option>
                            <?php foreach (['security', 'privacy', 'compliance', 'hr', 'it', 'operations', 'other'] as $cat): ?>
                            <option value="<?php echo e($cat); ?>" <?php echo ($filters['category'] ?? '') === $cat ? 'selected' : ''; ?>><?php echo e(ucfirst($cat)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php echo e(t('grc_policies.filter_search')); ?></label>
                        <input type="text" name="search" value="<?php echo e($filters['search'] ?? ''); ?>" placeholder="<?php echo e(t('grc_policies.search_placeholder')); ?>">
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;"><?php echo e(t('grc_policies.filter_button')); ?></button>
                    <a href="grc-policies.php?needs_review=1" class="btn btn-sm <?php echo !empty($filters['needs_review']) ? 'btn-primary' : 'btn-outline'; ?>" style="margin-bottom:0;"><?php echo e(t('grc_policies.due_for_review')); ?></a>
                </form>
                <?php if (!$readOnly): ?>
                <a href="grc-policies.php?new=1" class="btn btn-primary">+ <?php echo e(t('grc_policies.new_policy')); ?></a>
                <?php endif; ?>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('grc_policies.col_reference')); ?></th>
                        <th><?php echo e(t('grc_policies.col_title')); ?></th>
                        <th><?php echo e(t('grc_policies.col_status')); ?></th>
                        <th><?php echo e(t('grc_policies.col_category')); ?></th>
                        <th><?php echo e(t('grc_policies.col_owner')); ?></th>
                        <th><?php echo e(t('grc_policies.col_version')); ?></th>
                        <th><?php echo e(t('grc_policies.col_next_review')); ?></th>
                        <th><?php echo e(t('grc_policies.col_acknowledged')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($policies)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#6b7280;padding:30px;"><?php echo e(t('grc_policies.no_policies')); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($policies as $p):
                        $isOverdue = !empty($p['next_review_date']) && strtotime($p['next_review_date']) < time();
                        $ackRatio = ((int)($p['total_users'] ?? 0)) > 0
                            ? round(((int)($p['ack_count'] ?? 0) / (int)$p['total_users']) * 100) . '%'
                            : '-';
                    ?>
                    <tr>
                        <td><a href="grc-policies.php?view=<?php echo (int)$p['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($p['policy_ref']); ?></a></td>
                        <td><?php echo e($p['title']); ?></td>
                        <td><span class="status-badge status-<?php echo e($p['status']); ?>"><?php echo e(ucfirst($p['status'])); ?></span></td>
                        <td><?php echo e(ucfirst($p['category'] ?? '-')); ?></td>
                        <td><?php echo e($p['owner_name'] ?? '-'); ?></td>
                        <td>v<?php echo (int)$p['current_version']; ?></td>
                        <td style="<?php echo $isOverdue ? 'color:#dc3545;font-weight:600;' : ''; ?>">
                            <?php echo $p['next_review_date'] ? e(date('M j, Y', strtotime($p['next_review_date']))) : '-'; ?>
                            <?php if ($isOverdue): ?> (<?php echo e(t('grc_policies.overdue')); ?>)<?php endif; ?>
                        </td>
                        <td><?php echo e($ackRatio); ?></td>
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
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc_policies.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc_policies.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Initialize TinyMCE for policy content editing
if (document.getElementById('content') && typeof tinymce !== 'undefined') {
    tinymce.init({
        selector: '#content',
        height: 400,
        menubar: true,
        plugins: 'lists link table code wordcount',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | code',
        content_style: 'body { font-family: Roboto, sans-serif; font-size: 14px; }',
        setup: function(editor) {
            editor.on('change keyup', function() {
                editor.save();
            });
        }
    });
}

// Autosave every 30 seconds for forms with class .grc-autosave-form
var autosaveTimer;
document.querySelectorAll('.grc-autosave-form').forEach(function(form) {
    form.addEventListener('input', function() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(function() { grcAutosave(form); }, 5000);
    });
});
function grcAutosave(form) {
    var data = {};
    new FormData(form).forEach(function(v, k) { if (k !== 'csrf_token') data[k] = v; });
    fetch('/api/grc-autosave.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            csrf_token: document.querySelector('input[name=csrf_token]').value,
            form_type: form.dataset.formType || 'unknown',
            form_id: form.dataset.formId || null,
            draft_data: data
        })
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.csrf_token) document.querySelector('input[name=csrf_token]').value = d.csrf_token;
        var indicator = form.querySelector('.autosave-status');
        if (indicator) { indicator.textContent = 'Draft saved'; setTimeout(function() { indicator.textContent = ''; }, 3000); }
    });
}
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
