<?php
/**
 * Admin Section: API Integration (OpenWebUI / LibreChat / Custom)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Unified AI configuration panel. Supports three platforms:
 *   - OpenWebUI: JWT bearer token auth, /api/chat/completions
 *   - LibreChat: API key auth, /api/v1/chat/completions
 *   - Custom:    User-supplied curl-style headers + body template;
 *                the literal token SAMPLE inside --data-raw is replaced
 *                with the prompt at request time.
 *
 * Only one platform can be active at a time, or AI can be disabled entirely.
 * AIPlatformService handles the dispatch.
 *
 * Reachable as ?section=api (canonical) or ?section=openwebui (legacy alias).
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handler: update_ai_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_openwebui']) || isset($_POST['update_ai_settings']))) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_api-integration.invalid_request_retry');
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            // Active platform selection (disabled / openwebui / librechat / custom)
            $activePlatform = in_array($_POST['ai_platform_active'] ?? '', ['openwebui', 'librechat', 'custom', 'disabled'])
                ? $_POST['ai_platform_active']
                : 'disabled';

            $settings = ['ai_platform_active' => $activePlatform];

            // OpenWebUI settings
            $apiUrl = trim($_POST['openwebui_api_url'] ?? '');
            $settings['openwebui_enabled'] = ($activePlatform === 'openwebui') ? '1' : '0';
            $settings['openwebui_api_url'] = (!empty($apiUrl) && filter_var($apiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $apiUrl)) ? $apiUrl : '';
            $settings['openwebui_model'] = trim($_POST['openwebui_model'] ?? '');
            $settings['fair_ai_model'] = trim($_POST['fair_ai_model'] ?? '');
            $settings['openwebui_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['openwebui_temperature'] ?? 0.7)));
            $settings['openwebui_max_tokens'] = (string)max(1, min(100000, intval($_POST['openwebui_max_tokens'] ?? 500)));

            // OpenWebUI JWT token (encrypted)
            $jwtToken = trim($_POST['openwebui_jwt_token'] ?? '');
            if (!empty($jwtToken)) {
                $settings['openwebui_jwt_token'] = $encryption->encrypt($jwtToken);
            } else {
                $existingToken = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'openwebui_jwt_token']
                );
                if ($existingToken) {
                    $settings['openwebui_jwt_token'] = $existingToken['config_value'];
                }
            }

            // LibreChat settings
            $lcApiUrl = trim($_POST['librechat_api_url'] ?? '');
            $settings['librechat_enabled'] = ($activePlatform === 'librechat') ? '1' : '0';
            $settings['librechat_api_url'] = (!empty($lcApiUrl) && filter_var($lcApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $lcApiUrl)) ? $lcApiUrl : '';
            $settings['librechat_model'] = trim($_POST['librechat_model'] ?? '');
            $settings['librechat_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['librechat_temperature'] ?? 0.7)));
            $settings['librechat_max_tokens'] = (string)max(1, min(100000, intval($_POST['librechat_max_tokens'] ?? 500)));

            // LibreChat API key (encrypted)
            $lcApiKey = trim($_POST['librechat_api_key'] ?? '');
            if (!empty($lcApiKey)) {
                $settings['librechat_api_key'] = $encryption->encrypt($lcApiKey);
            } else {
                $existingKey = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'librechat_api_key']
                );
                if ($existingKey) {
                    $settings['librechat_api_key'] = $existingKey['config_value'];
                }
            }

            // Custom platform settings (curl-template style)
            $customApiUrl = trim($_POST['custom_api_url'] ?? '');
            $settings['custom_enabled'] = ($activePlatform === 'custom') ? '1' : '0';
            $settings['custom_api_url'] = (!empty($customApiUrl) && filter_var($customApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $customApiUrl)) ? $customApiUrl : '';
            $settings['custom_headers'] = $_POST['custom_headers'] ?? '';
            $settings['custom_secret_header_name'] = trim($_POST['custom_secret_header_name'] ?? '');

            // Optional override for the orchestrator's web-search endpoint. If
            // empty, AIPlatformService auto-derives /v1/safe-search from the
            // API URL above. Setting this lets admins point at a different
            // search backend without changing the inference URL.
            $customSearchUrl = trim($_POST['custom_search_url'] ?? '');
            $settings['custom_search_url'] = (!empty($customSearchUrl) && filter_var($customSearchUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $customSearchUrl)) ? $customSearchUrl : '';

            // Custom secret header value (encrypted, leave blank to keep existing)
            $customSecretValue = trim($_POST['custom_secret_header_value'] ?? '');
            if (!empty($customSecretValue)) {
                $settings['custom_secret_header_value'] = $encryption->encrypt($customSecretValue);
            } else {
                $existingSecret = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'custom_secret_header_value']
                );
                if ($existingSecret) {
                    $settings['custom_secret_header_value'] = $existingSecret['config_value'];
                }
            }

            // Save all settings
            foreach ($settings as $key => $value) {
                $isEncrypted = in_array($key, ['openwebui_jwt_token', 'librechat_api_key', 'custom_secret_header_value']) ? 1 : 0;
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :key', [':key' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value, 'is_encrypted' => $isEncrypted], 'config_key = :key', [':key' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => $isEncrypted]);
                }
            }

            $db->commit();

            $auditData = $settings;
            unset(
                $auditData['openwebui_jwt_token'],
                $auditData['librechat_api_key'],
                $auditData['custom_headers'],
                $auditData['custom_secret_header_value']
            );
            $auth->audit($user['id'], 'config_update_ai_settings', 'app_config', null, ['new' => $auditData]);
            $success = t('admin_api-integration.settings_saved');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating AI config: ' . $e->getMessage());
            $error = t('admin_api-integration.update_failed');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$encryption = new Encryption();

// Load all AI config keys
$aiRows = $db->fetchAll(
    'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key = ?',
    ['openwebui_%', 'librechat_%', 'custom_%', 'ai_platform_%', 'fair_ai_model']
);
$aiConfig = [];
foreach ($aiRows as $row) {
    if ($row['is_encrypted'] && !empty($row['config_value'])) {
        $aiConfig[$row['config_key']] = $encryption->decrypt($row['config_value']);
    } else {
        $aiConfig[$row['config_key']] = $row['config_value'];
    }
}

$activePlatform = $aiConfig['ai_platform_active'] ?? 'disabled';

// OpenWebUI values
$owApiUrl = $aiConfig['openwebui_api_url'] ?? '';
$owJwtToken = $aiConfig['openwebui_jwt_token'] ?? '';
$owModel = $aiConfig['openwebui_model'] ?? '';
$owTemperature = $aiConfig['openwebui_temperature'] ?? '0.7';
$owMaxTokens = $aiConfig['openwebui_max_tokens'] ?? '4096';
$fairAiModel = $aiConfig['fair_ai_model'] ?? '';

// LibreChat values
$lcApiUrl = $aiConfig['librechat_api_url'] ?? '';
$lcApiKey = $aiConfig['librechat_api_key'] ?? '';
$lcModel = $aiConfig['librechat_model'] ?? '';
$lcTemperature = $aiConfig['librechat_temperature'] ?? '0.7';
$lcMaxTokens = $aiConfig['librechat_max_tokens'] ?? '4096';

// Custom platform values
$customApiUrl            = $aiConfig['custom_api_url']            ?? '';
$customSearchUrl         = $aiConfig['custom_search_url']         ?? '';
$customHeaders           = $aiConfig['custom_headers']            ?? '';
$customSecretHeaderName  = $aiConfig['custom_secret_header_name'] ?? 'x-app-secret';
// Decrypted value is loaded into $aiConfig by the data-load loop, but we
// only check for presence here — the actual secret is never echoed back to
// the browser. Replacement is the only path; viewing is impossible after save.
$customSecretIsSet       = !empty($aiConfig['custom_secret_header_value']);

// Sanitized example shown when the headers field is empty.
// Only the additional headers and the body template are listed — the request
// URL comes from the API URL field above. The literal token SAMPLE inside
// --data-raw's JSON body is the dynamic prompt placeholder; the runtime
// JSON-escapes the actual prompt and substitutes it for SAMPLE, then sends
// the resulting JSON as the request body.
$customHeadersExample = <<<'CURL'
  -H 'Content-Type: application/json'
  -H 'x-app-id: your-app-id'
  -H 'x-user-name: firstname.lastname@example.com'
  --data-raw '{"model_class":"strong-fast","input_text":"SAMPLE"}'
CURL;

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_api-integration.title')); ?></h1>
    <p><?php echo e(t('admin_api-integration.intro')); ?></p>
</div>

<form method="POST" action="admin.php?section=<?php echo e($section); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

    <!-- Platform Selector -->
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.ai_platform')); ?></h3>
        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            <label class="api-choice <?php echo $activePlatform === 'disabled' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="disabled" <?php echo $activePlatform === 'disabled' ? 'checked' : ''; ?>>
                <span class="api-choice-label"><?php echo e(t('admin_api-integration.disabled')); ?></span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.disabled_desc')); ?></span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'openwebui' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="openwebui" <?php echo $activePlatform === 'openwebui' ? 'checked' : ''; ?>>
                <span class="api-choice-label"><?php echo e(t('admin_api-integration.openwebui')); ?></span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.jwt_authentication')); ?></span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'librechat' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="librechat" <?php echo $activePlatform === 'librechat' ? 'checked' : ''; ?>>
                <span class="api-choice-label"><?php echo e(t('admin_api-integration.librechat')); ?></span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.api_key_authentication')); ?></span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'custom' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="custom" <?php echo $activePlatform === 'custom' ? 'checked' : ''; ?>>
                <span class="api-choice-label"><?php echo e(t('admin_api-integration.custom')); ?></span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.curl_style_headers')); ?></span>
            </label>
        </div>
    </div>

    <!-- OpenWebUI Settings -->
    <div class="card" id="panel-openwebui" style="margin-bottom: 20px;<?php echo $activePlatform !== 'openwebui' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.openwebui_config')); ?></h3>
        <div class="form-group">
            <label for="openwebui_api_url"><?php echo e(t('admin_api-integration.api_url')); ?></label>
            <input type="text" id="openwebui_api_url" name="openwebui_api_url" class="form-control" value="<?php echo e($owApiUrl); ?>" placeholder="https://your-openwebui.com/api/chat/completions">
        </div>
        <div class="form-group">
            <label for="openwebui_jwt_token"><?php echo e(t('admin_api-integration.jwt_auth_token')); ?></label>
            <input type="password" id="openwebui_jwt_token" name="openwebui_jwt_token" class="form-control" placeholder="<?php echo !empty($owJwtToken) ? '••••••••  (leave blank to keep)' : 'Enter JWT token'; ?>">
            <div class="form-help"><?php echo e(t('admin_api-integration.stored_encrypted_token')); ?></div>
        </div>
        <div class="form-group">
            <label for="openwebui_model"><?php echo e(t('admin_api-integration.default_model')); ?></label>
            <input type="text" id="openwebui_model" name="openwebui_model" class="form-control" value="<?php echo e($owModel); ?>" placeholder="e.g. novita.google/gemma-3-27b-it">
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="openwebui_temperature"><?php echo e(t('admin_api-integration.temperature')); ?></label>
                <input type="number" id="openwebui_temperature" name="openwebui_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($owTemperature); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="openwebui_max_tokens"><?php echo e(t('admin_api-integration.max_tokens')); ?></label>
                <input type="number" id="openwebui_max_tokens" name="openwebui_max_tokens" class="form-control" min="1" value="<?php echo e($owMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- LibreChat Settings -->
    <div class="card" id="panel-librechat" style="margin-bottom: 20px;<?php echo $activePlatform !== 'librechat' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.librechat_config')); ?></h3>
        <div class="form-group">
            <label for="librechat_api_url"><?php echo e(t('admin_api-integration.api_url')); ?></label>
            <input type="text" id="librechat_api_url" name="librechat_api_url" class="form-control" value="<?php echo e($lcApiUrl); ?>" placeholder="https://your-librechat.com/api/v1/chat/completions">
        </div>
        <div class="form-group">
            <label for="librechat_api_key"><?php echo e(t('admin_api-integration.api_key')); ?></label>
            <input type="password" id="librechat_api_key" name="librechat_api_key" class="form-control" placeholder="<?php echo !empty($lcApiKey) ? '••••••••  (leave blank to keep)' : 'Enter API key'; ?>">
            <div class="form-help"><?php echo e(t('admin_api-integration.stored_encrypted_key')); ?></div>
        </div>
        <div class="form-group">
            <label for="librechat_model"><?php echo e(t('admin_api-integration.default_model')); ?></label>
            <input type="text" id="librechat_model" name="librechat_model" class="form-control" value="<?php echo e($lcModel); ?>" placeholder="e.g. gpt-4o, claude-sonnet-4-6">
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="librechat_temperature"><?php echo e(t('admin_api-integration.temperature')); ?></label>
                <input type="number" id="librechat_temperature" name="librechat_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($lcTemperature); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="librechat_max_tokens"><?php echo e(t('admin_api-integration.max_tokens')); ?></label>
                <input type="number" id="librechat_max_tokens" name="librechat_max_tokens" class="form-control" min="1" value="<?php echo e($lcMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- Custom Settings -->
    <div class="card" id="panel-custom" style="margin-bottom: 20px;<?php echo $activePlatform !== 'custom' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.custom_config')); ?></h3>
        <div class="form-group">
            <label for="custom_api_url"><?php echo e(t('admin_api-integration.api_url')); ?></label>
            <input type="text" id="custom_api_url" name="custom_api_url" class="form-control" value="<?php echo e($customApiUrl); ?>" placeholder="https://your-ai-api.example.com/v1/infer">
        </div>
        <div class="form-group">
            <label for="custom_search_url"><?php echo e(t('admin_api-integration.web_search_url')); ?> <span style="font-weight: 400; color: #6b7280;"><?php echo e(t('admin_api-integration.optional')); ?></span></label>
            <input type="text" id="custom_search_url" name="custom_search_url" class="form-control" value="<?php echo e($customSearchUrl); ?>" placeholder="Auto-detected from API URL — only set this to override">
            <div class="form-help">
                Used by breach research to ground the model on fresh news. Sends <code>{"query": "...", "max_results": N}</code> with the same headers/secret as the API URL above.
                Leave blank to auto-derive — if API URL ends in <code>/v1/infer</code>, the system uses <code>/v1/safe-search</code> on the same host (FairTPRM orchestrator convention). If your backend has no search endpoint, leave blank: breach scans will run inference-only and likely return zero alerts.
            </div>
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 0 0 240px;">
                <label for="custom_secret_header_name"><?php echo e(t('admin_api-integration.secret_header_name')); ?></label>
                <input type="text" id="custom_secret_header_name" name="custom_secret_header_name" class="form-control" value="<?php echo e($customSecretHeaderName); ?>" placeholder="x-app-secret">
                <div class="form-help">HTTP header that carries the secret (e.g., <code>x-app-secret</code>, <code>Authorization</code>).</div>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="custom_secret_header_value"><?php echo e(t('admin_api-integration.secret_header_value')); ?></label>
                <input type="password" id="custom_secret_header_value" name="custom_secret_header_value" class="form-control" autocomplete="off" placeholder="<?php echo $customSecretIsSet ? '••••••••  (saved — leave blank to keep, or enter a new value to replace)' : 'sk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; ?>">
                <div class="form-help">Stored AES-256 encrypted. Once saved the value can never be viewed again — only replaced by entering a new value here. Injected into the request as <code>{Header Name}: {value}</code>; do not include this header in the textarea below.</div>
            </div>
        </div>
        <div class="form-group">
            <label for="custom_headers"><?php echo e(t('admin_api-integration.headers_body_template')); ?></label>
            <textarea id="custom_headers" name="custom_headers" class="form-control" rows="12" style="font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; white-space: pre;" placeholder="<?php echo e($customHeadersExample); ?>"><?php echo e($customHeaders); ?></textarea>
            <div class="form-help">
                One <code>-H 'Header: value'</code> per line, plus a <code>--data-raw '{...}'</code> JSON body template.
                The literal token <code>SAMPLE</code> inside the JSON body is JSON-string-escaped and substituted with the dynamic prompt at request time
                (same dispatch model as OpenWebUI / LibreChat).
            </div>
            <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" id="custom_copy_example" class="btn" style="background:#6b7280;border-color:#6b7280;color:#fff;font-size:12px;padding:6px 12px;"><?php echo e(t('admin_api-integration.copy_example')); ?></button>
                <button type="button" id="custom_download_postman" class="btn" style="background:#6b7280;border-color:#6b7280;color:#fff;font-size:12px;padding:6px 12px;"><?php echo e(t('admin_api-integration.download_postman')); ?></button>
                <span id="custom_action_status" style="font-size:12px;color:#10b981;align-self:center;display:none;"></span>
            </div>
        </div>
    </div>

    <!-- Shared Settings (only relevant for OpenWebUI / LibreChat — Custom uses its own --data-raw body) -->
    <div class="card" id="panel-shared" style="margin-bottom: 20px;<?php echo in_array($activePlatform, ['disabled', 'custom']) ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.shared_model_settings')); ?></h3>
        <div class="form-group">
            <label for="fair_ai_model"><?php echo e(t('admin_api-integration.fair_structured_model')); ?></label>
            <input type="text" id="fair_ai_model" name="fair_ai_model" class="form-control" value="<?php echo e($fairAiModel); ?>" placeholder="e.g. novita.deepseek/deepseek-v3-turbo">
            <div class="form-help">Used for FAIR risk analysis, assessment auto-fill, and contract extraction. Needs fast response (&lt;30s) and good JSON output. Works with both platforms. Leave blank to use the default model.</div>
        </div>
    </div>

    <button type="submit" name="update_ai_settings" class="btn btn-primary"><?php echo e(t('admin_api-integration.save_configuration')); ?></button>
</form>

<style nonce="<?php echo cspNonce(); ?>">
.api-choice {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 140px;
    padding: 14px 18px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}
.api-choice:hover {
    border-color: #9ca3af;
}
.api-choice input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.api-choice-active {
    border-color: var(--theme-button-color, #3b82f6);
    background: rgba(53, 160, 163, 0.06);
}
.api-choice-label {
    font-size: 14px;
    font-weight: 600;
    color: #333;
}
.api-choice-desc {
    font-size: 12px;
    color: #6b7280;
    margin-top: 2px;
}
</style>
<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var radios = document.querySelectorAll('input[name="ai_platform_active"]');
    var panels = {
        openwebui: document.getElementById('panel-openwebui'),
        librechat: document.getElementById('panel-librechat'),
        custom:    document.getElementById('panel-custom'),
        shared:    document.getElementById('panel-shared')
    };

    function toggle() {
        var val = document.querySelector('input[name="ai_platform_active"]:checked').value;

        // Show/hide config panels
        panels.openwebui.style.display = val === 'openwebui' ? '' : 'none';
        panels.librechat.style.display = val === 'librechat' ? '' : 'none';
        panels.custom.style.display    = val === 'custom'    ? '' : 'none';
        panels.shared.style.display    = (val === 'disabled' || val === 'custom') ? 'none' : '';

        // Highlight active choice card
        document.querySelectorAll('.api-choice').forEach(function(card) {
            var radio = card.querySelector('input[type="radio"]');
            if (radio && radio.checked) {
                card.classList.add('api-choice-active');
            } else {
                card.classList.remove('api-choice-active');
            }
        });
    }

    radios.forEach(function(r) {
        r.addEventListener('change', toggle);
    });

    // ----- Custom panel: Copy Example + Download Postman Collection -----
    var customHeadersField = document.getElementById('custom_headers');
    var customUrlField     = document.getElementById('custom_api_url');
    var copyBtn            = document.getElementById('custom_copy_example');
    var dlBtn              = document.getElementById('custom_download_postman');
    var statusEl           = document.getElementById('custom_action_status');

    function flashStatus(msg) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.display = 'inline';
        clearTimeout(statusEl._t);
        statusEl._t = setTimeout(function() { statusEl.style.display = 'none'; }, 2500);
    }

    function getEffectiveHeadersText() {
        return (customHeadersField.value && customHeadersField.value.trim())
            ? customHeadersField.value
            : customHeadersField.placeholder;
    }

    function getEffectiveUrl() {
        return (customUrlField.value && customUrlField.value.trim())
            ? customUrlField.value.trim()
            : 'https://your-ai-api.example.com/v1/infer';
    }

    function parseHeadersBlob(text) {
        var headers = [];
        var headerRe = /-H\s+'([^']+)'/g;
        var m;
        while ((m = headerRe.exec(text)) !== null) {
            var line  = m[1];
            var idx   = line.indexOf(':');
            var key   = idx >= 0 ? line.slice(0, idx).trim() : line.trim();
            var value = idx >= 0 ? line.slice(idx + 1).trim() : '';
            headers.push({ key: key, value: value, type: 'text' });
        }
        var bodyMatch = text.match(/--data-raw\s+'([\s\S]*?)'\s*$/);
        return { headers: headers, body: bodyMatch ? bodyMatch[1] : '' };
    }

    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var example = customHeadersField.placeholder || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(example).then(
                    function() { flashStatus('Example copied to clipboard'); },
                    function() { flashStatus('Copy failed — select and copy manually'); }
                );
            } else {
                // Fallback for older browsers / non-https contexts
                var ta = document.createElement('textarea');
                ta.value = example;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); flashStatus('Example copied to clipboard'); }
                catch (e) { flashStatus('Copy failed — select and copy manually'); }
                document.body.removeChild(ta);
            }
        });
    }

    if (dlBtn) {
        dlBtn.addEventListener('click', function() {
            var url    = getEffectiveUrl();
            var parsed = parseHeadersBlob(getEffectiveHeadersText());

            // Inject the configured secret header by name (value is intentionally
            // left as a placeholder — the encrypted secret never leaves the server).
            var secretNameField = document.getElementById('custom_secret_header_name');
            var secretName = (secretNameField && secretNameField.value.trim()) || '';
            if (secretName) {
                var nameLower = secretName.toLowerCase();
                parsed.headers = parsed.headers.filter(function(h) {
                    return (h.key || '').toLowerCase() !== nameLower;
                });
                parsed.headers.push({
                    key: secretName,
                    value: '<set in Postman — actual secret is server-side only>',
                    type: 'text'
                });
            }

            var collection = {
                info: {
                    name: 'Fair TPRM — Custom AI Integration',
                    description: 'Imported from Fair TPRM admin (Custom AI panel). Replace the literal "SAMPLE" inside the body with a real prompt to test. At runtime, Fair TPRM JSON-string-escapes the prompt and substitutes it for "SAMPLE" automatically. The secret header value is NOT included in this export — set it manually in Postman before sending.',
                    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
                },
                item: [{
                    name: 'Custom AI Inference',
                    request: {
                        method: 'POST',
                        header: parsed.headers,
                        body: {
                            mode: 'raw',
                            raw: parsed.body,
                            options: { raw: { language: 'json' } }
                        },
                        url: { raw: url }
                    }
                }]
            };

            var blob = new Blob(
                [JSON.stringify(collection, null, 2)],
                { type: 'application/json' }
            );
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'fair-tprm-custom-ai.postman_collection.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() { URL.revokeObjectURL(a.href); }, 1000);

            flashStatus('Postman collection downloaded');
        });
    }
})();
</script>
