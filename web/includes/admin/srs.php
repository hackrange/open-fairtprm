<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Security Rating Service (SRS) - UpGuard & Shodan
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The unified SRS configuration hub. UpGuard CyberRisk and Shodan integrations
 * live under one roof with horizontal tab navigation. UpGuard tab covers API keys,
 * scoring methods, grade thresholds, and tier-based rescoring. Shodan tab covers
 * its own API settings, category weights, signal points, waivers, and about info.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// Determine active SRS tab
// ============================================================================
$srsTab = $_GET['srs_tab'] ?? 'upguard';
if (!in_array($srsTab, ['upguard', 'shodan', 'custom'], true)) {
    $srsTab = 'upguard';
}

// Auto-detect tab from POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_shodan']) || isset($_POST['update_shodan_signals']) || isset($_POST['reset_shodan_signals']) || isset($_POST['update_shodan_weights']) || isset($_POST['reset_shodan_weights']) || isset($_POST['test_shodan_connection']) || isset($_POST['remove_waiver']) || isset($_POST['remove_all_waivers'])) {
        $srsTab = 'shodan';
    }
    if (isset($_POST['update_custom_scoring'])) {
        $srsTab = 'custom';
    }
}

// ============================================================================
// POST Handler: Purge Pending Scores
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_pending_scores'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_srs.err_invalid_request');
    } else {
        try {
            $pendingCount = $db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM vendor_onboarding_requests
                 WHERE rescore_status IS NOT NULL AND rescore_status != ''"
            );
            $purged = (int)($pendingCount['cnt'] ?? 0);

            // Also count shadow_saas pending rescores
            $shadowPendingCount = 0;
            try {
                $shadowRow = $db->fetchOne(
                    "SELECT COUNT(*) AS cnt FROM shadow_saas
                     WHERE rescore_status IS NOT NULL AND rescore_status != ''"
                );
                $shadowPendingCount = (int)($shadowRow['cnt'] ?? 0);
            } catch (Exception $e) {}
            $totalPurged = $purged + $shadowPendingCount;

            if ($totalPurged > 0) {
                $db->query(
                    "UPDATE vendor_onboarding_requests
                     SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = 'Purged by admin'
                     WHERE rescore_status IS NOT NULL AND rescore_status != ''"
                );
                try {
                    $db->query(
                        "UPDATE shadow_saas
                         SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = 'Purged by admin'
                         WHERE rescore_status IS NOT NULL AND rescore_status != ''"
                    );
                } catch (Exception $e) {}
                $auth->audit($user['id'], 'purge_pending_scores', 'vendor_onboarding_requests', null, [
                    'purged_count' => $totalPurged, 'vendor_purged' => $purged, 'shadow_saas_purged' => $shadowPendingCount
                ]);
                $success = t('admin_srs.purged_prefix') . $totalPurged . t('admin_srs.purged_suffix');
            } else {
                $success = t('admin_srs.no_pending_rescores');
            }
        } catch (Exception $e) {
            error_log('Error purging pending scores: ' . $e->getMessage());
            $error = t('admin_srs.err_purge_failed');
        }
    }
}

// ============================================================================
// POST Handler: update_custom_scoring
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_custom_scoring'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_srs.err_invalid_request');
    } else {
        try {
            $customEnabled = isset($_POST['custom_scoring_enabled']) ? '1' : '0';
            $existing = $db->fetchOne(
                'SELECT id FROM app_config WHERE config_key = :key',
                [':key' => 'custom_scoring_enabled']
            );
            if ($existing) {
                $db->update('app_config', ['config_value' => $customEnabled], 'config_key = :key', [':key' => 'custom_scoring_enabled']);
            } else {
                $db->insert('app_config', ['config_key' => 'custom_scoring_enabled', 'config_value' => $customEnabled, 'is_encrypted' => 0]);
            }
            $auth->audit($user['id'], 'config_update_custom_scoring', 'app_config', null, [
                'new' => ['custom_scoring_enabled' => $customEnabled]
            ]);
            $success = t('admin_srs.custom_scoring_updated');
        } catch (Exception $e) {
            error_log('Error updating custom scoring config: ' . $e->getMessage());
            $error = t('admin_srs.err_custom_scoring_failed');
        }
    }
}

