<?php
/**
 * Shadow SaaS - Requests.
 *
 * The FairTPRM request method (Admin > Shadow SaaS): ask an app's users how
 * they use it. Each request emails one person a one-time link with the choices
 * set on Admin > Email > Shadow SaaS (e.g. Personal Use opens a User Request
 * form, Business Use opens vendor onboarding). Submitted forms wait here for a
 * decision; a denial can later be reversed (approved) with a reason, and an
 * approved request can be onboarded as a vendor from here. The attestation (name, IP address, user agent, time) and the
 * answers are shown on the request so a requester can be held to them.
 *
 * Views: list (?filter=review|waiting|approved|denied|closed|all, ?q=), one request (?id=),
 * and the send form (?new=1, optionally &ss=<shadow_saas id>).
 *
 * Access: administrator / cyber_tprm / auditor. Sending, resending,
 * cancelling, deciding and onboarding are administrator / cyber_tprm only.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();
require_once __DIR__ . '/includes/classes/ShadowSaasRequestService.php';
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

$svc         = new ShadowSaasRequestService($db);
$gripEnabled = (new GripService())->isEnabled();
$usesFairTprm = ShadowSaasRequestService::usesFairTprm();
$error   = '';
$success = '';

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
$srAction = (string)($_POST['action'] ?? '');
$srForm   = null;   // re-filled send form after an error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas-requests.invalid_request');
    } elseif (!$canManage) {
        http_response_code(403);
        die(e(t('shadow-saas.access_denied')));
    } elseif ($srAction === 'send_request') {
        $srForm = [
            'ss'       => (int)($_POST['shadow_saas_id'] ?? 0),
            'app_name' => trim((string)($_POST['app_name'] ?? '')),
            'domain'   => trim((string)($_POST['app_domain'] ?? '')),
            'emails'   => (string)($_POST['recipients'] ?? ''),
            'message'  => (string)($_POST['message'] ?? ''),
        ];
        $parsed = ShadowSaasRequestService::parseEmails($srForm['emails']);
        $app = null;
        if ($srForm['ss'] > 0) {
            $app = $svc->appFromShadowRow($srForm['ss']);
        } elseif ($srForm['app_name'] !== '') {
            // A typed name that matches a Shadow SaaS app links the request to it.
            $match = $db->fetchOne("SELECT id FROM shadow_saas WHERE vendor_name = :n ORDER BY id LIMIT 1", [':n' => $srForm['app_name']]);
            $app = $match ? $svc->appFromShadowRow((int)$match['id'])
                : ['shadow_saas_id' => null, 'grip_saas_id' => null, 'app_name' => mb_substr($srForm['app_name'], 0, 500),
                   'app_domain' => $srForm['domain'] !== '' ? shadowSaasRequestDomain($srForm['domain']) : null];
        }
        if (!$usesFairTprm) {
            $error = t('shadow-saas-requests.method_is_grip');
        } elseif (!$app) {
            $error = t('shadow-saas-requests.app_required');
        } elseif ($parsed['invalid']) {
            $error = t('shadow-saas-requests.invalid_recipients', implode(', ', array_slice($parsed['invalid'], 0, 5)));
        } elseif (!$parsed['valid']) {
            $error = t('shadow-saas-requests.recipients_required');
        } elseif (count($parsed['valid']) > ShadowSaasRequestService::MAX_RECIPIENTS) {
            $error = t('shadow-saas-requests.too_many', ShadowSaasRequestService::MAX_RECIPIENTS);
        } else {
            $r = $svc->createRequests($app, $parsed['valid'], $srForm['message'], (int)$user['id']);
            $auth->audit($user['id'], 'shadow_saas_request_send', 'shadow_saas_requests', null, [
                'new' => ['app' => $app['app_name'], 'shadow_saas_id' => $app['shadow_saas_id'], 'recipients' => count($parsed['valid']),
                          'sent' => $r['sent'], 'failed' => $r['failed'], 'skipped' => $r['skipped'], 'error' => $r['error']],
            ]);
            if ($r['error'] === 'no_choices') {
                $error = t('shadow-saas-requests.no_choices');
            } elseif ($r['error'] !== null) {
                $error = t('shadow-saas-requests.send_failed');
            } else {
                $parts = [];
                if ($r['sent'])    $parts[] = t('shadow-saas-requests.sent_n', $r['sent']);
                if ($r['skipped']) $parts[] = t('shadow-saas-requests.skipped_n', $r['skipped']);
                if ($r['failed'])  $error = t('shadow-saas-requests.failed_n', $r['failed']);
                $success = implode(' ', $parts);
                $srForm = null;
            }
        }
    } elseif (in_array($srAction, ['resend', 'cancel', 'approve', 'deny', 'approve_onboard', 'onboard'], true)) {
        $rid = (int)($_POST['request_id'] ?? 0);
        $before = $svc->get($rid);
        if (!$before) {
            $error = t('shadow-saas-requests.not_found');
        } elseif ($srAction === 'resend') {
            if (!$usesFairTprm) {
                $error = t('shadow-saas-requests.method_is_grip');
            } elseif ($svc->resend($rid)) {
                $success = t('shadow-saas-requests.resent', $before['requester_email']);
            } else {
                $error = t('shadow-saas-requests.resend_failed');
            }
        } elseif ($srAction === 'onboard' || $srAction === 'approve_onboard') {
            $ok = $srAction === 'onboard' || $svc->decide($rid, 'approve', (string)($_POST['review_notes'] ?? ''), (int)$user['id']);
            $srOnboardedVid = $ok ? $svc->onboard($rid, (int)$user['id']) : null;
            if (!$ok) {
                $error = t('shadow-saas-requests.decide_failed');
            } elseif (!$srOnboardedVid) {
                $error = t('shadow-saas-requests.onboard_failed');
            }
        } elseif ($srAction === 'approve' && $before['status'] === 'denied' && trim((string)($_POST['review_notes'] ?? '')) === '') {
            $error = t('shadow-saas-requests.reverse_reason_required');
        } elseif ($svc->decide($rid, $srAction, (string)($_POST['review_notes'] ?? ''), (int)$user['id'])) {
            $success = t('shadow-saas-requests.decided_' . ($srAction === 'approve' && $before['status'] === 'denied' ? 'reverse' : $srAction));
        } else {
            $error = t('shadow-saas-requests.decide_failed');
        }
        if ($before) {
            $auth->audit($user['id'], 'shadow_saas_request_' . $srAction, 'shadow_saas_requests', $rid, [
                // The prior decision is kept here since a reversal overwrites it on the row.
                'old' => ['status' => $before['status'], 'reviewed_by' => $before['reviewed_by'], 'reviewed_at' => $before['reviewed_at'],
                          'notes' => $before['review_notes'] !== null ? mb_substr((string)$before['review_notes'], 0, 500) : null],
                'new' => ['ok' => $error === '', 'notes' => mb_substr((string)($_POST['review_notes'] ?? ''), 0, 500),
                          'vendor_onboarding_id' => $srOnboardedVid ?? null],
            ]);
        }
        // Onboarded: continue in the vendor's onboarding form.
        if (!empty($srOnboardedVid)) {
            header('Location: vendor-onboarding.php?id=' . (int)$srOnboardedVid);
            exit;
        }
        $_GET['id'] = $rid;
    }
}
$csrfToken = $security->generateCSRFToken();

/** Normalize a typed domain or URL to a bare host. */
function shadowSaasRequestDomain(string $v): ?string
{
    $v = strtolower(trim($v));
    if (preg_match('#^[a-z][a-z0-9+.-]*://#', $v)) $v = (string)parse_url($v, PHP_URL_HOST);
    $v = preg_replace('#[/?\#].*$#', '', $v);
    return preg_match('/^[a-z0-9.-]{1,255}$/', $v) ? $v : null;
}

