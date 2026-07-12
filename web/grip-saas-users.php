<?php
/**
 * Grip SaaS App - Affected Users drill-down.
 *
 * Opened from the Shadow SaaS module list (the user-count on a Grip-sourced row
 * links here) and from a Grip breach entry on breach-alerts.php. Lists the users
 * Grip observed for a SaaS application - the people potentially impacted by a
 * breach - from the shadow_saas_grip_app_users snapshot. In Live mode an empty
 * snapshot is lazily populated from Grip on first view; in Local (Hydrated/cached)
 * mode the page serves EXCLUSIVELY from the database and shows nothing when the
 * snapshot is empty (it waits for the next sync - no per-view API calls).
 *
 * Read-only. Same access as breach alerts (admin / cyber_tprm / auditor).
 * Param: ?id=<grip SaaS application id>. Large lists are paginated (100/500/1000).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();
require_once __DIR__ . '/includes/classes/Pagination.php';
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$auth    = Auth::getInstance();
$user    = $auth->getUser();
$session = Session::getInstance();
$db      = Database::getInstance();
$theme   = getUserTheme();

$isAdmin     = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberTPRM = hasGroup('cyber_tprm');
$isAuditor   = hasGroup('auditor');
if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(t('breach-alerts.access_denied'));
}

// The Grip SaaS application id. Named "id" so Pagination::buildPageUrl (which
// whitelists query keys) preserves it across page links.
$gripId = (string)($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $gripId)) {
    http_response_code(400);
    die(t('grip-saas-users.invalid_app_id'));
}

// App context - vendor name / domain / user count. The Shadow SaaS list row the
// user clicked from is the authoritative source (vendor_name / vendor_domain);
// fall back to the Grip apps mirror if this id isn't in shadow_saas.
$appName     = '';
$appDomain   = '';
$appUsers    = null;
$ssRow = $db->fetchOne(
    "SELECT vendor_name, vendor_domain, number_of_users FROM shadow_saas WHERE grip_id = :g LIMIT 1",
    [':g' => $gripId]
);
if ($ssRow) {
    $appName   = (string)($ssRow['vendor_name'] ?? '');
    $appDomain = (string)($ssRow['vendor_domain'] ?? '');
    $appUsers  = $ssRow['number_of_users'] ?? null;
}
if ($appName === '' || $appDomain === '') {
    $mirror = $db->fetchOne(
        "SELECT name, domain, number_of_users FROM shadow_saas_grip_apps WHERE grip_id = :g",
        [':g' => $gripId]
    );
    if ($mirror) {
        if ($appName === '')   $appName   = (string)($mirror['name'] ?? '');
        if ($appDomain === '') $appDomain = (string)($mirror['domain'] ?? '');
        if ($appUsers === null) $appUsers = $mirror['number_of_users'] ?? null;
    }
}
// Onboarded vendors are excluded from the Shadow SaaS tables, so resolve the
// name/domain from the vendor record whose curated Grip telemetry carries this
// app id (grip_app_data JSON, stamped during sync). json_encode emits the key
// without spaces, so a LIKE on "grip_id":"<id>" is an exact, safe match.
if ($appName === '' || $appDomain === '') {
    $vr = $db->fetchOne(
        "SELECT vendor_name, vendor_domain, grip_app_data FROM vendor_onboarding_requests
         WHERE grip_app_data LIKE :pat AND status != 'inactive' ORDER BY id LIMIT 1",
        [':pat' => '%"grip_id":"' . $gripId . '"%']
    );
    if ($vr) {
        if ($appName === '')   $appName   = trim((string)($vr['vendor_name'] ?? ''));
        if ($appDomain === '') $appDomain = trim((string)($vr['vendor_domain'] ?? ''));
        if ($appUsers === null && !empty($vr['grip_app_data'])) {
            $gad = json_decode($vr['grip_app_data'], true);
            if (is_array($gad) && isset($gad['number_of_users'])) $appUsers = (int)$gad['number_of_users'];
        }
    }
}
// Last resort: a breach alert linked to this app (e.g. a vendor breach enriched
// with Grip telemetry) carries the entity name + domain even when the app isn't
// in the Shadow SaaS tables.
if ($appName === '' || $appDomain === '') {
    $br = $db->fetchOne(
        "SELECT affected_entity, affected_domain, impacted_user_count FROM cyber_breach_alerts WHERE grip_saas_id = :g AND affected_entity IS NOT NULL ORDER BY id LIMIT 1",
        [':g' => $gripId]
    );
    if ($br) {
        if ($appName === '')   $appName   = trim((string)($br['affected_entity'] ?? ''));
        if ($appDomain === '') $appDomain = trim((string)($br['affected_domain'] ?? ''));
        if ($appUsers === null && isset($br['impacted_user_count'])) $appUsers = $br['impacted_user_count'];
    }
}
// (Final live-API fallback + gripId default happen below, once GripService is up.)

// Pagination - 100/500/1000 per page for large datasets.
$validSort = ['full_name', 'mail', 'organizational_unit', 'manager_email', 'first_event_time', 'latest_event_time', 'last_usage'];
$params = Pagination::getParams([
    'per_page'           => 100,
    'per_page_options'   => [100, 500, 1000],
    'sort_column'        => 'full_name',
    'sort_dir'           => 'ASC',
    'valid_sort_columns' => $validSort,
]);
$sortCol = in_array($params['sort_column'], $validSort, true) ? $params['sort_column'] : 'full_name';
$sortDir = $params['sort_dir'] === 'DESC' ? 'DESC' : 'ASC';
// A sort (?sort=) or an SSO filter (?filter=) narrows/orders across the whole
// roster, so those views read from the local snapshot. The unfiltered, unsorted
// default view serves a single page straight from Grip (fast).
$sortActive = isset($_GET['sort']);
$authFilter = (string)($_GET['filter'] ?? '');     // '', 'sso', 'nosso', 'saml', 'credentials'
if (!in_array($authFilter, ['sso', 'nosso', 'saml', 'credentials'], true)) $authFilter = '';
$authWhere  = '';
$authParams = [':g' => $gripId];
if ($authFilter === 'sso')             { $authWhere = ' AND sso = 1'; }
elseif ($authFilter === 'nosso')       { $authWhere = ' AND (sso = 0 OR sso IS NULL)'; }
elseif ($authFilter === 'saml')        { $authWhere = ' AND authentication_type LIKE :auth'; $authParams[':auth'] = 'SAML%'; }
elseif ($authFilter === 'credentials') { $authWhere = ' AND authentication_type = :auth'; $authParams[':auth'] = 'Credentials'; }
// Free-text search over the PII columns (first or last name, or email address).
// Those columns are encrypted at rest with a random IV, so the match cannot run
// in SQL; like sorting, a search forces the snapshot path so we can filter the
// decrypted rows in PHP. Bounded to 128 chars.
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 128) $search = mb_substr($search, 0, 128);
$needsSnapshot = $sortActive || $authFilter !== '' || $search !== '';

require_once CLASSES_PATH . '/GripService.php';
$grip = new GripService();
$gripReady = $grip->isConfigured();
// Data-source mode. In 'local' (Hydrated/cached) mode the page reads EXCLUSIVELY
// from the hydrated DB snapshot - no per-view live API calls at all. If the
// snapshot is empty (e.g. after a Truncate), nothing is shown until the next sync
// repopulates it. Every live API call below (app detail, fast page fetch, and the
// read-through snapshot build) is gated on $useLive, so none of them fire in Local
// mode - that is what keeps "serve from the database only" actually true.
$useLive = $gripReady && !$grip->useLocalData();

// Final fallback: ask Grip directly for the app's name/domain. Covers apps that
// aren't in any local table (e.g. an onboarded vendor's app excluded from the
// Shadow SaaS mirror, with no linked breach). Only when still unresolved.
if (($appName === '' || $appDomain === '') && $useLive) {
    $detail = $grip->getAppDetail($gripId);
    if (is_array($detail)) {
        if ($appName === '' && !empty($detail['name'])) $appName = (string)$detail['name'];
        if ($appDomain === '' && !empty($detail['url'])) $appDomain = (string)GripService::extractDomain($detail['url']);
        if ($appUsers === null && isset($detail['gripData']['numberOfUsers'])) $appUsers = (int)$detail['gripData']['numberOfUsers'];
    }
}
if ($appName === '') $appName = $gripId;

$snapCount = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM shadow_saas_grip_app_users WHERE grip_saas_id = :g",
    [':g' => $gripId]
)['c'] ?? 0);

$users = [];
$total = 0;
$usedFast = false;

if (!$needsSnapshot && $useLive) {
    // FAST PATH - fetch only the requested page live from Grip (e.g. 100 of
    // 4862). Total comes from the app's user telemetry so paging is correct
    // without loading the whole roster. Falls through to the snapshot if the
    // live fetch yields nothing (Grip unreachable).
    $total = ($appUsers !== null && (int)$appUsers > 0) ? (int)$appUsers : $snapCount;
    $pg = Pagination::paginate($total, $params['per_page'], $params['page']);
    try {
        $users = $grip->getAppUsersPage($gripId, $pg['offset'], $params['per_page']);
    } catch (Throwable $e) {
        error_log('grip-saas-users live page fetch failed: ' . $e->getMessage());
    }
    if (!empty($users)) {
        $usedFast = true;
        if ($total === 0) { // telemetry unknown — reflect at least what we fetched
            $total = $pg['offset'] + count($users);
            $pg = Pagination::paginate($total, $params['per_page'], $params['page']);
        }
    }
}

if (!$usedFast) {
    // SNAPSHOT PATH - read the roster from the hydrated DB snapshot.
    //
    // The on-demand build is gated on $useLive so it ONLY runs in Live mode (where
    // sorting / SSO-filtering the full roster legitimately needs a live fetch). In
    // Local (Hydrated/cached) mode we never call the Grip API on a page view: an
    // empty snapshot stays empty and renders an empty roster until the next sync
    // repopulates it. This is what makes "serve only from the database" hold.
    if ($snapCount === 0 && $useLive) {
        try { $grip->refreshAppUsers($gripId); }
        catch (Throwable $e) { error_log('grip-saas-users snapshot build failed: ' . $e->getMessage()); }
    }

    // PII columns (mail, full_name, organizational_unit, manager_email, …) are
    // encrypted at rest with a random IV, so they cannot be ORDER BY'd or matched
    // in SQL. The non-PII auth filters ($authWhere: sso / authentication_type) DO
    // run in SQL; we then fetch the whole (filtered) roster for this app, decrypt
    // each row, and sort + paginate in PHP. Rosters are per-app and bounded, so
    // this stays cheap. raw_payload is no longer stored on this table (it was a
    // large blob we never render); the unset below is just defensive for any
    // legacy rows that predate the column drop.
    $allRows = $db->fetchAll(
        "SELECT * FROM shadow_saas_grip_app_users WHERE grip_saas_id = :g{$authWhere}",
        $authParams
    );
    foreach ($allRows as &$__row) {
        unset($__row['raw_payload']);
        $__row = $grip->decryptGripRow('shadow_saas_grip_app_users', $__row);
    }
    unset($__row);

    // Free-text search on the decrypted PII: a case-insensitive substring match
    // against the full name (covers first or last name) and the email address.
    if ($search !== '') {
        $needle = $search;
        $allRows = array_values(array_filter($allRows, function ($r) use ($needle) {
            $name = (string)($r['full_name'] ?? ($r['display_name'] ?? ''));
            $mail = (string)($r['mail'] ?? '');
            return mb_stripos($name, $needle) !== false || mb_stripos($mail, $needle) !== false;
        }));
    }

    // Sort by the chosen column on the decrypted values. NULL/empty always sort
    // last (matches the previous "ORDER BY (col IS NULL), col" SQL behaviour).
    usort($allRows, function ($a, $b) use ($sortCol, $sortDir) {
        $av = $a[$sortCol] ?? null; $bv = $b[$sortCol] ?? null;
        $aEmpty = ($av === null || $av === ''); $bEmpty = ($bv === null || $bv === '');
        if ($aEmpty && $bEmpty) return 0;
        if ($aEmpty) return 1;
        if ($bEmpty) return -1;
        $cmp = strcasecmp((string)$av, (string)$bv);   // ISO dates compare correctly too
        return $sortDir === 'DESC' ? -$cmp : $cmp;
    });

    $total = count($allRows);
    $pg = Pagination::paginate($total, $params['per_page'], $params['page']);
    $users = array_slice($allRows, $pg['offset'], $params['per_page']);
}

// Helpers ---------------------------------------------------------------------
function gsuDate($v): string {
    if (empty($v)) return '<span style="color:#9ca3af;">—</span>';
    $ts = strtotime($v);
    return $ts ? e(date('M j, Y', $ts)) : '<span style="color:#9ca3af;">—</span>';
}
function gsuText($v): string {
    $v = trim((string)$v);
    return $v === '' ? '<span style="color:#9ca3af;">—</span>' : e($v);
}
function gsuInitials(string $name, string $mail): string {
    $name = trim($name);
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        $i = strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1) $i .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
        return $i;
    }
    return strtoupper(substr($mail !== '' ? $mail : '?', 0, 1));
}
// Deterministic avatar colour from a string.
function gsuColor(string $seed): string {
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#9333ea', '#ca8a04'];
    return $palette[abs(crc32($seed)) % count($palette)];
}
// A sortable column header - links to itself with the toggled direction and shows
// the active sort arrow. Resets to page 1; preserves the app id and page size.
function gsuSortHeader(string $label, string $col, string $gripId, string $curCol, string $curDir, int $perPage, string $filter = '', string $search = ''): string {
    $isActive = ($curCol === $col);
    $newDir   = ($isActive && $curDir === 'ASC') ? 'desc' : 'asc';
    $arrow    = $isActive ? ($curDir === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
    $q = ['id' => $gripId, 'per_page' => $perPage, 'sort' => $col, 'order' => $newDir];
    if ($filter !== '') $q['filter'] = $filter;
    if ($search !== '') $q['q'] = $search;
    $qs = http_build_query($q);
    return '<th><a href="?' . e($qs) . '" title="' . e(t('grip-saas-users.sort_by')) . ' ' . e($label) . '"'
         . ' style="color:' . ($isActive ? 'var(--theme-button-color)' : 'inherit') . ';text-decoration:none;white-space:nowrap;">'
         . e($label) . $arrow . '</a></th>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($appName); ?> - <?php echo e(t('grip-saas-users.affected_users')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
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
        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        .page-title { font-size: 24px; font-weight: 600; color: #1f2937; }

        /* App identity header */
        .app-head { display: flex; align-items: center; gap: 14px; }
        .app-logo { width: 44px; height: 44px; border-radius: 10px; background: var(--theme-button-color); color: #fff; font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex: 0 0 44px; }
        .app-meta { color: #6b7280; font-size: 14px; margin: 2px 0 0; }
        .app-meta a { color: var(--theme-button-color); text-decoration: none; }
        .app-meta a:hover { text-decoration: underline; }
        .grip-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #ecfeff; color: #0e7490; vertical-align: middle; margin-left: 6px; }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; border: 1px solid #e5e7eb; }
        .data-table th { background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .data-table td { padding: 12px 16px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f9fafb; }

        /* User cell */
        .gsu-user { display: flex; align-items: center; gap: 10px; }
        .gsu-avatar { width: 32px; height: 32px; border-radius: 50%; color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 0 0 32px; }
        .gsu-name { font-weight: 600; color: #111827; }
        .gsu-sub { color: #6b7280; font-size: 12px; }
        .gsu-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .gsu-yes { background: #dcfce7; color: #166534; }
        .gsu-no { background: #fee2e2; color: #991b1b; }

        /* Pagination */
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 13px; color: #6b7280; }
        .pagination { display: flex; list-style: none; gap: 4px; padding: 0; margin: 0; }
        .pagination li a, .pagination li span { display: inline-block; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-decoration: none; color: #374151; }
        .pagination li a:hover { background: #f3f4f6; }
        .pagination li.active span { background: var(--theme-button-color); color: white; border-color: var(--theme-button-color); }
        .pagination li.disabled span { color: #d1d5db; cursor: not-allowed; }
        .per-page-group { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }

        .empty-state { text-align: center; padding: 60px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
        .empty-state h3 { color: #374151; margin-bottom: 8px; }

        /* Footer */
        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }

        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top-bar">
            <div class="user-menu">
                <span style="font-size: 13px; color: #666;"><?php echo e($user['full_name'] ?? ($user['email'] ?? '')); ?></span>
                <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
                <a href="shadow-saas.php"><?php echo e(t('chrome.dashboard') ?: 'Shadow SaaS'); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php $currentPage = 'shadow_saas'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <!-- Page Header: Vendor name + domain -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 22px; flex-wrap: wrap; gap: 15px;">
                    <div class="app-head">
                        <div class="app-logo"><?php echo e(gsuInitials($appName, $appDomain)); ?></div>
                        <div>
                            <h1 class="page-title" style="margin: 0;"><?php echo e($appName); ?><span class="grip-tag">Grip</span></h1>
                            <p class="app-meta" style="margin: 4px 0 0;">
                                <?php if ($appDomain !== ''): ?>
                                    <a href="https://<?php echo e($appDomain); ?>" target="_blank" rel="noopener"><?php echo e($appDomain); ?></a>
                                    &middot;
                                <?php endif; ?>
                                <?php if ($search !== '' || $authFilter !== ''): ?><?php echo number_format($total); ?> <?php echo e($total === 1 ? t('grip-saas-users.matching_user_one') : t('grip-saas-users.matching_user_many')); ?><?php else: ?><?php echo number_format($total); ?> <?php echo e($total === 1 ? t('grip-saas-users.user_observed_one') : t('grip-saas-users.user_observed_many')); ?><?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="shadow-saas.php" style="background: #6b7280; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px;">&larr; <?php echo e(t('grip-saas-users.back_to_shadow_saas')); ?></a>
                    </div>
                </div>

                <p style="color:#6b7280;font-size:13px;margin:-8px 0 18px;"><?php echo e(t('grip-saas-users.page_intro')); ?></p>

                <?php $filterOpts = ['' => t('grip-saas-users.filter_all'), 'sso' => 'SSO', 'nosso' => t('grip-saas-users.no_sso'), 'saml' => 'SAML 2.0', 'credentials' => t('grip-saas-users.filter_credentials')]; ?>
                <!-- Authentication filters (left) and name/email search (right) share one
                     row to save vertical space; they stack when the viewport is narrow. -->
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px 20px;flex-wrap:wrap;margin-bottom:16px;">
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-right:4px;"><?php echo e(t('grip-saas-users.authentication_label')); ?></span>
                        <?php foreach ($filterOpts as $fk => $fl):
                            $fActive = ($authFilter === $fk);
                            $fq = ['id' => $gripId, 'per_page' => (int)$params['per_page']];
                            if ($fk !== '') $fq['filter'] = $fk;
                            if ($search !== '') $fq['q'] = $search;
                        ?>
                        <a href="?<?php echo e(http_build_query($fq)); ?>" style="font-size:13px;padding:5px 12px;border-radius:6px;text-decoration:none;border:1px solid #e5e7eb;<?php echo $fActive ? 'background:var(--theme-button-color);color:#fff;border-color:var(--theme-button-color);font-weight:600;' : 'background:#fff;color:#374151;'; ?>"><?php echo e($fl); ?></a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Search by first/last name or email address. Submits as ?q=; keeps
                         the active auth filter and page size but resets to page 1. -->
                    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="id" value="<?php echo e($gripId); ?>">
                        <?php if ($authFilter !== ''): ?><input type="hidden" name="filter" value="<?php echo e($authFilter); ?>"><?php endif; ?>
                        <input type="hidden" name="per_page" value="<?php echo (int)$params['per_page']; ?>">
                        <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="<?php echo e(t('grip-saas-users.search_placeholder')); ?>" autocomplete="off" style="width:240px;max-width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                        <button type="submit" style="background:var(--theme-button-color);color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;cursor:pointer;"><?php echo e(t('grip-saas-users.search_button')); ?></button>
                        <?php if ($search !== ''): ?><a href="?<?php echo e(http_build_query(array_filter(['id' => $gripId, 'per_page' => (int)$params['per_page'], 'filter' => $authFilter]))); ?>" style="font-size:13px;color:#6b7280;text-decoration:none;padding:8px 4px;"><?php echo e(t('grip-saas-users.clear')); ?></a><?php endif; ?>
                    </form>
                </div>

                <?php if (empty($users) && ($search !== '' || $authFilter !== '')): ?>
                    <div class="empty-state">
                        <h3><?php echo e(t('grip-saas-users.no_matching_users')); ?></h3>
                        <div><?php echo e(t('grip-saas-users.no_users_match_prefix')); ?> <?php
                            if ($search !== '' && $authFilter !== '') echo e(t('grip-saas-users.match_search_and_filter'));
                            elseif ($search !== '') echo e(t('grip-saas-users.match_search')) . ' "' . e($search) . '"';
                            else echo e(t('grip-saas-users.match_filter'));
                        ?> <?php echo e(t('grip-saas-users.no_users_match_suffix')); ?></div>
                    </div>
                <?php elseif (empty($users)): ?>
                    <div class="empty-state">
                        <h3><?php echo e(t('grip-saas-users.no_users_available')); ?></h3>
                        <div><?php echo t('grip-saas-users.no_roster_body'); ?></div>
                    </div>
                <?php else: ?>
                    <?php Pagination::renderControls($pg, 'users', [100, 500, 1000]); ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php
                                    $pp = (int)$params['per_page'];
                                    echo gsuSortHeader(t('grip-saas-users.col_user'), 'full_name', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                    echo gsuSortHeader(t('grip-saas-users.col_org_unit'), 'organizational_unit', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                    echo gsuSortHeader(t('grip-saas-users.col_manager'), 'manager_email', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                ?>
                                <th><?php echo e(t('grip-saas-users.col_auth')); ?></th>
                                <th>SSO</th>
                                <?php
                                    echo gsuSortHeader(t('grip-saas-users.col_first_event'), 'first_event_time', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                    echo gsuSortHeader(t('grip-saas-users.col_latest_event'), 'latest_event_time', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                    echo gsuSortHeader(t('grip-saas-users.col_last_usage'), 'last_usage', $gripId, $sortCol, $sortDir, $pp, $authFilter, $search);
                                ?>
                                <th><?php echo e(t('grip-saas-users.col_platforms')); ?></th>
                                <th># SaaS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u):
                                $name = (string)($u['full_name'] ?? ($u['display_name'] ?? ''));
                                $mail = (string)($u['mail'] ?? '');
                                $initials = gsuInitials($name, $mail);
                                $color = gsuColor($mail !== '' ? $mail : $name);
                                $platforms = [];
                                if (!empty($u['platforms_activity'])) {
                                    $pa = json_decode($u['platforms_activity'], true);
                                    if (is_array($pa)) {
                                        foreach ($pa as $p) {
                                            if (is_array($p) && !empty($p['platform'])) $platforms[] = $p['platform'];
                                        }
                                    }
                                }
                                $sso = $u['sso'];
                            ?>
                            <tr>
                                <td>
                                    <div class="gsu-user">
                                        <div class="gsu-avatar" style="background:<?php echo $color; ?>;"><?php echo e($initials); ?></div>
                                        <div>
                                            <div class="gsu-name"><?php echo gsuText($name !== '' ? $name : $mail); ?></div>
                                            <?php if ($name !== '' && $mail !== ''): ?>
                                            <div class="gsu-sub"><?php echo e($mail); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo gsuText($u['organizational_unit'] ?? ''); ?></td>
                                <td><?php echo gsuText($u['manager_email'] ?? ''); ?></td>
                                <td><?php echo gsuText($u['authentication_type'] ?? ''); ?></td>
                                <td>
                                    <?php if ($sso === null || $sso === ''): ?><span style="color:#9ca3af;">—</span>
                                    <?php elseif ((int)$sso === 1): ?><span class="gsu-pill gsu-yes">SSO</span>
                                    <?php else: ?><span class="gsu-pill gsu-no"><?php echo e(t('grip-saas-users.no_sso')); ?></span><?php endif; ?>
                                </td>
                                <td><?php echo gsuDate($u['first_event_time'] ?? null); ?></td>
                                <td><?php echo gsuDate($u['latest_event_time'] ?? null); ?></td>
                                <td><?php echo gsuDate($u['last_usage'] ?? null); ?></td>
                                <td><?php echo $platforms ? e(implode(', ', $platforms)) : '<span style="color:#9ca3af;">—</span>'; ?></td>
                                <td><?php echo isset($u['number_of_saas']) && $u['number_of_saas'] !== null ? (int)$u['number_of_saas'] : '<span style="color:#9ca3af;">—</span>'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php Pagination::renderControls($pg, 'users', [100, 500, 1000]); ?>
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
        // Per-page selector - preserves all current query params (incl. ?id=).
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
