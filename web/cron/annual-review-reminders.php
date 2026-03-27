#!/usr/bin/env php
<?php
/**
 * Annual Review Reminders Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The friendly-but-persistent nag bot that reminds stakeholders to do their
 * vendor annual reviews. Nobody likes being nagged, but everyone likes
 * compliance violations even less. Runs daily and figures out who needs a
 * poke based on three escalation levels:
 *   - 30 days out: "Hey, heads up, this is coming."
 *   - On the due date: "It's today. Do the thing."
 *   - Every 7 days overdue: "Seriously, it's overdue. Please."
 *
 * Tracks what's been sent in the DB so we don't spam the same person
 * about the same review. We're persistent, not obnoxious.
 *
 * Usage:
 *   php annual-review-reminders.php [options]
 *
 * Options:
 *   --dry-run        Show what would be sent without actually sending emails (good for testing)
 *   --verbose        Spit out detailed logs as it runs
 *   --help           Show this help text
 *
 * Cron Setup:
 *   # Run daily at 2 AM
 *   0 2 * * * /usr/bin/php /path/to/cron/annual-review-reminders.php >> /var/log/tprm-reminders.log 2>&1
 */

// ---- CLI GUARD ----
// Browser visitors get the boot. This is a cron job, not a webpage.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ---- PATH SETUP ----
define('CRON_ROOT', dirname(__FILE__));
$_appRoot = dirname(CRON_ROOT);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $_appRoot);
    define('INCLUDES_PATH', $_appRoot . '/includes');
    define('CLASSES_PATH', $_appRoot . '/includes/classes');
    define('CONFIG_PATH', $_appRoot . '/config');
}
chdir(APP_ROOT);

// ---- PARSE CLI OPTIONS ----
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// ---- BOOTSTRAP THE APPLICATION ----
try {
    require_once APP_ROOT . '/includes/init.php';
    require_once APP_ROOT . '/includes/classes/EmailService.php';
    require_once APP_ROOT . '/includes/classes/Encryption.php';
} catch (Exception $e) {
    CronHelper::error("Failed to initialize application: " . $e->getMessage());
    exit(1);
}