// ============================================================================
// POST Handlers: update_srs, test_srs_connection, update_tier_schedule
// Only fire for UpGuard-specific actions (prevents CSRF conflicts with Shodan)
// ============================================================================
$_upguardPostAction = isset($_POST['update_srs']) || isset($_POST['test_srs_connection']) || isset($_POST['update_tier_schedule']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_upguardPostAction) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_srs.err_invalid_request');
    } elseif (isset($_POST['update_srs'])) {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            $srsSettings = [
                'upguard_enabled' => isset($_POST['upguard_enabled']) ? '1' : '0',
                'upguard_display_name' => trim($_POST['upguard_display_name'] ?? '') ?: 'UpGuard',
                'upguard_scoring_method' => in_array($_POST['upguard_scoring_method'] ?? 'range', ['range', 'percentage']) ? $_POST['upguard_scoring_method'] : 'range',
                'upguard_display_mode' => in_array($_POST['upguard_display_mode'] ?? 'raw', ['raw', 'percentage']) ? $_POST['upguard_display_mode'] : 'raw',
                'upguard_max_score' => max(1, intval($_POST['upguard_max_score'] ?? 950)),
                'upguard_grade_a_min' => max(0, intval($_POST['upguard_grade_a_min'] ?? 850)),
                'upguard_grade_b_min' => max(0, intval($_POST['upguard_grade_b_min'] ?? 700)),
                'upguard_grade_c_min' => max(0, intval($_POST['upguard_grade_c_min'] ?? 500)),
                'upguard_grade_d_min' => max(0, intval($_POST['upguard_grade_d_min'] ?? 300)),
                'upguard_vendor_domains' => isset($_POST['upguard_vendor_domains']) ? '1' : '0',
                'upguard_org_domain' => strtolower(trim($_POST['upguard_org_domain'] ?? '')),
                'upguard_use_cron' => isset($_POST['upguard_use_cron']) ? '1' : '0'
            ];

            $apiKey = trim($_POST['upguard_api_key'] ?? '');
            if (!empty($apiKey)) {
                $srsSettings['upguard_api_key'] = $encryption->encrypt($apiKey);
            } else {
                $existingKey = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'upguard_api_key']
                );
                if ($existingKey) {
                    $srsSettings['upguard_api_key'] = $existingKey['config_value'];
                }
            }

            foreach ($srsSettings as $key => $value) {
                $isEncrypted = ($key === 'upguard_api_key') ? 1 : 0;

                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        [
                            'config_value' => $value,
                            'is_encrypted' => $isEncrypted
                        ],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => $isEncrypted
                    ]);
                }
            }

            // GitHub PAT for OSINT code search — standalone key (no upguard_
            // prefix), always encrypted; a blank submission keeps the existing
            // token. Used by GitHubOSINTScanner, which needs an authenticated
            // token for GitHub's code-search API.
            $githubPat = trim($_POST['github_pat'] ?? '');
            if ($githubPat !== '') {
                $patEnc = $encryption->encrypt($githubPat);
                $patRow = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :key', [':key' => 'github_pat']);
                if ($patRow) {
                    $db->update('app_config', ['config_value' => $patEnc, 'is_encrypted' => 1], 'config_key = :key', [':key' => 'github_pat']);
                } else {
                    $db->insert('app_config', ['config_key' => 'github_pat', 'config_value' => $patEnc, 'is_encrypted' => 1]);
                }
            }

            $db->commit();
            $auditSrs = $srsSettings;
            unset($auditSrs['upguard_api_key']);
            $auth->audit($user['id'], 'config_update_srs', 'app_config', null, [
                'new' => $auditSrs
            ]);
            $success = t('admin_srs.srs_config_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating SRS config: ' . $e->getMessage());
            $error = t('admin_srs.err_srs_config_failed');
        }
    } elseif (isset($_POST['test_srs_connection'])) {
        try {
            require_once __DIR__ . '/../classes/SRSService.php';
            $srsService = new SRSService();
            $result = $srsService->testConnection();
            if ($result['success']) {
                $success = t('admin_srs.conn_test_prefix') . $result['message'];
            } else {
                $error = t('admin_srs.conn_test_failed_prefix') . $result['message'];
            }
        } catch (Exception $e) {
            error_log('SRS Connection Test Error: ' . $e->getMessage());
            $error = t('admin_srs.err_conn_test');
        }
    } elseif (isset($_POST['update_tier_schedule'])) {
        try {
            $db->beginTransaction();

            $tierSettings = [
                'upguard_tier1_days' => max(1, intval($_POST['upguard_tier1_days'] ?? 30)),
                'upguard_tier2_days' => max(1, intval($_POST['upguard_tier2_days'] ?? 90)),
                'upguard_tier3_days' => max(1, intval($_POST['upguard_tier3_days'] ?? 365)),
                'upguard_trending_days' => max(1, intval($_POST['upguard_trending_days'] ?? 90))
            ];

            foreach ($tierSettings as $key => $value) {
                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        ['config_value' => $value],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => 0
                    ]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update_tier_schedule', 'app_config', null, [
                'new' => $tierSettings
            ]);
            $success = t('admin_srs.tier_schedule_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating tier schedule: ' . $e->getMessage());
            $error = t('admin_srs.err_tier_schedule_failed');
        }
    }
}

