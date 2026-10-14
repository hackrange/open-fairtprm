<?php
/**
 * Shadow SaaS - AI Security - AI Dashboard.
 *
 * One-page summary of AI risk from Grip: AI applications by AI depth, which of
 * them train on customer data and which are not onboarded in TPRM, the most
 * used and the most recently discovered AI apps, AI agents by sanction state,
 * risk and internet access, and the latest "New Unmanaged AI App" alerts.
 * Every figure links to the filtered AI Applications / AI Agents view.
 *
 * Read-only, from the Grip mirrors (no personal data is read). Access:
 * administrator / cyber_tprm / auditor.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();
require_once __DIR__ . '/includes/classes/GripService.php';
header('Cache-Control: no-cache, no-store, must-revalidate');

$auth     = Auth::getInstance();
$db       = Database::getInstance();
$acl      = ACL::getInstance();
$security = Security::getInstance();
$user     = $auth->getUser();
$theme    = getUserTheme();

$isAdmin     = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isAuditor   = $acl->hasGroup('auditor');
if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(e(t('shadow-saas.access_denied')));
}
$canManage = $isAdmin || $isCyberTPRM;

$grip        = new GripService();
$gripEnabled = $grip->isEnabled();
$error   = '';
$success = '';

$adApps = [];
$adAgents = [];
$adAlerts = [];
if ($gripEnabled) {
    try {
        $levels = GripService::AI_DEPTH_LEVELS;
        $ph = implode(',', array_map(fn($i) => ":l{$i}", array_keys($levels)));
        $params = [];
        foreach ($levels as $i => $l) $params[":l{$i}"] = $l;
        $adApps = $db->fetchAll(
            "SELECT a.grip_id, a.name, a.domain, a.number_of_users, a.first_event_time, a.ai_depth_level, a.terms_questions, a.sanction_tag, v.vid AS vendor_id
               FROM shadow_saas_grip_apps a
               LEFT JOIN (SELECT vendor_domain, MIN(id) AS vid FROM vendor_onboarding_requests
                           WHERE status != 'inactive' AND vendor_domain IS NOT NULL AND vendor_domain != ''
                           GROUP BY vendor_domain) v ON v.vendor_domain = a.domain
              WHERE a.ai_depth_level IN ({$ph})",
            $params
        );
        // Non-personal agent columns only (owner/files/recipients stay encrypted).
        $adAgents = $db->fetchAll('SELECT grip_id, name, score, sanction_state, connected_to_internet, knowledge_files_count FROM shadow_saas_grip_ai_agents');
        $adAlerts = $db->fetchAll(
            "SELECT related_entity_id, related_entity_name, created_at_grip FROM shadow_saas_grip_alerts
              WHERE alert_type LIKE 'New Unmanaged AI App%' ORDER BY created_at_grip DESC LIMIT 200"
        );
    } catch (Throwable $e) {
        error_log('AI dashboard load failed: ' . $e->getMessage());
        $error = t('shadow-saas-posture.load_failed');
    }
}

$ad = ['apps' => count($adApps), 'users' => 0, 'not_tprm' => 0, 'depth' => array_fill_keys(GripService::AI_DEPTH_LEVELS, 0),
       'trains' => ['yes' => 0, 'optional' => 0, 'no' => 0, 'unknown' => 0],
       'agents' => count($adAgents), 'high' => 0, 'internet' => 0, 'files' => 0, 'sanction' => [], 'alerts30' => 0];
$cut30 = date('Y-m-d H:i:s', time() - 30 * 86400);
$recent = [];
foreach ($adApps as $a) {
    $ad['users'] += (int)$a['number_of_users'];
    if (empty($a['vendor_id'])) $ad['not_tprm']++;
    $ad['depth'][$a['ai_depth_level']]++;
    $qa = GripService::aiPrivacyAnswers($a['terms_questions']);
    $ad['trains'][GripService::aiTrainsBucket($qa['Trains on customer data']['answer'] ?? '')]++;
    if ($a['first_event_time'] && $a['first_event_time'] >= $cut30) $recent[] = $a;
}
foreach ($adAgents as $g) {
    if ($g['score'] !== null && (int)$g['score'] >= 70) $ad['high']++;
    if ((int)$g['connected_to_internet'] === 1) $ad['internet']++;
    if ((int)$g['knowledge_files_count'] > 0) $ad['files']++;
    $ss = (string)$g['sanction_state'] ?: 'Unknown';
    $ad['sanction'][$ss] = ($ad['sanction'][$ss] ?? 0) + 1;
}
arsort($ad['sanction']);
foreach ($adAlerts as $al) if ($al['created_at_grip'] && $al['created_at_grip'] >= $cut30) $ad['alerts30']++;
$topApps = $adApps;
usort($topApps, fn($x, $y) => (int)$y['number_of_users'] <=> (int)$x['number_of_users']);
$topApps = array_slice($topApps, 0, 10);
usort($recent, fn($x, $y) => strcmp((string)$y['first_event_time'], (string)$x['first_event_time']));
$recent = array_slice($recent, 0, 10);

/** Horizontal bar row: label, count, share of $max, link. */
function adBar(string $label, int $n, int $max, string $href, string $cls = ''): string
{
    $w = $max > 0 ? round(100 * $n / $max, 1) : 0;
    return '<a class="ad-bar" href="' . e($href) . '"><span class="ad-bl">' . e($label) . '</span>'
         . '<span class="ad-track"><span class="ad-fill ' . e($cls) . '" style="width:' . $w . '%"></span></span>'
         . '<span class="ad-n">' . number_format($n) . '</span></a>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-ad.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style nonce="<?php echo cspNonce(); ?>">
        :root {
            --theme-header-color: <?php echo e($theme['header_color'] ?? '#1e3a5f'); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color'] ?? '#1e293b'); ?>;
            --theme-button-color: <?php echo e($theme['button_color'] ?? '#2563eb'); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color'] ?? '#1e293b'); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color'] ?? '#ffffff'); ?>;
            --sidebar-width: <?php echo e($theme['nav_width'] ?? '260'); ?>px;
            --pp-pass: #16a34a; --pp-fail: #dc2626; --pp-muted: #9ca3af;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px; display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px; background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px; }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); max-width: var(--sidebar-width); background: var(--nav-fill-color); padding: 0; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title { color: var(--nav-font-color); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; padding: 0 20px; margin-bottom: 10px; opacity: 0.6; cursor: pointer; list-style: none; display: flex; align-items: center; justify-content: space-between; }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after { content: '\25BC'; font-size: 8px; opacity: 0.5; transition: transform 0.2s ease; margin-right: 2px; }
        .sidebar-section:not([open]) .sidebar-section-title::after { transform: rotate(-90deg); }
        .sidebar-nav { list-style: none; margin: 0; padding: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 10px 20px; color: var(--nav-font-color); text-decoration: none; font-size: 14px; transition: background 0.2s; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); font-weight: 500; border-left: 3px solid var(--theme-button-color); }
        .sidebar-nav li a .icon { width: 20px; text-align: center; font-size: 16px; }
        .sidebar-module { border-top: 1px solid rgba(255,255,255,0.08); margin-top: 4px; }
        .sidebar-module:first-of-type { margin-top: 0; border-top: none; }
        .sidebar-module > summary.sidebar-module-header { display: flex; align-items: center; padding: 12px 20px 8px; cursor: pointer; list-style: none; user-select: none; }
        .sidebar-module > summary.sidebar-module-header::-webkit-details-marker { display: none; }
        .sidebar-module-header .module-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--theme-button-color, #ff6543); }
        .sidebar-module-body { padding-top: 0; padding-bottom: 4px; position: relative; }
        .sidebar-module-body::before { content: ""; display: block; height: 2px; background: var(--theme-button-color, #ff6543); margin: 0 20px 8px; opacity: 0.5; border-radius: 1px; }
        .sidebar-module .sidebar-nav li a { font-size: 12px; padding-top: 9px; padding-bottom: 9px; }
        .sidebar-module .sidebar-section { margin-bottom: 16px; }
        .sidebar-module .sidebar-section-title { font-size: 9px; }
        .main-content { flex: 1; padding: 30px; overflow-y: auto; min-width: 0; }
        .page-title { font-size: 24px; font-weight: 600; color: #1f2937; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-danger { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #dcfce7; color: #166534; }

        /* Shadow SaaS section tabs (shared look with shadow-saas.php) */
        .ss-tabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin: 0 0 22px; flex-wrap: wrap; }
        .ss-tabs a { padding: 9px 18px; font-size: 14px; font-weight: 500; color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .ss-tabs a:hover { color: #111827; }
        .ss-tabs a.active { color: var(--theme-button-color); border-bottom-color: var(--theme-button-color); }


        .pp-muted { color: var(--pp-muted); }
        .pp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; flex-wrap: wrap; margin-bottom: 18px; }
        .pp-head p { color: #6b7280; font-size: 14px; margin: 6px 0 0; }
        .pp-btn { background: var(--theme-button-color); color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block; }
        .pp-chip { font-size: 13px; padding: 5px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
        .pp-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
        .pp-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .ai-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .ai-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; text-decoration: none; color: inherit; display: block; }
        .ai-stat:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .ai-stat .v { font-size: 26px; font-weight: 700; color: #111827; line-height: 1.1; }
        .ai-stat .l { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .ai-stat.warn .v { color: #b45309; }
        .ai-stat.bad .v { color: #b91c1c; }
        .pp-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
        .pp-filters select, .pp-filters input[type=search] { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; max-width: 100%; }
        .pp-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .pp-listhead { padding: 10px 16px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .pp-listhead a { color: inherit; text-decoration: none; white-space: nowrap; }
        .pp-listhead a.on { color: var(--theme-button-color); }
        .pp-row > summary { padding: 11px 16px; cursor: pointer; list-style: none; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-row:first-of-type > summary { border-top: none; }
        .pp-row > summary::-webkit-details-marker { display: none; }
        .pp-row > summary:hover, .pp-row[open] > summary { background: #f9fafb; }
        .pp-body { padding: 4px 16px 18px; background: #f9fafb; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-body h4 { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 16px 0 6px; }
        .ai-name { font-weight: 600; color: #111827; }
        .ai-sub { font-size: 12px; color: #6b7280; }
        .ai-sub a, .ai-link { color: var(--theme-button-color); text-decoration: none; }
        .ai-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border: 1px solid #e5e7eb; }
        .ai-table th { text-align: left; background: #f3f4f6; padding: 6px 8px; font-weight: 600; color: #6b7280; }
        .ai-table td { padding: 6px 8px; border-top: 1px solid #f3f4f6; vertical-align: top; word-break: break-word; }
        .lv-bad { background: #fee2e2; color: #991b1b; }
        .lv-warn { background: #ffedd5; color: #9a3412; }
        .lv-ok { background: #dcfce7; color: #166534; }
        .lv-na { background: #f3f4f6; color: #4b5563; }
        .lv-ai { background: #ede9fe; color: #5b21b6; }
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin: 14px 0; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 13px; color: #6b7280; }
        .pagination { display: flex; list-style: none; gap: 4px; padding: 0; margin: 0; }
        .pagination li a, .pagination li span { display: inline-block; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-decoration: none; color: #374151; }
        .pagination li.active span { background: var(--theme-button-color); color: white; border-color: var(--theme-button-color); }
        .pagination li.disabled span { color: #d1d5db; }
        .per-page-group { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }

        .ad-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .ad-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; min-width: 0; }
        .ad-card h3 { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 12px; }
        .ad-bar { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(60px, 1.5fr) 56px; gap: 10px; align-items: center; padding: 5px 0; text-decoration: none; color: #374151; font-size: 13px; }
        .ad-bar:hover .ad-bl { color: var(--theme-button-color); }
        .ad-bl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ad-track { height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden; }
        .ad-fill { display: block; height: 100%; background: #8b5cf6; }
        .ad-fill.bad { background: #dc2626; } .ad-fill.warn { background: #f59e0b; } .ad-fill.ok { background: #16a34a; } .ad-fill.na { background: #9ca3af; }
        .ad-n { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
        .ad-list { list-style: none; margin: 0; padding: 0; }
        .ad-list li { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-top: 1px solid #f3f4f6; font-size: 13px; }
        .ad-list li:first-child { border-top: none; }
        .ad-list a { color: #111827; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ad-list a:hover { color: var(--theme-button-color); }
        @media (max-width: 768px) { .main-layout { flex-direction: column; } .sidebar { width: 100%; max-width: 100%; min-width: 0; } .main-content { padding: 16px; } }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php $currentPage = 'shadow_saas_ai_dashboard'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'ai_dashboard'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-ad.title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-ad.intro')); ?></p>
                    </div>
                </div>
                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.grip_disabled')); ?></div>
                <?php else: ?>
                <div class="ai-stats" id="ad-tiles">
                    <a class="ai-stat" href="shadow-saas-ai-apps.php"><div class="v"><?php echo number_format($ad['apps']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_ai_apps')); ?></div></a>
                    <a class="ai-stat bad" href="shadow-saas-ai-apps.php?type=yes"><div class="v"><?php echo number_format($ad['trains']['yes']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_trains')); ?></div></a>
                    <a class="ai-stat warn" href="shadow-saas-ai-apps.php?view=not_onboarded"><div class="v"><?php echo number_format($ad['not_tprm']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_not_tprm')); ?></div></a>
                    <div class="ai-stat"><div class="v"><?php echo number_format($ad['users']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_users')); ?></div></div>
                    <a class="ai-stat" href="shadow-saas-ai-agents.php"><div class="v"><?php echo number_format($ad['agents']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_agents')); ?></div></a>
                    <a class="ai-stat warn" href="shadow-saas-ai-agents.php?status=New"><div class="v"><?php echo number_format($ad['sanction']['New'] ?? 0); ?></div><div class="l"><?php echo e(t('shadow-saas-ad.unreviewed_agents')); ?></div></a>
                    <a class="ai-stat bad" href="shadow-saas-ai-agents.php?type=high"><div class="v"><?php echo number_format($ad['high']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_high_risk')); ?></div></a>
                    <a class="ai-stat warn" href="shadow-saas-ai-agents.php?type=internet"><div class="v"><?php echo number_format($ad['internet']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_internet')); ?></div></a>
                </div>

                <div class="ad-grid">
                    <div class="ad-card" id="ad-depth">
                        <h3><?php echo e(t('shadow-saas-ad.apps_by_depth')); ?></h3>
                        <?php $mx = max(1, max($ad['depth'])); foreach ($ad['depth'] as $dl => $n) echo adBar($dl, $n, $mx, 'shadow-saas-ai-apps.php?' . http_build_query(['filter' => $dl])); ?>
                    </div>
                    <div class="ad-card" id="ad-trains">
                        <h3><?php echo e(t('shadow-saas-ad.trains_breakdown')); ?></h3>
                        <?php $mx = max(1, max($ad['trains'])); $cls = ['yes' => 'bad', 'optional' => 'warn', 'no' => 'ok', 'unknown' => 'na'];
                        foreach ($ad['trains'] as $k => $n) echo adBar(t('shadow-saas-ai.trains_' . $k), $n, $mx, 'shadow-saas-ai-apps.php?type=' . $k, $cls[$k]); ?>
                    </div>
                    <div class="ad-card" id="ad-sanction">
                        <h3><?php echo e(t('shadow-saas-ad.agents_by_sanction')); ?></h3>
                        <?php if (!$ad['sanction']): ?><div class="pp-muted"><?php echo e(t('shadow-saas-ai.no_agents')); ?></div><?php endif; ?>
                        <?php $mx = max(1, $ad['sanction'] ? max($ad['sanction']) : 1); foreach ($ad['sanction'] as $ss => $n) echo adBar($ss, $n, $mx, 'shadow-saas-ai-agents.php?' . http_build_query(['status' => $ss]), $ss === 'Sanctioned' ? 'ok' : ($ss === 'New' ? 'warn' : '')); ?>
                    </div>
                </div>
                <div class="ad-grid">
                    <div class="ad-card" id="ad-top">
                        <h3><?php echo e(t('shadow-saas-ad.top_apps')); ?></h3>
                        <ul class="ad-list"><?php foreach ($topApps as $a): ?><li><a href="shadow-saas-ai-apps.php?<?php echo e(http_build_query(['q' => (string)$a['name']])); ?>"><?php echo e($a['name']); ?></a><span><?php echo number_format((int)$a['number_of_users']); ?></span></li><?php endforeach; ?></ul>
                    </div>
                    <div class="ad-card" id="ad-recent">
                        <h3><?php echo e(t('shadow-saas-ad.recent_apps')); ?></h3>
                        <?php if (!$recent): ?><div class="pp-muted"><?php echo e(t('shadow-saas-ad.none_recent')); ?></div><?php endif; ?>
                        <ul class="ad-list"><?php foreach ($recent as $a): ?><li><a href="shadow-saas-ai-apps.php?<?php echo e(http_build_query(['q' => (string)$a['name']])); ?>"><?php echo e($a['name']); ?></a><span class="ai-sub"><?php echo e(date('M j', strtotime($a['first_event_time']))); ?></span></li><?php endforeach; ?></ul>
                    </div>
                    <div class="ad-card" id="ad-alerts">
                        <h3><?php echo e(t('shadow-saas-ad.ai_alerts', $ad['alerts30'])); ?></h3>
                        <?php if (!$adAlerts): ?><div class="pp-muted"><?php echo e(t('shadow-saas-ad.no_alerts')); ?></div><?php endif; ?>
                        <ul class="ad-list"><?php foreach (array_slice($adAlerts, 0, 8) as $al): ?><li><span class="ad-bl"><?php echo e((string)$al['related_entity_name']); ?></span><span class="ai-sub"><?php echo $al['created_at_grip'] ? e(date('M j, Y', strtotime($al['created_at_grip']))) : ''; ?></span></li><?php endforeach; ?></ul>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php"><img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;"></a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('vendor-domains.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/mobile-nav.js?v=2"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
