<?php
/**
 * FAIR Module Dashboard - Risk Analysis Home Base
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the landing page for the FAIR analysis module. Think of it as the
 * lobby of the risk analysis department -- it shows your recent analyses,
 * quick stats (total vendors, drafts, completed), and gives you quick links
 * to create new assessments, import CSV data, export your work, or view
 * detailed reports. It also has a nifty vendor search for admins and cyber folks.
 *
 * Features:
 * - Dashboard stats (total/draft/completed vendor analyses)
 * - Quick action buttons (new analysis, import CSV, export CSV)
 * - Action cards for the main FAIR workflows
 * - Recent analyses table with delete capability
 * - CSV export of all your FAIR analysis data (decrypted, of course)
 * - Vendor search type-ahead for admin/cyber_tprm users
 *
 * The CSV export here is the "full dump" version -- every field, every record,
 * all decrypted and ready for spreadsheet warriors to do their thing.
 */

require_once 'includes/init.php';
requireAuth();

// The usual lineup of singletons
$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();

// Handle delete request -- someone clicked the little trash can icon.
// We do a POST-redirect-GET to avoid the "resubmit form?" browser dialog
// that everyone accidentally clicks "yes" on and deletes things twice.
$acl = ACL::getInstance();
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"]) && $acl->hasGroup("auditor")) {
    $_SESSION["error_message"] = t('fair_dashboard.access_denied_auditor');
    header("Location: fair_dashboard.php");
    exit;
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = t('fair_dashboard.invalid_request');
        header('Location: fair_dashboard.php');
        exit;
    }
    $db = Database::getInstance();
    $deleteId = intval($_POST['delete_id']);

    // Permission check: you can only delete your own stuff (or everything if you're admin)
    $analysis = $db->fetchOne(
        "SELECT id, user_id FROM tprm_results WHERE id = :id",
        [':id' => $deleteId]
    );

    if ($analysis && ($auth->isAdmin() || $analysis['user_id'] == $user['id'])) {
        $auth->audit($user['id'], 'fair_analysis_delete', 'tprm_results', $deleteId);
        $db->delete('tprm_results', 'id = :id', [':id' => $deleteId]);
        $_SESSION['success_message'] = t('fair_dashboard.delete_success');
    } else {
        $_SESSION['error_message'] = t('fair_dashboard.delete_no_permission');
    }

    // Redirect to avoid the dreaded form resubmission on refresh
    header('Location: fair_dashboard.php');
    exit;
}

// Permission checks -- figure out what this user is allowed to see
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');

$showOnboarding = hasPermission('onboarding.create') ||
                  hasPermission('onboarding.read') ||
                  hasPermission('onboarding.read_own') ||
                  hasPermission('onboarding.read_assigned');

// Procurement-only users should not access the FAIR module at all
$isProcurement = hasGroup('procurement');
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurementOnly = $isProcurement && !$isAdmin && !$isCyberTPRM;
if ($isProcurementOnly) {
    redirect('index.php');
}

// Does this user have any FAIR module access at all?
// If not, they shouldn't even be on this page, but we check anyway.
$hasFairAccess = hasPermission('analysis.create') ||
                 hasPermission('analysis.read') ||
                 hasGroup('cyber_tprm') ||
                 $isAdmin;

