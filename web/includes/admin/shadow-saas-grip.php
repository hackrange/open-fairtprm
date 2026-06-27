<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Shadow SaaS — Grip Security integration.
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
// settings that belong to that form — saving the connection card no longer
// resets the cron schedule, and vice versa.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grip'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
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
                    throw new Exception('Server URL must be a valid http(s) URL.');
                }
                // SECURITY (SSRF): reject cloud-metadata / loopback hosts (the API token is
                // sent to this endpoint). Private LAN hosts remain allowed for on-prem Grip.
                if (!isConfigUrlHostSafe($rawBase)) {
                    throw new Exception('Server URL host is not allowed.');
                }

                $settings['grip_enabled']  = isset($_POST['grip_enabled']) ? '1' : '0';
                $settings['grip_base_url'] = $rawBase;
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
                    throw new Exception('Invalid cron expression: must be 5 space-separated fields (e.g. 0 2 * * *).');
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
                    $success = 'Rehydration schedule updated and crontab refreshed.';
                } else {
                    $error = 'Schedule saved, but failed to write /etc/cron.d/open-tprm. Check permissions.';
                }
            } else {
                $success = 'Grip configuration updated successfully.';
            }
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating Grip config: ' . $e->getMessage());
            $error = 'Failed to update Grip configuration: ' . $e->getMessage();
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
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();
            $settings = [];

            $rawUrl = rtrim(trim($_POST['zscaler_api_url'] ?? ''), '/');
            if ($rawUrl !== '' && !preg_match('#^https?://[^\s]+$#i', $rawUrl)) {
                throw new Exception('API URL must be a valid http(s) URL.');
            }
            // SECURITY (SSRF): reject cloud-metadata / loopback hosts (credentials are POSTed
            // to this endpoint). Private LAN hosts remain allowed for on-prem Zscaler proxies.
            if ($rawUrl !== '' && !isConfigUrlHostSafe($rawUrl)) {
                throw new Exception('API URL host is not allowed.');
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
            $success = 'Zscaler configuration updated successfully.';
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating Zscaler config: ' . $e->getMessage());
            $error = 'Failed to update Zscaler configuration: ' . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: test Zscaler connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_zscaler_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $svc = new ZscalerService();
            $result = $svc->testConnection();
            if ($result['success']) {
                $success = 'Zscaler connection test: ' . $result['message'];
            } else {
                $error = 'Zscaler connection test failed: ' . $result['message'];
            }
        } catch (Throwable $e) {
            error_log('Zscaler connection test error: ' . $e->getMessage());
            $error = 'Zscaler connection test error. Check the error log for details.';
        }
    }
}

// ============================================================================
// POST: test Grip connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_grip_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $svc = new GripService();
            $result = $svc->testConnection();
            if ($result['success']) {
                $success = 'Grip connection test: ' . $result['message'];
            } else {
                $error = 'Grip connection test failed: ' . $result['message'];
            }
        } catch (Throwable $e) {
            error_log('Grip connection test error: ' . $e->getMessage());
            $error = 'Grip connection test error. Check the error log for details.';
        }
    }
}

// ============================================================================
// POST: Run Now (background hydration)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_grip_now'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $cronScript = realpath(__DIR__ . '/../../cron/shadow-saas-sync.php');
        if (!$cronScript || !is_file($cronScript)) {
            $error = 'Shadow SaaS sync script not found at cron/shadow-saas-sync.php.';
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
            $success = 'Shadow SaaS rehydration started in the background. Refresh in a few seconds to see the latest run.';
        }
    }
}