// ============================================================================
// Data Loading: SRS/UpGuard configuration
// ============================================================================
$srsConfig = [];
$encryption = new Encryption();
$srsRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?', ['upguard_%']);
foreach ($srsRows as $row) {
    $key = str_replace('upguard_', '', $row['config_key']);
    if ($row['is_encrypted'] && !empty($row['config_value'])) {
        $srsConfig[$key] = $encryption->decrypt($row['config_value']);
    } else {
        $srsConfig[$key] = $row['config_value'];
    }
}
if (!isset($srsConfig['enabled'])) $srsConfig['enabled'] = '0';
if (!isset($srsConfig['api_key'])) $srsConfig['api_key'] = '';

// GitHub PAT is a standalone key (no upguard_ prefix); only its presence is
// surfaced to the form — the secret itself is never echoed back to the browser.
$githubPatRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'github_pat'");
$githubPatIsSet = !empty($githubPatRow['config_value']);

// ============================================================================
// Include Shodan section (POST handlers + data loading + capture HTML)
// ============================================================================
ob_start();
include __DIR__ . '/shodan-srs.php';
$shodanContent = ob_get_clean();

// ============================================================================
// HTML: SRS Settings with UpGuard/Shodan tabs
// ============================================================================
?>
<?php
// Check if either provider has use_cron enabled (need shodan config loaded first, but it's loaded via shodan-srs.php include above)
$_upguardCronEnabled = ($srsConfig['use_cron'] ?? '0') === '1';
$_shodanCronEnabled = getAppConfig('shodan_use_cron', '0') === '1';
$_anyCronEnabled = $_upguardCronEnabled || $_shodanCronEnabled;
$_pendingRescoreCount = 0;
if ($_anyCronEnabled) {
    $_pendingRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM vendor_onboarding_requests WHERE rescore_status IS NOT NULL AND rescore_status != ''");
    $_pendingRescoreCount = (int)($_pendingRow['cnt'] ?? 0);
    try {
        $_shadowPendingRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM shadow_saas WHERE rescore_status IS NOT NULL AND rescore_status != ''");
        $_pendingRescoreCount += (int)($_shadowPendingRow['cnt'] ?? 0);
    } catch (Exception $e) {}
}
?>
<div class="page-header-bar" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
    <div>
        <h1 class="page-title"><?php echo e(t('admin_srs.page_title')); ?></h1>
        <p><?php echo e(t('admin_srs.page_desc')); ?></p>
    </div>
    <?php if ($_anyCronEnabled): ?>
    <form method="POST" action="admin.php?section=srs" style="margin: 0;" onsubmit="return confirm('Purge all <?php echo $_pendingRescoreCount; ?> pending rescore(s)? This will cancel any queued or in-progress cron rescores.');">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <button type="submit" name="purge_pending_scores" value="1" class="btn btn-secondary" style="white-space: nowrap; display: flex; align-items: center; gap: 6px;">
            <?php echo e(t('admin_srs.purge_pending_scores')); ?>
            <?php if ($_pendingRescoreCount > 0): ?>
            <span style="background: #dc2626; color: white; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700;"><?php echo $_pendingRescoreCount; ?></span>
            <?php endif; ?>
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- SRS Tab Navigation -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showSrsSection" data-arg="upguard" id="srsTab_upguard" class="srs-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $srsTab === 'upguard' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $srsTab === 'upguard' ? '600' : '500'; ?>; color: <?php echo $srsTab === 'upguard' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">UpGuard</button>
    <button type="button" data-action="showSrsSection" data-arg="shodan" id="srsTab_shodan" class="srs-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $srsTab === 'shodan' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $srsTab === 'shodan' ? '600' : '500'; ?>; color: <?php echo $srsTab === 'shodan' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Shodan</button>
    <button type="button" data-action="showSrsSection" data-arg="custom" id="srsTab_custom" class="srs-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $srsTab === 'custom' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $srsTab === 'custom' ? '600' : '500'; ?>; color: <?php echo $srsTab === 'custom' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_srs.custom_scoring')); ?></button>
</div>

