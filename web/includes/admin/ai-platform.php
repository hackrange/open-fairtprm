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

            // Active platform selection (disabled / openwebui / librechat)
            $activePlatform = in_array($_POST['ai_platform_active'] ?? '', ['openwebui', 'librechat', 'disabled'])
                ? $_POST['ai_platform_active'] : 'disabled';

            $settings = [
                'ai_platform_active' => $activePlatform,
                // Auto-set enabled flags based on active platform selection
                'openwebui_enabled' => ($activePlatform === 'openwebui') ? '1' : '0',
                'librechat_enabled' => ($activePlatform === 'librechat') ? '1' : '0',
            ];

            // OpenWebUI settings
            $owApiUrl = trim($_POST['openwebui_api_url'] ?? '');
            $settings['openwebui_api_url'] = (!empty($owApiUrl) && filter_var($owApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $owApiUrl)) ? $owApiUrl : '';
            $settings['openwebui_model'] = trim($_POST['openwebui_model'] ?? '');
            $settings['openwebui_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['openwebui_temperature'] ?? 0.7)));
            $settings['openwebui_max_tokens'] = (string)max(1, min(100000, intval($_POST['openwebui_max_tokens'] ?? 500)));

            $owJwt = trim($_POST['openwebui_jwt_token'] ?? '');
            if (!empty($owJwt)) {
                $settings['openwebui_jwt_token'] = $encryption->encrypt($owJwt);
            }

            // LibreChat settings
            $lcApiUrl = trim($_POST['librechat_api_url'] ?? '');
            $settings['librechat_api_url'] = (!empty($lcApiUrl) && filter_var($lcApiUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $lcApiUrl)) ? $lcApiUrl : '';
            $settings['librechat_model'] = trim($_POST['librechat_model'] ?? '');
            $settings['librechat_temperature'] = (string)max(0.0, min(2.0, floatval($_POST['librechat_temperature'] ?? 0.7)));
            $settings['librechat_max_tokens'] = (string)max(1, min(100000, intval($_POST['librechat_max_tokens'] ?? 500)));

            $lcApiKey = trim($_POST['librechat_api_key'] ?? '');
            if (!empty($lcApiKey)) {
                $settings['librechat_api_key'] = $encryption->encrypt($lcApiKey);
            }

            // Anthropic Direct API (for breach research)
            $anthropicApiKey = trim($_POST['anthropic_api_key'] ?? '');
            if (!empty($anthropicApiKey)) {
                $settings['anthropic_api_key'] = $encryption->encrypt($anthropicApiKey);
            }
            $settings['anthropic_osint_model'] = trim($_POST['anthropic_osint_model'] ?? '') ?: 'claude-sonnet-4-5-20250929';

            // GitHub PAT (for OSINT code search)
            $githubPat = trim($_POST['github_pat'] ?? '');
            if (!empty($githubPat)) {
                $settings['github_pat'] = $encryption->encrypt($githubPat);
            }

            // Shared settings
            $settings['fair_ai_model'] = trim($_POST['fair_ai_model'] ?? '');

            foreach ($settings as $key => $value) {
                $isEncrypted = in_array($key, ['openwebui_jwt_token', 'librechat_api_key', 'anthropic_api_key', 'github_pat']) ? 1 : 0;

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
            unset($auditData['openwebui_jwt_token'], $auditData['librechat_api_key'], $auditData['anthropic_api_key'], $auditData['github_pat']);
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
     WHERE config_key LIKE 'openwebui_%' OR config_key LIKE 'librechat_%' OR config_key LIKE 'ai_platform_%' OR config_key LIKE 'anthropic_%' OR config_key = 'fair_ai_model' OR config_key = 'github_pat'"
);
foreach ($aiRows as $row) {
    $val = ($row['is_encrypted'] && !empty($row['config_value']))
        ? $encryption->decrypt($row['config_value'])
        : $row['config_value'];
    $aiConfig[$row['config_key']] = $val;
}

$activePlatform = $aiConfig['ai_platform_active'] ?? 'disabled';

// Defaults
$defaults = [
    'openwebui_api_url' => '', 'openwebui_jwt_token' => '',
    'openwebui_model' => 'novita.google/gemma-3-27b-it', 'openwebui_temperature' => '0.7',
    'openwebui_max_tokens' => '500',
    'librechat_api_url' => '', 'librechat_api_key' => '',
    'librechat_model' => '', 'librechat_temperature' => '0.7', 'librechat_max_tokens' => '500',
    'fair_ai_model' => '',
    'anthropic_api_key' => '',
    'anthropic_osint_model' => 'claude-sonnet-4-5-20250929',
    'github_pat' => '',
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

<form method="POST" action="admin.php?section=ai-platform">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

    <!-- Platform Selector -->
    <div class="card" style="margin-bottom:20px;">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">AI Platform</h3>
        <div style="display:flex;gap:16px;flex-wrap:wrap;">
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

    <!-- OpenWebUI Configuration -->
    <div class="card" id="panel-openwebui" style="margin-bottom:20px;<?php echo $activePlatform !== 'openwebui' ? 'display:none;' : ''; ?>">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">OpenWebUI Configuration</h3>
        <div class="form-group">
            <label>API URL</label>
            <input type="text" name="openwebui_api_url" class="form-control" value="<?php echo e($aiConfig['openwebui_api_url']); ?>" placeholder="https://your-openwebui.com/api/chat/completions">
        </div>
        <div class="form-group">
            <label>JWT Authentication Token</label>
            <input type="password" name="openwebui_jwt_token" class="form-control" placeholder="<?php echo !empty($aiConfig['openwebui_jwt_token']) ? '••••••••  (leave blank to keep)' : 'Enter JWT token'; ?>">
            <div class="form-help">Stored encrypted. Leave blank to keep existing token.</div>
        </div>
        <div class="form-group">
            <label>Default Model</label>
            <input type="text" name="openwebui_model" class="form-control" value="<?php echo e($aiConfig['openwebui_model']); ?>" placeholder="e.g. novita.google/gemma-3-27b-it">
        </div>
        <div style="display:flex;gap:16px;">
            <div class="form-group" style="flex:1;">
                <label>Temperature (0.0 - 2.0)</label>
                <input type="number" name="openwebui_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($aiConfig['openwebui_temperature']); ?>">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Max Tokens</label>
                <input type="number" name="openwebui_max_tokens" class="form-control" min="1" max="100000" value="<?php echo e($aiConfig['openwebui_max_tokens']); ?>">
            </div>
        </div>
    </div>

    <!-- LibreChat Configuration -->
    <div class="card" id="panel-librechat" style="margin-bottom:20px;<?php echo $activePlatform !== 'librechat' ? 'display:none;' : ''; ?>">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">LibreChat Configuration</h3>
        <div class="form-group">
            <label>API URL</label>
            <input type="text" name="librechat_api_url" class="form-control" value="<?php echo e($aiConfig['librechat_api_url']); ?>" placeholder="https://your-librechat.com/api/v1/chat/completions">
        </div>
        <div class="form-group">
            <label>API Key</label>
            <input type="password" name="librechat_api_key" class="form-control" placeholder="<?php echo !empty($aiConfig['librechat_api_key']) ? '••••••••  (leave blank to keep)' : 'Enter API key'; ?>">
            <div class="form-help">Stored encrypted. Leave blank to keep existing key.</div>
        </div>
        <div class="form-group">
            <label>Default Model</label>
            <input type="text" name="librechat_model" class="form-control" value="<?php echo e($aiConfig['librechat_model']); ?>" placeholder="e.g. gpt-4o or claude-sonnet-4-6">
        </div>
        <div style="display:flex;gap:16px;">
            <div class="form-group" style="flex:1;">
                <label>Temperature (0.0 - 2.0)</label>
                <input type="number" name="librechat_temperature" class="form-control" step="0.1" min="0" max="2" value="<?php echo e($aiConfig['librechat_temperature']); ?>">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Max Tokens</label>
                <input type="number" name="librechat_max_tokens" class="form-control" min="1" max="100000" value="<?php echo e($aiConfig['librechat_max_tokens']); ?>">
            </div>
        </div>
    </div>

    <!-- Shared Model Settings (visible when a platform is active) -->
    <div class="card" id="panel-shared" style="margin-bottom:20px;<?php echo $activePlatform === 'disabled' ? 'display:none;' : ''; ?>">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600;">Shared Model Settings</h3>
        <div class="form-group">
            <label>FAIR / Structured Output Model</label>
            <input type="text" name="fair_ai_model" class="form-control" value="<?php echo e($aiConfig['fair_ai_model']); ?>" placeholder="e.g. novita.deepseek/deepseek-v3-turbo">
            <div class="form-help">Used for FAIR risk analysis, assessment auto-fill, and contract extraction. Needs fast response (&lt;30s) and good JSON output. Works with both platforms. Leave blank to use the default model.</div>
        </div>
    </div>

    <!-- Anthropic Direct API (OSINT) -->
    <div class="card" style="margin-bottom:20px;background:<?php echo !empty($aiConfig['anthropic_api_key']) ? '#f0fdf4' : '#fff'; ?>;">
        <h3 style="margin:0 0 4px;font-size:15px;">Anthropic Direct API (OSINT Web Search)</h3>
        <p style="color:#6b7280;font-size:12px;margin:0 0 16px;">Optional. When configured, OSINT breach scans call the Anthropic API directly with the web_search tool enabled, bypassing the active AI platform. This avoids parameter conflicts and enables Anthropic's native web search for finding exposed credentials on GitHub, GitLab, Pastebin, etc.</p>
        <div class="form-group">
            <label>Anthropic API Key</label>
            <input type="password" name="anthropic_api_key" class="form-control" placeholder="<?php echo !empty($aiConfig['anthropic_api_key']) ? '••••••••••••••••' : 'sk-ant-... (leave blank to use active platform for OSINT)'; ?>">
            <div class="form-help">Direct Anthropic API key (starts with sk-ant-). Stored encrypted. Leave blank to use the active AI platform for OSINT instead.</div>
        </div>
        <div class="form-group">
            <label>OSINT Model</label>
            <input type="text" name="anthropic_osint_model" class="form-control" value="<?php echo e($aiConfig['anthropic_osint_model']); ?>" placeholder="claude-sonnet-4-5-20250929">
            <div class="form-help">Model used for OSINT web search research. Default: claude-sonnet-4-5-20250929</div>
        </div>
    </div>

    <!-- GitHub PAT (OSINT Code Search) -->
    <div class="card" style="margin-bottom:20px;background:<?php echo !empty($aiConfig['github_pat'] ?? '') ? '#f0fdf4' : '#fff'; ?>;">
        <h3 style="margin:0 0 4px;font-size:15px;">GitHub OSINT Code Search</h3>
        <p style="color:#6b7280;font-size:12px;margin:0 0 16px;">OSINT breach scanning uses GitHub's Code Search API directly to find exposed credentials in public repositories. A GitHub Personal Access Token (PAT) increases the rate limit from 10 to 30 searches per minute. Without a PAT, scanning still works but takes longer.</p>
        <div class="form-group">
            <label>GitHub Personal Access Token (optional)</label>
            <input type="password" name="github_pat" class="form-control" placeholder="<?php echo !empty($aiConfig['github_pat'] ?? '') ? '••••••••••••••••' : 'ghp_... (optional — increases rate limit)'; ?>">
            <div class="form-help">GitHub PAT with <code>public_repo</code> scope. Stored encrypted. <a href="https://github.com/settings/tokens/new?scopes=public_repo&description=TPRM+OSINT+Scanner" target="_blank" rel="noopener" style="color:var(--theme-header-color);">Create a PAT &rarr;</a></div>
        </div>
    </div>

    <div style="display:flex;gap:12px;">
        <button type="submit" name="update_ai_platform" class="btn btn-primary">Save Configuration</button>
    </div>
</form>

<div class="card" style="margin-top:20px;">
    <h3 style="margin:0 0 12px;font-size:15px;">Test Connection</h3>
    <p style="color:#6b7280;font-size:13px;margin-bottom:12px;">Send a test message to the active AI platform to verify configuration.</p>
    <form method="POST" action="admin.php?section=ai-platform">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <button type="submit" name="test_ai_connection" class="btn btn-primary" style="background:#6b7280;border-color:#6b7280;">Test Active Platform</button>
    </form>
</div>

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
