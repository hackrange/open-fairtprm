<?php
/**
 * Shadow SaaS - Posture (Grip SSPM posture management).
 *
 * Mirrors Grip's Posture Management view inside FairTPRM: the general posture
 * score (Pass / (Pass + Fail), the same figure Grip shows), the lowest-scoring
 * tenants, the security domains with the most failed checks, and every posture
 * check with its risk description, mitigation steps and compliance mappings.
 *
 * Checks come from shadow_saas_grip_posture_checks, refreshed on every Grip
 * sync (or on demand with "Refresh from Grip"). Opening a check (?id=<uuid>)
 * lists its violating entities, fetched live from Grip and never stored,
 * because findings name users and resources.
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
// Auditors read; only admin / cyber_tprm may trigger a refresh from Grip.
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
    } elseif (($_POST['action'] ?? '') === 'refresh_posture' && $gripEnabled) {
        try {
            $n = $grip->syncPostureChecks();
            $auth->audit($user['id'], 'shadow_saas_posture_refresh', 'shadow_saas_grip_posture_checks', null, ['new' => ['checks' => $n]]);
            $success = t('shadow-saas-posture.refreshed', $n);
        } catch (Throwable $e) {
            error_log('Posture refresh failed: ' . $e->getMessage());
            $error = t('shadow-saas-posture.refresh_failed');
        }
    }
}
$csrfToken = $security->generateCSRFToken();

// ---------------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------------

/** Grip text fields can carry HTML. Keep the line structure, drop the markup, escape. */
function ppText(?string $s): string
{
    if ($s === null || trim($s) === '') return '';
    $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s);
    $s = preg_replace('#<\s*li[^>]*>#i', "\n- ", $s);
    $s = preg_replace('#</\s*(p|div|li|ul|ol|h[1-6]|tr|table)\s*>#i', "\n", $s);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+\n/", "\n", $s)));
    return nl2br(e($s));
}

/** Mitigation steps arrive as text, or as JSON {header, steps[]}. */
function ppMitigation(?string $s): string
{
    if ($s === null || trim($s) === '') return '';
    $j = json_decode($s, true);
    if (is_array($j)) {
        $items = isset($j['steps']) ? [$j] : (array_is_list($j) ? $j : [$j]);
        $out = '';
        foreach ($items as $it) {
            if (!is_array($it)) { $out .= '<p>' . ppText((string)$it) . '</p>'; continue; }
            if (!empty($it['header'])) $out .= '<p class="pp-sub">' . ppText((string)$it['header']) . '</p>';
            if (!empty($it['steps']) && is_array($it['steps'])) {
                $out .= '<ol>';
                foreach ($it['steps'] as $st) $out .= '<li>' . ppText(is_scalar($st) ? (string)$st : json_encode($st)) . '</li>';
                $out .= '</ol>';
            }
        }
        return $out;
    }
    return ppText($s);
}

/** reportDetails is free text, or occasionally JSON. */
function ppReport(?string $s): string
{
    if ($s === null || trim($s) === '') return '';
    $j = json_decode($s, true);
    if (is_array($j)) {
        $out = '<dl class="pp-kv">';
        foreach (array_slice($j, 0, 40, true) as $k => $v) {
            $out .= '<dt>' . e((string)$k) . '</dt><dd>' . e(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES)) . '</dd>';
        }
        return $out . '</dl>';
    }
    return ppText($s);
}

function ppDate(?string $d): string
{
    if (!$d) return '<span class="pp-muted">&mdash;</span>';
    $ts = strtotime($d);
    return $ts ? e(date('M j, Y', $ts)) : e($d);
}

function ppPct(int $pass, int $fail): int
{
    return ($pass + $fail) > 0 ? (int)round(100 * $pass / ($pass + $fail)) : 0;
}

const PP_STATUS_ORDER   = ['Fail' => 0, 'Pending' => 1, 'Pass' => 2, 'Excluded' => 3, 'Undetectable' => 4];
const PP_SEVERITY_ORDER = ['High' => 0, 'Medium' => 1, 'Low' => 2];

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$checks = [];
$lastSynced = null;
if ($gripEnabled) {
    try {
        foreach ($db->fetchAll('SELECT * FROM shadow_saas_grip_posture_checks') as $r) {
            $checks[$r['uuid']] = $grip->decryptGripRow('shadow_saas_grip_posture_checks', $r);
        }
        $lastSynced = $db->fetchOne('SELECT MAX(last_synced_at) AS t FROM shadow_saas_grip_posture_checks')['t'] ?? null;
    } catch (Throwable $e) {
        error_log('Posture page load failed: ' . $e->getMessage());
        $error = $error ?: t('shadow-saas-posture.load_failed');
    }
}

