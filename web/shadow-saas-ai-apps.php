<?php
/**
 * Shadow SaaS - AI Security - AI Applications.
 *
 * Every SaaS application Grip classifies as having AI capability (AI-Native,
 * AI Copilot Experience, AI Features/Add-on), with its AI privacy profile from
 * Grip's terms analysis: whether it trains on customer data, data retention,
 * third-party sharing, storage location and encryption. Also users, Grip
 * sanction tag and the app's FairTPRM state. Expanding an app shows the full
 * privacy Q&A, the policy references and compliance certifications.
 *
 * Read-only, from the Grip apps mirror (shadow_saas_grip_apps) refreshed by the
 * Grip sync. Access: administrator / cyber_tprm / auditor.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();
require_once __DIR__ . '/includes/classes/GripService.php';
require_once __DIR__ . '/includes/classes/Pagination.php';
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

const AI_DEPTH_ORDER = ['AI-Native App' => 0, 'AI Copilot Experience' => 1, 'AI Features/Add-on' => 2];

/** Pill class for a privacy answer where "High"/"Yes" is the risky end. */
function aiLevelClass(string $q, string $a): string
{
    $a = strtolower($a);
    if ($a === '' || $a === 'unknown' || $a === 'not stated') return 'lv-na';
    switch ($q) {
        case 'Trains on customer data':
            return str_starts_with($a, 'yes') ? 'lv-bad' : ($a === 'optional' ? 'lv-warn' : 'lv-ok');
        case 'Data retained':
        case 'Sharing with 3rd parties':
            return $a === 'high' ? 'lv-bad' : ($a === 'medium' ? 'lv-warn' : ($a === 'low' ? 'lv-ok' : 'lv-na'));
        case 'Encryption':
            return $a === 'provided' ? 'lv-ok' : ($a === 'partial' ? 'lv-warn' : 'lv-bad');
        case 'Data deletion prevented':
            return $a === 'yes' ? 'lv-bad' : ($a === 'no' ? 'lv-ok' : 'lv-na');
    }
    return 'lv-na';
}

$aiApps = [];
if ($gripEnabled) {
    try {
        $rows = $db->fetchAll(
            "SELECT a.grip_id, a.name, a.domain, a.category, a.number_of_users, a.first_event_time, a.sanction_tag,
                    a.ai_depth_level, a.ai_depth_description, a.terms_questions, a.terms_references, a.compliances,
                    s.status AS ss_status, v.vid AS vendor_id
               FROM shadow_saas_grip_apps a
               LEFT JOIN shadow_saas s ON s.grip_id = a.grip_id
               LEFT JOIN (SELECT vendor_domain, MIN(id) AS vid FROM vendor_onboarding_requests
                           WHERE status != 'inactive' AND vendor_domain IS NOT NULL AND vendor_domain != ''
                           GROUP BY vendor_domain) v ON v.vendor_domain = a.domain
              WHERE a.ai_depth_level IS NOT NULL AND a.ai_depth_level != '' AND a.ai_depth_level != 'No AI'
              LIMIT 10000"
        );
        foreach ($rows as $r) {
            $r['qa'] = GripService::aiPrivacyAnswers($r['terms_questions'] ?? null);
            $r['trains'] = GripService::aiTrainsBucket($r['qa']['Trains on customer data']['answer'] ?? '');
            $aiApps[] = $r;
        }
    } catch (Throwable $e) {
        error_log('AI Applications load failed: ' . $e->getMessage());
        $error = t('shadow-saas-posture.load_failed');
    }
}

// Headline numbers (over all AI apps, before filters)
$aiStat = ['total' => count($aiApps), 'users' => 0, 'trains' => 0, 'not_tprm' => 0, 'sanctioned' => 0, 'depth' => []];
foreach ($aiApps as $a) {
    $aiStat['users'] += (int)$a['number_of_users'];
    if ($a['trains'] === 'yes') $aiStat['trains']++;
    if (empty($a['vendor_id'])) $aiStat['not_tprm']++;
    if (strtolower((string)$a['sanction_tag']) === 'sanctioned') $aiStat['sanctioned']++;
    $aiStat['depth'][$a['ai_depth_level']] = ($aiStat['depth'][$a['ai_depth_level']] ?? 0) + 1;
}
uksort($aiStat['depth'], fn($x, $y) => (AI_DEPTH_ORDER[$x] ?? 9) <=> (AI_DEPTH_ORDER[$y] ?? 9));

