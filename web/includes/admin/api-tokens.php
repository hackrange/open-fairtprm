<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: API Token Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Manages RESTful API bearer tokens with IP whitelisting. Tokens are SHA-512
 * hashed and shown exactly once at creation. Each token is locked to specific
 * IP addresses (single IPs only, no CIDR ranges) and can be scoped to
 * read or read_write access for TPRM, GRC, or both modules.
 *
 * Also includes the API testing playground (Swagger UI) and request logs.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

$apiService = APIService::getInstance();
if (!isset($newTokenRaw)) $newTokenRaw = null;
$proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));

// ============================================================================
// POST Handlers
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_api-tokens.invalid_request');
    } else {
        $action = $_POST['api_action'] ?? '';

        if ($action === 'create_token') {
            try {
                $name = trim($_POST['token_name'] ?? '');
                $description = trim($_POST['token_description'] ?? '');
                $scope = in_array($_POST['token_scope'] ?? '', ['read', 'read_write']) ? $_POST['token_scope'] : 'read';
                $moduleAccess = $_POST['module_access'] ?? ['both'];
                $userId = (int)($_POST['token_user_id'] ?? $user['id']);
                $rateLimit = max(1, min(1000, (int)($_POST['rate_limit'] ?? 60)));
                $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
                $ips = array_filter(array_map('trim', explode("\n", $_POST['allowed_ips'] ?? '')));

                if (empty($name)) {
                    $error = t('admin_api-tokens.token_name_required');
                } elseif (empty($ips)) {
                    $error = t('admin_api-tokens.ip_required');
                } else {
                    // Validate all IPs are single addresses (no CIDR)
                    $invalidIps = [];
                    foreach ($ips as $ip) {
                        if (strpos($ip, '/') !== false || !filter_var($ip, FILTER_VALIDATE_IP)) {
                            $invalidIps[] = $ip;
                        }
                    }
                    if (!empty($invalidIps)) {
                        $error = t('admin_api-tokens.invalid_ips_prefix') . htmlspecialchars(implode(', ', $invalidIps));
                    } else {
                        $result = $apiService->createToken([
                            'name' => $name,
                            'description' => $description,
                            'scope' => $scope,
                            'module_access' => $moduleAccess,
                            'user_id' => $userId,
                            'rate_limit_per_minute' => $rateLimit,
                            'expires_at' => $expiresAt,
                            'allowed_ips' => $ips,
                        ], (int)$user['id']);

                        $newTokenRaw = $result['raw_token'];
                        $auth->audit($user['id'], 'api_token_created', 'api_tokens', $result['token_id'], [
                            'name' => $name, 'scope' => $scope, 'ip_count' => count($ips),
                        ]);
                        $success = t('admin_api-tokens.token_created');
                    }
                }
            } catch (Exception $e) {
                error_log('API token creation error: ' . $e->getMessage());
                $error = t('admin_api-tokens.token_create_failed');
            }
        } elseif ($action === 'revoke_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                $apiService->revokeToken($tokenId);
                $auth->audit($user['id'], 'api_token_revoked', 'api_tokens', $tokenId, []);
                $success = t('admin_api-tokens.token_revoked');
            }
        } elseif ($action === 'reactivate_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                $apiService->reactivateToken($tokenId);
                $auth->audit($user['id'], 'api_token_reactivated', 'api_tokens', $tokenId, []);
                $success = t('admin_api-tokens.token_reactivated');
            }
        } elseif ($action === 'delete_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                $apiService->deleteToken($tokenId);
                $auth->audit($user['id'], 'api_token_deleted', 'api_tokens', $tokenId, []);
                $success = t('admin_api-tokens.token_deleted');
            }
        } elseif ($action === 'edit_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                $name = trim($_POST['token_name'] ?? '');
                if (empty($name)) {
                    $error = t('admin_api-tokens.token_name_required');
                } else {
                    $apiService->updateToken($tokenId, [
                        'name' => $name,
                        'description' => trim($_POST['token_description'] ?? ''),
                        'scope' => in_array($_POST['token_scope'] ?? '', ['read', 'read_write']) ? $_POST['token_scope'] : 'read',
                        'module_access' => json_encode($_POST['module_access'] ?? ['both']),
                        'user_id' => (int)($_POST['token_user_id'] ?? $user['id']),
                        'rate_limit_per_minute' => max(1, min(1000, (int)($_POST['rate_limit'] ?? 60))),
                        'expires_at' => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
                    ]);
                    $auth->audit($user['id'], 'api_token_updated', 'api_tokens', $tokenId, ['name' => $name]);
                    $success = t('admin_api-tokens.token_updated');
                }
            }
        } elseif ($action === 'add_ip') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $ip = trim($_POST['ip_address'] ?? '');
            $label = trim($_POST['ip_label'] ?? '');

            if (strpos($ip, '/') !== false) {
                $error = t('admin_api-tokens.cidr_not_allowed');
            } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $error = t('admin_api-tokens.invalid_ip');
            } else {
                if ($apiService->addTokenIp($tokenId, $ip, $label)) {
                    $auth->audit($user['id'], 'api_token_ip_added', 'api_token_ips', $tokenId, ['ip' => $ip]);
                    $success = t('admin_api-tokens.ip_added');
                } else {
                    $error = t('admin_api-tokens.ip_already_whitelisted');
                }
            }
        } elseif ($action === 'remove_ip') {
            $ipId = (int)($_POST['ip_id'] ?? 0);
            if ($ipId > 0) {
                $db->delete('api_token_ips', 'id = :id', [':id' => $ipId]);
                $success = t('admin_api-tokens.ip_removed');
            }
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$tokens = $apiService->getTokens();
$apiTab = $_GET['api_tab'] ?? 'tokens';
$activeUsers = $db->fetchAll('SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name');

// Request logs (if viewing logs tab)
$requestLogs = [];
if ($apiTab === 'logs') {
    $logFilters = [];
    if (!empty($_GET['log_token_id'])) $logFilters['token_id'] = (int)$_GET['log_token_id'];
    $requestLogs = $apiService->getRequestLogs($logFilters, 100);
}

// Selected token IPs
$selectedTokenIps = [];
$selectedTokenId = (int)($_GET['manage_token'] ?? 0);
if ($selectedTokenId > 0) {
    $selectedTokenIps = $apiService->getTokenIps($selectedTokenId);
}

// Edit token view
$editTokenId = (int)($_GET['edit_token'] ?? 0);
$editToken = null;
$editTokenIps = [];
if ($editTokenId > 0) {
    $editToken = $apiService->getToken($editTokenId);
    $editTokenIps = $apiService->getTokenIps($editTokenId);
    if ($editToken) $apiTab = 'edit';
}

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_api-tokens.page_title')); ?></h1>
    <p><?php echo e(t('admin_api-tokens.page_desc')); ?></p>
</div>

<!-- Tabs -->
<style>
    .api-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
    .api-tabs a {
        display:inline-block; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:500;
        text-decoration:none; color:#374151; background:#f3f4f6; border:1px solid #e5e7eb; transition:all 0.2s;
    }
    .api-tabs a:hover { background:#e5e7eb; color:#111827; }
    .api-tabs a.active { background:#374151; color:#fff; border-color:#374151; }
</style>
<div class="api-tabs">
    <a href="admin.php?section=api-management&api_tab=tokens" class="<?php echo $apiTab === 'tokens' ? 'active' : ''; ?>"><?php echo e(t('admin_api-tokens.tab_tokens')); ?></a>
    <a href="admin.php?section=api-management&api_tab=create" class="<?php echo $apiTab === 'create' ? 'active' : ''; ?>"><?php echo e(t('admin_api-tokens.tab_create')); ?></a>
    <a href="admin.php?section=api-management&api_tab=logs" class="<?php echo $apiTab === 'logs' ? 'active' : ''; ?>"><?php echo e(t('admin_api-tokens.tab_logs')); ?></a>
    <a href="admin.php?section=api-management&api_tab=playground" class="<?php echo $apiTab === 'playground' ? 'active' : ''; ?>"><?php echo e(t('admin_api-tokens.tab_playground')); ?></a>
    <a href="admin.php?section=api-management&api_tab=docs" class="<?php echo $apiTab === 'docs' ? 'active' : ''; ?>"><?php echo e(t('admin_api-tokens.tab_docs')); ?></a>
</div>

<?php if ($newTokenRaw): ?>
<div class="card" style="background:#fff3cd;border:2px solid #f59e0b;margin-bottom:20px;padding:20px;">
    <h3 style="margin:0 0 10px;color:#92400e;"><?php echo e(t('admin_api-tokens.new_token_heading')); ?></h3>
    <div style="background:#1f2937;color:#10b981;padding:16px;border-radius:8px;font-family:monospace;font-size:14px;word-break:break-all;margin-bottom:12px;">
        <?php echo e($newTokenRaw); ?>
    </div>
    <p style="color:#92400e;font-size:13px;margin:0;"><?php echo e(t('admin_api-tokens.copy_token_now')); ?></p>
</div>
<?php endif; ?>

<?php if ($apiTab === 'tokens'): ?>
<!-- Token List -->
<div class="card">
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_prefix')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_name')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_scope')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_user')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_ips')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_requests_24h')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_last_used')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_status')); ?></th>
                <th style="padding:12px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_actions')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tokens as $t): ?>
            <tr style="border-bottom:1px solid #e5e7eb;">
                <td style="padding:12px;font-family:monospace;font-size:12px;"><?php echo e($t['token_prefix']); ?>...</td>
                <td style="padding:12px;"><?php echo e($t['name']); ?></td>
                <td style="padding:12px;"><span style="padding:2px 8px;border-radius:4px;font-size:11px;background:<?php echo $t['scope'] === 'read_write' ? '#fee2e2' : '#e0f2fe'; ?>"><?php echo e($t['scope']); ?></span></td>
                <td style="padding:12px;font-size:12px;"><?php echo e($t['user_name']); ?></td>
                <td style="padding:12px;"><?php echo (int)$t['ip_count']; ?></td>
                <td style="padding:12px;"><?php echo number_format((int)$t['requests_24h']); ?></td>
                <td style="padding:12px;font-size:12px;"><?php echo $t['last_used_at'] ? e(date('M j, g:i A', strtotime($t['last_used_at']))) : '<span style="color:#9ca3af;">' . t('admin_api-tokens.never_used') . '</span>'; ?></td>
                <td style="padding:12px;">
                    <?php if ($t['is_active']): ?>
                    <span style="color:#28a745;font-size:12px;"><?php echo e(t('admin_api-tokens.status_active')); ?></span>
                    <?php else: ?>
                    <span style="color:#dc3545;font-size:12px;"><?php echo e(t('admin_api-tokens.status_revoked')); ?></span>
                    <?php endif; ?>
                </td>
                <td style="padding:12px;white-space:nowrap;">
                    <a href="admin.php?section=api-management&edit_token=<?php echo $t['id']; ?>" style="font-size:12px;margin-right:8px;"><?php echo e(t('admin_api-tokens.action_edit')); ?></a>
                    <?php if ($t['is_active']): ?>
                    <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_api-tokens.confirm_revoke')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="api_action" value="revoke_token">
                        <input type="hidden" name="token_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" style="border:none;background:none;color:#d97706;cursor:pointer;font-size:12px;"><?php echo e(t('admin_api-tokens.action_revoke')); ?></button>
                    </form>
                    <?php else: ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="api_action" value="reactivate_token">
                        <input type="hidden" name="token_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" style="border:none;background:none;color:#059669;cursor:pointer;font-size:12px;"><?php echo e(t('admin_api-tokens.action_reactivate')); ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_api-tokens.confirm_delete')); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="api_action" value="delete_token">
                        <input type="hidden" name="token_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:12px;"><?php echo e(t('admin_api-tokens.action_delete')); ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tokens)): ?>
            <tr><td colspan="9" style="padding:40px;text-align:center;color:#9ca3af;"><?php echo e(t('admin_api-tokens.no_tokens')); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($apiTab === 'edit' && $editToken): ?>
