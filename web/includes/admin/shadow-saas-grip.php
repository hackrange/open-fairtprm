<?php
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
require_once __DIR__ . '/../classes/ZscalerService.php';

$shadowTab = $_GET['shadow_tab'] ?? 'grip';
if (!in_array($shadowTab, ['grip'], true)) {
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

                $settings['grip_enabled']  = isset($_POST['grip_enabled']) ? '1' : '0';
                $settings['grip_base_url'] = $rawBase;

                $newToken = trim($_POST['grip_api_token'] ?? '');
                if ($newToken !== '') {
                    $settings['grip_api_token'] = $encryption->encrypt($newToken);
                }
            } else { // 'schedule'
                $settings['grip_cron_enabled'] = isset($_POST['grip_cron_enabled']) ? '1' : '0';
                $cronFreqRaw = $_POST['grip_cron_frequency'] ?? 'daily';
                $settings['grip_cron_frequency'] = in_array($cronFreqRaw, ['hourly','every_6h','every_12h','daily','weekly'], true)
                    ? $cronFreqRaw
                    : 'daily';
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
            $success = $formId === 'schedule'
                ? 'Grip schedule updated successfully.'
                : 'Grip configuration updated successfully.';
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
        $cronScript = realpath(__DIR__ . '/../../cron/grip-sync.php');
        if (!$cronScript || !is_file($cronScript)) {
            $error = 'Grip sync script not found at cron/grip-sync.php.';
        } else {
            $logDir = '/var/log/tprm';
            @mkdir($logDir, 0755, true);
            $logFile = is_dir($logDir) && is_writable($logDir) ? $logDir . '/grip-sync.log' : '/tmp/fairtprm-grip-sync.log';
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
            $auth->audit($user['id'], 'grip_run_now', 'shadow_saas_grip_sync_log', null, ['log_file' => $logFile]);
            $success = 'Grip hydration started in the background. Refresh in a few seconds to see the latest run.';
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

$cronCommand = '* * * * * /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1';
switch ($gripCfg['cron_frequency']) {
    case 'hourly':    $cronCommand = '0 * * * * /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1'; break;
    case 'every_6h':  $cronCommand = '0 */6 * * * /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1'; break;
    case 'every_12h': $cronCommand = '0 */12 * * * /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1'; break;
    case 'weekly':    $cronCommand = '0 2 * * 0 /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1'; break;
    case 'daily':
    default:          $cronCommand = '0 2 * * * /usr/bin/php ' . dirname(dirname(__DIR__)) . '/cron/grip-sync.php >> /var/log/tprm/grip-sync.log 2>&1'; break;
}
?>
<div class="page-header-bar">
    <h1 class="page-title">Shadow SaaS</h1>
    <p>Configure third-party Shadow SaaS data providers. Discovered SaaS apps populate the existing Shadow SaaS list with normalized vendor domains.</p>
</div>

<!-- Horizontal tab navigation (same pattern as admin.php?section=email; only "Grip" for now) -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showShadowSection" data-arg="grip" id="shadowTab_grip" class="shadow-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $shadowTab === 'grip' ? '600' : '500'; ?>; color: <?php echo $shadowTab === 'grip' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Grip</button>
</div>

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
        <div style="display: flex; gap: 10px; margin-top: 8px;">
            <button type="submit" name="update_grip" class="btn btn-primary">Save Configuration</button>
            <button type="submit" name="test_grip_connection" class="btn btn-secondary">Test Connection</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Scheduled Rehydration</h3>
    <form method="POST" action="admin.php?section=shadow-saas">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="grip_form" value="schedule">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="grip_cron_enabled" value="1" <?php echo ($gripCfg['cron_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable scheduled rehydration via cron</span>
            </label>
            <div class="form-help">When enabled, the cron job below should be installed on the host. The job runs <code>cron/grip-sync.php</code> and refreshes both the mirror tables and the Shadow SaaS list.</div>
        </div>
        <div class="form-group" style="max-width: 400px;">
            <label for="grip_cron_frequency">Run frequency</label>
            <select id="grip_cron_frequency" name="grip_cron_frequency" class="form-control">
                <option value="hourly"    <?php echo ($gripCfg['cron_frequency'] === 'hourly')    ? 'selected' : ''; ?>>Hourly</option>
                <option value="every_6h"  <?php echo ($gripCfg['cron_frequency'] === 'every_6h')  ? 'selected' : ''; ?>>Every 6 hours</option>
                <option value="every_12h" <?php echo ($gripCfg['cron_frequency'] === 'every_12h') ? 'selected' : ''; ?>>Every 12 hours</option>
                <option value="daily"     <?php echo ($gripCfg['cron_frequency'] === 'daily')     ? 'selected' : ''; ?>>Daily (2am)</option>
                <option value="weekly"    <?php echo ($gripCfg['cron_frequency'] === 'weekly')    ? 'selected' : ''; ?>>Weekly (Sun 2am)</option>
            </select>
        </div>
        <div style="margin-top: 8px;">
            <div style="font-weight: 500; font-size: 13px; color: #374151; margin-bottom: 6px;">Crontab entry</div>
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 10px 12px; border-radius: 4px; font-family: monospace; font-size: 12px; overflow-x: auto;"><?php echo e($cronCommand); ?></div>
            <div class="form-help">Add this entry to your system crontab to enable scheduled rehydration. Lock files prevent overlapping runs.</div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 14px; align-items: center; flex-wrap: wrap;">
            <button type="submit" name="update_grip" class="btn btn-primary">Save Schedule</button>
            <button type="submit" name="run_grip_now" class="btn btn-secondary" onclick="return confirm('Kick off a Grip rehydration now? It runs in the background; refresh this page in a few seconds to see the result.');">Run Now</button>
            <?php if ($lastSync): ?>
                <span style="color: #6b7280; font-size: 13px;">Last run: <strong><?php echo e($lastSync['started_at']); ?></strong> &middot; status: <span class="badge <?php echo $lastSync['status'] === 'success' ? 'badge-success' : ($lastSync['status'] === 'error' ? 'badge-danger' : 'badge-blue'); ?>"><?php echo e($lastSync['status']); ?></span></span>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3>Zscaler Connection</h3>
    <p style="color: #666; line-height: 1.6; margin-top: -4px;">When a vendor is denied from the Shadow SaaS list, the vendor's domain is appended to the URL Category below in Zscaler ZIA (using <code>action=ADD_TO_LIST</code>), then activated.</p>
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
</div>
<?php endif; ?>

<div class="card">
    <h3>Mirror Tables</h3>
    <table>
        <thead><tr><th>Table</th><th>Rows</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>shadow_saas_grip_apps</code></td><td><?php echo number_format($counts['apps']); ?></td><td>One row per Grip SaaS application (full payload + flattened fields).</td></tr>
            <tr><td><code>shadow_saas_grip_users</code></td><td><?php echo number_format($counts['users']); ?></td><td>One row per Grip-discovered user.</td></tr>
            <tr><td><code>shadow_saas_grip_alerts</code></td><td><?php echo number_format($counts['alerts']); ?></td><td>One row per Grip alert with severity, status, and related entity.</td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>API Reference</h3>
    <p style="color: #666; line-height: 1.6;">Endpoint inventory for the Grip Security public API used by this integration. Domain extraction normalizes URLs to their registrable domain (e.g. <code>https://www.united.com</code> → <code>united.com</code>, <code>http://color.redhat.com</code> → <code>redhat.com</code>).</p>
    <table>
        <thead><tr><th>Method</th><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas</code></td><td>List SaaS apps (paginated; <code>offset</code> + <code>limit</code>).</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/{id}</code></td><td>Get a single SaaS app by Grip UUID.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/saas/name/{name}</code></td><td>Find SaaS apps by display name.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/users</code></td><td>List discovered users.</td></tr>
            <tr><td><span class="badge badge-blue">GET</span></td><td><code>/public/alerts</code></td><td>List security alerts with severity, mitigation, and related entity.</td></tr>
        </tbody>
    </table>
    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
        <a href="app/grip-reference/grip-openapi.yaml" target="_blank" class="btn btn-secondary">View OpenAPI / Swagger</a>
        <a href="app/grip-reference/grip-openapi.yaml" download class="btn btn-secondary">Download Swagger (YAML)</a>
        <a href="app/grip-reference/grip-postman-collection.json" target="_blank" class="btn btn-secondary">View Postman Collection</a>
        <a href="app/grip-reference/grip-postman-collection.json" download class="btn btn-secondary">Download Postman (JSON)</a>
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

<script nonce="<?php echo cspNonce(); ?>">
function showShadowSection(section) {
    var sections = ['grip'];
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
}
</script>