// Filters. Param names are on Pagination's whitelist so paging keeps them:
// filter = AI depth, type = trains on data, status = Grip sanction, view = FairTPRM state.
$sanctionTags = array_values(array_unique(array_filter(array_map(fn($a) => strtolower((string)$a['sanction_tag']), $aiApps))));
sort($sanctionTags);
$fDepth = (string)($_GET['filter'] ?? '');
if (!isset($aiStat['depth'][$fDepth])) $fDepth = '';
$fTrains = (string)($_GET['type'] ?? '');
if (!in_array($fTrains, ['yes', 'no', 'optional', 'unknown'], true)) $fTrains = '';
$fSanction = (string)($_GET['status'] ?? '');
if (!in_array($fSanction, $sanctionTags, true)) $fSanction = '';
$fView = (string)($_GET['view'] ?? '');
if (!in_array($fView, ['onboarded', 'not_onboarded'], true)) $fView = '';
$fQ = trim((string)($_GET['q'] ?? ''));

$aiList = array_values(array_filter($aiApps, function ($a) use ($fDepth, $fTrains, $fSanction, $fView, $fQ) {
    if ($fDepth !== '' && $a['ai_depth_level'] !== $fDepth) return false;
    if ($fTrains !== '' && $a['trains'] !== $fTrains) return false;
    if ($fSanction !== '' && strtolower((string)$a['sanction_tag']) !== $fSanction) return false;
    if ($fView === 'onboarded' && empty($a['vendor_id'])) return false;
    if ($fView === 'not_onboarded' && !empty($a['vendor_id'])) return false;
    if ($fQ !== '' && stripos(($a['name'] ?? '') . ' ' . ($a['domain'] ?? '') . ' ' . ($a['category'] ?? ''), $fQ) === false) return false;
    return true;
}));

$validSort = ['users', 'name', 'first_seen', 'depth', 'trains'];
$pgParams = Pagination::getParams(['per_page' => 100, 'per_page_options' => [100, 500, 1000], 'sort_column' => 'users', 'sort_dir' => 'DESC', 'valid_sort_columns' => $validSort]);
$sortCol = $pgParams['sort_column'];
$sortDir = $pgParams['sort_dir'] === 'ASC' ? 1 : -1;
$trainsRank = ['yes' => 0, 'optional' => 1, 'unknown' => 2, 'no' => 3];
usort($aiList, function ($a, $b) use ($sortCol, $sortDir, $trainsRank) {
    switch ($sortCol) {
        case 'name':       $c = strcasecmp((string)$a['name'], (string)$b['name']); break;
        case 'first_seen': $c = strcmp((string)$a['first_event_time'], (string)$b['first_event_time']); break;
        case 'depth':      $c = (AI_DEPTH_ORDER[$b['ai_depth_level']] ?? 9) <=> (AI_DEPTH_ORDER[$a['ai_depth_level']] ?? 9); break;
        case 'trains':     $c = $trainsRank[$b['trains']] <=> $trainsRank[$a['trains']]; break;
        default:           $c = (int)$a['number_of_users'] <=> (int)$b['number_of_users'];
    }
    return $c * $sortDir ?: strcasecmp((string)$a['name'], (string)$b['name']);
});
$pg = Pagination::paginate(count($aiList), $pgParams['per_page'], $pgParams['page']);
$aiPage = array_slice($aiList, $pg['offset'], $pg['per_page']);

