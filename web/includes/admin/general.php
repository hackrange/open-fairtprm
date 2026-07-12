<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: General Configuration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "front door" of the admin panel. Authentication type, session timeouts,
 * lockout policies, and timezone settings live here. It's the stuff you
 * configure once, forget about, and then panic-edit at 2am when someone
 * gets locked out because the session timeout was set to 30 seconds.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handler: update_config
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin.general.error_invalid_request');
    } else {
        try {
            $authType = $_POST['auth_type'] ?? 'local';

            $db->beginTransaction();

            // Validate auth_type against allowed values
            if (!in_array($authType, ['local', 'saml'], true)) {
                $authType = 'local';
            }

            // Validate timezone against PHP's known timezone list
            $appTimezone = $_POST['app_timezone'] ?? 'America/New_York';
            if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
                $appTimezone = 'America/New_York';
            }

            // Validate app_url: must be a valid URL with http(s) scheme, strip trailing slash
            $appUrl = trim($_POST['app_url'] ?? '');
            if ($appUrl !== '' && !preg_match('#^https?://[a-zA-Z0-9.\-]+(:[0-9]+)?(/.*)?$#', $appUrl)) {
                $appUrl = '';
            }
            $appUrl = rtrim($appUrl, '/');

            // Language settings. Default must be a known code; the enabled set is
            // validated against the known codes with English always forced in.
            $knownLanguages = i18nLanguages();
            $defaultLanguage = $_POST['default_language'] ?? 'en';
            if (!isset($knownLanguages[$defaultLanguage])) {
                $defaultLanguage = 'en';
            }
            $enabledLanguages = array_values(array_filter(
                (array)($_POST['enabled_languages'] ?? []),
                function ($code) use ($knownLanguages) { return isset($knownLanguages[$code]); }
            ));
            if (!in_array('en', $enabledLanguages, true)) {
                array_unshift($enabledLanguages, 'en');
            }
            // Don't let the default get switched off in the enabled set.
            if (!in_array($defaultLanguage, $enabledLanguages, true)) {
                $enabledLanguages[] = $defaultLanguage;
            }

            $configs = [
                'company_name' => trim($_POST['company_name'] ?? ''),
                'app_url' => $appUrl,
                'auth_type' => $authType,
                'session_timeout' => max(300, min(86400, intval($_POST['session_timeout'] ?? 3600))),
                'max_login_attempts' => max(1, min(50, intval($_POST['max_login_attempts'] ?? 5))),
                'lockout_duration' => max(60, min(86400, intval($_POST['lockout_duration'] ?? 1800))),
                'app_timezone' => $appTimezone,
                'log_retention_days' => max(0, min(3650, intval($_POST['log_retention_days'] ?? 90))),
                'default_language' => $defaultLanguage,
                'enabled_languages' => json_encode($enabledLanguages)
            ];

            foreach ($configs as $key => $value) {
                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        ['config_value' => $value],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => 0
                    ]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update', 'app_config', null, [
                'new' => $configs
            ]);
            $success = t('admin.general.config_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating config: ' . $e->getMessage());
            $error = t('admin.general.config_update_failed');
        }
    }
}

// ============================================================================
// POST Handler: update_default_theme
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_default_theme'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin.general.error_invalid_request');
    } else {
        try {
            $db->beginTransaction();

            $themeConfigs = [
                'header_color' => safeColor($_POST['header_color'] ?? '', '#35a0a3'),
                'footer_color' => safeColor($_POST['footer_color'] ?? '', '#1a365d'),
                'button_color' => safeColor($_POST['button_color'] ?? '', '#35a0a3'),
                'nav_fill_color' => safeColor($_POST['nav_fill_color'] ?? '', '#e9ecef'),
                'nav_font_color' => safeColor($_POST['nav_font_color'] ?? '', '#1f1e1e'),
                'nav_width' => max(150, min(400, intval($_POST['nav_width'] ?? 220))),
            ];

            foreach ($themeConfigs as $key => $value) {
                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        ['config_value' => $value],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => 0
                    ]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update_theme', 'app_config', null, [
                'new' => $themeConfigs
            ]);
            $success = t('admin.general.theme_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating default theme: ' . $e->getMessage());
            $error = t('admin.general.theme_update_failed');
        }
    }
}

