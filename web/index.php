<?php
/**
 * TPRM Main Dashboard - Mission Control
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the main dashboard -- the command center, the cockpit, the place
 * where all the magic happens after you log in. It figures out who you are
 * (admin? procurement? cyber team? just some stakeholder?), then builds a
 * custom dashboard with the modules you're allowed to see. Think of it like
 * a restaurant menu, but instead of appetizers and entrees, you get FAIR
 * analyses, vendor onboarding, and SRS scoring. Bon appetit.
 *
 * The page also pulls in a bunch of stats: SRS scores, cyber todo items,
 * expiring certs, vendors needing reviews, and a partridge in a pear tree.
 * It's basically a one-stop-shop for "things you should probably worry about."
 */

require_once 'includes/init.php';
requireAuth(); // No ticket, no ride

// Gather our army of singletons and helpers
$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();

// ============================================================================
// PERMISSION CHECKS -- Who are you and what are you allowed to see?
// This is basically the bouncer logic for every section on the dashboard.
// ============================================================================
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');
$isAuditor = hasGroup('auditor');
$isStakeholderGroup = hasGroup('stakeholder');

// Stakeholder-only means they ONLY have the stakeholder hat on -- not also an admin or cyber guru.
// These folks get a simplified dashboard because they don't need to see the full buffet.
$isStakeholderOnly = $isStakeholderGroup && !$isAdmin && !$isProcurement && !$isCyberTPRM && !$isAuditor;
$isProcurementOnly = $isProcurement && !$isAdmin && !$isCyberTPRM;

// Figure out which modules to show based on permissions. It's like a feature flag system
// but built on ACL groups because we're fancy like that.
$showOnboarding = hasPermission('onboarding.create') ||
                  hasPermission('onboarding.read') ||
                  hasPermission('onboarding.read_own') ||
                  hasPermission('onboarding.read_assigned') ||
                  hasGroup('stakeholder') || hasGroup('procurement');

$showFairModule = (hasPermission('analysis.create') ||
                  hasPermission('analysis.read') ||
                  $isCyberTPRM ||
                  $isAdmin) && !$isStakeholderOnly && !$isProcurementOnly && !$isAuditor;

$showSRSModule = ($isCyberTPRM || $isAdmin || $isAuditor) && !$isStakeholderOnly;

// ============================================================================
// DATA ASSEMBLY -- Let the DashboardData service do the heavy lifting.
// All those inline queries have been promoted to a proper class where they
// can be tested, reused, and generally not clutter up this file.
// ============================================================================
$db = Database::getInstance();
$dashboardData = new DashboardData($db);

// SRS Stats -- security rating numbers for the dashboard cards
$srsStats = ['vendors_with_scores' => 0, 'avg_score' => 0, 'needs_rescore' => 0];
$srsService = null;
$showVendorDomains = false;
$vendorDomainsDisplayName = '';
if ($showSRSModule) {
    try {
        require_once __DIR__ . '/includes/classes/SRSService.php';
        $srsService = new SRSService();
        if ($srsService->isAvailable()) {
            $srsStats = $dashboardData->getSRSStats($srsService);
        }
        // Check if Vendor Domains nav item should be shown
        if ($srsService->isVendorDomainsEnabled()) {
            $showVendorDomains = true;
            $srsConfig = $srsService->getScoringConfig();
            $vendorDomainsDisplayName = ($srsConfig['display_name'] ?? 'UpGuard') . ' Vendor Domains';
        }
    } catch (Exception $e) {
        // SRS tables may not exist yet -- no biggie, we'll survive without the stats
    }
}

