<?php
/**
 * Shadow SaaS - AI Security - AI Agents.
 *
 * The AI agents Grip has discovered (custom GPTs, copilots and similar), with
 * owner, risk score, sanction state, whether the agent can reach the internet,
 * how it is shared, the knowledge files it was given, who it is shared with,
 * and the SaaS scopes it holds. Mirrors GET /public/ai-agents into
 * shadow_saas_grip_ai_agents on every Grip sync or with "Refresh from Grip".
 * Owner emails, file names and permission recipients are encrypted at rest.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas-posture.invalid_request');
    } elseif (!$canManage) {
        http_response_code(403);
        $error = t('shadow-saas.access_denied');
    } elseif (($_POST['action'] ?? '') === 'refresh_agents' && $gripEnabled) {
        try {
            $n = $grip->syncAiAgents();
            $auth->audit($user['id'], 'shadow_saas_ai_agents_refresh', 'shadow_saas_grip_ai_agents', null, ['new' => ['agents' => $n]]);
            $success = t('shadow-saas-ai.agents_refreshed', $n);
        } catch (Throwable $e) {
            error_log('AI agents refresh failed: ' . $e->getMessage());
            $error = t('shadow-saas-ai.agents_refresh_failed');
        }
    }
}
$csrfToken = $security->generateCSRFToken();

function agDate(?string $d): string
{
    if (!$d) return '<span class="pp-muted">&mdash;</span>';
    $ts = strtotime($d);
    return $ts ? e(date('M j, Y', $ts)) : e($d);
}
/** Risk band for Grip's agent score (higher is riskier). */
function agScoreClass($s): string
{
    if ($s === null || $s === '') return 'lv-na';
    $s = (int)$s;
    return $s >= 70 ? 'lv-bad' : ($s >= 40 ? 'lv-warn' : 'lv-ok');
}

$agents = [];
$agSynced = null;
if ($gripEnabled) {
    try {
        foreach ($db->fetchAll('SELECT * FROM shadow_saas_grip_ai_agents') as $r) {
            $agents[] = $grip->decryptGripRow('shadow_saas_grip_ai_agents', $r);
        }
        $agSynced = $db->fetchOne('SELECT MAX(last_synced_at) AS t FROM shadow_saas_grip_ai_agents')['t'] ?? null;
    } catch (Throwable $e) {
        error_log('AI agents load failed: ' . $e->getMessage());
        $error = $error ?: t('shadow-saas-posture.load_failed');
    }
}

$agStat = ['total' => count($agents), 'internet' => 0, 'files' => 0, 'high' => 0, 'sanction' => []];
foreach ($agents as $a) {
    if ((int)($a['connected_to_internet'] ?? 0) === 1) $agStat['internet']++;
    if ((int)($a['knowledge_files_count'] ?? 0) > 0) $agStat['files']++;
    if ($a['score'] !== null && (int)$a['score'] >= 70) $agStat['high']++;
    $ss = (string)($a['sanction_state'] ?? '') ?: 'Unknown';
    $agStat['sanction'][$ss] = ($agStat['sanction'][$ss] ?? 0) + 1;
}
arsort($agStat['sanction']);

$fSanction = (string)($_GET['status'] ?? '');
if (!isset($agStat['sanction'][$fSanction])) $fSanction = '';
$fNet = (string)($_GET['type'] ?? '');
if (!in_array($fNet, ['internet', 'files', 'high'], true)) $fNet = '';
$fQ = trim((string)($_GET['q'] ?? ''));

$agList = array_values(array_filter($agents, function ($a) use ($fSanction, $fNet, $fQ) {
    if ($fSanction !== '' && ((string)($a['sanction_state'] ?? '') ?: 'Unknown') !== $fSanction) return false;
    if ($fNet === 'internet' && (int)($a['connected_to_internet'] ?? 0) !== 1) return false;
    if ($fNet === 'files' && (int)($a['knowledge_files_count'] ?? 0) === 0) return false;
    if ($fNet === 'high' && ($a['score'] === null || (int)$a['score'] < 70)) return false;
    if ($fQ !== '' && stripos(($a['name'] ?? '') . ' ' . ($a['owner_email'] ?? '') . ' ' . ($a['site_url'] ?? ''), $fQ) === false) return false;
    return true;
}));
usort($agList, fn($a, $b) => [(int)($b['score'] ?? -1), (string)($b['last_usage'] ?? '')] <=> [(int)($a['score'] ?? -1), (string)($a['last_usage'] ?? '')]);