function srDate(?string $d): string
{
    if (!$d) return '<span class="pp-muted">&mdash;</span>';
    $ts = strtotime($d);
    return $ts ? e(date('M j, Y g:i A', $ts)) : e($d);
}

function srStatusPill(array $r): string
{
    $s = ShadowSaasRequestService::displayStatus($r);
    return '<span class="pp-pill sr-' . e($s) . '">' . e(t('shadow-saas-requests.status_' . $s)) . '</span>';
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$srCounts = $svc->counts();
$view = 'list';
$srReq = null;
if (!empty($_GET['id'])) {
    $srReq = $svc->get((int)$_GET['id']);
    if ($srReq) { $view = 'detail'; } else { $error = $error ?: t('shadow-saas-requests.not_found'); }
} elseif (!empty($_GET['new']) || $srForm !== null) {
    $view = 'new';
}

$srFilter = (string)($_GET['filter'] ?? '');
if (!in_array($srFilter, ['review', 'waiting', 'approved', 'denied', 'closed', 'all'], true)) {
    $srFilter = $srCounts['awaiting_review'] > 0 ? 'review' : 'all';
}
$srSearch = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 128);
$srRows = $view === 'list' ? $svc->listRequests($srFilter, $srSearch) : [];

$srChoices = $svc->choices();
$srNewApp = null;
if ($view === 'new') {
    $ssId = $srForm['ss'] ?? (int)($_GET['ss'] ?? 0);
    if ($ssId > 0) $srNewApp = $db->fetchOne("SELECT id, vendor_name, vendor_domain, grip_id, number_of_users FROM shadow_saas WHERE id = :id", [':id' => $ssId]) ?: null;
}