<!-- Edit Token -->
<div class="card">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.edit_token_prefix')); ?> <?php echo e($editToken['name']); ?> <span style="font-weight:400;font-size:12px;color:#6b7280;">(<?php echo e($editToken['token_prefix']); ?>...)</span></h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="api_action" value="edit_token">
        <input type="hidden" name="token_id" value="<?php echo $editToken['id']; ?>">

        <div class="form-row">
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_token_name')); ?></label>
                <input type="text" name="token_name" class="form-control" required value="<?php echo e($editToken['name']); ?>">
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_api_user')); ?></label>
                <select name="token_user_id" class="form-control">
                    <?php foreach ($activeUsers as $u): ?>
                    <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $editToken['user_id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?> (<?php echo e($u['email']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_description')); ?></label>
            <textarea name="token_description" class="form-control" rows="2"><?php echo e($editToken['description'] ?? ''); ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_access_scope')); ?></label>
                <select name="token_scope" class="form-control">
                    <option value="read" <?php echo $editToken['scope'] === 'read' ? 'selected' : ''; ?>><?php echo e(t('admin_api-tokens.scope_read')); ?></option>
                    <option value="read_write" <?php echo $editToken['scope'] === 'read_write' ? 'selected' : ''; ?>><?php echo e(t('admin_api-tokens.scope_read_write')); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_module_access')); ?></label>
                <?php $currentModules = json_decode($editToken['module_access'] ?? '["both"]', true) ?: ['both']; ?>
                <select name="module_access[]" class="form-control" multiple size="3">
                    <option value="both" <?php echo in_array('both', $currentModules) ? 'selected' : ''; ?>><?php echo e(t('admin_api-tokens.module_both')); ?></option>
                    <option value="tprm" <?php echo in_array('tprm', $currentModules) ? 'selected' : ''; ?>><?php echo e(t('admin_api-tokens.module_tprm')); ?></option>
                    <option value="grc" <?php echo in_array('grc', $currentModules) ? 'selected' : ''; ?>><?php echo e(t('admin_api-tokens.module_grc')); ?></option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_rate_limit')); ?></label>
                <input type="number" name="rate_limit" class="form-control" value="<?php echo (int)$editToken['rate_limit_per_minute']; ?>" min="1" max="1000">
            </div>
            <div class="form-group">
                <label><?php echo e(t('admin_api-tokens.label_expiration')); ?></label>
                <input type="datetime-local" name="expires_at" class="form-control" value="<?php echo $editToken['expires_at'] ? date('Y-m-d\TH:i', strtotime($editToken['expires_at'])) : ''; ?>">
                <div class="form-help"><?php echo e(t('admin_api-tokens.help_no_expiration')); ?></div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:10px;">
            <button type="submit" class="btn btn-primary"><?php echo e(t('admin_api-tokens.btn_save_changes')); ?></button>
            <a href="admin.php?section=api-management&api_tab=tokens" class="btn btn-secondary"><?php echo e(t('admin_api-tokens.btn_cancel')); ?></a>
        </div>
    </form>
