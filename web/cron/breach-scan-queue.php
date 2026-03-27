#!/usr/bin/env php
<?php
/**
 * Breach Scan Queue Processor
 *
 * Processes on-demand breach scan requests queued via the "Run Scan Now"
 * button on breach-alerts.php. Also performs self-healing: clears stale
 * scans, removes dead lock files, and fixes stuck execution history.
 *
 * Schedule: * * * * * (every minute)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
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

$options = getopt('', ['verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php breach-scan-queue.php [--verbose]\n";
    echo "  --verbose   Print detailed output\n";
    exit(0);
}

$verbose = isset($options['verbose']);

try {
    require_once APP_ROOT . '/includes/init.php';
} catch (Exception $e) {
    fwrite(STDERR, "Failed to initialize: " . $e->getMessage() . "\n");
    exit(1);
}

// Hard 10-minute timeout: if the process hangs (stuck AI call, network),
// SIGALRM terminates it so the lock is released and the next cron run
// can clean up. The crontab also wraps this with /usr/bin/timeout as a
// secondary safety net.
define('BREACH_SCAN_TIMEOUT', 1800); // 30 minutes — breach research + company OSINT; full vendor OSINT runs as separate process

if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
    pcntl_signal(SIGALRM, function () {
        CronHelper::error("Process exceeded " . BREACH_SCAN_TIMEOUT . "s timeout. Terminating.");
        // Clean up scan status so the UI doesn't show "scanning" forever
        try {
            $bs = BreachAlertService::getInstance();
            $bs->completeScan('Timed out — scan exceeded 30 minute limit.');
        } catch (\Exception $e) { /* best effort */ }
        exit(1);
    });
    pcntl_alarm(BREACH_SCAN_TIMEOUT);
}

$cron = new CronHelper('breach-scan-queue');

if (!$cron->acquireLock()) {
    if ($verbose) {
        CronHelper::warn("Another instance is already running. Exiting.");
    }
    exit(0);
}

// Shutdown handler: if the process dies for any reason (OOM, SIGTERM from
// timeout command, unhandled error), clear the scanning status so users
// aren't stuck staring at "Scan Running..." forever.
// IMPORTANT: Registered AFTER lock acquisition so instances that fail to
// get the lock don't overwrite a running scan's status on exit.
register_shutdown_function(function () {
    try {
        $bs = BreachAlertService::getInstance();
        if ($bs->isScanCompleted()) {
            return; // Normal exit after successful scan — nothing to clean up
        }
        $status = $bs->getScanStatus();
        if ($status['status'] === 'scanning') {
            $bs->completeScan('Scan interrupted — process terminated unexpectedly.');
        }
    } catch (\Exception $e) { /* best effort */ }
});