// ============================================================================
// CSV EXPORT -- The "give me everything in a spreadsheet" feature.
// Downloads all FAIR analysis records for the current user, decrypts every
// encrypted field, and spits out a CSV. This is the full export, not the
// filtered/sorted version from the reports page.
// ============================================================================
if (isset($_GET['export_csv'])) {
    $db = Database::getInstance();
    $encryption = new Encryption();

    // Fetch all records for current user
    $results = $db->fetchAll(
        'SELECT * FROM tprm_results WHERE user_id = :user_id ORDER BY created_at DESC',
        [':user_id' => $user['id']]
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fair_analysis_export_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // CSV Headers (same as import template)
    $headers = [
        'vendor_name', 'vendor_domain', 'msa', 'scope_of_work', 'medium_of_data',
        'certifications', 'compliance', 'security_governance', 'incident_response_plan',
        'continuous_monitoring', 'supply_chain_risk_mgmt', 'security_awareness_training',
        'vulnerability_management', 'patch_management', 'access_controls', 'data_encryption',
        'network_security', 'daily_impact', 'security_score', 'vulnerability_data',
        'configuration_data', 'compliance_data', 'risk_assessment', 'threat_intelligence',
        'iso_27001_certified', 'securityscorecard_rating', 'pii_record_count',
        'spii_record_count', 'sox_record_count', 'vendor_risk_assessment',
        'security_questionnaire', 'compliance_questionnaire', 'data_classification',
        'data_sharing', 'business_impact', 'vendor_performance', 'third_party_vendor_list',
        'third_party_risk_assessment', 'third_party_security_questionnaire',
        'third_party_compliance_questionnaire', 'vendor_cyber_insurance_coverage',
        'cost_of_breach', 'cost_of_outage', 'sec_fines', 'compliance_fines',
        'insurance_premiums'
    ];

    fputcsv($output, $headers);

    // Export each record
    foreach ($results as $result) {
        $row = [];

        // Fields that need decryption
        $encryptedFields = [
            'msa', 'scope_of_work', 'medium_of_data', 'certifications',
            'compliance', 'security_governance', 'incident_response_plan', 'continuous_monitoring',
            'supply_chain_risk_mgmt', 'security_awareness_training', 'vulnerability_management',
            'patch_management', 'access_controls', 'data_encryption', 'network_security',
            'daily_impact', 'vulnerability_data', 'configuration_data', 'compliance_data',
            'risk_assessment', 'threat_intelligence', 'vendor_risk_assessment',
            'security_questionnaire', 'compliance_questionnaire', 'data_classification',
            'data_sharing', 'business_impact', 'vendor_performance', 'third_party_vendor_list',
            'third_party_risk_assessment', 'third_party_security_questionnaire',
            'third_party_compliance_questionnaire', 'vendor_cyber_insurance_coverage',
            'cost_of_breach', 'cost_of_outage', 'sec_fines', 'compliance_fines',
            'insurance_premiums'
        ];

        foreach ($headers as $header) {
            if (in_array($header, $encryptedFields)) {
                // Decrypt encrypted fields
                $value = $result[$header] ?? '';
                if (!empty($value)) {
                    $decrypted = $encryption->decrypt($value);
                    $row[] = $decrypted !== false ? $decrypted : '';
                } else {
                    $row[] = '';
                }
            } else {
                // Plain text fields
                $row[] = $result[$header] ?? '';
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

$csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('fair_dashboard.page_title')); ?></title>
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
            padding: 30px 35px;
            background: #f9fafb;
            min-width: 0;
            overflow-y: auto;
        }

        .module-header {
            background: var(--theme-header-color);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .module-header .breadcrumb {
            margin-bottom: 12px;
            font-size: 13px;
            opacity: 0.8;
        }

        .module-header .breadcrumb a {
            color: white;
            text-decoration: none;
        }

        .module-header .breadcrumb a:hover {
            text-decoration: underline;
        }

        .module-header h1 {
            margin: 0 0 8px 0;
            font-size: 26px;
            font-weight: 600;
        }

        .page-title { font-size: 18px; font-weight: 600; color: #333; }

        .module-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
            line-height: 1.5;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            text-decoration: none;
            display: block;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            border-color: var(--theme-header-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-card .number {
            font-size: 26px;
            font-weight: 600;
            color: var(--theme-header-color);
        }

        .stat-card .label {
            color: #6b7280;
            font-size: 13px;
            margin-top: 4px;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        .action-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 22px;
            transition: all 0.2s ease;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            text-decoration: none;
            border-color: var(--theme-header-color);
        }

        .action-card .icon {
            font-size: 28px;
            margin-bottom: 12px;
        }

        .action-card h3 {
            color: var(--theme-header-color);
            margin: 0 0 6px 0;
            font-size: 15px;
            font-weight: 600;
        }

        .action-card p {
            color: #6b7280;
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }

        /* Action Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            text-decoration: none;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-info {
            background: #3b82f6;
            color: white;
        }

        /* Recent Analyses Table */
        .recent-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 25px;
            margin-top: 30px;
        }
        .recent-section h2 {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0 0 20px 0;
        }
        .recent-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .recent-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 500;
            border-bottom: 2px solid #e5e7eb;
            color: #374151;
        }
        .recent-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .recent-table tr:hover {
            background: #f9fafb;
        }
        .recent-table tr:last-child td {
            border-bottom: none;
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
        .alert {
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            .module-header {
                padding: 22px;
            }
            .module-header h1 {
                font-size: 22px;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
            .action-grid {
                grid-template-columns: 1fr;
            }
            .main-content {
                padding: 20px 15px;
            }
        }

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
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php $currentPage = 'fair'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <!-- Main Content Area -->
            <main class="main-content">
                <?php
                // Get stats for the dashboard
                $db = Database::getInstance();
                $userId = $user['id'];

                try {
                    // Use DISTINCT counts to show unique vendors, not total analyses
                    $totalAnalyses = $db->fetchOne(
                        'SELECT COUNT(DISTINCT vendor_name) as count FROM tprm_results WHERE user_id = :user_id',
                        [':user_id' => $userId]
                    );
                    $draftCount = $db->fetchOne(
                        'SELECT COUNT(DISTINCT vendor_name) as count FROM tprm_results WHERE user_id = :user_id AND status = :status',
                        [':user_id' => $userId, ':status' => 'draft']
                    );
                    $completedCount = $db->fetchOne(
                        'SELECT COUNT(DISTINCT vendor_name) as count FROM tprm_results WHERE user_id = :user_id AND status = :status',
                        [':user_id' => $userId, ':status' => 'completed']
                    );

                    // Get recent analyses (most recent per vendor)
                    $recentAnalyses = $db->fetchAll(
                        "SELECT r.id, r.vendor_name, r.status, r.risk_output, r.created_at, r.user_id
                         FROM tprm_results r
                         INNER JOIN (
                             SELECT vendor_name, MAX(id) as max_id
                             FROM tprm_results
                             WHERE user_id = :user_id
                             GROUP BY vendor_name
                         ) AS latest ON r.id = latest.max_id
                         ORDER BY r.created_at DESC",
                        [':user_id' => $userId]
                    );
                } catch (Exception $e) {
                    $totalAnalyses = ['count' => 0];
                    $draftCount = ['count' => 0];
                    $completedCount = ['count' => 0];
                    $recentAnalyses = [];
                }
                ?>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 20px;">
                    <h1 class="page-title" style="margin: 0;"><?php echo e(t('fair_dashboard.heading')); ?></h1>
                    <?php if ($isAdmin || hasGroup('cyber_tprm')): ?>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input
                            type="text"
                            id="vendorSearchInput"
                            placeholder="<?php echo e(t('fair_dashboard.search_placeholder')); ?>"
                            style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                            class="focus-ring"
                        >
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">🔍</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php echo e($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <?php echo e($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
                <?php endif; ?>

                <div class="stats-row">
                    <a href="fair_results.php" class="stat-card">
                        <div class="number"><?php echo $totalAnalyses['count'] ?? 0; ?></div>
                        <div class="label"><?php echo e(t('fair_dashboard.total_vendors')); ?></div>
                    </a>
                    <a href="fair_results.php?status=draft" class="stat-card">
                        <div class="number"><?php echo $draftCount['count'] ?? 0; ?></div>
                        <div class="label"><?php echo e(t('fair_dashboard.draft_vendors')); ?></div>
                    </a>
                    <a href="fair_results.php?status=completed" class="stat-card">
                        <div class="number"><?php echo $completedCount['count'] ?? 0; ?></div>
                        <div class="label"><?php echo e(t('fair_dashboard.completed_vendors')); ?></div>
                    </a>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions" style="display: flex; gap: 12px; margin-bottom: 30px; flex-wrap: wrap;">
                    <a href="fair-analysis.php" class="btn btn-primary">
                        <span>➕</span> <?php echo e(t('fair_dashboard.btn_new_analysis')); ?>
                    </a>
                    <a href="fair_import-analysis.php" class="btn btn-success">
                        <span>📥</span> <?php echo e(t('fair_dashboard.btn_import_csv')); ?>
                    </a>
                    <a href="?export_csv=1" class="btn btn-info">
                        <span>📤</span> <?php echo e(t('fair_dashboard.btn_export_csv')); ?>
                    </a>
                </div>

                <div class="action-grid">
                    <a href="fair-analysis.php" class="action-card">
                        <div class="icon"><img src="app/icons/bar-chart-square-02.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('fair_dashboard.card_new_title')); ?></h3>
                        <p><?php echo e(t('fair_dashboard.card_new_desc')); ?></p>
                    </a>

                    <a href="reports.php" class="action-card">
                        <div class="icon"><img src="app/icons/file-03.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('fair_dashboard.card_reports_title')); ?></h3>
                        <p><?php echo e(t('fair_dashboard.card_reports_desc')); ?></p>
                    </a>

                    <a href="fair_import-analysis.php" class="action-card">
                        <div class="icon"><img src="app/icons/upload-cloud-01.svg" alt="" width="32" height="32"></div>
                        <h3><?php echo e(t('fair_dashboard.card_import_title')); ?></h3>
                        <p><?php echo e(t('fair_dashboard.card_import_desc')); ?></p>
                    </a>
                </div>

                <!-- Recent Analyses -->
                <?php if (!empty($recentAnalyses)): ?>
                <div class="recent-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px;">
                        <h2 style="margin: 0;"><?php echo e(t('fair_dashboard.recent_heading')); ?></h2>
                        <input
                            type="text"
                            id="recentAnalysesSearch"
                            placeholder="<?php echo e(t('fair_dashboard.recent_search_placeholder')); ?>"
                            style="padding: 8px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s; min-width: 200px;"
                            class="focus-ring"
                        >
                        <a href="reports.php" style="color: var(--theme-button-color); text-decoration: none; font-size: 13px; font-weight: 500; white-space: nowrap;"><?php echo e(t('fair_dashboard.view_all')); ?> →</a>
                    </div>
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('fair_dashboard.th_vendor_name')); ?></th>
                                <th><?php echo e(t('fair_dashboard.th_status')); ?></th>
                                <th><?php echo e(t('fair_dashboard.th_risk_level')); ?></th>
                                <th><?php echo e(t('fair_dashboard.th_created')); ?></th>
                                <th><?php echo e(t('fair_dashboard.th_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $riskColors = [
                                'Very Low' => '#28a745',
                                'Low' => '#5cb85c',
                                'Medium' => '#ffc107',
                                'High' => '#ff9800',
                                'Very High' => '#ff5722',
                                'Critical' => '#dc3545'
                            ];
                            foreach ($recentAnalyses as $analysis):
                            ?>
                                <tr>
                                    <td><strong><?php echo e($analysis['vendor_name']); ?></strong></td>
                                    <td>
                                        <?php if ($analysis['status'] === 'completed'): ?>
                                            <span style="color: #28a745;">✓ <?php echo e(t('fair_dashboard.status_completed')); ?></span>
                                        <?php else: ?>
                                            <span style="color: #ffc107;">⚠ <?php echo e(t('fair_dashboard.status_draft')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($analysis['risk_output'])): ?>
                                            <span style="color: <?php echo $riskColors[$analysis['risk_output']] ?? '#666'; ?>; font-weight: bold;">
                                                <?php echo e($analysis['risk_output']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;"><?php echo e(t('fair_dashboard.na')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($analysis['created_at'])); ?></td>
                                    <td style="white-space: nowrap;">
                                        <a href="view-result.php?id=<?php echo $analysis['id']; ?>" style="color: #ff6543; text-decoration: none; font-weight: 500; margin-right: 12px;"><?php echo e(t('fair_dashboard.view')); ?> →</a>
                                        <?php if ($isAdmin || $analysis['user_id'] == $user['id']): ?>
                                        <form method="POST" style="display: inline;" data-confirm="Are you sure you want to delete this analysis for <?php echo e($analysis['vendor_name']); ?>? This action cannot be undone.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="delete_id" value="<?php echo $analysis['id']; ?>">
                                            <button type="submit" class="btn-delete">🗑 <?php echo e(t('fair_dashboard.delete')); ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                        <span>All Rights Reserved</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        var input = document.getElementById('recentAnalysesSearch');
        if (!input) return;
        var tbody = input.closest('.recent-section').querySelector('.recent-table tbody');
        if (!tbody) return;
        input.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = tbody.querySelectorAll('tr');
            for (var i = 0; i < rows.length; i++) {
                var vendorCell = rows[i].querySelector('td');
                if (!vendorCell) continue;
                var text = vendorCell.textContent.toLowerCase();
                rows[i].style.display = text.indexOf(filter) !== -1 ? '' : 'none';
            }
        });
    })();
    </script>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