$srAnswers = [];
$srAssessment = null;
$srVendor = null;
if ($view === 'detail') {
    if ($srReq['assessment_id']) {
        $srAnswers = $svc->answers((int)$srReq['assessment_id']);
        $srAssessment = $db->fetchOne("SELECT id, status, submitter_name, submitter_email, submitter_title, submitter_phone, submitter_ip_address, attested_at, completed_at FROM vendor_assessments WHERE id = :id", [':id' => (int)$srReq['assessment_id']]) ?: null;
    }
    if ($srReq['vendor_request_id']) {
        $srVendor = $db->fetchOne("SELECT id, vendor_name, status FROM vendor_onboarding_requests WHERE id = :id", [':id' => (int)$srReq['vendor_request_id']]) ?: null;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('shadow-saas-requests.title')); ?> - <?php echo e($theme['system_title'] ?? 'FairTPRM'); ?></title>
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
        .pp-btn.alt { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .pp-btn.danger { background: #dc2626; }
        .pp-btn.ok { background: #16a34a; }
        .pp-chip { font-size: 13px; padding: 5px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
        .pp-chip.on { background: var(--theme-button-color); color: #fff; border-color: var(--theme-button-color); font-weight: 600; }
        .pp-chip .n { display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 4px; border-radius: 9px; background: rgba(0,0,0,.08); font-size: 11px; text-align: center; }
        .pp-empty { text-align: center; padding: 50px 20px; color: #6b7280; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
        .pp-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .sr-sent, .sr-opened { background: #e0f2fe; color: #075985; }
        .sr-in_progress { background: #ede9fe; color: #5b21b6; }
        .sr-submitted { background: #fef3c7; color: #92400e; }
        .sr-approved { background: #dcfce7; color: #166534; }
        .sr-denied { background: #fee2e2; color: #991b1b; }
        .sr-cancelled, .sr-expired { background: #f3f4f6; color: #6b7280; }
        .sr-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .sr-chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .sr-search { display: flex; gap: 6px; }
        .sr-search input { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 220px; }
        .data-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .data-table th { background: #f9fafb; padding: 11px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .data-table td { padding: 11px 12px; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .data-table tr:hover td { background: #f9fafb; }
        .data-table tr.sr-row { cursor: pointer; }
        .sr-name { font-weight: 600; color: #111827; }
        .sr-sub { font-size: 12px; color: #6b7280; }
        .sr-link { color: var(--theme-button-color); text-decoration: none; }
        .sr-link:hover { text-decoration: underline; }
        .sr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 18px; }
        .sr-card h3 { margin: 0 0 14px; font-size: 16px; color: #111827; }
        .sr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px 24px; }
        .sr-grid label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 2px; }
        .sr-grid div > div { font-size: 14px; color: #111827; word-break: break-word; }
        .sr-answers dt { font-size: 13px; color: #6b7280; margin-top: 12px; }
        .sr-answers dd { margin: 3px 0 0; font-size: 14px; color: #111827; white-space: pre-wrap; word-break: break-word; }
        .sr-form label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin: 14px 0 5px; }
        .sr-form input[type=text], .sr-form textarea { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        .sr-form textarea.mono { font-family: monospace; }
        .sr-help { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .sr-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 14px; }
        .sr-choice-list { margin: 6px 0 0; padding-left: 18px; font-size: 13px; color: #374151; }
        .sr-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; }
        .footer-modern { background: var(--theme-footer-color, #1f2937); color: rgba(255,255,255,0.7); padding: 20px 30px; font-size: 13px; }
        .footer-modern-body { max-width: 1400px; margin: 0 auto; }
        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; max-width: 100%; min-width: 0; }
            .main-content { padding: 16px; }
            .sr-search input { min-width: 0; width: 100%; }
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
            <?php $currentPage = 'shadow_saas_requests'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <?php $ssTab = 'requests'; include __DIR__ . '/includes/partials/shadow-saas-tabs.php'; ?>

                <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<?php if ($view === 'detail'): $r = $srReq; $rs = ShadowSaasRequestService::displayStatus($r); ?>
                <div class="pp-head">
                    <div>
                        <a class="sr-link" href="shadow-saas-requests.php">&larr; <?php echo e(t('shadow-saas-requests.back')); ?></a>
                        <h1 class="page-title" style="margin:6px 0 0;"><?php echo e($r['app_name']); ?> <?php echo srStatusPill($r); ?></h1>
                        <p><?php echo e($r['requester_email']); ?><?php if ($r['app_domain']): ?> &middot; <?php echo e($r['app_domain']); ?><?php endif; ?></p>
                    </div>
                </div>

                <div class="sr-card" id="sr-overview">
                    <h3><?php echo e(t('shadow-saas-requests.overview')); ?></h3>
                    <div class="sr-grid">
                        <div><label><?php echo e(t('shadow-saas-requests.col_choice')); ?></label><div><?php echo $r['choice_label'] ? e($r['choice_label']) : '<span class="pp-muted">' . e(t('shadow-saas-requests.no_choice_yet')) . '</span>'; ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.sent_by')); ?></label><div><?php echo e($r['sent_by_name'] ?: '-'); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.col_sent')); ?></label><div><?php echo srDate($r['sent_at']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.email_status')); ?></label><div><?php echo e($r['email_status'] ? t('shadow-saas-requests.email_' . $r['email_status']) : '-'); ?><?php if ($r['email_error'] && $canManage): ?><div class="sr-sub"><?php echo e($r['email_error']); ?></div><?php endif; ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.opened')); ?></label><div><?php echo srDate($r['opened_at']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.chosen')); ?></label><div><?php echo srDate($r['chosen_at']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.col_submitted')); ?></label><div><?php echo srDate($r['submitted_at']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.expires')); ?></label><div><?php echo srDate($r['expires_at']); ?></div></div>
                    </div>
                    <?php if ($r['message']): ?><div class="sr-help" style="margin-top:12px;white-space:pre-wrap;"><?php echo e(t('shadow-saas-requests.message_sent')); ?> <?php echo e($r['message']); ?></div><?php endif; ?>
                    <?php if ($srVendor || $srAssessment): ?>
                    <div class="sr-actions">
                        <?php if ($srVendor): ?><a class="pp-btn alt" href="vendor-onboarding.php?id=<?php echo (int)$srVendor['id']; ?>"><?php echo e(t('shadow-saas-requests.open_vendor', $srVendor['status'])); ?></a><?php endif; ?>
                        <?php if ($srAssessment && $srAssessment['status'] === 'completed' && $r['choice_kind'] === 'business'): ?><a class="pp-btn alt" href="vendor-assessment-view.php?id=<?php echo (int)$srAssessment['id']; ?>"><?php echo e(t('shadow-saas-requests.open_form')); ?></a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($r['attested_at'] || $r['submitted_at']): ?>
                <div class="sr-card" id="sr-attestation">
                    <h3><?php echo e(t('shadow-saas-requests.attestation')); ?></h3>
                    <?php $attName = trim(($r['submitter_first_name'] ?? '') . ' ' . ($r['submitter_last_name'] ?? '')) ?: (string)($srAssessment['submitter_name'] ?? ''); ?>
                    <div class="sr-grid">
                        <?php if ($r['submitter_first_name'] !== null): ?>
                        <div><label><?php echo e(t('shadow-saas-requests.first_name')); ?></label><div data-field="first"><?php echo e($r['submitter_first_name']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.last_name')); ?></label><div data-field="last"><?php echo e($r['submitter_last_name']); ?></div></div>
                        <?php else: ?>
                        <div><label><?php echo e(t('shadow-saas-requests.name')); ?></label><div data-field="name"><?php echo e($attName ?: '-'); ?></div></div>
                        <?php endif; ?>
                        <div><label><?php echo e(t('shadow-saas-requests.email_attested')); ?></label><div><?php echo e($r['requester_email']); ?></div></div>
                        <div><label><?php echo e(t('shadow-saas-requests.attested_at')); ?></label><div><?php echo srDate($r['attested_at']); ?></div></div>
                        <?php if ($canManage): ?>
                        <div><label><?php echo e(t('shadow-saas-requests.ip')); ?></label><div data-field="ip"><?php echo e($r['submitter_ip'] ?: '-'); ?></div></div>
                        <?php if ($r['submitter_user_agent']): ?><div style="grid-column:1/-1;"><label><?php echo e(t('shadow-saas-requests.user_agent')); ?></label><div class="sr-sub"><?php echo e($r['submitter_user_agent']); ?></div></div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['attested_at']): ?><p class="sr-help" style="margin-top:12px;">&#10003; <?php echo e(t('shadow-saas-requests.attested_statement')); ?></p><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($srAnswers): ?>
                <div class="sr-card" id="sr-answers">
                    <h3><?php echo e(t('shadow-saas-requests.answers')); ?></h3>
                    <dl class="sr-answers" style="margin:0;">
                        <?php foreach ($srAnswers as $a): ?>
                        <dt><?php echo e($a['question']); ?></dt>
                        <dd><?php echo e($a['answer']); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
                <?php endif; ?>

                <?php if ($canManage && $r['status'] === 'approved' && !$srVendor): ?>
                <div class="sr-card" id="sr-onboard">
                    <h3><?php echo e(t('shadow-saas-requests.onboard_heading')); ?></h3>
                    <p class="sr-help" style="margin-top:0;"><?php echo e(t('shadow-saas-requests.onboard_help', $r['app_name'])); ?></p>
                    <form method="POST" action="shadow-saas-requests.php?id=<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="action" value="onboard">
                        <div class="sr-actions" style="margin-top:0;"><button type="submit" class="pp-btn ok" id="sr-onboard-btn"><?php echo e(t('shadow-saas-requests.onboard')); ?></button></div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($r['reviewed_at']): ?>
                <div class="sr-card" id="sr-decision">
                    <h3><?php echo e(t('shadow-saas-requests.decision')); ?></h3>
                    <p style="margin:0;font-size:14px;"><?php echo srStatusPill($r); ?> <?php echo e(t('shadow-saas-requests.decided_by', $r['reviewed_by_name'] ?: '-')); ?> &middot; <?php echo srDate($r['reviewed_at']); ?></p>
                    <?php if ($r['review_notes']): ?><p style="white-space:pre-wrap;font-size:14px;margin:10px 0 0;"><?php echo e($r['review_notes']); ?></p><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($canManage && (in_array($r['status'], ['submitted', 'denied'], true) || in_array($r['status'], ShadowSaasRequestService::OPEN_STATUSES, true))): ?>
                <div class="sr-card" id="sr-review">
                    <h3><?php echo e(t('shadow-saas-requests.' . ($r['status'] === 'submitted' ? 'review_heading' : ($r['status'] === 'denied' ? 'reverse_heading' : 'manage_heading')))); ?></h3>
                    <form method="POST" action="shadow-saas-requests.php?id=<?php echo (int)$r['id']; ?>" class="sr-form" id="sr-review-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="action" id="sr-review-action" value="">
                        <?php if ($r['status'] === 'submitted'): ?>
                        <label for="review_notes"><?php echo e(t('shadow-saas-requests.review_notes')); ?></label>
                        <textarea id="review_notes" name="review_notes" rows="3" maxlength="4000"></textarea>
                        <div class="sr-actions">
                            <button type="submit" class="pp-btn ok" data-sr-action="approve"><?php echo e(t('shadow-saas-requests.approve')); ?></button>
                            <?php if (!$srVendor): ?><button type="submit" class="pp-btn ok" data-sr-action="approve_onboard"><?php echo e(t('shadow-saas-requests.approve_onboard')); ?></button><?php endif; ?>
                            <button type="submit" class="pp-btn danger" data-sr-action="deny" data-sr-confirm="<?php echo e(t('shadow-saas-requests.confirm_deny')); ?>"><?php echo e(t('shadow-saas-requests.deny')); ?></button>
                        </div>
                        <?php elseif ($r['status'] === 'denied'): ?>
                        <p class="sr-help" style="margin-top:0;"><?php echo e(t('shadow-saas-requests.reverse_help')); ?></p>
                        <label for="review_notes"><?php echo e(t('shadow-saas-requests.reverse_reason')); ?></label>
                        <textarea id="review_notes" name="review_notes" rows="3" maxlength="4000" required></textarea>
                        <div class="sr-actions">
                            <button type="submit" class="pp-btn ok" data-sr-action="approve" data-sr-confirm="<?php echo e(t('shadow-saas-requests.confirm_reverse')); ?>"><?php echo e(t('shadow-saas-requests.approve')); ?></button>
                        </div>
                        <?php else: ?>
                        <div class="sr-actions" style="margin-top:0;">
                            <?php if ($usesFairTprm): ?><button type="submit" class="pp-btn" data-sr-action="resend" data-sr-confirm="<?php echo e(t('shadow-saas-requests.confirm_resend')); ?>"><?php echo e(t('shadow-saas-requests.resend')); ?></button><?php endif; ?>
                            <button type="submit" class="pp-btn alt" data-sr-action="cancel" data-sr-confirm="<?php echo e(t('shadow-saas-requests.confirm_cancel')); ?>"><?php echo e(t('shadow-saas-requests.cancel_request')); ?></button>
                        </div>
                        <p class="sr-help"><?php echo e(t('shadow-saas-requests.resend_help')); ?></p>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>

<?php elseif ($view === 'new'): ?>
                <div class="pp-head">
                    <div>
                        <a class="sr-link" href="shadow-saas-requests.php">&larr; <?php echo e(t('shadow-saas-requests.back')); ?></a>
                        <h1 class="page-title" style="margin:6px 0 0;"><?php echo e(t('shadow-saas-requests.new_title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-requests.new_intro')); ?></p>
                    </div>
                </div>
                <?php if (!$canManage): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-requests.read_only')); ?></div>
                <?php elseif (!$usesFairTprm): ?>
                    <div class="sr-warn"><?php echo e(t('shadow-saas-requests.method_is_grip')); ?></div>
                <?php else: ?>
                <?php if (!$srChoices): ?><div class="sr-warn"><?php echo e(t('shadow-saas-requests.no_choices')); ?></div><?php endif; ?>
                <div class="sr-card">
                    <form method="POST" action="shadow-saas-requests.php" class="sr-form" id="sr-new-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="send_request">
                        <?php if ($srNewApp): ?>
                        <input type="hidden" name="shadow_saas_id" value="<?php echo (int)$srNewApp['id']; ?>">
                        <label style="margin-top:0;"><?php echo e(t('shadow-saas-requests.application')); ?></label>
                        <div class="sr-name"><?php echo e($srNewApp['vendor_name']); ?></div>
                        <div class="sr-sub"><?php echo e($srNewApp['vendor_domain'] ?? ''); ?>
                            <?php if (!empty($srNewApp['grip_id']) && $gripEnabled): ?> &middot; <a class="sr-link" href="grip-saas-users.php?id=<?php echo e(urlencode($srNewApp['grip_id'])); ?>"><?php echo e(t('shadow-saas-requests.pick_from_grip', (int)$srNewApp['number_of_users'])); ?></a><?php endif; ?>
                        </div>
                        <?php else: ?>
                        <label for="app_name" style="margin-top:0;"><?php echo e(t('shadow-saas-requests.application')); ?></label>
                        <input type="text" id="app_name" name="app_name" maxlength="500" required value="<?php echo e($srForm['app_name'] ?? ''); ?>" list="sr-apps">
                        <datalist id="sr-apps">
                            <?php foreach ($db->fetchAll("SELECT vendor_name FROM shadow_saas WHERE status != 'onboarded' ORDER BY vendor_name LIMIT 2000") as $o): ?><option value="<?php echo e($o['vendor_name']); ?>"><?php endforeach; ?>
                        </datalist>
                        <div class="sr-help"><?php echo e(t('shadow-saas-requests.app_help')); ?></div>
                        <label for="app_domain"><?php echo e(t('shadow-saas-requests.domain')); ?></label>
                        <input type="text" id="app_domain" name="app_domain" maxlength="255" value="<?php echo e($srForm['domain'] ?? ''); ?>" placeholder="example.com">
                        <?php endif; ?>
                        <label for="recipients"><?php echo e(t('shadow-saas-requests.recipients')); ?></label>
                        <textarea id="recipients" name="recipients" rows="4" class="mono" required placeholder="jane@example.com, john@example.com"><?php echo e($srForm['emails'] ?? ''); ?></textarea>
                        <div class="sr-help"><?php echo e(t('shadow-saas-requests.recipients_help', ShadowSaasRequestService::MAX_RECIPIENTS)); ?></div>
                        <label for="message"><?php echo e(t('shadow-saas-requests.message')); ?></label>
                        <textarea id="message" name="message" rows="3" maxlength="2000"><?php echo e($srForm['message'] ?? ''); ?></textarea>
                        <div class="sr-help"><?php echo e(t('shadow-saas-requests.message_help')); ?></div>
                        <?php if ($srChoices): ?>
                        <label><?php echo e(t('shadow-saas-requests.choices_heading')); ?></label>
                        <ul class="sr-choice-list">
                            <?php foreach ($srChoices as $c): ?><li><strong><?php echo e($c['label']); ?></strong> &rarr; <?php echo e($c['template_name']); ?> (<?php echo e(t('shadow-saas-requests.kind_' . $c['kind'])); ?>)</li><?php endforeach; ?>
                        </ul>
                        <div class="sr-help"><?php echo $isAdmin ? '<a class="sr-link" href="admin.php?section=email">' . e(t('shadow-saas-requests.choices_edit')) . '</a>' : ''; ?></div>
                        <?php endif; ?>
                        <div class="sr-actions">
                            <button type="submit" class="pp-btn" id="sr-send-btn" <?php echo $srChoices ? '' : 'disabled'; ?>><?php echo e(t('shadow-saas-requests.send')); ?></button>
                            <a class="pp-btn alt" href="shadow-saas-requests.php"><?php echo e(t('shadow-saas-requests.cancel')); ?></a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

<?php else: ?>
                <div class="pp-head">
                    <div>
                        <h1 class="page-title" style="margin:0;"><?php echo e(t('shadow-saas-requests.title')); ?></h1>
                        <p><?php echo e(t('shadow-saas-requests.intro')); ?></p>
                    </div>
                    <?php if ($canManage && $usesFairTprm): ?><a class="pp-btn" href="shadow-saas-requests.php?new=1" id="sr-new-btn"><?php echo e(t('shadow-saas-requests.new_request')); ?></a><?php endif; ?>
                </div>
                <?php if (!$usesFairTprm): ?><div class="sr-warn"><?php echo e(t('shadow-saas-requests.method_is_grip_list')); ?></div><?php endif; ?>
                <div class="sr-bar">
                    <div class="sr-chips">
                        <?php foreach (['review' => $srCounts['awaiting_review'], 'waiting' => $srCounts['awaiting_response'], 'approved' => $srCounts['approved'], 'denied' => $srCounts['denied'], 'closed' => null, 'all' => $srCounts['total']] as $fk => $fn): ?>
                        <a class="pp-chip<?php echo $srFilter === $fk ? ' on' : ''; ?>" href="?<?php echo e(http_build_query(['filter' => $fk] + ($srSearch !== '' ? ['q' => $srSearch] : []))); ?>"><?php echo e(t('shadow-saas-requests.filter_' . $fk)); ?><?php if ($fn !== null): ?><span class="n"><?php echo (int)$fn; ?></span><?php endif; ?></a>
                        <?php endforeach; ?>
                    </div>
                    <form method="GET" class="sr-search">
                        <input type="hidden" name="filter" value="<?php echo e($srFilter); ?>">
                        <input type="search" name="q" value="<?php echo e($srSearch); ?>" placeholder="<?php echo e(t('shadow-saas-requests.search_placeholder')); ?>" aria-label="<?php echo e(t('shadow-saas-requests.search_placeholder')); ?>">
                        <button type="submit" class="pp-chip"><?php echo e(t('shadow-saas-posture.filter')); ?></button>
                    </form>
                </div>
                <?php if (!$srRows): ?>
                    <div class="pp-empty"><?php echo e(t('shadow-saas-requests.none')); ?></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="data-table" id="sr-table">
                    <thead><tr>
                        <th><?php echo e(t('shadow-saas-requests.col_app')); ?></th>
                        <th><?php echo e(t('shadow-saas-requests.col_requester')); ?></th>
                        <th><?php echo e(t('shadow-saas-requests.col_choice')); ?></th>
                        <th><?php echo e(t('shadow-saas-requests.col_status')); ?></th>
                        <th><?php echo e(t('shadow-saas-requests.col_sent')); ?></th>
                        <th><?php echo e(t('shadow-saas-requests.col_submitted')); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($srRows as $row): ?>
                    <tr class="sr-row" data-request-id="<?php echo (int)$row['id']; ?>" data-href="shadow-saas-requests.php?id=<?php echo (int)$row['id']; ?>">
                        <td><a class="sr-name sr-link" href="shadow-saas-requests.php?id=<?php echo (int)$row['id']; ?>"><?php echo e($row['app_name']); ?></a>
                            <?php if ($row['app_domain']): ?><div class="sr-sub"><?php echo e($row['app_domain']); ?></div><?php endif; ?></td>
                        <td><?php echo e(trim(($row['submitter_first_name'] ?? '') . ' ' . ($row['submitter_last_name'] ?? '')) ?: ($row['requester_name'] ?: '')); ?>
                            <div class="sr-sub"><?php echo e($row['requester_email']); ?></div></td>
                        <td><?php echo $row['choice_label'] ? e($row['choice_label']) : '<span class="pp-muted">&mdash;</span>'; ?></td>
                        <td><?php echo srStatusPill($row); ?><?php if ($row['email_status'] === 'failed' || $row['email_status'] === 'disabled'): ?><div class="sr-sub" style="color:#b91c1c;"><?php echo e(t('shadow-saas-requests.email_' . $row['email_status'])); ?></div><?php endif; ?></td>
                        <td style="white-space:nowrap;"><?php echo srDate($row['sent_at']); ?><?php if ($row['sent_by_name']): ?><div class="sr-sub"><?php echo e($row['sent_by_name']); ?></div><?php endif; ?></td>
                        <td style="white-space:nowrap;"><?php echo srDate($row['submitted_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
    <script nonce="<?php echo cspNonce(); ?>">
        // Review / resend / cancel: the dispatch key rides on a hidden input set
        // from the clicked button, so it survives any submit-button handling.
        (function () {
            var form = document.getElementById('sr-review-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                var btn = e.submitter;
                if (!btn || !btn.getAttribute('data-sr-action')) { e.preventDefault(); return; }
                var msg = btn.getAttribute('data-sr-confirm');
                if (msg && !confirm(msg)) { e.preventDefault(); return; }
                document.getElementById('sr-review-action').value = btn.getAttribute('data-sr-action');
            });
        })();
    </script>
    <script src="app/js/mobile-nav.js?v=2"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
