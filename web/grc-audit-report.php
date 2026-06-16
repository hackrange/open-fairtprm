<?php
/**
 * GRC Audit Report - Generate, Edit, and Save as PDF
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Full-page report editor: calls grc-generate-report.php to produce editable
 * report sections, allows in-place editing, then saves as encrypted PDF via
 * grc-report-save.php. Supports download and deletion of stored reports.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

// GRC access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die(t('grc-audit-report.access_denied'));
}

$auditId = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : 0;
if ($auditId <= 0) {
    header('Location: grc-audits.php');
    exit;
}

// Load audit details
$auditDetail = $db->fetchOne(
    'SELECT a.*, f.code as framework_code, f.name as framework_name,
            u.full_name as lead_auditor_name, gs.name as scope_name
     FROM grc_audits a
     LEFT JOIN grc_frameworks f ON f.id = a.framework_id
     LEFT JOIN users u ON u.id = a.lead_auditor_user_id
     LEFT JOIN grc_scopes gs ON gs.id = a.scope_id
     WHERE a.id = :id',
    [':id' => $auditId]
);

if (!$auditDetail) {
    header('Location: grc-audits.php');
    exit;
}

$hasReport = !empty($auditDetail['report_file_name']);
$csrfToken = $security->generateCSRFToken();

$currentPage = 'grc_audits';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Audit Report - <?php echo e($auditDetail['audit_ref']); ?> - GRC Compliance Engine</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style nonce="<?php echo cspNonce(); ?>">
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        a { text-decoration: none; }
        a:hover { text-decoration: none; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }
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
        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-info { background: #3b82f6; color: #fff !important; }
        .btn-info:hover { opacity: 0.9; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { opacity: 0.9; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        /* Action toolbar */
        .report-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 24px; padding: 16px 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .report-toolbar .toolbar-title { font-size: 15px; font-weight: 600; color: #333; margin-right: auto; }

        /* Report sections */
        .report-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
        .report-section-header { display: flex; align-items: center; gap: 10px; padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; cursor: pointer; user-select: none; }
        .report-section-header h3 { margin: 0; font-size: 15px; color: #1e293b; flex: 1; font-family: Arial, Helvetica, sans-serif; }
        .report-section-header .toggle-icon { font-size: 14px; color: #6b7280; transition: transform 0.2s; width: 20px; text-align: center; }
        .report-section-header .toggle-icon.collapsed { transform: rotate(-90deg); }
        .report-section-body { padding: 20px 24px; }
        .report-section-body.hidden { display: none; }

        /* Editable content styling - professional report look */
        .editable-content {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13.5px;
            line-height: 1.7;
            color: #1e293b;
            min-height: 60px;
            outline: none;
            border: 2px solid transparent;
            border-radius: 4px;
            padding: 8px;
            transition: border-color 0.2s;
        }
        .editable-content:focus {
            border-color: #93c5fd;
            background: #f8fafc;
        }
        .editable-content p { margin: 0 0 10px; }
        .editable-content table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 12px 0; }
        .editable-content table th { background: #f3f4f6; padding: 6px 8px; text-align: left; border: 1px solid #e5e7eb; font-family: 'Roboto', sans-serif; font-size: 11px; }
        .editable-content table td { padding: 6px 8px; border: 1px solid #e5e7eb; font-family: 'Roboto', sans-serif; font-size: 11px; }
        .editable-content ul, .editable-content ol { padding-left: 24px; margin: 8px 0; }
        .editable-content li { margin-bottom: 4px; }
        .editable-content h4 { font-size: 14px; color: #1e40af; margin: 16px 0 8px; }
        .editable-content strong { font-weight: 700; }

        /* Loading overlay */
        .loading-overlay { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 80px 20px; color: #6b7280; }
        .loading-overlay .spinner { width: 40px; height: 40px; border: 4px solid #e5e7eb; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .spinner-sm { display: inline-block; width: 14px; height: 14px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; vertical-align: middle; margin-right: 4px; }

        /* Print styles */
        @media print {
            .sidebar, .top-bar, .report-toolbar, .back-link { display: none !important; }
            .main-layout { display: block !important; }
            .main-content { padding: 0 !important; background: #fff !important; }
            .report-section { border: none !important; margin-bottom: 8px !important; break-inside: avoid; }
            .report-section-header { background: none !important; border-bottom: 2px solid #333 !important; padding: 8px 0 !important; }
            .report-section-body { padding: 12px 0 !important; }
            .report-section-body.hidden { display: block !important; }
            .editable-content { border: none !important; padding: 0 !important; }
        }

        @media (max-width: 900px) {
            .report-toolbar { flex-direction: column; align-items: stretch; }
            .report-toolbar .toolbar-title { margin-right: 0; margin-bottom: 8px; }
        }
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
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
            <a href="grc-audits.php?view=<?php echo (int)$auditId; ?>" class="back-link">&larr; <?php echo e(t('grc-audit-report.back_to_audit')); ?></a>

            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-audit-report.audit_report')); ?></h1>
            <p style="color:#6b7280;margin:0 0 20px;"><?php echo e($auditDetail['audit_ref']); ?> &mdash; <?php echo e($auditDetail['title']); ?> &mdash; <?php echo e($auditDetail['framework_name'] ?? $auditDetail['framework_code']); ?></p>

            <div id="alertContainer"></div>

            <div class="report-toolbar">
                <span class="toolbar-title"><?php echo e(t('grc-audit-report.report_editor')); ?></span>
                <a href="grc-audits.php?view=<?php echo (int)$auditId; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc-audit-report.back_to_audit')); ?></a>
                <button type="button" class="btn btn-sm btn-primary" id="btnSavePdf" data-action="save-pdf" disabled><?php echo e(t('grc-audit-report.save_as_pdf')); ?></button>
                <button type="button" class="btn btn-sm btn-outline" id="btnDownload" data-action="download-report" style="<?php echo $hasReport ? '' : 'display:none;'; ?>background:#f0fdf4;border-color:#22c55e;color:#15803d;"><?php echo e(t('grc-audit-report.download_latest')); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="btnDelete" data-action="delete-report" style="<?php echo $hasReport ? '' : 'display:none;'; ?>"><?php echo e(t('grc-audit-report.delete_report')); ?></button>
                <button type="button" class="btn btn-sm btn-outline" data-action="print-report"><?php echo e(t('grc-audit-report.print')); ?></button>
                <button type="button" class="btn btn-sm btn-outline" data-action="expand-all"><?php echo e(t('grc-audit-report.expand_all')); ?></button>
                <button type="button" class="btn btn-sm btn-outline" data-action="collapse-all"><?php echo e(t('grc-audit-report.collapse_all')); ?></button>
            </div>

            <div id="reportContainer">
                <div class="loading-overlay" id="loadingOverlay">
                    <div class="spinner"></div>
                    <div><?php echo e(t('grc-audit-report.generating_sections')); ?></div>
                    <div style="font-size:12px;margin-top:4px;color:#9ca3af;"><?php echo e(t('grc-audit-report.may_take_moment')); ?></div>
                </div>
            </div>

        </main>
    </div>
    <!-- Footer -->
    <footer class="section footer-modern bg-gray-13">
        <div class="footer-modern-body">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($theme['footer_logo_url'])): ?>
                        <a class="brand" href="index.php">
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc-audit-report.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var _auditId = <?php echo json_encode($auditId); ?>;
    var _csrfToken = <?php echo json_encode($csrfToken); ?>;
    var _hasReport = <?php echo json_encode($hasReport); ?>;
    var _sectionKeys = [];
    var _saving = false;

    // Show alert message
    function showAlert(msg, type) {
        var container = document.getElementById('alertContainer');
        var div = document.createElement('div');
        div.className = 'alert alert-' + (type || 'info');
        div.textContent = msg;
        container.innerHTML = '';
        container.appendChild(div);
        if (type !== 'danger') {
            setTimeout(function() { if (div.parentNode) div.parentNode.removeChild(div); }, 6000);
        }
    }

    // Load report sections via API
    function loadReport() {
        fetch('api/grc-generate-report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ audit_id: _auditId, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            if (data.error) {
                showAlert(data.error, 'danger');
                document.getElementById('loadingOverlay').innerHTML = '<div style="color:#991b1b;">Failed to generate report: ' + escapeHtml(data.error) + '</div>';
                return;
            }
            if (!data.success || !data.report) {
                showAlert('Failed to generate report.', 'danger');
                return;
            }
            renderSections(data.report, data.framework_name);
            // If AI sections were queued, poll and merge when ready
            if (data.ai_job_id) {
                (function pollJob() {
                    setTimeout(function() {
                        fetch('api/ai-job-status.php?id=' + data.ai_job_id).then(function(r) { return r.json(); }).then(function(poll) {
                            if (poll.csrf_token) _csrfToken = poll.csrf_token;
                            if (poll.status === 'completed' && poll.result && poll.result.sections) {
                                // Merge AI sections into the report
                                var merged = Object.assign({}, data.report, poll.result.sections);
                                renderSections(merged, data.framework_name);
                            } else if (poll.status !== 'failed') { pollJob(); }
                        }).catch(function() {});
                    }, 3000);
                })();
            }
        })
        .catch(function(err) {
            showAlert('Network error loading report: ' + err.message, 'danger');
            document.getElementById('loadingOverlay').innerHTML = '<div style="color:#991b1b;">Network error. Please try again.</div>';
        });
    }

    // Render editable sections
    function renderSections(report, frameworkName) {
        var container = document.getElementById('reportContainer');
        container.innerHTML = '';
        _sectionKeys = [];

        var sectionNumber = 0;
        // Enforce section order — disclaimer always first
        var preferredOrder = ['disclaimer', 'opinion', 'assertion', 'system_description', 'control_environment', 'criteria_controls', 'controls_matrix', 'findings', 'complementary_criteria', 'summary', 'compliance'];
        var allKeys = Object.keys(report);
        var keys = [];
        for (var p = 0; p < preferredOrder.length; p++) {
            if (report[preferredOrder[p]]) keys.push(preferredOrder[p]);
        }
        for (var a = 0; a < allKeys.length; a++) {
            if (keys.indexOf(allKeys[a]) === -1) keys.push(allKeys[a]);
        }
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var section = report[key];
            if (!section || !section.title) continue;
            sectionNumber++;
            _sectionKeys.push(key);

            var wrapper = document.createElement('div');
            wrapper.className = 'report-section';
            wrapper.setAttribute('data-section-key', key);

            var header = document.createElement('div');
            header.className = 'report-section-header';
            header.setAttribute('data-action', 'toggle-section');

            var toggleIcon = document.createElement('span');
            toggleIcon.className = 'toggle-icon';
            toggleIcon.textContent = '\u25BC';

            var h3 = document.createElement('h3');
            h3.textContent = section.title;

            header.appendChild(toggleIcon);
            header.appendChild(h3);

            var body = document.createElement('div');
            body.className = 'report-section-body';

            var editable = document.createElement('div');
            editable.className = 'editable-content';
            editable.setAttribute('contenteditable', 'true');
            editable.setAttribute('data-section-key', key);
            editable.innerHTML = section.content || '<p>[No content generated for this section]</p>';

            body.appendChild(editable);
            wrapper.appendChild(header);
            wrapper.appendChild(body);
            container.appendChild(wrapper);
        }

        // Enable save button
        document.getElementById('btnSavePdf').disabled = false;
    }

    // Collect edited sections
    function collectSections() {
        var sections = [];
        for (var i = 0; i < _sectionKeys.length; i++) {
            var key = _sectionKeys[i];
            var wrapper = document.querySelector('[data-section-key="' + key + '"].report-section');
            if (!wrapper) continue;
            var h3 = wrapper.querySelector('h3');
            var editable = wrapper.querySelector('.editable-content');
            sections.push({
                key: key,
                title: h3 ? h3.textContent : key,
                content: editable ? editable.innerHTML : ''
            });
        }
        return sections;
    }

    // Save as PDF
    function savePdf() {
        if (_saving) return;
        _saving = true;
        var btn = document.getElementById('btnSavePdf');
        var originalText = btn.textContent;
        btn.innerHTML = '<span class="spinner-sm"></span> Saving...';
        btn.disabled = true;

        var sections = collectSections();

        fetch('api/grc-report-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: _csrfToken,
                audit_id: _auditId,
                sections: sections
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            if (data.success) {
                showAlert(data.message || 'Report saved successfully.', 'success');
                _hasReport = true;
                document.getElementById('btnDownload').style.display = '';
                document.getElementById('btnDelete').style.display = '';
            } else {
                showAlert(data.error || 'Failed to save report.', 'danger');
            }
        })
        .catch(function(err) {
            showAlert('Network error saving report: ' + err.message, 'danger');
        })
        .finally(function() {
            _saving = false;
            btn.textContent = originalText;
            btn.disabled = false;
        });
    }

    // Delete report
    function deleteReport() {
        if (!confirm('Delete the saved PDF report? This cannot be undone.')) return;

        fetch('api/grc-report-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: _csrfToken,
                audit_id: _auditId,
                action: 'delete'
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_token) _csrfToken = data.csrf_token;
            if (data.success) {
                showAlert('Report deleted.', 'success');
                _hasReport = false;
                document.getElementById('btnDownload').style.display = 'none';
                document.getElementById('btnDelete').style.display = 'none';
            } else {
                showAlert(data.error || 'Failed to delete report.', 'danger');
            }
        })
        .catch(function(err) {
            showAlert('Network error: ' + err.message, 'danger');
        });
    }

    // Escape HTML for safe insertion
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Event delegation (CSP-safe, no inline handlers)
    document.addEventListener('click', function(e) {
        var target = e.target.closest('[data-action]');
        if (!target) return;

        var action = target.getAttribute('data-action');

        if (action === 'toggle-section') {
            var header = target.closest('.report-section-header');
            if (!header) return;
            var body = header.nextElementSibling;
            var icon = header.querySelector('.toggle-icon');
            if (body) {
                var isHidden = body.classList.toggle('hidden');
                if (icon) {
                    icon.classList.toggle('collapsed', isHidden);
                }
            }
        }

        if (action === 'save-pdf') {
            savePdf();
        }

        if (action === 'download-report') {
            window.location.href = 'api/grc-report-download.php?audit_id=' + _auditId;
        }

        if (action === 'delete-report') {
            deleteReport();
        }

        if (action === 'print-report') {
            window.print();
        }

        if (action === 'expand-all') {
            var bodies = document.querySelectorAll('.report-section-body');
            var icons = document.querySelectorAll('.toggle-icon');
            for (var i = 0; i < bodies.length; i++) {
                bodies[i].classList.remove('hidden');
            }
            for (var j = 0; j < icons.length; j++) {
                icons[j].classList.remove('collapsed');
            }
        }

        if (action === 'collapse-all') {
            var bodies = document.querySelectorAll('.report-section-body');
            var icons = document.querySelectorAll('.toggle-icon');
            for (var i = 0; i < bodies.length; i++) {
                bodies[i].classList.add('hidden');
            }
            for (var j = 0; j < icons.length; j++) {
                icons[j].classList.add('collapsed');
            }
        }
    });

    // Load on page ready
    loadReport();
})();
</script>
</body>
</html>
