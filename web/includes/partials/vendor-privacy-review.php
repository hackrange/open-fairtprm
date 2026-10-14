<?php
/**
 * Vendor privacy and file-sharing review panel (VendorPrivacyService results).
 * Shared by the Shadow SaaS row details and the vendor onboarding page.
 *
 * Expects in scope: $vpReview (a vendor_privacy_reviews row, or null). Prints
 * nothing when the vendor has not been reviewed. Everything printed from the
 * review is HTML-escaped; quotes come from vendor websites and are untrusted.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */
if (!empty($vpReview)):
    $vpFacts = json_decode((string)($vpReview['facts'] ?? ''), true) ?: [];
    $vpEvidence = json_decode((string)($vpReview['evidence'] ?? ''), true) ?: [];
    $vpSources = json_decode((string)($vpReview['sources'] ?? ''), true) ?: [];
    $vpLvl = fn($l) => in_array($l, ['High', 'Medium', 'Low'], true) ? $l : 'Unknown';
?>
<div class="vp-panel" data-vp-domain="<?php echo e($vpReview['domain']); ?>">
    <div>
        <span class="vp-badge vp-<?php echo e($vpLvl($vpReview['privacy_risk'])); ?>" style="margin-left:0;"><?php echo e(t('vendor-privacy.badge_privacy', t('vendor-privacy.level_' . strtolower($vpLvl($vpReview['privacy_risk']))))); ?></span>
        <span class="vp-badge vp-<?php echo e($vpLvl($vpReview['sharing_risk'])); ?>"><?php echo e(t('vendor-privacy.badge_sharing', t('vendor-privacy.level_' . strtolower($vpLvl($vpReview['sharing_risk']))))); ?></span>
        <?php if (!empty($vpReview['risk_changed_at']) && ($vpReview['previous_privacy_risk'] !== null || $vpReview['previous_sharing_risk'] !== null)): ?>
            <span class="vp-meta" style="margin-left:6px;"><?php echo e(t('vendor-privacy.changed', (string)$vpReview['previous_privacy_risk'], (string)$vpReview['previous_sharing_risk'], date('M j, Y', strtotime($vpReview['risk_changed_at'])))); ?></span>
        <?php endif; ?>
    </div>
    <?php if ($vpReview['status'] === 'error'): ?>
        <div class="vp-meta"><?php echo e(t('vendor-privacy.error', (string)$vpReview['last_error'])); ?></div>
    <?php endif; ?>
    <?php if (!empty($vpReview['summary'])): ?><div style="margin-top:6px;"><?php echo e($vpReview['summary']); ?></div><?php endif; ?>
    <?php $vpKnown = array_filter($vpFacts, fn($v) => $v !== 'unknown'); if ($vpKnown): ?>
    <ul class="vp-facts">
        <?php foreach ($vpKnown as $k => $v): ?>
            <li><?php echo e(t('vendor-privacy.fact_' . $k)); ?>: <strong><?php echo e(t('vendor-privacy.val_' . $v)); ?></strong></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php foreach (array_slice($vpEvidence, 0, 8) as $ev): if (!is_array($ev)) continue; ?>
        <div class="vp-quote<?php echo ($ev['source'] ?? '') === 'ai_knowledge' ? ' vp-knowledge' : ''; ?>">
            <?php if (($ev['source'] ?? '') === 'ai_knowledge'): ?><?php echo e((string)($ev['quote'] ?? '')); ?><?php else: ?>&ldquo;<?php echo e((string)($ev['quote'] ?? '')); ?>&rdquo;<?php endif; ?>
            <div class="vp-meta">
                <?php echo e(t('vendor-privacy.fact_' . ($ev['field'] ?? ''))); ?>
                &middot;
                <?php if (($ev['source'] ?? '') === 'grip'): ?>
                    <?php echo e(t('vendor-privacy.source_grip')); ?>
                <?php elseif (($ev['source'] ?? '') === 'ai_knowledge'): ?>
                    <?php echo e(t('vendor-privacy.source_ai_knowledge')); ?>
                <?php elseif (!empty($ev['url']) && preg_match('#^https?://#i', (string)$ev['url'])): ?>
                    <a href="<?php echo e($ev['url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo e(parse_url((string)$ev['url'], PHP_URL_HOST) . (string)parse_url((string)$ev['url'], PHP_URL_PATH)); ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="vp-meta">
        <?php if (!empty($vpReview['evaluated_at'])): ?><?php echo e(t('vendor-privacy.reviewed', date('M j, Y', strtotime($vpReview['evaluated_at'])))); ?><?php endif; ?>
        <?php if (!empty($vpReview['checked_at']) && $vpReview['checked_at'] !== $vpReview['evaluated_at']): ?> &middot; <?php echo e(t('vendor-privacy.checked', date('M j, Y', strtotime($vpReview['checked_at'])))); ?><?php endif; ?>
        <?php if (!empty($vpReview['next_due_at'])): ?> &middot; <?php echo e(t('vendor-privacy.next', date('M j, Y', strtotime($vpReview['next_due_at'])))); ?><?php endif; ?>
        <?php if ($vpSources): ?> &middot; <?php echo e(t('vendor-privacy.sources', count($vpSources))); ?><?php endif; ?>
        <?php if (empty(array_filter($vpFacts, fn($v) => $v !== 'unknown')) && $vpReview['status'] !== 'error'): ?><div><?php echo e(t('vendor-privacy.nothing_found')); ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>