</div>

<!-- IP Whitelist -->
<div class="card" style="margin-top:20px;">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.ip_whitelist_heading')); ?></h3>
    <?php if (!empty($editTokenIps)): ?>
    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
        <thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
            <th style="padding:10px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_ip_address')); ?></th>
            <th style="padding:10px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_label')); ?></th>
            <th style="padding:10px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_added')); ?></th>
            <th style="padding:10px;text-align:left;font-size:12px;"><?php echo e(t('admin_api-tokens.col_action')); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($editTokenIps as $tip): ?>
        <tr style="border-bottom:1px solid #e5e7eb;">
            <td style="padding:10px;font-family:monospace;"><?php echo e($tip['ip_address']); ?></td>
            <td style="padding:10px;"><?php echo e($tip['label'] ?? ''); ?></td>
            <td style="padding:10px;font-size:12px;"><?php echo e(date('M j, Y', strtotime($tip['created_at']))); ?></td>
            <td style="padding:10px;">
                <form method="POST" style="display:inline;" data-confirm="<?php echo e(t('admin_api-tokens.confirm_remove_ip')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="api_action" value="remove_ip">
                    <input type="hidden" name="ip_id" value="<?php echo $tip['id']; ?>">
                    <button type="submit" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:12px;"><?php echo e(t('admin_api-tokens.action_remove')); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color:#9ca3af;font-size:13px;margin-bottom:16px;"><?php echo e(t('admin_api-tokens.no_ips')); ?></p>
    <?php endif; ?>
    <form method="POST" action="admin.php?section=api-management&edit_token=<?php echo $editToken['id']; ?>" style="display:flex;gap:10px;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="api_action" value="add_ip">
        <input type="hidden" name="token_id" value="<?php echo $editToken['id']; ?>">
        <div style="flex:1;">
            <label style="font-size:12px;display:block;margin-bottom:4px;"><?php echo e(t('admin_api-tokens.label_ip_single')); ?></label>
            <input type="text" name="ip_address" class="form-control" placeholder="192.168.1.100" required pattern="^[0-9a-fA-F.:]+$">
        </div>
        <div style="flex:1;">
            <label style="font-size:12px;display:block;margin-bottom:4px;"><?php echo e(t('admin_api-tokens.label_ip_label_optional')); ?></label>
            <input type="text" name="ip_label" class="form-control" placeholder="<?php echo e(t('admin_api-tokens.placeholder_office_gateway')); ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="height:38px;"><?php echo e(t('admin_api-tokens.btn_add_ip')); ?></button>
    </form>
