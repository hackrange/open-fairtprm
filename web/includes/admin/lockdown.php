<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Lockdown (WAF / ModSecurity Management)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Positive security model WAF — learn legitimate traffic, generate a whitelist,
 * then enforce it. Anything not in the whitelist gets blocked.
 *
 * NOTE: All action forms use a hidden <input name="waf_action"> instead of
 * relying on button name= attributes. The data-disable-on-click JS handler
 * disables the button before form.submit(), and disabled buttons don't send
 * their name in the POST data.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

require_once __DIR__ . '/../classes/LockdownService.php';

$lockdown = new LockdownService($db);
$modSecAvailable = $lockdown->isModSecurityAvailable();

// ============================================================================
// POST Handlers
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wafAction = $_POST['waf_action'] ?? '';
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_lockdown.msg_invalid_request');
    } elseif (!$modSecAvailable) {
        $error = t('admin_lockdown.msg_modsec_unavailable_op');
    } elseif ($wafAction === 'start_learning') {
        if ($lockdown->setMode('learning')) {
            $success = t('admin_lockdown.msg_learning_activated');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'learning'],
            ]);
        } else {
            $error = t('admin_lockdown.msg_learning_activate_failed');
        }
    } elseif ($wafAction === 'stop_learning') {
        if ($lockdown->setMode('disabled')) {
            $success = t('admin_lockdown.msg_learning_stopped');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = t('admin_lockdown.msg_learning_stop_failed');
        }
    } elseif ($wafAction === 'ingest_logs') {
        $count = $lockdown->ingestAuditLog();
        $success = t('admin_lockdown.msg_ingested', $count);
        $auth->audit($user['id'], 'waf_ingest_logs', 'waf_learned_patterns', null, [
            'new' => ['patterns_ingested' => $count],
        ]);
    } elseif ($wafAction === 'generate_whitelist') {
        $count = $lockdown->generateWhitelistRules();
        $success = t('admin_lockdown.msg_generated_whitelist', $count);
        $auth->audit($user['id'], 'waf_generate_whitelist', 'waf_whitelist_rules', null, [
            'new' => ['rules_generated' => $count],
        ]);
    } elseif ($wafAction === 'enable_enforcement') {
        if ($lockdown->setMode('enforcing')) {
            $success = t('admin_lockdown.msg_enforcement_enabled');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'enforcing'],
            ]);
        } else {
            $error = t('admin_lockdown.msg_enforcement_failed');
        }
    } elseif ($wafAction === 'disable_waf') {
        if ($lockdown->setMode('disabled')) {
            $success = t('admin_lockdown.msg_waf_disabled');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = t('admin_lockdown.msg_waf_disable_failed');
        }
    } elseif ($wafAction === 'add_exception') {
        $exUri = trim($_POST['exception_uri'] ?? '');
        $exMethod = strtoupper(trim($_POST['exception_method'] ?? 'GET'));
        if (empty($exUri)) {
            $error = t('admin_lockdown.msg_uri_required');
        } else {
            if ($lockdown->addException($exUri, $exMethod)) {
                $success = t('admin_lockdown.msg_exception_added', $exMethod, $exUri);
                $auth->audit($user['id'], 'waf_add_exception', 'waf_whitelist_rules', null, [
                    'new' => ['uri' => $exUri, 'method' => $exMethod],
                ]);
            } else {
                $error = t('admin_lockdown.msg_exception_add_failed');
            }
        }
    } elseif ($wafAction === 'remove_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0 && $lockdown->removeRule($ruleId)) {
            $success = t('admin_lockdown.msg_rule_removed', $ruleId);
            $auth->audit($user['id'], 'waf_remove_rule', 'waf_whitelist_rules', null, [
                'new' => ['rule_id' => $ruleId],
            ]);
        } else {
            $error = t('admin_lockdown.msg_rule_remove_failed');
        }
    } elseif ($wafAction === 'clear_patterns') {
        if ($lockdown->clearLearnedPatterns()) {
            $success = t('admin_lockdown.msg_patterns_cleared');
            $auth->audit($user['id'], 'waf_clear_patterns', 'waf_learned_patterns', null, []);
        } else {
            $error = t('admin_lockdown.msg_patterns_clear_failed');
        }
    } elseif ($wafAction === 'export_rules') {
        $export = $lockdown->exportRules();
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename = 'waf-rules-' . date('Y-m-d-His') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    } elseif ($wafAction === 'toggle_scanner_blocking') {
        $scannerEnabled = $lockdown->isScannerBlockingEnabled();
        $newState = !$scannerEnabled;
        if ($lockdown->setScannerBlocking($newState)) {
            $success = $newState
                ? t('admin_lockdown.msg_scanner_enabled')
                : t('admin_lockdown.msg_scanner_disabled');
            $auth->audit($user['id'], 'waf_scanner_blocking', 'app_config', null, [
                'new' => ['scanner_blocking' => $newState ? 'enabled' : 'disabled'],
            ]);
        } else {
            $error = t('admin_lockdown.msg_scanner_toggle_failed');
        }
    } elseif ($wafAction === 'import_rules') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error = t('admin_lockdown.msg_no_file_uploaded');
        } else {
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['rules'])) {
                $error = t('admin_lockdown.msg_invalid_import');
            } else {
                [$rulesImported, $patternsImported] = $lockdown->importRules($data);
                $success = t('admin_lockdown.msg_imported', $rulesImported, $patternsImported);
                $auth->audit($user['id'], 'waf_import_rules', 'waf_whitelist_rules', null, [
                    'new' => ['rules' => $rulesImported, 'patterns' => $patternsImported],
                ]);
            }
        }

    } elseif ($wafAction === 'add_whitelisted_ip') {
        $ip = trim($_POST['whitelist_ip'] ?? '');
        $label = trim($_POST['whitelist_label'] ?? '');
        if ($ip === '') {
            $error = t('admin_lockdown.msg_ip_required');
        } elseif ($lockdown->addWhitelistedIP($ip, $label)) {
            $success = t('admin_lockdown.msg_ip_added', $ip);
            $auth->audit($user['id'], 'waf_ip_whitelist_add', 'waf_ip_whitelist', null, [
                'new' => ['ip' => $ip, 'label' => $label],
            ]);
        } else {
            $error = t('admin_lockdown.msg_ip_add_failed');
        }

    } elseif ($wafAction === 'remove_whitelisted_ip') {
        $ipId = (int)($_POST['whitelist_ip_id'] ?? 0);
        if ($ipId > 0 && $lockdown->removeWhitelistedIP($ipId)) {
            $success = t('admin_lockdown.msg_ip_removed');
            $auth->audit($user['id'], 'waf_ip_whitelist_remove', 'waf_ip_whitelist', null, [
                'new' => ['id' => $ipId],
            ]);
        } else {
            $error = t('admin_lockdown.msg_ip_remove_failed');
        }

    } elseif ($wafAction === 'update_rate_limit') {
        $rlEnabled = isset($_POST['rl_enabled']);
        $rlRequests = (int)($_POST['rl_requests'] ?? 100);
        $rlPeriod = (int)($_POST['rl_period'] ?? 60);
        $rlBlock = (int)($_POST['rl_block'] ?? 300);
        if ($lockdown->updateRateLimitSettings($rlEnabled, $rlRequests, $rlPeriod, $rlBlock)) {
            $success = $rlEnabled
                ? t('admin_lockdown.msg_rate_limit_enabled', $rlRequests, $rlPeriod, $rlBlock)
                : t('admin_lockdown.msg_rate_limit_disabled');
            $auth->audit($user['id'], 'waf_rate_limit', 'app_config', null, [
                'new' => ['enabled' => $rlEnabled, 'requests' => $rlRequests, 'period' => $rlPeriod, 'block' => $rlBlock],
            ]);
        } else {
            $error = t('admin_lockdown.msg_rate_limit_failed');
        }

    } elseif ($wafAction === 'clear_block_log') {
        try {
            $db->query("DELETE FROM waf_block_events");
            // Keep the ingest offset so cleared entries aren't re-ingested from the error log
            $logFile = '/var/log/apache2/error.log';
            if (is_readable($logFile)) {
                file_put_contents('/tmp/.waf_block_ingest_offset', (string)filesize($logFile));
            }
            $success = t('admin_lockdown.msg_block_log_cleared');
            $auth->audit($user['id'], 'waf_clear_block_log', 'waf_block_events', null, []);
        } catch (Exception $e) {
            $error = t('admin_lockdown.msg_block_log_clear_failed');
        }

    } elseif ($wafAction === 'export_block_log') {
        $lockdown->ingestBlockLog();
        try {
            $rows = $db->fetchAll(
                "SELECT blocked_at, client_ip, uri, method, rule_id, rule_message, request_headers, user_agent, resolved
                 FROM waf_block_events ORDER BY blocked_at DESC"
            );
        } catch (Exception $e) {
            $rows = [];
        }
        $filename = 'waf-block-log-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Blocked At', 'Client IP', 'URI', 'Method', 'Rule ID', 'Rule Message', 'Tag', 'Match Detail', 'Hostname', 'Rule File', 'User Agent', 'Resolved']);
        foreach ($rows as $row) {
            $details = json_decode($row['request_headers'] ?? '{}', true) ?: [];
            fputcsv($out, [
                $row['blocked_at'],
                $row['client_ip'],
                $row['uri'],
                $row['method'],
                $row['rule_id'],
                $row['rule_message'],
                $details['tag'] ?? '',
                $details['match_detail'] ?? '',
                $details['hostname'] ?? '',
                $details['file'] ?? '',
                $row['user_agent'] ?? '',
                $row['resolved'] ? 'Yes' : 'No',
            ]);
        }
        fclose($out);
        exit;
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$status = $lockdown->getStatus();
$scannerBlocking = $lockdown->isScannerBlockingEnabled();

