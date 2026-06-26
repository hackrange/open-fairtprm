#!/usr/bin/env php
<?php
/**
 * Vendor Remediation Schedule - Daily Cron Job
 *
 * Processes due vendor "Action Plan" items (vendor_scheduled_actions). Any row
 * that is still pending and whose scheduled_date is today or earlier is fired
 * via CyberTodoService::executeScheduledAction() and flipped to executed/failed,
 * so missed days and late onboardings are caught up and each item fires once.
 *
 * Schedule: 0 7 * * * (daily at 7 AM)
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
    echo "Usage: php onboarding-scheduled-actions.php [--dry-run] [--verbose]\n";
    echo "  --dry-run   List the actions that would fire, but change nothing\n";
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

$cron = new CronHelper('onboarding-scheduled-actions');

if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();

    // Honour the Scheduler enable toggle.
    $enabled = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'cron_onboarding_scheduled_actions_enabled'");
    if (!$enabled || $enabled['config_value'] !== '1') {
        CronHelper::info("Vendor Remediation Schedule is disabled. Exiting.");
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);
    CronHelper::info("Starting Vendor Remediation Schedule" . ($dryRun ? ' (DRY RUN)' : ''));

    // Everything due on or before today that has not yet fired (executed_at IS NULL)
    // and is still in an active workflow state. Firing is tracked by executed_at so
    // the user-editable status (pending/in_progress/problem/completed/cancelled) is
    // never clobbered, and a once-fired action is never re-fired.
    $actions = $db->fetchAll(
        "SELECT * FROM vendor_scheduled_actions
         WHERE executed_at IS NULL AND scheduled_date <= CURDATE()
           AND status IN ('pending','in_progress')
         ORDER BY scheduled_date ASC, id ASC"
    );

    $processed = count($actions);
    $success   = 0;
    $failed    = 0;
    $errors    = [];

    if ($verbose) {
        CronHelper::info("Found {$processed} due action(s)");
    }

    if ($processed === 0) {
        if (!$dryRun) {
            $cron->logExecutionComplete($db, 0, 0, 0, []);
        }
        $cron->releaseLock();
        exit(0);
    }

    require_once APP_ROOT . '/includes/classes/SRSService.php';
    require_once APP_ROOT . '/includes/classes/CyberTodoService.php';
    $todoService = new CyberTodoService($db, new SRSService());

    // Fallback actor when an action has no creator on record (FK-safe: an admin).
    $fallbackActor = (int)($db->fetchOne(
        "SELECT u.id FROM users u
         JOIN user_acl_groups ug ON ug.user_id = u.id
         JOIN acl_groups g ON g.id = ug.group_id
         WHERE g.group_name = 'administrator'
         ORDER BY u.id ASC LIMIT 1"
    )['id'] ?? 0);

    foreach ($actions as $action) {
        $actor = (int)($action['created_by'] ?? 0) ?: $fallbackActor;
        $label = $action['action'];

        if ($dryRun) {
            CronHelper::info("[DRY RUN] Would fire action #{$action['id']} ({$label}) for vendor #{$action['request_id']} due {$action['scheduled_date']}");
            continue;
        }

        // Decode the saved cyber_tprm assignees, if any.
        $assignees = [];
        if (!empty($action['assignees'])) {
            $decoded = json_decode($action['assignees'], true);
            if (is_array($decoded)) { $assignees = $decoded; }
        }

        try {
            $result = $todoService->executeScheduledAction([
                'request_id'             => (int)$action['request_id'],
                'action'                 => $label,
                'assessment_template_id' => $action['assessment_template_id'],
                'description'            => $action['description'],
                'assignees'              => $assignees,
                'notify_assignees'       => !empty($action['notify_assignees']),
                'notify_emails'          => $action['notify_emails'] ?? '',
            ], $actor);

            if (empty($result['error'])) {
                // Mark as fired; advance pending -> in_progress but leave any status
                // the user already set (in_progress) untouched.
                $db->query(
                    "UPDATE vendor_scheduled_actions
                     SET result_type = :rt, result_id = :rid, error = NULL, executed_at = NOW(),
                         status = IF(status = 'pending', 'in_progress', status)
                     WHERE id = :id",
                    [':rt' => $result['result_type'], ':rid' => $result['result_id'], ':id' => $action['id']]
                );
                $success++;
                if ($verbose) {
                    CronHelper::info("Fired action #{$action['id']} ({$label}) for vendor #{$action['request_id']}");
                }
            } else {
                $db->query(
                    "UPDATE vendor_scheduled_actions
                     SET status = 'problem', error = :err, executed_at = NOW()
                     WHERE id = :id",
                    [':err' => $result['error'], ':id' => $action['id']]
                );
                $failed++;
                $errors[] = "Action #{$action['id']} ({$label}): " . $result['error'];
                CronHelper::warn("Action #{$action['id']} ({$label}) failed: " . $result['error']);
            }
        } catch (Exception $e) {
            $db->query(
                "UPDATE vendor_scheduled_actions
                 SET status = 'problem', error = :err, executed_at = NOW()
                 WHERE id = :id",
                [':err' => $e->getMessage(), ':id' => $action['id']]
            );
            $failed++;
            $errors[] = "Action #{$action['id']} ({$label}): " . $e->getMessage();
            CronHelper::error("Action #{$action['id']} ({$label}) threw: " . $e->getMessage());
        }
    }

    CronHelper::info("Complete: {$processed} due, {$success} fired, {$failed} failed");

    if (!$dryRun) {
        $cron->logExecutionComplete($db, $processed, $success, $failed, $errors);
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
