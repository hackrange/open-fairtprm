<?php
/**
 * Shadow SaaS - New (recently discovered SaaS from Grip).
 *
 * Every Grip SaaS application first seen in the last N days (default 30),
 * newest first: first seen, users, category, AI depth, Grip sanction tag, the
 * app's FairTPRM state (pending / unsanctioned / dismissed / onboarded vendor),
 * how Grip detected it, the Grip discovery alert, and patient zero - the
 * earliest user Grip observed on the app (from the per-app roster; the sync
 * snapshots rosters of apps discovered in the last GripService::RECENT_ROSTER_DAYS
 * days). Pending apps can be selected and onboarded through the same
 * onboard_selected path as the Applications list, which also marks them
 * Sanctioned in Grip.
 *
 * Access: administrator / cyber_tprm / auditor (auditors are read-only).
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

$days = (int)($_GET['days'] ?? 30);
if ($days < 1 || $days > 365) $days = 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas-posture.invalid_request');
    } elseif (!$canManage) {
        http_response_code(403);
        $error = t('shadow-saas.access_denied');
    } elseif (($_POST['action'] ?? '') === 'refresh_recent' && $gripEnabled) {
        $n = $grip->refreshRecentRosters($days);
        $auth->audit($user['id'], 'shadow_saas_recent_refresh', 'shadow_saas_grip_app_users', null, ['new' => ['days' => $days, 'rosters' => $n]]);
        $success = t('shadow-saas-new.refreshed', $n);
    }
}
$csrfToken = $security->generateCSRFToken();

function snDate(?string $d): string
{
    if (!$d) return '<span class="pp-muted">&mdash;</span>';
    $ts = strtotime($d);
    return $ts ? e(date('M j, Y', $ts)) : e($d);
}

// ---------------------------------------------------------------------------
// Data: Grip apps first seen in the window, with their FairTPRM state.
// ---------------------------------------------------------------------------
$snApps = [];
if ($gripEnabled) {
    try {
        $snApps = $db->fetchAll(
            "SELECT a.grip_id, a.name, a.domain, a.category, a.number_of_users, a.first_event_time,
                    a.last_known_usage, a.sanction_tag, a.ai_depth_level, a.source_platforms, a.risk_score,
                    s.id AS ss_id, s.status AS ss_status, s.security_scorecard_rating,
                    (SELECT v.id FROM vendor_onboarding_requests v
                      WHERE a.domain IS NOT NULL AND a.domain != '' AND v.vendor_domain = a.domain
                        AND v.status != 'inactive' ORDER BY v.id LIMIT 1) AS vendor_id
               FROM shadow_saas_grip_apps a
               LEFT JOIN shadow_saas s ON s.grip_id = a.grip_id
              WHERE a.first_event_time >= :cutoff
              ORDER BY a.first_event_time DESC, a.name ASC
              LIMIT 1000",
            // Grip times are stored in the app time zone (GripService::parseDate),
            // not the database's, so the cutoff is computed in PHP to match.
            [':cutoff' => date('Y-m-d H:i:s', time() - $days * 86400)]
        );
    } catch (Throwable $e) {
        error_log('Shadow SaaS New load failed: ' . $e->getMessage());
        $error = $error ?: t('shadow-saas-posture.load_failed');
    }
}

// Patient zero per app: the roster row with the earliest first event. Only the
// chosen row is decrypted. Ties (same first event) are counted, not listed.
$snZero = [];
$snAlert = [];
$ids = array_values(array_filter(array_map(fn($r) => (string)$r['grip_id'], $snApps)));
if ($ids) {
    $ph = [];
    $params = [];
    foreach ($ids as $i => $gid) { $ph[] = ":g{$i}"; $params[":g{$i}"] = $gid; }
    $in = implode(',', $ph);
    try {
        $rows = $db->fetchAll(
            "SELECT grip_saas_id, mail, full_name, display_name, authentication_type, sso, first_event_time
               FROM shadow_saas_grip_app_users
              WHERE grip_saas_id IN ({$in}) AND first_event_time IS NOT NULL
              ORDER BY grip_saas_id, first_event_time ASC",
            $params
        );
        foreach ($rows as $r) {
            $gid = (string)$r['grip_saas_id'];
            if (!isset($snZero[$gid])) {
                $snZero[$gid] = ['row' => $grip->decryptGripRow('shadow_saas_grip_app_users', $r), 'ties' => 0];
            } elseif ($r['first_event_time'] === $snZero[$gid]['row']['first_event_time']) {
                $snZero[$gid]['ties']++;
            }
        }
    } catch (Throwable $e) {
        error_log('Shadow SaaS New patient-zero load failed: ' . $e->getMessage());
    }
    try {
        foreach ($db->fetchAll(
            "SELECT related_entity_id, alert_type, created_at_grip, alert_url
               FROM shadow_saas_grip_alerts
              WHERE related_entity_id IN ({$in}) AND alert_type LIKE 'New Unmanaged%'
              ORDER BY created_at_grip ASC",
            $params
        ) as $a) {
            $snAlert[(string)$a['related_entity_id']] ??= $a;
        }
    } catch (Throwable $e) {
        error_log('Shadow SaaS New alert load failed: ' . $e->getMessage());
    }
}
$snMissingZero = count(array_filter($snApps, fn($r) => (int)$r['number_of_users'] > 0 && !isset($snZero[(string)$r['grip_id']])));
$snPendingCount = count(array_filter($snApps, fn($r) => ($r['ss_status'] ?? '') === 'pending' && empty($r['vendor_id'])));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-new.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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
        .pp-btn:disabled { opacity: .5; cursor: not-allowed; }
        .pp-chip { font-size: 13px; padding: 5px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
        .pp-chip.on { background: var(--theme-button-color); color: #fff; border-color: var(--theme-button-color); font-weight: 600; }
        .pp-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
        .pp-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .sn-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .sn-days { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .sn-days input[type=number] { width: 80px; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }
        .data-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .data-table th { background: #f9fafb; padding: 11px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .data-table td { padding: 11px 12px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .data-table tr:hover td { background: #f9fafb; }
        .sn-name { font-weight: 600; color: #111827; }
        .sn-sub { font-size: 12px; color: #6b7280; }
        .sn-sub a, .sn-link { color: var(--theme-button-color); text-decoration: none; }
        .sn-sub a:hover, .sn-link:hover { text-decoration: underline; }
        .st-pending { background: #fef3c7; color: #92400e; }
        .st-unsanctioned { background: #fee2e2; color: #991b1b; }
        .st-dismissed { background: #f3f4f6; color: #6b7280; }
        .st-onboarded { background: #dcfce7; color: #166534; }
        .tag-sanctioned { background: #dcfce7; color: #166534; }
        .tag-unsanctioned { background: #fee2e2; color: #991b1b; }
        .tag-other { background: #f3f4f6; color: #4b5563; }
        .tag-ai { background: #ede9fe; color: #5b21b6; }
        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }
        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; max-width: 100%; min-width: 0; }
            .main-content { padding: 16px; }
        }
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
            <?php $currentPage = 'shadow_saas_new'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <nav class="ss-tabs" aria-label="<?php echo e(t('shadow-saas.heading')); ?>">
                    <a href="shadow-saas.php"><?php echo e(t('shadow-saas-posture.tab_applications')); ?></a>
                    <a href="shadow-saas-new.php" class="active"><?php echo e(t('shadow-saas-new.tab_new')); ?></a>
                    <a href="shadow-saas-posture.php"><?php echo e(t('shadow-saas-posture.tab_posture')); ?></a>
                    <a href="shadow-saas-itdr.php"><?php echo e(t('shadow-saas-itdr.tab_itdr')); ?></a>
                </nav>

                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-new.title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-new.intro')); ?></p>
                    </div>
                    <?php if ($canManage && $gripEnabled): ?>
                    <form method="POST" action="?<?php echo e(http_build_query(['days' => $days])); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="refresh_recent">
                        <button type="submit" class="pp-btn" title="<?php echo e(t('shadow-saas-new.refresh_title')); ?>"><?php echo e(t('shadow-saas-posture.refresh')); ?></button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-new.grip_disabled')); ?></div>
                <?php else: ?>
                <div class="sn-bar">
                    <form method="GET" class="sn-days" id="sn-days">
                        <span class="sn-sub"><?php echo e(t('shadow-saas-new.first_seen_within')); ?></span>
                        <?php foreach ([7, 30, 90] as $d): ?>
                            <a class="pp-chip<?php echo $days === $d ? ' on' : ''; ?>" href="?days=<?php echo $d; ?>"><?php echo e(t('shadow-saas-new.n_days', $d)); ?></a>
                        <?php endforeach; ?>
                        <input type="number" name="days" min="1" max="365" value="<?php echo (int)$days; ?>" aria-label="<?php echo e(t('shadow-saas-new.days_label')); ?>">
                        <button type="submit" class="pp-chip"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                    </form>
                    <span class="sn-sub" id="sn-count"><?php echo e(t('shadow-saas-new.count', count($snApps), $days)); ?></span>
                </div>
                <?php if ($snMissingZero > 0): ?>
                    <p class="sn-sub" style="margin:-4px 0 12px;"><?php echo e(t('shadow-saas-new.missing_zero', $snMissingZero)); ?></p>
                <?php endif; ?>

                <?php if (!$snApps): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-new.none', $days)); ?></div>
                <?php else: ?>
                <form method="POST" action="shadow-saas.php" id="sn-onboard">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="onboard_selected">
                    <?php if ($canManage): ?>
                    <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
                        <button type="submit" class="pp-btn" id="sn-onboard-btn" <?php echo $snPendingCount ? '' : 'disabled'; ?>><?php echo e(t('shadow-saas.onboard_selected')); ?></button>
                    </div>
                    <?php endif; ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table" id="sn-table">
                        <thead><tr>
                            <?php if ($canManage): ?><th style="width:34px;"></th><?php endif; ?>
                            <th><?php echo e(t('shadow-saas-new.col_app')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_first_seen')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_users')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_patient_zero')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_detected_via')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_grip')); ?></th>
                            <th><?php echo e(t('shadow-saas-new.col_fairtprm')); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($snApps as $a):
                            $gid = (string)$a['grip_id'];
                            $tag = strtolower((string)($a['sanction_tag'] ?? ''));
                            $platforms = json_decode((string)($a['source_platforms'] ?? ''), true);
                            $platforms = is_array($platforms) ? array_filter(array_map(fn($p) => is_scalar($p) ? (string)$p : '', $platforms)) : [];
                            $z = $snZero[$gid] ?? null;
                            $al = $snAlert[$gid] ?? null;
                            if (!empty($a['vendor_id']))                         { $state = 'onboarded'; }
                            elseif (($a['ss_status'] ?? '') === 'pending' && $tag === 'unsanctioned') { $state = 'unsanctioned'; }
                            else                                                 { $state = (string)($a['ss_status'] ?? ''); }
                            $ageDays = $a['first_event_time'] ? (int)floor((time() - strtotime($a['first_event_time'])) / 86400) : null;
                        ?>
                        <tr data-grip-id="<?php echo e($gid); ?>">
                            <?php if ($canManage): ?>
                            <td><?php if (($a['ss_status'] ?? '') === 'pending' && empty($a['vendor_id']) && !empty($a['ss_id'])): ?>
                                <input type="checkbox" name="selected[]" value="<?php echo (int)$a['ss_id']; ?>" class="sn-check" aria-label="<?php echo e($a['name']); ?>">
                            <?php endif; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="sn-name"><?php echo e($a['name']); ?>
                                    <?php if (!empty($a['ai_depth_level'])): ?><span class="pp-pill tag-ai" title="<?php echo e(t('shadow-saas-new.ai_depth')); ?>">AI: <?php echo e($a['ai_depth_level']); ?></span><?php endif; ?>
                                </div>
                                <div class="sn-sub">
                                    <?php if (!empty($a['domain'])): ?><a href="https://<?php echo e($a['domain']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($a['domain']); ?></a><?php endif; ?>
                                    <?php if (!empty($a['category'])): ?><?php echo !empty($a['domain']) ? ' &middot; ' : ''; ?><?php echo e($a['category']); ?><?php endif; ?>
                                </div>
                            </td>
                            <td style="white-space:nowrap;"><?php echo snDate($a['first_event_time']); ?>
                                <?php if ($ageDays !== null): ?><div class="sn-sub"><?php echo e($ageDays === 0 ? t('shadow-saas-new.today') : t('shadow-saas-new.days_ago', $ageDays)); ?></div><?php endif; ?></td>
                            <td><?php if ((int)$a['number_of_users'] > 0): ?><a class="sn-link" href="grip-saas-users.php?id=<?php echo e(urlencode($gid)); ?>" target="_blank" rel="noopener"><?php echo (int)$a['number_of_users']; ?></a><?php else: ?>0<?php endif; ?></td>
                            <td class="sn-zero">
                                <?php if ($z):
                                    $zr = $z['row'];
                                    $zn = trim((string)($zr['full_name'] ?? '')) ?: trim((string)($zr['display_name'] ?? ''));
                                    $zm = trim((string)($zr['mail'] ?? ''));
                                ?>
                                    <div class="sn-name" style="font-weight:500;"><?php echo e($zn !== '' ? $zn : $zm); ?></div>
                                    <?php if ($zn !== '' && $zm !== ''): ?><div class="sn-sub"><?php echo e($zm); ?></div><?php endif; ?>
                                    <div class="sn-sub"><?php echo snDate($zr['first_event_time']); ?><?php if (!empty($zr['authentication_type'])): ?> &middot; <?php echo e($zr['authentication_type']); ?><?php endif; ?><?php if ($z['ties'] > 0): ?> &middot; <?php echo e(t('shadow-saas-new.ties', $z['ties'])); ?><?php endif; ?></div>
                                <?php else: ?><span class="pp-muted">&mdash;</span><?php endif; ?>
                            </td>
                            <td><?php echo $platforms ? e(implode(', ', $platforms)) : '<span class="pp-muted">&mdash;</span>'; ?>
                                <?php if ($al): ?><div class="sn-sub"><?php if (!empty($al['alert_url']) && preg_match('#^https://#i', $al['alert_url'])): ?><a href="<?php echo e($al['alert_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(trim($al['alert_type'])); ?></a><?php else: ?><?php echo e(trim($al['alert_type'])); ?><?php endif; ?> &middot; <?php echo snDate($al['created_at_grip']); ?></div><?php endif; ?>
                            </td>
                            <td><?php if ($tag !== ''): ?><span class="pp-pill <?php echo $tag === 'sanctioned' ? 'tag-sanctioned' : ($tag === 'unsanctioned' ? 'tag-unsanctioned' : 'tag-other'); ?>"><?php echo e(ucwords($tag)); ?></span><?php endif; ?>
                                <?php $rb = GripService::bucketScore($a['risk_score']); if ($rb): ?><div class="sn-sub"><?php echo e(t('shadow-saas-new.grip_risk', $rb)); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($state === 'onboarded'): ?>
                                    <a class="pp-pill st-onboarded" href="vendor-onboarding.php?id=<?php echo (int)$a['vendor_id']; ?>"><?php echo e(t('shadow-saas-new.state_onboarded')); ?></a>
                                <?php elseif ($state !== ''): ?>
                                    <span class="pp-pill st-<?php echo e($state); ?>"><?php echo e($state === 'unsanctioned' && ($a['ss_status'] ?? '') === 'pending' ? t('shadow-saas.status_grip_unsanctioned') : t('shadow-saas-new.state_' . $state)); ?></span>
                                <?php else: ?><span class="pp-muted">&mdash;</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </form>
                <?php endif; ?>
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
    <script nonce="<?php echo cspNonce(); ?>">
        // Onboard selected: require a selection and a confirmation. The dispatch
        // key rides on a hidden input, so the submission never depends on the button.
        (function () {
            var form = document.getElementById('sn-onboard');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                var n = form.querySelectorAll('input.sn-check:checked').length;
                if (n === 0) { e.preventDefault(); alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>); return; }
                var msg = <?php echo json_encode(t('shadow-saas.js_confirm_onboard_prefix')); ?> + n + <?php echo json_encode(t('shadow-saas.js_confirm_onboard_suffix') . ' ' . t('shadow-saas.js_confirm_onboard_grip_note')); ?>;
                if (!confirm(msg)) e.preventDefault();
            });
        })();
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
