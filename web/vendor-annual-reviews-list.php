<?php
/**
 * Vendor Annual Reviews List - The Nagging Dashboard
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is command central for keeping tabs on which vendors are due for their
 * annual checkup. Think of it as a dentist's appointment reminder system, but
 * for vendor risk management. It shows you who's overdue (red), who's coming
 * up soon (orange), and who's recently been taken care of (green). Stakeholders
 * only see their own vendors, while admins get the full buffet. Features filter
 * tabs, search, and a full sidebar navigation because this is a real page, not
 * some stripped-down form.
 */

// Boot up the app with error handling -- if init fails, at least tell us why
try {
    require_once 'includes/init.php';
    requireAuth();
} catch (Exception $e) {
    error_log("Initialization error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    die("An application error occurred. Please contact the administrator.");
}

// The usual singleton party
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// ============================================================================
// PERMISSION CHECKS
// Two tiers: read-all (admins see everything) and read-assigned (stakeholders
// only see their vendors). Also check if the user can actually submit reviews.
// ============================================================================
$canReadAll = $acl->hasPermission('annual_review.read');
$canReadAssigned = $acl->hasPermission('annual_review.read_assigned');
$canCreate = $acl->hasPermission('annual_review.create');

// Figure out what kind of user we're dealing with -- stakeholder-only gets
// a simplified sidebar, while admins get the full enchilada
$session = Session::getInstance();
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || $acl->hasGroup('administrator');
$isProcurement = $acl->hasGroup('procurement');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isStakeholderGroup = $acl->hasGroup('stakeholder');
$isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM;

// Navigation visibility flags -- controls what shows up in the sidebar
$showFairModule = (hasPermission('analysis.create') ||
                  hasPermission('analysis.read') ||
                  $isCyberTPRM ||
                  $isAdmin) && !$isStakeholderOnly;

$showSRSModule = ($isCyberTPRM || $isAdmin) && !$isStakeholderOnly;
$showOnboarding = $canReadAll || $canReadAssigned;

// If you can't read any reviews at all, you're in the wrong place
if (!$canReadAll && !$canReadAssigned) {
    http_response_code(403);
    die(e(t('vendor-annual-reviews-list.access_denied')));
}

// ============================================================================
// SUCCESS/ERROR MESSAGES FROM REDIRECTS
// These come from query params set by other pages (like the review form)
// ============================================================================
$success = '';
$error = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'reviewed':
            $success = t('vendor-annual-reviews-list.success_reviewed');
            break;
    }
}
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'not_found':
            $error = t('vendor-annual-reviews-list.error_not_found');
            break;
        case 'permission_denied':
            $error = t('vendor-annual-reviews-list.error_permission');
            break;
    }
}

// Filter parameters -- default to showing overdue first because those need attention
$statusFilter = isset($_GET['filter']) && in_array($_GET['filter'], ['overdue', 'due_soon', 'all', 'completed'], true) ? $_GET['filter'] : 'overdue';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// ============================================================================
// DUE DATE CALCULATOR
// Figures out when a vendor's next annual review is due.
// Checks last review + 365 days first, then falls back to approval date + 365.
// Why 365 and not "one year"? Because leap years are someone else's problem.
// ============================================================================
function calculateReviewDueDate($vendor) {
    if ($vendor['status'] !== 'approved') return null;

    // Last review + 365 days is the most common case
    if (!empty($vendor['last_annual_review'])) {
        return date('Y-m-d', strtotime($vendor['last_annual_review'] . ' +365 days'));
    }

    // No reviews yet -- use the approval/submission date
    $approvalDate = $vendor['submitted_at'] ?? $vendor['created_at'];
    if (empty($approvalDate)) return null;

    return date('Y-m-d', strtotime($approvalDate . ' +365 days'));
}

// ============================================================================
// REVIEW STATUS CALCULATOR
// Takes a vendor and returns a status object with label, CSS class, and days.
// Three states: overdue (you're in trouble), due soon (tick tock), or OK (chill).
// ============================================================================
function getReviewStatus($vendor) {
    // Use the stored due date if available, otherwise calculate it
    $dueDate = !empty($vendor['last_annual_review_due'])
        ? $vendor['last_annual_review_due']
        : calculateReviewDueDate($vendor);

    if (empty($dueDate)) {
        return ['label' => 'N/A', 'class' => 'status-na', 'days' => 0, 'due_date' => null];
    }

    $now = strtotime(date('Y-m-d'));
    $due = strtotime($dueDate);
    $daysDiff = floor(($due - $now) / 86400);

    if ($daysDiff < 0) {
        // Overdue -- time to send angry emails
        $daysOverdue = abs($daysDiff);
        return [
            'label' => $daysOverdue . ' days overdue',
            'class' => 'status-overdue',
            'days' => $daysDiff,
            'due_date' => $dueDate
        ];
    } elseif ($daysDiff <= 30) {
        // Due soon -- gentle nudge territory
        return [
            'label' => 'Due in ' . $daysDiff . ' days',
            'class' => 'status-due-soon',
            'days' => $daysDiff,
            'due_date' => $dueDate
        ];
    } else {
        // All good -- nothing to see here
        return [
            'label' => 'Due ' . date('M j, Y', strtotime($dueDate)),
            'class' => 'status-ok',
            'days' => $daysDiff,
            'due_date' => $dueDate
        ];
    }
}

