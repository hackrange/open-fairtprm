<?php
/**
 * User Profile Page - Your Personal Corner of the App
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is where users come to change their password, set up TOTP two-factor
 * authentication (you know, for those who actually care about security), and
 * customize their theme colors and logos. Yes, every user can have their own
 * color scheme. It's like MySpace for risk management professionals.
 *
 * Handles: profile updates, password changes, TOTP enable/disable/verify,
 * theme customization (logo URLs, header/footer/button/nav colors, nav width),
 * and resetting themes back to defaults when users inevitably pick awful colors.
 */

require_once 'includes/init.php';
requireAuth(); // Must be logged in to see your own profile. Makes sense, right?

// The usual suspects -- singletons assemble!
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

// These track form submission results to display success/error messages
$error = '';
$success = '';
$totpSetup = null;  // Will hold QR code data if user is setting up TOTP

// Fetch the full user record from the database -- we need everything for the form fields
$userDetails = $db->fetchOne(
    'SELECT * FROM users WHERE id = :id',
    [':id' => $user['id']]
);

// Load the application-wide default theme settings from app_config.
// These are the "factory defaults" that users see before they go color-crazy.
$defaultTheme = [
    'logo_url' => '',
    'header_color' => '#35a0a3',
    'footer_color' => '#1a365d',
    'button_color' => '#35a0a3',
    'nav_fill_color' => '#e9ecef',
    'nav_font_color' => '#1f1e1e',
    'nav_width' => '220'
];

try {
    // Pull the actual configured defaults from the database (admin may have changed them)
    $themeSettings = $db->fetchAll('SELECT config_key, config_value FROM app_config WHERE config_key IN (?, ?, ?, ?, ?, ?, ?)',
        ['logo_url', 'header_color', 'footer_color', 'button_color', 'nav_fill_color', 'nav_font_color', 'nav_width']);

    foreach ($themeSettings as $setting) {
        $defaultTheme[$setting['config_key']] = $setting['config_value'];
    }
} catch (Exception $e) {
    // If app_config doesn't have theme settings yet, we just use the hardcoded defaults above
    error_log('Failed to load theme settings: ' . $e->getMessage());
}

// ============================================================================
// POST HANDLER -- The Big Switch Statement of Destiny
// Each form on the profile page submits with a different hidden field so we
// know which action to take. It's like a choose-your-own-adventure book.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // First things first: Origin/Referer + CSRF validation. No token, no dice.
    if (!$security->validateOrigin()) {
        http_response_code(403);
        $error = t('profile.invalid_origin');
    } elseif (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        $error = t('profile.invalid_request');

    // --- TOTP Setup: User wants to enable 2FA. Generate a secret and QR code. ---
    } elseif (isset($_POST['enable_totp'])) {
        $result = $auth->enableTOTP($user['id']);
        if ($result['success']) {
            $totpSetup = $result;
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['confirm_totp'])) {
        $totpCode = preg_replace('/[^0-9]/', '', $_POST['totp_code'] ?? '');
        $result = $auth->confirmTOTP($user['id'], $totpCode);
        if ($result['success']) {
            $success = $result['message'];
            header('Refresh: 2; url=profile.php');
        } else {
            $error = $result['message'];
            $result = $auth->enableTOTP($user['id']);
            if ($result['success']) {
                $totpSetup = $result;
            }
        }
    } elseif (isset($_POST['disable_totp'])) {
        $result = $auth->disableTOTP($user['id']);
        if ($result['success']) {
            $success = $result['message'];
            header('Refresh: 2; url=profile.php');
        } else {
            $error = $result['message'];
        }
    } elseif (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($fullName) || empty($email)) {
            $error = t('profile.name_email_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('profile.invalid_email');
        } else {
            try {
                $oldProfile = ['full_name' => $userDetails['full_name'], 'email' => $userDetails['email']];
                $db->update('users', [
                    'full_name' => $fullName,
                    'email' => $email
                ], 'id = :id', [':id' => $user['id']]);
                $auth->audit($user['id'], 'profile_update', 'users', $user['id'], [
                    'old' => $oldProfile,
                    'new' => ['full_name' => $fullName, 'email' => $email]
                ]);
                $success = t('profile.profile_updated');
            } catch (Exception $e) {
                error_log('Profile update failed: ' . $e->getMessage());
                $error = t('profile.profile_update_failed');
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = t('profile.all_password_required');
        } elseif ($newPassword !== $confirmPassword) {
            $error = t('profile.passwords_mismatch');
        } else {
            $encryption = new Encryption();

            if (!$encryption->verifyPassword($currentPassword, $userDetails['password_hash'])) {
                $auth->audit($user['id'], 'password_change_failed', 'users', $user['id'], [
                    'new' => ['reason' => 'invalid_current_password']
                ]);
                $error = t('profile.current_password_incorrect');
            } else {
                $validation = $security->validatePassword($newPassword);
                if (!$validation['valid']) {
                    $error = implode('<br>', $validation['errors']);
                } else {
                    $newHash = $encryption->hashPassword($newPassword);
                    $db->update('users', ['password_hash' => $newHash], 'id = :id', [':id' => $user['id']]);
                    $auth->audit($user['id'], 'password_change', 'users', $user['id']);
                    $success = t('profile.password_changed');
                }
            }
        }
    } elseif (isset($_POST['update_dashboard_modules'])) {
        $selectedModules = $_POST['dashboard_modules'] ?? [];
        if (!is_array($selectedModules)) $selectedModules = [];
        // Whitelist valid module keys
        $validKeys = ['fair_analysis','vendor_onboarding','contracts','srs_scoring','vendor_assessments',
                      'annual_reviews','cyber_todo','grc_compliance','system_admin'];
        $selectedModules = array_values(array_intersect($selectedModules, $validKeys));
        $selectedModules = array_slice($selectedModules, 0, 9);
        $jsonModules = !empty($selectedModules) ? json_encode($selectedModules) : null;
        try {
            $db->update('users', ['dashboard_modules' => $jsonModules], 'id = :id', [':id' => $user['id']]);
            $success = t('profile.modules_updated');
        } catch (Exception $e) {
            // Column may not exist yet — try adding it
            try {
                $db->query('ALTER TABLE users ADD COLUMN dashboard_modules TEXT DEFAULT NULL');
                $db->update('users', ['dashboard_modules' => $jsonModules], 'id = :id', [':id' => $user['id']]);
                $success = t('profile.modules_updated');
            } catch (Exception $e2) {
                error_log('Dashboard modules update failed: ' . $e2->getMessage());
                $error = t('profile.modules_update_failed');
            }
        }
    } elseif (isset($_POST['update_theme'])) {
        // Preserve existing logo paths (removed from form, managed by admin)
        $themeLogo = $userDetails['theme_logo_url'] ?? '';
        $themeFooterLogo = $userDetails['theme_footer_logo_url'] ?? '';
        $themeHeader = trim($_POST['theme_header_color'] ?? '');
        $themeFooter = trim($_POST['theme_footer_color'] ?? '');
        $themeButton = trim($_POST['theme_button_color'] ?? '');
        $themeNavFill = trim($_POST['theme_nav_fill_color'] ?? '');
        $themeNavFont = trim($_POST['theme_nav_font_color'] ?? '');
        $themeNavWidth = trim($_POST['theme_nav_width'] ?? '');
        $resetTheme = isset($_POST['reset_theme']) && $_POST['reset_theme'] === '1';

        // Validate color fields -- only allow valid CSS hex colors (#rgb or #rrggbb)
        if (!$error) {
            $colorFields = [
                'themeHeader' => 'Header Color',
                'themeFooter' => 'Footer Color',
                'themeButton' => 'Button Color',
                'themeNavFill' => 'Navigation Fill Color',
                'themeNavFont' => 'Navigation Font Color',
            ];
            foreach ($colorFields as $var => $label) {
                $val = $$var;
                if ($val !== '' && !preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $val)) {
                    $error = t('profile.invalid_hex_color', $label);
                    break;
                }
            }
        }

        // Validate nav width is a number between 150 and 400
        if (!$error && !empty($themeNavWidth) && (!is_numeric($themeNavWidth) || $themeNavWidth < 150 || $themeNavWidth > 400)) {
            $error = t('profile.invalid_nav_width');
        }

        if (!$error) {
            // Base theme data (always available)
            $baseThemeData = $resetTheme ? [
                'theme_logo_url' => null,
                'theme_footer_logo_url' => null,
                'theme_header_color' => null,
                'theme_footer_color' => null,
                'theme_button_color' => null
            ] : [
                'theme_logo_url' => $themeLogo ?: null,
                'theme_footer_logo_url' => $themeFooterLogo ?: null,
                'theme_header_color' => $themeHeader ?: null,
                'theme_footer_color' => $themeFooter ?: null,
                'theme_button_color' => $themeButton ?: null
            ];

            // Navigation theme data (may not exist in older schemas)
            $navThemeData = $resetTheme ? [
                'theme_nav_fill_color' => null,
                'theme_nav_font_color' => null,
                'theme_nav_width' => null
            ] : [
                'theme_nav_fill_color' => $themeNavFill ?: null,
                'theme_nav_font_color' => $themeNavFont ?: null,
                'theme_nav_width' => $themeNavWidth ?: null
            ];

            try {
                // Try to save all including nav columns
                $db->update('users', array_merge($baseThemeData, $navThemeData), 'id = :id', [':id' => $user['id']]);
                $success = $resetTheme ? t('profile.theme_reset') : t('profile.theme_saved');
            } catch (Exception $e) {
                // If that fails, try saving just the base theme (nav columns may not exist)
                try {
                    $db->update('users', $baseThemeData, 'id = :id', [':id' => $user['id']]);
                    $success = $resetTheme ? t('profile.theme_reset') : t('profile.theme_saved_nav_migration');
                } catch (Exception $e2) {
                    error_log('Theme update failed: ' . $e2->getMessage());
                    $error = t('profile.theme_update_failed');
                }
            }
        }

    // --- Language preference: store the user's chosen UI language, or NULL for "system default" ---
    } elseif (isset($_POST['update_language'])) {
        $enabledLangs = i18nEnabledLanguages();
        $preferredLang = trim($_POST['preferred_language'] ?? '');

        if ($preferredLang === '') {
            $newLang = null; // empty selection means "use system default"
        } elseif (in_array($preferredLang, $enabledLangs, true)) {
            $newLang = $preferredLang;
        } else {
            $newLang = null;
            $error = t('profile.language_not_enabled');
        }

        if (!$error) {
            try {
                $db->update('users', ['preferred_language' => $newLang], 'id = :id', [':id' => $user['id']]);
                $auth->audit($user['id'], 'language_preference_update', 'users', $user['id'], [
                    'new' => ['preferred_language' => $newLang]
                ]);
                $success = t('profile.language_updated');
            } catch (Exception $e) {
                error_log('Language preference update failed: ' . $e->getMessage());
                $error = t('profile.language_update_failed');
            }
        }
    }

    if (!empty($success) || !empty($error)) {
        $userDetails = $db->fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $user['id']]);
    }
}

