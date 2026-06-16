<?php
/**
 * Procurement Contracts - Contract Management & Expiration Inbox
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * A dedicated page for procurement, admin, and cyber TPRM users to manage
 * all contract-type documents across vendors. Features an expiration inbox
 * at the top highlighting contracts that are expiring soon or already expired,
 * plus a full sortable/filterable table of all contracts below.
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

if (!$isAdmin && !$isProcurement && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(e(t('procurement-contracts.access_denied')));
}

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

// Fetch all contract documents with vendor info
$allContracts = $db->fetchAll("
    SELECT vd.id, vd.file_uuid, vd.document_type, vd.contract_name, vd.contract_type,
           vd.contract_creation_date, vd.contract_expiration_date, vd.is_active,
           vd.original_filename, vd.mime_type, vd.file_size, vd.created_at,
           vd.contract_pricing,
           r.vendor_name, r.id as vendor_request_id,
           u.full_name as uploaded_by_name
    FROM vendor_documents vd
    JOIN vendor_onboarding_requests r ON vd.vendor_request_id = r.id
    LEFT JOIN users u ON vd.uploaded_by = u.id
    WHERE vd.document_type = 'contract'
    ORDER BY vd.contract_expiration_date ASC
");

// Separate expiring/expired contracts (active, within 90 days or already expired)
$expiringContracts = [];
$today = new DateTime();
foreach ($allContracts as $contract) {
    if ($contract['is_active'] && !empty($contract['contract_expiration_date'])) {
        $expDate = new DateTime($contract['contract_expiration_date']);
        $diff = $today->diff($expDate);
        $daysUntil = (int) $diff->days;
        if ($expDate < $today) {
            $daysUntil = -$daysUntil; // negative = expired
        }

        $ninetyDaysFromNow = (clone $today)->modify('+90 days');
        if ($expDate <= $ninetyDaysFromNow) {
            $contract['days_until_expiry'] = $daysUntil;
            $expiringContracts[] = $contract;
        }
    }
}

// Dashboard data for sidebar badge
$dashboardData = new DashboardData($db);
$expiringContractsCount = $dashboardData->getExpiringContractsCount();

// Expiring contract count for sidebar (from vendor_documents directly)
$expiringContractCasesCount = 0;
try {
    $expiringContractCasesCount = intval($db->fetchOne(
        "SELECT COUNT(*) as c FROM vendor_documents
         WHERE document_type = 'contract' AND is_active = 1
           AND contract_expiration_date IS NOT NULL
           AND contract_expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
    )['c'] ?? 0);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Procurement Contracts - TPRM</title>
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

        /* Expiring Contracts Inbox */
        .inbox-section {
            margin-bottom: 30px;
        }
        .inbox-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .inbox-title .count-badge {
            background: #dc3545;
            color: white;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 500;
        }
        .inbox-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 15px;
        }
        .inbox-card {
            background: white;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #ccc;
            transition: transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .inbox-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .inbox-card.expired { border-left-color: #dc3545; }
        .inbox-card.urgent { border-left-color: #ff9800; }
        .inbox-card.warning { border-left-color: #ffc107; }

        .inbox-card .card-vendor {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            margin-bottom: 4px;
        }
        .inbox-card .card-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }
        .inbox-card .card-meta {
            font-size: 12px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .inbox-card .expiry-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .expiry-badge.expired { background: #f8d7da; color: #721c24; }
        .expiry-badge.urgent { background: #fff3cd; color: #856404; }
        .expiry-badge.warning { background: #fff8e1; color: #f57f17; }

        /* Filter pills */
        .filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }
        .filter-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            color: #666;
            background: #f0f0f0;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-pill:hover { background: #e0e0e0; }
        .filter-pill.active {
            background: var(--theme-header-color);
            color: white;
        }

        .search-box {
            margin-left: auto;
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 13px;
            min-width: 220px;
        }
        .search-box:focus {
            outline: none;
            border-color: var(--theme-header-color);
        }

        /* Contracts table */
        .contracts-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .contracts-table th, .contracts-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .contracts-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        .contracts-table tr:hover { background: #fafafa; }
        .contracts-table tr:last-child td { border-bottom: none; }
        .contracts-table tr.hidden { display: none; }

        .vendor-link {
            color: var(--theme-header-color);
            text-decoration: none;
            font-weight: 500;
        }
        .vendor-link:hover { text-decoration: underline; }

        .status-active {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #d4edda;
            color: #155724;
        }
        .status-inactive {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #e2e3e5;
            color: #383d41;
        }

        .type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: #e8eaf6;
            color: #3949ab;
        }

        .action-btn {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            text-decoration: none;
            color: var(--theme-header-color);
            border: 1px solid var(--theme-header-color);
            transition: all 0.2s;
            margin-right: 5px;
        }
        .action-btn:hover {
            background: var(--theme-header-color);
            color: white;
        }

        .meta-info { font-size: 12px; color: #888; }

        .contract-pricing-summary {
            margin-top: 6px;
            padding: 6px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            font-size: 11px;
        }
        .contract-pricing-summary .pricing-row {
            color: #64748b;
            margin-bottom: 3px;
        }
        .contract-pricing-summary .pricing-row:last-child {
            margin-bottom: 0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
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
            .contracts-table { display: block; overflow-x: auto; }
            .filter-bar { flex-direction: column; }
            .search-box { margin-left: 0; width: 100%; }
            .inbox-cards { grid-template-columns: 1fr; }
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
            <?php $currentPage = 'contracts'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="page-header-row">
                    <h1 class="page-title"><?php echo e(t('procurement-contracts.page_title')); ?></h1>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input
                            type="text"
                            id="vendorSearchInput"
                            placeholder="<?php echo e(t('procurement-contracts.search_vendors_placeholder')); ?>"
                            class="focus-ring"
                            style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                            data-link-base="vendor-onboarding.php"
                        >
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128269;</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                </div>

                <!-- Expiring Contracts Inbox -->
                <?php if (!empty($expiringContracts)): ?>
                <div class="inbox-section">
                    <div class="inbox-title">
                        <?php echo e(t('procurement-contracts.expiring_inbox')); ?>
                        <span class="count-badge"><?php echo count($expiringContracts); ?></span>
                    </div>
                    <div class="inbox-cards">
                        <?php foreach ($expiringContracts as $ec):
                            $days = $ec['days_until_expiry'];
                            if ($days < 0) {
                                $urgencyClass = 'expired';
                                $badgeText = abs($days) . 'd overdue';
                            } elseif ($days <= 30) {
                                $urgencyClass = 'urgent';
                                $badgeText = $days . 'd remaining';
                            } else {
                                $urgencyClass = 'warning';
                                $badgeText = $days . 'd remaining';
                            }
                        ?>
                        <a href="vendor-onboarding.php?id=<?php echo (int)$ec['vendor_request_id']; ?>" class="inbox-card <?php echo $urgencyClass; ?>">
                            <div class="card-vendor"><?php echo e($ec['vendor_name']); ?></div>
                            <div class="card-title"><?php echo e($ec['contract_name'] ?: $ec['original_filename']); ?></div>
                            <div class="card-meta">
                                <span><?php echo e($ec['contract_type'] ?: t('procurement-contracts.contract')); ?></span>
                                <span class="expiry-badge <?php echo $urgencyClass; ?>"><?php echo $badgeText; ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Pills and Search -->
                <div class="filter-bar">
                    <button class="filter-pill active" data-filter="all"><?php echo e(t('procurement-contracts.filter_all')); ?></button>
                    <button class="filter-pill" data-filter="type-dpa">DPA</button>
                    <button class="filter-pill" data-filter="type-msa">MSA</button>
                    <button class="filter-pill" data-filter="type-privacy"><?php echo e(t('procurement-contracts.filter_privacy')); ?></button>
                    <button class="filter-pill" data-filter="type-order_form"><?php echo e(t('procurement-contracts.filter_order_form')); ?></button>
                    <button class="filter-pill" data-filter="type-po">PO</button>
                    <button class="filter-pill" data-filter="status-active"><?php echo e(t('procurement-contracts.filter_active')); ?></button>
                    <button class="filter-pill" data-filter="status-inactive"><?php echo e(t('procurement-contracts.filter_inactive')); ?></button>
                    <button class="filter-pill" data-filter="expiring"><?php echo e(t('procurement-contracts.filter_expiring')); ?></button>
                    <input type="text" class="search-box" id="contractSearch" placeholder="<?php echo e(t('procurement-contracts.search_placeholder')); ?>">
                </div>

                <!-- All Contracts Table -->
                <?php if (empty($allContracts)): ?>
                <div class="empty-state">
                    <h3><?php echo e(t('procurement-contracts.no_contracts')); ?></h3>
                    <p><?php echo e(t('procurement-contracts.no_contracts_desc')); ?></p>
                </div>
                <?php else: ?>
                <table class="contracts-table" id="contractsTable">
                    <thead>
                        <tr>
                            <th><?php echo e(t('procurement-contracts.th_vendor')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_contract_name')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_type')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_created')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_expires')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_status')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_uploaded_by')); ?></th>
                            <th><?php echo e(t('procurement-contracts.th_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allContracts as $contract):
                            $contractType = strtolower(str_replace(' ', '_', $contract['contract_type'] ?? ''));
                            $isActive = $contract['is_active'] ? 1 : 0;
                            $isExpiring = false;
                            if ($isActive && !empty($contract['contract_expiration_date'])) {
                                $expDate = new DateTime($contract['contract_expiration_date']);
                                $ninetyDays = (clone $today)->modify('+90 days');
                                $isExpiring = ($expDate <= $ninetyDays);
                            }
                        ?>
                        <tr data-type="<?php echo e($contractType); ?>"
                            data-status="<?php echo $isActive ? 'active' : 'inactive'; ?>"
                            data-expiring="<?php echo $isExpiring ? '1' : '0'; ?>"
                            data-search="<?php echo e(strtolower(($contract['vendor_name'] ?? '') . ' ' . ($contract['contract_name'] ?? '') . ' ' . ($contract['original_filename'] ?? ''))); ?>">
                            <td>
                                <a href="vendor-onboarding.php?id=<?php echo (int)$contract['vendor_request_id']; ?>" class="vendor-link">
                                    <?php echo e($contract['vendor_name'] ?: t('procurement-contracts.unknown_vendor')); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo e($contract['contract_name'] ?: $contract['original_filename']); ?>
                                <div class="meta-info"><?php echo e($contract['original_filename']); ?></div>
                                <?php
                                // Contract Pricing Summary (mirrors vendor-onboarding.php)
                                if (!empty($contract['contract_pricing'])):
                                    $pricing = json_decode($contract['contract_pricing'], true);
                                    if (is_array($pricing)):
                                        $annualFee = floatval($pricing['annualFee'] ?? 0);
                                        $unitPrice = floatval($pricing['unitPrice'] ?? 0);
                                        $includedUnits = intval($pricing['includedUnits'] ?? 0);
                                        $oneTimeFees = floatval($pricing['oneTimeFees'] ?? 0);
                                        $priceIncreaseCap = floatval($pricing['priceIncreaseCap'] ?? 0);
                                        $renewalUplift = ($pricing['renewalUplift'] ?? '') === 'Y';
                                        $unitTotal = $unitPrice * $includedUnits;

                                        if ($annualFee > 0) {
                                            $recurringAnnual = $annualFee;
                                        } elseif ($unitTotal > 0) {
                                            $recurringAnnual = $unitTotal;
                                        } else {
                                            $recurringAnnual = 0;
                                        }

                                        $termYears = 1;
                                        if (!empty($contract['contract_creation_date']) && !empty($contract['contract_expiration_date'])) {
                                            $dtStart = new DateTime($contract['contract_creation_date']);
                                            $dtEnd = new DateTime($contract['contract_expiration_date']);
                                            $diffDays = max(1, $dtStart->diff($dtEnd)->days);
                                            $termYears = round($diffDays / 365.25, 1);
                                            if ($termYears < 0.5) $termYears = 1;
                                        }

                                        $currentTermCost = ($recurringAnnual * $termYears) + $oneTimeFees;
                                        $forecastPerYear = $priceIncreaseCap > 0 ? $recurringAnnual * (1 + $priceIncreaseCap / 100) : $recurringAnnual;
                                        $forecastTerm = $forecastPerYear * $termYears;
                                        $hasCostData = ($annualFee > 0 || $unitTotal > 0 || $oneTimeFees > 0);
                                ?>
                                <?php if ($hasCostData): ?>
                                <div class="contract-pricing-summary">
                                    <?php if (!empty($pricing['contractId'])): ?>
                                    <div class="pricing-row">Contract ID: <span style="color: #334155; font-weight: 500;"><?php echo e($pricing['contractId']); ?></span>
                                        <?php
                                            // Only render the link when it is an http(s) URL. e() blocks attribute
                                            // breakout but NOT the scheme, so a stored `javascript:` value would be a
                                            // clickable stored-XSS vector for the reviewer. Allow-list the scheme,
                                            // matching the pattern used for theme logo URLs in init.php.
                                            $sorLink = (string)($pricing['systemOfRecordLink'] ?? '');
                                            $sorScheme = $sorLink !== '' ? strtolower((string)parse_url($sorLink, PHP_URL_SCHEME)) : '';
                                        ?>
                                        <?php if (in_array($sorScheme, ['http', 'https'], true)): ?>
                                            &middot; <a href="<?php echo e($sorLink); ?>" target="_blank" rel="noopener" style="color: #2563eb; text-decoration: none;"><?php echo e(t('procurement-contracts.system_of_record')); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="pricing-row">
                                        <?php if (!empty($pricing['billingFrequency'])): ?>
                                            <?php echo e($pricing['billingFrequency']); ?> billing
                                        <?php endif; ?>
                                        <?php if ($annualFee > 0): ?>
                                            &middot; Annual: $<?php echo number_format($annualFee, 2); ?>
                                        <?php endif; ?>
                                        <?php if ($oneTimeFees > 0): ?>
                                            &middot; One-time: $<?php echo number_format($oneTimeFees, 2); ?>
                                        <?php endif; ?>
                                        <?php if ($termYears > 1): ?>
                                            &middot; <?php echo rtrim(rtrim(number_format($termYears, 1), '0'), '.'); ?>-year term
                                        <?php endif; ?>
                                    </div>
                                    <?php $overagePrice = floatval($pricing['overagePrice'] ?? 0); ?>
                                    <?php if ($unitPrice > 0 || $includedUnits > 0 || $overagePrice > 0): ?>
                                    <div class="pricing-row">
                                        <?php if ($unitPrice > 0): ?>
                                            Price/Unit: <span style="color: #334155; font-weight: 600;">$<?php echo number_format($unitPrice, 2); ?></span>
                                        <?php endif; ?>
                                        <?php if ($includedUnits > 0): ?>
                                            <?php echo $unitPrice > 0 ? '&middot;' : ''; ?> Units: <span style="color: #334155; font-weight: 600;"><?php echo number_format($includedUnits); ?></span>
                                        <?php endif; ?>
                                        <?php if ($unitPrice > 0 && $includedUnits > 0): ?>
                                            &middot; Unit Total: <span style="color: #334155; font-weight: 600;">$<?php echo number_format($unitTotal, 2); ?></span>
                                        <?php endif; ?>
                                        <?php if ($overagePrice > 0): ?>
                                            &middot; Overage: <span style="color: #334155; font-weight: 600;">$<?php echo number_format($overagePrice, 2); ?>/unit</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div style="font-weight: 600; color: #1e293b;">
                                        Current Term: $<?php echo number_format($currentTermCost, 2); ?>
                                        <span style="font-weight: 400; color: #64748b;">($<?php echo number_format($recurringAnnual, 2); ?>/yr)</span>
                                    </div>
                                    <?php if ($priceIncreaseCap > 0): ?>
                                    <div style="font-weight: 600; color: <?php echo ($forecastPerYear > $recurringAnnual) ? '#d97706' : '#16a34a'; ?>; margin-top: 2px;">
                                        Forecast Renewal: $<?php echo number_format($forecastTerm, 2); ?>
                                        <span style="font-weight: 400; font-size: 10px;">($<?php echo number_format($forecastPerYear, 2); ?>/yr, +<?php echo rtrim(rtrim(number_format($priceIncreaseCap, 2), '0'), '.'); ?>% cap)</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($pricing['renewalType'])): ?>
                                    <div style="color: #64748b; margin-top: 3px;">
                                        <?php echo e($pricing['renewalType']); ?>
                                        <?php if (!empty($pricing['renewalNoticeWindow'])): ?>
                                            &middot; Notice: <?php echo e($pricing['renewalNoticeWindow']); ?>
                                        <?php endif; ?>
                                        <?php if ($renewalUplift): ?>
                                            &middot; <span style="color: #d97706;">Uplift clause</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php
                                    endif;
                                endif;
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($contract['contract_type'])): ?>
                                <span class="type-badge"><?php echo e($contract['contract_type']); ?></span>
                                <?php else: ?>
                                <span class="meta-info">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo !empty($contract['contract_creation_date'])
                                    ? date('M j, Y', strtotime($contract['contract_creation_date']))
                                    : '<span class="meta-info">-</span>'; ?>
                            </td>
                            <td>
                                <?php if (!empty($contract['contract_expiration_date'])): ?>
                                    <?php
                                    $expDate = new DateTime($contract['contract_expiration_date']);
                                    $isExpired = $expDate < $today;
                                    ?>
                                    <span style="<?php echo $isExpired && $contract['is_active'] ? 'color: #dc3545; font-weight: 600;' : ''; ?>">
                                        <?php echo date('M j, Y', strtotime($contract['contract_expiration_date'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="meta-info"><?php echo e(t('procurement-contracts.no_expiration')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($contract['is_active']): ?>
                                <span class="status-active"><?php echo e(t('procurement-contracts.status_active')); ?></span>
                                <?php else: ?>
                                <span class="status-inactive"><?php echo e(t('procurement-contracts.status_inactive')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="meta-info">
                                <?php echo e($contract['uploaded_by_name'] ?: t('procurement-contracts.unknown')); ?>
                                <div><?php echo date('M j, Y', strtotime($contract['created_at'])); ?></div>
                            </td>
                            <td>
                                <?php
                                $viewableMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
                                if (in_array($contract['mime_type'], $viewableMimes)):
                                ?>
                                <a href="api/vendor-document-download.php?uuid=<?php echo e($contract['file_uuid']); ?>&view=1" target="_blank" class="action-btn" title="<?php echo e(t('procurement-contracts.view_document')); ?>"><?php echo e(t('procurement-contracts.view')); ?></a>
                                <?php endif; ?>
                                <a href="api/vendor-document-download.php?uuid=<?php echo e($contract['file_uuid']); ?>&download=1" class="action-btn" title="<?php echo e(t('procurement-contracts.download_document')); ?>"><?php echo e(t('procurement-contracts.download')); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span>All Rights Reserved</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var pills = document.querySelectorAll('.filter-pill');
        var rows = document.querySelectorAll('#contractsTable tbody tr');
        var searchBox = document.getElementById('contractSearch');
        var activeFilter = 'all';

        function applyFilters() {
            var searchTerm = (searchBox ? searchBox.value : '').toLowerCase();

            rows.forEach(function(row) {
                var type = row.getAttribute('data-type');
                var status = row.getAttribute('data-status');
                var expiring = row.getAttribute('data-expiring');
                var searchData = row.getAttribute('data-search');

                var matchesFilter = true;
                if (activeFilter === 'all') {
                    matchesFilter = true;
                } else if (activeFilter.indexOf('type-') === 0) {
                    var filterType = activeFilter.replace('type-', '');
                    matchesFilter = (type === filterType);
                } else if (activeFilter.indexOf('status-') === 0) {
                    var filterStatus = activeFilter.replace('status-', '');
                    matchesFilter = (status === filterStatus);
                } else if (activeFilter === 'expiring') {
                    matchesFilter = (expiring === '1');
                }

                var matchesSearch = !searchTerm || searchData.indexOf(searchTerm) !== -1;

                row.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
            });
        }

        pills.forEach(function(pill) {
            pill.addEventListener('click', function() {
                pills.forEach(function(p) { p.classList.remove('active'); });
                pill.classList.add('active');
                activeFilter = pill.getAttribute('data-filter');
                applyFilters();
            });
        });

        if (searchBox) {
            searchBox.addEventListener('input', applyFilters);
        }

        // Auto-activate filter from URL query parameter (e.g. ?filter=expiring)
        var urlFilter = new URLSearchParams(window.location.search).get('filter');
        if (urlFilter) {
            var target = document.querySelector('.filter-pill[data-filter="' + urlFilter + '"]');
            if (target) {
                pills.forEach(function(p) { p.classList.remove('active'); });
                target.classList.add('active');
                activeFilter = urlFilter;
                applyFilters();
            }
        }
    })();
    </script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
