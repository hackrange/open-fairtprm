<?php
/**
 * Shadow SaaS section tabs. Include with $ssTab set to the active page key.
 * Top row: section groups; second row: the pages inside the active group (AI
 * Security, Posture). The Grip-backed groups show only while Grip is enabled;
 * Requests shows while the FairTPRM request method is in use or any request
 * exists, with a bubble for submitted requests awaiting review. The .ss-tabs CSS lives in each page's
 * stylesheet; the sub-tab rules ride with this include.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */
require_once dirname(__DIR__) . '/classes/ShadowSaasRequestService.php';
$ssReqCounts = (new ShadowSaasRequestService())->counts();
$ssShowRequests = ShadowSaasRequestService::usesFairTprm() || $ssReqCounts['total'] > 0;
$ssGroups = [
    'applications' => ['shadow-saas.php', t('shadow-saas-posture.tab_applications'), [
        'applications' => ['shadow-saas.php', ''],
    ]],
    'new' => ['shadow-saas-new.php', t('shadow-saas-new.tab_new'), [
        'new' => ['shadow-saas-new.php', ''],
    ]],
    'ai' => ['shadow-saas-ai-dashboard.php', t('nav.ss_ai_security'), [
        'ai_dashboard' => ['shadow-saas-ai-dashboard.php', t('nav.ss_ai_dashboard')],
        'ai_apps'      => ['shadow-saas-ai-apps.php', t('nav.ss_ai_apps')],
        'ai_agents'    => ['shadow-saas-ai-agents.php', t('nav.ss_ai_agents')],
    ]],
    'posture' => ['shadow-saas-compliance.php', t('nav.ss_posture'), [
        'compliance' => ['shadow-saas-compliance.php', t('nav.ss_compliance_posture')],
        'posture'    => ['shadow-saas-posture.php', t('nav.ss_posture_management')],
    ]],
    'itdr' => ['shadow-saas-itdr.php', t('shadow-saas-itdr.tab_itdr'), [
        'itdr' => ['shadow-saas-itdr.php', ''],
    ]],    'requests' => ['shadow-saas-requests.php', t('nav.ss_requests'), [
        'requests' => ['shadow-saas-requests.php', ''],
    ]],
];
if (getAppConfig('grip_enabled', '0') !== '1') {
    $ssGroups = array_intersect_key($ssGroups, ['applications' => 1, 'requests' => 1]);
}
if (!$ssShowRequests) unset($ssGroups['requests']);
$ssActiveGroup = '';
foreach ($ssGroups as $ssG => $ssDef) {
    if (isset($ssDef[2][$ssTab ?? ''])) { $ssActiveGroup = $ssG; break; }
}
?>
<style nonce="<?php echo cspNonce(); ?>">
    .ss-subtabs { display: flex; gap: 6px; flex-wrap: wrap; margin: -12px 0 20px; }
    .ss-subtabs a { font-size: 13px; padding: 5px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #e5e7eb; background: #fff; color: #374151; }
    .ss-tab-badge { display: inline-block; min-width: 18px; padding: 0 6px; border-radius: 9px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; }
    .ss-subtabs a.active { background: var(--theme-button-color); color: #fff; border-color: var(--theme-button-color); font-weight: 600; }
</style>
<nav class="ss-tabs" aria-label="<?php echo e(t('shadow-saas.heading')); ?>">
<?php foreach ($ssGroups as $ssG => [$ssHref, $ssLabel]): ?>
    <a href="<?php echo e($ssHref); ?>"<?php echo $ssActiveGroup === $ssG ? ' class="active"' : ''; ?>><?php echo e($ssLabel); ?><?php if ($ssG === 'requests' && $ssReqCounts['awaiting_review'] > 0): ?> <span class="ss-tab-badge"><?php echo (int)$ssReqCounts['awaiting_review']; ?></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>
<?php if ($ssActiveGroup !== '' && count($ssGroups[$ssActiveGroup][2]) > 1): ?>
<nav class="ss-subtabs" aria-label="<?php echo e($ssGroups[$ssActiveGroup][1]); ?>">
<?php foreach ($ssGroups[$ssActiveGroup][2] as $ssKey => [$ssHref, $ssLabel]): ?>
    <a href="<?php echo e($ssHref); ?>"<?php echo ($ssTab ?? '') === $ssKey ? ' class="active"' : ''; ?>><?php echo e($ssLabel); ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
