<?php
/**
 * Breach / Cyber Alerts - Main Dashboard
 *
 * Displays AI-researched breach and cyber threat alerts affecting monitored
 * vendors, subprocessors, and technologies. Provides status management,
 * impact analysis, and credible source links.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

$isAdmin     = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isAuditor   = hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die('Access denied. Breach/Cyber Alerts requires cyber_tprm, administrator, or auditor role.');
}

$canManage = $isAdmin || $isCyberTPRM;
$breachService = BreachAlertService::getInstance();

$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid CSRF token. Please try again.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $alertId = (int)($_POST['alert_id'] ?? 0);

        if ($action === 'update_status' && $alertId > 0) {
            $newStatus = $_POST['new_status'] ?? '';
            $notes = trim($_POST['resolution_notes'] ?? '');
            $result = $breachService->updateStatus($alertId, $newStatus, (int)$user['id'], $notes ?: null);
            if ($result['success']) {
                $msg = 'Alert status updated.';
                $msgType = 'success';
            } else {
                $msg = $result['error'] ?? 'Update failed.';
                $msgType = 'danger';
            }
        } elseif ($action === 'delete_alert' && $alertId > 0 && $isAdmin) {
            $result = $breachService->deleteAlert($alertId);
            if ($result['success']) {
                header('Location: breach-alerts.php?deleted=1');
                exit;
            } else {
                $msg = $result['error'] ?? 'Delete failed.';
                $msgType = 'danger';
            }
        } elseif ($action === 'scan_company' && $isAdmin) {
            if ($breachService->queueScan((int)$user['id'], 'company')) {
                $msg = 'Company scan queued. Breach research + company OSINT will run in the background.';
                $msgType = 'success';
            } else {
                $msg = 'A scan is already in progress. Please wait for it to complete.';
                $msgType = 'danger';
            }
        } elseif ($action === 'scan_vendors' && $isAdmin) {
            if ($breachService->queueScan((int)$user['id'], 'full')) {
                $msg = 'Full vendor scan queued. Breach research + all vendor OSINT will run in the background. This may take 20-30 minutes.';
                $msgType = 'success';
            } else {
                $msg = 'A scan is already in progress. Please wait for it to complete.';
                $msgType = 'danger';
            }
        }
    }
}

// Fetch CSRF token AFTER POST handling so it reflects any regenerated token
$csrfToken = $security->getCSRFToken();

if (isset($_GET['deleted'])) { $msg = 'Alert deleted.'; $msgType = 'success'; }
if (isset($_GET['scan_complete'])) { $msg = $_GET['scan_result'] ?? 'Scan completed.'; $msgType = 'success'; }

// Check if a scan is currently running (for progress banner + polling)
$scanStatus = $breachService->getScanStatus();
$scanRunning = ($scanStatus['status'] === 'scanning');

// Load data - always fetch ALL alerts for counting, then filter for display
$statusFilter = $_GET['status'] ?? '';
// Default to 'vendors' view unless explicitly set to 'all'
$vendorFilter = $_GET['vf'] ?? 'vendors';
$vendorsOnly = ($vendorFilter !== 'all');

$allAlerts = $breachService->getAlerts(null, 200, 0, false);
$vendorAlerts = array_filter($allAlerts, function($a) { return ((int)($a['vendor_count'] ?? 0)) > 0; });

// Count by status for the current vendor filter view
$alertCounts = ['new' => 0, 'acknowledged' => 0, 'investigating' => 0, 'resolved' => 0, 'false_positive' => 0, 'total' => 0];
$countSource = $vendorsOnly ? $vendorAlerts : $allAlerts;
foreach ($countSource as $a) {
    $s = $a['status'] ?? '';
    if (isset($alertCounts[$s])) $alertCounts[$s]++;
    $alertCounts['total']++;
}

// Apply filters for display
$displayAlerts = $vendorsOnly ? $vendorAlerts : $allAlerts;
$alerts = $statusFilter ? array_filter($displayAlerts, function($a) use ($statusFilter) { return $a['status'] === $statusFilter; }) : $displayAlerts;

// Severity colors
$severityColors = [
    'critical' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#dc2626', 'badge' => '#dc2626'],
    'high'     => ['bg' => '#fff7ed', 'text' => '#9a3412', 'border' => '#ea580c', 'badge' => '#ea580c'],
    'medium'   => ['bg' => '#fefce8', 'text' => '#854d0e', 'border' => '#ca8a04', 'badge' => '#ca8a04'],
    'low'      => ['bg' => '#f0fdf4', 'text' => '#166534', 'border' => '#16a34a', 'badge' => '#16a34a'],
];

$alertTypeLabels = [
    'data_breach'    => 'Data Breach',
    'cyber_attack'   => 'Cyber Attack',
    'vulnerability'  => 'Vulnerability',
    'supply_chain'   => 'Supply Chain',
    'ransomware'     => 'Ransomware',
    'osint_exposure' => 'OSINT Exposure',
    'other'          => 'Other',
];

$statusLabels = [
    'new'           => 'New',
    'acknowledged'  => 'Acknowledged',
    'investigating' => 'Investigating',
    'resolved'      => 'Resolved',
    'false_positive' => 'False Positive',
];

$pageKey = 'breach_alerts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Breach / Cyber Alerts - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color'] ?? '#1e3a5f'); ?>;
            --theme-header-font-color: <?php echo e($theme['header_font_color'] ?? '#ffffff'); ?>;
            --theme-button-color: <?php echo e($theme['button_color'] ?? '#2563eb'); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color'] ?? '#1e293b'); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color'] ?? '#1e293b'); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color'] ?? '#ffffff'); ?>;
            --sidebar-width: <?php echo e($theme['nav_width'] ?? '260'); ?>px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar {
            background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px;
            display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px;
            background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar {
            width: var(--sidebar-width); min-width: var(--sidebar-width); max-width: var(--sidebar-width);
            background: var(--nav-fill-color); padding: 0; flex-shrink: 0; display: flex; flex-direction: column;
        }
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
        .sidebar-section summary { list-style: none; cursor: pointer; user-select: none; }
        .sidebar-section summary::-webkit-details-marker { display: none; }
        .sidebar-module { border-top: 1px solid rgba(255,255,255,0.08); margin-top: 4px; }
        .sidebar-module:first-of-type { margin-top: 0; border-top: none; }
        .sidebar-module > summary.sidebar-module-header { display: flex; align-items: center; padding: 12px 20px 8px; cursor: pointer; list-style: none; list-style-type: none; user-select: none; }
        .sidebar-module > summary.sidebar-module-header::-webkit-details-marker { display: none; }
        .sidebar-module > summary.sidebar-module-header::marker { display: none; content: none; font-size: 0; }
        .sidebar-module > summary.sidebar-module-header::before,
        .sidebar-module > summary.sidebar-module-header::after { content: none; display: none; }
        .sidebar-module-header .module-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--theme-button-color, #ff6543); opacity: 1; transition: opacity 0.2s; }
        .sidebar-module-header:hover .module-label { opacity: 0.8; }
        .sidebar-module-body { padding-top: 0; padding-bottom: 4px; position: relative; }
        .sidebar-module-body::before { content: ""; display: block; height: 2px; background: var(--theme-button-color, #ff6543); margin: 0 20px 8px; opacity: 0.5; border-radius: 1px; }
        .sidebar-module .sidebar-nav li a { font-size: 12px; padding-top: 9px; padding-bottom: 9px; }
        .sidebar-module .sidebar-section { margin-bottom: 16px; }
        .sidebar-module .sidebar-section:first-child { margin-top: 4px; }
        .sidebar-module .sidebar-section-title { font-size: 9px; }
        .sidebar-nav li a .badge-danger { background: #dc3545; color: #fff; }
        .main-content { flex: 1; padding: 25px; overflow-y: auto; }
        .btn { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--theme-button-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-success { background: #059669; color: white; }
        .btn-info { background: #2563eb; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-xs { padding: 4px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; }
        .alert-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; overflow: hidden; }
        .alert-card-header { display: flex; align-items: center; gap: 12px; padding: 14px 20px; cursor: pointer; }
        .alert-card-header:hover { background: #f9fafb; }
        .alert-card-body { display: none; padding: 0 20px 16px; border-top: 1px solid #f3f4f6; }
        .alert-card-body.open { display: block; }
        .severity-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #fff; }
        .status-pill { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .entity-type-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; background: #e0e7ff; color: #3730a3; }
        .entity-type-badge.osint { background: #fce7f3; color: #9d174d; }
        .source-link { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 12px; color: #166534; text-decoration: none; margin: 2px; }
        .source-link:hover { background: #dcfce7; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; text-align: center; }
        .stat-card .stat-number { font-size: 28px; font-weight: 700; color: #333; }
        .stat-card .stat-label { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .filter-tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-tab { padding: 6px 14px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; color: #6b7280; text-decoration: none; background: #fff; }
        .filter-tab:hover { background: #f9fafb; color: #374151; }
        .filter-tab.active { background: var(--theme-header-color); color: #fff; border-color: var(--theme-header-color); }
        .vendor-impact { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #fef3c7; border-radius: 4px; font-size: 11px; color: #92400e; font-weight: 600; }
        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
        }

        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
<div class="page">
    <?php renderImpersonationBanner(); ?>
    <div class="top-bar">
        <span style="color: #666; margin-right: 15px;">Welcome, <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php">Admin</a><?php endif; ?>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    <div class="main-layout">
        <?php $currentPage = 'breach_alerts'; include __DIR__ . '/includes/sidebar_nav.php'; ?>
        <main class="main-content">

            <?php if ($msg): ?>
            <div style="padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; background: <?php echo $msgType === 'success' ? '#dcfce7' : '#fef2f2'; ?>; color: <?php echo $msgType === 'success' ? '#166534' : '#991b1b'; ?>; border: 1px solid <?php echo $msgType === 'success' ? '#bbf7d0' : '#fecaca'; ?>;">
                <?php echo e($msg); ?>
            </div>
            <?php endif; ?>

            <!-- Scan in progress banner -->
            <div id="scan-progress" style="display:<?php echo $scanRunning ? 'flex' : 'none'; ?>;align-items:center;gap:10px;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite;flex-shrink:0;"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg>
                <span id="scan-progress-text">Breach scan is running in the background. This page will update automatically when complete.</span>
            </div>
            <style nonce="<?php echo cspNonce(); ?>">@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <div>
                    <h1 style="font-size:24px;margin:0 0 4px;color:#333;font-weight:600;display:flex;align-items:center;gap:10px;">
                        <img src="app/icons/announcement-03.svg" alt="" width="24" height="24" style="opacity:0.7;">
                        Breach / Cyber Alerts
                    </h1>
                    <p style="color:#6b7280;margin:0;font-size:13px;">Breach and threat intelligence monitoring for your vendor supply chain</p>
                </div>
                <?php if ($isAdmin): ?>
                <div style="display:flex;gap:8px;" id="scan-form">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <button type="submit" name="action" value="scan_company" class="btn btn-primary scan-btn" <?php echo $scanRunning ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?> data-confirm="Run a company scan? This checks for recent threats and searches GitHub for exposed credentials related to your organization."><?php echo $scanRunning ? 'Scan Running...' : 'Scan Company'; ?></button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <button type="submit" name="action" value="scan_vendors" class="btn scan-btn" style="background:#6b7280;color:white;<?php echo $scanRunning ? 'opacity:0.5;cursor:not-allowed;' : ''; ?>" <?php echo $scanRunning ? 'disabled' : ''; ?> data-confirm="Run a full vendor scan? This checks for recent threats AND searches GitHub for exposed credentials across ALL vendor domains. This may take 20-30 minutes.">Scan All Vendors</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stats (clickable to filter) -->
            <?php $vfParam = $vendorsOnly ? '' : '&vf=all'; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:24px;">
                <a href="breach-alerts.php?status=new<?php echo $vfParam; ?>" class="stat-card" style="text-decoration:none;<?php echo $statusFilter === 'new' ? 'outline:2px solid #dc2626;' : ''; ?>"><div class="stat-number" style="color:#dc2626;"><?php echo (int)($alertCounts['new'] ?? 0); ?></div><div class="stat-label">New</div></a>
                <a href="breach-alerts.php?status=acknowledged<?php echo $vfParam; ?>" class="stat-card" style="text-decoration:none;<?php echo $statusFilter === 'acknowledged' ? 'outline:2px solid #2563eb;' : ''; ?>"><div class="stat-number" style="color:#2563eb;"><?php echo (int)($alertCounts['acknowledged'] ?? 0); ?></div><div class="stat-label">Acknowledged</div></a>
                <a href="breach-alerts.php?status=investigating<?php echo $vfParam; ?>" class="stat-card" style="text-decoration:none;<?php echo $statusFilter === 'investigating' ? 'outline:2px solid #ea580c;' : ''; ?>"><div class="stat-number" style="color:#ea580c;"><?php echo (int)($alertCounts['investigating'] ?? 0); ?></div><div class="stat-label">Investigating</div></a>
                <a href="breach-alerts.php?status=resolved<?php echo $vfParam; ?>" class="stat-card" style="text-decoration:none;<?php echo $statusFilter === 'resolved' ? 'outline:2px solid #16a34a;' : ''; ?>"><div class="stat-number" style="color:#16a34a;"><?php echo (int)($alertCounts['resolved'] ?? 0); ?></div><div class="stat-label">Resolved</div></a>
                <a href="breach-alerts.php?<?php echo $vendorsOnly ? '' : 'vf=all'; ?>" class="stat-card" style="text-decoration:none;<?php echo !$statusFilter ? 'outline:2px solid #333;' : ''; ?>"><div class="stat-number"><?php echo (int)($alertCounts['total'] ?? 0); ?></div><div class="stat-label">Total</div></a>
            </div>

            <!-- Vendor Filter Toggle + Status Filter Tabs -->
            <div style="display:flex;gap:12px;margin-bottom:20px;align-items:center;flex-wrap:wrap;">
                <div style="display:flex;gap:2px;background:#e5e7eb;border-radius:6px;padding:2px;">
                    <a href="breach-alerts.php<?php echo $statusFilter ? '?status=' . e($statusFilter) : ''; ?>" class="filter-tab" style="border:none;border-radius:4px;<?php echo $vendorsOnly ? 'background:var(--theme-header-color);color:#fff;font-weight:600;' : 'background:transparent;'; ?>">Your Vendors</a>
                    <a href="breach-alerts.php?vf=all<?php echo $statusFilter ? '&status=' . e($statusFilter) : ''; ?>" class="filter-tab" style="border:none;border-radius:4px;<?php echo !$vendorsOnly ? 'background:var(--theme-header-color);color:#fff;font-weight:600;' : 'background:transparent;'; ?>">All</a>
                </div>
                <div style="width:1px;height:24px;background:#d1d5db;"></div>
            <div class="filter-tabs" style="margin-bottom:0;">
                <a href="breach-alerts.php?<?php echo $vendorsOnly ? '' : 'vf=all'; ?>" class="filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">All (<?php echo (int)($alertCounts['total'] ?? 0); ?>)</a>
                <?php foreach (['new', 'acknowledged', 'investigating', 'resolved', 'false_positive'] as $sf): ?>
                <a href="breach-alerts.php?status=<?php echo $sf; ?><?php echo $vfParam; ?>" class="filter-tab <?php echo $statusFilter === $sf ? 'active' : ''; ?>"><?php echo $statusLabels[$sf]; ?> (<?php echo (int)($alertCounts[$sf] ?? 0); ?>)</a>
                <?php endforeach; ?>
            </div>
            </div>

            <!-- Alerts List -->
            <?php if (empty($alerts)): ?>
            <div style="text-align:center;padding:60px 20px;color:#6b7280;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <img src="app/icons/announcement-03.svg" alt="" width="48" height="48" style="opacity:0.3;margin-bottom:12px;">
                <p style="font-size:16px;margin:0 0 8px;">No alerts found.</p>
                <p style="font-size:13px;margin:0;">Breach alerts are generated daily by the automated monitoring system.</p>
            </div>
            <?php else: ?>
            <?php foreach ($alerts as $alert):
                $sc = $severityColors[$alert['severity']] ?? $severityColors['medium'];
                $sources = json_decode($alert['source_urls'] ?? '[]', true) ?: [];
                $vendorIds = json_decode($alert['affected_vendor_ids'] ?? '[]', true) ?: [];
            ?>
            <div class="alert-card" style="border-left: 4px solid <?php echo $sc['border']; ?>;">
                <div class="alert-card-header">
                    <span class="severity-badge" style="background:<?php echo $sc['badge']; ?>;"><?php echo strtoupper(e($alert['severity'])); ?></span>
                    <span style="flex:1;font-weight:600;color:#333;font-size:14px;"><?php echo e($alert['title']); ?></span>
                    <span class="entity-type-badge<?php echo ($alert['alert_type'] === 'osint_exposure') ? ' osint' : ''; ?>"><?php echo e($alertTypeLabels[$alert['alert_type']] ?? $alert['alert_type']); ?></span>
                    <?php if ($alert['vendor_count'] > 0): ?>
                    <span class="vendor-impact"><?php echo (int)$alert['vendor_count']; ?> vendor(s)</span>
                    <?php endif; ?>
                    <span class="status-pill" style="background:<?php echo $alert['status'] === 'new' ? '#fef2f2' : ($alert['status'] === 'resolved' ? '#dcfce7' : '#f3f4f6'); ?>;color:<?php echo $alert['status'] === 'new' ? '#991b1b' : ($alert['status'] === 'resolved' ? '#166534' : '#374151'); ?>;"><?php echo e($statusLabels[$alert['status']] ?? $alert['status']); ?></span>
                    <span style="font-size:12px;color:#9ca3af;"><?php echo date('M j, Y', strtotime($alert['created_at'])); ?></span>
                    <span class="toggle-arrow" style="color:#9ca3af;font-size:12px;">&#9660;</span>
                </div>
                <div class="alert-card-body">
                    <!-- Details Grid -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px 24px;margin-top:16px;padding:16px;background:#f9fafb;border-radius:8px;">
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Affected Entity</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo e($alert['affected_entity']); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Entity Type</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;text-transform:capitalize;"><?php echo e($alert['affected_entity_type']); ?></div>
                        </div>
                        <?php if ($alert['affected_technology']): ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Technology</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo e($alert['affected_technology']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Detected</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo date('M j, Y g:i A', strtotime($alert['created_at'])); ?></div>
                        </div>
                        <?php if ($alert['email_sent_at']): ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Email Sent</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo date('M j, Y g:i A', strtotime($alert['email_sent_at'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($alert['acknowledged_by_name'])): ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:4px;">Acknowledged By</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo e($alert['acknowledged_by_name']); ?><?php if ($alert['acknowledged_at']): ?> <span style="font-size:12px;color:#9ca3af;font-weight:400;"><?php echo date('M j, g:i A', strtotime($alert['acknowledged_at'])); ?></span><?php endif; ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($alert['investigating_by_name'])): ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#ea580c;font-weight:600;margin-bottom:4px;">Investigating By</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo e($alert['investigating_by_name']); ?><?php if ($alert['investigating_at']): ?> <span style="font-size:12px;color:#9ca3af;font-weight:400;"><?php echo date('M j, g:i A', strtotime($alert['investigating_at'])); ?></span><?php endif; ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($alert['resolved_by_name'])): ?>
                        <div>
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#16a34a;font-weight:600;margin-bottom:4px;">Resolved By</div>
                            <div style="font-size:14px;color:#111827;font-weight:500;"><?php echo e($alert['resolved_by_name']); ?><?php if ($alert['resolved_at']): ?> <span style="font-size:12px;color:#9ca3af;font-weight:400;"><?php echo date('M j, g:i A', strtotime($alert['resolved_at'])); ?></span><?php endif; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sources -->
                    <?php if (!empty($sources)): ?>
                    <div style="margin-top:16px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:8px;">Sources</div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach ($sources as $i => $url):
                                $domain = parse_url($url, PHP_URL_HOST);
                                $domain = $domain ? preg_replace('/^www\./', '', $domain) : 'Source ' . ($i + 1);
                            ?>
                            <a href="<?php echo e($url); ?>" target="_blank" rel="noopener noreferrer" class="source-link">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                <?php echo e($domain); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Summary -->
                    <div style="margin-top:16px;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:600;margin-bottom:8px;">Summary</div>
                        <p style="font-size:14px;color:#374151;line-height:1.7;margin:0;"><?php echo nl2br(e($alert['summary'])); ?></p>
                    </div>

                    <!-- AI Impact Analysis -->
                    <?php if ($alert['ai_analysis'] && $alert['ai_analysis'] !== $alert['summary']): ?>
                    <div style="margin-top:12px;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#1e40af;font-weight:600;margin-bottom:8px;">Impact Analysis</div>
                        <p style="margin:0;color:#1e3a5f;font-size:14px;line-height:1.7;"><?php echo nl2br(e($alert['ai_analysis'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Resolution Notes -->
                    <?php if ($alert['resolution_notes']): ?>
                    <div style="margin-top:12px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#166534;font-weight:600;margin-bottom:8px;">Resolution Notes</div>
                        <p style="margin:0;color:#14532d;font-size:14px;line-height:1.7;"><?php echo nl2br(e($alert['resolution_notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                    <div style="display:flex;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid #f3f4f6;flex-wrap:wrap;align-items:center;">
                        <?php if ($alert['status'] === 'new'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>"><input type="hidden" name="new_status" value="acknowledged"><button type="submit" name="action" value="update_status" class="btn btn-xs btn-info">Acknowledge</button></form>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>"><input type="hidden" name="new_status" value="false_positive"><button type="submit" name="action" value="update_status" class="btn btn-xs" style="background:#6b7280;color:#fff;" data-confirm="Mark this alert as a false positive?">False Positive</button></form>
                        <?php endif; ?>
                        <?php if ($alert['status'] === 'acknowledged'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>"><input type="hidden" name="new_status" value="investigating"><button type="submit" name="action" value="update_status" class="btn btn-xs btn-warning">Start Investigation</button></form>
                        <?php endif; ?>
                        <?php if ($alert['status'] === 'investigating'): ?>
                        <form method="POST" style="display:inline;" class="resolve-form"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>"><input type="hidden" name="new_status" value="resolved"><input type="hidden" name="resolution_notes" value=""><button type="submit" name="action" value="update_status" class="btn btn-xs btn-success">Resolve</button></form>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                        <form method="POST" style="display:inline;margin-left:auto;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>"><button type="submit" name="action" value="delete_alert" class="btn btn-xs btn-danger" data-confirm="Permanently delete this alert?">Delete</button></form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </main>
    </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme["footer_logo_url"])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme["footer_logo_url"]); ?>" alt="Footer Logo" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig("company_name", ""); if ($cn) echo e($cn) . " "; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date("Y"); ?></span>
                        <span>.&nbsp;</span>
                        <span>All Rights Reserved</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
<script nonce="<?php echo cspNonce(); ?>">
// Toggle alert card expand/collapse
document.querySelectorAll('.alert-card-header').forEach(function(header) {
    header.addEventListener('click', function() {
        this.nextElementSibling.classList.toggle('open');
        var arrow = this.querySelector('.toggle-arrow');
        if (arrow) arrow.textContent = this.nextElementSibling.classList.contains('open') ? '\u25B2' : '\u25BC';
    });
});
// Resolve form - prompt for notes
document.querySelectorAll('.resolve-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var n = prompt('Resolution notes:');
        if (!n) { e.preventDefault(); return; }
        form.querySelector('[name=resolution_notes]').value = n;
    });
});
// Confirm dialogs for data-confirm buttons
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
});

// Background scan polling
(function() {
    var scanning = <?php echo $scanRunning ? 'true' : 'false'; ?>;
    var pollTimer = null;
    var progressEl = document.getElementById('scan-progress');
    var progressText = document.getElementById('scan-progress-text');
    // scan buttons are now handled via .scan-btn class

    function pollScanStatus() {
        fetch('api-breach-scan-status.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'scanning') {
                    // Still running — show batch progress if available
                    if (data.result && progressText) {
                        progressText.textContent = data.result;
                    }
                    return;
                }
                // Scan finished
                clearInterval(pollTimer);
                scanning = false;

                if (progressEl) {
                    progressEl.style.background = '#dcfce7';
                    progressEl.style.color = '#166534';
                    progressEl.style.borderColor = '#bbf7d0';
                }
                if (progressText) {
                    progressText.textContent = data.result || 'Scan complete.';
                }

                // Re-enable button
                document.querySelectorAll('.scan-btn').forEach(function(btn) {
                    btn.disabled = false;
                    btn.style.opacity = '';
                    btn.style.cursor = '';
                });

                // Auto-refresh after brief delay so user sees the result message
                setTimeout(function() {
                    window.location.href = 'breach-alerts.php?scan_complete=1&scan_result=' + encodeURIComponent(data.result || 'Scan complete.');
                }, 2000);
            })
            .catch(function() {
                // Network error — keep trying
            });
    }

    if (scanning) {
        pollTimer = setInterval(pollScanStatus, 3000);
    }

    // Also start polling after form submit (in case page reloads with scan just queued)
    var scanFormDiv = document.getElementById('scan-form');
    if (scanFormDiv && !scanning) {
        scanFormDiv.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                setTimeout(function() {
                    scanning = true;
                    if (progressEl) progressEl.style.display = 'flex';
                    document.querySelectorAll('.scan-btn').forEach(function(btn) {
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        btn.style.cursor = 'not-allowed';
                    });
                    pollTimer = setInterval(pollScanStatus, 3000);
                }, 500);
            });
        });
    }
})();
</script>
</body>
</html>