// ============================================================================
// BUILD THE MAIN QUERY
// Pull all approved vendors with their review info. If user is stakeholder-only,
// add a WHERE clause to filter to just their assigned vendors.
// ============================================================================
$params = [];
$whereConditions = ['r.status = :approved_status'];
$params[':approved_status'] = 'approved';

if ($canReadAll) {
    // Admins see everything -- no additional filters needed
} elseif ($canReadAssigned) {
    // Stakeholders only see their vendors -- check the junction table
    $whereConditions[] = "EXISTS (
        SELECT 1 FROM vendor_onboarding_stakeholders s
        WHERE s.request_id = r.id AND s.user_id = :user_id
    )";
    $params[':user_id'] = $user['id'];
}

// Search filter -- matches vendor name or type
if (!empty($searchQuery)) {
    $whereConditions[] = "(r.vendor_name LIKE :search OR r.vendor_type LIKE :search2)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// The big query -- pulls vendor info, creator info, last review date, and stakeholder names
// Uses subqueries because JOINs would give us duplicate rows for multi-stakeholder vendors
$query = "
    SELECT r.*,
           u.full_name as created_by_name,
           u.email as created_by_email,
           (SELECT MAX(review_date) FROM vendor_annual_reviews WHERE vendor_request_id = r.id) as last_review_date,
           (SELECT GROUP_CONCAT(CONCAT(us.full_name, ' (', us.email, ')') SEPARATOR ', ')
            FROM vendor_onboarding_stakeholders s
            LEFT JOIN users us ON s.user_id = us.id
            WHERE s.request_id = r.id) as stakeholders
    FROM vendor_onboarding_requests r
    LEFT JOIN users u ON r.created_by = u.id
    {$whereClause}
    ORDER BY r.last_annual_review_due ASC, r.updated_at DESC
";

$allRequests = $db->fetchAll($query, $params);

// ============================================================================
// CALCULATE STATUSES AND APPLY FILTERS
// Loop through all vendors, calculate their review status, count them up
// for the filter badges, and then apply the selected filter.
// ============================================================================
$requests = [];
$dueSoonCount = 0;
$overdueCount = 0;
$completedRecentCount = 0;

foreach ($allRequests as $request) {
    $status = getReviewStatus($request);
    $request['review_status'] = $status;

    // Count for the filter tab badges
    if ($status['days'] < 0) {
        $overdueCount++;
    } elseif ($status['days'] <= 30) {
        $dueSoonCount++;
    }

    // Check if this vendor was reviewed in the last 30 days
    if (!empty($request['last_review_date'])) {
        $reviewDate = strtotime($request['last_review_date']);
        $thirtyDaysAgo = strtotime('-30 days');
        if ($reviewDate >= $thirtyDaysAgo) {
            $completedRecentCount++;
        }
    }

    // Apply the selected status filter -- each tab shows different vendors
    switch ($statusFilter) {
        case 'overdue':
            if ($status['days'] < 0) {
                $requests[] = $request;
            }
            break;
        case 'due_soon':
            if ($status['days'] >= 0 && $status['days'] <= 30) {
                $requests[] = $request;
            }
            break;
        case 'all':
            // "All Action Needed" = overdue + due soon combined
            if ($status['days'] <= 30) {
                $requests[] = $request;
            }
            break;
        case 'completed':
            // Recently completed = reviewed in last 30 days
            if (!empty($request['last_review_date'])) {
                $reviewDate = strtotime($request['last_review_date']);
                $thirtyDaysAgo = strtotime('-30 days');
                if ($reviewDate >= $thirtyDaysAgo) {
                    $requests[] = $request;
                }
            }
            break;
    }
}

$totalActionableCount = $dueSoonCount + $overdueCount;

// ============================================================================
// HELPER: EXTRACT STAKEHOLDER NAMES
// The stakeholders column comes back as "Name (email), Name (email)".
// This helper strips out the emails for a cleaner display in the table.
// ============================================================================
function getStakeholderNames($stakeholdersString) {
    if (empty($stakeholdersString)) return 'None assigned';

    $names = [];
    $parts = explode(', ', $stakeholdersString);
    foreach ($parts as $part) {
        if (preg_match('/^(.+?)\s*\(/', $part, $matches)) {
            $names[] = $matches[1];
        }
    }
    return !empty($names) ? implode(', ', $names) : $stakeholdersString;
}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Annual Vendor Reviews - TPRM</title>
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
        /* Theme CSS variables -- dynamically set from database */
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

        /* Full height page layout -- sidebar + content flexbox */
        .page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Kill the default page header -- we roll our own */
        .page-header { display: none !important; }

        /* Top Bar -- just the user menu, nothing fancy */
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

        /* Main Layout -- sidebar on the left, content on the right */
        .main-layout {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
        }

        /* Sidebar -- the navigation column with theme-colored background */
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

        /* Main content area -- where the actual review table lives */
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
        .page-subtitle { font-size: 14px; color: #666; margin-top: 5px; }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }

        /* Filter bar with tabs and search -- the control panel */
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .filter-tab {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            color: #666;
            background: #f0f0f0;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        .filter-tab:hover { background: #e0e0e0; }
        .filter-tab.active {
            background: var(--theme-header-color);
            color: white;
            border-color: var(--theme-header-color);
        }
        .filter-tab .count {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(0,0,0,0.1);
            margin-left: 5px;
            font-size: 12px;
        }
        .filter-tab.active .count { background: rgba(255,255,255,0.3); }

        .search-bar {
            display: flex;
            gap: 10px;
        }
        .search-bar input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .search-bar button {
            padding: 10px 25px;
            background: var(--theme-button-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .search-bar button:hover { filter: brightness(1.1); }

        /* Reviews table -- the main data display */
        .reviews-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .reviews-table th, .reviews-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .reviews-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
        }
        .reviews-table tr:hover { background: #fafafa; }
        .reviews-table tr:last-child td { border-bottom: none; }

        .vendor-name {
            font-weight: 500;
            color: #333;
            font-size: 15px;
        }
        .vendor-type {
            font-size: 13px;
            color: #666;
            margin-top: 3px;
        }

        /* Status badges -- traffic light colors for review urgency */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .status-overdue { background: #dc3545; color: #fff; }
        .status-due-soon { background: #ff9800; color: #fff; }
        .status-ok { background: #28a745; color: #fff; }
        .status-na { background: #6c757d; color: #fff; }

        .meta-info { font-size: 13px; color: #888; }

        .action-links a {
            color: var(--theme-button-color);
            text-decoration: none;
            margin-right: 15px;
            font-size: 14px;
            font-weight: 500;
        }
        .action-links a:hover { text-decoration: underline; }

        /* Empty state -- when no vendors match the current filter */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .empty-state h3 { color: #666; margin-bottom: 10px; font-size: 18px; font-weight: 600; }
        .empty-state p { color: #999; margin-bottom: 20px; }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 14px;
        }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        /* Footer -- themed to match the rest of the app */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
        .footer-modern .brand img { max-height: 45px; }

        /* Kill the preloader -- we don't need no stinking loading animation */
        .preloader { display: none !important; }

        /* Responsive -- Tablet: sidebar goes horizontal, content stacks below */
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

        /* Responsive -- Mobile: everything goes single-column */
        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .top-bar > span { margin-right: 0 !important; }
            .user-menu {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }
            .sidebar-brand {
                flex-direction: column;
                text-align: center;
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
            .reviews-table { display: block; overflow-x: auto; }
            .filter-bar { flex-direction: column; }
            .search-bar { flex-direction: column; }
            .search-bar input { width: 100%; }
            .page-header-row { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar -- welcome message and quick links -->
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
            <?php $currentPage = 'annual_reviews'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- =============================================================
                 MAIN CONTENT AREA
                 The actual review list with filter tabs, search, and table.
                 Filter tabs show counts so you know what needs attention.
                 ============================================================= -->
            <main class="main-content">
                <div class="page-header-row">
                    <div>
                        <h1 class="page-title"><?php echo e(t('vendor-annual-reviews-list.page_title')); ?></h1>
                        <p class="page-subtitle"><?php echo e(t('vendor-annual-reviews-list.page_subtitle')); ?></p>
                    </div>
                </div>

                <?php // Success/error banners from redirects ?>
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <!-- Filter tabs and search bar -- the command center -->
                <div class="filter-bar">
                    <div class="filter-tabs">
                        <a href="?filter=overdue" class="filter-tab <?php echo $statusFilter === 'overdue' ? 'active' : ''; ?>">
                            <?php echo e(t('vendor-annual-reviews-list.filter_overdue')); ?>
                            <span class="count"><?php echo $overdueCount; ?></span>
                        </a>
                        <a href="?filter=due_soon" class="filter-tab <?php echo $statusFilter === 'due_soon' ? 'active' : ''; ?>">
                            <?php echo e(t('vendor-annual-reviews-list.filter_due_soon')); ?>
                            <span class="count"><?php echo $dueSoonCount; ?></span>
                        </a>
                        <a href="?filter=all" class="filter-tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                            <?php echo e(t('vendor-annual-reviews-list.filter_all')); ?>
                            <span class="count"><?php echo $totalActionableCount; ?></span>
                        </a>
                        <a href="?filter=completed" class="filter-tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">
                            <?php echo e(t('vendor-annual-reviews-list.filter_completed')); ?>
                            <span class="count"><?php echo $completedRecentCount; ?></span>
                        </a>
                    </div>

                    <form method="GET" class="search-bar">
                        <input type="hidden" name="filter" value="<?php echo e($statusFilter); ?>">
                        <input type="text" name="search" placeholder="<?php echo e(t('vendor-annual-reviews-list.search_placeholder')); ?>"
                               value="<?php echo e($searchQuery); ?>">
                        <button type="submit"><?php echo e(t('vendor-annual-reviews-list.search')); ?></button>
                    </form>
                </div>

                <?php if (empty($requests)): ?>
                    <!-- Empty state -- different messages for each filter tab -->
                    <div class="empty-state">
                        <h3><?php echo e(t('vendor-annual-reviews-list.empty_title')); ?></h3>
                        <p>
                            <?php if ($statusFilter === 'overdue'): ?>
                                <?php echo e(t('vendor-annual-reviews-list.empty_overdue')); ?>
                            <?php elseif ($statusFilter === 'due_soon'): ?>
                                <?php echo e(t('vendor-annual-reviews-list.empty_due_soon')); ?>
                            <?php elseif ($statusFilter === 'completed'): ?>
                                <?php echo e(t('vendor-annual-reviews-list.empty_completed')); ?>
                            <?php else: ?>
                                <?php echo e(t('vendor-annual-reviews-list.empty_all')); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- The review table -- vendor name, stakeholders, last review, status, actions -->
                    <table class="reviews-table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('vendor-annual-reviews-list.th_vendor_name')); ?></th>
                                <th><?php echo e(t('vendor-annual-reviews-list.th_stakeholders')); ?></th>
                                <th><?php echo e(t('vendor-annual-reviews-list.th_last_review')); ?></th>
                                <th><?php echo e(t('vendor-annual-reviews-list.th_review_status')); ?></th>
                                <th><?php echo e(t('vendor-annual-reviews-list.th_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td>
                                        <div class="vendor-name">
                                            <?php echo e($request['vendor_name']); ?>
                                        </div>
                                        <div class="vendor-type">
                                            <?php echo e($request['vendor_type'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="meta-info">
                                            <?php echo e(getStakeholderNames($request['stakeholders'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="meta-info">
                                            <?php if (!empty($request['last_review_date'])): ?>
                                                <?php echo date('M j, Y', strtotime($request['last_review_date'])); ?>
                                            <?php elseif (!empty($request['submitted_at'])): ?>
                                                <?php echo e(t('vendor-annual-reviews-list.approved_date', date('M j, Y', strtotime($request['submitted_at'])))); ?>
                                            <?php else: ?>
                                                <?php echo e(t('vendor-annual-reviews-list.never_reviewed')); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $request['review_status']['class']; ?>">
                                            <?php echo e($request['review_status']['label']); ?>
                                        </span>
                                    </td>
                                    <td class="action-links">
                                        <?php if ($canCreate): ?>
                                            <a href="vendor-annual-review.php?id=<?php echo $request['id']; ?>"><?php echo e(t('vendor-annual-reviews-list.complete_review')); ?></a>
                                        <?php endif; ?>
                                        <?php if (!$isStakeholderOnly): ?>
                                        <a href="vendor-annual-review-history.php?id=<?php echo $request['id']; ?>"><?php echo e(t('vendor-annual-reviews-list.view_history')); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </main>
        </div>

        <!-- Footer -- copyright and logo -->
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
                        <span><?php echo e(t('vendor-annual-reviews-list.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
