#!/usr/bin/env php
<?php
/**
 * OSINT Vendor Scanner - Weekly Cron Job
 *
 * Searches GitHub Code Search API for exposed credentials related to ALL
 * monitored vendor domains. Full scan — may take 20-60 minutes depending
 * on number of vendors and GitHub rate limits.
 *
 * Schedule: 0 2 * * 0 (weekly, Sunday at 2 AM)
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

// 2-hour timeout for large vendor lists (GitHub code search: ~10 req/min)
define('OSINT_VENDOR_TIMEOUT', 7200);

if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
    pcntl_signal(SIGALRM, function () {
        CronHelper::error("Process exceeded " . OSINT_VENDOR_TIMEOUT . "s timeout. Terminating.");
        exit(1);
    });
    pcntl_alarm(OSINT_VENDOR_TIMEOUT);
}

$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php osint-vendors.php [--dry-run] [--verbose]\n";
    echo "  --dry-run   Search but do not save alerts\n";
    echo "  --verbose   Print detailed output\n";
    exit(0);
}

$dryRun  = isset($options['dry-run']);
$verbose = isset($options['verbose']) || $dryRun;

try {
    require_once APP_ROOT . '/includes/init.php';
} catch (Exception $e) {
    fwrite(STDERR, "Failed to initialize: " . $e->getMessage() . "\n");
    exit(1);
}

$cron = new CronHelper('osint-vendors');

if (!$cron->acquireLock()) {
    if ($verbose) CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $cron->logExecutionStart($db);
    CronHelper::info("Starting full vendor OSINT scan" . ($dryRun ? ' (DRY RUN)' : ''));

    $breachService = BreachAlertService::getInstance();
    $result = $breachService->runOSINTResearch();

    if (!$result['success']) {
        CronHelper::error("Vendor OSINT failed: " . ($result['error'] ?? 'Unknown error'));
        $cron->logExecutionFailed($db, $result['error'] ?? 'OSINT failed');
        $cron->releaseLock();
        exit(1);
    }

    $alerts    = $result['alerts'];
    $processed = count($alerts);
    $created   = 0;
    $duplicates = 0;
    $errors    = [];
    $newAlertIds = [];

    if ($verbose) {
        CronHelper::info("OSINT found {$processed} potential exposure(s)");
    }

    foreach ($alerts as $alertData) {
        if ($dryRun) {
            CronHelper::info("[DRY RUN] Would create: {$alertData['title']} ({$alertData['severity']})");
            continue;
        }

        $createResult = $breachService->createAlert($alertData);

        if ($createResult['success']) {
            $created++;
            $newAlertIds[] = $createResult['id'];
            if ($verbose) CronHelper::info("Created alert #{$createResult['id']}: {$alertData['title']}");
        } elseif (!empty($createResult['duplicate'])) {
            $duplicates++;
            if ($verbose) CronHelper::info("Skipped duplicate: {$alertData['title']}");
        } else {
            $errors[] = "Failed to create: {$alertData['title']}";
        }
    }

    // Send email digest for new alerts
    if (!$dryRun && !empty($newAlertIds)) {
        $recipientsRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_alert_recipients'");
        $recipients = array_filter(array_map('trim', explode(',', $recipientsRow['config_value'] ?? '')));

        if (!empty($recipients)) {
            try {
                require_once APP_ROOT . '/includes/classes/EmailService.php';
                $emailService = new EmailService($db, new Encryption());

                if ($emailService->isEnabled()) {
                    $digestAlerts = [];
                    foreach ($newAlertIds as $alertId) {
                        $alert = $breachService->getAlert($alertId);
                        if ($alert) $digestAlerts[] = $alert;
                    }

                    if (!empty($digestAlerts)) {
                        foreach ($recipients as $email) {
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                            try {
                                $emailService->sendBreachAlertDigest($digestAlerts, $email);
                            } catch (Exception $e) {
                                $errors[] = "Email to {$email} failed: " . $e->getMessage();
                            }
                        }
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

    CronHelper::info("Complete: {$processed} found, {$created} new, {$duplicates} duplicates");

    if (!$dryRun) {
        $cron->logExecutionComplete($db, $processed, $created, count($errors), $errors);
    }

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($db)) $cron->logExecutionFailed($db, $e->getMessage());
    $cron->releaseLock();
    exit(1);
}

$cron->releaseLock();
exit(0);
