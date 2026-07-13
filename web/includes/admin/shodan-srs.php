<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Shodan SRS Integration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Configuration page for the Shodan internet intelligence integration.
 * Lets admins enable/disable Shodan, enter their API key, tweak grade
 * thresholds, and test the connection. Same pattern as the UpGuard SRS
 * settings page (includes/admin/srs.php) because consistency is king.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handlers: update_shodan, test_shodan_connection
// ============================================================================
$_shodanPostAction = isset($_POST['update_shodan']) || isset($_POST['update_shodan_signals']) || isset($_POST['reset_shodan_signals']) || isset($_POST['update_shodan_weights']) || isset($_POST['reset_shodan_weights']) || isset($_POST['test_shodan_connection']) || isset($_POST['remove_waiver']) || isset($_POST['remove_all_waivers']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_shodanPostAction) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shodan-srs.error_invalid_request');
    } elseif (isset($_POST['update_shodan'])) {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            $shodanSettings = [
                'shodan_enabled' => isset($_POST['shodan_enabled']) ? '1' : '0',
                'shodan_display_name' => trim($_POST['shodan_display_name'] ?? '') ?: 'Shodan',
                'shodan_scoring_method' => in_array($_POST['shodan_scoring_method'] ?? 'range', ['range', 'percentage']) ? $_POST['shodan_scoring_method'] : 'range',
                'shodan_max_score' => max(1, intval($_POST['shodan_max_score'] ?? 100)),
                'shodan_grade_a_min' => max(0, intval($_POST['shodan_grade_a_min'] ?? 90)),
                'shodan_grade_b_min' => max(0, intval($_POST['shodan_grade_b_min'] ?? 75)),
                'shodan_grade_c_min' => max(0, intval($_POST['shodan_grade_c_min'] ?? 60)),
                'shodan_grade_d_min' => max(0, intval($_POST['shodan_grade_d_min'] ?? 40)),
                'shodan_max_subdomains' => max(1, min(1000, intval($_POST['shodan_max_subdomains'] ?? 20))),
                'shodan_randomize_subdomains' => isset($_POST['shodan_randomize_subdomains']) ? '1' : '0',
                'shodan_excluded_domains' => trim($_POST['shodan_excluded_domains'] ?? ''),
                'shodan_use_cron' => isset($_POST['shodan_use_cron']) ? '1' : '0',
                'shodan_on_demand_scan' => isset($_POST['shodan_on_demand_scan']) ? '1' : '0',
                'shodan_verify_open_ports' => isset($_POST['shodan_verify_open_ports']) ? '1' : '0',
                'shodan_verify_attribution' => isset($_POST['shodan_verify_attribution']) ? '1' : '0',
                'shodan_min_cve_year' => max(0, intval($_POST['shodan_min_cve_year'] ?? 0)),
                'shodan_banner_max_age' => max(0, intval($_POST['shodan_banner_max_age'] ?? 7)),
                'shodan_rescan_cooldown_hours' => max(0, intval($_POST['shodan_rescan_cooldown_hours'] ?? 24))
            ];

            $apiKey = trim($_POST['shodan_api_key'] ?? '');
            if (!empty($apiKey)) {
                $shodanSettings['shodan_api_key'] = $encryption->encrypt($apiKey);
            } else {
                $existingKey = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'shodan_api_key']
                );
                if ($existingKey) {
                    $shodanSettings['shodan_api_key'] = $existingKey['config_value'];
                }
            }

            foreach ($shodanSettings as $key => $value) {
                $isEncrypted = ($key === 'shodan_api_key') ? 1 : 0;

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

            $db->commit();
            $auditShodan = $shodanSettings;
            unset($auditShodan['shodan_api_key']);
            $auth->audit($user['id'], 'config_update_shodan', 'app_config', null, [
                'new' => $auditShodan
            ]);
            $success = t('admin_shodan-srs.success_config_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating Shodan config: ' . $e->getMessage());
            $error = t('admin_shodan-srs.error_config_update_failed');
        }
    } elseif (isset($_POST['update_shodan_signals'])) {
        try {
            require_once __DIR__ . '/../classes/ShodanClient.php';
            $defaults = ShodanClient::DEFAULT_SIGNAL_POINTS;
            $customPoints = [];

            foreach ($defaults as $category => $signals) {
                foreach ($signals as $signal => $defaultVal) {
                    $fieldName = 'sp_' . $category . '_' . $signal;
                    if (isset($_POST[$fieldName]) && $_POST[$fieldName] !== '') {
                        $val = intval($_POST[$fieldName]);
                        if ($val !== $defaultVal) {
                            $customPoints[$category][$signal] = $val;
                        }
                    }
                }
            }

            $jsonValue = !empty($customPoints) ? json_encode($customPoints) : '';

            $existing = $db->fetchOne(
                'SELECT id FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_signal_points']
            );

            if ($existing) {
                $db->update(
                    'app_config',
                    ['config_value' => $jsonValue, 'is_encrypted' => 0],
                    'config_key = :key',
                    [':key' => 'shodan_signal_points']
                );
            } else {
                $db->insert('app_config', [
                    'config_key' => 'shodan_signal_points',
                    'config_value' => $jsonValue,
                    'is_encrypted' => 0
                ]);
            }

            $success = t('admin_shodan-srs.success_signals_updated');
        } catch (Exception $e) {
            error_log('Error updating Shodan signal points: ' . $e->getMessage());
            $error = t('admin_shodan-srs.error_signals_update_failed');
        }
    } elseif (isset($_POST['reset_shodan_signals'])) {
        try {
            $existing = $db->fetchOne(
                'SELECT id FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_signal_points']
            );

            if ($existing) {
                $db->update(
                    'app_config',
                    ['config_value' => '', 'is_encrypted' => 0],
                    'config_key = :key',
                    [':key' => 'shodan_signal_points']
                );
            }

            $success = t('admin_shodan-srs.success_signals_reset');
        } catch (Exception $e) {
            error_log('Error resetting Shodan signal points: ' . $e->getMessage());
            $error = t('admin_shodan-srs.error_signals_reset_failed');
        }
    } elseif (isset($_POST['update_shodan_weights'])) {
        try {
            require_once __DIR__ . '/../classes/ShodanClient.php';
            $defaults = ShodanClient::DEFAULT_CATEGORY_WEIGHTS;
            $customWeights = [];

            $totalWeight = 0;
            foreach ($defaults as $category => $defaultWeight) {
                $fieldName = 'cw_' . $category;
                $val = isset($_POST[$fieldName]) && $_POST[$fieldName] !== '' ? intval($_POST[$fieldName]) : $defaultWeight;
                $val = max(0, min(100, $val));
                $totalWeight += $val;
                if ($val !== $defaultWeight) {
                    $customWeights[$category] = $val;
                }
            }

            if ($totalWeight === 0) {
                $error = t('admin_shodan-srs.error_weights_all_zero');
            } else {
                $jsonValue = !empty($customWeights) ? json_encode($customWeights) : '';

                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => 'shodan_category_weights']
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        ['config_value' => $jsonValue, 'is_encrypted' => 0],
                        'config_key = :key',
                        [':key' => 'shodan_category_weights']
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => 'shodan_category_weights',
                        'config_value' => $jsonValue,
                        'is_encrypted' => 0
                    ]);
                }

                $success = t('admin_shodan-srs.success_weights_updated_prefix') . $totalWeight . t('admin_shodan-srs.success_weights_updated_suffix');
            }
        } catch (Exception $e) {
            error_log('Error updating Shodan category weights: ' . $e->getMessage());
            $error = t('admin_shodan-srs.error_weights_update_failed');
        }
    } elseif (isset($_POST['reset_shodan_weights'])) {
        try {
            $existing = $db->fetchOne(
                'SELECT id FROM app_config WHERE config_key = :key',
                [':key' => 'shodan_category_weights']
            );

            if ($existing) {
                $db->update(
                    'app_config',
                    ['config_value' => '', 'is_encrypted' => 0],
                    'config_key = :key',
                    [':key' => 'shodan_category_weights']
                );
            }

            $success = t('admin_shodan-srs.success_weights_reset');
        } catch (Exception $e) {
            error_log('Error resetting Shodan category weights: ' . $e->getMessage());
            $error = t('admin_shodan-srs.error_weights_reset_failed');
        }
    } elseif (isset($_POST['test_shodan_connection'])) {
        try {
            require_once __DIR__ . '/../classes/ShodanService.php';
            $shodanService = new ShodanService();
            $result = $shodanService->testConnection();
            if ($result['success']) {
                $success = t('admin_shodan-srs.connection_test_prefix') . $result['message'];
            } else {
                $error = t('admin_shodan-srs.connection_test_failed_prefix') . $result['message'];
            }
        } catch (Exception $e) {
            error_log('Shodan Connection Test Error: ' . $e->getMessage());
            $error = t('admin_shodan-srs.connection_test_error');
        }
    } elseif (isset($_POST['remove_waiver'])) {
        $waiverId = intval($_POST['waiver_id'] ?? 0);
        if ($waiverId > 0) {
            try {
                require_once __DIR__ . '/../classes/ShodanService.php';
                $shodanService = new ShodanService();
                if ($shodanService->removeWaiver($waiverId)) {
                    $success = t('admin_shodan-srs.success_waiver_removed');
                } else {
                    $error = t('admin_shodan-srs.error_waiver_remove_failed');
                }
            } catch (Exception $e) {
                error_log('Error removing waiver: ' . $e->getMessage());
                $error = t('admin_shodan-srs.error_waiver_remove');
            }
        }
    } elseif (isset($_POST['remove_all_waivers'])) {
        $vendorId = intval($_POST['vendor_id'] ?? 0);
        if ($vendorId > 0) {
            try {
                require_once __DIR__ . '/../classes/ShodanService.php';
                $shodanService = new ShodanService();
                $removed = $shodanService->removeAllWaiversForVendor($vendorId);
                if ($removed !== false) {
                    $success = t('admin_shodan-srs.success_waivers_removed_prefix') . ' ' . $removed . ' ' . ($removed !== 1 ? t('admin_shodan-srs.word_waivers') : t('admin_shodan-srs.word_waiver')) . ' ' . t('admin_shodan-srs.success_waivers_removed_suffix');
                } else {
                    $error = t('admin_shodan-srs.error_waivers_remove_failed');
                }
            } catch (Exception $e) {
                error_log('Error removing all waivers: ' . $e->getMessage());
                $error = t('admin_shodan-srs.error_waivers_remove');
            }
        }
    }
}