// ============================================================================
// POST: abort a running sync (cooperative — flips the run's status to 'aborting';
// the driver notices within a few iterations, stops, and releases its lock).
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abort_shadow_sync'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $provider = (($_POST['sync_provider'] ?? '') === 'hero') ? 'hero' : 'grip';
        $table = $provider === 'hero' ? 'shadow_saas_hero_sync_log' : 'shadow_saas_grip_sync_log';
        try {
            $n = $db->update($table, ['status' => 'aborting'], "status = 'running'");
            $auth->audit($user['id'], 'shadow_saas_abort_sync', $table, null, ['provider' => $provider]);
            $success = $n > 0
                ? ucfirst($provider) . ' sync abort requested — it will stop within a few seconds.'
                : 'No running ' . $provider . ' sync to abort.';
        } catch (Throwable $e) {
            $error = 'Failed to abort sync: ' . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: truncate a Shadow SaaS mirror table (admin maintenance). Whitelisted
// table names ONLY — the table name is never taken from raw user input.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['truncate_mirror_table'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $allowedTables = [
            'shadow_saas_grip_apps', 'shadow_saas_grip_users', 'shadow_saas_grip_alerts',
            'shadow_saas_hero_vendors', 'shadow_saas_hero_issues', 'shadow_saas_hero_users',
        ];
        $tbl = (string)($_POST['mirror_table'] ?? '');
        if (!in_array($tbl, $allowedTables, true)) {
            $error = 'Unknown mirror table.';
        } else {
            try {
                // $tbl is constrained to the whitelist above — safe to interpolate.
                $db->query("TRUNCATE TABLE `{$tbl}`");
                $auth->audit($user['id'], 'shadow_saas_truncate_mirror', $tbl, null, ['table' => $tbl]);
                $success = 'Mirror table ' . e($tbl) . ' truncated.';
            } catch (Throwable $e) {
                error_log('Truncate mirror failed: ' . $e->getMessage());
                $error = 'Failed to truncate ' . e($tbl) . '.';
            }
        }
    }
}

// ============================================================================
// POST: save HERO settings (mutually exclusive with Grip)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hero'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();
            $rawBase = trim($_POST['hero_base_url'] ?? '');
            if ($rawBase === '') $rawBase = 'https://api.herosecurity.ai/stable';
            $rawBase = rtrim($rawBase, '/');
            if (!preg_match('#^https?://[^\s]+$#i', $rawBase)) {
                throw new Exception('Server URL must be a valid http(s) URL.');
            }
            if (!isConfigUrlHostSafe($rawBase)) {
                throw new Exception('Server URL host is not allowed.');
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
            $success = 'HERO configuration updated successfully.' . ($heroEnabled === '1' ? ' Grip was disabled — only one provider can be active at a time.' : '');
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('Error updating HERO config: ' . $e->getMessage());
            $error = 'Failed to update HERO configuration: ' . $e->getMessage();
        }
    }
}

