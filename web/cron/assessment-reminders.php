#!/usr/bin/env php
<?php
/**
 * Assessment Reminders Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The friendly nudge machine for vendor security assessments. Handles two jobs:
 *
 * Phase 1 - Initial Send:
 *   If an assessment was created with email disabled (or the vendor contact email
 *   was added after creation), this picks it up and sends the initial request.
 *   Checks the vendor_assessment_reminders table so it never re-sends what the
 *   UI already sent via the Email button or auto-send on creation.
 *
 * Phase 2 - Expiry Reminders:
 *   Sends reminders at 7 days, 3 days, and 0 days before the assessment expires.
 *   Because vendors are busy people and sometimes need a gentle (or not so gentle)
 *   reminder that their security assessment isn't going to fill itself out.
 *
 * Usage:
 *   php assessment-reminders.php [options]
 *
 * Options:
 *   --dry-run        Show what would be sent without actually sending emails
 *   --verbose        Detailed logs as it runs
 *   --help           Show this help text
 *
 * Cron Setup:
 *   # Run daily at 4 AM
 *   0 4 * * * /usr/bin/php /path/to/cron/assessment-reminders.php >> /var/log/php/cron-assessment-reminders.log 2>&1
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
// going out because two cron runs overlapped.
$cron = new CronHelper('assessment-reminders');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $encryption = new Encryption();
    $emailService = new EmailService($db, $encryption);

    // If email is turned off in the config, there's no point running this.
    if (!$emailService->isEnabled()) {
        CronHelper::warn("Email notifications are disabled in app_config. Skipping.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting assessment reminder job" . ($dryRun ? " (DRY RUN)" : ""));

    // Useful date references
    $today = date('Y-m-d');
    $sevenDaysFromNow = date('Y-m-d', strtotime('+7 days'));
    $threeDaysFromNow = date('Y-m-d', strtotime('+3 days'));

    // ---- COUNTERS ----
    $totalReminders = 0;
    $remindersSent = 0;
    $remindersFailed = 0;
    $errors = [];

    // ========================================================================
    // PHASE 1: INITIAL SEND
    // Pick up assessments that were created without an email being sent.
    // This happens when email was disabled at creation time, or the vendor
    // contact email was added after the assessment was created.
    // ========================================================================
    CronHelper::info("Phase 1: Checking for unsent initial assessments...");

    $unseenAssessments = $db->fetchAll(
        "SELECT va.*
         FROM vendor_assessments va
         WHERE va.status = 'pending'
           AND va.vendor_contact_email IS NOT NULL
           AND va.vendor_contact_email != ''
           AND va.expires_at > NOW()
           AND NOT EXISTS (
               SELECT 1 FROM vendor_assessment_reminders var
               WHERE var.assessment_id = va.id
                 AND var.reminder_type = 'initial'
                 AND var.status = 'sent'
           )
         ORDER BY va.id"
    );

    CronHelper::info("Found " . count($unseenAssessments) . " assessments needing initial send");

    foreach ($unseenAssessments as $assessment) {
        $totalReminders++;
        $vendorEmail = $assessment['vendor_contact_email'];
        $expiresDate = date('Y-m-d', strtotime($assessment['expires_at']));

        if ($verbose) {
            CronHelper::info("Assessment #{$assessment['id']} ({$assessment['vendor_name']}): Sending initial email to {$vendorEmail}");
        }

        // Create pending record first
        try {
            $reminderId = $db->insert('vendor_assessment_reminders', [
                'assessment_id' => $assessment['id'],
                'reminder_type' => 'initial',
                'expires_at' => $expiresDate,
                'status' => 'pending',
                'email_sent_to' => $vendorEmail,
            ]);
        } catch (Exception $e) {
            if ($verbose) {
                CronHelper::warn("Failed to create reminder record for assessment #{$assessment['id']}: " . $e->getMessage());
            }
            continue;
        }

        if (!$dryRun) {
            try {
                $assessmentUrl = baseUrl('vendor-assessment.php?token=' . $assessment['uuid']);
                $contactName = $assessment['vendor_contact_name'] ?? null;
                $fullName = null;
                if (!empty($assessment['vendor_request_id'])) {
                    $onboardingRecord = $db->fetchOne(
                        "SELECT primary_contact_details FROM vendor_onboarding_requests WHERE id = ?",
                        [$assessment['vendor_request_id']]
                    );
                    $fullName = !empty($onboardingRecord['primary_contact_details']) ? $onboardingRecord['primary_contact_details'] : null;
                }

                $result = $emailService->sendAssessmentEmail(
                    $vendorEmail,
                    $assessment['vendor_name'],
                    $assessmentUrl,
                    $contactName,
                    (int)$assessment['template_id'],
                    $fullName,
                    $assessment['expires_at']
                );

                if (!empty($result['success'])) {
                    $db->update('vendor_assessment_reminders', [
                        'status' => 'sent',
                        'sent_at' => date('Y-m-d H:i:s'),
                    ], 'id = :id', [':id' => $reminderId]);

                    $remindersSent++;
                    CronHelper::info("Sent initial email for assessment #{$assessment['id']} ({$assessment['vendor_name']}) to {$vendorEmail}");
                } else {
                    $errorMsg = $result['message'] ?? 'Email service returned false';
                    $db->update('vendor_assessment_reminders', [
                        'status' => 'failed',
                        'error_message' => $errorMsg,
                    ], 'id = :id', [':id' => $reminderId]);

                    $remindersFailed++;
                    $errors[] = "Failed initial send to {$vendorEmail} for assessment #{$assessment['id']}: {$errorMsg}";
                    CronHelper::warn("Failed initial email for assessment #{$assessment['id']} to {$vendorEmail}");
                }
            } catch (Exception $e) {
                $db->update('vendor_assessment_reminders', [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ], 'id = :id', [':id' => $reminderId]);

                $remindersFailed++;
                $errors[] = "Exception sending initial email to {$vendorEmail}: " . $e->getMessage();
                CronHelper::error("Exception sending initial email for assessment #{$assessment['id']}: " . $e->getMessage());
            }
        } else {
            CronHelper::info("[DRY RUN] Would send initial email for assessment #{$assessment['id']} ({$assessment['vendor_name']}) to {$vendorEmail}");
            $remindersSent++;
        }
    }

    // ========================================================================
    // PHASE 2: EXPIRY REMINDERS
    // Send reminders at 7 days, 3 days, and 0 days before expiration.
    // Only for assessments that haven't been completed yet.
    // ========================================================================
    CronHelper::info("Phase 2: Checking for expiry reminders...");

    // Define the reminder windows: [reminder_type, target_date]
    $reminderWindows = [
        ['7_days_before', $sevenDaysFromNow],
        ['3_days_before', $threeDaysFromNow],
        ['expiry_day', $today],
    ];

    foreach ($reminderWindows as [$reminderType, $targetDate]) {
        $expiringAssessments = $db->fetchAll(
            "SELECT va.*
             FROM vendor_assessments va
             WHERE va.status IN ('pending', 'in_progress')
               AND va.vendor_contact_email IS NOT NULL
               AND va.vendor_contact_email != ''
               AND DATE(va.expires_at) = :target_date
               AND NOT EXISTS (
                   SELECT 1 FROM vendor_assessment_reminders var
                   WHERE var.assessment_id = va.id
                     AND var.reminder_type = :reminder_type
                     AND var.expires_at = :expires_date
                     AND var.status = 'sent'
               )
             ORDER BY va.id",
            [
                ':target_date' => $targetDate,
                ':reminder_type' => $reminderType,
                ':expires_date' => $targetDate,
            ]
        );

        if ($verbose || count($expiringAssessments) > 0) {
            CronHelper::info("  {$reminderType} (expires {$targetDate}): " . count($expiringAssessments) . " assessments");
        }

        foreach ($expiringAssessments as $assessment) {
            $totalReminders++;
            $vendorEmail = $assessment['vendor_contact_email'];

            // Create pending record first
            try {
                $reminderId = $db->insert('vendor_assessment_reminders', [
                    'assessment_id' => $assessment['id'],
                    'reminder_type' => $reminderType,
                    'expires_at' => $targetDate,
                    'status' => 'pending',
                    'email_sent_to' => $vendorEmail,
                ]);
            } catch (Exception $e) {
                if ($verbose) {
                    CronHelper::warn("Failed to create {$reminderType} reminder record for assessment #{$assessment['id']}: " . $e->getMessage());
                }
                continue;
            }

            if (!$dryRun) {
                try {
                    $success = $emailService->sendAssessmentReminder(
                        $assessment,
                        $vendorEmail,
                        $reminderType
                    );

                    if ($success) {
                        $db->update('vendor_assessment_reminders', [
                            'status' => 'sent',
                            'sent_at' => date('Y-m-d H:i:s'),
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersSent++;
                        CronHelper::info("Sent {$reminderType} reminder for assessment #{$assessment['id']} ({$assessment['vendor_name']}) to {$vendorEmail}");
                    } else {
                        $db->update('vendor_assessment_reminders', [
                            'status' => 'failed',
                            'error_message' => 'Email service returned false',
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersFailed++;
                        $errors[] = "Failed {$reminderType} reminder to {$vendorEmail} for assessment #{$assessment['id']}";
                        CronHelper::warn("Failed {$reminderType} reminder for assessment #{$assessment['id']} to {$vendorEmail}");
                    }
                } catch (Exception $e) {
                    $db->update('vendor_assessment_reminders', [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ], 'id = :id', [':id' => $reminderId]);

                    $remindersFailed++;
                    $errors[] = "Exception sending {$reminderType} reminder to {$vendorEmail}: " . $e->getMessage();
                    CronHelper::error("Exception sending {$reminderType} reminder for assessment #{$assessment['id']}: " . $e->getMessage());
                }
            } else {
                CronHelper::info("[DRY RUN] Would send {$reminderType} reminder for assessment #{$assessment['id']} ({$assessment['vendor_name']}) to {$vendorEmail}");
                $remindersSent++;
            }
        }
    }

    // ---- SUMMARY ----
    CronHelper::info("Assessment reminder job completed:");
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
 * Print the help text.
 */
function showHelp(): void
{
    echo <<<HELP
Assessment Reminders Cron Job

Sends initial assessment emails and expiry reminders for vendor security assessments.

Usage:
  php assessment-reminders.php [options]

Options:
  --dry-run        Show what would be sent without sending emails
  --verbose        Show detailed output during execution
  --help           Display this help message

Reminder Schedule:
  - Initial send: Picks up assessments that were never emailed
  - 7 days before expiry: Friendly reminder
  - 3 days before expiry: Urgent reminder
  - On expiry day: Final notice

Cron Setup:
  # Run daily at 4 AM
  0 4 * * * /usr/bin/php /path/to/cron/assessment-reminders.php >> /var/log/php/cron-assessment-reminders.log 2>&1

HELP;
}
