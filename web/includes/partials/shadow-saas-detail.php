<?php
/**
 * Shadow SaaS row details (the panel that opens when a row is clicked). Shared
 * by shadow-saas.php (Applications) and shadow-saas-new.php (New) so both show
 * the same information.
 *
 * Expects in scope: $entry (a shadow_saas row, s.*), $gripEnabled, $srsService,
 * $shodanService, $upguardName, $shodanName, $upguardDisplayMode,
 * $upguardMaxScore, $shodanColumnsExist. Local variables are sd-prefixed so the
 * caller's loop variables are never overwritten.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */
require_once __DIR__ . '/shadow-saas-helpers.php';
$sdHasUpguard   = !empty($entry['current_srs_score']);
$sdUpguardGrade = $sdHasUpguard ? $srsService->calculateGrade((int)$entry['current_srs_score']) : null;
$sdHasShodan    = $shodanColumnsExist && !empty($entry['current_shodan_score']);
$sdShodanGrade  = $sdHasShodan ? $shodanService->calculateGrade((int)$entry['current_shodan_score']) : null;
$sdIsScoring    = !empty($entry['rescore_status']) && $entry['rescore_status'] === 'rescoring';
$sdAiLevel      = shadowSaasAiLevel($entry);
?>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_description')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['description'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_rm')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['relationship_manager'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_breaches')); ?></span>
                                        <span class="detail-value"><?php echo $entry['breaches_in_three_years'] !== null ? (int)$entry['breaches_in_three_years'] : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_download')); ?></span>
                                        <span class="detail-value"><?php echo $entry['downloadbytes'] !== null ? formatBytes((int)$entry['downloadbytes']) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_upload')); ?></span>
                                        <span class="detail-value"><?php echo $entry['uploadbytes'] !== null ? formatBytes((int)$entry['uploadbytes']) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_filesharing')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['filesharing'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_mfa')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['mfasupport'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e($upguardName); ?> <?php echo e(t('shadow-saas.score_suffix')); ?></span>
                                        <span class="detail-value">
                                            <?php if ($sdHasUpguard): ?>
                                            <?php echo displayUpguardScore((int)$entry['current_srs_score'], $upguardDisplayMode, $upguardMaxScore); ?>
                                            <span class="score-grade grade-<?php echo strtolower($sdUpguardGrade); ?>"><?php echo $sdUpguardGrade; ?></span>
                                            <?php elseif ($sdIsScoring): ?>
                                            <span style="color: #9ca3af; font-style: italic;"><?php echo e(t('shadow-saas.scoring')); ?></span>
                                            <?php else: ?>—<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if ($shodanColumnsExist): ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e($shodanName); ?> <?php echo e(t('shadow-saas.score_suffix')); ?></span>
                                        <span class="detail-value">
                                            <?php if ($sdHasShodan): ?>
                                            <?php echo e($entry['current_shodan_score']); ?>%
                                            <span class="score-grade grade-<?php echo strtolower($sdShodanGrade); ?>"><?php echo $sdShodanGrade; ?></span>
                                            <?php elseif ($sdIsScoring): ?>
                                            <span style="color: #9ca3af; font-style: italic;"><?php echo e(t('shadow-saas.scoring')); ?></span>
                                            <?php else: ?>—<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_date_added')); ?></span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($entry['created_at'])); ?></span>
                                    </div>
                                    <?php if ($gripEnabled && ($entry['source'] ?? '') === 'grip'): ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_grip_mfa')); ?></span>
                                        <span class="detail-value"><?php echo !empty($entry['grip_mfa_status']) ? e(t('shadow-saas.grip_mfa_' . strtolower(str_replace(' ', '_', $entry['grip_mfa_status'])))) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_grip_usage')); ?></span>
                                        <span class="detail-value"><?php echo !empty($entry['grip_app_classification']) ? e(t('shadow-saas.grip_usage_' . $entry['grip_app_classification'])) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item" style="grid-column: 1 / -1;">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_grip_ai')); ?></span>
                                        <span class="detail-value">
                                            <?php if ($sdAiLevel !== null): ?>
                                            <span class="ai-badge"><?php echo e(t('shadow-saas.ai_badge')); ?></span> <strong><?php echo e($sdAiLevel); ?></strong>
                                            <?php if (!empty($entry['grip_ai_depth_description'])): ?><div style="margin-top: 4px;"><?php echo e($entry['grip_ai_depth_description']); ?></div><?php endif; ?>
                                            <?php elseif (shadowSaasAiDeclared($entry)): ?><?php echo e(t('shadow-saas.ai_none')); ?>
                                            <?php else: ?>—<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($entry['grip_id'])): ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_grip_users')); ?></span>
                                        <span class="detail-value"><a href="grip-saas-users.php?id=<?php echo e(urlencode($entry['grip_id'])); ?>"><?php echo e(t('shadow-saas.view_users_justify')); ?></a></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                    $sdBreaches = json_decode((string)($entry['grip_breach_history'] ?? ''), true);
                                    if (is_array($sdBreaches) && $sdBreaches): ?>
                                    <div class="detail-item" style="grid-column: 1 / -1;">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_grip_breaches')); ?></span>
                                        <ul class="grip-breach-list">
                                            <?php foreach (array_slice($sdBreaches, 0, 10) as $sdB): ?>
                                            <li>
                                                <?php echo e($sdB['date'] ?? '—'); ?>
                                                <?php if (!empty($sdB['types'])): ?> - <?php echo e(implode(', ', (array)$sdB['types'])); ?><?php endif; ?>
                                                <?php if (!empty($sdB['url']) && preg_match('#^https?://#i', (string)$sdB['url'])): ?>
                                                (<a href="<?php echo e($sdB['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(t('shadow-saas.breach_source')); ?></a>)
                                                <?php endif; ?>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
