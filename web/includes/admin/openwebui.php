<?php
/**
 * Admin Section: API Integration (OpenWebUI / LibreChat)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Unified AI configuration panel. Supports two platforms:
 *   - OpenWebUI: JWT bearer token auth, /api/chat/completions
 *   - LibreChat: API key auth, /api/v1/chat/completions
 *
 * Only one platform can be active at a time, or AI can be disabled entirely.
 * Both use OpenAI-compatible chat completion endpoints, so the same
 * prompts work on either. AIPlatformService handles the abstraction.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handler: update_ai_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_openwebui']) || isset($_POST['update_ai_settings']))) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            // Active platform selection (disabled / openwebui / librechat)
            $activePlatform = in_array($_POST['ai_platform_active'] ?? '', ['openwebui', 'librechat', 'disabled'])
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

            // Save all settings
            foreach ($settings as $key => $value) {
                $isEncrypted = in_array($key, ['openwebui_jwt_token', 'librechat_api_key']) ? 1 : 0;
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :key', [':key' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value, 'is_encrypted' => $isEncrypted], 'config_key = :key', [':key' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => $isEncrypted]);
                }
            }

            $db->commit();

            $auditData = $settings;
            unset($auditData['openwebui_jwt_token'], $auditData['librechat_api_key']);
            $auth->audit($user['id'], 'config_update_ai_settings', 'app_config', null, ['new' => $auditData]);
            $success = 'API integration settings saved.';
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating AI config: ' . $e->getMessage());
            $error = 'Failed to update API configuration.';
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$encryption = new Encryption();

// Load all AI config keys
$aiRows = $db->fetchAll(
    'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key = ?',
    ['openwebui_%', 'librechat_%', 'ai_platform_%', 'fair_ai_model']
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

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title">API Integration</h1>
    <p>Configure the AI platform used for FAIR analysis, executive summaries, and auto-fill features. Only one platform can be active at a time.</p>
</div>

<form method="POST" action="admin.php?section=<?php echo e($section); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

    <!-- Platform Selector -->
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;">AI Platform</h3>
        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            <label class="api-choice <?php echo $activePlatform === 'disabled' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="disabled" <?php echo $activePlatform === 'disabled' ? 'checked' : ''; ?>>
                <span class="api-choice-label">Disabled</span>
                <span class="api-choice-desc">No AI integration</span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'openwebui' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="openwebui" <?php echo $activePlatform === 'openwebui' ? 'checked' : ''; ?>>
                <span class="api-choice-label">OpenWebUI</span>
                <span class="api-choice-desc">JWT authentication</span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'librechat' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="librechat" <?php echo $activePlatform === 'librechat' ? 'checked' : ''; ?>>
                <span class="api-choice-label">LibreChat</span>
                <span class="api-choice-desc">API key authentication</span>
            </label>
        </div>
    </div>

    <!-- OpenWebUI Settings -->
    <div class="card" id="panel-openwebui" style="margin-bottom: 20px;<?php echo $activePlatform !== 'openwebui' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;">OpenWebUI Configuration</h3>
        <div class="form-group">
            <label for="openwebui_api_url">API URL</label>
            <input type="text" id="openwebui_api_url" name="openwebui_api_url" class="form-control" value="<?php echo e($owApiUrl); ?>" placeholder="https://your-openwebui.com/api/chat/completions">
        </div>
        <div class="form-group">
            <label for="openwebui_jwt_token">JWT Authentication Token</label>
            <input type="password" id="openwebui_jwt_token" name="openwebui_jwt_token" class="form-control" placeholder="<?php echo !empty($owJwtToken) ? '••••••••  (leave blank to keep)' : 'Enter JWT token'; ?>">
            <div class="form-help">Stored encrypted. Leave blank to keep existing token.</div>
        </div>
        <div class="form-group">
            <label for="openwebui_model">Default Model</label>
            <input type="text" id="openwebui_model" name="openwebui_model" class="form-control" value="<?php echo e($owModel); ?>" placeholder="e.g. novita.google/gemma-3-27b-it">
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="openwebui_temperature">Temperature</label>
                <input type="number" id="openwebui_temperature" name="openwebui_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($owTemperature); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="openwebui_max_tokens">Max Tokens</label>
                <input type="number" id="openwebui_max_tokens" name="openwebui_max_tokens" class="form-control" min="1" value="<?php echo e($owMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- LibreChat Settings -->
    <div class="card" id="panel-librechat" style="margin-bottom: 20px;<?php echo $activePlatform !== 'librechat' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;">LibreChat Configuration</h3>
        <div class="form-group">
            <label for="librechat_api_url">API URL</label>
            <input type="text" id="librechat_api_url" name="librechat_api_url" class="form-control" value="<?php echo e($lcApiUrl); ?>" placeholder="https://your-librechat.com/api/v1/chat/completions">
        </div>
        <div class="form-group">
            <label for="librechat_api_key">API Key</label>
            <input type="password" id="librechat_api_key" name="librechat_api_key" class="form-control" placeholder="<?php echo !empty($lcApiKey) ? '••••••••  (leave blank to keep)' : 'Enter API key'; ?>">
            <div class="form-help">Stored encrypted. Leave blank to keep existing key.</div>
        </div>
        <div class="form-group">
            <label for="librechat_model">Default Model</label>
            <input type="text" id="librechat_model" name="librechat_model" class="form-control" value="<?php echo e($lcModel); ?>" placeholder="e.g. gpt-4o, claude-sonnet-4-6">
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="librechat_temperature">Temperature</label>
                <input type="number" id="librechat_temperature" name="librechat_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($lcTemperature); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="librechat_max_tokens">Max Tokens</label>
                <input type="number" id="librechat_max_tokens" name="librechat_max_tokens" class="form-control" min="1" value="<?php echo e($lcMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- Shared Settings (only visible when a platform is active) -->
    <div class="card" id="panel-shared" style="margin-bottom: 20px;<?php echo $activePlatform === 'disabled' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;">Shared Model Settings</h3>
        <div class="form-group">
            <label for="fair_ai_model">FAIR / Structured Output Model</label>
            <input type="text" id="fair_ai_model" name="fair_ai_model" class="form-control" value="<?php echo e($fairAiModel); ?>" placeholder="e.g. novita.deepseek/deepseek-v3-turbo">
            <div class="form-help">Used for FAIR risk analysis, assessment auto-fill, and contract extraction. Needs fast response (&lt;30s) and good JSON output. Works with both platforms. Leave blank to use the default model.</div>
        </div>
    </div>

    <button type="submit" name="update_ai_settings" class="btn btn-primary">Save Configuration</button>
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
        shared:    document.getElementById('panel-shared')
    };

    function toggle() {
        var val = document.querySelector('input[name="ai_platform_active"]:checked').value;

        // Show/hide config panels
        panels.openwebui.style.display = val === 'openwebui' ? '' : 'none';
        panels.librechat.style.display = val === 'librechat' ? '' : 'none';
        panels.shared.style.display    = val === 'disabled'  ? 'none' : '';

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
})();
</script>
