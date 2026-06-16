#!/usr/bin/env php
<?php
/**
 * Grip Security — Shadow SaaS rehydration cron job.
 *
 * Pulls SaaS apps, users, and alerts from the configured Grip Security
 * tenant and refreshes the shadow_saas_grip_* mirror tables. Also projects
 * SaaS apps into the existing shadow_saas table.
 *
 * Triggered by:
 *   - cron entry on the configured grip_cron_frequency
 *   - "Run Now" button in admin (executed in the background via exec)
 *
 * Usage: php grip-sync.php [--manual] [--user-id=N] [--verbose]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

define('CRON_ROOT', dirname(__FILE__));
$_appRoot = dirname(CRON_ROOT);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $_appRoot);
    define('INCLUDES_PATH', $_appRoot . '/includes');
    define('CLASSES_PATH', $_appRoot . '/includes/classes');
    define('CONFIG_PATH', $_appRoot . '/config');
}
chdir(APP_ROOT);

$options = getopt('', ['manual', 'verbose', 'user-id::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php grip-sync.php [--manual] [--user-id=N] [--verbose]\n";
    exit(0);
}
$verbose = isset($options['verbose']);
$trigger = isset($options['manual']) ? 'manual' : 'cron';
$userId  = isset($options['user-id']) ? (int)$options['user-id'] : null;

$lockFile = sys_get_temp_dir() . '/fairtprm_grip_sync.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[grip-sync] Another grip sync is already running. Exiting.\n");
    exit(0);
}
register_shutdown_function(function () use ($lockFp, $lockFile) {
    @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile);
});

require_once APP_ROOT . '/includes/init.php';
require_once CLASSES_PATH . '/GripService.php';

$start = microtime(true);
if ($verbose) fwrite(STDOUT, "[grip-sync] starting (trigger={$trigger})\n");

try {
    $svc = new GripService();
    if (!$svc->isEnabled()) {
        fwrite(STDOUT, "[grip-sync] Grip integration is disabled; nothing to do.\n");
        exit(0);
    }
    $res = $svc->syncAll($trigger, $userId);
    $elapsed = round(microtime(true) - $start, 2);
    if (!empty($res['success'])) {
        fwrite(STDOUT, sprintf(
            "[grip-sync] OK in %ss — apps=%d users=%d alerts=%d shadow_saas=%d\n",
            $elapsed, $res['apps_fetched'] ?? 0, $res['users_fetched'] ?? 0,
            $res['alerts_fetched'] ?? 0, $res['shadow_saas_upserted'] ?? 0
        ));
        exit(0);
    }
    fwrite(STDERR, "[grip-sync] FAILED: " . ($res['error'] ?? 'unknown') . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "[grip-sync] EXCEPTION: " . $e->getMessage() . "\n");
    exit(1);
}