try {
    $db = Database::getInstance();
    $breachService = BreachAlertService::getInstance();

    // -----------------------------------------------------------------
    // SELF-HEALING: Clear stale scans (>30 minutes old)
    // -----------------------------------------------------------------
    if ($breachService->clearStaleScan(1800)) {
        CronHelper::warn("Cleared stale breach scan (exceeded 30 minute limit).");
    }

    // -----------------------------------------------------------------
    // SELF-HEALING: Clean dead lock file for breach-monitor cron
    // If breach-monitor.lock exists but the PID inside is dead, remove it
    // so the next daily cron run isn't permanently blocked.
    // -----------------------------------------------------------------
    $monitorLock = sys_get_temp_dir() . '/tprm-breach-monitor.lock';
    if (file_exists($monitorLock)) {
        $lockPid = (int)@file_get_contents($monitorLock);
        if ($lockPid > 0 && !file_exists("/proc/{$lockPid}")) {
            @unlink($monitorLock);
            CronHelper::warn("Removed dead lock file for breach-monitor (PID {$lockPid} no longer running).");
        }
    }

    // -----------------------------------------------------------------
    // SELF-HEALING: Fix stuck cron_execution_history entries
    // If breach-monitor or breach-scan-queue has been "running" for >35
    // minutes, the process almost certainly died without cleaning up.
    // (Increased from 15 to 35 min to accommodate batch processing of
    // hundreds of vendors across multiple AI calls.)
    // -----------------------------------------------------------------
    try {
        $db->query(
            "UPDATE cron_execution_history
             SET status = 'failed',
                 completed_at = NOW(),
                 error_messages = JSON_ARRAY('Automatically cleared: stuck in running state for over 35 minutes')
             WHERE job_name IN ('breach-monitor', 'breach-scan-queue')
               AND status = 'running'
               AND started_at < DATE_SUB(NOW(), INTERVAL 35 MINUTE)",
            []
        );
    } catch (Exception $e) {
        // Table may not exist yet, that's fine
    }

    // -----------------------------------------------------------------
    // CHECK FOR PENDING ON-DEMAND SCAN
    // -----------------------------------------------------------------
    $scanStatus = $breachService->getScanStatus();

    if ($scanStatus['status'] !== 'scanning') {
        if ($verbose) {
            CronHelper::info("No pending scan. Exiting.");
        }
        $cron->releaseLock();
        exit(0);
    }

    // Atomic claim: only proceed if still in 'scanning' state
    $claimed = $db->fetchOne(
        "SELECT config_value FROM app_config WHERE config_key = 'breach_scan_status' AND config_value = 'scanning'"
    );
    if (!$claimed) {
        $cron->releaseLock();
        exit(0);
    }

    $scanType = $scanStatus['scan_type'] ?? 'full';

    $cron->logExecutionStart($db);
    CronHelper::info("Processing on-demand breach scan — type: {$scanType} (requested by user #{$scanStatus['requested_by']})");

    // Check if AI platform is enabled (needed for breach research)
    $aiService = AIPlatformService::getInstance();
    if (!$aiService->isEnabled()) {
        $breachService->completeScan('Scan failed: AI platform is not enabled.');
        CronHelper::error("AI platform is not enabled.");
        $cron->logExecutionFailed($db, 'AI platform is not enabled');
        $cron->releaseLock();
        exit(1);
    }

    // -----------------------------------------------------------------
    // RUN BREACH RESEARCH (always runs for both scan types)
    // Progress callback updates the scan status so the UI shows progress
    // -----------------------------------------------------------------
    $progressCallback = function (string $msg) use ($breachService) {
        $breachService->updateScanProgress($msg);
    };

    $result = $breachService->runAIBreachResearch($progressCallback);

    if (!$result['success']) {
        $errorMsg = $result['error'] ?? 'Unknown error';
        $breachService->completeScan('Scan failed: ' . $errorMsg);
        CronHelper::error("AI research failed: " . $errorMsg);
        $cron->logExecutionFailed($db, $errorMsg);
        $cron->releaseLock();
        exit(1);
    }

    $alerts     = $result['alerts'];
    $processed  = count($alerts);

    // -----------------------------------------------------------------
    // RUN OSINT EXPOSURE RESEARCH (scope depends on scan type)
    // -----------------------------------------------------------------
    if ($scanType === 'company') {
        // Company OSINT runs inline — fast (~1-2 min)
        CronHelper::info("Running COMPANY OSINT scan (org domains only)...");
        $osintResult = $breachService->runCompanyOSINT($progressCallback);

        if ($osintResult['success']) {
            $osintAlerts = $osintResult['alerts'];
            CronHelper::info("OSINT returned " . count($osintAlerts) . " potential exposure(s)");
            $alerts = array_merge($alerts, $osintAlerts);
            $processed = count($alerts);
        } else {
            CronHelper::warn("Company OSINT failed: " . ($osintResult['error'] ?? 'Unknown error') . " — continuing with breach alerts only.");
        }
    } elseif ($scanType === 'full') {
        // Full vendor OSINT is spawned as a separate background process with its
        // own timeout and lock file. This prevents the scan queue from timing out
        // when scanning hundreds of vendor domains (can take 30-60+ minutes).
        $osintScript = APP_ROOT . '/cron/osint-vendors.php';
        $osintLog = '/var/log/php/cron-osint-vendors.log';
        $cmd = "/usr/bin/php " . escapeshellarg($osintScript) . " --verbose >> " . escapeshellarg($osintLog) . " 2>&1 &";
        exec($cmd);
        CronHelper::info("Full vendor OSINT scan spawned as background process. Results will appear as they are found.");
        $progressCallback("Breach research complete. Vendor OSINT scan running in background...");
    }

    $created    = 0;
    $duplicates = 0;
    $emailed    = 0;
    $errors     = [];
    $newAlertIds = [];

    CronHelper::info("AI returned {$processed} total alert(s) (breach + OSINT)");

    foreach ($alerts as $alertData) {
        $createResult = $breachService->createAlert($alertData);

        if ($createResult['success']) {
            $created++;
            $newAlertIds[] = $createResult['id'];
            if ($verbose) {
                CronHelper::info("Created alert #{$createResult['id']}: {$alertData['title']}");
            }
        } elseif (!empty($createResult['duplicate'])) {
            $duplicates++;
            if ($verbose) {
                CronHelper::info("Skipped duplicate: {$alertData['title']}");
            }
        } else {
            $errors[] = "Failed to create alert: {$alertData['title']}";
        }
    }

    // Send digest email for all new alerts (one email per recipient)
    if (!empty($newAlertIds)) {
        $recipientsRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_alert_recipients'");
        $recipientStr  = $recipientsRow['config_value'] ?? '';
        $recipients    = array_filter(array_map('trim', explode(',', $recipientStr)));

        if (!empty($recipients)) {
            try {
                require_once APP_ROOT . '/includes/classes/EmailService.php';
                $emailService = new EmailService($db, new Encryption());

                if ($emailService->isEnabled()) {
                    // Collect all new alerts for digest
                    $digestAlerts = [];
                    foreach ($newAlertIds as $alertId) {
                        $alert = $breachService->getAlert($alertId);
                        if ($alert) $digestAlerts[] = $alert;
                    }

                    if (!empty($digestAlerts)) {
                        foreach ($recipients as $email) {
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                            try {
                                $sent = $emailService->sendBreachAlertDigest($digestAlerts, $email);
                                if ($sent['success'] ?? false) {
                                    $emailed++;
                                }
                            } catch (Exception $e) {
                                $errors[] = "Digest email to {$email} failed: " . $e->getMessage();
                            }
                        }

                        // Mark all alerts as emailed
                        foreach ($newAlertIds as $alertId) {
                            $breachService->markEmailSent($alertId);
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Email setup failed: " . $e->getMessage();
            }
        }
    }

    // Update last run timestamp
    $db->query(
        "UPDATE app_config SET config_value = :ts WHERE config_key = 'breach_alert_last_run'",
        [':ts' => date('Y-m-d H:i:s')]
    );

    // Build result message and complete scan
    $resultMsg = "Scan complete: {$processed} researched, {$created} new alert(s) created";
    if ($duplicates > 0) {
        $resultMsg .= ", {$duplicates} duplicate(s) skipped";
    }
    if ($emailed > 0) {
        $resultMsg .= ", {$emailed} email(s) sent";
    }
    $resultMsg .= '.';

    $breachService->completeScan($resultMsg);
    CronHelper::info($resultMsg);

    $cron->logExecutionComplete($db, $processed, $created, count($errors), $errors);

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($breachService)) {
        $breachService->completeScan('Scan failed: ' . $e->getMessage());
    }
    if (isset($db)) {
        $cron->logExecutionFailed($db, $e->getMessage());
    }
    $cron->releaseLock();
    exit(1);
}

$cron->releaseLock();
exit(0);
