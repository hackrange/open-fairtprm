#!/usr/bin/env php
<?php
/**
 * Grip new-app discovery (every 15 minutes by default).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The full Shadow SaaS rehydration runs once a day, so an app Grip detects in
 * the morning (for example a new AI coding tool) would not reach Shadow SaaS
 * until that night. This job reads Grip's alert list and, for each "New
 * Unmanaged App / AI App Detected" alert on an app the mirror does not hold
 * yet, fetches just that app and adds it to Shadow SaaS. See
 * GripService::discoverNewApps(). Exits immediately when Grip is not enabled,
 * and skips the run while a full Shadow SaaS sync holds the shared lock.
 *
 * Usage: php grip-discovery.php [--verbose]
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
$verbose = isset(getopt('', ['verbose'])['verbose']);

// Same lock as cron/shadow-saas-sync.php: never overlap a full rehydration.
$lockFile = sys_get_temp_dir() . '/fairtprm_shadow_saas_sync.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($verbose) fwrite(STDOUT, "[grip-discovery] Shadow SaaS sync in progress; skipping.\n");
    exit(0);
}
register_shutdown_function(function () use ($lockFp, $lockFile) {
    @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile);
});

require_once APP_ROOT . '/includes/init.php';
require_once CLASSES_PATH . '/GripService.php';

$grip = new GripService();
if (!$grip->isEnabled()) {
    if ($verbose) fwrite(STDOUT, "[grip-discovery] Grip is not enabled; nothing to do.\n");
    exit(0);
}

$db = Database::getInstance();
$cron = new CronHelper('grip-discovery');
$cron->logExecutionStart($db);
try {
    $r = $grip->discoverNewApps();
    $cron->logExecutionComplete($db, $r['new_apps'] + $r['failed'], $r['new_apps'], $r['failed'], []);
    fwrite(STDOUT, sprintf("[grip-discovery] alerts=%d new_apps=%d failed=%d\n", $r['alerts'], $r['new_apps'], $r['failed']));
    exit(0);
} catch (Throwable $e) {
    $cron->logExecutionFailed($db, $e->getMessage());
    fwrite(STDERR, '[grip-discovery] FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
