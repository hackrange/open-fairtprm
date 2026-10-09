<?php
/**
 * Shadow SaaS section tabs (Grip-backed views). Include with $ssTab set to the
 * active key. The .ss-tabs CSS lives in each page's stylesheet.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */
$ssTabs = [
    'applications' => ['shadow-saas.php',          t('shadow-saas-posture.tab_applications')],
    'new'          => ['shadow-saas-new.php',      t('shadow-saas-new.tab_new')],
    'ai_apps'      => ['shadow-saas-ai-apps.php',  t('shadow-saas-ai.tab_ai_apps')],
    'ai_agents'    => ['shadow-saas-ai-agents.php', t('shadow-saas-ai.tab_ai_agents')],
    'posture'      => ['shadow-saas-posture.php',  t('shadow-saas-posture.tab_posture')],
    'itdr'         => ['shadow-saas-itdr.php',     t('shadow-saas-itdr.tab_itdr')],
];
?>
<nav class="ss-tabs" aria-label="<?php echo e(t('shadow-saas.heading')); ?>">
<?php foreach ($ssTabs as $ssKey => [$ssHref, $ssLabel]): ?>
    <a href="<?php echo e($ssHref); ?>"<?php echo ($ssTab ?? '') === $ssKey ? ' class="active"' : ''; ?>><?php echo e($ssLabel); ?></a>
<?php endforeach; ?>
</nav>
