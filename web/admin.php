<?php
/**
 * Admin Panel - The God Mode Console (Now With Less Spaghetti)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Welcome to the admin panel, the place where all the knobs and levers live.
 * This is the configuration hub for the entire app: general settings, FAIR
 * calculation defaults, email/SMTP configuration, SAML SSO, OpenWebUI/AI
 * integration, SRS/UpGuard security ratings, user management, and ACL groups.
 *
 * If the main dashboard is the cockpit, this is the engine room. Only admins
 * get in here, and with great power comes great responsibility. And also the
 * ability to accidentally lock yourself out if you change the auth settings
 * without thinking. Ask me how I know.
 *
 * This file is now a thin router. Each section lives in includes/admin/ and
 * handles its own POST logic and HTML output. We finally did the refactor.
 * You're welcome, future us.
 */

require_once 'includes/init.php';

// Handle stop impersonation BEFORE requireAdmin() check.
// This is important: if an admin is impersonating a non-admin user, they need
// to be able to stop impersonation from this page even though the "current user"
// wouldn't normally have admin access. Without this, you'd be stuck as that user. Awkward.
$auth = Auth::getInstance();
if (isset($_GET['stop_impersonation']) && $auth->isImpersonating()) {
    $auth->stopImpersonation();
    header('Location: admin.php?section=users');
    exit;
}

// Now that impersonation is handled, enforce the admin requirement.
// Exception: cyber_tprm group members can access assessment-templates, field-reference, and workflows sections.
$requestedSection = $_GET['section'] ?? 'general';
if (in_array($requestedSection, ['assessment-templates', 'field-reference', 'workflows'])) {
    requireAuth();
    $authCheck = Auth::getInstance();
    $aclCheck = ACL::getInstance();
    $sessionCheck = Session::getInstance();
    $isTplAdmin = $sessionCheck->get('is_super_admin') || $authCheck->isAdmin() || hasGroup('administrator') || hasGroup('cyber_tprm');
    if (!$isTplAdmin) {
        http_response_code(403);
        die('Access denied.');
    }
} else {
    requireAdmin();
}

// Singleton party -- everyone's invited
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$config = Config::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();

// Figure out which admin section we're looking at.
// Default to 'general' because you gotta start somewhere.
$section = $_GET['section'] ?? 'general';

// Whitelist of valid sections -> file mappings.
// If you're trying to sneak in a ../../../etc/passwd, think again.
// Backward compatibility: redirect old ?section=shodan to merged SRS page
if ($section === 'shodan') {
    $section = 'srs';
    $_GET['srs_tab'] = 'shodan';
}

$sectionFiles = [
    'general'   => 'general.php',
    'fair'      => 'fair-defaults.php',
    'email'     => 'email.php',
    'saml'      => 'saml.php',
    'ai-integration' => 'api-integration.php',
    'openwebui' => 'api-integration.php',
    'api'       => 'api-integration.php',
    'ai-platform' => 'ai-platform.php',
    'api-management' => 'api-tokens.php',
    'api-tokens' => 'api-tokens.php',
    'srs'       => 'srs.php',
    'shadow-saas' => 'shadow-saas-grip.php',
    'users'     => 'users.php',
    'groups'    => 'groups.php',
    'backup'    => 'backup.php',
    'updates'   => 'updates.php',
    'activity'  => 'activity-log.php',
    'version'   => 'version.php',
    'assessment-templates' => 'assessment-templates.php',
    'field-reference' => 'field-reference.php',
    'workflows' => 'workflows.php',
    'scheduler' => 'scheduler.php',
    'lockdown'  => 'lockdown.php',
];

if (!array_key_exists($section, $sectionFiles)) {
    $section = 'general'; // Nice try, hacker
}

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');

