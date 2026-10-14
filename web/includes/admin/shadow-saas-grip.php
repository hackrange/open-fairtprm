<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Shadow SaaS - Grip Security integration.
 *
 * Same horizontal-tab format as admin.php?section=email; for now there's a
 * single tab ("Grip"). Configures the Grip tenant base URL + API token,
 * handles connection test, exposes the rehydration cron schedule, a
 * "Run Now" background trigger, and links to the bundled Swagger/Postman
 * reference files.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

require_once __DIR__ . '/../classes/GripService.php';
require_once __DIR__ . '/../classes/HeroService.php';
require_once __DIR__ . '/../classes/ZscalerService.php';
require_once __DIR__ . '/../classes/VendorPrivacyService.php';
// Shared cron-job definitions + regenerateCrontab()/isValidCronExpression()/
// describeCron(). The "Scheduled Rehydration" card manages the hidden
// shadow_saas_rehydrate job (registered here) directly from this page.
require_once __DIR__ . '/../cron-jobs.php';

$shadowTab = $_GET['shadow_tab'] ?? 'grip';
if (!in_array($shadowTab, ['grip', 'hero'], true)) {
    $shadowTab = 'grip';
}

// ============================================================================
// POST: save Grip settings
// ----------------------------------------------------------------------------
// The page renders two independent <form>s (connection card + schedule card),
// each with its own "Save" button bound to name="update_grip". A hidden
// grip_form input identifies which form was submitted so we only touch the
// settings that belong to that form - saving the connection card no longer
// resets the cron schedule, and vice versa.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grip'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();
            $formId = in_array($_POST['grip_form'] ?? '', ['schedule', 'privacy'], true) ? $_POST['grip_form'] : 'connection';
            $settings = [];

            if ($formId === 'connection') {
                $rawBase = trim($_POST['grip_base_url'] ?? '');
                if ($rawBase === '') {
                    $rawBase = 'https://tenant.dep.grip.security/public/saas';
                }
                // Strip trailing slash; reject anything that isn't an http(s) URL.
                $rawBase = rtrim($rawBase, '/');
                if (!preg_match('#^https?://[^\s]+$#i', $rawBase)) {
                    throw new Exception(t('admin_shadow-saas-grip.server_url_invalid'));
                }
                // SECURITY (SSRF): reject cloud-metadata / loopback hosts (the API token is
                // sent to this endpoint). Private LAN hosts remain allowed for on-prem Grip.
                if (!isConfigUrlHostSafe($rawBase)) {
                    throw new Exception(t('admin_shadow-saas-grip.server_url_host_not_allowed'));
                }

                $settings['grip_enabled']  = isset($_POST['grip_enabled']) ? '1' : '0';
                $settings['grip_base_url'] = $rawBase;
                // Data source: 'live' = query the Grip API on every view;
                // 'local' = serve the hydrated DB snapshot (caching, lighter on the
                // API) and full-refresh (truncate + re-hydrate) on each sync.
                $settings['grip_data_source'] = (($_POST['grip_data_source'] ?? 'live') === 'local') ? 'local' : 'live';
                // Flow Grip "Security Incident Detected" alerts into the Breach Alerts
                // system on each hydration. Also requires Grip enabled (above) and the
                // Breach/Cyber Alert master switch (admin.php?section=email).
                $settings['grip_breach_sync_enabled'] = isset($_POST['grip_breach_sync_enabled']) ? '1' : '0';
                // Write TPRM status back into Grip: FairTPRM labels and the vendor
                // relationship manager as the app's primary contact.
                $settings['grip_writeback_enabled'] = isset($_POST['grip_writeback_enabled']) ? '1' : '0';
                // With write-back on, also label apps with their AI privacy ratings.
                $settings['grip_writeback_privacy_labels'] = isset($_POST['grip_writeback_privacy_labels']) ? '1' : '0';

                $newToken = trim($_POST['grip_api_token'] ?? '');
                if ($newToken !== '') {
                    $settings['grip_api_token'] = $encryption->encrypt($newToken);
                }

                // Mutual exclusion: Grip and Hero can't both be enabled.
                if ($settings['grip_enabled'] === '1') {
                    $settings['hero_enabled'] = '0';
                }
            } elseif ($formId === 'privacy') {
                // AI vendor privacy review (VendorPrivacyService). "Use AI" can only
                // be switched on while an AI platform is enabled.
                $wantAi = isset($_POST['grip_ai_privacy_enabled']);
                if ($wantAi && !AIPlatformService::getInstance()->isEnabled()) {
                    throw new Exception(t('admin_shadow-saas-grip.privacy_needs_ai'));
                }
                $settings['grip_ai_privacy_enabled']       = $wantAi ? '1' : '0';
                $settings['grip_ai_privacy_interval_days'] = (string)max(7, min(365, (int)($_POST['grip_ai_privacy_interval_days'] ?? 30) ?: 30));
                $settings['grip_ai_privacy_daily_cap']     = (string)max(1, min(5000, (int)($_POST['grip_ai_privacy_daily_cap'] ?? 150) ?: 150));
                $settings['grip_ai_privacy_web_search']    = isset($_POST['grip_ai_privacy_web_search']) ? '1' : '0';
                $model = trim((string)($_POST['grip_ai_privacy_model'] ?? ''));
                if ($model !== '' && !preg_match('~^[A-Za-z0-9._:/@-]{1,100}$~', $model)) {
                    throw new Exception(t('admin_shadow-saas-grip.privacy_model_invalid'));
                }
                $settings['grip_ai_privacy_model'] = $model;
                $pSchedule = trim($_POST['privacy_schedule'] ?? '30 3 * * *');
                if (!isValidCronExpression($pSchedule)) {
                    throw new Exception(t('admin_shadow-saas-grip.invalid_cron'));
                }
                $settings['cron_vendor_privacy_review_enabled']  = $wantAi ? '1' : '0';
                $settings['cron_vendor_privacy_review_schedule'] = $pSchedule;
            } else { // 'schedule'
                // Shared rehydration schedule. Stored under the cron_* convention
                // (cron_<key>_enabled / cron_<key>_schedule) so regenerateCrontab()
                // installs it exactly like every other job, and edited here instead
                // of on the Scheduler page (the job is 'hidden' there).
                $schedule = trim($_POST['rehydrate_schedule'] ?? '0 2 * * *');
                if (!isValidCronExpression($schedule)) {
                    throw new Exception(t('admin_shadow-saas-grip.invalid_cron'));
                }
                $settings['cron_shadow_saas_rehydrate_enabled']  = isset($_POST['rehydrate_enabled']) ? '1' : '0';
                $settings['cron_shadow_saas_rehydrate_schedule'] = $schedule;
                // Grip new-app discovery (hidden grip_discovery job), saved with
                // the same form so both Grip schedules live in one place.
                $discSchedule = trim($_POST['discovery_schedule'] ?? '*/15 * * * *');
                if (!isValidCronExpression($discSchedule)) {
                    throw new Exception(t('admin_shadow-saas-grip.invalid_cron'));
                }
                $settings['cron_grip_discovery_enabled']  = isset($_POST['discovery_enabled']) ? '1' : '0';
                $settings['cron_grip_discovery_schedule'] = $discSchedule;
            }

            foreach ($settings as $key => $value) {
                // Defensive: every config_value column is NOT NULL; coerce to string.
                if ($value === null) { $value = ''; }
                $isEncrypted = ($key === 'grip_api_token') ? 1 : 0;
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :k', [':k' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value, 'is_encrypted' => $isEncrypted], 'config_key = :k', [':k' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => $isEncrypted]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update_grip', 'app_config', null, ['form' => $formId, 'new' => $settings]);
            if ($formId === 'privacy') {
                $success = regenerateCrontab($db, cronJobDefinitions()) ? t('admin_shadow-saas-grip.privacy_saved') : t('admin_shadow-saas-grip.schedule_save_crontab_failed');
            } elseif ($formId === 'schedule') {
                // Install/refresh the crontab so the schedule change takes effect
                // immediately (same mechanism the Scheduler page uses).
                if (regenerateCrontab($db, cronJobDefinitions())) {
                    $success = t('admin_shadow-saas-grip.schedule_updated');
                } else {
                    $error = t('admin_shadow-saas-grip.schedule_save_crontab_failed');
                }
            } else {
                $success = t('admin_shadow-saas-grip.grip_config_updated');
            }
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating Grip config: ' . $e->getMessage());
            $error = t('admin_shadow-saas-grip.grip_config_update_failed_prefix') . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: save Zscaler settings