// Generate CSRF token only for GET requests or after successful POST.
// We learned the hard way that regenerating the token on a failed POST
// means the retry will also fail. Don't ask how many hours that took to debug.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($success)) {
    $csrfToken = $security->generateCSRFToken();
} else {
    $csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
}
$theme = getUserTheme();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="<?php echo e(currentLanguage()); ?>">
<head>
    <title>Profile - TPRM FAIR Analysis</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
        }

        /* Remove yellow background from navbar pseudo-element */
        nav.rd-navbar.rd-navbar-modern.rd-navbar-modern-1::before,
        nav.rd-navbar.rd-navbar-modern.rd-navbar-static::before {
            display: none !important;
        }

        /* Use nav fill color for the entire navbar area */
        .rd-navbar-main-outer {
            background: var(--nav-fill-color) !important;
        }
        .profile-container {
            padding: 40px 0;
        }
        .card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 25px;
        }
        .card h3 {
            color: var(--theme-header-color);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        .info-label {
            font-weight: 500;
            color: #666;
            width: 150px;
        }
        .info-value {
            color: #333;
        }
        .qr-code {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            margin: 20px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .alert {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper .form-control {
            padding-right: 44px;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #888;
            padding: 0;
            line-height: 1;
            font-size: 16px;
        }
        .password-toggle:hover {
            color: #333;
        }
        .password-hints {
            margin-top: 10px;
            font-size: 13px;
            line-height: 1.8;
            padding: 10px 14px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        .password-hints .hint::before {
            content: '\25CB  ';
            font-size: 11px;
        }
        .password-hints .hint {
            color: #666;
        }
        .password-hints .hint.pass {
            color: #28a745;
        }
        .password-hints .hint.pass::before {
            content: '\2713  ';
        }
        .password-hints .hint.fail {
            color: #dc3545;
        }
        .password-hints .hint.fail::before {
            content: '\2717  ';
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
        }
        .user-menu a {
            color: var(--nav-font-color);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            background: rgba(0,0,0,0.05);
            transition: background 0.2s;
        }
        .user-menu a:hover {
            background: rgba(0,0,0,0.1);
        }
        .rd-navbar-brand {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 15px 20px;
        }
        .rd-navbar-brand a,
        .rd-navbar-brand div {
            color: var(--nav-font-color) !important;
        }
    </style>
</head>
<body style="background: #fff;">
    <?php renderImpersonationBanner(); ?>
    <div class="page" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
        <header class="section page-header">
            <div class="rd-navbar-wrap">
                <nav class="rd-navbar rd-navbar-modern rd-navbar-modern-1">
                    <div class="rd-navbar-main-outer">
                        <div class="rd-navbar-main">
                            <div class="rd-navbar-panel">
                                <div class="rd-navbar-brand">
                                    <a class="brand" href="index.php">
                                        <img class="brand-logo-dark" src="<?php echo e($theme['logo_url']); ?>" alt="" width="209" height="39"/>
                                    </a>
                                    <div style="margin-top: 5px; color: #333; font-size: 16px; font-weight: 500;">
                                        <?php echo e(t('profile.profile_settings')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: var(--nav-font-color);"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('profile.dashboard')); ?></a>
                                <?php if ($auth->isAdmin()): ?>
                                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                                <?php endif; ?>
                                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <div class="profile-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <h1 class="page-title" style="margin-bottom: 30px;"><?php echo e(t('profile.profile_settings')); ?></h1>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <h3><?php echo e(t('profile.user_information')); ?></h3>
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                                <div class="form-group">
                                    <label for="username_display"><?php echo e(t('profile.username')); ?></label>
                                    <input type="text" id="username_display" class="form-control" value="<?php echo e($userDetails['username']); ?>" disabled>
                                    <small style="color: #666;"><?php echo e(t('profile.username_cannot_change')); ?></small>
                                </div>

                                <div class="form-group">
                                    <label for="full_name"><?php echo e(t('profile.full_name')); ?></label>
                                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo e($userDetails['full_name']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="email"><?php echo e(t('profile.email_address')); ?></label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?php echo e($userDetails['email']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label><?php echo e(t('profile.role')); ?></label>
                                    <div style="margin-top: 8px;">
                                        <?php echo $userDetails['is_admin'] ? '<span class="badge badge-success">' . e(t('profile.administrator')) . '</span>' : '<span class="badge badge-secondary">' . e(t('profile.user')) . '</span>'; ?>
                                    </div>
                                    <small style="color: #666;"><?php echo e(t('profile.role_admin_only')); ?></small>
                                </div>

                                <div class="info-row" style="margin-top: 15px;">
                                    <div class="info-label"><?php echo e(t('profile.last_login')); ?></div>
                                    <div class="info-value"><?php echo $userDetails['last_login'] ? date('M d, Y H:i', strtotime($userDetails['last_login'])) : e(t('profile.never')); ?></div>
                                </div>

                                <button type="submit" name="update_profile" class="btn btn-primary" style="margin-top: 15px;"><?php echo e(t('profile.update_profile')); ?></button>
                            </form>
                        </div>

                        <div class="card">
                            <h3><?php echo e(t('profile.lang.title')); ?></h3>
                            <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('profile.lang.intro')); ?></p>
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <div class="form-group">
                                    <label for="preferred_language"><?php echo e(t('profile.lang.label')); ?></label>
                                    <?php
                                    $langNames = i18nLanguages();
                                    $enabledLangs = i18nEnabledLanguages();
                                    $userLang = $userDetails['preferred_language'] ?? '';
                                    ?>
                                    <select id="preferred_language" name="preferred_language" class="form-control">
                                        <option value="" <?php echo $userLang === '' ? 'selected' : ''; ?>><?php echo e(t('profile.lang.system_default')); ?></option>
                                        <?php foreach ($enabledLangs as $code): ?>
                                        <option value="<?php echo e($code); ?>" <?php echo $userLang === $code ? 'selected' : ''; ?>><?php echo e($langNames[$code] ?? $code); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="update_language" class="btn btn-primary"><?php echo e(t('profile.lang.button')); ?></button>
                            </form>
                        </div>

                        <div class="card">
                            <h3><?php echo e(t('profile.change_password')); ?></h3>
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                                <div class="form-group">
                                    <label for="current_password"><?php echo e(t('profile.current_password')); ?></label>
                                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label for="new_password"><?php echo e(t('profile.new_password')); ?></label>
                                    <div class="password-wrapper">
                                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                                        <button type="button" class="password-toggle" data-target="new_password" title="<?php echo e(t('profile.show_password')); ?>">
                                            <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                                        </button>
                                    </div>
                                    <?php
                                    $pwReqs = $config->get('auth.password_requirements');
                                    ?>
                                    <div class="password-hints" id="password-hints">
                                        <div class="hint" data-rule="length"><?php echo e(t('profile.pw_min_length', $pwReqs['min_length'])); ?></div>
                                        <?php if ($pwReqs['require_uppercase']): ?>
                                        <div class="hint" data-rule="uppercase"><?php echo e(t('profile.pw_uppercase')); ?></div>
                                        <?php endif; ?>
                                        <?php if ($pwReqs['require_lowercase']): ?>
                                        <div class="hint" data-rule="lowercase"><?php echo e(t('profile.pw_lowercase')); ?></div>
                                        <?php endif; ?>
                                        <?php if ($pwReqs['require_numbers']): ?>
                                        <div class="hint" data-rule="number"><?php echo e(t('profile.pw_number')); ?></div>
                                        <?php endif; ?>
                                        <?php if ($pwReqs['require_special_chars']): ?>
                                        <div class="hint" data-rule="special"><?php echo e(t('profile.pw_special')); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password"><?php echo e(t('profile.confirm_new_password')); ?></label>
                                    <div class="password-wrapper">
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                        <button type="button" class="password-toggle" data-target="confirm_password" title="<?php echo e(t('profile.show_password')); ?>">
                                            <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <button type="submit" name="change_password" class="btn btn-primary"><?php echo e(t('profile.change_password')); ?></button>
                            </form>
                        </div>

                        <div class="card">
                            <h3><?php echo e(t('profile.theme_customization')); ?></h3>
                            <p style="color: #666; margin-bottom: 20px;"><?php echo e(t('profile.theme_intro')); ?></p>

                            <form method="POST" action="profile.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                                <div class="form-group">
                                    <label for="theme_header_color"><?php echo e(t('profile.header_color')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="color" id="theme_header_color" name="theme_header_color"
                                               value="<?php echo e($userDetails['theme_header_color'] ?: $defaultTheme['header_color']); ?>"
                                               style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="text" id="theme_header_color_text" class="form-control"
                                               value="<?php echo e($userDetails['theme_header_color'] ?: $defaultTheme['header_color']); ?>"
                                               readonly style="flex: 1;">
                                    </div>
                                    <small>Default: <span style="color: <?php echo e($defaultTheme['header_color']); ?>;">●</span> <?php echo e($defaultTheme['header_color']); ?></small>
                                </div>

                                <div class="form-group">
                                    <label for="theme_footer_color"><?php echo e(t('profile.footer_color')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="color" id="theme_footer_color" name="theme_footer_color"
                                               value="<?php echo e($userDetails['theme_footer_color'] ?: $defaultTheme['footer_color']); ?>"
                                               style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="text" id="theme_footer_color_text" class="form-control"
                                               value="<?php echo e($userDetails['theme_footer_color'] ?: $defaultTheme['footer_color']); ?>"
                                               readonly style="flex: 1;">
                                    </div>
                                    <small>Default: <span style="color: <?php echo e($defaultTheme['footer_color']); ?>;">●</span> <?php echo e($defaultTheme['footer_color']); ?></small>
                                </div>

                                <div class="form-group">
                                    <label for="theme_button_color"><?php echo e(t('profile.button_color')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="color" id="theme_button_color" name="theme_button_color"
                                               value="<?php echo e($userDetails['theme_button_color'] ?: $defaultTheme['button_color']); ?>"
                                               style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="text" id="theme_button_color_text" class="form-control"
                                               value="<?php echo e($userDetails['theme_button_color'] ?: $defaultTheme['button_color']); ?>"
                                               readonly style="flex: 1;">
                                    </div>
                                    <small>Default: <span style="color: <?php echo e($defaultTheme['button_color']); ?>;">●</span> <?php echo e($defaultTheme['button_color']); ?></small>
                                </div>

                                <hr style="margin: 25px 0; border: none; border-top: 1px solid #e5e7eb;">
                                <h4 style="margin-bottom: 15px; color: #333; font-size: 14px; font-weight: 600;"><?php echo e(t('profile.navigation_settings')); ?></h4>

                                <div class="form-group">
                                    <label for="theme_nav_fill_color"><?php echo e(t('profile.nav_fill_color')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="color" id="theme_nav_fill_color" name="theme_nav_fill_color"
                                               value="<?php echo e(($userDetails['theme_nav_fill_color'] ?? '') ?: $defaultTheme['nav_fill_color']); ?>"
                                               style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="text" id="theme_nav_fill_color_text" class="form-control"
                                               value="<?php echo e(($userDetails['theme_nav_fill_color'] ?? '') ?: $defaultTheme['nav_fill_color']); ?>"
                                               readonly style="flex: 1;">
                                    </div>
                                    <small>Default: <span style="color: <?php echo e($defaultTheme['nav_fill_color']); ?>;">●</span> <?php echo e($defaultTheme['nav_fill_color']); ?></small>
                                </div>

                                <div class="form-group">
                                    <label for="theme_nav_font_color"><?php echo e(t('profile.nav_font_color')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="color" id="theme_nav_font_color" name="theme_nav_font_color"
                                               value="<?php echo e(($userDetails['theme_nav_font_color'] ?? '') ?: $defaultTheme['nav_font_color']); ?>"
                                               style="width: 60px; height: 40px; border: 2px solid #ddd; border-radius: 4px; cursor: pointer;">
                                        <input type="text" id="theme_nav_font_color_text" class="form-control"
                                               value="<?php echo e(($userDetails['theme_nav_font_color'] ?? '') ?: $defaultTheme['nav_font_color']); ?>"
                                               readonly style="flex: 1;">
                                    </div>
                                    <small>Default: <span style="color: <?php echo e($defaultTheme['nav_font_color']); ?>;">●</span> <?php echo e($defaultTheme['nav_font_color']); ?></small>
                                </div>

                                <div class="form-group">
                                    <label for="theme_nav_width"><?php echo e(t('profile.nav_width')); ?></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="range" id="theme_nav_width_range" min="150" max="400" step="10"
                                               value="<?php echo e(($userDetails['theme_nav_width'] ?? '') ?: $defaultTheme['nav_width']); ?>"
                                               style="flex: 1; cursor: pointer;">
                                        <input type="number" id="theme_nav_width" name="theme_nav_width" class="form-control"
                                               value="<?php echo e(($userDetails['theme_nav_width'] ?? '') ?: $defaultTheme['nav_width']); ?>"
                                               min="150" max="400" style="width: 80px;">
                                        <span>px</span>
                                    </div>
                                    <small>Default: <?php echo e($defaultTheme['nav_width']); ?>px (Range: 150-400px)</small>
                                </div>

                                <div style="display: flex; gap: 10px; margin-top: 20px;">
                                    <button type="submit" name="update_theme" class="btn btn-primary"><?php echo e(t('profile.save_theme')); ?></button>
                                    <button type="submit" name="update_theme" value="1" id="resetThemeBtn" class="btn btn-secondary" style="background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;"><?php echo e(t('profile.reset_to_defaults')); ?></button>
                                    <script nonce="<?php echo cspNonce(); ?>">document.getElementById('resetThemeBtn').addEventListener('click', function() { document.querySelector('input[name=reset_theme]').value='1'; });</script>
                                    <input type="hidden" name="reset_theme" value="0">
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <h3><?php echo e(t('profile.totp_title')); ?></h3>

                            <?php if ($userDetails['totp_enabled']): ?>
                                <p style="color: #155724; margin-bottom: 20px;">
                                    <strong><?php echo e(t('profile.status')); ?></strong> <span class="badge badge-success"><?php echo e(t('profile.enabled')); ?></span>
                                </p>
                                <p style="color: #666; margin-bottom: 20px;">
                                    <?php echo e(t('profile.totp_enabled_desc')); ?>
                                </p>
                                <form method="POST" action="profile.php" data-confirm="Are you sure you want to disable TOTP?">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <button type="submit" name="disable_totp" class="btn btn-danger"><?php echo e(t('profile.disable_totp')); ?></button>
                                </form>
                            <?php elseif ($totpSetup): ?>
                                <p style="color: #856404; margin-bottom: 20px;">
                                    <strong><?php echo e(t('profile.status')); ?></strong> <span class="badge" style="background: #fff3cd; color: #856404;"><?php echo e(t('profile.setup_required')); ?></span>
                                </p>
                                <p style="color: #666; margin-bottom: 20px;">
                                    <?php echo e(t('profile.totp_scan_desc')); ?>
                                </p>

                                <div class="qr-code">
                                    <div id="totp-qr-code" style="max-width: 200px;"></div>
                                    <noscript>
                                        <p style="margin-top: 10px; font-size: 13px; color: #856404;">
                                            <?php echo e(t('profile.qr_noscript')); ?>
                                        </p>
                                    </noscript>
                                    <p style="margin-top: 15px; font-size: 12px; color: #666;">
                                        <?php echo e(t('profile.totp_manual_key')); ?> <code><?php echo e($totpSetup['secret']); ?></code>
                                    </p>
                                </div>

                                <form method="POST" action="profile.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                                    <div class="form-group">
                                        <label for="totp_code"><?php echo e(t('profile.enter_6_digit')); ?></label>
                                        <input type="text" id="totp_code" name="totp_code" class="form-control"
                                               pattern="[0-9]{6}" maxlength="6" required autofocus>
                                    </div>

                                    <button type="submit" name="confirm_totp" class="btn btn-primary"><?php echo e(t('profile.verify_enable')); ?></button>
                                </form>
                            <?php else: ?>
                                <p style="color: #666; margin-bottom: 20px;">
                                    <strong><?php echo e(t('profile.status')); ?></strong> <span class="badge badge-secondary"><?php echo e(t('profile.disabled')); ?></span>
                                </p>
                                <p style="color: #666; margin-bottom: 20px;">
                                    <?php echo e(t('profile.totp_disabled_desc')); ?>
                                </p>
                                <form method="POST" action="profile.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <button type="submit" name="enable_totp" class="btn btn-primary"><?php echo e(t('profile.enable_totp')); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php
                        // --- Dashboard Module Selection ---
                        $session = Session::getInstance();
                        $acl = ACL::getInstance();
                        $_isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
                        $_isCyberTPRM = hasGroup('cyber_tprm');
                        $_isProcurement = hasGroup('procurement');
                        $_isAuditor = hasGroup('auditor');
                        $_isCyberGRC = hasGroup('cyber_grc');
                        $_isStakeholderOnly = hasGroup('stakeholder') && !$_isAdmin && !$_isProcurement && !$_isCyberTPRM && !$_isAuditor && !$_isCyberGRC;
                        $_isProcurementOnly = $_isProcurement && !$_isAdmin && !$_isCyberTPRM;

                        // Master list of all dashboard modules
                        $allModules = [
                            'fair_analysis' => [
                                'name' => 'FAIR Risk Analysis',
                                'desc' => 'Quantify and analyze vendor risks using FAIR methodology.',
                                'icon' => 'app/icons/file-07.svg',
                                'tag' => 'Risk Assessment',
                            ],
                            'vendor_onboarding' => [
                                'name' => 'Vendor Onboarding',
                                'desc' => 'Submit and manage vendor intake requests.',
                                'icon' => 'app/icons/book-open-01.svg',
                                'tag' => 'Stakeholder Portal',
                            ],
                            'contracts' => [
                                'name' => 'Contracts',
                                'desc' => 'View and manage vendor contracts and procurement status.',
                                'icon' => 'app/icons/file-06.svg',
                                'tag' => 'Procurement',
                            ],
                            'srs_scoring' => [
                                'name' => 'SRS Scoring',
                                'desc' => 'Monitor vendor security ratings and scores.',
                                'icon' => 'app/icons/file-shield-02.svg',
                                'tag' => 'Security Ratings',
                            ],
                            'vendor_assessments' => [
                                'name' => 'Vendor Assessments',
                                'desc' => 'Send and manage security assessment questionnaires.',
                                'icon' => 'app/icons/file-shield-02.svg',
                                'tag' => 'Questionnaires',
                            ],
                            'annual_reviews' => [
                                'name' => 'Annual Reviews',
                                'desc' => 'Track vendor annual review schedules and completion.',
                                'icon' => 'app/icons/calendar-check-01.svg',
                                'tag' => 'Compliance',
                            ],
                            'cyber_todo' => [
                                'name' => 'Cyber To-Do',
                                'desc' => 'Action items requiring attention from the Cyber TPRM team.',
                                'icon' => 'app/icons/check-square-broken.svg',
                                'tag' => 'Task Management',
                            ],
                            'grc_compliance' => [
                                'name' => 'GRC Compliance',
                                'desc' => 'Governance, Risk, and Compliance framework management.',
                                'icon' => 'app/icons/file-shield-02.svg',
                                'tag' => 'GRC Module',
                            ],
                            'system_admin' => [
                                'name' => 'System Administration',
                                'desc' => 'Manage users, settings, and system configuration.',
                                'icon' => 'app/icons/key-01.svg',
                                'tag' => 'Admin Only',
                            ],
                        ];

                        // Determine which modules user has access to
                        $accessibleModules = [];
                        $showFair = (hasPermission('analysis.create') || hasPermission('analysis.read') || $_isCyberTPRM || $_isAdmin) && !$_isStakeholderOnly && !$_isProcurementOnly && !$_isAuditor;
                        $showOnboard = hasPermission('onboarding.create') || hasPermission('onboarding.read') || hasPermission('onboarding.read_own') || hasPermission('onboarding.read_assigned') || hasGroup('stakeholder') || hasGroup('procurement');
                        $showSRS = ($_isCyberTPRM || $_isAdmin || $_isAuditor) && !$_isStakeholderOnly;
                        $showGRC = $_isCyberGRC || $_isAdmin || $_isAuditor;

                        if ($showFair) $accessibleModules[] = 'fair_analysis';
                        if ($showOnboard) $accessibleModules[] = 'vendor_onboarding';
                        if ($_isProcurement || $_isAdmin) $accessibleModules[] = 'contracts';
                        if ($showSRS) $accessibleModules[] = 'srs_scoring';
                        if ($_isAdmin || $_isCyberTPRM || $_isProcurement || $_isAuditor) $accessibleModules[] = 'vendor_assessments';
                        if ($showOnboard || $_isCyberTPRM || $_isAdmin || $_isAuditor) $accessibleModules[] = 'annual_reviews';
                        if ($showSRS || $_isAdmin) $accessibleModules[] = 'cyber_todo';
                        if ($showGRC) $accessibleModules[] = 'grc_compliance';
                        if ($_isAdmin) $accessibleModules[] = 'system_admin';

                        // Load user's current module selection
                        $savedModules = [];
                        try {
                            $rawModules = $userDetails['dashboard_modules'] ?? null;
                            if ($rawModules) {
                                $decoded = json_decode($rawModules, true);
                                if (is_array($decoded)) $savedModules = $decoded;
                            }
                        } catch (Exception $e) {}
                        // If no saved preference, default to all accessible
                        if (empty($savedModules)) $savedModules = $accessibleModules;
                        ?>
                        <?php if (count($accessibleModules) > 0): ?>
                        <div class="card">
                            <h3><?php echo e(t('profile.dashboard_modules')); ?></h3>
                            <p style="color: #666; margin-bottom: 16px;"><?php echo e(t('profile.dashboard_modules_intro')); ?></p>
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <div id="module-selector" style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                                    <?php foreach ($accessibleModules as $key):
                                        $mod = $allModules[$key];
                                        $isSelected = in_array($key, $savedModules);
                                    ?>
                                    <label class="mod-pick <?php echo $isSelected ? 'mod-pick-on' : ''; ?>" style="display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 2px solid <?php echo $isSelected ? 'var(--theme-button-color, #35a0a3)' : '#e5e7eb'; ?>; border-radius: 8px; cursor: pointer; transition: border-color 0.15s, background 0.15s; background: <?php echo $isSelected ? 'rgba(53,160,163,0.05)' : '#fff'; ?>;">
                                        <input type="checkbox" name="dashboard_modules[]" value="<?php echo e($key); ?>" <?php echo $isSelected ? 'checked' : ''; ?> style="display:none;">
                                        <div style="width: 32px; height: 32px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 6px;">
                                            <img src="<?php echo e($mod['icon']); ?>" alt="" width="20" height="20">
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-weight: 600; font-size: 13px; color: #333;"><?php echo e($mod['name']); ?></div>
                                            <div style="font-size: 11px; color: #6b7280; margin-top: 1px;"><?php echo e($mod['desc']); ?></div>
                                        </div>
                                        <span class="mod-check" style="width: 22px; height: 22px; border-radius: 4px; border: 2px solid <?php echo $isSelected ? 'var(--theme-button-color, #35a0a3)' : '#d1d5db'; ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: <?php echo $isSelected ? 'var(--theme-button-color, #35a0a3)' : '#fff'; ?>; transition: all 0.15s;">
                                            <?php if ($isSelected): ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 16px;">
                                    <span id="mod-count" style="font-size: 12px; color: #6b7280;"><span id="mod-selected"><?php echo count(array_intersect($savedModules, $accessibleModules)); ?></span> <?php echo e(t('profile.slash_9_selected')); ?></span>
                                    <button type="submit" name="update_dashboard_modules" class="btn btn-primary"><?php echo e(t('profile.save_modules')); ?></button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top: 30px;">
                    <a href="index.php" style="color: #ff6543; text-decoration: none;">&larr; <?php echo e(t('profile.back_to_dashboard')); ?></a>
                </div>
            </div>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body section-lg">
                <div class="container wow fadeInUp">
                    <div class="row row-30 row-md-50 justify-content-md-between">
                        <div class="col-md-6 col-lg-4">
                            <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($theme['footer_logo_url'])): ?>
                                    <a class="brand" href="index.php">
                                        <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 60px;">
                                    </a>
                                <?php endif; ?>
                                <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                            </div>
                            <p class="rights">
                                <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                                <span class="copyright-year"><?php echo date('Y'); ?></span>
                                <span>.&nbsp;</span>
                                <span><?php echo e(t('profile.all_rights_reserved')); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Sync color picker with text field for theme colors
        document.getElementById('theme_header_color').addEventListener('input', function() {
            document.getElementById('theme_header_color_text').value = this.value.toUpperCase();
        });
        document.getElementById('theme_footer_color').addEventListener('input', function() {
            document.getElementById('theme_footer_color_text').value = this.value.toUpperCase();
        });
        document.getElementById('theme_button_color').addEventListener('input', function() {
            document.getElementById('theme_button_color_text').value = this.value.toUpperCase();
        });
        document.getElementById('theme_nav_fill_color').addEventListener('input', function() {
            document.getElementById('theme_nav_fill_color_text').value = this.value.toUpperCase();
        });
        document.getElementById('theme_nav_font_color').addEventListener('input', function() {
            document.getElementById('theme_nav_font_color_text').value = this.value.toUpperCase();
        });

        // Sync range slider with number input for nav width
        document.getElementById('theme_nav_width_range').addEventListener('input', function() {
            document.getElementById('theme_nav_width').value = this.value;
        });
        document.getElementById('theme_nav_width').addEventListener('input', function() {
            var val = parseInt(this.value) || 220;
            val = Math.max(150, Math.min(400, val));
            document.getElementById('theme_nav_width_range').value = val;
        });

        // Password visibility toggle
        document.querySelectorAll('.password-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var input = document.getElementById(this.getAttribute('data-target'));
                var open = this.querySelector('.eye-open');
                var closed = this.querySelector('.eye-closed');
                if (input.type === 'password') {
                    input.type = 'text';
                    open.style.display = 'none';
                    closed.style.display = '';
                    this.title = 'Hide password';
                } else {
                    input.type = 'password';
                    open.style.display = '';
                    closed.style.display = 'none';
                    this.title = 'Show password';
                }
            });
        });

        // Live password complexity hints
        var minLength = <?php echo (int)$pwReqs['min_length']; ?>;
        var newPw = document.getElementById('new_password');
        if (newPw) {
            function checkPasswordHints() {
                var pw = newPw.value;
                var rules = {
                    length:    pw.length >= minLength,
                    uppercase: /[A-Z]/.test(pw),
                    lowercase: /[a-z]/.test(pw),
                    number:    /[0-9]/.test(pw),
                    special:   /[^A-Za-z0-9]/.test(pw)
                };
                document.querySelectorAll('#password-hints .hint').forEach(function(el) {
                    var rule = el.getAttribute('data-rule');
                    if (rules.hasOwnProperty(rule)) {
                        el.classList.toggle('pass', rules[rule]);
                        el.classList.toggle('fail', !rules[rule]);
                    }
                });
            }
            newPw.addEventListener('input', checkPasswordHints);
            newPw.addEventListener('focus', checkPasswordHints);
        }

        // Dashboard module toggle cards
        (function() {
            var MAX = 9;
            var checkSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            var sel = document.getElementById('module-selector');
            if (!sel) return;
            var countEl = document.getElementById('mod-selected');
            var labels = sel.querySelectorAll('.mod-pick');

            function updateCount() {
                var n = sel.querySelectorAll('input[type=checkbox]:checked').length;
                if (countEl) countEl.textContent = n;
            }

            labels.forEach(function(lbl) {
                lbl.addEventListener('click', function(e) {
                    e.preventDefault();
                    var cb = lbl.querySelector('input[type=checkbox]');
                    var chk = lbl.querySelector('.mod-check');
                    if (cb.checked) {
                        cb.checked = false;
                        lbl.style.borderColor = '#e5e7eb';
                        lbl.style.background = '#fff';
                        chk.style.borderColor = '#d1d5db';
                        chk.style.background = '#fff';
                        chk.innerHTML = '';
                    } else {
                        var current = sel.querySelectorAll('input[type=checkbox]:checked').length;
                        if (current >= MAX) return;
                        cb.checked = true;
                        lbl.style.borderColor = 'var(--theme-button-color, #35a0a3)';
                        lbl.style.background = 'rgba(53,160,163,0.05)';
                        chk.style.borderColor = 'var(--theme-button-color, #35a0a3)';
                        chk.style.background = 'var(--theme-button-color, #35a0a3)';
                        chk.innerHTML = checkSvg;
                    }
                    updateCount();
                });
            });
        })();
    </script>
    <?php if ($totpSetup && !empty($totpSetup['qr_url'])): ?>
    <script nonce="<?php echo cspNonce(); ?>">
    // Minimal QR Code generator for TOTP provisioning URI
    // Generates QR code client-side so the TOTP secret never leaves the browser
    (function() {
        var uri = <?php echo json_encode($totpSetup['qr_url']); ?>;
        var container = document.getElementById('totp-qr-code');
        if (!container) return;

        // Use a canvas-based QR code via the provisioning URI
        // Fall back to showing the manual entry instructions if generation fails
        try {
            var img = document.createElement('img');
            img.alt = 'TOTP QR Code';
            img.style.maxWidth = '200px';
            img.style.imageRendering = 'pixelated';

            // Generate QR code as SVG data URI using simple encoding
            var qr = generateQR(uri);
            if (qr) {
                var size = qr.length;
                var scale = 4;
                var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+(size+8)+' '+(size+8)+'" width="'+((size+8)*scale)+'" height="'+((size+8)*scale)+'">';
                svg += '<rect width="100%" height="100%" fill="white"/>';
                for (var y = 0; y < size; y++) {
                    for (var x = 0; x < size; x++) {
                        if (qr[y][x]) {
                            svg += '<rect x="'+(x+4)+'" y="'+(y+4)+'" width="1" height="1" fill="black"/>';
                        }
                    }
                }
                svg += '</svg>';
                img.src = 'data:image/svg+xml;base64,' + btoa(svg);
                container.appendChild(img);
            } else {
                throw new Error('QR generation failed');
            }
        } catch(e) {
            container.innerHTML = '<p style="color: #856404; font-size: 13px;">Unable to generate QR code. Please enter the secret key manually in your authenticator app.</p>';
        }

        // Minimal QR Code encoder (Version 1-4, Byte mode, ECC Level L)
        function generateQR(data) {
            var bytes = [];
            for (var i = 0; i < data.length; i++) {
                var c = data.charCodeAt(i);
                if (c < 128) bytes.push(c);
                else if (c < 2048) { bytes.push(192|(c>>6)); bytes.push(128|(c&63)); }
                else { bytes.push(224|(c>>12)); bytes.push(128|((c>>6)&63)); bytes.push(128|(c&63)); }
            }

            // Determine version (1-10) based on byte capacity at ECC Level L
            var caps = [0,17,32,53,78,106,134,154,192,230,271];
            var ver = 0;
            for (var v = 1; v <= 10; v++) { if (bytes.length <= caps[v]) { ver = v; break; } }
            if (ver === 0) return null;

            var size = ver * 4 + 17;
            var grid = [], reserved = [];
            for (var y = 0; y < size; y++) {
                grid[y] = [];
                reserved[y] = [];
                for (var x = 0; x < size; x++) {
                    grid[y][x] = 0;
                    reserved[y][x] = false;
                }
            }

            // Place finder patterns
            function placeFinder(cx, cy) {
                for (var dy = -3; dy <= 3; dy++) {
                    for (var dx = -3; dx <= 3; dx++) {
                        var px = cx+dx, py = cy+dy;
                        if (px<0||py<0||px>=size||py>=size) continue;
                        var v = (Math.max(Math.abs(dx),Math.abs(dy))!==2)?1:0;
                        grid[py][px] = v;
                        reserved[py][px] = true;
                    }
                }
                // Separators
                for (var i = -4; i <= 4; i++) {
                    [[cx+i,cy-4],[cx+i,cy+4],[cx-4,cy+i],[cx+4,cy+i]].forEach(function(p) {
                        if (p[0]>=0&&p[1]>=0&&p[0]<size&&p[1]<size) {
                            grid[p[1]][p[0]] = 0;
                            reserved[p[1]][p[0]] = true;
                        }
                    });
                }
            }
            placeFinder(3, 3);
            placeFinder(size-4, 3);
            placeFinder(3, size-4);

            // Timing patterns
            for (var i = 8; i < size-8; i++) {
                grid[6][i] = (i%2===0)?1:0; reserved[6][i] = true;
                grid[i][6] = (i%2===0)?1:0; reserved[i][6] = true;
            }

            // Dark module
            grid[size-8][8] = 1; reserved[size-8][8] = true;

            // Alignment patterns (version >= 2)
            if (ver >= 2) {
                var alignPos = [6, ver*4+10];
                if (ver >= 7) alignPos = [6, Math.round(ver*4/2)+8, ver*4+10];
                for (var ai = 0; ai < alignPos.length; ai++) {
                    for (var aj = 0; aj < alignPos.length; aj++) {
                        var ax = alignPos[aj], ay = alignPos[ai];
                        if (reserved[ay] && reserved[ay][ax]) continue;
                        for (var dy = -2; dy <= 2; dy++) {
                            for (var dx = -2; dx <= 2; dx++) {
                                var px = ax+dx, py = ay+dy;
                                if (px<0||py<0||px>=size||py>=size) continue;
                                var v = (Math.max(Math.abs(dx),Math.abs(dy))===2||
                                        (dx===0&&dy===0))?1:0;
                                grid[py][px] = v;
                                reserved[py][px] = true;
                            }
                        }
                    }
                }
            }

            // Reserve format info areas
            for (var i = 0; i < 9; i++) {
                if (i < size) { reserved[8][i] = true; reserved[i][8] = true; }
            }
            for (var i = 0; i < 8; i++) {
                reserved[8][size-1-i] = true;
                reserved[size-1-i][8] = true;
            }

            // Reserve version info (version >= 7)
            if (ver >= 7) {
                for (var i = 0; i < 6; i++) {
                    for (var j = 0; j < 3; j++) {
                        reserved[i][size-11+j] = true;
                        reserved[size-11+j][i] = true;
                    }
                }
            }

            // Encode data
            var totalBits = getDataCapacity(ver);
            var bits = '';
            bits += '0100'; // Byte mode indicator
            var lenBits = (ver <= 9) ? 8 : 16;
            bits += pad(bytes.length, lenBits);
            for (var i = 0; i < bytes.length; i++) bits += pad(bytes[i], 8);
            bits += '0000'.substring(0, Math.min(4, totalBits - bits.length));
            while (bits.length % 8 !== 0) bits += '0';
            var padBytes = [0xEC, 0x11];
            var pi = 0;
            while (bits.length < totalBits) {
                bits += pad(padBytes[pi % 2], 8);
                pi++;
            }

            // Convert to byte array
            var dataBytes = [];
            for (var i = 0; i < bits.length; i += 8) {
                dataBytes.push(parseInt(bits.substring(i, i+8), 2));
            }

            // Generate error correction
            var ecInfo = getECInfo(ver);
            var allCodewords = [];
            var allEC = [];
            var offset = 0;
            for (var g = 0; g < ecInfo.groups.length; g++) {
                var group = ecInfo.groups[g];
                for (var b = 0; b < group.blocks; b++) {
                    var block = dataBytes.slice(offset, offset + group.dataPerBlock);
                    offset += group.dataPerBlock;
                    allCodewords.push(block);
                    allEC.push(rsEncode(block, ecInfo.ecPerBlock));
                }
            }

            // Interleave
            var result = [];
            var maxData = 0;
            for (var i = 0; i < allCodewords.length; i++) maxData = Math.max(maxData, allCodewords[i].length);
            for (var i = 0; i < maxData; i++) {
                for (var j = 0; j < allCodewords.length; j++) {
                    if (i < allCodewords[j].length) result.push(allCodewords[j][i]);
                }
            }
            for (var i = 0; i < ecInfo.ecPerBlock; i++) {
                for (var j = 0; j < allEC.length; j++) {
                    if (i < allEC[j].length) result.push(allEC[j][i]);
                }
            }

            // Convert to bit string
            var dataBits = '';
            for (var i = 0; i < result.length; i++) dataBits += pad(result[i], 8);

            // Place data on grid
            var bitIdx = 0;
            var upward = true;
            for (var right = size-1; right >= 1; right -= 2) {
                if (right === 6) right = 5; // Skip timing column
                var rows = upward ? range(size-1, -1) : range(0, size);
                for (var ri = 0; ri < rows.length; ri++) {
                    var y = rows[ri];
                    for (var dx = 0; dx >= -1; dx--) {
                        var x = right + dx;
                        if (x < 0 || x >= size) continue;
                        if (reserved[y][x]) continue;
                        if (bitIdx < dataBits.length) {
                            grid[y][x] = parseInt(dataBits[bitIdx]);
                            bitIdx++;
                        }
                    }
                }
                upward = !upward;
            }

            // Apply mask 0 (checkerboard) and format info
            var maskedGrid = [];
            for (var y = 0; y < size; y++) {
                maskedGrid[y] = [];
                for (var x = 0; x < size; x++) {
                    if (reserved[y][x]) {
                        maskedGrid[y][x] = grid[y][x];
                    } else {
                        var mask = ((y + x) % 2 === 0) ? 1 : 0;
                        maskedGrid[y][x] = grid[y][x] ^ mask;
                    }
                }
            }

            // Place format info (ECC L = 01, mask 0 = 000, format = 01000 -> with ECC: 111011111000100)
            var formatBits = '111011111000100';
            var formatPositions1 = [[0,8],[1,8],[2,8],[3,8],[4,8],[5,8],[7,8],[8,8],[8,7],[8,5],[8,4],[8,3],[8,2],[8,1],[8,0]];
            var formatPositions2 = [[8,size-1],[8,size-2],[8,size-3],[8,size-4],[8,size-5],[8,size-6],[8,size-7],[size-8,8],[size-7,8],[size-6,8],[size-5,8],[size-4,8],[size-3,8],[size-2,8],[size-1,8]];
            for (var i = 0; i < 15; i++) {
                var bit = parseInt(formatBits[i]);
                maskedGrid[formatPositions1[i][0]][formatPositions1[i][1]] = bit;
                maskedGrid[formatPositions2[i][0]][formatPositions2[i][1]] = bit;
            }

            return maskedGrid;
        }

        function pad(num, len) {
            var s = num.toString(2);
            while (s.length < len) s = '0' + s;
            return s;
        }

        function range(start, end) {
            var arr = [];
            if (start < end) { for (var i = start; i < end; i++) arr.push(i); }
            else { for (var i = start; i >= end+1; i--) arr.push(i); }
            return arr;
        }

        function getDataCapacity(ver) {
            // Total data codewords * 8 for ECC Level L
            var caps = [0,152,272,440,640,864,1088,1248,1552,1856,2192];
            return caps[ver] || 0;
        }

        function getECInfo(ver) {
            // ECC Level L configurations
            var info = {
                1: {ecPerBlock:7, groups:[{blocks:1, dataPerBlock:19}]},
                2: {ecPerBlock:10, groups:[{blocks:1, dataPerBlock:34}]},
                3: {ecPerBlock:15, groups:[{blocks:1, dataPerBlock:55}]},
                4: {ecPerBlock:20, groups:[{blocks:1, dataPerBlock:80}]},
                5: {ecPerBlock:26, groups:[{blocks:1, dataPerBlock:108}]},
                6: {ecPerBlock:18, groups:[{blocks:2, dataPerBlock:68}]},
                7: {ecPerBlock:20, groups:[{blocks:2, dataPerBlock:78}]},
                8: {ecPerBlock:24, groups:[{blocks:2, dataPerBlock:97}]},
                9: {ecPerBlock:30, groups:[{blocks:2, dataPerBlock:116}]},
                10:{ecPerBlock:18, groups:[{blocks:2, dataPerBlock:68},{blocks:2, dataPerBlock:69}]}
            };
            return info[ver];
        }

        // Reed-Solomon error correction encoding
        function rsEncode(data, ecLen) {
            // GF(256) with primitive polynomial 0x11D
            var gfExp = new Array(512), gfLog = new Array(256);
            var x = 1;
            for (var i = 0; i < 255; i++) {
                gfExp[i] = x;
                gfLog[x] = i;
                x <<= 1;
                if (x >= 256) x ^= 0x11D;
            }
            for (var i = 255; i < 512; i++) gfExp[i] = gfExp[i - 255];

            function gfMul(a, b) {
                if (a === 0 || b === 0) return 0;
                return gfExp[gfLog[a] + gfLog[b]];
            }

            // Generate generator polynomial
            var gen = [1];
            for (var i = 0; i < ecLen; i++) {
                var newGen = new Array(gen.length + 1).fill(0);
                for (var j = 0; j < gen.length; j++) {
                    newGen[j] ^= gen[j];
                    newGen[j+1] ^= gfMul(gen[j], gfExp[i]);
                }
                gen = newGen;
            }

            // Polynomial division
            var msg = data.slice();
            for (var i = 0; i < ecLen; i++) msg.push(0);
            for (var i = 0; i < data.length; i++) {
                var coef = msg[i];
                if (coef !== 0) {
                    for (var j = 1; j < gen.length; j++) {
                        msg[i+j] ^= gfMul(gen[j], coef);
                    }
                }
            }
            return msg.slice(data.length);
        }
    })();
    </script>
    <?php endif; ?>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