// ============================================================================
// POST Handler: upload_branding
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_branding'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin.general.error_invalid_request');
    } else {
        try {
            $allowedMimes = [
                'image/png', 'image/jpeg', 'image/gif',
                'image/x-icon', 'image/vnd.microsoft.icon',
                'image/svg+xml'
            ];
            $maxSize = 2 * 1024 * 1024; // 2MB
            $brandingDir = is_dir('/persistent/branding') ? '/persistent/branding' : APP_ROOT . '/app/images';

            $uploadFields = [
                'header_logo' => ['filename' => 'logo-default-418x78.png', 'config_key' => 'logo_url'],
                'footer_logo' => ['filename' => 'logo-inverse-416x78.png', 'config_key' => 'footer_logo_url'],
                'favicon'     => ['filename' => 'favicon.ico', 'config_key' => null],
            ];

            $changes = [];
            $db->beginTransaction();

            foreach ($uploadFields as $field => $info) {
                // Handle reset checkbox
                if (!empty($_POST['reset_' . $field])) {
                    $defaultBackup = $brandingDir . '/' . $info['filename'] . '.default';
                    if (file_exists($defaultBackup)) {
                        copy($defaultBackup, $brandingDir . '/' . $info['filename']);
                    }
                    if ($info['config_key']) {
                        $db->delete(
                            'app_config',
                            'config_key = :key',
                            [':key' => $info['config_key']]
                        );
                    }
                    $changes[] = $field . ' reset to default';
                    continue;
                }

                // Handle file upload
                if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Upload error for ' . $field . '.');
                }
                if ($_FILES[$field]['size'] > $maxSize) {
                    throw new Exception('File too large for ' . $field . ' (max 2 MB).');
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES[$field]['tmp_name']);
                if (!in_array($mime, $allowedMimes, true)) {
                    throw new Exception('Invalid file type for ' . $field . ': ' . $mime);
                }

                $targetPath = $brandingDir . '/' . $info['filename'];

                // Save a backup of the original on first custom upload
                $defaultBackup = $targetPath . '.default';
                if (!file_exists($defaultBackup) && file_exists($targetPath)) {
                    copy($targetPath, $defaultBackup);
                }

                if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
                    throw new Exception('Failed to save ' . $field . '.');
                }
                chmod($targetPath, 0644);

                // Store path in app_config
                if ($info['config_key']) {
                    $configValue = 'app/images/' . $info['filename'];
                    $existing = $db->fetchOne(
                        'SELECT id FROM app_config WHERE config_key = :key',
                        [':key' => $info['config_key']]
                    );
                    if ($existing) {
                        $db->update(
                            'app_config',
                            ['config_value' => $configValue],
                            'config_key = :key',
                            [':key' => $info['config_key']]
                        );
                    } else {
                        $db->insert('app_config', [
                            'config_key' => $info['config_key'],
                            'config_value' => $configValue,
                            'is_encrypted' => 0
                        ]);
                    }
                }

                $changes[] = $field . ' uploaded';
            }

            $db->commit();

            if (!empty($changes)) {
                $auth->audit($user['id'], 'branding_update', 'app_config', null, [
                    'changes' => $changes
                ]);
                $success = t('admin.general.branding_updated');
            } else {
                $success = t('admin.general.no_changes');
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error uploading branding: ' . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$appConfig = [];
$configRows = $db->fetchAll('SELECT config_key, config_value FROM app_config');
foreach ($configRows as $row) {
    $appConfig[$row['config_key']] = $row['config_value'];
}

// ============================================================================
// HTML: General Settings Form
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin.general.page_title')); ?></h1>
    <p><?php echo e(t('admin.general.page_subtitle')); ?></p>
</div>
<div class="card">
    <form method="POST" action="admin.php?section=general">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="company_name"><?php echo e(t('admin.general.company_name_label')); ?></label>
                <input type="text" id="company_name" name="company_name" class="form-control" value="<?php echo e($appConfig['company_name'] ?? ''); ?>" placeholder="<?php echo e(t('admin.general.company_name_placeholder')); ?>">
                <div class="form-help"><?php echo e(t('admin.general.company_name_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="app_url"><?php echo e(t('admin.general.app_url_label')); ?></label>
                <input type="url" id="app_url" name="app_url" class="form-control" value="<?php echo e($appConfig['app_url'] ?? ''); ?>" placeholder="https://demo.fairtprm.com">
                <div class="form-help"><?php echo e(t('admin.general.app_url_help')); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="auth_type"><?php echo e(t('admin.general.auth_type_label')); ?></label>
                <select id="auth_type" name="auth_type" class="form-select">
                    <option value="local" <?php echo ($appConfig['auth_type'] ?? 'local') === 'local' ? 'selected' : ''; ?>><?php echo e(t('admin.general.auth_type_local')); ?></option>
                    <option value="saml" <?php echo ($appConfig['auth_type'] ?? 'local') === 'saml' ? 'selected' : ''; ?>><?php echo e(t('admin.general.auth_type_saml')); ?></option>
                </select>
                <div class="form-help"><?php echo e(t('admin.general.auth_type_help')); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="session_timeout"><?php echo e(t('admin.general.session_timeout_label')); ?></label>
                <input type="number" id="session_timeout" name="session_timeout" class="form-control" value="<?php echo e($appConfig['session_timeout'] ?? '3600'); ?>" required>
                <div class="form-help"><?php echo e(t('admin.general.session_timeout_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="max_login_attempts"><?php echo e(t('admin.general.max_login_attempts_label')); ?></label>
                <input type="number" id="max_login_attempts" name="max_login_attempts" class="form-control" value="<?php echo e($appConfig['max_login_attempts'] ?? '5'); ?>" required>
                <div class="form-help"><?php echo e(t('admin.general.max_login_attempts_help')); ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="lockout_duration"><?php echo e(t('admin.general.lockout_duration_label')); ?></label>
                <input type="number" id="lockout_duration" name="lockout_duration" class="form-control" value="<?php echo e($appConfig['lockout_duration'] ?? '1800'); ?>" required>
                <div class="form-help"><?php echo e(t('admin.general.lockout_duration_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="log_retention_days"><?php echo e(t('admin.general.log_retention_label')); ?></label>
                <input type="number" id="log_retention_days" name="log_retention_days" class="form-control" value="<?php echo e($appConfig['log_retention_days'] ?? '90'); ?>" min="0" max="3650">
                <div class="form-help"><?php echo e(t('admin.general.log_retention_help')); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label for="app_timezone"><?php echo e(t('admin.general.timezone_label')); ?></label>
            <select id="app_timezone" name="app_timezone" class="form-control">
                <?php
                $currentTimezone = $appConfig['app_timezone'] ?? 'America/New_York';
                $timezones = [
                    'America/New_York' => t('admin.general.tz_eastern'),
                    'America/Chicago' => t('admin.general.tz_central'),
                    'America/Denver' => t('admin.general.tz_mountain'),
                    'America/Los_Angeles' => t('admin.general.tz_pacific'),
                    'America/Anchorage' => t('admin.general.tz_alaska'),
                    'Pacific/Honolulu' => t('admin.general.tz_hawaii'),
                    'America/Phoenix' => t('admin.general.tz_arizona'),
                    'UTC' => t('admin.general.tz_utc'),
                    'Europe/London' => t('admin.general.tz_london'),
                    'Europe/Paris' => t('admin.general.tz_paris'),
                    'Europe/Berlin' => t('admin.general.tz_berlin'),
                    'Asia/Tokyo' => t('admin.general.tz_tokyo'),
                    'Asia/Shanghai' => t('admin.general.tz_shanghai'),
                    'Asia/Singapore' => t('admin.general.tz_singapore'),
                    'Australia/Sydney' => t('admin.general.tz_sydney'),
                ];
                foreach ($timezones as $tz => $label):
                ?>
                <option value="<?php echo e($tz); ?>" <?php echo $currentTimezone === $tz ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-help"><?php echo e(t('admin.general.timezone_help')); ?></div>
        </div>
        <?php
        $knownLanguages = i18nLanguages();
        $defaultLanguage = $appConfig['default_language'] ?? 'en';
        if (!isset($knownLanguages[$defaultLanguage])) {
            $defaultLanguage = 'en';
        }
        $enabledLanguages = json_decode($appConfig['enabled_languages'] ?? '["en"]', true);
        if (!is_array($enabledLanguages)) {
            $enabledLanguages = ['en'];
        }
        ?>
        <div class="form-group">
            <label for="default_language"><?php echo e(t('admin.general.language.default_label')); ?></label>
            <select id="default_language" name="default_language" class="form-control">
                <?php foreach ($knownLanguages as $code => $label): ?>
                <option value="<?php echo e($code); ?>" <?php echo $defaultLanguage === $code ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-help"><?php echo e(t('admin.general.language.default_help')); ?></div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin.general.language.enabled_label')); ?></label>
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 4px;">
                <?php foreach ($knownLanguages as $code => $label):
                    $isEnglish = ($code === 'en');
                    $checked = $isEnglish || in_array($code, $enabledLanguages, true);
                ?>
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="enabled_languages[]" value="<?php echo e($code); ?>"
                        <?php echo $checked ? 'checked' : ''; ?> <?php echo $isEnglish ? 'disabled' : ''; ?>>
                    <?php echo e($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-help"><?php echo e(t('admin.general.language.enabled_help')); ?></div>
        </div>
        <button type="submit" name="update_config" class="btn btn-primary"><?php echo e(t('admin.general.save_config_button')); ?></button>
    </form>
</div>

<?php
// Branding preview data
$headerLogoUrl = $theme['logo_url'] ?? 'app/images/logo-default-418x78.png';
$footerLogoUrl = $theme['footer_logo_url'] ?? 'app/images/logo-inverse-416x78.png';
$headerLogoPath = APP_ROOT . '/' . $headerLogoUrl;
$footerLogoPath = APP_ROOT . '/' . $footerLogoUrl;
$faviconPath = APP_ROOT . '/app/images/favicon.ico';
$headerLogoCacheBust = file_exists($headerLogoPath) ? '?v=' . filemtime($headerLogoPath) : '';
$footerLogoCacheBust = file_exists($footerLogoPath) ? '?v=' . filemtime($footerLogoPath) : '';
$faviconCacheBust = file_exists($faviconPath) ? '?v=' . filemtime($faviconPath) : '';
$brandingDir = is_dir('/persistent/branding') ? '/persistent/branding' : APP_ROOT . '/app/images';
$headerHasCustom = file_exists($brandingDir . '/logo-default-418x78.png.default');
$footerHasCustom = file_exists($brandingDir . '/logo-inverse-416x78.png.default');
$faviconHasCustom = file_exists($brandingDir . '/favicon.ico.default');
?>

<div class="page-header-bar" style="margin-top: 15px;">
    <h1 class="page-title"><?php echo e(t('admin.general.branding_title')); ?></h1>
    <p><?php echo e(t('admin.general.branding_subtitle')); ?></p>
</div>
<div class="card">
    <form method="POST" action="admin.php?section=general" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-row">
            <div class="form-group">
                <label for="header_logo"><?php echo e(t('admin.general.header_logo_label')); ?></label>
                <div style="margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 6px; display: inline-block;">
                    <img src="<?php echo e($headerLogoUrl . $headerLogoCacheBust); ?>"
                         alt="<?php echo e(t('admin.general.header_logo_alt')); ?>" style="max-height: 60px; max-width: 300px;">
                </div>
                <input type="file" id="header_logo" name="header_logo" class="form-control" accept="image/png,image/jpeg,image/gif,image/svg+xml">
                <div class="form-help"><?php echo t('admin.general.logo_recommended_418_help'); ?></div>
                <?php if ($headerHasCustom): ?>
                <label style="margin-top: 6px; display: flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="reset_header_logo" value="1"> <?php echo e(t('admin.general.reset_default_logo')); ?>
                </label>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="footer_logo"><?php echo e(t('admin.general.footer_logo_label')); ?></label>
                <div style="margin-bottom: 10px; padding: 10px; background: #1a365d; border-radius: 6px; display: inline-block;">
                    <img src="<?php echo e($footerLogoUrl . $footerLogoCacheBust); ?>"
                         alt="<?php echo e(t('admin.general.footer_logo_alt')); ?>" style="max-height: 60px; max-width: 300px;">
                </div>
                <input type="file" id="footer_logo" name="footer_logo" class="form-control" accept="image/png,image/jpeg,image/gif,image/svg+xml">
                <div class="form-help"><?php echo t('admin.general.logo_recommended_416_help'); ?></div>
                <?php if ($footerHasCustom): ?>
                <label style="margin-top: 6px; display: flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="reset_footer_logo" value="1"> <?php echo e(t('admin.general.reset_default_logo')); ?>
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="favicon"><?php echo e(t('admin.general.favicon_label')); ?></label>
                <div style="margin-bottom: 10px; padding: 10px; background: #f8f9fa; border-radius: 6px; display: inline-block;">
                    <img src="app/images/favicon.ico<?php echo $faviconCacheBust; ?>"
                         alt="<?php echo e(t('admin.general.favicon_alt')); ?>" style="max-height: 32px; max-width: 32px;">
                </div>
                <input type="file" id="favicon" name="favicon" class="form-control" accept="image/x-icon,image/vnd.microsoft.icon,image/png">
                <div class="form-help"><?php echo e(t('admin.general.favicon_help')); ?></div>
                <?php if ($faviconHasCustom): ?>
                <label style="margin-top: 6px; display: flex; align-items: center; gap: 6px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="reset_favicon" value="1"> <?php echo e(t('admin.general.reset_default_favicon')); ?>
                </label>
                <?php endif; ?>
            </div>
            <div class="form-group"></div>
        </div>

        <button type="submit" name="upload_branding" class="btn btn-primary"><?php echo e(t('admin.general.upload_branding_button')); ?></button>
    </form>
</div>

<?php
// Default theme values with hardcoded fallbacks
$defaultHeader = $appConfig['header_color'] ?? '#35a0a3';
$defaultFooter = $appConfig['footer_color'] ?? '#1a365d';
$defaultButton = $appConfig['button_color'] ?? '#35a0a3';
$defaultNavFill = $appConfig['nav_fill_color'] ?? '#e9ecef';
$defaultNavFont = $appConfig['nav_font_color'] ?? '#1f1e1e';
$defaultNavWidth = $appConfig['nav_width'] ?? '220';
?>

<div class="page-header-bar" style="margin-top: 15px;">
    <h1 class="page-title"><?php echo e(t('admin.general.theme_title')); ?></h1>
    <p><?php echo e(t('admin.general.theme_subtitle')); ?></p>
</div>
<div class="card">
    <form method="POST" action="admin.php?section=general">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-row">
            <div class="form-group">
                <label for="def_header_color"><?php echo e(t('admin.general.header_color_label')); ?></label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="def_header_color" name="header_color"
                           value="<?php echo e($defaultHeader); ?>"
                           style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                    <input type="text" id="def_header_color_text" class="form-control"
                           value="<?php echo e($defaultHeader); ?>"
                           readonly style="flex: 1;">
                </div>
                <div class="form-help"><?php echo e(t('admin.general.default_35a0a3')); ?></div>
            </div>
            <div class="form-group">
                <label for="def_footer_color"><?php echo e(t('admin.general.footer_color_label')); ?></label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="def_footer_color" name="footer_color"
                           value="<?php echo e($defaultFooter); ?>"
                           style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                    <input type="text" id="def_footer_color_text" class="form-control"
                           value="<?php echo e($defaultFooter); ?>"
                           readonly style="flex: 1;">
                </div>
                <div class="form-help"><?php echo e(t('admin.general.default_1a365d')); ?></div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="def_button_color"><?php echo e(t('admin.general.button_color_label')); ?></label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="def_button_color" name="button_color"
                           value="<?php echo e($defaultButton); ?>"
                           style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                    <input type="text" id="def_button_color_text" class="form-control"
                           value="<?php echo e($defaultButton); ?>"
                           readonly style="flex: 1;">
                </div>
                <div class="form-help"><?php echo e(t('admin.general.default_35a0a3')); ?></div>
            </div>
            <div class="form-group"></div>
        </div>

        <hr style="margin: 25px 0; border: none; border-top: 1px solid #e5e7eb;">
        <h4 style="margin-bottom: 15px; color: #333; font-size: 14px; font-weight: 600;"><?php echo e(t('admin.general.nav_settings_heading')); ?></h4>

        <div class="form-row">
            <div class="form-group">
                <label for="def_nav_fill_color"><?php echo e(t('admin.general.nav_fill_color_label')); ?></label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="def_nav_fill_color" name="nav_fill_color"
                           value="<?php echo e($defaultNavFill); ?>"
                           style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                    <input type="text" id="def_nav_fill_color_text" class="form-control"
                           value="<?php echo e($defaultNavFill); ?>"
                           readonly style="flex: 1;">
                </div>
                <div class="form-help"><?php echo e(t('admin.general.default_e9ecef')); ?></div>
            </div>
            <div class="form-group">
                <label for="def_nav_font_color"><?php echo e(t('admin.general.nav_font_color_label')); ?></label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="def_nav_font_color" name="nav_font_color"
                           value="<?php echo e($defaultNavFont); ?>"
                           style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                    <input type="text" id="def_nav_font_color_text" class="form-control"
                           value="<?php echo e($defaultNavFont); ?>"
                           readonly style="flex: 1;">
                </div>
                <div class="form-help"><?php echo e(t('admin.general.default_1f1e1e')); ?></div>
            </div>
        </div>

        <div class="form-group">
            <label for="def_nav_width"><?php echo e(t('admin.general.nav_width_label')); ?></label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="range" id="def_nav_width_range" min="150" max="400" step="10"
                       value="<?php echo e($defaultNavWidth); ?>"
                       style="flex: 1; cursor: pointer;">
                <input type="number" id="def_nav_width" name="nav_width" class="form-control"
                       value="<?php echo e($defaultNavWidth); ?>"
                       min="150" max="400" style="width: 80px;">
                <span>px</span>
            </div>
            <div class="form-help"><?php echo e(t('admin.general.nav_width_help')); ?></div>
        </div>

        <button type="submit" name="update_default_theme" class="btn btn-primary"><?php echo e(t('admin.general.save_theme_button')); ?></button>
    </form>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Sync color pickers with text fields for default theme
['header', 'footer', 'button', 'nav_fill', 'nav_font'].forEach(function(name) {
    var picker = document.getElementById('def_' + name + '_color');
    var text = document.getElementById('def_' + name + '_color_text');
    if (picker && text) {
        picker.addEventListener('input', function() {
            text.value = this.value.toUpperCase();
        });
    }
});
// Sync range slider with number input for nav width
var navRange = document.getElementById('def_nav_width_range');
var navNumber = document.getElementById('def_nav_width');
if (navRange && navNumber) {
    navRange.addEventListener('input', function() {
        navNumber.value = this.value;
    });
    navNumber.addEventListener('input', function() {
        var val = parseInt(this.value) || 220;
        val = Math.max(150, Math.min(400, val));
        navRange.value = val;
    });
}
</script>
