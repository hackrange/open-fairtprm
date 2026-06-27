<?php
/**
 * Grip SaaS App — Affected Users drill-down.
 *
 * Opened from the Shadow SaaS module list (the user-count on a Grip-sourced row
 * links here) and from a Grip breach entry on breach-alerts.php. Lists the users
 * Grip observed for a SaaS application — the people potentially impacted by a
 * breach — from the shadow_saas_grip_app_users snapshot. If the snapshot is empty
 * (the app never went through the breach projection) it is lazily populated from
 * Grip on first view.
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
    die('Invalid application id.');
}

// App context — vendor name / domain / user count. The Shadow SaaS list row the
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
if ($appName === '') $appName = $gripId;

// Pagination — 100/500/1000 per page for large datasets.
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

$total = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM shadow_saas_grip_app_users WHERE grip_saas_id = :g",
    [':g' => $gripId]
)['c'] ?? 0);

// Lazy-populate: most Shadow SaaS rows never went through the breach projection,
// so their user snapshot is empty. The first time this drill-down is opened for
// such an app, fetch the roster live from Grip and cache it. Best-effort —
// silently leaves $total at 0 if Grip is unconfigured or the call fails.
if ($total === 0 && file_exists(CLASSES_PATH . '/GripService.php')) {
    require_once CLASSES_PATH . '/GripService.php';
    try {
        $grip = new GripService();
        if ($grip->isConfigured()) {
            $grip->refreshAppUsers($gripId);
            $total = (int)($db->fetchOne(
                "SELECT COUNT(*) AS c FROM shadow_saas_grip_app_users WHERE grip_saas_id = :g",
                [':g' => $gripId]
            )['c'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('grip-saas-users lazy populate failed: ' . $e->getMessage());
    }
}

$pg = Pagination::paginate($total, $params['per_page'], $params['page']);

$users = $db->fetchAll(
    "SELECT * FROM shadow_saas_grip_app_users WHERE grip_saas_id = :g
     ORDER BY ({$sortCol} IS NULL), {$sortCol} {$sortDir}
     LIMIT :lim OFFSET :off",
    [':g' => $gripId, ':lim' => $params['per_page'], ':off' => $pg['offset']]
);

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
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($appName); ?> — Affected Users - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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
                                <?php echo number_format($total); ?> user<?php echo $total === 1 ? '' : 's'; ?> observed by Grip
                            </p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="shadow-saas.php" style="background: #6b7280; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px;">&larr; Back to Shadow SaaS</a>
                    </div>
                </div>

                <p style="color:#6b7280;font-size:13px;margin:-8px 0 18px;">Users potentially impacted by a Grip-detected security incident affecting this application, as observed by Grip Security.</p>

                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <h3>No users available</h3>
                        <div>No user roster is available for this application yet.<br>
                        It is captured live on first view and on each Shadow SaaS sync (scheduled rehydration or "Run Now"). If Grip is not connected, no roster can be shown.</div>
                    </div>
                <?php else: ?>
                    <?php Pagination::renderControls($pg, 'users', [100, 500, 1000]); ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Org Unit</th>
                                <th>Manager</th>
                                <th>Auth</th>
                                <th>SSO</th>
                                <th>First Event</th>
                                <th>Latest Event</th>
                                <th>Last Usage</th>
                                <th>Platforms</th>
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
                                    <?php else: ?><span class="gsu-pill gsu-no">No SSO</span><?php endif; ?>
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
        // Per-page selector — preserves all current query params (incl. ?id=).
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
