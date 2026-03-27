<?php
/**
 * GRC Policy Review Reminder - Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Sends email reminders for policies that are due for annual review and
 * for users who haven't acknowledged published policies within the deadline.
 *
 * Schedule: Daily at 5 AM
 * Usage: php /var/www/html/cron/grc-policy-reminders.php [--verbose] [--help]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$verbose = in_array('--verbose', $argv ?? []);
$help = in_array('--help', $argv ?? []);

if ($help) {
    echo "GRC Policy Review Reminder\n";
    echo "Usage: php grc-policy-reminders.php [--verbose] [--help]\n";
    exit(0);
}

define('APP_ROOT', dirname(__DIR__));
define('INCLUDES_PATH', APP_ROOT . '/includes');
define('CLASSES_PATH', APP_ROOT . '/includes/classes');
define('CONFIG_PATH', APP_ROOT . '/config');
require_once INCLUDES_PATH . '/init.php';

try {
    $db = Database::getInstance();

    // Find policies due for review (within 30 days or overdue)
    $duePolices = $db->fetchAll(
        "SELECT p.*, u.full_name as owner_name, u.email as owner_email
         FROM grc_policies p
         LEFT JOIN users u ON u.id = p.owner_user_id
         WHERE p.is_active = 1
           AND p.next_review_date IS NOT NULL
           AND p.next_review_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    );

    if ($verbose) echo date('Y-m-d H:i:s') . " - " . count($duePolices) . " policies due for review\n";

    foreach ($duePolices as $policy) {
        // Check if we already sent a reminder today
        $existing = $db->fetchOne(
            "SELECT id FROM grc_policy_review_schedule
             WHERE policy_id = :pid AND scheduled_date = CURDATE()",
            [':pid' => $policy['id']]
        );

        if (!$existing && !empty($policy['owner_email'])) {
            // Create review schedule entry
            $db->insert('grc_policy_review_schedule', [
                'policy_id' => $policy['id'],
                'scheduled_date' => date('Y-m-d'),
                'reviewer_user_id' => $policy['owner_user_id'],
                'status' => 'pending',
                'reminder_sent_at' => date('Y-m-d H:i:s'),
            ]);

            // Send email reminder
            if (class_exists('EmailService')) {
                try {
                    $emailService = new EmailService();
                    $isOverdue = strtotime($policy['next_review_date']) < time();
                    $subject = ($isOverdue ? 'OVERDUE: ' : '') . 'Policy Review Due: ' . $policy['title'];
                    $body = "Hello " . ($policy['owner_name'] ?? 'Policy Owner') . ",\n\n";
                    $body .= "The following policy is " . ($isOverdue ? 'OVERDUE for' : 'due for') . " review:\n\n";
                    $body .= "Policy: " . $policy['title'] . " (" . $policy['policy_ref'] . ")\n";
                    $body .= "Review Due: " . $policy['next_review_date'] . "\n\n";
                    $body .= "Please log in to review and update this policy.\n";

                    $emailService->send($policy['owner_email'], $subject, $body);
                    if ($verbose) echo "  Sent reminder for: {$policy['policy_ref']} to {$policy['owner_email']}\n";
                } catch (Exception $e) {
                    error_log('Policy reminder email failed: ' . $e->getMessage());
                }
            }
        }
    }

    if ($verbose) echo date('Y-m-d H:i:s') . " - Complete\n";
} catch (Exception $e) {
    error_log('GRC policy reminder error: ' . $e->getMessage());
    if ($verbose) echo "ERROR: " . $e->getMessage() . "\n";
}
