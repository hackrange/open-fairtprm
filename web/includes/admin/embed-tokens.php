<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Iframe Embed Tokens
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Super-admin-only. Generates signed embed tokens that let external intranet
 * portals (e.g., Assembly) embed FairTPRM in an iframe without altering the
 * main app's SameSite=Strict session cookies.
 *
 * Token flow:
 *   1. Admin creates a token here, specifying the user the embed runs as and
 *      the portal origin (e.g., https://app.assembly.com).
 *   2. The raw token is shown exactly once and must be copied immediately.
 *   3. The admin pastes the generated embed URL into the portal's iframe src.
 *   4. embed-auth.php validates the token and creates a SameSite=None embed
 *      session that persists in cross-site iframes.
 *
 * Available from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

if (!$isAdmin) {
    echo '<p>Super-admin access required.</p>';
    return;
}

$proto   = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
         ? $_SERVER['HTTP_X_FORWARDED_PROTO']
         : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// One-time flash: raw token shown exactly once then cleared from session.
// admin.php includes this file twice (POST pass, then render pass), so read
// the flash only on the first pass and keep it for the render pass.
if (!isset($embedFlash)) {
    $embedFlash = [$_SESSION['_flash_embed_token'] ?? null, $_SESSION['_flash_embed_url'] ?? null];
    unset($_SESSION['_flash_embed_token'], $_SESSION['_flash_embed_url']);
}
[$newTokenRaw, $newTokenUrl] = $embedFlash;

// ============================================================================
// POST Handlers
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['embed_action'] ?? '';

        if ($action === 'create_embed_token') {
            $name        = trim($_POST['token_name'] ?? '');
            $description = trim($_POST['token_description'] ?? '');
            $userId      = (int)($_POST['embed_user_id'] ?? 0);
            $rawOrigins  = trim($_POST['allowed_origins'] ?? '');
            $expiresAt   = !empty(trim($_POST['expires_at'] ?? '')) ? trim($_POST['expires_at']) : null;
            $returnPath  = trim($_POST['return_path'] ?? '/');

            // Validate return path
            if (!preg_match('#^/(?:[^/]|$)#', $returnPath)) {
                $returnPath = '/';
            }

            // Validate origins: each line must be https://hostname or https://hostname:port
            $originLines = array_filter(array_map('trim', explode("\n", $rawOrigins)));
            $validOrigins = [];
            $badOrigins   = [];
            foreach ($originLines as $line) {
                if (preg_match('#^https://[a-zA-Z0-9._-]+(:\d+)?$#', $line)) {
                    $validOrigins[] = $line;
                } else {
                    $badOrigins[] = $line;
                }
            }

            if (empty($name)) {
                $error = 'Token name is required.';
            } elseif ($userId <= 0) {
                $error = 'Please select an embed user account.';
            } elseif (empty($validOrigins)) {
                $error = 'At least one valid HTTPS origin is required (e.g., https://app.assembly.com).';
            } elseif (!empty($badOrigins)) {
                $error = 'Invalid origins (must be https://hostname, no wildcards): ' . e(implode(', ', $badOrigins));
            } else {
                // Confirm user exists and is active
                $embedUser = $db->fetchOne('SELECT id FROM users WHERE id = ? AND is_active = 1', [$userId]);
                if (!$embedUser) {
                    $error = 'Selected user account is inactive or does not exist.';
                } else {
                    $rawToken   = bin2hex(random_bytes(32));
                    $tokenHash  = hash('sha256', $rawToken);
                    $originsStr = implode(',', $validOrigins);

                    $db->insert('embed_tokens', [
                        'token_hash'      => $tokenHash,
                        'name'            => $name,
                        'description'     => $description,
                        'user_id'         => $userId,
                        'allowed_origins' => $originsStr,
                        'created_by'      => (int)$user['id'],
                        'expires_at'      => $expiresAt,
                        'is_active'       => 1,
                    ]);

                    $tokenId = $db->lastInsertId();
                    $auth->audit((int)$user['id'], 'embed_token_created', 'embed_tokens', $tokenId, [
                        'name'    => $name,
                        'user_id' => $userId,
                        'origins' => $originsStr,
                    ]);

                    $embedUrl = $baseUrl . '/embed-auth.php?token=' . urlencode($rawToken)
                              . '&return=' . urlencode($returnPath);

                    // Store token and URL in session flash (read once and cleared above on next load)
                    $_SESSION['_flash_embed_token'] = $rawToken;
                    $_SESSION['_flash_embed_url']   = $embedUrl;

                    // Redirect so the POST isn't re-submitted on refresh
                    header('Location: admin.php?section=embed-tokens&created=1');
                    exit;
                }
            }

        } elseif ($action === 'revoke_embed_token') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if ($tokenId > 0) {
                $db->update('embed_tokens', ['is_active' => 0], 'id = :id', [':id' => $tokenId]);
                $auth->audit((int)$user['id'], 'embed_token_revoked', 'embed_tokens', $tokenId, []);
                $success = 'Embed token revoked.';
            }
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$tokens = $db->fetchAll(
    "SELECT et.id, et.name, et.description, et.allowed_origins,
            et.created_at, et.expires_at, et.last_used_at, et.is_active,
            u.full_name AS embed_user_name, u.email AS embed_user_email,
            cb.full_name AS created_by_name
     FROM embed_tokens et
     LEFT JOIN users u  ON u.id  = et.user_id
     LEFT JOIN users cb ON cb.id = et.created_by
     ORDER BY et.created_at DESC"
);

$activeUsers = $db->fetchAll(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name'
);

$showCreate = isset($_GET['created']) || !empty($_GET['new']);
?>
<div class="page-header-bar">
    <h1 class="page-title">Iframe Embed Tokens</h1>
    <p>Create signed tokens that let external portals (e.g., Assembly) embed FairTPRM in an iframe without exposing admin credentials.</p>
</div>

<?php if ($newTokenRaw): ?>
<div class="card" style="background:#fff3cd;border:2px solid #f59e0b;margin-bottom:20px;padding:20px;">
    <h3 style="margin:0 0 8px;color:#92400e;">&#9888; Copy Your Embed Token Now</h3>
    <p style="color:#92400e;font-size:13px;margin:0 0 12px;">This token is shown <strong>exactly once</strong>. It cannot be recovered after you leave this page.</p>
    <p style="font-weight:600;margin:0 0 4px;font-size:13px;color:#374151;">Raw token (keep private):</p>
    <div style="background:#1f2937;color:#10b981;padding:14px 16px;border-radius:8px;font-family:monospace;font-size:13px;word-break:break-all;margin-bottom:14px;">
        <?php echo e($newTokenRaw); ?>
    </div>
    <p style="font-weight:600;margin:0 0 4px;font-size:13px;color:#374151;">Embed URL (use as the iframe src):</p>
    <div style="background:#1f2937;color:#60a5fa;padding:14px 16px;border-radius:8px;font-family:monospace;font-size:13px;word-break:break-all;">
        <?php echo e($newTokenUrl); ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo e($success); ?></div>
<?php endif; ?>

<style>
    .embed-tabs { display:flex; gap:6px; margin-bottom:20px; }
    .embed-tabs a { display:inline-block; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:500;
        text-decoration:none; color:#374151; background:#f3f4f6; border:1px solid #e5e7eb; }
    .embed-tabs a:hover { background:#e5e7eb; }
    .embed-tabs a.active { background:#374151; color:#fff; border-color:#374151; }
    .embed-table { width:100%; border-collapse:collapse; font-size:13px; }
    .embed-table th { background:#f9fafb; border-bottom:2px solid #e5e7eb; padding:10px 12px; text-align:left; font-weight:600; color:#374151; }
    .embed-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
    .embed-table tr:last-child td { border-bottom:none; }
    .badge-active { background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
    .badge-revoked { background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
    .origin-list { font-size:12px; color:#6b7280; }
</style>

<?php $embedTab = $showCreate ? 'create' : ($_GET['tab'] ?? 'list'); ?>
<div class="embed-tabs">
    <a href="?section=embed-tokens&tab=list"   class="<?php echo $embedTab === 'list'   ? 'active' : ''; ?>">Active Tokens</a>
    <a href="?section=embed-tokens&tab=create" class="<?php echo $embedTab === 'create' ? 'active' : ''; ?>">Create Token</a>
    <a href="?section=embed-tokens&tab=guide"  class="<?php echo $embedTab === 'guide'  ? 'active' : ''; ?>">Setup Guide</a>
</div>

<?php if ($embedTab === 'list'): ?>
<div class="card">
    <?php if (empty($tokens)): ?>
        <p style="color:#6b7280;font-size:14px;padding:12px 0;">No embed tokens yet. <a href="?section=embed-tokens&tab=create">Create one.</a></p>
    <?php else: ?>
    <table class="embed-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Runs As</th>
                <th>Allowed Origins</th>
                <th>Created</th>
                <th>Last Used</th>
                <th>Expires</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $tok): ?>
            <tr>
                <td>
                    <strong><?php echo e($tok['name']); ?></strong>
                    <?php if ($tok['description']): ?>
                        <br><span style="color:#6b7280;font-size:12px;"><?php echo e($tok['description']); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo e($tok['embed_user_name'] ?? '—'); ?><br><span style="color:#9ca3af;font-size:11px;"><?php echo e($tok['embed_user_email'] ?? ''); ?></span></td>
                <td class="origin-list"><?php echo nl2br(e(str_replace(',', "\n", $tok['allowed_origins']))); ?></td>
                <td><?php echo e($tok['created_at'] ? date('M j, Y', strtotime($tok['created_at'])) : '—'); ?>
                    <?php if ($tok['created_by_name']): ?><br><span style="color:#9ca3af;font-size:11px;"><?php echo e($tok['created_by_name']); ?></span><?php endif; ?></td>
                <td><?php echo $tok['last_used_at'] ? e(date('M j, Y g:i A', strtotime($tok['last_used_at']))) : '<span style="color:#9ca3af;">Never</span>'; ?></td>
                <td><?php echo $tok['expires_at'] ? e(date('M j, Y', strtotime($tok['expires_at']))) : '<span style="color:#9ca3af;">Never</span>'; ?></td>
                <td><?php if ($tok['is_active']): ?><span class="badge-active">Active</span><?php else: ?><span class="badge-revoked">Revoked</span><?php endif; ?></td>
                <td>
                    <?php if ($tok['is_active']): ?>
                    <form method="post" action="admin.php?section=embed-tokens" class="embed-revoke-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="embed_action" value="revoke_embed_token">
                        <input type="hidden" name="token_id" value="<?php echo (int)$tok['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($embedTab === 'create'): ?>
<div class="card">
    <h3 style="margin:0 0 20px;font-size:16px;">Create Embed Token</h3>

    <div class="callout callout-warning" style="margin-bottom:20px;">
        <strong>Dedicated account recommended.</strong> Create a read-only user account (e.g., <em>embed-viewer@your-company.com</em>) and select it here. That way, portal visitors only see what that account can see, and revoking the account instantly cuts off embed access.
    </div>

    <form method="post" action="admin.php?section=embed-tokens">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="embed_action" value="create_embed_token">

        <div class="form-group">
            <label class="form-label">Token Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="token_name" class="form-control" placeholder="e.g., Assembly Portal" required maxlength="100">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="token_description" class="form-control" placeholder="Optional note for your own reference" maxlength="255">
        </div>

        <div class="form-group">
            <label class="form-label">Embed User Account <span style="color:#ef4444;">*</span></label>
            <select name="embed_user_id" class="form-control" required>
                <option value="">— Select a user —</option>
                <?php foreach ($activeUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['full_name']); ?> &lt;<?php echo e($u['email']); ?>&gt;</option>
                <?php endforeach; ?>
            </select>
            <p class="field-hint">The portal will appear as this user. Use a dedicated read-only account.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Allowed Origins <span style="color:#ef4444;">*</span></label>
            <textarea name="allowed_origins" class="form-control" rows="3"
                placeholder="https://app.assembly.com&#10;https://intranet.your-company.com"></textarea>
            <p class="field-hint">One origin per line. Must be <code>https://hostname</code> exactly — no wildcards, no paths, no trailing slashes. Only frames served from these origins will be allowed to display FairTPRM.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Default Return Path</label>
            <input type="text" name="return_path" class="form-control" value="/" placeholder="/">
            <p class="field-hint">The page the iframe will load after authentication (e.g., <code>/</code> for dashboard, <code>/grc-risks.php</code> for the risk register).</p>
        </div>

        <div class="form-group">
            <label class="form-label">Expiry Date (optional)</label>
            <input type="date" name="expires_at" class="form-control" style="max-width:220px;">
            <p class="field-hint">Leave blank for a token that never expires. You can always revoke it manually.</p>
        </div>

        <button type="submit" class="btn btn-primary">Generate Embed Token</button>
    </form>
</div>

<?php elseif ($embedTab === 'guide'): ?>
<div class="card">
    <h3 style="margin:0 0 16px;font-size:16px;">Assembly Portal Setup Guide</h3>

    <ol style="font-size:14px;color:#374151;line-height:1.8;padding-left:20px;">
        <li><strong>Create a dedicated embed user.</strong><br>
            Go to <a href="?section=users">Admin &rarr; Users</a> and add a new user (e.g., <em>embed-viewer@your-company.com</em>).
            Assign only the roles and groups needed for the view you want to embed.</li>

        <li style="margin-top:12px;"><strong>Generate an embed token.</strong><br>
            Click <a href="?section=embed-tokens&tab=create">Create Token</a>, select the embed user, enter your Assembly origin
            (e.g., <code>https://app.assembly.com</code>), and choose the return path.
            Copy the generated Embed URL — it is shown only once.</li>

        <li style="margin-top:12px;"><strong>Add the iframe in Assembly.</strong><br>
            In Assembly, create a new App or Tab with the iframe embed widget.
            Paste the Embed URL as the iframe <code>src</code>. No authentication credentials are needed in Assembly — the token handles everything.</li>

        <li style="margin-top:12px;"><strong>Test.</strong><br>
            Open the Assembly portal in a browser. The FairTPRM dashboard (or whichever return path you set) should appear
            inside the iframe without a login prompt. If you see "Access Denied", the token may have been revoked or the
            origin does not match exactly.</li>

        <li style="margin-top:12px;"><strong>Rotating or revoking tokens.</strong><br>
            Click Revoke on the Tokens tab to instantly cut off access. Create a new token and update the Assembly iframe src to rotate.</li>
    </ol>

    <div class="callout callout-info" style="margin-top:20px;">
        <strong>Security note.</strong> The embed session runs entirely in a separate browser cookie (<code>tprm_embed</code>).
        Your normal FairTPRM login session is not affected. The embed token is never stored in plaintext — only a SHA-256 hash is kept in the database.
        Allowed origins are enforced at the HTTP header level on every request inside the iframe, so only your specified Assembly domain can frame the app.
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.embed-revoke-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm('Revoke this embed token? Any iframe using it will stop working immediately.')) {
                e.preventDefault();
            }
        });
    });
});
</script>