// ============================================================================
// POST: test HERO connection
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_hero_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $svc = new HeroService();
            $res = $svc->testConnection();
            if (!empty($res['success'])) { $success = $res['message']; }
            else { $error = 'HERO test failed: ' . ($res['message'] ?? 'unknown error'); }
        } catch (Throwable $e) {
            $error = 'HERO test failed: ' . $e->getMessage();
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
$counts = ['apps' => 0, 'users' => 0, 'alerts' => 0];
foreach ([['shadow_saas_grip_apps','apps'],['shadow_saas_grip_users','users'],['shadow_saas_grip_alerts','alerts']] as [$tbl,$key]) {
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

// Shared rehydration schedule — stored under the cron_* convention and installed
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
    <p>Configure third-party Shadow SaaS data providers. Discovered SaaS apps populate the existing Shadow SaaS list with normalized vendor domains.</p>
</div>

<!-- Horizontal tab navigation: one tab per Shadow SaaS provider (Grip / Hero). -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showShadowSection" data-arg="grip" id="shadowTab_grip" class="shadow-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shadowTab === 'grip' ? '600' : '500'; ?>; color: <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Grip<?php echo ($gripCfg['enabled'] ?? '0') === '1' ? ' &#9679;' : ''; ?></button>
    <button type="button" data-action="showShadowSection" data-arg="hero" id="shadowTab_hero" class="shadow-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shadowTab === 'hero' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shadowTab === 'hero' ? '600' : '500'; ?>; color: <?php echo $shadowTab === 'hero' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Hero<?php echo ($heroCfg['enabled'] ?? '0') === '1' ? ' &#9679;' : ''; ?></button>
</div>
<div class="form-help" style="margin: -12px 0 18px;">Grip and Hero are mutually exclusive — enabling one disables the other. The active provider (&#9679;) feeds the shared Zscaler blocking and Scheduled Rehydration below.</div>

<div id="shadowSection_grip" <?php echo $shadowTab !== 'grip' ? 'style="display: none;"' : ''; ?>>

<div class="card">
    <h3>Grip Security Connection</h3>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="connection">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_enabled" value="1" <?php echo ($gripCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable Grip Security integration</span>
            </label>
            <div class="form-help">When enabled, scheduled and on-demand syncs will pull SaaS apps, users, and alerts from your Grip tenant into the Shadow SaaS list.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="grip_base_url">Server (Tenant Base URL)</label>
                <input type="text" id="grip_base_url" name="grip_base_url" class="form-control" value="<?php echo e($gripCfg['base_url'] ?? ''); ?>" placeholder="https://tenant.dep.grip.security/public/saas">
                <div class="form-help">Tenant-prefixed Grip URL rooted at <code>/public/saas</code> (e.g. <code>https://tenant.dep.grip.security/public/saas</code>). Sibling endpoints (<code>/public/users</code>, <code>/public/alerts</code>) are derived from this host.</div>
            </div>
            <div class="form-group">
                <label for="grip_api_token">API Token</label>
                <input type="password" id="grip_api_token" name="grip_api_token" class="form-control" placeholder="<?php echo !empty($gripCfg['api_token']) ? '••••••••••••••••' : 'Enter administrator API key'; ?>">
                <div class="form-help">Sent as the <code>access-token</code> HTTP header on every request. Leave blank to keep the existing token.</div>
            </div>
        </div>
        <div class="form-group" style="margin-top: 4px;">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_breach_sync_enabled" value="1" <?php echo ($gripCfg['breach_sync_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Flow Grip breach information into Breach&nbsp;/&nbsp;Cyber Alerts</span>
            </label>
            <div class="form-help">On each scheduled rehydration (and the manual <strong>Run&nbsp;Now</strong> below), Grip "Security Incident Detected" alerts are written into the <a href="breach-alerts.php">Breach&nbsp;/&nbsp;Cyber Alerts</a> system (deduplicated). Breaches for an onboarded vendor are linked to that vendor; breaches for an app with no onboarded vendor are still recorded and tagged <strong>Shadow&nbsp;SaaS</strong> with the number of users potentially impacted. Requires Grip enabled (above) <em>and</em> the Breach&nbsp;/&nbsp;Cyber Alert setting under <a href="admin.php?section=email">Email &amp; Alerts</a>.</div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_grip" class="btn btn-primary">Save Configuration</button>
            <button type="submit" name="test_grip_connection" class="btn btn-secondary">Test Connection</button>
        </div>
    </form>
</div>

<?php if ($lastSync): ?>
<div class="card">
    <h3>Last Sync</h3>
    <table>
        <tr><th style="width: 220px;">Started</th><td><?php echo e($lastSync['started_at']); ?></td></tr>
        <tr><th>Finished</th><td><?php echo $lastSync['finished_at'] ? e($lastSync['finished_at']) : '<em>still running</em>'; ?></td></tr>
        <tr><th>Trigger</th><td><?php echo e($lastSync['trigger_source']); ?></td></tr>
        <tr><th>Status</th><td><span class="badge <?php echo $lastSync['status'] === 'success' ? 'badge-success' : ($lastSync['status'] === 'error' ? 'badge-danger' : 'badge-blue'); ?>"><?php echo e($lastSync['status']); ?></span></td></tr>
        <tr><th>SaaS apps</th><td><?php echo (int)$lastSync['apps_fetched']; ?></td></tr>
        <tr><th>Users</th><td><?php echo (int)$lastSync['users_fetched']; ?></td></tr>
        <tr><th>Alerts</th><td><?php echo (int)$lastSync['alerts_fetched']; ?></td></tr>
        <tr><th>Shadow SaaS upserts</th><td><?php echo (int)$lastSync['shadow_saas_upserted']; ?></td></tr>
        <?php if (!empty($lastSync['error_message'])): ?>
        <tr><th>Error</th><td style="color: #991b1b;"><?php echo e($lastSync['error_message']); ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (($lastSync['status'] ?? '') === 'running'): ?>
    <form method="POST" action="admin.php?section=shadow-saas" style="margin-top: 12px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="sync_provider" value="grip">
        <button type="submit" name="abort_shadow_sync" class="btn btn-secondary" data-confirm="Abort the running Grip sync?">Stop Sync</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>Mirror Tables</h3>
    <table>
        <thead><tr><th>Table</th><th>Rows</th><th>Description</th><th style="width: 100px;">Actions</th></tr></thead>
        <tbody>
            <tr><td><code>shadow_saas_grip_apps</code></td><td><?php echo number_format($counts['apps']); ?></td><td>One row per Grip SaaS application (full payload + flattened fields).</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_apps"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_grip_apps? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
            <tr><td><code>shadow_saas_grip_users</code></td><td><?php echo number_format($counts['users']); ?></td><td>One row per Grip-discovered user.</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_users"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_grip_users? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
            <tr><td><code>shadow_saas_grip_alerts</code></td><td><?php echo number_format($counts['alerts']); ?></td><td>One row per Grip alert with severity, status, and related entity.</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_grip_alerts"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_grip_alerts? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>API Reference</h3>
    <p style="color: #666; line-height: 1.6;">Endpoint inventory for the Grip Security public API (server base <code>https://&lt;tenant&gt;.dep.grip.security/public</code>, auto-generated OpenAPI 3.1). The FairTPRM sync consumes the read (GET) endpoints marked <em>used by sync</em>; the complete inventory &mdash; including the Users, Workflows, Posture&nbsp;Management, AuditLogs, Alerts / Alerts&nbsp;V2, Actions, and AI&nbsp;Agents groups &mdash; is in the OpenAPI and Postman downloads below. Domain extraction normalizes URLs to their registrable domain (e.g. <code>https://www.united.com</code> → <code>united.com</code>, <code>http://color.redhat.com</code> → <code>redhat.com</code>).</p>
    <table>
        <thead><tr><th>Method</th><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas</code></td><td>List SaaS apps (paginated; <code>offset</code> + <code>limit</code>). <em>Used by sync.</em></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{id}</code></td><td>Get a single SaaS app by Grip UUID.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{name}/id</code></td><td>Get a SaaS app's Grip ID by display name.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/name/{name}</code></td><td>Get full SaaS details by display name.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{id}/users</code></td><td>List users of a SaaS application.</td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/label</code></td><td>Add a label to a SaaS app.</td></tr>
            <tr><td><span class="badge badge-danger">DELETE</span></td><td><code>/public/saas/{id}/label</code></td><td>Remove a label from a SaaS app.</td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/update-primary-contact</code></td><td>Set the primary contact (<code>user_id</code>).</td></tr>
            <tr><td><span class="badge badge-success">POST</span></td><td><code>/public/saas/{id}/sanction_status</code></td><td>Update sanction state (<code>new_sanction_state</code>).</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/users</code></td><td>List discovered users. <em>Used by sync.</em></td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/alerts</code></td><td>List security alerts (severity, mitigation, related entity). <em>Used by sync.</em></td></tr>
        </tbody>
    </table>
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="reference-doc.php?file=grip-openapi.yaml" target="_blank" class="btn btn-secondary">View OpenAPI / Swagger</a>
        <a href="reference-doc.php?file=grip-openapi.yaml&amp;download=1" class="btn btn-secondary">Download Swagger (YAML)</a>
        <a href="reference-doc.php?file=grip-postman-collection.json" target="_blank" class="btn btn-secondary">View Postman Collection</a>
        <a href="reference-doc.php?file=grip-postman-collection.json&amp;download=1" class="btn btn-secondary">Download Postman (JSON)</a>
        <a href="https://apidocs.grip.security/docs/api-docs/16b5e21308a0e-grip" target="_blank" rel="noopener" class="btn btn-secondary">Official Grip Docs &rarr;</a>
    </div>
    <div class="form-help" style="margin-top: 10px;">Authentication header: <code>access-token: &lt;your-token&gt;</code>. The Postman collection's <code>accessToken</code> variable is set to <code>REPLACE_ME</code>; substitute your tenant token before sending requests.</div>
</div>

<div class="card">
    <h3>About the Grip Integration</h3>
    <p style="color: #666; line-height: 1.6;">
        Grip Security discovers and continuously monitors SaaS-of-Record applications across your environment.
        This integration:<br><br>
        <strong>&bull; Mirrors Grip data:</strong> Pulls the full SaaS / users / alerts inventory into <code>shadow_saas_grip_*</code> tables.<br>
        <strong>&bull; Hydrates Shadow SaaS:</strong> Projects each SaaS app into the existing Shadow SaaS list, normalizing the URL to its registrable domain so vendor matching works the same as a CSV import.<br>
        <strong>&bull; Tracks risk:</strong> Persists Grip's risk score, sanction tag, OAuth-scope counts, AI-depth classification, MFA / SAML support, and primary-contact metadata.<br>
        <strong>&bull; Stays fresh:</strong> Re-runs on the configured cron schedule; admins can also trigger an immediate background run with the "Run Now" button above.
    </p>
</div>

</div><!-- end shadowSection_grip -->

<div id="shadowSection_hero" <?php echo $shadowTab !== 'hero' ? 'style="display: none;"' : ''; ?>>

<div class="card">
    <h3>HERO Security Connection</h3>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="hero_enabled" value="1" <?php echo ($heroCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable HERO Security integration</span>
            </label>
            <div class="form-help">When enabled, scheduled and on-demand syncs pull vendors, issues, and per-vendor users from HERO into the Shadow SaaS list. Enabling HERO disables Grip — only one provider can be active at a time.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="hero_base_url">Server (Base URL)</label>
                <input type="text" id="hero_base_url" name="hero_base_url" class="form-control" value="<?php echo e($heroCfg['base_url'] ?? ''); ?>" placeholder="https://api.herosecurity.ai/stable">
                <div class="form-help">HERO public API base, e.g. <code>https://api.herosecurity.ai/stable</code>. Endpoints (<code>/v1/vendors</code>, <code>/v1/issues</code>) are derived from this host.</div>
            </div>
            <div class="form-group">
                <label for="hero_client_id">Client ID</label>
                <input type="text" id="hero_client_id" name="hero_client_id" class="form-control" value="<?php echo e($heroCfg['client_id'] ?? ''); ?>" placeholder="hero_xxxxxxxxxxxxxxxx" autocomplete="off">
                <div class="form-help">OAuth 2.0 <code>client_id</code> from the HERO admin panel.</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="hero_client_secret">Client Secret</label>
                <input type="password" id="hero_client_secret" name="hero_client_secret" class="form-control" placeholder="<?php echo !empty($heroCfg['client_secret']) ? '••••••••••••••••' : 'client_secret (shown once at creation)'; ?>" autocomplete="new-password">
                <div class="form-help">OAuth 2.0 <code>client_secret</code>, exchanged for a short-lived Bearer token (client-credentials grant). Stored encrypted. Leave blank to keep the existing value.</div>
            </div>
        </div>
        <details style="margin-top: 4px;">
            <summary style="cursor: pointer; color: #6b7280; font-size: 13px;">Sync pacing (advanced)</summary>
            <p class="form-help" style="margin: 8px 0;">HERO rate-limits API calls (~60 requests per minute, account-wide). These control how the per-vendor user sync paces itself.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="hero_throttle_ms">Throttle between vendor calls (ms)</label>
                    <input type="number" id="hero_throttle_ms" name="hero_throttle_ms" class="form-control" min="0" max="10000" step="50" value="<?php echo e($heroCfg['throttle_ms'] ?? '1100'); ?>">
                    <div class="form-help">Pause between each vendor's user request. ~1100&nbsp;ms keeps a full sync just under HERO's ~60/min limit. Lower is faster but triggers rate-limit (429) backoffs.</div>
                </div>
                <div class="form-group">
                    <label for="hero_users_timeout">Users fetch timeout (seconds)</label>
                    <input type="number" id="hero_users_timeout" name="hero_users_timeout" class="form-control" min="5" max="120" value="<?php echo e($heroCfg['users_timeout'] ?? '15'); ?>">
                    <div class="form-help">How long to wait for one vendor's users before skipping it. Lower means very large tenants that hang fail fast instead of stalling the run for the full timeout.</div>
                </div>
            </div>
        </details>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_hero" class="btn btn-primary">Save Configuration</button>
            <button type="submit" name="test_hero_connection" class="btn btn-secondary">Test Connection</button>
        </div>
    </form>
</div>

<?php if ($heroLastSync): ?>
<div class="card">
    <h3>Last Sync</h3>
    <table>
        <tr><th style="width: 220px;">Started</th><td><?php echo e($heroLastSync['started_at']); ?></td></tr>
        <tr><th>Finished</th><td><?php echo $heroLastSync['finished_at'] ? e($heroLastSync['finished_at']) : '<em>still running</em>'; ?></td></tr>
        <tr><th>Trigger</th><td><?php echo e($heroLastSync['trigger_source']); ?></td></tr>
        <tr><th>Status</th><td><span class="badge <?php echo $heroLastSync['status'] === 'success' ? 'badge-success' : ($heroLastSync['status'] === 'error' ? 'badge-danger' : 'badge-blue'); ?>"><?php echo e($heroLastSync['status']); ?></span></td></tr>
        <tr><th>Vendors</th><td><?php echo (int)$heroLastSync['vendors_fetched']; ?></td></tr>
        <tr><th>Users</th><td><?php echo (int)$heroLastSync['users_fetched']; ?></td></tr>
        <tr><th>Issues</th><td><?php echo (int)$heroLastSync['issues_fetched']; ?></td></tr>
        <tr><th>Shadow SaaS upserts</th><td><?php echo (int)$heroLastSync['shadow_saas_upserted']; ?></td></tr>
        <?php if (!empty($heroLastSync['error_message'])): ?>
        <tr><th>Error</th><td style="color: #991b1b;"><?php echo e($heroLastSync['error_message']); ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (($heroLastSync['status'] ?? '') === 'running'): ?>
    <form method="POST" action="admin.php?section=shadow-saas" style="margin-top: 12px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="sync_provider" value="hero">
        <button type="submit" name="abort_shadow_sync" class="btn btn-secondary" data-confirm="Abort the running HERO sync?">Stop Sync</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>Mirror Tables</h3>
    <table>
        <thead><tr><th>Table</th><th>Rows</th><th>Description</th><th style="width: 100px;">Actions</th></tr></thead>
        <tbody>
            <tr><td><code>shadow_saas_hero_vendors</code></td><td><?php echo number_format($heroCounts['vendors']); ?></td><td>One row per HERO vendor (status, aspects, derived 1-5 risk score, full payload).</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_vendors"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_hero_vendors? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
            <tr><td><code>shadow_saas_hero_issues</code></td><td><?php echo number_format($heroCounts['issues']); ?></td><td>One row per HERO security issue (type, severity, status, vendor).</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_issues"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_hero_issues? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
            <tr><td><code>shadow_saas_hero_users</code></td><td><?php echo number_format($heroCounts['users']); ?></td><td>Primary contact per vendor — the highest email-activity user, used as the relationship manager.</td><td><form method="POST" action="admin.php?section=shadow-saas" style="margin:0;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="mirror_table" value="shadow_saas_hero_users"><button type="submit" name="truncate_mirror_table" data-confirm="Truncate shadow_saas_hero_users? This permanently deletes all mirrored rows in this table." style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;font-size:12px;border-radius:4px;cursor:pointer;white-space:nowrap;">Truncate</button></form></td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>API Reference</h3>
    <p style="color: #666; line-height: 1.6;">Endpoint inventory for the HERO public API used by this integration. Auth is OAuth 2.0 client-credentials &rarr; Bearer token; list endpoints use cursor pagination (<code>next_cursor</code> &rarr; <code>cursor</code>).</p>
    <table>
        <thead><tr><th>Method</th><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-blue">POST</span></td><td><code>/v1/auth/token</code></td><td>Exchange client_id + client_secret for a Bearer token.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors</code></td><td>List vendors (cursor paginated; <code>page_size</code> &le; 250).</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors/{domain}</code></td><td>Get a single vendor by canonical domain.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/vendors/{domain}/users</code></td><td>List users observed interacting with a vendor.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/v1/issues</code></td><td>List security issues with severity, status, and vendor.</td></tr>
        </tbody>
    </table>
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="reference-doc.php?file=hero-openapi.yaml" target="_blank" class="btn btn-secondary">View OpenAPI / Swagger</a>
        <a href="reference-doc.php?file=hero-openapi.yaml&amp;download=1" class="btn btn-secondary">Download Swagger (YAML)</a>
        <a href="https://herosecurity.ai/" target="_blank" rel="noopener" class="btn btn-secondary">HERO Security &rarr;</a>
    </div>
    <div class="form-help" style="margin-top: 10px;">Authentication: <code>Authorization: Bearer &lt;token&gt;</code> on all data endpoints. Tokens expire after ~1 hour.</div>
</div>

<div class="card">
    <h3>About the HERO Integration</h3>
    <p style="color: #666; line-height: 1.6;">
        HERO Security discovers third-party SaaS vendors and the issues affecting them across your environment.
        This integration:<br><br>
        <strong>&bull; Mirrors HERO data:</strong> Pulls vendors / issues / per-vendor users into <code>shadow_saas_hero_*</code> tables.<br>
        <strong>&bull; Hydrates Shadow SaaS:</strong> Projects each vendor into the existing Shadow SaaS list by registrable domain, so matching works the same as a CSV import.<br>
        <strong>&bull; Tracks risk:</strong> Derives a 1-5 risk score from the worst open issue per vendor (the same 1-5 scale Grip uses) and records the vendor's status and engagement aspects.<br>
        <strong>&bull; Stays fresh:</strong> Re-runs on the shared Scheduled Rehydration job; admins can also trigger an immediate run with the "Run Now" button below.
    </p>
</div>

</div><!-- end shadowSection_hero -->

<!-- ============================================================ -->
<!-- Shared across providers: Scheduled Rehydration + Zscaler.     -->
<!-- Always visible regardless of the selected provider tab.       -->
<!-- ============================================================ -->
<div class="card">
    <h3>Scheduled Rehydration</h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;">Runs the enabled Shadow SaaS provider (Grip or Hero) on a schedule, refreshing the mirror tables and the Shadow SaaS list. The schedule below is installed into the system crontab automatically &mdash; no manual crontab editing, and it survives container restarts.</p>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="schedule">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="rehydrate_enabled" value="1" <?php echo $rehydrateEnabled === '1' ? 'checked' : ''; ?>>
                <span>Enable scheduled rehydration</span>
            </label>
            <div class="form-help">When enabled, the job is installed into the system crontab and runs on the schedule below. A lock file prevents overlapping runs. The run uses whichever provider is enabled.</div>
        </div>
        <div class="form-group" style="max-width: 400px;">
            <label for="rehydrate_schedule">Schedule (cron expression)</label>
            <input type="text" id="rehydrate_schedule" name="rehydrate_schedule" class="form-control" style="font-family: monospace;" value="<?php echo e($rehydrateSchedule); ?>" placeholder="0 2 * * *">
            <div class="form-help">Five-field cron expression, evaluated in the Scheduler's timezone. Currently: <strong><?php echo e(describeCron($rehydrateSchedule)); ?></strong>. Examples: <code>0 2 * * *</code> (daily 2am), <code>0 */6 * * *</code> (every 6 hours), <code>0 2 * * 0</code> (weekly Sun 2am).</div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 14px; align-items: center; flex-wrap: wrap;">
            <button type="submit" name="update_grip" class="btn btn-primary">Save Schedule</button>
            <button type="submit" name="run_grip_now" class="btn btn-secondary" data-confirm="Kick off a Shadow SaaS rehydration now? It runs in the background; refresh this page in a few seconds to see the result.">Run Now</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Zscaler Connection</h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;">When a vendor is denied from the Shadow SaaS list, the vendor's domain is appended to the URL Category below in Zscaler ZIA (using <code>action=ADD_TO_LIST</code>), then activated. Shared by both providers.</p>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="zscaler_form" value="connection">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="zscaler_enabled" value="1" <?php echo ($zscalerCfg['enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable Zscaler URL-Category blocking on Deny</span>
            </label>
            <div class="form-help">When enabled, the Deny button in Shadow SaaS will push the vendor's domain into the configured URL Category. When disabled, Deny only updates local state.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_api_url">API URL</label>
                <input type="text" id="zscaler_api_url" name="zscaler_api_url" class="form-control" value="<?php echo e($zscalerCfg['api_url'] ?? ''); ?>" placeholder="https://api.zsapi.net">
                <div class="form-help">OneAPI base: <code>https://api.zsapi.net</code> (universal). Legacy per-cloud: <code>https://zsapi.&lt;cloud&gt;.net</code> (e.g. <code>zscaler</code>, <code>zscalerone</code>, <code>zscalertwo</code>, <code>zscalerthree</code>, <code>zscloud</code>).</div>
            </div>
            <div class="form-group">
                <label for="zscaler_vanity_domain">ZIdentity Vanity Domain</label>
                <input type="text" id="zscaler_vanity_domain" name="zscaler_vanity_domain" class="form-control" value="<?php echo e($zscalerCfg['vanity_domain'] ?? ''); ?>" placeholder="fairtprm.zslogin.net">
                <div class="form-help">Host of the ZIdentity tenant's OAuth token endpoint — token URL is <code>https://&lt;vanity&gt;/oauth2/v1/token</code>. Find this in the ZIdentity admin URL (the part before <code>.zslogin.net</code>).</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_username">Client ID</label>
                <input type="text" id="zscaler_username" name="zscaler_username" class="form-control" value="<?php echo e($zscalerCfg['username'] ?? ''); ?>" placeholder="UUID from ZIdentity → Integration → API Clients" autocomplete="off">
                <div class="form-help">The <code>client_id</code> from an API Client created under <strong>ZIdentity → Integration → API Clients</strong>. Must be granted a role with ZIA URL Categories read/write.</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="zscaler_password">Client Secret</label>
                <input type="password" id="zscaler_password" name="zscaler_password" class="form-control" placeholder="<?php echo !empty($zscalerCfg['password']) ? '••••••••••••••••' : 'client_secret from the API Client'; ?>" autocomplete="new-password">
                <div class="form-help">The <code>client_secret</code> shown <strong>once</strong> when the API Client was created. Stored encrypted. Leave blank to keep the existing value.</div>
            </div>
            <div class="form-group">
                <label for="zscaler_url_category">URL Category</label>
                <input type="text" id="zscaler_url_category" name="zscaler_url_category" class="form-control" value="<?php echo e($zscalerCfg['url_category'] ?? ''); ?>" placeholder="URL_CATEGORY_BLOCK_SHADOW_SAAS_BLOCK">
                <div class="form-help">The <em>configured name</em> of a custom URL Category in ZIA. Must already exist (we append URLs, not create the category).</div>
            </div>
        </div>
        <details style="margin-top: 4px;">
            <summary style="cursor: pointer; color: #6b7280; font-size: 13px;">Legacy auth: API Key (optional)</summary>
            <div class="form-group" style="margin-top: 10px;">
                <label for="zscaler_api_key">API Key</label>
                <input type="password" id="zscaler_api_key" name="zscaler_api_key" class="form-control" placeholder="<?php echo !empty($zscalerCfg['api_key']) ? '••••••••••••••••' : 'Only for legacy /authenticatedSession auth'; ?>" autocomplete="new-password">
                <div class="form-help">Only used for legacy ZIA tenants that haven't moved to ZIdentity. Leave blank if you're using OAuth.</div>
            </div>
        </details>
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_zscaler" class="btn btn-primary">Save Configuration</button>
            <button type="submit" name="test_zscaler_connection" class="btn btn-secondary">Test Connection</button>
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
