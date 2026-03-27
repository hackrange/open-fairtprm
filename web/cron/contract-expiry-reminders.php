#!/usr/bin/env php
<?php
/**
 * Contract Expiry Reminders Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The procurement team's early warning system for expiring contracts. Runs daily
 * and checks all active contracts against the configured warning window. When a
 * contract is about to expire (or already has), it:
 *   1. Creates a Case Management case (cyber_todo_activities) if one doesn't exist
 *   2. Sends email reminders to configured recipients
 *
 * Reminder types:
 *   - warning: N days before expiration (configurable, default 30)
 *   - expiring_today: On the expiration date itself
 *   - expired: After expiration, every 7 days
 *
 * Deduplication via vendor_contract_reminders table + UNIQUE KEY.
 *
 * Usage:
 *   php contract-expiry-reminders.php [options]
 *
 * Options:
 *   --dry-run        Show what would happen without sending emails or creating cases
 *   --verbose        Detailed logging output
 *   --help           Show this help text
 *
 * Cron Setup:
 *   # Run daily at 3 AM (offset from annual-review-reminders at 2 AM)
 *   0 3 * * * /usr/bin/php /path/to/cron/contract-expiry-reminders.php >> /var/log/tprm-contract-reminders.log 2>&1
 */

// ---- CLI GUARD ----
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
$cron = new CronHelper('contract-expiry-reminders');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $encryption = new Encryption();
    $emailService = new EmailService($db, $encryption);

    // Check if email and procurement notifications are enabled
    $emailEnabled = $emailService->isEnabled();
    $procEnabled = false;
    try {
        $procEnabledRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_notifications_enabled'");
        $procEnabled = ($procEnabledRow && $procEnabledRow['config_value'] === '1');
    } catch (Exception $e) {}

    if (!$procEnabled) {
        CronHelper::warn("Procurement notifications are disabled in app_config. Skipping.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting contract expiry reminder job" . ($dryRun ? " (DRY RUN)" : ""));

    // ---- LOAD CONFIGURATION ----
    $warningDays = 30;
    try {
        $wdRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_notification_warning_days'");
        if ($wdRow) $warningDays = max(1, intval($wdRow['config_value']));
    } catch (Exception $e) {}

    $recipientMode = 'all';
    try {
        $rmRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_notification_recipients'");
        if ($rmRow) $recipientMode = $rmRow['config_value'];
    } catch (Exception $e) {}

    $selectedUserIds = [];
    try {
        $suRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'procurement_notification_selected_users'");
        if ($suRow) $selectedUserIds = json_decode($suRow['config_value'], true) ?: [];
    } catch (Exception $e) {}

    // ---- BUILD RECIPIENT LIST ----
    $recipients = [];
    if ($recipientMode === 'all') {
        $recipients = $db->fetchAll(
            "SELECT DISTINCT u.id, u.email, u.full_name
             FROM users u
             JOIN user_acl_groups uag ON u.id = uag.user_id
             JOIN acl_groups ag ON uag.group_id = ag.id
             WHERE ag.group_name = 'procurement' AND u.is_active = 1 AND u.email IS NOT NULL AND u.email != ''"
        );
    } elseif ($recipientMode === 'selected' && !empty($selectedUserIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
        $recipients = $db->fetchAll(
            "SELECT id, email, full_name FROM users WHERE id IN ({$placeholders}) AND is_active = 1 AND email IS NOT NULL AND email != ''",
            $selectedUserIds
        );
    }

    CronHelper::info("Recipient mode: {$recipientMode}, recipients: " . count($recipients));
    CronHelper::info("Warning window: {$warningDays} days");

    $today = date('Y-m-d');
    $warningDate = date('Y-m-d', strtotime("+{$warningDays} days"));

    // ---- COUNTERS ----
    $totalProcessed = 0;
    $casesCreated = 0;
    $remindersSent = 0;
    $remindersFailed = 0;
    $errors = [];

    // ---- FETCH EXPIRING/EXPIRED CONTRACTS ----
    $contracts = $db->fetchAll(
        "SELECT vd.id as document_id, vd.contract_name, vd.contract_type,
                vd.contract_expiration_date, vd.vendor_request_id,
                r.vendor_name,
                DATEDIFF(vd.contract_expiration_date, NOW()) as days_until_expiry
         FROM vendor_documents vd
         JOIN vendor_onboarding_requests r ON vd.vendor_request_id = r.id
         WHERE vd.document_type = 'contract'
           AND vd.is_active = 1
           AND vd.contract_expiration_date IS NOT NULL
           AND vd.contract_expiration_date <= :warning_date
         ORDER BY vd.contract_expiration_date ASC",
        [':warning_date' => $warningDate]
    );

    CronHelper::info("Found " . count($contracts) . " contracts expiring within {$warningDays} days or already expired");

    // ---- MAIN LOOP ----
    foreach ($contracts as $contract) {
        $totalProcessed++;
        $expirationDate = $contract['contract_expiration_date'];
        $daysUntilExpiry = (int)$contract['days_until_expiry'];
        $contractName = $contract['contract_name'] ?: 'Unnamed Contract';

        // Determine reminder type
        // SQL already filters to contracts within the warning window or expired,
        // so any contract with days > 0 is a valid warning candidate.
        // Dedup table prevents duplicate sends for the same reminder type.
        $reminderType = null;
        if ($daysUntilExpiry > 0) {
            $reminderType = 'warning';
        } elseif ($daysUntilExpiry == 0) {
            $reminderType = 'expiring_today';
        } elseif ($daysUntilExpiry < 0) {
            // Expired -- only nag every 7 days
            $daysOverdue = abs($daysUntilExpiry);
            if ($daysOverdue % 7 === 0) {
                $reminderType = 'expired';
            }
        }

        if (!$reminderType) {
            if ($verbose) {
                CronHelper::info("Contract #{$contract['document_id']} ({$contractName}): Not due for reminder (expires: {$expirationDate}, days: {$daysUntilExpiry})");
            }
            continue;
        }

        if ($verbose) {
            CronHelper::info("Contract #{$contract['document_id']} ({$contractName}): Reminder type = {$reminderType}");
        }

        // ---- CREATE CASE IF NONE EXISTS ----
        $existingCase = $db->fetchOne(
            "SELECT id FROM cyber_todo_activities
             WHERE todo_type = 'contract_expiry'
               AND reference_type = 'vendor_onboarding_requests'
               AND reference_id = :ref_id
               AND JSON_EXTRACT(metadata, '$.document_id') = :doc_id
               AND status IN ('open', 'in_progress')",
            [':ref_id' => $contract['vendor_request_id'], ':doc_id' => $contract['document_id']]
        );

        if (!$existingCase) {
            $caseTitle = $daysUntilExpiry < 0
                ? "Contract Expired: {$contractName}"
                : "Contract Expiring: {$contractName}";

            $metadata = json_encode([
                'document_id' => $contract['document_id'],
                'contract_name' => $contractName,
                'contract_type' => $contract['contract_type'] ?? 'N/A',
                'expiration_date' => $expirationDate,
            ]);

            if (!$dryRun) {
                try {
                    $db->query(
                        "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, title, description, metadata, status, due_date, created_by)
                         VALUES ('contract_expiry', 'vendor_onboarding_requests', :ref_id, 'reminder', :title, :description, :metadata, 'open', :due_date, 0)",
                        [
                            ':ref_id' => $contract['vendor_request_id'],
                            ':title' => $caseTitle,
                            ':description' => "Vendor: {$contract['vendor_name']}. Contract: {$contractName}. Type: " . ($contract['contract_type'] ?? 'N/A') . ". Expires: {$expirationDate}.",
                            ':metadata' => $metadata,
                            ':due_date' => $expirationDate,
                        ]
                    );
                    $casesCreated++;
                    CronHelper::info("Created case for contract #{$contract['document_id']} ({$contractName})");

                    // Audit log the case creation (system user, no session)
                    try {
                        $auth = Auth::getInstance();
                        $auth->audit(null, 'case_create', 'cyber_todo_activities', null, [
                            'new' => [
                                'todo_type' => 'contract_expiry',
                                'title' => $caseTitle,
                                'vendor_name' => $contract['vendor_name'],
                                'contract_name' => $contractName,
                                'expiration_date' => $expirationDate,
                                'source' => 'cron',
                            ]
                        ]);
                    } catch (Exception $ae) {
                        // Don't let audit logging failure break the cron
                    }
                } catch (Exception $e) {
                    CronHelper::warn("Failed to create case for contract #{$contract['document_id']}: " . $e->getMessage());
                    $errors[] = "Case creation failed for contract #{$contract['document_id']}: " . $e->getMessage();
                }
            } else {
                CronHelper::info("[DRY RUN] Would create case: {$caseTitle}");
                $casesCreated++;
            }
        } else {
            if ($verbose) {
                CronHelper::info("Contract #{$contract['document_id']} ({$contractName}): Case already exists (ID: {$existingCase['id']})");
            }
        }

        // ---- SEND EMAIL REMINDERS ----
        if ($recipientMode === 'none' || !$emailEnabled) {
            if ($verbose) {
                CronHelper::info("Email reminders skipped (mode={$recipientMode}, emailEnabled=" . ($emailEnabled ? 'yes' : 'no') . ")");
            }
            continue;
        }

        foreach ($recipients as $recipient) {
            // Check dedup via vendor_contract_reminders UNIQUE KEY
            $existingReminder = $db->fetchOne(
                "SELECT id FROM vendor_contract_reminders
                 WHERE vendor_document_id = :doc_id
                   AND recipient_user_id = :user_id
                   AND reminder_type = :type
                   AND expiration_date = :exp_date",
                [
                    ':doc_id' => $contract['document_id'],
                    ':user_id' => $recipient['id'],
                    ':type' => $reminderType,
                    ':exp_date' => $expirationDate,
                ]
            );

            if ($existingReminder) {
                if ($verbose) {
                    CronHelper::info("  Reminder already sent to {$recipient['email']} for contract #{$contract['document_id']}");
                }
                continue;
            }

            // Create reminder record first
            try {
                $reminderId = $db->insert('vendor_contract_reminders', [
                    'vendor_document_id' => $contract['document_id'],
                    'recipient_user_id' => $recipient['id'],
                    'reminder_type' => $reminderType,
                    'expiration_date' => $expirationDate,
                    'status' => 'pending',
                    'email_sent_to' => $recipient['email'],
                ]);
            } catch (Exception $e) {
                if ($verbose) {
                    CronHelper::warn("  Failed to create reminder record: " . $e->getMessage());
                }
                continue;
            }

            // Send the email
            if (!$dryRun) {
                try {
                    $success = $emailService->sendContractExpiryReminder($contract, $recipient['email'], $reminderType);

                    if ($success) {
                        $db->update('vendor_contract_reminders', [
                            'status' => 'sent',
                            'sent_at' => date('Y-m-d H:i:s'),
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersSent++;
                        CronHelper::info("  Sent {$reminderType} reminder for contract #{$contract['document_id']} to {$recipient['email']}");
                    } else {
                        $db->update('vendor_contract_reminders', [
                            'status' => 'failed',
                            'error_message' => 'Email service returned false',
                        ], 'id = :id', [':id' => $reminderId]);

                        $remindersFailed++;
                        $errors[] = "Failed to send to {$recipient['email']} for contract #{$contract['document_id']}";
                    }
                } catch (Exception $e) {
                    $db->update('vendor_contract_reminders', [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ], 'id = :id', [':id' => $reminderId]);

                    $remindersFailed++;
                    $errors[] = "Exception sending to {$recipient['email']}: " . $e->getMessage();
                    CronHelper::error("  Exception: " . $e->getMessage());
                }
            } else {
                CronHelper::info("[DRY RUN] Would send {$reminderType} reminder for contract #{$contract['document_id']} ({$contractName}) to {$recipient['email']}");
                $remindersSent++;
            }
        }
    }

    // ---- SUMMARY ----
    CronHelper::info("Contract expiry reminder job completed:");
    CronHelper::info("  Total contracts processed: {$totalProcessed}");
    CronHelper::info("  Cases created: {$casesCreated}");
    CronHelper::info("  Reminders sent: {$remindersSent}");
    CronHelper::info("  Reminders failed: {$remindersFailed}");

    if ($dryRun) {
        CronHelper::info("  (DRY RUN - no emails actually sent, no cases created)");
    }

    $cron->logExecutionComplete($db, $totalProcessed, $remindersSent, $remindersFailed, $errors);

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

function showHelp(): void
{
    echo <<<HELP
Contract Expiry Reminders Cron Job

Sends email reminders for expiring procurement contracts and creates
Case Management cases for tracking.

Usage:
  php contract-expiry-reminders.php [options]

Options:
  --dry-run        Show what would be sent/created without actually doing it
  --verbose        Show detailed output during execution
  --help           Display this help message

Reminder Schedule:
  - Warning: Configurable days before expiration (default: 30)
  - Expiring today: On the expiration date
  - Expired: Every 7 days after expiration

Cron Setup:
  # Run daily at 3 AM
  0 3 * * * /usr/bin/php /path/to/cron/contract-expiry-reminders.php >> /var/log/tprm-contract-reminders.log 2>&1

HELP;
}