function aiQuery(array $over = []): string
{
    global $fDepth, $fTrains, $fSanction, $fView, $fQ;
    $q = array_merge(['filter' => $fDepth, 'type' => $fTrains, 'status' => $fSanction, 'view' => $fView, 'q' => $fQ], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
function aiSortLink(string $col, string $label): string
{
    global $sortCol, $pgParams;
    $on = $sortCol === $col;
    $dir = ($on && $pgParams['sort_dir'] === 'DESC') ? 'asc' : 'desc';
    $arrow = $on ? ($pgParams['sort_dir'] === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
    return '<a class="' . ($on ? 'on' : '') . '" href="' . e(aiQuery(['sort' => $col, 'order' => $dir])) . '">' . e($label) . $arrow . '</a>';
}
// Privacy questions shown as list columns (Grip question text => string key).
const AI_SUMMARY_QUESTIONS = ['Data retained' => 'col_retention', 'Sharing with 3rd parties' => 'col_sharing', 'Data storage location' => 'col_storage', 'Encryption' => 'col_encryption'];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-ai.apps_title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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

        .ai-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) 130px 70px 90px repeat(4, minmax(0, .8fr)) 100px 100px; gap: 10px; align-items: center; }
        @media (max-width: 1200px) { .ai-grid { grid-template-columns: minmax(0, 1.6fr) 130px 70px 90px 100px 100px; } .ai-grid .ai-hide-md { display: none; } }
        @media (max-width: 760px) { .pp-listhead { display: none; } .ai-grid { grid-template-columns: 1fr auto; } .ai-grid .ai-hide-sm { display: none; } }
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
            <?php $currentPage = 'shadow_saas_ai_apps'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'ai_apps'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-ai.apps_title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-ai.apps_intro')); ?></p>
                    </div>
                </div>

                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.grip_disabled')); ?></div>
                <?php elseif (!$aiApps): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.no_apps')); ?></div>
                <?php else: ?>
                <div class="ai-stats" id="ai-stats">
                    <a class="ai-stat" href="shadow-saas-ai-apps.php"><div class="v"><?php echo number_format($aiStat['total']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_ai_apps')); ?></div></a>
                    <?php foreach ($aiStat['depth'] as $dl => $n): ?>
                    <a class="ai-stat" href="<?php echo e(aiQuery(['filter' => $dl])); ?>"><div class="v"><?php echo number_format($n); ?></div><div class="l"><?php echo e($dl); ?></div></a>
                    <?php endforeach; ?>
                    <a class="ai-stat bad" id="ai-stat-trains" href="<?php echo e(aiQuery(['type' => 'yes'])); ?>"><div class="v"><?php echo number_format($aiStat['trains']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_trains')); ?></div></a>
                    <a class="ai-stat warn" href="<?php echo e(aiQuery(['view' => 'not_onboarded'])); ?>"><div class="v"><?php echo number_format($aiStat['not_tprm']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_not_tprm')); ?></div></a>
                    <div class="ai-stat"><div class="v"><?php echo number_format($aiStat['users']); ?></div><div class="l"><?php echo e(t('shadow-saas-ai.stat_users')); ?></div></div>
                </div>

                <form method="GET" class="pp-filters">
                    <select name="filter" aria-label="<?php echo e(t('shadow-saas-ai.col_depth')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.any_depth')); ?></option>
                        <?php foreach (array_keys($aiStat['depth']) as $dl): ?><option value="<?php echo e($dl); ?>" <?php echo $fDepth === $dl ? 'selected' : ''; ?>><?php echo e($dl); ?></option><?php endforeach; ?>
                    </select>
                    <select name="type" aria-label="<?php echo e(t('shadow-saas-ai.col_trains')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.any_trains')); ?></option>
                        <?php foreach (['yes', 'optional', 'no', 'unknown'] as $tv): ?><option value="<?php echo $tv; ?>" <?php echo $fTrains === $tv ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.trains_' . $tv)); ?></option><?php endforeach; ?>
                    </select>
                    <select name="status" aria-label="<?php echo e(t('shadow-saas-new.col_grip')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.any_sanction')); ?></option>
                        <?php foreach ($sanctionTags as $st): ?><option value="<?php echo e($st); ?>" <?php echo $fSanction === $st ? 'selected' : ''; ?>><?php echo e(ucwords($st)); ?></option><?php endforeach; ?>
                    </select>
                    <select name="view" aria-label="<?php echo e(t('shadow-saas-new.col_fairtprm')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-ai.any_tprm')); ?></option>
                        <option value="onboarded" <?php echo $fView === 'onboarded' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.view_onboarded')); ?></option>
                        <option value="not_onboarded" <?php echo $fView === 'not_onboarded' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas-ai.view_not_onboarded')); ?></option>
                    </select>
                    <input type="search" name="q" value="<?php echo e($fQ); ?>" placeholder="<?php echo e(t('shadow-saas-ai.search_apps')); ?>">
                    <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                    <?php if ($fDepth !== '' || $fTrains !== '' || $fSanction !== '' || $fView !== '' || $fQ !== ''): ?><a class="pp-chip" href="shadow-saas-ai-apps.php"><?php echo e(t('shadow-saas-posture.clear')); ?></a><?php endif; ?>
                </form>

                <?php if (!$aiList): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-ai.no_matches')); ?></div>
                <?php else: ?>
                <?php Pagination::renderControls($pg, 'apps', [100, 500, 1000]); ?>
                <div class="pp-list" id="ai-list">
                    <div class="pp-listhead ai-grid">
                        <span><?php echo aiSortLink('name', t('shadow-saas-new.col_app')); ?></span>
                        <span class="ai-hide-sm"><?php echo aiSortLink('depth', t('shadow-saas-ai.col_depth')); ?></span>
                        <span class="ai-hide-sm"><?php echo aiSortLink('users', t('shadow-saas-new.col_users')); ?></span>
                        <span class="ai-hide-sm"><?php echo aiSortLink('trains', t('shadow-saas-ai.col_trains')); ?></span>
                        <?php foreach (AI_SUMMARY_QUESTIONS as $sqKey): ?><span class="ai-hide-md ai-hide-sm"><?php echo e(t('shadow-saas-ai.' . $sqKey)); ?></span><?php endforeach; ?>
                        <span class="ai-hide-sm"><?php echo e(t('shadow-saas-new.col_grip')); ?></span>
                        <span><?php echo e(t('shadow-saas-new.col_fairtprm')); ?></span>
                    </div>
                    <?php foreach ($aiPage as $a):
                        $gid = (string)$a['grip_id'];
                        $tag = strtolower((string)$a['sanction_tag']);
                        $tq = $a['qa']['Trains on customer data']['answer'] ?? '';
                        $refs = json_decode((string)($a['terms_references'] ?? ''), true);
                        $comp = json_decode((string)($a['compliances'] ?? ''), true);
                    ?>
                    <details class="pp-row" data-grip-id="<?php echo e($gid); ?>">
                        <summary class="ai-grid">
                            <span><span class="ai-name"><?php echo e($a['name']); ?></span>
                                <div class="ai-sub"><?php echo e($a['domain'] ?? ''); ?><?php if (!empty($a['category'])): ?> &middot; <?php echo e($a['category']); ?><?php endif; ?></div></span>
                            <span class="ai-hide-sm"><span class="pp-pill lv-ai"><?php echo e($a['ai_depth_level']); ?></span></span>
                            <span class="ai-hide-sm"><?php if ((int)$a['number_of_users'] > 0): ?><a class="ai-link" href="grip-saas-users.php?id=<?php echo e(urlencode($gid)); ?>" target="_blank" rel="noopener"><?php echo number_format((int)$a['number_of_users']); ?></a><?php else: ?>0<?php endif; ?></span>
                            <span class="ai-hide-sm"><span class="pp-pill <?php echo aiLevelClass('Trains on customer data', $tq); ?>"><?php echo e($tq !== '' ? $tq : t('shadow-saas-ai.trains_unknown')); ?></span></span>
                            <?php foreach (array_keys(AI_SUMMARY_QUESTIONS) as $sq): $av = $a['qa'][$sq]['answer'] ?? ''; ?>
                            <span class="ai-hide-md ai-hide-sm"><?php if ($av !== ''): ?><span class="pp-pill <?php echo aiLevelClass($sq, $av); ?>" title="<?php echo e($a['qa'][$sq]['detail'] ?? ''); ?>"><?php echo e(mb_strimwidth($av, 0, 22, '...')); ?></span><?php else: ?><span class="pp-muted">&mdash;</span><?php endif; ?></span>
                            <?php endforeach; ?>
                            <span class="ai-hide-sm"><?php if ($tag !== ''): ?><span class="pp-pill <?php echo $tag === 'sanctioned' ? 'lv-ok' : ($tag === 'unsanctioned' ? 'lv-bad' : 'lv-na'); ?>"><?php echo e(ucwords($tag)); ?></span><?php endif; ?></span>
                            <span><?php if (!empty($a['vendor_id'])): ?><span class="pp-pill lv-ok"><?php echo e(t('shadow-saas-new.state_onboarded')); ?></span><?php elseif (!empty($a['ss_status'])): ?><span class="pp-pill lv-na"><?php echo e(t('shadow-saas-new.state_' . $a['ss_status'])); ?></span><?php else: ?><span class="pp-muted">&mdash;</span><?php endif; ?></span>
                        </summary>
                        <div class="pp-body">
                            <?php if (!empty($a['ai_depth_description'])): ?><h4><?php echo e(t('shadow-saas-ai.col_depth')); ?></h4><div><?php echo e($a['ai_depth_description']); ?></div><?php endif; ?>
                            <?php if ($a['qa']): ?>
                                <h4><?php echo e(t('shadow-saas-ai.privacy_profile')); ?></h4>
                                <table class="ai-table"><thead><tr><th><?php echo e(t('shadow-saas-ai.question')); ?></th><th><?php echo e(t('shadow-saas-ai.answer')); ?></th><th><?php echo e(t('shadow-saas-ai.detail')); ?></th></tr></thead><tbody>
                                <?php foreach ($a['qa'] as $qq => $qv): ?>
                                    <tr><td><?php echo e($qq); ?></td><td><span class="pp-pill <?php echo aiLevelClass($qq, $qv['answer']); ?>"><?php echo e($qv['answer']); ?></span></td><td><?php echo e($qv['detail']); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                            <?php if (is_array($refs) && $refs): ?>
                                <h4><?php echo e(t('shadow-saas-ai.references')); ?></h4>
                                <ul style="margin:4px 0 0 18px;padding:0;"><?php foreach ($refs as $rf): if (!is_array($rf) || !preg_match('#^https?://#i', (string)($rf['url'] ?? ''))) continue; ?><li><a class="ai-link" href="<?php echo e($rf['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e((string)($rf['title'] ?? $rf['url'])); ?></a></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                            <?php if (is_array($comp) && $comp): ?>
                                <h4><?php echo e(t('shadow-saas-ai.certifications')); ?></h4>
                                <div><?php echo e(implode(', ', array_map(fn($c) => is_scalar($c) ? (string)$c : '', $comp))); ?></div>
                            <?php endif; ?>
                            <p class="ai-sub" style="margin-top:12px;"><?php echo e(t('shadow-saas-new.col_first_seen')); ?>: <?php echo $a['first_event_time'] ? e(date('M j, Y', strtotime($a['first_event_time']))) : '&mdash;'; ?></p>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
                <?php Pagination::renderControls($pg, 'apps', [100, 500, 1000]); ?>
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
        // Per-page selector (Pagination::renderControls) - keeps the current filters.
        document.addEventListener('change', function (e) {
            var el = e.target.closest('[data-action="changePerPage"]');
            if (!el) return;
            var params = new URLSearchParams(window.location.search);
            params.set('per_page', el.value);
            params.delete('page');
            window.location.href = '?' + params.toString();
        });
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