// ============================================================================
// Data Loading: Shodan configuration
// ============================================================================
$shodanConfig = [];
$encryption = new Encryption();
$shodanRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?', ['shodan_%']);
foreach ($shodanRows as $row) {
    $key = str_replace('shodan_', '', $row['config_key']);
    if ($row['is_encrypted'] && !empty($row['config_value'])) {
        $shodanConfig[$key] = $encryption->decrypt($row['config_value']);
    } else {
        $shodanConfig[$key] = $row['config_value'];
    }
}
if (!isset($shodanConfig['enabled'])) $shodanConfig['enabled'] = '0';
if (!isset($shodanConfig['api_key'])) $shodanConfig['api_key'] = '';

// Load signal points configuration
require_once __DIR__ . '/../classes/ShodanClient.php';
$signalDefaults = ShodanClient::DEFAULT_SIGNAL_POINTS;
$signalLabels = ShodanClient::SIGNAL_LABELS;

// Load custom overrides from app_config
$signalCustom = [];
$spRow = $db->fetchOne(
    'SELECT config_value FROM app_config WHERE config_key = :key',
    [':key' => 'shodan_signal_points']
);
if ($spRow && !empty($spRow['config_value'])) {
    $decoded = json_decode($spRow['config_value'], true);
    if (is_array($decoded)) {
        $signalCustom = $decoded;
    }
}

// Merge: custom overrides on top of defaults
$signalCurrent = $signalDefaults;
foreach ($signalCustom as $cat => $signals) {
    if (isset($signalCurrent[$cat]) && is_array($signals)) {
        foreach ($signals as $sig => $val) {
            if (array_key_exists($sig, $signalCurrent[$cat])) {
                $signalCurrent[$cat][$sig] = (int)$val;
            }
        }
    }
}

$hasCustomSignals = !empty($signalCustom);

