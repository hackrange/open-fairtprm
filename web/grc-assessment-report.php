<?php
/**
 * Cyber Report - Report Editor
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Editable report with section editing. Admin/cyber_grc users can edit
 * narrative sections with AI assistant support (propose language, rewrite).
 * Generates a professional PDF via dompdf on save.
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
    die(t('grc-assessment-report.access_denied'));
}

$canEdit = $isAdmin || $isCyberGRC;
$uas = UnifiedAssessmentService::getInstance();
$aiEnabled = AIPlatformService::getInstance()->isEnabled();

// Load assessment
$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header('Location: grc-fairscore.php');
    exit;
}

$assessment = $uas->getAssessment($assessmentId);
if (!$assessment || $assessment['status'] === 'archived') {
    header('Location: grc-fairscore.php');
    exit;
}

// Load company name
$orgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
$orgName = ($orgRow && !empty($orgRow['config_value'])) ? $orgRow['config_value'] : 'Organization';

// Load data
$progress = $uas->getAssessmentProgress($assessmentId);
$domainScores = $uas->getDomainScores($assessmentId);
$frameworkCompliance = $uas->calculateFrameworkCompliance($assessmentId);
$gaps = $uas->getGapAnalysis($assessmentId);

// Stats
$totalQuestions = (int)($progress['total_questions'] ?? 0);
$answered = (int)($progress['answered'] ?? 0);
$overallScore = number_format((float)($assessment['overall_fairscore'] ?? 0), 1);
$compliancePct = (int)($assessment['overall_compliance_pct'] ?? 0);
$gapCount = count($gaps);

// Has existing report?
$hasReport = false;
try {
    $rptCheck = $db->fetchOne('SELECT report_file_name FROM grc_assessments WHERE id = :id', [':id' => $assessmentId]);
    $hasReport = !empty($rptCheck['report_file_name']);
} catch (Exception $e) { /* column may not exist yet */ }

// Score helpers
function rptScoreColor(float $s): string {
    if ($s >= 3.5) return '#059669';
    if ($s >= 2.5) return '#3b82f6';
    if ($s >= 1.5) return '#f59e0b';
    return '#dc3545';
}
function rptScoreTier(float $s): string {
    if ($s >= 3.5) return t('grc-assessment-report.tier_adaptive');
    if ($s >= 2.5) return t('grc-assessment-report.tier_repeatable');
    if ($s >= 1.5) return t('grc-assessment-report.tier_risk_informed');
    if ($s >= 0.5) return t('grc-assessment-report.tier_partial');
    return t('grc-assessment-report.tier_not_assessed');
}

// Default section texts
$defaultExecSummary = $orgName . ' conducted a comprehensive cybersecurity maturity assessment covering '
    . $totalQuestions . ' unified security questions across 14 security domains. '
    . 'The assessment maps to all major compliance frameworks including SOC 2, ISO 27001, NIST CSF 2.0, '
    . 'CMMC/NIST 800-171, PCI DSS v4.0.1, HIPAA, CIS Controls v8, and SOX.' . "\n\n"
    . 'The overall CSF Maturity Score is ' . $overallScore . '/4.0 (' . rptScoreTier((float)$overallScore)
    . '), with a composite compliance rate of ' . $compliancePct . '%.';

$defaultScope = $assessment['scope'] ?? 'This assessment covers the full organizational scope including all information systems, network infrastructure, personnel, and third-party service providers.';

$defaultMethodology = 'The assessment utilizes the FairTPRM Unified Cybersecurity Assessment Engine, which consolidates questions from 9 major cybersecurity frameworks into 146 unique, non-duplicating questions across 14 security domains. Each question is scored on a 1-4 maturity scale aligned with NIST CSF 2.0 Implementation Tiers.' . "\n\n"
    . 'Tier 1 (Partial): Ad hoc, reactive practices with limited awareness.' . "\n"
    . 'Tier 2 (Risk-Informed): Practices are informed by risk but not organization-wide.' . "\n"
    . 'Tier 3 (Repeatable): Policies and procedures are established and consistently applied.' . "\n"
    . 'Tier 4 (Adaptive): Advanced practices with continuous improvement and adaptation.';

