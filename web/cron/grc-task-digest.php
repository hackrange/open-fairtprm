#!/usr/bin/env php
<?php
/**
 * GRC Task Assignment Digest Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Sends a single digest email per assignee containing all their open/in-progress
 * GRC assessment tasks. Avoids email fatigue by consolidating multiple task
 * assignments into one summary instead of individual notifications.
 *
 * Digest logic:
 *   - Queries all open/in_progress tasks grouped by assigned_to user
 *   - Sends one email per user with a table of their tasks
 *   - Tracks sent digests so the same set of tasks isn't re-notified
 *   - Re-sends only when new tasks are added or task state changes
 *
 * Usage:
 *   php grc-task-digest.php [options]
 *
 * Options:
 *   --dry-run        Show what would be sent without actually sending emails
 *   --verbose        Detailed logs as it runs
 *   --force          Send digest even if no new/changed tasks since last send
 *   --help           Show this help text
 *
 * Cron Setup:
 *   # Run daily at 6 AM
 *   0 6 * * * /usr/bin/php /var/www/html/cron/grc-task-digest.php >> /var/log/php/cron-grc-task-digest.log 2>&1
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
$options = getopt('', ['dry-run', 'verbose', 'force', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$dryRun  = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$force   = isset($options['force']);

// ---- BOOTSTRAP ----
try {
    require_once APP_ROOT . '/includes/init.php';
    require_once APP_ROOT . '/includes/classes/EmailService.php';
    require_once APP_ROOT . '/includes/classes/Encryption.php';
} catch (Exception $e) {
    CronHelper::error("Failed to initialize application: " . $e->getMessage());
    exit(1);
}

// ---- LOCK FILE ----
$cron = new CronHelper('grc-task-digest');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $encryption = new Encryption();
    $emailService = new EmailService($db, $encryption);

    if (!$emailService->isEnabled()) {
        CronHelper::warn("Email notifications are disabled in app_config. Skipping.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting GRC task digest job" . ($dryRun ? " (DRY RUN)" : "") . ($force ? " (FORCE)" : ""));

    // Ensure tracking table exists
    ensureDigestTable($db);

    // ========================================================================
    // Fetch all open/in_progress tasks grouped by assignee
    // ========================================================================
    $tasks = $db->fetchAll(
        "SELECT t.*, a.title AS assessment_title, a.assessment_ref,
                q.question_ref, u.email AS assignee_email,
                u.username AS assignee_username,
                COALESCE(u.full_name, u.username) AS assignee_name
         FROM grc_assessment_tasks t
         JOIN grc_assessments a ON a.id = t.assessment_id
         LEFT JOIN grc_unified_questions q ON q.id = t.question_id
         JOIN users u ON u.id = t.assigned_to
         WHERE t.assigned_to IS NOT NULL
           AND t.status IN ('open', 'in_progress')
           AND u.email IS NOT NULL
           AND u.email != ''
         ORDER BY t.assigned_to,
                  FIELD(t.priority, 'critical', 'high', 'medium', 'low'),
                  t.due_date ASC"
    );

    // Group tasks by assignee
    $tasksByUser = [];
    foreach ($tasks as $task) {
        $userId = $task['assigned_to'];
        if (!isset($tasksByUser[$userId])) {
            $tasksByUser[$userId] = [
                'email' => $task['assignee_email'],
                'name'  => $task['assignee_name'],
                'tasks' => [],
            ];
        }
        $tasksByUser[$userId]['tasks'][] = $task;
    }

    CronHelper::info("Found " . count($tasks) . " active task(s) across " . count($tasksByUser) . " assignee(s)");

    // ---- COUNTERS ----
    $totalUsers    = count($tasksByUser);
    $digestsSent   = 0;
    $digestsSkipped = 0;
    $digestsFailed = 0;
    $errors        = [];

    $assessmentUrl = baseUrl('grc-tasks.php');

    foreach ($tasksByUser as $userId => $userData) {
        $email     = $userData['email'];
        $name      = $userData['name'];
        $userTasks = $userData['tasks'];
        $taskCount = count($userTasks);

        // Build a fingerprint of the current task set to detect changes
        $fingerprint = buildTaskFingerprint($userTasks);

        // Check if we already sent a digest with this exact fingerprint
        if (!$force && !hasDigestChanged($db, $userId, $fingerprint)) {
            if ($verbose) {
                CronHelper::info("Skipping {$name} ({$email}): no changes since last digest ({$taskCount} tasks)");
            }
            $digestsSkipped++;
            continue;
        }

        if ($verbose) {
            CronHelper::info("Sending digest to {$name} ({$email}): {$taskCount} task(s)");
        }

        if (!$dryRun) {
            try {
                $success = $emailService->sendGrcTaskDigest($email, $name, $userTasks, $assessmentUrl);

                if ($success) {
                    recordDigestSent($db, $userId, $fingerprint, $taskCount);
                    $digestsSent++;
                    CronHelper::info("Sent digest to {$name} ({$email}): {$taskCount} task(s)");
                } else {
                    $digestsFailed++;
                    $errors[] = "EmailService returned false for {$email}";
                    CronHelper::warn("Failed to send digest to {$name} ({$email})");
                }
            } catch (Exception $e) {
                $digestsFailed++;
                $errors[] = "Exception sending to {$email}: " . $e->getMessage();
                CronHelper::error("Exception sending digest to {$name} ({$email}): " . $e->getMessage());
            }
        } else {
            CronHelper::info("[DRY RUN] Would send digest to {$name} ({$email}): {$taskCount} task(s)");
            foreach ($userTasks as $t) {
                CronHelper::info("  - [{$t['task_ref']}] {$t['title']} (Priority: {$t['priority']}, Due: " . ($t['due_date'] ?? 'none') . ")");
            }
            $digestsSent++;
        }
    }

    // ---- SUMMARY ----
    CronHelper::info("GRC task digest job completed:");
    CronHelper::info("  Total assignees: {$totalUsers}");
    CronHelper::info("  Digests sent: {$digestsSent}");
    CronHelper::info("  Skipped (no changes): {$digestsSkipped}");
    CronHelper::info("  Failed: {$digestsFailed}");
    if ($dryRun) {
        CronHelper::info("  (DRY RUN - no emails actually sent)");
    }

    $cron->logExecutionComplete($db, $totalUsers, $digestsSent, $digestsFailed, $errors);

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
// Helper Functions
// ============================================================================

/**
 * Ensure the digest tracking table exists.
 */