// Load category weights configuration
$weightDefaults = ShodanClient::DEFAULT_CATEGORY_WEIGHTS;
$weightCatLabels = ShodanClient::CATEGORY_LABELS;
$weightCustom = [];
$cwRow = $db->fetchOne(
    'SELECT config_value FROM app_config WHERE config_key = :key',
    [':key' => 'shodan_category_weights']
);
if ($cwRow && !empty($cwRow['config_value'])) {
    $decoded = json_decode($cwRow['config_value'], true);
    if (is_array($decoded)) {
        $weightCustom = $decoded;
    }
}

$weightCurrent = $weightDefaults;
foreach ($weightCustom as $cat => $val) {
    if (array_key_exists($cat, $weightCurrent)) {
        $weightCurrent[$cat] = (int)$val;
    }
}
$hasCustomWeights = !empty($weightCustom);

// Build category labels with current weight percentages
$categoryLabels = [];
foreach ($weightCurrent as $cat => $weight) {
    $categoryLabels[$cat] = ($weightCatLabels[$cat] ?? ucwords(str_replace('_', ' ', $cat))) . ' (' . $weight . '%)';
}

// Load all active waivers for the management section
require_once __DIR__ . '/../classes/ShodanService.php';
$waiverService = new ShodanService();
$allWaivers = $waiverService->getAllWaivers();

// Group waivers by vendor
$waiversByVendor = [];
foreach ($allWaivers as $w) {
    $vendorKey = $w['vendor_onboarding_id'];
    if (!isset($waiversByVendor[$vendorKey])) {
        $waiversByVendor[$vendorKey] = [
            'vendor_name' => $w['vendor_name'],
            'vendor_domain' => $w['vendor_domain'],
            'waivers' => []
        ];
    }
    $waiversByVendor[$vendorKey]['waivers'][] = $w;
}

// ============================================================================
// HTML: Shodan Settings Form
// ============================================================================
$shodanSubTab = $_GET['shodan_tab'] ?? 'settings';
if (!in_array($shodanSubTab, ['settings', 'weights', 'signals', 'waivers', 'about'], true)) {
    $shodanSubTab = 'settings';
}
?>
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <?php foreach (['settings' => t('admin_shodan-srs.tab_settings'), 'weights' => t('admin_shodan-srs.tab_weights'), 'signals' => t('admin_shodan-srs.tab_signals'), 'waivers' => t('admin_shodan-srs.tab_waivers'), 'about' => t('admin_shodan-srs.tab_about')] as $_stKey => $_stLabel): ?>
    <button type="button" data-action="showShodanSection" data-arg="<?php echo $_stKey; ?>" id="shodanTab_<?php echo $_stKey; ?>" class="shodan-section-tab" style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shodanSubTab === $_stKey ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shodanSubTab === $_stKey ? '600' : '500'; ?>; color: <?php echo $shodanSubTab === $_stKey ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e($_stLabel); ?><?php if ($_stKey === 'waivers' && !empty($allWaivers)): ?> <span style="background: #e0e7ff; color: #3730a3; padding: 1px 6px; border-radius: 8px; font-size: 10px; font-weight: 600; margin-left: 4px;"><?php echo count($allWaivers); ?></span><?php endif; ?></button>
    <?php endforeach; ?>
</div>

