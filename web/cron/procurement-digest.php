#!/usr/bin/env php
<?php
/**
 * Procurement Update Digest - Weekly Cron Job
 *
 * Emails a digest of vendors currently in review (statuses in_review + ai_review)
 * along with their latest procurement update to the configured recipients.
 *
 * Schedule: 0 7 * * 1 (Mondays at 7 AM)
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
    echo "Usage: php procurement-digest.php [--dry-run] [--verbose]\n";
    echo "  --dry-run   Build the digest but do not email or update last-run\n";
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

$cron = new CronHelper('procurement-digest');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();

    // Check if the procurement digest is enabled
    $enabled = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_digest_enabled'");
    if (!$enabled || $enabled['config_value'] !== '1') {
        CronHelper::info("Procurement digest is disabled. Exiting.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting procurement update digest" . ($dryRun ? ' (DRY RUN)' : ''));

    // Canonical "vendors in review with their latest update" query.
    $vendors = $db->fetchAll(
        "SELECT r.id, r.vendor_name, r.status, u.update_text, u.created_at AS update_date
         FROM vendor_onboarding_requests r
         JOIN vendor_procurement_updates u
           ON u.id = (SELECT u2.id FROM vendor_procurement_updates u2 WHERE u2.request_id = r.id ORDER BY u2.created_at DESC, u2.id DESC LIMIT 1)
         WHERE r.status IN ('in_review','ai_review')
         ORDER BY u.created_at DESC"
    );

    $vendorCount = count($vendors);
    $emailed = 0;
    $errors  = [];

    if ($verbose) {
        CronHelper::info("Found {$vendorCount} vendor(s) in review with at least one update");
    }

    if ($vendorCount === 0) {
        CronHelper::info("No vendors in review with updates. Nothing to send.");
        if (!$dryRun) {
            $db->query(
                "UPDATE app_config SET config_value = :ts WHERE config_key = 'procurement_digest_last_run'",
                [':ts' => date('Y-m-d H:i:s')]
            );
            $cron->logExecutionComplete($db, 0, 0, 0, []);
        }
        $cron->releaseLock();
        exit(0);
    }

    // Read configured recipients
    $recipientsRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_digest_recipients'");
    $recipientStr  = $recipientsRow['config_value'] ?? '';
    $recipients    = array_filter(array_map('trim', explode(',', $recipientStr)));

    if (empty($recipients)) {
        if ($verbose) {
            CronHelper::info("No procurement digest recipients configured. Skipping email.");
        }
    } elseif ($dryRun) {
        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            CronHelper::info("[DRY RUN] Would send digest covering {$vendorCount} vendor(s) to {$email}");
        }
    } else {
        try {
            require_once APP_ROOT . '/includes/classes/EmailService.php';
            $emailService = new EmailService($db, new Encryption());

            if ($emailService->isEnabled()) {
                foreach ($recipients as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                    try {
                        $sent = $emailService->sendProcurementDigest($vendors, $email);
                        if ($sent['success'] ?? false) {
                            $emailed++;
                        } else {
                            $errors[] = "Digest email to {$email} failed: " . ($sent['error'] ?? 'unknown error');
                        }
                    } catch (Exception $e) {
                        $errors[] = "Digest email to {$email} failed: " . $e->getMessage();
                    }
                }

                if ($verbose) {
                    CronHelper::info("Sent digest email to {$emailed} recipient(s) covering {$vendorCount} vendor(s)");
                }
            } else {
                CronHelper::warn("Email is disabled; skipping notifications.");
            }
        } catch (Exception $e) {
            $errors[] = "Email setup failed: " . $e->getMessage();
        }
    }

    // Update last run timestamp
    if (!$dryRun) {
        $db->query(
            "UPDATE app_config SET config_value = :ts WHERE config_key = 'procurement_digest_last_run'",
            [':ts' => date('Y-m-d H:i:s')]
        );
    }

    CronHelper::info("Complete: {$vendorCount} vendor(s), {$emailed} email(s) sent");

    if (!$dryRun) {
        $cron->logExecutionComplete($db, $vendorCount, $emailed, count($errors), $errors);
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