function ensureDigestTable(Database $db): void
{
    $db->query("
        CREATE TABLE IF NOT EXISTS grc_task_digest_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            task_fingerprint VARCHAR(64) NOT NULL,
            task_count INT UNSIGNED NOT NULL DEFAULT 0,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_sent (user_id, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Build a fingerprint (hash) of the current task set.
 * Changes when tasks are added, removed, or their status/priority/due_date changes.
 */
function buildTaskFingerprint(array $tasks): string
{
    $parts = [];
    foreach ($tasks as $t) {
        $parts[] = $t['id'] . ':' . $t['status'] . ':' . $t['priority'] . ':' . ($t['due_date'] ?? '');
    }
    sort($parts);
    return hash('sha256', implode('|', $parts));
}

/**
 * Check if the digest has changed since the last send for this user.
 */
function hasDigestChanged(Database $db, int $userId, string $fingerprint): bool
{
    $last = $db->fetchOne(
        "SELECT task_fingerprint FROM grc_task_digest_log
         WHERE user_id = :uid ORDER BY sent_at DESC LIMIT 1",
        [':uid' => $userId]
    );
    return !$last || $last['task_fingerprint'] !== $fingerprint;
}

/**
 * Record that a digest was sent.
 */
function recordDigestSent(Database $db, int $userId, string $fingerprint, int $taskCount): void
{
    $db->query(
        "INSERT INTO grc_task_digest_log (user_id, task_fingerprint, task_count) VALUES (:uid, :fp, :cnt)",
        [':uid' => $userId, ':fp' => $fingerprint, ':cnt' => $taskCount]
    );
}

/**
 * Print help text.
 */
function showHelp(): void
{
    echo <<<HELP
GRC Task Assignment Digest Cron Job

Sends a single digest email per assignee with all their open/in-progress GRC
assessment tasks. Avoids email fatigue by consolidating multiple tasks into
one summary email.

Usage:
  php grc-task-digest.php [options]

Options:
  --dry-run        Show what would be sent without sending emails
  --verbose        Show detailed output during execution
  --force          Send digest even if no task changes since last send
  --help           Display this help message

Digest Logic:
  - Groups all open/in_progress tasks by assignee
  - Sends one email per person with a task summary table
  - Tracks a fingerprint of each user's tasks to avoid re-sending
    the same digest when nothing has changed
  - Re-sends when tasks are added, removed, or status/priority changes

Cron Setup:
  # Run daily at 6 AM
  0 6 * * * /usr/bin/php /var/www/html/cron/grc-task-digest.php >> /var/log/php/cron-grc-task-digest.log 2>&1

HELP;
}
