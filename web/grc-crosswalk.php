<?php
/**
 * GRC Unified Compliance Engine - Framework Crosswalk
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The crosswalk view shows how controls shared between frameworks satisfy
 * requirements in both. Select source and target frameworks, view the
 * crosswalk table, and visualize coverage gaps. This is the "Apptega"
 * feature -- complete SOC 2 and you already have partial ISO 27001 coverage.
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
    die(t('grc-crosswalk.access_denied'));
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();

// Generate CSRF token for GET requests (POST handler above generates its own after validation)
if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Load frameworks
$frameworks = $grc->getFrameworks();

// Selected frameworks
$sourceId = isset($_GET['source']) ? (int)$_GET['source'] : 0;
$targetId = isset($_GET['target']) ? (int)$_GET['target'] : 0;

$crosswalk = [];
$sourceFramework = null;
$targetFramework = null;
$targetRequirements = [];
$coveredTargetRefs = [];
$uncoveredTargetRefs = [];

if ($sourceId > 0 && $targetId > 0 && $sourceId !== $targetId) {
    $sourceFramework = $grc->getFramework($sourceId);
    $targetFramework = $grc->getFramework($targetId);

    if ($sourceFramework && $targetFramework) {
        $crosswalk = $grc->getCrosswalk($sourceId, $targetId);

        // Get all target requirements for gap analysis
        $targetRequirements = $grc->getAllRequirements($targetId);

        // Find which target requirements are covered
        foreach ($crosswalk as $cw) {
            $coveredTargetRefs[$cw['target_ref']] = true;
        }

        // Find uncovered target requirements
        foreach ($targetRequirements as $tr) {
            if (!isset($coveredTargetRefs[$tr['requirement_ref']])) {
                $uncoveredTargetRefs[] = $tr;
            }
        }
    }
}

$currentPage = 'grc_crosswalk';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-crosswalk.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <script src="app/js/chart.min.js"></script>
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

        .selector-bar { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px; display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
        .selector-bar label { display: block; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .selector-bar select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 200px; }

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize; }
        .status-implemented { background: #d1fae5; color: #065f46; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-planned { background: #fef3c7; color: #92400e; }
        .status-not_applicable { background: #f3f4f6; color: #6b7280; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .stat-card.success { border-left: 4px solid #28a745; }
        .stat-card.warning { border-left: 4px solid #f59e0b; }
        .stat-card.danger { border-left: 4px solid #dc3545; }
        .stat-card.info { border-left: 4px solid #3b82f6; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }

        .chart-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 24px; max-width: 400px; }
        .chart-card h3 { margin: 0 0 16px; font-size: 15px; color: #333; }
        .chart-card canvas { max-height: 250px; }

        .gap-item { padding: 8px 16px; border-left: 3px solid #dc3545; margin-bottom: 6px; background: #fff; border-radius: 0 6px 6px 0; font-size: 13px; }
        .gap-item .gap-ref { font-weight: 600; color: #333; }
        .gap-item .gap-title { color: #6b7280; margin-left: 8px; }

        @media (max-width: 900px) {
            .selector-bar { flex-direction: column; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-crosswalk.framework_crosswalk')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc-crosswalk.intro')); ?></p>

            <!-- Framework Selector -->
            <form method="get" class="selector-bar">
                <div>
                    <label for="source"><?php echo e(t('grc-crosswalk.source_framework')); ?></label>
                    <select id="source" name="source">
                        <option value=""><?php echo e(t('grc-crosswalk.select_source')); ?></option>
                        <?php foreach ($frameworks as $fw): ?>
                        <option value="<?php echo (int)$fw['id']; ?>" <?php echo $sourceId === (int)$fw['id'] ? 'selected' : ''; ?>><?php echo e($fw['code']); ?> - <?php echo e($fw['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="font-size:20px;color:#6b7280;padding-bottom:8px;">&rarr;</div>
                <div>
                    <label for="target"><?php echo e(t('grc-crosswalk.target_framework')); ?></label>
                    <select id="target" name="target">
                        <option value=""><?php echo e(t('grc-crosswalk.select_target')); ?></option>
                        <?php foreach ($frameworks as $fw): ?>
                        <option value="<?php echo (int)$fw['id']; ?>" <?php echo $targetId === (int)$fw['id'] ? 'selected' : ''; ?>><?php echo e($fw['code']); ?> - <?php echo e($fw['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo e(t('grc-crosswalk.generate_crosswalk')); ?></button>
            </form>

            <?php if ($sourceFramework && $targetFramework): ?>
            <!-- Coverage Stats -->
            <?php
            $totalTargetReqs = count($targetRequirements);
            $coveredCount = count($coveredTargetRefs);
            $gapCount = count($uncoveredTargetRefs);
            $coveragePct = $totalTargetReqs > 0 ? round(($coveredCount / $totalTargetReqs) * 100, 1) : 0;
            $implementedInCrosswalk = 0;
            foreach ($crosswalk as $cw) {
                if ($cw['implementation_status'] === 'implemented') $implementedInCrosswalk++;
            }
            ?>
            <div class="stat-grid">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo count($crosswalk); ?></div>
                    <div class="stat-label"><?php echo e(t('grc-crosswalk.crosswalk_mappings')); ?></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $coveredCount; ?></div>
                    <div class="stat-label"><?php echo e($targetFramework['code']); ?> <?php echo e(t('grc-crosswalk.requirements_covered')); ?></div>
                </div>
                <div class="stat-card <?php echo $gapCount > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-value"><?php echo $gapCount; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-crosswalk.coverage_gaps')); ?></div>
                </div>
                <div class="stat-card <?php echo $coveragePct >= 80 ? 'success' : ($coveragePct >= 50 ? 'warning' : 'danger'); ?>">
                    <div class="stat-value"><?php echo $coveragePct; ?>%</div>
                    <div class="stat-label"><?php echo e(t('grc-crosswalk.target_coverage')); ?></div>
                </div>
            </div>

            <!-- Coverage Chart -->
            <div class="chart-card">
                <h3><?php echo e($targetFramework['code']); ?> <?php echo e(t('grc-crosswalk.coverage_from')); ?> <?php echo e($sourceFramework['code']); ?></h3>
                <canvas id="coverageChart"></canvas>
            </div>

            <!-- Crosswalk Table -->
            <h2 class="section-header"><?php echo e(t('grc-crosswalk.crosswalk_label')); ?> <?php echo e($sourceFramework['code']); ?> &rarr; <?php echo e($targetFramework['code']); ?></h2>
            <?php if (!empty($crosswalk)): ?>
            <table class="grc-table">
                <thead>
                    <tr>
                        <th><?php echo e($sourceFramework['code']); ?> <?php echo e(t('grc-crosswalk.req')); ?></th>
                        <th><?php echo e(t('grc-crosswalk.source_title')); ?></th>
                        <th><?php echo e(t('grc-crosswalk.control')); ?></th>
                        <th><?php echo e(t('grc-crosswalk.status')); ?></th>
                        <th><?php echo e($targetFramework['code']); ?> <?php echo e(t('grc-crosswalk.req')); ?></th>
                        <th><?php echo e(t('grc-crosswalk.target_title')); ?></th>
                        <th><?php echo e(t('grc-crosswalk.coverage')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($crosswalk as $cw): ?>
                    <tr>
                        <td style="font-weight:500;"><?php echo e($cw['source_ref']); ?></td>
                        <td style="font-size:12px;"><?php echo e($cw['source_title']); ?></td>
                        <td><span style="font-weight:500;"><?php echo e($cw['control_ref']); ?></span><br><span style="font-size:11px;color:#6b7280;"><?php echo e($cw['control_title']); ?></span></td>
                        <td><span class="status-badge status-<?php echo e($cw['implementation_status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $cw['implementation_status']))); ?></span></td>
                        <td style="font-weight:500;"><?php echo e($cw['target_ref']); ?></td>
                        <td style="font-size:12px;"><?php echo e($cw['target_title']); ?></td>
                        <td><?php echo e(ucfirst($cw['target_coverage'] ?? 'full')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:40px;text-align:center;color:#6b7280;">
                <?php echo e(t('grc-crosswalk.no_shared_controls')); ?>
            </div>
            <?php endif; ?>

            <!-- Coverage Gaps -->
            <?php if (!empty($uncoveredTargetRefs)): ?>
            <h2 class="section-header"><?php echo e(t('grc-crosswalk.coverage_gaps_in')); ?> <?php echo e($targetFramework['code']); ?></h2>
            <p style="color:#6b7280;font-size:13px;margin-bottom:16px;"><?php echo e(t('grc-crosswalk.gaps_desc_prefix')); ?> <?php echo e($targetFramework['code']); ?> <?php echo e(t('grc-crosswalk.gaps_desc_middle')); ?> <?php echo e($sourceFramework['code']); ?>.</p>
            <div style="max-height:400px;overflow-y:auto;">
                <?php foreach ($uncoveredTargetRefs as $gap): ?>
                <div class="gap-item">
                    <span class="gap-ref"><?php echo e($gap['requirement_ref']); ?></span>
                    <span class="gap-title"><?php echo e($gap['title']); ?></span>
                    <?php if ((int)$gap['mapped_controls'] > 0): ?>
                    <span style="font-size:11px;color:#3b82f6;margin-left:8px;">(<?php echo (int)$gap['mapped_controls']; ?> <?php echo e(t('grc-crosswalk.controls_mapped_not_shared')); ?>)</span>
                    <?php else: ?>
                    <span style="font-size:11px;color:#dc3545;margin-left:8px;">(<?php echo e(t('grc-crosswalk.no_controls_mapped')); ?>)</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($sourceId > 0 || $targetId > 0): ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:40px;text-align:center;color:#6b7280;">
                <?php if ($sourceId === $targetId): ?>
                <?php echo e(t('grc-crosswalk.select_two_different')); ?>
                <?php else: ?>
                <?php echo e(t('grc-crosswalk.select_both')); ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:40px;text-align:center;color:#6b7280;">
                <p style="font-size:15px;margin:0 0 8px;"><?php echo e(t('grc-crosswalk.select_two_above')); ?></p>
                <p style="font-size:13px;margin:0;"><?php echo e(t('grc-crosswalk.crosswalk_explainer')); ?></p>
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
                    <span><?php echo e(t('grc-crosswalk.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<?php if ($sourceFramework && $targetFramework): ?>
<script nonce="<?php echo cspNonce(); ?>">
new Chart(document.getElementById('coverageChart'), {
    type: 'doughnut',
    data: {
        labels: [<?php echo json_encode(t('grc-crosswalk.chart_covered')); ?>, <?php echo json_encode(t('grc-crosswalk.chart_gaps')); ?>],
        datasets: [{
            data: [<?php echo $coveredCount; ?>, <?php echo $gapCount; ?>],
            backgroundColor: ['#28a745', '#dc3545'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 12 } } }
        }
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