// Handle snooze POST before loading items (Auditors cannot snooze)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['snooze_todo']) && !$isAuditor) {
    $security = Security::getInstance();
    if ($security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $snoozeDays = intval($_POST['snooze_days'] ?? 0);
        if (in_array($snoozeDays, [5, 10, 30])) {
            $dbSnooze = Database::getInstance();
            // Auto-create table if it doesn't exist
            $dbSnooze->query("CREATE TABLE IF NOT EXISTS cyber_todo_snoozes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                todo_type VARCHAR(50) NOT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id INT UNSIGNED NOT NULL,
                snoozed_until DATETIME NOT NULL,
                snoozed_by INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_unique_snooze (todo_type, reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $snoozedUntil = date('Y-m-d H:i:s', strtotime("+{$snoozeDays} days"));
            $dbSnooze->query(
                "INSERT INTO cyber_todo_snoozes (todo_type, reference_type, reference_id, snoozed_until, snoozed_by)
                 VALUES (:todo_type, :ref_type, :ref_id, :until, :user_id)
                 ON DUPLICATE KEY UPDATE snoozed_until = VALUES(snoozed_until), snoozed_by = VALUES(snoozed_by)",
                [
                    ':todo_type' => $_POST['todo_type'] ?? '',
                    ':ref_type' => $_POST['reference_type'] ?? '',
                    ':ref_id' => intval($_POST['reference_id'] ?? 0),
                    ':until' => $snoozedUntil,
                    ':user_id' => $user['id'],
                ]
            );
        }
    }
    header('Location: index.php');
    exit;
}

// Cyber To-Do items -- the "stuff keeping the security team up at night" widget
$cyberTodoItems = $showSRSModule ? $dashboardData->getCyberTodoItems($srsService) : [];

// Filter out items whose cases have been fully closed in the Cyber To-Do system
if (!empty($cyberTodoItems)) {
    try {
        $closedRefs = [];
        $dbTodo = Database::getInstance();
        // Find vendor_onboarding_requests IDs where ALL activities are closed (none open/in_progress)
        $closedVendors = $dbTodo->fetchAll(
            "SELECT DISTINCT reference_id FROM cyber_todo_activities
             WHERE reference_type = 'vendor_onboarding_requests'
               AND status = 'closed'
               AND reference_id NOT IN (
                   SELECT reference_id FROM cyber_todo_activities
                   WHERE reference_type = 'vendor_onboarding_requests' AND status IN ('open','in_progress')
               )"
        );
        foreach ($closedVendors as $row) {
            $closedRefs['vendor_onboarding_requests:' . $row['reference_id']] = true;
        }
        // Same for vendor_assessments
        $closedAssessments = $dbTodo->fetchAll(
            "SELECT DISTINCT reference_id FROM cyber_todo_activities
             WHERE reference_type = 'vendor_assessments'
               AND status = 'closed'
               AND reference_id NOT IN (
                   SELECT reference_id FROM cyber_todo_activities
                   WHERE reference_type = 'vendor_assessments' AND status IN ('open','in_progress')
               )"
        );
        foreach ($closedAssessments as $row) {
            $closedRefs['vendor_assessments:' . $row['reference_id']] = true;
        }
        if (!empty($closedRefs)) {
            $cyberTodoItems = array_values(array_filter($cyberTodoItems, function($item) use ($closedRefs) {
                // Extract reference ID from the link URL
                if (preg_match('/[?&]id=(\d+)/', $item['link'] ?? '', $m)) {
                    $refId = $m[1];
                    $refType = (strpos($item['link'], 'vendor-assessment-view') !== false)
                        ? 'vendor_assessments' : 'vendor_onboarding_requests';
                    return !isset($closedRefs[$refType . ':' . $refId]);
                }
                return true;
            }));
        }
    } catch (Exception $e) {
        // Non-fatal — show unfiltered items
    }

    // Filter out snoozed items
    try {
        $dbSnooze = Database::getInstance();
        $snoozedRefs = [];
        $snoozedRows = $dbSnooze->fetchAll(
            "SELECT todo_type, reference_type, reference_id FROM cyber_todo_snoozes WHERE snoozed_until > NOW()"
        );
        foreach ($snoozedRows as $row) {
            $snoozedRefs[$row['todo_type'] . ':' . $row['reference_type'] . ':' . $row['reference_id']] = true;
        }
        if (!empty($snoozedRefs)) {
            $cyberTodoItems = array_values(array_filter($cyberTodoItems, function($item) use ($snoozedRefs) {
                $key = ($item['type'] ?? '') . ':' . ($item['reference_type'] ?? '') . ':' . ($item['reference_id'] ?? '');
                return !isset($snoozedRefs[$key]);
            }));
        }
    } catch (Exception $e) {
        // Table may not exist yet — no biggie
    }
}

// Annual review countdown -- vendors due within 30 days or already overdue.
// Because nothing says "compliance" like a big scary number on the dashboard.
$annualReviewCount = 0;
if ($showOnboarding || $isCyberTPRM || $isAdmin || $isAuditor) {
    $canReadAll = hasPermission('annual_review.read');
    $canReadAssigned = hasPermission('annual_review.read_assigned');
    $annualReviewCount = $dashboardData->getAnnualReviewCount($user, $canReadAll, $canReadAssigned);
}

// Request counts for sidebar badges -- everyone loves badge counters.
// They're like unread emails but for vendor risk management.
$pendingRequestCount = 0;
$draftRequestCount = 0;
$approvedVendorCount = 0;
$vendorTaskCount = 0;
$totalVendorCount = 0;
if ($showOnboarding) {
    $counts = $dashboardData->getRequestCounts($user['id']);
    $pendingRequestCount = $counts['pending'];
    $draftRequestCount = $counts['drafts'];
    $approvedVendorCount = $counts['approved'];
    $totalVendorCount = $pendingRequestCount + $draftRequestCount + $approvedVendorCount;

    // Also count vendors assigned to this user (not just created by them)
    if ($isStakeholderOnly && $totalVendorCount === 0) {
        try {
            $db = Database::getInstance();
            $assignedCount = $db->fetchOne(
                "SELECT COUNT(DISTINCT s.request_id) as count FROM vendor_onboarding_stakeholders s
                 INNER JOIN vendor_onboarding_requests r ON s.request_id = r.id
                 WHERE s.user_id = :uid",
                [':uid' => $user['id']]
            );
            $totalVendorCount = intval($assignedCount['count'] ?? 0);
        } catch (\Exception $e) {
            // Table may not exist yet
        }
    }

    $canReadAll = hasPermission('onboarding.read') || hasGroup('administrator') || hasGroup('cyber_tprm');
    $canReadOwn = hasPermission('onboarding.read_own') || hasGroup('stakeholder') || hasGroup('procurement');
    $canReadAssigned = hasPermission('onboarding.read_assigned') || hasGroup('stakeholder');
    $vendorTaskCount = $dashboardData->getVendorTaskCount($user['id'], $canReadAll, $canReadOwn, $canReadAssigned);
}

// Expiring contracts count for the Procurement sidebar badge
$expiringContractsCount = 0;
if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor) {
    $expiringContractsCount = $dashboardData->getExpiringContractsCount();
}

