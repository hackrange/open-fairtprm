<?php
/**
 * Reports List - The Trophy Case
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This page shows all your saved FAIR analyses in a sortable, filterable,
 * paginated table. Admins can see everything (because they're nosy like that),
 * while regular users only see their own analyses. Features include:
 *
 * - Search by vendor name, status, risk level, or stakeholder
 * - Filter by status (completed/draft) and risk level (clickable bar chart!)
 * - Sort by pretty much any column including encrypted fields (decrypted first, obviously)
 * - Pagination with configurable page sizes (25/50/100/500)
 * - CSV export for when you need to paste stuff into Excel like a civilized person
 * - Risk distribution bar chart that doubles as a filter (fancy, right?)
 * - Delete analyses (with permission checks, we're not barbarians)
 *
 * The encrypted field handling is worth noting: FAIR output values (ALE, LEF, etc.)
 * are stored encrypted in the database. We decrypt them all first, then do sorting
 * and filtering in PHP. Not the most performant approach, but security > speed here.
 */

require_once 'includes/init.php';
requireAuth();

// The usual gang of helpers and singletons
$auth = Auth::getInstance();
$db = Database::getInstance();
$encryption = new Encryption();
$user = $auth->getUser();
$theme = getUserTheme();

// Handle delete request -- someone wants to nuke an analysis from orbit
$acl = ACL::getInstance();
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"]) && $acl->hasGroup("auditor")) {
    $_SESSION["error_message"] = t('reports.auditor_read_only');
    header("Location: reports.php");
    exit;
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = t('reports.invalid_request');
        header('Location: reports.php');
        exit;
    }
    $deleteId = intval($_POST['delete_id']);

    // Check if user owns this analysis or is admin
    $analysis = $db->fetchOne(
        "SELECT id, user_id FROM tprm_results WHERE id = :id",
        [':id' => $deleteId]
    );

    if ($analysis && ($auth->isAdmin() || $analysis['user_id'] == $user['id'])) {
        $db->delete('tprm_results', 'id = :id', [':id' => $deleteId]);
        $_SESSION['success_message'] = t('reports.deleted_success');
    } else {
        $_SESSION['error_message'] = t('reports.no_delete_permission');
    }

    // Redirect to avoid resubmission
    header('Location: reports.php');
    exit;
}

// Admins see everything, mortals see only their own work.
// The classic "WHERE 1=1" trick makes it easy to chain conditions later.
$whereClause = $auth->isAdmin() ? '1=1' : 'user_id = :user_id';
$params = $auth->isAdmin() ? [] : [':user_id' => $user['id']];