// ----------------------------------------------------------------------------
// Single connection card on the Grip tab. Mirrors the Grip save pattern:
// hidden zscaler_form input differentiates this submission from the Grip
// forms above. Sensitive values (password, api_key) are encrypted at rest.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_zscaler'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();
            $settings = [];

            $rawUrl = rtrim(trim($_POST['zscaler_api_url'] ?? ''), '/');
            if ($rawUrl !== '' && !preg_match('#^https?://[^\s]+$#i', $rawUrl)) {
                throw new Exception(t('admin_shadow-saas-grip.api_url_invalid'));
            }
            // SECURITY (SSRF): reject cloud-metadata / loopback hosts (credentials are POSTed
            // to this endpoint). Private LAN hosts remain allowed for on-prem Zscaler proxies.
            if ($rawUrl !== '' && !isConfigUrlHostSafe($rawUrl)) {
                throw new Exception(t('admin_shadow-saas-grip.api_url_host_not_allowed'));
            }

            $settings['zscaler_enabled']       = isset($_POST['zscaler_enabled']) ? '1' : '0';
            $settings['zscaler_api_url']       = $rawUrl;
            $settings['zscaler_vanity_domain'] = trim($_POST['zscaler_vanity_domain'] ?? '');
            $settings['zscaler_username']      = trim($_POST['zscaler_username'] ?? '');
            $settings['zscaler_url_category']  = trim($_POST['zscaler_url_category'] ?? '');

            $newPwd = (string)($_POST['zscaler_password'] ?? '');
            if ($newPwd !== '') {
                $settings['zscaler_password'] = $encryption->encrypt($newPwd);
            }
            $newKey = trim($_POST['zscaler_api_key'] ?? '');
            if ($newKey !== '') {
                $settings['zscaler_api_key'] = $encryption->encrypt($newKey);
            }

            foreach ($settings as $key => $value) {
                if ($value === null) { $value = ''; }
                $isEncrypted = in_array($key, ['zscaler_password', 'zscaler_api_key'], true) ? 1 : 0;
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :k', [':k' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value, 'is_encrypted' => $isEncrypted], 'config_key = :k', [':k' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => $isEncrypted]);
                }
            }

            $db->commit();
            // Audit without leaking encrypted values
            $auditPayload = $settings;
            foreach (['zscaler_password', 'zscaler_api_key'] as $k) {
                if (isset($auditPayload[$k])) $auditPayload[$k] = '[encrypted]';
            }
            $auth->audit($user['id'], 'config_update_zscaler', 'app_config', null, ['new' => $auditPayload]);
            $success = t('admin_shadow-saas-grip.zscaler_config_updated');
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating Zscaler config: ' . $e->getMessage());
            $error = t('admin_shadow-saas-grip.zscaler_config_update_failed_prefix') . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: test Zscaler connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_zscaler_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $svc = new ZscalerService();
            $result = $svc->testConnection();
            if ($result['success']) {
                $success = t('admin_shadow-saas-grip.zscaler_test_prefix') . $result['message'];
            } else {
                $error = t('admin_shadow-saas-grip.zscaler_test_failed_prefix') . $result['message'];
            }
        } catch (Throwable $e) {
            error_log('Zscaler connection test error: ' . $e->getMessage());
            $error = t('admin_shadow-saas-grip.zscaler_test_error');
        }
    }
}

// ============================================================================
// POST: test Grip connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_grip_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $svc = new GripService();
            $result = $svc->testConnection();
            if ($result['success']) {
                $success = t('admin_shadow-saas-grip.grip_test_prefix') . $result['message'];
            } else {
                $error = t('admin_shadow-saas-grip.grip_test_failed_prefix') . $result['message'];
            }
        } catch (Throwable $e) {
            error_log('Grip connection test error: ' . $e->getMessage());
            $error = t('admin_shadow-saas-grip.grip_test_error');
        }
    }
}

// ============================================================================
// POST: Run Now (background hydration)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_grip_now'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        $cronScript = realpath(__DIR__ . '/../../cron/shadow-saas-sync.php');
        if (!$cronScript || !is_file($cronScript)) {
            $error = t('admin_shadow-saas-grip.sync_script_not_found');
        } else {
            $logFile = '/var/log/php/cron-shadow-saas-rehydrate.log';
            $cmd = sprintf(
                'nohup /usr/bin/php %s --manual --user-id=%d --verbose >> %s 2>&1 &',
                escapeshellarg($cronScript),
                (int)($user['id'] ?? 0),
                escapeshellarg($logFile)
            );
            // exec() is disabled by the web hardening (99-hardening.ini); proc_open
            // is permitted. $cmd already backgrounds itself (nohup … &), so the
            // wrapping sh returns immediately.
            $_gsProc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $_gsPipes);
            if (is_resource($_gsProc)) {
                if (isset($_gsPipes[1])) { fclose($_gsPipes[1]); }
                if (isset($_gsPipes[2])) { fclose($_gsPipes[2]); }
                proc_close($_gsProc);
            }
            $auth->audit($user['id'], 'shadow_saas_run_now', 'shadow_saas_grip_sync_log', null, ['log_file' => $logFile]);
            $success = t('admin_shadow-saas-grip.rehydration_started');
        }
    }
}

// ============================================================================
// POST: Run the vendor privacy review now (background, up to the daily cap)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_privacy_now'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } elseif (($why = VendorPrivacyService::notReadyReason($db)) !== null) {
        // Say exactly why, instead of a button that silently does nothing.
        $error = t('vendor-privacy.not_ready_' . $why);
    } elseif (($why = VendorPrivacyService::startBackground(['--manual', '--verbose'])) !== null) {
        $error = t('vendor-privacy.start_' . $why);
    } else {
        $auth->audit($user['id'], 'vendor_privacy_run_now', 'vendor_privacy_reviews', null, []);
        $success = t('admin_shadow-saas-grip.privacy_started');
    }
}

// ============================================================================
// POST: abort a running sync (cooperative - flips the run's status to 'aborting';
// the driver notices within a few iterations, stops, and releases its lock).
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abort_shadow_sync'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        $provider = (($_POST['sync_provider'] ?? '') === 'hero') ? 'hero' : 'grip';
        $table = $provider === 'hero' ? 'shadow_saas_hero_sync_log' : 'shadow_saas_grip_sync_log';
        try {
            $n = $db->update($table, ['status' => 'aborting'], "status = 'running'");
            $auth->audit($user['id'], 'shadow_saas_abort_sync', $table, null, ['provider' => $provider]);
            $success = $n > 0
                ? ucfirst($provider) . t('admin_shadow-saas-grip.sync_abort_requested_suffix')
                : t('admin_shadow-saas-grip.no_running_prefix') . $provider . t('admin_shadow-saas-grip.no_running_suffix');
        } catch (Throwable $e) {
            $error = t('admin_shadow-saas-grip.abort_failed_prefix') . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: truncate a Shadow SaaS mirror table (admin maintenance). Whitelisted
// table names ONLY - the table name is never taken from raw user input.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truncate_mirror_table'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        $allowedTables = [
            'shadow_saas_grip_apps', 'shadow_saas_grip_users', 'shadow_saas_grip_alerts',
            'shadow_saas_hero_vendors', 'shadow_saas_hero_issues', 'shadow_saas_hero_users',
        ];
        $tbl = (string)($_POST['mirror_table'] ?? '');
        if (!in_array($tbl, $allowedTables, true)) {
            $error = t('admin_shadow-saas-grip.unknown_mirror_table');
        } else {
            try {
                // $tbl is constrained to the whitelist above - safe to interpolate.
                $db->query("TRUNCATE TABLE `{$tbl}`");
                $auth->audit($user['id'], 'shadow_saas_truncate_mirror', $tbl, null, ['table' => $tbl]);
                $success = t('admin_shadow-saas-grip.mirror_truncated_prefix') . e($tbl) . t('admin_shadow-saas-grip.mirror_truncated_suffix');
            } catch (Throwable $e) {
                error_log('Truncate mirror failed: ' . $e->getMessage());
                $error = t('admin_shadow-saas-grip.failed_truncate_prefix') . e($tbl) . '.';
            }
        }
    }
}