// Count total intercepts since last Apache restart (from error log)
// error.log is chown root:www-data in entrypoint — www-data is NOT in the adm group
// is_readable() fails here (open_basedir), AND exec() is disabled by the web
// hardening (99-hardening.ini). proc_open IS permitted (as LockdownService uses
// it), so shell out via proc_open to count blocks from the Apache error log.
$interceptCount = 0;
$_icDesc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$_icProc = @proc_open('grep -c "ModSecurity: Access denied" /var/log/apache2/error.log 2>/dev/null', $_icDesc, $_icPipes);
if (is_resource($_icProc)) {
    $_icOut = stream_get_contents($_icPipes[1]);
    fclose($_icPipes[1]);
    fclose($_icPipes[2]);
    proc_close($_icProc);
    $interceptCount = (int)trim($_icOut);
}

$ipFilter = trim($_GET['ip'] ?? '');
$detailUri = $_GET['detail_uri'] ?? '';
$detailMethod = $_GET['detail_method'] ?? '';
$isDetailView = ($detailUri !== '');

$patterns = $lockdown->getLearnedPatterns(500, $ipFilter);
$clientIPs = $lockdown->getDistinctClientIPs();
$rules = $lockdown->getWhitelistRules();
$blocks = $lockdown->getRecentBlocks(0);
$whitelistedIPs = $lockdown->getWhitelistedIPs();
$rateLimitSettings = $lockdown->getRateLimitSettings();

// Detail view: load individual requests for the selected pattern
$detailRequests = [];
if ($isDetailView) {
    $detailRequests = $lockdown->getRequestsForPattern($detailUri, $detailMethod, $ipFilter);
}

// Mode badge config
$modeBadges = [
    'disabled'  => ['label' => t('admin_lockdown.label_disabled'),  'bg' => '#f3f4f6', 'color' => '#6b7280'],
    'learning'  => ['label' => t('admin_lockdown.label_learning'),  'bg' => '#fef3c7', 'color' => '#92400e'],
    'enforcing' => ['label' => t('admin_lockdown.label_enforcing'), 'bg' => '#dcfce7', 'color' => '#166534'],
];
$badge = $modeBadges[$status['mode']] ?? $modeBadges['disabled'];