// Grab all the search/filter/sort/pagination params from the query string.
// There's a lot of state to track here but it all flows through GET params
// so the URLs are shareable. Bookmarkable reports -- you're welcome.
$searchQuery = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$riskFilter = $_GET['risk'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$perPage = in_array($perPage, [25, 50, 100, 500]) ? $perPage : 25;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Whitelist allowed sort columns
$allowedSortColumns = ['vendor_name', 'status', 'risk_output', 'security_score', 'iso_27001_certified', 'created_at', 'completed_at', 'ale', 'loss_event_frequency'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'created_at';
}

// Validate sort order
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Build WHERE clause with filters
$whereConditions = [$whereClause];
if (!empty($statusFilter)) {
    $whereConditions[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
$finalWhereClause = implode(' AND ', $whereConditions);

// Get only the most recent analysis per vendor (distinct by vendor_name)
$analyses = $db->fetchAll(
    "SELECT r.id, r.vendor_name, r.status, r.security_score, r.user_id,
            r.iso_27001_certified, r.risk_output, r.ale, r.loss_event_frequency,
            r.primary_loss_magnitude, r.secondary_loss_magnitude, r.recommended_liability,
            r.created_at, r.completed_at,
            MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name, creator.full_name)) as stakeholder_name,
            MAX(COALESCE(stakeholder_user.email, owner_user.email, creator.email)) as stakeholder_email
     FROM tprm_results r
     INNER JOIN (
         SELECT vendor_name, MAX(id) as max_id
         FROM tprm_results
         WHERE $finalWhereClause
         GROUP BY vendor_name
     ) AS latest ON r.id = latest.max_id
     LEFT JOIN vendor_onboarding_requests vor ON r.vendor_name = vor.vendor_name
     LEFT JOIN users creator ON r.user_id = creator.id
     LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
         ON vor.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
     LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
     LEFT JOIN vendor_onboarding_stakeholders vos_owner
         ON vor.id = vos_owner.request_id AND vos_owner.role = 'owner'
     LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
     GROUP BY r.id, r.vendor_name, r.status, r.security_score, r.user_id,
              r.iso_27001_certified, r.risk_output, r.ale, r.loss_event_frequency,
              r.primary_loss_magnitude, r.secondary_loss_magnitude, r.recommended_liability,
              r.created_at, r.completed_at
     ORDER BY r.created_at DESC",
    $params
);

// Decrypt FAIR output fields -- because we store these bad boys encrypted in the DB.
// This loop hits every record and decrypts the financial values so we can display
// and sort on them. Yes, we're decrypting potentially hundreds of records in a loop.
// No, there's not really a better way to do this with field-level encryption.
foreach ($analyses as &$analysis) {
    // risk_output is plain text, not encrypted - skip it
    if (!empty($analysis['ale'])) {
        $decrypted = $encryption->decrypt($analysis['ale']);
        $analysis['ale'] = $decrypted !== false ? $decrypted : '';
    }
    if (!empty($analysis['loss_event_frequency'])) {
        $decrypted = $encryption->decrypt($analysis['loss_event_frequency']);
        $analysis['loss_event_frequency'] = $decrypted !== false ? $decrypted : '';
    }
    if (!empty($analysis['primary_loss_magnitude'])) {
        $decrypted = $encryption->decrypt($analysis['primary_loss_magnitude']);
        $analysis['primary_loss_magnitude'] = $decrypted !== false ? $decrypted : '';
    }
    if (!empty($analysis['secondary_loss_magnitude'])) {
        $decrypted = $encryption->decrypt($analysis['secondary_loss_magnitude']);
        $analysis['secondary_loss_magnitude'] = $decrypted !== false ? $decrypted : '';
    }
    if (!empty($analysis['recommended_liability'])) {
        $decrypted = $encryption->decrypt($analysis['recommended_liability']);
        $analysis['recommended_liability'] = $decrypted !== false ? $decrypted : '';
    }
}
unset($analysis); // Break reference

// Apply search filter
if (!empty($searchQuery)) {
    $searchLower = strtolower($searchQuery);
    $analyses = array_filter($analyses, function($a) use ($searchLower) {
        return strpos(strtolower($a['vendor_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['status'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['risk_output'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['security_score'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['stakeholder_name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($a['stakeholder_email'] ?? ''), $searchLower) !== false;
    });
    $analyses = array_values($analyses); // Re-index
}

// Apply risk level filter
if (!empty($riskFilter)) {
    $analyses = array_filter($analyses, function($a) use ($riskFilter) {
        return $a['risk_output'] === $riskFilter;
    });
    $analyses = array_values($analyses); // Re-index
}

// Sort by encrypted fields if requested (after decryption)
if (in_array($sortBy, ['ale', 'loss_event_frequency'])) {
    usort($analyses, function($a, $b) use ($sortBy, $sortOrder) {
        $valA = (float)($a[$sortBy] ?? 0);
        $valB = (float)($b[$sortBy] ?? 0);
        $comparison = $valA <=> $valB;
        return $sortOrder === 'DESC' ? -$comparison : $comparison;
    });
}

// Pagination
$totalAnalyses = count($analyses);
$totalPages = max(1, ceil($totalAnalyses / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;
$paginatedAnalyses = array_slice($analyses, $offset, $perPage);

// Helper function to generate sort URLs
function getSortUrl($column, $currentSort, $currentOrder) {
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params['page'] = 1; // Reset to first page on sort
    return '?' . http_build_query($params);
}

// Helper to build pagination URL
function buildPageUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Helper to build risk filter URL
function buildRiskFilterUrl($riskLevel) {
    $params = $_GET;
    if (!empty($riskLevel)) {
        $params['risk'] = $riskLevel;
    } else {
        unset($params['risk']);
    }
    $params['page'] = 1; // Reset to first page
    return '?' . http_build_query($params);
}

// Helper function to get sort indicator
function getSortIndicator($column, $currentSort, $currentOrder) {
    if ($column === $currentSort) {
        return $currentOrder === 'ASC' ? ' &#9650;' : ' &#9660;';
    }
    return '';
}

// Calculate statistics (before pagination)
$statsTotal = count($analyses);
$completedAnalyses = count(array_filter($analyses, fn($a) => $a['status'] === 'completed'));
$draftAnalyses = count(array_filter($analyses, fn($a) => $a['status'] === 'draft'));

// Risk level distribution
$riskLevels = [
    'Very Low' => 0,
    'Low' => 0,
    'Medium' => 0,
    'High' => 0,
    'Very High' => 0,
    'Critical' => 0
];

foreach ($analyses as $analysis) {
    if (!empty($analysis['risk_output']) && isset($riskLevels[$analysis['risk_output']])) {
        $riskLevels[$analysis['risk_output']]++;
    }
}

// CSV export -- for when management wants the data in a spreadsheet because
// apparently a beautiful web dashboard just isn't good enough for them
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="tprm_analysis_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, [
        'Vendor Name',
        'Status',
        'Security Score',
        'ISO 27001 Certified',
        'Risk Level',
        'LEF (Loss Event Frequency)',
        'Primary Loss Magnitude',
        'Secondary Loss Magnitude',
        'ALE (Annual Loss Expectancy)',
        'Recommended Cyber Insurance',
        'Created Date',
        'Completed Date'
    ]);

    // CSV Data
    foreach ($analyses as $analysis) {
        fputcsv($output, [
            $analysis['vendor_name'],
            ucfirst($analysis['status']),
            $analysis['security_score'] ?? 'N/A',
            $analysis['iso_27001_certified'] ? 'Yes' : 'No',
            $analysis['risk_output'] ?? 'N/A',
            $analysis['loss_event_frequency'] ?? 'N/A',
            $analysis['primary_loss_magnitude'] ? '$' . number_format((float)$analysis['primary_loss_magnitude'], 2) : 'N/A',
            $analysis['secondary_loss_magnitude'] ? '$' . number_format((float)$analysis['secondary_loss_magnitude'], 2) : 'N/A',
            $analysis['ale'] ? '$' . number_format((float)$analysis['ale'], 2) : 'N/A',
            $analysis['recommended_liability'] ? '$' . number_format((float)$analysis['recommended_liability'], 2) : 'N/A',
            date('Y-m-d H:i', strtotime($analysis['created_at'])),
            $analysis['completed_at'] ? date('Y-m-d H:i', strtotime($analysis['completed_at'])) : 'N/A'
        ]);
    }

    fclose($output);
    exit;
}

$csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('reports.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <!-- style.css removed - causes layout conflicts with custom page styling -->
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
        body { margin: 0; }

        /* Full height page layout */
        .page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Hide default navbar decorations */
        .page-header {
            display: none !important;
        }

        /* Top Bar - User Menu Only */
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

        /* Main Layout */
        .main-layout {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
        }

        /* Sidebar - Customizable Navigation */
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

        /* Sidebar Brand/Logo */
        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand a {
            display: block;
        }
        .sidebar-brand img {
            max-width: 100%;
            height: auto;
        }
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

        .sidebar-section {
            margin-bottom: 25px;
        }

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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 35px 40px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }
        .page-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 18px;
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 600;
            color: var(--theme-header-color);
            margin-bottom: 5px;
        }
        .stat-label {
            color: #666;
            font-size: 13px;
        }
        .risk-distribution {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .risk-distribution h3 {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        .risk-bar {
            margin-bottom: 10px;
        }
        .risk-bar:last-child {
            margin-bottom: 0;
        }
        .risk-bar-clickable {
            cursor: pointer;
            transition: all 0.2s;
        }
        .risk-bar-clickable:hover {
            transform: translateX(3px);
        }
        .risk-bar-clickable:hover .risk-bar-progress {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .risk-bar-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: 13px;
        }
        .risk-bar-label a {
            color: inherit;
            text-decoration: none;
            display: flex;
            justify-content: space-between;
            width: 100%;
        }
        .risk-bar-label a:hover {
            color: var(--theme-header-color);
        }
        .risk-bar-progress {
            height: 20px;
            background: #f3f4f6;
            border-radius: 4px;
            overflow: hidden;
        }
        .risk-bar-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 500;
            border-bottom: 2px solid #e5e7eb;
            color: #374151;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        tr:hover {
            background: #f9fafb;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }
        th.sortable:hover {
            background: #e9ecef;
        }
        th.sortable a {
            color: #374151;
            text-decoration: none;
            display: block;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            border: none;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-success:hover {
            background: #059669;
        }
        .btn-delete {
            color: #dc3545;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
            padding: 0;
            transition: all 0.2s;
        }
        .btn-delete:hover {
            color: #a71d2a;
            text-decoration: underline;
        }

        /* Search and filter controls */
        .controls-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .search-box {
            flex: 1;
            min-width: 200px;
            max-width: 350px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
        }
        .search-box input:focus {
            outline: none;
            border-color: var(--theme-header-color);
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-group label {
            font-size: 13px;
            color: #666;
            white-space: nowrap;
            font-weight: 500;
        }
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            min-width: 120px;
        }
        .per-page-group {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .per-page-group label {
            font-size: 12px;
            color: #666;
        }
        .per-page-group select {
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
        }

        /* Pagination */
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 20px;
            border-top: 1px solid #e5e7eb;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 13px;
            color: #666;
        }
        .pagination {
            display: flex;
            gap: 4px;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .pagination li a, .pagination li span {
            display: inline-block;
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            font-size: 13px;
            transition: all 0.2s;
        }
        .pagination li a:hover {
            background: #f3f4f6;
            border-color: #ccc;
        }
        .pagination li.active span {
            background: var(--theme-header-color);
            color: white;
            border-color: var(--theme-header-color);
        }
        .pagination li.disabled span {
            color: #ccc;
            cursor: not-allowed;
        }
        .sortable-header {
            cursor: pointer;
            user-select: none;
        }
        .sortable-header a {
            color: white;
            text-decoration: none;
            display: block;
        }
        .sortable-header:hover {
            background: rgba(0,0,0,0.1);
        }

        /* Responsive - Tablet */
        @media (max-width: 992px) {
            .main-layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
            .main-content {
                padding: 25px 20px;
            }
        }

        /* Responsive - Mobile */
        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .main-content {
                padding: 20px 15px;
            }
        }

        /* Hide preloader */
        .preloader { display: none !important; }

        /* Footer styling */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
            overflow: visible;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar with User Menu -->
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($auth->isAdmin()): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <!-- Left Sidebar Navigation -->
            <?php $currentPage = 'reports'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content Area -->
            <main class="main-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <h1 class="page-title"><?php echo e(t('reports.heading')); ?></h1>
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <?php if ($auth->isAdmin() || hasGroup('cyber_tprm')): ?>
                        <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                            <input
                                type="text"
                                id="vendorSearchInput"
                                placeholder="<?php echo e(t('reports.search_vendors_placeholder')); ?>"
                                style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                                class="focus-ring"
                            >
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">🔍</span>
                            <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                        </div>
                        <?php endif; ?>
                        <a href="reports.php?export=csv" class="btn btn-success">📊 <?php echo e(t('reports.export_csv')); ?></a>
                    </div>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                <div style="padding: 12px 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 6px; margin-bottom: 20px;">
                    <?php echo e($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                <div style="padding: 12px 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px; margin-bottom: 20px;">
                    <?php echo e($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $statsTotal; ?></div>
                        <div class="stat-label"><?php echo e(t('reports.stat_total')); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $completedAnalyses; ?></div>
                        <div class="stat-label"><?php echo e(t('reports.stat_completed')); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $draftAnalyses; ?></div>
                        <div class="stat-label"><?php echo e(t('reports.stat_in_progress')); ?></div>
                    </div>
                </div>

                <!-- Risk Distribution -->
                <?php if ($completedAnalyses > 0): ?>
                <div class="risk-distribution">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3><?php echo e(t('reports.risk_distribution')); ?></h3>
                        <?php if (!empty($riskFilter)): ?>
                        <a href="<?php echo buildRiskFilterUrl(''); ?>" style="font-size: 12px; color: #666; text-decoration: none; padding: 4px 8px; border-radius: 4px; background: #f3f4f6;">
                            <?php echo e(t('reports.clear_filter')); ?> ✕
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php
                    $riskColors = [
                        'Very Low' => '#28a745',
                        'Low' => '#5cb85c',
                        'Medium' => '#ffc107',
                        'High' => '#ff9800',
                        'Very High' => '#ff5722',
                        'Critical' => '#dc3545'
                    ];

                    foreach ($riskLevels as $level => $count):
                        $percentage = $completedAnalyses > 0 ? ($count / $completedAnalyses) * 100 : 0;
                        $isActive = $riskFilter === $level;
                    ?>
                        <div class="risk-bar <?php echo $count > 0 ? 'risk-bar-clickable' : ''; ?>"
                             <?php if ($count > 0): ?>data-href="<?php echo buildRiskFilterUrl($level); ?>"<?php endif; ?>
                             style="<?php echo $isActive ? 'border-left: 4px solid ' . $riskColors[$level] . '; padding-left: 8px;' : ''; ?>">
                            <div class="risk-bar-label">
                                <a href="<?php echo $count > 0 ? buildRiskFilterUrl($level) : '#'; ?>"
                                   style="<?php echo $count === 0 ? 'pointer-events: none; opacity: 0.5;' : ''; ?>">
                                    <span><?php echo e($level); ?><?php echo $isActive ? ' (Filtered)' : ''; ?></span>
                                    <span><strong><?php echo $count; ?></strong> (<?php echo number_format($percentage, 1); ?>%)</span>
                                </a>
                            </div>
                            <div class="risk-bar-progress">
                                <div class="risk-bar-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $riskColors[$level]; ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Search, Status Filter, and Per-Page Controls -->
                <div class="controls-bar">
                    <div class="search-box">
                        <form method="GET" id="searchForm">
                            <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo e($statusFilter); ?>"><?php endif; ?>
                            <?php if ($riskFilter): ?><input type="hidden" name="risk" value="<?php echo e($riskFilter); ?>"><?php endif; ?>
                            <?php if ($sortBy !== 'created_at'): ?><input type="hidden" name="sort" value="<?php echo e($sortBy); ?>"><?php endif; ?>
                            <?php if ($sortOrder !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($sortOrder); ?>"><?php endif; ?>
                            <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?php echo $perPage; ?>"><?php endif; ?>
                            <input type="text" name="search" placeholder="<?php echo e(t('reports.search_placeholder')); ?>" value="<?php echo e($searchQuery); ?>">
                        </form>
                    </div>

                    <div class="filter-group">
                        <label><?php echo e(t('reports.status_label')); ?></label>
                        <select data-action="filterByStatus">
                            <option value=""><?php echo e(t('reports.all_status')); ?></option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>><?php echo e(t('reports.opt_completed')); ?></option>
                            <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>><?php echo e(t('reports.opt_draft')); ?></option>
                        </select>
                    </div>

                    <?php if (!empty($riskFilter)): ?>
                    <div class="filter-group" style="background: #fef3c7; padding: 6px 12px; border-radius: 6px;">
                        <span style="font-size: 13px; color: #92400e;">
                            <?php echo e(t('reports.risk_label')); ?> <strong><?php echo e($riskFilter); ?></strong>
                        </span>
                        <a href="<?php echo buildRiskFilterUrl(''); ?>" style="color: #92400e; text-decoration: none; margin-left: 8px;">✕</a>
                    </div>
                    <?php endif; ?>

                    <div class="per-page-group">
                        <label><?php echo e(t('reports.show_label')); ?></label>
                        <select data-action="changePerPage">
                            <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                            <option value="500" <?php echo $perPage == 500 ? 'selected' : ''; ?>>500</option>
                        </select>
                        <label><?php echo e(t('reports.per_page')); ?></label>
                    </div>
                </div>

                <!-- Analysis Table -->
                <?php if (!empty($paginatedAnalyses)): ?>
                <div class="card">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('vendor_name', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_vendor_name')); ?><?php echo getSortIndicator('vendor_name', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('status', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_status')); ?><?php echo getSortIndicator('status', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('risk_output', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_risk_level')); ?><?php echo getSortIndicator('risk_output', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('loss_event_frequency', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_lef')); ?><?php echo getSortIndicator('loss_event_frequency', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('ale', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_ale')); ?><?php echo getSortIndicator('ale', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('security_score', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_security_score')); ?><?php echo getSortIndicator('security_score', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('iso_27001_certified', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_iso_27001')); ?><?php echo getSortIndicator('iso_27001_certified', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th class="sortable">
                                    <a href="<?php echo getSortUrl('created_at', $sortBy, $sortOrder); ?>">
                                        <?php echo e(t('reports.col_created')); ?><?php echo getSortIndicator('created_at', $sortBy, $sortOrder); ?>
                                    </a>
                                </th>
                                <th><?php echo e(t('reports.col_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paginatedAnalyses as $analysis): ?>
                                <tr>
                                    <td><strong><?php echo e($analysis['vendor_name']); ?></strong></td>
                                    <td>
                                        <?php if ($analysis['status'] === 'completed'): ?>
                                            <span style="color: #28a745;">✓ <?php echo e(t('reports.status_completed')); ?></span>
                                        <?php else: ?>
                                            <span style="color: #ffc107;">⚠ <?php echo e(t('reports.status_draft')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($analysis['risk_output'])): ?>
                                            <span style="color: <?php echo $riskColors[$analysis['risk_output']] ?? '#666'; ?>; font-weight: bold;">
                                                <?php echo e($analysis['risk_output']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($analysis['loss_event_frequency'])): ?>
                                            <strong><?php echo e($analysis['loss_event_frequency']); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($analysis['ale'])): ?>
                                            <strong>$<?php echo number_format((float)$analysis['ale'], 2); ?></strong>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($analysis['security_score'])): ?>
                                            <?php
                                            $scoreColors = ['A' => '#28a745', 'B' => '#5cb85c', 'C' => '#ffc107', 'D' => '#ff9800', 'F' => '#dc3545'];
                                            $color = $scoreColors[$analysis['security_score']] ?? '#666';
                                            ?>
                                            <span style="color: <?php echo $color; ?>; font-weight: bold;"><?php echo e(t('reports.grade')); ?> <?php echo e($analysis['security_score']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($analysis['iso_27001_certified']): ?>
                                            <span style="color: #28a745;">✓ <?php echo e(t('reports.certified')); ?></span>
                                        <?php else: ?>
                                            <span style="color: #999;"><?php echo e(t('reports.no')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($analysis['created_at'])); ?></td>
                                    <td style="white-space: nowrap;">
                                        <a href="view-result.php?id=<?php echo $analysis['id']; ?>" style="color: #ff6543; text-decoration: none; font-weight: 500; margin-right: 12px;"><?php echo e(t('reports.view')); ?> →</a>
                                        <?php if ($auth->isAdmin() || $analysis['user_id'] == $user['id']): ?>
                                        <form method="POST" style="display: inline;" data-confirm="Are you sure you want to delete this analysis for <?php echo e($analysis['vendor_name']); ?>? This action cannot be undone.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="delete_id" value="<?php echo $analysis['id']; ?>">
                                            <button type="submit" class="btn-delete">🗑 <?php echo e(t('reports.delete')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1 || $totalAnalyses > 0): ?>
                    <div class="pagination-bar">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalAnalyses); ?> of <?php echo $totalAnalyses; ?> analyses
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <ul class="pagination">
                            <?php if ($currentPage > 1): ?>
                            <li><a href="<?php echo buildPageUrl(1); ?>">&laquo;</a></li>
                            <li><a href="<?php echo buildPageUrl($currentPage - 1); ?>">&lsaquo;</a></li>
                            <?php else: ?>
                            <li class="disabled"><span>&laquo;</span></li>
                            <li class="disabled"><span>&lsaquo;</span></li>
                            <?php endif; ?>

                            <?php
                            // Show page numbers
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);

                            if ($startPage > 1): ?>
                            <li><a href="<?php echo buildPageUrl(1); ?>">1</a></li>
                            <?php if ($startPage > 2): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="<?php echo $i === $currentPage ? 'active' : ''; ?>">
                                <?php if ($i === $currentPage): ?>
                                <span><?php echo $i; ?></span>
                                <?php else: ?>
                                <a href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                            <li><a href="<?php echo buildPageUrl($totalPages); ?>"><?php echo $totalPages; ?></a></li>
                            <?php endif; ?>

                            <?php if ($currentPage < $totalPages): ?>
                            <li><a href="<?php echo buildPageUrl($currentPage + 1); ?>">&rsaquo;</a></li>
                            <li><a href="<?php echo buildPageUrl($totalPages); ?>">&raquo;</a></li>
                            <?php else: ?>
                            <li class="disabled"><span>&rsaquo;</span></li>
                            <li class="disabled"><span>&raquo;</span></li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 8px;">
                    <h3 style="color: #666; margin-bottom: 15px;"><?php echo e(t('reports.no_analyses')); ?></h3>
                    <p style="color: #999; margin-bottom: 20px;"><?php echo e(t('reports.no_analyses_desc')); ?></p>
                    <a href="fair-analysis.php" class="btn btn-primary"><?php echo e(t('reports.create_analysis')); ?></a>
                </div>
                <?php endif; ?>

                <div style="margin-top: 30px;">
                    <a href="index.php" style="color: #ff6543; text-decoration: none;">&larr; <?php echo e(t('reports.back_to_dashboard')); ?></a>
                </div>
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
                        <span><?php echo e(t('reports.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Filter by status
        function filterByStatus(status) {
            const params = new URLSearchParams(window.location.search);
            if (status) {
                params.set('status', status);
            } else {
                params.delete('status');
            }
            params.delete('page'); // Reset to first page
            window.location.href = '?' + params.toString();
        }

        // Change per page
        function changePerPage(perPage) {
            const params = new URLSearchParams(window.location.search);
            params.set('per_page', perPage);
            params.delete('page'); // Reset to first page
            window.location.href = '?' + params.toString();
        }

    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