// ---- Single check: live findings -------------------------------------------
$viewId = (string)($_GET['id'] ?? '');
$check  = null;
if ($viewId !== '') {
    if (!preg_match('/^[0-9a-f-]{36}$/i', $viewId) || !isset($checks[$viewId])) {
        http_response_code(404);
        $error = t('shadow-saas-posture.check_not_found');
        $viewId = '';
    } else {
        $check = $checks[$viewId];
    }
}
$findings = [];
$findingsError = '';
$fPage = max(1, (int)($_GET['page'] ?? 1));
$fPer  = 100;
if ($check) {
    $res = $grip->getPostureFindingsPage($viewId, ($fPage - 1) * $fPer, $fPer);
    if ($res['success']) {
        $findings = $res['rows'];
    } else {
        error_log('Posture findings fetch failed for ' . $viewId . ': ' . $res['error']);
        $findingsError = t('shadow-saas-posture.findings_failed');
    }
}

// ---- Aggregates (over every check, independent of filters) ------------------
$ppCounts = ['Pass' => 0, 'Fail' => 0, 'Undetectable' => 0, 'Excluded' => 0, 'Pending' => 0];
$tenants = [];
$domains = [];
$severities = [];
$modules = [];
foreach ($checks as $c) {
    $st = (string)($c['status'] ?? '');
    if (isset($ppCounts[$st])) $ppCounts[$st]++;
    $tk = trim(($c['tenant_name'] ?? '') . '|' . ($c['integration_type'] ?? ''), '|');
    if (!isset($tenants[$tk])) $tenants[$tk] = ['name' => $c['tenant_name'] ?? '', 'type' => $c['integration_type'] ?? '', 'pass' => 0, 'fail' => 0];
    $dm = (string)($c['security_domain'] ?? '');
    if ($dm !== '' && !isset($domains[$dm])) $domains[$dm] = ['pass' => 0, 'fail' => 0];
    if ($st === 'Pass') { $tenants[$tk]['pass']++; if ($dm !== '') $domains[$dm]['pass']++; }
    if ($st === 'Fail') { $tenants[$tk]['fail']++; if ($dm !== '') $domains[$dm]['fail']++; }
    if (!empty($c['severity'])) $severities[$c['severity']] = true;
    if (!empty($c['module']))   $modules[$c['module']] = true;
}
$score = ppPct($ppCounts['Pass'], $ppCounts['Fail']);
uasort($tenants, fn($a, $b) => ppPct($a['pass'], $a['fail']) <=> ppPct($b['pass'], $b['fail']));
$failedDomains = array_filter($domains, fn($d) => $d['fail'] > 0);
uasort($failedDomains, fn($a, $b) => [$b['fail'], $a['pass']] <=> [$a['fail'], $b['pass']]);
$domainNames = array_keys($domains);
sort($domainNames, SORT_NATURAL | SORT_FLAG_CASE);
$severityNames = array_keys($severities);
usort($severityNames, fn($a, $b) => (PP_SEVERITY_ORDER[$a] ?? 9) <=> (PP_SEVERITY_ORDER[$b] ?? 9));
$moduleNames = array_keys($modules);
sort($moduleNames, SORT_NATURAL | SORT_FLAG_CASE);

// ---- Filters (whitelisted against the values actually present) ------------
$fStatus = (string)($_GET['status'] ?? 'Fail');
if ($fStatus !== 'all' && !isset($ppCounts[$fStatus])) $fStatus = 'Fail';
$fSeverity = (string)($_GET['severity'] ?? '');
if (!in_array($fSeverity, $severityNames, true)) $fSeverity = '';
$fDomain = (string)($_GET['domain'] ?? '');
if (!in_array($fDomain, $domainNames, true)) $fDomain = '';
$fModule = (string)($_GET['module'] ?? '');
if (!in_array($fModule, $moduleNames, true)) $fModule = '';
$fQ = trim((string)($_GET['q'] ?? ''));

