#!/usr/bin/env php
<?php
/**
 * Breach / Cyber Alert Monitor - Daily Cron Job
 *
 * Uses the configured AI platform to research credible security breaches,
 * data breaches, and critical vulnerabilities affecting monitored vendors,
 * subprocessors, and technologies. Cross-references fourth-party risk data
 * to assess supply chain impact. Sends email notifications for new alerts.
 *
 * Schedule: 0 6 * * * (daily at 6 AM)
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

$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php breach-monitor.php [--dry-run] [--verbose]\n";
    echo "  --dry-run   Research breaches but do not save or email\n";
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

// Hard 10-minute timeout: kill the process if it hangs on an AI call or
// network request. The crontab also wraps this with /usr/bin/timeout.
define('BREACH_MONITOR_TIMEOUT', 1800); // 30 minutes — allows batch processing of hundreds of vendors

if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
    pcntl_signal(SIGALRM, function () {
        CronHelper::error("Process exceeded " . BREACH_MONITOR_TIMEOUT . "s timeout. Terminating.");
        exit(1);
    });
    pcntl_alarm(BREACH_MONITOR_TIMEOUT);
}

$cron = new CronHelper('breach-monitor');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();

    // Check if breach alerts are enabled
    $enabled = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_alert_enabled'");
    if (!$enabled || $enabled['config_value'] !== '1') {
        CronHelper::info("Breach alerts are disabled. Exiting.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting breach/cyber alert research" . ($dryRun ? ' (DRY RUN)' : ''));

    $breachService = BreachAlertService::getInstance();

    // Run AI-powered breach research
    $result = $breachService->runAIBreachResearch();

    if (!$result['success']) {
        CronHelper::error("AI research failed: " . ($result['error'] ?? 'Unknown error'));
        $cron->logExecutionFailed($db, $result['error'] ?? 'AI research failed');
        $cron->releaseLock();
        exit(1);
    }

    $alerts     = $result['alerts'];
    $processed  = count($alerts);

    // OSINT scanning is handled by separate cron jobs:
    //   osint-company.php  — daily, company domains only
    //   osint-vendors.php  — weekly, all vendor domains

    $created    = 0;
    $duplicates = 0;
    $emailed    = 0;
    $errors     = [];
    $newAlertIds = [];

    if ($verbose) {
        CronHelper::info("AI returned " . $processed . " total alert(s) (breach + OSINT)");
    }

    foreach ($alerts as $alertData) {
        if ($dryRun) {
            CronHelper::info("[DRY RUN] Would create alert: {$alertData['title']} ({$alertData['severity']}) - {$alertData['affected_entity']}");
            continue;
        }

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

    // Send the digest for every NEW, not-yet-emailed qualifying alert. Driven by
    // email_sent_at (not just this run's $newAlertIds), so breaches created by
    // other sources since the last digest — notably the Grip Shadow SaaS breach
    // feed (GripService::syncAll) — are included when this cron fires. Qualifying
    // = vendor-relevant alerts (vendor_count > 0) plus Grip "Shadow SaaS" breaches.
    if (!$dryRun) {
        $digestAlerts = $breachService->getPendingDigestAlerts();

        if (!empty($digestAlerts)) {
            $recipientsRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_alert_recipients'");
            $recipientStr  = $recipientsRow['config_value'] ?? '';
            $recipients    = array_filter(array_map('trim', explode(',', $recipientStr)));

            if (!empty($recipients)) {
                try {
                    require_once APP_ROOT . '/includes/classes/EmailService.php';
                    $emailService = new EmailService($db, new Encryption());

                    if ($emailService->isEnabled()) {
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

                        // Mark every digested alert as emailed so it isn't re-sent.
                        foreach ($digestAlerts as $da) {
                            $breachService->markEmailSent((int)$da['id']);
                        }

                        if ($verbose) {
                            CronHelper::info("Sent digest email to {$emailed} recipient(s) covering " . count($digestAlerts) . " alert(s)");
                        }
                    } else {
                        CronHelper::warn("Email is disabled; skipping notifications.");
                    }
                } catch (Exception $e) {
                    $errors[] = "Email setup failed: " . $e->getMessage();
                }
            } elseif ($verbose) {
                CronHelper::info("No breach alert recipients configured. Skipping email.");
            }
        } elseif ($verbose) {
            CronHelper::info("No new alerts pending email.");
        }
    }

    // Update last run timestamp
    if (!$dryRun) {
        $db->query(
            "UPDATE app_config SET config_value = :ts WHERE config_key = 'breach_alert_last_run'",
            [':ts' => date('Y-m-d H:i:s')]
        );
    }

    $successCount = $created;
    $failedCount  = count($errors);

    CronHelper::info("Complete: {$processed} researched, {$created} new, {$duplicates} duplicates, {$emailed} emails sent");

    if (!$dryRun) {
        $cron->logExecutionComplete($db, $processed, $successCount, $failedCount, $errors);
    }

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($db)) {
        $cron->logExecutionFailed($db, $e->getMessage());
    }
    $cron->releaseLock();
    exit(1);
}

$cron->releaseLock();
exit(0);
