<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
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
        $error = t('admin_ai-platform.invalid_request_retry');
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
            $settings['openwebui_api_url'] = (!empty($owApiUrl) && filter_var($owApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $owApiUrl) && isConfigUrlHostSafe($owApiUrl)) ? $owApiUrl : '';
            $settings['openwebui_model'] = trim($_POST['openwebui_model'] ?? '');

            $owJwt = trim($_POST['openwebui_jwt_token'] ?? '');
            if (!empty($owJwt)) {
                $settings['openwebui_jwt_token'] = $encryption->encrypt($owJwt);
            }

            // LibreChat settings
            $lcApiUrl = trim($_POST['librechat_api_url'] ?? '');
            $settings['librechat_enabled'] = isset($_POST['librechat_enabled']) ? '1' : '0';
            $settings['librechat_api_url'] = (!empty($lcApiUrl) && filter_var($lcApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $lcApiUrl) && isConfigUrlHostSafe($lcApiUrl)) ? $lcApiUrl : '';
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
            $success = t('admin_ai-platform.config_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('AI platform config error: ' . $e->getMessage());
            $error = t('admin_ai-platform.config_update_failed');
        }
    }
}

// Test connection handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_ai_connection'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_ai-platform.invalid_request');
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

// FAIR analysis test handler — exercises the exact path AI-assisted FAIR
// Analysis uses (purpose=fair, which applies the FAIR AI Model override and
// runs through the same chat-completion call), so a green result here means
// the real feature will work. The generic Test Connection above only uses the
// default model, which can differ from the FAIR model.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_fair_analysis'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_ai-platform.invalid_request');
    } else {
        $aiPlatform = AIPlatformService::getInstance();
        $result = $aiPlatform->chatCompletion(
            [['role' => 'user', 'content' => 'Reply with only this JSON and nothing else: {"ok": true}']],
            ['purpose' => 'fair', 'max_tokens' => 50]
        );
        if ($result['success']) {
            $success = t('admin_ai-platform.test_fair_ok') . ' (' . $aiPlatform->getModel('fair') . ') — ' . trim((string)$result['content']);
        } else {
            $error = t('admin_ai-platform.test_fair_fail') . ' ' . ($result['error'] ?? 'Unknown error');
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
    <h1 class="page-title"><?php echo e(t('admin_ai-platform.title')); ?></h1>
    <p><?php echo e(t('admin_ai-platform.intro')); ?></p>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="POST" action="admin.php?section=ai-platform">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <!-- Platform Selection -->
        <div class="form-group" style="background:#f0f4ff;padding:16px;border-radius:8px;margin-bottom:20px;">
            <label style="font-weight:600;margin-bottom:8px;display:block;"><?php echo e(t('admin_ai-platform.active_platform')); ?></label>
            <label class="checkbox-label" style="display:block;margin-bottom:8px;">
                <input type="radio" name="ai_platform_active" value="openwebui" <?php echo $activePlatform === 'openwebui' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_ai-platform.openwebui')); ?></span>
            </label>
            <label class="checkbox-label" style="display:block;">
                <input type="radio" name="ai_platform_active" value="librechat" <?php echo $activePlatform === 'librechat' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_ai-platform.librechat')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_ai-platform.only_one_active_help')); ?></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <!-- OpenWebUI Column -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;<?php echo $activePlatform === 'openwebui' ? 'border-color:#3b82f6;' : 'opacity:0.7;'; ?>">
                <h3 style="margin:0 0 16px;font-size:15px;"><?php echo e(t('admin_ai-platform.openwebui')); ?></h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="openwebui_enabled" value="1" <?php echo $aiConfig['openwebui_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span><?php echo e(t('admin_ai-platform.enable_openwebui')); ?></span>
                    </label>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.api_url')); ?></label>
                    <input type="text" name="openwebui_api_url" class="form-control" value="<?php echo e($aiConfig['openwebui_api_url']); ?>" placeholder="https://your-openwebui/api/chat/completions">
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.jwt_token')); ?></label>
                    <input type="password" name="openwebui_jwt_token" class="form-control" placeholder="<?php echo e(t('admin_ai-platform.leave_blank_keep')); ?>">
                    <div class="form-help"><?php echo e(t('admin_ai-platform.jwt_help')); ?></div>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.model')); ?></label>
                    <input type="text" name="openwebui_model" id="openwebui_model" class="form-control" value="<?php echo e($aiConfig['openwebui_model']); ?>">
                    <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                        <select class="form-control ai-model-select" data-target="openwebui_model" style="flex:1;">
                            <option value=""><?php echo e(t('admin_ai-platform.models_not_loaded')); ?></option>
                        </select>
                        <button type="button" class="btn ai-load-models" style="background:#6b7280;border-color:#6b7280;color:#fff;white-space:nowrap;"><?php echo e(t('admin_ai-platform.load_models')); ?></button>
                    </div>
                    <div class="form-help ai-models-msg" style="margin-top:4px;"></div>
                </div>
            </div>

            <!-- LibreChat Column -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;<?php echo $activePlatform === 'librechat' ? 'border-color:#3b82f6;' : 'opacity:0.7;'; ?>">
                <h3 style="margin:0 0 16px;font-size:15px;"><?php echo e(t('admin_ai-platform.librechat')); ?></h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="librechat_enabled" value="1" <?php echo $aiConfig['librechat_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span><?php echo e(t('admin_ai-platform.enable_librechat')); ?></span>
                    </label>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.api_url')); ?></label>
                    <input type="text" name="librechat_api_url" class="form-control" value="<?php echo e($aiConfig['librechat_api_url']); ?>" placeholder="https://your-librechat/api/v1/chat/completions">
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.api_key')); ?></label>
                    <input type="password" name="librechat_api_key" class="form-control" placeholder="<?php echo e(t('admin_ai-platform.leave_blank_keep')); ?>">
                    <div class="form-help"><?php echo e(t('admin_ai-platform.api_key_help')); ?></div>
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.model')); ?></label>
                    <input type="text" name="librechat_model" class="form-control" value="<?php echo e($aiConfig['librechat_model']); ?>" placeholder="e.g., gpt-4o or claude-3-opus">
                </div>
            </div>
        </div>

        <!-- Shared Settings -->
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:20px;">
            <h3 style="margin:0 0 16px;font-size:15px;"><?php echo e(t('admin_ai-platform.shared_settings')); ?></h3>
            <div class="form-group">
                <label><?php echo e(t('admin_ai-platform.fair_ai_model')); ?></label>
                <input type="text" name="fair_ai_model" id="fair_ai_model" class="form-control" value="<?php echo e($aiConfig['fair_ai_model']); ?>" placeholder="e.g., deepseek/deepseek-v3-turbo">
                <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                    <select class="form-control ai-model-select" data-target="fair_ai_model" style="flex:1;">
                        <option value=""><?php echo e(t('admin_ai-platform.models_not_loaded')); ?></option>
                    </select>
                    <button type="button" class="btn ai-load-models" style="background:#6b7280;border-color:#6b7280;color:#fff;white-space:nowrap;"><?php echo e(t('admin_ai-platform.load_models')); ?></button>
                </div>
                <div class="form-help"><?php echo e(t('admin_ai-platform.fair_ai_model_help')); ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.temperature')); ?></label>
                    <input type="number" name="openwebui_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($aiConfig['openwebui_temperature']); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo e(t('admin_ai-platform.maximum_tokens')); ?></label>
                    <input type="number" name="openwebui_max_tokens" class="form-control" min="1" max="100000" value="<?php echo e($aiConfig['openwebui_max_tokens']); ?>">
                </div>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:12px;">
            <button type="submit" name="update_ai_platform" class="btn btn-primary"><?php echo e(t('admin_ai-platform.save_configuration')); ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="margin:0 0 12px;font-size:15px;"><?php echo e(t('admin_ai-platform.test_connection')); ?></h3>
    <p style="color:#6b7280;font-size:13px;margin-bottom:12px;"><?php echo e(t('admin_ai-platform.test_connection_desc')); ?></p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <form method="POST" action="admin.php?section=ai-platform">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <button type="submit" name="test_ai_connection" class="btn btn-primary" style="background:#6b7280;border-color:#6b7280;"><?php echo e(t('admin_ai-platform.test_active_platform')); ?></button>
        </form>
        <form method="POST" action="admin.php?section=ai-platform">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <button type="submit" name="test_fair_analysis" class="btn btn-primary"><?php echo e(t('admin_ai-platform.test_fair')); ?></button>
        </form>
    </div>
    <p style="color:#6b7280;font-size:12px;margin-top:10px;"><?php echo e(t('admin_ai-platform.test_fair_desc')); ?></p>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Populate the Model / FAIR AI Model dropdowns by querying the active platform
// for its model list (api/ai-models.php uses the SAVED URL + token). Selecting
// a model writes it into the matching text input, which is what gets submitted.
(function () {
    var csrf = <?php echo json_encode($csrfToken); ?>;
    var loadingTxt = <?php echo json_encode(t('admin_ai-platform.models_loading')); ?>;
    var loadTxt = <?php echo json_encode(t('admin_ai-platform.load_models')); ?>;
    var selects = document.querySelectorAll('.ai-model-select');
    var buttons = document.querySelectorAll('.ai-load-models');
    var msgs = document.querySelectorAll('.ai-models-msg');

    function setMsg(text, isError) {
        msgs.forEach(function (m) {
            m.textContent = text;
            m.style.color = isError ? '#b91c1c' : '#6b7280';
        });
    }

    // Keep the dropdown in sync with its text input
    selects.forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (!sel.value) return;
            var input = document.getElementById(sel.getAttribute('data-target'));
            if (input) input.value = sel.value;
        });
    });

    function populate(models) {
        selects.forEach(function (sel) {
            var input = document.getElementById(sel.getAttribute('data-target'));
            var current = input ? input.value : '';
            sel.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = '— select a model —';
            sel.appendChild(ph);
            var found = false;
            models.forEach(function (m) {
                var o = document.createElement('option');
                o.value = m.id;
                o.textContent = (m.name && m.name !== m.id) ? (m.name + '  (' + m.id + ')') : m.id;
                if (m.id === current) { o.selected = true; found = true; }
                sel.appendChild(o);
            });
            // If the saved value isn't in the list, surface it so it stays visible
            if (current && !found) {
                var o = document.createElement('option');
                o.value = current;
                o.textContent = current + '  (current — not in list)';
                o.selected = true;
                sel.appendChild(o);
            }
        });
    }

    function load() {
        buttons.forEach(function (b) { b.disabled = true; });
        setMsg(loadingTxt, false);
        var body = new URLSearchParams({ csrf_token: csrf });
        fetch('api/ai-models.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success && data.models && data.models.length) {
                populate(data.models);
                setMsg(data.models.length + ' model(s) loaded.', false);
            } else {
                setMsg((data && data.error) ? data.error : 'Could not load models.', true);
            }
        }).catch(function (e) {
            setMsg('Could not load models: ' + e.message, true);
        }).finally(function () {
            buttons.forEach(function (b) { b.disabled = false; });
        });
    }

    buttons.forEach(function (b) { b.addEventListener('click', load); });
})();
</script>
