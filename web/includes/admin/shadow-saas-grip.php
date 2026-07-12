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
            $formId = ($_POST['grip_form'] ?? 'connection') === 'schedule' ? 'schedule' : 'connection';
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

                $newToken = trim($_POST['grip_api_token'] ?? '');
                if ($newToken !== '') {
                    $settings['grip_api_token'] = $encryption->encrypt($newToken);
                }

                // Mutual exclusion: Grip and Hero can't both be enabled.
                if ($settings['grip_enabled'] === '1') {
                    $settings['hero_enabled'] = '0';
                }
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
            if ($formId === 'schedule') {
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
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="reference-doc.php?file=grip-openapi.yaml" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_openapi')); ?></a>
        <a href="reference-doc.php?file=grip-openapi.yaml&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_swagger_yaml')); ?></a>
        <a href="reference-doc.php?file=grip-postman-collection.json" target="_blank" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.view_postman')); ?></a>
        <a href="reference-doc.php?file=grip-postman-collection.json&amp;download=1" class="btn btn-secondary"><?php echo e(t('admin_shadow-saas-grip.download_postman_json')); ?></a>
        <a href="https://apidocs.grip.security/docs/api-docs/16b5e21308a0e-grip" target="_blank" rel="noopener" class="btn btn-secondary"><?php echo t('admin_shadow-saas-grip.official_grip_docs'); ?></a>
    </div>
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
