<?php
/**
 * Shadow SaaS - Identity Threat Detection & Response (Grip ITDR).
 *
 * Mirrors Grip's ITDR view: active threats by severity and by status, the most
 * exposed identities (threat count, worst severity, total SaaS accounts), and
 * every threat with its description, potential impacts, mitigation, threat
 * details and the event records that triggered it, linked back to Grip.
 *
 * Threats come from shadow_saas_grip_threats, refreshed on every Grip sync or on
 * demand ("Refresh from Grip"). Identity names, emails, event logs and threat
 * details are personal data: encrypted at rest and decrypted only for display.
 *
 * Waivers: admin / cyber_tprm can waive a threat with a written justification
 * and an optional expiry (shadow_saas_grip_waivers), and revoke it. A waived
 * threat shows as "waived" and is left out of the exposed-identity counts.
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
    } elseif (($_POST['action'] ?? '') === 'refresh_threats' && $gripEnabled) {
        try {
            $n = $grip->syncThreats();
            $auth->audit($user['id'], 'shadow_saas_itdr_refresh', 'shadow_saas_grip_threats', null, ['new' => ['threats' => $n]]);
            $success = t('shadow-saas-itdr.refreshed', $n);
        } catch (Throwable $e) {
            error_log('ITDR refresh failed: ' . $e->getMessage());
            $error = t('shadow-saas-itdr.refresh_failed');
        }
    } elseif (($_POST['action'] ?? '') === 'waive_threat' && $gripEnabled) {
        $aid = (string)($_POST['alert_id'] ?? '');
        $in = GripService::validateWaiverInput((string)($_POST['justification'] ?? ''), (string)($_POST['expires_at'] ?? ''));
        $thr = $aid !== '' ? $db->fetchOne("SELECT alert_id, alert_name FROM shadow_saas_grip_threats WHERE alert_id = :a", [':a' => $aid]) : null;
        if (!$thr) {
            $error = t('shadow-saas-itdr.not_found');
        } elseif (isset($in['error'])) {
            $error = t('shadow-saas-waiver.' . $in['error'], GripService::WAIVER_JUSTIFICATION_MIN);
        } else {
            $added = $grip->addWaivers('threat', (string)$thr['alert_id'], [['key' => '', 'label' => (string)($thr['alert_name'] ?? '')]], $in['justification'], $in['expires'], (int)$user['id']);
            $auth->audit($user['id'], 'shadow_saas_itdr_waive', 'shadow_saas_grip_threats', null, ['new' => [
                'alert_id' => $thr['alert_id'], 'waived' => $added, 'expires_at' => $in['expires'], 'justification' => $in['justification'],
            ]]);
            $success = $added ? t('shadow-saas-waiver.threat_waived', (string)$thr['alert_id']) : t('shadow-saas-waiver.already_waived');
        }
    } elseif (($_POST['action'] ?? '') === 'revoke_waiver' && $gripEnabled) {
        $row = $grip->revokeWaiver((int)($_POST['waiver_id'] ?? 0), (int)$user['id'], (string)($_POST['revoke_reason'] ?? ''), 'threat');
        if (!$row || $row['kind'] !== 'threat') {
            $error = t('shadow-saas-waiver.not_found');
        } else {
            $auth->audit($user['id'], 'shadow_saas_itdr_waiver_revoke', 'shadow_saas_grip_threats', null, ['new' => ['waiver_id' => (int)$row['id'], 'alert_id' => $row['subject_id']]]);
            $success = t('shadow-saas-waiver.revoked');
        }
    }
}
$csrfToken = $security->generateCSRFToken();

const TD_SEVERITY_ORDER = ['Critical' => 0, 'High' => 1, 'Medium' => 2, 'Low' => 3, 'Info' => 4];
function tdSev(?string $s): string { $s = ucfirst(strtolower(trim((string)$s))); return $s === '' ? 'Unknown' : $s; }
function tdDateTime(?string $d): string
{
    if (!$d) return '<span class="pp-muted">&mdash;</span>';
    $ts = strtotime($d);
    return $ts ? e(date('M j, Y H:i', $ts)) : e($d);
}
function tdScalar($v): string { return is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function tdPlain(?string $s): string
{
    if ($s === null || trim($s) === '') return '';
    $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s);
    $s = preg_replace('#<\s*li[^>]*>#i', "\n- ", $s);
    $s = preg_replace('#</\s*(p|div|li|ul|ol|h[1-6])\s*>#i', "\n", $s);
    $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return nl2br(e(preg_replace("/\n{3,}/", "\n\n", $s)));
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$tdThreats = [];
$tdSynced = null;
if ($gripEnabled) {
    try {
        $tdWaivers = $grip->activeWaivers('threat');
        foreach ($db->fetchAll('SELECT * FROM shadow_saas_grip_threats') as $r) {
            $r = $grip->decryptGripRow('shadow_saas_grip_threats', $r);
            $r['sev'] = tdSev($r['severity'] ?? '');
            // FairTPRM status: a waived threat shows as 'waived' (Grip's own status kept).
            $r['grip_status'] = $r['status'];
            $r['waiver'] = $tdWaivers[(string)$r['alert_id']][''] ?? null;
            if ($r['waiver']) $r['status'] = 'waived';
            $tdThreats[] = $r;
        }
        $tdSynced = $db->fetchOne('SELECT MAX(last_synced_at) AS t FROM shadow_saas_grip_threats')['t'] ?? null;
    } catch (Throwable $e) {
        error_log('ITDR load failed: ' . $e->getMessage());
        $error = $error ?: t('shadow-saas-posture.load_failed');
    }
}

$tdBySev = [];
$tdByStatus = [];
$tdCats = [];
$tdIdent = [];
foreach ($tdThreats as $t) {
    $tdBySev[$t['sev']] = ($tdBySev[$t['sev']] ?? 0) + 1;
    $st = strtolower((string)($t['status'] ?? '')) ?: 'unknown';
    $tdByStatus[$st] = ($tdByStatus[$st] ?? 0) + 1;
    if (!empty($t['category'])) $tdCats[$t['category']] = true;
    if (($t['related_entity_type'] ?? '') === 'identity' && !empty($t['related_entity_id']) && empty($t['waiver'])) {
        $k = (string)$t['related_entity_id'];
        $tdIdent[$k] ??= ['id' => $k, 'name' => $t['related_entity_name'] ?? '', 'mail' => $t['related_identity_mail'] ?? '', 'count' => 0, 'worst' => 'Info', 'last' => null];
        $tdIdent[$k]['count']++;
        if ((TD_SEVERITY_ORDER[$t['sev']] ?? 9) < (TD_SEVERITY_ORDER[$tdIdent[$k]['worst']] ?? 9)) $tdIdent[$k]['worst'] = $t['sev'];
        if ($t['created_at_grip'] && ($tdIdent[$k]['last'] === null || $t['created_at_grip'] > $tdIdent[$k]['last'])) $tdIdent[$k]['last'] = $t['created_at_grip'];
    }
}
uksort($tdBySev, fn($a, $b) => (TD_SEVERITY_ORDER[$a] ?? 9) <=> (TD_SEVERITY_ORDER[$b] ?? 9));
arsort($tdByStatus);
$tdCatNames = array_keys($tdCats);
sort($tdCatNames, SORT_NATURAL | SORT_FLAG_CASE);

// Total SaaS accounts per exposed identity, from the Grip users mirror.
if ($tdIdent) {
    $ph = [];
    $params = [];
    foreach (array_keys($tdIdent) as $i => $gid) { $ph[] = ":u{$i}"; $params[":u{$i}"] = $gid; }
    try {
        foreach ($db->fetchAll('SELECT grip_id, number_of_saas, sso_percentage FROM shadow_saas_grip_users WHERE grip_id IN (' . implode(',', $ph) . ')', $params) as $u) {
            $tdIdent[$u['grip_id']]['accounts'] = $u['number_of_saas'];
            $tdIdent[$u['grip_id']]['sso'] = $u['sso_percentage'];
        }
    } catch (Throwable $e) {
        error_log('ITDR identity accounts load failed: ' . $e->getMessage());
    }
}
uasort($tdIdent, fn($a, $b) => [$b['count'], TD_SEVERITY_ORDER[$a['worst']] ?? 9, (int)($b['accounts'] ?? 0)] <=> [$a['count'], TD_SEVERITY_ORDER[$b['worst']] ?? 9, (int)($a['accounts'] ?? 0)]);

// Filters (whitelisted against present values)
$fSev = (string)($_GET['severity'] ?? '');
if (!isset($tdBySev[$fSev])) $fSev = '';
$fStatus = (string)($_GET['status'] ?? '');
if (!isset($tdByStatus[$fStatus])) $fStatus = '';
$fCat = (string)($_GET['category'] ?? '');
if (!in_array($fCat, $tdCatNames, true)) $fCat = '';
$fQ = trim((string)($_GET['q'] ?? ''));

$tdList = array_values(array_filter($tdThreats, function ($t) use ($fSev, $fStatus, $fCat, $fQ) {
    if ($fSev !== '' && $t['sev'] !== $fSev) return false;
    if ($fStatus !== '' && strtolower((string)($t['status'] ?? '')) !== $fStatus) return false;
    if ($fCat !== '' && ($t['category'] ?? '') !== $fCat) return false;
    if ($fQ !== '') {
        $hay = implode(' ', [$t['alert_id'], $t['alert_name'] ?? '', $t['alert_type'] ?? '', $t['category'] ?? '', $t['related_entity_name'] ?? '', $t['related_identity_mail'] ?? '']);
        if (stripos($hay, $fQ) === false) return false;
    }
    return true;
}));
usort($tdList, fn($a, $b) => strcmp((string)($b['created_at_grip'] ?? ''), (string)($a['created_at_grip'] ?? '')));

function tdQuery(array $over = []): string
{
    global $fSev, $fStatus, $fCat, $fQ;
    $q = array_merge(['severity' => $fSev, 'status' => $fStatus, 'category' => $fCat, 'q' => $fQ], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-itdr.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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
        .pp-chip.on { background: var(--theme-button-color); color: #fff; border-color: var(--theme-button-color); font-weight: 600; }
        .pp-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
        .pp-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .pp-cards { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) minmax(320px, 1.6fr); gap: 16px; margin-bottom: 22px; }
        .pp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; min-width: 0; }
        .pp-card h3 { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 12px; }
        .td-big { font-size: 36px; font-weight: 700; color: #111827; line-height: 1; }
        .td-big small { font-size: 14px; font-weight: 500; color: #6b7280; margin-left: 6px; }
        .td-rowstat { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 0; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .td-rowstat:first-of-type { border-top: none; }
        .td-rowstat a { color: inherit; text-decoration: none; }
        .td-rowstat a:hover { color: var(--theme-button-color); }
        .td-ident { width: 100%; border-collapse: collapse; font-size: 13px; }
        .td-ident th { text-align: left; color: #6b7280; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 0 6px 6px 0; }
        .td-ident td { padding: 6px 6px 6px 0; border-top: 1px solid #f3f4f6; vertical-align: top; }
        .td-sub { font-size: 12px; color: #6b7280; }
        .sev-Critical { background: #7f1d1d; color: #fff; }
        .sev-High { background: #fee2e2; color: #991b1b; }
        .sev-Medium { background: #ffedd5; color: #9a3412; }
        .sev-Low { background: #e0f2fe; color: #075985; }
        .sev-Info, .sev-Unknown { background: #f3f4f6; color: #4b5563; }
        .td-status { background: #f3f4f6; color: #374151; }
        .td-status.open { background: #fef3c7; color: #92400e; }
        .td-status.waived { background: #ede9fe; color: #5b21b6; }
        .pp-waive { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; font-size: 13px; max-width: 720px; }
        .pp-waive textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
        .pp-waive-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .pp-waive-row input[type=date] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        .pp-waiver-note { font-size: 13px; color: #374151; margin-top: 4px; white-space: pre-wrap; }
        .td-inline { margin-top: 8px; }
        .td-revoke { cursor: pointer; border: 1px solid #ddd6fe; background: #fff; }
        .pp-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
        .pp-filters select, .pp-filters input[type=search] { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; max-width: 100%; }
        .pp-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .td-grid { display: grid; grid-template-columns: 100px 90px minmax(0, 1.2fr) minmax(0, 1.2fr) 90px 150px 100px; gap: 12px; align-items: center; }
        .pp-listhead { padding: 10px 16px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .pp-row > summary { padding: 12px 16px; cursor: pointer; list-style: none; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-row:first-of-type > summary { border-top: none; }
        .pp-row > summary::-webkit-details-marker { display: none; }
        .pp-row > summary:hover, .pp-row[open] > summary { background: #f9fafb; }
        .pp-body { padding: 4px 16px 18px; background: #f9fafb; border-top: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pp-body h4 { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 16px 0 6px; }
        .pp-body ul, .pp-body ol { margin: 4px 0 0 18px; padding: 0; }
        .pp-kv { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: 13px; }
        .pp-kv dt { color: #6b7280; }
        .pp-kv dd { margin: 0; word-break: break-word; }
        .td-events { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border: 1px solid #e5e7eb; }
        .td-events th { text-align: left; background: #f3f4f6; padding: 6px 8px; font-weight: 600; color: #6b7280; }
        .td-events td { padding: 6px 8px; border-top: 1px solid #f3f4f6; vertical-align: top; word-break: break-word; }
        .td-id { font-weight: 600; color: #111827; }
        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }
        @media (max-width: 1100px) { .pp-cards { grid-template-columns: 1fr; } }
        @media (max-width: 900px) {
            .pp-listhead { display: none; }
            .td-grid { grid-template-columns: 1fr auto; }
            .td-grid .td-hide-sm { display: none; }
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
            <?php $currentPage = 'shadow_saas_itdr'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'itdr'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-itdr.title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-itdr.intro')); ?></p>
                        <?php if ($tdSynced): ?><p class="td-sub"><?php echo e(t('shadow-saas-posture.last_synced', date('M j, Y g:i A', strtotime($tdSynced)))); ?></p><?php endif; ?>
                    </div>
                    <?php if ($canManage && $gripEnabled): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="refresh_threats">
                        <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.refresh')); ?></button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-itdr.grip_disabled')); ?></div>
                <?php elseif (!$tdThreats): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-itdr.no_threats')); ?></div>
                <?php else: ?>
                <div class="pp-cards">
                    <div class="pp-card" id="td-sev">
                        <h3><?php echo e(t('shadow-saas-itdr.by_severity')); ?></h3>
                        <div class="td-big"><?php echo count($tdThreats); ?><small><?php echo e(t('shadow-saas-itdr.threats')); ?></small></div>
                        <div style="margin-top:12px;">
                        <?php foreach ($tdBySev as $sv => $n): ?>
                            <div class="td-rowstat"><a href="<?php echo e(tdQuery(['severity' => $sv])); ?>"><span class="pp-pill sev-<?php echo e($sv); ?>"><?php echo e($sv); ?></span></a><span><?php echo (int)$n; ?></span></div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pp-card" id="td-status">
                        <h3><?php echo e(t('shadow-saas-itdr.by_status')); ?></h3>
                        <div class="td-big"><?php echo count($tdThreats); ?><small><?php echo e(t('shadow-saas-itdr.threats')); ?></small></div>
                        <div style="margin-top:12px;">
                        <?php foreach ($tdByStatus as $st => $n): ?>
                            <div class="td-rowstat"><a href="<?php echo e(tdQuery(['status' => $st])); ?>"><span class="pp-pill td-status <?php echo e($st); ?>"><?php echo e($st); ?></span></a><span><?php echo (int)$n; ?> (<?php echo e(number_format(100 * $n / count($tdThreats), 1)); ?>%)</span></div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pp-card" id="td-identities">
                        <h3><?php echo e(t('shadow-saas-itdr.top_identities')); ?></h3>
                        <?php if (!$tdIdent): ?><div class="pp-muted"><?php echo e(t('shadow-saas-itdr.no_identities')); ?></div><?php else: ?>
                        <table class="td-ident">
                            <thead><tr><th><?php echo e(t('shadow-saas-itdr.col_identity')); ?></th><th><?php echo e(t('shadow-saas-itdr.threats')); ?></th><th title="<?php echo e(t('shadow-saas-itdr.accounts_title')); ?>"><?php echo e(t('shadow-saas-itdr.col_accounts')); ?></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($tdIdent, 0, 8, true) as $id): ?>
                                <tr>
                                    <td><a href="<?php echo e(tdQuery(['q' => $id['mail'] !== '' ? $id['mail'] : $id['name']])); ?>" style="color:#111827;text-decoration:none;font-weight:500;"><?php echo e($id['name'] !== '' ? $id['name'] : $id['mail']); ?></a>
                                        <?php if ($id['name'] !== '' && $id['mail'] !== ''): ?><div class="td-sub"><?php echo e($id['mail']); ?></div><?php endif; ?></td>
                                    <td><?php echo (int)$id['count']; ?> <span class="pp-pill sev-<?php echo e($id['worst']); ?>"><?php echo e($id['worst']); ?></span></td>
                                    <td><?php echo isset($id['accounts']) && $id['accounts'] !== null ? (int)$id['accounts'] : '<span class="pp-muted">&mdash;</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="GET" class="pp-filters">
                    <select name="severity" aria-label="<?php echo e(t('shadow-saas-itdr.col_severity')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-posture.any_severity')); ?></option>
                        <?php foreach (array_keys($tdBySev) as $sv): ?><option value="<?php echo e($sv); ?>" <?php echo $fSev === $sv ? 'selected' : ''; ?>><?php echo e($sv); ?></option><?php endforeach; ?>
                    </select>
                    <select name="status" aria-label="<?php echo e(t('shadow-saas-itdr.col_status')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-itdr.any_status')); ?></option>
                        <?php foreach (array_keys($tdByStatus) as $st): ?><option value="<?php echo e($st); ?>" <?php echo $fStatus === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option><?php endforeach; ?>
                    </select>
                    <select name="category" aria-label="<?php echo e(t('shadow-saas-itdr.col_category')); ?>">
                        <option value=""><?php echo e(t('shadow-saas-itdr.any_category')); ?></option>
                        <?php foreach ($tdCatNames as $cn): ?><option value="<?php echo e($cn); ?>" <?php echo $fCat === $cn ? 'selected' : ''; ?>><?php echo e($cn); ?></option><?php endforeach; ?>
                    </select>
                    <input type="search" name="q" value="<?php echo e($fQ); ?>" placeholder="<?php echo e(t('shadow-saas-itdr.search_placeholder')); ?>">
                    <button type="submit" class="pp-btn"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                    <?php if ($fSev !== '' || $fStatus !== '' || $fCat !== '' || $fQ !== ''): ?><a class="pp-chip" href="shadow-saas-itdr.php"><?php echo e(t('shadow-saas-posture.clear')); ?></a><?php endif; ?>
                </form>

                <?php if (!$tdList): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-itdr.no_matches')); ?></div>
                <?php else: ?>
                <div class="pp-list" id="td-list">
                    <div class="pp-listhead td-grid">
                        <span><?php echo e(t('shadow-saas-itdr.col_id')); ?></span>
                        <span><?php echo e(t('shadow-saas-itdr.col_severity')); ?></span>
                        <span><?php echo e(t('shadow-saas-itdr.col_threat')); ?></span>
                        <span class="td-hide-sm"><?php echo e(t('shadow-saas-itdr.col_identity')); ?></span>
                        <span class="td-hide-sm"><?php echo e(t('shadow-saas-itdr.col_entity')); ?></span>
                        <span class="td-hide-sm"><?php echo e(t('shadow-saas-itdr.col_detected')); ?></span>
                        <span><?php echo e(t('shadow-saas-itdr.col_status')); ?></span>
                    </div>
                    <?php foreach ($tdList as $t):
                        $impacts = json_decode((string)($t['potential_impacts'] ?? '[]'), true);
                        $mit = json_decode((string)($t['mitigation'] ?? ''), true);
                        $ufd = json_decode((string)($t['unique_fields_data'] ?? ''), true);
                        $logs = json_decode((string)($t['event_logs'] ?? ''), true);
                        $st = strtolower((string)($t['status'] ?? ''));
                    ?>
                    <details class="pp-row" data-alert="<?php echo e($t['alert_id']); ?>">
                        <summary class="td-grid">
                            <span class="td-id"><?php echo e($t['alert_id']); ?></span>
                            <span><span class="pp-pill sev-<?php echo e($t['sev']); ?>"><?php echo e($t['sev']); ?></span></span>
                            <span><?php echo e($t['alert_name'] ?: ($t['category'] ?: $t['alert_type'])); ?><?php if (!empty($t['category']) && !empty($t['alert_name'])): ?><div class="td-sub"><?php echo e($t['category']); ?></div><?php endif; ?></span>
                            <span class="td-hide-sm"><?php echo e($t['related_entity_name'] ?: ''); ?><?php if (!empty($t['related_identity_mail'])): ?><div class="td-sub"><?php echo e($t['related_identity_mail']); ?></div><?php endif; ?></span>
                            <span class="td-hide-sm td-sub"><?php echo e(ucwords(str_replace('_', ' ', (string)($t['related_entity_type'] ?? '')))); ?></span>
                            <span class="td-hide-sm td-sub"><?php echo tdDateTime($t['created_at_grip'] ?? null); ?></span>
                            <span><span class="pp-pill td-status <?php echo e($st); ?>"><?php echo e($st); ?></span></span>
                        </summary>
                        <div class="pp-body">
                            <?php if (!empty($t['description'])): ?><h4><?php echo e(t('shadow-saas-itdr.description')); ?></h4><div><?php echo tdPlain($t['description']); ?></div><?php endif; ?>
                            <?php if (is_array($impacts) && $impacts): ?>
                                <h4><?php echo e(t('shadow-saas-itdr.impacts')); ?></h4>
                                <ul><?php foreach ($impacts as $im): ?><li><?php echo e(tdScalar($im)); ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                            <?php if (is_array($mit) && (!empty($mit['description']) || !empty($mit['steps']))): ?>
                                <h4><?php echo e(t('shadow-saas-posture.mitigation')); ?></h4>
                                <?php if (!empty($mit['description'])): ?><div><?php echo tdPlain($mit['description']); ?></div><?php endif; ?>
                                <?php $ms = $mit['steps'] ?? null; if (is_array($ms)):
                                    $steps = isset($ms['steps']) && is_array($ms['steps']) ? $ms['steps'] : (array_is_list($ms) ? $ms : []); ?>
                                    <?php if (!empty($ms['header'])): ?><p style="font-weight:600;margin:6px 0 2px;"><?php echo tdPlain((string)$ms['header']); ?></p><?php endif; ?>
                                    <?php if ($steps): ?><ol><?php foreach ($steps as $s1): ?><li><?php echo tdPlain(tdScalar($s1)); ?></li><?php endforeach; ?></ol><?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (is_array($ufd) && $ufd): ?>
                                <h4><?php echo e(t('shadow-saas-itdr.details')); ?></h4>
                                <dl class="pp-kv"><?php foreach (array_slice($ufd, 0, 40, true) as $k => $v): ?><dt><?php echo e((string)$k); ?></dt><dd><?php echo e(tdScalar($v)); ?></dd><?php endforeach; ?></dl>
                            <?php endif; ?>
                            <?php if (is_array($logs) && $logs):
                                $cols = [];
                                foreach (array_slice($logs, 0, 50) as $lg) { if (is_array($lg)) foreach (array_keys($lg) as $ck) $cols[$ck] = true; }
                                $cols = array_slice(array_keys($cols), 0, 8); ?>
                                <h4><?php echo e(t('shadow-saas-itdr.events')); ?></h4>
                                <div style="overflow-x:auto;"><table class="td-events">
                                    <thead><tr><?php foreach ($cols as $ck): ?><th><?php echo e((string)$ck); ?></th><?php endforeach; ?></tr></thead>
                                    <tbody><?php foreach (array_slice($logs, 0, 50) as $lg): if (!is_array($lg)) continue; ?><tr><?php foreach ($cols as $ck): ?><td><?php echo e(tdScalar($lg[$ck] ?? '')); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                                </table></div>
                            <?php endif; ?>
                            <h4><?php echo e(t('shadow-saas-itdr.source')); ?></h4>
                            <div><?php echo e($t['source'] ?: ($t['alert_type'] ?? '')); ?><?php if (!empty($t['updated_at_grip'])): ?> <span class="pp-muted">&middot; <?php echo e(t('shadow-saas-itdr.updated', date('M j, Y H:i', strtotime($t['updated_at_grip'])))); ?></span><?php endif; ?></div>
                            <?php if (!empty($t['alert_url'])): ?><p style="margin:14px 0 0;"><a class="pp-btn" href="<?php echo e($t['alert_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(t('shadow-saas-itdr.open_in_grip')); ?></a></p><?php endif; ?>
                            <h4><?php echo e(t('shadow-saas-waiver.col_waiver')); ?></h4>
                            <?php if ($t['waiver']): $tw = $t['waiver']; ?>
                                <div class="td-waiver">
                                    <span class="pp-pill td-status waived"><?php echo e(t('shadow-saas-posture.status_waived')); ?></span>
                                    <span class="pp-muted"><?php echo e(t('shadow-saas-waiver.grip_status', (string)($t['grip_status'] ?? ''))); ?></span>
                                    <div class="pp-waiver-note"><?php echo e($tw['justification']); ?></div>
                                    <div class="td-sub"><?php echo e(t('shadow-saas-waiver.by', (string)($tw['waived_by_name'] ?? '-'), date('M j, Y', strtotime((string)$tw['waived_at'])))); ?><?php if (!empty($tw['expires_at'])): ?> &middot; <?php echo e(t('shadow-saas-waiver.until', date('M j, Y', strtotime((string)$tw['expires_at'])))); ?><?php endif; ?></div>
                                    <?php if ($canManage): ?>
                                    <form method="POST" class="td-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="action" value="revoke_waiver">
                                        <input type="hidden" name="waiver_id" value="<?php echo (int)$tw['id']; ?>">
                                        <button type="submit" class="pp-chip td-revoke" data-confirm="<?php echo e(t('shadow-saas-waiver.revoke_confirm')); ?>"><?php echo e(t('shadow-saas-waiver.revoke')); ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($canManage): ?>
                                <form method="POST" class="pp-waive td-waive-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="action" value="waive_threat">
                                    <input type="hidden" name="alert_id" value="<?php echo e($t['alert_id']); ?>">
                                    <label for="tdJ<?php echo e(md5($t['alert_id'])); ?>"><?php echo e(t('shadow-saas-waiver.justification_label')); ?></label>
                                    <textarea id="tdJ<?php echo e(md5($t['alert_id'])); ?>" name="justification" rows="2" minlength="<?php echo (int)GripService::WAIVER_JUSTIFICATION_MIN; ?>" maxlength="<?php echo (int)GripService::WAIVER_JUSTIFICATION_MAX; ?>" required placeholder="<?php echo e(t('shadow-saas-waiver.justification_placeholder')); ?>"></textarea>
                                    <div class="pp-waive-row">
                                        <label><?php echo e(t('shadow-saas-waiver.expires_label')); ?> <input type="date" name="expires_at" value="<?php echo e(date('Y-m-d', strtotime('+1 year'))); ?>" min="<?php echo e(date('Y-m-d')); ?>" max="<?php echo e(date('Y-m-d', strtotime('+3 years'))); ?>"></label>
                                        <button type="submit" class="pp-btn" data-confirm="<?php echo e(t('shadow-saas-waiver.confirm_threat')); ?>"><?php echo e(t('shadow-saas-waiver.waive_threat')); ?></button>
                                    </div>
                                </form>
                            <?php else: ?><div class="pp-muted"><?php echo e(t('shadow-saas-waiver.not_waived')); ?></div><?php endif; ?>
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