$defaultRecommendations = '';
if (!empty($domainScores)) {
    $weak = array_filter($domainScores, function($d) {
        return ((float)($d['average_score'] ?? $d['avg_score'] ?? $d['score'] ?? 0)) < 3.0;
    });
    usort($weak, function($a, $b) {
        return ($a['average_score'] ?? $a['avg_score'] ?? $a['score'] ?? 0) <=> ($b['average_score'] ?? $b['avg_score'] ?? $b['score'] ?? 0);
    });
    if (!empty($weak)) {
        $lines = [];
        foreach (array_slice($weak, 0, 5) as $w) {
            $ws = (float)($w['average_score'] ?? $w['avg_score'] ?? $w['score'] ?? 0);
            $dn = $w['domain_code'] ?? '';
            $lines[] = '- ' . $dn . ' (' . number_format($ws, 1) . '): Focus on improving ' . ($w['domain_name'] ?? 'this domain') . ' practices.';
        }
        $defaultRecommendations = "Priority areas for improvement:\n\n" . implode("\n", $lines);
    }
}

$defaultConclusion = 'This Cyber Report provides a comprehensive snapshot of ' . $orgName
    . '\'s current cybersecurity posture. The findings and recommendations should be reviewed by '
    . 'relevant stakeholders and incorporated into the organization\'s cybersecurity improvement roadmap.';

