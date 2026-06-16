<?php
/**
 * FAIR Results List - The Grand Gallery of Risk Analysis
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the big board where you can browse all your completed FAIR analyses.
 * Think of it as your trophy case for risk assessments -- complete with filtering,
 * sorting, CSV export, and the ability to nuke entries you regret creating at 2am.
 * Each row is a vendor you've assessed, showing the key metrics at a glance so you
 * don't have to click into every single one like some kind of caveman.
 */

// Boot up the app -- if init.php fails, nothing else matters anyway
require_once 'includes/init.php';
requireAuth(); // No ticket, no laundry. You gotta be logged in for this.

// Grab all the singletons we need. Yes, we love singletons here. Fight me.
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$encryption = new Encryption();
$theme = getUserTheme();

// ============================================================================
// CSV EXPORT HANDLER
// Someone clicked the "Export CSV" button. Time to dump everything into a
// spreadsheet so management can pretend they read it.
// ============================================================================
if (isset($_GET['export_csv'])) {
    // Pull every analysis this user owns, newest first
    $results = $db->fetchAll(
        'SELECT * FROM tprm_results WHERE user_id = :user_id ORDER BY created_at DESC',
        [':user_id' => $user['id']]
    );

    // Tell the browser "hey, this is a file download, not a web page"
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fair_analysis_export_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // The column headers -- buckle up, there are a LOT of these.
    // This matches the import template so you can round-trip your data.
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

    // Loop through each result and build a CSV row.
    // Most fields are encrypted at rest, so we decrypt on the fly.
    foreach ($results as $result) {
        $row = [];

        // These fields are the ones that got the encryption treatment.
        // Basically anything that could be considered sensitive or juicy.
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
                // Decrypt the field -- if decryption fails, just give back empty string.
                // We're not going to crash the export because one field got corrupted.
                $value = $result[$header] ?? '';
                if (!empty($value)) {
                    $decrypted = $encryption->decrypt($value);
                    $row[] = $decrypted !== false ? $decrypted : '';
                } else {
                    $row[] = '';
                }
            } else {
                // Plain text fields -- vendor_name, security_score, etc.
                $row[] = $result[$header] ?? '';
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit; // We're done here. No HTML needed when you're spitting out a CSV.
}

// Message variables for showing success/error banners to the user
$message = '';
$messageType = '';

// ============================================================================
// DELETE HANDLER
// Somebody wants to obliterate an analysis from existence. Let's make sure
// they own it first -- we're not running a delete-your-neighbor's-work service.
// ============================================================================
$acl = ACL::getInstance();
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"]) && $acl->hasGroup("auditor")) {
    $message = t('fair_results.auditor_read_only');
    $messageType = "error";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_id"])) {
    // CSRF check -- because we're not animals
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $message = t('fair_results.invalid_request');
        $messageType = 'error';
    } else {
        $deleteId = intval($_POST['delete_id']);

        // Verify the user actually owns this analysis before we nuke it
        $analysis = $db->fetchOne(
            'SELECT id FROM tprm_results WHERE id = :id AND user_id = :user_id',
            [':id' => $deleteId, ':user_id' => $user['id']]
        );

        if ($analysis) {
            try {
                $auth->audit($user['id'], 'fair_analysis_delete', 'tprm_results', $deleteId);
                $db->delete('tprm_results', 'id = :id', [':id' => $deleteId]);
                $message = t('fair_results.deleted_success');
                $messageType = 'success';
            } catch (Exception $e) {
                // Something went sideways. Log it and move on.
                error_log('Failed to delete analysis: ' . $e->getMessage());
                $message = t('fair_results.delete_failed');
                $messageType = 'error';
            }
        } else {
            // Either it doesn't exist or they're trying to delete someone else's stuff
            $message = t('fair_results.not_found');
            $messageType = 'error';
        }
    }
}

// ============================================================================
// CSRF TOKEN GENERATION
// We only regenerate the token on GET requests or after a successful POST.
// If we regenerated on every POST, we'd invalidate the token mid-validation
// and that's a one-way ticket to "Invalid request" city.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $messageType === 'success') {
    $csrfToken = $security->generateCSRFToken();
} else {
    $csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
}

// ============================================================================
// FILTERING & DATA FETCH
// Let users filter by draft vs completed. If no filter, show everything.
// ============================================================================
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$validStatuses = ['draft', 'completed'];

$params = [':user_id' => $user['id']];
$whereClause = 'WHERE user_id = :user_id';

// Only apply the status filter if it's a legit value (no SQL injection today, Satan)
if ($statusFilter && in_array($statusFilter, $validStatuses)) {
    $whereClause .= ' AND status = :status';
    $params[':status'] = $statusFilter;
}

// Pull the analyses for display -- just the columns we need for the list view
$analyses = $db->fetchAll(
    "SELECT id, vendor_name, status, completed_at, created_at, updated_at
     FROM tprm_results
     $whereClause
     ORDER BY created_at DESC",
    $params
);

