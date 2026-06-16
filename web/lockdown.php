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
        $error = t('lockdown.invalid_request');
    } elseif (!$modSecAvailable) {
        $error = t('lockdown.modsec_unavailable_op');
    } elseif ($wafAction === 'start_learning') {
        if ($lockdown->setMode('learning')) {
            $success = t('lockdown.learning_activated');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'learning'],
            ]);
        } else {
            $error = t('lockdown.activate_learning_failed');
        }
    } elseif ($wafAction === 'stop_learning') {
        if ($lockdown->setMode('disabled')) {
            $success = t('lockdown.learning_stopped');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = t('lockdown.stop_learning_failed');
        }
    } elseif ($wafAction === 'ingest_logs') {
        $count = $lockdown->ingestAuditLog();
        $success = t('lockdown.logs_ingested', $count);
        $auth->audit($user['id'], 'waf_ingest_logs', 'waf_learned_patterns', null, [
            'new' => ['patterns_ingested' => $count],
        ]);
    } elseif ($wafAction === 'generate_whitelist') {
        $count = $lockdown->generateWhitelistRules();
        $success = t('lockdown.whitelist_generated', $count);
        $auth->audit($user['id'], 'waf_generate_whitelist', 'waf_whitelist_rules', null, [
            'new' => ['rules_generated' => $count],
        ]);
    } elseif ($wafAction === 'enable_enforcement') {
        if ($lockdown->setMode('enforcing')) {
            $success = t('lockdown.enforcement_enabled');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'enforcing'],
            ]);
        } else {
            $error = t('lockdown.enable_enforcement_failed');
        }
    } elseif ($wafAction === 'disable_waf') {
        if ($lockdown->setMode('disabled')) {
            $success = t('lockdown.waf_disabled');
            $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                'new' => ['mode' => 'disabled'],
            ]);
        } else {
            $error = t('lockdown.disable_waf_failed');
        }
    } elseif ($wafAction === 'add_exception') {
        $exUri = trim($_POST['exception_uri'] ?? '');
        $exMethod = strtoupper(trim($_POST['exception_method'] ?? 'GET'));
        if (empty($exUri)) {
            $error = t('lockdown.uri_required');
        } else {
            if ($lockdown->addException($exUri, $exMethod)) {
                $success = "Exception added for {$exMethod} {$exUri}. Apache reloaded.";
                $auth->audit($user['id'], 'waf_add_exception', 'waf_whitelist_rules', null, [
                    'new' => ['uri' => $exUri, 'method' => $exMethod],
                ]);
            } else {
                $error = t('lockdown.add_exception_failed');
            }
        }
    } elseif ($wafAction === 'remove_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0 && $lockdown->removeRule($ruleId)) {
            $success = t('lockdown.rule_removed', $ruleId);
            $auth->audit($user['id'], 'waf_remove_rule', 'waf_whitelist_rules', null, [
                'new' => ['rule_id' => $ruleId],
            ]);
        } else {
            $error = t('lockdown.remove_rule_failed');
        }
    } elseif ($wafAction === 'clear_patterns') {
        if ($lockdown->clearLearnedPatterns()) {
            $success = t('lockdown.patterns_cleared');
            $auth->audit($user['id'], 'waf_clear_patterns', 'waf_learned_patterns', null, []);
        } else {
            $error = t('lockdown.clear_patterns_failed');
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
                ? t('lockdown.scanner_blocking_enabled')
                : t('lockdown.scanner_blocking_disabled');
            $auth->audit($user['id'], 'waf_scanner_blocking', 'app_config', null, [
                'new' => ['scanner_blocking' => $newState ? 'enabled' : 'disabled'],
            ]);
        } else {
            $error = t('lockdown.toggle_scanner_failed');
        }
    } elseif ($wafAction === 'import_rules') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error = t('lockdown.no_file_uploaded');
        } else {
            $raw = file_get_contents($_FILES['import_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['rules'])) {
                $error = t('lockdown.invalid_import_file');
            } else {
                [$rulesImported, $patternsImported] = $lockdown->importRules($data);
                $success = "Imported {$rulesImported} rule(s) and {$patternsImported} pattern(s).";
                $auth->audit($user['id'], 'waf_import_rules', 'waf_whitelist_rules', null, [
                    'new' => ['rules' => $rulesImported, 'patterns' => $patternsImported],
                ]);
            }
        }

    } elseif ($wafAction === 'clear_block_log') {
        try {
            $db->query("DELETE FROM waf_block_events");
            @unlink('/tmp/.waf_block_ingest_offset');
            $success = t('lockdown.block_log_cleared');
            $auth->audit($user['id'], 'waf_clear_block_log', 'waf_block_events', null, []);
        } catch (Exception $e) {
            $error = t('lockdown.clear_block_log_failed');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$status = $lockdown->getStatus();
$scannerBlocking = $lockdown->isScannerBlockingEnabled();
$ipFilter = trim($_GET['ip'] ?? '');
$detailUri = $_GET['detail_uri'] ?? '';
$detailMethod = $_GET['detail_method'] ?? '';
$isDetailView = ($detailUri !== '');

$patterns = $lockdown->getLearnedPatterns(500, $ipFilter);
$clientIPs = $lockdown->getDistinctClientIPs();
$rules = $lockdown->getWhitelistRules();
$blocks = $lockdown->getRecentBlocks();

// Detail view: load individual requests for the selected pattern
$detailRequests = [];
if ($isDetailView) {
    $detailRequests = $lockdown->getRequestsForPattern($detailUri, $detailMethod, $ipFilter);
}

// Mode badge config
$modeBadges = [
    'disabled'  => ['label' => t('lockdown.mode_disabled'),  'bg' => '#f3f4f6', 'color' => '#6b7280'],
    'learning'  => ['label' => t('lockdown.mode_learning'),  'bg' => '#fef3c7', 'color' => '#92400e'],
    'enforcing' => ['label' => t('lockdown.mode_enforcing'), 'bg' => '#dcfce7', 'color' => '#166534'],
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
    <h1 class="page-title"><?php echo e(t('lockdown.heading')); ?></h1>
    <p><?php echo e(t('lockdown.subtitle')); ?></p>
</div>

<?php if (!$modSecAvailable): ?>
<div class="alert alert-danger">
    <strong><?php echo e(t('lockdown.modsec_not_available')); ?></strong> — The <code>security2_module</code> is not loaded in Apache.
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
            <h3 style="margin: 0;"><?php echo e(t('lockdown.request_detail')); ?></h3>
            <p style="color: #666; margin: 5px 0 0; font-size: 13px;">
                Individual requests for <strong><code><?php echo e($detailMethod); ?> <?php echo e($detailUri); ?></code></strong>
                <?php if ($ipFilter !== ''): ?>
                    from IP <strong><code><?php echo e($ipFilter); ?></code></strong>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?php echo e($lockdownUrl()); ?>" class="btn btn-secondary">&laquo; <?php echo e(t('lockdown.back_to_patterns')); ?></a>
    </div>

    <!-- IP Filter for detail view -->
    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="detail-ip-filter" style="font-size: 12px; color: #666; white-space: nowrap;"><?php echo e(t('lockdown.filter_by_ip')); ?></label>
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
                    <tr>
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
        <form method="POST" action="admin.php?section=lockdown" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="waf_action" value="clear_block_log">
            <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; font-size: 12px;"
                    onclick="return confirm('Clear all block log entries? This cannot be undone.');">
                Clear Block Log
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php if (empty($blocks)): ?>
        <p style="color: #999;">No blocked requests recorded.</p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px; font-size: 13px;"><?php echo number_format(count($blocks)); ?> recent block event(s).</p>
        <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
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
                    <?php foreach ($blocks as $b): ?>
                    <tr>
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