// Helper to build lockdown URLs preserving the IP filter
$lockdownUrl = function(array $extra = []) use ($ipFilter) {
    $params = ['section' => 'lockdown'];
    if ($ipFilter !== '') $params['ip'] = $ipFilter;
    return 'admin.php?' . http_build_query(array_merge($params, $extra));
};

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_lockdown.page_title')); ?></h1>
    <p><?php echo e(t('admin_lockdown.page_subtitle')); ?></p>
</div>

<?php if (!$modSecAvailable): ?>
<div class="alert alert-danger">
    <?php echo t('admin_lockdown.modsec_unavailable_alert'); ?>
</div>
<?php endif; ?>

<?php if ($isDetailView): ?>
<!-- ======================================================================= -->
<!-- DETAIL VIEW: Individual requests for a pattern                          -->
<!-- ======================================================================= -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0;"><?php echo e(t('admin_lockdown.request_detail_heading')); ?></h3>
            <p style="color: #666; margin: 5px 0 0; font-size: 13px;">
                <?php echo t('admin_lockdown.individual_requests_for', e($detailMethod), e($detailUri)); ?>
                <?php if ($ipFilter !== ''): ?>
                    <?php echo t('admin_lockdown.from_ip_detail', e($ipFilter)); ?>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?php echo e($lockdownUrl()); ?>" class="btn btn-secondary">&laquo; <?php echo e(t('admin_lockdown.back_to_patterns')); ?></a>
    </div>

    <!-- IP Filter for detail view -->
    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="detail-ip-filter" style="font-size: 12px; color: #666; white-space: nowrap;"><?php echo e(t('admin_lockdown.filter_by_ip')); ?></label>
        <select id="detail-ip-filter" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;"
                data-url-with-ip="<?php echo e($lockdownUrl(['detail_uri' => $detailUri, 'detail_method' => $detailMethod])); ?>&ip="
                data-url-no-ip="<?php echo e('admin.php?' . http_build_query(['section' => 'lockdown', 'detail_uri' => $detailUri, 'detail_method' => $detailMethod])); ?>">
            <option value=""><?php echo e(t('admin_lockdown.all_ips')); ?></option>
            <?php foreach ($clientIPs as $cip): ?>
            <option value="<?php echo e($cip['client_ip']); ?>" <?php echo $ipFilter === $cip['client_ip'] ? 'selected' : ''; ?>>
                <?php echo e($cip['client_ip']); ?> (<?php echo number_format((int)$cip['cnt']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($detailRequests)): ?>
        <p style="color: #999;"><?php echo e(t('admin_lockdown.no_individual_requests')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 10px; font-size: 13px;"><?php echo e(t('admin_lockdown.requests_recorded', number_format(count($detailRequests)))); ?></p>

        <!-- Pagination controls (top) -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <label for="dr-page-size" style="font-size: 12px; color: #666; white-space: nowrap;"><?php echo e(t('admin_lockdown.show_label')); ?></label>
                <select id="dr-page-size" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="500">500</option>
                </select>
            </div>
            <div id="dr-pager-top" style="display: flex; align-items: center; gap: 4px; font-size: 13px; color: #666;">
                <span id="dr-info-top"></span>
                <button type="button" id="dr-prev-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; <?php echo e(t('admin_lockdown.btn_prev')); ?></button>
                <span id="dr-pages-top" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="dr-next-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;"><?php echo e(t('admin_lockdown.btn_next')); ?> &raquo;</button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="dr-table">
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php echo e(t('admin_lockdown.col_timestamp')); ?></th>
                        <th style="width: 120px;"><?php echo e(t('admin_lockdown.col_client_ip')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_user_agent')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_query_string')); ?></th>
                        <th style="width: 100px;"><?php echo e(t('admin_lockdown.col_content_type')); ?></th>
                        <th style="width: 60px;"><?php echo e(t('admin_lockdown.col_status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailRequests as $req): ?>
                    <tr class="dr-row">
                        <td style="font-size: 12px; white-space: nowrap;"><?php echo e(date('M j, g:i:s A', strtotime($req['request_at']))); ?></td>
                        <td style="font-family: monospace; font-size: 12px;">
                            <a href="<?php echo e($lockdownUrl(['ip' => $req['client_ip']])); ?>" style="color: #1e40af; text-decoration: none;" title="<?php echo e(t('admin_lockdown.filter_patterns_by_ip')); ?>">
                                <?php echo e($req['client_ip']); ?>
                            </a>
                        </td>
                        <td style="font-size: 11px; color: #666; max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($req['user_agent']); ?>">
                            <?php echo e($req['user_agent'] ?: '—'); ?>
                        </td>
                        <td style="font-family: monospace; font-size: 11px; color: #666; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($req['query_string'] ?? ''); ?>">
                            <?php echo e($req['query_string'] ?: '—'); ?>
                        </td>
                        <td style="font-size: 11px; color: #666;"><?php echo e($req['content_type'] ?: '—'); ?></td>
                        <td style="text-align: center;"><?php echo (int)$req['response_status']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination controls (bottom) -->
        <div id="dr-pager-bottom" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 13px; color: #666;">
            <span id="dr-info-bottom"></span>
            <div style="display: flex; gap: 4px;">
                <button type="button" id="dr-prev-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; <?php echo e(t('admin_lockdown.btn_prev')); ?></button>
                <span id="dr-pages-bottom" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="dr-next-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;"><?php echo e(t('admin_lockdown.btn_next')); ?> &raquo;</button>
            </div>
        </div>

        <script nonce="<?php echo cspNonce(); ?>">
        (function() {
            var rows = document.querySelectorAll('#dr-table .dr-row');
            var total = rows.length;
            var sizeEl = document.getElementById('dr-page-size');
            var page = 1;

            function render() {
                var size = parseInt(sizeEl.value, 10);
                var pages = Math.ceil(total / size);
                if (page > pages) page = pages;
                if (page < 1) page = 1;
                var start = (page - 1) * size;
                var end = Math.min(start + size, total);
                for (var i = 0; i < total; i++) rows[i].style.display = (i >= start && i < end) ? '' : 'none';
                var info = <?php echo json_encode(t('admin_lockdown.js_showing')); ?> + (start + 1) + '\u2013' + end + <?php echo json_encode(t('admin_lockdown.js_of')); ?> + total;
                ['top', 'bottom'].forEach(function(pos) {
                    document.getElementById('dr-info-' + pos).textContent = info;
                    document.getElementById('dr-prev-' + pos).disabled = page <= 1;
                    document.getElementById('dr-next-' + pos).disabled = page >= pages;
                    var pagesEl = document.getElementById('dr-pages-' + pos);
                    pagesEl.innerHTML = '';
                    var maxBtns = 7, sp = Math.max(1, page - 3), ep = Math.min(pages, sp + maxBtns - 1);
                    if (ep - sp < maxBtns - 1) sp = Math.max(1, ep - maxBtns + 1);
                    for (var p = sp; p <= ep; p++) {
                        var btn = document.createElement('button');
                        btn.type = 'button'; btn.textContent = p;
                        btn.className = 'btn btn-sm' + (p === page ? '' : ' btn-secondary');
                        btn.style.cssText = 'padding:3px 8px;min-width:32px;' + (p === page ? 'background:#1e40af;color:#fff;' : '');
                        btn.setAttribute('data-page', p);
                        btn.addEventListener('click', function() { page = parseInt(this.getAttribute('data-page'), 10); render(); });
                        pagesEl.appendChild(btn);
                    }
                });
            }
            sizeEl.addEventListener('change', function() { page = 1; render(); });
            ['top', 'bottom'].forEach(function(pos) {
                document.getElementById('dr-prev-' + pos).addEventListener('click', function() { if (page > 1) { page--; render(); } });
                document.getElementById('dr-next-' + pos).addEventListener('click', function() { page++; render(); });
            });
            render();

            // Detail view IP filter
            var detailIpFilter = document.getElementById('detail-ip-filter');
            if (detailIpFilter) {
                detailIpFilter.addEventListener('change', function() {
                    if (this.value) {
                        location.href = this.getAttribute('data-url-with-ip') + encodeURIComponent(this.value);
                    } else {
                        location.href = this.getAttribute('data-url-no-ip');
                    }
                });
            }
        })();
        </script>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ======================================================================= -->
<!-- MAIN VIEW: Status, Patterns, Rules, Block Log                           -->
<!-- ======================================================================= -->

<!-- Card 1: WAF Status -->
<div class="card">
    <h3><?php echo e(t('admin_lockdown.waf_status_heading')); ?></h3>

    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.current_mode')); ?></span><br>
            <span class="badge" style="background: <?php echo e($badge['bg']); ?>; color: <?php echo e($badge['color']); ?>; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;">
                <?php echo e($badge['label']); ?>
            </span>
        </div>

        <?php if ($status['mode'] === 'learning' && $status['learning_duration']): ?>
        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.learning_since')); ?></span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo e($status['learning_started']); ?></span>
            <span style="color: #666; font-size: 13px;">(<?php echo e($status['learning_duration']); ?>)</span>
        </div>
        <?php endif; ?>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.stat_patterns')); ?></span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo number_format($status['pattern_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.stat_rules')); ?></span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo number_format($status['rule_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.stat_unresolved_blocks')); ?></span><br>
            <span style="font-size: 14px; font-weight: 500; <?php echo $status['block_count'] > 0 ? 'color: #dc2626;' : ''; ?>"><?php echo number_format($status['block_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.stat_scanner_blocking')); ?></span><br>
            <?php if ($scannerBlocking): ?>
                <span class="badge" style="background: #dcfce7; color: #166534; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;"><?php echo e(t('admin_lockdown.label_enabled')); ?></span>
            <?php else: ?>
                <span class="badge" style="background: #fef3c7; color: #92400e; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;"><?php echo e(t('admin_lockdown.label_disabled')); ?></span>
            <?php endif; ?>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;"><?php echo e(t('admin_lockdown.stat_intercepts')); ?></span><br>
            <span style="font-size: 14px; font-weight: 500; <?php echo $interceptCount > 0 ? 'color: #dc2626;' : ''; ?>"><?php echo number_format($interceptCount); ?></span>
        </div>
    </div>

    <!-- Action Buttons (state-dependent) -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <?php if ($status['mode'] === 'disabled'): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="start_learning">
                <button type="submit" class="btn btn-primary" <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_starting')); ?>">
                    <?php echo e(t('admin_lockdown.btn_start_learning')); ?>
                </button>
            </form>
            <?php if ($status['rule_count'] > 0): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="enable_enforcement">
                <button type="submit" class="btn" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_enabling')); ?>">
                    <?php echo e(t('admin_lockdown.btn_enable_enforcement')); ?>
                </button>
            </form>
            <?php endif; ?>
        <?php elseif ($status['mode'] === 'learning'): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="ingest_logs">
                <button type="submit" class="btn btn-primary"
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_ingesting')); ?>">
                    <?php echo e(t('admin_lockdown.btn_ingest_logs')); ?>
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="stop_learning">
                <button type="submit" class="btn btn-secondary"
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_stopping')); ?>">
                    <?php echo e(t('admin_lockdown.btn_stop_learning')); ?>
                </button>
            </form>
        <?php elseif ($status['mode'] === 'enforcing'): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="disable_waf">
                <button type="submit" class="btn" style="background: #dc2626; color: white;"
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_disabling')); ?>"
                        onclick="return confirm('Disable WAF enforcement? All traffic will be allowed through.');">
                    <?php echo e(t('admin_lockdown.btn_disable_waf')); ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($status['pattern_count'] > 0): ?>
        <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="generate_whitelist">
            <button type="submit" class="btn" style="background: #1e40af; color: white;"
                    <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                    data-disable-on-click="<?php echo e(t('admin_lockdown.busy_generating')); ?>">
                <?php echo e(t('admin_lockdown.btn_generate_whitelist')); ?>
            </button>
        </form>
        <?php endif; ?>

        <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="toggle_scanner_blocking">
            <?php if ($scannerBlocking): ?>
                <button type="submit" class="btn" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_disabling')); ?>">
                    <?php echo e(t('admin_lockdown.btn_disable_scanner')); ?>
                </button>
            <?php else: ?>
                <button type="submit" class="btn" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_enabling')); ?>">
                    <?php echo e(t('admin_lockdown.btn_enable_scanner')); ?>
                </button>
            <?php endif; ?>
        </form>
    </div>

    <div style="margin-top: 15px; font-size: 12px; color: #999;">
        <?php echo t('admin_lockdown.workflow_hint'); ?>
    </div>
</div>

<!-- Card: Rate Limiting & IP Whitelist -->
<div class="card">
    <h3><?php echo t('admin_lockdown.rate_limit_ip_heading'); ?></h3>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Rate Limiting Settings -->
        <div>
            <h4 style="margin: 0 0 10px; font-size: 14px; color: #374151;"><?php echo e(t('admin_lockdown.rate_limiting_heading')); ?></h4>
            <p style="font-size: 12px; color: #666; margin-bottom: 12px;">
                <?php echo e(t('admin_lockdown.rate_limiting_desc')); ?>
            </p>
            <form method="POST" action="admin.php?section=lockdown">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="update_rate_limit">
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <input type="checkbox" name="rl_enabled" value="1" <?php echo $rateLimitSettings['enabled'] ? 'checked' : ''; ?>>
                        <?php echo e(t('admin_lockdown.enable_rate_limiting')); ?>
                    </label>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; align-items: center; font-size: 13px;">
                        <label><?php echo e(t('admin_lockdown.max_requests')); ?></label>
                        <input type="number" name="rl_requests" value="<?php echo (int)$rateLimitSettings['requests']; ?>" min="10" max="10000" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                        <label><?php echo e(t('admin_lockdown.time_window')); ?></label>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <input type="number" name="rl_period" value="<?php echo (int)$rateLimitSettings['period']; ?>" min="10" max="3600" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                            <span style="color: #666;"><?php echo e(t('admin_lockdown.seconds')); ?></span>
                        </div>
                        <label><?php echo e(t('admin_lockdown.block_duration')); ?></label>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <input type="number" name="rl_block" value="<?php echo (int)$rateLimitSettings['block_duration']; ?>" min="60" max="86400" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                            <span style="color: #666;"><?php echo e(t('admin_lockdown.seconds')); ?></span>
                        </div>
                    </div>
                    <div style="margin-top: 6px;">
                        <button type="submit" class="btn btn-sm btn-primary" <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                                data-disable-on-click="<?php echo e(t('admin_lockdown.busy_saving')); ?>">
                            <?php echo e(t('admin_lockdown.btn_save_rate_limit')); ?>
                        </button>
                    </div>
                </div>
            </form>
            <?php if ($rateLimitSettings['enabled']): ?>
            <div style="margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 12px; color: #166534;">
                <?php echo e(t('admin_lockdown.rate_limit_active', number_format($rateLimitSettings['requests']), $rateLimitSettings['period'], $rateLimitSettings['block_duration'])); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- IP Whitelist -->
        <div>
            <h4 style="margin: 0 0 10px; font-size: 14px; color: #374151;"><?php echo e(t('admin_lockdown.ip_whitelist_heading')); ?></h4>
            <p style="font-size: 12px; color: #666; margin-bottom: 12px;">
                <?php echo e(t('admin_lockdown.ip_whitelist_desc')); ?>
            </p>

            <!-- Add IP form -->
            <form method="POST" action="admin.php?section=lockdown" style="display: flex; gap: 8px; align-items: end; flex-wrap: wrap; margin-bottom: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="add_whitelisted_ip">
                <div>
                    <label style="font-size: 11px; color: #666; display: block; margin-bottom: 2px;"><?php echo e(t('admin_lockdown.ip_address_or_cidr')); ?></label>
                    <input type="text" name="whitelist_ip" required placeholder="<?php echo e(t('admin_lockdown.ip_placeholder')); ?>"
                           pattern="[0-9a-fA-F.:/]+" title="<?php echo e(t('admin_lockdown.ip_valid_title')); ?>"
                           style="width: 200px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; font-family: monospace;">
                </div>
                <div>
                    <label style="font-size: 11px; color: #666; display: block; margin-bottom: 2px;"><?php echo e(t('admin_lockdown.label_optional')); ?></label>
                    <input type="text" name="whitelist_label" placeholder="<?php echo e(t('admin_lockdown.label_placeholder')); ?>"
                           style="width: 160px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                </div>
                <button type="submit" class="btn btn-sm" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_adding')); ?>">
                    <?php echo e(t('admin_lockdown.btn_add_ip')); ?>
                </button>
            </form>

            <!-- IP list -->
            <?php if (empty($whitelistedIPs)): ?>
                <p style="color: #999; font-size: 12px;"><?php echo e(t('admin_lockdown.no_ips_whitelisted')); ?></p>
            <?php else: ?>
                <div style="max-height: 200px; overflow-y: auto;">
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th><?php echo e(t('admin_lockdown.col_ip_cidr')); ?></th>
                                <th><?php echo e(t('admin_lockdown.col_label')); ?></th>
                                <th style="width: 120px;"><?php echo e(t('admin_lockdown.col_added')); ?></th>
                                <th style="width: 60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($whitelistedIPs as $wip): ?>
                            <tr>
                                <td style="font-family: monospace;"><?php echo e($wip['ip_address']); ?></td>
                                <td style="color: #666;"><?php echo e($wip['label'] ?: '—'); ?></td>
                                <td style="color: #666;"><?php echo e(date('M j, Y', strtotime($wip['created_at']))); ?></td>
                                <td>
                                    <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="waf_action" value="remove_whitelisted_ip">
                                        <input type="hidden" name="whitelist_ip_id" value="<?php echo (int)$wip['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 11px; padding: 2px 8px;"
                                                onclick="return confirm('Remove <?php echo e($wip['ip_address']); ?> from whitelist?');">
                                            <?php echo e(t('admin_lockdown.btn_remove')); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Card 2: Learned Patterns -->
<div class="card">
    <h3><?php echo e(t('admin_lockdown.learned_patterns_heading')); ?></h3>

    <!-- IP Filter -->
    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="lp-ip-filter" style="font-size: 12px; color: #666; white-space: nowrap;"><?php echo e(t('admin_lockdown.filter_by_client_ip')); ?></label>
        <select id="lp-ip-filter" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
            <option value=""><?php echo e(t('admin_lockdown.all_ips')); ?></option>
            <?php foreach ($clientIPs as $cip): ?>
            <option value="<?php echo e($cip['client_ip']); ?>" <?php echo $ipFilter === $cip['client_ip'] ? 'selected' : ''; ?>>
                <?php echo e($cip['client_ip']); ?> <?php echo e(t('admin_lockdown.ip_request_count', number_format((int)$cip['cnt']))); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($ipFilter !== ''): ?>
            <a href="admin.php?section=lockdown" style="font-size: 12px; color: #dc2626; text-decoration: none;"><?php echo e(t('admin_lockdown.clear_filter')); ?></a>
        <?php endif; ?>
    </div>

    <?php if (empty($patterns)): ?>
        <p style="color: #999;"><?php echo e(t('admin_lockdown.no_patterns_yet')); ?><?php echo $ipFilter !== '' ? ' ' . e(t('admin_lockdown.for_this_ip')) : ''; ?>. <?php echo e(t('admin_lockdown.start_learning_hint')); ?></p>
    <?php else: ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <p style="color: #666; margin: 0; font-size: 13px;">
                    <?php echo number_format(count($patterns)); ?> <?php echo e(t('admin_lockdown.patterns_count_suffix')); ?><?php echo $ipFilter !== '' ? ' ' . e(t('admin_lockdown.from_label')) . ' ' . e($ipFilter) : ''; ?>.
                </p>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="lp-page-size" style="font-size: 12px; color: #666; white-space: nowrap;"><?php echo e(t('admin_lockdown.show_label')); ?></label>
                    <select id="lp-page-size" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="500">500</option>
                    </select>
                </div>
            </div>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="clear_patterns">
                <button type="submit" class="btn btn-sm btn-secondary"
                        onclick="return confirm('Clear all learned patterns? This cannot be undone.');"
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_clearing')); ?>">
                    <?php echo e(t('admin_lockdown.btn_clear_all')); ?>
                </button>
            </form>
        </div>

        <!-- Pagination controls (top) -->
        <div id="lp-pager-top" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 13px; color: #666;">
            <span id="lp-info-top"></span>
            <div style="display: flex; gap: 4px;">
                <button type="button" id="lp-prev-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; <?php echo e(t('admin_lockdown.btn_prev')); ?></button>
                <span id="lp-pages-top" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="lp-next-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;"><?php echo e(t('admin_lockdown.btn_next')); ?> &raquo;</button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="lp-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_lockdown.col_uri')); ?></th>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_method')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_params')); ?></th>
                        <th style="width: 120px;"><?php echo e(t('admin_lockdown.col_content_type')); ?></th>
                        <th style="width: 60px;"><?php echo e(t('admin_lockdown.col_status')); ?></th>
                        <th style="width: 60px;"><?php echo e(t('admin_lockdown.col_freq')); ?></th>
                        <th style="width: 130px;"><?php echo e(t('admin_lockdown.col_last_seen')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patterns as $p): ?>
                    <tr class="lp-row">
                        <td style="font-family: monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?php echo e($lockdownUrl(['detail_uri' => $p['uri'], 'detail_method' => $p['method']])); ?>"
                               style="color: #1e40af; text-decoration: none;" title="<?php echo e(t('admin_lockdown.view_individual_requests')); ?>">
                                <?php echo e($p['uri']); ?>
                            </a>
                        </td>
                        <td><span class="badge badge-blue"><?php echo e($p['method']); ?></span></td>
                        <td style="font-size: 11px; color: #666; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?php echo e($lockdownUrl(['detail_uri' => $p['uri'], 'detail_method' => $p['method']])); ?>"
                               style="color: #4b5563; text-decoration: none;" title="<?php echo e(t('admin_lockdown.view_individual_requests')); ?>">
                            <?php
                            $params = json_decode($p['param_names'] ?? '[]', true);
                            echo e(is_array($params) && !empty($params) ? implode(', ', $params) : '—');
                            ?>
                            </a>
                        </td>
                        <td style="font-size: 11px; color: #666;"><?php echo e($p['content_type'] ?: '—'); ?></td>
                        <td style="text-align: center;"><?php echo (int)$p['response_status']; ?></td>
                        <td style="text-align: center; font-weight: 500;"><?php echo number_format((int)$p['frequency']); ?></td>
                        <td style="font-size: 12px;"><?php echo e(date('M j, g:i A', strtotime($p['last_seen']))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination controls (bottom) -->
        <div id="lp-pager-bottom" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 13px; color: #666;">
            <span id="lp-info-bottom"></span>
            <div style="display: flex; gap: 4px;">
                <button type="button" id="lp-prev-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; <?php echo e(t('admin_lockdown.btn_prev')); ?></button>
                <span id="lp-pages-bottom" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="lp-next-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;"><?php echo e(t('admin_lockdown.btn_next')); ?> &raquo;</button>
            </div>
        </div>

        <script nonce="<?php echo cspNonce(); ?>">
        (function() {
            var rows = document.querySelectorAll('#lp-table .lp-row');
            var total = rows.length;
            var sizeEl = document.getElementById('lp-page-size');
            var page = 1;

            function render() {
                var size = parseInt(sizeEl.value, 10);
                var pages = Math.ceil(total / size);
                if (page > pages) page = pages;
                if (page < 1) page = 1;
                var start = (page - 1) * size;
                var end = Math.min(start + size, total);
                for (var i = 0; i < total; i++) rows[i].style.display = (i >= start && i < end) ? '' : 'none';
                var info = <?php echo json_encode(t('admin_lockdown.js_showing')); ?> + (start + 1) + '\u2013' + end + <?php echo json_encode(t('admin_lockdown.js_of')); ?> + total;
                ['top', 'bottom'].forEach(function(pos) {
                    document.getElementById('lp-info-' + pos).textContent = info;
                    document.getElementById('lp-prev-' + pos).disabled = page <= 1;
                    document.getElementById('lp-next-' + pos).disabled = page >= pages;
                    var pagesEl = document.getElementById('lp-pages-' + pos);
                    pagesEl.innerHTML = '';
                    var maxBtns = 7, sp = Math.max(1, page - 3), ep = Math.min(pages, sp + maxBtns - 1);
                    if (ep - sp < maxBtns - 1) sp = Math.max(1, ep - maxBtns + 1);
                    for (var p = sp; p <= ep; p++) {
                        var btn = document.createElement('button');
                        btn.type = 'button'; btn.textContent = p;
                        btn.className = 'btn btn-sm' + (p === page ? '' : ' btn-secondary');
                        btn.style.cssText = 'padding:3px 8px;min-width:32px;' + (p === page ? 'background:#1e40af;color:#fff;' : '');
                        btn.setAttribute('data-page', p);
                        btn.addEventListener('click', function() { page = parseInt(this.getAttribute('data-page'), 10); render(); });
                        pagesEl.appendChild(btn);
                    }
                });
            }
            sizeEl.addEventListener('change', function() { page = 1; render(); });
            ['top', 'bottom'].forEach(function(pos) {
                document.getElementById('lp-prev-' + pos).addEventListener('click', function() { if (page > 1) { page--; render(); } });
                document.getElementById('lp-next-' + pos).addEventListener('click', function() { page++; render(); });
            });
            render();

            // IP filter
            var ipFilter = document.getElementById('lp-ip-filter');
            if (ipFilter) {
                ipFilter.addEventListener('change', function() {
                    location.href = 'admin.php?section=lockdown' + (this.value ? '&ip=' + encodeURIComponent(this.value) : '');
                });
            }
        })();
        </script>
    <?php endif; ?>
</div>

<!-- Card 3: Whitelist Rules -->
<div class="card">
    <h3><?php echo e(t('admin_lockdown.whitelist_rules_heading')); ?></h3>
    <?php if (empty($rules)): ?>
        <p style="color: #999;"><?php echo e(t('admin_lockdown.no_rules_yet')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px; font-size: 13px;">
            <?php echo number_format(count($rules)); ?> <?php echo e(t('admin_lockdown.rules_active_suffix')); ?>
        </p>
        <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_rule_id')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_uri_pattern')); ?></th>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_method')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_description')); ?></th>
                        <th style="width: 60px;"><?php echo e(t('admin_lockdown.col_type')); ?></th>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $r): ?>
                    <tr class="rule-row" style="cursor: pointer;">
                        <td style="font-family: monospace; font-size: 12px;"><?php echo (int)$r['rule_id']; ?></td>
                        <td style="font-family: monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo e($r['uri_pattern']); ?>
                        </td>
                        <td><span class="badge badge-blue"><?php echo e($r['method_pattern']); ?></span></td>
                        <td style="font-size: 12px; color: #666;"><?php echo e($r['description']); ?></td>
                        <td>
                            <?php if ($r['is_auto_generated']): ?>
                                <span class="badge" style="background: #dbeafe; color: #1e40af;"><?php echo e(t('admin_lockdown.type_auto')); ?></span>
                            <?php else: ?>
                                <span class="badge" style="background: #f3e8ff; color: #7c3aed;"><?php echo e(t('admin_lockdown.type_manual')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="waf_action" value="remove_rule">
                                <input type="hidden" name="rule_id" value="<?php echo (int)$r['rule_id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;"
                                        onclick="return confirm('Remove rule #<?php echo (int)$r['rule_id']; ?>?');">
                                    <?php echo e(t('admin_lockdown.btn_remove')); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr class="rule-detail-row" style="display: none;">
                        <td colspan="6" style="background: #f8fafc; padding: 12px 20px; border-top: none;">
                            <div style="display: grid; grid-template-columns: 120px 1fr; gap: 6px 16px; font-size: 12px;">
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_rule_text')); ?></span>
                                <pre style="font-family: monospace; font-size: 11px; background: #1e293b; color: #e2e8f0; padding: 8px 12px; border-radius: 4px; overflow-x: auto; margin: 0; white-space: pre-wrap; word-break: break-all;"><?php echo e($r['rule_text']); ?></pre>

                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.col_status')); ?></span>
                                <span><?php echo $r['is_active'] ? '<span class="badge badge-success">' . e(t('admin_lockdown.status_active')) . '</span>' : '<span class="badge badge-danger">' . e(t('admin_lockdown.status_inactive')) . '</span>'; ?></span>

                                <?php if (!empty($r['created_at'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_created')); ?></span>
                                <span><?php echo e(date('M j, Y g:i A', strtotime($r['created_at']))); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="generate_whitelist">
                <button type="submit" class="btn btn-sm" style="background: #1e40af; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_regenerating')); ?>">
                    <?php echo e(t('admin_lockdown.btn_regenerate_whitelist')); ?>
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="export_rules">
                <button type="submit" class="btn btn-sm btn-secondary"
                        data-disable-on-click="<?php echo e(t('admin_lockdown.busy_exporting')); ?>">
                    <?php echo e(t('admin_lockdown.btn_export_rules')); ?>
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Import Rules (always visible) -->
    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
        <form method="POST" action="admin.php?section=lockdown" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="import_rules">
            <input type="file" name="import_file" accept=".json" required
                   style="font-size: 13px; border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 8px;">
            <button type="submit" class="btn btn-sm btn-secondary"
                    data-disable-on-click="<?php echo e(t('admin_lockdown.busy_importing')); ?>">
                <?php echo e(t('admin_lockdown.btn_import_rules')); ?>
            </button>
            <span style="font-size: 12px; color: #999;"><?php echo e(t('admin_lockdown.import_hint')); ?></span>
        </form>
    </div>
</div>

<!-- Card 4: Block Log -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;"><?php echo e(t('admin_lockdown.block_log_heading')); ?></h3>
        <?php if (!empty($blocks)): ?>
        <div style="display: flex; gap: 8px;">
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="export_block_log">
                <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 12px;">
                    <?php echo e(t('admin_lockdown.btn_export_csv')); ?>
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="clear_block_log">
                <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 12px;"
                        onclick="return confirm('Clear all block log entries? This cannot be undone.');">
                    <?php echo e(t('admin_lockdown.btn_clear_block_log')); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php if (empty($blocks)): ?>
        <p style="color: #999;"><?php echo e(t('admin_lockdown.no_blocks')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px; font-size: 13px;"><?php echo number_format(count($blocks)); ?> <?php echo e(t('admin_lockdown.block_events_suffix')); ?></p>
        <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php echo e(t('admin_lockdown.col_blocked_at')); ?></th>
                        <th style="width: 110px;"><?php echo e(t('admin_lockdown.col_client_ip')); ?></th>
                        <th><?php echo e(t('admin_lockdown.col_uri')); ?></th>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_method')); ?></th>
                        <th style="width: 70px;"><?php echo e(t('admin_lockdown.col_rule_id')); ?></th>
                        <th style="width: 60px;"><?php echo e(t('admin_lockdown.col_status')); ?></th>
                        <th style="width: 100px;"><?php echo e(t('admin_lockdown.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($blocks as $b):
                        $details = json_decode($b['request_headers'] ?? '{}', true) ?: [];
                    ?>
                    <tr class="block-row" style="cursor: pointer;">
                        <td style="font-size: 12px; white-space: nowrap;"><?php echo e(date('M j, g:i A', strtotime($b['blocked_at']))); ?></td>
                        <td style="font-family: monospace; font-size: 12px;"><?php echo e($b['client_ip']); ?></td>
                        <td style="font-family: monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo e($b['uri']); ?>
                        </td>
                        <td><span class="badge badge-blue"><?php echo e($b['method']); ?></span></td>
                        <td style="font-family: monospace; font-size: 12px;"><?php echo (int)$b['rule_id']; ?></td>
                        <td>
                            <?php if ($b['resolved']): ?>
                                <span class="badge badge-success"><?php echo e(t('admin_lockdown.status_resolved')); ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger"><?php echo e(t('admin_lockdown.status_blocked')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$b['resolved']): ?>
                            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="waf_action" value="add_exception">
                                <input type="hidden" name="exception_uri" value="<?php echo e($b['uri']); ?>">
                                <input type="hidden" name="exception_method" value="<?php echo e($b['method']); ?>">
                                <button type="submit" class="btn btn-sm" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"
                                        onclick="return confirm('Add exception for <?php echo e($b['method']); ?> <?php echo e($b['uri']); ?>?');">
                                    <?php echo e(t('admin_lockdown.btn_allow')); ?>
                                </button>
                            </form>
                            <?php else: ?>
                                <span style="color: #999; font-size: 12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="block-detail-row" style="display: none;">
                        <td colspan="7" style="background: #f8fafc; padding: 12px 20px; border-top: none;">
                            <div style="display: grid; grid-template-columns: 120px 1fr; gap: 6px 16px; font-size: 12px;">
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_rule_message')); ?></span>
                                <span style="font-family: monospace; color: #dc2626;"><?php echo e($b['rule_message'] ?: '—'); ?></span>

                                <?php if (!empty($details['match_detail'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_match_detail')); ?></span>
                                <span style="font-family: monospace; word-break: break-all;"><?php echo e($details['match_detail']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['tag'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_tag')); ?></span>
                                <span><span class="badge" style="background: #e0e7ff; color: #3730a3;"><?php echo e($details['tag']); ?></span></span>
                                <?php endif; ?>

                                <?php if (!empty($details['file'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_rule_file')); ?></span>
                                <span style="font-family: monospace; font-size: 11px;"><?php echo e($details['file']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['hostname'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_hostname')); ?></span>
                                <span style="font-family: monospace;"><?php echo e($details['hostname']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($b['user_agent'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.col_user_agent')); ?></span>
                                <span style="font-family: monospace; word-break: break-all;"><?php echo e($b['user_agent']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['unique_id'])): ?>
                                <span style="color: #64748b; font-weight: 600;"><?php echo e(t('admin_lockdown.label_unique_id')); ?></span>
                                <span style="font-family: monospace; font-size: 11px; color: #64748b;"><?php echo e($details['unique_id']); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script nonce="<?php echo cspNonce(); ?>">
document.addEventListener('DOMContentLoaded', function() {
    function bindExpandableRows(rowClass, detailClass) {
        document.querySelectorAll('tr.' + rowClass).forEach(function(row) {
            row.addEventListener('click', function(e) {
                if (e.target.closest('form') || e.target.closest('button')) return;
                var detail = row.nextElementSibling;
                if (detail && detail.classList.contains(detailClass)) {
                    detail.style.display = detail.style.display === 'none' ? 'table-row' : 'none';
                    row.style.background = detail.style.display === 'none' ? '' : '#f1f5f9';
                }
            });
        });
    }
    bindExpandableRows('block-row', 'block-detail-row');
    bindExpandableRows('rule-row', 'rule-detail-row');
});
</script>
<?php endif; ?>
