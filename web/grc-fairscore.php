<?php
/**
 * CSF Maturity Score Dashboard - Radar Charts & Maturity Visualization
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Comprehensive visual dashboard for CSF-aligned maturity scoring.
 * Centerpiece radar chart showing all 14 security domain scores, framework
 * compliance bars, gap analysis summary, domain score cards, and maturity target
 * vs actual comparison. Scoring aligned with NIST CSF Implementation Tiers (1-4).
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

// ACL
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');
$isContributor = hasGroup('grc_contributors');
if (!$isAdmin && !$isCyberGRC && !$isAuditor && !$isContributor) {
    http_response_code(403);
    die(t('grc-fairscore.access_denied'));
}

$uas = UnifiedAssessmentService::getInstance();

// Load assessments for dropdown
$assessments = $uas->getAssessments(null, true);
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$selectedId && !empty($assessments)) $selectedId = (int)$assessments[0]['id'];

// Load data for selected assessment
$domains = $uas->getDomains();
$domainScores = [];
$progress = null;
$frameworkCompliance = [];
$gaps = [];
$assessment = null;
$targets = [];

if ($selectedId > 0) {
    $assessment = $uas->getAssessment($selectedId);
    if ($assessment) {
        $domainScores = $uas->getDomainScores($selectedId);
        $progress = $uas->getAssessmentProgress($selectedId);
        $frameworkCompliance = $uas->calculateFrameworkCompliance($selectedId);
        $gaps = $uas->getGapAnalysis($selectedId);
        $targets = $uas->getMaturityTargets();
    }
}

// Risk heatmap data
$likelihoodNumeric = ['rare' => 1, 'unlikely' => 2, 'possible' => 3, 'likely' => 4, 'almost_certain' => 5];
$impactNumeric = ['insignificant' => 1, 'minor' => 2, 'moderate' => 3, 'major' => 4, 'catastrophic' => 5];
$heatmapData = [];
for ($l = 1; $l <= 5; $l++) { for ($i = 1; $i <= 5; $i++) { $heatmapData[$l][$i] = 0; } }
$riskRows = $db->fetchAll("SELECT likelihood, impact FROM grc_risk_register WHERE status != 'closed'");
$totalRisks = count($riskRows);
foreach ($riskRows as $rr) {
    $lNum = $likelihoodNumeric[$rr['likelihood'] ?? 'possible'] ?? 3;
    $iNum = $impactNumeric[$rr['impact'] ?? 'moderate'] ?? 3;
    $heatmapData[$lNum][$iNum]++;
}

// Compute overall stats from domain scores
$overallFairScore = 0;
$totalConforming = 0;
$totalApplicable = 0;
$totalAnswered = 0;
$totalQuestions = 0;
$gapCount = count($gaps);

if (!empty($domainScores)) {
    $weightedSum = 0;
    $weightedCount = 0;
    foreach ($domainScores as $ds) {
        $totalQuestions += (int)($ds['questions_total'] ?? 0);
        $totalAnswered += (int)($ds['questions_answered'] ?? 0);
        $totalConforming += (int)($ds['conforming_count'] ?? 0);
        $applicable = (int)($ds['questions_total'] ?? 0) - (int)($ds['not_applicable_count'] ?? 0);
        $totalApplicable += $applicable;
        if ($ds['average_score'] !== null) {
            $weightedSum += (float)$ds['average_score'] * (int)$ds['questions_answered'];
            $weightedCount += (int)$ds['questions_answered'];
        }
    }
    $overallFairScore = $weightedCount > 0 ? round($weightedSum / $weightedCount, 2) : 0;
}

// Use assessment header values if available (more authoritative)
if ($assessment && $assessment['overall_fairscore'] !== null) {
    $overallFairScore = (float)$assessment['overall_fairscore'];
}

$compliancePct = $totalApplicable > 0 ? round(($totalConforming / $totalApplicable) * 100, 1) : 0;
if ($assessment && $assessment['overall_compliance_pct'] !== null) {
    $compliancePct = (float)$assessment['overall_compliance_pct'];
}

$answeredPct = $totalQuestions > 0 ? round(($totalAnswered / $totalQuestions) * 100, 1) : 0;

// Build target lookup by domain_code
$targetLookup = [];
foreach ($targets as $t) {
    $targetLookup[$t['domain_code']] = $t;
}

// Helper: FairScore color
function fairScoreColor($score): string {
    if ($score === null || $score === 0) return '#6b7280';
    if ($score >= 3.0) return '#28a745';
    if ($score >= 2.0) return '#f59e0b';
    return '#dc3545';
}

// Helper: FairScore label
function fairScoreLabel($score): string {
    if ($score === null || $score === 0) return t('grc-fairscore.no_data');
    if ($score >= 3.5) return t('grc-fairscore.level_optimized');
    if ($score >= 3.0) return t('grc-fairscore.level_managed');
    if ($score >= 2.0) return t('grc-fairscore.level_developing');
    if ($score >= 1.0) return t('grc-fairscore.level_initial');
    return t('grc-fairscore.no_data');
}

$_page = 'grc_fairscore';
$currentPage = 'grc_fairscore';
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-fairscore.page_title')); ?></title>
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

        /* Section headers */
        .section-header { font-size: 18px; font-weight: 600; color: #333; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .section-header:first-of-type { margin-top: 0; }

        /* Assessment selector */
        .assessment-selector { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
        .assessment-selector label { font-size: 14px; font-weight: 600; color: #374151; white-space: nowrap; }
        .assessment-selector select { padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #fff; color: #374151; cursor: pointer; min-width: 320px; }
        .assessment-selector .assessment-meta { font-size: 12px; color: #6b7280; display: flex; gap: 16px; align-items: center; }
        .assessment-selector .assessment-meta .status-pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }

        /* Overview stat cards */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; position: relative; overflow: hidden; }
        .stat-card .stat-value { font-size: 32px; font-weight: 700; color: #333; line-height: 1.1; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .stat-card .stat-sub { font-size: 11px; color: #9ca3af; margin-top: 6px; }
        .stat-card.success { border-left: 4px solid #28a745; }
        .stat-card.warning { border-left: 4px solid #f59e0b; }
        .stat-card.danger { border-left: 4px solid #dc3545; }
        .stat-card.info { border-left: 4px solid #3b82f6; }
        .stat-card.neutral { border-left: 4px solid #6b7280; }
        .stat-card .fairscore-ring { position: absolute; top: 12px; right: 16px; width: 56px; height: 56px; }
        .stat-card .fairscore-ring svg { width: 56px; height: 56px; transform: rotate(-90deg); }
        .stat-card .fairscore-ring .ring-track { fill: none; stroke: #e5e7eb; stroke-width: 5; }
        .stat-card .fairscore-ring .ring-fill { fill: none; stroke-width: 5; stroke-linecap: round; }
        .stat-card .fairscore-ring .ring-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #333; }

        /* Chart layouts */
        .chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
        .chart-card h3 { margin: 0 0 16px; font-size: 15px; color: #333; font-weight: 600; }
        .chart-card canvas { max-height: 400px; }

        .chart-full { grid-column: 1 / -1; }

        /* Radar container */
        .radar-container { display: flex; align-items: center; justify-content: center; gap: 30px; }
        .radar-wrap { flex: 1; max-width: 520px; min-width: 320px; }
        .radar-legend { flex-shrink: 0; min-width: 180px; }
        .radar-legend-item { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 12px; color: #374151; }
        .radar-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .radar-legend-score { font-weight: 700; margin-left: auto; padding-left: 12px; font-size: 13px; }

        /* Domain score cards grid */
        .domain-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .domain-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; position: relative; overflow: hidden; transition: box-shadow 0.2s, transform 0.15s; }
        .domain-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .domain-card .domain-accent { position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .domain-card .domain-code { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #9ca3af; margin-top: 4px; }
        .domain-card .domain-name { font-size: 13px; font-weight: 600; color: #1f2937; margin: 4px 0 10px; line-height: 1.3; }
        .domain-card .domain-score-big { font-size: 28px; font-weight: 700; line-height: 1; }
        .domain-card .domain-score-label { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .domain-card .domain-bar { height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: 12px; overflow: hidden; }
        .domain-card .domain-bar-fill { height: 100%; border-radius: 3px; transition: width 0.8s ease; }
        .domain-card .domain-stats { display: flex; gap: 12px; margin-top: 8px; font-size: 11px; color: #6b7280; }
        .domain-card .domain-stats span { display: flex; align-items: center; gap: 3px; }
        .domain-card .domain-stats .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }

        /* Risk heatmap */
        .heatmap-wrapper { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 30px; }
        .heatmap-wrapper h3 { margin: 0 0 16px; font-size: 15px; color: #333; }
        .heatmap { display: grid; grid-template-columns: 30px repeat(5, 1fr); grid-template-rows: repeat(5, 1fr) 30px; gap: 2px; max-width: 400px; }
        .heatmap-cell { display: flex; align-items: center; justify-content: center; min-height: 55px; border-radius: 4px; font-size: 14px; font-weight: 600; color: #fff; }
        .heatmap-label { display: flex; align-items: center; justify-content: center; font-size: 10px; color: #6b7280; font-weight: 600; }
        .hc-green { background: #28a745; }
        .hc-yellow { background: #f59e0b; }
        .hc-orange { background: #f97316; }
        .hc-red { background: #dc3545; }
        .hc-darkred { background: #991b1b; }

        /* Gap analysis table */
        .gap-table { width: 100%; border-collapse: collapse; }
        .gap-table th { background: #f3f4f6; padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .gap-table td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .gap-table tr:hover td { background: #f9fafb; }
        .severity-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .severity-non_conforming { background: #fee2e2; color: #991b1b; }
        .severity-partial { background: #fef3c7; color: #92400e; }

        /* Maturity targets comparison */
        .target-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .target-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; }
        .target-card .target-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .target-card .target-domain { font-size: 13px; font-weight: 600; color: #1f2937; }
        .target-card .target-code { font-size: 10px; font-weight: 700; color: #9ca3af; letter-spacing: 1px; }
        .target-card .target-bar-wrap { position: relative; height: 24px; background: #f3f4f6; border-radius: 12px; overflow: hidden; margin-bottom: 8px; }
        .target-card .target-bar-actual { position: absolute; top: 0; left: 0; height: 100%; border-radius: 12px; transition: width 0.6s ease; }
        .target-card .target-bar-marker { position: absolute; top: -2px; width: 3px; height: 28px; background: #1f2937; border-radius: 2px; }
        .target-card .target-values { display: flex; justify-content: space-between; font-size: 12px; }
        .target-card .target-actual-val { font-weight: 700; }
        .target-card .target-target-val { color: #6b7280; }
        .target-card .target-status { font-size: 11px; margin-top: 4px; }

        /* Framework compliance bars */
        .fw-compliance-item { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .fw-compliance-item .fw-code { font-size: 12px; font-weight: 600; color: #374151; min-width: 80px; text-align: right; }
        .fw-compliance-item .fw-bar-wrap { flex: 1; height: 22px; background: #f3f4f6; border-radius: 11px; overflow: hidden; position: relative; }
        .fw-compliance-item .fw-bar-fill { height: 100%; border-radius: 11px; transition: width 0.8s ease; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px; }
        .fw-compliance-item .fw-bar-label { font-size: 11px; font-weight: 700; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .fw-compliance-item .fw-bar-label-outside { font-size: 11px; font-weight: 600; color: #6b7280; margin-left: 8px; }
        .fw-compliance-item .fw-name { font-size: 11px; color: #6b7280; min-width: 100px; }

        /* Empty state */
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state .empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state p { font-size: 15px; color: #6b7280; margin: 0 0 16px; }
        .empty-state a { display: inline-block; padding: 10px 24px; border-radius: 8px; background: var(--theme-button-color, #ff6543); color: #fff; text-decoration: none; font-size: 14px; font-weight: 500; }

        /* Print friendly */
        @media print {
            .sidebar, .top-bar, .assessment-selector { display: none !important; }
            .main-content { padding: 20px !important; }
            .chart-row { break-inside: avoid; }
        }

        @media (max-width: 1100px) {
            .chart-row { grid-template-columns: 1fr; }
            .radar-container { flex-direction: column; }
            .radar-legend { min-width: auto; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 20px !important; }
            .stat-grid { grid-template-columns: 1fr 1fr; }
            .domain-grid { grid-template-columns: 1fr; }
            .target-grid { grid-template-columns: 1fr; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-fairscore.title')); ?></h1>
            <p style="color:#6b7280;margin:0 0 20px;"><?php echo e(t('grc-fairscore.subtitle')); ?></p>

            <!-- Assessment Selector -->
            <div class="assessment-selector">
                <label for="assessmentSelect"><?php echo e(t('grc-fairscore.assessment')); ?></label>
                <select id="assessmentSelect">
                    <?php if (empty($assessments)): ?>
                    <option value=""><?php echo e(t('grc-fairscore.no_assessments')); ?></option>
                    <?php else: ?>
                    <?php foreach ($assessments as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $selectedId === (int)$a['id'] ? 'selected' : ''; ?>>
                        <?php echo e($a['assessment_ref'] . ' - ' . $a['title']); ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if ($assessment): ?>
                <div class="assessment-meta">
                    <span class="status-pill status-<?php echo e($assessment['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $assessment['status']))); ?></span>
                    <span><?php echo e(t('grc-fairscore.type', ucfirst(str_replace('_', ' ', $assessment['assessment_type'] ?? 'initial')))); ?></span>
                    <?php if (!empty($assessment['lead_auditor_name'])): ?>
                    <span><?php echo e(t('grc-fairscore.lead', $assessment['lead_auditor_name'])); ?></span>
                    <?php endif; ?>
                    <?php if ($isAdmin || $isCyberGRC): ?>
                    <a href="grc-assessment-report.php?id=<?php echo $selectedId; ?>" class="btn btn-sm btn-primary" style="padding:4px 14px;font-size:12px;border-radius:6px;text-decoration:none;"><?php echo e(t('grc-fairscore.generate_report')); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$assessment): ?>
            <!-- Empty state -->
            <div class="empty-state">
                <div class="empty-icon">&#9776;</div>
                <p><?php echo e(t('grc-fairscore.no_assessment_selected')); ?></p>
                <a href="grc-assessment.php"><?php echo e(t('grc-fairscore.go_to_assessment')); ?></a>
            </div>
            <?php else: ?>

            <!-- Overview Stat Cards -->
            <div class="stat-grid">
                <div class="stat-card <?php echo $overallFairScore >= 3 ? 'success' : ($overallFairScore >= 2 ? 'warning' : ($overallFairScore > 0 ? 'danger' : 'neutral')); ?>">
                    <div class="stat-value" style="color:<?php echo fairScoreColor($overallFairScore); ?>;"><?php echo number_format($overallFairScore, 2); ?></div>
                    <div class="stat-label"><?php echo e(t('grc-fairscore.csf_maturity_score')); ?></div>
                    <div class="stat-sub"><?php echo fairScoreLabel($overallFairScore); ?> <?php echo e(t('grc-fairscore.scale_1_4')); ?></div>
                    <div class="fairscore-ring">
                        <?php
                        $ringPct = $overallFairScore > 0 ? ($overallFairScore / 4) * 100 : 0;
                        $ringCirc = 2 * M_PI * 22;
                        $ringOffset = $ringCirc - ($ringCirc * $ringPct / 100);
                        ?>
                        <svg viewBox="0 0 56 56">
                            <circle class="ring-track" cx="28" cy="28" r="22"/>
                            <circle class="ring-fill" cx="28" cy="28" r="22" stroke="<?php echo fairScoreColor($overallFairScore); ?>" stroke-dasharray="<?php echo round($ringCirc, 2); ?>" stroke-dashoffset="<?php echo round($ringOffset, 2); ?>"/>
                        </svg>
                    </div>
                </div>
                <div class="stat-card <?php echo $compliancePct >= 80 ? 'success' : ($compliancePct >= 50 ? 'warning' : 'danger'); ?>">
                    <div class="stat-value"><?php echo number_format($compliancePct, 1); ?>%</div>
                    <div class="stat-label"><?php echo e(t('grc-fairscore.compliance_rate')); ?></div>
                    <div class="stat-sub"><?php echo $totalConforming; ?> <?php echo e(t('grc-fairscore.of')); ?> <?php echo $totalApplicable; ?> <?php echo e(t('grc-fairscore.applicable_controls')); ?></div>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($answeredPct, 1); ?>%</div>
                    <div class="stat-label"><?php echo e(t('grc-fairscore.questions_answered')); ?></div>
                    <div class="stat-sub"><?php echo $totalAnswered; ?> <?php echo e(t('grc-fairscore.of')); ?> <?php echo $totalQuestions; ?> <?php echo e(t('grc-fairscore.total')); ?></div>
                </div>
                <div class="stat-card <?php echo $gapCount > 0 ? 'danger' : 'success'; ?>">
                    <div class="stat-value"><?php echo $gapCount; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-fairscore.gaps_found')); ?></div>
                    <div class="stat-sub"><?php
                        $ncCount = 0; $pCount = 0;
                        foreach ($gaps as $g) {
                            if (($g['conformity_status'] ?? '') === 'non_conforming') $ncCount++;
                            else $pCount++;
                        }
                        echo $ncCount . t('grc-fairscore.non_conforming_sep') . $pCount . t('grc-fairscore.partial_sep');
                    ?></div>
                </div>
            </div>

            <!-- Radar Chart - Centerpiece -->
            <div class="chart-row">
                <div class="chart-card chart-full">
                    <h3><?php echo e(t('grc-fairscore.domain_maturity_radar')); ?></h3>
                    <div class="radar-container">
                        <div class="radar-wrap">
                            <canvas id="radarChart"></canvas>
                        </div>
                        <div class="radar-legend" id="radarLegend">
                            <?php foreach ($domainScores as $ds):
                                $score = $ds['average_score'] !== null ? (float)$ds['average_score'] : 0;
                                $color = fairScoreColor($score ?: null);
                            ?>
                            <div class="radar-legend-item">
                                <span class="radar-legend-dot" style="background:<?php echo $color; ?>;"></span>
                                <span><?php echo e($ds['domain_code']); ?></span>
                                <span class="radar-legend-score" style="color:<?php echo $color; ?>;"><?php echo $score > 0 ? number_format($score, 1) : '-'; ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($domainScores)): ?>
                            <div style="font-size:13px;color:#6b7280;"><?php echo e(t('grc-fairscore.no_domain_scores_yet')); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Framework Compliance + Maturity Trend -->
            <div class="chart-row">
                <div class="chart-card">
                    <h3><?php echo e(t('grc-fairscore.framework_compliance')); ?></h3>
                    <?php if (empty($frameworkCompliance)): ?>
                    <p style="color:#6b7280;font-size:13px;text-align:center;padding:30px 0;"><?php echo e(t('grc-fairscore.no_framework_mappings')); ?></p>
                    <?php else: ?>
                    <div style="padding:8px 0;">
                        <?php foreach ($frameworkCompliance as $fc):
                            $pct = $fc['compliance_pct'] !== null ? (float)$fc['compliance_pct'] : 0;
                            $barColor = $pct >= 80 ? '#28a745' : ($pct >= 50 ? '#f59e0b' : '#dc3545');
                            $showInside = $pct >= 20;
                        ?>
                        <div class="fw-compliance-item">
                            <span class="fw-code"><?php echo e($fc['framework_code']); ?></span>
                            <div class="fw-bar-wrap">
                                <div class="fw-bar-fill" style="width:<?php echo max($pct, 2); ?>%;background:<?php echo $barColor; ?>;">
                                    <?php if ($showInside): ?>
                                    <span class="fw-bar-label"><?php echo number_format($pct, 1); ?>%</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!$showInside): ?>
                            <span class="fw-bar-label-outside"><?php echo number_format($pct, 1); ?>%</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="chart-card">
                    <h3><?php echo e(t('grc-fairscore.domain_score_distribution')); ?></h3>
                    <canvas id="domainBarChart"></canvas>
                </div>
            </div>

            <!-- Domain Score Cards -->
            <h2 class="section-header"><?php echo e(t('grc-fairscore.domain_scores')); ?></h2>
            <?php if (empty($domainScores)): ?>
            <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-fairscore.no_domain_scores_calc')); ?> <a href="grc-assessment.php?id=<?php echo $selectedId; ?>" style="color:#3b82f6;"><?php echo e(t('grc-fairscore.complete_the_assessment')); ?></a> <?php echo e(t('grc-fairscore.to_generate_scores')); ?></p>
            <?php else: ?>
            <div class="domain-grid">
                <?php foreach ($domainScores as $ds):
                    $score = $ds['average_score'] !== null ? (float)$ds['average_score'] : 0;
                    $color = fairScoreColor($score ?: null);
                    $barPct = $score > 0 ? ($score / 4) * 100 : 0;
                    $conf = (int)($ds['conforming_count'] ?? 0);
                    $part = (int)($ds['partial_count'] ?? 0);
                    $nc = (int)($ds['non_conforming_count'] ?? 0);
                    $qTotal = (int)($ds['questions_total'] ?? 0);
                    $qAnswered = (int)($ds['questions_answered'] ?? 0);
                ?>
                <div class="domain-card">
                    <div class="domain-accent" style="background:<?php echo $color; ?>;"></div>
                    <div class="domain-code"><?php echo e($ds['domain_code']); ?></div>
                    <div class="domain-name"><?php echo e($ds['domain_name']); ?></div>
                    <div class="domain-score-big" style="color:<?php echo $color; ?>;"><?php echo $score > 0 ? number_format($score, 1) : '-'; ?></div>
                    <div class="domain-score-label"><?php echo fairScoreLabel($score ?: null); ?></div>
                    <div class="domain-bar">
                        <div class="domain-bar-fill" style="width:<?php echo $barPct; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="domain-stats">
                        <span><span class="dot" style="background:#28a745;"></span> <?php echo $conf; ?></span>
                        <span><span class="dot" style="background:#f59e0b;"></span> <?php echo $part; ?></span>
                        <span><span class="dot" style="background:#dc3545;"></span> <?php echo $nc; ?></span>
                        <span style="margin-left:auto;color:#9ca3af;"><?php echo $qAnswered; ?>/<?php echo $qTotal; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Risk Heatmap -->
            <?php if ($totalRisks > 0): ?>
            <h2 class="section-header"><?php echo e(t('grc-fairscore.risk_heatmap')); ?></h2>
            <div class="heatmap-wrapper">
                <h3><?php echo e(t('grc-fairscore.heatmap_heading_prefix')); ?> (<?php echo $totalRisks; ?> <?php echo e($totalRisks !== 1 ? t('grc-fairscore.active_risks') : t('grc-fairscore.active_risk')); ?>)</h3>
                <div style="text-align:left;font-size:11px;color:#6b7280;margin-bottom:4px;"><?php echo e(t('grc-fairscore.impact')); ?> &rarr;</div>
                <div class="heatmap">
                    <?php
                    function getHeatColor($l, $i) {
                        $s = $l * $i;
                        if ($s >= 20) return 'hc-darkred';
                        if ($s >= 12) return 'hc-red';
                        if ($s >= 6) return 'hc-orange';
                        if ($s >= 3) return 'hc-yellow';
                        return 'hc-green';
                    }
                    for ($l = 5; $l >= 1; $l--):
                    ?>
                    <div class="heatmap-label"><?php echo $l; ?></div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="heatmap-cell <?php echo getHeatColor($l, $i); ?>"><?php echo $heatmapData[$l][$i] > 0 ? $heatmapData[$l][$i] : ''; ?></div>
                    <?php endfor; ?>
                    <?php endfor; ?>
                    <div class="heatmap-label"></div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="heatmap-label"><?php echo $i; ?></div>
                    <?php endfor; ?>
                </div>
                <div style="text-align:left;font-size:11px;color:#6b7280;margin-top:4px;"><?php echo e(t('grc-fairscore.likelihood')); ?> &uarr;</div>
                <p style="margin:12px 0 0;font-size:12px;"><a href="grc-risks.php" style="color:#3b82f6;text-decoration:none;"><?php echo e(t('grc-fairscore.view_risk_register')); ?> &rarr;</a></p>
            </div>
            <?php endif; ?>

            <!-- Gap Analysis Summary -->
            <?php if (!empty($gaps)): ?>
            <h2 class="section-header"><?php echo e(t('grc-fairscore.gap_analysis')); ?></h2>
            <div class="chart-card" style="margin-bottom:30px;">
                <table class="gap-table">
                    <thead>
                        <tr>
                            <th><?php echo e(t('grc-fairscore.severity')); ?></th>
                            <th><?php echo e(t('grc-fairscore.domain')); ?></th>
                            <th><?php echo e(t('grc-fairscore.question_ref')); ?></th>
                            <th><?php echo e(t('grc-fairscore.finding')); ?></th>
                            <th><?php echo e(t('grc-fairscore.framework_impact')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $displayGaps = array_slice($gaps, 0, 20);
                        foreach ($displayGaps as $g):
                            $severity = $g['conformity_status'] ?? 'partial';
                            $fwImpact = [];
                            if (!empty($g['framework_impact'])) {
                                foreach ($g['framework_impact'] as $fi) {
                                    $fwImpact[] = e($fi['framework_code'] . ':' . $fi['requirement_ref']);
                                }
                            }
                        ?>
                        <tr>
                            <td><span class="severity-badge severity-<?php echo e($severity); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $severity))); ?></span></td>
                            <td style="font-weight:500;"><?php echo e($g['domain_code']); ?></td>
                            <td><a href="grc-assessment.php?id=<?php echo $selectedId; ?>&question=<?php echo (int)$g['question_id']; ?>" style="color:#3b82f6;text-decoration:none;"><?php echo e($g['question_ref']); ?></a></td>
                            <td style="max-width:300px;"><?php echo e(mb_strimwidth($g['question_text'] ?? '', 0, 120, '...')); ?></td>
                            <td style="font-size:11px;color:#6b7280;"><?php echo implode(', ', $fwImpact) ?: '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($gaps) > 20): ?>
                <p style="font-size:12px;color:#6b7280;margin:12px 0 0;text-align:center;"><?php echo e(t('grc-fairscore.showing_20_of')); ?> <?php echo count($gaps); ?> <?php echo e(t('grc-fairscore.gaps_dot')); ?> <a href="grc-assessment.php?id=<?php echo $selectedId; ?>" style="color:#3b82f6;text-decoration:none;"><?php echo e(t('grc-fairscore.view_all_in_assessment')); ?></a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Maturity Targets vs Actual -->
            <?php
            $hasTargets = false;
            foreach ($targets as $t) {
                if (!empty($t['target_score']) && $t['target_score'] > 0) { $hasTargets = true; break; }
            }
            ?>
            <?php if ($hasTargets): ?>
            <h2 class="section-header"><?php echo e(t('grc-fairscore.maturity_targets')); ?></h2>
            <div class="chart-row" style="margin-bottom:10px;">
                <div class="chart-card chart-full">
                    <h3><?php echo e(t('grc-fairscore.target_comparison_radar')); ?></h3>
                    <div style="max-width:520px;margin:0 auto;">
                        <canvas id="targetRadarChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="target-grid">
                <?php foreach ($targets as $t):
                    if (empty($t['target_score']) || $t['target_score'] <= 0) continue;
                    $targetScore = (float)$t['target_score'];
                    // Find actual score from domain scores
                    $actualScore = 0;
                    foreach ($domainScores as $ds) {
                        if ($ds['domain_code'] === $t['domain_code']) {
                            $actualScore = $ds['average_score'] !== null ? (float)$ds['average_score'] : 0;
                            break;
                        }
                    }
                    $actualPct = $targetScore > 0 ? min(($actualScore / $targetScore) * 100, 100) : 0;
                    $targetPctOf4 = ($targetScore / 4) * 100;
                    $actualPctOf4 = ($actualScore / 4) * 100;
                    $barColor = $actualScore >= $targetScore ? '#28a745' : ($actualScore >= $targetScore * 0.75 ? '#f59e0b' : '#dc3545');
                    $statusText = $actualScore >= $targetScore ? t('grc-fairscore.target_met') : t('grc-fairscore.below_target', number_format($targetScore - $actualScore, 1));
                    $statusColor = $actualScore >= $targetScore ? '#065f46' : '#991b1b';
                ?>
                <div class="target-card">
                    <div class="target-header">
                        <span class="target-domain"><?php echo e($t['domain_name']); ?></span>
                        <span class="target-code"><?php echo e($t['domain_code']); ?></span>
                    </div>
                    <div class="target-bar-wrap">
                        <div class="target-bar-actual" style="width:<?php echo $actualPctOf4; ?>%;background:<?php echo $barColor; ?>;opacity:0.7;"></div>
                        <div class="target-bar-marker" style="left:<?php echo $targetPctOf4; ?>%;" title="<?php echo e(t('grc-fairscore.target', number_format($targetScore, 1))); ?>"></div>
                    </div>
                    <div class="target-values">
                        <span class="target-actual-val" style="color:<?php echo $barColor; ?>;"><?php echo e(t('grc-fairscore.actual', number_format($actualScore, 1))); ?></span>
                        <span class="target-target-val"><?php echo e(t('grc-fairscore.target', number_format($targetScore, 1))); ?></span>
                    </div>
                    <div class="target-status" style="color:<?php echo $statusColor; ?>;"><?php echo e($statusText); ?></div>
                    <?php if (!empty($t['target_date'])): ?>
                    <div style="font-size:11px;color:#9ca3af;margin-top:2px;"><?php echo e(t('grc-fairscore.due', date('M j, Y', strtotime($t['target_date'])))); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php endif; /* end if $assessment */ ?>

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
                    <span><?php echo e(t('grc-fairscore.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Assessment selector
var assessmentSelect = document.getElementById('assessmentSelect');
if (assessmentSelect) {
    assessmentSelect.addEventListener('change', function() {
        if (this.value) {
            window.location.href = 'grc-fairscore.php?id=' + encodeURIComponent(this.value);
        }
    });
}

<?php if ($assessment && !empty($domainScores)): ?>
// --- DATA PREPARATION ---
var domainLabels = <?php echo json_encode(array_map(function($ds) { return $ds['domain_code']; }, $domainScores)); ?>;
var domainNames = <?php echo json_encode(array_map(function($ds) { return $ds['domain_name']; }, $domainScores)); ?>;
var domainValues = <?php echo json_encode(array_map(function($ds) { return $ds['average_score'] !== null ? (float)$ds['average_score'] : 0; }, $domainScores)); ?>;
var domainColors = domainValues.map(function(v) {
    if (v === 0) return '#6b7280';
    if (v >= 3.0) return '#28a745';
    if (v >= 2.0) return '#f59e0b';
    return '#dc3545';
});

// --- RADAR CHART (Centerpiece) ---
var radarCtx = document.getElementById('radarChart');
if (radarCtx) {
    var themeColor = getComputedStyle(document.documentElement).getPropertyValue('--theme-button-color').trim() || '#35a0a3';

    // Build target data if available
    var targetValues = <?php
        $tv = [];
        foreach ($domainScores as $ds) {
            $code = $ds['domain_code'];
            if (isset($targetLookup[$code]) && $targetLookup[$code]['target_score'] > 0) {
                $tv[] = (float)$targetLookup[$code]['target_score'];
            } else {
                $tv[] = null;
            }
        }
        echo json_encode($tv);
    ?>;
    var hasTargetData = targetValues.some(function(v) { return v !== null; });

    var radarDatasets = [{
        label: <?php echo json_encode(t('grc-fairscore.chart_csf_maturity')); ?>,
        data: domainValues,
        borderColor: themeColor,
        backgroundColor: themeColor + '22',
        borderWidth: 2.5,
        pointBackgroundColor: domainColors,
        pointBorderColor: domainColors,
        pointRadius: 5,
        pointHoverRadius: 7,
        fill: true
    }];

    if (hasTargetData) {
        var targetFilled = targetValues.map(function(v) { return v !== null ? v : 0; });
        radarDatasets.push({
            label: <?php echo json_encode(t('grc-fairscore.chart_target')); ?>,
            data: targetFilled,
            borderColor: '#6b728088',
            backgroundColor: '#6b728011',
            borderWidth: 1.5,
            borderDash: [6, 4],
            pointBackgroundColor: '#6b7280',
            pointBorderColor: '#6b7280',
            pointRadius: 3,
            pointHoverRadius: 5,
            fill: true
        });
    }

    new Chart(radarCtx, {
        type: 'radar',
        data: {
            labels: domainLabels,
            datasets: radarDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    beginAtZero: true,
                    min: 0,
                    max: 4,
                    ticks: {
                        stepSize: 1,
                        font: { size: 11 },
                        color: '#9ca3af',
                        backdropColor: 'transparent',
                        callback: function(val) {
                            var labels = ['', <?php echo json_encode(t('grc-fairscore.level_initial')); ?>, <?php echo json_encode(t('grc-fairscore.level_developing')); ?>, <?php echo json_encode(t('grc-fairscore.level_managed')); ?>, <?php echo json_encode(t('grc-fairscore.level_optimized')); ?>];
                            return labels[val] || val;
                        }
                    },
                    grid: {
                        color: '#e5e7eb'
                    },
                    angleLines: {
                        color: '#e5e7eb'
                    },
                    pointLabels: {
                        font: { size: 12, weight: '600' },
                        color: '#374151'
                    }
                }
            },
            plugins: {
                legend: {
                    display: hasTargetData,
                    position: 'bottom',
                    labels: { font: { size: 12 }, usePointStyle: true, padding: 20 }
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            var idx = items[0].dataIndex;
                            return domainLabels[idx] + ' - ' + domainNames[idx];
                        },
                        label: function(item) {
                            return item.dataset.label + ': ' + item.raw.toFixed(2) + ' / 4.00';
                        }
                    }
                }
            }
        }
    });
}

// --- DOMAIN BAR CHART ---
var domainBarCtx = document.getElementById('domainBarChart');
if (domainBarCtx) {
    new Chart(domainBarCtx, {
        type: 'bar',
        data: {
            labels: domainLabels,
            datasets: [{
                label: <?php echo json_encode(t('grc-fairscore.chart_domain_score')); ?>,
                data: domainValues,
                backgroundColor: domainColors.map(function(c) { return c + 'cc'; }),
                borderColor: domainColors,
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    max: 4,
                    ticks: {
                        stepSize: 1,
                        font: { size: 11 },
                        callback: function(val) {
                            var labels = ['0', <?php echo json_encode(t('grc-fairscore.axis_1_initial')); ?>, <?php echo json_encode(t('grc-fairscore.axis_2_developing')); ?>, <?php echo json_encode(t('grc-fairscore.axis_3_managed')); ?>, <?php echo json_encode(t('grc-fairscore.axis_4_optimized')); ?>];
                            return labels[val] || val;
                        }
                    },
                    grid: { color: '#f3f4f6' }
                },
                y: {
                    ticks: { font: { size: 11, weight: '500' }, color: '#374151' },
                    grid: { display: false }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            var idx = items[0].dataIndex;
                            return domainLabels[idx] + ' - ' + domainNames[idx];
                        },
                        label: function(item) {
                            return <?php echo json_encode(t('grc-fairscore.chart_score_prefix')); ?> + item.raw.toFixed(2) + ' / 4.00';
                        }
                    }
                }
            }
        }
    });
}

<?php if ($hasTargets): ?>
// --- TARGET COMPARISON RADAR ---
var targetRadarCtx = document.getElementById('targetRadarChart');
if (targetRadarCtx) {
    var tLabels = [];
    var tActuals = [];
    var tTargets = [];
    var tColors = [];

    <?php foreach ($targets as $t):
        if (empty($t['target_score']) || $t['target_score'] <= 0) continue;
        $actualScore = 0;
        foreach ($domainScores as $ds) {
            if ($ds['domain_code'] === $t['domain_code']) {
                $actualScore = $ds['average_score'] !== null ? (float)$ds['average_score'] : 0;
                break;
            }
        }
    ?>
    tLabels.push(<?php echo json_encode($t['domain_code']); ?>);
    tActuals.push(<?php echo $actualScore; ?>);
    tTargets.push(<?php echo (float)$t['target_score']; ?>);
    tColors.push(<?php echo json_encode(fairScoreColor($actualScore ?: null)); ?>);
    <?php endforeach; ?>

    if (tLabels.length > 0) {
        var tTheme = getComputedStyle(document.documentElement).getPropertyValue('--theme-button-color').trim() || '#35a0a3';
        new Chart(targetRadarCtx, {
            type: 'radar',
            data: {
                labels: tLabels,
                datasets: [
                    {
                        label: <?php echo json_encode(t('grc-fairscore.chart_actual')); ?>,
                        data: tActuals,
                        borderColor: tTheme,
                        backgroundColor: tTheme + '22',
                        borderWidth: 2.5,
                        pointBackgroundColor: tColors,
                        pointBorderColor: tColors,
                        pointRadius: 5,
                        fill: true
                    },
                    {
                        label: <?php echo json_encode(t('grc-fairscore.chart_target')); ?>,
                        data: tTargets,
                        borderColor: '#ef4444',
                        backgroundColor: '#ef444411',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#ef4444',
                        pointRadius: 4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        min: 0,
                        max: 4,
                        ticks: { stepSize: 1, font: { size: 10 }, color: '#9ca3af', backdropColor: 'transparent' },
                        grid: { color: '#e5e7eb' },
                        angleLines: { color: '#e5e7eb' },
                        pointLabels: { font: { size: 11, weight: '600' }, color: '#374151' }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { font: { size: 12 }, usePointStyle: true, padding: 20 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(item) {
                                return item.dataset.label + ': ' + item.raw.toFixed(2) + ' / 4.00';
                            }
                        }
                    }
                }
            }
        });
    }
}
<?php endif; ?>

<?php endif; /* end if assessment && domainScores */ ?>
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