// ============================================================================
// POST: truncate ALL locally-served Grip data in one action.
//
// Grip data lives locally in three places, not just the mirror tables:
//   1. the shadow_saas_grip_* mirror tables (raw hydrated snapshot);
//   2. rows projected into the shared shadow_saas table (source = 'grip');
//   3. the curated grip_app_data JSON stamped onto vendor_onboarding_requests
//      (this is what feeds the "SaaS Data" tab on the vendor page).
// This clears all three so no stale Grip data is served. The next sync (in
// Local mode) re-hydrates everything from the Grip API. Non-Grip shadow_saas
// rows and every other vendor field are left untouched. The grip sync-log
// history is intentionally preserved. Table names are hardcoded (never user
// input), so interpolation is safe.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truncate_grip_data'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        $gripMirrors = [
            'shadow_saas_grip_apps', 'shadow_saas_grip_users',
            'shadow_saas_grip_alerts', 'shadow_saas_grip_app_users',
        ];
        $truncated = [];
        $failed = [];
        foreach ($gripMirrors as $tbl) {
            try {
                $db->query("TRUNCATE TABLE `{$tbl}`");
                $truncated[] = $tbl;
            } catch (Throwable $e) {
                error_log('Truncate Grip data failed for ' . $tbl . ': ' . $e->getMessage());
                $failed[] = $tbl;
            }
        }

        // Grip-projected rows in the shared shadow_saas table (scoped by source).
        $saasRowsRemoved = 0;
        try {
            $saasRowsRemoved = $db->delete('shadow_saas', "source = 'grip'", []);
        } catch (Throwable $e) {
            error_log('Truncate Grip data: shadow_saas delete failed: ' . $e->getMessage());
            $failed[] = 'shadow_saas';
        }

        // Curated Grip telemetry stamped on vendor records (the "SaaS Data" tab).
        $vendorsCleared = 0;
        try {
            $vendorsCleared = $db->update(
                'vendor_onboarding_requests',
                ['grip_app_data' => null],
                "grip_app_data IS NOT NULL AND grip_app_data <> ''",
                []
            );
        } catch (Throwable $e) {
            error_log('Truncate Grip data: vendor grip_app_data clear failed: ' . $e->getMessage());
            $failed[] = 'vendor_onboarding_requests.grip_app_data';
        }

        $auth->audit($user['id'], 'shadow_saas_truncate_grip_all', 'shadow_saas_grip_*', null, [
            'mirror_tables'    => $truncated,
            'shadow_saas_rows' => $saasRowsRemoved,
            'vendors_cleared'  => $vendorsCleared,
            'failed'           => $failed,
        ]);

        if (empty($failed)) {
            $success = t('admin_shadow-saas-grip.grip_all_cleared_p1') . count($truncated) . t('admin_shadow-saas-grip.grip_all_cleared_p2')
                . number_format($saasRowsRemoved) . t('admin_shadow-saas-grip.grip_all_cleared_p3')
                . number_format($vendorsCleared) . t('admin_shadow-saas-grip.grip_all_cleared_p4');
        } else {
            $error = t('admin_shadow-saas-grip.grip_partial_cleared_prefix') . e(implode(', ', $failed)) . t('admin_shadow-saas-grip.grip_partial_cleared_suffix');
        }
    }
}

// ============================================================================
// POST: save HERO settings (mutually exclusive with Grip)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hero'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();
            $rawBase = trim($_POST['hero_base_url'] ?? '');
            if ($rawBase === '') $rawBase = 'https://api.herosecurity.ai/stable';
            $rawBase = rtrim($rawBase, '/');
            if (!preg_match('#^https?://[^\s]+$#i', $rawBase)) {
                throw new Exception(t('admin_shadow-saas-grip.server_url_invalid'));
            }
            if (!isConfigUrlHostSafe($rawBase)) {
                throw new Exception(t('admin_shadow-saas-grip.server_url_host_not_allowed'));
            }
            $heroEnabled = isset($_POST['hero_enabled']) ? '1' : '0';
            $settings = [
                'hero_enabled'   => $heroEnabled,
                'hero_base_url'  => $rawBase,
                'hero_client_id' => trim($_POST['hero_client_id'] ?? ''),
            ];
            $newSecret = trim($_POST['hero_client_secret'] ?? '');
            if ($newSecret !== '') {
                $settings['hero_client_secret'] = $encryption->encrypt($newSecret);
            }
            // Sync pacing (clamped to safe bounds; HERO rate-limits ~60 req/min).
            $settings['hero_throttle_ms']   = (string)max(0, min(10000, (int)($_POST['hero_throttle_ms'] ?? 1100)));
            $settings['hero_users_timeout'] = (string)max(5, min(120, (int)($_POST['hero_users_timeout'] ?? 15)));
            // Mutual exclusion: enabling Hero disables Grip.
            if ($heroEnabled === '1') {
                $settings['grip_enabled'] = '0';
            }
            foreach ($settings as $key => $value) {
                if ($value === null) { $value = ''; }
                $isEncrypted = ($key === 'hero_client_secret') ? 1 : 0;
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :k', [':k' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value, 'is_encrypted' => $isEncrypted], 'config_key = :k', [':k' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => $isEncrypted]);
                }
            }
            $db->commit();
            $auth->audit($user['id'], 'config_update_hero', 'app_config', null, ['new' => [
                'hero_enabled'       => $heroEnabled,
                'hero_base_url'      => $rawBase,
                'hero_client_id'     => $settings['hero_client_id'],
                'hero_client_secret' => isset($settings['hero_client_secret']) ? '[encrypted]' : '(unchanged)',
            ]]);
            $success = t('admin_shadow-saas-grip.hero_config_updated') . ($heroEnabled === '1' ? t('admin_shadow-saas-grip.hero_disabled_grip') : '');
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating HERO config: ' . $e->getMessage());
            $error = t('admin_shadow-saas-grip.hero_config_update_failed_prefix') . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: test HERO connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_hero_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_shadow-saas-grip.invalid_request');
    } else {
        try {
            $svc = new HeroService();
            $res = $svc->testConnection();
            if (!empty($res['success'])) { $success = $res['message']; }
            else { $error = t('admin_shadow-saas-grip.hero_test_failed_prefix') . ($res['message'] ?? t('admin_shadow-saas-grip.unknown_error')); }
        } catch (Throwable $e) {
            $error = t('admin_shadow-saas-grip.hero_test_failed_prefix') . $e->getMessage();
        }
    }
}

// ============================================================================
// Data loading
// ============================================================================
$encryption = new Encryption();
$gripCfg = ['enabled' => '0', 'base_url' => 'https://tenant.dep.grip.security/public/saas', 'api_token' => '', 'cron_enabled' => '0', 'cron_frequency' => 'daily'];
$gripRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?', ['grip_%']);
foreach ($gripRows as $row) {
    $key = str_replace('grip_', '', $row['config_key']);
    $val = ($row['is_encrypted'] && !empty($row['config_value'])) ? $encryption->decrypt($row['config_value']) : $row['config_value'];
    $gripCfg[$key] = $val;
}

// Zscaler config (rendered alongside Grip on the same tab)
$zscalerCfg = [
    'enabled'        => '0',
    'api_url'        => 'https://api.zsapi.net',
    'vanity_domain'  => '',
    'username'       => '',
    'password'       => '',
    'api_key'        => '',
    'url_category'   => '',
];
$zscalerRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?', ['zscaler_%']);
foreach ($zscalerRows as $row) {
    $key = str_replace('zscaler_', '', $row['config_key']);
    $val = ($row['is_encrypted'] && !empty($row['config_value'])) ? $encryption->decrypt($row['config_value']) : $row['config_value'];
    $zscalerCfg[$key] = $val;
}

// Last sync info
$lastSync = null;
try { $lastSync = $db->fetchOne("SELECT * FROM shadow_saas_grip_sync_log ORDER BY id DESC LIMIT 1"); } catch (Throwable $e) {}

// Counts in mirror tables (best-effort; tables may not exist on first install)
$counts = ['apps' => 0, 'users' => 0, 'alerts' => 0, 'app_users' => 0];
foreach ([['shadow_saas_grip_apps','apps'],['shadow_saas_grip_users','users'],['shadow_saas_grip_alerts','alerts'],['shadow_saas_grip_app_users','app_users']] as [$tbl,$key]) {
    try { $r = $db->fetchOne("SELECT COUNT(*) AS cnt FROM {$tbl}"); $counts[$key] = (int)($r['cnt'] ?? 0); } catch (Throwable $e) {}
}

