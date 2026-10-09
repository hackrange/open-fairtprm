<?php
/**
 * Shadow SaaS - Posture - Compliance Posture.
 *
 * Grip's SSPM posture checks rolled up by the compliance frameworks they map to
 * (NIST CSF, NIST 800-53, ISO 27001, SOC 2, CIS, HIPAA, PCI, ...). A control is
 * failing when any mapped check fails, passing when its evaluated checks all
 * pass, and not evaluated when none of its checks could be evaluated. The
 * framework score is passing controls / (passing + failing). Opening a
 * framework lists its controls, failing first, with the checks behind each.
 *
 * Read-only, from shadow_saas_grip_posture_checks (refreshed by the Grip sync).
 * Access: administrator / cyber_tprm / auditor.
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

/** "NIST|CSF|1.1" -> "NIST CSF 1.1" */
function cpLabel(string $fw): string { return trim(str_replace('|', ' ', $fw)); }

$cpChecks = [];
if ($gripEnabled) {
    try {
        foreach ($db->fetchAll('SELECT uuid, posture_check_id, description, security_domain, severity, status, tenant_name, compliance, risk_description, mitigation_steps FROM shadow_saas_grip_posture_checks') as $r) {
            $cpChecks[$r['uuid']] = $r;
        }
    } catch (Throwable $e) {
        error_log('Compliance posture load failed: ' . $e->getMessage());
        $error = t('shadow-saas-posture.load_failed');
    }
}

// framework => control => ['checks' => [uuid...], 'status' => Fail|Pass|NA]
$cpFw = [];
foreach ($cpChecks as $uuid => $c) {
    $comp = json_decode((string)($c['compliance'] ?? '[]'), true);
    if (!is_array($comp)) continue;
    foreach ($comp as $m) {
        if (!is_array($m) || empty($m['framework'])) continue;
        $fw = (string)$m['framework'];
        $cp = (string)($m['controlPath'] ?? '');
        $ctl = str_starts_with($cp, $fw . '|') ? substr($cp, strlen($fw) + 1) : ($cp !== '' ? $cp : '?');
        $cpFw[$fw][$ctl]['checks'][$uuid] = true;
    }
}
$cpSummary = [];
foreach ($cpFw as $fw => &$controls) {
    $s = ['controls' => 0, 'fail' => 0, 'pass' => 0, 'na' => 0, 'checks' => []];
    foreach ($controls as $ctl => &$info) {
        $st = 'NA';
        foreach (array_keys($info['checks']) as $u) {
            $cs = $cpChecks[$u]['status'] ?? '';
            if ($cs === 'Fail') { $st = 'Fail'; break; }
            if ($cs === 'Pass') $st = 'Pass';
        }
        $info['status'] = $st;
        $s['controls']++;
        $s[$st === 'Fail' ? 'fail' : ($st === 'Pass' ? 'pass' : 'na')]++;
        foreach (array_keys($info['checks']) as $u) $s['checks'][$u] = true;
    }
    unset($info);
    $s['score'] = ($s['pass'] + $s['fail']) > 0 ? (int)round(100 * $s['pass'] / ($s['pass'] + $s['fail'])) : null;
    $s['checks'] = count($s['checks']);
    $cpSummary[$fw] = $s;
}
unset($controls);
uasort($cpSummary, fn($a, $b) => [$b['fail'], $a['score'] ?? 101] <=> [$a['fail'], $b['score'] ?? 101]);

