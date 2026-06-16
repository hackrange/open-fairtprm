<?php
/**
 * GRC Unified Compliance Engine - Gap Analysis
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Lists all identified gaps (non-conforming / partial responses) from the
 * active assessment, with severity, domain, framework impact, and links to
 * the assessment questionnaire and risk register.
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
    die('Access denied.');
}

$uas = UnifiedAssessmentService::getInstance();

// Load assessments for dropdown
$assessments = $uas->getAssessments(null, true);
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$selectedId && !empty($assessments)) $selectedId = (int)$assessments[0]['id'];

$gaps = [];
$assessment = null;
if ($selectedId > 0) {
    $assessment = $uas->getAssessment($selectedId);
    if ($assessment) {
        $gaps = $uas->getGapAnalysis($selectedId);
    }
}

$totalGaps = count($gaps);
$nonConformingCount = 0;
$partialCount = 0;
foreach ($gaps as $g) {
    if ($g['conformity_status'] === 'non_conforming') $nonConformingCount++;
    else $partialCount++;
}

// Check for linked risks
$risksByRef = [];
try {
    $risks = $db->fetchAll("SELECT title FROM grc_risk_register WHERE status != 'closed'");
    foreach ($risks as $risk) {
        if (preg_match('/\[([A-Z]+-\d+)\]/', $risk['title'], $m)) {
            $risksByRef[$m[1]] = true;
        }
    }
} catch (Exception $e) {}

$_page = 'grc_gaps';
$currentPage = 'grc_gaps';
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc_gaps.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style nonce="<?php echo cspNonce(); ?>">
        :root {
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; color: #111827; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }
        .top-bar { display: flex; align-items: center; padding: 10px 24px; background: #fff; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #6b7280; text-decoration: none; font-size: 13px; }
        .user-menu a:hover { color: #111827; }
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
        .sidebar-nav li a.active { background: rgba(255,255,255,0.12); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 600; }
        .sidebar-nav li a .icon { width: 22px; text-align: center; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .sidebar-section summary { list-style: none; cursor: pointer; user-select: none; }
        .sidebar-section summary::-webkit-details-marker { display: none; }
        .sidebar-module { border-top: 1px solid rgba(255,255,255,0.08); margin-top: 4px; }
        .sidebar-module:first-of-type { margin-top: 0; border-top: none; }
        .sidebar-module > summary.sidebar-module-header { display: flex; align-items: center; padding: 12px 20px 8px; cursor: pointer; list-style: none; list-style-type: none; user-select: none; }
        .sidebar-module > summary.sidebar-module-header::-webkit-details-marker { display: none; }
        .sidebar-module > summary.sidebar-module-header::marker { display: none; content: none; font-size: 0; }
        .sidebar-module > summary.sidebar-module-header::before,
        .sidebar-module > summary.sidebar-module-header::after { content: none; display: none; }
        .sidebar-module-header .module-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--theme-button-color, #ff6543); opacity: 1; transition: opacity 0.2s; }
        .sidebar-module-header:hover .module-label { opacity: 0.8; }
        .sidebar-module-body { padding-top: 0; padding-bottom: 4px; position: relative; }
        .sidebar-module-body::before { content: ""; display: block; height: 2px; background: var(--theme-button-color, #ff6543); margin: 0 20px 8px; opacity: 0.5; border-radius: 1px; }
        .sidebar-module .sidebar-nav li a { font-size: 12px; padding-top: 9px; padding-bottom: 9px; }
        .sidebar-module .sidebar-section { margin-bottom: 16px; }
        .sidebar-module .sidebar-section:first-child { margin-top: 4px; }
        .sidebar-module .sidebar-section-title { font-size: 9px; }
        .main-content { flex: 1; padding: 32px; overflow-y: auto; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-card .stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 4px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; }

        .gap-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .gap-table th { background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .gap-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .gap-table tr:hover td { background: #f9fafb; }
        .severity-non_conforming { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b; }
        .severity-partial { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e; }
        .framework-tag { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 500; background: #e0e7ff; color: #3730a3; margin: 1px 2px; }
        .risk-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #fce7f3; color: #9d174d; }
        .no-risk-link { color: #9ca3af; font-size: 11px; font-style: italic; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer; border: none; }
        .btn-primary { background: var(--theme-button-color); color: #fff; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .assessment-selector { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .assessment-selector select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff; }

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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc_gaps.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 20px;"><?php echo e(t('grc_gaps.subheading')); ?></p>

            <!-- Assessment Selector -->
            <?php if (!empty($assessments)): ?>
            <div style="margin-bottom:24px;padding:14px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <label style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><?php echo e(t('grc_gaps.assessment_label')); ?></label>
                    <select id="assessmentSelect" onchange="window.location='grc-gaps.php?id='+this.value" style="flex:1;max-width:500px;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">-- <?php echo e(t('grc_gaps.select_assessment')); ?> --</option>
                        <?php foreach ($assessments as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>" <?php echo (int)$a['id'] === $selectedId ? 'selected' : ''; ?>>
                            <?php echo e(($a['assessment_ref'] ?? '') . ' — ' . ($a['title'] ?? '') . ' (' . ucfirst($a['status'] ?? 'draft') . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <!-- Summary Stats -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc_gaps.stat_total_gaps')); ?></div>
                    <div class="stat-value" style="color:#dc3545;"><?php echo $totalGaps; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc_gaps.stat_non_conforming')); ?></div>
                    <div class="stat-value" style="color:#991b1b;"><?php echo $nonConformingCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc_gaps.stat_partial')); ?></div>
                    <div class="stat-value" style="color:#92400e;"><?php echo $partialCount; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?php echo e(t('grc_gaps.stat_with_linked_risk')); ?></div>
                    <div class="stat-value" style="color:#059669;"><?php
                        $linkedCount = 0;
                        foreach ($gaps as $g) { if (isset($risksByRef[$g['question_ref']])) $linkedCount++; }
                        echo $linkedCount;
                    ?></div>
                </div>
            </div>

            <?php if (empty($gaps)): ?>
            <div style="text-align:center;padding:60px 20px;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                <p style="font-size:16px;color:#6b7280;margin-bottom:8px;"><?php echo e(t('grc_gaps.no_gaps')); ?></p>
                <p style="font-size:13px;color:#9ca3af;"><?php echo e(t('grc_gaps.all_conforming')); ?></p>
            </div>
            <?php else: ?>
            <table class="gap-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('grc_gaps.col_severity')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_domain')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_ref')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_finding')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_framework_impact')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_risk')); ?></th>
                        <th><?php echo e(t('grc_gaps.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gaps as $g):
                    $severity = $g['conformity_status'];
                    $hasRisk = isset($risksByRef[$g['question_ref']]);
                ?>
                    <tr>
                        <td>
                            <span class="severity-<?php echo $severity; ?>">
                                <?php echo e($severity === 'non_conforming' ? t('grc_gaps.non_conforming') : t('grc_gaps.partial')); ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight:600;font-size:12px;color:#1f2937;"><?php echo e($g['domain_code']); ?></span><br>
                            <span style="font-size:11px;color:#6b7280;"><?php echo e($g['domain_name']); ?></span>
                        </td>
                        <td><span style="font-weight:600;"><?php echo e($g['question_ref']); ?></span></td>
                        <td style="max-width:300px;">
                            <?php echo e(mb_substr($g['question_text'], 0, 120)); ?><?php if (mb_strlen($g['question_text']) > 120) echo '...'; ?>
                            <?php if (!empty($g['notes'])): ?>
                            <div style="margin-top:4px;font-size:11px;color:#6b7280;font-style:italic;">Notes: <?php echo e(mb_substr($g['notes'], 0, 80)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($g['framework_impact'])):
                                foreach ($g['framework_impact'] as $fi): ?>
                                <span class="framework-tag"><?php echo e($fi['framework_code'] . ':' . $fi['requirement_ref']); ?></span>
                                <?php endforeach;
                            endif; ?>
                        </td>
                        <td>
                            <?php if ($hasRisk): ?>
                            <span class="risk-badge"><?php echo e(t('grc_gaps.risk_logged')); ?></span>
                            <?php else: ?>
                            <span class="no-risk-link"><?php echo e(t('grc_gaps.no_risk')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="grc-assessment.php?view=<?php echo $selectedId; ?>#q-<?php echo (int)$g['question_id']; ?>" class="btn btn-outline" style="font-size:11px;padding:4px 10px;"><?php echo e(t('grc_gaps.view')); ?></a>
                            <?php if (!$hasRisk): ?>
                            <a href="grc-risks.php" class="btn btn-outline" style="font-size:11px;padding:4px 10px;margin-top:4px;display:inline-block;"><?php echo e(t('grc_gaps.log_risk')); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc_gaps.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc_gaps.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>
</body>
</html>
