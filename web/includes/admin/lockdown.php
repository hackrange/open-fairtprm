<?php
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
        $error = 'Invalid request. Please try again.';
    } elseif (!$modSecAvailable) {
        $error = 'ModSecurity is not available. Cannot perform WAF operations.';
    } elseif ($wafAction === 'start_learning') {
        if ($lockdown->setMode('learning')) {
            $success = 'WAF learning mode activated. ModSecurity is now logging all traffic patterns.';
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'learning'],
            ]);
        } else {
            $error = 'Failed to activate learning mode. Check Apache/ModSecurity configuration.';
        }
    } elseif ($wafAction === 'stop_learning') {
        if ($lockdown->setMode('disabled')) {
            $success = 'WAF learning stopped. ModSecurity is now disabled.';
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = 'Failed to stop learning mode.';
        }
    } elseif ($wafAction === 'ingest_logs') {
        $count = $lockdown->ingestAuditLog();
        $success = "Ingested {$count} traffic pattern(s) from the audit log.";
        $auth->audit($user['id'], 'waf_ingest_logs', 'waf_learned_patterns', null, [
            'new' => ['patterns_ingested' => $count],
        ]);
    } elseif ($wafAction === 'generate_whitelist') {
        $count = $lockdown->generateWhitelistRules();
        $success = "Generated {$count} whitelist rule(s) from learned patterns.";
        $auth->audit($user['id'], 'waf_generate_whitelist', 'waf_whitelist_rules', null, [
            'new' => ['rules_generated' => $count],
        ]);
    } elseif ($wafAction === 'enable_enforcement') {
        if ($lockdown->setMode('enforcing')) {
            $success = 'WAF enforcement enabled. Requests not in the whitelist will be blocked.';
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'enforcing'],
            ]);
        } else {
            $error = 'Failed to enable enforcement mode.';
        }
    } elseif ($wafAction === 'disable_waf') {
        if ($lockdown->setMode('disabled')) {
            $success = 'WAF disabled. ModSecurity is now off.';
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = 'Failed to disable WAF.';
        }
    } elseif ($wafAction === 'add_exception') {
        $exUri = trim($_POST['exception_uri'] ?? '');
        $exMethod = strtoupper(trim($_POST['exception_method'] ?? 'GET'));
        if (empty($exUri)) {
            $error = 'URI is required for adding an exception.';
        } else {
            if ($lockdown->addException($exUri, $exMethod)) {
                $success = "Exception added for {$exMethod} {$exUri}. Apache reloaded.";
                $auth->audit($user['id'], 'waf_add_exception', 'waf_whitelist_rules', null, [
                    'new' => ['uri' => $exUri, 'method' => $exMethod],
                ]);
            } else {
                $error = 'Failed to add exception.';
            }
        }
    } elseif ($wafAction === 'remove_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0 && $lockdown->removeRule($ruleId)) {
            $success = "Rule #{$ruleId} removed. Apache reloaded.";
            $auth->audit($user['id'], 'waf_remove_rule', 'waf_whitelist_rules', null, [
                'new' => ['rule_id' => $ruleId],
            ]);
        } else {
            $error = 'Failed to remove rule.';
        }
    } elseif ($wafAction === 'clear_patterns') {
        if ($lockdown->clearLearnedPatterns()) {
            $success = 'All learned patterns cleared.';
            $auth->audit($user['id'], 'waf_clear_patterns', 'waf_learned_patterns', null, []);
        } else {
            $error = 'Failed to clear learned patterns.';
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
                ? 'Automated scanner blocking enabled. Requests from known scanners will be denied.'
                : 'Automated scanner blocking disabled.';
            $auth->audit($user['id'], 'waf_scanner_blocking', 'app_config', null, [
                'new' => ['scanner_blocking' => $newState ? 'enabled' : 'disabled'],
            ]);
        } else {
            $error = 'Failed to toggle scanner blocking. Check Apache/ModSecurity configuration.';
        }
    } elseif ($wafAction === 'import_rules') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'No file uploaded or upload error occurred.';
        } else {
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['rules'])) {
                $error = 'Invalid import file. Must be a JSON file exported from this system.';
            } else {
                [$rulesImported, $patternsImported] = $lockdown->importRules($data);
                $success = "Imported {$rulesImported} rule(s) and {$patternsImported} pattern(s).";
                $auth->audit($user['id'], 'waf_import_rules', 'waf_whitelist_rules', null, [
                    'new' => ['rules' => $rulesImported, 'patterns' => $patternsImported],
                ]);
            }
        }

    } elseif ($wafAction === 'add_whitelisted_ip') {
        $ip = trim($_POST['whitelist_ip'] ?? '');
        $label = trim($_POST['whitelist_label'] ?? '');
        if ($ip === '') {
            $error = 'IP address is required.';
        } elseif ($lockdown->addWhitelistedIP($ip, $label)) {
            $success = "IP {$ip} added to whitelist. Scanner detection and rate limiting will be skipped for this IP.";
            $auth->audit($user['id'], 'waf_ip_whitelist_add', 'waf_ip_whitelist', null, [
                'new' => ['ip' => $ip, 'label' => $label],
            ]);
        } else {
            $error = 'Failed to add IP. Ensure it is a valid IPv4/IPv6 address or CIDR range.';
        }

    } elseif ($wafAction === 'remove_whitelisted_ip') {
        $ipId = (int)($_POST['whitelist_ip_id'] ?? 0);
        if ($ipId > 0 && $lockdown->removeWhitelistedIP($ipId)) {
            $success = 'IP removed from whitelist. Apache reloaded.';
            $auth->audit($user['id'], 'waf_ip_whitelist_remove', 'waf_ip_whitelist', null, [
                'new' => ['id' => $ipId],
            ]);
        } else {
            $error = 'Failed to remove IP from whitelist.';
        }

    } elseif ($wafAction === 'update_rate_limit') {
        $rlEnabled = isset($_POST['rl_enabled']);
        $rlRequests = (int)($_POST['rl_requests'] ?? 100);
        $rlPeriod = (int)($_POST['rl_period'] ?? 60);
        $rlBlock = (int)($_POST['rl_block'] ?? 300);
        if ($lockdown->updateRateLimitSettings($rlEnabled, $rlRequests, $rlPeriod, $rlBlock)) {
            $success = $rlEnabled
                ? "Rate limiting enabled: {$rlRequests} requests per {$rlPeriod}s, block for {$rlBlock}s."
                : 'Rate limiting disabled.';
            $auth->audit($user['id'], 'waf_rate_limit', 'app_config', null, [
                'new' => ['enabled' => $rlEnabled, 'requests' => $rlRequests, 'period' => $rlPeriod, 'block' => $rlBlock],
            ]);
        } else {
            $error = 'Failed to update rate limit settings.';
        }

    } elseif ($wafAction === 'update_auth_lockout') {
        $maxAttempts = (int)($_POST['al_max_attempts'] ?? 5);
        $lockoutDuration = (int)($_POST['al_lockout_duration'] ?? 300);
        if ($lockdown->updateAuthLockoutSettings($maxAttempts, $lockoutDuration)) {
            $success = "Authentication lockouts updated: {$maxAttempts} attempts, {$lockoutDuration}s window.";
            $auth->audit($user['id'], 'auth_lockout_update', 'app_config', null, [
                'new' => ['max_login_attempts' => $maxAttempts, 'lockout_duration' => $lockoutDuration],
            ]);
        } else {
            $error = 'Failed to update authentication lockout settings.';
        }

    } elseif ($wafAction === 'clear_block_log') {
        try {
            $db->query("DELETE FROM waf_block_events");
            // Keep the ingest offset so cleared entries aren't re-ingested from the error log
            $logFile = '/var/log/apache2/error.log';
            if (is_readable($logFile)) {
                file_put_contents('/tmp/.waf_block_ingest_offset', (string)filesize($logFile));
            }
            $success = 'Block log cleared.';
            $auth->audit($user['id'], 'waf_clear_block_log', 'waf_block_events', null, []);
        } catch (Exception $e) {
            $error = 'Failed to clear block log.';
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
// exec() is in disable_functions, so use proc_open (same pattern as LockdownService)
$interceptCount = 0;
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('grep -c "ModSecurity: Access denied" /var/log/apache2/error.log 2>/dev/null', $desc, $pipes);
if (is_resource($proc)) {
    fclose($pipes[0]);
    $interceptCount = (int)trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
}

$ipFilter = trim($_GET['ip'] ?? '');
$detailUri = $_GET['detail_uri'] ?? '';
$detailMethod = $_GET['detail_method'] ?? '';
$isDetailView = ($detailUri !== '');

$patterns = $lockdown->getLearnedPatterns(500, $ipFilter);
$clientIPs = $lockdown->getDistinctClientIPs();
$rules = $lockdown->getWhitelistRules();
// Block Log pagination: default 50 per page, keep current page in URL so
// reloads land in the same place. Capped page size so a hostile ?per_page
// can't blow up memory.
$blocksPerPage = max(10, min(500, (int)($_GET['bl_per_page'] ?? 50)));
$blocksPage = max(1, (int)($_GET['bl_page'] ?? 1));
$blocksTotal = $lockdown->getRecentBlocksCount();
$blocksTotalPages = max(1, (int)ceil($blocksTotal / $blocksPerPage));
if ($blocksPage > $blocksTotalPages) $blocksPage = $blocksTotalPages;
$blocks = $lockdown->getRecentBlocks($blocksPerPage, ($blocksPage - 1) * $blocksPerPage);
$whitelistedIPs = $lockdown->getWhitelistedIPs();
$rateLimitSettings = $lockdown->getRateLimitSettings();
$authLockoutSettings = $lockdown->getAuthLockoutSettings();

// Detail view: load individual requests for the selected pattern
$detailRequests = [];
if ($isDetailView) {
    $detailRequests = $lockdown->getRequestsForPattern($detailUri, $detailMethod, $ipFilter);
}

// Mode badge config
$modeBadges = [
    'disabled'  => ['label' => 'Disabled',  'bg' => '#f3f4f6', 'color' => '#6b7280'],
    'learning'  => ['label' => 'Learning',  'bg' => '#fef3c7', 'color' => '#92400e'],
    'enforcing' => ['label' => 'Enforcing', 'bg' => '#dcfce7', 'color' => '#166534'],
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
    <h1 class="page-title">Lockdown</h1>
    <p>Web Application Firewall — learn traffic patterns, generate a whitelist, then enforce it.</p>
</div>

<?php if (!$modSecAvailable): ?>
<div class="alert alert-danger">
    <strong>ModSecurity Not Available</strong> — The <code>security2_module</code> is not loaded in Apache.
    WAF features require the <code>libapache2-mod-security2</code> package. All controls are disabled.
</div>
<?php endif; ?>

<?php if ($isDetailView): ?>
<!-- ======================================================================= -->
<!-- DETAIL VIEW: Individual requests for a pattern                          -->
<!-- ======================================================================= -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="margin: 0;">Request Detail</h3>
            <p style="color: #666; margin: 5px 0 0; font-size: 13px;">
                Individual requests for <strong><code><?php echo e($detailMethod); ?> <?php echo e($detailUri); ?></code></strong>
                <?php if ($ipFilter !== ''): ?>
                    from IP <strong><code><?php echo e($ipFilter); ?></code></strong>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?php echo e($lockdownUrl()); ?>" class="btn btn-secondary">&laquo; Back to Patterns</a>
    </div>

    <!-- IP Filter for detail view -->
    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="detail-ip-filter" style="font-size: 12px; color: #666; white-space: nowrap;">Filter by IP:</label>
        <select id="detail-ip-filter" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;"
                data-url-with-ip="<?php echo e($lockdownUrl(['detail_uri' => $detailUri, 'detail_method' => $detailMethod])); ?>&ip="
                data-url-no-ip="<?php echo e('admin.php?' . http_build_query(['section' => 'lockdown', 'detail_uri' => $detailUri, 'detail_method' => $detailMethod])); ?>">
            <option value="">All IPs</option>
            <?php foreach ($clientIPs as $cip): ?>
            <option value="<?php echo e($cip['client_ip']); ?>" <?php echo $ipFilter === $cip['client_ip'] ? 'selected' : ''; ?>>
                <?php echo e($cip['client_ip']); ?> (<?php echo number_format((int)$cip['cnt']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($detailRequests)): ?>
        <p style="color: #999;">No individual requests recorded for this pattern yet. Requests are captured during log ingestion.</p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 10px; font-size: 13px;"><?php echo number_format(count($detailRequests)); ?> request(s) recorded.</p>

        <!-- Pagination controls (top) -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <label for="dr-page-size" style="font-size: 12px; color: #666; white-space: nowrap;">Show:</label>
                <select id="dr-page-size" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="500">500</option>
                </select>
            </div>
            <div id="dr-pager-top" style="display: flex; align-items: center; gap: 4px; font-size: 13px; color: #666;">
                <span id="dr-info-top"></span>
                <button type="button" id="dr-prev-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; Prev</button>
                <span id="dr-pages-top" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="dr-next-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">Next &raquo;</button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="dr-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Timestamp</th>
                        <th style="width: 120px;">Client IP</th>
                        <th>User Agent</th>
                        <th>Query String</th>
                        <th style="width: 100px;">Content Type</th>
                        <th style="width: 60px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailRequests as $req): ?>
                    <tr class="dr-row">
                        <td style="font-size: 12px; white-space: nowrap;"><?php echo e(date('M j, g:i:s A', strtotime($req['request_at']))); ?></td>
                        <td style="font-family: monospace; font-size: 12px;">
                            <a href="<?php echo e($lockdownUrl(['ip' => $req['client_ip']])); ?>" style="color: #1e40af; text-decoration: none;" title="Filter patterns by this IP">
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
                <button type="button" id="dr-prev-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; Prev</button>
                <span id="dr-pages-bottom" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="dr-next-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">Next &raquo;</button>
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
                var info = 'Showing ' + (start + 1) + '\u2013' + end + ' of ' + total;
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
    <h3>WAF Status</h3>

    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Current Mode</span><br>
            <span class="badge" style="background: <?php echo e($badge['bg']); ?>; color: <?php echo e($badge['color']); ?>; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;">
                <?php echo e($badge['label']); ?>
            </span>
        </div>

        <?php if ($status['mode'] === 'learning' && $status['learning_duration']): ?>
        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Learning Since</span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo e($status['learning_started']); ?></span>
            <span style="color: #666; font-size: 13px;">(<?php echo e($status['learning_duration']); ?>)</span>
        </div>
        <?php endif; ?>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Patterns</span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo number_format($status['pattern_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Rules</span><br>
            <span style="font-size: 14px; font-weight: 500;"><?php echo number_format($status['rule_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Unresolved Blocks</span><br>
            <span style="font-size: 14px; font-weight: 500; <?php echo $status['block_count'] > 0 ? 'color: #dc2626;' : ''; ?>"><?php echo number_format($status['block_count']); ?></span>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Scanner Blocking</span><br>
            <?php if ($scannerBlocking): ?>
                <span class="badge" style="background: #dcfce7; color: #166534; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;">Enabled</span>
            <?php else: ?>
                <span class="badge" style="background: #fef3c7; color: #92400e; font-size: 14px; padding: 5px 14px; margin-top: 4px; display: inline-block;">Disabled</span>
            <?php endif; ?>
        </div>

        <div>
            <span style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Intercepts Since Restart</span><br>
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
                        data-disable-on-click="Starting...">
                    Start Learning
                </button>
            </form>
            <?php if ($status['rule_count'] > 0): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="enable_enforcement">
                <button type="submit" class="btn" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="Enabling...">
                    Enable Enforcement
                </button>
            </form>
            <?php endif; ?>
        <?php elseif ($status['mode'] === 'learning'): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="ingest_logs">
                <button type="submit" class="btn btn-primary"
                        data-disable-on-click="Ingesting...">
                    Ingest Logs
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="stop_learning">
                <button type="submit" class="btn btn-secondary"
                        data-disable-on-click="Stopping...">
                    Stop Learning
                </button>
            </form>
        <?php elseif ($status['mode'] === 'enforcing'): ?>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="disable_waf">
                <button type="submit" class="btn" style="background: #dc2626; color: white;"
                        data-disable-on-click="Disabling..."
                        onclick="return confirm('Disable WAF enforcement? All traffic will be allowed through.');">
                    Disable WAF
                </button>
            </form>
        <?php endif; ?>

        <?php if ($status['pattern_count'] > 0): ?>
        <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="generate_whitelist">
            <button type="submit" class="btn" style="background: #1e40af; color: white;"
                    <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                    data-disable-on-click="Generating...">
                Generate Whitelist
            </button>
        </form>
        <?php endif; ?>

        <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="toggle_scanner_blocking">
            <?php if ($scannerBlocking): ?>
                <button type="submit" class="btn" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="Disabling...">
                    Disable Scanner Blocking
                </button>
            <?php else: ?>
                <button type="submit" class="btn" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="Enabling...">
                    Enable Scanner Blocking
                </button>
            <?php endif; ?>
        </form>
    </div>

    <div style="margin-top: 15px; font-size: 12px; color: #999;">
        <strong>Workflow:</strong> Start Learning &rarr; browse the app normally &rarr; Ingest Logs &rarr; Generate Whitelist &rarr; Enable Enforcement.
        Admin, login, API, and static asset paths are always whitelisted — you cannot lock yourself out.
    </div>
</div>

<!-- Card: Rate Limiting & IP Whitelist -->
<div class="card">
    <h3>Rate Limiting &amp; IP Whitelist</h3>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Rate Limiting Settings -->
        <div>
            <h4 style="margin: 0 0 10px; font-size: 14px; color: #374151;">Rate Limiting</h4>
            <p style="font-size: 12px; color: #666; margin-bottom: 12px;">
                Automatically block IPs that exceed a request threshold. Uses ModSecurity IP collections to track requests per source IP.
            </p>
            <form method="POST" action="admin.php?section=lockdown">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="update_rate_limit">
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <input type="checkbox" name="rl_enabled" value="1" <?php echo $rateLimitSettings['enabled'] ? 'checked' : ''; ?>>
                        Enable rate limiting
                    </label>
                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; align-items: center; font-size: 13px;">
                        <label>Max requests:</label>
                        <input type="number" name="rl_requests" value="<?php echo (int)$rateLimitSettings['requests']; ?>" min="10" max="10000" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                        <label>Time window:</label>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <input type="number" name="rl_period" value="<?php echo (int)$rateLimitSettings['period']; ?>" min="10" max="3600" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                            <span style="color: #666;">seconds</span>
                        </div>
                        <label>Block duration:</label>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <input type="number" name="rl_block" value="<?php echo (int)$rateLimitSettings['block_duration']; ?>" min="60" max="86400" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                            <span style="color: #666;">seconds</span>
                        </div>
                    </div>
                    <div style="margin-top: 6px;">
                        <button type="submit" class="btn btn-sm btn-primary" <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                                data-disable-on-click="Saving...">
                            Save Rate Limit Settings
                        </button>
                    </div>
                </div>
            </form>
            <?php if ($rateLimitSettings['enabled']): ?>
            <div style="margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 12px; color: #166534;">
                Active: blocking IPs after <?php echo number_format($rateLimitSettings['requests']); ?> requests in <?php echo $rateLimitSettings['period']; ?>s (blocked for <?php echo $rateLimitSettings['block_duration']; ?>s)
            </div>
            <?php endif; ?>
        </div>

        <!-- IP Whitelist -->
        <div>
            <h4 style="margin: 0 0 10px; font-size: 14px; color: #374151;">IP Whitelist</h4>
            <p style="font-size: 12px; color: #666; margin-bottom: 12px;">
                Whitelisted IPs bypass all ModSecurity rules — scanner detection, rate limiting, and whitelist deny. Use for trusted sources like penetration testers or monitoring services.
            </p>

            <!-- Add IP form -->
            <form method="POST" action="admin.php?section=lockdown" style="display: flex; gap: 8px; align-items: end; flex-wrap: wrap; margin-bottom: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="add_whitelisted_ip">
                <div>
                    <label style="font-size: 11px; color: #666; display: block; margin-bottom: 2px;">IP Address or CIDR</label>
                    <input type="text" name="whitelist_ip" required placeholder="192.168.1.100 or 10.0.0.0/8"
                           pattern="[0-9a-fA-F.:/]+" title="Valid IP address or CIDR range"
                           style="width: 200px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px; font-family: monospace;">
                </div>
                <div>
                    <label style="font-size: 11px; color: #666; display: block; margin-bottom: 2px;">Label (optional)</label>
                    <input type="text" name="whitelist_label" placeholder="e.g. Pentest team"
                           style="width: 160px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                </div>
                <button type="submit" class="btn btn-sm" style="background: #166534; color: white;"
                        <?php echo !$modSecAvailable ? 'disabled' : ''; ?>
                        data-disable-on-click="Adding...">
                    Add IP
                </button>
            </form>

            <!-- IP list -->
            <?php if (empty($whitelistedIPs)): ?>
                <p style="color: #999; font-size: 12px;">No IPs whitelisted. All IPs are subject to WAF rules.</p>
            <?php else: ?>
                <div style="max-height: 200px; overflow-y: auto;">
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>IP / CIDR</th>
                                <th>Label</th>
                                <th style="width: 120px;">Added</th>
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
                                            Remove
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

<!-- Card: Authentication Lockouts -->
<div class="card">
    <h3>Authentication Lockouts</h3>
    <p style="font-size: 13px; color: #666; margin-bottom: 16px;">
        Brute-force and password-spray protection for the login endpoint. Triggered on real failed logins and applied independently to per-(IP, browser fingerprint), per-(username, IP), and per-IP fallback combinations — so a scanner attacking one account from one IP can never lock the legitimate user out of their own network.
    </p>

    <form method="POST" action="admin.php?section=lockdown" style="max-width: 420px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="waf_action" value="update_auth_lockout">
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; align-items: center; font-size: 13px;">
                <label>Max attempts:</label>
                <input type="number" name="al_max_attempts" value="<?php echo (int)$authLockoutSettings['max_attempts']; ?>" min="1" max="50" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                <label>Lockout window:</label>
                <div style="display: flex; align-items: center; gap: 4px;">
                    <input type="number" name="al_lockout_duration" value="<?php echo (int)$authLockoutSettings['lockout_duration']; ?>" min="60" max="86400" style="width: 80px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;">
                    <span style="color: #666;">seconds</span>
                </div>
            </div>
            <div style="margin-top: 6px;">
                <button type="submit" class="btn btn-sm btn-primary" data-disable-on-click="Saving...">
                    Save Auth Lockouts
                </button>
            </div>
        </div>
    </form>
    <div style="margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 12px; color: #166534; max-width: 420px;">
        Active: <?php echo (int)$authLockoutSettings['max_attempts']; ?> attempts triggers a <?php echo (int)$authLockoutSettings['lockout_duration']; ?>s lockout
    </div>
</div>

<!-- Card 2: Learned Patterns -->
<div class="card">
    <h3>Learned Patterns</h3>

    <!-- IP Filter -->
    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="lp-ip-filter" style="font-size: 12px; color: #666; white-space: nowrap;">Filter by Client IP:</label>
        <select id="lp-ip-filter" style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
            <option value="">All IPs</option>
            <?php foreach ($clientIPs as $cip): ?>
            <option value="<?php echo e($cip['client_ip']); ?>" <?php echo $ipFilter === $cip['client_ip'] ? 'selected' : ''; ?>>
                <?php echo e($cip['client_ip']); ?> (<?php echo number_format((int)$cip['cnt']); ?> requests)
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($ipFilter !== ''): ?>
            <a href="admin.php?section=lockdown" style="font-size: 12px; color: #dc2626; text-decoration: none;">Clear filter</a>
        <?php endif; ?>
    </div>

    <?php if (empty($patterns)): ?>
        <p style="color: #999;">No patterns learned yet<?php echo $ipFilter !== '' ? ' for this IP' : ''; ?>. Start learning mode and browse the application, then ingest logs.</p>
    <?php else: ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <p style="color: #666; margin: 0; font-size: 13px;">
                    <?php echo number_format(count($patterns)); ?> pattern(s)<?php echo $ipFilter !== '' ? ' from ' . e($ipFilter) : ''; ?>.
                </p>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <label for="lp-page-size" style="font-size: 12px; color: #666; white-space: nowrap;">Show:</label>
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
                        data-disable-on-click="Clearing...">
                    Clear All
                </button>
            </form>
        </div>

        <!-- Pagination controls (top) -->
        <div id="lp-pager-top" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 13px; color: #666;">
            <span id="lp-info-top"></span>
            <div style="display: flex; gap: 4px;">
                <button type="button" id="lp-prev-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; Prev</button>
                <span id="lp-pages-top" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="lp-next-top" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">Next &raquo;</button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table id="lp-table">
                <thead>
                    <tr>
                        <th>URI</th>
                        <th style="width: 70px;">Method</th>
                        <th>Params</th>
                        <th style="width: 120px;">Content Type</th>
                        <th style="width: 60px;">Status</th>
                        <th style="width: 60px;">Freq.</th>
                        <th style="width: 130px;">Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patterns as $p): ?>
                    <tr class="lp-row">
                        <td style="font-family: monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?php echo e($lockdownUrl(['detail_uri' => $p['uri'], 'detail_method' => $p['method']])); ?>"
                               style="color: #1e40af; text-decoration: none;" title="View individual requests">
                                <?php echo e($p['uri']); ?>
                            </a>
                        </td>
                        <td><span class="badge badge-blue"><?php echo e($p['method']); ?></span></td>
                        <td style="font-size: 11px; color: #666; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <a href="<?php echo e($lockdownUrl(['detail_uri' => $p['uri'], 'detail_method' => $p['method']])); ?>"
                               style="color: #4b5563; text-decoration: none;" title="View individual requests">
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
                <button type="button" id="lp-prev-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">&laquo; Prev</button>
                <span id="lp-pages-bottom" style="display: flex; gap: 2px; align-items: center;"></span>
                <button type="button" id="lp-next-bottom" class="btn btn-sm btn-secondary" style="padding: 3px 10px;">Next &raquo;</button>
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
                var info = 'Showing ' + (start + 1) + '\u2013' + end + ' of ' + total;
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
    <h3>Whitelist Rules</h3>
    <?php if (empty($rules)): ?>
        <p style="color: #999;">No whitelist rules generated yet. Learn traffic patterns first, then generate the whitelist.</p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px; font-size: 13px;">
            <?php echo number_format(count($rules)); ?> rule(s) active.
            Rules in the 500000-599999 ID range. Final deny rule (599999) blocks non-whitelisted requests.
        </p>
        <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 70px;">Rule ID</th>
                        <th>URI Pattern</th>
                        <th style="width: 70px;">Method</th>
                        <th>Description</th>
                        <th style="width: 60px;">Type</th>
                        <th style="width: 70px;">Actions</th>
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
                                <span class="badge" style="background: #dbeafe; color: #1e40af;">Auto</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f3e8ff; color: #7c3aed;">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="waf_action" value="remove_rule">
                                <input type="hidden" name="rule_id" value="<?php echo (int)$r['rule_id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;"
                                        onclick="return confirm('Remove rule #<?php echo (int)$r['rule_id']; ?>?');">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr class="rule-detail-row" style="display: none;">
                        <td colspan="6" style="background: #f8fafc; padding: 12px 20px; border-top: none;">
                            <div style="display: grid; grid-template-columns: 120px 1fr; gap: 6px 16px; font-size: 12px;">
                                <span style="color: #64748b; font-weight: 600;">Rule Text</span>
                                <pre style="font-family: monospace; font-size: 11px; background: #1e293b; color: #e2e8f0; padding: 8px 12px; border-radius: 4px; overflow-x: auto; margin: 0; white-space: pre-wrap; word-break: break-all;"><?php echo e($r['rule_text']); ?></pre>

                                <span style="color: #64748b; font-weight: 600;">Status</span>
                                <span><?php echo $r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'; ?></span>

                                <?php if (!empty($r['created_at'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Created</span>
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
                        data-disable-on-click="Regenerating...">
                    Regenerate Whitelist
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="export_rules">
                <button type="submit" class="btn btn-sm btn-secondary"
                        data-disable-on-click="Exporting...">
                    Export Rules
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
                    data-disable-on-click="Importing...">
                Import Rules
            </button>
            <span style="font-size: 12px; color: #999;">Upload a previously exported JSON file to restore rules and patterns.</span>
        </form>
    </div>
</div>

<!-- Card 4: Block Log -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;">Block Log</h3>
        <?php if (!empty($blocks)): ?>
        <div style="display: flex; gap: 8px;">
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="export_block_log">
                <button type="submit" class="btn btn-sm btn-secondary" style="font-size: 12px;">
                    Export CSV
                </button>
            </form>
            <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="waf_action" value="clear_block_log">
                <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 12px;"
                        onclick="return confirm('Clear all block log entries? This cannot be undone.');">
                    Clear Block Log
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($blocksTotal === 0): ?>
        <p style="color: #999;">No blocked requests recorded.</p>
    <?php else: ?>
        <?php
        $blStart = ($blocksPage - 1) * $blocksPerPage + 1;
        $blEnd   = min($blStart + count($blocks) - 1, $blocksTotal);
        $blQs = function(array $extra) use ($ipFilter, $blocksPerPage) {
            $params = ['section' => 'lockdown', 'bl_per_page' => $blocksPerPage];
            if ($ipFilter !== '') $params['ip'] = $ipFilter;
            return 'admin.php?' . http_build_query(array_merge($params, $extra));
        };
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <p style="color: #666; font-size: 13px; margin: 0;">
                Showing <strong><?php echo number_format($blStart); ?>–<?php echo number_format($blEnd); ?></strong> of <?php echo number_format($blocksTotal); ?> block event(s).
            </p>
            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                <label style="color: #666;">Per page:</label>
                <select onchange="location.href = '<?php echo e($blQs(['bl_page' => 1])); ?>'.replace(/bl_per_page=\d+/, 'bl_per_page=' + this.value)"
                        style="font-size: 12px; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <?php foreach ([25, 50, 100, 250] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $blocksPerPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($blocksTotalPages > 1): ?>
                <a href="<?php echo e($blQs(['bl_page' => max(1, $blocksPage - 1)])); ?>"
                   class="btn btn-sm btn-secondary"
                   style="padding: 3px 10px; <?php echo $blocksPage <= 1 ? 'pointer-events: none; opacity: 0.4;' : ''; ?>">&laquo; Prev</a>
                <span style="color: #666;">Page <?php echo $blocksPage; ?> of <?php echo $blocksTotalPages; ?></span>
                <a href="<?php echo e($blQs(['bl_page' => min($blocksTotalPages, $blocksPage + 1)])); ?>"
                   class="btn btn-sm btn-secondary"
                   style="padding: 3px 10px; <?php echo $blocksPage >= $blocksTotalPages ? 'pointer-events: none; opacity: 0.4;' : ''; ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 140px;">Blocked At</th>
                        <th style="width: 110px;">Client IP</th>
                        <th>URI</th>
                        <th style="width: 70px;">Method</th>
                        <th style="width: 70px;">Rule ID</th>
                        <th style="width: 60px;">Status</th>
                        <th style="width: 100px;">Actions</th>
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
                                <span class="badge badge-success">Resolved</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Blocked</span>
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
                                    Allow
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
                                <span style="color: #64748b; font-weight: 600;">Rule Message</span>
                                <span style="font-family: monospace; color: #dc2626;"><?php echo e($b['rule_message'] ?: '—'); ?></span>

                                <?php if (!empty($details['match_detail'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Match Detail</span>
                                <span style="font-family: monospace; word-break: break-all;"><?php echo e($details['match_detail']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['tag'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Tag</span>
                                <span><span class="badge" style="background: #e0e7ff; color: #3730a3;"><?php echo e($details['tag']); ?></span></span>
                                <?php endif; ?>

                                <?php if (!empty($details['file'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Rule File</span>
                                <span style="font-family: monospace; font-size: 11px;"><?php echo e($details['file']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['hostname'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Hostname</span>
                                <span style="font-family: monospace;"><?php echo e($details['hostname']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($b['user_agent'])): ?>
                                <span style="color: #64748b; font-weight: 600;">User Agent</span>
                                <span style="font-family: monospace; word-break: break-all;"><?php echo e($b['user_agent']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($details['unique_id'])): ?>
                                <span style="color: #64748b; font-weight: 600;">Unique ID</span>
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
