<?php
/**
 * Vendor Domains - Subdomain Security Scores
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Shows all subdomains/domains discovered via UpGuard's /vendor/domains endpoint.
 * Search by vendor name or domain to view the full attack surface for any
 * monitored vendor.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die('Access denied. You do not have permission to view vendor domain data.');
}

require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();

// Check if feature is enabled
if (!$srsService->isVendorDomainsEnabled()) {
    http_response_code(403);
    die('Vendor Domains feature is not enabled. An administrator can enable it in Admin &gt; SRS &gt; UpGuard settings.');
}

$scoringConfig = $srsService->getScoringConfig();
$displayName = ($scoringConfig['display_name'] ?? 'UpGuard') . ' Vendor Domains';
$upguardDisplayMode = $scoringConfig['display_mode'] ?? 'raw';
$upguardMaxScore = (int)($scoringConfig['max_score'] ?? 950);

$csrfToken = $security->generateCSRFToken();

// Search and pagination
require_once __DIR__ . '/includes/classes/Pagination.php';

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'active';

$validSortColumns = ['subdomain', 'score', 'score_grade', 'is_active', 'last_scanned', 'vendor_name', 'parent_domain'];
$pgParams = Pagination::getParams([
    'per_page' => 25,
    'sort_column' => 'score',
    'sort_dir' => 'ASC',
    'valid_sort_columns' => $validSortColumns,
]);
$sortColumn = $pgParams['sort_column'];
$sortOrder = $pgParams['sort_dir'];
$perPage = $pgParams['per_page'];
$paginationPage = $pgParams['page'];

// Fetch data
$allRisks = $srsService->getAllVendorSubdomains(!empty($searchQuery) ? $searchQuery : null);

// Filter by status
if ($statusFilter === 'active') {
    $allRisks = array_values(array_filter($allRisks, fn($r) => !empty($r['is_active'])));
} elseif ($statusFilter === 'inactive') {
    $allRisks = array_values(array_filter($allRisks, fn($r) => empty($r['is_active'])));
}

// Sort in PHP
usort($allRisks, function($a, $b) use ($sortColumn, $sortOrder) {
    $aVal = $a[$sortColumn] ?? '';
    $bVal = $b[$sortColumn] ?? '';

    if ($sortColumn === 'last_scanned') {
        $aVal = $aVal ? strtotime($aVal) : 0;
        $bVal = $bVal ? strtotime($bVal) : 0;
    } elseif (in_array($sortColumn, ['score', 'is_active'])) {
        $aVal = intval($aVal);
        $bVal = intval($bVal);
    } else {
        $aVal = strtolower($aVal);
        $bVal = strtolower($bVal);
    }

    if ($sortOrder === 'ASC') {
        return $aVal <=> $bVal;
    }
    return $bVal <=> $aVal;
});

// Stats
$totalDomains = count($allRisks);
$activeDomains = count(array_filter($allRisks, fn($r) => $r['is_active']));
$avgScore = $totalDomains > 0 ? round(array_sum(array_column($allRisks, 'score')) / $totalDomains) : 0;
$uniqueVendors = count(array_unique(array_column($allRisks, 'vendor_onboarding_id')));

// Pagination
$pg = Pagination::paginate($totalDomains, $perPage, $paginationPage);
$offset = $pg['offset'];
$paginatedRisks = array_slice($allRisks, $offset, $perPage);

function displayScore4p(int $rawScore, string $mode, int $maxScore): string {
    if ($mode === 'percentage') {
        $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
        return $pct . '%';
    }
    return (string)$rawScore;
}

// Using Pagination class for sort URLs and indicators

function getScoreColor4p(?int $score): string {
    if ($score === null) return '#6b7280';
    if ($score >= 850) return '#059669';
    if ($score >= 700) return '#10b981';
    if ($score >= 500) return '#f59e0b';
    if ($score >= 300) return '#f97316';
    return '#ef4444';
}

function getGradeBadgeColor4p(?string $grade): string {
    return match($grade) {
        'A' => '#059669',
        'B' => '#10b981',
        'C' => '#f59e0b',
        'D' => '#f97316',
        'F' => '#ef4444',
        default => '#6b7280'
    };
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vendor-domains-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Vendor Name', 'Parent Domain', 'Subdomain', 'Score', 'Grade', 'Status', 'Last Scanned']);
    foreach ($allRisks as $r) {
        fputcsv($out, [
            $r['vendor_name'] ?? '',
            $r['parent_domain'] ?? $r['vendor_domain'],
            $r['subdomain'],
            $r['score'] ?? '',
            $r['score_grade'] ?? '',
            $r['is_active'] ? 'Active' : 'Inactive',
            $r['last_scanned'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e($displayName); ?> - TPRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
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
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }

        .page { display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .user-menu .btn-configure {
            background: var(--theme-button-color);
            color: white !important;
        }
        .user-menu .btn-configure:hover { filter: brightness(1.1); }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            max-width: var(--sidebar-width);
            background: var(--nav-fill-color);
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
            color: var(--nav-font-color);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 0 20px;
            margin-bottom: 10px;
            opacity: 0.6;
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '\25BC';
            font-size: 8px;
            opacity: 0.5;
            transition: transform 0.2s ease;
            margin-right: 2px;
        }
        .sidebar-section:not([open]) .sidebar-section-title::after {
            transform: rotate(-90deg);
        }
        .sidebar-nav {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: var(--nav-font-color);
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1);
        }
        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15);
            font-weight: 500;
            border-left: 3px solid var(--theme-button-color);
        }
        .sidebar-nav li a .icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }

        /* Stat cards */
        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }

        /* Search bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-bar input[type="text"] {
            flex: 1;
            min-width: 250px;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-bar input[type="text"]:focus {
            border-color: var(--theme-button-color);
            box-shadow: 0 0 0 3px rgba(255,101,67,0.1);
        }
        .search-bar .btn-search {
            padding: 10px 20px;
            background: var(--theme-button-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        .search-bar .btn-search:hover { filter: brightness(1.1); }
        .search-bar .btn-clear {
            padding: 10px 16px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        .data-table th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        .data-table th a {
            color: #6b7280;
            text-decoration: none;
        }
        .data-table th a:hover { color: #374151; }
        .data-table td {
            padding: 12px 16px;
            font-size: 14px;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }
        .data-table tr:hover { background: #f9fafb; }
        .data-table .domain-cell {
            font-family: 'Roboto Mono', monospace;
            font-size: 13px;
        }

        /* Badges */
        .grade-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        /* Pagination */
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 13px;
            color: #6b7280;
        }
        .pagination {
            display: flex;
            list-style: none;
            gap: 4px;
            padding: 0;
            margin: 0;
        }
        .pagination li a, .pagination li span {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            color: #374151;
        }
        .pagination li a:hover { background: #f3f4f6; }
        .pagination li.active span {
            background: var(--theme-button-color);
            color: white;
            border-color: var(--theme-button-color);
        }
        .pagination li.disabled span {
            color: #d1d5db;
            cursor: not-allowed;
        }

        /* Footer */
        .footer-modern {
            background: var(--theme-footer-color, #1f2937);
            color: rgba(255,255,255,0.7);
            padding: 20px 30px;
            font-size: 13px;
        }
        .footer-modern-body {
            max-width: 1400px;
            margin: 0 auto;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 15px; }
        .empty-state h3 { color: #374151; margin-bottom: 8px; }

        /* Per-page selector */
        .per-page-group { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select {
            padding: 6px 10px; border: 1px solid #ddd;
            border-radius: 4px; font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top-bar">
            <div class="user-menu">
                <span style="font-size: 13px; color: #666;">
                    <?php echo e($user['full_name'] ?? $user['email']); ?>
                </span>
                <?php if ($isAdmin): ?>
                <a href="admin.php?section=srs" class="btn-configure"><?php echo e(t('vendor-domains.configure')); ?></a>
                <?php endif; ?>
                <a href="index.php"><?php echo e(t('vendor-domains.dashboard')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php $currentPage = 'vendor_domains'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <!-- Page Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h1 class="page-title" style="margin: 0 0 5px 0;"><?php echo e($displayName); ?></h1>
                        <p style="margin: 0; color: #6b7280; font-size: 14px;"><?php echo e(t('vendor-domains.subtitle')); ?></p>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="?export=csv<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>&status=<?php echo urlencode($statusFilter); ?>" class="btn-clear" style="background: #6b7280; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px;"><?php echo e(t('vendor-domains.export_csv')); ?></a>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div class="stat-cards">
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('vendor-domains.stat_total_domains')); ?></div>
                        <div class="stat-value"><?php echo number_format($totalDomains); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('vendor-domains.stat_active_domains')); ?></div>
                        <div class="stat-value" style="color: #059669;"><?php echo number_format($activeDomains); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('vendor-domains.stat_avg_score')); ?></div>
                        <div class="stat-value" style="color: <?php echo getScoreColor4p($avgScore); ?>;"><?php echo displayScore4p($avgScore, $upguardDisplayMode, $upguardMaxScore); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('vendor-domains.stat_vendors')); ?></div>
                        <div class="stat-value"><?php echo number_format($uniqueVendors); ?></div>
                    </div>
                </div>

                <!-- Search -->
                <form method="GET" class="search-bar">
                    <input type="text" name="search" placeholder="<?php echo e(t('vendor-domains.search_placeholder')); ?>" value="<?php echo e($searchQuery); ?>" autofocus>
                    <select name="status" style="padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: white;">
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo e(t('vendor-domains.status_active')); ?></option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo e(t('vendor-domains.status_inactive')); ?></option>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>><?php echo e(t('vendor-domains.status_all')); ?></option>
                    </select>
                    <button type="submit" class="btn-search"><?php echo e(t('vendor-domains.btn_search')); ?></button>
                    <?php if (!empty($searchQuery) || $statusFilter !== 'active'): ?>
                    <a href="vendor-domains.php" class="btn-clear"><?php echo e(t('vendor-domains.btn_clear')); ?></a>
                    <?php endif; ?>
                </form>

                <?php if (!empty($searchQuery)): ?>
                <div style="margin-bottom: 15px; font-size: 14px; color: #6b7280;">
                    Showing <?php echo number_format($totalDomains); ?> result<?php echo $totalDomains !== 1 ? 's' : ''; ?> for &ldquo;<strong><?php echo e($searchQuery); ?></strong>&rdquo;
                </div>
                <?php endif; ?>

                <?php if ($totalDomains === 0): ?>
                <div class="empty-state">
                    <div class="icon"><img src="app/icons/globe-01.svg" alt="" width="32" height="32"></div>
                    <?php if (!empty($searchQuery)): ?>
                    <h3><?php echo e(t('vendor-domains.no_results_found')); ?></h3>
                    <p><?php echo e(t('vendor-domains.no_results_hint')); ?></p>
                    <?php else: ?>
                    <h3><?php echo e(t('vendor-domains.no_domain_data')); ?></h3>
                    <p><?php echo e(t('vendor-domains.no_domain_data_hint')); ?></p>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <!-- Data Table -->
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><a href="<?php echo Pagination::buildSortUrl('vendor_name', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_vendor')); ?><?php echo Pagination::getSortIndicator('vendor_name', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('parent_domain', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_parent_domain')); ?><?php echo Pagination::getSortIndicator('parent_domain', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('subdomain', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_subdomain')); ?><?php echo Pagination::getSortIndicator('subdomain', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('score', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_score')); ?><?php echo Pagination::getSortIndicator('score', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('score_grade', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_grade')); ?><?php echo Pagination::getSortIndicator('score_grade', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('is_active', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_status')); ?><?php echo Pagination::getSortIndicator('is_active', $sortColumn, $sortOrder); ?></a></th>
                                <th><a href="<?php echo Pagination::buildSortUrl('last_scanned', $sortColumn, $sortOrder); ?>"><?php echo e(t('vendor-domains.col_last_scanned')); ?><?php echo Pagination::getSortIndicator('last_scanned', $sortColumn, $sortOrder); ?></a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paginatedRisks as $risk): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($risk['vendor_id'])): ?>
                                    <a href="vendor-srs-details.php?id=<?php echo (int)$risk['vendor_id']; ?>" style="color: var(--theme-button-color); text-decoration: none; font-weight: 500;"><?php echo e($risk['vendor_name'] ?? 'Unknown'); ?></a>
                                    <?php else: ?>
                                    <?php echo e($risk['vendor_name'] ?? 'Unknown'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="domain-cell"><?php echo e($risk['parent_domain'] ?? $risk['vendor_domain']); ?></td>
                                <td class="domain-cell"><?php echo e($risk['subdomain']); ?></td>
                                <td>
                                    <?php if ($risk['score'] !== null): ?>
                                    <span style="font-weight: 600; color: <?php echo getScoreColor4p((int)$risk['score']); ?>;">
                                        <?php echo displayScore4p((int)$risk['score'], $upguardDisplayMode, $upguardMaxScore); ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="color: #9ca3af;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($risk['score_grade'])): ?>
                                    <span class="grade-badge" style="background: <?php echo getGradeBadgeColor4p($risk['score_grade']); ?>;">
                                        <?php echo e($risk['score_grade']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="color: #9ca3af;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($risk['is_active']): ?>
                                    <span class="status-badge status-active"><?php echo e(t('vendor-domains.badge_active')); ?></span>
                                    <?php else: ?>
                                    <span class="status-badge status-inactive"><?php echo e(t('vendor-domains.badge_inactive')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 13px; color: #6b7280;">
                                    <?php echo $risk['last_scanned'] ? date('M j, Y', strtotime($risk['last_scanned'])) : '—'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php Pagination::renderControls($pg, 'domains'); ?>
                <?php endif; ?>
            </main>
        </div>

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
                        <span><?php echo e(t('vendor-domains.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        document.addEventListener('change', function(e) {
            if (e.target.matches('[data-action="changePerPage"]')) {
                const params = new URLSearchParams(window.location.search);
                params.set('per_page', e.target.value);
                params.delete('page');
                window.location.href = '?' + params.toString();
            }
        });
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