</div>

<!-- Token Info -->
<div class="card" style="margin-top:20px;">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.token_info_heading')); ?></h3>
    <table style="font-size:13px;">
        <tr><td style="padding:6px 20px 6px 0;color:#6b7280;font-weight:500;"><?php echo e(t('admin_api-tokens.info_status')); ?></td><td><?php echo $editToken['is_active'] ? '<span style="color:#059669;">' . t('admin_api-tokens.status_active') . '</span>' : '<span style="color:#dc2626;">' . t('admin_api-tokens.status_revoked') . '</span>'; ?></td></tr>
        <tr><td style="padding:6px 20px 6px 0;color:#6b7280;font-weight:500;"><?php echo e(t('admin_api-tokens.info_created')); ?></td><td><?php echo e(date('M j, Y g:i A', strtotime($editToken['created_at']))); ?></td></tr>
        <tr><td style="padding:6px 20px 6px 0;color:#6b7280;font-weight:500;"><?php echo e(t('admin_api-tokens.info_last_used')); ?></td><td><?php echo $editToken['last_used_at'] ? e(date('M j, Y g:i A', strtotime($editToken['last_used_at']))) : t('admin_api-tokens.never_used'); ?></td></tr>
        <tr><td style="padding:6px 20px 6px 0;color:#6b7280;font-weight:500;"><?php echo e(t('admin_api-tokens.info_last_ip')); ?></td><td style="font-family:monospace;"><?php echo e($editToken['last_used_ip'] ?? 'N/A'); ?></td></tr>
        <tr><td style="padding:6px 20px 6px 0;color:#6b7280;font-weight:500;"><?php echo e(t('admin_api-tokens.info_total_requests')); ?></td><td><?php echo number_format((int)$editToken['request_count']); ?></td></tr>
    </table>
</div>

<?php elseif ($apiTab === 'create'): ?>
<!-- Create Token -->
<div class="card">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.create_heading')); ?></h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="api_action" value="create_token">

        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_token_name')); ?></label>
            <input type="text" name="token_name" class="form-control" required placeholder="<?php echo e(t('admin_api-tokens.placeholder_token_name')); ?>">
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_description')); ?></label>
            <textarea name="token_description" class="form-control" rows="2" placeholder="<?php echo e(t('admin_api-tokens.placeholder_description')); ?>"></textarea>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_access_scope')); ?></label>
            <select name="token_scope" class="form-control">
                <option value="read"><?php echo e(t('admin_api-tokens.scope_read')); ?></option>
                <option value="read_write"><?php echo e(t('admin_api-tokens.scope_read_write')); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_module_access')); ?></label>
            <select name="module_access[]" class="form-control" multiple size="3">
                <option value="both" selected><?php echo e(t('admin_api-tokens.module_both')); ?></option>
                <option value="tprm"><?php echo e(t('admin_api-tokens.module_tprm')); ?></option>
                <option value="grc"><?php echo e(t('admin_api-tokens.module_grc')); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_api_user')); ?></label>
            <select name="token_user_id" class="form-control">
                <?php foreach ($activeUsers as $u): ?>
                <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $user['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?> (<?php echo e($u['email']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="form-help"><?php echo e(t('admin_api-tokens.help_token_user')); ?></div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_rate_limit')); ?></label>
            <input type="number" name="rate_limit" class="form-control" value="60" min="1" max="1000">
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_expiration_optional')); ?></label>
            <input type="datetime-local" name="expires_at" class="form-control">
            <div class="form-help"><?php echo e(t('admin_api-tokens.help_no_expiration')); ?></div>
        </div>
        <div class="form-group">
            <label><?php echo e(t('admin_api-tokens.label_whitelisted_ips')); ?></label>
            <textarea name="allowed_ips" class="form-control" rows="4" required placeholder="192.168.1.100&#10;10.0.0.5&#10;2001:db8::1"></textarea>
            <div class="form-help"><?php echo e(t('admin_api-tokens.help_no_cidr')); ?></div>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_api-tokens.btn_create_token')); ?></button>
    </form>