// Stakeholder-specific annual review count -- simplified view for
// folks who only have the stakeholder hat on. They see their vendors, not the whole enchilada.
$stakeholderAnnualReviewCount = 0;
if ($isStakeholderOnly && (hasPermission('annual_review.read_assigned') || hasGroup('stakeholder'))) {
    $stakeholderAnnualReviewCount = $dashboardData->getStakeholderAnnualReviewCount($user['id']);
}

// Case management counts for sidebar
$openCasesCount = 0;
$closedCasesCount = 0;
$assignedToMeCasesCount = 0;
try {
    $db = Database::getInstance();
    if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor) {
        // Admin/procurement/cyber_tprm/auditor see all cases
        $openCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)"
        )['c'] ?? 0);
        $closedCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND status='closed' AND (parent_id IS NULL OR parent_id = 0)"
        )['c'] ?? 0);
    } elseif ($isStakeholderOnly) {
        // Stakeholders see cases for their assigned vendors
        $stakeholderVendorIds = $db->fetchAll(
            "SELECT request_id FROM vendor_onboarding_stakeholders WHERE user_id = :uid",
            [':uid' => $user['id']]
        );
        if (!empty($stakeholderVendorIds)) {
            $ids = array_column($stakeholderVendorIds, 'request_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $openCasesCount = intval($db->fetchOne(
                "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)", $ids
            )['c'] ?? 0);
            $closedCasesCount = intval($db->fetchOne(
                "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND reference_id IN ($placeholders) AND status='closed' AND (parent_id IS NULL OR parent_id = 0)", $ids
            )['c'] ?? 0);
        }
    }
    // Assigned to me count (for all roles)
    $assignedToMeCasesCount = intval($db->fetchOne(
        "SELECT COUNT(*) as c FROM cyber_todo_activities WHERE reference_type='vendor_onboarding_requests' AND assigned_to = :uid AND status IN ('open','in_progress') AND (parent_id IS NULL OR parent_id = 0)",
        [':uid' => $user['id']]
    )['c'] ?? 0);
} catch (Exception $e) {}