// HERO config (parallel to Grip; mutually exclusive)
$heroCfg = ['enabled' => '0', 'base_url' => 'https://api.herosecurity.ai/stable', 'client_id' => '', 'client_secret' => '', 'throttle_ms' => '1100', 'users_timeout' => '15'];
$heroRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?', ['hero_%']);
foreach ($heroRows as $row) {
    $key = str_replace('hero_', '', $row['config_key']);
    $val = ($row['is_encrypted'] && !empty($row['config_value'])) ? $encryption->decrypt($row['config_value']) : $row['config_value'];
    $heroCfg[$key] = $val;
}
$heroLastSync = null;
try { $heroLastSync = $db->fetchOne("SELECT * FROM shadow_saas_hero_sync_log ORDER BY id DESC LIMIT 1"); } catch (Throwable $e) {}
$heroCounts = ['vendors' => 0, 'users' => 0, 'issues' => 0];
foreach ([['shadow_saas_hero_vendors','vendors'],['shadow_saas_hero_users','users'],['shadow_saas_hero_issues','issues']] as [$tbl,$key]) {
    try { $r = $db->fetchOne("SELECT COUNT(*) AS cnt FROM {$tbl}"); $heroCounts[$key] = (int)($r['cnt'] ?? 0); } catch (Throwable $e) {}
}

// Shared rehydration schedule - stored under the cron_* convention and installed
// into the system crontab as the hidden shadow_saas_rehydrate job. Defaults match
// the SQL seed (disabled, daily 2am) for the case where the keys aren't set yet.
$rehydrateEnabled  = '0';
$rehydrateSchedule = '0 2 * * *';
$rrEn = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_shadow_saas_rehydrate_enabled'");
if ($rrEn && isset($rrEn['config_value'])) { $rehydrateEnabled = (string)$rrEn['config_value']; }
$rrSc = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_shadow_saas_rehydrate_schedule'");
if ($rrSc && !empty($rrSc['config_value'])) { $rehydrateSchedule = (string)$rrSc['config_value']; }
// Grip new-app discovery job. Unset means enabled on the default schedule, the
// same rule regenerateCrontab() applies.
$discoveryEnabled  = '1';
$discoverySchedule = '*/15 * * * *';
$dEn = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_grip_discovery_enabled'");
if ($dEn && isset($dEn['config_value'])) { $discoveryEnabled = (string)$dEn['config_value']; }
$dSc = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_grip_discovery_schedule'");
if ($dSc && !empty($dSc['config_value'])) { $discoverySchedule = (string)$dSc['config_value']; }
// Vendor privacy review card
$aiOn = AIPlatformService::getInstance()->isEnabled();
$privacyStats = null;
try { $privacyStats = (new VendorPrivacyService($db))->stats(); } catch (Throwable $e) { error_log('Privacy stats: ' . $e->getMessage()); }
$privacySchedule = '30 3 * * *';
$pSc = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_vendor_privacy_review_schedule'");
if ($pSc && !empty($pSc['config_value'])) { $privacySchedule = (string)$pSc['config_value']; }
$privacyLast = null;
try { $privacyLast = $db->fetchOne("SELECT started_at, status, success_count FROM cron_execution_history WHERE job_name = 'vendor-privacy-review' ORDER BY id DESC LIMIT 1") ?: null; } catch (Throwable $e) {}
$privacyNotReady = null;
try { $privacyNotReady = VendorPrivacyService::notReadyReason($db); } catch (Throwable $e) {}
// Live progress while a run holds the lock: vendors checked since it started.
$privacyRunning = VendorPrivacyService::isRunning() || VendorPrivacyService::isRunning(VendorPrivacyService::QUEUE_LOCK_FILE);
$privacyProgress = 0;
if ($privacyRunning && $privacyLast && $privacyLast['status'] === 'running') {
    try { $privacyProgress = (int)($db->fetchOne("SELECT COUNT(*) n FROM vendor_privacy_reviews WHERE checked_at >= :s", [':s' => $privacyLast['started_at']])['n'] ?? 0); } catch (Throwable $e) {}
}
[$aiBudgetLimit, , $aiBudgetWarn, $aiBudgetPauseBg] = AIPlatformService::getInstance()->budget();
$aiBudgetUsed = AIPlatformService::getInstance()->budgetUsedFraction();

$discoveryLast = null;
try {
    $discoveryLast = $db->fetchOne("SELECT started_at, status, success_count FROM cron_execution_history WHERE job_name = 'grip-discovery' ORDER BY id DESC LIMIT 1") ?: null;
} catch (Throwable $e) {}
?>
<div class="page-header-bar">
    <h1 class="page-title">Shadow SaaS</h1>
    <p><?php echo e(t('admin_shadow-saas-grip.page_intro')); ?></p>
</div>

<!-- Horizontal tab navigation: one tab per Shadow SaaS provider (Grip / Hero). -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showShadowSection" data-arg="grip" id="shadowTab_grip" class="shadow-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shadowTab === 'grip' ? '600' : '500'; ?>; color: <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Grip<?php echo ($gripCfg['enabled'] ?? '0') === '1' ? ' &#9679;' : ''; ?></button>
    <button type="button" data-action="showShadowSection" data-arg="hero" id="shadowTab_hero" class="shadow-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shadowTab === 'hero' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shadowTab === 'hero' ? '600' : '500'; ?>; color: <?php echo $shadowTab === 'hero' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Hero<?php echo ($heroCfg['enabled'] ?? '0') === '1' ? ' &#9679;' : ''; ?></button>
</div>
<div class="form-help" style="margin: -12px 0 18px;"><?php echo t('admin_shadow-saas-grip.providers_mutually_exclusive_help'); ?></div>

<div id="shadowSection_grip" <?php echo $shadowTab !== 'grip' ? 'style="display: none;"' : ''; ?>>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.grip_connection_heading')); ?></h3>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="connection">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_enabled" value="1" <?php echo ($gripCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.enable_grip')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.grip_enable_help')); ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="grip_base_url"><?php echo e(t('admin_shadow-saas-grip.grip_base_url_label')); ?></label>
                <input type="text" id="grip_base_url" name="grip_base_url" class="form-control" value="<?php echo e($gripCfg['base_url'] ?? ''); ?>" placeholder="https://tenant.dep.grip.security/public/saas">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.grip_base_url_help'); ?></div>
            </div>
            <div class="form-group">
                <label for="grip_api_token"><?php echo e(t('admin_shadow-saas-grip.api_token_label')); ?></label>
                <input type="password" id="grip_api_token" name="grip_api_token" class="form-control" placeholder="<?php echo !empty($gripCfg['api_token']) ? '••••••••••••••••' : e(t('admin_shadow-saas-grip.enter_admin_api_key')); ?>">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.api_token_help'); ?></div>
            </div>
        </div>
        <div class="form-group" style="margin-top: 4px;">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_breach_sync_enabled" value="1" <?php echo ($gripCfg['breach_sync_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo t('admin_shadow-saas-grip.flow_grip_breach_label'); ?></span>
            </label>
            <div class="form-help"><?php echo t('admin_shadow-saas-grip.grip_breach_help'); ?></div>
        </div>
        <div class="form-group" style="margin-top: 4px;">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_writeback_enabled" value="1" <?php echo ($gripCfg['writeback_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.writeback_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.writeback_help')); ?></div>
            <label class="checkbox-label" style="margin: 8px 0 0 24px;">
                <input type="checkbox" name="grip_writeback_privacy_labels" value="1" <?php echo ($gripCfg['writeback_privacy_labels'] ?? '1') !== '0' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.writeback_privacy_label')); ?></span>
            </label>
            <div class="form-help" style="margin-left: 24px;"><?php echo e(t('admin_shadow-saas-grip.writeback_privacy_help')); ?></div>
        </div>
        <div class="form-group" style="margin-top: 4px;">
            <label for="grip_data_source"><?php echo e(t('admin_shadow-saas-grip.data_source_label')); ?></label>
            <?php $gripSrc = (($gripCfg['data_source'] ?? 'live') === 'local') ? 'local' : 'live'; ?>
            <select id="grip_data_source" name="grip_data_source" class="form-control">
                <option value="live"  <?php echo $gripSrc === 'live'  ? 'selected' : ''; ?>><?php echo e(t('admin_shadow-saas-grip.data_source_live')); ?></option>
                <option value="local" <?php echo $gripSrc === 'local' ? 'selected' : ''; ?>><?php echo e(t('admin_shadow-saas-grip.data_source_local')); ?></option>
            </select>
            <div class="form-help"><?php echo t('admin_shadow-saas-grip.data_source_help'); ?></div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_grip" class="btn btn-primary"><?php echo e(t('admin_shadow-saas-grip.save_configuration')); ?></button>
            <button type="submit" name="test_grip_connection" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.test_connection')); ?></button>
        </div>
    </form>
</div>