</div>

<?php elseif ($apiTab === 'logs'): ?>
<!-- Request Logs -->
<div class="card">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.logs_heading')); ?></h3>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_time')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_token')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_method')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_endpoint')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_ip')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_status')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_time_ms')); ?></th>
            <th style="padding:10px;text-align:left;font-size:11px;"><?php echo e(t('admin_api-tokens.log_error')); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($requestLogs as $log): ?>
        <tr style="border-bottom:1px solid #e5e7eb;">
            <td style="padding:8px;font-size:11px;"><?php echo e(date('M j, g:i:s A', strtotime($log['created_at']))); ?></td>
            <td style="padding:8px;font-size:11px;font-family:monospace;"><?php echo e($log['token_prefix'] ?? 'N/A'); ?></td>
            <td style="padding:8px;font-size:11px;"><span style="font-weight:600;"><?php echo e($log['method']); ?></span></td>
            <td style="padding:8px;font-size:11px;font-family:monospace;"><?php echo e($log['endpoint']); ?></td>
            <td style="padding:8px;font-size:11px;font-family:monospace;"><?php echo e($log['request_ip']); ?></td>
            <td style="padding:8px;font-size:11px;">
                <span style="padding:2px 6px;border-radius:3px;background:<?php echo $log['response_code'] < 400 ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $log['response_code'] < 400 ? '#166534' : '#991b1b'; ?>;"><?php echo (int)$log['response_code']; ?></span>
            </td>
            <td style="padding:8px;font-size:11px;"><?php echo $log['response_time_ms'] !== null ? (int)$log['response_time_ms'] : '-'; ?></td>
            <td style="padding:8px;font-size:11px;color:#dc3545;"><?php echo e($log['error_message'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requestLogs)): ?>
        <tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;"><?php echo e(t('admin_api-tokens.no_logs')); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($apiTab === 'playground'):
    $baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $swaggerSpec = $apiService->getSwaggerSpec($baseUrl);
    $paths = $swaggerSpec['paths'] ?? [];
    // Group endpoints by tag
    $tagGroups = [];
    foreach ($paths as $path => $methods) {
        foreach ($methods as $method => $spec) {
            $tag = $spec['tags'][0] ?? 'Other';
            $tagGroups[$tag][] = ['path' => $path, 'method' => strtoupper($method), 'spec' => $spec];
        }
    }
