<?php
/**
 * Embed Auth - Token-based iframe session bootstrap
 *
 * Validates an embed token and creates a SameSite=None embed session.
 * Used as the iframe src URL for external portal integrations (e.g., Assembly).
 *
 * Flow:
 *   1. Admin creates an embed token in Admin > Integrations > Embed Tokens.
 *   2. The generated URL (/embed-auth.php?token=...&return=/) is used as the iframe src.
 *   3. This endpoint validates the token, starts an embed session with SameSite=None,
 *      and redirects to the return path.
 *   4. Subsequent iframe navigations carry the embed cookie automatically.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

// Skip the standard session bootstrap -- we start our own embed session below.
define('SKIP_SESSION_INIT', true);
require_once __DIR__ . '/includes/init.php';
$db = Database::getInstance();

$rawToken = $_GET['token'] ?? '';
$return   = $_GET['return'] ?? '/';

// Validate the return path: must be a relative path, never absolute/protocol-relative.
if (!preg_match('#^/(?:[^/]|$)#', $return)) {
    $return = '/';
}
// Strip any token= param from the return value to avoid leaking it in Referer headers.
$return = preg_replace('/([?&])token=[^&]*/i', '$1', $return);
$return = rtrim($return, '?&');

function embedDeny(string $msg): void {
    http_response_code(403);
    // Minimal page -- no app chrome, no login redirect, no information leakage.
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Access Denied</title>'
       . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;'
       . 'min-height:100vh;margin:0;background:#f9fafb}'
       . 'p{color:#374151;font-size:15px;text-align:center;max-width:400px;padding:20px}</style>'
       . '</head><body><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

// Token length sanity check before hitting the DB.
if (strlen($rawToken) < 32 || strlen($rawToken) > 128) {
    embedDeny('Invalid embed link. Please contact your administrator.');
}

$tokenHash = hash('sha256', $rawToken);

$row = $db->fetchOne(
    "SELECT id, user_id, allowed_origins, expires_at, is_active
     FROM embed_tokens WHERE token_hash = ?",
    [$tokenHash]
);

if (!$row || !$row['is_active']) {
    embedDeny('This embed link has been revoked. Please contact your administrator.');
}

if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
    embedDeny('This embed link has expired. Please contact your administrator.');
}

// Confirm the user account is still active.
$embedUser = $db->fetchOne(
    "SELECT id, username FROM users WHERE id = ? AND is_active = 1",
    [(int)$row['user_id']]
);
if (!$embedUser) {
    embedDeny('The account linked to this embed token is inactive. Please contact your administrator.');
}

// Record usage timestamp.
$db->update('embed_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => (int)$row['id']]);

// Start the embed session with SameSite=None; Secure.
// A separate session name keeps embed sessions completely isolated from the
// main app sessions (which stay SameSite=Strict).
$isHttps         = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$embedSessionName = $isHttps ? '__Host-tprm_embed' : 'tprm_embed';

ini_set('session.use_strict_mode',   '1');
ini_set('session.use_cookies',       '1');
ini_set('session.use_only_cookies',  '1');
ini_set('session.cookie_httponly',   '1');
ini_set('session.cookie_secure',     $isHttps ? '1' : '0');
ini_set('session.cookie_samesite',   'None');
ini_set('session.cookie_domain',     '');
ini_set('session.cookie_path',       '/');
ini_set('session.gc_maxlifetime',    '3600');
session_name($embedSessionName);

session_start([
    'cookie_lifetime' => 3600,
    'cookie_secure'   => $isHttps,
    'cookie_httponly' => true,
    'cookie_samesite' => 'None',
    'cookie_domain'   => '',
    'cookie_path'     => '/',
    'use_strict_mode' => true,
]);

session_regenerate_id(true);

$_SESSION['is_embed_session']      = true;
$_SESSION['embed_token_id']        = (int)$row['id'];
$_SESSION['user_id']               = (int)$row['user_id'];
$_SESSION['username']              = $embedUser['username'];
$_SESSION['embed_allowed_origins'] = trim($row['allowed_origins']);
$_SESSION['initiated']             = true;
$_SESSION['ip_address']            = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$_SESSION['user_agent']            = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$_SESSION['regenerated']           = time();
$_SESSION['last_activity']         = time();

session_write_close();

header('Location: ' . $return, true, 302);
exit;
