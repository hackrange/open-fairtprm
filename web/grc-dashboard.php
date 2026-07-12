<?php
/**
 * GRC Unified Compliance Engine - Dashboard
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The GRC command center. Shows compliance posture across all frameworks,
 * control implementation progress, evidence freshness, open findings,
 * policy review status, continuous monitor health, and risk register summary.
 * Basically everything an auditor, CISO, or GRC analyst needs in one glance.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();

// GRC access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die(t('grc-dashboard.access_denied'));
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

// Load GRC data
$grc = GRCService::getInstance();
$db = Database::getInstance();
$stats = $grc->getDashboardStats();
$allFrameworks = $grc->getFrameworks();
$scopes = $grc->getScopes();

// Year, Scope & Audit filters
$currentYear = (int)date('Y');
$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$filterScopeId = isset($_GET['scope']) ? (int)$_GET['scope'] : 0;
$filterAuditId = isset($_GET['audit']) ? (int)$_GET['audit'] : 0;

// Available years from audits
$availableYears = [];
try {
    $yearRows = $db->fetchAll('SELECT DISTINCT YEAR(COALESCE(planned_start, created_at)) as audit_year FROM grc_audits WHERE framework_id IS NOT NULL ORDER BY audit_year DESC');
    foreach ($yearRows as $yr) $availableYears[] = (int)$yr['audit_year'];
} catch (Exception $e) { $availableYears = [$currentYear]; }
if (!in_array($currentYear, $availableYears)) array_unshift($availableYears, $currentYear);
rsort($availableYears);

// Load audits for the selected year (and optionally scope)
$auditFilterWhere = 'a.framework_id IS NOT NULL AND YEAR(COALESCE(a.planned_start, a.created_at)) = :yr';
$auditFilterParams = [':yr' => $filterYear];
if ($filterScopeId > 0) {
    $auditFilterWhere .= ' AND a.scope_id = :sid';
    $auditFilterParams[':sid'] = $filterScopeId;
}
$availableAudits = $db->fetchAll(
    "SELECT a.id, a.audit_ref, a.title, a.framework_id, a.scope_id, a.status, f.code as framework_code, gs.name as scope_name
     FROM grc_audits a
     LEFT JOIN grc_frameworks f ON f.id = a.framework_id
     LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
     WHERE $auditFilterWhere
     ORDER BY a.planned_start DESC, a.created_at DESC",
    $auditFilterParams
);

// If a specific audit is selected, validate it exists in the filtered list
$selectedAudit = null;
if ($filterAuditId > 0) {
    foreach ($availableAudits as $aa) {
        if ((int)$aa['id'] === $filterAuditId) { $selectedAudit = $aa; break; }
    }
    if (!$selectedAudit) $filterAuditId = 0;
}

// Get framework IDs from matching audits
$auditedFrameworkIds = [];
if ($filterAuditId > 0 && $selectedAudit) {
    $auditedFrameworkIds[] = (int)$selectedAudit['framework_id'];
} else {
    foreach ($availableAudits as $aa) {
        $fid = (int)$aa['framework_id'];
        if (!in_array($fid, $auditedFrameworkIds)) $auditedFrameworkIds[] = $fid;
    }
}

// Filter frameworks: must have an audit
$frameworks = [];
foreach ($allFrameworks as $fw) {
    if (!in_array((int)$fw['id'], $auditedFrameworkIds)) continue;
    $frameworks[] = $fw;
}

// Build scope lookup
$scopeLookup = [];
foreach ($scopes as $s) $scopeLookup[$s['id']] = $s;

// Compliance status per filtered framework — AUDIT-BASED when audits are selected
$frameworkStatus = [];

// Determine which audit IDs to aggregate
$dashboardAuditIds = [];
if ($filterAuditId > 0 && $selectedAudit) {
    $dashboardAuditIds[] = (int)$selectedAudit['id'];
} else {
    foreach ($availableAudits as $aa) {
        $dashboardAuditIds[] = (int)$aa['id'];
    }
}

if (!empty($dashboardAuditIds)) {
    // Build audit-assessment-based compliance status for each framework
    foreach ($frameworks as $fw) {
        $fwId = (int)$fw['id'];

        // Get audit IDs for this framework from the filtered set
        $fwAuditIds = [];
        foreach ($availableAudits as $aa) {
            if ((int)$aa['framework_id'] === $fwId && in_array((int)$aa['id'], $dashboardAuditIds)) {
                $fwAuditIds[] = (int)$aa['id'];
            }
        }
        if (empty($fwAuditIds)) continue;

        // Use the most recent audit for this framework (first in list, already sorted DESC)
        $primaryAuditId = $fwAuditIds[0];

        // Get the audit details
        $auditInfo = null;
        foreach ($availableAudits as $aa) {
            if ((int)$aa['id'] === $primaryAuditId) { $auditInfo = $aa; break; }
        }

        // Count total requirements for this framework
        $totalReqs = (int)($db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_framework_requirements WHERE framework_id = :fid',
            [':fid' => $fwId]
        )['c'] ?? 0);

        // Count assessment statuses from grc_audit_requirement_assessments
        $assessmentStats = $db->fetchAll(
            'SELECT assessment_status, COUNT(*) as cnt
             FROM grc_audit_requirement_assessments
             WHERE audit_id = :aid
             GROUP BY assessment_status',
            [':aid' => $primaryAuditId]
        );

        $conforming = 0;
        $nonConforming = 0;
        $partial = 0;
        $notApplicable = 0;
        $notAssessed = 0;
        foreach ($assessmentStats as $as) {
            switch ($as['assessment_status']) {
                case 'conforming': $conforming = (int)$as['cnt']; break;
                case 'non_conforming': $nonConforming = (int)$as['cnt']; break;
                case 'partially_conforming': $partial = (int)$as['cnt']; break;
                case 'not_applicable': $notApplicable = (int)$as['cnt']; break;
                case 'not_assessed': $notAssessed = (int)$as['cnt']; break;
            }
        }
        $assessed = $conforming + $nonConforming + $partial;
        $totalAssessmentRows = $conforming + $nonConforming + $partial + $notApplicable + $notAssessed;
        // Requirements that have no assessment row yet
        $unassessed = $totalReqs - $totalAssessmentRows;
        $notAssessed += $unassessed;

        $applicable = $totalReqs - $notApplicable;
        $pct = $applicable > 0 ? round(($conforming / $applicable) * 100, 0) : 0;

        // Count open findings for this audit
        $openFindings = (int)($db->fetchOne(
            'SELECT COUNT(*) as c FROM grc_audit_findings WHERE audit_id = :aid AND status IN ("open","in_remediation")',
            [':aid' => $primaryAuditId]
        )['c'] ?? 0);

        $frameworkStatus[] = [
            'framework' => $fw,
            'audit' => $auditInfo,
            'audit_id' => $primaryAuditId,
            'total_requirements' => $totalReqs,
            'conforming' => $conforming,
            'non_conforming' => $nonConforming,
            'partial' => $partial,
            'not_applicable' => $notApplicable,
            'not_assessed' => $notAssessed,
            'compliance_percentage' => $pct,
            'open_findings' => $openFindings,
            'is_audit_based' => true,
        ];
    }
} else {
    // No audits — fall back to generic control-based compliance
    foreach ($frameworks as $fw) {
        $status = $grc->getFrameworkComplianceStatus((int)$fw['id']);
        if ($status) {
            $status['is_audit_based'] = false;
        }
        $frameworkStatus[] = $status;
    }
}

// Recent monitor results
$cm = ContinuousMonitor::getInstance();
$monitors = $cm->getMonitors(true);

// Policy acknowledgment stats
$policyService = PolicyService::getInstance();

$currentPage = 'grc_dashboard';
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-dashboard.page_title')); ?></title>
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

        /* Filter bar */
        .filter-bar { display: flex; align-items: center; gap: 20px; margin-bottom: 28px; flex-wrap: wrap; }
        .year-pills { display: flex; gap: 6px; align-items: center; }
        .year-pill { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; border: 1px solid #d1d5db; background: #fff; color: #374151; cursor: pointer; text-decoration: none; transition: all 0.15s; }
        .year-pill:hover { background: #f3f4f6; border-color: #9ca3af; }
        .year-pill.active { background: var(--theme-button-color, #35a0a3); color: #fff; border-color: var(--theme-button-color, #35a0a3); }
        .scope-filter-wrap { display: flex; align-items: center; gap: 8px; }
        .scope-filter-wrap label { font-size: 13px; font-weight: 500; color: #374151; white-space: nowrap; }
        .scope-filter-wrap select { padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 20px; font-size: 13px; background: #fff; color: #374151; cursor: pointer; }

        /* Dashboard cards */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: #333; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .stat-card.success { border-left: 4px solid #28a745; }
        .stat-card.warning { border-left: 4px solid #f59e0b; }
        .stat-card.danger { border-left: 4px solid #dc3545; }
        .stat-card.info { border-left: 4px solid #3b82f6; }

        /* Framework compliance cards — donut style */
        .framework-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; margin-bottom: 30px; }
        .fw-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; transition: box-shadow 0.2s, transform 0.15s; }
        .fw-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .fw-card-link { display: block; text-decoration: none; color: inherit; padding: 22px 22px 0; }
        .fw-card-top { display: flex; align-items: flex-start; gap: 16px; }
        .fw-donut { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
        .fw-donut svg { width: 64px; height: 64px; transform: rotate(-90deg); }
        .fw-donut .donut-track { fill: none; stroke: #e5e7eb; stroke-width: 6; }
        .fw-donut .donut-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.8s ease; }
        .fw-donut .donut-pct { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #333; }
        .fw-card-info { flex: 1; min-width: 0; }
        .fw-card-code { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .fw-card-name { font-size: 15px; font-weight: 600; color: #1f2937; margin: 4px 0 0; line-height: 1.3; }
        .fw-card-scope { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 10px; color: #fff; margin-top: 6px; }
        .fw-card-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border-top: 1px solid #f3f4f6; margin-top: 16px; }
        .fw-metric { text-align: center; padding: 12px 4px; border-right: 1px solid #f3f4f6; }
        .fw-metric:last-child { border-right: none; }
        .fw-metric-val { font-size: 16px; font-weight: 700; color: #111; }
        .fw-metric-val.green { color: #059669; }
        .fw-metric-val.amber { color: #d97706; }
        .fw-metric-val.red { color: #dc2626; }
        .fw-metric-val.gray { color: #6b7280; }
        .fw-metric-lbl { font-size: 9px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }

        /* Section headers */
        .section-header { font-size: 18px; font-weight: 600; color: #333; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .section-header:first-of-type { margin-top: 0; }

        /* Monitor status */
        .monitor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .monitor-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; display: flex; align-items: center; gap: 12px; }
        .monitor-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .monitor-dot.pass { background: #28a745; }
        .monitor-dot.fail { background: #dc3545; }
        .monitor-dot.warning { background: #f59e0b; }
        .monitor-dot.error { background: #dc3545; }
        .monitor-dot.not_run { background: #9ca3af; }
        .monitor-info { flex: 1; }
        .monitor-name { font-size: 13px; font-weight: 500; color: #333; }
        .monitor-detail { font-size: 11px; color: #6b7280; margin-top: 2px; }

        /* Chart containers */
        .chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
        .chart-card h3 { margin: 0 0 16px; font-size: 15px; color: #333; }
        .chart-card canvas { max-height: 300px; }

        @media (max-width: 900px) {
            .chart-row { grid-template-columns: 1fr; }
            .framework-grid { grid-template-columns: 1fr; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-dashboard.title')); ?></h1>
            <p style="color:#6b7280;margin:0 0 20px;"><?php echo e(t('grc-dashboard.subtitle')); ?></p>

            <!-- Year, Scope & Audit Filters -->
            <div class="filter-bar">
                <div class="year-pills">
                    <?php foreach ($availableYears as $yr):
                        $params = ['year' => $yr];
                        if ($filterScopeId > 0) $params['scope'] = $filterScopeId;
                    ?>
                    <a href="grc-dashboard.php?<?php echo http_build_query($params); ?>" class="year-pill <?php echo $filterYear === $yr ? 'active' : ''; ?>"><?php echo $yr; ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($scopes)): ?>
                <div class="scope-filter-wrap">
                    <label><?php echo e(t('grc-dashboard.scope')); ?></label>
                    <select id="scopeFilter" data-year="<?php echo $filterYear; ?>">
                        <option value=""><?php echo e(t('grc-dashboard.all_scopes')); ?></option>
                        <?php foreach ($scopes as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>" <?php echo $filterScopeId === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($availableAudits)): ?>
                <div class="scope-filter-wrap">
                    <label><?php echo e(t('grc-dashboard.audit')); ?></label>
                    <select id="auditFilter" data-year="<?php echo $filterYear; ?>" data-scope="<?php echo $filterScopeId; ?>">
                        <option value=""><?php echo e(t('grc-dashboard.all_audits')); ?></option>
                        <?php foreach ($availableAudits as $aa): ?>
                        <option value="<?php echo (int)$aa['id']; ?>" <?php echo $filterAuditId === (int)$aa['id'] ? 'selected' : ''; ?>><?php echo e($aa['audit_ref'] . ' - ' . $aa['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <span style="font-size:12px;color:#9ca3af;margin-left:auto;"><?php echo count($frameworks); ?> <?php echo e(t('grc-dashboard.framework_word')); ?><?php echo count($frameworks) !== 1 ? 's' : ''; ?></span>
            </div>

            <!-- Overview Stats -->
            <div class="stat-grid">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo $stats['controls_total']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.internal_controls')); ?></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['evidence_current']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.current_evidence')); ?></div>
                </div>
                <div class="stat-card <?php echo $stats['evidence_expiring_30d'] > 0 ? 'warning' : 'success'; ?>">
                    <div class="stat-value"><?php echo $stats['evidence_expiring_30d']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.evidence_expiring')); ?></div>
                </div>
                <div class="stat-card <?php echo $stats['open_findings'] > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-value"><?php echo $stats['open_findings']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.open_findings')); ?></div>
                </div>
                <div class="stat-card <?php echo $stats['policies_due_review'] > 0 ? 'warning' : 'success'; ?>">
                    <div class="stat-value"><?php echo $stats['policies_due_review']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.policies_due_review')); ?></div>
                </div>
                <div class="stat-card <?php echo $stats['overdue_remediation'] > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-value"><?php echo $stats['overdue_remediation']; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-dashboard.overdue_remediation')); ?></div>
                </div>
            </div>

            <!-- Framework Compliance -->
            <h2 class="section-header"><?php echo e(t('grc-dashboard.framework_readiness')); ?></h2>
            <?php if (empty($frameworkStatus)): ?>
            <div style="text-align:center;padding:40px 20px;color:#6b7280;">
                <p style="font-size:15px;margin:0;"><?php echo e(t('grc-dashboard.no_audited_prefix')); ?> <?php echo (int)$filterYear; ?><?php echo $filterScopeId > 0 ? e(t('grc-dashboard.in_this_scope')) : ''; ?><?php echo e(t('grc-dashboard.no_audited_suffix')); ?></p>
            </div>
            <?php else: ?>
            <div class="framework-grid">
                <?php foreach ($frameworkStatus as $fs): if (empty($fs)) continue;
                    $fw = $fs['framework'];
                    $pct = $fs['compliance_percentage'];
                    $color = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
                    $circumference = 2 * M_PI * 26;
                    $dashOffset = $circumference - ($circumference * $pct / 100);
                    $isAuditBased = !empty($fs['is_audit_based']);
                    // Scope: from audit data or framework
                    $tileScope = null;
                    if ($isAuditBased && !empty($fs['audit']['scope_id'])) {
                        $tileScope = $scopeLookup[(int)$fs['audit']['scope_id']] ?? null;
                    } elseif (!empty($fw['scope_id'])) {
                        $tileScope = $scopeLookup[$fw['scope_id']] ?? null;
                    }
                    // Link destination
                    $tileLink = $isAuditBased
                        ? 'grc-audits.php?view=' . (int)$fs['audit_id']
                        : 'grc-frameworks.php?framework_id=' . (int)$fw['id'];
                ?>
                <a href="<?php echo $tileLink; ?>" class="fw-card" style="text-decoration:none;color:inherit;">
                    <div class="fw-card-link" style="padding:22px 22px 0;">
                        <div class="fw-card-top">
                            <div class="fw-donut">
                                <svg viewBox="0 0 64 64">
                                    <circle class="donut-track" cx="32" cy="32" r="26"/>
                                    <circle class="donut-fill" cx="32" cy="32" r="26" stroke="<?php echo $color; ?>" stroke-dasharray="<?php echo round($circumference, 2); ?>" stroke-dashoffset="<?php echo round($dashOffset, 2); ?>"/>
                                </svg>
                                <span class="donut-pct"><?php echo $pct; ?>%</span>
                            </div>
                            <div class="fw-card-info">
                                <div class="fw-card-code"><?php echo e($fw['code']); ?> <?php echo e($fw['version'] ?? ''); ?></div>
                                <div class="fw-card-name"><?php echo e($fw['name']); ?></div>
                                <?php if ($isAuditBased && !empty($fs['audit'])): ?>
                                <div style="font-size:11px;color:#6b7280;margin-top:3px;"><?php echo e($fs['audit']['audit_ref'] . ' — ' . $fs['audit']['title']); ?></div>
                                <?php endif; ?>
                                <?php if ($tileScope): ?>
                                <span class="fw-card-scope" style="background:<?php echo e($tileScope['color'] ?? '#6b7280'); ?>;"><?php echo e($tileScope['name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($isAuditBased): ?>
                    <div class="fw-card-metrics">
                        <div class="fw-metric">
                            <div class="fw-metric-val green"><?php echo $fs['conforming']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.conforming')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val amber"><?php echo $fs['partial']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.partial')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val <?php echo $fs['non_conforming'] > 0 ? 'red' : 'gray'; ?>"><?php echo $fs['non_conforming']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.non_conform')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val gray"><?php echo $fs['not_assessed']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.not_assessed')); ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="fw-card-metrics">
                        <div class="fw-metric">
                            <div class="fw-metric-val green"><?php echo $fs['implemented']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.ready')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val amber"><?php echo $fs['partial']; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.partial')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val gray"><?php echo ($fs['in_progress'] ?? 0) + ($fs['planned'] ?? 0); ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.in_progress')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val <?php echo ($fs['no_control'] ?? 0) > 0 ? 'red' : 'gray'; ?>"><?php echo $fs['no_control'] ?? 0; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc-dashboard.unmapped')); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Charts -->
            <div class="chart-row">
                <div class="chart-card">
                    <h3><?php echo e(t('grc-dashboard.control_impl_status')); ?></h3>
                    <canvas id="controlStatusChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3><?php echo e(t('grc-dashboard.framework_compliance_overview')); ?></h3>
                    <canvas id="frameworkChart"></canvas>
                </div>
            </div>

            <!-- Continuous Monitors -->
            <?php if (!empty($monitors)): ?>
            <h2 class="section-header"><?php echo e(t('grc-dashboard.monitor_health')); ?></h2>
            <div class="monitor-grid">
                <?php foreach ($monitors as $mon): ?>
                <div class="monitor-card">
                    <div class="monitor-dot <?php echo e($mon['last_result']); ?>"></div>
                    <div class="monitor-info">
                        <div class="monitor-name"><?php echo e($mon['name']); ?></div>
                        <div class="monitor-detail">
                            <?php echo e(ucfirst($mon['last_result'])); ?>
                            <?php if ($mon['last_run_at']): ?>
                            &middot; <?php echo e(date('M j, g:i A', strtotime($mon['last_run_at']))); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
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
                    <span><?php echo e(t('grc-dashboard.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Control Implementation Status - Doughnut Chart
var controlData = <?php echo json_encode($stats['controls_by_status']); ?>;
var labels = controlData.map(function(d) { return d.implementation_status.replace('_', ' ').replace(/\b\w/g, function(l){return l.toUpperCase();}); });
var values = controlData.map(function(d) { return parseInt(d.count); });
var colors = controlData.map(function(d) {
    switch(d.implementation_status) {
        case 'implemented': return '#28a745';
        case 'in_progress': return '#3b82f6';
        case 'planned': return '#f59e0b';
        case 'not_applicable': return '#9ca3af';
        default: return '#e5e7eb';
    }
});

new Chart(document.getElementById('controlStatusChart'), {
    type: 'doughnut',
    data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 12 } } } } }
});

// Framework Compliance - Horizontal Bar Chart (clickable to audit)
var fwData = <?php echo json_encode(array_values(array_filter(array_map(function($fs) {
    if (!$fs) return null;
    return [
        'code' => $fs['framework']['code'],
        'pct' => $fs['compliance_percentage'],
        'link' => !empty($fs['is_audit_based']) ? 'grc-audits.php?view=' . (int)$fs['audit_id'] : 'grc-frameworks.php?framework_id=' . (int)$fs['framework']['id']
    ];
}, $frameworkStatus)))); ?>;
var fwLabels = fwData.map(function(d) { return d.code; });
var fwValues = fwData.map(function(d) { return d.pct; });
var fwColors = fwValues.map(function(v) { return v >= 80 ? '#28a745' : (v >= 50 ? '#f59e0b' : '#dc3545'); });

// Scope filter dropdown
var scopeEl = document.getElementById('scopeFilter');
if (scopeEl) {
    scopeEl.addEventListener('change', function() {
        var yr = this.getAttribute('data-year');
        var p = 'grc-dashboard.php?year=' + yr;
        if (this.value) p += '&scope=' + this.value;
        window.location.href = p;
    });
}

// Audit filter dropdown
var auditEl = document.getElementById('auditFilter');
if (auditEl) {
    auditEl.addEventListener('change', function() {
        var yr = this.getAttribute('data-year');
        var scopeId = this.getAttribute('data-scope');
        var p = 'grc-dashboard.php?year=' + yr;
        if (scopeId && scopeId !== '0') p += '&scope=' + scopeId;
        if (this.value) p += '&audit=' + this.value;
        window.location.href = p;
    });
}

var fwChart = new Chart(document.getElementById('frameworkChart'), {
    type: 'bar',
    data: {
        labels: fwLabels,
        datasets: [{ label: <?php echo json_encode(t('grc-dashboard.chart_compliance_pct')); ?>, data: fwValues, backgroundColor: fwColors, borderWidth: 0, borderRadius: 6 }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        scales: { x: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } } },
        plugins: { legend: { display: false } },
        onClick: function(e) {
            var points = fwChart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true);
            if (points.length > 0) {
                var idx = points[0].index;
                if (fwData[idx] && fwData[idx].link) {
                    window.location.href = fwData[idx].link;
                }
            }
        },
        onHover: function(e, elements) {
            e.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
        }
    }
});
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