?>
<!-- Swagger-style API Playground -->
<style>
    .play-auth { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:20px; }
    .play-auth label { font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:6px; }
    .play-auth input { width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:6px; font-family:'Roboto Mono',monospace; font-size:13px; }
    .play-tag-group { margin-bottom:16px; }
    .play-tag-header { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; padding:8px 0; border-bottom:1px solid #e5e7eb; margin-bottom:4px; }
    .play-endpoint { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:6px; overflow:hidden; background:#fff; }
    .play-endpoint.open { box-shadow:0 2px 8px rgba(0,0,0,0.08); }
    .play-ep-header { display:flex; align-items:center; gap:10px; padding:10px 16px; cursor:pointer; user-select:none; }
    .play-ep-header:hover { background:#f9fafb; }
    .play-method { display:inline-block; min-width:56px; padding:3px 0; border-radius:4px; font-size:11px; font-weight:700; text-align:center; color:#fff; text-transform:uppercase; }
    .play-method.get { background:#3b82f6; }
    .play-method.post { background:#22c55e; }
    .play-method.put { background:#f59e0b; }
    .play-method.delete { background:#ef4444; }
    .play-ep-path { font-family:'Roboto Mono',monospace; font-size:13px; color:#1f2937; font-weight:500; }
    .play-ep-summary { font-size:12px; color:#6b7280; margin-left:auto; white-space:nowrap; }
    .play-ep-body { display:none; border-top:1px solid #e5e7eb; padding:16px; background:#f9fafb; }
    .play-endpoint.open .play-ep-body { display:block; }
    .play-ep-desc { font-size:13px; color:#4b5563; margin-bottom:14px; line-height:1.5; }
    .play-params { margin-bottom:14px; }
    .play-params table { width:100%; border-collapse:collapse; font-size:12px; }
    .play-params th { text-align:left; padding:6px 10px; background:#f3f4f6; border-bottom:1px solid #e5e7eb; font-weight:600; color:#374151; }
    .play-params td { padding:6px 10px; border-bottom:1px solid #f3f4f6; color:#4b5563; }
    .play-params input { padding:5px 8px; border:1px solid #d1d5db; border-radius:4px; font-size:12px; width:100%; font-family:'Roboto Mono',monospace; }
    .play-params .req { color:#ef4444; font-weight:600; }
    .play-body-input { margin-bottom:14px; }
    .play-body-input label { font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
    .play-body-input textarea { width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-family:'Roboto Mono',monospace; font-size:12px; min-height:80px; resize:vertical; }
    .play-actions { display:flex; gap:10px; align-items:center; margin-bottom:14px; }
    .play-try-btn { padding:8px 20px; background:#374151; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; }
    .play-try-btn:hover { background:#1f2937; }
    .play-response { margin-top:14px; }
    .play-resp-bar { display:flex; gap:12px; align-items:center; margin-bottom:8px; }
    .play-resp-code { font-size:13px; font-weight:700; padding:2px 8px; border-radius:4px; }
    .play-resp-code.ok { background:#dcfce7; color:#166534; }
    .play-resp-code.err { background:#fee2e2; color:#991b1b; }
    .play-resp-time { font-size:12px; color:#6b7280; }
    .play-resp-body { background:#1e293b; color:#e2e8f0; padding:14px; border-radius:6px; font-family:'Roboto Mono',monospace; font-size:11px; max-height:350px; overflow:auto; white-space:pre-wrap; line-height:1.5; }
    .play-code-tabs { display:flex; gap:4px; margin-bottom:8px; }
    .play-code-tab { padding:5px 12px; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; border:1px solid #d1d5db; background:#fff; color:#374151; }
    .play-code-tab.active { background:#374151; color:#fff; border-color:#374151; }
    .play-code-block { background:#1e293b; color:#e2e8f0; padding:14px; border-radius:6px; font-family:'Roboto Mono',monospace; font-size:11px; overflow-x:auto; white-space:pre-wrap; line-height:1.5; position:relative; }
    .play-copy-btn { position:absolute; top:8px; right:8px; padding:3px 8px; background:rgba(255,255,255,0.15); color:#94a3b8; border:none; border-radius:4px; font-size:10px; cursor:pointer; }
    .play-copy-btn:hover { background:rgba(255,255,255,0.25); color:#fff; }
</style>

<div class="play-auth">
    <label><?php echo e(t('admin_api-tokens.bearer_token_label')); ?></label>
    <input type="password" id="playToken" placeholder="tprm_your_api_token_here">
    <div style="margin-top:8px;font-size:12px;color:#6b7280;">
        <?php echo e(t('admin_api-tokens.detected_ip_prefix')); ?> <strong style="font-family:'Roboto Mono',monospace;color:#374151;"><?php echo e($security->getClientIP()); ?></strong><?php echo e(t('admin_api-tokens.detected_ip_suffix')); ?>
    </div>
</div>

<?php foreach ($tagGroups as $tag => $endpoints): ?>
<div class="play-tag-group">
    <div class="play-tag-header"><?php echo e($tag); ?></div>
    <?php foreach ($endpoints as $i => $ep):
        $uid = 'ep_' . md5($ep['method'] . $ep['path']);
        $params = $ep['spec']['parameters'] ?? [];
        $pathParams = array_filter($params, function($p) { return $p['in'] === 'path'; });
        $queryParams = array_filter($params, function($p) { return $p['in'] === 'query'; });
        $hasBody = in_array($ep['method'], ['POST', 'PUT']);
    ?>
    <div class="play-endpoint" id="<?php echo $uid; ?>">
        <div class="play-ep-header">
            <span class="play-method <?php echo strtolower($ep['method']); ?>"><?php echo $ep['method']; ?></span>
            <span class="play-ep-path"><?php echo e($ep['path']); ?></span>
            <span class="play-ep-summary"><?php echo e($ep['spec']['summary'] ?? ''); ?></span>
        </div>
        <div class="play-ep-body">
            <?php if (!empty($ep['spec']['description'])): ?>
            <div class="play-ep-desc"><?php echo e($ep['spec']['description']); ?></div>
            <?php endif; ?>

            <?php if (!empty($params)): ?>
            <div class="play-params">
                <table>
                    <thead><tr><th><?php echo e(t('admin_api-tokens.param_parameter')); ?></th><th><?php echo e(t('admin_api-tokens.param_in')); ?></th><th><?php echo e(t('admin_api-tokens.param_required')); ?></th><th><?php echo e(t('admin_api-tokens.param_value')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($params as $p): ?>
                    <tr>
                        <td><strong><?php echo e($p['name']); ?></strong><?php if (!empty($p['description'])): ?><br><span style="color:#9ca3af;font-size:11px;"><?php echo e($p['description']); ?></span><?php endif; ?></td>
                        <td><?php echo e($p['in']); ?></td>
                        <td><?php echo !empty($p['required']) ? '<span class="req">' . t('admin_api-tokens.param_yes') . '</span>' : t('admin_api-tokens.param_no'); ?></td>
                        <td><input type="text" data-ep="<?php echo $uid; ?>" data-param="<?php echo e($p['name']); ?>" data-in="<?php echo e($p['in']); ?>" placeholder="<?php echo e($p['schema']['default'] ?? ''); ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($hasBody): ?>
            <div class="play-body-input">
                <label><?php echo e(t('admin_api-tokens.request_body_label')); ?></label>
                <textarea id="<?php echo $uid; ?>_body"><?php
                    $rb = $ep['spec']['requestBody']['content']['application/json']['schema']['properties'] ?? null;
                    if ($rb) {
                        $example = [];
                        foreach ($rb as $k => $v) {
                            if (!empty($v['enum'])) {
                                $example[$k] = $v['enum'][0];
                            } elseif ($v['type'] === 'integer') {
                                $example[$k] = 0;
                            } elseif ($v['type'] === 'boolean') {
                                $example[$k] = true;
                            } else {
                                $example[$k] = '';
                            }
                        }
                        echo e(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    } else {
                        echo '{"key": "value"}';
                    }
                ?></textarea>
            </div>
            <?php endif; ?>

            <div class="play-actions">
                <button class="play-try-btn" data-uid="<?php echo $uid; ?>" data-method="<?php echo $ep['method']; ?>" data-path="<?php echo e($ep['path']); ?>"><?php echo e(t('admin_api-tokens.try_it_out')); ?></button>
            </div>

            <!-- Code Examples -->
            <div class="play-code-tabs" id="<?php echo $uid; ?>_tabs">
                <div class="play-code-tab active" data-lang="curl" data-uid="<?php echo $uid; ?>">cURL</div>
                <div class="play-code-tab" data-lang="php" data-uid="<?php echo $uid; ?>">PHP</div>
                <div class="play-code-tab" data-lang="python" data-uid="<?php echo $uid; ?>">Python</div>
                <div class="play-code-tab" data-lang="js" data-uid="<?php echo $uid; ?>">JavaScript</div>
            </div>
            <div style="position:relative;">
                <pre class="play-code-block" id="<?php echo $uid; ?>_code"></pre>
                <button class="play-copy-btn" data-copy-target="<?php echo $uid; ?>_code"><?php echo e(t('admin_api-tokens.btn_copy')); ?></button>
            </div>

            <!-- Response -->
            <div class="play-response" id="<?php echo $uid; ?>_resp" style="display:none;">
                <div class="play-resp-bar">
                    <span class="play-resp-code" id="<?php echo $uid; ?>_status"></span>
                    <span class="play-resp-time" id="<?php echo $uid; ?>_time"></span>
                </div>
                <pre class="play-resp-body" id="<?php echo $uid; ?>_body_resp"></pre>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    // Endpoint header click to expand/collapse
    document.querySelectorAll('.play-ep-header').forEach(function(hdr) {
        hdr.addEventListener('click', function() {
            this.parentElement.classList.toggle('open');
        });
    });

    // Copy button
    document.querySelectorAll('.play-copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = document.getElementById(this.getAttribute('data-copy-target'));
            if (target && navigator.clipboard) {
                navigator.clipboard.writeText(target.textContent);
                var orig = this.textContent;
                this.textContent = <?php echo json_encode(t('admin_api-tokens.js_copied')); ?>;
                var b = this;
                setTimeout(function() { b.textContent = orig; }, 1500);
            }
        });
    });

    var baseUrl = <?php echo json_encode(rtrim($baseUrl, '/') . '/api/v2'); ?>;

    function buildUrl(uid, pathTemplate) {
        var url = pathTemplate;
        var queryParts = [];
        document.querySelectorAll('input[data-ep="' + uid + '"]').forEach(function(inp) {
            var val = inp.value.trim();
            if (!val) return;
            if (inp.dataset.in === 'path') {
                url = url.replace('{' + inp.dataset.param + '}', encodeURIComponent(val));
            } else if (inp.dataset.in === 'query') {
                queryParts.push(encodeURIComponent(inp.dataset.param) + '=' + encodeURIComponent(val));
            }
        });
        if (queryParts.length) url += '?' + queryParts.join('&');
        return url;
    }

    function getToken() {
        return document.getElementById('playToken').value.trim();
    }

    function genCurl(method, fullUrl, token, body) {
        var s = "curl -sX " + method + " \\\n  '" + fullUrl + "' \\\n  -H 'Authorization: Bearer " + (token || 'YOUR_TOKEN') + "' \\\n  -H 'Content-Type: application/json'";
        if (body) s += " \\\n  -d '" + body + "'";
        return s;
    }
    function genPhp(method, fullUrl, token, body) {
        var s = "$ch = curl_init();\ncurl_setopt_array($ch, [\n    CURLOPT_URL => '" + fullUrl + "',\n    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_CUSTOMREQUEST => '" + method + "',\n    CURLOPT_HTTPHEADER => [\n        'Authorization: Bearer " + (token || 'YOUR_TOKEN') + "',\n        'Content-Type: application/json',\n    ],";
        if (body) s += "\n    CURLOPT_POSTFIELDS => '" + body + "',";
        s += "\n]);\n$response = curl_exec($ch);\n$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);\ncurl_close($ch);\n$data = json_decode($response, true);";
        return s;
    }
    function genPython(method, fullUrl, token, body) {
        var s = "import requests\n\nresponse = requests." + method.toLowerCase() + "(\n    '" + fullUrl + "',\n    headers={\n        'Authorization': 'Bearer " + (token || 'YOUR_TOKEN') + "',\n        'Content-Type': 'application/json',\n    },";
        if (body) s += "\n    json=" + body + ",";
        s += "\n)\ndata = response.json()";
        return s;
    }
    function genJs(method, fullUrl, token, body) {
        var s = "const response = await fetch('" + fullUrl + "', {\n    method: '" + method + "',\n    headers: {\n        'Authorization': 'Bearer " + (token || 'YOUR_TOKEN') + "',\n        'Content-Type': 'application/json',\n    },";
        if (body) s += "\n    body: JSON.stringify(" + body + "),";
        s += "\n});\nconst data = await response.json();";
        return s;
    }

    var generators = { curl: genCurl, php: genPhp, python: genPython, js: genJs };

    function updateCode(uid, method, pathTemplate) {
        var url = buildUrl(uid, pathTemplate);
        var fullUrl = baseUrl + url;
        var token = getToken();
        var bodyEl = document.getElementById(uid + '_body');
        var body = bodyEl ? bodyEl.value.trim() : '';
        var activeTab = document.querySelector('.play-code-tab.active[data-uid="' + uid + '"]');
        var lang = activeTab ? activeTab.dataset.lang : 'curl';
        var gen = generators[lang] || genCurl;
        document.getElementById(uid + '_code').textContent = gen(method, fullUrl, token, body);
    }

    // Tab clicks
    document.querySelectorAll('.play-code-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var uid = this.dataset.uid;
            document.querySelectorAll('.play-code-tab[data-uid="' + uid + '"]').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            var btn = document.querySelector('.play-try-btn[data-uid="' + uid + '"]');
            if (btn) updateCode(uid, btn.dataset.method, btn.dataset.path);
        });
    });

    // Initialize code examples and listen for param changes
    document.querySelectorAll('.play-try-btn').forEach(function(btn) {
        var uid = btn.dataset.uid;
        // Initial render
        updateCode(uid, btn.dataset.method, btn.dataset.path);

        // Update code when params change
        document.querySelectorAll('input[data-ep="' + uid + '"]').forEach(function(inp) {
            inp.addEventListener('input', function() { updateCode(uid, btn.dataset.method, btn.dataset.path); });
        });
        var bodyEl = document.getElementById(uid + '_body');
        if (bodyEl) bodyEl.addEventListener('input', function() { updateCode(uid, btn.dataset.method, btn.dataset.path); });

        // Try it out
        btn.addEventListener('click', function() {
            var method = btn.dataset.method;
            var url = buildUrl(uid, btn.dataset.path);
            var fullUrl = baseUrl + url;
            var token = getToken();
            var bodyEl = document.getElementById(uid + '_body');
            var body = bodyEl ? bodyEl.value.trim() : '';

            if (!token) { alert(<?php echo json_encode(t('admin_api-tokens.js_enter_bearer')); ?>); return; }

            btn.disabled = true;
            btn.textContent = <?php echo json_encode(t('admin_api-tokens.js_sending')); ?>;
            var start = Date.now();
            var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' } };
            if (method !== 'GET' && body) opts.body = body;

            fetch(fullUrl, opts).then(function(r) {
                var elapsed = Date.now() - start;
                var statusEl = document.getElementById(uid + '_status');
                statusEl.textContent = 'HTTP ' + r.status;
                statusEl.className = 'play-resp-code ' + (r.status < 400 ? 'ok' : 'err');
                document.getElementById(uid + '_time').textContent = elapsed + 'ms';
                return r.text();
            }).then(function(text) {
                try { text = JSON.stringify(JSON.parse(text), null, 2); } catch(e) {}
                document.getElementById(uid + '_body_resp').textContent = text;
                document.getElementById(uid + '_resp').style.display = 'block';
                btn.disabled = false;
                btn.textContent = <?php echo json_encode(t('admin_api-tokens.try_it_out')); ?>;
            }).catch(function(e) {
                document.getElementById(uid + '_body_resp').textContent = <?php echo json_encode(t('admin_api-tokens.js_network_error')); ?> + e.message;
                document.getElementById(uid + '_resp').style.display = 'block';
                btn.disabled = false;
                btn.textContent = <?php echo json_encode(t('admin_api-tokens.try_it_out')); ?>;
            });
        });
    });

    // Update all code blocks when token changes
    document.getElementById('playToken').addEventListener('input', function() {
        document.querySelectorAll('.play-try-btn').forEach(function(btn) {
            updateCode(btn.dataset.uid, btn.dataset.method, btn.dataset.path);
        });
    });
})();
</script>

<?php elseif ($apiTab === 'docs'): ?>
<!-- Swagger / Postman Documentation -->
<div class="card">
    <h3 style="margin:0 0 16px;"><?php echo e(t('admin_api-tokens.docs_heading')); ?></h3>
    <p style="color:#6b7280;margin-bottom:20px;"><?php echo e(t('admin_api-tokens.docs_desc')); ?></p>

    <div style="display:flex;gap:16px;">
        <a href="/api/v2/swagger.json" target="_blank" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
            <img src="app/icons/file-07.svg" alt="" width="16" height="16"> OpenAPI / Swagger JSON
        </a>
        <a href="/api/v2/postman" target="_blank" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;background:#f59e0b;border-color:#f59e0b;">
            <img src="app/icons/file-03.svg" alt="" width="16" height="16"> Postman Collection
        </a>
    </div>

    <div style="margin-top:24px;">
        <h4 style="font-size:14px;margin:0 0 8px;"><?php echo e(t('admin_api-tokens.docs_auth_heading')); ?></h4>
        <p style="font-size:13px;color:#374151;"><?php echo e(t('admin_api-tokens.docs_auth_desc')); ?></p>
        <pre style="background:#1f2937;color:#a3e635;padding:12px;border-radius:6px;font-size:12px;">Authorization: Bearer tprm_your_token_here</pre>
        <p style="font-size:13px;color:#374151;margin-top:8px;"><?php echo e(t('admin_api-tokens.docs_ip_note')); ?></p>
    </div>

    <div style="margin-top:24px;">
        <h4 style="font-size:14px;margin:0 0 8px;"><?php echo e(t('admin_api-tokens.docs_base_url_heading')); ?></h4>
        <pre style="background:#f3f4f6;color:#374151;padding:12px;border-radius:6px;font-size:12px;"><?php echo e($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')); ?>/api/v2</pre>
    </div>
</div>
<?php endif; ?>
