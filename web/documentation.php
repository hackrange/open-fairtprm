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
<html class="wide wow-animation" lang="en">
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

        .doc-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px 32px; margin-bottom: 24px; }
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

        /* "New in 2.6.1" badge */
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
                @bottom-left { content: "Fair TPRM & GRC Platform — v2.6.1"; font-size: 8px; color: #999; font-family: 'Roboto', sans-serif; }
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

                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>Platform Documentation</h1>
                    <div class="cover-edition">Governance, Risk &amp; Compliance &bull; Third Party Risk Management</div>
                    <div class="cover-version">Version 2.6.1</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>Date:</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>Classification:</strong> Internal Use Only<br>
                        <strong>Prepared by:</strong> GRC Administration Team
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>Introduction</h2>

                    <h3>Purpose</h3>
                    <p>This document provides comprehensive documentation for the Fair TPRM &amp; GRC Platform. It serves as both a user guide and a reference manual for all personnel involved in third-party risk management, governance, risk assessment, and compliance operations.</p>
                    <p>The intended audience includes GRC analysts, compliance officers, auditors, IT security staff, procurement teams, and system administrators. Whether you are conducting your first compliance assessment or managing an ongoing audit program, this guide provides the step-by-step instructions you need.</p>

                    <h3>Scope</h3>
                    <p>This documentation covers the following platform modules and capabilities:</p>
                    <ul>
                        <li><strong>GRC Module</strong> &mdash; Unified compliance assessments across multiple frameworks (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171), internal controls management, evidence collection, policy lifecycle management, audit management, risk register, continuous monitoring, and maturity scoring</li>
                        <li><strong>TPRM Module</strong> &mdash; Third-party vendor onboarding, risk tiering, security assessments, quantitative risk analysis (FAIR), external security scoring, fourth-party risk tracking, and shadow SaaS discovery</li>
                        <li><strong>Admin Portal</strong> &mdash; System configuration, user and group management, branding, email settings, SSO/SAML integration, and AI platform configuration</li>
                    </ul>

                    <h3>How to Use This Guide</h3>
                    <p>This guide is organized into four parts. <strong>Part 1 (Getting Started)</strong> covers platform navigation, user roles, and your first login. <strong>Part 2 (GRC Module)</strong> provides a detailed walkthrough of the compliance assessment process, starting with creating your first assessment and progressing through evidence collection, scoring, and report generation. <strong>Part 3 (TPRM Module)</strong> covers vendor risk management. <strong>Part 4 (Admin Portal)</strong> covers system administration.</p>
                    <p>If you are new to the platform, start with the <em>Getting Started</em> section and then follow the five-step GRC Quick Start Guide. Each step includes exact, click-by-click instructions.</p>

                    <h3>Document Conventions</h3>
                    <p>Throughout this document, the following conventions are used:</p>
                    <ul>
                        <li><strong>Bold text</strong> indicates important concepts or emphasis</li>
                        <li><code>Code formatting</code> indicates values you type or system-generated references</li>
                        <li>Numbered step lists indicate sequential procedures to follow in order</li>
                        <li>Callout boxes provide tips, warnings, and important context</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>Table of Contents</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>Platform Documentation</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.1 &mdash; Last updated: <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">Download PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>Platform Overview</h2>
    <p>This platform provides two integrated modules for managing your organization's security posture:</p>
    <ul>
        <li><strong>TPRM (Third Party Risk Management)</strong> &mdash; Track, assess, and score your vendors and suppliers. Understand the security risk each vendor poses to your organization.</li>
        <li><strong>GRC (Governance, Risk &amp; Compliance)</strong> &mdash; Manage compliance frameworks (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, and more), answer a single unified questionnaire that covers all frameworks simultaneously, track internal controls, upload evidence, manage policies, and run audits.</li>
    </ul>
    <p>Administrators also have access to the <strong>Admin Portal</strong> for system configuration, user management, integrations, and maintenance.</p>

    <div class="callout callout-success">
        <strong>Key Concept &mdash; One Assessment, Many Frameworks:</strong> The GRC module uses a <em>unified assessment questionnaire</em> with 146 questions across 14 security domains. When you answer these questions once, the platform automatically calculates your compliance percentage against every supported framework (SOC 2, ISO 27001, PCI DSS, etc.) &mdash; no duplicate work required.
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>Navigating the Sidebar</h2>
    <p>The left sidebar is your primary navigation tool. It is organized into collapsible modules and sections:</p>
    <ol class="steps">
        <li>At the top of the sidebar you see your company logo and branding text.</li>
        <li>Below that are two collapsible module headers: <span class="menu-label">TPRM Module</span> and <span class="menu-label">GRC Module</span>. Click either header to expand or collapse it. Your browser remembers which modules are open.</li>
        <li>Inside each module, there are collapsible <strong>sections</strong> (e.g., "Compliance", "Evidence &amp; Monitoring", "Assessment &amp; Audit"). Click a section title to expand it and see the navigation links inside.</li>
        <li>At the bottom of the sidebar you will find utility links: <span class="menu-label">Dashboard</span>, <span class="menu-label">Profile</span>, <span class="menu-label">Documentation</span> (this page), and <span class="menu-label">Administration</span> (admin only).</li>
    </ol>

    <h3>GRC Module Sidebar Structure</h3>
    <p>When you expand <span class="menu-label">GRC Module</span>, you will see these sections:</p>
    <table class="doc-table">
        <tr><th>Section</th><th>Pages Inside</th><th>What It Contains</th></tr>
        <tr><td><strong>Compliance</strong></td><td>GRC Dashboard, Frameworks, Internal Controls, Framework Crosswalk</td><td>Your compliance posture overview, framework management, control library, and cross-framework mapping</td></tr>
        <tr><td><strong>Evidence &amp; Monitoring</strong></td><td>Evidence Library, Continuous Monitors</td><td>Upload and manage compliance evidence; configure automated compliance checks</td></tr>
        <tr><td><strong>Policy Management</strong></td><td>Policies</td><td>Create, version, approve, and publish organizational policies</td></tr>
        <tr><td><strong>Assessment &amp; Audit</strong></td><td>CSF Maturity Score, Assessment Questionnaire, Task Inbox, Audits, Findings, Risk Register</td><td>The unified assessment questionnaire, maturity dashboards, audits, and risk tracking</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>User Roles &amp; Permissions</h2>
    <p>Users are assigned to one or more <strong>ACL Groups</strong> that determine what they can see and do. An administrator assigns groups via <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> &rarr; <span class="btn-label">Groups</span> button.</p>
    <table class="doc-table">
        <tr><th>Group</th><th>What You Can Do</th></tr>
        <tr><td><strong>Administrator</strong></td><td>Full access to everything &mdash; all modules, admin settings, user management, and system configuration</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>Full access to the TPRM module &mdash; create/edit/delete vendors, run assessments, FAIR analysis, scoring</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>Full access to the GRC module &mdash; manage frameworks, run assessments, upload evidence, manage policies, run audits, manage risks</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>Limited GRC access &mdash; complete assigned tasks, provide evidence, answer assigned assessment questions</td></tr>
        <tr><td><strong>Auditor</strong></td><td><strong>Read-only access</strong> to both TPRM and GRC modules &mdash; can view everything, download evidence, and generate reports, but cannot create, edit, or delete</td></tr>
        <tr><td><strong>Procurement</strong></td><td>Create and manage vendor onboarding requests, upload vendor documents</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>View their own vendor requests and respond to tasks assigned to them</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>To see the GRC module in the sidebar:</strong> You must be in the <strong>Administrator</strong>, <strong>Cyber GRC</strong>, or <strong>Auditor</strong> group. If you do not see the GRC Module in the sidebar, ask your administrator to add you to one of these groups.
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>Your First Login</h2>
    <ol class="steps">
        <li>Open your web browser and navigate to your platform URL (e.g., <code>https://tprm.yourcompany.com</code>).</li>
        <li>Enter your <span class="field-label">Username</span> and <span class="field-label">Password</span> provided by your administrator.</li>
        <li>If two-factor authentication (TOTP) is enabled for your account, open your authenticator app (Google Authenticator, Microsoft Authenticator, etc.) and enter the 6-digit code when prompted.</li>
        <li>You will land on the <strong>Dashboard</strong>. The top bar shows "Welcome, [Your Name]" with links to Admin (if you are an administrator), Profile, and Logout.</li>
        <li>Look at the left sidebar. If you are in the <strong>Cyber GRC</strong> or <strong>Administrator</strong> group, you will see <span class="menu-label">GRC Module</span> in the sidebar. Click it to expand the GRC navigation.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>The Dashboard.</strong> After signing in you land here. The top bar (upper right) has <strong>Admin</strong>, <strong>Profile</strong>, and <strong>Logout</strong>. The left sidebar is your main menu.</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.1
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>What's New in Version 2.6.1</h2>
    <p>Version 2.6.1 adds several features focused on <strong>vendor onboarding, procurement collaboration, multi-language support, and shadow-SaaS discovery</strong>. If you have used an earlier version, here is what is new. Each item links to its full walkthrough later in this guide.</p>
    <table class="doc-table">
        <tr><th>New Feature</th><th>What It Does</th><th>Who It's For</th></tr>
        <tr><td><strong><a href="#language">Language settings</a></strong></td><td>Use the platform in 8 languages. Each person picks their own language; admins choose which languages are available.</td><td>Everyone</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Procurement Onboarding &amp; Vendor ID</a></strong></td><td>A vendor must be onboarded through procurement and have a valid Vendor ID (VID) before it can be submitted for cyber review.</td><td>Procurement, Stakeholders</td></tr>
        <tr><td><strong><a href="#ai-review">AI Review for vendors</a></strong></td><td>A dedicated review status for vendors whose services use AI, plus a "Force AI Review" action.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Procurement Cyber Status</a></strong></td><td>A live page showing vendors in review, with a running history of updates the cyber team shares with procurement, plus a weekly email digest.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Grip Security integration</a></strong></td><td>Automatically discover SaaS apps used across your organization and pull them into the Shadow SaaS list.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#zscaler">Zscaler blocking</a></strong></td><td>Block an unsanctioned app's web domain directly in Zscaler with one click.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-updates">In-app upgrades</a></strong></td><td>Check your registry for a newer version and upgrade from inside the Admin Portal.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#question-types">Phone &amp; VAT question types</a></strong></td><td>New assessment/onboarding field types: a phone number with a country-code &amp; flag picker (auto-formatted), and an EU VAT number with double entry and free live validation against the official EU VIES service.</td><td>Everyone</td></tr>
        <tr><td><strong><a href="#question-types">Vendor data &amp; search improvements</a></strong></td><td>Store a VAT number on each vendor (shown on the vendor page with an &quot;Add VAT&quot; shortcut), find vendors by VAT number in the quick search, and a clearer Procurement-Onboarding scoring banner.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">Large database backups</a></strong></td><td>Backup and restore now support multi-gigabyte databases and very large records without timing out.</td><td>Admins</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>How do I know which version I'm on?</strong> Administrators can go to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> to see the installed version. This guide describes <strong>v2.6.1</strong>. See <a href="#admin-updates">Updating the Platform</a>.
    </div>
</div>

<div class="doc-section" id="language">
    <h2>Changing Your Language <span class="new-badge">New in 2.6.1</span></h2>
    <p>The platform interface can be displayed in <strong>8 languages</strong>. Every person chooses their own language &mdash; changing it only affects <em>your</em> screen, not anyone else's. Your choice is remembered every time you log in.</p>

    <h3>Languages available</h3>
    <table class="doc-table">
        <tr><th>Language</th><th>Shown in the menu as</th></tr>
        <tr><td>English</td><td>English</td></tr>
        <tr><td>Spanish</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italian</td><td>Italiano</td></tr>
        <tr><td>Ukrainian</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Chinese (Simplified)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>French</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Portuguese</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>Only the languages your administrator has turned on will appear in your list.</strong> English is always available and cannot be turned off.</p>

    <h3>How to change your language (step by step)</h3>
    <ol class="steps">
        <li>Click <span class="menu-label">Profile</span> in the top-right corner of any page.</li>
        <li>On the Profile page, scroll down to the <span class="field-label">Language Preference</span> card.</li>
        <li>Click the <span class="field-label">Language</span> drop-down and choose your language. To go back to the language your administrator set for everyone, choose <strong>System default</strong>.</li>
        <li>Click <span class="btn-label">Update Language</span>. The page reloads and the menus, buttons, and labels now appear in your chosen language.</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profile &rarr; Language Preference.</strong> Pick a language and click <strong>Update Language</strong>. Choosing <em>System default</em> removes your personal preference.</figcaption>
    </figure>

    <h3>For administrators: choosing which languages are available</h3>
    <p>Administrators decide the <strong>default language</strong> (used for brand-new users and for the sign-in page before anyone logs in) and which languages everyone is allowed to pick.</p>
    <ol class="steps">
        <li>Go to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">General</span>.</li>
        <li>Find the <span class="field-label">Default Language</span> drop-down and choose the organization-wide default.</li>
        <li>Under <span class="field-label">Enabled Languages</span>, tick the languages you want to make available. (English is always ticked and cannot be disabled.)</li>
        <li>Click <span class="btn-label">Save Configuration</span>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; General.</strong> Set the <strong>Default Language</strong> and tick the <strong>Enabled Languages</strong> users may choose from.</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>Good to know:</strong> Most of the interface (menus, buttons, labels, and form fields) is translated. Vendor assessment <em>questions</em> can also be translated automatically when an AI provider is configured (see <a href="#admin-ai">AI Integration</a>), but the stored answer values stay in English so that scoring and reports remain consistent across languages.
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Phone &amp; VAT Question Types <span class="new-badge">New in 2.6.1</span></h2>
    <p>The <strong>Template Builder</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Assessment Templates</span>) gains two new question types that capture contact and tax details in a clean, consistent format. They can be used on any assessment or onboarding template, and like other questions they can be mapped to a vendor field so the answer flows onto the vendor record.</p>

    <h3>Phone</h3>
    <p>The <strong>Phone</strong> type shows a country selector with a flag and dialing code next to the number box. The United States is listed first; every other country follows in alphabetical order. Whatever format the person types &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> or <code>3144445544</code> &mdash; the number is stored in one uniform international format (for example, picking the US flag and typing <code>3144445544</code> stores <code>+13144445544</code>). The default Vendor Onboarding Request form now uses this type for the primary contact's phone number, and the assessment <strong>attestation</strong> phone field uses it too.</p>

    <h3>VAT (EU VAT number)</h3>
    <p>The <strong>VAT</strong> type is for European VAT numbers. To guard against typos it must be <strong>entered twice</strong>, and the two entries must match before it is saved. The number is stored in a consistent form (uppercase, no spaces or punctuation &mdash; for example <code>DE123456789</code>).</p>
    <ul>
        <li><strong>Free live validation.</strong> When you finish typing, the platform checks the number against the official <strong>EU VIES</strong> service (the European Commission's VAT Information Exchange System). VIES is free, needs no account, and reflects each member state's live registry.</li>
        <li><strong>Advisory, never blocking.</strong> If VIES cannot confirm the number, it is still saved &mdash; a notice simply asks you to double-check it. If VIES is momentarily slow or a country's registry is temporarily unavailable, the number is saved and you are told to verify it later.</li>
        <li><strong>Details on demand.</strong> When VIES confirms a number, an information (&#9432;) button appears next to it. Clicking it opens a panel showing the registered company name and address returned by VIES.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Mapping VAT to the vendor record.</strong> A dedicated <code>vat_number</code> field is available, so a VAT question mapped to it stores the value on the vendor. When you choose the VAT question type in the Template Builder, this mapping is selected for you automatically.
    </div>

    <h3>VAT on the vendor page</h3>
    <p>The vendor's VAT number is shown in the <strong>Vendor Information</strong> card on the vendor onboarding page. If no VAT is on file, an <strong>&ldquo;+ Add VAT&rdquo;</strong> button appears that jumps straight into edit mode with the VAT field focused.</p>

    <h3>Finding vendors by VAT number</h3>
    <p>The <strong>quick search</strong> box in the top-right of the platform now also matches on VAT number, alongside vendor name, domain, and stakeholder. Direct matches on a vendor's own name, domain, or VAT number are always shown first.</p>

    <h2>Procurement Onboarding scoring banner <span class="new-badge">New in 2.6.1</span></h2>
    <p>When a vendor's <strong>Procurement Onboarding</strong> status is set to <strong>No</strong>, a banner now makes clear that <em>automated vendor scoring is disabled until the vendor completes Procurement Onboarding</em>. It appears both on the vendor onboarding page and beneath the matching assessment question, and updates immediately as the answer changes.</p>

    <h2>Submitting an assessment: required fields checked first <span class="new-badge">New in 2.6.1</span></h2>
    <p>When a vendor clicks <strong>Submit</strong> on an assessment, the platform now checks that every required question is answered <em>before</em> asking for the submitter's attestation details. Previously a missing answer was only reported after the attestation was filled in, forcing it to be re-entered.</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>Large Database Backups &amp; Restores <span class="new-badge">New in 2.6.1</span></h2>
    <p>Backup and restore (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Backup</span>) now handle <strong>multi-gigabyte databases</strong> and individual records approaching <strong>1&nbsp;GB</strong> without the operation being cut short by a timeout or running out of memory. Behind the scenes the database packet limit, network timeouts, upload size, and request time limits were all raised to accommodate very large data.</p>
    <div class="callout callout-info">
        <strong>For very large databases:</strong> A backup or restore of a multi-gigabyte file can take a while &mdash; leave the page open until it finishes. Extremely large datasets (tens of gigabytes) are best restored from the server command line.
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>GRC Module: What is Governance, Risk &amp; Compliance?</h2>
    <p><strong>GRC</strong> stands for <strong>Governance, Risk, and Compliance</strong>. It is the practice of ensuring your organization meets regulatory requirements, follows security best practices, manages risks, and can prove compliance to auditors and regulators.</p>

    <p>The GRC module helps you:</p>
    <ul>
        <li><strong>Assess your security maturity</strong> using a single unified questionnaire that maps to multiple compliance frameworks simultaneously</li>
        <li><strong>Track compliance</strong> against SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, and more</li>
        <li><strong>Manage internal controls</strong> &mdash; document the security measures your organization has implemented</li>
        <li><strong>Collect and store evidence</strong> &mdash; upload screenshots, configuration exports, policy documents, and certificates that prove compliance</li>
        <li><strong>Manage policies</strong> &mdash; create, version, approve, and publish organizational security policies</li>
        <li><strong>Run audits</strong> &mdash; plan audits, record findings, assign remediation, and track closure</li>
        <li><strong>Track risks</strong> &mdash; maintain a risk register with likelihood/impact scoring and treatment plans</li>
        <li><strong>Monitor continuously</strong> &mdash; set up automated checks that verify compliance controls on a schedule</li>
    </ul>

    <div class="callout callout-warning">
        <strong>Important Concept &mdash; Unified Questions:</strong> The platform contains <strong>146 unified security questions</strong> organized into <strong>14 security domains</strong> (Governance, Identity &amp; Access Management, Data Security, Network Security, etc.). Each question is pre-mapped to specific requirements in multiple compliance frameworks. When you answer a question once, the answer automatically applies to every framework that question maps to. This eliminates the need to answer the same question separately for SOC 2, ISO 27001, and PCI DSS.
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>Getting Started with GRC &mdash; Quick Start Guide</h2>
    <p>If you are brand new to the GRC module, follow these steps in order. By the end, you will have a completed compliance assessment with scores across all frameworks.</p>

    <div class="callout callout-info">
        <strong>Prerequisites:</strong><br>
        &bull; You must be logged in as a user in the <strong>Administrator</strong> or <strong>Cyber GRC</strong> group<br>
        &bull; You must be able to see <span class="menu-label">GRC Module</span> in the left sidebar<br>
        &bull; If you do not see it, ask your administrator to assign you to the Cyber GRC group (Admin &rarr; Users &rarr; click Groups button next to your name &rarr; check "Cyber GRC" &rarr; Save)
    </div>

    <p>The recommended workflow is:</p>
    <ol>
        <li><strong>Create an Assessment</strong> &mdash; This defines the scope and purpose of your compliance review</li>
        <li><strong>Answer the Questions</strong> &mdash; Work through the 146 unified questions, rating your maturity level for each</li>
        <li><strong>Upload Evidence</strong> &mdash; Attach documents, screenshots, and files that prove your answers</li>
        <li><strong>View Your Scores</strong> &mdash; Check your compliance percentages on the Frameworks page</li>
        <li><strong>Generate Reports</strong> &mdash; Create detailed per-framework compliance reports for auditors</li>
    </ol>
    <p>Each step is explained in detail below.</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>Step 1: Create Your First Assessment</h2>
    <p>An <strong>Assessment</strong> is a compliance review of your organization. It represents a point-in-time evaluation where you answer security questions, record maturity ratings, and collect evidence. Think of it as a "compliance snapshot."</p>

    <h3>How to Create a New Assessment</h3>
    <ol class="steps">
        <li>In the left sidebar, click <span class="menu-label">GRC Module</span> to expand it.</li>
        <li>Click the <span class="menu-label">Assessment &amp; Audit</span> section to expand it.</li>
        <li>Click <span class="menu-label">Assessment Questionnaire</span>. This opens the main assessment page.</li>
        <li>At the top of the page, you will see an <span class="btn-label">+ New Assessment</span> button. Click it.</li>
        <li>A form will appear. Fill in the following fields:
            <ul>
                <li><span class="field-label">Title</span> &mdash; Give your assessment a descriptive name. Example: <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Assessment Type</span> &mdash; Select the type of assessment:
                    <ul>
                        <li><strong>Initial</strong> &mdash; Your first-ever assessment (recommended for new users)</li>
                        <li><strong>Periodic</strong> &mdash; A regular recurring assessment (e.g., annual review)</li>
                        <li><strong>Targeted</strong> &mdash; A focused assessment on a specific area</li>
                        <li><strong>Pre-Audit</strong> &mdash; Preparation before a formal audit</li>
                        <li><strong>Certification</strong> &mdash; Assessment for certification purposes (e.g., SOC 2 Type II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Scope</span> &mdash; Select or describe the organizational scope. This defines what part of your organization is being assessed (e.g., "All IT systems" or "Cloud Infrastructure").</li>
                <li><span class="field-label">Lead Auditor</span> &mdash; Select the person leading this assessment. The dropdown only shows users in the Administrator or Cyber GRC groups.</li>
                <li><span class="field-label">Planned Start Date</span> &mdash; When you plan to begin the assessment.</li>
                <li><span class="field-label">Planned End Date</span> &mdash; Your target completion date.</li>
            </ul>
        </li>
        <li>Click <span class="btn-label">Create Assessment</span>.</li>
        <li>Your new assessment is created in <span class="status-label">Draft</span> status. You can now begin answering questions.</li>
    </ol>

    <div class="example-box">
        <strong>Example:</strong> You are conducting your organization's first annual security review.<br><br>
        &bull; Title: <code>2026 Annual Security Assessment</code><br>
        &bull; Type: <code>Initial</code><br>
        &bull; Scope: <code>All Corporate IT Systems</code><br>
        &bull; Lead Auditor: <code>Jane Smith</code><br>
        &bull; Start Date: <code>March 1, 2026</code><br>
        &bull; End Date: <code>April 30, 2026</code>
    </div>

    <h3>Assessment Statuses</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Meaning</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>Assessment has been created but work has not started yet. Questions can be answered.</td></tr>
        <tr><td><span class="status-label">In Progress</span></td><td>Active assessment &mdash; team members are answering questions and uploading evidence.</td></tr>
        <tr><td><span class="status-label">Under Review</span></td><td>All questions answered &mdash; a lead auditor or validator is reviewing responses.</td></tr>
        <tr><td><span class="status-label">Completed</span></td><td>Assessment is finished and finalized. Responses are locked.</td></tr>
        <tr><td><span class="status-label">Archived</span></td><td>Historical assessment kept for records. No longer active.</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Assessment Questionnaire.</strong> Every assessment is listed with its reference, title, type, status, lead auditor, current CSF score and compliance %, and planned date. Use <span class="btn-label">+ New Assessment</span> to start one, or <span class="btn-label">Open</span> to continue answering an existing one. The status tabs across the top filter the list.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>Step 2: Answer Assessment Questions</h2>
    <p>Once you have created an assessment, you need to answer the 146 unified security questions. Each question belongs to one of 14 security domains.</p>

    <h3>The 14 Security Domains</h3>
    <table class="doc-table">
        <tr><th>Code</th><th>Domain Name</th><th>Questions</th><th>What It Covers</th></tr>
        <tr><td><code>GOV</code></td><td>Governance &amp; Leadership</td><td>12</td><td>Security program leadership, strategy, budget, board reporting</td></tr>
        <tr><td><code>IAM</code></td><td>Identity &amp; Access Management</td><td>14</td><td>User accounts, authentication, access controls, privileged access</td></tr>
        <tr><td><code>DSP</code></td><td>Data Security &amp; Privacy</td><td>12</td><td>Data classification, encryption, privacy, data loss prevention</td></tr>
        <tr><td><code>EPS</code></td><td>Endpoint &amp; Platform Security</td><td>10</td><td>Laptops, servers, mobile devices, patching, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Network Security</td><td>11</td><td>Firewalls, segmentation, VPN, DNS security, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Application Security</td><td>10</td><td>Secure development, code reviews, API security, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Security Operations</td><td>12</td><td>SIEM, logging, monitoring, vulnerability scanning, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Incident Management</td><td>10</td><td>Incident response plans, tabletop exercises, breach notification</td></tr>
        <tr><td><code>SCM</code></td><td>Supply Chain &amp; Third Party</td><td>10</td><td>Vendor management, supply chain risk, contracts</td></tr>
        <tr><td><code>PHY</code></td><td>Physical &amp; Environmental</td><td>8</td><td>Data centers, badge access, CCTV, environmental controls</td></tr>
        <tr><td><code>HRS</code></td><td>Human Resources Security</td><td>10</td><td>Background checks, security training, termination procedures</td></tr>
        <tr><td><code>BCP</code></td><td>Business Continuity</td><td>10</td><td>Backup, disaster recovery, BCP testing, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptography &amp; Key Management</td><td>8</td><td>Encryption standards, key rotation, certificate management</td></tr>
        <tr><td><code>CMP</code></td><td>Compliance &amp; Assurance</td><td>9</td><td>Regulatory compliance, internal audit, external audit readiness</td></tr>
    </table>

    <h3>How to Answer Questions</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Assessment Questionnaire</span>.</li>
        <li>If you have multiple assessments, select the correct one from the dropdown at the top of the page.</li>
        <li>You will see the 14 security domains listed. Click on a domain name (e.g., <strong>GOV - Governance &amp; Leadership</strong>) to expand it and see its questions.</li>
        <li>For each question, you need to provide two pieces of information:
            <ul>
                <li><span class="field-label">Maturity Rating</span> (1-4) &mdash; How mature is your organization's implementation of this control?
                    <ul>
                        <li><strong>1 &mdash; Initial/Ad Hoc:</strong> No formal process. Done inconsistently or not at all.</li>
                        <li><strong>2 &mdash; Developing:</strong> Some processes exist but are not consistently followed. Partially documented.</li>
                        <li><strong>3 &mdash; Defined:</strong> Formal, documented processes are in place and consistently followed.</li>
                        <li><strong>4 &mdash; Managed/Optimized:</strong> Processes are measured, monitored, and continuously improved.</li>
                    </ul>
                </li>
                <li><span class="field-label">Conformity Status</span> &mdash; Your compliance status for this question:
                    <ul>
                        <li><strong>Conforming</strong> &mdash; Fully implemented and meets the requirement</li>
                        <li><strong>Partial</strong> &mdash; Partially implemented; some gaps remain</li>
                        <li><strong>Non-Conforming</strong> &mdash; Not implemented or does not meet the requirement</li>
                        <li><strong>Not Applicable</strong> &mdash; This question does not apply to your organization</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>Optionally, add <span class="field-label">Notes</span> to explain your answer. This is highly recommended &mdash; auditors will want to see your reasoning.</li>
        <li>Your responses <strong>auto-save</strong> as you work. You do not need to click a save button.</li>
        <li>Continue answering questions across all 14 domains. You do not need to complete everything in one session &mdash; come back anytime to resume.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Tip &mdash; Maturity drives Conformity:</strong> When you set a maturity rating, the system can automatically derive the conformity status: Maturity 3-4 = Conforming, Maturity 2 = Partial, Maturity 1 = Non-Conforming. You can override this if needed.
    </div>

    <div class="callout callout-warning">
        <strong>Important:</strong> Each question you answer maps to requirements across multiple frameworks. For example, answering a question about "Multi-Factor Authentication" (in the IAM domain) simultaneously updates your compliance scores for SOC 2, ISO 27001, PCI DSS, NIST CSF, and CMMC. You never need to answer the same concept twice.
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>Answering the questionnaire.</strong> The header tracks <em>Progress</em>, live <em>CSF Maturity</em>, and <em>Compliance</em> as you work. The domain tabs (GOV, IAM, DSP, &hellip;) each show that domain's current score; click one to jump to its questions. For each question you set a <strong>Maturity</strong> rating (1&ndash;4 or N/A) and a <strong>Conformity</strong> status &mdash; answers auto-save. Use <span class="btn-label">Show Unanswered Questions</span> to find what is left.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>Step 3: Upload Evidence</h2>
    <p>Evidence proves that your answers are accurate. Auditors will expect to see evidence for each compliance claim. Evidence can include screenshots, configuration exports, policy documents, audit logs, certificates, and more.</p>

    <h3>How to Upload Evidence During an Assessment</h3>
    <ol class="steps">
        <li>While answering a question in the <span class="menu-label">Assessment Questionnaire</span>, look for the <strong>Evidence</strong> section below the question response area.</li>
        <li>Click <span class="btn-label">Upload Evidence</span> or the attachment icon.</li>
        <li>Select a file from your computer. Supported types include PDF, images (PNG, JPG), Word documents, Excel spreadsheets, and text files.</li>
        <li>Give the evidence a descriptive <span class="field-label">Title</span> (e.g., "MFA Configuration Screenshot - Okta Admin Console").</li>
        <li>The evidence is automatically linked to the current assessment question.</li>
        <li>You can upload multiple evidence files per question.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Security:</strong> All uploaded evidence files are encrypted (AES-256-CBC) before being stored in the database. When you download evidence, it is decrypted on-the-fly. This ensures sensitive compliance documents are protected at rest.
    </div>

    <h3>Evidence Library</h3>
    <p>You can also manage evidence separately via <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span>. This page shows all evidence across all assessments and controls, with filtering by type, status, and expiry date.</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>Step 4: View Your Compliance Scores</h2>
    <p>As you answer questions, the platform calculates your compliance percentage for each framework in real-time.</p>

    <h3>Viewing Scores on the Frameworks Page</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>At the top of the page, you will see an <span class="field-label">Assessment</span> dropdown. Select the assessment you want to view scores for. By default, the most recent assessment is selected.</li>
        <li>Below the dropdown, you will see framework cards &mdash; one for each compliance framework that has questions mapped to it. Each card shows:
            <ul>
                <li>A <strong>donut chart</strong> showing the overall compliance percentage (e.g., 75%)</li>
                <li>The <strong>framework code and name</strong> (e.g., "SOC2 &mdash; SOC 2 Type II")</li>
                <li><strong>Average Maturity</strong> score (if maturity data exists, displayed as e.g., "3.50 / 4.00")</li>
                <li>Metric counts: <strong>Conforming</strong>, <strong>Partial</strong>, <strong>Non-Conforming</strong>, and <strong>Total Mapped</strong></li>
            </ul>
        </li>
        <li>Click on any framework card to open the detailed <strong>Compliance Report</strong> for that framework.</li>
    </ol>

    <h3>Compliance Percentage Calculation</h3>
    <p>The compliance percentage is calculated as:</p>
    <div class="example-box">
        <strong>Formula:</strong> <code>(Conforming + Partial &times; 0.5) &divide; Applicable Requirements &times; 100</code><br><br>
        &bull; <strong>Conforming</strong> requirements count as 100% complete<br>
        &bull; <strong>Partial</strong> requirements count as 50% complete<br>
        &bull; <strong>Not Applicable</strong> requirements are excluded from the calculation<br>
        &bull; <strong>Non-Conforming</strong> and <strong>Not Assessed</strong> requirements count as 0%
    </div>

    <h3>Currently Supported Frameworks</h3>
    <table class="doc-table">
        <tr><th>Framework</th><th>Version</th><th>Mapped Questions</th></tr>
        <tr><td>NIST Cybersecurity Framework (CSF)</td><td>2.0</td><td>146</td></tr>
        <tr><td>ISO/IEC 27001</td><td>2022</td><td>146</td></tr>
        <tr><td>SOC 2 Type II</td><td>2017</td><td>146</td></tr>
        <tr><td>PCI DSS</td><td>4.0</td><td>132</td></tr>
        <tr><td>CMMC / NIST 800-171</td><td>v2.0</td><td>97</td></tr>
        <tr><td>CIS Controls</td><td>v8</td><td>95</td></tr>
        <tr><td>NIST SP 800-171</td><td>Rev 2</td><td>90</td></tr>
        <tr><td>HIPAA Security Rule</td><td>2013</td><td>61</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-step5">
    <h2>Step 5: Generate a Framework Compliance Report</h2>
    <p>Once you have answered questions, you can generate a detailed compliance report for any framework. This report is suitable for sharing with auditors, regulators, or management.</p>

    <h3>How to Generate a Report</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>Select your assessment from the <span class="field-label">Assessment</span> dropdown at the top.</li>
        <li>Click on the framework card you want to report on (e.g., "SOC2 &mdash; SOC 2 Type II").</li>
        <li>The <strong>Framework Compliance Report</strong> page opens, showing:
            <ul>
                <li><strong>Report Header</strong> &mdash; Framework name, assessment title, type, status, scope, lead auditor, dates, and overall compliance percentage</li>
                <li><strong>Summary Statistics</strong> &mdash; Clickable cards showing Total Requirements, Conforming, Partial, Non-Conforming, Not Assessed, and N/A counts</li>
                <li><strong>Requirement Cards</strong> &mdash; One card per framework requirement, showing the requirement reference, title, status badge, and all mapped questions with their responses</li>
            </ul>
        </li>
        <li>To <strong>filter requirements by status</strong>, click any of the summary statistic cards at the top. For example, click <strong>Non-Conforming</strong> to show only requirements that are non-conforming. Click it again (or click "Total Requirements") to show all.</li>
        <li>To <strong>print the report</strong>, click the <span class="btn-label">Print Report</span> button at the top. Your browser's print dialog will open. You can print to paper or select "Save as PDF" to create a PDF file.</li>
    </ol>

    <h3>What Each Requirement Card Shows</h3>
    <p>For each requirement in the report, you will see:</p>
    <ul>
        <li><strong>Requirement Reference</strong> &mdash; The official reference number (e.g., "CC6.1" for SOC 2)</li>
        <li><strong>Requirement Title</strong> &mdash; What the requirement says</li>
        <li><strong>Status Badge</strong> &mdash; Color-coded: green (Conforming), amber (Partial), red (Non-Conforming), gray (Not Assessed / N/A)</li>
        <li><strong>Mapped Questions</strong> &mdash; Each question that maps to this requirement, showing:
            <ul>
                <li>Question reference and text</li>
                <li>Maturity rating (1-4) with a visual bar</li>
                <li>Conformity status</li>
                <li>Validation status (Pending, Validated, Rejected, Needs Review)</li>
                <li>Assessor name and date</li>
                <li>Mapping strength (Exact, Strong, Partial, Related)</li>
                <li>Assessor notes</li>
                <li>Validation notes</li>
                <li>Evidence attachments (with download links)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>CSF Maturity Score Dashboard</h2>
    <p>The <strong>CSF Maturity Score</strong> page provides a visual dashboard showing your organization's maturity across all 14 security domains, aligned to the NIST Cybersecurity Framework.</p>

    <h3>How to Access</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">CSF Maturity Score</span>.</li>
        <li>If you have multiple assessments, select the desired one from the dropdown.</li>
        <li>The page shows:
            <ul>
                <li><strong>Overall FAIR Score</strong> &mdash; A weighted average maturity score across all domains</li>
                <li><strong>Radar Chart</strong> &mdash; A visual spider/radar chart plotting your scores across all 14 domains</li>
                <li><strong>Domain Score Cards</strong> &mdash; Individual cards for each domain showing average maturity, questions answered, and conformity breakdown</li>
                <li><strong>Framework Compliance Bars</strong> &mdash; Horizontal bars showing compliance percentages per framework</li>
                <li><strong>Gap Analysis Summary</strong> &mdash; Domains where scores are below target</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>GRC Module &rarr; CSF Maturity Score.</strong> The four headline tiles &mdash; <em>CSF Maturity Score</em> (1&ndash;4 scale), <em>Compliance Rate</em>, <em>Questions Answered</em>, and <em>Gaps Found</em> &mdash; summarise your posture at a glance. The <strong>Security Domain Maturity Radar</strong> plots all 14 domains, and the list on the right gives each domain's exact average score. Pick the assessment you want from the dropdown at the top.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Gap Analysis</h2>
    <p>The <strong>Gap Analysis</strong> page pulls together every weakness found during an assessment &mdash; every question answered <strong>Non-Conforming</strong> or <strong>Partial</strong> &mdash; into one prioritised worklist. It answers the question "where are we falling short, and what does each shortfall affect?"</p>

    <h3>How to Access</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Gaps</span>.</li>
        <li>Select the assessment you want to analyse from the <span class="field-label">Assessment</span> dropdown.</li>
    </ol>

    <h3>What the Page Shows</h3>
    <p>Four summary tiles at the top count your <strong>Total Gaps</strong>, <strong>Non-Conforming</strong>, <strong>Partial</strong>, and gaps <strong>With Linked Risk</strong>. Below them, each gap is listed as a row with:</p>
    <ul>
        <li><strong>Severity</strong> &mdash; a badge: <em>Non-Conforming</em> (red) or <em>Partial</em> (amber).</li>
        <li><strong>Domain</strong> and <strong>Ref</strong> &mdash; the security domain and the exact question reference (e.g., <code>GOV-08</code>).</li>
        <li><strong>Finding</strong> &mdash; the question text describing what is missing.</li>
        <li><strong>Framework Impact</strong> &mdash; badges for every framework requirement this gap affects, so you can see at a glance whether a single fix improves SOC 2, ISO 27001, PCI DSS, and more at once.</li>
        <li><strong>Risk</strong> &mdash; whether a risk has been logged for this gap, and a <span class="btn-label">View</span> action to open the full detail.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Gaps.</strong> Every non-conforming or partial response becomes a gap. The <strong>Framework Impact</strong> column shows which requirements across each framework the gap touches &mdash; closing one gap can lift several frameworks at once.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Frameworks Page</h2>
    <p>The <strong>Frameworks</strong> page is your central hub for viewing compliance status across all supported frameworks. It shows assessment-driven compliance data.</p>

    <h3>How to Use</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>Select an assessment from the <span class="field-label">Assessment</span> dropdown. The page defaults to your most recent assessment.</li>
        <li>The page displays framework cards in a grid. Only frameworks with mapped questions appear. Each card shows compliance percentage, maturity score, and metric counts.</li>
        <li>Click a framework card to open the detailed compliance report.</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Frameworks.</strong> The top tiles count your frameworks, average readiness, total requirements, and how many <em>need attention</em>. Each card shows a framework's compliance donut, its average maturity, and the Conforming / Partial / Non-Conforming / Total-Mapped breakdown. Click any card to open that framework's full compliance report.</figcaption>
    </figure>

    <h3>Framework Requirement Tree</h3>
    <p>If you navigate to this page <em>without</em> selecting an assessment (or by clicking a framework link from elsewhere), you will see the <strong>Requirement Tree</strong> view. This shows the hierarchical structure of all requirements within a framework, along with mapped controls and implementation status. Administrators and Cyber GRC users can add, edit, and delete custom requirements here.</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>Internal Controls</h2>
    <p><strong>Internal Controls</strong> are the specific security measures your organization has implemented. Examples: "Multi-Factor Authentication on all systems," "Daily encrypted backups," "Annual penetration testing."</p>

    <h3>How to Create a Control</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Internal Controls</span>.</li>
        <li>Click <span class="btn-label">+ New Control</span>.</li>
        <li>Fill in the fields:
            <ul>
                <li><span class="field-label">Control Title</span> &mdash; A short name (e.g., "MFA for all user accounts")</li>
                <li><span class="field-label">Description</span> &mdash; Detailed description of what this control does</li>
                <li><span class="field-label">Control Type</span> &mdash; Preventive, Detective, Corrective, or Directive</li>
                <li><span class="field-label">Category</span> &mdash; Technical, Administrative, or Physical</li>
                <li><span class="field-label">Implementation Status</span> &mdash; Planned, In Progress, Implemented, or Not Applicable</li>
                <li><span class="field-label">Effectiveness</span> &mdash; Not Tested, Ineffective, Partially Effective, or Effective</li>
                <li><span class="field-label">Risk Level</span> &mdash; Low, Medium, High, or Critical</li>
                <li><span class="field-label">Owner</span> &mdash; The person responsible (limited to Administrator and Cyber GRC group members)</li>
                <li><span class="field-label">Test Frequency</span> &mdash; How often this control is tested (Daily, Weekly, Monthly, etc.)</li>
            </ul>
        </li>
        <li>Under <strong>Framework Mapping</strong>, select which framework requirements this control satisfies. You can map one control to requirements across multiple frameworks.</li>
        <li>Click <span class="btn-label">Save</span>.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Key Benefit &mdash; Cross-Framework Mapping:</strong> A single control like "MFA" can satisfy requirements in SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2), and NIST CSF (PR.AC-7) simultaneously. Map it once and it covers all frameworks.
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Framework Crosswalk</h2>
    <p>The <strong>Framework Crosswalk</strong> shows how compliance with one framework automatically provides coverage for another. For example, if you are SOC 2 compliant, how much of ISO 27001 do you already cover?</p>

    <h3>How to Use</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Framework Crosswalk</span>.</li>
        <li>Select a <span class="field-label">Source Framework</span> (the framework you have already completed, e.g., "SOC 2").</li>
        <li>Select a <span class="field-label">Target Framework</span> (the framework you want to compare against, e.g., "ISO 27001").</li>
        <li>The crosswalk table shows which target requirements are covered by your source controls, and which have gaps.</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Evidence Library</h2>
    <p>The <strong>Evidence Library</strong> is a centralized repository for all compliance evidence across your organization.</p>

    <h3>How to Upload Evidence</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span>.</li>
        <li>Click <span class="btn-label">+ Upload Evidence</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Evidence Type</span> (screenshot, document, certificate, configuration, report, etc.), <span class="field-label">Description</span>, and optionally an <span class="field-label">Expiry Date</span>.</li>
        <li>Select the file to upload.</li>
        <li>Click <span class="btn-label">Upload</span>. The file is encrypted and stored securely.</li>
        <li>You can then link this evidence to specific controls or assessment responses.</li>
    </ol>

    <h3>Evidence Statuses</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Meaning</th></tr>
        <tr><td><strong>Current</strong></td><td>Active, valid evidence</td></tr>
        <tr><td><strong>Expired</strong></td><td>Past its expiry date &mdash; needs to be refreshed</td></tr>
        <tr><td><strong>Superseded</strong></td><td>Replaced by newer evidence</td></tr>
        <tr><td><strong>Draft</strong></td><td>Uploaded but not yet reviewed or finalized</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Policy Management</h2>
    <p>The <strong>Policies</strong> page provides a full policy lifecycle &mdash; from drafting through approval, publishing, and periodic review.</p>

    <h3>How to Create a Policy</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Policy Management</span> &rarr; <span class="menu-label">Policies</span>.</li>
        <li>Click <span class="btn-label">+ New Policy</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Category</span> (Security, Privacy, Compliance, Operational, HR, IT, etc.), <span class="field-label">Review Frequency</span> (how often the policy should be reviewed).</li>
        <li>Write the policy content using the rich text editor.</li>
        <li>Click <span class="btn-label">Save</span>. The policy is created in <span class="status-label">Draft</span> status.</li>
        <li>When ready, submit for <strong>Review</strong> &rarr; <strong>Approve</strong> &rarr; <strong>Publish</strong>.</li>
    </ol>

    <h3>Policy Lifecycle</h3>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Review</span> &rarr; <span class="status-label">Approved</span> &rarr; <span class="status-label">Published</span> &rarr; (Periodic Review or <span class="status-label">Retired</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Audits &amp; Findings</h2>
    <p>The <strong>Audits</strong> page manages the full audit lifecycle &mdash; from planning through fieldwork, findings, remediation, and closure.</p>

    <h3>How to Create an Audit</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Audits</span>.</li>
        <li>Click <span class="btn-label">+ New Audit</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Audit Type</span> (Internal, External, Certification, Surveillance, Readiness), <span class="field-label">Framework</span>, <span class="field-label">Lead Auditor</span>, <span class="field-label">Planned Start/End Dates</span>.</li>
        <li>Click <span class="btn-label">Create</span>.</li>
    </ol>

    <h3>Recording Findings</h3>
    <ol class="steps">
        <li>Open an audit and click <span class="btn-label">+ Add Finding</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Severity</span> (Informational, Low, Medium, High, Critical), <span class="field-label">Finding Type</span> (Nonconformity, Observation, Opportunity, Strength), and <span class="field-label">Description</span>.</li>
        <li>Map the finding to specific framework requirements or controls.</li>
        <li>Assign remediation to a team member with a due date.</li>
        <li>Track remediation progress through to <strong>Verified Closed</strong> status.</li>
    </ol>

    <h3>Audit Statuses</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Meaning</th></tr>
        <tr><td><strong>Planning</strong></td><td>Defining scope, objectives, and schedule</td></tr>
        <tr><td><strong>Fieldwork</strong></td><td>Active testing, evidence review, and interviews</td></tr>
        <tr><td><strong>Reporting</strong></td><td>Drafting audit report and documenting findings</td></tr>
        <tr><td><strong>Remediation</strong></td><td>Findings have been reported; team is fixing issues</td></tr>
        <tr><td><strong>Closed</strong></td><td>All findings resolved and audit is complete</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Risk Register</h2>
    <p>The <strong>Risk Register</strong> tracks organizational risks with likelihood/impact scoring, treatment plans, and links to controls.</p>

    <h3>How to Add a Risk</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Risk Register</span>.</li>
        <li>Click <span class="btn-label">+ New Risk</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Description</span>, <span class="field-label">Category</span> (Strategic, Operational, Financial, Compliance, Reputational, Technology, Third Party).</li>
        <li>Set <span class="field-label">Likelihood</span> (Rare, Unlikely, Possible, Likely, Almost Certain) and <span class="field-label">Impact</span> (Insignificant, Minor, Moderate, Major, Catastrophic).</li>
        <li>The system calculates the <strong>Inherent Risk Score</strong> (Likelihood &times; Impact, on a 1-25 scale).</li>
        <li>Select a <span class="field-label">Treatment Strategy</span>: Accept, Mitigate, Transfer, or Avoid.</li>
        <li>Link relevant internal controls to show how the risk is being mitigated. The system calculates the <strong>Residual Risk Score</strong> after controls.</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Continuous Monitors</h2>
    <p><strong>Continuous Monitors</strong> are automated checks that verify your security controls on a schedule (hourly, daily, weekly, or monthly).</p>

    <h3>How to Create a Monitor</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Continuous Monitors</span>.</li>
        <li>Click <span class="btn-label">+ New Monitor</span>.</li>
        <li>Fill in: <span class="field-label">Title</span>, <span class="field-label">Check Type</span>, <span class="field-label">Frequency</span> (Hourly, Daily, Weekly, Monthly), and the <span class="field-label">Collector Configuration</span> (JSON settings for the check).</li>
        <li>Link the monitor to an internal control.</li>
        <li>Enable the monitor. It will run automatically on the configured schedule.</li>
        <li>View results (Pass, Fail, Error, Warning) and execution history on the monitor detail page.</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Task Inbox</h2>
    <p>The <strong>Task Inbox</strong> shows all GRC tasks assigned to you across all assessments. Tasks are created during assessments to delegate work like evidence collection, remediation, reviews, or documentation.</p>

    <h3>How to Use</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Task Inbox</span>.</li>
        <li>You will see a list of tasks assigned to you. Each task shows: title, type (Evidence Request, Remediation, Review, Documentation, Implementation), priority, due date, and status.</li>
        <li>Click a task to view details and update its status.</li>
        <li>Mark tasks as <span class="status-label">In Progress</span> when you start working, and <span class="status-label">Completed</span> when done.</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>GRC Dashboard</h2>
    <p>The <strong>GRC Dashboard</strong> is your compliance command center &mdash; a single-page overview of your entire GRC posture.</p>

    <h3>What the Dashboard Shows</h3>
    <ul>
        <li><strong>Framework Compliance Heatmap</strong> &mdash; Color-coded compliance percentages for each framework</li>
        <li><strong>Control Implementation Progress</strong> &mdash; How many controls are implemented vs. planned</li>
        <li><strong>Evidence Freshness</strong> &mdash; How many evidence items are current, expiring, or expired</li>
        <li><strong>Open Findings</strong> &mdash; Count and severity breakdown of unresolved audit findings</li>
        <li><strong>Policy Review Status</strong> &mdash; Policies that are due for review</li>
        <li><strong>Monitor Health</strong> &mdash; Pass/fail status of continuous monitors</li>
        <li><strong>Risk Register Summary</strong> &mdash; Open risks by severity</li>
    </ul>

    <h3>How to Access</h3>
    <p>Navigate to <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">GRC Dashboard</span>.</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>TPRM Module: What is Third Party Risk Management?</h2>
    <p>Every company relies on outside vendors &mdash; cloud providers, payroll companies, marketing platforms, IT consultants. Each vendor may have access to your data or systems. <strong>TPRM</strong> helps you answer: "How risky is each vendor, and are they protecting our data?"</p>
    <ul>
        <li>Add and track all your vendors in one place</li>
        <li>Assign a risk tier (Tier 1 = highest risk, Tier 3 = lowest)</li>
        <li>Send security questionnaires (assessments) to vendors</li>
        <li>Automatically score vendors using external security rating services</li>
        <li>Perform quantitative risk analysis (FAIR) to estimate potential financial losses</li>
        <li>Track 4th-party risk (your vendors' vendors)</li>
        <li>Discover unmanaged SaaS applications (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>Adding a New Vendor</h2>
    <ol class="steps">
        <li>In the left sidebar, expand <span class="menu-label">TPRM Module</span>, then expand the <span class="menu-label">Stakeholders</span> section.</li>
        <li>Click <span class="menu-label">New Request</span>. This opens the vendor onboarding form.</li>
        <li>Fill in the required fields:
            <ul>
                <li><span class="field-label">Vendor Name</span> &mdash; The company's legal name (e.g., "Acme Cloud Services")</li>
                <li><span class="field-label">Vendor Domain</span> &mdash; Their website domain without https:// (e.g., "acmecloud.com"). Used by security scoring engines to scan the vendor.</li>
            </ul>
        </li>
        <li>Fill in recommended optional fields:
            <ul>
                <li><span class="field-label">Vendor Type</span> &mdash; Technology, Professional Services, Financial Services, HR/Benefits, etc.</li>
                <li><span class="field-label">Vendor Tier</span> &mdash; 1 (Critical), 2 (Important), or 3 (Standard)</li>
                <li><span class="field-label">Primary Contact Name</span>, <span class="field-label">Email</span>, <span class="field-label">Phone</span></li>
                <li><span class="field-label">PII Record Count</span> &mdash; How many personal records this vendor accesses</li>
                <li><span class="field-label">SPII Record Count</span> &mdash; How many sensitive personal records (SSNs, health data)</li>
            </ul>
        </li>
        <li>Click <span class="btn-label">Save</span>. The vendor is created in <strong>Draft</strong> status.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Vendor Tiers Explained:</strong><br>
        &bull; <strong>Tier 1 (Critical)</strong> &mdash; Vendors with access to sensitive data or critical systems. Require full assessment.<br>
        &bull; <strong>Tier 2 (Important)</strong> &mdash; Vendors with moderate access. Require standard assessment.<br>
        &bull; <strong>Tier 3 (Standard)</strong> &mdash; Low-risk vendors. May only require a basic review.
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>Vendor Lifecycle</h2>
    <p>Vendors move through a defined lifecycle:</p>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Pending Review</span> &rarr; <span class="status-label">In Review</span> &rarr; <span class="status-label">Approved</span> (or <span class="status-label">Rejected</span>) &rarr; <span class="status-label">Active</span> &rarr; <span class="status-label">Annual Review</span> &rarr; <span class="status-label">Offboarded</span></p>
    <p>Each stage triggers appropriate workflows, notifications, and required actions.</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>Vendor Assessments</h2>
    <p>Vendor assessments are security questionnaires sent to vendors to evaluate their security posture. Navigate to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> to manage them.</p>
    <ol class="steps">
        <li>Open a vendor's detail page.</li>
        <li>Click <span class="btn-label">Send Assessment</span>.</li>
        <li>Select the assessment template appropriate for the vendor's tier.</li>
        <li>The vendor receives an email with a link to complete the questionnaire.</li>
        <li>Once submitted, review the vendor's responses and score them.</li>
    </ol>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Security Risk Scorecard (SRS)</h2>
    <p>The SRS provides an automated, external security score for each vendor based on DNS configuration, SSL/TLS, email security (SPF, DKIM, DMARC), open ports, and other technical indicators.</p>
    <p>Navigate to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Security Risk Scorecard</span>.</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>FAIR Analysis</h2>
    <p><strong>FAIR</strong> (Factor Analysis of Information Risk) is a quantitative risk model that estimates the probable financial loss from a security event involving a vendor.</p>
    <p>Navigate to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">FAIR Analysis</span> to create and view analyses.</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>4th Party Risk</h2>
    <p>Track the vendors that <em>your vendors</em> rely on. If your cloud provider uses a subcontractor for data storage, that is a 4th-party risk.</p>
    <p>Navigate to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">4th Party Risk</span>.</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Shadow SaaS Discovery</h2>
    <p>Discover SaaS applications being used across your organization that may not have been formally approved or assessed. Navigate to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Shadow SaaS</span>. In v2.6.1 this list can be filled automatically by the <a href="#shadow-saas-grip">Grip Security integration</a>, and unsanctioned apps can be blocked in <a href="#zscaler">Zscaler</a>.</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.1)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>Vendor Onboarding &amp; Procurement Onboarding <span class="new-badge">New in 2.6.1</span></h2>
    <p>A <strong>vendor onboarding request</strong> is how a new vendor enters the platform. It moves through a series of <strong>statuses</strong> from first draft to final decision. Before the cyber team will review a vendor, the vendor must first be <strong>onboarded through your procurement process</strong> and have a valid <strong>Vendor ID (VID)</strong>. This section explains why, and exactly how it works.</p>

    <h3>The onboarding journey (statuses)</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>What it means</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>The request is being filled in. It has not been sent for review yet.</td></tr>
        <tr><td><span class="status-label">Submitted</span></td><td>The request passed the submission checks and has been sent to the cyber team.</td></tr>
        <tr><td><span class="status-label">In Review</span></td><td>The cyber team is reviewing the vendor.</td></tr>
        <tr><td><span class="status-label">AI Review</span></td><td>The vendor's services use AI and it is in the dedicated AI review stage (see <a href="#ai-review">AI Review</a>).</td></tr>
        <tr><td><span class="status-label">Evaluation</span></td><td>The vendor is being trialled or evaluated.</td></tr>
        <tr><td><span class="status-label">Approved</span></td><td>The vendor has been approved and is onboarded.</td></tr>
        <tr><td><span class="status-label">Rejected</span></td><td>The vendor was not approved.</td></tr>
        <tr><td><span class="status-label">Inactive</span></td><td>The vendor is no longer active.</td></tr>
    </table>

    <h3>Finding your vendor requests</h3>
    <p>Go to <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Stakeholders</span> &rarr; <span class="menu-label">Vendor Onboarding</span>. You will see a searchable list of vendors with their status, tier, security score (SRS), and quick actions (View, Edit). Use the filter pills at the top (for example <strong>All</strong>, <strong>Approved</strong>, <strong>Review</strong>) to narrow the list. Use <span class="btn-label">+ New Request</span> to start a new vendor.</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Vendor Onboarding list.</strong> Search, filter pills, and per-vendor actions. The <strong>Review</strong> filter pill is a single view that combines both <em>In Review</em> and <em>AI Review</em> vendors.</figcaption>
    </figure>

    <h3 id="procurement-onboarding">The two things every vendor needs before review</h3>
    <p>Open a vendor and look at the <strong>Vendor Information</strong> card. Two fields control whether the vendor can be submitted for cyber review:</p>
    <ul>
        <li><strong>Procurement Onboarding</strong> &mdash; a Yes/No field answering the question <em>"Has this vendor completed Procurement Onboarding?"</em> This must be set to <strong>Yes</strong>.</li>
        <li><strong>Vendor ID (VID)</strong> &mdash; the 4&ndash;8 digit identifier assigned to the vendor by your procurement system. This must be a valid 4&ndash;8 digit number.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Vendor Information card.</strong> The <strong>Vendor ID (VID)</strong> and the procurement-onboarding field both need to be filled in before the vendor can be submitted. <em>Note:</em> on instances upgraded from an earlier release this field may still read <strong>"VSU Onboarded"</strong>; in v2.6.1 it is labelled <strong>"Procurement Onboarding"</strong> &mdash; it is the same field.</figcaption>
    </figure>

    <h3>Submitting a vendor for review</h3>
    <ol class="steps">
        <li>Open the vendor from the <span class="menu-label">Vendor Onboarding</span> list (the request must be in <strong>Draft</strong>).</li>
        <li>In the <strong>Vendor Information</strong> card, set <span class="field-label">Procurement Onboarding</span> to <strong>Yes</strong> and enter a valid <span class="field-label">Vendor ID (VID)</span> (4&ndash;8 digits). Save your changes.</li>
        <li>Click <span class="btn-label">Submit for Review</span>. You will be asked to confirm: <em>"Submit this vendor for review? The vendor must have a valid VID and be onboarded at VSU."</em></li>
        <li>If both checks pass, the status changes to <strong>Submitted</strong> and the cyber team is notified.</li>
    </ol>
    <div class="callout callout-danger">
        <strong>If submission is blocked,</strong> you will see one of these messages:
        <ul style="margin:8px 0 0;">
            <li>"Cannot submit: Vendor must be onboarded at VSU before submission. Please complete the onboarding assessment with VSU details." &rarr; set <strong>Procurement Onboarding</strong> to <strong>Yes</strong>.</li>
            <li>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits). Please complete the onboarding assessment with the VSU Vendor ID." &rarr; enter a valid 4&ndash;8 digit <strong>Vendor ID</strong>.</li>
        </ul>
        See <a href="#troubleshooting">Troubleshooting</a> for why this rule exists.
    </div>
</div>

<div class="doc-section" id="ai-review">
    <h2>AI Review for Vendors <span class="new-badge">New in 2.6.1</span></h2>
    <p>Some vendors provide services that use artificial intelligence. These vendors can carry different risks, so v2.6.1 adds a dedicated <strong>AI Review</strong> status to track them separately during the review process.</p>

    <h3>How a vendor enters AI Review</h3>
    <p>On the <strong>Vendor Information</strong> card there is a <span class="field-label">Services Use AI</span> field. When this is set to <strong>Yes</strong>, an authorized reviewer (a <strong>Cyber TPRM</strong> user or <strong>Administrator</strong>, while editing the vendor) sees a <span class="btn-label">Force AI Review</span> link directly beneath that field.</p>
    <ol class="steps">
        <li>Open the vendor and confirm <span class="field-label">Services Use AI</span> is set to <strong>Yes</strong>.</li>
        <li>Click <span class="btn-label">Force AI Review</span>. Confirm the prompt: <em>"Force this vendor into AI Review?"</em></li>
        <li>The vendor's status changes to <strong>AI Review</strong>.</li>
    </ol>
    <div class="callout callout-info">
        <strong>Why might the link not appear?</strong> The <strong>Force AI Review</strong> link only shows when (1) you have permission to approve, (2) you are in edit mode, (3) <strong>Services Use AI</strong> is <strong>Yes</strong>, and (4) the vendor is not already in AI Review. If <strong>Services Use AI</strong> is "No", you will see the message <em>"AI Review can only be forced for vendors whose services use AI."</em></p>
    </div>
    <p>On the <a href="#procurement-cyber-status">Procurement Cyber Status</a> page and on the vendor list's <strong>Review</strong> filter, vendors in <strong>In Review</strong> and <strong>AI Review</strong> are shown together &mdash; so nothing in review is ever hidden just because it is being reviewed with AI.</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Procurement Cyber Status <span class="new-badge">New in 2.6.1</span></h2>
    <p>The <strong>Cyber Status</strong> page gives the <strong>procurement team</strong> a simple, always-current view of which vendors the cyber team is reviewing and what the latest word is on each one &mdash; without needing access to the full security tooling. The cyber team posts short, dated updates; procurement reads them here (and in a weekly email).</p>
    <p>Open it from <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Procurement</span> &rarr; <span class="menu-label">Cyber Status</span>. It is available to <strong>Procurement</strong>, <strong>Cyber TPRM</strong>, and <strong>Administrator</strong> users.</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Procurement &rarr; Cyber Status.</strong> Lists every vendor whose status is <em>In Review</em> or <em>AI Review</em>, with the number of updates and the date of the latest update. When no vendors are in review the table is replaced by a "No vendors in review" message.</figcaption>
    </figure>

    <h3>Reading a vendor's update history</h3>
    <ol class="steps">
        <li>Click a vendor's name in the <strong>Vendors in Review</strong> table.</li>
        <li>The <strong>Procurement Update History</strong> panel opens, showing every update newest-first: the date and time, who wrote it, the vendor's status at that time, and the note itself.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>A vendor's update history.</strong> Clicking a vendor name opens its <strong>Procurement Update History</strong>. Each entry shows the date and time, the author, a badge for the vendor's status when the note was written, and the cyber team's note &mdash; so procurement can see exactly where each review stands. Long histories are paged with the <em>Show&nbsp;per&nbsp;page</em> control.</figcaption>
    </figure>

    <h3>For cyber reviewers: posting an update to procurement</h3>
    <p>Cyber TPRM users and admins can post an update for one or more vendors at once:</p>
    <ol class="steps">
        <li>On the <strong>Cyber Status</strong> page, tick the checkbox next to each vendor you want to update.</li>
        <li>Click <span class="btn-label">Provide Procurement with Update</span>.</li>
        <li>In the <strong>Provide Procurement with Update</strong> window, type your note in the <span class="field-label">Update</span> box.</li>
        <li>Optionally use <span class="field-label">Change status</span> to move the vendor(s) forward (for example to <strong>Evaluation</strong>, <strong>Approved</strong>, or <strong>Rejected</strong>). Leave it on <em>Keep current status</em> to only add a note.</li>
        <li>Click <span class="btn-label">Save Update</span>. The update is recorded against every selected vendor.</li>
    </ol>

    <h3>The weekly procurement digest email</h3>
    <p>To keep procurement informed without anyone logging in, the platform can email a <strong>weekly digest</strong> listing every vendor in review together with its most recent update. By default this is sent <strong>every Monday at 7:00 AM</strong>.</p>
    <ol class="steps">
        <li>An administrator goes to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span> and finds the <strong>Procurement Update Digest</strong> options.</li>
        <li>Turn the digest <strong>on</strong> and enter one or more recipient email addresses (separated by commas).</li>
        <li>Save. You can also send one immediately with <span class="btn-label">Send digest now</span> to test it.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Scheduler.</strong> The <strong>Procurement Update Digest</strong> job (bottom of the list) runs weekly. The Scheduler is where admins enable, disable, and time all automated jobs.</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.1)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Grip Security Integration (Shadow SaaS) <span class="new-badge">New in 2.6.1</span></h2>
    <p>"Shadow SaaS" means cloud apps that employees use which were never formally approved. <strong>Grip Security</strong> is a service that discovers these apps. In v2.6.1 you can connect your Grip account so the platform automatically pulls in the apps Grip finds &mdash; along with how many people use each one, a risk score, and security alerts &mdash; and lists them on your <a href="#tprm-shadow-saas">Shadow SaaS</a> page.</p>
    <p>It is configured by an administrator at <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>.</p>

    <h3>Connecting Grip (step by step)</h3>
    <ol class="steps">
        <li>In Grip, create an <strong>API token</strong> and note your tenant's base URL (it ends in <code>/public/saas</code>, for example <code>https://acme.dep.grip.security/public/saas</code>).</li>
        <li>In the platform, go to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> and find the <strong>Grip Security Connection</strong> card.</li>
        <li>Tick <span class="field-label">Enable Grip Security integration</span>.</li>
        <li>Paste your tenant URL into <span class="field-label">Server (Tenant Base URL)</span> and your token into <span class="field-label">API Token</span>.</li>
        <li>Click <span class="btn-label">Save Configuration</span>, then click <span class="btn-label">Test Connection</span> to confirm. A success message looks like <em>"Connected to Grip — sample returned 1 record(s)"</em>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Grip Security Connection.</strong> Enter your tenant URL and API token, save, then test.</figcaption>
    </figure>

    <h3>Keeping it up to date automatically</h3>
    <p>Use the <strong>Scheduled Rehydration</strong> card to have the platform refresh Grip data on a schedule. Tick <span class="field-label">Enable scheduled rehydration via cron</span> and choose a <span class="field-label">Run frequency</span> (Hourly, Every 6 hours, Every 12 hours, Daily, or Weekly). You can also click <span class="btn-label">Run Now</span> to refresh immediately.</p>
    <figure class="doc-figure">
        <img src="app/docs/grip-cron-card.png" alt="Scheduled Rehydration settings for Grip" loading="lazy">
        <figcaption><strong>Scheduled Rehydration.</strong> Choose how often Grip data refreshes, or click <strong>Run Now</strong> for an immediate sync. The exact schedule line shown is the technical command your server runs.</figcaption>
    </figure>

    <h3>What you'll see afterward</h3>
    <p>Discovered apps appear on the <span class="menu-label">Shadow SaaS</span> page as <strong>Pending</strong> entries with a risk score (shown on a 1&ndash;5 scale), category, and number of users. From there you can <strong>Allow</strong> an app (which begins onboarding it as a vendor), <strong>Deny</strong> it (mark it unsanctioned, and optionally block it in Zscaler), or <strong>Dismiss</strong> it.</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>The Shadow SaaS page.</strong> Discovered and imported apps with their risk and actions. Apps onboarded as vendors are skipped on future syncs, and anything you dismiss stays dismissed.</figcaption>
    </figure>
</div>

<div class="doc-section" id="zscaler">
    <h2>Zscaler Blocking Integration <span class="new-badge">New in 2.6.1</span></h2>
    <p><strong>Zscaler</strong> is a web-security service that can block access to websites. With this integration, when you <strong>Deny</strong> an unsanctioned app on the Shadow SaaS page, the platform can automatically add that app's web domain to a blocking list in your Zscaler account &mdash; so people can no longer reach it. Clicking <strong>Allow</strong> later removes the block.</p>
    <p>It is configured by an administrator at <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>, on the <strong>Zscaler Connection</strong> card.</p>

    <h3>Connecting Zscaler (step by step)</h3>
    <ol class="steps">
        <li>In Zscaler (ZIdentity), create an <strong>API Client</strong> and copy its <strong>Client ID</strong> and <strong>Client Secret</strong>. Note your <strong>vanity domain</strong> (the part before <code>.zslogin.net</code>).</li>
        <li>In ZIA, create (or pick) a <strong>custom URL Category</strong> that the blocked domains will be added to, and note its exact name.</li>
        <li>In the platform's <strong>Zscaler Connection</strong> card, tick <span class="field-label">Enable Zscaler URL-Category blocking on Deny</span>.</li>
        <li>Fill in <span class="field-label">API URL</span> (default <code>https://api.zsapi.net</code>), <span class="field-label">ZIdentity Vanity Domain</span>, <span class="field-label">Client ID</span>, <span class="field-label">Client Secret</span>, and the <span class="field-label">URL Category</span> name.</li>
        <li>Click <span class="btn-label">Save Configuration</span>, then <span class="btn-label">Test Connection</span> to confirm the credentials work.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Zscaler Connection.</strong> When enabled, the <strong>Deny</strong> button on a Shadow SaaS app adds its domain to the URL Category you name here. The category must already exist in Zscaler.</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>If blocking is turned off,</strong> denying an app only marks it unsanctioned in the platform; nothing is sent to Zscaler. You will see <em>"Marked unsanctioned. Zscaler integration is not enabled; domain not added to URL Category."</em>
    </div>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Admin Portal: General Settings</h2>
    <p>The Admin Portal is accessible via <span class="menu-label">Administration</span> in the sidebar (admin users only) or the <span class="btn-label">Admin</span> link in the top bar.</p>
    <p>General Settings include: application name, company name, support email, and system-wide configuration options.</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Branding &amp; Theme</h2>
    <p>Customize the platform appearance: upload your company logo, set sidebar colors, header colors, button colors, and navigation width. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Branding</span>.</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>User Management</h2>
    <p>Manage user accounts and group assignments. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>.</p>

    <h3>Assigning Users to ACL Groups</h3>
    <ol class="steps">
        <li>Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>.</li>
        <li>Find the user in the list.</li>
        <li>Click the <span class="btn-label">Groups</span> button next to the user's name.</li>
        <li>A modal will appear showing all available groups with checkboxes. Check the groups you want to assign (e.g., <strong>Cyber GRC</strong>, <strong>Administrator</strong>).</li>
        <li>Click <span class="btn-label">Save Changes</span>.</li>
    </ol>
</div>

<div class="doc-section" id="admin-email">
    <h2>Email Configuration</h2>
    <p>Configure SMTP settings for sending email notifications. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email</span>. Settings include SMTP host, port, username, password, encryption method (TLS/SSL), and sender address.</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>Configure Single Sign-On using SAML 2.0. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>. This allows users to log in using your organization's identity provider (Okta, Azure AD, etc.).</p>
</div>

<div class="doc-section" id="admin-ai">
    <h2>AI Integration</h2>
    <p>Enable AI-powered features including assessment note refinement, control suggestions, and report language assistance. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">AI Platform</span> to configure your AI provider API key.</p>
    <p>In v2.6.1 the same AI provider also powers <strong>automatic translation of assessment questions</strong> (see <a href="#language">Changing Your Language</a>) and AI-assisted FAIR risk analysis for vendors.</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>Updating the Platform <span class="new-badge">New in 2.6.1</span></h2>
    <p>Administrators can check for and apply new versions from inside the platform. Navigate to <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span>.</p>
    <ol class="steps">
        <li>The <strong>Current Status</strong> card shows your <span class="field-label">Installed Version</span> and whether a newer one is available.</li>
        <li>Confirm the <span class="field-label">Registry Hostname</span> is correct (your image registry), then click <span class="btn-label">Check for Updates</span>.</li>
        <li>If a newer version is listed, follow the on-screen <strong>Upgrade</strong> action to apply it.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png" alt="Version Management page showing installed version 2.6.1" loading="lazy">
        <figcaption><strong>Admin &rarr; Version.</strong> Here the installed version is <strong>v2.6.1</strong> and the platform reports it is up to date. This is also where you confirm which version this guide applies to.</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>Frequently Asked Questions</h2>
    <p>Type a keyword below to instantly filter the questions &mdash; for example <em>language</em>, <em>VID</em>, <em>onboard</em>, <em>Grip</em>, or <em>password</em>.</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Search the FAQ&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>How do I change the language of the platform?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Click <strong>Profile</strong> (top-right), open the <strong>Language Preference</strong> card, choose your language, and click <strong>Update Language</strong>. This only changes your own screen. See <a href="#language">Changing Your Language</a>.</p></div></details>

        <details class="faq-item"><summary>Which languages are supported?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>English, Spanish, Italian, Ukrainian, Chinese (Simplified), Hindi, French, and Portuguese. Your administrator decides which of these appear in your list; English is always available.</p></div></details>

        <details class="faq-item"><summary>I changed my language but some text is still in English. Why?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Menus, buttons, and labels are translated. Vendor assessment <em>questions</em> are translated automatically only when an AI provider is configured by your administrator, and stored answer values always remain in English so scoring stays consistent. A few third-party messages may also remain in English.</p></div></details>

        <details class="faq-item"><summary>Why can't I submit my vendor for review?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>A vendor can only be submitted once it has completed <strong>Procurement Onboarding</strong> (set to <strong>Yes</strong>) and has a valid <strong>Vendor ID (VID)</strong> of 4&ndash;8 digits. Open the vendor, fill in both on the <strong>Vendor Information</strong> card, save, then click <strong>Submit for Review</strong>. See <a href="#onboarding-workflow">Vendor Onboarding</a> and <a href="#troubleshooting">Troubleshooting</a>.</p></div></details>

        <details class="faq-item"><summary>What is a Vendor ID (VID) and where do I get it?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>The VID is a 4&ndash;8 digit number assigned to the vendor by your procurement system when the vendor is onboarded. It links the vendor here to your procurement and finance records. If you don't have one, the vendor has not finished procurement onboarding yet.</p></div></details>

        <details class="faq-item"><summary>The field on my vendor says "VSU Onboarded", but the guide says "Procurement Onboarding". Which is it?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>They are the same field. It was renamed to the clearer <strong>"Procurement Onboarding"</strong> in v2.6.1. If your screen still shows <strong>"VSU Onboarded"</strong>, your instance hasn't been upgraded to the latest v2.6.1 image yet &mdash; the behaviour is identical.</p></div></details>

        <details class="faq-item"><summary>What does "AI Review" mean for a vendor?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>It is a separate review status for vendors whose services use AI, so they can be tracked apart from ordinary reviews. A Cyber TPRM user or admin moves a vendor into it with the <strong>Force AI Review</strong> link. See <a href="#ai-review">AI Review for Vendors</a>.</p></div></details>

        <details class="faq-item"><summary>I don't see the "Force AI Review" link. Why?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>It only appears when you are editing the vendor with approval permission, the vendor's <strong>Services Use AI</strong> field is <strong>Yes</strong>, and the vendor is not already in AI Review.</p></div></details>

        <details class="faq-item"><summary>What is the Procurement Cyber Status page?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>A plain-language page (<strong>TPRM &rarr; Procurement &rarr; Cyber Status</strong>) where procurement can see which vendors the cyber team is reviewing and read dated updates the cyber team posts. See <a href="#procurement-cyber-status">Procurement Cyber Status</a>.</p></div></details>

        <details class="faq-item"><summary>How does procurement get update emails?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>An administrator turns on the <strong>Procurement Update Digest</strong> under <strong>Admin &rarr; Email Settings</strong> and adds recipient addresses. It is emailed weekly (by default Mondays at 7:00 AM) and can also be sent on demand.</p></div></details>

        <details class="faq-item"><summary>What is Grip and what does it do here?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Grip Security discovers SaaS apps used across your organization. When connected (<strong>Admin &rarr; Shadow SaaS</strong>), the platform automatically pulls those apps, their user counts, risk scores, and alerts into your Shadow SaaS list. See <a href="#shadow-saas-grip">Grip Security Integration</a>.</p></div></details>

        <details class="faq-item"><summary>My Grip "Test Connection" failed. What should I check?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Confirm the <strong>Server (Tenant Base URL)</strong> ends in <code>/public/saas</code>, that the <strong>API Token</strong> is current, and that your server can reach the Grip endpoint. A token error reports <em>"Unauthorized — token rejected"</em>; a URL error reports <em>"Endpoint not found — check base URL"</em>.</p></div></details>

        <details class="faq-item"><summary>What does the Zscaler integration do?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>When you <strong>Deny</strong> an unsanctioned app, the platform can add its web domain to a blocking URL Category in your Zscaler account so people can't reach it. Clicking <strong>Allow</strong> later removes the block. See <a href="#zscaler">Zscaler Blocking Integration</a>.</p></div></details>

        <details class="faq-item"><summary>What's the difference between Allow, Deny, and Dismiss on a Shadow SaaS app?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p><strong>Allow</strong> begins onboarding the app as a vendor; <strong>Deny</strong> marks it unsanctioned (and can block it in Zscaler); <strong>Dismiss</strong> hides it from the list. Dismissed apps stay dismissed even after future syncs.</p></div></details>

        <details class="faq-item"><summary>Who can see the GRC module?<span class="faq-tag">Access</span></summary>
            <div class="faq-body"><p>Users in the <strong>Administrator</strong>, <strong>Cyber GRC</strong>, or <strong>Auditor</strong> groups. If you don't see it, ask your administrator to add you to one of these groups. See <a href="#roles">User Roles &amp; Permissions</a>.</p></div></details>

        <details class="faq-item"><summary>How do I turn on two-factor authentication (2FA)?<span class="faq-tag">Account</span></summary>
            <div class="faq-body"><p>Open <strong>Profile</strong> and use the <strong>Two-Factor Authentication (TOTP)</strong> card to enable it with an authenticator app such as Google Authenticator or Microsoft Authenticator.</p></div></details>

        <details class="faq-item"><summary>Can I save or print this documentation?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Yes. Click <strong>Download PDF</strong> at the top of this page. It produces a formatted document with a cover page, table of contents, and page numbers.</p></div></details>

        <details class="faq-item"><summary>How do I know which version I'm running?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Administrators can check <strong>Admin &rarr; Version</strong>. This guide describes <strong>v2.6.1</strong>. See <a href="#admin-updates">Updating the Platform</a>.</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">No questions match your search. Try a different keyword.</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Troubleshooting</h2>

    <h3>Why onboarding through procurement matters (the VID &amp; Procurement Onboarding rule)</h3>
    <p>This is the single most common thing that stops a vendor from moving forward, so it is worth understanding. The platform <strong>will not let a vendor be submitted for cyber review</strong> until two procurement facts are recorded on the vendor:</p>
    <ul>
        <li><strong>Procurement Onboarding = Yes</strong> &mdash; confirmation that the vendor has been set up and vetted through your organization's procurement process.</li>
        <li>A valid <strong>Vendor ID (VID)</strong> &mdash; the 4&ndash;8 digit number procurement assigns to the vendor.</li>
    </ul>
    <p>Why enforce this? Because the VID is the shared key that links this vendor to procurement, finance, and contract records. If the cyber team reviewed and approved a vendor that procurement had never onboarded, you would end up with duplicate or "ghost" vendors, security work that can't be tied back to a real purchase order, and reports that don't reconcile. Requiring procurement onboarding <em>first</em> keeps the security review and the procurement record pointing at the same, real vendor.</p>
    <div class="callout callout-warning">
        <strong>Fix it:</strong> Open the vendor, and on the <strong>Vendor Information</strong> card set <strong>Procurement Onboarding</strong> to <strong>Yes</strong> and enter the 4&ndash;8 digit <strong>Vendor ID (VID)</strong> from your procurement system. Save, then click <strong>Submit for Review</strong> again. If you don't have a VID yet, the vendor hasn't completed procurement onboarding &mdash; start there.
    </div>

    <h3>Common issues and how to resolve them</h3>
    <table class="doc-table">
        <tr><th>Symptom</th><th>Likely cause &amp; fix</th></tr>
        <tr><td>"Cannot submit: Vendor must be onboarded at VSU before submission&hellip;"</td><td>The <strong>Procurement Onboarding</strong> field isn't set to <strong>Yes</strong>. Set it to Yes on the Vendor Information card and save.</td></tr>
        <tr><td>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits)&hellip;"</td><td>The <strong>Vendor ID</strong> is missing or not 4&ndash;8 digits. Enter a valid VID from procurement.</td></tr>
        <tr><td>"Only draft requests can be submitted for review."</td><td>The vendor is already past Draft. You can only submit a request that is still in <strong>Draft</strong> status.</td></tr>
        <tr><td>The <strong>Submit for Review</strong> button isn't visible</td><td>It only appears for vendors in <strong>Draft</strong> when you have edit permission.</td></tr>
        <tr><td>"AI Review can only be forced for vendors whose services use AI."</td><td>Set <strong>Services Use AI</strong> to <strong>Yes</strong> on the vendor before forcing AI Review.</td></tr>
        <tr><td>I can't see the GRC module in the sidebar</td><td>You need to be in the <strong>Administrator</strong>, <strong>Cyber GRC</strong>, or <strong>Auditor</strong> group. Ask an administrator.</td></tr>
        <tr><td>My language change didn't stick</td><td>Make sure you clicked <strong>Update Language</strong> (not just changed the drop-down), and that the language is enabled by your administrator.</td></tr>
        <tr><td>Grip "Test Connection" fails</td><td>Check the base URL ends in <code>/public/saas</code> and the API token is valid and current.</td></tr>
        <tr><td>Denying a Shadow SaaS app didn't block it in Zscaler</td><td>Zscaler blocking must be enabled and configured, and the named <strong>URL Category</strong> must already exist in Zscaler.</td></tr>
        <tr><td>Procurement didn't receive the digest email</td><td>Confirm the digest is enabled with recipients under <strong>Admin &rarr; Email Settings</strong>, and that <strong>Admin &rarr; Email</strong> SMTP settings are correct.</td></tr>
        <tr><td>The Upgrade button says "Could not fetch manifest"</td><td>A registry/network issue reaching your image registry. Verify the <strong>Registry Hostname</strong> under <strong>Admin &rarr; Version</strong> and that the host can reach it.</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Still stuck?</strong> Note the exact on-screen message and which page you were on, then contact your platform administrator. Administrators can review <strong>Admin &rarr; Activity Log</strong> for details.
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>Glossary</h2>
    <table class="doc-table">
        <tr><th>Term</th><th>Definition</th></tr>
        <tr><td><strong>ACL</strong></td><td>Access Control List &mdash; defines what actions users in a group can perform</td></tr>
        <tr><td><strong>AI Review</strong></td><td>A vendor onboarding status for vendors whose services use AI, tracked separately during review</td></tr>
        <tr><td><strong>Assessment</strong></td><td>A point-in-time compliance evaluation using the unified questionnaire</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Center for Internet Security Controls &mdash; a prioritized set of security best practices</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Cybersecurity Maturity Model Certification &mdash; required for US Department of Defense contractors</td></tr>
        <tr><td><strong>Conformity Status</strong></td><td>Whether a requirement is Conforming, Partial, Non-Conforming, Not Applicable, or Not Assessed</td></tr>
        <tr><td><strong>Control</strong></td><td>A specific security measure implemented to meet compliance requirements</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>A mapping between two frameworks showing which requirements overlap</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; a widely-used cybersecurity risk management framework</td></tr>
        <tr><td><strong>Domain</strong></td><td>A category of security questions (e.g., Governance, Identity &amp; Access Management)</td></tr>
        <tr><td><strong>Evidence</strong></td><td>Documents, screenshots, or files that prove a compliance claim</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; a quantitative risk analysis methodology</td></tr>
        <tr><td><strong>FairScore</strong></td><td>The platform's overall maturity score calculated from assessment responses</td></tr>
        <tr><td><strong>Finding</strong></td><td>An issue discovered during an audit (nonconformity, observation, opportunity, or strength)</td></tr>
        <tr><td><strong>Framework</strong></td><td>A compliance standard like SOC 2, ISO 27001, PCI DSS, etc.</td></tr>
        <tr><td><strong>GRC</strong></td><td>Governance, Risk, and Compliance</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; a service that discovers SaaS apps in use; can feed the Shadow SaaS list (see <a href="#shadow-saas-grip">Grip Integration</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Health Insurance Portability and Accountability Act &mdash; US healthcare data protection law</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>International standard for information security management systems</td></tr>
        <tr><td><strong>Maturity Rating</strong></td><td>A 1-4 score indicating how mature a security practice is (1=Ad Hoc, 4=Optimized)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>NIST guidelines for protecting Controlled Unclassified Information (CUI)</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Payment Card Industry Data Security Standard</td></tr>
        <tr><td><strong>PII</strong></td><td>Personally Identifiable Information (names, emails, addresses, etc.)</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>Confirmation (Yes/No) that a vendor has been set up through your procurement process; required, along with a valid VID, before a vendor can be submitted for review. (Labelled "VSU Onboarded" on instances upgraded from earlier releases.)</td></tr>
        <tr><td><strong>Requirement</strong></td><td>A specific clause or control objective within a compliance framework</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software as a Service &mdash; cloud applications accessed over the web</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>SaaS apps used in the organization that were never formally approved or assessed</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Service Organization Control Type 2 &mdash; trust services criteria for service organizations</td></tr>
        <tr><td><strong>SPII</strong></td><td>Sensitive PII (SSNs, financial data, health records)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Security Risk Scorecard &mdash; the platform's external security rating/grade for a vendor</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Third Party Risk Management</td></tr>
        <tr><td><strong>Unified Question</strong></td><td>A single security question that maps to requirements across multiple frameworks</td></tr>
        <tr><td><strong>VID</strong></td><td>Vendor ID &mdash; a 4&ndash;8 digit identifier assigned to a vendor by your procurement system</td></tr>
        <tr><td><strong>VSU</strong></td><td>The procurement/vendor-setup function; "onboarded at VSU" means the vendor has completed Procurement Onboarding</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>A web-security service that can block website domains; integrated so unsanctioned apps can be blocked on Deny (see <a href="#zscaler">Zscaler Blocking</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>Getting Started</h4>
                    <a href="#overview">Platform Overview</a>
                    <a href="#navigation">Navigating the Sidebar</a>
                    <a href="#roles">User Roles &amp; Permissions</a>
                    <a href="#first-login">Your First Login</a>
                    <a href="#whats-new">What's New in 2.6.1</a>
                    <a href="#language">Changing Your Language</a>
                    <a href="#question-types">Phone &amp; VAT Question Types</a>

                    <h4>GRC — Quick Start</h4>
                    <a href="#grc-overview">What is GRC?</a>
                    <a href="#grc-getting-started">Getting Started</a>
                    <a href="#grc-step1">Step 1: Create Assessment</a>
                    <a href="#grc-step2">Step 2: Answer Questions</a>
                    <a href="#grc-step3">Step 3: Upload Evidence</a>
                    <a href="#grc-step4">Step 4: View Scores</a>
                    <a href="#grc-step5">Step 5: Generate Report</a>

                    <h4>GRC — Features</h4>
                    <a href="#grc-fairscore">CSF Maturity Score</a>
                    <a href="#grc-gaps">Gap Analysis</a>
                    <a href="#grc-frameworks">Frameworks</a>
                    <a href="#grc-controls">Internal Controls</a>
                    <a href="#grc-crosswalk">Framework Crosswalk</a>
                    <a href="#grc-evidence">Evidence Library</a>
                    <a href="#grc-policies">Policy Management</a>
                    <a href="#grc-audits">Audits &amp; Findings</a>
                    <a href="#grc-risks">Risk Register</a>
                    <a href="#grc-monitors">Continuous Monitors</a>
                    <a href="#grc-tasks">Task Inbox</a>
                    <a href="#grc-dashboard">GRC Dashboard</a>

                    <h4>TPRM Module</h4>
                    <a href="#tprm-overview">What is TPRM?</a>
                    <a href="#tprm-add-vendor">Adding a Vendor</a>
                    <a href="#tprm-lifecycle">Vendor Lifecycle</a>
                    <a href="#tprm-assessments">Vendor Assessments</a>
                    <a href="#tprm-srs">Security Risk Scorecard</a>
                    <a href="#tprm-fair">FAIR Analysis</a>
                    <a href="#tprm-fourth-party">4th Party Risk</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>Onboarding &amp; Procurement</h4>
                    <a href="#onboarding-workflow">Vendor Onboarding</a>
                    <a href="#ai-review">AI Review</a>
                    <a href="#procurement-cyber-status">Procurement Cyber Status</a>
                    <a href="#shadow-saas-grip">Grip Integration</a>
                    <a href="#zscaler">Zscaler Blocking</a>

                    <h4>Admin Portal</h4>
                    <a href="#admin-general">General Settings</a>
                    <a href="#admin-branding">Branding &amp; Theme</a>
                    <a href="#admin-users">User Management</a>
                    <a href="#admin-email">Email Configuration</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">AI Integration</a>
                    <a href="#admin-backup">Large Database Backups</a>
                    <a href="#admin-updates">Updating the Platform</a>

                    <h4>Help &amp; Reference</h4>
                    <a href="#faq">FAQ</a>
                    <a href="#troubleshooting">Troubleshooting</a>
                    <a href="#glossary">Glossary</a>
                </nav>

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
                { label: '1.5 What’s New in Version 2.6.1', id: 'whats-new' },
                { label: '1.6 Changing Your Language', id: 'language' }
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
                { label: '4.5 Security Risk Scorecard', id: 'tprm-srs' },
                { label: '4.6 FAIR Analysis', id: 'tprm-fair' },
                { label: '4.7 4th Party Risk', id: 'tprm-fourth-party' },
                { label: '4.8 Shadow SaaS Discovery', id: 'tprm-shadow-saas' }
            ]},
            { heading: '5. Onboarding & Procurement (New in 2.6.1)', entries: [
                { label: '5.1 Vendor Onboarding & Procurement Onboarding', id: 'onboarding-workflow' },
                { label: '5.2 AI Review for Vendors', id: 'ai-review' },
                { label: '5.3 Procurement Cyber Status', id: 'procurement-cyber-status' },
                { label: '5.4 Grip Security Integration', id: 'shadow-saas-grip' },
                { label: '5.5 Zscaler Blocking Integration', id: 'zscaler' }
            ]},
            { heading: '6. Admin Portal', entries: [
                { label: '6.1 General Settings', id: 'admin-general' },
                { label: '6.2 Branding & Theme', id: 'admin-branding' },
                { label: '6.3 User Management', id: 'admin-users' },
                { label: '6.4 Email Configuration', id: 'admin-email' },
                { label: '6.5 SAML / SSO', id: 'admin-saml' },
                { label: '6.6 AI Integration', id: 'admin-ai' },
                { label: '6.7 Updating the Platform', id: 'admin-updates' }
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

        // Scroll to section on TOC link click
        tocLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var targetId = this.getAttribute('href').substring(1);
                var target = document.getElementById(targetId);
                if (target) {
                    // scrollIntoView works regardless of which ancestor is the scroll container
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    setTimeout(function() {
                        history.replaceState(null, '', '#' + targetId);
                    }, 100);
                }
            });
        });

        // Scroll-spy: highlight active section
        function updateActiveToc() {
            var offset = 100;
            var activeId = null;

            for (var i = sectionIds.length - 1; i >= 0; i--) {
                var el = document.getElementById(sectionIds[i]);
                if (el) {
                    var elTop = el.getBoundingClientRect().top;
                    if (mainContent) {
                        elTop = elTop - mainContent.getBoundingClientRect().top;
                    }
                    if (elTop <= offset) {
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

        if (mainContent) {
            mainContent.addEventListener('scroll', updateActiveToc);
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