<div class="card" id="vendor-privacy-card">
    <h3><?php echo e(t('admin_shadow-saas-grip.privacy_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;"><?php echo e(t('admin_shadow-saas-grip.privacy_intro')); ?></p>
    <?php if ($aiBudgetLimit > 0 && ($aiBudgetUsed >= 1 || ($aiBudgetPauseBg && $aiBudgetUsed * 100 >= $aiBudgetWarn))): ?>
        <div class="alert alert-warning" id="vendor-privacy-budget" style="margin-bottom: 12px;"><?php echo e(t('admin_shadow-saas-grip.privacy_budget_paused', (int)round(100 * $aiBudgetUsed), AIPlatformService::budgetResetAt())); ?> <a href="admin.php?section=api"><?php echo e(t('admin_shadow-saas-grip.privacy_budget_link')); ?></a></div>
    <?php endif; ?>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="privacy">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_ai_privacy_enabled" value="1" <?php echo ($gripCfg['ai_privacy_enabled'] ?? '0') === '1' ? 'checked' : ''; ?> <?php echo $aiOn ? '' : 'disabled'; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.privacy_enable_label')); ?></span>
            </label>
            <div class="form-help"><?php if ($aiOn): ?><?php echo e(t('admin_shadow-saas-grip.privacy_enable_help')); ?><?php else: ?><?php echo e(t('admin_shadow-saas-grip.privacy_needs_ai')); ?> <a href="admin.php?section=api"><?php echo e(t('admin_shadow-saas-grip.privacy_ai_settings_link')); ?></a><?php endif; ?></div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
            <div class="form-group">
                <label for="grip_ai_privacy_interval_days"><?php echo e(t('admin_shadow-saas-grip.privacy_interval_label')); ?></label>
                <input type="number" min="7" max="365" id="grip_ai_privacy_interval_days" name="grip_ai_privacy_interval_days" class="form-control" value="<?php echo (int)($gripCfg['ai_privacy_interval_days'] ?? 30) ?: 30; ?>">
            </div>
            <div class="form-group">
                <label for="grip_ai_privacy_daily_cap"><?php echo e(t('admin_shadow-saas-grip.privacy_cap_label')); ?></label>
                <input type="number" min="1" max="5000" id="grip_ai_privacy_daily_cap" name="grip_ai_privacy_daily_cap" class="form-control" value="<?php echo (int)($gripCfg['ai_privacy_daily_cap'] ?? 150) ?: 150; ?>">
            </div>
            <div class="form-group">
                <label for="privacy_schedule"><?php echo e(t('admin_shadow-saas-grip.schedule_cron_label')); ?></label>
                <input type="text" id="privacy_schedule" name="privacy_schedule" class="form-control" style="font-family: monospace;" value="<?php echo e($privacySchedule); ?>" placeholder="30 3 * * *">
                <div class="form-help"><strong><?php echo e(describeCron($privacySchedule)); ?></strong></div>
            </div>
            <div class="form-group">
                <label for="grip_ai_privacy_model"><?php echo e(t('admin_shadow-saas-grip.privacy_model_label')); ?></label>
                <input type="text" id="grip_ai_privacy_model" name="grip_ai_privacy_model" class="form-control" value="<?php echo e($gripCfg['ai_privacy_model'] ?? ''); ?>" placeholder="<?php echo e(AIPlatformService::getInstance()->billingModel([])); ?>">
                <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.privacy_model_help')); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_ai_privacy_web_search" value="1" <?php echo ($gripCfg['ai_privacy_web_search'] ?? '1') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.privacy_search_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.privacy_search_help')); ?></div>
        </div>
        <?php if ($privacyStats): $ps = $privacyStats; ?>
        <div id="vendor-privacy-stats" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 14px; margin: 6px 0 12px; font-size: 13px; line-height: 1.7;">
            <div><?php echo e(t('admin_shadow-saas-grip.privacy_stats_coverage', $ps['reviewed'], $ps['scope'], $ps['due'], $ps['privacy_high'], $ps['sharing_high'])); ?></div>
            <div>
                <?php if ($ps['measured']): ?>
                    <?php echo e(t('admin_shadow-saas-grip.privacy_cost_measured', '$' . number_format($ps['cost_per_vendor'], 4), $ps['measured_n'], number_format($ps['avg_in']), number_format($ps['avg_out']))); ?><?php if ($ps['estimated_tokens']): ?> <?php echo e(t('admin_shadow-saas-grip.privacy_cost_estimated_tokens')); ?><?php endif; ?>
                <?php else: ?>
                    <?php echo e(t('admin_shadow-saas-grip.privacy_cost_planning', '$' . number_format($ps['cost_per_vendor'], 4), number_format(VendorPrivacyService::EST_INPUT_TOKENS), number_format(VendorPrivacyService::EST_OUTPUT_TOKENS))); ?>
                <?php endif; ?>
            </div>
            <div><?php echo e(t('admin_shadow-saas-grip.privacy_cost_projection', '$' . number_format($ps['first_pass'], 2), $ps['scope'], '$' . number_format($ps['spend_30d'], 2))); ?></div>
            <div style="color: #6b7280;"><?php echo e(t('admin_shadow-saas-grip.privacy_rates', $ps['model'] !== '' ? $ps['model'] : '-', number_format($ps['prices'][0], 2), number_format($ps['prices'][1], 2), number_format($ps['prices'][2], 2))); ?><?php if (($ps['prices'][3] ?? '') === 'none'): ?> <strong><?php echo e(t('admin_shadow-saas-grip.privacy_rates_missing')); ?></strong><?php endif; ?> <a href="admin.php?section=api"><?php echo e(t('admin_shadow-saas-grip.privacy_rates_link')); ?></a></div>
            <?php if ($privacyRunning): ?><div id="vendor-privacy-running" style="color: #92400e; font-weight: 600;"><?php echo e(t('vendor-privacy.running', $privacyProgress)); ?></div><?php endif; ?>
            <?php if ($privacyLast): ?><div style="color: #6b7280;"><?php echo e(t('admin_shadow-saas-grip.discovery_last_run', date('M j, Y H:i', strtotime($privacyLast['started_at'])), (string)$privacyLast['status'], (int)$privacyLast['success_count'])); ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div style="display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap;">
            <button type="submit" name="update_grip" class="btn btn-primary"><?php echo e(t('admin_shadow-saas-grip.save_configuration')); ?></button>
            <button type="submit" name="run_privacy_now" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shadow-saas-grip.privacy_run_confirm')); ?>" ><?php echo e(t('admin_shadow-saas-grip.privacy_run_button')); ?></button>
            <?php if ($privacyNotReady !== null): ?><span class="form-help" id="vendor-privacy-not-ready" style="align-self:center;"><?php echo e(t('vendor-privacy.not_ready_' . $privacyNotReady)); ?></span><?php endif; ?>
        </div>
    </form>
</div>

