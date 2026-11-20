<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: open-ctem integration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * One place to connect FairTPRM to an open-ctem instance:
 *   1. CTEM URL + API token (encrypted; the token is never shown again)
 *   2. Test connection: reads CTEM's check catalog and caches it for the
 *      monitor form
 *   3. Pin the evidence-signing key(s): reports are only accepted when signed
 *      by a pinned key, so rotation on the CTEM side needs a re-pin here
 *   4. Quick setup: create a continuous monitor per CTEM check in one step
 *   5. The values CTEM needs to read FairTPRM (API base URL, token scopes)
 *
 * Settings live in the single grc_integrations row with
 * integration_type = 'open_ctem'. Admin-only.
 *
 * Available from admin.php: $db, $auth, $security, $user, $isAdmin, $error, $success, $csrfToken
 */

if (!$isAdmin) {
    echo '<p>Administrator access required.</p>';
    return;
}

$ctemSvc = new OpenCtemEvidenceService();
$ctemEnc = new Encryption();
$ctemRow = $ctemSvc->integration();
$ctemCreds = $ctemSvc->credentials($ctemRow);

$ctemSaveCreds = function (array $creds) use (&$ctemRow, $db, $ctemEnc) {
    $db->update('grc_integrations', ['credentials_encrypted' => $ctemEnc->encrypt(json_encode($creds))],
        'id = :id', [':id' => (int)$ctemRow['id']]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ctem_action'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $act = (string)$_POST['ctem_action'];
        try {
            if ($act === 'save') {
                $url = rtrim(trim((string)($_POST['ctem_url'] ?? '')), '/');
                OpenCtemClient::parseBaseUrl($url);           // throws with an admin-readable reason
                $newToken = trim((string)($_POST['ctem_token'] ?? ''));
                if ($newToken !== '' && (strlen($newToken) > 512 || preg_match('/[\s\0]/', $newToken))) {
                    throw new OpenCtemException('API token is malformed');
                }
                $urlChanged = $ctemRow && rtrim((string)$ctemRow['endpoint_url'], '/') !== $url;
                $creds = $ctemCreds;
                if ($newToken !== '') $creds['api_token'] = $newToken;
                if (empty($creds['api_token'])) throw new OpenCtemException('Enter the CTEM API token');
                $creds['allow_private'] = !empty($_POST['ctem_allow_private']);
                $caPem = trim((string)($_POST['ctem_ca_pem'] ?? ''));
                if (!empty($_POST['ctem_ca_clear'])) {
                    unset($creds['ca_pem']);
                } elseif ($caPem !== '') {
                    OpenCtemClient::validateCaPem($caPem);
                    $creds['ca_pem'] = $caPem;
                }
                if ($urlChanged) $creds['pinned_keys'] = [];  // a different CTEM must be re-pinned
                $fields = [
                    'name'                  => mb_substr(trim((string)($_POST['ctem_name'] ?? '')) ?: 'open-ctem', 0, 255),
                    'endpoint_url'          => $url,
                    'is_active'             => !empty($_POST['ctem_enabled']) ? 1 : 0,
                    'credentials_encrypted' => $ctemEnc->encrypt(json_encode($creds)),
                ];
                if ($newToken !== '' || $urlChanged) $fields['connection_status'] = 'untested';
                if ($ctemRow) {
                    $db->update('grc_integrations', $fields, 'id = :id', [':id' => (int)$ctemRow['id']]);
                } else {
                    $db->insert('grc_integrations', $fields + [
                        'integration_type' => 'open_ctem',
                        'description'      => 'open-ctem continuous exposure telemetry',
                        'created_by'       => (int)$user['id'],
                    ]);
                }
                Auth::recordEvent((int)$user['id'], 'open_ctem_settings_saved', 'grc_integrations', null, [
                    'host' => parse_url($url, PHP_URL_HOST), 'token_changed' => $newToken !== '',
                    'enabled' => $fields['is_active'], 'on_prem' => $creds['allow_private'], 'custom_ca' => !empty($creds['ca_pem']),
                ]);
                $success = 'open-ctem settings saved.' . ($urlChanged ? ' The CTEM URL changed, so pinned signing keys were cleared; pin them again.' : '');
            } elseif (!$ctemRow) {
                throw new OpenCtemException('Save the CTEM URL and API token first');
            } elseif ($act === 'test') {
                $client = OpenCtemClient::fromIntegration($ctemCreds, (string)$ctemRow['endpoint_url']);
                try {
                    $catalog = $client->getCatalog();
                } catch (OpenCtemException $e) {
                    $db->update('grc_integrations', ['connection_status' => 'failed', 'last_connection_test' => date('Y-m-d H:i:s')],
                        'id = :id', [':id' => (int)$ctemRow['id']]);
                    throw $e;
                }
                $keep = [];
                foreach ($catalog as $c) {
                    if (!is_array($c) || !OpenCtemClient::isValidCheckType((string)($c['check_type'] ?? ''))) continue;
                    $keep[] = array_intersect_key($c, array_flip(['check_type', 'name', 'description', 'suggested_controls', 'default_thresholds', 'data_sources']));
                }
                $json = json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (strlen($json) < 60000) {
                    $db->query("INSERT INTO app_config (config_key, config_value, is_encrypted, description)
                                VALUES ('open_ctem_catalog', :v, 0, 'Cached open-ctem check catalog')
                                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)", [':v' => $json]);
                    getAppConfig('', null, true);
                }
                $db->update('grc_integrations', ['connection_status' => 'connected', 'last_connection_test' => date('Y-m-d H:i:s')],
                    'id = :id', [':id' => (int)$ctemRow['id']]);
                $success = 'Connected to open-ctem. ' . count($keep) . ' check(s) in its catalog.';
            } elseif ($act === 'fetch_keys') {
                $client = OpenCtemClient::fromIntegration($ctemCreds, (string)$ctemRow['endpoint_url']);
                $ctemFetchedKeys = [];
                foreach ($client->getEvidenceKeys() as $kid => $raw) $ctemFetchedKeys[$kid] = hash('sha256', $raw);
                if (!$ctemFetchedKeys) throw new OpenCtemException('CTEM publishes no Ed25519 evidence key');
                $success = 'Compare these fingerprints with the ones shown on the CTEM side, then pin.';
            } elseif ($act === 'pin_key') {
                // Re-fetch and pin only the key whose fingerprint the admin confirmed.
                $kid = (string)($_POST['key_id'] ?? '');
                $fp  = strtolower((string)($_POST['fingerprint'] ?? ''));
                $keys = OpenCtemClient::fromIntegration($ctemCreds, (string)$ctemRow['endpoint_url'])->getEvidenceKeys();
                if (!isset($keys[$kid]) || !hash_equals(hash('sha256', $keys[$kid]), $fp)) {
                    throw new OpenCtemException('That key is no longer published or its fingerprint changed; fetch the keys again');
                }
                $ctemCreds['pinned_keys'] = (array)($ctemCreds['pinned_keys'] ?? []);
                $ctemCreds['pinned_keys'][$kid] = base64_encode($keys[$kid]);
                $ctemSaveCreds($ctemCreds);
                Auth::recordEvent((int)$user['id'], 'open_ctem_key_pinned', 'grc_integrations', (int)$ctemRow['id'], ['key_id' => $kid, 'sha256' => $fp]);
                $success = 'Pinned signing key ' . $kid . '.';
            } elseif ($act === 'unpin_key') {
                $kid = (string)($_POST['key_id'] ?? '');
                unset($ctemCreds['pinned_keys'][$kid]);
                $ctemSaveCreds($ctemCreds);
                Auth::recordEvent((int)$user['id'], 'open_ctem_key_unpinned', 'grc_integrations', (int)$ctemRow['id'], ['key_id' => $kid]);
                $success = 'Unpinned signing key.';
            } elseif ($act === 'create_monitors') {
                $catalog = OpenCtemEvidenceCollector::catalog();
                $freq = in_array($_POST['frequency'] ?? '', ['hourly', 'daily', 'weekly'], true) ? $_POST['frequency'] : 'daily';
                $attach = !empty($_POST['attach_report']) ? 1 : 0;
                $made = 0;
                foreach ((array)($_POST['checks'] ?? []) as $type) {
                    $type = (string)$type;
                    if (!isset($catalog[$type])) continue;   // only catalog check types
                    if ($db->fetchOne('SELECT id FROM grc_continuous_monitors WHERE collector_class = :c AND check_type = :t',
                        [':c' => OpenCtemEvidenceService::COLLECTOR, ':t' => $type])) continue;
                    $db->insert('grc_continuous_monitors', [
                        'monitor_ref'      => ContinuousMonitor::nextMonitorRef(),
                        'name'             => 'CTEM: ' . $catalog[$type]['name'],
                        'description'      => $catalog[$type]['description'] . ($catalog[$type]['controls'] !== '' ? ' Suggested controls: ' . $catalog[$type]['controls'] : ''),
                        'integration_id'   => (int)$ctemRow['id'],
                        'collector_class'  => OpenCtemEvidenceService::COLLECTOR,
                        'collector_config' => '{}',
                        'check_type'       => $type,
                        'frequency'        => $freq,
                        'is_enabled'       => 1,
                        'attach_report'    => $attach,
                        'alert_on_failure' => 1,
                        'control_ids'      => '[]',
                        'created_by'       => (int)$user['id'],
                    ]);
                    $made++;
                }
                $success = $made . ' monitor(s) created. Map each one to its controls in GRC > Continuous Monitors.';
            }
        } catch (OpenCtemException $e) {
            $error = $e->getMessage();
        }
        $ctemRow = $ctemSvc->integration();
        $ctemCreds = $ctemSvc->credentials($ctemRow);
    }
}

