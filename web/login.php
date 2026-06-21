<?php
/**
 * Login Page - The Velvet Rope of TPRM
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the front door to the whole application. Handles username/password
 * authentication with optional TOTP two-factor because we're not animals.
 * Also handles account lockouts after too many failed attempts, so if you
 * forget your password, maybe try writing it on a sticky note like the rest
 * of corporate America. (Just kidding. Please don't do that. Seriously.)
 *
 * Flow: User enters creds -> validate CSRF -> check username/password ->
 * if TOTP is enabled, ask for the 6-digit code -> let them in or tell them
 * to try again. It's like a nightclub bouncer but with less attitude.
 */

// Pull in the entire framework. We need Auth, Security, Session, the works.
require_once 'includes/init.php';

// Grab our singleton friends -- the gang's all here
$auth = Auth::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$theme = getUserTheme();

// Validate return_to URL: must be a relative path on our own domain.
// Block absolute URLs, protocol-relative URLs, and anything with newlines (header injection).
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? '';
if (!empty($returnTo) && (!preg_match('#^/[a-zA-Z0-9]#', $returnTo) || preg_match('/[\r\n]/', $returnTo))) {
    $returnTo = '';
}
$redirectAfterLogin = !empty($returnTo) ? $returnTo : 'index.php';

// If you're already logged in, why are you even here? Go home.
if ($auth->isAuthenticated()) {
    redirect($redirectAfterLogin);
}

// Check if SAML SSO is enabled for showing the SSO button
$samlEnabled = false;
try {
    $saml = new SAMLHandler();
    $samlEnabled = $saml->isEnabled();
} catch (Exception $e) {
    // SAML not available, that's fine
}

// Local-login policy. When SAML is enabled, an admin may set local_login_enabled=0
// to make SSO the only path for normal users. The designated break-glass admin
// account can ALWAYS use local login, so a broken/misconfigured IdP can never lock
// everyone out. If SAML is not actually configured, the toggle is ignored entirely
// (anti-lockout) and local login stays fully available.
$localLoginEnabled = true;
$breakGlassUser = 'admin';
if ($samlEnabled) {
    try {
        $cfgDb = Database::getInstance();
        // Config-file override (auth.local_login_enabled) takes precedence over the DB
        // toggle: it's a no-SQL, file-only break-glass control. null = key absent from
        // config.php, so we defer to the database setting exactly as before.
        $cfgLocalLogin = Config::getInstance()->get('auth.local_login_enabled', null);
        if ($cfgLocalLogin !== null) {
            $localLoginEnabled = (bool)$cfgLocalLogin;
        } else {
            $rowLL = $cfgDb->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'local_login_enabled'");
            if ($rowLL !== null && isset($rowLL['config_value'])) {
                $localLoginEnabled = ((string)$rowLL['config_value'] !== '0');
            }
        }
        $rowBG = $cfgDb->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'break_glass_admin_username'");
        if ($rowBG !== null && !empty($rowBG['config_value'])) {
            $breakGlassUser = (string)$rowBG['config_value'];
        }
    } catch (Exception $e) {
        // On any config-read error, fail OPEN for local login (anti-lockout).
        $localLoginEnabled = true;
    }
}
// SAML-only mode: SSO is the sole path for everyone except the break-glass admin.
$samlOnly = $samlEnabled && !$localLoginEnabled;

// Check for SAML error message from ACS redirect
$samlError = $session->get('saml_error');
if ($samlError) {
    $session->remove('saml_error');
}

// State variables for the login form -- tracking errors, success messages,
// and whether we need to bug the user for a TOTP code
$error = $samlError ?: '';
$success = '';
$requiresTOTP = false;
$pendingUserId = null;

