<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Scheduler (Cron Job Management)
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Gives admins a web UI to view, enable/disable, and reschedule cron jobs
 * without SSH access or a Docker rebuild. Changes are persisted in app_config
 * and written live to /etc/cron.d/open-tprm in the running container.
 *
 * Also shows recent execution history from cron_execution_history so you can
 * see at a glance whether your jobs are behaving or misbehaving.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// Job Definitions
// ============================================================================
// Canonical job definitions + crontab helpers live in includes/cron-jobs.php so
// scheduler.php, cron/sync-crontab.php, and the Shadow SaaS admin page all share
// ONE source of truth (no more hand-synced duplicate arrays). The require below
// also defines regenerateCrontab()/isValidCronExpression()/describeCron(); the
// guarded copies further down this file are therefore skipped (kept only as a
// fallback if this file is somehow loaded standalone).
require_once __DIR__ . '/../cron-jobs.php';
$cronJobs = cronJobDefinitions();

// ============================================================================
// Helper: Regenerate /etc/cron.d/open-tprm from DB config
// ============================================================================
if (!function_exists('regenerateCrontab')) {
function regenerateCrontab(Database $db, array $cronJobs): bool
{
    // Read all cron_* config from DB
    $rows = $db->fetchAll(
        "SELECT config_key, config_value FROM app_config WHERE config_key LIKE 'cron_%'"
    );
    $cfg = [];
    foreach ($rows as $row) {
        $cfg[$row['config_key']] = $row['config_value'];
    }

    $timezone = $cfg['cron_timezone'] ?? 'UTC';

    $lines = [];
    $lines[] = '# =============================================================================';
    $lines[] = '# Open TPRM & GRC - Cron Jobs (managed via Admin > Scheduler)';
    $lines[] = '# Auto-generated — do not edit manually';
    $lines[] = '# =============================================================================';
    $lines[] = '';
    $lines[] = 'CRON_TZ=' . $timezone;
    $lines[] = '';

    foreach ($cronJobs as $key => $job) {
        $enabled  = ($cfg["cron_{$key}_enabled"] ?? '1') === '1';
        $schedule = $cfg["cron_{$key}_schedule"] ?? $job['default_schedule'];

        if (!$enabled) {
            continue;
        }

        // Long-running jobs are wrapped in `timeout` so a hung run can't pile
        // up. Keep this in lockstep with cron/sync-crontab.php.
        $timeout = (int)($job['timeout'] ?? 0);
        if ($timeout > 0) {
            $lines[] = sprintf(
                '%s www-data /usr/bin/timeout --signal=TERM --kill-after=30 %d /usr/bin/php /var/www/html/cron/%s >> %s 2>&1',
                $schedule,
                $timeout,
                $job['script'],
                $job['log']
            );
        } else {
            $lines[] = sprintf(
                '%s www-data /usr/bin/php /var/www/html/cron/%s >> %s 2>&1',
                $schedule,
                $job['script'],
                $job['log']
            );
        }
    }

    // Cron requires a trailing newline
    $lines[] = '';

    $content = implode("\n", $lines);

    // /etc/cron.d/open-tprm must be owned by root (cron requirement).
    // Use sudo tee via proc_open (popen is disabled by php-hardening.ini).
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open('sudo /usr/bin/tee /etc/cron.d/open-tprm', $descriptors, $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    fwrite($pipes[0], $content);
    fclose($pipes[0]);
    // Drain stdout/stderr to prevent blocking
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    return $exitCode === 0;
}
} // end function_exists('regenerateCrontab')

// ============================================================================
// Helper: Validate a 5-field cron expression
// ============================================================================
if (!function_exists('isValidCronExpression')) {
function isValidCronExpression(string $expr): bool
{
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) {
        return false;
    }
    foreach ($fields as $field) {
        if (!preg_match('/^[0-9,\-\*\/]+$/', $field)) {
            return false;
        }
    }
    return true;
}
} // end function_exists('isValidCronExpression')