// ---------------------------------------------------------------------------
// Add a failing control to the GRC risk register.
// Same write rule as grc-risks.php: administrators and cyber_grc only.
// ---------------------------------------------------------------------------
$canRisk = $isAdmin || $acl->hasGroup('cyber_grc');
const CP_RISK_PREFIX = 'SaaS posture gap: ';
/** Grip framework id => FairTPRM grc_frameworks.code values it corresponds to. */
const CP_GRC_CODES = [
    'SOC 2|TSC|2017' => ['SOC2'], 'ISO|27001|2022' => ['ISO27001'], 'NIST|CSF|2.0' => ['NIST-CSF', 'CSF'],
    'CIS|Controls|8' => ['CIS'], 'PCI' => ['PCI-DSS', 'PCI'], 'HIPAA/HITECH' => ['HIPAA'], 'CMMC' => ['CMMC'], 'SOX' => ['SOX'],
];
function cpRiskTitle(string $fw, string $ctl): string { return CP_RISK_PREFIX . cpLabel($fw) . ' ' . str_replace('|', ' ', $ctl); }
/** Plain text from Grip text that may carry HTML. */
function cpPlain(?string $s): string
{
    $s = preg_replace('#<\s*(br|/p|/li|/div)\s*/?\s*>#i', "\n", (string)$s);
    return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_risk') {
    $pfw  = (string)($_POST['framework'] ?? '');
    $pctl = (string)($_POST['control'] ?? '');
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas-posture.invalid_request');
    } elseif (!$canRisk) {
        http_response_code(403);
        $error = t('shadow-saas-cp.risk_forbidden');
    } elseif (!isset($cpFw[$pfw][$pctl]) || $cpFw[$pfw][$pctl]['status'] !== 'Fail') {
        // Re-validated server side: only a control that is failing right now.
        $error = t('shadow-saas-cp.risk_not_failing');
    } else {
        $title = cpRiskTitle($pfw, $pctl);
        $dup = $db->fetchOne("SELECT id, risk_ref FROM grc_risk_register WHERE title = :t AND status != 'closed' LIMIT 1", [':t' => $title]);
        if ($dup) {
            $error = t('shadow-saas-cp.risk_exists', $dup['risk_ref']);
        } else {
            $sevRank = ['High' => 3, 'Medium' => 2, 'Low' => 1];
            $worst = 0;
            $desc = [];
            $plan = [];
            foreach (array_keys($cpFw[$pfw][$pctl]['checks']) as $u) {
                $c = $cpChecks[$u];
                if (($c['status'] ?? '') !== 'Fail') continue;
                $worst = max($worst, $sevRank[$c['severity'] ?? ''] ?? 1);
                $desc[] = '- ' . $c['posture_check_id'] . ' (' . ($c['severity'] ?? '') . ', ' . ($c['tenant_name'] ?? '') . '): ' . $c['description']
                        . (!empty($c['risk_description']) ? "\n  " . cpPlain($c['risk_description']) : '');
                $m = json_decode((string)($c['mitigation_steps'] ?? ''), true);
                $mt = is_array($m) ? cpPlain(implode("\n", array_map(fn($x) => is_array($x) ? implode("\n", array_map('strval', array_filter($x, 'is_scalar'))) : (string)$x, $m['steps'] ?? $m))) : cpPlain($c['mitigation_steps'] ?? '');
                if ($mt !== '') $plan[] = $c['posture_check_id'] . ":\n" . $mt;
            }
            $likelihood = [3 => 'likely', 2 => 'possible', 1 => 'unlikely'][$worst] ?? 'possible';
            $impact     = [3 => 'major', 2 => 'moderate', 1 => 'minor'][$worst] ?? 'moderate';
            $score = ['likely' => 4, 'possible' => 3, 'unlikely' => 2][$likelihood] * ['major' => 4, 'moderate' => 3, 'minor' => 2][$impact];

            // Link the FairTPRM requirement and any internal controls mapped to it.
            $controlIds = [];
            $reqNote = '';
            $codes = CP_GRC_CODES[$pfw] ?? [];
            $ref = str_replace('|', ' ', $pctl);
            if ($codes) {
                $ph = [];
                $params = [':ref' => $ref];
                foreach ($codes as $i => $cd) { $ph[] = ":c{$i}"; $params[":c{$i}"] = $cd; }
                $reqs = $db->fetchAll(
                    'SELECT r.id, r.requirement_ref, f.name FROM grc_framework_requirements r JOIN grc_frameworks f ON f.id = r.framework_id
                      WHERE f.code IN (' . implode(',', $ph) . ') AND r.requirement_ref = :ref',
                    $params
                );
                if ($reqs) {
                    $reqNote = "\n\n" . t('shadow-saas-cp.risk_requirements') . ' ' . implode(', ', array_map(fn($r) => $r['name'] . ' ' . $r['requirement_ref'], $reqs));
                    $rph = [];
                    $rparams = [];
                    foreach ($reqs as $i => $r) { $rph[] = ":r{$i}"; $rparams[":r{$i}"] = (int)$r['id']; }
                    foreach ($db->fetchAll('SELECT DISTINCT control_id FROM grc_control_requirement_map WHERE requirement_id IN (' . implode(',', $rph) . ')', $rparams) as $cm) {
                        $controlIds[] = (int)$cm['control_id'];
                    }
                }
            }

            $last = $db->fetchOne("SELECT risk_ref FROM grc_risk_register WHERE risk_ref LIKE 'RSK-%' ORDER BY id DESC LIMIT 1");
            $riskRef = $last ? 'RSK-' . str_pad((int)substr($last['risk_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'RSK-001';
            $riskId = $db->insert('grc_risk_register', [
                'risk_ref'            => $riskRef,
                'title'               => mb_substr($title, 0, 500),
                'description'         => t('shadow-saas-cp.risk_description', cpLabel($pfw), $ref) . "\n\n" . implode("\n", $desc) . $reqNote,
                'risk_category'       => 'compliance',
                'likelihood'          => $likelihood,
                'impact'              => $impact,
                'inherent_risk_score' => $score,
                'residual_risk_score' => $score,
                'risk_treatment'      => 'mitigate',
                'treatment_plan'      => $plan ? implode("\n\n", $plan) : null,
                'control_ids'         => $controlIds ? implode(',', array_unique($controlIds)) : null,
                'status'              => 'identified',
                'created_by'          => (int)$user['id'],
            ]);
            $auth->audit($user['id'], 'shadow_saas_posture_risk', 'grc_risk_register', (int)$riskId, [
                'new' => ['risk_ref' => $riskRef, 'framework' => $pfw, 'control' => $pctl],
            ]);
            $success = t('shadow-saas-cp.risk_created', $riskRef);
        }
    }
}
$csrfToken = $security->generateCSRFToken();

// Open risks already raised from this page, keyed by title.
$cpRisks = [];
try {
    foreach ($db->fetchAll("SELECT id, risk_ref, title FROM grc_risk_register WHERE title LIKE :p AND status != 'closed'", [':p' => CP_RISK_PREFIX . '%']) as $r) {
        $cpRisks[$r['title']] = $r;
    }
} catch (Throwable $e) {
    error_log('Compliance posture risk lookup failed: ' . $e->getMessage());
}

$cpSel = (string)($_GET['framework'] ?? '');
if ($cpSel !== '' && !isset($cpFw[$cpSel])) $cpSel = '';
$cpRows = [];
if ($cpSel !== '') {
    $order = ['Fail' => 0, 'Pass' => 1, 'NA' => 2];
    foreach ($cpFw[$cpSel] as $ctl => $info) $cpRows[] = ['control' => $ctl] + $info;
    usort($cpRows, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']] ?: strnatcasecmp($a['control'], $b['control']));
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-cp.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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

        .cp-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
        .cp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; text-decoration: none; color: inherit; display: block; }
        .cp-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .cp-card h3 { font-size: 15px; margin: 0 0 8px; color: #111827; }
        .cp-score { font-size: 28px; font-weight: 700; color: #111827; }
        .cp-score small { font-size: 13px; font-weight: 500; color: #6b7280; margin-left: 6px; }
        .cp-bar { display: flex; height: 7px; border-radius: 4px; overflow: hidden; background: #e5e7eb; margin: 10px 0 8px; }
        .cp-bar .p { background: #16a34a; } .cp-bar .f { background: #dc2626; }
        .cp-meta { display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; color: #6b7280; }
        .cp-meta b { color: #374151; }
        .st-Fail { background: #fee2e2; color: #991b1b; } .st-Pass { background: #dcfce7; color: #166534; } .st-NA { background: #f3f4f6; color: #4b5563; }
        .sev-High { background: #fee2e2; color: #991b1b; } .sev-Medium { background: #ffedd5; color: #9a3412; } .sev-Low { background: #e0f2fe; color: #075985; }
        .cp-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .cp-table th { background: #f9fafb; padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e5e7eb; }
        .cp-table td { padding: 10px 12px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .cp-check { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; padding: 2px 0; }
        .cp-check a { color: var(--theme-button-color); text-decoration: none; font-weight: 500; white-space: nowrap; }
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
            <?php $currentPage = 'shadow_saas_compliance'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'compliance'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

                <?php if ($cpSel === ''): ?>
                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-cp.title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-cp.intro')); ?></p>
                    </div>
                </div>
                <?php if (!$gripEnabled): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-posture.grip_disabled')); ?></div>
                <?php elseif (!$cpSummary): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-cp.no_data')); ?></div>
                <?php else: ?>
                <div class="cp-cards" id="cp-cards">
                    <?php foreach ($cpSummary as $fw => $s): $ev = max(1, $s['pass'] + $s['fail']); ?>
                    <a class="cp-card" href="?<?php echo e(http_build_query(['framework' => $fw])); ?>" data-framework="<?php echo e($fw); ?>">
                        <h3><?php echo e(cpLabel($fw)); ?></h3>
                        <div class="cp-score"><?php echo $s['score'] !== null ? (int)$s['score'] . '%' : '&mdash;'; ?><small><?php echo e(t('shadow-saas-cp.controls_passing', $s['pass'], $s['pass'] + $s['fail'])); ?></small></div>
                        <div class="cp-bar"><span class="p" style="width:<?php echo round(100 * $s['pass'] / $ev, 2); ?>%"></span><span class="f" style="width:<?php echo round(100 * $s['fail'] / $ev, 2); ?>%"></span></div>
                        <div class="cp-meta"><span><b><?php echo (int)$s['fail']; ?></b> <?php echo e(t('shadow-saas-cp.failing')); ?></span><span><b><?php echo (int)$s['pass']; ?></b> <?php echo e(t('shadow-saas-cp.passing')); ?></span><span><b><?php echo (int)$s['na']; ?></b> <?php echo e(t('shadow-saas-posture.not_evaluated')); ?></span><span><b><?php echo (int)$s['checks']; ?></b> <?php echo e(t('shadow-saas-cp.checks')); ?></span></div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php else: $s = $cpSummary[$cpSel]; ?>
                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(cpLabel($cpSel)); ?></h1>
                        <p><?php echo e(t('shadow-saas-cp.framework_summary', $s['score'] !== null ? $s['score'] . '%' : '-', $s['fail'], $s['pass'], $s['na'])); ?></p>
                    </div>
                    <a class="pp-btn" style="background:#6b7280;" href="shadow-saas-compliance.php">&larr; <?php echo e(t('shadow-saas-cp.all_frameworks')); ?></a>
                </div>
                <div style="overflow-x:auto;">
                <table class="cp-table" id="cp-controls">
                    <thead><tr><th><?php echo e(t('shadow-saas-cp.col_control')); ?></th><th><?php echo e(t('shadow-saas-posture.col_status')); ?></th><th><?php echo e(t('shadow-saas-cp.col_checks')); ?></th><th><?php echo e(t('shadow-saas-cp.col_risk')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($cpRows as $row): ?>
                        <tr data-control="<?php echo e($row['control']); ?>">
                            <td style="white-space:nowrap;font-weight:600;"><?php echo e(str_replace('|', ' ', $row['control'])); ?></td>
                            <td><span class="pp-pill st-<?php echo e($row['status']); ?>"><?php echo e($row['status'] === 'NA' ? t('shadow-saas-posture.not_evaluated') : t('shadow-saas-posture.status_' . strtolower($row['status']))); ?></span></td>
                            <td>
                            <?php foreach (array_keys($row['checks']) as $u): $c = $cpChecks[$u]; ?>
                                <div class="cp-check">
                                    <a href="shadow-saas-posture.php?<?php echo e(http_build_query(['status' => 'all', 'q' => (string)$c['posture_check_id']])); ?>"><?php echo e($c['posture_check_id']); ?></a>
                                    <span class="pp-pill st-<?php echo e(in_array($c['status'], ['Fail', 'Pass'], true) ? $c['status'] : 'NA'); ?>"><?php echo e($c['status']); ?></span>
                                    <?php if (!empty($c['severity'])): ?><span class="pp-pill sev-<?php echo e($c['severity']); ?>"><?php echo e($c['severity']); ?></span><?php endif; ?>
                                    <span class="ai-sub"><?php echo e($c['description']); ?></span>
                                </div>
                            <?php endforeach; ?>
                            </td>
                            <td class="cp-risk" style="white-space:nowrap;">
                            <?php $rk = $cpRisks[cpRiskTitle($cpSel, $row['control'])] ?? null; ?>
                            <?php if ($rk): ?>
                                <a class="pp-pill st-NA" href="grc-risks.php?view=<?php echo (int)$rk['id']; ?>"><?php echo e($rk['risk_ref']); ?></a>
                            <?php elseif ($row['status'] === 'Fail' && $canRisk): ?>
                                <form method="POST" action="?<?php echo e(http_build_query(['framework' => $cpSel])); ?>" class="cp-risk-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_risk">
                                    <input type="hidden" name="framework" value="<?php echo e($cpSel); ?>">
                                    <input type="hidden" name="control" value="<?php echo e($row['control']); ?>">
                                    <button type="submit" class="pp-btn" style="padding:5px 10px;font-size:12px;"><?php echo e(t('shadow-saas-cp.add_risk')); ?></button>
                                </form>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
