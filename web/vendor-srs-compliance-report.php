<?php
/**
 * SRS 90-Day Compliance Report (Fiscal Quarter)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Renders a simple text summary of UpGuard SRS, Shodan, and combined averages
 * for all Tier 1 vendors plus a primary-organization breakout. The window is bounded
 * by the requested fiscal quarter (Q1 = Jan 1 to Apr 1, Q2 = Apr 1 to Jul 1,
 * Q3 = Jul 1 to Oct 1, Q4 = Oct 1 to Dec 31). Output mirrors the side-by-side
 * "two box" view used in the chat conversation: per-source averages and a
 * combined percentage in UpGuard's standard scale.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Same access policy as vendor-srs-list.php
$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isAuditor = $acl->hasGroup('auditor');
if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(e(t('vendor-srs-compliance-report.access_denied')));
}

require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();
$scoringConfig = $srsService->getScoringConfig();
$srsMax = ($scoringConfig['max_score'] > 0) ? (int)$scoringConfig['max_score'] : 950;

// Quarter -> [start (inclusive), end (exclusive)]
$quarter = isset($_GET['quarter']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $_GET['quarter'])) : 'Q1';
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

$quarters = [
    'Q1' => [sprintf('%04d-01-01', $year),     sprintf('%04d-04-01', $year)],
    'Q2' => [sprintf('%04d-04-01', $year),     sprintf('%04d-07-01', $year)],
    'Q3' => [sprintf('%04d-07-01', $year),     sprintf('%04d-10-01', $year)],
    'Q4' => [sprintf('%04d-10-01', $year),     sprintf('%04d-12-31', $year)],
];
if (!isset($quarters[$quarter])) $quarter = 'Q1';
[$startDate, $endDate] = $quarters[$quarter];

// SQL window: scored_at >= start AND scored_at < end (Q4 inclusive of Dec 31 23:59:59)
$endBound = ($quarter === 'Q4') ? ($endDate . ' 23:59:59') : $endDate;

// ---- Tier 1 portfolio aggregate ----
$tier1Sql = "
    SELECT
        COUNT(*) AS vendor_count,
        ROUND(AVG(srs_pct), 2) AS avg_srs_pct,
        ROUND(AVG(srs_raw), 2) AS avg_srs_raw,
        ROUND(AVG(shodan_pct), 2) AS avg_shodan_pct,
        ROUND(AVG((srs_pct + COALESCE(shodan_pct, srs_pct)) / IF(shodan_pct IS NULL, 1, 2)), 2) AS avg_combined_pct,
        SUM(CASE WHEN shodan_pct IS NOT NULL THEN 1 ELSE 0 END) AS vendors_with_shodan
    FROM (
        SELECT
            vor.id,
            AVG(srs.score) AS srs_raw,
            AVG(srs.score) / :srs_max1 * 100 AS srs_pct,
            (SELECT AVG(sh.score) FROM vendor_shodan_scores sh
              WHERE sh.vendor_onboarding_id = vor.id
                AND sh.scored_at >= :start1
                AND sh.scored_at <= :end1
                AND sh.score > 0) AS shodan_pct
        FROM vendor_onboarding_requests vor
        JOIN vendor_srs_scores srs ON srs.vendor_onboarding_id = vor.id
        WHERE vor.vendor_tier = 1
          AND srs.scored_at >= :start2
          AND srs.scored_at <= :end2
          AND srs.score > 0
        GROUP BY vor.id
    ) t
";
$tier1 = $db->fetchOne($tier1Sql, [
    ':srs_max1' => $srsMax,
    ':start1' => $startDate, ':end1' => $endBound,
    ':start2' => $startDate, ':end2' => $endBound,
]);

// ---- Primary-organization breakout (matched by the org's own domain) ----
// The org's own domain is configurable via the app_config key 'org_self_domain'
// and the display label reuses 'company_name'. On fresh installs org_self_domain
// is empty, so the breakout is simply omitted (no customer-specific hardcoding).
$orgLabel  = t('vendor-srs-compliance-report.your_organization');
$orgDomain = '';
$cfgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
if ($cfgRow && !empty($cfgRow['config_value'])) { $orgLabel = $cfgRow['config_value']; }
$cfgRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'org_self_domain'");
if ($cfgRow && !empty($cfgRow['config_value'])) { $orgDomain = trim($cfgRow['config_value']); }

$org = null;
if ($orgDomain !== '') {
    $orgSql = "
        SELECT
            vor.id,
            vor.vendor_name,
            vor.vendor_domain,
            (SELECT COUNT(*) FROM vendor_srs_scores s WHERE s.vendor_onboarding_id = vor.id AND s.scored_at >= :s1 AND s.scored_at <= :e1) AS srs_samples,
            (SELECT ROUND(AVG(s.score), 2) FROM vendor_srs_scores s WHERE s.vendor_onboarding_id = vor.id AND s.scored_at >= :s2 AND s.scored_at <= :e2 AND s.score > 0) AS srs_raw,
            (SELECT ROUND(AVG(s.score) / :srs_max2 * 100, 2) FROM vendor_srs_scores s WHERE s.vendor_onboarding_id = vor.id AND s.scored_at >= :s3 AND s.scored_at <= :e3 AND s.score > 0) AS srs_pct,
            (SELECT COUNT(*) FROM vendor_shodan_scores s WHERE s.vendor_onboarding_id = vor.id AND s.scored_at >= :s4 AND s.scored_at <= :e4) AS shodan_samples,
            (SELECT ROUND(AVG(s.score), 2) FROM vendor_shodan_scores s WHERE s.vendor_onboarding_id = vor.id AND s.scored_at >= :s5 AND s.scored_at <= :e5 AND s.score > 0) AS shodan_pct
        FROM vendor_onboarding_requests vor
        WHERE vor.vendor_domain = :org_domain
        ORDER BY vor.id
        LIMIT 1
    ";
    $org = $db->fetchOne($orgSql, [
        ':org_domain' => $orgDomain,
        ':srs_max2' => $srsMax,
        ':s1' => $startDate, ':e1' => $endBound,
        ':s2' => $startDate, ':e2' => $endBound,
        ':s3' => $startDate, ':e3' => $endBound,
        ':s4' => $startDate, ':e4' => $endBound,
        ':s5' => $startDate, ':e5' => $endBound,
    ]);
}

// Letter grade against UpGuard's 0-100 percentage scale
function complianceLetter(?float $pct): string {
    if ($pct === null) return '-';
    if ($pct >= 90) return 'A';
    if ($pct >= 80) return 'B';
    if ($pct >= 70) return 'C';
    if ($pct >= 60) return 'D';
    return 'F';
}

$tier1Combined = $tier1 && isset($tier1['avg_combined_pct']) ? (float)$tier1['avg_combined_pct'] : null;
$orgCombined = null;
if ($org && $org['srs_pct'] !== null) {
    $orgCombined = ($org['shodan_pct'] !== null)
        ? round(((float)$org['srs_pct'] + (float)$org['shodan_pct']) / 2, 2)
        : (float)$org['srs_pct'];
}

$displayEndDate = ($quarter === 'Q4') ? $endDate : date('Y-m-d', strtotime($endDate . ' -1 day'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($quarter . ' ' . $year); ?> <?php echo e(t('vendor-srs-compliance-report.compliance_report')); ?></title>
    <link rel="stylesheet" href="app/css/fonts.css">
    <style>
        :root {
            --theme-header-color: <?php echo htmlspecialchars($theme['header_color']); ?>;
            --theme-footer-color: <?php echo htmlspecialchars($theme['footer_color']); ?>;
            --theme-button-color: <?php echo htmlspecialchars($theme['button_color']); ?>;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f9fafb; font-family: 'Roboto', sans-serif; color: #1f2937; padding: 30px; }
        .report-wrap { max-width: 960px; margin: 0 auto; }
        .report-header { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 20px; border-top: 4px solid var(--theme-header-color); }
        .report-header h1 { margin: 0 0 4px; font-size: 22px; color: var(--theme-header-color); }
        .report-header .meta { color: #6b7280; font-size: 13px; }
        .boxes { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 720px) { .boxes { grid-template-columns: 1fr; } }
        .box { background: white; border-radius: 12px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .box h2 { margin: 0 0 14px; font-size: 16px; color: var(--theme-header-color); border-bottom: 2px solid var(--theme-header-color); padding-bottom: 10px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .row .label { color: #6b7280; }
        .row .value { font-weight: 600; color: #111827; }
        .combined { margin-top: 14px; padding: 14px; background: var(--theme-header-color); border-radius: 8px; text-align: center; color: white; }
        .combined .pct { font-size: 28px; font-weight: 700; color: white; }
        .combined .grade { font-size: 13px; color: rgba(255,255,255,0.85); margin-top: 4px; }
        .actions { margin-top: 24px; text-align: center; }
        .actions button {
            padding: 9px 18px; border: none; background: var(--theme-button-color);
            color: white; border-radius: 6px; font-size: 13px; cursor: pointer;
            margin: 0 5px; font-weight: 500;
        }
        .actions button:hover { filter: brightness(1.1); }
        .actions button.btn-secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
        .actions button.btn-secondary:hover { background: #f9fafb; filter: none; }
        @media print {
            body { background: white; padding: 0; }
            .actions { display: none; }
            .box, .report-header { box-shadow: none; border: 1px solid #e5e7eb; }
            .combined { background: white !important; color: #111 !important; border: 2px solid var(--theme-header-color); }
            .combined .pct { color: var(--theme-header-color) !important; }
            .combined .grade { color: #6b7280 !important; }
        }
    </style>
</head>
<body>
<div class="report-wrap">
    <div class="report-header">
        <h1><?php echo htmlspecialchars($quarter . ' ' . $year); ?> <?php echo e(t('vendor-srs-compliance-report.compliance_report')); ?></h1>
        <div class="meta">
            Window: <?php echo htmlspecialchars($startDate); ?> &ndash; <?php echo htmlspecialchars($displayEndDate); ?>
            &nbsp;|&nbsp; Generated: <?php echo date('Y-m-d H:i'); ?>
            &nbsp;|&nbsp; By: <?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? ''); ?>
        </div>
    </div>

    <div class="boxes">
        <!-- Tier 1 portfolio -->
        <div class="box">
            <h2><?php echo e(t('vendor-srs-compliance-report.tier1_portfolio')); ?></h2>
            <?php if (!$tier1 || (int)$tier1['vendor_count'] === 0): ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('vendor-srs-compliance-report.no_tier1_data')); ?></p>
            <?php else: ?>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.vendors_with_srs')); ?></span><span class="value"><?php echo (int)$tier1['vendor_count']; ?></span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.vendors_with_shodan')); ?></span><span class="value"><?php echo (int)$tier1['vendors_with_shodan']; ?></span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.avg_upguard_srs')); ?></span><span class="value"><?php echo number_format((float)$tier1['avg_srs_raw'], 2); ?> / <?php echo $srsMax; ?> &nbsp;(<?php echo number_format((float)$tier1['avg_srs_pct'], 2); ?>%)</span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.avg_shodan')); ?></span><span class="value"><?php echo $tier1['avg_shodan_pct'] !== null ? number_format((float)$tier1['avg_shodan_pct'], 2) . '%' : '&mdash;'; ?></span></div>
                <div class="combined">
                    <div class="pct"><?php echo $tier1Combined !== null ? number_format($tier1Combined, 2) . '%' : '&mdash;'; ?></div>
                    <div class="grade"><?php echo e(t('vendor-srs-compliance-report.combined_letter_grade')); ?> <strong><?php echo complianceLetter($tier1Combined); ?></strong></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Primary organization breakout -->
        <div class="box">
            <h2><?php echo e($orgLabel); ?></h2>
            <?php if ($orgDomain === ''): ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('vendor-srs-compliance-report.no_org_domain')); ?></p>
            <?php elseif (!$org): ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e($orgLabel); ?> (<?php echo e($orgDomain); ?>) <?php echo e(t('vendor-srs-compliance-report.not_found_in_list')); ?></p>
            <?php elseif ((int)$org['srs_samples'] === 0 && (int)$org['shodan_samples'] === 0): ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('vendor-srs-compliance-report.no_samples_before')); ?> <?php echo e($orgLabel); ?> <?php echo e(t('vendor-srs-compliance-report.no_samples_after')); ?></p>
            <?php else: ?>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.srs_samples')); ?></span><span class="value"><?php echo (int)$org['srs_samples']; ?></span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.shodan_samples')); ?></span><span class="value"><?php echo (int)$org['shodan_samples']; ?></span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.avg_upguard_srs')); ?></span><span class="value"><?php echo $org['srs_raw'] !== null ? number_format((float)$org['srs_raw'], 2) . ' / ' . $srsMax . ' (' . number_format((float)$org['srs_pct'], 2) . '%)' : '&mdash;'; ?></span></div>
                <div class="row"><span class="label"><?php echo e(t('vendor-srs-compliance-report.avg_shodan')); ?></span><span class="value"><?php echo $org['shodan_pct'] !== null ? number_format((float)$org['shodan_pct'], 2) . '%' : '&mdash;'; ?></span></div>
                <div class="combined">
                    <div class="pct"><?php echo $orgCombined !== null ? number_format($orgCombined, 2) . '%' : '&mdash;'; ?></div>
                    <div class="grade"><?php echo e(t('vendor-srs-compliance-report.combined_letter_grade')); ?> <strong><?php echo complianceLetter($orgCombined); ?></strong></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="actions">
        <button type="button" data-action="printReport"><?php echo e(t('vendor-srs-compliance-report.print')); ?></button>
        <button type="button" class="btn-secondary" data-action="closeReport"><?php echo e(t('vendor-srs-compliance-report.close')); ?></button>
    </div>
</div>
<script nonce="<?php echo cspNonce(); ?>">
    window.printReport = function() { window.print(); };
    window.closeReport = function() { window.close(); };
</script>
<script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