$acl = ACL::getInstance();
$isAdmin = (Session::getInstance()->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator'));
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e(t('fair_results.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <link rel="stylesheet" href="app/css/style.css">
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

        .page { display: flex !important; flex-direction: column; min-height: 100vh; opacity: 1 !important; visibility: visible !important; }
        .page-header { display: none !important; }

        /* Top Bar */
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

        /* Main Layout */
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .main-content {
            flex: 1; padding: 35px 40px; background: #f9fafb;
            min-width: 0; overflow-y: auto;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important;
            padding: 0; flex-shrink: 0; display: flex; flex-direction: column;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5;
            padding: 0 20px; margin-bottom: 10px;
        }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a {
            display: flex; align-items: center; gap: 10px; padding: 11px 20px;
            color: var(--nav-font-color); opacity: 0.85; text-decoration: none;
            font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }

        /* Welcome header */
        .welcome-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px; flex-wrap: wrap; gap: 15px;
        }

        /* Table styles */
        .results-table {
            background: white; border-radius: 8px; overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: var(--theme-button-color, #ff6543); color: white; }
        th { padding: 15px; text-align: left; font-weight: 500; }
        td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
        tr:hover { background: #f9f9f9; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .status-draft { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .btn-small {
            padding: 6px 12px; font-size: 13px; border-radius: 4px;
            text-decoration: none; display: inline-block; margin-right: 5px;
        }
        .btn-view { background: #ff6543; color: white; }
        .btn-edit { background: #007bff; color: white; }
        .btn-delete {
            background: #dc3545; color: white; border: none; cursor: pointer;
            padding: 6px 12px; font-size: 13px; border-radius: 4px; margin-right: 5px;
        }
        .btn-delete:hover { background: #c82333; }
        .message { padding: 15px 20px; margin: 0 0 20px 0; border-radius: 4px; font-weight: 500; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state h3 { color: #666; margin-bottom: 15px; }

        /* Footer */
        .footer-modern, .bg-gray-13 { background-color: var(--theme-footer-color) !important; padding: 30px 0; color: #fff; width: 100%; overflow: visible; }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar -->
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

        <!-- Main Layout with Sidebar -->
        <div class="main-layout">
            <?php $currentPage = 'fair'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <!-- Welcome Header with Search -->
                <div class="welcome-header">
                    <div>
                        <h1 style="font-size: 18px; font-weight: 600; color: #333; margin: 0 0 5px 0;"><?php echo e(t('fair_results.heading')); ?></h1>
                        <p style="color: #666; margin: 0; font-size: 14px;"><?php echo e(t('fair_results.subheading')); ?></p>
                    </div>
                    <?php if ($isAdmin || $isCyberTPRM || $isProcurement): ?>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input type="text" id="vendorSearchInput" placeholder="<?php echo e(t('fair_results.search_placeholder')); ?>"
                               class="focus-ring"
                               style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                               <?php if ($isProcurement && !$isAdmin && !$isCyberTPRM): ?>data-link-base="vendor-onboarding.php"<?php endif; ?>>
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128270;</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?>
                    <div class="message message-<?php echo $messageType; ?>">
                        <?php echo e($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Action buttons -->
                <div style="display: flex; gap: 10px; margin-bottom: 25px;">
                    <a href="fair_import-analysis.php" style="background: #28a745; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500;"><?php echo e(t('fair_results.import')); ?></a>
                    <a href="?export_csv=1" style="background: #007bff; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500;"><?php echo e(t('fair_results.export_csv')); ?></a>
                    <a href="fair-analysis.php" style="background: var(--theme-button-color, #ffc211); color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500;"><?php echo e(t('fair_results.new_analysis')); ?></a>
                </div>

                <?php if (empty($analyses)): ?>
                    <div class="empty-state">
                        <h3><?php echo e(t('fair_results.empty_title')); ?></h3>
                        <p style="color: #999; margin-bottom: 20px;"><?php echo e(t('fair_results.empty_desc')); ?></p>
                        <a href="fair-analysis.php" style="background: var(--theme-button-color, #ffc211); color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none;"><?php echo e(t('fair_results.create_analysis')); ?></a>
                    </div>
                <?php else: ?>
                    <div class="results-table">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo e(t('fair_results.col_vendor')); ?></th>
                                    <th><?php echo e(t('fair_results.col_status')); ?></th>
                                    <th><?php echo e(t('fair_results.col_created')); ?></th>
                                    <th><?php echo e(t('fair_results.col_updated')); ?></th>
                                    <th><?php echo e(t('fair_results.col_actions')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analyses as $analysis): ?>
                                    <tr>
                                        <td><strong><?php echo e($analysis['vendor_name']); ?></strong></td>
                                        <td>
                                            <span class="status-badge status-<?php echo e($analysis['status']); ?>">
                                                <?php echo e(ucfirst($analysis['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($analysis['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($analysis['updated_at'])); ?></td>
                                        <td>
                                            <a href="view-result.php?id=<?php echo $analysis['id']; ?>" class="btn-small btn-view"><?php echo e(t('fair_results.view')); ?></a>
                                            <a href="fair-analysis.php?id=<?php echo $analysis['id']; ?>" class="btn-small btn-edit"><?php echo e(t('fair_results.edit')); ?></a>
                                            <form method="POST" style="display: inline;" data-confirm="Are you sure you want to delete this analysis? This action cannot be undone.">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="delete_id" value="<?php echo $analysis['id']; ?>">
                                                <button type="submit" class="btn-small btn-delete"><?php echo e(t('fair_results.delete')); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
                        <span><?php echo e(t('fair_results.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
