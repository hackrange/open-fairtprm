<?php
/**
 * Documentation Portal - Open TPRM & GRC Platform
 *
 * Comprehensive user documentation for TPRM, GRC, and Admin modules.
 * Uses the same sidebar navigation and layout as all other pages.
 * Only accessible to users in the administrator group.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();

$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$isCyberTPRM = hasGroup('cyber_tprm');
$isAuditor = hasGroup('auditor');
$isCyberGRC = hasGroup('cyber_grc');

$nonce = cspNonce();
$currentPage = 'documentation';
$logoUrl = $theme['logo_url'] ?? 'app/template/site/images/logo-default-418x78.png';
$csrfToken = $security->getCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="<?php echo e(currentLanguage()); ?>">
<head>
    <title>Documentation - Fair TPRM &amp; GRC</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style nonce="<?php echo $nonce; ?>">
        :root {
            --theme-header-color: <?php echo e($theme['header_color'] ?? '#35a0a3'); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color'] ?? '#1a1a2e'); ?>;
            --theme-button-color: <?php echo e($theme['button_color'] ?? '#ff6543'); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color'] ?? '#1a1a2e'); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color'] ?? '#ffffff'); ?>;
            --sidebar-width: <?php echo e($theme['nav_width'] ?? '260'); ?>px;
        }

        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }

        .top-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px; display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px; background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px; }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar { width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important; max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important; padding: 0; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5; padding: 0 20px; margin-bottom: 10px; }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-font-color); opacity: 0.85; text-decoration: none; font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .sidebar .icon svg { color: var(--nav-font-color, #ffffff); }

        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; scroll-behavior: smooth; }

        /* Documentation Content Styles */
        .doc-wrapper { max-width: 900px; }
        .doc-body { min-width: 0; }
        .doc-body h1 { font-size: 28px; font-weight: 700; color: #111827; margin: 0 0 6px; }
        .doc-body .doc-subtitle { font-size: 15px; color: #6b7280; margin-bottom: 24px; }

        /* Floating right-side TOC — anchored ~0.5in to the right of the
           900px article column (sidebar + 40px content padding + 900px
           article + 48px gap), clamped so it never runs off-screen. */
        .doc-sidebar-toc {
            width: 220px;
            position: fixed; top: 80px;
            left: min(calc(var(--sidebar-width) + 988px), calc(100vw - 244px));
            right: auto;
            max-height: calc(100vh - 110px); overflow-y: auto;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 16px 14px; font-size: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            z-index: 100;
        }
        .doc-sidebar-toc h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; margin: 12px 0 4px; }
        .doc-sidebar-toc h4:first-child { margin-top: 0; }
        .doc-sidebar-toc a { display: block; padding: 3px 6px; color: #374151; text-decoration: none; font-size: 12px; line-height: 1.4; border-radius: 4px; border-left: 2px solid transparent; }
        .doc-sidebar-toc a:hover { color: var(--theme-button-color); background: #f9fafb; }
        .doc-sidebar-toc a.active-toc { color: var(--theme-button-color); border-left-color: var(--theme-button-color); background: #fff7ed; font-weight: 500; }
        .doc-sidebar-toc::-webkit-scrollbar { width: 4px; }
        .doc-sidebar-toc::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 2px; }
        @media (max-width: 1000px) { .doc-sidebar-toc { display: none; } .doc-wrapper { max-width: 960px; } }

        .doc-toc { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 24px 28px; margin-bottom: 32px; }
        .doc-toc h3 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #374151; margin: 0 0 12px; }
        .doc-toc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 8px 24px; }
        .doc-toc a { display: block; padding: 4px 0; color: #2563eb; text-decoration: none; font-size: 13px; line-height: 1.5; }
        .doc-toc a:hover { color: var(--theme-button-color); text-decoration: underline; }
        .doc-toc-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; margin-top: 10px; margin-bottom: 4px; }
        .doc-toc-section:first-child { margin-top: 0; }

        .doc-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px 32px; margin-bottom: 24px; scroll-margin-top: 80px; }
        .doc-section h2 { font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 16px; padding-bottom: 10px; border-bottom: 2px solid var(--theme-button-color, #ff6543); }
        .doc-section h3 { font-size: 17px; font-weight: 600; color: #1f2937; margin: 28px 0 10px; }
        .doc-section h4 { font-size: 15px; font-weight: 600; color: #374151; margin: 20px 0 8px; }
        .doc-section p { font-size: 14px; color: #374151; line-height: 1.7; margin: 0 0 12px; }
        .doc-section ul, .doc-section ol { font-size: 14px; color: #374151; line-height: 1.7; margin: 0 0 14px; padding-left: 24px; }
        .doc-section li { margin-bottom: 6px; }

        ol.steps { counter-reset: step; list-style: none; padding-left: 0; }
        ol.steps > li { counter-increment: step; position: relative; padding-left: 44px; margin-bottom: 14px; min-height: 30px; }
        ol.steps > li::before { content: counter(step); position: absolute; left: 0; top: 0; width: 28px; height: 28px; background: var(--theme-button-color, #ff6543); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; }

        .callout { padding: 14px 18px; border-radius: 8px; margin: 14px 0; font-size: 13px; line-height: 1.6; }
        .callout-info { background: #eff6ff; border-left: 4px solid #3b82f6; }
        .callout-success { background: #f0fdf4; border-left: 4px solid #22c55e; }
        .callout-warning { background: #fffbeb; border-left: 4px solid #f59e0b; }
        .callout-danger { background: #fef2f2; border-left: 4px solid #ef4444; }

        table.doc-table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; font-size: 13px; }
        table.doc-table th { background: #1f2937; color: white; padding: 10px 14px; text-align: left; font-weight: 500; }
        table.doc-table td { padding: 9px 14px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.doc-table tr:nth-child(even) { background: #f9fafb; }
        table.doc-table code { background: #e5e7eb; padding: 1px 5px; border-radius: 3px; font-size: 12px; }

        .field-label { display: inline-block; background: #e0f2fe; color: #0369a1; padding: 1px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .btn-label { display: inline-block; background: #dcfce7; color: #15803d; padding: 1px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .menu-label { display: inline-block; background: #fef3c7; color: #92400e; padding: 1px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-label { display: inline-block; background: #f3e8ff; color: #7c3aed; padding: 1px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }

        .example-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin: 12px 0; font-size: 13px; }
        .example-box strong { color: #15803d; }

        .btn-doc { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; background: var(--theme-button-color, #ff6543); color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; transition: opacity 0.2s; text-decoration: none; }
        .btn-doc:hover { opacity: 0.9; color: white; }

        /* Screenshot figures */
        figure.doc-figure { margin: 18px 0; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fbfcfd; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        figure.doc-figure img { display: block; width: 100%; height: auto; border-bottom: 1px solid #eef0f2; }
        figure.doc-figure figcaption { font-size: 12px; color: #6b7280; padding: 8px 14px; line-height: 1.5; }
        figure.doc-figure figcaption strong { color: #374151; }
        figure.doc-figure.narrow { max-width: 640px; }

        /* "New in 2.6.2" badge */
        .new-badge { display: inline-block; vertical-align: middle; background: var(--theme-button-color, #ff6543); color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; padding: 2px 7px; border-radius: 10px; margin-left: 8px; }

        /* FAQ search + accordion */
        .faq-search-wrap { position: relative; margin: 6px 0 18px; }
        .faq-search { width: 100%; padding: 12px 16px 12px 40px; font-size: 14px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; font-family: inherit; }
        .faq-search:focus { outline: none; border-color: var(--theme-button-color, #ff6543); box-shadow: 0 0 0 3px rgba(255,101,67,0.12); }
        .faq-search-wrap::before { content: "\1F50D"; position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 14px; opacity: 0.5; }
        .faq-count { font-size: 12px; color: #9ca3af; margin: 0 0 14px; }
        details.faq-item { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 8px; background: #fff; overflow: hidden; }
        details.faq-item > summary { list-style: none; cursor: pointer; padding: 12px 16px; font-size: 14px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px; }
        details.faq-item > summary::-webkit-details-marker { display: none; }
        details.faq-item > summary::before { content: "+"; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; flex-shrink: 0; background: #f3f4f6; border-radius: 4px; color: var(--theme-button-color, #ff6543); font-weight: 700; }
        details.faq-item[open] > summary::before { content: "\2212"; }
        details.faq-item[open] > summary { border-bottom: 1px solid #eef0f2; }
        details.faq-item .faq-body { padding: 12px 16px 16px; font-size: 13px; color: #374151; line-height: 1.7; }
        details.faq-item .faq-body p { font-size: 13px; margin: 0 0 10px; }
        .faq-tag { display: inline-block; background: #eef2ff; color: #4338ca; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; padding: 1px 7px; border-radius: 4px; margin-left: auto; }
        .faq-no-results { display: none; padding: 14px; color: #9ca3af; font-size: 13px; font-style: italic; }

        .doc-nav-bottom { display: flex; justify-content: space-between; margin-top: 16px; }
        .doc-nav-bottom a { color: #2563eb; text-decoration: none; font-size: 13px; }
        .doc-nav-bottom a:hover { text-decoration: underline; }

        /* Print-only elements (hidden on screen) */
        .print-cover, .print-intro, .print-toc { display: none; }

        /* Print Styles */
        @media print {
            .top-bar, .sidebar, .doc-toc, .btn-doc, .screen-only, .doc-sidebar-toc { display: none !important; }
            .doc-wrapper { display: block !important; max-width: 100% !important; }
            .main-layout { display: block !important; }
            .main-content { padding: 0 !important; background: #fff !important; }
            .doc-section { border: none; box-shadow: none; padding: 16px 0; }
            .doc-section h2 { break-after: avoid; }
            body { font-size: 11px; color: #1e293b; }
            figure.doc-figure { break-inside: avoid; box-shadow: none; max-width: 100%; }
            figure.doc-figure img { max-width: 100%; }
            .faq-search-wrap, .faq-count, .faq-no-results { display: none !important; }
            details.faq-item, details.faq-item .faq-body { display: block !important; }
            details.faq-item > summary::before, details.faq-item[open] > summary::before { content: "" !important; }

            /* Page setup & footer */
            @page {
                margin: 2cm 2cm 2.8cm 2cm;
                @bottom-left { content: "Fair TPRM & GRC Platform — v2.6.2"; font-size: 8px; color: #999; font-family: 'Roboto', sans-serif; }
                @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size: 8px; color: #999; font-family: 'Roboto', sans-serif; }
            }

            /* === COVER PAGE === */
            .print-cover {
                display: flex !important;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-height: 90vh;
                text-align: center;
                page-break-after: always;
            }
            .print-cover .cover-logo { max-width: 300px; margin-bottom: 50px; }
            .print-cover .cover-rule { width: 120px; height: 3px; background: var(--theme-button-color, #ff6543); margin: 0 auto 30px; border-radius: 2px; }
            .print-cover h1 { font-size: 34px; font-weight: 700; color: #111; margin: 0 0 10px; letter-spacing: -0.5px; }
            .print-cover .cover-edition { font-size: 16px; color: #374151; font-weight: 400; margin-bottom: 6px; }
            .print-cover .cover-version { font-size: 13px; color: #6b7280; margin-bottom: 50px; }
            .print-cover .cover-rule-bottom { width: 60px; height: 2px; background: #d1d5db; margin: 0 auto 20px; }
            .print-cover .cover-meta { font-size: 11px; color: #9ca3af; line-height: 1.8; }
            .print-cover .cover-meta strong { color: #6b7280; }

            /* === INTRODUCTION PAGE === */
            .print-intro {
                display: block !important;
                page-break-after: always;
                padding: 20px 0;
            }
            .print-intro h2 { font-size: 22px; font-weight: 700; color: #111; margin: 0 0 18px; padding-bottom: 10px; border-bottom: 2px solid var(--theme-button-color, #ff6543); }
            .print-intro h3 { font-size: 15px; font-weight: 600; color: #1f2937; margin: 24px 0 8px; }
            .print-intro p { font-size: 12px; color: #374151; line-height: 1.7; margin: 0 0 10px; }
            .print-intro ul { font-size: 12px; color: #374151; line-height: 1.7; margin: 0 0 12px; padding-left: 20px; }
            .print-intro li { margin-bottom: 4px; }

            /* === TABLE OF CONTENTS (print overrides) === */
            .print-toc {
                border: none !important; border-radius: 0 !important;
                page-break-after: always;
                padding: 20px 0 !important;
            }
            .print-toc h2 {
                font-size: 22px !important; font-weight: 700; color: #111;
                margin: 0 0 24px !important; padding-bottom: 10px !important;
                border-bottom: 2px solid var(--theme-button-color, #ff6543) !important;
            }
            .toc-section-group { margin-bottom: 18px; }
            .toc-section-heading {
                font-size: 12px !important; font-weight: 700; color: #111;
                margin-bottom: 5px; padding: 3px 0;
                border-bottom: 1px solid #d1d5db;
            }
            a.toc-entry {
                padding: 2px 0 2px 16px !important;
                font-size: 11px !important; color: #1a56db !important;
            }
            a.toc-entry .toc-label { color: #1a56db !important; }
            a.toc-entry .toc-leader {
                display: block !important;
                flex: 1;
                border-bottom: 1px dotted #bbb;
                margin: 0 6px;
                min-width: 20px;
                position: relative;
                top: -3px;
            }
            a.toc-entry .toc-page {
                display: inline !important;
                white-space: nowrap;
                font-variant-numeric: tabular-nums;
                min-width: 20px;
                text-align: right;
                color: #1a56db;
            }

            /* Hide screen-only header in print */
            .doc-body > h1, .doc-body > .doc-subtitle, .doc-body > div:first-of-type { display: none !important; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <span style="margin-right:auto;font-size:14px;color:#333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
            <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
            <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
        </div>
    </div>

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

        <main class="main-content">
            <div class="doc-wrapper">
<?php
// Documentation body is factored per-language under lang/<code>/documentation.php
// so it can be pre-translated ONCE (offline) and served statically — no runtime
// AI. currentLanguage() comes from the user's preferred_language / default_language.
// Any language without a translated file falls back to the English body.
$docLang = currentLanguage();
$docBody = __DIR__ . '/lang/' . $docLang . '/documentation.php';
if (!is_file($docBody)) { $docBody = __DIR__ . '/lang/en/documentation.php'; }
include $docBody;
?>
            </div><!-- /.doc-wrapper -->
        </main>
    </div>
</div>

<script nonce="<?php echo $nonce; ?>">
(function() {
    // Build TOC on load so links are always clickable
    buildPrintTOC();

    document.getElementById('printBtn').addEventListener('click', function() {
        // Recalculate page numbers right before printing
        buildPrintTOC();
        setTimeout(function() { window.print(); }, 100);
    });

    function buildPrintTOC() {
        // Approximate page height in px for A4 at 96dpi with 2cm+2.8cm margins
        var PAGE_H = 1123 - 182; // ~941px usable
        var FRONT_PAGES = 3; // cover + intro + toc itself

        var tocBody = document.getElementById('tocBody');
        if (!tocBody) return;
        tocBody.innerHTML = '';

        // Define TOC structure: section groups with entries pointing to element IDs
        var structure = [
            { heading: '1. Getting Started', entries: [
                { label: '1.1 Platform Overview', id: 'overview' },
                { label: '1.2 Navigating the Sidebar', id: 'navigation' },
                { label: '1.3 User Roles & Permissions', id: 'roles' },
                { label: '1.4 Your First Login', id: 'first-login' },
                { label: '1.5 What’s New in Version 2.6.2', id: 'whats-new' },
                { label: '1.6 Changing Your Language', id: 'language' },
                { label: '1.7 Phone & VAT Question Types', id: 'question-types' }
            ]},
            { heading: '2. GRC Module — Quick Start', entries: [
                { label: '2.1 What is GRC?', id: 'grc-overview' },
                { label: '2.2 Getting Started with GRC', id: 'grc-getting-started' },
                { label: '2.3 Step 1: Create Your First Assessment', id: 'grc-step1' },
                { label: '2.4 Step 2: Answer Assessment Questions', id: 'grc-step2' },
                { label: '2.5 Step 3: Upload Evidence', id: 'grc-step3' },
                { label: '2.6 Step 4: View Your Compliance Scores', id: 'grc-step4' },
                { label: '2.7 Step 5: Generate a Compliance Report', id: 'grc-step5' }
            ]},
            { heading: '3. GRC Module — Features', entries: [
                { label: '3.1 CSF Maturity Score Dashboard', id: 'grc-fairscore' },
                { label: '3.2 Gap Analysis', id: 'grc-gaps' },
                { label: '3.3 Frameworks Page', id: 'grc-frameworks' },
                { label: '3.4 Internal Controls', id: 'grc-controls' },
                { label: '3.5 Framework Crosswalk', id: 'grc-crosswalk' },
                { label: '3.6 Evidence Library', id: 'grc-evidence' },
                { label: '3.7 Policy Management', id: 'grc-policies' },
                { label: '3.8 Audits & Findings', id: 'grc-audits' },
                { label: '3.9 Risk Register', id: 'grc-risks' },
                { label: '3.10 Continuous Monitors', id: 'grc-monitors' },
                { label: '3.11 Task Inbox', id: 'grc-tasks' },
                { label: '3.12 GRC Dashboard', id: 'grc-dashboard' }
            ]},
            { heading: '4. TPRM Module', entries: [
                { label: '4.1 What is TPRM?', id: 'tprm-overview' },
                { label: '4.2 Adding a New Vendor', id: 'tprm-add-vendor' },
                { label: '4.3 Vendor Lifecycle', id: 'tprm-lifecycle' },
                { label: '4.4 Vendor Assessments', id: 'tprm-assessments' },
                { label: '4.5 Assessment Forms & AI Auto-Fill', id: 'assessment-forms' },
                { label: '4.6 Vendor Action Plan', id: 'tprm-action-plan' },
                { label: '4.7 Security Risk Scorecard', id: 'tprm-srs' },
                { label: '4.8 FAIR Analysis', id: 'tprm-fair' },
                { label: '4.9 4th Party Risk', id: 'tprm-fourth-party' },
                { label: '4.10 Shadow SaaS Discovery', id: 'tprm-shadow-saas' }
            ]},
            { heading: '5. Onboarding & Procurement (New in 2.6.2)', entries: [
                { label: '5.1 Vendor Onboarding & Procurement Onboarding', id: 'onboarding-workflow' },
                { label: '5.2 Custom Onboarding Fields & Data', id: 'custom-onboarding' },
                { label: '5.3 AI Review for Vendors', id: 'ai-review' },
                { label: '5.4 Procurement Cyber Status', id: 'procurement-cyber-status' },
                { label: '5.5 Grip Shadow SaaS Integration', id: 'shadow-saas-grip' },
                { label: '5.6 Hero Shadow SaaS Integration', id: 'shadow-saas-hero' },
                { label: '5.7 Zscaler Blocking Integration', id: 'zscaler' },
                { label: '5.8 Breach / Cyber Alerts', id: 'breach-alerts' }
            ]},
            { heading: '6. Admin Portal', entries: [
                { label: '6.1 General Settings', id: 'admin-general' },
                { label: '6.2 Branding & Theme', id: 'admin-branding' },
                { label: '6.3 User Management', id: 'admin-users' },
                { label: '6.4 ACL Groups & Access Control', id: 'admin-acl-groups' },
                { label: '6.5 Assessment Template Builder', id: 'admin-templates' },
                { label: '6.6 Email Configuration', id: 'admin-email' },
                { label: '6.7 SAML / SSO', id: 'admin-saml' },
                { label: '6.8 AI Integration', id: 'admin-ai' },
                { label: '6.9 Large Database Backups', id: 'admin-backup' },
                { label: '6.10 Updating the Platform', id: 'admin-updates' }
            ]},
            { heading: '7. Help & Reference', entries: [
                { label: '7.1 Frequently Asked Questions', id: 'faq' },
                { label: '7.2 Troubleshooting', id: 'troubleshooting' },
                { label: '7.3 Glossary', id: 'glossary' }
            ]}
        ];

        structure.forEach(function(group) {
            var groupDiv = document.createElement('div');
            groupDiv.className = 'toc-section-group';

            var headingDiv = document.createElement('div');
            headingDiv.className = 'toc-section-heading';
            headingDiv.textContent = group.heading;
            groupDiv.appendChild(headingDiv);

            group.entries.forEach(function(entry) {
                var el = document.getElementById(entry.id);
                var pageNum = '—';
                if (el) {
                    var yPos = el.getBoundingClientRect().top + window.scrollY;
                    pageNum = Math.floor(yPos / PAGE_H) + FRONT_PAGES + 1;
                }

                var row = document.createElement('a');
                row.className = 'toc-entry';
                row.href = '#' + entry.id;
                row.innerHTML =
                    '<span class="toc-label">' + entry.label + '</span>' +
                    '<span class="toc-leader"></span>' +
                    '<span class="toc-page">' + pageNum + '</span>';
                groupDiv.appendChild(row);
            });

            tocBody.appendChild(groupDiv);
        });
    }

    // === Floating TOC + scroll-spy ===
    var sidebarToc = document.getElementById('sidebarToc');
    if (sidebarToc) {
        var tocLinks = sidebarToc.querySelectorAll('a[href^="#"]');
        var sectionIds = [];
        tocLinks.forEach(function(link) {
            var id = link.getAttribute('href').substring(1);
            if (id) sectionIds.push(id);
        });

        var mainContent = document.querySelector('.main-content');

        // Reliable in-page scroll. The doc has lazy-loaded figure images; when we
        // jump toward a section, images ABOVE the target load and grow the page,
        // which would otherwise leave the viewport parked at a now-stale offset
        // (e.g. clicking "FAQ" landed on Zscaler). We re-align to the element's live
        // position until the layout stops moving. scrollIntoView honours the
        // .doc-section scroll-margin-top and works whatever ancestor is the scroller.
        function scrollToTarget(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var ticks = 0, stable = 0, last = null;
            function align() { el.scrollIntoView({ block: 'start' }); }
            align();
            var timer = setInterval(function () {
                var top = Math.round(el.getBoundingClientRect().top);
                if (last !== null && Math.abs(top - last) < 2) {
                    stable++;
                } else {
                    stable = 0;
                    align();
                }
                last = top;
                if (stable >= 3 || ++ticks > 30) clearInterval(timer);
            }, 80);
        }

        // Delegate clicks for in-page anchors in the TOC and the doc body (What's New
        // table, cross-links, glossary). Scoped so the global app nav is untouched.
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
            if (!a) return;
            if (!a.closest('#sidebarToc') && !a.closest('.doc-body')) return;
            var href = a.getAttribute('href');
            if (!href || href === '#') return;
            var el = document.getElementById(href.substring(1));
            if (!el) return;
            e.preventDefault();
            scrollToTarget(href.substring(1));
            setTimeout(function () { history.replaceState(null, '', href); }, 120);
        });

        // Honour a #hash in the URL on load (same reflow-safe alignment).
        if (location.hash && location.hash.length > 1) {
            var hid = location.hash.substring(1);
            if (document.getElementById(hid)) {
                window.addEventListener('load', function () { scrollToTarget(hid); });
            }
        }

        // Scroll-spy: highlight active section. The window is the scroll container
        // (the .main-content column is as tall as its content), so listen on window.
        function updateActiveToc() {
            var offset = 120;
            var activeId = null;

            for (var i = sectionIds.length - 1; i >= 0; i--) {
                var el = document.getElementById(sectionIds[i]);
                if (el) {
                    if (el.getBoundingClientRect().top <= offset) {
                        activeId = sectionIds[i];
                        break;
                    }
                }
            }

            tocLinks.forEach(function(link) {
                var linkId = link.getAttribute('href').substring(1);
                if (linkId === activeId) {
                    link.classList.add('active-toc');
                } else {
                    link.classList.remove('active-toc');
                }
            });

            // Keep active link visible in TOC sidebar
            if (activeId) {
                var activeLink = sidebarToc.querySelector('a.active-toc');
                if (activeLink) {
                    var tocRect = sidebarToc.getBoundingClientRect();
                    var linkRect = activeLink.getBoundingClientRect();
                    if (linkRect.top < tocRect.top || linkRect.bottom > tocRect.bottom) {
                        activeLink.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                }
            }
        }

        window.addEventListener('scroll', updateActiveToc, { passive: true });
        if (mainContent) {
            mainContent.addEventListener('scroll', updateActiveToc, { passive: true });
        }
        updateActiveToc();
    }

    // === FAQ live search ===
    var faqSearch = document.getElementById('faqSearch');
    if (faqSearch) {
        var faqItems = Array.prototype.slice.call(document.querySelectorAll('#faqList .faq-item'));
        var faqCount = document.getElementById('faqCount');
        var faqNoResults = document.getElementById('faqNoResults');
        var totalFaq = faqItems.length;

        function updateFaqCount(shown) {
            if (!faqCount) return;
            if (!faqSearch.value.trim()) {
                faqCount.textContent = totalFaq + ' questions. Start typing to filter.';
            } else {
                faqCount.textContent = shown + ' of ' + totalFaq + ' question' + (totalFaq === 1 ? '' : 's') + ' match “' + faqSearch.value.trim() + '”.';
            }
        }

        function filterFaq() {
            var q = faqSearch.value.trim().toLowerCase();
            var shown = 0;
            faqItems.forEach(function(item) {
                var text = (item.textContent || '').toLowerCase();
                var match = q === '' || text.indexOf(q) !== -1;
                item.style.display = match ? '' : 'none';
                if (match) shown++;
                // Auto-open matches when searching, collapse when cleared
                if (q !== '' && match) { item.setAttribute('open', ''); }
                else if (q === '') { item.removeAttribute('open'); }
            });
            if (faqNoResults) faqNoResults.style.display = (shown === 0 && q !== '') ? 'block' : 'none';
            updateFaqCount(shown);
        }

        faqSearch.addEventListener('input', filterFaq);
        updateFaqCount(totalFaq);
    }
})();
</script>
</body>
</html>