$list = array_filter($checks, function ($c) use ($fStatus, $fSeverity, $fDomain, $fModule, $fQ) {
    if ($fStatus !== 'all' && ($c['status'] ?? '') !== $fStatus) return false;
    if ($fSeverity !== '' && ($c['severity'] ?? '') !== $fSeverity) return false;
    if ($fDomain !== '' && ($c['security_domain'] ?? '') !== $fDomain) return false;
    if ($fModule !== '' && ($c['module'] ?? '') !== $fModule) return false;
    if ($fQ !== '') {
        $hay = ($c['posture_check_id'] ?? '') . ' ' . ($c['description'] ?? '') . ' ' . ($c['security_domain'] ?? '');
        if (stripos($hay, $fQ) === false) return false;
    }
    return true;
});
usort($list, fn($a, $b) =>
    [PP_STATUS_ORDER[$a['status'] ?? ''] ?? 9, PP_SEVERITY_ORDER[$a['severity'] ?? ''] ?? 9, -(int)($a['findings_count'] ?? 0)]
    <=>
    [PP_STATUS_ORDER[$b['status'] ?? ''] ?? 9, PP_SEVERITY_ORDER[$b['severity'] ?? ''] ?? 9, -(int)($b['findings_count'] ?? 0)]
    ?: strnatcasecmp((string)($a['posture_check_id'] ?? ''), (string)($b['posture_check_id'] ?? ''))
);