<!-- ================================================================== -->
<!-- UPGUARD TAB -->
<!-- ================================================================== -->
<div id="srsSection_upguard" <?php echo $srsTab !== 'upguard' ? 'style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_srs.upguard_integration_heading')); ?></h3>
    <form method="POST" action="admin.php?section=srs">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="upguard_enabled" value="1" <?php echo ($srsConfig['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_srs.enable_upguard_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_srs.enable_upguard_help')); ?></div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="upguard_vendor_domains" value="1" <?php echo ($srsConfig['vendor_domains'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_srs.enable_vendor_domains_label')); ?></span>
            </label>
            <div class="form-help"><?php echo t('admin_srs.enable_vendor_domains_help'); ?></div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="upguard_use_cron" value="1" <?php echo ($srsConfig['use_cron'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_srs.use_cron_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_srs.use_cron_help')); ?></div>
            <div id="upguard_cron_setup" style="<?php echo ($srsConfig['use_cron'] ?? '0') === '1' ? '' : 'display: none;'; ?> margin-top: 10px; padding: 12px 16px; background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px;">
                <div style="font-weight: 600; font-size: 13px; color: #374151; margin-bottom: 6px;"><?php echo e(t('admin_srs.cron_job_required')); ?></div>
                <p style="font-size: 12px; color: #6b7280; margin: 0 0 8px 0;"><?php echo e(t('admin_srs.cron_add_entry')); ?></p>
                <div style="background: #1e1e1e; color: #d4d4d4; padding: 8px 12px; border-radius: 4px; font-family: monospace; font-size: 11px; overflow-x: auto;">* * * * * /usr/bin/php <?php echo e(dirname(dirname(__DIR__))); ?>/cron/rescore-queue.php >> /var/log/tprm/rescore-queue.log 2>&1</div>
                <p style="font-size: 11px; color: #9ca3af; margin: 6px 0 0 0;"><?php echo t('admin_srs.cron_lock_files_note'); ?></p>
            </div>
        </div>
        <script nonce="<?php echo cspNonce(); ?>">
        document.querySelector('input[name="upguard_use_cron"]').addEventListener('change', function() {
            document.getElementById('upguard_cron_setup').style.display = this.checked ? '' : 'none';
        });
        </script>
        <div class="form-row">
            <div class="form-group">
                <label for="upguard_api_key"><?php echo e(t('admin_srs.api_key_label')); ?></label>
                <input type="password" id="upguard_api_key" name="upguard_api_key" class="form-control" placeholder="<?php echo !empty($srsConfig['api_key']) ? '••••••••••••••••' : e(t('admin_srs.api_key_placeholder')); ?>">
                <div class="form-help"><?php echo e(t('admin_srs.api_key_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="upguard_display_name"><?php echo e(t('admin_srs.display_name_label')); ?></label>
                <input type="text" id="upguard_display_name" name="upguard_display_name" class="form-control" value="<?php echo e($srsConfig['display_name'] ?? 'UpGuard'); ?>" placeholder="UpGuard" maxlength="50">
                <div class="form-help"><?php echo e(t('admin_srs.display_name_help')); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label for="upguard_org_domain"><?php echo e(t('admin_srs.org_domain_label')); ?></label>
            <input type="text" id="upguard_org_domain" name="upguard_org_domain" class="form-control" value="<?php echo e($srsConfig['org_domain'] ?? ''); ?>" placeholder="<?php echo e(t('admin_srs.org_domain_placeholder')); ?>" maxlength="255" style="max-width: 400px;">
            <div class="form-help"><?php echo t('admin_srs.org_domain_help'); ?></div>
        </div>
        <div class="form-group">
            <label for="github_pat"><?php echo t('admin_srs.github_pat_label'); ?></label>
            <input type="password" id="github_pat" name="github_pat" class="form-control" autocomplete="off" placeholder="<?php echo $githubPatIsSet ? '••••••••  (leave blank to keep)' : 'github_pat_… or ghp_…'; ?>" maxlength="255" style="max-width: 400px;">
            <div class="form-help"><?php echo e(t('admin_srs.github_pat_help')); ?></div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.score_display_grading_heading')); ?></h4>
        <div class="form-row">
            <div class="form-group">
                <label for="upguard_display_mode"><?php echo e(t('admin_srs.display_mode_label')); ?></label>
                <select id="upguard_display_mode" name="upguard_display_mode" class="form-control">
                    <option value="raw" <?php echo ($srsConfig['display_mode'] ?? 'raw') === 'raw' ? 'selected' : ''; ?>><?php echo e(t('admin_srs.display_mode_raw')); ?></option>
                    <option value="percentage" <?php echo ($srsConfig['display_mode'] ?? 'raw') === 'percentage' ? 'selected' : ''; ?>><?php echo e(t('admin_srs.display_mode_percentage')); ?></option>
                </select>
                <div class="form-help"><?php echo e(t('admin_srs.display_mode_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="upguard_scoring_method"><?php echo e(t('admin_srs.scoring_method_label')); ?></label>
                <select id="upguard_scoring_method" name="upguard_scoring_method" class="form-control" data-action="toggleScoringMethod">
                    <option value="range" <?php echo ($srsConfig['scoring_method'] ?? 'range') === 'range' ? 'selected' : ''; ?>><?php echo e(t('admin_srs.scoring_method_range')); ?></option>
                    <option value="percentage" <?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? 'selected' : ''; ?>><?php echo e(t('admin_srs.scoring_method_percentage')); ?></option>
                </select>
                <div class="form-help" id="scoring_method_help"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.scoring_help_pct')) : e(t('admin_srs.scoring_help_range')); ?></div>
            </div>
        </div>

        <div class="form-group" id="max_score_group" style="<?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? '' : 'display: none;'; ?>">
            <label for="upguard_max_score"><?php echo e(t('admin_srs.max_score_label')); ?></label>
            <input type="number" id="upguard_max_score" name="upguard_max_score" class="form-control" value="<?php echo e($srsConfig['max_score'] ?? '950'); ?>" min="1" max="10000">
            <div class="form-help"><?php echo e(t('admin_srs.max_score_help')); ?></div>
        </div>

        <div id="grade_thresholds_label" style="font-weight: 500; margin-bottom: 10px; color: #374151;">
            <?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.grade_thresholds_pct')) : e(t('admin_srs.grade_thresholds_score')); ?>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label for="upguard_grade_a_min" id="grade_a_label"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.grade_a_min_pct')) : e(t('admin_srs.grade_a_min_score')); ?></label>
                <input type="number" id="upguard_grade_a_min" name="upguard_grade_a_min" class="form-control" value="<?php echo e($srsConfig['grade_a_min'] ?? '850'); ?>" min="0" placeholder="<?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? '90' : '850'; ?>">
            </div>
            <div class="form-group">
                <label for="upguard_grade_b_min" id="grade_b_label"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.grade_b_min_pct')) : e(t('admin_srs.grade_b_min_score')); ?></label>
                <input type="number" id="upguard_grade_b_min" name="upguard_grade_b_min" class="form-control" value="<?php echo e($srsConfig['grade_b_min'] ?? '700'); ?>" min="0" placeholder="<?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? '70' : '700'; ?>">
            </div>
            <div class="form-group">
                <label for="upguard_grade_c_min" id="grade_c_label"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.grade_c_min_pct')) : e(t('admin_srs.grade_c_min_score')); ?></label>
                <input type="number" id="upguard_grade_c_min" name="upguard_grade_c_min" class="form-control" value="<?php echo e($srsConfig['grade_c_min'] ?? '500'); ?>" min="0" placeholder="<?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? '50' : '500'; ?>">
            </div>
            <div class="form-group">
                <label for="upguard_grade_d_min" id="grade_d_label"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.grade_d_min_pct')) : e(t('admin_srs.grade_d_min_score')); ?></label>
                <input type="number" id="upguard_grade_d_min" name="upguard_grade_d_min" class="form-control" value="<?php echo e($srsConfig['grade_d_min'] ?? '300'); ?>" min="0" placeholder="<?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? '30' : '300'; ?>">
            </div>
        </div>
        <div class="form-help" id="grade_f_help" style="margin-top: 5px; color: #666;"><?php echo ($srsConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_srs.scores_below_prefix')) . e($srsConfig['grade_d_min'] ?? '300') . e(t('admin_srs.scores_below_suffix_pct')) : e(t('admin_srs.scores_below_prefix')) . e($srsConfig['grade_d_min'] ?? '300') . e(t('admin_srs.scores_below_suffix')); ?></div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button type="submit" name="update_srs" class="btn btn-primary"><?php echo e(t('admin_srs.save_srs_config_btn')); ?></button>
            <button type="submit" name="test_srs_connection" class="btn btn-secondary"><?php echo e(t('admin_srs.test_connection_btn')); ?></button>
        </div>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function toggleScoringMethod() {
    const method = document.getElementById('upguard_scoring_method').value;
    const maxScoreGroup = document.getElementById('max_score_group');
    const isPercentage = method === 'percentage';

    maxScoreGroup.style.display = isPercentage ? '' : 'none';

    const methodHelp = document.getElementById('scoring_method_help');
    if (methodHelp) {
        methodHelp.textContent = isPercentage
            ? <?php echo json_encode(t('admin_srs.scoring_help_pct')); ?>
            : <?php echo json_encode(t('admin_srs.scoring_help_range')); ?>;
    }

    const thresholdsLabel = document.getElementById('grade_thresholds_label');
    if (thresholdsLabel) {
        thresholdsLabel.textContent = isPercentage ? <?php echo json_encode(t('admin_srs.grade_thresholds_pct')); ?> : <?php echo json_encode(t('admin_srs.grade_thresholds_score')); ?>;
    }

    const gradeDefaults = {
        a: { pct: 90, score: 850 },
        b: { pct: 70, score: 700 },
        c: { pct: 50, score: 500 },
        d: { pct: 30, score: 300 }
    };

    ['a', 'b', 'c', 'd'].forEach(grade => {
        const label = document.getElementById('grade_' + grade + '_label');
        const input = document.getElementById('upguard_grade_' + grade + '_min');
        const defaults = gradeDefaults[grade];

        if (label) {
            label.textContent = isPercentage
                ? 'Grade ' + grade.toUpperCase() + ' Minimum (%)'
                : 'Grade ' + grade.toUpperCase() + ' Minimum Score';
        }
        if (input) {
            input.placeholder = isPercentage ? defaults.pct : defaults.score;
        }
    });

    const fHelp = document.getElementById('grade_f_help');
    const dMinInput = document.getElementById('upguard_grade_d_min');
    if (fHelp && dMinInput) {
        const dMin = dMinInput.value || (isPercentage ? '30' : '300');
        fHelp.textContent = isPercentage
            ? <?php echo json_encode(t('admin_srs.scores_below_prefix')); ?> + dMin + <?php echo json_encode(t('admin_srs.scores_below_suffix_pct')); ?>
            : <?php echo json_encode(t('admin_srs.scores_below_prefix')); ?> + dMin + <?php echo json_encode(t('admin_srs.scores_below_suffix')); ?>;
    }
}

document.getElementById('upguard_grade_d_min')?.addEventListener('input', function() {
    const method = document.getElementById('upguard_scoring_method').value;
    const isPercentage = method === 'percentage';
    const fHelp = document.getElementById('grade_f_help');
    if (fHelp) {
        const dMin = this.value || (isPercentage ? '30' : '300');
        fHelp.textContent = isPercentage
            ? <?php echo json_encode(t('admin_srs.scores_below_prefix')); ?> + dMin + <?php echo json_encode(t('admin_srs.scores_below_suffix_pct')); ?>
            : <?php echo json_encode(t('admin_srs.scores_below_prefix')); ?> + dMin + <?php echo json_encode(t('admin_srs.scores_below_suffix')); ?>;
    }
});
</script>

<div class="card">
    <h3>
        <?php echo e(t('admin_srs.tier_schedule_heading')); ?>
        <span data-action="openCronInfoModal" style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #e5e7eb; color: #666; font-size: 12px; margin-left: 8px; vertical-align: middle;" title="<?php echo e(t('admin_srs.cron_setup_instructions_title')); ?>">?</span>
    </h3>
    <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('admin_srs.tier_schedule_desc')); ?></p>
    <form method="POST" action="admin.php?section=srs">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <table>
            <thead>
                <tr>
                    <th><?php echo e(t('admin_srs.col_tier')); ?></th>
                    <th><?php echo e(t('admin_srs.col_rescore_frequency')); ?></th>
                    <th><?php echo e(t('admin_srs.col_description')); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="badge badge-danger"><?php echo e(t('admin_srs.tier1_badge')); ?></span></td>
                    <td>
                        <input type="number" name="upguard_tier1_days" class="form-control" style="width: 100px;" value="<?php echo e($srsConfig['tier1_days'] ?? '30'); ?>" min="1" max="365">
                    </td>
                    <td><?php echo e(t('admin_srs.tier1_desc')); ?></td>
                </tr>
                <tr>
                    <td><span class="badge" style="background: #fef3c7; color: #92400e;"><?php echo e(t('admin_srs.tier2_badge')); ?></span></td>
                    <td>
                        <input type="number" name="upguard_tier2_days" class="form-control" style="width: 100px;" value="<?php echo e($srsConfig['tier2_days'] ?? '90'); ?>" min="1" max="365">
                    </td>
                    <td><?php echo e(t('admin_srs.tier2_desc')); ?></td>
                </tr>
                <tr>
                    <td><span class="badge badge-success"><?php echo e(t('admin_srs.tier3_badge')); ?></span></td>
                    <td>
                        <input type="number" name="upguard_tier3_days" class="form-control" style="width: 100px;" value="<?php echo e($srsConfig['tier3_days'] ?? '365'); ?>" min="1" max="730">
                    </td>
                    <td><?php echo e(t('admin_srs.tier3_desc')); ?></td>
                </tr>
            </tbody>
        </table>

        <h4 style="margin-top: 25px; margin-bottom: 15px;"><?php echo e(t('admin_srs.trend_chart_heading')); ?></h4>
        <div style="display: flex; align-items: center; gap: 15px;">
            <label style="font-weight: 500;"><?php echo e(t('admin_srs.default_trending_period_label')); ?></label>
            <input type="number" name="upguard_trending_days" class="form-control" style="width: 100px;" value="<?php echo e($srsConfig['trending_days'] ?? '90'); ?>" min="7" max="365">
            <span style="color: #666;"><?php echo e(t('admin_srs.days_label')); ?></span>
            <span style="color: #999; font-size: 13px;"><?php echo e(t('admin_srs.trending_shown_note')); ?></span>
        </div>

        <div style="margin-top: 20px;">
            <button type="submit" name="update_tier_schedule" class="btn btn-primary"><?php echo e(t('admin_srs.save_tier_schedule_btn')); ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_srs.about_upguard_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;">
        <?php echo t('admin_srs.about_upguard_body'); ?>
    </p>
    <p style="margin-top: 15px;">
        <a href="https://cyber-risk.upguard.com" target="_blank" style="color: var(--theme-header-color);"><?php echo t('admin_srs.about_upguard_learn_more'); ?></a>
    </p>
</div>

<!-- Cron Setup Info Modal -->
<div id="cronInfoModal" class="modal">
    <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <span class="close" data-action="closeCronInfoModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_srs.cron_modal_heading')); ?></h3>

        <h4 style="margin: 15px 0 10px; font-size: 14px; color: #333;"><?php echo e(t('admin_srs.cron_prerequisites_heading')); ?></h4>
        <ul style="font-size: 13px; color: #666; margin-bottom: 15px; padding-left: 20px;">
            <li><?php echo e(t('admin_srs.cron_prereq_enabled')); ?></li>
            <li><?php echo t('admin_srs.cron_prereq_php_cli'); ?></li>
            <li><?php echo t('admin_srs.cron_prereq_log_dir'); ?></li>
        </ul>

        <h4 style="margin: 20px 0 10px; font-size: 14px; color: #333;"><?php echo e(t('admin_srs.cron_tier_based_heading')); ?></h4>
        <p style="font-size: 13px; color: #666; margin-bottom: 8px;">
            <?php echo e(t('admin_srs.cron_tier_based_desc')); ?>
        </p>
        <div style="background: #1e1e1e; color: #d4d4d4; padding: 12px 15px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto; margin-bottom: 10px;">
            <code style="color: #9cdcfe;">0 * * * *</code> <code style="color: #ce9178;">/usr/bin/php</code> <code style="color: #dcdcaa;"><?php echo e(dirname(dirname(__DIR__))); ?>/cron/srs-rescore.php</code> <code style="color: #6a9955;">>> /var/log/tprm-srs.log 2>&1</code>
        </div>
        <table style="width: 100%; font-size: 12px; margin-bottom: 10px; border-collapse: collapse;">
            <tr><td style="padding: 5px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb; width: 120px;">--batch-size=N</td><td style="padding: 5px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.flag_batch_size')); ?></td></tr>
            <tr><td style="padding: 5px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb;">--dry-run</td><td style="padding: 5px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.flag_dry_run')); ?></td></tr>
            <tr><td style="padding: 5px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb;">--verbose</td><td style="padding: 5px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.flag_verbose')); ?></td></tr>
        </table>

        <h4 style="margin: 20px 0 10px; font-size: 14px; color: #333;"><?php echo t('admin_srs.cron_ondemand_heading'); ?></h4>
        <p style="font-size: 13px; color: #666; margin-bottom: 8px;">
            <?php echo t('admin_srs.cron_ondemand_desc'); ?>
        </p>
        <div style="background: #1e1e1e; color: #d4d4d4; padding: 12px 15px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto; margin-bottom: 10px;">
            <code style="color: #9cdcfe;">* * * * *</code> <code style="color: #ce9178;">/usr/bin/php</code> <code style="color: #dcdcaa;"><?php echo e(dirname(dirname(__DIR__))); ?>/cron/rescore-queue.php</code> <code style="color: #6a9955;">>> /var/log/tprm/rescore-queue.log 2>&1</code>
        </div>
        <p style="font-size: 12px; color: #6b7280; margin-bottom: 10px;">
            <?php echo e(t('admin_srs.cron_ondemand_note')); ?>
        </p>

        <h4 style="margin: 20px 0 10px; font-size: 14px; color: #333;"><?php echo e(t('admin_srs.cron_example_schedules_heading')); ?></h4>
        <table style="width: 100%; font-size: 13px; border-collapse: collapse; margin-bottom: 10px;">
            <tr><td style="padding: 6px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb;">0 * * * *</td><td style="padding: 6px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.schedule_every_hour')); ?></td></tr>
            <tr><td style="padding: 6px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb;">0 */4 * * *</td><td style="padding: 6px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.schedule_every_4h')); ?></td></tr>
            <tr><td style="padding: 6px 8px; background: #f8f9fa; font-family: monospace; border: 1px solid #e5e7eb;">0 2 * * *</td><td style="padding: 6px 8px; border: 1px solid #e5e7eb;"><?php echo e(t('admin_srs.schedule_daily_2am')); ?></td></tr>
        </table>

        <h4 style="margin: 20px 0 10px; font-size: 14px; color: #333;"><?php echo e(t('admin_srs.cron_testing_heading')); ?></h4>
        <div style="background: #1e1e1e; color: #d4d4d4; padding: 12px 15px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
            <code style="color: #6a9955;"># Test tier-based rescoring</code><br>
            <code style="color: #ce9178;">php</code> <code style="color: #dcdcaa;"><?php echo e(dirname(dirname(__DIR__))); ?>/cron/srs-rescore.php</code> <code style="color: #9cdcfe;">--dry-run --verbose</code><br><br>
            <code style="color: #6a9955;"># Test on-demand queue (processes any pending rescores)</code><br>
            <code style="color: #ce9178;">php</code> <code style="color: #dcdcaa;"><?php echo e(dirname(dirname(__DIR__))); ?>/cron/rescore-queue.php</code> <code style="color: #9cdcfe;">--verbose</code>
        </div>

        <h4 style="margin: 20px 0 10px; font-size: 14px; color: #333;"><?php echo e(t('admin_srs.cron_verify_heading')); ?></h4>
        <div style="background: #1e1e1e; color: #d4d4d4; padding: 10px 15px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 10px;">
            <code style="color: #ce9178;">crontab -l</code> <code style="color: #6a9955;">&nbsp;&nbsp;# View registered cron jobs</code><br>
            <code style="color: #ce9178;">tail -f /var/log/tprm-srs.log</code> <code style="color: #6a9955;">&nbsp;&nbsp;# Monitor tier-based log</code><br>
            <code style="color: #ce9178;">tail -f /var/log/tprm-rescore-queue.log</code> <code style="color: #6a9955;">&nbsp;&nbsp;# Monitor queue log</code>
        </div>

        <div style="margin-top: 15px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
            <strong style="color: #92400e;"><?php echo e(t('admin_srs.note_label')); ?></strong>
            <span style="font-size: 13px; color: #92400e;"> <?php echo t('admin_srs.cron_note_body'); ?></span>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button type="button" class="btn btn-secondary" data-action="closeCronInfoModal"><?php echo e(t('admin_srs.close_btn')); ?></button>
        </div>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function openCronInfoModal() { document.getElementById('cronInfoModal').style.display = 'block'; }
function closeCronInfoModal() { document.getElementById('cronInfoModal').style.display = 'none'; }

window.addEventListener('click', function(event) {
    if (event.target == document.getElementById('cronInfoModal')) {
        document.getElementById('cronInfoModal').style.display = 'none';
    }
});
</script>
</div><!-- end srsSection_upguard -->

<!-- ================================================================== -->
<!-- SHODAN TAB -->
<!-- ================================================================== -->
<div id="srsSection_shodan" <?php echo $srsTab !== 'shodan' ? 'style="display: none;"' : ''; ?>>
<?php echo $shodanContent; ?>
</div><!-- end srsSection_shodan -->

<!-- ================================================================== -->
<!-- CUSTOM SCORING TAB -->
<!-- ================================================================== -->
<?php $customScoringEnabled = getAppConfig('custom_scoring_enabled', '0'); ?>
<div id="srsSection_custom" <?php echo $srsTab !== 'custom' ? 'style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_srs.custom_scoring')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('admin_srs.custom_scoring_intro')); ?></p>
    <form method="POST" action="admin.php?section=srs&srs_tab=custom">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="custom_scoring_enabled" value="1" <?php echo $customScoringEnabled === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_srs.enable_custom_scoring_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_srs.enable_custom_scoring_help')); ?></div>
        </div>
        <button type="submit" name="update_custom_scoring" class="btn btn-primary"><?php echo e(t('admin_srs.save_custom_scoring_btn')); ?></button>
    </form>
</div>
<div class="card">
    <h3><?php echo e(t('admin_srs.about_custom_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;">
        <?php echo t('admin_srs.about_custom_body'); ?>
    </p>
</div>
</div><!-- end srsSection_custom -->

<script nonce="<?php echo cspNonce(); ?>">
function showSrsSection(section) {
    var sections = ['upguard', 'shodan', 'custom'];
    sections.forEach(function(s) {
        var el = document.getElementById('srsSection_' + s);
        var tab = document.getElementById('srsTab_' + s);
        if (el) el.style.display = s === section ? '' : 'none';
        if (tab) {
            tab.style.borderBottomColor = s === section ? 'var(--theme-header-color, #2563eb)' : 'transparent';
            tab.style.color = s === section ? 'var(--theme-header-color, #2563eb)' : '#6b7280';
            tab.style.fontWeight = s === section ? '600' : '500';
        }
    });
}
</script>