function agQuery(array $over = []): string
{
    global $fSanction, $fNet, $fQ;
    $q = array_merge(['status' => $fSanction, 'type' => $fNet, 'q' => $fQ], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-ai.agents_title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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

        .ag-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.2fr) 60px 90px 80px 70px 70px 100px; gap: 10px; align-items: center; }
        @media (max-width: 1100px) { .ag-grid { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) 60px 90px; } .ag-grid .ag-hide-md { display: none; } }
        @media (max-width: 700px) { .pp-listhead { display: none; } .ag-grid { grid-template-columns: 1fr auto; } .ag-grid .ag-hide-sm { display: none; } }
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
            <?php $currentPage = 'shadow_saas_ai_agents'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'ai_agents'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-ai.agents_title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-ai.agents_intro')); ?></p>
                        <?php if ($agSynced): ?><p class="ai-sub"><?php echo e(t('shadow-saas-posture.last_synced', date('M j, Y g:i A', strtotime($agSynced)))); ?></p><?php endif; ?>
                    </div>
                    <?php if ($canManage && $gripEnabled): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="refresh_agents">
                        <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.refresh')); ?></button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.grip_disabled')); ?></div>
                <?php elseif (!$agents): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.no_agents')); ?></div>
                <?php else: ?>
                <div class="ai-stats" id="ag-stats">
                    <a class="ai-stat" href="shadow-saas-ai-agents.php"><div class="v"><?php echo (int)$agStat['total']; ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_agents')); ?></div></a>
                    <?php foreach ($agStat['sanction'] as $ss => $n): ?>
                    <a class="ai-stat<?php echo $ss === 'New' ? ' warn' : ''; ?>" href="<?php echo e(agQuery(['status' => $ss])); ?>"><div class="v"><?php echo (int)$n; ?></div><div class="l"><?php echo e(t('shadow-saas-ai.sanction_state_n', $ss)); ?></div></a>
                    <?php endforeach; ?>
                    <a class="ai-stat bad" href="<?php echo e(agQuery(['type' => 'high'])); ?>"><div class="v"><?php echo (int)$agStat['high']; ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_high_risk')); ?></div></a>
                    <a class="ai-stat warn" id="ag-stat-internet" href="<?php echo e(agQuery(['type' => 'internet'])); ?>"><div class="v"><?php echo (int)$agStat['internet']; ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_internet')); ?></div></a>
                    <a class="ai-stat" href="<?php echo e(agQuery(['type' => 'files'])); ?>"><div class="v"><?php echo (int)$agStat['files']; ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_files')); ?></div></a>
                </div>

                <form method="GET" class="pp-filters">
                    <select name="status" aria-label="<?php echo e(t('shadow-saas-ai.col_sanction')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.any_sanction')); ?></option>
                        <?php foreach (array_keys($agStat['sanction']) as $ss): ?><option value="<?php echo e($ss); ?>" <?php echo $fSanction === $ss ? 'selected' : ''; ?>><?php echo e($ss); ?></option><?php endforeach; ?>
                    </select>
                    <select name="type" aria-label="<?php echo e(t('shadow-saas-ai.agent_filter')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.all_agents')); ?></option>
                        <option value="high" <?php echo $fNet === 'high' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.stat_high_risk')); ?></option>
                        <option value="internet" <?php echo $fNet === 'internet' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.stat_internet')); ?></option>
                        <option value="files" <?php echo $fNet === 'files' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.stat_files')); ?></option>
                    </select>
                    <input type="search" name="q" value="<?php echo e($fQ); ?>" placeholder="<?php echo e(t('shadow-saas-ai.search_agents')); ?>">
                    <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                    <?php if ($fSanction !== '' || $fNet !== '' || $fQ !== ''): ?><a class="pp-chip" href="shadow-saas-ai-agents.php"><?php echo e(t('shadow-saas-posture.clear')); ?></a><?php endif; ?>
                </form>

                <?php if (!$agList): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.no_matches')); ?></div>
                <?php else: ?>
                <div class="pp-list" id="ag-list">
                    <div class="pp-listhead ag-grid">
                        <span><?php echo e(t('shadow-saas-ai.col_agent')); ?></span>
                        <span class="ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_owner')); ?></span>
                        <span><?php echo e(t('shadow-saas-ai.col_score')); ?></span>
                        <span class="ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_sanction')); ?></span>
                        <span class="ag-hide-md ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_internet')); ?></span>
                        <span class="ag-hide-md ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_files')); ?></span>
                        <span class="ag-hide-md ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_scopes')); ?></span>
                        <span class="ag-hide-md ag-hide-sm"><?php echo e(t('shadow-saas-ai.col_last_used')); ?></span>
                    </div>
                    <?php foreach ($agList as $a):
                        $files = json_decode((string)($a['knowledge_files'] ?? ''), true);
                        $perms = json_decode((string)($a['identities_permissions'] ?? ''), true);
                        $scopes = json_decode((string)($a['saas_scopes'] ?? ''), true);
                        $files = is_array($files) ? $files : [];
                        $perms = is_array($perms) ? $perms : [];
                        $scopes = is_array($scopes) ? $scopes : [];
                        $host = !empty($a['site_url']) ? (string)parse_url($a['site_url'], PHP_URL_HOST) : '';
                    ?>
                    <details class="pp-row" data-agent="<?php echo e($a['grip_id']); ?>">
                        <summary class="ag-grid">
                            <span><span class="ai-name"><?php echo e($a['name'] ?? ''); ?></span><?php if ($host !== ''): ?><div class="ai-sub"><?php echo e($host); ?></div><?php endif; ?></span>
                            <span class="ag-hide-sm ai-sub" style="word-break:break-all;"><?php echo e($a['owner_email'] ?? ''); ?></span>
                            <span><span class="pp-pill <?php echo agScoreClass($a['score']); ?>"><?php echo $a['score'] !== null ? (int)$a['score'] : '&mdash;'; ?></span></span>
                            <span class="ag-hide-sm"><span class="pp-pill <?php echo ($a['sanction_state'] ?? '') === 'Sanctioned' ? 'lv-ok' : (in_array($a['sanction_state'] ?? '', ['NotSanctioned', 'Unsanctioned'], true) ? 'lv-bad' : 'lv-warn'); ?>"><?php echo e($a['sanction_state'] ?? ''); ?></span></span>
                            <span class="ag-hide-md ag-hide-sm"><?php echo (int)($a['connected_to_internet'] ?? 0) === 1 ? '<span class="pp-pill lv-warn">' . e(t('shadow-saas-posture.yes')) . '</span>' : e(t('shadow-saas-posture.no')); ?></span>
                            <span class="ag-hide-md ag-hide-sm"><?php echo (int)($a['knowledge_files_count'] ?? 0); ?></span>
                            <span class="ag-hide-md ag-hide-sm"><?php echo count($scopes); ?></span>
                            <span class="ag-hide-md ag-hide-sm ai-sub"><?php echo agDate($a['last_usage'] ?? null); ?></span>
                        </summary>
                        <div class="pp-body">
                            <h4><?php echo e(t('shadow-saas-ai.agent_details')); ?></h4>
                            <div class="ai-sub" style="font-size:13px;color:#374151;">
                                <?php if (!empty($a['site_url'])): ?><a class="ai-link" href="<?php echo e($a['site_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($a['site_url']); ?></a><br><?php endif; ?>
                                <?php echo e(t('shadow-saas-ai.created')); ?>: <?php echo agDate($a['creation_date'] ?? null); ?> &middot;
                                <?php echo e(t('shadow-saas-ai.last_seen')); ?>: <?php echo agDate($a['last_seen'] ?? null); ?> &middot;
                                <?php echo e(t('shadow-saas-ai.col_last_used')); ?>: <?php echo agDate($a['last_usage'] ?? null); ?>
                                <?php if (!empty($a['sharing_attributes'])): ?><br><?php echo e(t('shadow-saas-ai.sharing')); ?>: <?php echo e($a['sharing_attributes']); ?><?php endif; ?>
                            </div>
                            <?php if ($files): ?>
                                <h4><?php echo e(t('shadow-saas-ai.knowledge_files')); ?> (<?php echo count($files); ?>)</h4>
                                <table class="ai-table"><thead><tr><th><?php echo e(t('shadow-saas-ai.file_name')); ?></th><th><?php echo e(t('shadow-saas-ai.file_format')); ?></th></tr></thead><tbody>
                                <?php foreach (array_slice($files, 0, 200) as $f): if (!is_array($f)) continue; ?><tr><td><?php echo e((string)($f['fileName'] ?? '')); ?></td><td><?php echo e((string)($f['fileFormat'] ?? '')); ?></td></tr><?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                            <?php if ($perms): ?>
                                <h4><?php echo e(t('shadow-saas-ai.shared_with')); ?> (<?php echo count($perms); ?>)</h4>
                                <table class="ai-table"><thead><tr><th><?php echo e(t('shadow-saas-ai.recipient')); ?></th><th><?php echo e(t('shadow-saas-ai.permission')); ?></th></tr></thead><tbody>
                                <?php foreach (array_slice($perms, 0, 200) as $p): if (!is_array($p)) continue; ?><tr><td><?php echo e((string)($p['recipientEmail'] ?? ($p['recipientId'] ?? ''))); ?></td><td><?php echo e((string)($p['permission'] ?? '')); ?></td></tr><?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                            <?php if ($scopes): ?>
                                <h4><?php echo e(t('shadow-saas-ai.saas_scopes')); ?> (<?php echo count($scopes); ?>)</h4>
                                <table class="ai-table"><thead><tr><th><?php echo e(t('shadow-saas-new.col_app')); ?></th><th><?php echo e(t('shadow-saas-ai.auth_type')); ?></th><th><?php echo e(t('shadow-saas-ai.scope')); ?></th><th><?php echo e(t('shadow-saas-ai.created')); ?></th></tr></thead><tbody>
                                <?php foreach (array_slice($scopes, 0, 200) as $sc): if (!is_array($sc)) continue; ?><tr><td><?php echo e((string)($sc['saasApplicationName'] ?? '')); ?></td><td><?php echo e((string)($sc['nhiAuthType'] ?? '')); ?></td><td><?php echo e((string)($sc['scope'] ?? '')); ?></td><td><?php echo agDate($sc['creationDate'] ?? null); ?></td></tr><?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
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
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
