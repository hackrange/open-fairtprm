#!/usr/bin/env php
<?php
/**
 * Sync Crontab — Regenerate /etc/cron.d/open-tprm from database settings
 *
 * Called by docker/entrypoint.sh on every container start to ensure the
 * crontab reflects the timezone, schedules, and enabled/disabled state
 * configured in Admin > Scheduler. Also called after scheduler saves.
 *
 * Job definitions here MUST stay in sync with includes/admin/scheduler.php.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

$_appRoot = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $_appRoot);
    define('INCLUDES_PATH', $_appRoot . '/includes');
    define('CLASSES_PATH', $_appRoot . '/includes/classes');
    define('CONFIG_PATH', $_appRoot . '/config');
}
chdir(APP_ROOT);

try {
    require_once APP_ROOT . '/includes/init.php';
} catch (Exception $e) {
    fwrite(STDERR, "Failed to initialize: " . $e->getMessage() . "\n");
    exit(1);
}

$db = Database::getInstance();

// -------------------------------------------------------------------------
// Job definitions — keep in sync with includes/admin/scheduler.php
// -------------------------------------------------------------------------
$cronJobs = [
    'rescore_queue'        => ['script' => 'rescore-queue.php',              'log' => '/var/log/php/cron-rescore-queue.log',            'default_schedule' => '* * * * *'],
    'breach_scan_queue'    => ['script' => 'breach-scan-queue.php',          'log' => '/var/log/php/cron-breach-scan-queue.log',        'default_schedule' => '* * * * *',   'timeout' => 1800],
    'srs_rescore'          => ['script' => 'srs-rescore.php',                'log' => '/var/log/php/cron-srs-rescore.log',              'default_schedule' => '0 * * * *'],
    'annual_review'        => ['script' => 'annual-review-reminders.php',    'log' => '/var/log/php/cron-annual-review.log',            'default_schedule' => '0 2 * * *'],
    'contract_expiry'      => ['script' => 'contract-expiry-reminders.php',  'log' => '/var/log/php/cron-contract-expiry.log',          'default_schedule' => '0 3 * * *'],
    'assessment_reminders' => ['script' => 'assessment-reminders.php',       'log' => '/var/log/php/cron-assessment-reminders.log',     'default_schedule' => '0 4 * * *'],
    'grc_policy_reminders' => ['script' => 'grc-policy-reminders.php',       'log' => '/var/log/php/cron-grc-policy-reminders.log',     'default_schedule' => '0 5 * * *'],
    'grc_monitors'         => ['script' => 'grc-continuous-monitors.php',    'log' => '/var/log/php/cron-grc-monitors.log',             'default_schedule' => '*/5 * * * *'],
    'grc_task_digest'      => ['script' => 'grc-task-digest.php',            'log' => '/var/log/php/cron-grc-task-digest.log',          'default_schedule' => '0 6 * * *'],
    'breach_monitor'       => ['script' => 'breach-monitor.php',             'log' => '/var/log/php/cron-breach-monitor.log',           'default_schedule' => '0 7 * * *',   'timeout' => 1800],
    'osint_company'        => ['script' => 'osint-company.php',              'log' => '/var/log/php/cron-osint-company.log',            'default_schedule' => '0 8 * * *',   'timeout' => 600],
    'osint_vendors'        => ['script' => 'osint-vendors.php',              'log' => '/var/log/php/cron-osint-vendors.log',            'default_schedule' => '0 2 * * 0',   'timeout' => 7200],
];

// -------------------------------------------------------------------------
// Read cron config from database
// -------------------------------------------------------------------------
$rows = $db->fetchAll("SELECT config_key, config_value FROM app_config WHERE config_key LIKE 'cron_%'");
$cfg = [];
foreach ($rows as $row) {
    $cfg[$row['config_key']] = $row['config_value'];
}

$timezone = $cfg['cron_timezone'] ?? 'UTC';

// -------------------------------------------------------------------------
// Build crontab content
// -------------------------------------------------------------------------
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

// -------------------------------------------------------------------------
// Write to /etc/cron.d/open-tprm (requires root via sudo)
// -------------------------------------------------------------------------
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('sudo /usr/bin/tee /etc/cron.d/open-tprm', $descriptors, $pipes);
if (!is_resource($proc)) {
    // Fallback: try direct write (works when running as root in entrypoint)
    if (@file_put_contents('/etc/cron.d/open-tprm', $content) !== false) {
        echo "    - Crontab synced (timezone: {$timezone}, direct write)\n";
        exit(0);
    }
    fwrite(STDERR, "Failed to write /etc/cron.d/open-tprm\n");
    exit(1);
}
fwrite($pipes[0], $content);
fclose($pipes[0]);
stream_get_contents($pipes[1]);
fclose($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode === 0) {
    echo "    - Crontab synced (timezone: {$timezone})\n";
    exit(0);
} else {
    // Fallback: try direct write (entrypoint runs as root)
    if (@file_put_contents('/etc/cron.d/open-tprm', $content) !== false) {
        echo "    - Crontab synced (timezone: {$timezone}, direct write)\n";
        exit(0);
    }
    fwrite(STDERR, "Failed to write crontab (exit code: {$exitCode})\n");
    exit(1);
}