$ctemPinned = (array)($ctemCreds['pinned_keys'] ?? []);
$ctemCatalog = OpenCtemEvidenceCollector::catalog();
$ctemCatalogLive = getAppConfig('open_ctem_catalog', '') !== '';
$ctemMonitored = array_flip(array_column($db->fetchAll(
    'SELECT check_type FROM grc_continuous_monitors WHERE collector_class = :c', [':c' => OpenCtemEvidenceService::COLLECTOR]), 'check_type'));
$proto = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$ctemApiBase = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/v2';
$statusColor = ['connected' => '#16a34a', 'failed' => '#dc2626', 'expired' => '#d97706'][$ctemRow['connection_status'] ?? ''] ?? '#6b7280';
?>
<div class="page-header-bar">
    <h1 class="page-title">open-ctem</h1>
</div>
<p class="form-help" style="margin-top:-8px;">open-ctem measures, FairTPRM governs. Each CTEM check (EDR coverage, KEV remediation, dormant accounts...) becomes a continuous monitor that creates evidence and updates the controls it is mapped to, across every framework.</p>

<div class="card">
    <h2>1. Connection</h2>
    <?php if ($ctemRow): ?>
    <p style="font-size:13px;">Status: <strong style="color:<?php echo e($statusColor); ?>;"><?php echo e(ucfirst($ctemRow['connection_status'] ?? 'untested')); ?></strong>
        <?php if (!empty($ctemRow['last_connection_test'])): ?> &middot; last tested <?php echo e($ctemRow['last_connection_test']); ?><?php endif; ?>
        <?php if (!(int)$ctemRow['is_active']): ?> &middot; <strong>disabled</strong> (monitors return an error until enabled)<?php endif; ?></p>
    <?php endif; ?>
    <form method="POST" action="admin.php?section=open-ctem">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="ctem_action" value="save">
        <div class="form-group">
            <label class="checkbox-label"><input type="checkbox" name="ctem_enabled" value="1" <?php echo (!$ctemRow || (int)$ctemRow['is_active']) ? 'checked' : ''; ?>> Enabled</label>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="ctem_url">CTEM URL</label>
                <input type="text" id="ctem_url" name="ctem_url" class="form-control" required value="<?php echo e($ctemRow['endpoint_url'] ?? ''); ?>" placeholder="https://ctem.example.com">
                <div class="form-help">The base URL of your open-ctem instance. https only; redirects are not followed.</div>
            </div>
            <div class="form-group">
                <label for="ctem_token">API token</label>
                <input type="password" id="ctem_token" name="ctem_token" class="form-control" autocomplete="new-password" placeholder="<?php echo !empty($ctemCreds['api_token']) ? 'Saved - leave blank to keep' : 'CTEM grc_integration token'; ?>">
                <div class="form-help">A CTEM service-user token with only <code>grc:read</code> (role <code>grc_integration</code>), bound to this server's egress IP. Stored encrypted; never shown again.</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="ctem_name">Display name</label>
                <input type="text" id="ctem_name" name="ctem_name" class="form-control" maxlength="255" value="<?php echo e($ctemRow['name'] ?? 'open-ctem'); ?>">
            </div>
            <div class="form-group">
                <label class="checkbox-label" style="margin-top:26px;"><input type="checkbox" name="ctem_allow_private" value="1" <?php echo !empty($ctemCreds['allow_private']) ? 'checked' : ''; ?>> On-prem CTEM (private LAN address)</label>
                <div class="form-help">Allows 10/8, 172.16/12, 192.168/16 and fc00::/7. Loopback, link-local and cloud-metadata addresses are always refused.</div>
            </div>
        </div>
        <div class="form-group">
            <label for="ctem_ca_pem">Custom CA certificate (optional, PEM)</label>
            <textarea id="ctem_ca_pem" name="ctem_ca_pem" class="form-control" rows="3" style="font-family:monospace;font-size:11px;" placeholder="<?php echo !empty($ctemCreds['ca_pem']) ? 'A custom CA is saved - paste a new one to replace it' : '-----BEGIN CERTIFICATE-----'; ?>"></textarea>
            <div class="form-help">For an on-prem CTEM whose certificate is issued by an internal CA. Certificates are still fully verified.</div>
            <?php if (!empty($ctemCreds['ca_pem'])): ?><label class="checkbox-label"><input type="checkbox" name="ctem_ca_clear" value="1"> Remove the saved custom CA</label><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
    <?php if ($ctemRow): ?>
    <form method="POST" action="admin.php?section=open-ctem" style="margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="ctem_action" value="test">
        <button type="submit" class="btn btn-secondary">Test connection &amp; refresh check catalog</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($ctemRow): ?>