<?php if ($lastSync): ?>
<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.last_sync_heading')); ?></h3>
    <table>
        <tr><th style="width: 220px;"><?php echo e(t('admin_shadow-saas-grip.th_started')); ?></th><td><?php echo e($lastSync['started_at']); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_finished')); ?></th><td><?php echo $lastSync['finished_at'] ? e($lastSync['finished_at']) : t('admin_shadow-saas-grip.still_running'); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_trigger')); ?></th><td><?php echo e($lastSync['trigger_source']); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_status')); ?></th><td><span class="badge <?php echo $lastSync['status'] === 'success' ? 'badge-success' : ($lastSync['status'] === 'error' ? 'badge-danger' : 'badge-blue'); ?>"><?php echo e($lastSync['status']); ?></span></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_saas_apps')); ?></th><td><?php echo (int)$lastSync['apps_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_users')); ?></th><td><?php echo (int)$lastSync['users_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_alerts')); ?></th><td><?php echo (int)$lastSync['alerts_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_shadow_saas_upserts')); ?></th><td><?php echo (int)$lastSync['shadow_saas_upserted']; ?></td></tr>
        <?php if ((int)($lastSync['roster_total'] ?? 0) > 0): ?>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_rosters_hydrated')); ?></th><td><?php echo number_format((int)$lastSync['roster_done']) . ' / ' . number_format((int)$lastSync['roster_total']) . t('admin_shadow-saas-grip.apps_with_users_suffix'); ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($lastSync['error_message'])): ?>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_error')); ?></th><td style="color: #991b1b;"><?php echo e($lastSync['error_message']); ?></td></tr>
        <?php endif; ?>
    </table>

    <?php
    // Total rows currently held across all locally-served Grip mirror tables.
    $gripLocalRows = $counts['apps'] + $counts['users'] + $counts['alerts'] + $counts['app_users'];
    ?>
    <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee;">
        <form method="POST" action="admin.php?section=shadow-saas" style="margin: 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <button type="submit" name="truncate_grip_data"
                    data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_all_confirm')); ?>"
                    style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:8px 16px;font-size:13px;border-radius:4px;cursor:pointer;font-weight:600;white-space:nowrap;">
                <?php echo e(t('admin_shadow-saas-grip.truncate_data_button')); ?>
            </button>
            <span style="color:#6b7280;font-size:12px;">
                <?php echo t('admin_shadow-saas-grip.truncate_hint_line1'); ?>
                <?php echo e(t('admin_shadow-saas-grip.currently_holding_prefix')); ?><?php echo number_format($gripLocalRows); ?><?php echo e(t('admin_shadow-saas-grip.mirror_row_suffix')); ?><?php echo $gripLocalRows === 1 ? '' : 's'; ?>.
            </span>
        </form>
    </div>

    <?php if (($lastSync['status'] ?? '') === 'running'):
        $rDone  = (int)($lastSync['roster_done'] ?? 0);
        $rTotal = (int)($lastSync['roster_total'] ?? 0);
        $rPct   = $rTotal > 0 ? min(100, (int)floor($rDone * 100 / $rTotal)) : 0;
    ?>
    <!-- Live roster-hydration progress. Polls the JSON status endpoint while the
         sync runs and reloads the card when it finishes. -->
    <div id="grip-sync-progress" style="margin-top: 16px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
            <span style="font-size:13px;color:#374151;font-weight:600;"><?php echo e(t('admin_shadow-saas-grip.hydrating_rosters')); ?></span>
            <span id="grip-sync-progress-label" style="font-size:13px;color:#374151;font-variant-numeric:tabular-nums;">
                <?php echo $rTotal > 0 ? ($rPct . '% (' . number_format($rDone) . ' / ' . number_format($rTotal) . ' apps)') : e(t('admin_shadow-saas-grip.starting')); ?>
            </span>
        </div>
        <div style="height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden;">
            <div id="grip-sync-progress-bar" style="height:100%;width:<?php echo $rPct; ?>%;background:#2563eb;border-radius:5px;transition:width .4s ease;"></div>
        </div>
    </div>
    <form method="POST" action="admin.php?section=shadow-saas" style="margin-top: 12px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="sync_provider" value="grip">
        <button type="submit" name="abort_shadow_sync" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shadow-saas-grip.abort_grip_confirm')); ?>"><?php echo e(t('admin_shadow-saas-grip.stop_sync_button')); ?></button>
    </form>
    <script nonce="<?php echo cspNonce(); ?>">
    (function () {
        var bar = document.getElementById('grip-sync-progress-bar');
        var label = document.getElementById('grip-sync-progress-label');
        if (!bar || !label) return;
        var timer = setInterval(function () {
            fetch('admin.php?section=shadow-saas&grip_sync_status=1', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d) return;
                    // Sync finished (or aborted/errored) -> refresh to show final card.
                    if (!d.running) { clearInterval(timer); window.location.reload(); return; }
                    var pct = (d.percent === null || d.percent === undefined) ? 0 : d.percent;
                    bar.style.width = pct + '%';
                    label.textContent = (d.total > 0)
                        ? (pct + '% (' + d.done.toLocaleString() + ' / ' + d.total.toLocaleString() + ' apps)')
                        : <?php echo json_encode(t('admin_shadow-saas-grip.starting')); ?>;
                })
                .catch(function () { /* transient — keep polling */ });
        }, 2500);
    })();
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.mirror_tables_heading')); ?></h3>
    <table>
        <thead><tr><th><?php echo e(t('admin_shadow-saas-grip.th_table')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_rows')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_description')); ?></th><th style="width: 100px;"><?php echo e(t('admin_shadow-saas-grip.th_actions')); ?></th></tr></thead>
        <tbody>
            <tr><td><code>shadow_saas_grip_apps</code></td><td><?php echo number_format($counts['apps']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.mirror_apps_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_apps"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_grip_apps<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
            <tr><td><code>shadow_saas_grip_users</code></td><td><?php echo number_format($counts['users']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.mirror_users_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_users"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_grip_users<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
            <tr><td><code>shadow_saas_grip_alerts</code></td><td><?php echo number_format($counts['alerts']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.mirror_alerts_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_alerts"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_grip_alerts<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
        </tbody>
    </table>
</div>

<?php
// Write-back status: what FairTPRM last wrote into Grip, and primary contacts it
// could not set (the relationship manager is not an email/FairTPRM user, or is
// not a Grip user). Table may not exist before the v2.6.2 update has run.
$wbStats = null; $wbProblems = [];
try {
    $wbStats = $db->fetchOne("SELECT COUNT(*) AS apps,
            SUM(labels IS NOT NULL AND labels != '[]') AS labeled,
            SUM(contact_status = 'set') AS contact_set,
            SUM(contact_status IN ('not_grip_user', 'no_email')) AS contact_unresolved,
            SUM(last_error IS NOT NULL) AS errored,
            MAX(updated_at) AS last_at
        FROM shadow_saas_grip_writeback");
    $wbProblems = $db->fetchAll("SELECT w.contact_status, w.contact_source, w.last_error, v.id AS vendor_id, v.vendor_name
        FROM shadow_saas_grip_writeback w LEFT JOIN vendor_onboarding_requests v ON v.id = w.vendor_id
        WHERE w.contact_status IN ('not_grip_user', 'no_email', 'error') OR w.last_error IS NOT NULL
        ORDER BY w.updated_at DESC LIMIT 25");
} catch (Throwable $e) { $wbStats = null; }
?>
<?php if ($wbStats !== null && ($gripCfg['writeback_enabled'] ?? '0') === '1'): ?>
<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.writeback_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;"><?php echo e(t('admin_shadow-saas-grip.writeback_summary', (int)$wbStats['labeled'], (int)$wbStats['contact_set'], (int)$wbStats['contact_unresolved'], (int)$wbStats['errored'])); ?></p>
    <?php if ($wbProblems): ?>
    <table>
        <thead><tr><th><?php echo e(t('admin_shadow-saas-grip.writeback_th_vendor')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.writeback_th_rm')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.writeback_th_problem')); ?></th></tr></thead>
        <tbody>
        <?php foreach ($wbProblems as $wp): ?>
            <tr>
                <td><?php if (!empty($wp['vendor_id'])): ?><a href="vendor-onboarding.php?id=<?php echo (int)$wp['vendor_id']; ?>"><?php echo e($wp['vendor_name'] ?? ''); ?></a><?php else: ?>—<?php endif; ?></td>
                <td><?php echo e($wp['contact_source'] ?? '—'); ?></td>
                <td><?php echo e(in_array($wp['contact_status'], ['not_grip_user', 'no_email'], true) ? t('admin_shadow-saas-grip.writeback_' . $wp['contact_status']) : ($wp['last_error'] ?? '')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.api_reference_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;"><?php echo t('admin_shadow-saas-grip.grip_api_reference_intro'); ?></p>
    <table>
        <thead><tr><th><?php echo e(t('admin_shadow-saas-grip.th_method')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_path')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_purpose')); ?></th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas</code></td><td><?php echo t('admin_shadow-saas-grip.grip_ep_saas_list'); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{id}</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_saas_get')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{name}/id</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_saas_id')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/name/{name}</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_saas_by_name')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{id}/users</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_saas_users')); ?></td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/label</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_label_add')); ?></td></tr>
            <tr><td><span class="badge badge-danger">DELETE</span></td><td><code>/public/saas/{id}/label</code></td><td><?php echo e(t('admin_shadow-saas-grip.grip_ep_label_remove')); ?></td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/update-primary-contact</code></td><td><?php echo t('admin_shadow-saas-grip.grip_ep_primary_contact'); ?></td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/sanction_status</code></td><td><?php echo t('admin_shadow-saas-grip.grip_ep_sanction'); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/users</code></td><td><?php echo t('admin_shadow-saas-grip.grip_ep_users_list'); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/alerts</code></td><td><?php echo t('admin_shadow-saas-grip.grip_ep_alerts_list'); ?></td></tr>
        </tbody>
    </table>
    <?php
    // The tenant serves its own Swagger UI and live spec under /public. Derive
    // them from the configured base URL; skip the unconfigured placeholder.
    $gripDocsHost = '';
    $gripBaseParts = parse_url((string)($gripCfg['base_url'] ?? ''));
    if (($gripBaseParts['scheme'] ?? '') === 'https' && !empty($gripBaseParts['host'])
        && strcasecmp($gripBaseParts['host'], 'tenant.dep.grip.security') !== 0) {
        $gripDocsHost = 'https://' . $gripBaseParts['host'] . (isset($gripBaseParts['port']) ? ':' . (int)$gripBaseParts['port'] : '');
    }
    ?>
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="reference-doc.php?file=grip-openapi.json" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_openapi_json')); ?></a>
        <a href="reference-doc.php?file=grip-openapi.json&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_openapi_json')); ?></a>
        <a href="reference-doc.php?file=grip-openapi.yaml" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_openapi')); ?></a>
        <a href="reference-doc.php?file=grip-openapi.yaml&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_swagger_yaml')); ?></a>
        <a href="reference-doc.php?file=grip-postman-collection.json" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_postman')); ?></a>
        <a href="reference-doc.php?file=grip-postman-collection.json&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_postman_json')); ?></a>
        <a href="https://apidocs.grip.security/docs/api-docs/16b5e21308a0e-grip" target="_blank" rel="noopener" class="btn btn-secondary"><?php echo t('admin_shadow-saas-grip.official_grip_docs'); ?></a>
        <?php if ($gripDocsHost !== ''): ?>
        <a href="<?php echo e($gripDocsHost . '/public/docs'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.tenant_api_docs')); ?></a>
        <a href="<?php echo e($gripDocsHost . '/public/openapi.json'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.tenant_openapi_spec')); ?></a>
        <?php endif; ?>
    </div>
    <div class="form-help" style="margin-top: 10px;"><?php echo e(t('admin_shadow-saas-grip.reference_formats_help')); ?></div>
    <div class="form-help" style="margin-top: 10px;"><?php echo t('admin_shadow-saas-grip.grip_auth_help'); ?></div>
</div>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.about_grip_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;">
        <?php echo t('admin_shadow-saas-grip.about_grip_body'); ?>
    </p>
</div>

</div><!-- end shadowSection_grip -->

<div id="shadowSection_hero" <?php echo $shadowTab !== 'hero' ? 'style="display: none;"' : ''; ?>>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.hero_connection_heading')); ?></h3>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="hero_enabled" value="1" <?php echo ($heroCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.enable_hero')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.hero_enable_help')); ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="hero_base_url"><?php echo e(t('admin_shadow-saas-grip.hero_base_url_label')); ?></label>
                <input type="text" id="hero_base_url" name="hero_base_url" class="form-control" value="<?php echo e($heroCfg['base_url'] ?? ''); ?>" placeholder="https://api.herosecurity.ai/stable">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.hero_base_url_help'); ?></div>
            </div>
            <div class="form-group">
                <label for="hero_client_id"><?php echo e(t('admin_shadow-saas-grip.client_id_label')); ?></label>
                <input type="text" id="hero_client_id" name="hero_client_id" class="form-control" value="<?php echo e($heroCfg['client_id'] ?? ''); ?>" placeholder="hero_xxxxxxxxxxxxxxxx" autocomplete="off">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.hero_client_id_help'); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="hero_client_secret"><?php echo e(t('admin_shadow-saas-grip.client_secret_label')); ?></label>
                <input type="password" id="hero_client_secret" name="hero_client_secret" class="form-control" placeholder="<?php echo !empty($heroCfg['client_secret']) ? '••••••••••••••••' : e(t('admin_shadow-saas-grip.hero_client_secret_placeholder')); ?>" autocomplete="new-password">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.hero_client_secret_help'); ?></div>
            </div>
        </div>
        <details style="margin-top: 4px;">
            <summary style="cursor: pointer; color: #6b7280; font-size: 13px;"><?php echo e(t('admin_shadow-saas-grip.sync_pacing_summary')); ?></summary>
            <p class="form-help" style="margin: 8px 0;"><?php echo e(t('admin_shadow-saas-grip.hero_pacing_help')); ?></p>
            <div class="form-row">
                <div class="form-group">
                    <label for="hero_throttle_ms"><?php echo e(t('admin_shadow-saas-grip.hero_throttle_label')); ?></label>
                    <input type="number" id="hero_throttle_ms" name="hero_throttle_ms" class="form-control" min="0" max="10000" step="50" value="<?php echo e($heroCfg['throttle_ms'] ?? '1100'); ?>">
                    <div class="form-help"><?php echo t('admin_shadow-saas-grip.hero_throttle_help'); ?></div>
                </div>
                <div class="form-group">
                    <label for="hero_users_timeout"><?php echo e(t('admin_shadow-saas-grip.hero_timeout_label')); ?></label>
                    <input type="number" id="hero_users_timeout" name="hero_users_timeout" class="form-control" min="5" max="120" value="<?php echo e($heroCfg['users_timeout'] ?? '15'); ?>">
                    <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.hero_timeout_help')); ?></div>
                </div>
            </div>
        </details>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_hero" class="btn btn-primary"><?php echo e(t('admin_shadow-saas-grip.save_configuration')); ?></button>
            <button type="submit" name="test_hero_connection" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.test_connection')); ?></button>
        </div>
    </form>