// Expiring contract count for sidebar (from vendor_documents directly)
$expiringContractCasesCount = 0;
try {
    if ($isAdmin || $isProcurement || $isCyberTPRM || $isAuditor) {
        $expiringContractCasesCount = intval($db->fetchOne(
            "SELECT COUNT(*) as c FROM vendor_documents
             WHERE document_type = 'contract' AND is_active = 1
               AND contract_expiration_date IS NOT NULL
               AND contract_expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
        )['c'] ?? 0);
    }
} catch (Exception $e) {}

$csrfToken = Security::getInstance()->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="<?php echo e(currentLanguage()); ?>">
<head>
    <title><?php echo e(t('index.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
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

        .sidebar-nav li a .badge {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: var(--nav-font-color);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 35px 40px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }

        .welcome-header {
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .welcome-header h1 {
            font-size: 26px;
            margin: 0 0 6px 0;
            color: #333;
            font-weight: 600;
        }

        .welcome-header p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        /* Module Cards */
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 0 0 18px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 18px;
            margin-bottom: 35px;
        }

        .module-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 22px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .module-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            text-decoration: none;
            border-color: var(--theme-header-color);
        }

        .module-card .icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .module-card h3 {
            font-size: 16px;
            margin: 0 0 6px 0;
            color: var(--theme-header-color);
            font-weight: 600;
        }

        .module-card p {
            color: #666;
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
            flex: 1;
        }

        .module-card .tag {
            display: inline-block;
            margin-top: 14px;
            padding: 3px 9px;
            background: #f3f4f6;
            border-radius: 4px;
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .quick-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: var(--theme-button-color);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .quick-action-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            text-decoration: none;
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .quick-action-btn.secondary {
            background: #6b7280;
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
            .sidebar-brand {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 15px 20px;
            }
            .sidebar-brand img {
                max-width: 150px;
            }
            .sidebar-brand .brand-title {
                margin-top: 0;
            }
            .sidebar-content {
                padding: 10px 0;
            }
            .sidebar-section {
                margin-bottom: 10px;
            }
            .sidebar-section-title {
                padding: 0 15px;
                margin-bottom: 8px;
            }
            .sidebar-nav {
                display: flex;
                flex-wrap: wrap;
                padding: 0 10px;
            }
            .sidebar-nav li {
                flex: 0 0 auto;
            }
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
            .main-content {
                padding: 25px 20px;
            }
            .module-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            }
        }

        /* Responsive - Mobile */
        @media (max-width: 576px) {
            .top-bar {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .top-bar > span {
                margin-right: 0 !important;
            }
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
            .sidebar-nav li {
                width: 100%;
            }
            .sidebar-nav li a {
                margin: 2px 0;
                border-radius: 6px;
            }
            .module-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .module-card {
                padding: 18px;
            }
            .quick-actions {
                flex-direction: column;
            }
            .quick-action-btn {
                justify-content: center;
                width: 100%;
            }
            .welcome-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .welcome-header h1 {
                font-size: 22px;
            }
            .vendor-search-container {
                width: 100% !important;
                min-width: 0 !important;
            }
            .main-content {
                padding: 20px 15px;
            }
        }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }

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

        /* Hide preloader */
        .preloader { display: none !important; }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar with User Menu -->
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
            <?php $currentPage = 'dashboard'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content Area -->
            <main class="main-content">
                <?php if ($isStakeholderOnly): ?>
                <!-- Stakeholder-Only Dashboard -->
                <div class="welcome-header" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                    <h1 class="page-title"><?php echo e(t('index.onboarding_portal')); ?></h1>
                    <p><?php echo e(t('index.onboarding_portal_desc')); ?></p>
                </div>

                <?php if ($stakeholderAnnualReviewCount > 0): ?>
                <!-- Annual Review Warning Alert -->
                <div class="annual-review-alert" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%); border: 1px solid #f5c6cb; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 20px; margin-bottom: 25px; display: flex; align-items: flex-start; gap: 15px; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);">
                    <div style="font-size: 32px; line-height: 1;">&#9888;</div>
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px 0; color: #92400e; font-size: 16px; font-weight: 600;"><?php echo e(t('index.annual_reviews_required')); ?></h3>
                        <p style="margin: 0 0 12px 0; color: #78350f; font-size: 14px; line-height: 1.5;">
                            You have <strong><?php echo $stakeholderAnnualReviewCount; ?></strong> vendor<?php echo $stakeholderAnnualReviewCount > 1 ? 's' : ''; ?>
                            that <?php echo $stakeholderAnnualReviewCount > 1 ? 'require' : 'requires'; ?> an annual review.
                            Please complete these reviews to maintain vendor compliance.
                        </p>
                        <a href="vendor-annual-reviews-list.php?filter=overdue" class="hover-bg-amber" style="display: inline-flex; align-items: center; gap: 6px; background: #f59e0b; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;"
                            <img src="app/icons/calendar-check-01.svg" alt="" width="16" height="16"> <?php echo e(t('index.review_now')); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($totalVendorCount > 0): ?>
                <!-- Quick Stats for Stakeholders -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 30px;">
                    <a href="vendor-onboarding-list.php?status=draft" class="hover-card" style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; text-decoration: none; transition: all 0.2s; cursor: pointer;">
                        <div style="font-size: 28px; font-weight: 600; color: var(--theme-header-color);"><?php echo $draftRequestCount; ?></div>
                        <div style="color: #666; font-size: 13px; margin-top: 4px;"><?php echo e(t('index.draft_requests')); ?></div>
                    </a>
                    <a href="vendor-onboarding-list.php?status=submitted" class="hover-card-amber" style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; text-decoration: none; transition: all 0.2s; cursor: pointer;">
                        <div style="font-size: 28px; font-weight: 600; color: #f59e0b;"><?php echo $pendingRequestCount; ?></div>
                        <div style="color: #666; font-size: 13px; margin-top: 4px;"><?php echo e(t('index.pending_review')); ?></div>
                    </a>
                    <a href="vendor-onboarding-list.php?status=approved" class="hover-card-green" style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; text-decoration: none; transition: all 0.2s; cursor: pointer;">
                        <div style="font-size: 28px; font-weight: 600; color: #28a745;"><?php echo $approvedVendorCount; ?></div>
                        <div style="color: #666; font-size: 13px; margin-top: 4px;"><?php echo e(t('index.approved_vendors')); ?></div>
                    </a>
                </div>
                <?php endif; ?>

                <div class="quick-actions">
                    <a href="vendor-onboarding.php" class="quick-action-btn">
                        <img src="app/icons/plus-square.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('index.submit_new_vendor_request')); ?>
                    </a>
                    <?php if ($totalVendorCount > 0): ?>
                    <a href="vendor-onboarding-list.php" class="quick-action-btn secondary">
                        <img src="app/icons/book-open-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('index.view_my_vendor_requests')); ?>
                    </a>
                    <?php endif; ?>
                </div>

                <h2 class="section-title"><?php echo e(t('index.quick_actions')); ?></h2>
                <div class="module-grid">
                    <a href="vendor-onboarding.php" class="module-card">
                        <div class="icon"><img src="app/icons/plus-square.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.new_vendor_request')); ?></h3>
                        <p><?php echo e(t('index.new_vendor_request_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.start_here')); ?></span>
                    </a>

                    <?php if ($totalVendorCount > 0): ?>
                    <a href="vendor-onboarding-list.php" class="module-card">
                        <div class="icon"><img src="app/icons/book-open-01.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.my_vendor_requests')); ?></h3>
                        <p><?php echo e(t('index.my_vendor_requests_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.my_submissions')); ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($draftRequestCount > 0): ?>
                    <a href="vendor-onboarding-list.php?status=draft" class="module-card" style="border-color: #fbbf24;">
                        <div class="icon"><img src="app/icons/edit-04.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.continue_draft')); ?></h3>
                        <p>You have <?php echo $draftRequestCount; ?> unfinished draft<?php echo $draftRequestCount > 1 ? 's' : ''; ?>. Continue editing and submit when ready.</p>
                        <span class="tag" style="background: #fef3c7; color: #92400e;"><?php echo e(t('index.in_progress')); ?></span>
                    </a>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <?php if ($isAdmin && file_exists(__DIR__ . '/setup.php')): ?>
                <!-- Security Warning: setup.php still exists -->
                <div id="setupWarningOverlay" style="background: #fef2f2; border: 2px solid #dc2626; border-radius: 10px; padding: 0; overflow: hidden; margin-bottom: 25px; box-shadow: 0 4px 12px rgba(220,38,38,0.15);">
                    <div style="background: #dc2626; color: #fff; padding: 14px 20px; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 22px;">&#9888;</span>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;"><?php echo e(t('index.security_warning')); ?></h3>
                        <button type="button" data-close="setupWarningOverlay" style="margin-left: auto; background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; padding: 0 4px; opacity: 0.8;" title="<?php echo e(t('index.dismiss')); ?>">&times;</button>
                    </div>
                    <div style="padding: 18px 20px;">
                        <p style="color: #333; font-size: 14px; line-height: 1.6; margin: 0 0 12px;">
                            The file <code style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-weight: 600;">setup.php</code> still exists on the server. This is a <strong>critical security risk</strong> that could allow anyone to reconfigure your application.
                        </p>
                        <div style="background: #1e1e1e; color: #d4d4d4; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 13px;">
                            <code style="color: #ce9178;">rm</code> <code style="color: #9cdcfe;"><?php echo e(realpath(__DIR__ . '/setup.php')); ?></code>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Full Dashboard for Admins, Procurement, Cyber TPRM -->
                <div class="welcome-header">
                    <div style="flex: 1;">
                        <h1 class="page-title"><?php echo e(t('index.welcome_back')); ?> <?php echo e($user['full_name']); ?></h1>
                        <p><?php echo e(t('index.dashboard_intro')); ?></p>
                    </div>
                    <?php if ($isAdmin || $isCyberTPRM || $isProcurement || $isAuditor): ?>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input
                            type="text"
                            id="vendorSearchInput"
                            placeholder="<?php echo e(t('index.search_placeholder')); ?>"
                            class="focus-ring"
                            style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                            <?php if ($isProcurement && !$isAdmin && !$isCyberTPRM): ?>data-link-base="vendor-onboarding.php"<?php endif; ?>
                        >
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">🔍</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (($showOnboarding || $showFairModule) && !$isAuditor): ?>
                <div class="quick-actions">
                    <?php if ($showOnboarding && hasPermission('onboarding.create')): ?>
                        <a href="vendor-onboarding.php" class="quick-action-btn">
                            <img src="app/icons/plus-square.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('index.new_vendor_request')); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($showFairModule): ?>
                        <a href="fair-analysis.php" class="quick-action-btn secondary">
                            <img src="app/icons/file-07.svg" alt="" width="18" height="18" style="vertical-align: middle;"> <?php echo e(t('index.new_fair_analysis')); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Modules Section -->
                <h2 class="section-title"><?php echo e(t('index.modules')); ?></h2>
                <div class="module-grid">
                    <?php if ($showFairModule): ?>
                    <a href="fair_dashboard.php" class="module-card">
                        <div class="icon"><img src="app/icons/file-07.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.fair_risk_analysis')); ?></h3>
                        <p><?php echo e(t('index.fair_risk_analysis_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.risk_assessment')); ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($showOnboarding): ?>
                    <a href="vendor-onboarding-list.php" class="module-card">
                        <div class="icon"><img src="app/icons/book-open-01.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.vendor_onboarding')); ?></h3>
                        <p><?php echo e(t('index.vendor_onboarding_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.stakeholder_portal')); ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($isProcurement): ?>
                    <a href="vendor-onboarding-list.php" class="module-card">
                        <div class="icon"><img src="app/icons/file-06.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.contracts')); ?></h3>
                        <p><?php echo e(t('index.contracts_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.procurement')); ?></span>
                    </a>
                    <?php endif; ?>

                    <?php if ($showSRSModule): ?>
                    <a href="vendor-srs-list.php" class="module-card">
                        <div class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.srs_scoring')); ?></h3>
                        <p><?php echo e(t('index.srs_scoring_desc')); ?></p>
                        <?php if ($srsStats['vendors_with_scores'] > 0): ?>
                        <span class="tag" style="background: #ecfdf5; color: #065f46;"><?php echo $srsStats['vendors_with_scores']; ?> Vendors Scored</span>
                        <?php else: ?>
                        <span class="tag"><?php echo e(t('index.security_ratings')); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($isAdmin || $isCyberTPRM || $isProcurement || $isAuditor): ?>
                    <a href="vendor-assessments.php" class="module-card">
                        <div class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.vendor_assessments')); ?></h3>
                        <p><?php echo e(t('index.vendor_assessments_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.security_questionnaires')); ?></span>
                    </a>
                    <?php endif; ?>

                </div>

                <?php if (($isCyberTPRM || $isAdmin || $isAuditor) && !empty($cyberTodoItems)): ?>
                <!-- Cyber To-Do Section -->
                <h2 class="section-title"><?php echo e(t('index.cyber_todo')); ?></h2>
                <div class="cyber-todo-section" style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <p style="margin: 0; color: #666; font-size: 13px;"><?php echo e(t('index.cyber_todo_desc')); ?></p>
                        <a href="cyber-todo.php" style="font-size: 13px; color: var(--theme-header-color); text-decoration: none;"><?php echo e(t('index.view_all')); ?> &rarr;</a>
                    </div>
                    <div class="todo-list" style="display: flex; flex-direction: column; gap: 10px;">
                        <?php
                        $displayedItems = array_slice($cyberTodoItems, 0, 8);
                        foreach ($displayedItems as $item):
                        ?>
                        <div class="todo-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 15px; background: #f9fafb; border-radius: 8px; border-left: 4px solid <?php echo e($item['badge_color']); ?>;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                    <span style="font-weight: 500; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo e($item['title']); ?></span>
                                    <span style="font-size: 10px; padding: 2px 8px; border-radius: 10px; background: <?php echo e($item['badge_color']); ?>20; color: <?php echo e($item['badge_color']); ?>; font-weight: 500; white-space: nowrap;"><?php echo e($item['badge']); ?></span>
                                </div>
                                <div style="font-size: 12px; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo e($item['description']); ?></div>
                            </div>
                            <?php if (!$isAuditor): ?>
                            <div style="position: relative;" class="snooze-wrapper">
                                <button type="button" class="snooze-toggle" style="padding: 5px 10px; background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; border-radius: 4px; font-size: 11px; cursor: pointer; white-space: nowrap;" title="<?php echo e(t('index.snooze_this_item')); ?>">&#9716; <?php echo e(t('index.snooze')); ?> &#9662;</button>
                                <div class="snooze-dropdown" style="display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; min-width: 130px;">
                                    <?php foreach ([5, 10, 30] as $days): ?>
                                    <form method="post" style="margin: 0;">
                                        <input type="hidden" name="snooze_todo" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="todo_type" value="<?php echo e($item['type'] ?? ''); ?>">
                                        <input type="hidden" name="reference_type" value="<?php echo e($item['reference_type'] ?? ''); ?>">
                                        <input type="hidden" name="reference_id" value="<?php echo intval($item['reference_id'] ?? 0); ?>">
                                        <input type="hidden" name="snooze_days" value="<?php echo $days; ?>">
                                        <button type="submit" style="display: block; width: 100%; padding: 8px 14px; background: none; border: none; text-align: left; font-size: 12px; color: #374151; cursor: pointer; white-space: nowrap;"
                                            onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'"><?php echo $days; ?> days</button>
                                    </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <a href="<?php echo e($item['link']); ?>" style="padding: 6px 12px; background: var(--theme-header-color); color: white; border-radius: 4px; text-decoration: none; font-size: 12px; white-space: nowrap;"><?php echo e(t('index.view')); ?></a>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($cyberTodoItems) > 8): ?>
                        <div style="text-align: center; padding: 10px;">
                            <a href="cyber-todo.php" style="color: var(--theme-header-color); text-decoration: none; font-size: 13px;">
                                + <?php echo count($cyberTodoItems) - 8; ?> more items
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                <!-- Admin Section -->
                <h2 class="section-title"><?php echo e(t('index.administration')); ?></h2>
                <div class="module-grid">
                    <a href="admin.php" class="module-card">
                        <div class="icon"><img src="app/icons/key-01.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('index.system_administration')); ?></h3>
                        <p><?php echo e(t('index.system_administration_desc')); ?></p>
                        <span class="tag"><?php echo e(t('index.admin_only')); ?></span>
                    </a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('index.footer_logo_alt')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('index.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        document.querySelectorAll('.snooze-toggle').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var dropdown = btn.nextElementSibling;
                // Close all other open dropdowns first
                document.querySelectorAll('.snooze-dropdown').forEach(function(d) {
                    if (d !== dropdown) d.style.display = 'none';
                });
                dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
            });
        });
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.snooze-dropdown').forEach(function(d) {
                d.style.display = 'none';
            });
        });
    })();
    </script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