// Only process form submissions, not GET requests (duh)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check first -- if someone tries to replay this form, they can go pound sand
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('login.invalid_request');

    // TOTP verification step -- user already passed username/password, now prove you have your phone
    } elseif (isset($_POST['totp_code'])) {
        // Use the pending user ID from the session, not from the POST data.
        // The POST field is untrusted -- an attacker could change it to target any user.
        $userId = intval($session->get('pending_totp_user_id', 0));
        if ($userId <= 0) {
            $error = t('login.invalid_request_restart');
        } else {
            // Strip anything that isn't a digit because people love pasting spaces
            $totpCode = preg_replace('/[^0-9]/', '', $_POST['totp_code']);

            $result = $auth->verifyTOTP($userId, $totpCode);
            if ($result['success']) {
                // Welcome aboard, captain
                redirect($redirectAfterLogin);
            } else {
                // Wrong code -- maybe your clock is off, or you typed it too slowly
                $error = $result['message'];
                $requiresTOTP = true;
                $pendingUserId = $userId;
            }
        }

    // Standard username/password login -- the bread and butter
    } else {
        // Use cleanInput (trim + strip null bytes) instead of sanitizeInput
        // (which HTML-encodes). HTML encoding before DB lookup would prevent
        // users with special chars in their username from authenticating.
        $username = $security->cleanInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($samlOnly && strcasecmp($username, $breakGlassUser) !== 0) {
            // SAML-only mode: reject local password auth for everyone except the
            // break-glass admin. Enforced server-side, not just hidden in the UI.
            $error = t('login.local_disabled');
        } elseif (empty($username) || empty($password)) {
            $error = t('login.enter_both');
        } else {
            // Let the Auth class do the heavy lifting -- password hashing, lockout checks, etc.
            $result = $auth->login($username, $password);

            if ($result['success']) {
                // No TOTP? Straight to the dashboard you go.
                redirect($redirectAfterLogin);
            } elseif (isset($result['requires_totp']) && $result['requires_totp']) {
                // Password was right, but you've got 2FA enabled. One more hoop to jump through.
                $requiresTOTP = true;
                $pendingUserId = $result['user_id'];
            } else {
                // Nope. Wrong password, locked out, or some other sad state of affairs.
                $error = $result['message'];
            }
        }
    }
}