<div class="card">
    <h2>2. Evidence signing keys</h2>
    <p class="form-help">CTEM signs every report and history with Ed25519. FairTPRM only accepts reports signed by a key pinned here, and refuses (and records why) anything unsigned or mismatched.</p>
    <?php if ($ctemPinned): ?>
    <table class="table"><thead><tr><th>Pinned key id</th><th>SHA-256 fingerprint</th><th></th></tr></thead><tbody>
    <?php foreach ($ctemPinned as $kid => $b64): ?>
        <tr><td><code><?php echo e($kid); ?></code></td><td><code style="font-size:11px;"><?php echo e(hash('sha256', (string)base64_decode((string)$b64))); ?></code></td>
        <td><form method="POST" action="admin.php?section=open-ctem"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="ctem_action" value="unpin_key"><input type="hidden" name="key_id" value="<?php echo e($kid); ?>"><button type="submit" class="btn btn-sm btn-secondary">Unpin</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?>
    <p style="font-size:13px;color:#b45309;">No key pinned yet: "attach report", "Collect from CTEM" and audit packages will refuse every report until one is.</p>
    <?php endif; ?>
    <?php if (!empty($ctemFetchedKeys)): ?>
    <table class="table"><thead><tr><th>Published key id</th><th>SHA-256 fingerprint</th><th></th></tr></thead><tbody>
    <?php foreach ($ctemFetchedKeys as $kid => $fp): ?>
        <tr><td><code><?php echo e($kid); ?></code></td><td><code style="font-size:11px;"><?php echo e($fp); ?></code></td>
        <td><?php if (isset($ctemPinned[$kid])): ?>Pinned<?php else: ?><form method="POST" action="admin.php?section=open-ctem"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="ctem_action" value="pin_key"><input type="hidden" name="key_id" value="<?php echo e($kid); ?>"><input type="hidden" name="fingerprint" value="<?php echo e($fp); ?>"><button type="submit" class="btn btn-sm btn-primary">Pin</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <form method="POST" action="admin.php?section=open-ctem">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="ctem_action" value="fetch_keys">
        <button type="submit" class="btn btn-secondary">Fetch published keys</button>
    </form>