// Live Grip sync progress (AJAX/JSON) - polled by the Shadow SaaS "Last Sync"
// card to render the roster-hydration percentage. Must answer BEFORE any layout
// HTML is emitted, so it lives here alongside the other pre-render handlers.
if ($section === 'shadow-saas' && isset($_GET['grip_sync_status'])) {
    header('Content-Type: application/json');
    $out = ['status' => 'none', 'running' => false, 'done' => 0, 'total' => 0, 'percent' => null];
    try {
        $row = $db->fetchOne("SELECT status, started_at, finished_at, roster_done, roster_total
                              FROM shadow_saas_grip_sync_log ORDER BY id DESC LIMIT 1");
        if ($row) {
            $done    = (int)($row['roster_done'] ?? 0);
            $total   = (int)($row['roster_total'] ?? 0);
            $running = ($row['status'] === 'running');
            $out = [
                'status'      => $row['status'],
                'running'     => $running,
                'done'        => $done,
                'total'       => $total,
                'percent'     => $total > 0 ? min(100, (int)floor($done * 100 / $total)) : ($running ? 0 : 100),
                'started_at'  => $row['started_at'],
                'finished_at' => $row['finished_at'],
            ];
        }
    } catch (Throwable $e) {
        $out['error'] = 'unavailable';
    }
    echo json_encode($out);
    exit;
}

// Load BackupService only when we actually need it (backup section).
// No sense dragging in the heavy machinery for every page load.
if ($section === 'backup' || $section === 'version') {
    require_once __DIR__ . '/includes/classes/BackupService.php';
}

// Handle database backup download (must be before any output).
// This streams the file directly, so it needs to happen before we start
// spitting out HTML. Headers and readfile don't mix with half-rendered pages.
if (isset($_GET['download_backup']) && $section === 'backup') {
    $backupDir = __DIR__ . '/db_backup';
    $requestedFile = basename($_GET['download_backup']); // basename prevents directory traversal
    $filePath = $backupDir . '/' . $requestedFile;

    if (preg_match('/^tprm_database_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $requestedFile)
        && file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $requestedFile . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($filePath);
        exit;
    } else {
        header('HTTP/1.1 404 Not Found');
        exit('Backup file not found.');
    }
}

// Form submission result tracking -- sections set these as needed
$error = '';
$success = '';

// Defense-in-depth: mark that section files are being loaded via the
// admin dispatcher (each includes/admin/*.php refuses direct web access).
define('ADMIN_DISPATCH', true);

// Resolve the section file path once. Used for both POST handling and rendering.
$sectionPath = __DIR__ . '/includes/admin/' . $sectionFiles[$section];

// Include the section file under output buffering to run POST handlers.
// This sets $error/$success but we throw the HTML away -- forms need $csrfToken
// which doesn't exist until after POST processing. Chicken, meet egg.
$csrfToken = '';  // Placeholder so the first-pass include doesn't choke on undefined var
ob_start();
include $sectionPath;
ob_end_clean();

// POST handlers have run. Now neutralize $_SERVER['REQUEST_METHOD'] so the
// section's POST guards don't fire again on the second include below.
// We save the original method first because the CSRF token logic needs it.
$originalMethod = $_SERVER['REQUEST_METHOD'];
if ($originalMethod === 'POST') {
    $_SERVER['REQUEST_METHOD'] = 'GET'; // Shh, pretend it was a GET all along
}

// Generate CSRF token after POST processing.
// On successful POST we regenerate; otherwise reuse the existing one from session.
// validateCSRFToken() already rotated the session token if validation passed,
// so getCSRFToken() returns the fresh one. Neat, right?
if ($originalMethod !== 'POST' || !empty($success)) {
    $csrfToken = $security->generateCSRFToken();
} else {
    $csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
}

// Now the section file gets included for real inside the HTML layout below.
// POST handlers won't re-fire because we switched REQUEST_METHOD to GET.
// The section just loads its data and renders HTML with $csrfToken available.
?>
<!DOCTYPE html>
<html lang="<?php echo e(currentLanguage()); ?>">
<head>
    <title><?php echo e(t('admin.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }
        .main-content a { color: #374151; }
        .main-content a:hover { color: var(--theme-footer-color, #374151); text-decoration: none; }

        .page { display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        .sidebar {
            width: var(--sidebar-width) !important;
            min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important;
            background: var(--nav-fill-color) !important;
            padding: 0; flex-shrink: 0;
            display: flex; flex-direction: column;
        }
        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color);
            font-size: 13px; font-weight: 500;
            margin-top: 8px; opacity: 0.9;
        }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.5px;
            color: var(--nav-font-color); opacity: 0.5;
            padding: 0 20px; margin-bottom: 10px;
        }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 20px; color: var(--nav-font-color);
            opacity: 0.85; text-decoration: none; font-size: 13px;
            transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1); opacity: 1;
            border-left-color: var(--nav-font-color);
        }
        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15); opacity: 1;
            border-left-color: var(--nav-font-color); font-weight: 500;
        }
        .sidebar-nav li a .icon {
            font-size: 16px; width: 20px; text-align: center; opacity: 0.9;
        }

        .main-content {
            flex: 1; padding: 30px 35px;
            background: #f9fafb; min-width: 0; overflow-y: auto;
        }
        .page-header-bar {
            margin-bottom: 25px;
        }
        .page-header-bar h1 {
            font-size: 24px; margin: 0 0 5px 0; color: #333; font-weight: 600;
        }
        .page-header-bar p {
            color: #666; margin: 0; font-size: 14px;
        }

        .card {
            background: white; border: 1px solid #e5e7eb;
            border-radius: 10px; padding: 25px; margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .card h3 {
            color: var(--theme-header-color); margin: 0 0 20px 0;
            font-size: 16px; font-weight: 600;
            padding-bottom: 12px; border-bottom: 2px solid #f0f0f0;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 6px;
            color: #333; font-weight: 500; font-size: 13px;
        }
        .form-control, .form-select {
            width: 100%; padding: 10px 12px;
            border: 1px solid #ddd; border-radius: 4px; font-size: 14px;
        }
        select.form-control {
            height: 42px;
            line-height: 1.5;
            padding-top: 8px;
            padding-bottom: 8px;
        }
        textarea.form-control { min-height: 80px; }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: var(--theme-header-color);
        }
        .form-help { color: #666; font-size: 12px; margin-top: 4px; }

        .btn {
            padding: 10px 20px; border: none; border-radius: 4px;
            cursor: pointer; font-size: 13px; font-weight: 500;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--theme-header-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert {
            padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; font-size: 14px;
        }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; padding: 10px; text-align: left; font-weight: 500; border-bottom: 2px solid #dee2e6; }
        td { padding: 10px; border-bottom: 1px solid #dee2e6; }
        .badge {
            padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fef2f2; color: #991b1b; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #f3e8ff; color: #7c3aed; }

        .modal {
            display: none; position: fixed; z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fff; margin: 5% auto; padding: 25px;
            border: 1px solid #888; border-radius: 8px;
            width: 90%; max-width: 500px;
        }
        .close { color: #aaa; float: right; font-size: 24px; cursor: pointer; }
        .close:hover { color: #000; }

        .checkbox-label { display: flex; align-items: center; gap: 8px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
        .form-row .form-group { margin-bottom: 15px; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }

        @media (max-width: 992px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100% !important; min-width: 100% !important; max-width: 100% !important; }
            .sidebar-brand { display: flex; align-items: center; gap: 15px; padding: 15px 20px; }
            .sidebar-brand img { max-width: 150px; }
            .sidebar-brand .brand-title { margin-top: 0; }
            .sidebar-content { padding: 10px 0; }
            .sidebar-nav { display: flex; flex-wrap: wrap; padding: 0 10px; }
            .sidebar-nav li { flex: 0 0 auto; }
            .sidebar-nav li a { padding: 8px 14px; border-radius: 6px; margin: 3px; border-left: none; }
            .main-content { padding: 20px; }
        }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }

        /* Footer styling */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
            overflow: visible;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
    <div class="page">
        <?php if ($auth->isImpersonating()): ?>
        <?php $impersonationInfo = $auth->getImpersonationInfo(); ?>
        <div class="impersonation-banner" style="background: linear-gradient(90deg, #f59e0b, #d97706); color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; font-size: 14px;">
            <div>
                <strong><?php echo e(t('admin.impersonation_active')); ?></strong> <?php echo e(t('admin.viewing_as')); ?> <strong><?php echo e($impersonationInfo['impersonating_full_name']); ?></strong> (<?php echo e($impersonationInfo['impersonating_username']); ?>)
                <span style="opacity: 0.8; margin-left: 10px;">| <?php echo e(t('admin.logged_in_as')); ?> <?php echo e($impersonationInfo['original_full_name']); ?></span>
            </div>
            <form method="POST" action="admin.php?section=users" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <button type="submit" name="stop_impersonation" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.5); padding: 6px 15px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                    <?php echo e(t('admin.stop_impersonation')); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <a href="index.php"><?php echo e(t('admin.dashboard')); ?></a>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <aside class="sidebar">
                <div class="sidebar-brand">
                    <a href="index.php">
                        <img src="<?php echo e($theme['logo_url']); ?>" alt="<?php echo e(t('admin.header_logo_alt')); ?>"/>
                    </a>
                    <div class="brand-title"><?php echo e(t('admin.brand_title')); ?></div>
                </div>

                <div class="sidebar-content">
                    <details class="sidebar-section"<?php echo in_array($section, ['general', 'fair', 'email']) ? ' open' : ''; ?>>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_configuration')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="?section=general" class="<?php echo $section === 'general' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/settings-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_general')); ?></span></a></li>
                            <li><a href="?section=fair" class="<?php echo $section === 'fair' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/bar-chart-11.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_fair_defaults')); ?></span></a></li>
                            <li><a href="?section=email" class="<?php echo $section === 'email' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/mail-04.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_email_settings')); ?></span></a></li>
                        </ul>
                    </details>

                    <details class="sidebar-section"<?php echo in_array($section, ['saml', 'openwebui', 'api', 'api-tokens', 'srs', 'shadow-saas']) ? ' open' : ''; ?>>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_integrations')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="?section=saml" class="<?php echo $section === 'saml' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/users-01.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_saml_sso')); ?></span></a></li>
                            <li><a href="?section=ai-integration" class="<?php echo in_array($section, ['ai-integration', 'api', 'openwebui']) ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/message-text-square-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_ai_integration')); ?></span></a></li>
                            <li><a href="?section=api-management" class="<?php echo in_array($section, ['api-management', 'api-tokens']) ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/key-01.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_restful_api')); ?></span></a></li>
                            <li><a href="?section=srs" class="<?php echo $section === 'srs' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/cube-outline.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_srs')); ?></span></a></li>
                            <li><a href="?section=shadow-saas" class="<?php echo $section === 'shadow-saas' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/cube-outline.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_shadow_saas')); ?></span></a></li>
                        </ul>
                    </details>

                    <details class="sidebar-section"<?php echo in_array($section, ['users', 'groups']) ? ' open' : ''; ?>>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_access_control')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="?section=users" class="<?php echo $section === 'users' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/user-03.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_users')); ?></span></a></li>
                            <li><a href="?section=groups" class="<?php echo $section === 'groups' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/users-01.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_acl_groups')); ?></span></a></li>
                        </ul>
                    </details>

                    <details class="sidebar-section"<?php echo in_array($section, ['assessment-templates', 'field-reference', 'workflows']) ? ' open' : ''; ?>>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_assessments')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="?section=assessment-templates" class="<?php echo $section === 'assessment-templates' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/layout-alt-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_template_builder')); ?></span></a></li>
                            <li><a href="?section=field-reference" class="<?php echo $section === 'field-reference' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/file-03.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_field_reference')); ?></span></a></li>
                            <li><a href="?section=workflows" class="<?php echo $section === 'workflows' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/dataflow-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_workflow_rules')); ?></span></a></li>
                        </ul>
                    </details>

                    <details class="sidebar-section"<?php echo in_array($section, ['scheduler', 'lockdown', 'backup', 'updates', 'activity', 'version']) ? ' open' : ''; ?>>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_maintenance')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="?section=scheduler" class="<?php echo $section === 'scheduler' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/clock.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_scheduler')); ?></span></a></li>
                            <li><a href="?section=lockdown" class="<?php echo $section === 'lockdown' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_lockdown')); ?></span></a></li>
                            <li><a href="?section=backup" class="<?php echo $section === 'backup' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/database-01.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_database_backup')); ?></span></a></li>
                            <li><a href="?section=updates" class="<?php echo $section === 'updates' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/lightning-02.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_sql_updates')); ?></span></a></li>
                            <li><a href="?section=activity" class="<?php echo $section === 'activity' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/file-03.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_activity_log')); ?></span></a></li>
                            <li><a href="?section=version" class="<?php echo $section === 'version' ? 'active' : ''; ?>"><span class="icon"><img src="app/icons/git-branch-01.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_version')); ?></span></a></li>
                        </ul>
                    </details>

                    <details class="sidebar-section" open>
                        <summary class="sidebar-section-title"><?php echo e(t('admin.section_navigation')); ?></summary>
                        <ul class="sidebar-nav">
                            <li><a href="index.php"><span class="icon"><img src="app/icons/home-03.svg" alt="" width="18" height="18"></span><span><?php echo e(t('admin.nav_home')); ?></span></a></li>
                        </ul>
                    </details>
                </div>
            </aside>
            <script nonce="<?php echo cspNonce(); ?>">
            // Inline sidebar SVG icons so stroke="currentColor" inherits --nav-font-color
            document.querySelectorAll('.sidebar .icon img').forEach(function(img) {
                fetch(img.getAttribute('src')).then(function(r){return r.text()}).then(function(svg) {
                    var span = document.createElement('span');
                    span.innerHTML = svg.trim();
                    var el = span.querySelector('svg');
                    if (el) {
                        el.setAttribute('width', img.getAttribute('width') || '18');
                        el.setAttribute('height', img.getAttribute('height') || '18');
                        el.removeAttribute('class');
                        img.parentNode.replaceChild(el, img);
                    }
                });
            });
            </script>

            <main class="main-content">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <?php
                // Here's the magic -- include the section file which outputs its
                // HTML content. Each section is self-contained with its own page
                // header, cards, forms, modals, and scripts.
                //
                // POST handlers already ran during the ob_start() include above,
                // and we flipped REQUEST_METHOD to GET so they won't fire again.
                // The section loads fresh data and renders HTML with $csrfToken.
                include $sectionPath;
                ?>
            </main>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('admin.footer_logo_alt')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('admin.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/mobile-nav.js" nonce="<?php echo cspNonce(); ?>"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