// ============================================================================
// Helper: Human-readable cron description
// ============================================================================
if (!function_exists('describeCron')) {
function describeCron(string $expr): string
{
    $map = [
        '* * * * *'   => 'Every minute',
        '*/5 * * * *' => 'Every 5 minutes',
        '0 * * * *'   => 'Every hour',
        '0 0 * * *'   => 'Daily at midnight',
        '0 1 * * *'   => 'Daily at 1:00 AM',
        '0 2 * * *'   => 'Daily at 2:00 AM',
        '0 3 * * *'   => 'Daily at 3:00 AM',
        '0 4 * * *'   => 'Daily at 4:00 AM',
        '0 5 * * *'   => 'Daily at 5:00 AM',
        '0 6 * * *'   => 'Daily at 6:00 AM',
        '0 12 * * *'  => 'Daily at noon',
        '0 0 * * 0'   => 'Weekly on Sunday at midnight',
        '0 0 1 * *'   => 'Monthly on the 1st at midnight',
    ];
    if (isset($map[$expr])) {
        return $map[$expr];
    }

    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) {
        return $expr;
    }

    [$min, $hour, $dom, $mon, $dow] = $fields;

    // "N M * * *" pattern — daily at H:MM
    if ($dom === '*' && $mon === '*' && $dow === '*' && is_numeric($min) && is_numeric($hour)) {
        $h = (int)$hour;
        $m = (int)$min;
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;
        return sprintf('Daily at %d:%02d %s', $h12, $m, $suffix);
    }

    return $expr;
}
} // end function_exists('describeCron')