// Generate a fresh CSRF token for the form
$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Login - TPRM FAIR Analysis</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
        }

        /* Remove yellow background from navbar pseudo-element */
        nav.rd-navbar.rd-navbar-modern.rd-navbar-modern-1::before,
        nav.rd-navbar.rd-navbar-modern.rd-navbar-static::before {
            display: none !important;
        }

        /* Make navbar background transparent/white */
        .rd-navbar,
        .rd-navbar-wrap,
        .rd-navbar-main-outer,
        .rd-navbar-main,
        .rd-navbar-panel {
            background: transparent !important;
        }

        /* Style the logo without background box */
        .rd-navbar-brand {
            display: inline-block;
            padding: 20px !important;
        }

        .rd-navbar-brand .brand {
            display: block;
        }

        .rd-navbar-brand img {
            display: block;
            max-height: 60px;
            width: auto;
        }

        .page-header {
            background: #fff;
            margin-bottom: 0;
        }

        .login-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h2 {
            color: #333;
            font-weight: 500;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--theme-button-color);
            box-shadow: 0 0 0 2px rgba(53,160,163,0.1);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--theme-button-color);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-login:hover {
            background: var(--theme-footer-color) !important;
            color: white !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
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
        .ie-panel{display: none;background: #212121;padding: 10px 0;box-shadow: 3px 3px 5px 0 rgba(0,0,0,.3);clear: both;text-align:center;position: relative;z-index: 1;}
        html.ie-10 .ie-panel, html.lt-ie-10 .ie-panel {display: block;}
    </style>
</head>
<body>
    <div class="ie-panel"><a href="http://windows.microsoft.com/en-US/internet-explorer/"><img src="app/images/ie8-panel/warning_bar_0000_us.jpg" height="42" width="820" alt="You are using an outdated browser. For a faster, safer browsing experience, upgrade for free today."></a></div>
    <div class="preloader"></div>
    <div class="page">
        <header class="section page-header">
            <div class="rd-navbar-wrap">
                <nav class="rd-navbar rd-navbar-modern rd-navbar-modern-1">
                    <div class="rd-navbar-main-outer">
                        <div class="rd-navbar-main">
                            <div class="rd-navbar-panel">
                                <div class="rd-navbar-brand">
                                    <a class="brand" href="login.php">
                                        <?php if (!empty($theme['logo_url'])): ?>
                                            <img class="brand-logo-dark" src="<?php echo e($theme['logo_url']); ?>" alt="Logo" width="209" height="39"/>
                                        <?php else: ?>
                                            <img class="brand-logo-dark" src="app/images/logo-default-418x78.png" alt="Logo" width="209" height="39"/>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <div class="login-container">
            <div class="login-header">
                <h2><?php echo e($requiresTOTP ? t('login.two_factor_auth') : t('login.sign_in')); ?></h2>
                <p style="color: #777; font-size: 14px;">
                    <?php echo e($requiresTOTP ? t('login.enter_totp') : t('login.enter_credentials')); ?>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php if ($requiresTOTP): ?>
                <form method="POST" action="login.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <?php if (!empty($returnTo)): ?>
                        <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="totp_code"><?php echo e(t('login.totp_code')); ?></label>
                        <input type="text" id="totp_code" name="totp_code" class="form-control"
                               pattern="[0-9]{6}" maxlength="6" required autofocus
                               placeholder="000000">
                    </div>

                    <button type="submit" class="btn-login"><?php echo e(t('login.verify')); ?></button>

                    <div style="text-align: center; margin-top: 15px;">
                        <a href="login.php" style="color: var(--theme-button-color); text-decoration: none;"><?php echo e(t('login.back_to_login')); ?></a>
                    </div>
                </form>
            <?php elseif ($samlEnabled): ?>
                <a href="saml/login<?php echo !empty($returnTo) ? '?return_to=' . urlencode($returnTo) : ''; ?>"
                   class="btn-login" style="display: block; text-align: center; text-decoration: none; background: var(--theme-header-color);">
                    <?php echo e(t('login.sign_in_sso')); ?>
                </a>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="#" id="localSigninToggle" style="color: #999; font-size: 12px; text-decoration: none;"><?php echo e($samlOnly ? t('login.break_glass_login') : t('login.local_sign_in')); ?></a>
                </div>

                <div id="localSigninForm" style="display: <?php echo $error && !$samlError ? '' : 'none'; ?>; margin-top: 20px;">
                    <div style="position: relative; margin: 15px 0; border-top: 1px solid #ddd;">
                        <span style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 12px; color: #999; font-size: 13px;"><?php echo e(t('login.or')); ?></span>
                    </div>
                    <form method="POST" action="login.php" autocomplete="off" style="margin-top: 20px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <?php if (!empty($returnTo)): ?>
                            <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="username"><?php echo e(t('login.username')); ?></label>
                            <input type="text" id="username" name="username" class="form-control"
                                   required autocomplete="username">
                        </div>

                        <div class="form-group">
                            <label for="password"><?php echo e(t('login.password')); ?></label>
                            <input type="password" id="password" name="password" class="form-control"
                                   required autocomplete="current-password">
                        </div>

                        <button type="submit" class="btn-login"><?php echo e(t('login.sign_in')); ?></button>
                    </form>
                </div>

                <script nonce="<?php echo cspNonce(); ?>">
                document.getElementById('localSigninToggle').addEventListener('click', function(e) {
                    e.preventDefault();
                    var form = document.getElementById('localSigninForm');
                    form.style.display = form.style.display === 'none' ? '' : 'none';
                });
                </script>
            <?php else: ?>
                <form method="POST" action="login.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <?php if (!empty($returnTo)): ?>
                        <input type="hidden" name="return_to" value="<?php echo e($returnTo); ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="username"><?php echo e(t('login.username')); ?></label>
                        <input type="text" id="username" name="username" class="form-control"
                               required autofocus autocomplete="username">
                    </div>

                    <div class="form-group">
                        <label for="password"><?php echo e(t('login.password')); ?></label>
                        <input type="password" id="password" name="password" class="form-control"
                               required autocomplete="current-password">
                    </div>

                    <button type="submit" class="btn-login"><?php echo e(t('login.sign_in')); ?></button>
                </form>
            <?php endif; ?>
        </div>

        <footer class="section footer-modern bg-gray-13" style="margin-top: 50px;">
            <div class="footer-modern-body section-lg">
                <div class="container wow fadeInUp">
                    <div class="row row-30 row-md-50 justify-content-md-between">
                        <div class="col-md-6 col-lg-4">
                            <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($theme['footer_logo_url'])): ?>
                                    <a class="brand" href="login.php">
                                        <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 60px;">
                                    </a>
                                <?php endif; ?>
                                <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                            </div>
                            <p class="rights">
                                <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                                <span class="copyright-year"><?php echo date('Y'); ?></span>
                                <span>.&nbsp;</span>
                                <span><?php echo e(t('login.all_rights_reserved')); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
