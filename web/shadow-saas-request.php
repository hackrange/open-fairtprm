<?php
/**
 * Shadow SaaS Request - public landing page (no login).
 *
 * The request email links here with ?t=<token>[&c=<choice>]. The page shows
 * the app and one button per configured choice (e.g. Personal Use / Business
 * Use). Nothing changes on GET apart from "opened": mail security scanners
 * fetch every link in a message, so picking a choice is always a POST, which
 * creates the matching form and forwards to vendor-assessment.php.
 *
 * The token authorizes exactly one request. Unknown, malformed and expired
 * tokens get the same neutral page.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
require_once __DIR__ . '/includes/classes/ShadowSaasRequestService.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$security = Security::getInstance();
$svc      = new ShadowSaasRequestService();

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$req   = $token !== '' ? $svc->findByToken($token) : null;
$state = 'invalid';
$error = '';

if ($req) {
    if (in_array($req['status'], ShadowSaasRequestService::OPEN_STATUSES, true)) {
        $state = ShadowSaasRequestService::isExpired($req) ? 'expired' : 'open';
    } elseif ($req['status'] === 'cancelled') {
        $state = 'cancelled';
    } else {
        $state = 'done';
    }
}

if ($state === 'open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas-request.session_expired');
    } else {
        $res = $svc->choose($req, (int)($_POST['c'] ?? -1));
        if (!empty($res['uuid'])) {
            try {
                Auth::getInstance()->audit(null, 'shadow_saas_request_choice', 'shadow_saas_requests', (int)$req['id'], [
                    'new' => ['choice' => (int)($_POST['c'] ?? -1), 'email' => $req['requester_email']],
                ]);
            } catch (Throwable $e) {}
            header('Location: vendor-assessment.php?token=' . rawurlencode($res['uuid']));
            exit;
        }
        $error = t('shadow-saas-request.choice_failed');
        error_log('Shadow SaaS request ' . $req['id'] . ' choice failed: ' . ($res['error'] ?? '?'));
    }
} elseif ($state === 'open') {
    $svc->markOpened($req);
}

$choices  = $state === 'open' ? $svc->choices() : [];
$selected = isset($_GET['c']) && ctype_digit((string)$_GET['c']) ? (int)$_GET['c'] : null;
$current  = ($req && $req['choice_index'] !== null && $req['assessment_id']) ? (int)$req['choice_index'] : null;
if ($state !== 'invalid' && $state !== 'open') http_response_code(410);
if ($state === 'invalid') http_response_code(404);

$csrfToken   = $security->generateCSRFToken();
$companyName = getAppConfig('company_name', '');
$logoUrl     = getAppConfig('logo_url', '');
$headerColor = getAppConfig('header_color', '#35a0a3') ?: '#35a0a3';
$buttonColor = getAppConfig('button_color', '#35a0a3') ?: '#35a0a3';
foreach (['headerColor', 'buttonColor'] as $cv) {
    if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $$cv)) $$cv = '#35a0a3';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e(t('shadow-saas-request.page_title')); ?><?php echo $companyName !== '' ? ' - ' . e($companyName) : ''; ?></title>
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <style nonce="<?php echo cspNonce(); ?>">
        :root { --hdr: <?php echo e($headerColor); ?>; --btn: <?php echo e($buttonColor); ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f4f4f5; color: #1f2937; }
        .ssr-head { background: var(--hdr); color: #fff; padding: 28px 16px; text-align: center; }
        .ssr-head img { max-width: 200px; max-height: 60px; }
        .ssr-head h1 { margin: 10px 0 0; font-size: 20px; font-weight: 600; }
        .ssr-wrap { max-width: 620px; margin: 0 auto; padding: 24px 16px 48px; }
        .ssr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 24px; }
        .ssr-card h2 { margin: 0 0 6px; font-size: 20px; }
        .ssr-app { color: #6b7280; font-size: 14px; margin: 0 0 18px; }
        .ssr-card p { line-height: 1.6; font-size: 15px; }
        .ssr-note { background: #f9fafb; border-left: 3px solid #d1d5db; padding: 10px 14px; margin: 0 0 18px; font-size: 14px; color: #374151; white-space: pre-wrap; }
        .ssr-choices { display: grid; gap: 12px; margin: 18px 0; }
        .ssr-choice { margin: 0; }
        .ssr-choice button { width: 100%; text-align: left; background: #fff; border: 2px solid #e5e7eb; border-radius: 10px; padding: 16px 18px; cursor: pointer; font: inherit; color: inherit; }
        .ssr-choice button:hover, .ssr-choice button:focus { border-color: var(--btn); outline: none; }
        .ssr-choice.is-picked button { border-color: var(--btn); background: #f8fafc; }
        .ssr-choice strong { display: block; font-size: 16px; color: #111827; }
        .ssr-choice span { display: block; font-size: 13px; color: #6b7280; margin-top: 4px; }
        .ssr-choice em { display: inline-block; font-style: normal; font-size: 11px; font-weight: 600; color: #fff; background: var(--btn); border-radius: 10px; padding: 1px 8px; margin-top: 8px; }
        .ssr-fine { font-size: 12px; color: #6b7280; margin: 18px 0 0; }
        .ssr-error { background: #fee2e2; color: #991b1b; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 14px; }
    </style>
</head>
<body>
    <header class="ssr-head">
        <?php if ($logoUrl !== ''): ?><img src="<?php echo e($logoUrl); ?>" alt="<?php echo e($companyName ?: 'Logo'); ?>"><?php endif; ?>
        <h1><?php echo e(t('shadow-saas-request.page_title')); ?></h1>
    </header>
    <main class="ssr-wrap">
        <div class="ssr-card" id="ssr-card" data-state="<?php echo e($state); ?>">
        <?php if ($state === 'invalid'): ?>
            <h2><?php echo e(t('shadow-saas-request.invalid_title')); ?></h2>
            <p><?php echo e(t('shadow-saas-request.invalid_body')); ?></p>
        <?php elseif ($state === 'expired'): ?>
            <h2><?php echo e(t('shadow-saas-request.expired_title')); ?></h2>
            <p><?php echo e(t('shadow-saas-request.expired_body')); ?></p>
        <?php elseif ($state === 'cancelled'): ?>
            <h2><?php echo e(t('shadow-saas-request.cancelled_title')); ?></h2>
            <p><?php echo e(t('shadow-saas-request.cancelled_body')); ?></p>
        <?php elseif ($state === 'done'): ?>
            <h2><?php echo e(t('shadow-saas-request.done_title')); ?></h2>
            <p><?php echo e(t('shadow-saas-request.done_body', $req['app_name'])); ?></p>
        <?php else: ?>
            <?php if ($error !== ''): ?><div class="ssr-error"><?php echo e($error); ?></div><?php endif; ?>
            <h2><?php echo e(t('shadow-saas-request.heading', $req['app_name'])); ?></h2>
            <p class="ssr-app"><?php echo e($req['app_domain'] ?: $req['app_name']); ?> &middot; <?php echo e($req['requester_email']); ?></p>
            <p><?php echo e(t('shadow-saas-request.intro')); ?></p>
            <?php if (!empty($req['message'])): ?><div class="ssr-note"><?php echo e($req['message']); ?></div><?php endif; ?>
            <?php if (!$choices): ?>
                <p><?php echo e(t('shadow-saas-request.no_choices')); ?></p>
            <?php else: ?>
            <div class="ssr-choices">
                <?php foreach ($choices as $i => $c): ?>
                <form method="POST" action="shadow-saas-request.php" class="ssr-choice<?php echo ($selected === $i || ($selected === null && $current === $i)) ? ' is-picked' : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="t" value="<?php echo e($token); ?>">
                    <input type="hidden" name="c" value="<?php echo (int)$i; ?>">
                    <button type="submit" data-choice="<?php echo (int)$i; ?>"<?php echo $selected === $i ? ' autofocus' : ''; ?>>
                        <strong><?php echo e($c['label']); ?></strong>
                        <?php if ($c['description'] !== ''): ?><span><?php echo e($c['description']); ?></span><?php endif; ?>
                        <?php if ($current === $i): ?><em><?php echo e(t('shadow-saas-request.continue_badge')); ?></em><?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php if ($current !== null): ?><p class="ssr-fine"><?php echo e(t('shadow-saas-request.switch_note')); ?></p><?php endif; ?>
            <?php endif; ?>
            <p class="ssr-fine"><?php echo e(t('shadow-saas-request.recorded_note', date('F j, Y', strtotime($req['expires_at'])))); ?></p>
        <?php endif; ?>
        </div>
    </main>
</body>
</html>
