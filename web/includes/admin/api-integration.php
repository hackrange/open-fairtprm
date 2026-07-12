<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
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
            $activePlatform = in_array($_POST['ai_platform_active'] ?? '', ['openwebui', 'librechat', 'custom', 'anthropic', 'openai', 'disabled'])
                ? $_POST['ai_platform_active']
                : 'disabled';

            $settings = ['ai_platform_active' => $activePlatform];

            // OpenWebUI settings
            $apiUrl = trim($_POST['openwebui_api_url'] ?? '');
            $settings['openwebui_enabled'] = ($activePlatform === 'openwebui') ? '1' : '0';
            $settings['openwebui_api_url'] = (!empty($apiUrl) && filter_var($apiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $apiUrl) && isConfigUrlHostSafe($apiUrl)) ? $apiUrl : '';
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
            $settings['librechat_api_url'] = (!empty($lcApiUrl) && filter_var($lcApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $lcApiUrl) && isConfigUrlHostSafe($lcApiUrl)) ? $lcApiUrl : '';
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
            $settings['custom_api_url'] = (!empty($customApiUrl) && filter_var($customApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $customApiUrl) && isConfigUrlHostSafe($customApiUrl)) ? $customApiUrl : '';
            $settings['custom_headers'] = $_POST['custom_headers'] ?? '';
            $settings['custom_secret_header_name'] = trim($_POST['custom_secret_header_name'] ?? '');

            // Optional override for the orchestrator's web-search endpoint. If
            // empty, AIPlatformService auto-derives /v1/safe-search from the
            // API URL above. Setting this lets admins point at a different
            // search backend without changing the inference URL.
            $customSearchUrl = trim($_POST['custom_search_url'] ?? '');
            $settings['custom_search_url'] = (!empty($customSearchUrl) && filter_var($customSearchUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $customSearchUrl) && isConfigUrlHostSafe($customSearchUrl)) ? $customSearchUrl : '';

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

            // Anthropic (Claude) native settings. The API URL defaults to the
            // canonical Messages endpoint when left blank so admins only have to
            // paste a key. Web search is attached automatically during scans.
            $anthropicApiUrl = trim($_POST['anthropic_api_url'] ?? '');
            $settings['anthropic_enabled'] = ($activePlatform === 'anthropic') ? '1' : '0';
            $settings['anthropic_api_url'] = (!empty($anthropicApiUrl) && filter_var($anthropicApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $anthropicApiUrl) && isConfigUrlHostSafe($anthropicApiUrl)) ? $anthropicApiUrl : 'https://api.anthropic.com/v1/messages';
            $settings['anthropic_model'] = trim($_POST['anthropic_model'] ?? '');
            $settings['anthropic_max_tokens'] = (string)max(1, min(100000, intval($_POST['anthropic_max_tokens'] ?? 4096)));

            // Anthropic API key (encrypted, leave blank to keep existing)
            $anthropicKey = trim($_POST['anthropic_api_key'] ?? '');
            if (!empty($anthropicKey)) {
                $settings['anthropic_api_key'] = $encryption->encrypt($anthropicKey);
            } else {
                $existingAnthropicKey = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'anthropic_api_key']
                );
                if ($existingAnthropicKey) {
                    $settings['anthropic_api_key'] = $existingAnthropicKey['config_value'];
                }
            }

            // OpenAI (ChatGPT) native settings. The search model is used only
            // for breach/OSINT scans, which need a web-search-capable model.
            $openaiApiUrl = trim($_POST['openai_api_url'] ?? '');
            $settings['openai_enabled'] = ($activePlatform === 'openai') ? '1' : '0';
            $settings['openai_api_url'] = (!empty($openaiApiUrl) && filter_var($openaiApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $openaiApiUrl) && isConfigUrlHostSafe($openaiApiUrl)) ? $openaiApiUrl : 'https://api.openai.com/v1/chat/completions';
            $settings['openai_model'] = trim($_POST['openai_model'] ?? '');
            $settings['openai_search_model'] = trim($_POST['openai_search_model'] ?? '');
            $settings['openai_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['openai_temperature'] ?? 0.7)));
            $settings['openai_max_tokens'] = (string)max(1, min(100000, intval($_POST['openai_max_tokens'] ?? 4096)));

            // OpenAI API key (encrypted, leave blank to keep existing)
            $openaiKey = trim($_POST['openai_api_key'] ?? '');
            if (!empty($openaiKey)) {
                $settings['openai_api_key'] = $encryption->encrypt($openaiKey);
            } else {
                $existingOpenaiKey = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'openai_api_key']
                );
                if ($existingOpenaiKey) {
                    $settings['openai_api_key'] = $existingOpenaiKey['config_value'];
                }
            }

            // Save all settings
            foreach ($settings as $key => $value) {
                $isEncrypted = in_array($key, ['openwebui_jwt_token', 'librechat_api_key', 'custom_secret_header_value', 'anthropic_api_key', 'openai_api_key']) ? 1 : 0;
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
                $auditData['custom_secret_header_value'],
                $auditData['anthropic_api_key'],
                $auditData['openai_api_key']
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
// Test handlers: connection (default model) and FAIR Analysis (purpose=fair).
// Both use the SAVED config via AIPlatformService, so Save before testing.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_ai_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_api-integration.invalid_request_retry');
    } else {
        require_once __DIR__ . '/../classes/AIPlatformService.php';
        $r = AIPlatformService::getInstance()->testConnection();
        if ($r['success']) {
            $success = t('admin_ai-platform.test_connection') . ' — ' . t('admin_api-integration.settings_saved') . ' (' . $r['platform'] . ', ' . $r['model'] . ')';
        } else {
            $error = 'Connection failed: ' . ($r['message'] ?? 'Unknown error');
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_fair_analysis'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_api-integration.invalid_request_retry');
    } else {
        require_once __DIR__ . '/../classes/AIPlatformService.php';
        $ai = AIPlatformService::getInstance();
        $r = $ai->chatCompletion(
            [['role' => 'user', 'content' => 'Reply with only this JSON and nothing else: {"ok": true}']],
            ['purpose' => 'fair', 'max_tokens' => 50]
        );
        if ($r['success']) {
            $success = t('admin_ai-platform.test_fair_ok') . ' (' . $ai->getModel('fair') . ') — ' . trim((string)$r['content']);
        } else {
            $error = t('admin_ai-platform.test_fair_fail') . ' ' . ($r['error'] ?? 'Unknown error');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$encryption = new Encryption();

// Load all AI config keys
$aiRows = $db->fetchAll(
    'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key = ?',
    ['openwebui_%', 'librechat_%', 'custom_%', 'ai_platform_%', 'anthropic_%', 'openai_%', 'fair_ai_model']
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

// Anthropic (Claude) values
$anthropicApiUrl    = $aiConfig['anthropic_api_url']    ?? 'https://api.anthropic.com/v1/messages';
$anthropicApiKey    = $aiConfig['anthropic_api_key']    ?? '';
$anthropicModel     = $aiConfig['anthropic_model']      ?? 'claude-opus-4-8';
$anthropicMaxTokens = $aiConfig['anthropic_max_tokens'] ?? '4096';

// OpenAI (ChatGPT) values
$openaiApiUrl      = $aiConfig['openai_api_url']      ?? 'https://api.openai.com/v1/chat/completions';
$openaiApiKey      = $aiConfig['openai_api_key']      ?? '';
$openaiModel       = $aiConfig['openai_model']        ?? 'gpt-4o';
$openaiSearchModel = $aiConfig['openai_search_model'] ?? 'gpt-4o-search-preview';
$openaiTemperature = $aiConfig['openai_temperature']  ?? '0.7';
$openaiMaxTokens   = $aiConfig['openai_max_tokens']   ?? '4096';

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
            <label class="api-choice <?php echo $activePlatform === 'anthropic' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="anthropic" <?php echo $activePlatform === 'anthropic' ? 'checked' : ''; ?>>
                <span class="api-choice-label">Anthropic (Claude)</span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.native_api_web_search')); ?></span>
            </label>
            <label class="api-choice <?php echo $activePlatform === 'openai' ? 'api-choice-active' : ''; ?>">
                <input type="radio" name="ai_platform_active" value="openai" <?php echo $activePlatform === 'openai' ? 'checked' : ''; ?>>
                <span class="api-choice-label">OpenAI (ChatGPT)</span>
                <span class="api-choice-desc"><?php echo e(t('admin_api-integration.native_api_web_search')); ?></span>
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
            <input type="password" id="openwebui_jwt_token" name="openwebui_jwt_token" class="form-control" placeholder="<?php echo e(!empty($owJwtToken) ? t('admin_api-integration.masked_leave_blank_to_keep') : t('admin_api-integration.enter_jwt_token_placeholder')); ?>">
            <div class="form-help"><?php echo e(t('admin_api-integration.stored_encrypted_token')); ?></div>
        </div>
        <div class="form-group">
            <label for="openwebui_model"><?php echo e(t('admin_api-integration.default_model')); ?></label>
            <input type="text" id="openwebui_model" name="openwebui_model" class="form-control" value="<?php echo e($owModel); ?>" placeholder="e.g. gemma-3-27b-it">
            <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                <select class="form-control ai-model-select" data-target="openwebui_model" style="flex:1;">
                    <option value=""><?php echo e(t('admin_ai-platform.models_not_loaded')); ?></option>
                </select>
                <button type="button" class="btn ai-load-models" style="background:#6b7280;border-color:#6b7280;color:#fff;white-space:nowrap;"><?php echo e(t('admin_ai-platform.load_models')); ?></button>
            </div>
            <div class="form-help ai-models-msg" style="margin-top:4px;"></div>
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
            <input type="password" id="librechat_api_key" name="librechat_api_key" class="form-control" placeholder="<?php echo e(!empty($lcApiKey) ? t('admin_api-integration.masked_leave_blank_to_keep') : t('admin_api-integration.enter_api_key_placeholder')); ?>">
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
            <input type="text" id="custom_search_url" name="custom_search_url" class="form-control" value="<?php echo e($customSearchUrl); ?>" placeholder="<?php echo e(t('admin_api-integration.custom_search_url_placeholder')); ?>">
            <div class="form-help"><?php echo t('admin_api-integration.custom_search_url_help'); ?></div>
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 0 0 240px;">
                <label for="custom_secret_header_name"><?php echo e(t('admin_api-integration.secret_header_name')); ?></label>
                <input type="text" id="custom_secret_header_name" name="custom_secret_header_name" class="form-control" value="<?php echo e($customSecretHeaderName); ?>" placeholder="x-app-secret">
                <div class="form-help"><?php echo t('admin_api-integration.secret_header_name_help'); ?></div>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="custom_secret_header_value"><?php echo e(t('admin_api-integration.secret_header_value')); ?></label>
                <input type="password" id="custom_secret_header_value" name="custom_secret_header_value" class="form-control" autocomplete="off" placeholder="<?php echo $customSecretIsSet ? e(t('admin_api-integration.masked_saved_replace')) : 'sk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; ?>">
                <div class="form-help"><?php echo t('admin_api-integration.secret_header_value_help'); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label for="custom_headers"><?php echo e(t('admin_api-integration.headers_body_template')); ?></label>
            <textarea id="custom_headers" name="custom_headers" class="form-control" rows="12" style="font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; white-space: pre;" placeholder="<?php echo e($customHeadersExample); ?>"><?php echo e($customHeaders); ?></textarea>
            <div class="form-help"><?php echo t('admin_api-integration.headers_body_template_help'); ?></div>
            <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                <button type="button" id="custom_copy_example" class="btn" style="background:#6b7280;border-color:#6b7280;color:#fff;font-size:12px;padding:6px 12px;"><?php echo e(t('admin_api-integration.copy_example')); ?></button>
                <button type="button" id="custom_download_postman" class="btn" style="background:#6b7280;border-color:#6b7280;color:#fff;font-size:12px;padding:6px 12px;"><?php echo e(t('admin_api-integration.download_postman')); ?></button>
                <span id="custom_action_status" style="font-size:12px;color:#10b981;align-self:center;display:none;"></span>
            </div>
        </div>
    </div>

    <!-- Anthropic (Claude) Settings -->
    <div class="card" id="panel-anthropic" style="margin-bottom: 20px;<?php echo $activePlatform !== 'anthropic' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.anthropic_config_title')); ?></h3>
        <div class="form-group">
            <label for="anthropic_api_url"><?php echo e(t('admin_api-integration.api_url')); ?></label>
            <input type="text" id="anthropic_api_url" name="anthropic_api_url" class="form-control" value="<?php echo e($anthropicApiUrl); ?>" placeholder="https://api.anthropic.com/v1/messages">
            <div class="form-help"><?php echo e(t('admin_api-integration.anthropic_api_url_help')); ?></div>
        </div>
        <div class="form-group">
            <label for="anthropic_api_key"><?php echo e(t('admin_api-integration.api_key')); ?></label>
            <input type="password" id="anthropic_api_key" name="anthropic_api_key" class="form-control" autocomplete="off" placeholder="<?php echo !empty($anthropicApiKey) ? e(t('admin_api-integration.masked_leave_blank_to_keep')) : 'sk-ant-api03-...'; ?>">
            <div class="form-help"><?php echo t('admin_api-integration.anthropic_api_key_help'); ?></div>
        </div>
        <div class="form-group">
            <label for="anthropic_model"><?php echo e(t('admin_api-integration.default_model')); ?></label>
            <input type="text" id="anthropic_model" name="anthropic_model" class="form-control" value="<?php echo e($anthropicModel); ?>" placeholder="e.g. claude-opus-4-8">
            <div class="form-help"><?php echo t('admin_api-integration.anthropic_model_help'); ?></div>
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="anthropic_max_tokens"><?php echo e(t('admin_api-integration.max_tokens')); ?></label>
                <input type="number" id="anthropic_max_tokens" name="anthropic_max_tokens" class="form-control" min="1" value="<?php echo e($anthropicMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- OpenAI (ChatGPT) Settings -->
    <div class="card" id="panel-openai" style="margin-bottom: 20px;<?php echo $activePlatform !== 'openai' ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.openai_config_title')); ?></h3>
        <div class="form-group">
            <label for="openai_api_url"><?php echo e(t('admin_api-integration.api_url')); ?></label>
            <input type="text" id="openai_api_url" name="openai_api_url" class="form-control" value="<?php echo e($openaiApiUrl); ?>" placeholder="https://api.openai.com/v1/chat/completions">
        </div>
        <div class="form-group">
            <label for="openai_api_key"><?php echo e(t('admin_api-integration.api_key')); ?></label>
            <input type="password" id="openai_api_key" name="openai_api_key" class="form-control" autocomplete="off" placeholder="<?php echo !empty($openaiApiKey) ? e(t('admin_api-integration.masked_leave_blank_to_keep')) : 'sk-...'; ?>">
            <div class="form-help"><?php echo t('admin_api-integration.openai_api_key_help'); ?></div>
        </div>
        <div class="form-group">
            <label for="openai_model"><?php echo e(t('admin_api-integration.default_model')); ?></label>
            <input type="text" id="openai_model" name="openai_model" class="form-control" value="<?php echo e($openaiModel); ?>" placeholder="e.g. gpt-4o">
            <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                <select class="form-control ai-model-select" data-target="openai_model" style="flex:1;">
                    <option value=""><?php echo e(t('admin_ai-platform.models_not_loaded')); ?></option>
                </select>
                <button type="button" class="btn ai-load-models" style="background:#6b7280;border-color:#6b7280;color:#fff;white-space:nowrap;"><?php echo e(t('admin_ai-platform.load_models')); ?></button>
            </div>
            <div class="form-help ai-models-msg" style="margin-top:4px;"></div>
        </div>
        <div class="form-group">
            <label for="openai_search_model"><?php echo e(t('admin_api-integration.web_search_model_label')); ?></label>
            <input type="text" id="openai_search_model" name="openai_search_model" class="form-control" value="<?php echo e($openaiSearchModel); ?>" placeholder="gpt-4o-search-preview">
            <div class="form-help"><?php echo t('admin_api-integration.openai_search_model_help'); ?></div>
        </div>
        <div style="display: flex; gap: 16px;">
            <div class="form-group" style="flex: 1;">
                <label for="openai_temperature"><?php echo e(t('admin_api-integration.temperature')); ?></label>
                <input type="number" id="openai_temperature" name="openai_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($openaiTemperature); ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="openai_max_tokens"><?php echo e(t('admin_api-integration.max_tokens')); ?></label>
                <input type="number" id="openai_max_tokens" name="openai_max_tokens" class="form-control" min="1" value="<?php echo e($openaiMaxTokens); ?>">
            </div>
        </div>
    </div>

    <!-- Shared Settings (only relevant for OpenWebUI / LibreChat — Custom/Anthropic/OpenAI use their own model field) -->
    <div class="card" id="panel-shared" style="margin-bottom: 20px;<?php echo in_array($activePlatform, ['disabled', 'custom', 'anthropic', 'openai']) ? ' display:none;' : ''; ?>">
        <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;"><?php echo e(t('admin_api-integration.shared_model_settings')); ?></h3>
        <div class="form-group">
            <label for="fair_ai_model"><?php echo e(t('admin_api-integration.fair_structured_model')); ?></label>
            <input type="text" id="fair_ai_model" name="fair_ai_model" class="form-control" value="<?php echo e($fairAiModel); ?>" placeholder="e.g. deepseek/deepseek-v3-turbo">
            <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                <select class="form-control ai-model-select" data-target="fair_ai_model" style="flex:1;">
                    <option value=""><?php echo e(t('admin_ai-platform.models_not_loaded')); ?></option>
                </select>
                <button type="button" class="btn ai-load-models" style="background:#6b7280;border-color:#6b7280;color:#fff;white-space:nowrap;"><?php echo e(t('admin_ai-platform.load_models')); ?></button>
            </div>
            <div class="form-help"><?php echo t('admin_api-integration.fair_model_help'); ?></div>
        </div>
    </div>

    <button type="submit" name="update_ai_settings" class="btn btn-primary"><?php echo e(t('admin_api-integration.save_configuration')); ?></button>
</form>

<?php if (!in_array($activePlatform, ['disabled'], true)): ?>
<div class="card" style="margin-top:20px;">
    <h3 style="margin:0 0 12px;font-size:15px;font-weight:600;"><?php echo e(t('admin_ai-platform.test_connection')); ?></h3>
    <p style="color:#6b7280;font-size:13px;margin-bottom:12px;"><?php echo e(t('admin_ai-platform.test_connection_desc')); ?></p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <form method="POST" action="admin.php?section=<?php echo e($section); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <button type="submit" name="test_ai_connection" class="btn" style="background:#6b7280;border-color:#6b7280;color:#fff;"><?php echo e(t('admin_ai-platform.test_active_platform')); ?></button>
        </form>
        <form method="POST" action="admin.php?section=<?php echo e($section); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <button type="submit" name="test_fair_analysis" class="btn btn-primary"><?php echo e(t('admin_ai-platform.test_fair')); ?></button>
        </form>
    </div>
    <p style="color:#6b7280;font-size:12px;margin-top:10px;"><?php echo e(t('admin_ai-platform.test_fair_desc')); ?></p>
</div>
<?php endif; ?>

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
        anthropic: document.getElementById('panel-anthropic'),
        openai:    document.getElementById('panel-openai'),
        shared:    document.getElementById('panel-shared')
    };

    function toggle() {
        var val = document.querySelector('input[name="ai_platform_active"]:checked').value;

        // Show/hide config panels
        panels.openwebui.style.display = val === 'openwebui' ? '' : 'none';
        panels.librechat.style.display = val === 'librechat' ? '' : 'none';
        panels.custom.style.display    = val === 'custom'    ? '' : 'none';
        panels.anthropic.style.display = val === 'anthropic' ? '' : 'none';
        panels.openai.style.display    = val === 'openai'    ? '' : 'none';
        panels.shared.style.display    = (val === 'disabled' || val === 'custom' || val === 'anthropic' || val === 'openai') ? 'none' : '';

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
                    function() { flashStatus(<?php echo json_encode(t('admin_api-integration.js_example_copied')); ?>); },
                    function() { flashStatus(<?php echo json_encode(t('admin_api-integration.js_copy_failed')); ?>); }
                );
            } else {
                // Fallback for older browsers / non-https contexts
                var ta = document.createElement('textarea');
                ta.value = example;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); flashStatus(<?php echo json_encode(t('admin_api-integration.js_example_copied')); ?>); }
                catch (e) { flashStatus(<?php echo json_encode(t('admin_api-integration.js_copy_failed')); ?>); }
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

            flashStatus(<?php echo json_encode(t('admin_api-integration.js_postman_downloaded')); ?>);
        });
    }

    // ----- Model dropdowns: query the active platform's /models list -----
    // Uses api/ai-models.php (saved URL + token). Selecting a model fills the
    // matching text input, which is what gets submitted.
    var aiCsrf = <?php echo json_encode($csrfToken); ?>;
    var modelSelects = document.querySelectorAll('.ai-model-select');
    var loadButtons  = document.querySelectorAll('.ai-load-models');
    var modelMsgs    = document.querySelectorAll('.ai-models-msg');

    function setModelMsg(text, isError) {
        modelMsgs.forEach(function (m) {
            m.textContent = text;
            m.style.color = isError ? '#b91c1c' : '#6b7280';
        });
    }

    modelSelects.forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (!sel.value) return;
            var input = document.getElementById(sel.getAttribute('data-target'));
            if (input) input.value = sel.value;
        });
    });

    function populateModels(models) {
        modelSelects.forEach(function (sel) {
            var input = document.getElementById(sel.getAttribute('data-target'));
            var current = input ? input.value : '';
            sel.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = ''; ph.textContent = <?php echo json_encode(t('admin_api-integration.js_select_a_model')); ?>;
            sel.appendChild(ph);
            var found = false;
            models.forEach(function (m) {
                var o = document.createElement('option');
                o.value = m.id;
                o.textContent = (m.name && m.name !== m.id) ? (m.name + '  (' + m.id + ')') : m.id;
                if (m.id === current) { o.selected = true; found = true; }
                sel.appendChild(o);
            });
            if (current && !found) {
                var o2 = document.createElement('option');
                o2.value = current; o2.textContent = current + '  (current — not in list)';
                o2.selected = true;
                sel.appendChild(o2);
            }
        });
    }

    function loadModels() {
        loadButtons.forEach(function (b) { b.disabled = true; });
        setModelMsg(<?php echo json_encode(t('admin_ai-platform.models_loading')); ?>, false);
        // Query the platform the admin is currently editing (the selected card),
        // not whatever happens to be the saved active platform.
        var checked = document.querySelector('input[name="ai_platform_active"]:checked');
        var selPlatform = checked ? checked.value : '';
        fetch('api/ai-models.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: aiCsrf, platform: selPlatform })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success && data.models && data.models.length) {
                populateModels(data.models);
                setModelMsg(data.models.length + <?php echo json_encode(t('admin_api-integration.js_models_loaded_suffix')); ?>, false);
            } else {
                setModelMsg((data && data.error) ? data.error : <?php echo json_encode(t('admin_api-integration.js_could_not_load_models')); ?>, true);
            }
        }).catch(function (e) {
            setModelMsg(<?php echo json_encode(t('admin_api-integration.js_could_not_load_models_prefix')); ?> + e.message, true);
        }).finally(function () {
            loadButtons.forEach(function (b) { b.disabled = false; });
        });
    }

    loadButtons.forEach(function (b) { b.addEventListener('click', loadModels); });
})();
</script>