// ============================================================================
// POST Handler: save_schedules
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_scheduler.invalid_request');
    } elseif (isset($_POST['save_schedules'])) {
        $changes = [];
        $validationError = false;

        // Validate and collect timezone
        $tz = trim($_POST['cron_timezone'] ?? 'UTC');
        if ($tz === '' || !in_array($tz, timezone_identifiers_list())) {
            $error = t('admin_scheduler.invalid_timezone');
            $validationError = true;
        }

        if (!$validationError) {
            // Validate all schedules first
            foreach ($cronJobs as $key => $job) {
                if (!empty($job['hidden'])) { continue; } // managed on its own admin page
                $schedule = trim($_POST["schedule_{$key}"] ?? $job['default_schedule']);
                if (!isValidCronExpression($schedule)) {
                    $error = t('admin_scheduler.invalid_cron_prefix') . e($job['name']) . t('admin_scheduler.invalid_cron_suffix');
                    $validationError = true;
                    break;
                }
            }
        }

        if (!$validationError) {
            try {
                // Save timezone
                $oldTz = getAppConfig('cron_timezone', 'UTC');
                $db->query(
                    "INSERT INTO app_config (config_key, config_value, description)
                     VALUES ('cron_timezone', :val, 'Cron job timezone')
                     ON DUPLICATE KEY UPDATE config_value = :val2",
                    [':val' => $tz, ':val2' => $tz]
                );
                if ($oldTz !== $tz) {
                    $changes[] = "Timezone: {$oldTz} → {$tz}";
                }

                // Save each job's config
                foreach ($cronJobs as $key => $job) {
                    if (!empty($job['hidden'])) { continue; } // managed on its own admin page
                    $enabled  = isset($_POST["enabled_{$key}"]) ? '1' : '0';
                    $schedule = trim($_POST["schedule_{$key}"] ?? $job['default_schedule']);

                    $oldEnabled  = getAppConfig("cron_{$key}_enabled", '1');
                    $oldSchedule = getAppConfig("cron_{$key}_schedule", $job['default_schedule']);

                    // Upsert enabled
                    $db->query(
                        "INSERT INTO app_config (config_key, config_value, description)
                         VALUES (:key, :val, :desc)
                         ON DUPLICATE KEY UPDATE config_value = :val2",
                        [
                            ':key'  => "cron_{$key}_enabled",
                            ':val'  => $enabled,
                            ':val2' => $enabled,
                            ':desc' => $job['name'] . ' enabled',
                        ]
                    );

                    // Upsert schedule
                    $db->query(
                        "INSERT INTO app_config (config_key, config_value, description)
                         VALUES (:key, :val, :desc)
                         ON DUPLICATE KEY UPDATE config_value = :val2",
                        [
                            ':key'  => "cron_{$key}_schedule",
                            ':val'  => $schedule,
                            ':val2' => $schedule,
                            ':desc' => $job['name'] . ' schedule',
                        ]
                    );

                    if ($oldEnabled !== $enabled) {
                        $changes[] = $job['name'] . ': ' . ($enabled === '1' ? 'enabled' : 'disabled');
                    }
                    if ($oldSchedule !== $schedule) {
                        $changes[] = $job['name'] . " schedule: {$oldSchedule} → {$schedule}";
                    }
                }

                // Save SRS rescore batch size
                $batchSize = max(1, min(100, (int)($_POST['srs_rescore_batch_size'] ?? 20)));
                $oldBatch = getAppConfig('cron_srs_rescore_batch_size', '20');
                $db->query(
                    "INSERT INTO app_config (config_key, config_value, description)
                     VALUES ('cron_srs_rescore_batch_size', :val, 'SRS rescoring vendors per cron run')
                     ON DUPLICATE KEY UPDATE config_value = :val2",
                    [':val' => (string)$batchSize, ':val2' => (string)$batchSize]
                );
                if ($oldBatch !== (string)$batchSize) {
                    $changes[] = "SRS batch size: {$oldBatch} → {$batchSize}";
                }

                // Clear config cache so the render pass reads fresh values
                getAppConfig('', null, true);

                // Regenerate crontab
                if (!regenerateCrontab($db, $cronJobs)) {
                    $error = t('admin_scheduler.crontab_write_failed');
                } else {
                    $success = t('admin_scheduler.schedules_updated');
                }

                // Audit log
                if (!empty($changes)) {
                    $auth->audit($user['id'], 'cron_schedules_update', 'app_config', null, [
                        'new' => ['changes' => $changes],
                    ]);
                }
            } catch (Exception $e) {
                error_log('Scheduler save error: ' . $e->getMessage());
                $error = t('admin_scheduler.save_failed_prefix') . $e->getMessage();
            }
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================

// Load current config from app_config (seed defaults if missing)
$jobConfigs = [];
foreach ($cronJobs as $key => $job) {
    if (!empty($job['hidden'])) { continue; } // managed on its own admin page (e.g. Shadow SaaS)
    $enabled  = getAppConfig("cron_{$key}_enabled", null);
    $schedule = getAppConfig("cron_{$key}_schedule", null);

    // Seed defaults if this is the first visit
    if ($enabled === null) {
        try {
            $db->query(
                "INSERT IGNORE INTO app_config (config_key, config_value, description)
                 VALUES (:key, '1', :desc)",
                [':key' => "cron_{$key}_enabled", ':desc' => $job['name'] . ' enabled']
            );
        } catch (Exception $e) { /* already exists */ }
        $enabled = '1';
    }
    if ($schedule === null) {
        try {
            $db->query(
                "INSERT IGNORE INTO app_config (config_key, config_value, description)
                 VALUES (:key, :val, :desc)",
                [
                    ':key'  => "cron_{$key}_schedule",
                    ':val'  => $job['default_schedule'],
                    ':desc' => $job['name'] . ' schedule',
                ]
            );
        } catch (Exception $e) { /* already exists */ }
        $schedule = $job['default_schedule'];
    }

    $jobConfigs[$key] = [
        'enabled'  => $enabled,
        'schedule' => $schedule,
    ];
}

// Load timezone
$cronTimezone = getAppConfig('cron_timezone', null);
if ($cronTimezone === null) {
    $cronTimezone = 'UTC';
    try {
        $db->query(
            "INSERT IGNORE INTO app_config (config_key, config_value, description)
             VALUES ('cron_timezone', 'UTC', 'Cron job timezone')"
        );
    } catch (Exception $e) { /* already exists */ }
}

// Query last execution per job from cron_execution_history
$lastExecutions = [];
try {
    $rows = $db->fetchAll(
        "SELECT h.job_name, h.started_at, h.completed_at, h.status,
                h.processed_count, h.success_count, h.failed_count,
                h.execution_time_seconds, h.error_messages
         FROM cron_execution_history h
         INNER JOIN (
             SELECT job_name, MAX(id) AS max_id
             FROM cron_execution_history
             GROUP BY job_name
         ) latest ON h.id = latest.max_id"
    );
    foreach ($rows as $row) {
        $lastExecutions[$row['job_name']] = $row;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Query last 20 executions for history card
$recentHistory = [];
try {
    $recentHistory = $db->fetchAll(
        "SELECT job_name, started_at, completed_at, status,
                processed_count, success_count, failed_count,
                execution_time_seconds, error_messages
         FROM cron_execution_history
         ORDER BY id DESC
         LIMIT 20"
    );
} catch (Exception $e) {
    // Table might not exist yet
}

// Build timezone list grouped by region
$timezones = timezone_identifiers_list();

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_scheduler.page_title')); ?></h1>
    <p><?php echo e(t('admin_scheduler.page_subtitle')); ?></p>
</div>

<form method="POST" action="admin.php?section=scheduler">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
    <input type="hidden" name="save_schedules" value="1">

    <div class="card">
        <h3><?php echo e(t('admin_scheduler.scheduled_jobs_heading')); ?></h3>

        <div class="form-group" style="max-width: 350px; margin-bottom: 20px;">
            <label for="cron_timezone"><?php echo e(t('admin_scheduler.timezone_label')); ?></label>
            <select name="cron_timezone" id="cron_timezone" class="form-control">
                <?php foreach ($timezones as $tz): ?>
                    <option value="<?php echo e($tz); ?>"<?php echo $tz === $cronTimezone ? ' selected' : ''; ?>><?php echo e($tz); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-help"><?php echo e(t('admin_scheduler.timezone_help_prefix')); ?> <strong id="tzClock">--</strong></div>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php echo e(t('admin_scheduler.th_enabled')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_job_name')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_description')); ?></th>
                        <th style="width: 140px;"><?php echo e(t('admin_scheduler.th_schedule')); ?></th>
                        <th style="width: 160px;"><?php echo e(t('admin_scheduler.th_runs_at')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_last_run')); ?></th>
                        <th style="width: 90px;"><?php echo e(t('admin_scheduler.th_status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cronJobs as $key => $job):
                        if (!empty($job['hidden'])) { continue; } // managed on its own admin page (e.g. Shadow SaaS)
                        $cfg = $jobConfigs[$key];
                        $lastExec = $lastExecutions[$job['history_key']] ?? null;
                    ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="checkbox" name="enabled_<?php echo e($key); ?>" value="1"
                                <?php echo $cfg['enabled'] === '1' ? 'checked' : ''; ?>
                                style="width: 18px; height: 18px; cursor: pointer;">
                        </td>
                        <td><strong><?php echo e($job['name']); ?></strong></td>
                        <td style="color: #666; font-size: 12px;"><?php echo e($job['description']); ?></td>
                        <td>
                            <input type="text" name="schedule_<?php echo e($key); ?>"
                                   value="<?php echo e($cfg['schedule']); ?>"
                                   class="form-control" style="padding: 6px 8px; font-family: monospace; font-size: 13px;">
                        </td>
                        <td style="color: #666; font-size: 12px;">
                            <?php echo e(describeCron($cfg['schedule'])); ?>
                        </td>
                        <td style="font-size: 12px;">
                            <?php if ($lastExec): ?>
                                <?php echo e(date('M j, g:i A', strtotime($lastExec['started_at']))); ?>
                                <?php if ($lastExec['execution_time_seconds']): ?>
                                    <span style="color: #999;">(<?php echo e(round((float)$lastExec['execution_time_seconds'], 1)); ?>s)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #999;"><?php echo e(t('admin_scheduler.never')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$lastExec): ?>
                                <span class="badge" style="background: #f3f4f6; color: #6b7280;"><?php echo e(t('admin_scheduler.never_run')); ?></span>
                            <?php elseif ($lastExec['status'] === 'completed'): ?>
                                <span class="badge badge-success"><?php echo e(t('admin_scheduler.status_completed')); ?></span>
                            <?php elseif ($lastExec['status'] === 'failed'): ?>
                                <span class="badge badge-danger"><?php echo e(t('admin_scheduler.status_failed')); ?></span>
                            <?php elseif ($lastExec['status'] === 'running'): ?>
                                <span class="badge badge-blue"><?php echo e(t('admin_scheduler.status_running')); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
            <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;"><?php echo e(t('admin_scheduler.srs_batch_heading')); ?></h4>
            <div class="form-group" style="max-width: 200px; margin-bottom: 10px;">
                <input type="number" name="srs_rescore_batch_size"
                       value="<?php echo e(getAppConfig('cron_srs_rescore_batch_size', '20')); ?>"
                       min="1" max="100" class="form-control" style="padding: 6px 8px;">
                <div class="form-help"><?php echo e(t('admin_scheduler.srs_batch_help')); ?></div>
            </div>
        </div>

        <div style="margin-top: 20px; display: flex; align-items: center; gap: 15px;">
            <button type="submit" class="btn btn-primary" data-disable-on-click="<?php echo e(t('admin_scheduler.saving')); ?>"><?php echo e(t('admin_scheduler.save_button')); ?></button>
            <span style="font-size: 12px; color: #999;"><?php echo e(t('admin_scheduler.changes_written_prefix')); ?> <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;">/etc/cron.d/open-tprm</code> <?php echo e(t('admin_scheduler.changes_written_suffix')); ?></span>
        </div>
    </div>
</form>

<div class="card">
    <h3><?php echo e(t('admin_scheduler.execution_history_heading')); ?></h3>
    <?php if (empty($recentHistory)): ?>
        <p style="color: #999;"><?php echo e(t('admin_scheduler.no_history')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px; font-size: 13px;"><?php echo e(t('admin_scheduler.history_subtitle')); ?></p>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th><?php echo e(t('admin_scheduler.th_job')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_started')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_duration')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_processed')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_success')); ?></th>
                        <th><?php echo e(t('admin_scheduler.status_failed')); ?></th>
                        <th><?php echo e(t('admin_scheduler.th_status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentHistory as $exec): ?>
                    <tr>
                        <td><strong><?php echo e($exec['job_name']); ?></strong></td>
                        <td style="font-size: 12px; white-space: nowrap;"><?php echo e(date('M j, g:i:s A', strtotime($exec['started_at']))); ?></td>
                        <td style="font-size: 12px;">
                            <?php if ($exec['execution_time_seconds'] !== null): ?>
                                <?php echo e(round((float)$exec['execution_time_seconds'], 1)); ?>s
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$exec['processed_count']; ?></td>
                        <td><?php echo (int)$exec['success_count']; ?></td>
                        <td>
                            <?php if ((int)$exec['failed_count'] > 0): ?>
                                <span style="color: #dc2626; font-weight: 500;"><?php echo (int)$exec['failed_count']; ?></span>
                            <?php else: ?>
                                <?php echo (int)$exec['failed_count']; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($exec['status'] === 'completed'): ?>
                                <span class="badge badge-success"><?php echo e(t('admin_scheduler.status_completed')); ?></span>
                            <?php elseif ($exec['status'] === 'failed'): ?>
                                <span class="badge badge-danger"><?php echo e(t('admin_scheduler.status_failed')); ?></span>
                            <?php elseif ($exec['status'] === 'running'): ?>
                                <span class="badge badge-blue"><?php echo e(t('admin_scheduler.status_running')); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($exec['status'] === 'failed' && !empty($exec['error_messages'])): ?>
                    <tr>
                        <td colspan="7" style="background: #fef2f2; padding: 8px 10px; font-size: 12px; color: #991b1b;">
                            <?php
                            $errors = json_decode($exec['error_messages'], true);
                            if (is_array($errors)) {
                                echo e(implode('; ', array_slice($errors, 0, 3)));
                                if (count($errors) > 3) {
                                    echo ' <span style="color: #999;">' . e(t('admin_scheduler.errors_and_prefix')) . ' ' . (count($errors) - 3) . ' ' . e(t('admin_scheduler.errors_more_suffix')) . '</span>';
                                }
                            } else {
                                echo e($exec['error_messages']);
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Live clock showing current time in selected timezone
(function() {
    var tzSelect = document.getElementById('cron_timezone');
    var clockEl = document.getElementById('tzClock');
    function updateClock() {
        try {
            var tz = tzSelect.value;
            var now = new Date();
            clockEl.textContent = now.toLocaleString('en-US', {
                timeZone: tz,
                hour: 'numeric', minute: '2-digit', second: '2-digit',
                hour12: true, month: 'short', day: 'numeric'
            });
        } catch(e) {
            clockEl.textContent = '--';
        }
    }
    updateClock();
    setInterval(updateClock, 1000);
    tzSelect.addEventListener('change', updateClock);
})();
</script>
