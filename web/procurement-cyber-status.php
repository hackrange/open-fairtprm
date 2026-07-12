<?php
/**
 * Procurement Cyber Status - Vendors in Review & Update History
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * A procurement-facing page listing vendors whose onboarding status is
 * 'in_review' or 'ai_review'. Selecting a vendor (?vendor_id=N) shows that
 * vendor's procurement update history — the most recent updates, paginated.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();

// Permission gate: admin, procurement, cyber_tprm only
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');
$isAuditor = hasGroup('auditor');
$isStakeholderGroup = hasGroup('stakeholder');
$isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM && !$isAuditor;

if (!$isAdmin && !$isProcurement && !$isCyberTPRM) {
    http_response_code(403);
    die(e(t('procurement-cyber-status.access_denied')));
}

// cyber_tprm / administrators may multi-select vendors and provide a procurement
// update (with an optional status change), just like vendor-onboarding-list.php.
$canApprove = $isAdmin || $isCyberTPRM;
$security = Security::getInstance();
$csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();

// Sidebar nav flags (same pattern as index.php)
$showOnboarding = hasPermission('onboarding.create') ||
                  hasPermission('onboarding.read') ||
                  hasPermission('onboarding.read_own') ||
                  hasPermission('onboarding.read_assigned') ||
                  hasGroup('stakeholder') || hasGroup('procurement');
$showFairModule = (hasPermission('analysis.create') ||
                  hasPermission('analysis.read') ||
                  $isCyberTPRM ||
                  $isAdmin) && !$isStakeholderOnly;
$showSRSModule = ($isCyberTPRM || $isAdmin || $isAuditor) && !$isStakeholderOnly;

require_once __DIR__ . '/includes/classes/Pagination.php';

// Vendors currently in review (in_review + ai_review), with update counts.
$reviewVendors = [];
try {
    $reviewVendors = $db->fetchAll("
        SELECT r.id, r.vendor_name, r.status,
               COUNT(u.id) AS update_count,
               MAX(u.created_at) AS latest_update
        FROM vendor_onboarding_requests r
        LEFT JOIN vendor_procurement_updates u ON u.request_id = r.id
        WHERE r.status IN ('in_review', 'ai_review')
        GROUP BY r.id, r.vendor_name, r.status
        ORDER BY r.vendor_name ASC
    ");
} catch (Exception $e) {}

// Selected vendor detail
$vendorId = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : 0;
$selectedVendor = null;
$updates = [];
$pg = null;
if ($vendorId > 0) {
    try {
        $selectedVendor = $db->fetchOne("
            SELECT id, vendor_name, status
            FROM vendor_onboarding_requests
            WHERE id = :id AND status IN ('in_review', 'ai_review')
        ", [':id' => $vendorId]);
    } catch (Exception $e) {}

    if ($selectedVendor) {
        $pgParams = Pagination::getParams([
            'per_page' => 5,
            'per_page_options' => [5, 10, 25],
            'sort_column' => 'created_at',
            'sort_dir' => 'DESC',
            'valid_sort_columns' => ['created_at'],
        ]);
        $perPage = $pgParams['per_page'];
        $currentPageNum = $pgParams['page'];

        $totalUpdates = 0;
        try {
            $countRow = $db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM vendor_procurement_updates WHERE request_id = :rid",
                [':rid' => $vendorId]
            );
            $totalUpdates = (int)($countRow['cnt'] ?? 0);
        } catch (Exception $e) {}

        $pg = Pagination::paginate($totalUpdates, $perPage, $currentPageNum);
        $offset = $pg['offset'];

        try {
            $updates = $db->fetchAll("
                SELECT pu.id, pu.update_text, pu.status_at_update, pu.created_at,
                       pu.created_by, pu.edited_at,
                       u.full_name AS author_name
                FROM vendor_procurement_updates pu
                LEFT JOIN users u ON pu.created_by = u.id
                WHERE pu.request_id = :rid
                ORDER BY pu.created_at DESC, pu.id DESC
                LIMIT {$perPage} OFFSET {$offset}
            ", [':rid' => $vendorId]);
        } catch (Exception $e) {}
    }
}

// Helper: human label for a review status value.
function cyberStatusLabel($status) {
    return $status === 'ai_review' ? t('procurement-cyber-status.status_ai_review') : t('procurement-cyber-status.status_in_review');
}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('procurement-cyber-status.page_title_tag')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
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
        body { margin: 0; font-family: 'Roboto', sans-serif; }

        .page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-header { display: none !important; }

        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-menu a {
            color: #333;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            background: rgba(255,101,67,0.1);
            transition: background 0.2s;
            font-size: 14px;
        }
        .user-menu a:hover {
            background: rgba(255,101,67,0.2);
        }

        .main-layout {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
        }

        .sidebar {
            width: var(--sidebar-width) !important;
            min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important;
            background: var(--nav-fill-color) !important;
            padding: 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color);
            font-size: 13px;
            font-weight: 500;
            margin-top: 8px;
            opacity: 0.9;
        }

        .sidebar-content {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }

        .sidebar-section { margin-bottom: 25px; }

        .sidebar-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--nav-font-color);
            opacity: 0.5;
            padding: 0 20px;
            margin-bottom: 10px;
        }

        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 20px;
            color: var(--nav-font-color);
            opacity: 0.85;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1);
            opacity: 1;
            border-left-color: var(--nav-font-color);
        }

        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15);
            opacity: 1;
            border-left-color: var(--nav-font-color);
            font-weight: 500;
        }

        .sidebar-nav li a .icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
            opacity: 0.9;
        }

        .sidebar-nav li a .badge {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: var(--nav-font-color);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }

        .main-content {
            flex: 1;
            padding: 35px 40px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }

        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0; }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }

        /* Vendor list table */
        .vendor-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .vendor-table th, .vendor-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .vendor-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .vendor-table tr:hover { background: #fafafa; }
        .vendor-table tr:last-child td { border-bottom: none; }
        .vendor-table tr.selected { background: #fff5f2; }

        .vendor-link {
            color: var(--theme-header-color);
            text-decoration: none;
            font-weight: 500;
        }
        .vendor-link:hover { text-decoration: underline; }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.in_review { background: #fff3cd; color: #856404; }
        .status-badge.ai_review { background: #e0e7ff; color: #3730a3; }

        .meta-info { font-size: 12px; color: #888; }

        /* Update history detail panel */
        .detail-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 25px 30px;
            margin-bottom: 30px;
        }
        .detail-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .detail-header h2 { font-size: 18px; font-weight: 600; color: #333; margin: 0; }

        .update-item {
            border-left: 3px solid var(--theme-header-color);
            padding: 12px 18px;
            margin-bottom: 14px;
            background: #f9fafb;
            border-radius: 0 6px 6px 0;
        }
        .update-item:last-child { margin-bottom: 0; }
        .update-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
            font-size: 12px;
            color: #888;
        }
        .update-meta .update-author { color: #555; font-weight: 500; }
        .update-text {
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 8px;
        }
        .empty-state h3 { color: #666; margin-bottom: 10px; font-size: 18px; font-weight: 600; }
        .empty-state p { color: #999; }

        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }

        .preloader { display: none !important; }

        @media (max-width: 992px) {
            .main-layout { flex-direction: column; }
            .sidebar {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar-brand {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
            }
            .sidebar-brand img { max-width: 150px; }
            .sidebar-brand .brand-title { margin-top: 0; }
            .sidebar-content { padding: 10px 0; }
            .sidebar-section { margin-bottom: 10px; }
            .sidebar-section-title { padding: 0 15px; margin-bottom: 8px; }
            .sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                padding: 0 10px;
            }
            .sidebar-nav li { flex: 0 0 auto; }
            .sidebar-nav li a {
                padding: 8px 14px;
                border-radius: 6px;
                margin: 3px;
                border-left: none;
            }
            .sidebar-nav li a:hover,
            .sidebar-nav li a.active {
                border-left: none;
                background: rgba(255,255,255,0.2);
            }
            .main-content { padding: 25px 20px; }
        }

        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .user-menu {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }
            .sidebar-nav {
                flex-direction: column;
                padding: 0 10px;
            }
            .sidebar-nav li { width: 100%; }
            .sidebar-nav li a {
                margin: 2px 0;
                border-radius: 6px;
            }
            .main-content { padding: 20px 15px; }
            .vendor-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <!-- Sidebar Navigation -->
            <?php $currentPage = 'cyber_status'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="page-header-row">
                    <h1 class="page-title"><?php echo e(t('procurement-cyber-status.page_title')); ?></h1>
                </div>

                <!-- Vendors in Review -->
                <div class="section-title"><?php echo e(t('procurement-cyber-status.vendors_in_review_heading')); ?></div>
                <?php if (empty($reviewVendors)): ?>
                <div class="empty-state">
                    <h3><?php echo e(t('procurement-cyber-status.no_vendors_in_review_title')); ?></h3>
                    <p><?php echo e(t('procurement-cyber-status.no_vendors_in_review_body')); ?></p>
                </div>
                <?php else: ?>
                <table class="vendor-table">
                    <thead>
                        <tr>
                            <?php if ($canApprove): ?><th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAllReview" title="<?php echo e(t('procurement-cyber-status.select_all_vendors_title')); ?>" style="cursor: pointer;"></th><?php endif; ?>
                            <th><?php echo e(t('procurement-cyber-status.th_vendor')); ?></th>
                            <th><?php echo e(t('procurement-cyber-status.th_status')); ?></th>
                            <th><?php echo e(t('procurement-cyber-status.th_updates')); ?></th>
                            <th><?php echo e(t('procurement-cyber-status.th_latest_update')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviewVendors as $rv):
                            $rvId = (int)$rv['id'];
                            $isSelected = ($vendorId === $rvId && $selectedVendor);
                            $rvStatus = $rv['status'] === 'ai_review' ? 'ai_review' : 'in_review';
                        ?>
                        <tr class="<?php echo $isSelected ? 'selected' : ''; ?>">
                            <?php if ($canApprove): ?>
                            <td style="text-align: center;">
                                <input type="checkbox" class="review-checkbox" value="<?php echo $rvId; ?>" data-vendor="<?php echo e($rv['vendor_name']); ?>" style="cursor: pointer;">
                            </td>
                            <?php endif; ?>
                            <td>
                                <a href="?vendor_id=<?php echo $rvId; ?>" class="vendor-link"><?php echo e($rv['vendor_name']); ?></a>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $rvStatus; ?>"><?php echo e(cyberStatusLabel($rv['status'])); ?></span>
                            </td>
                            <td><?php echo (int)$rv['update_count']; ?></td>
                            <td class="meta-info">
                                <?php echo !empty($rv['latest_update'])
                                    ? e(date('M j, Y', strtotime($rv['latest_update'])))
                                    : '&mdash;'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($canApprove && !empty($reviewVendors)): ?>
                <!-- Procurement Update Action Bar (hidden until vendors are selected) -->
                <div id="procUpdateBar" style="display: none; position: sticky; bottom: 0; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 20px; margin: -10px 0 30px; box-shadow: 0 -2px 8px rgba(0,0,0,0.1); z-index: 10; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 14px; color: #3730a3; font-weight: 500;"><span id="procSelectedCount">0</span> <?php echo e(t('procurement-cyber-status.vendors_selected_suffix')); ?></span>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" id="procClearSelection" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; color: #374151; font-size: 13px; cursor: pointer;"><?php echo e(t('procurement-cyber-status.clear_selection')); ?></button>
                        <button type="button" id="procProvideUpdateBtn" style="padding: 8px 16px; border: none; border-radius: 6px; background: #4f46e5; color: white; font-size: 13px; font-weight: 600; cursor: pointer;"><?php echo e(t('procurement-cyber-status.provide_update_button')); ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Selected Vendor Detail -->
                <?php if ($selectedVendor): ?>
                <div class="detail-panel">
                    <div class="detail-header">
                        <h2><?php echo e($selectedVendor['vendor_name']); ?></h2>
                        <?php $svStatus = $selectedVendor['status'] === 'ai_review' ? 'ai_review' : 'in_review'; ?>
                        <span class="status-badge <?php echo $svStatus; ?>"><?php echo e(cyberStatusLabel($selectedVendor['status'])); ?></span>
                    </div>

                    <div class="section-title"><?php echo e(t('procurement-cyber-status.procurement_update_history_heading')); ?></div>

                    <?php if (empty($updates)): ?>
                    <div class="empty-state">
                        <h3><?php echo e(t('procurement-cyber-status.no_updates_title')); ?></h3>
                        <p><?php echo e(t('procurement-cyber-status.no_updates_body')); ?></p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($updates as $upd):
                            $author = !empty($upd['author_name']) ? $upd['author_name'] : t('procurement-cyber-status.author_system');
                            // Per-object authz: cyber_tprm / admin, or the note's own author.
                            $isNoteAuthor = isset($upd['created_by']) && (int)$upd['created_by'] === (int)$user['id'];
                            $canManageNote = $isAdmin || $isCyberTPRM || $isNoteAuthor;
                        ?>
                        <div class="update-item" data-note-id="<?php echo (int)$upd['id']; ?>">
                            <div class="update-meta">
                                <span><?php echo e(date('M j, Y g:i A', strtotime($upd['created_at']))); ?></span>
                                <span class="update-author"><?php echo e($author); ?></span>
                                <?php if (!empty($upd['status_at_update'])): ?>
                                    <?php $suStatus = $upd['status_at_update'] === 'ai_review' ? 'ai_review' : ($upd['status_at_update'] === 'in_review' ? 'in_review' : ''); ?>
                                    <span class="status-badge <?php echo e($suStatus); ?>"><?php echo e($upd['status_at_update']); ?></span>
                                <?php endif; ?>
                                <span class="note-edited-marker" style="font-size:12px; color:#9ca3af; font-style:italic;<?php echo empty($upd['edited_at']) ? ' display:none;' : ''; ?>">(<?php echo e(t('procurement-cyber-status.edited_label')); ?><?php echo !empty($upd['edited_at']) ? ' ' . e(date('M j, Y g:i A', strtotime($upd['edited_at']))) : ''; ?>)</span>
                                <?php if ($canManageNote): ?>
                                <span class="note-actions" style="margin-left:auto; display:inline-flex; gap:10px;">
                                    <button type="button" class="note-edit-btn" style="background:none; border:none; padding:0; color:#4f46e5; font-size:13px; cursor:pointer;"><?php echo e(t('procurement-cyber-status.action_edit')); ?></button>
                                    <button type="button" class="note-delete-btn" style="background:none; border:none; padding:0; color:#dc2626; font-size:13px; cursor:pointer;"><?php echo e(t('procurement-cyber-status.action_delete')); ?></button>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="update-text" style="white-space:pre-wrap;"><?php echo e($upd['update_text']); ?></div>
                            <?php if ($canManageNote): ?>
                            <div class="note-edit-form" style="display:none; margin-top:8px;">
                                <textarea class="note-edit-textarea" rows="4" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; font-family:inherit; box-sizing:border-box;"><?php echo e($upd['update_text']); ?></textarea>
                                <div style="margin-top:6px; display:flex; gap:8px; align-items:center;">
                                    <button type="button" class="note-edit-save" style="padding:6px 14px; border:none; border-radius:6px; background:#4f46e5; color:#fff; font-size:13px; font-weight:600; cursor:pointer;"><?php echo e(t('procurement-cyber-status.action_save')); ?></button>
                                    <button type="button" class="note-edit-cancel" style="padding:6px 14px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#374151; font-size:13px; cursor:pointer;"><?php echo e(t('procurement-cyber-status.action_cancel')); ?></button>
                                    <span class="note-edit-msg" style="font-size:12px;"></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($pg) { Pagination::renderControls($pg, 'updates', [5, 10, 25]); } ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </main>
        </div>

        <?php if ($canApprove): ?>
        <!-- Provide Procurement with Update modal (cyber_tprm / admin) -->
        <div id="procUpdateModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:8px; width:90%; max-width:560px; max-height:90vh; overflow:auto; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; color:#111827;"><?php echo e(t('procurement-cyber-status.provide_update_button')); ?></h3>
                    <button type="button" id="procModalClose" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#6b7280;">&times;</button>
                </div>
                <div style="padding:20px;">
                    <p style="margin:0 0 12px; font-size:13px; color:#6b7280;"><span id="procModalCount">0</span> <?php echo e(t('procurement-cyber-status.vendors_selected_colon')); ?> <span id="procModalVendors" style="color:#374151;"></span></p>
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;"><?php echo e(t('procurement-cyber-status.label_update')); ?></label>
                    <textarea id="procUpdateText" rows="5" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; font-family:inherit; box-sizing:border-box;" placeholder="<?php echo e(t('procurement-cyber-status.update_placeholder')); ?>"></textarea>
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin:14px 0 6px;"><?php echo e(t('procurement-cyber-status.change_status_label')); ?></label>
                    <select id="procNewStatus" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                        <option value=""><?php echo e(t('procurement-cyber-status.status_keep_current')); ?></option>
                        <option value="in_review"><?php echo e(t('procurement-cyber-status.status_in_review')); ?></option>
                        <option value="ai_review"><?php echo e(t('procurement-cyber-status.status_ai_review')); ?></option>
                        <option value="evaluation"><?php echo e(t('procurement-cyber-status.status_evaluation')); ?></option>
                        <option value="approved"><?php echo e(t('procurement-cyber-status.status_approved')); ?></option>
                        <option value="rejected"><?php echo e(t('procurement-cyber-status.status_rejected')); ?></option>
                        <option value="inactive"><?php echo e(t('procurement-cyber-status.status_inactive')); ?></option>
                    </select>
                    <div id="procModalMsg" style="margin-top:14px; font-size:13px;"></div>
                </div>
                <div style="padding:16px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" id="procModalCancel" style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:white; color:#374151; font-size:13px; cursor:pointer;"><?php echo e(t('procurement-cyber-status.close')); ?></button>
                    <button type="button" id="procModalSubmit" style="padding:8px 16px; border:none; border-radius:6px; background:#4f46e5; color:white; font-size:13px; font-weight:600; cursor:pointer;"><?php echo e(t('procurement-cyber-status.save_update')); ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('procurement-cyber-status.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php if ($canApprove): ?>
    <script nonce="<?php echo cspNonce(); ?>">
    // Provide Procurement with Update — multi-select + modal (reuses api/procurement-update-save.php)
    (function() {
        var csrf = <?php echo json_encode($csrfToken); ?>;
        var reloadOnClose = false;
        var pBar = document.getElementById('procUpdateBar');
        var pModal = document.getElementById('procUpdateModal');
        var reviewBoxes = document.querySelectorAll('.review-checkbox');
        var selectAllReview = document.getElementById('selectAllReview');

        if (pBar && reviewBoxes.length) {
            var pCount = document.getElementById('procSelectedCount');
            function pUpdateBar() {
                var checked = document.querySelectorAll('.review-checkbox:checked');
                pCount.textContent = checked.length;
                pBar.style.display = checked.length > 0 ? 'flex' : 'none';
                if (selectAllReview) {
                    selectAllReview.checked = checked.length > 0 && checked.length === reviewBoxes.length;
                    selectAllReview.indeterminate = checked.length > 0 && checked.length < reviewBoxes.length;
                }
            }
            if (selectAllReview) selectAllReview.addEventListener('change', function() { reviewBoxes.forEach(function(cb) { cb.checked = selectAllReview.checked; }); pUpdateBar(); });
            reviewBoxes.forEach(function(cb) { cb.addEventListener('change', pUpdateBar); });
            var pClear = document.getElementById('procClearSelection');
            if (pClear) pClear.addEventListener('click', function() { reviewBoxes.forEach(function(cb) { cb.checked = false; }); pUpdateBar(); });

            var openBtn = document.getElementById('procProvideUpdateBtn');
            if (openBtn && pModal) {
                openBtn.addEventListener('click', function() {
                    var checked = document.querySelectorAll('.review-checkbox:checked');
                    if (!checked.length) return;
                    var names = Array.prototype.map.call(checked, function(cb) { return cb.getAttribute('data-vendor'); });
                    document.getElementById('procModalCount').textContent = checked.length;
                    document.getElementById('procModalVendors').textContent = names.join(', ');
                    document.getElementById('procUpdateText').value = '';
                    document.getElementById('procNewStatus').value = '';
                    document.getElementById('procModalMsg').textContent = '';
                    pModal.style.display = 'flex';
                });
            }
        }
        if (pModal) {
            // Modal stays open until the user explicitly closes it (no backdrop / Escape close).
            function closeProc() { pModal.style.display = 'none'; if (reloadOnClose) window.location.reload(); }
            var pmClose = document.getElementById('procModalClose');
            var pmCancel = document.getElementById('procModalCancel');
            if (pmClose) pmClose.addEventListener('click', closeProc);
            if (pmCancel) pmCancel.addEventListener('click', closeProc);
            var pmSubmit = document.getElementById('procModalSubmit');
            if (pmSubmit) pmSubmit.addEventListener('click', function() {
                var text = document.getElementById('procUpdateText').value.trim();
                var msg = document.getElementById('procModalMsg');
                if (!text) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_enter_update')); ?>; return; }
                var checked = document.querySelectorAll('.review-checkbox:checked');
                var ids = Array.prototype.map.call(checked, function(cb) { return parseInt(cb.value, 10); });
                if (!ids.length) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_no_vendors_selected')); ?>; return; }
                pmSubmit.disabled = true;
                msg.style.color = '#6b7280'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_saving')); ?>;
                fetch('api/procurement-update-save.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrf, vendor_ids: ids, update_text: text, new_status: document.getElementById('procNewStatus').value })
                }).then(function(r) { return r.json(); }).then(function(resp) {
                    pmSubmit.disabled = false;
                    if (resp.csrf_token) csrf = resp.csrf_token;
                    if (!resp.success) { msg.style.color = '#dc2626'; msg.textContent = resp.error || <?php echo json_encode(t('procurement-cyber-status.js_failed_save')); ?>; return; }
                    reloadOnClose = true;
                    msg.style.color = '#166534';
                    msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_saved_prefix')); ?> + (resp.updated || ids.length) + <?php echo json_encode(t('procurement-cyber-status.js_saved_suffix')); ?>;
                }).catch(function() { pmSubmit.disabled = false; msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_network_error')); ?>; });
            });
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ($selectedVendor): ?>
    <!-- Inline edit / delete of procurement notes (api/procurement-note-manage.php).
         Buttons are only rendered for notes the viewer may manage (cyber_tprm/admin
         or the note's author); the server re-checks authorization on every call. -->
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var csrf = <?php echo json_encode($csrfToken); ?>;
        var panel = document.querySelector('.detail-panel');
        if (!panel) return;

        function post(payload) {
            payload.csrf_token = csrf;
            return fetch('api/procurement-note-manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function(r) { return r.json(); });
        }

        panel.addEventListener('click', function(ev) {
            var btn = ev.target;
            if (!btn || !btn.closest) return;
            var item = btn.closest('.update-item');
            if (!item) return;
            var noteId = parseInt(item.getAttribute('data-note-id'), 10);
            if (!noteId) return;

            var textEl  = item.querySelector('.update-text');
            var form    = item.querySelector('.note-edit-form');
            var actions = item.querySelector('.note-actions');

            // --- Enter edit mode ---
            if (btn.classList.contains('note-edit-btn')) {
                if (textEl) textEl.style.display = 'none';
                if (actions) actions.style.display = 'none';
                if (form) {
                    form.style.display = 'block';
                    var ta = form.querySelector('.note-edit-textarea');
                    if (ta) ta.focus();
                }
                return;
            }

            // --- Cancel edit ---
            if (btn.classList.contains('note-edit-cancel')) {
                if (form) form.style.display = 'none';
                if (textEl) textEl.style.display = '';
                if (actions) actions.style.display = '';
                var cmsg = form ? form.querySelector('.note-edit-msg') : null;
                if (cmsg) cmsg.textContent = '';
                return;
            }

            // --- Save edit ---
            if (btn.classList.contains('note-edit-save')) {
                var ta2 = form ? form.querySelector('.note-edit-textarea') : null;
                var msg = form ? form.querySelector('.note-edit-msg') : null;
                var text = ta2 ? ta2.value.trim() : '';
                if (!text) { if (msg) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_note_text_required')); ?>; } return; }
                btn.disabled = true;
                if (msg) { msg.style.color = '#6b7280'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_saving')); ?>; }
                post({ action: 'edit', note_id: noteId, update_text: text }).then(function(resp) {
                    btn.disabled = false;
                    if (!resp.success) { if (msg) { msg.style.color = '#dc2626'; msg.textContent = resp.error || <?php echo json_encode(t('procurement-cyber-status.js_failed_save')); ?>; } return; }
                    if (textEl) {
                        textEl.textContent = resp.text;   // textContent: XSS-safe; pre-wrap preserves newlines
                        textEl.style.display = '';
                    }
                    if (form) form.style.display = 'none';
                    if (actions) actions.style.display = '';
                    if (resp.edited_at) {
                        var marker = item.querySelector('.note-edited-marker');
                        if (marker) { marker.textContent = '(' + <?php echo json_encode(t('procurement-cyber-status.edited_label')); ?> + ' ' + resp.edited_at + ')'; marker.style.display = ''; }
                    }
                    if (msg) msg.textContent = '';
                }).catch(function() { btn.disabled = false; if (msg) { msg.style.color = '#dc2626'; msg.textContent = <?php echo json_encode(t('procurement-cyber-status.js_network_error')); ?>; } });
                return;
            }

            // --- Delete ---
            if (btn.classList.contains('note-delete-btn')) {
                if (!window.confirm(<?php echo json_encode(t('procurement-cyber-status.js_confirm_delete_note')); ?>)) return;
                btn.disabled = true;
                post({ action: 'delete', note_id: noteId }).then(function(resp) {
                    if (!resp.success) { btn.disabled = false; alert(resp.error || <?php echo json_encode(t('procurement-cyber-status.js_failed_delete')); ?>); return; }
                    if (item.parentNode) item.parentNode.removeChild(item);
                }).catch(function() { btn.disabled = false; alert(<?php echo json_encode(t('procurement-cyber-status.js_network_error')); ?>); });
                return;
            }
        });
    })();
    </script>
    <?php endif; ?>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