</div>

<?php if ($heroLastSync): ?>
<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.last_sync_heading')); ?></h3>
    <table>
        <tr><th style="width: 220px;"><?php echo e(t('admin_shadow-saas-grip.th_started')); ?></th><td><?php echo e($heroLastSync['started_at']); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_finished')); ?></th><td><?php echo $heroLastSync['finished_at'] ? e($heroLastSync['finished_at']) : t('admin_shadow-saas-grip.still_running'); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_trigger')); ?></th><td><?php echo e($heroLastSync['trigger_source']); ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_status')); ?></th><td><span class="badge <?php echo $heroLastSync['status'] === 'success' ? 'badge-success' : ($heroLastSync['status'] === 'error' ? 'badge-danger' : 'badge-blue'); ?>"><?php echo e($heroLastSync['status']); ?></span></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_vendors')); ?></th><td><?php echo (int)$heroLastSync['vendors_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_users')); ?></th><td><?php echo (int)$heroLastSync['users_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_issues')); ?></th><td><?php echo (int)$heroLastSync['issues_fetched']; ?></td></tr>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_shadow_saas_upserts')); ?></th><td><?php echo (int)$heroLastSync['shadow_saas_upserted']; ?></td></tr>
        <?php if (!empty($heroLastSync['error_message'])): ?>
        <tr><th><?php echo e(t('admin_shadow-saas-grip.th_error')); ?></th><td style="color: #991b1b;"><?php echo e($heroLastSync['error_message']); ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (($heroLastSync['status'] ?? '') === 'running'): ?>
    <form method="POST" action="admin.php?section=shadow-saas" style="margin-top: 12px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="sync_provider" value="hero">
        <button type="submit" name="abort_shadow_sync" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shadow-saas-grip.abort_hero_confirm')); ?>"><?php echo e(t('admin_shadow-saas-grip.stop_sync_button')); ?></button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.mirror_tables_heading')); ?></h3>
    <table>
        <thead><tr><th><?php echo e(t('admin_shadow-saas-grip.th_table')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_rows')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_description')); ?></th><th style="width: 100px;"><?php echo e(t('admin_shadow-saas-grip.th_actions')); ?></th></tr></thead>
        <tbody>
            <tr><td><code>shadow_saas_hero_vendors</code></td><td><?php echo number_format($heroCounts['vendors']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.hero_mirror_vendors_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_vendors"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_hero_vendors<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
            <tr><td><code>shadow_saas_hero_issues</code></td><td><?php echo number_format($heroCounts['issues']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.hero_mirror_issues_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_issues"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_hero_issues<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
            <tr><td><code>shadow_saas_hero_users</code></td><td><?php echo number_format($heroCounts['users']); ?></td><td><?php echo e(t('admin_shadow-saas-grip.hero_mirror_users_desc')); ?></td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_users"><button type="submit" name="truncate_mirror_table" data-confirm="<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_prefix')); ?>shadow_saas_hero_users<?php echo e(t('admin_shadow-saas-grip.truncate_confirm_suffix')); ?>" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;"><?php echo e(t('admin_shadow-saas-grip.truncate_button')); ?></button></form></td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.api_reference_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;"><?php echo t('admin_shadow-saas-grip.hero_api_reference_intro'); ?></p>
    <table>
        <thead><tr><th><?php echo e(t('admin_shadow-saas-grip.th_method')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_path')); ?></th><th><?php echo e(t('admin_shadow-saas-grip.th_purpose')); ?></th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-blue">POST</span></td><td><code>/v1/auth/token</code></td><td><?php echo e(t('admin_shadow-saas-grip.hero_ep_auth')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors</code></td><td><?php echo t('admin_shadow-saas-grip.hero_ep_vendors_list'); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors/{domain}</code></td><td><?php echo e(t('admin_shadow-saas-grip.hero_ep_vendor_get')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors/{domain}/users</code></td><td><?php echo e(t('admin_shadow-saas-grip.hero_ep_vendor_users')); ?></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/issues</code></td><td><?php echo e(t('admin_shadow-saas-grip.hero_ep_issues_list')); ?></td></tr>
        </tbody>
    </table>
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="reference-doc.php?file=hero-openapi.yaml" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_openapi')); ?></a>
        <a href="reference-doc.php?file=hero-openapi.yaml&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_swagger_yaml')); ?></a>
        <a href="https://herosecurity.ai/" target="_blank" rel="noopener" class="btn btn-secondary"><?php echo t('admin_shadow-saas-grip.hero_security_link'); ?></a>
    </div>
    <div class="form-help" style="margin-top: 10px;"><?php echo t('admin_shadow-saas-grip.hero_auth_help'); ?></div>
</div>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.about_hero_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6;">
        <?php echo t('admin_shadow-saas-grip.about_hero_body'); ?>
    </p>
</div>

</div><!-- end shadowSection_hero -->

<!-- ============================================================ -->
<!-- Shared across providers: Scheduled Rehydration + Zscaler.     -->
<!-- Always visible regardless of the selected provider tab.       -->
<!-- ============================================================ -->
<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.sched_rehydration_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;"><?php echo t('admin_shadow-saas-grip.sched_rehydration_intro'); ?></p>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="schedule">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="rehydrate_enabled" value="1" <?php echo $rehydrateEnabled === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.enable_scheduled_rehydration')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.sched_enable_help')); ?></div>
        </div>
        <div class="form-group" style="max-width: 400px;">
            <label for="rehydrate_schedule"><?php echo e(t('admin_shadow-saas-grip.schedule_cron_label')); ?></label>
            <input type="text" id="rehydrate_schedule" name="rehydrate_schedule" class="form-control" style="font-family: monospace;" value="<?php echo e($rehydrateSchedule); ?>" placeholder="0 2 * * *">
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.schedule_help_prefix')); ?><strong><?php echo e(describeCron($rehydrateSchedule)); ?></strong><?php echo t('admin_shadow-saas-grip.schedule_help_suffix'); ?></div>
        </div>
        <div class="form-group" style="margin-top: 18px;">
            <label class="checkbox-label">
                <input type="checkbox" name="discovery_enabled" value="1" <?php echo $discoveryEnabled === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.enable_discovery')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.discovery_help')); ?></div>
        </div>
        <div class="form-group" style="max-width: 400px;">
            <label for="discovery_schedule"><?php echo e(t('admin_shadow-saas-grip.discovery_cron_label')); ?></label>
            <input type="text" id="discovery_schedule" name="discovery_schedule" class="form-control" style="font-family: monospace;" value="<?php echo e($discoverySchedule); ?>" placeholder="*/15 * * * *">
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.schedule_help_prefix')); ?><strong><?php echo e(describeCron($discoverySchedule)); ?></strong><?php if ($discoveryLast): ?> &middot; <?php echo e(t('admin_shadow-saas-grip.discovery_last_run', date('M j, Y H:i', strtotime($discoveryLast['started_at'])), (string)$discoveryLast['status'], (int)$discoveryLast['success_count'])); ?><?php endif; ?></div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 14px; align-items: center; flex-wrap: wrap;">
            <button type="submit" name="update_grip" class="btn btn-primary"><?php echo e(t('admin_shadow-saas-grip.save_schedule_button')); ?></button>
            <button type="submit" name="run_grip_now" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_shadow-saas-grip.run_now_confirm')); ?>"><?php echo e(t('admin_shadow-saas-grip.run_now_button')); ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_shadow-saas-grip.zscaler_connection_heading')); ?></h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;"><?php echo t('admin_shadow-saas-grip.zscaler_intro'); ?></p>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="zscaler_form" value="connection">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="zscaler_enabled" value="1" <?php echo ($zscalerCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_shadow-saas-grip.enable_zscaler')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.zscaler_enable_help')); ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_api_url"><?php echo e(t('admin_shadow-saas-grip.api_url_label')); ?></label>
                <input type="text" id="zscaler_api_url" name="zscaler_api_url" class="form-control" value="<?php echo e($zscalerCfg['api_url'] ?? ''); ?>" placeholder="https://api.zsapi.net">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.zscaler_api_url_help'); ?></div>
            </div>
            <div class="form-group">
                <label for="zscaler_vanity_domain"><?php echo e(t('admin_shadow-saas-grip.zscaler_vanity_label')); ?></label>
                <input type="text" id="zscaler_vanity_domain" name="zscaler_vanity_domain" class="form-control" value="<?php echo e($zscalerCfg['vanity_domain'] ?? ''); ?>" placeholder="fairtprm.zslogin.net">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.zscaler_vanity_help'); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_username"><?php echo e(t('admin_shadow-saas-grip.client_id_label')); ?></label>
                <input type="text" id="zscaler_username" name="zscaler_username" class="form-control" value="<?php echo e($zscalerCfg['username'] ?? ''); ?>" placeholder="<?php echo e(t('admin_shadow-saas-grip.zscaler_username_placeholder')); ?>" autocomplete="off">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.zscaler_username_help'); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_password"><?php echo e(t('admin_shadow-saas-grip.client_secret_label')); ?></label>
                <input type="password" id="zscaler_password" name="zscaler_password" class="form-control" placeholder="<?php echo !empty($zscalerCfg['password']) ? '••••••••••••••••' : e(t('admin_shadow-saas-grip.zscaler_password_placeholder')); ?>" autocomplete="new-password">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.zscaler_password_help'); ?></div>
            </div>
            <div class="form-group">
                <label for="zscaler_url_category"><?php echo e(t('admin_shadow-saas-grip.url_category_label')); ?></label>
                <input type="text" id="zscaler_url_category" name="zscaler_url_category" class="form-control" value="<?php echo e($zscalerCfg['url_category'] ?? ''); ?>" placeholder="URL_CATEGORY_BLOCK_SHADOW_SAAS_BLOCK">
                <div class="form-help"><?php echo t('admin_shadow-saas-grip.url_category_help'); ?></div>
            </div>
        </div>
        <details style="margin-top: 4px;">
            <summary style="cursor: pointer; color: #6b7280; font-size: 13px;"><?php echo e(t('admin_shadow-saas-grip.legacy_auth_summary')); ?></summary>
            <div class="form-group" style="margin-top: 10px;">
                <label for="zscaler_api_key"><?php echo e(t('admin_shadow-saas-grip.api_key_label')); ?></label>
                <input type="password" id="zscaler_api_key" name="zscaler_api_key" class="form-control" placeholder="<?php echo !empty($zscalerCfg['api_key']) ? '••••••••••••••••' : e(t('admin_shadow-saas-grip.zscaler_api_key_placeholder')); ?>" autocomplete="new-password">
                <div class="form-help"><?php echo e(t('admin_shadow-saas-grip.zscaler_api_key_help')); ?></div>
            </div>
        </details>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_zscaler" class="btn btn-primary"><?php echo e(t('admin_shadow-saas-grip.save_configuration')); ?></button>
            <button type="submit" name="test_zscaler_connection" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.test_connection')); ?></button>
        </div>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function showShadowSection(section) {
    var sections = ['grip', 'hero'];
    if (sections.indexOf(section) === -1) section = 'grip';
    sections.forEach(function(s) {
        var el = document.getElementById('shadowSection_' + s);
        var tab = document.getElementById('shadowTab_' + s);
        if (el) el.style.display = (s === section) ? '' : 'none';
        if (tab) {
            tab.style.borderBottomColor = (s === section) ? 'var(--theme-header-color, #2563eb)' : 'transparent';
            tab.style.color = (s === section) ? 'var(--theme-header-color, #2563eb)' : '#6b7280';
            tab.style.fontWeight = (s === section) ? '600' : '500';
        }
    });
    // Remember the active provider tab so a form submit (Save / Test / Run Now),
    // which reloads the page, returns the admin to the SAME tab instead of
    // bouncing back to Grip. The Scheduled Rehydration + Zscaler cards are shared
    // and always visible, so saving them also keeps the current tab.
    try { localStorage.setItem('shadowSaasTab', section); } catch (e) {}
}
// Restore the last-used tab on load (after any POST reload).
(function () {
    var saved = null;
    try { saved = localStorage.getItem('shadowSaasTab'); } catch (e) {}
    if (saved === 'grip' || saved === 'hero') {
        showShadowSection(saved);
    }
})();
</script>