$_page = 'grc_assessment_report';
$currentPage = 'grc_assessment_report';
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e($orgName); ?> <?php echo e(t('grc-assessment-report.title_suffix')); ?></title>
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
        .main-content { flex: 1 1 auto; overflow-y: auto; padding: 30px; background: #f9fafb; min-width: 0; }
        .btn { display: inline-block; padding: 8px 18px; font-size: 14px; font-weight: 500; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; line-height: 1.4; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        .btn-xs { padding: 3px 10px; font-size: 11px; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #059669; color: #fff; }
        .btn-success:hover { background: #047857; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f9fafb; }
        .btn-danger { background: #dc3545; color: #fff; }

        /* Report Editor Styles */
        .report-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .report-header h1 { font-size: 22px; font-weight: 700; color: #1e3a5f; margin: 0; }
        .report-header .report-meta { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .report-actions { display: flex; gap: 8px; align-items: center; }

        .section-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
        .section-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; cursor: pointer; }
        .section-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: #1e3a5f; }
        .section-header .section-type { font-size: 11px; padding: 2px 10px; border-radius: 10px; font-weight: 500; }
        .type-editable { background: #dbeafe; color: #1e40af; }
        .type-data { background: #f3f4f6; color: #6b7280; }
        .section-body { padding: 20px; display: none; }
        .section-body.open { display: block; }
        .section-textarea { width: 100%; min-height: 140px; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; line-height: 1.6; font-family: inherit; resize: vertical; }
        .section-textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .section-textarea:read-only { background: #f9fafb; color: #6b7280; cursor: not-allowed; }

        .section-toolbar { display: flex; gap: 6px; margin-top: 10px; align-items: center; }
        .btn-assist { padding: 4px 12px; font-size: 11px; border: 1px solid #8b5cf6; background: #f5f3ff; color: #6d28d9; border-radius: 4px; cursor: pointer; }
        .btn-assist:hover { background: #ede9fe; }
        .btn-assist:disabled { opacity: 0.5; cursor: not-allowed; }

        .assist-preview { background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 6px; padding: 12px 14px; font-size: 12px; line-height: 1.6; margin-top: 10px; }
        .assist-preview .assist-text { white-space: pre-wrap; color: #374151; margin-bottom: 10px; }
        .assist-preview .assist-actions { display: flex; gap: 6px; }

        /* Data tables */
        .data-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .data-table th { background: #1e3a5f; color: #fff; padding: 8px 10px; text-align: left; font-weight: 600; font-size: 11px; }
        .data-table td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; }
        .data-table tr:nth-child(even) td { background: #f9fafb; }
        .score-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; color: #fff; font-weight: 600; font-size: 10px; }
        .bar-outer { background: #e5e7eb; border-radius: 4px; height: 8px; width: 100%; }
        .bar-inner { border-radius: 4px; height: 8px; }

        .stat-row { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .stat-card { flex: 1; min-width: 130px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; text-align: center; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; margin: 4px 0; }
        .stat-card .stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; }

        .status-msg { padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .status-msg.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-msg.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
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
            <a href="grc-assessment.php?view=<?php echo $assessmentId; ?>"><?php echo e(t('grc-assessment-report.nav_assessment')); ?></a>
            <a href="grc-fairscore.php?id=<?php echo $assessmentId; ?>"><?php echo e(t('grc-assessment-report.nav_dashboard')); ?></a>
            <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
            <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
            <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
        </div>
    </div>
    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>
        <main class="main-content">

            <div class="report-header">
                <div>
                    <h1 id="reportTitle"><?php echo e($orgName); ?> <?php echo e(t('grc-assessment-report.cyber_report')); ?></h1>
                    <div class="report-meta">
                        <?php echo e($assessment['assessment_ref']); ?> &mdash; <?php echo e($assessment['title']); ?>
                        &bull; <?php echo e(ucfirst(str_replace('_', ' ', $assessment['status']))); ?>
                    </div>
                </div>
                <div class="report-actions">
                    <?php if ($canEdit): ?>
                    <button class="btn btn-success" id="btnSavePdf" data-action="save-pdf"><?php echo e(t('grc-assessment-report.save_generate_pdf')); ?></button>
                    <?php endif; ?>
                    <?php if ($hasReport): ?>
                    <a href="api/grc-assessment-report.php?action=download&id=<?php echo $assessmentId; ?>" class="btn btn-primary"><?php echo e(t('grc-assessment-report.download_pdf')); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Company Name -->
            <div class="section-card" style="margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:14px;padding:14px 20px;">
                    <label for="companyName" style="font-size:13px;font-weight:600;color:#1e3a5f;white-space:nowrap;"><?php echo e(t('grc-assessment-report.company_name')); ?></label>
                    <input type="text" id="companyName" value="<?php echo e($orgName); ?>" <?php echo $canEdit ? '' : 'readonly'; ?>
                        style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;<?php echo $canEdit ? '' : 'background:#f9fafb;color:#6b7280;cursor:not-allowed;'; ?>"
                        placeholder="<?php echo e(t('grc-assessment-report.company_name_placeholder')); ?>" />
                    <span style="font-size:11px;color:#6b7280;"><?php echo e(t('grc-assessment-report.used_on_cover')); ?></span>
                </div>
            </div>

            <div id="statusMsg" style="display:none;" class="status-msg"></div>

            <!-- Overview Stats (read-only) -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.csf_maturity')); ?></div>
                    <div class="stat-value" style="color:<?php echo rptScoreColor((float)$overallScore); ?>;"><?php echo $overallScore; ?></div>
                    <div class="stat-label"><?php echo rptScoreTier((float)$overallScore); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.compliance')); ?></div>
                    <div class="stat-value" style="color:#3b82f6;"><?php echo $compliancePct; ?>%</div>
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.overall_rate')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.questions')); ?></div>
                    <div class="stat-value" style="color:#374151;"><?php echo $answered; ?>/<?php echo $totalQuestions; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.answered')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.gaps')); ?></div>
                    <div class="stat-value" style="color:<?php echo $gapCount > 0 ? '#dc3545' : '#059669'; ?>;"><?php echo $gapCount; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-assessment-report.non_conformities')); ?></div>
                </div>
            </div>

            <!-- Section 1: Executive Summary (Editable) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="exec-summary">
                    <h3><?php echo e(t('grc-assessment-report.sec_exec_summary')); ?></h3>
                    <span class="section-type type-editable"><?php echo e(t('grc-assessment-report.editable')); ?></span>
                </div>
                <div class="section-body open" id="sec-exec-summary">
                    <textarea class="section-textarea" id="section-executive_summary" <?php echo $canEdit ? '' : 'readonly'; ?>><?php echo e($defaultExecSummary); ?></textarea>
                    <?php if ($canEdit): ?>
                    <div class="section-toolbar">
                        <button class="btn-assist" data-action="assist-propose" data-section="executive_summary"><?php echo e(t('grc-assessment-report.propose_language')); ?></button>
                        <button class="btn-assist" data-action="assist-rewrite" data-section="executive_summary"><?php echo e(t('grc-assessment-report.rewrite')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 2: Scope (Editable) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="scope">
                    <h3><?php echo e(t('grc-assessment-report.sec_scope')); ?></h3>
                    <span class="section-type type-editable"><?php echo e(t('grc-assessment-report.editable')); ?></span>
                </div>
                <div class="section-body" id="sec-scope">
                    <textarea class="section-textarea" id="section-scope" <?php echo $canEdit ? '' : 'readonly'; ?>><?php echo e($defaultScope); ?></textarea>
                    <?php if ($canEdit): ?>
                    <div class="section-toolbar">
                        <button class="btn-assist" data-action="assist-propose" data-section="scope"><?php echo e(t('grc-assessment-report.propose_language')); ?></button>
                        <button class="btn-assist" data-action="assist-rewrite" data-section="scope"><?php echo e(t('grc-assessment-report.rewrite')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 3: Methodology (Editable) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="methodology">
                    <h3><?php echo e(t('grc-assessment-report.sec_methodology')); ?></h3>
                    <span class="section-type type-editable"><?php echo e(t('grc-assessment-report.editable')); ?></span>
                </div>
                <div class="section-body" id="sec-methodology">
                    <textarea class="section-textarea" id="section-methodology" <?php echo $canEdit ? '' : 'readonly'; ?>><?php echo e($defaultMethodology); ?></textarea>
                    <?php if ($canEdit): ?>
                    <div class="section-toolbar">
                        <button class="btn-assist" data-action="assist-propose" data-section="methodology"><?php echo e(t('grc-assessment-report.propose_language')); ?></button>
                        <button class="btn-assist" data-action="assist-rewrite" data-section="methodology"><?php echo e(t('grc-assessment-report.rewrite')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 4: Security Domain Scores (Data) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="domains">
                    <h3><?php echo e(t('grc-assessment-report.sec_domain_scores')); ?></h3>
                    <span class="section-type type-data"><?php echo e(t('grc-assessment-report.data_auto')); ?></span>
                </div>
                <div class="section-body" id="sec-domains">
                    <?php if (!empty($domainScores)): ?>
                    <table class="data-table">
                        <thead><tr><th><?php echo e(t('grc-assessment-report.col_code')); ?></th><th><?php echo e(t('grc-assessment-report.col_domain')); ?></th><th><?php echo e(t('grc-assessment-report.col_score')); ?></th><th><?php echo e(t('grc-assessment-report.col_tier')); ?></th><th style="width:120px;"><?php echo e(t('grc-assessment-report.col_bar')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($domainScores as $d):
                            $ds = (float)($d['average_score'] ?? $d['avg_score'] ?? $d['score'] ?? 0);
                            $barW = max(1, ($ds / 4) * 100);
                        ?>
                        <tr>
                            <td><strong><?php echo e($d['domain_code'] ?? ''); ?></strong></td>
                            <td><?php echo e($d['domain_name'] ?? ''); ?></td>
                            <td><span class="score-badge" style="background:<?php echo rptScoreColor($ds); ?>;"><?php echo number_format($ds, 1); ?></span></td>
                            <td><?php echo rptScoreTier($ds); ?></td>
                            <td><div class="bar-outer"><div class="bar-inner" style="width:<?php echo $barW; ?>%;background:<?php echo rptScoreColor($ds); ?>;"></div></div></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-assessment-report.no_domain_scores')); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 5: Framework Compliance (Data) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="frameworks">
                    <h3><?php echo e(t('grc-assessment-report.sec_framework_compliance')); ?></h3>
                    <span class="section-type type-data"><?php echo e(t('grc-assessment-report.data_auto')); ?></span>
                </div>
                <div class="section-body" id="sec-frameworks">
                    <?php if (!empty($frameworkCompliance)): ?>
                    <table class="data-table">
                        <thead><tr><th><?php echo e(t('grc-assessment-report.col_framework')); ?></th><th><?php echo e(t('grc-assessment-report.col_requirements')); ?></th><th><?php echo e(t('grc-assessment-report.col_conforming')); ?></th><th><?php echo e(t('grc-assessment-report.col_compliance')); ?></th><th style="width:120px;"><?php echo e(t('grc-assessment-report.col_bar')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($frameworkCompliance as $fw):
                            $fwPct = (int)($fw['compliance_pct'] ?? 0);
                            $barColor = $fwPct >= 80 ? '#059669' : ($fwPct >= 50 ? '#f59e0b' : '#dc3545');
                        ?>
                        <tr>
                            <td><strong><?php echo e($fw['framework_code'] ?? $fw['code'] ?? ''); ?></strong></td>
                            <td style="text-align:center;"><?php echo (int)($fw['total_requirements'] ?? $fw['total'] ?? 0); ?></td>
                            <td style="text-align:center;"><?php echo (int)($fw['conforming'] ?? 0); ?></td>
                            <td style="text-align:center;"><strong><?php echo $fwPct; ?>%</strong></td>
                            <td><div class="bar-outer"><div class="bar-inner" style="width:<?php echo max(1, $fwPct); ?>%;background:<?php echo $barColor; ?>;"></div></div></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-assessment-report.no_framework_data')); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 6: Gap Analysis (Data) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="gaps">
                    <h3><?php echo e(t('grc-assessment-report.sec_gap_analysis', $gapCount)); ?></h3>
                    <span class="section-type type-data"><?php echo e(t('grc-assessment-report.data_auto')); ?></span>
                </div>
                <div class="section-body" id="sec-gaps">
                    <?php if (!empty($gaps)): ?>
                    <table class="data-table">
                        <thead><tr><th><?php echo e(t('grc-assessment-report.col_ref')); ?></th><th><?php echo e(t('grc-assessment-report.col_question')); ?></th><th><?php echo e(t('grc-assessment-report.col_status')); ?></th><th><?php echo e(t('grc-assessment-report.col_score')); ?></th><th><?php echo e(t('grc-assessment-report.col_affected_frameworks')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($gaps as $g):
                            $gStatus = $g['conformity_status'] ?? 'partial';
                            $gColor = $gStatus === 'non_conforming' ? '#dc3545' : '#f59e0b';
                            $afw = $g['affected_frameworks'] ?? $g['frameworks'] ?? '';
                            if (is_array($afw)) $afw = implode(', ', $afw);
                        ?>
                        <tr style="background:<?php echo $gStatus === 'non_conforming' ? '#fef2f2' : '#fffbeb'; ?>;">
                            <td><strong><?php echo e($g['question_ref'] ?? ''); ?></strong></td>
                            <td style="font-size:11px;"><?php echo e($g['question_text'] ?? ''); ?></td>
                            <td><span class="score-badge" style="background:<?php echo $gColor; ?>;"><?php echo e(ucfirst(str_replace('_', ' ', $gStatus))); ?></span></td>
                            <td style="text-align:center;"><?php echo e($g['maturity_rating'] ?? '—'); ?></td>
                            <td style="font-size:10px;"><?php echo e($afw); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="color:#059669;"><strong><?php echo e(t('grc-assessment-report.no_gaps_title')); ?></strong> <?php echo e(t('grc-assessment-report.no_gaps_desc')); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 7: Recommendations (Editable) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="recommendations">
                    <h3><?php echo e(t('grc-assessment-report.sec_recommendations')); ?></h3>
                    <span class="section-type type-editable"><?php echo e(t('grc-assessment-report.editable')); ?></span>
                </div>
                <div class="section-body" id="sec-recommendations">
                    <textarea class="section-textarea" id="section-recommendations" <?php echo $canEdit ? '' : 'readonly'; ?>><?php echo e($defaultRecommendations); ?></textarea>
                    <?php if ($canEdit): ?>
                    <div class="section-toolbar">
                        <button class="btn-assist" data-action="assist-propose" data-section="recommendations"><?php echo e(t('grc-assessment-report.propose_language')); ?></button>
                        <button class="btn-assist" data-action="assist-rewrite" data-section="recommendations"><?php echo e(t('grc-assessment-report.rewrite')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 8: Conclusion (Editable) -->
            <div class="section-card">
                <div class="section-header" data-toggle-section="conclusion">
                    <h3><?php echo e(t('grc-assessment-report.sec_conclusion')); ?></h3>
                    <span class="section-type type-editable"><?php echo e(t('grc-assessment-report.editable')); ?></span>
                </div>
                <div class="section-body" id="sec-conclusion">
                    <textarea class="section-textarea" id="section-conclusion" <?php echo $canEdit ? '' : 'readonly'; ?>><?php echo e($defaultConclusion); ?></textarea>
                    <?php if ($canEdit): ?>
                    <div class="section-toolbar">
                        <button class="btn-assist" data-action="assist-propose" data-section="conclusion"><?php echo e(t('grc-assessment-report.propose_language')); ?></button>
                        <button class="btn-assist" data-action="assist-rewrite" data-section="conclusion"><?php echo e(t('grc-assessment-report.rewrite')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var _csrfToken = <?php echo json_encode($csrfToken); ?>;
    var _assessmentId = <?php echo $assessmentId; ?>;
    var _aiEnabled = <?php echo json_encode($aiEnabled); ?>;

    // Update page title when company name changes
    var companyInput = document.getElementById('companyName');
    if (companyInput) {
        companyInput.addEventListener('input', function() {
            var name = this.value.trim() || 'Organization';
            document.getElementById('reportTitle').textContent = name + ' ' + <?php echo json_encode(t('grc-assessment-report.cyber_report')); ?>;
        });
    }

    // Section toggle
    document.querySelectorAll('[data-toggle-section]').forEach(function(header) {
        header.addEventListener('click', function() {
            var secId = 'sec-' + this.getAttribute('data-toggle-section');
            var body = document.getElementById(secId);
            if (body) body.classList.toggle('open');
        });
    });

    // Event delegation
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        switch (action) {
            case 'save-pdf':
                savePdf(btn);
                break;
            case 'assist-propose':
                assistPropose(btn.getAttribute('data-section'), btn);
                break;
            case 'assist-rewrite':
                assistRewrite(btn.getAttribute('data-section'), btn);
                break;
            case 'accept-assist':
                acceptAssist(btn.getAttribute('data-section'));
                break;
            case 'discard-assist':
                var prev = document.getElementById('assist-preview-' + btn.getAttribute('data-section'));
                if (prev) prev.remove();
                break;
        }
    });

    function showStatus(msg, type) {
        var el = document.getElementById('statusMsg');
        el.textContent = msg;
        el.className = 'status-msg ' + type;
        el.style.display = 'block';
        setTimeout(function() { el.style.display = 'none'; }, 6000);
    }

    function getCompanyName() {
        var el = document.getElementById('companyName');
        return (el && el.value.trim()) ? el.value.trim() : '';
    }

    function getSections() {
        return {
            executive_summary: document.getElementById('section-executive_summary').value,
            scope: document.getElementById('section-scope').value,
            methodology: document.getElementById('section-methodology').value,
            recommendations: document.getElementById('section-recommendations').value,
            conclusion: document.getElementById('section-conclusion').value
        };
    }

    function savePdf(btn) {
        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment-report.js_generating_pdf')); ?>;

        fetch('api/grc-assessment-report.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assessment_id: _assessmentId,
                csrf_token: _csrfToken,
                company_name: getCompanyName(),
                sections: getSections()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.csrf_token) _csrfToken = resp.csrf_token;
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.save_generate_pdf')); ?>;
            if (resp.success) {
                showStatus(<?php echo json_encode(t('grc-assessment-report.js_report_generated_prefix')); ?> + resp.file_name, 'success');
                // Add download button if not present
                if (!document.querySelector('a[href*="grc-assessment-report.php?action=download"]')) {
                    var dl = document.createElement('a');
                    dl.href = resp.download_url;
                    dl.className = 'btn btn-primary';
                    dl.textContent = <?php echo json_encode(t('grc-assessment-report.download_pdf')); ?>;
                    btn.parentNode.appendChild(dl);
                }
            } else {
                showStatus(<?php echo json_encode(t('grc-assessment-report.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment-report.js_unknown_error')); ?>), 'error');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.save_generate_pdf')); ?>;
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_failed_prefix')); ?> + err.message, 'error');
        });
    }

    function assistPropose(section, btn) {
        var ta = document.getElementById('section-' + section);
        if (!ta) return;

        if (!_aiEnabled) {
            // Without AI, populate with the existing default text
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_assist_not_configured')); ?>, 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment-report.js_thinking')); ?>;

        // Remove existing preview
        var existing = document.getElementById('assist-preview-' + section);
        if (existing) existing.remove();

        var sectionLabels = {
            executive_summary: 'executive summary of a cybersecurity program overview report',
            scope: 'scope section of a cybersecurity assessment report',
            methodology: 'methodology section describing the assessment approach',
            recommendations: 'recommendations section based on assessment findings',
            conclusion: 'conclusion section summarizing key findings and next steps'
        };

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assistant_rewrite',
                text: ta.value || 'Write a professional ' + (sectionLabels[section] || section) + '.',
                context: 'This is the ' + (sectionLabels[section] || section) + ' for ' + (getCompanyName() || <?php echo json_encode($orgName); ?>) + '. Assessment: ' + <?php echo json_encode($assessment['title']); ?> + '. CSF Score: ' + <?php echo json_encode($overallScore); ?> + '/4.0. Compliance: ' + <?php echo json_encode($compliancePct); ?> + '%. Gaps: ' + <?php echo json_encode($gapCount); ?> + '.',
                field: 'description',
                csrf_token: _csrfToken
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.csrf_token) _csrfToken = resp.csrf_token;
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.propose_language')); ?>;
            if (resp.success && resp.content) {
                var preview = document.createElement('div');
                preview.className = 'assist-preview';
                preview.id = 'assist-preview-' + section;
                preview.innerHTML = '<div style="font-size:11px;font-weight:600;color:#6d28d9;margin-bottom:6px;">' + <?php echo json_encode(t('grc-assessment-report.js_proposed_language')); ?> + '</div>' +
                    '<div class="assist-text">' + escHtml(resp.content) + '</div>' +
                    '<div class="assist-actions">' +
                    '<button class="btn btn-xs btn-success" data-action="accept-assist" data-section="' + section + '">' + <?php echo json_encode(t('grc-assessment-report.js_accept')); ?> + '</button>' +
                    '<button class="btn btn-xs btn-outline" data-action="discard-assist" data-section="' + section + '">' + <?php echo json_encode(t('grc-assessment-report.js_discard')); ?> + '</button>' +
                    '</div>';
                ta.parentNode.insertBefore(preview, ta.nextSibling);
            } else {
                showStatus(<?php echo json_encode(t('grc-assessment-report.js_assistant_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment-report.js_no_response')); ?>), 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.propose_language')); ?>;
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_connection_error')); ?>, 'error');
        });
    }

    function assistRewrite(section, btn) {
        var ta = document.getElementById('section-' + section);
        if (!ta || !ta.value.trim()) {
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_write_content_first')); ?>, 'error');
            return;
        }

        if (!_aiEnabled) {
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_ai_not_configured_rewrite')); ?>, 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment-report.js_rewriting')); ?>;

        var existing = document.getElementById('assist-preview-' + section);
        if (existing) existing.remove();

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assistant_rewrite',
                text: ta.value,
                context: 'Cyber Report report section',
                field: 'description',
                csrf_token: _csrfToken
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.csrf_token) _csrfToken = resp.csrf_token;
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.rewrite')); ?>;
            if (resp.success && resp.content) {
                var preview = document.createElement('div');
                preview.className = 'assist-preview';
                preview.id = 'assist-preview-' + section;
                preview.innerHTML = '<div style="font-size:11px;font-weight:600;color:#6d28d9;margin-bottom:6px;">' + <?php echo json_encode(t('grc-assessment-report.js_proposed_rewrite')); ?> + '</div>' +
                    '<div class="assist-text">' + escHtml(resp.content) + '</div>' +
                    '<div class="assist-actions">' +
                    '<button class="btn btn-xs btn-success" data-action="accept-assist" data-section="' + section + '">' + <?php echo json_encode(t('grc-assessment-report.js_accept')); ?> + '</button>' +
                    '<button class="btn btn-xs btn-outline" data-action="discard-assist" data-section="' + section + '">' + <?php echo json_encode(t('grc-assessment-report.js_discard')); ?> + '</button>' +
                    '</div>';
                ta.parentNode.insertBefore(preview, ta.nextSibling);
            } else {
                showStatus(<?php echo json_encode(t('grc-assessment-report.js_rewrite_failed_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment-report.js_no_response')); ?>), 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment-report.rewrite')); ?>;
            showStatus(<?php echo json_encode(t('grc-assessment-report.js_connection_error')); ?>, 'error');
        });
    }

    function acceptAssist(section) {
        var preview = document.getElementById('assist-preview-' + section);
        var ta = document.getElementById('section-' + section);
        if (preview && ta) {
            var textEl = preview.querySelector('.assist-text');
            if (textEl) ta.value = textEl.textContent;
            preview.remove();
        }
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
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
                    <span><?php echo e(t('grc-assessment-report.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>
</body>
</html>