// ---- LOCK FILE ----
// One instance at a time, please. We don't want duplicate reminder emails
// going out because two cron runs overlapped. That's how you get angry users.
$cron = new CronHelper('annual-review-reminders');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $encryption = new Encryption();
    $emailService = new EmailService($db, $encryption);

    // If email is turned off in the config, there's no point running this.
    // It'd be like hiring a mail carrier and then boarding up the mailbox.
    if (!$emailService->isEnabled()) {
        CronHelper::warn("Email notifications are disabled in app_config. Skipping.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting annual review reminder job" . ($dryRun ? " (DRY RUN)" : ""));

    // Useful date references
    $today = date('Y-m-d');
    $thirtyDaysFromNow = date('Y-m-d', strtotime('+30 days'));

    // ---- COUNTERS ----
    $totalReminders = 0;
    $remindersSent = 0;
    $remindersFailed = 0;
    $errors = [];

    // ---- FETCH ALL APPROVED VENDORS ----
    // We only care about approved vendors because pending/rejected ones
    // don't have active relationships that need annual reviews.
    $vendors = $db->fetchAll(
        "SELECT r.*,
                (SELECT MAX(review_date) FROM vendor_annual_reviews WHERE vendor_request_id = r.id) as last_review_date
         FROM vendor_onboarding_requests r
         WHERE r.status = 'approved'
         ORDER BY r.id"
    );

    CronHelper::info("Found " . count($vendors) . " approved vendors");

    // ---- MAIN LOOP: CHECK EACH VENDOR ----
    foreach ($vendors as $vendor) {
        $dueDate = calculateReviewDueDate($vendor);
        if (!$dueDate) {
            if ($verbose) {
                CronHelper::info("Vendor #{$vendor['id']} ({$vendor['vendor_name']}): No due date calculated, skipping");
            }
            continue;
        }

        // Find the humans who need to be reminded about this vendor
        $stakeholders = $db->fetchAll(
            "SELECT s.user_id, u.email, u.full_name
             FROM vendor_onboarding_stakeholders s
             LEFT JOIN users u ON s.user_id = u.id
             WHERE s.request_id = :request_id AND u.is_active = 1 AND u.email IS NOT NULL",
            [':request_id' => $vendor['id']]
        );

        if (empty($stakeholders)) {
            if ($verbose) {
                CronHelper::info("Vendor #{$vendor['id']} ({$vendor['vendor_name']}): No active stakeholders with email, skipping");
            }
            continue;
        }

        // ---- DETERMINE REMINDER TYPE ----
        // The three flavors: 30-day warning, due date alert, overdue nag.
        $reminderType = null;

        if ($dueDate === $thirtyDaysFromNow) {
            $reminderType = '30_days_before';
        } elseif ($dueDate === $today) {
            $reminderType = 'due_date';
        } elseif ($dueDate < $today) {
            // Overdue -- but we only nag every 7 days to avoid being That Guy
            $daysOverdue = floor((strtotime($today) - strtotime($dueDate)) / 86400);
            if ($daysOverdue % 7 === 0) {
                $reminderType = 'overdue';
            }
        }

        if (!$reminderType) {
            if ($verbose) {
                CronHelper::info("Vendor #{$vendor['id']} ({$vendor['vendor_name']}): Not due for reminder (due: {$dueDate})");
            }
            continue;
        }

        // ---- SEND REMINDERS TO EACH STAKEHOLDER ----
        foreach ($stakeholders as $stakeholder) {
            $totalReminders++;

            // Check if we already sent this exact reminder. No double-dipping.
            $existingReminder = $db->fetchOne(
                "SELECT id FROM vendor_review_reminders
                 WHERE vendor_request_id = :vendor_id
                   AND stakeholder_user_id = :user_id
                   AND reminder_type = :type
                   AND due_date = :due_date",
                [
                    ':vendor_id' => $vendor['id'],
                    ':user_id' => $stakeholder['user_id'],
                    ':type' => $reminderType,
                    ':due_date' => $dueDate
                ]
            );

            if ($existingReminder) {
                if ($verbose) {
                    CronHelper::info("Vendor #{$vendor['id']} ({$vendor['vendor_name']}): Reminder already sent to {$stakeholder['email']}");
                }
                continue;
            }

            // Create the reminder record in the DB first (status: pending)
            try {
                $reminderId = $db->insert('vendor_review_reminders', [
                    'vendor_request_id' => $vendor['id'],
                    'stakeholder_user_id' => $stakeholder['user_id'],
                    'reminder_type' => $reminderType,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                    'email_sent_to' => $stakeholder['email']
                ]);
            } catch (Exception $e) {
                if ($verbose) {
                    CronHelper::warn("Failed to create reminder record for vendor #{$vendor['id']}, stakeholder {$stakeholder['email']}: " . $e->getMessage());
                }
                continue;
            }

            // ---- ACTUALLY SEND THE EMAIL (unless dry run) ----
            if (!$dryRun) {
                try {
                    $success = $emailService->sendAnnualReviewReminder(
                        $vendor,
                        $stakeholder['email'],
                        $reminderType,
                        $dueDate
                    );

                    if ($success) {
                        $db->update('vendor_review_reminders', [
                            'status' => 'sent',
                            'sent_at' => date('Y-m-d H:i:s')
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersSent++;
                        CronHelper::info("Sent {$reminderType} reminder for vendor #{$vendor['id']} ({$vendor['vendor_name']}) to {$stakeholder['email']}");
                    } else {
                        $db->update('vendor_review_reminders', [
                            'status' => 'failed',
                            'error_message' => 'Email service returned false'
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersFailed++;
                        $errors[] = "Failed to send reminder to {$stakeholder['email']} for vendor #{$vendor['id']}";
                        CronHelper::warn("Failed to send {$reminderType} reminder for vendor #{$vendor['id']} to {$stakeholder['email']}");
                    }
                } catch (Exception $e) {
                    $db->update('vendor_review_reminders', [
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ], 'id = :id', [':id' => $reminderId]);

                    $remindersFailed++;
                    $errors[] = "Exception sending reminder to {$stakeholder['email']}: " . $e->getMessage();
                    CronHelper::error("Exception sending {$reminderType} reminder for vendor #{$vendor['id']}: " . $e->getMessage());
                }
            } else {
                CronHelper::info("[DRY RUN] Would send {$reminderType} reminder for vendor #{$vendor['id']} ({$vendor['vendor_name']}) to {$stakeholder['email']}");
                $remindersSent++;
            }
        }
    }

    // ---- SUMMARY ----
    CronHelper::info("Reminder job completed:");
    CronHelper::info("  Total reminders processed: {$totalReminders}");
    CronHelper::info("  Successfully sent: {$remindersSent}");
    CronHelper::info("  Failed: {$remindersFailed}");

    if ($dryRun) {
        CronHelper::info("  (DRY RUN - no emails actually sent)");
    }

    $cron->logExecutionComplete($db, $totalReminders, $remindersSent, $remindersFailed, $errors);

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($db)) {
        $cron->logExecutionFailed($db, $e->getMessage());
    }
    $cron->releaseLock();
    exit(1);
}

$cron->releaseLock();
CronHelper::info("Job completed successfully");
exit(0);

// ============================================================================
// Job-Specific Helper Functions
// ============================================================================

/**
 * Calculate when a vendor's annual review is due.
 * Uses stored due date first, then falls back to calculating from last review
 * or approval date. Same logic as the web UI so the numbers match.
 */
function calculateReviewDueDate(array $vendor): ?string
{
    if ($vendor['status'] !== 'approved') {
        return null;
    }

    if (!empty($vendor['last_annual_review_due'])) {
        return $vendor['last_annual_review_due'];
    }

    if (!empty($vendor['last_annual_review'])) {
        return date('Y-m-d', strtotime($vendor['last_annual_review'] . ' +365 days'));
    }

    $approvalDate = $vendor['submitted_at'] ?? $vendor['created_at'];
    if (empty($approvalDate)) {
        return null;
    }

    return date('Y-m-d', strtotime($approvalDate . ' +365 days'));
}

/**
 * Print the help text.
 * For the rare soul who actually reads the manual before running things.
 */
function showHelp(): void
{
    echo <<<HELP
Annual Review Reminders Cron Job

Sends email reminders for vendor annual reviews.

Usage:
  php annual-review-reminders.php [options]

Options:
  --dry-run        Show what would be sent without sending emails
  --verbose        Show detailed output during execution
  --help           Display this help message

Reminder Schedule:
  - 30 days before due date: Single notification
  - On due date: Single notification
  - When overdue: Every 7 days

Cron Setup:
  # Run daily at 2 AM
  0 2 * * * /usr/bin/php /path/to/cron/annual-review-reminders.php >> /var/log/tprm-reminders.log 2>&1

HELP;
}