<div id="shodanSection_settings"<?php echo $shodanSubTab !== 'settings' ? ' style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_shodan-srs.settings_heading')); ?></h3>
    <form method="POST" action="admin.php?section=srs&amp;srs_tab=shodan">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="shodan_enabled" value="1" <?php echo ($shodanConfig['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shodan-srs.enable_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shodan-srs.enable_help')); ?></div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="shodan_use_cron" value="1" <?php echo ($shodanConfig['use_cron'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shodan-srs.use_cron_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shodan-srs.use_cron_help')); ?></div>
            <div id="shodan_cron_setup" style="<?php echo ($shodanConfig['use_cron'] ?? '0') === '1' ? '' : 'display: none;'; ?> margin-top: 10px; padding: 12px 16px; background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px;">
                <div style="font-weight: 600; font-size: 13px; color: #374151; margin-bottom: 6px;"><?php echo e(t('admin_shodan-srs.cron_required_heading')); ?></div>
                <p style="font-size: 12px; color: #6b7280; margin: 0 0 8px 0;"><?php echo e(t('admin_shodan-srs.cron_required_intro')); ?></p>
                <div style="background: #1e1e1e; color: #d4d4d4; padding: 8px 12px; border-radius: 4px; font-family: monospace; font-size: 11px; overflow-x: auto;">* * * * * /usr/bin/php <?php echo e(dirname(dirname(__DIR__))); ?>/cron/rescore-queue.php >> /var/log/tprm/rescore-queue.log 2>&1</div>
                <p style="font-size: 11px; color: #9ca3af; margin: 6px 0 0 0;"><?php echo e(t('admin_shodan-srs.cron_required_note')); ?></p>
            </div>
        </div>
        <script nonce="<?php echo cspNonce(); ?>">
        document.querySelector('input[name="shodan_use_cron"]').addEventListener('change', function() {
            document.getElementById('shodan_cron_setup').style.display = this.checked ? '' : 'none';
        });
        </script>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="shodan_on_demand_scan" value="1" <?php echo ($shodanConfig['on_demand_scan'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shodan-srs.on_demand_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shodan-srs.on_demand_help')); ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="shodan_api_key"><?php echo e(t('admin_shodan-srs.api_key_label')); ?></label>
                <input type="password" id="shodan_api_key" name="shodan_api_key" class="form-control" placeholder="<?php echo !empty($shodanConfig['api_key']) ? '••••••••••••••••' : e(t('admin_shodan-srs.api_key_placeholder')); ?>">
                <div class="form-help"><?php echo t('admin_shodan-srs.api_key_help'); ?></div>
            </div>
            <div class="form-group">
                <label for="shodan_display_name"><?php echo e(t('admin_shodan-srs.display_name_label')); ?></label>
                <input type="text" id="shodan_display_name" name="shodan_display_name" class="form-control" value="<?php echo e($shodanConfig['display_name'] ?? 'Shodan'); ?>" placeholder="Shodan" maxlength="50">
                <div class="form-help"><?php echo e(t('admin_shodan-srs.display_name_help')); ?></div>
            </div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;"><?php echo e(t('admin_shodan-srs.subdomain_scanning_heading')); ?></h4>
        <div class="form-row">
            <div class="form-group">
                <label for="shodan_max_subdomains"><?php echo e(t('admin_shodan-srs.max_subdomains_label')); ?></label>
                <input type="number" id="shodan_max_subdomains" name="shodan_max_subdomains" class="form-control" value="<?php echo e($shodanConfig['max_subdomains'] ?? '20'); ?>" min="1" max="1000">
                <div class="form-help"><?php echo e(t('admin_shodan-srs.max_subdomains_help')); ?></div>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_shodan-srs.subdomain_selection_label')); ?></label>
                <label class="checkbox-label" style="margin-top: 8px;">
                    <input type="checkbox" name="shodan_randomize_subdomains" value="1" <?php echo ($shodanConfig['randomize_subdomains'] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <span><?php echo e(t('admin_shodan-srs.randomize_subdomains_label')); ?></span>
                </label>
                <div class="form-help"><?php echo e(t('admin_shodan-srs.randomize_subdomains_help')); ?></div>
            </div>
        </div>
        <div class="form-group" style="margin-top: 15px;">
            <label for="shodan_excluded_domains"><?php echo e(t('admin_shodan-srs.excluded_domains_label')); ?></label>
            <textarea id="shodan_excluded_domains" name="shodan_excluded_domains" class="form-control" rows="4" placeholder="dev.example.com&#10;staging.example.com&#10;internal.example.com" style="font-family: monospace; font-size: 13px;"><?php echo e($shodanConfig['excluded_domains'] ?? ''); ?></textarea>
            <div class="form-help"><?php echo e(t('admin_shodan-srs.excluded_domains_help')); ?></div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;"><?php echo e(t('admin_shodan-srs.cve_filtering_heading')); ?></h4>
        <div class="form-group">
            <label for="shodan_min_cve_year"><?php echo e(t('admin_shodan-srs.min_cve_year_label')); ?></label>
            <input type="number" id="shodan_min_cve_year" name="shodan_min_cve_year" class="form-control" value="<?php echo e($shodanConfig['min_cve_year'] ?? '0'); ?>" min="0" max="2099" placeholder="<?php echo e(t('admin_shodan-srs.min_cve_year_placeholder_prefix')); ?><?php echo date('Y') - 5; ?><?php echo e(t('admin_shodan-srs.min_cve_year_placeholder_suffix')); ?>" style="max-width: 200px;">
            <div class="form-help"><?php echo e(t('admin_shodan-srs.min_cve_year_help_prefix')); ?><?php echo date('Y') - 5; ?><?php echo e(t('admin_shodan-srs.min_cve_year_help_suffix')); ?></div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;"><?php echo e(t('admin_shodan-srs.data_freshness_heading')); ?></h4>
        <div class="form-group">
            <label for="shodan_banner_max_age"><?php echo e(t('admin_shodan-srs.banner_max_age_label')); ?></label>
            <input type="number" id="shodan_banner_max_age" name="shodan_banner_max_age" class="form-control" value="<?php echo e($shodanConfig['banner_max_age'] ?? '7'); ?>" min="0" max="365" style="max-width: 200px;">
            <div class="form-help"><?php echo e(t('admin_shodan-srs.banner_max_age_help')); ?></div>
        </div>
        <div class="form-group">
            <label for="shodan_rescan_cooldown_hours"><?php echo e(t('admin_shodan-srs.rescan_cooldown_label')); ?></label>
            <input type="number" id="shodan_rescan_cooldown_hours" name="shodan_rescan_cooldown_hours" class="form-control" value="<?php echo e($shodanConfig['rescan_cooldown_hours'] ?? '24'); ?>" min="0" max="720" style="max-width: 200px;">
            <div class="form-help"><?php echo e(t('admin_shodan-srs.rescan_cooldown_help')); ?></div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;">Scan Accuracy</h4>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="shodan_verify_open_ports" value="1" <?php echo ($shodanConfig['verify_open_ports'] ?? '1') === '1' ? 'checked' : ''; ?>>
                <span>Actively verify open ports (TCP connect)</span>
            </label>
            <div class="form-help">Confirms each Shodan-reported port with a real TCP connection and drops any that are actually filtered/closed, removing false-positive "exposed port" findings. Requires outbound TCP egress from this server. Leave enabled unless this deployment cannot make outbound connections.</div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="shodan_verify_attribution" value="1" <?php echo ($shodanConfig['verify_attribution'] ?? '1') === '1' ? 'checked' : ''; ?>>
                <span>Cross-check host attribution against live DNS</span>
            </label>
            <div class="form-help">Drops findings on IPs that Shodan attributes to a host but that live authoritative DNS no longer resolves to (stale/cached attributions), reducing misattributed findings.</div>
        </div>

        <h4 style="margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px solid #e5e7eb;"><?php echo e(t('admin_shodan-srs.grade_scoring_heading')); ?></h4>
        <div class="form-row">
            <div class="form-group">
                <label for="shodan_scoring_method"><?php echo e(t('admin_shodan-srs.scoring_method_label')); ?></label>
                <select id="shodan_scoring_method" name="shodan_scoring_method" class="form-control" data-action="toggleShodanScoringMethod">
                    <option value="range" <?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'range' ? 'selected' : ''; ?>><?php echo e(t('admin_shodan-srs.scoring_method_range_option')); ?></option>
                    <option value="percentage" <?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? 'selected' : ''; ?>><?php echo e(t('admin_shodan-srs.scoring_method_percentage_option')); ?></option>
                </select>
                <div class="form-help" id="shodan_scoring_method_help"><?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.scoring_help_percentage')) : e(t('admin_shodan-srs.scoring_help_range')); ?></div>
            </div>
            <div class="form-group" id="shodan_max_score_group" style="<?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? '' : 'display: none;'; ?>">
                <label for="shodan_max_score"><?php echo e(t('admin_shodan-srs.max_score_label')); ?></label>
                <input type="number" id="shodan_max_score" name="shodan_max_score" class="form-control" value="<?php echo e($shodanConfig['max_score'] ?? '100'); ?>" min="1" max="10000">
                <div class="form-help"><?php echo e(t('admin_shodan-srs.max_score_help')); ?></div>
            </div>
        </div>

        <div id="shodan_grade_thresholds_label" style="font-weight: 500; margin-bottom: 10px; color: #374151;">
            <?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.thresholds_label_percentage')) : e(t('admin_shodan-srs.thresholds_label_score')); ?>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group">
                <label for="shodan_grade_a_min" id="shodan_grade_a_label"><?php echo e(t('admin_shodan-srs.grade_prefix')); ?>A<?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.grade_minimum_pct_suffix')) : e(t('admin_shodan-srs.grade_minimum_score_suffix')); ?></label>
                <input type="number" id="shodan_grade_a_min" name="shodan_grade_a_min" class="form-control" value="<?php echo e($shodanConfig['grade_a_min'] ?? '90'); ?>" min="0" placeholder="90">
            </div>
            <div class="form-group">
                <label for="shodan_grade_b_min" id="shodan_grade_b_label"><?php echo e(t('admin_shodan-srs.grade_prefix')); ?>B<?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.grade_minimum_pct_suffix')) : e(t('admin_shodan-srs.grade_minimum_score_suffix')); ?></label>
                <input type="number" id="shodan_grade_b_min" name="shodan_grade_b_min" class="form-control" value="<?php echo e($shodanConfig['grade_b_min'] ?? '75'); ?>" min="0" placeholder="75">
            </div>
            <div class="form-group">
                <label for="shodan_grade_c_min" id="shodan_grade_c_label"><?php echo e(t('admin_shodan-srs.grade_prefix')); ?>C<?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.grade_minimum_pct_suffix')) : e(t('admin_shodan-srs.grade_minimum_score_suffix')); ?></label>
                <input type="number" id="shodan_grade_c_min" name="shodan_grade_c_min" class="form-control" value="<?php echo e($shodanConfig['grade_c_min'] ?? '60'); ?>" min="0" placeholder="60">
            </div>
            <div class="form-group">
                <label for="shodan_grade_d_min" id="shodan_grade_d_label"><?php echo e(t('admin_shodan-srs.grade_prefix')); ?>D<?php echo ($shodanConfig['scoring_method'] ?? 'range') === 'percentage' ? e(t('admin_shodan-srs.grade_minimum_pct_suffix')) : e(t('admin_shodan-srs.grade_minimum_score_suffix')); ?></label>
                <input type="number" id="shodan_grade_d_min" name="shodan_grade_d_min" class="form-control" value="<?php echo e($shodanConfig['grade_d_min'] ?? '40'); ?>" min="0" placeholder="40">
            </div>
        </div>
        <div class="form-help" id="shodan_grade_f_help" style="margin-top: 5px; color: #666;"><?php echo e(t('admin_shodan-srs.grade_f_prefix')); ?><?php echo e($shodanConfig['grade_d_min'] ?? '40'); ?><?php echo e(t('admin_shodan-srs.grade_f_suffix')); ?></div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button type="submit" name="update_shodan" class="btn btn-primary"><?php echo e(t('admin_shodan-srs.save_config_button')); ?></button>
            <button type="submit" name="test_shodan_connection" class="btn btn-secondary"><?php echo e(t('admin_shodan-srs.test_connection_button')); ?></button>
        </div>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function toggleShodanScoringMethod() {
    const method = document.getElementById('shodan_scoring_method').value;
    const maxScoreGroup = document.getElementById('shodan_max_score_group');
    const isPercentage = method === 'percentage';

    maxScoreGroup.style.display = isPercentage ? '' : 'none';

    const methodHelp = document.getElementById('shodan_scoring_method_help');
    if (methodHelp) {
        methodHelp.textContent = isPercentage
            ? <?php echo json_encode(t('admin_shodan-srs.scoring_help_percentage')); ?>
            : <?php echo json_encode(t('admin_shodan-srs.scoring_help_range')); ?>;
    }

    const thresholdsLabel = document.getElementById('shodan_grade_thresholds_label');
    if (thresholdsLabel) {
        thresholdsLabel.textContent = isPercentage ? <?php echo json_encode(t('admin_shodan-srs.thresholds_label_percentage')); ?> : <?php echo json_encode(t('admin_shodan-srs.thresholds_label_score')); ?>;
    }

    const gradeDefaults = { a: 90, b: 75, c: 60, d: 40 };

    ['a', 'b', 'c', 'd'].forEach(grade => {
        const label = document.getElementById('shodan_grade_' + grade + '_label');
        const input = document.getElementById('shodan_grade_' + grade + '_min');

        if (label) {
            label.textContent = isPercentage
                ? <?php echo json_encode(t('admin_shodan-srs.grade_prefix')); ?> + grade.toUpperCase() + <?php echo json_encode(t('admin_shodan-srs.grade_minimum_pct_suffix')); ?>
                : <?php echo json_encode(t('admin_shodan-srs.grade_prefix')); ?> + grade.toUpperCase() + <?php echo json_encode(t('admin_shodan-srs.grade_minimum_score_suffix')); ?>;
        }
        if (input) {
            input.placeholder = gradeDefaults[grade];
        }
    });

    const fHelp = document.getElementById('shodan_grade_f_help');
    const dMinInput = document.getElementById('shodan_grade_d_min');
    if (fHelp && dMinInput) {
        const dMin = dMinInput.value || '40';
        fHelp.textContent = <?php echo json_encode(t('admin_shodan-srs.grade_f_prefix')); ?> + dMin + (isPercentage ? '% ' : ' ') + <?php echo json_encode(t('admin_shodan-srs.grade_f_suffix_js')); ?>;
    }
}

document.getElementById('shodan_grade_d_min')?.addEventListener('input', function() {
    const method = document.getElementById('shodan_scoring_method').value;
    const isPercentage = method === 'percentage';
    const fHelp = document.getElementById('shodan_grade_f_help');
    if (fHelp) {
        const dMin = this.value || '40';
        fHelp.textContent = <?php echo json_encode(t('admin_shodan-srs.grade_f_prefix')); ?> + dMin + (isPercentage ? '% ' : ' ') + <?php echo json_encode(t('admin_shodan-srs.grade_f_suffix_js')); ?>;
    }
});
</script>
</div><!-- end shodanSection_settings -->

<div id="shodanSection_weights"<?php echo $shodanSubTab !== 'weights' ? ' style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_shodan-srs.weights_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo e(t('admin_shodan-srs.weights_intro')); ?>
        <?php if ($hasCustomWeights): ?>
            <span style="color: #b45309; font-weight: 500;"><?php echo e(t('admin_shodan-srs.weights_custom_active')); ?></span>
        <?php endif; ?>
    </p>
    <form method="POST" action="admin.php?section=srs&amp;srs_tab=shodan">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <table style="width: 100%; max-width: 600px; border-collapse: collapse; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e5e7eb;">
                    <th style="text-align: left; padding: 8px 10px; color: #6b7280; font-weight: 500;"><?php echo e(t('admin_shodan-srs.th_category')); ?></th>
                    <th style="text-align: center; padding: 8px 10px; color: #6b7280; font-weight: 500; width: 80px;"><?php echo e(t('admin_shodan-srs.th_default')); ?></th>
                    <th style="text-align: center; padding: 8px 10px; color: #6b7280; font-weight: 500; width: 110px;"><?php echo e(t('admin_shodan-srs.th_weight_pct')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($weightDefaults as $cat => $defaultWeight):
                $currentWeight = $weightCurrent[$cat] ?? $defaultWeight;
                $isCustomWeight = isset($weightCustom[$cat]);
            ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 8px 10px; color: #374151; font-weight: 500;"><?php echo e($weightCatLabels[$cat] ?? $cat); ?></td>
                    <td style="text-align: center; padding: 8px 10px; color: #9ca3af; font-size: 13px;"><?php echo $defaultWeight; ?>%</td>
                    <td style="text-align: center; padding: 8px 6px;">
                        <input type="number" name="cw_<?php echo e($cat); ?>" value="<?php echo $currentWeight; ?>"
                            min="0" max="100" step="1"
                            style="width: 80px; padding: 5px 8px; border: 1px solid <?php echo $isCustomWeight ? '#f59e0b' : '#d1d5db'; ?>; border-radius: 4px; text-align: center; font-size: 14px; <?php echo $isCustomWeight ? 'background: #fffbeb;' : ''; ?>"
                            data-action="updateWeightTotal">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top: 2px solid #e5e7eb;">
                    <td style="padding: 8px 10px; font-weight: 600; color: #374151;"><?php echo e(t('admin_shodan-srs.th_total')); ?></td>
                    <td style="text-align: center; padding: 8px 10px; color: #9ca3af; font-size: 13px;">100%</td>
                    <td style="text-align: center; padding: 8px 10px;">
                        <span id="weightTotal" style="font-weight: 700; font-size: 14px;"><?php echo array_sum($weightCurrent); ?></span><span style="color: #6b7280;">%</span>
                    </td>
                </tr>
            </tfoot>
        </table>
        <p id="weightWarning" style="display: none; margin-top: 8px; padding: 8px 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px; font-size: 13px; color: #92400e;">
            <?php echo e(t('admin_shodan-srs.weights_warning')); ?>
        </p>

        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button type="submit" name="update_shodan_weights" class="btn btn-primary"><?php echo e(t('admin_shodan-srs.save_weights_button')); ?></button>
            <button type="submit" name="reset_shodan_weights" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shodan-srs.reset_weights_confirm')); ?>"><?php echo e(t('admin_shodan-srs.reset_to_defaults_button')); ?></button>
        </div>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function updateWeightTotal() {
    const categories = <?php echo json_encode(array_keys($weightDefaults)); ?>;
    let total = 0;
    categories.forEach(cat => {
        const input = document.querySelector('input[name="cw_' + cat + '"]');
        if (input) total += parseInt(input.value) || 0;
    });
    const totalEl = document.getElementById('weightTotal');
    const warningEl = document.getElementById('weightWarning');
    if (totalEl) {
        totalEl.textContent = total;
        totalEl.style.color = total === 100 ? '#16a34a' : (total === 0 ? '#dc2626' : '#b45309');
    }
    if (warningEl) {
        warningEl.style.display = (total !== 100 && total > 0) ? 'block' : 'none';
    }
}
updateWeightTotal();
</script>
</div><!-- end shodanSection_weights -->

<div id="shodanSection_signals"<?php echo $shodanSubTab !== 'signals' ? ' style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_shodan-srs.signals_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo e(t('admin_shodan-srs.signals_intro')); ?>
        <?php if ($hasCustomSignals): ?>
            <span style="color: #b45309; font-weight: 500;"><?php echo e(t('admin_shodan-srs.signals_custom_active')); ?></span>
        <?php endif; ?>
    </p>
    <form method="POST" action="admin.php?section=srs&amp;srs_tab=shodan">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <?php foreach ($signalDefaults as $category => $signals): ?>
        <div class="shodan-signal-category" style="margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleSignalCategory" data-arg="<?php echo $category; ?>" style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: #374151;"><?php echo e($categoryLabels[$category] ?? $category); ?></span>
                <span id="arrow_<?php echo $category; ?>" style="color: #9ca3af; transition: transform 0.2s;">&#9660;</span>
            </div>
            <div id="signals_<?php echo $category; ?>" style="display: none; padding: 12px 16px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e5e7eb;">
                            <th style="text-align: left; padding: 6px 8px; color: #6b7280; font-weight: 500;"><?php echo e(t('admin_shodan-srs.th_signal')); ?></th>
                            <th style="text-align: center; padding: 6px 8px; color: #6b7280; font-weight: 500; width: 60px;"><?php echo e(t('admin_shodan-srs.th_type')); ?></th>
                            <th style="text-align: center; padding: 6px 8px; color: #6b7280; font-weight: 500; width: 70px;"><?php echo e(t('admin_shodan-srs.th_default')); ?></th>
                            <th style="text-align: center; padding: 6px 8px; color: #6b7280; font-weight: 500; width: 90px;"><?php echo e(t('admin_shodan-srs.th_points')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($signals as $signal => $defaultVal):
                        $currentVal = $signalCurrent[$category][$signal] ?? $defaultVal;
                        $isCustom = isset($signalCustom[$category][$signal]);
                        $isPositive = $defaultVal > 0;
                        $fieldName = 'sp_' . $category . '_' . $signal;
                        $label = $signalLabels[$category][$signal] ?? $signal;
                    ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 6px 8px; color: #374151;"><?php echo e($label); ?></td>
                            <td style="text-align: center; padding: 6px 8px;">
                                <span style="display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; <?php echo $isPositive ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                    <?php echo $isPositive ? '+' : '−'; ?>
                                </span>
                            </td>
                            <td style="text-align: center; padding: 6px 8px; color: #9ca3af; font-size: 12px;"><?php echo $defaultVal > 0 ? '+' . $defaultVal : $defaultVal; ?></td>
                            <td style="text-align: center; padding: 6px 4px;">
                                <input type="number" name="<?php echo e($fieldName); ?>" value="<?php echo $currentVal; ?>"
                                    style="width: 70px; padding: 3px 6px; border: 1px solid <?php echo $isCustom ? '#f59e0b' : '#d1d5db'; ?>; border-radius: 4px; text-align: center; font-size: 13px; <?php echo $isCustom ? 'background: #fffbeb;' : ''; ?>"
                                    <?php echo $isPositive ? 'min="0" max="50"' : 'max="0" min="-50"'; ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button type="submit" name="update_shodan_signals" class="btn btn-primary"><?php echo e(t('admin_shodan-srs.save_signals_button')); ?></button>
            <button type="submit" name="reset_shodan_signals" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shodan-srs.reset_signals_confirm')); ?>"><?php echo e(t('admin_shodan-srs.reset_to_defaults_button')); ?></button>
        </div>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function toggleSignalCategory(category) {
    const el = document.getElementById('signals_' + category);
    const arrow = document.getElementById('arrow_' + category);
    if (el.style.display === 'none') {
        el.style.display = 'block';
        arrow.style.transform = 'rotate(180deg)';
    } else {
        el.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
    }
}
</script>
</div><!-- end shodanSection_signals -->

<div id="shodanSection_waivers"<?php echo $shodanSubTab !== 'waivers' ? ' style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_shodan-srs.waivers_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo e(t('admin_shodan-srs.waivers_intro')); ?>
    </p>

    <?php if (empty($waiversByVendor)): ?>
    <div style="text-align: center; padding: 30px 20px; background: #f9fafb; border-radius: 8px; color: #6b7280;">
        <div style="font-size: 24px; margin-bottom: 8px;">&#10003;</div>
        <div style="font-size: 14px; font-weight: 500;"><?php echo e(t('admin_shodan-srs.waivers_empty_title')); ?></div>
        <div style="font-size: 12px; margin-top: 4px;"><?php echo e(t('admin_shodan-srs.waivers_empty_hint')); ?></div>
    </div>
    <?php else: ?>

    <!-- Filter -->
    <div style="margin-bottom: 15px;">
        <input type="text" id="waiverFilter" placeholder="<?php echo e(t('admin_shodan-srs.waiver_filter_placeholder')); ?>" autocomplete="off"
               class="focus-ring-blue"
               style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
    </div>

    <!-- Summary -->
    <div style="margin-bottom: 15px; padding: 10px 14px; background: #f0f4ff; border-radius: 8px; font-size: 13px; color: #374151;">
        <?php echo count($allWaivers); ?> <?php echo count($allWaivers) !== 1 ? e(t('admin_shodan-srs.active_waivers_plural')) : e(t('admin_shodan-srs.active_waiver_singular')); ?> <?php echo e(t('admin_shodan-srs.across')); ?> <?php echo count($waiversByVendor); ?> <?php echo count($waiversByVendor) !== 1 ? e(t('admin_shodan-srs.word_vendors')) : e(t('admin_shodan-srs.word_vendor')); ?>
    </div>

    <!-- Vendor list -->
    <div id="waiverVendorList">
    <?php
    $catLabels = [
        'tls_crypto' => 'TLS / Crypto',
        'network_security' => 'Network Security',
        'app_hardening' => 'App Hardening',
        'vuln_exposure' => 'Vuln Exposure',
        'email_security' => 'Email Security',
    ];
    foreach ($waiversByVendor as $vendorId => $vendorData):
        $vendorName = htmlspecialchars($vendorData['vendor_name']);
        $vendorDomain = htmlspecialchars($vendorData['vendor_domain'] ?? '');
        $waivers = $vendorData['waivers'];
        $waiverCount = count($waivers);
    ?>
    <div class="waiver-vendor-block" data-vendor-name="<?php echo strtolower($vendorName); ?>" data-vendor-domain="<?php echo strtolower($vendorDomain); ?>" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 12px;">
        <!-- Vendor header (clickable to expand/collapse) -->
        <div class="waiver-vendor-header" style="padding: 12px 14px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; cursor: pointer; flex-wrap: wrap; gap: 8px;"
             data-target="waiverDetail-<?php echo (int)$vendorId; ?>">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="waiver-chevron" style="display: inline-block; transition: transform 0.15s; font-size: 11px; color: #9ca3af;">&#9654;</span>
                <strong style="color: #374151; font-size: 14px;"><?php echo $vendorName; ?></strong>
                <?php if ($vendorDomain): ?>
                <span style="color: #9ca3af; font-size: 12px;"><?php echo $vendorDomain; ?></span>
                <?php endif; ?>
                <span style="background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
                    <?php echo $waiverCount; ?> <?php echo $waiverCount !== 1 ? e(t('admin_shodan-srs.word_waivers')) : e(t('admin_shodan-srs.word_waiver')); ?>
                </span>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a href="vendor-srs-details.php?id=<?php echo (int)$vendorId; ?>" style="padding: 4px 10px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; font-size: 11px; font-weight: 500; text-decoration: none;"><?php echo e(t('admin_shodan-srs.view_srs_button')); ?></a>
                <form method="POST" action="admin.php?section=srs&amp;srs_tab=shodan&amp;shodan_tab=waivers" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="vendor_id" value="<?php echo (int)$vendorId; ?>">
                    <input type="hidden" name="remove_all_waivers" value="1">
                    <button type="submit" style="padding: 4px 10px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;"
                            data-confirm="<?php echo e(t('admin_shodan-srs.remove_all_confirm_prefix')); ?> <?php echo $waiverCount; ?> <?php echo $waiverCount !== 1 ? e(t('admin_shodan-srs.word_waivers')) : e(t('admin_shodan-srs.word_waiver')); ?> <?php echo e(t('admin_shodan-srs.remove_all_confirm_for')); ?> <?php echo $vendorName; ?><?php echo e(t('admin_shodan-srs.remove_all_confirm_suffix')); ?>"><?php echo e(t('admin_shodan-srs.remove_all_button')); ?></button>
                </form>
            </div>
        </div>
        <!-- Waiver details (collapsed by default) -->
        <div id="waiverDetail-<?php echo (int)$vendorId; ?>" class="waiver-detail-panel" style="display: none;">
            <table style="font-size: 12px; margin: 0; width: 100%;">
                <thead><tr style="background: #fafafa;">
                    <th style="padding: 6px 10px; text-align: left;"><?php echo e(t('admin_shodan-srs.th_finding')); ?></th>
                    <th style="padding: 6px 10px; text-align: left; width: 100px;"><?php echo e(t('admin_shodan-srs.th_category')); ?></th>
                    <th style="padding: 6px 10px; text-align: left; width: 140px;"><?php echo e(t('admin_shodan-srs.th_subdomain')); ?></th>
                    <th style="padding: 6px 10px; text-align: left;"><?php echo e(t('admin_shodan-srs.th_reason')); ?></th>
                    <th style="padding: 6px 10px; text-align: left; width: 100px;"><?php echo e(t('admin_shodan-srs.th_waived_by')); ?></th>
                    <th style="padding: 6px 10px; text-align: left; width: 90px;"><?php echo e(t('admin_shodan-srs.th_date')); ?></th>
                    <th style="padding: 6px 10px; width: 60px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($waivers as $w):
                    $cat = $catLabels[$w['category'] ?? ''] ?? ucwords(str_replace('_', ' ', $w['category'] ?? ''));
                    $dateStr = !empty($w['created_at']) ? date('M j, Y', strtotime($w['created_at'])) : '-';
                ?>
                <tr style="border-top: 1px solid #f3f4f6;">
                    <td style="padding: 6px 10px; color: #374151;"><?php echo htmlspecialchars($w['label'] ?? $w['signal_name'] ?? ''); ?></td>
                    <td style="padding: 6px 10px; font-size: 11px; color: #6b7280;"><?php echo htmlspecialchars($cat); ?></td>
                    <td style="padding: 6px 10px; font-family: monospace; font-size: 11px; color: #555;"><?php echo htmlspecialchars($w['subdomain'] ?? ''); ?></td>
                    <td style="padding: 6px 10px; font-size: 11px; color: #555;"><?php echo htmlspecialchars($w['reason'] ?? '-'); ?></td>
                    <td style="padding: 6px 10px; font-size: 11px; color: #555;"><?php echo htmlspecialchars($w['waived_by'] ?? '-'); ?></td>
                    <td style="padding: 6px 10px; font-size: 11px; color: #9ca3af;"><?php echo $dateStr; ?></td>
                    <td style="padding: 6px 10px; text-align: center;">
                        <form method="POST" style="display: inline;" action="admin.php?section=srs&amp;srs_tab=shodan&amp;shodan_tab=waivers">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="waiver_id" value="<?php echo (int)$w['id']; ?>">
                            <input type="hidden" name="remove_waiver" value="1">
                            <button type="submit" style="padding: 2px 8px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 4px; font-size: 11px; cursor: pointer;"
                                    data-confirm="<?php echo e(t('admin_shodan-srs.remove_waiver_confirm_prefix')); ?><?php echo htmlspecialchars($w['subdomain'] ?? ''); ?><?php echo e(t('admin_shodan-srs.remove_waiver_confirm_suffix')); ?>"><?php echo e(t('admin_shodan-srs.remove_button')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
    (function() {
        // Toggle vendor detail panels
        document.querySelectorAll('.waiver-vendor-header').forEach(function(header) {
            header.addEventListener('click', function(e) {
                if (e.target.closest('form') || e.target.closest('a')) return;
                var targetId = this.getAttribute('data-target');
                var panel = document.getElementById(targetId);
                var chevron = this.querySelector('.waiver-chevron');
                if (panel.style.display === 'none') {
                    panel.style.display = '';
                    chevron.style.transform = 'rotate(90deg)';
                } else {
                    panel.style.display = 'none';
                    chevron.style.transform = 'rotate(0deg)';
                }
            });
        });

        // Client-side filter
        var filterInput = document.getElementById('waiverFilter');
        if (filterInput) {
            filterInput.addEventListener('input', function() {
                var q = this.value.trim().toLowerCase();
                document.querySelectorAll('.waiver-vendor-block').forEach(function(block) {
                    var name = block.getAttribute('data-vendor-name') || '';
                    var domain = block.getAttribute('data-vendor-domain') || '';
                    block.style.display = (!q || name.indexOf(q) !== -1 || domain.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        }
    })();
    </script>

    <?php endif; ?>
</div>
</div><!-- end shodanSection_waivers -->

<div id="shodanSection_about"<?php echo $shodanSubTab !== 'about' ? ' style="display: none;"' : ''; ?>>
<div class="card">
    <h3><?php echo e(t('admin_shodan-srs.about_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;">
        <?php echo t('admin_shodan-srs.about_body'); ?>
    </p>
    <p style="margin-top: 15px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
        <strong style="color: #92400e;"><?php echo e(t('admin_shodan-srs.about_note_label')); ?></strong>
        <span style="font-size: 13px; color: #92400e;"> <?php echo e(t('admin_shodan-srs.about_note_body')); ?></span>
    </p>
    <p style="margin-top: 15px;">
        <a href="https://www.shodan.io" target="_blank" style="color: var(--theme-header-color);"><?php echo e(t('admin_shodan-srs.about_learn_more')); ?></a>
    </p>
</div>
</div><!-- end shodanSection_about -->

<script nonce="<?php echo cspNonce(); ?>">
function showShodanSection(section) {
    const sections = ['settings', 'weights', 'signals', 'waivers', 'about'];
    sections.forEach(s => {
        const el = document.getElementById('shodanSection_' + s);
        const tab = document.getElementById('shodanTab_' + s);
        if (el) el.style.display = s === section ? '' : 'none';
        if (tab) {
            tab.style.borderBottomColor = s === section ? 'var(--theme-header-color, #2563eb)' : 'transparent';
            tab.style.color = s === section ? 'var(--theme-header-color, #2563eb)' : '#6b7280';
            tab.style.fontWeight = s === section ? '600' : '500';
        }
    });
}
</script>
