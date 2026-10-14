#!/usr/bin/env php
<?php
/**
 * Vendor privacy and file-sharing review (daily by default).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Reviews the vendor domains that are due (never reviewed, or past the
 * re-check interval, default 30 days), up to the daily cap set on
 * Admin > Shadow SaaS > Grip. See VendorPrivacyService. Exits at once unless
 * Grip is enabled, an AI platform is enabled and "Use AI" is ticked. Stops
 * early, leaving the rest due, when the monthly AI budget is reached.
 *
 * Usage: php vendor-privacy-review.php [--limit=N] [--domain=example.com] [--verbose] [--manual]
 *   --domain  review just this domain now, even if it is not due
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
$opt = getopt('', ['limit:', 'domain:', 'verbose', 'manual', 'queue', 'token:']);
$verbose = isset($opt['verbose']);

// --queue (manual reviews of selected vendors) has its own lock so it can run
// beside the scheduled batch; queue items are claimed atomically.
$lockFile = sys_get_temp_dir() . '/' . (isset($opt['queue']) ? 'fairtprm_vendor_privacy_queue.lock' : 'fairtprm_vendor_privacy.lock');
// Fall back to read-only: flock() works on it, so a lock file owned by the other
// user (root cron vs www-data Run now) can never block a run.
$lockFp = @fopen($lockFile, 'c') ?: @fopen($lockFile, 'r');
// Root (cron) and www-data (Run now) share these files; keep them writable by both
// so a run killed mid-way can never leave a lock the other user cannot open.
if ($lockFp) @chmod($lockFile, 0666);
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[vendor-privacy] Another review run is in progress; skipping.\n");
    exit(0);
}
register_shutdown_function(function () use ($lockFp, $lockFile) {
    @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile);
});
// Tell the admin page that started us that we are really running.
if (!empty($opt['token']) && preg_match('/^[0-9a-f]{16}$/', (string)$opt['token'])) {
    $mk = sys_get_temp_dir() . '/fairtprm_vendor_privacy.started';
    @file_put_contents($mk, $opt['token']);
    @chmod($mk, 0666);
}
if ($verbose) fwrite(STDOUT, '[vendor-privacy] ' . date('Y-m-d H:i:s') . ' started' . (isset($opt['manual']) ? ' (manual)' : '') . (isset($opt['queue']) ? ' (queue)' : '') . "\n");

require_once APP_ROOT . '/includes/init.php';
require_once CLASSES_PATH . '/GripService.php';
require_once CLASSES_PATH . '/VendorPrivacyService.php';

$db = Database::getInstance();
if (!VendorPrivacyService::isEnabled($db)) {
    if ($verbose) fwrite(STDOUT, "[vendor-privacy] Not enabled (needs Grip, an AI platform, and Use AI on the Grip settings); nothing to do.\n");
    exit(0);
}

$svc = new VendorPrivacyService($db);
$cron = new CronHelper('vendor-privacy-review');
$cron->logExecutionStart($db);
$log = function (string $line) use ($verbose) { if ($verbose) fwrite(STDOUT, '[vendor-privacy] ' . $line . "\n"); };
try {
    if (isset($opt['queue'])) {
        $q = $svc->runQueue(3300, $log);
        $a = VendorPrivacyService::sendIncreaseAlerts($db, $svc->increases);
        if ($a['changes']) $log(sprintf('rating increases: %d, alert emails sent: %d%s', $a['changes'], $a['sent'], $a['error'] ? ' (' . $a['error'] . ')' : ''));
        $cron->logExecutionComplete($db, $q['reviewed'] + $q['failed'], $q['reviewed'], $q['failed'], []);
        fwrite(STDOUT, sprintf("[vendor-privacy] queue: reviewed=%d failed=%d cost=$%.4f%s\n", $q['reviewed'], $q['failed'], $q['cost'], $q['budget_stopped'] ? ' (stopped: monthly AI limit)' : ''));
        exit(0);
    }
    if (!empty($opt['domain'])) {
        $r = $svc->reviewDomain((string)$opt['domain'], '', true);
        VendorPrivacyService::sendIncreaseAlerts($db, $svc->increases);
        $cron->logExecutionComplete($db, 1, 1, 0, []);
        fwrite(STDOUT, sprintf("[vendor-privacy] %s: %s privacy=%s sharing=%s cost=$%.4f\n", $opt['domain'], $r['outcome'], $r['privacy_risk'], $r['sharing_risk'], $r['cost']));
        exit(0);
    }
    // Requests people queued go first (their own lock keeps a queue run beside us safe).
    if (!VendorPrivacyService::isRunning(VendorPrivacyService::QUEUE_LOCK_FILE)) $svc->runQueue(600, $log);
    $limit = isset($opt['limit']) ? max(1, (int)$opt['limit']) : $svc->dailyCap();
    // Leave a minute of the job's 3600 s timeout for bookkeeping.
    $r = $svc->runBatch($limit, 3300, $log);
    // One digest email per run for every rating that went up (Admin > Email > Shadow SaaS).
    $a = VendorPrivacyService::sendIncreaseAlerts($db, $svc->increases);
    if ($a['changes']) $log(sprintf('rating increases: %d, alert emails sent: %d%s', $a['changes'], $a['sent'], $a['error'] ? ' (' . $a['error'] . ')' : ''));
    $cron->logExecutionComplete($db, $r['reviewed'] + $r['failed'], $r['reviewed'], $r['failed'], []);
    fwrite(STDOUT, sprintf("[vendor-privacy] reviewed=%d ai_calls=%d unchanged=%d unknown=%d failed=%d remaining=%d cost=$%.4f%s\n",
        $r['reviewed'], $r['ai_calls'], $r['unchanged'], $r['unknown'], $r['failed'], $r['remaining'], $r['cost'],
        $r['budget_stopped'] ? ' (stopped: monthly AI budget reached)' : ''));
    exit(0);
} catch (Throwable $e) {
    $cron->logExecutionFailed($db, $e->getMessage());
    fwrite(STDERR, '[vendor-privacy] FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