</div>

<div class="card">
    <h2>3. Quick setup: monitors</h2>
    <p class="form-help">Creates one continuous monitor per selected check<?php echo $ctemCatalogLive ? ' (catalog from your CTEM)' : ' (built-in catalog; test the connection to load your CTEM\'s own)'; ?>. Then map each monitor to its controls in <a href="grc-monitors.php">GRC &gt; Continuous Monitors</a>; a monitor with no controls collects evidence but changes no control.</p>
    <form method="POST" action="admin.php?section=open-ctem">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="ctem_action" value="create_monitors">
        <table class="table"><thead><tr><th></th><th>Check</th><th>What it measures</th><th>Suggested controls</th></tr></thead><tbody>
        <?php foreach ($ctemCatalog as $type => $c): ?>
            <tr>
                <td><?php if (isset($ctemMonitored[$type])): ?><span title="Already monitored">&#10003;</span><?php else: ?><input type="checkbox" name="checks[]" value="<?php echo e($type); ?>" checked><?php endif; ?></td>
                <td><strong><?php echo e($c['name']); ?></strong><br><code style="font-size:11px;"><?php echo e($type); ?></code></td>
                <td style="font-size:12px;"><?php echo e($c['description']); ?></td>
                <td style="font-size:12px;"><?php echo e($c['controls']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="form-row">
            <div class="form-group">
                <label for="ctem_frequency">Frequency</label>
                <select id="ctem_frequency" name="frequency" class="form-control">
                    <option value="daily" selected>Daily</option><option value="hourly">Hourly</option><option value="weekly">Weekly</option>
                </select>
            </div>
            <div class="form-group">
                <label class="checkbox-label" style="margin-top:26px;"><input type="checkbox" name="attach_report" value="1"> Attach the signed detail report as encrypted evidence on every run</label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Create monitors</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>4. Let open-ctem read FairTPRM</h2>
    <p class="form-help">In open-ctem's FairTPRM integration, use:</p>
    <ul style="font-size:13px;">
        <li>API base URL: <code><?php echo e($ctemApiBase); ?></code></li>
        <li>A <strong>read</strong> token with TPRM + GRC access, owned by an org-wide reviewer (e.g. an auditor service user), bound to CTEM's egress IP. Create it in <a href="admin.php?section=api-management">RESTful API</a>.</li>
        <li>Optional, for risk-register write-back: a separate <strong>read/write</strong> token with GRC access only, also IP-bound. Risks CTEM creates carry <code>source=open_ctem</code>; CTEM can update only those, never a risk created in FairTPRM.</li>
    </ul>
</div>
