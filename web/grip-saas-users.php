<?php
/**
 * Grip SaaS App — Affected Users drill-down.
 *
 * Opened from a Grip breach entry on breach-alerts.php (the impacted-user count
 * links here). Lists the users Grip observed for a SaaS application — the people
 * potentially impacted by the breach — from the shadow_saas_grip_app_users
 * snapshot hydrated by GripService during Shadow SaaS sync.
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

$auth    = Auth::getInstance();
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

// App context from the mirror (name/domain/total users), if present.
$appRow  = $db->fetchOne(
    "SELECT name, domain, number_of_users FROM shadow_saas_grip_apps WHERE grip_id = :g",
    [':g' => $gripId]
);
$appName = ($appRow['name'] ?? '') !== '' ? $appRow['name'] : $gripId;

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
    <title><?php echo e($appName); ?> — Affected Users</title>
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style nonce="<?php echo cspNonce(); ?>">
        body { font-family: 'Roboto', Arial, sans-serif; background: #f3f4f6; margin: 0; color: #111827; }
        .gsu-topbar { background: var(--theme-header-color, #1f2937); color: #fff; padding: 14px 24px; display: flex; align-items: center; gap: 14px; }
        .gsu-topbar h1 { font-size: 16px; font-weight: 600; margin: 0; }
        .gsu-wrap { max-width: 1200px; margin: 24px auto; padding: 0 20px; }
        .gsu-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
        .gsu-meta { color: #6b7280; font-size: 13px; }
        table.gsu { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.gsu th { text-align: left; padding: 10px 12px; border-bottom: 2px solid #e5e7eb; color: #6b7280; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; white-space: nowrap; }
        table.gsu td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .gsu-user { display: flex; align-items: center; gap: 10px; }
        .gsu-avatar { width: 32px; height: 32px; border-radius: 50%; color: #fff; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 0 0 32px; }
        .gsu-name { font-weight: 600; color: #111827; }
        .gsu-sub { color: #6b7280; font-size: 12px; }
        .gsu-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .gsu-yes { background: #dcfce7; color: #166534; }
        .gsu-no { background: #fee2e2; color: #991b1b; }
        .gsu-empty { text-align: center; color: #6b7280; padding: 40px 0; }
        a.gsu-back { color: #fff; opacity: .85; text-decoration: none; font-size: 13px; }
        a.gsu-back:hover { opacity: 1; }
    </style>
</head>
<body>
    <div class="gsu-topbar">
        <a class="gsu-back" href="breach-alerts.php">&larr; Breach Alerts</a>
        <h1><?php echo e($appName); ?> — Affected Users</h1>
    </div>
    <div class="gsu-wrap">
        <div class="gsu-card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;">
                <div>
                    <strong style="font-size:15px;"><?php echo e($appName); ?></strong>
                    <?php if (!empty($appRow['domain'])): ?>
                    <span class="gsu-meta"> &middot; <?php echo e($appRow['domain']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="gsu-meta">
                    <?php echo number_format($total); ?> user<?php echo $total === 1 ? '' : 's'; ?> observed by Grip
                </div>
            </div>
            <div class="gsu-meta" style="margin-top:6px;">Users potentially impacted by a Grip-detected security incident affecting this application. Source: Grip Security <code>GET /public/saas/{id}/users</code>.</div>
        </div>

        <div class="gsu-card">
            <?php if (empty($users)): ?>
                <div class="gsu-empty">
                    No user snapshot is available for this application yet.<br>
                    <span class="gsu-meta">It is captured on the next Shadow SaaS sync (scheduled rehydration or "Run Now").</span>
                </div>
            <?php else: ?>
                <?php Pagination::renderControls($pg, 'users', [100, 500, 1000]); ?>
                <div style="overflow-x:auto;">
                <table class="gsu">
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
        </div>
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
</body>
</html>
