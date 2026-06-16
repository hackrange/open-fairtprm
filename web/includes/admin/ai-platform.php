<?php
/**
 * Admin Section: AI Platform Settings (OpenWebUI + LibreChat)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Unified AI platform configuration. Supports OpenWebUI and LibreChat,
 * but only one can be active at a time. Both use OpenAI-compatible
 * chat completion APIs, so the same prompts work on either platform.
 *
 * OpenWebUI: JWT bearer token, /api/chat/completions
 * LibreChat: API key bearer token, /api/v1/chat/completions
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handler
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ai_platform'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            $activePlatform = in_array($_POST['ai_platform_active'] ?? '', ['openwebui', 'librechat'])
                ? $_POST['ai_platform_active'] : 'openwebui';

            $settings = [
                'ai_platform_active' => $activePlatform,
            ];

            // OpenWebUI settings
            $owApiUrl = trim($_POST['openwebui_api_url'] ?? '');
            $settings['openwebui_enabled'] = isset($_POST['openwebui_enabled']) ? '1' : '0';
            $settings['openwebui_api_url'] = (!empty($owApiUrl) && filter_var($owApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $owApiUrl)) ? $owApiUrl : '';
            $settings['openwebui_model'] = trim($_POST['openwebui_model'] ?? '');

            $owJwt = trim($_POST['openwebui_jwt_token'] ?? '');
            if (!empty($owJwt)) {
                $settings['openwebui_jwt_token'] = $encryption->encrypt($owJwt);
            }

            // LibreChat settings
            $lcApiUrl = trim($_POST['librechat_api_url'] ?? '');
            $settings['librechat_enabled'] = isset($_POST['librechat_enabled']) ? '1' : '0';
            $settings['librechat_api_url'] = (!empty($lcApiUrl) && filter_var($lcApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $lcApiUrl)) ? $lcApiUrl : '';
            $settings['librechat_model'] = trim($_POST['librechat_model'] ?? '');
            $settings['librechat_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['librechat_temperature'] ?? 0.7)));
            $settings['librechat_max_tokens'] = (string)max(1, min(100000, intval($_POST['librechat_max_tokens'] ?? 500)));

            $lcApiKey = trim($_POST['librechat_api_key'] ?? '');
            if (!empty($lcApiKey)) {
                $settings['librechat_api_key'] = $encryption->encrypt($lcApiKey);
            }

            // Shared settings
            $settings['fair_ai_model'] = trim($_POST['fair_ai_model'] ?? '');
            $settings['openwebui_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['openwebui_temperature'] ?? 0.7)));
            $settings['openwebui_max_tokens'] = (string)max(1, min(100000, intval($_POST['openwebui_max_tokens'] ?? 500)));

            foreach ($settings as $key => $value) {
                $isEncrypted = in_array($key, ['openwebui_jwt_token', 'librechat_api_key']) ? 1 : 0;

                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :key', [':key' => $key]);
                if ($existing) {
                    $db->update('app_config', [
                        'config_value' => $value,
                        'is_encrypted' => $isEncrypted,
                    ], 'config_key = :key', [':key' => $key]);
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => $isEncrypted,
                    ]);
                }
            }

            $db->commit();
            $auditData = $settings;
            unset($auditData['openwebui_jwt_token'], $auditData['librechat_api_key']);
            $auth->audit($user['id'], 'config_update_ai_platform', 'app_config', null, ['new' => $auditData]);
            $success = 'AI platform configuration updated successfully.';
        } catch (Exception $e) {
            $db->rollback();
            error_log('AI platform config error: ' . $e->getMessage());
            $error = 'Failed to update AI platform configuration.';
        }
    }
}

// Test connection handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_ai_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request.';
    } else {
        $aiPlatform = AIPlatformService::getInstance();
        $testResult = $aiPlatform->testConnection();
        if ($testResult['success']) {
            $success = 'Connection successful! Platform: ' . $testResult['platform'] . ', Model: ' . $testResult['model'];
        } else {
            $error = 'Connection failed: ' . ($testResult['message'] ?? 'Unknown error');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$encryption = new Encryption();
$aiConfig = [];
$aiRows = $db->fetchAll(
    "SELECT config_key, config_value, is_encrypted FROM app_config
     WHERE config_key LIKE 'openwebui_%' OR config_key LIKE 'librechat_%' OR config_key LIKE 'ai_platform_%' OR config_key = 'fair_ai_model'"
);
foreach ($aiRows as $row) {
    $val = ($row['is_encrypted'] && !empty($row['config_value']))
        ? $encryption->decrypt($row['config_value'])
        : $row['config_value'];
    $aiConfig[$row['config_key']] = $val;
}

$activePlatform = $aiConfig['ai_platform_active'] ?? 'openwebui';

// Defaults
$defaults = [
    'openwebui_enabled' => '0', 'openwebui_api_url' => '', 'openwebui_jwt_token' => '',
    'openwebui_model' => 'novita.google/gemma-3-27b-it', 'openwebui_temperature' => '0.7',
    'openwebui_max_tokens' => '500',
    'librechat_enabled' => '0', 'librechat_api_url' => '', 'librechat_api_key' => '',
    'librechat_model' => '', 'librechat_temperature' => '0.7', 'librechat_max_tokens' => '500',
    'fair_ai_model' => '',
];
foreach ($defaults as $k => $v) {
    if (!isset($aiConfig[$k])) $aiConfig[$k] = $v;
}

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title">AI Platform Settings</h1>
    <p>Configure AI integration. Only one platform can be active at a time.</p>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="POST" action="admin.php?section=ai-platform">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <!-- Platform Selection -->
        <div class="form-group" style="background:#f0f4ff;padding:16px;border-radius:8px;margin-bottom:20px;">
            <label style="font-weight:600;margin-bottom:8px;display:block;">Active AI Platform</label>
            <label class="checkbox-label" style="display:block;margin-bottom:8px;">
                <input type="radio" name="ai_platform_active" value="openwebui" <?php echo $activePlatform === 'openwebui' ? 'checked' : ''; ?>>
                <span>OpenWebUI</span>
            </label>
            <label class="checkbox-label" style="display:block;">
                <input type="radio" name="ai_platform_active" value="librechat" <?php echo $activePlatform === 'librechat' ? 'checked' : ''; ?>>
                <span>LibreChat</span>
            </label>
            <div class="form-help">Only one platform can be active. Both use OpenAI-compatible APIs, so all prompts work on either platform.</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <!-- OpenWebUI Column -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;<?php echo $activePlatform === 'openwebui' ? 'border-color:#3b82f6;' : 'opacity:0.7;'; ?>">
                <h3 style="margin:0 0 16px;font-size:15px;">OpenWebUI</h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="openwebui_enabled" value="1" <?php echo $aiConfig['openwebui_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span>Enable OpenWebUI</span>
                    </label>
                </div>
                <div class="form-group">
                    <label>API URL</label>
                    <input type="text" name="openwebui_api_url" class="form-control" value="<?php echo e($aiConfig['openwebui_api_url']); ?>" placeholder="https://your-openwebui/api/chat/completions">
                </div>
                <div class="form-group">
                    <label>JWT Token</label>
                    <input type="password" name="openwebui_jwt_token" class="form-control" placeholder="Leave blank to keep existing">
                    <div class="form-help">Bearer token for OpenWebUI authentication (stored encrypted).</div>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="openwebui_model" class="form-control" value="<?php echo e($aiConfig['openwebui_model']); ?>">
                </div>
            </div>

            <!-- LibreChat Column -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;<?php echo $activePlatform === 'librechat' ? 'border-color:#3b82f6;' : 'opacity:0.7;'; ?>">
                <h3 style="margin:0 0 16px;font-size:15px;">LibreChat</h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="librechat_enabled" value="1" <?php echo $aiConfig['librechat_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span>Enable LibreChat</span>
                    </label>
                </div>
                <div class="form-group">
                    <label>API URL</label>
                    <input type="text" name="librechat_api_url" class="form-control" value="<?php echo e($aiConfig['librechat_api_url']); ?>" placeholder="https://your-librechat/api/v1/chat/completions">
                </div>
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="librechat_api_key" class="form-control" placeholder="Leave blank to keep existing">
                    <div class="form-help">API key for LibreChat authentication (stored encrypted).</div>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="librechat_model" class="form-control" value="<?php echo e($aiConfig['librechat_model']); ?>" placeholder="e.g., gpt-4o or claude-3-opus">
                </div>
            </div>
        </div>

        <!-- Shared Settings -->
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:20px;">
            <h3 style="margin:0 0 16px;font-size:15px;">Shared Settings (applies to whichever platform is active)</h3>
            <div class="form-group">
                <label>FAIR AI Model (for structured JSON output)</label>
                <input type="text" name="fair_ai_model" class="form-control" value="<?php echo e($aiConfig['fair_ai_model']); ?>" placeholder="e.g., novita.deepseek/deepseek-v3-turbo">
                <div class="form-help">Used for FAIR analysis, assessment auto-fill, and contract extraction. Needs fast structured JSON output.</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label>Temperature (0.0 - 2.0)</label>
                    <input type="number" name="openwebui_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($aiConfig['openwebui_temperature']); ?>">
                </div>
                <div class="form-group">
                    <label>Maximum Tokens</label>
                    <input type="number" name="openwebui_max_tokens" class="form-control" min="1" max="100000" value="<?php echo e($aiConfig['openwebui_max_tokens']); ?>">
                </div>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:12px;">
            <button type="submit" name="update_ai_platform" class="btn btn-primary">Save Configuration</button>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="margin:0 0 12px;font-size:15px;">Test Connection</h3>
    <p style="color:#6b7280;font-size:13px;margin-bottom:12px;">Send a test message to the active AI platform to verify configuration.</p>
    <form method="POST" action="admin.php?section=ai-platform">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <button type="submit" name="test_ai_connection" class="btn btn-primary" style="background:#6b7280;border-color:#6b7280;">Test Active Platform</button>
    </form>
</div>