function ppQuery(array $over = []): string
{
    global $fStatus, $fSeverity, $fDomain, $fModule, $fQ;
    $q = array_merge(['status' => $fStatus, 'severity' => $fSeverity, 'domain' => $fDomain, 'module' => $fModule, 'q' => $fQ], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-posture.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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
        .pp-btn.secondary { background: #6b7280; }

        .pp-cards { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) minmax(260px, 1.3fr); gap: 16px; margin-bottom: 22px; }
        .pp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; min-width: 0; }
        .pp-card h3 { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 12px; }
        .pp-score { font-size: 40px; font-weight: 700; color: #111827; line-height: 1; }
        .pp-score small { font-size: 15px; font-weight: 500; color: #6b7280; margin-left: 8px; }
        .pp-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: #e5e7eb; margin: 14px 0 8px; }
        .pp-bar .p { background: var(--pp-pass); }
        .pp-bar .f { background: var(--pp-fail); }
        .pp-legend { display: flex; gap: 14px; font-size: 12px; color: #6b7280; flex-wrap: wrap; }
        .pp-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
        .pp-rowstat { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 0; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-rowstat:first-of-type { border-top: none; }
        .pp-rowstat a { color: inherit; text-decoration: none; }
        .pp-rowstat a:hover { color: var(--theme-button-color); }
        .pp-rowstat .v { font-weight: 600; white-space: nowrap; }
        .pp-rowstat .sub { color: #9ca3af; font-size: 12px; margin-left: 6px; }

        .pp-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .pp-chip { font-size: 13px; padding: 5px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
        .pp-chip.on { background: var(--theme-button-color); color: #fff; border-color: var(--theme-button-color); font-weight: 600; }
        .pp-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
        .pp-filters select, .pp-filters input[type=search] { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; max-width: 100%; }

        .pp-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .pp-row > summary { display: grid; grid-template-columns: 150px 110px 80px minmax(0, 1fr) 80px 110px; gap: 12px; align-items: center; padding: 12px 16px; cursor: pointer; list-style: none; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-row:first-of-type > summary { border-top: none; }
        .pp-row > summary::-webkit-details-marker { display: none; }
        .pp-row > summary:hover { background: #f9fafb; }
        .pp-row[open] > summary { background: #f9fafb; }
        .pp-listhead { display: grid; grid-template-columns: 150px 110px 80px minmax(0, 1fr) 80px 110px; gap: 12px; padding: 10px 16px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .pp-id { font-weight: 600; color: #111827; }
        .pp-tenant { font-size: 12px; color: #6b7280; }
        .pp-num { text-align: right; font-variant-numeric: tabular-nums; }
        .pp-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .sev-High { background: #fee2e2; color: #991b1b; }
        .sev-Medium { background: #ffedd5; color: #9a3412; }
        .sev-Low { background: #e0f2fe; color: #075985; }
        .st-Fail { background: #fee2e2; color: #991b1b; }
        .st-Pass { background: #dcfce7; color: #166534; }
        .st-Undetectable, .st-Excluded, .st-Pending { background: #f3f4f6; color: #4b5563; }
        .pp-body { padding: 4px 16px 18px 16px; background: #f9fafb; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-body h4 { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 16px 0 6px; }
        .pp-body ol { margin: 4px 0 0 18px; padding: 0; }
        .pp-body .pp-sub { font-weight: 600; margin: 6px 0 2px; }
        .pp-comp { display: flex; flex-wrap: wrap; gap: 6px; }
        .pp-comp span { background: #eef2ff; color: #3730a3; border-radius: 6px; padding: 3px 8px; font-size: 12px; }
        .pp-kv { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: 13px; }
        .pp-kv dt { color: #6b7280; }
        .pp-kv dd { margin: 0; word-break: break-word; }
        .pp-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }

        .data-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .data-table th { background: #f9fafb; padding: 11px 14px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .data-table td { padding: 11px 14px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .pp-pager { display: flex; gap: 10px; align-items: center; justify-content: flex-end; margin-top: 14px; font-size: 13px; color: #6b7280; }

        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }

        @media (max-width: 1100px) { .pp-cards { grid-template-columns: 1fr; } }
        @media (max-width: 860px) {
            .pp-listhead { display: none; }
            .pp-row > summary { grid-template-columns: 1fr auto; }
            .pp-row > summary .pp-hide-sm { display: none; }
        }
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
            <?php $currentPage = 'shadow_saas'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <nav class="ss-tabs" aria-label="<?php echo e(t('shadow-saas.heading')); ?>">
                    <a href="shadow-saas.php"><?php echo e(t('shadow-saas-posture.tab_applications')); ?></a>
                    <a href="shadow-saas-posture.php" class="active"><?php echo e(t('shadow-saas-posture.tab_posture')); ?></a>
                </nav>

                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <?php if (!$gripEnabled): ?>
                    <h1 class="page-title"><?php echo e(t('shadow-saas-posture.title')); ?></h1>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-posture.grip_disabled')); ?></div>

                <?php elseif ($check): ?>
                    <!-- ===== Single check: violating entities, live from Grip ===== -->
                    <div class="pp-head">
                        <div>
                            <h1 class="page-title" style="margin:0;"><?php echo e($check['posture_check_id'] ?? ''); ?>
                                <span class="pp-pill st-<?php echo e($check['status'] ?? ''); ?>"><?php echo e($check['status'] ?? ''); ?></span>
                                <span class="pp-pill sev-<?php echo e($check['severity'] ?? ''); ?>"><?php echo e($check['severity'] ?? ''); ?></span>
                            </h1>
                            <p><?php echo e($check['description'] ?? ''); ?></p>
                            <p class="pp-tenant"><?php echo e(trim(($check['tenant_name'] ?? '') . ' · ' . ($check['security_domain'] ?? ''), ' ·')); ?></p>
                        </div>
                        <a class="pp-btn secondary" href="shadow-saas-posture.php">&larr; <?php echo e(t('shadow-saas-posture.back_to_posture')); ?></a>
                    </div>

                    <h2 style="font-size:16px;font-weight:600;color:#1f2937;margin:0 0 10px;"><?php echo e(t('shadow-saas-posture.findings_heading')); ?>
                        <?php if ($check['findings_count'] !== null): ?><span class="pp-muted">(<?php echo (int)$check['findings_count']; ?>)</span><?php endif; ?></h2>
                    <p class="pp-muted" style="font-size:13px;margin:0 0 12px;"><?php echo e(t('shadow-saas-posture.findings_live_note')); ?></p>

                    <?php if ($findingsError): ?>
                        <div class="alert alert-danger"><?php echo e($findingsError); ?></div>
                    <?php elseif (!$findings): ?>
                        <div class="pp-empty"><?php echo e(t('shadow-saas-posture.no_findings')); ?></div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                        <table class="data-table">
                            <thead><tr>
                                <th><?php echo e(t('shadow-saas-posture.col_entity')); ?></th>
                                <th><?php echo e(t('shadow-saas-posture.col_entity_type')); ?></th>
                                <th><?php echo e(t('shadow-saas-posture.col_status')); ?></th>
                                <th><?php echo e(t('shadow-saas-posture.col_violating')); ?></th>
                                <th><?php echo e(t('shadow-saas-posture.col_last_detected')); ?></th>
                                <th><?php echo e(t('shadow-saas-posture.col_details')); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($findings as $f): if (!is_array($f)) continue;
                                $extra = is_array($f['extraData'] ?? null) ? array_slice($f['extraData'], 0, 6, true) : [];
                            ?>
                                <tr>
                                    <td><strong><?php echo e((string)($f['displayName'] ?? ($f['entityIdentifier'] ?? ''))); ?></strong>
                                        <?php if (!empty($f['entityIdentifier']) && ($f['entityIdentifier'] ?? '') !== ($f['displayName'] ?? '')): ?><div class="pp-tenant"><?php echo e((string)$f['entityIdentifier']); ?></div><?php endif; ?></td>
                                    <td><?php echo e(trim(($f['entityCategory'] ?? '') . ' / ' . ($f['entityType'] ?? ''), ' /')); ?></td>
                                    <td><?php echo e((string)($f['status'] ?? '')); ?></td>
                                    <td><?php echo !empty($f['isCurrentlyViolating']) ? '<span class="pp-pill st-Fail">' . e(t('shadow-saas-posture.yes')) . '</span>' : '<span class="pp-pill st-Pass">' . e(t('shadow-saas-posture.no')) . '</span>'; ?></td>
                                    <td><?php echo ppDate($f['lastDetectedAt'] ?? null); ?></td>
                                    <td><?php if ($extra): ?><dl class="pp-kv"><?php foreach ($extra as $k => $v): ?><dt><?php echo e((string)$k); ?></dt><dd><?php echo e(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES)); ?></dd><?php endforeach; ?></dl><?php else: ?><span class="pp-muted">&mdash;</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="pp-pager">
                            <?php if ($fPage > 1): ?><a class="pp-chip" href="?<?php echo e(http_build_query(['id' => $viewId, 'page' => $fPage - 1])); ?>">&larr; <?php echo e(t('shadow-saas-posture.prev')); ?></a><?php endif; ?>
                            <span><?php echo e(t('shadow-saas-posture.page_n', $fPage)); ?></span>
                            <?php if (count($findings) >= $fPer): ?><a class="pp-chip" href="?<?php echo e(http_build_query(['id' => $viewId, 'page' => $fPage + 1])); ?>"><?php echo e(t('shadow-saas-posture.next')); ?> &rarr;</a><?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ===== Overview ===== -->
                    <div class="pp-head">
                        <div>
                            <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-posture.title')); ?></h1>
                            <p><?php echo e(t('shadow-saas-posture.intro')); ?></p>
                            <?php if ($lastSynced): ?><p class="pp-tenant"><?php echo e(t('shadow-saas-posture.last_synced', date('M j, Y g:i A', strtotime($lastSynced)))); ?></p><?php endif; ?>
                        </div>
                        <?php if ($canManage): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="refresh_posture">
                            <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.refresh')); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$checks): ?>
                        <div class="pp-empty"><?php echo e(t('shadow-saas-posture.no_data')); ?></div>
                    <?php else: ?>
                    <div class="pp-cards">
                        <div class="pp-card" id="pp-score">
                            <h3><?php echo e(t('shadow-saas-posture.general_score')); ?></h3>
                            <div class="pp-score"><?php echo $score; ?>%<small><?php echo (int)$ppCounts['Pass']; ?>/<?php echo (int)($ppCounts['Pass'] + $ppCounts['Fail']); ?></small></div>
                            <?php $tot = max(1, $ppCounts['Pass'] + $ppCounts['Fail']); ?>
                            <div class="pp-bar"><span class="p" style="width:<?php echo round(100 * $ppCounts['Pass'] / $tot, 2); ?>%"></span><span class="f" style="width:<?php echo round(100 * $ppCounts['Fail'] / $tot, 2); ?>%"></span></div>
                            <div class="pp-legend">
                                <span><span class="pp-dot" style="background:var(--pp-pass)"></span><?php echo e(t('shadow-saas-posture.pass')); ?> <?php echo (int)$ppCounts['Pass']; ?></span>
                                <span><span class="pp-dot" style="background:var(--pp-fail)"></span><?php echo e(t('shadow-saas-posture.fail')); ?> <?php echo (int)$ppCounts['Fail']; ?></span>
                                <span><span class="pp-dot" style="background:#d1d5db"></span><?php echo e(t('shadow-saas-posture.not_evaluated')); ?> <?php echo (int)($ppCounts['Undetectable'] + $ppCounts['Excluded'] + $ppCounts['Pending']); ?></span>
                            </div>
                        </div>
                        <div class="pp-card">
                            <h3><?php echo e(t('shadow-saas-posture.lowest_tenants')); ?></h3>
                            <?php foreach (array_slice($tenants, 0, 5, true) as $tn): ?>
                            <div class="pp-rowstat">
                                <span><?php echo e($tn['name'] !== '' ? $tn['name'] : t('shadow-saas-posture.unknown')); ?><span class="sub"><?php echo e($tn['type']); ?></span></span>
                                <span class="v"><?php echo ppPct($tn['pass'], $tn['fail']); ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="pp-card">
                            <h3><?php echo e(t('shadow-saas-posture.top_failed_domains')); ?></h3>
                            <?php if (!$failedDomains): ?><div class="pp-muted" style="font-size:14px;"><?php echo e(t('shadow-saas-posture.no_failed_domains')); ?></div><?php endif; ?>
                            <?php foreach (array_slice($failedDomains, 0, 5, true) as $dn => $d): ?>
                            <div class="pp-rowstat">
                                <a href="<?php echo e(ppQuery(['status' => 'Fail', 'domain' => $dn])); ?>"><?php echo e($dn); ?></a>
                                <span class="v" title="<?php echo e(t('shadow-saas-posture.failed_of_evaluated')); ?>"><?php echo (int)$d['fail']; ?>/<?php echo (int)($d['fail'] + $d['pass']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pp-chips">
                        <?php foreach (['Fail', 'Pass', 'Undetectable', 'Excluded', 'Pending', 'all'] as $sk):
                            if ($sk !== 'all' && $ppCounts[$sk] === 0 && $fStatus !== $sk) continue; ?>
                            <a class="pp-chip<?php echo $fStatus === $sk ? ' on' : ''; ?>" href="<?php echo e(ppQuery(['status' => $sk])); ?>"><?php echo e($sk === 'all' ? t('shadow-saas-posture.all') : t('shadow-saas-posture.status_' . strtolower($sk))); ?> (<?php echo $sk === 'all' ? count($checks) : (int)$ppCounts[$sk]; ?>)</a>
                        <?php endforeach; ?>
                    </div>

                    <form method="GET" class="pp-filters">
                        <input type="hidden" name="status" value="<?php echo e($fStatus); ?>">
                        <select name="severity" aria-label="<?php echo e(t('shadow-saas-posture.col_severity')); ?>">
                            <option value=""><?php echo e(t('shadow-saas-posture.any_severity')); ?></option>
                            <?php foreach ($severityNames as $sv): ?><option value="<?php echo e($sv); ?>" <?php echo $fSeverity === $sv ? 'selected' : ''; ?>><?php echo e($sv); ?></option><?php endforeach; ?>
                        </select>
                        <select name="domain" aria-label="<?php echo e(t('shadow-saas-posture.col_domain')); ?>">
                            <option value=""><?php echo e(t('shadow-saas-posture.any_domain')); ?></option>
                            <?php foreach ($domainNames as $dn): ?><option value="<?php echo e($dn); ?>" <?php echo $fDomain === $dn ? 'selected' : ''; ?>><?php echo e($dn); ?></option><?php endforeach; ?>
                        </select>
                        <select name="module" aria-label="<?php echo e(t('shadow-saas-posture.col_module')); ?>">
                            <option value=""><?php echo e(t('shadow-saas-posture.any_module')); ?></option>
                            <?php foreach ($moduleNames as $mn): ?><option value="<?php echo e($mn); ?>" <?php echo $fModule === $mn ? 'selected' : ''; ?>><?php echo e($mn); ?></option><?php endforeach; ?>
                        </select>
                        <input type="search" name="q" value="<?php echo e($fQ); ?>" placeholder="<?php echo e(t('shadow-saas-posture.search_placeholder')); ?>">
                        <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                        <?php if ($fSeverity !== '' || $fDomain !== '' || $fModule !== '' || $fQ !== ''): ?><a class="pp-chip" href="<?php echo e(ppQuery(['severity' => '', 'domain' => '', 'module' => '', 'q' => ''])); ?>"><?php echo e(t('shadow-saas-posture.clear')); ?></a><?php endif; ?>
                    </form>

                    <?php if (!$list): ?>
                        <div class="pp-empty"><?php echo e(t('shadow-saas-posture.no_matches')); ?></div>
                    <?php else: ?>
                    <div class="pp-list" id="pp-list">
                        <div class="pp-listhead">
                            <span><?php echo e(t('shadow-saas-posture.col_check')); ?></span>
                            <span class="pp-hide-sm"><?php echo e(t('shadow-saas-posture.col_tenant')); ?></span>
                            <span class="pp-hide-sm"><?php echo e(t('shadow-saas-posture.col_severity')); ?></span>
                            <span class="pp-hide-sm"><?php echo e(t('shadow-saas-posture.col_description')); ?></span>
                            <span class="pp-hide-sm pp-num"><?php echo e(t('shadow-saas-posture.col_findings')); ?></span>
                            <span><?php echo e(t('shadow-saas-posture.col_status')); ?></span>
                        </div>
                        <?php foreach ($list as $c):
                            $comp = json_decode((string)($c['compliance'] ?? '[]'), true);
                            $comp = is_array($comp) ? $comp : [];
                        ?>
                        <details class="pp-row" data-check="<?php echo e($c['uuid']); ?>">
                            <summary>
                                <span class="pp-id"><?php echo e($c['posture_check_id'] ?? ''); ?></span>
                                <span class="pp-tenant pp-hide-sm"><?php echo e($c['tenant_name'] ?? ''); ?><br><?php echo e($c['module'] ?? ''); ?></span>
                                <span class="pp-hide-sm"><span class="pp-pill sev-<?php echo e($c['severity'] ?? ''); ?>"><?php echo e($c['severity'] ?? ''); ?></span></span>
                                <span class="pp-hide-sm"><?php echo e($c['description'] ?? ''); ?></span>
                                <span class="pp-hide-sm pp-num"><?php echo $c['findings_count'] !== null ? (int)$c['findings_count'] : '<span class="pp-muted">N/A</span>'; ?></span>
                                <span><span class="pp-pill st-<?php echo e($c['status'] ?? ''); ?>"><?php echo e($c['status'] ?? ''); ?></span></span>
                            </summary>
                            <div class="pp-body">
                                <h4><?php echo e(t('shadow-saas-posture.col_description')); ?></h4>
                                <div><?php echo e($c['description'] ?? ''); ?> <span class="pp-muted">&middot; <?php echo e($c['security_domain'] ?? ''); ?></span></div>
                                <?php if (!empty($c['risk_description'])): ?>
                                    <h4><?php echo e(t('shadow-saas-posture.risk')); ?></h4><div><?php echo ppText($c['risk_description']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['mitigation_steps'])): ?>
                                    <h4><?php echo e(t('shadow-saas-posture.mitigation')); ?></h4><div><?php echo ppMitigation($c['mitigation_steps']); ?></div>
                                <?php endif; ?>
                                <?php if ($comp): ?>
                                    <h4><?php echo e(t('shadow-saas-posture.compliance')); ?></h4>
                                    <div class="pp-comp"><?php foreach ($comp as $cc): if (!is_array($cc)) continue; ?><span><?php echo e(trim(($cc['framework'] ?? '') . ' ' . ($cc['controlPath'] ?? ''))); ?></span><?php endforeach; ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['report_details'])): ?>
                                    <h4><?php echo e(t('shadow-saas-posture.report_details')); ?></h4><div><?php echo ppReport($c['report_details']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($c['status_override_reason']) || !empty($c['severity_override_reason'])): ?>
                                    <h4><?php echo e(t('shadow-saas-posture.overrides')); ?></h4>
                                    <?php if (!empty($c['status_override_reason'])): ?><div><?php echo e(t('shadow-saas-posture.status_override')); ?>: <?php echo ppText($c['status_override_reason']); ?></div><?php endif; ?>
                                    <?php if (!empty($c['severity_override_reason'])): ?><div><?php echo e(t('shadow-saas-posture.severity_override')); ?>: <?php echo ppText($c['severity_override_reason']); ?></div><?php endif; ?>
                                <?php endif; ?>
                                <h4><?php echo e(t('shadow-saas-posture.updated')); ?></h4>
                                <div><?php echo ppDate($c['updated_at_grip'] ?? null); ?></div>
                                <?php if ((int)($c['findings_count'] ?? 0) > 0): ?>
                                    <p style="margin:14px 0 0;"><a class="pp-btn" href="?<?php echo e(http_build_query(['id' => $c['uuid']])); ?>"><?php echo e(t('shadow-saas-posture.view_findings', (int)$c['findings_count'])); ?></a></p>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
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
