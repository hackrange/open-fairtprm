<?php
/**
 * Canonical cron-job definitions + crontab generation helpers.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * SINGLE SOURCE OF TRUTH for the scheduled jobs. Previously the $cronJobs array
 * was duplicated in includes/admin/scheduler.php AND cron/sync-crontab.php and
 * the two "had to be kept in sync by hand" — a footgun that silently dropped
 * jobs from the generated crontab when they diverged. Both files now pull the
 * definitions from cronJobDefinitions() here, so they can never drift, and the
 * Shadow SaaS admin page reuses regenerateCrontab() to manage its own job.
 *
 * Every consumer:
 *   - includes/admin/scheduler.php  (renders/saves all NON-hidden jobs)
 *   - cron/sync-crontab.php         (entrypoint: regenerates /etc/cron.d/open-tprm)
 *   - includes/admin/shadow-saas.php(shadow-saas-grip.php; manages the hidden
 *                                    shadow_saas_rehydrate job on its own page)
 *
 * A job flagged 'hidden' => true is still installed into the crontab, but is NOT
 * rendered or saved by the central Scheduler page — it is managed elsewhere (the
 * Shadow SaaS slug). regenerateCrontab() ignores 'hidden' entirely; it only ever
 * looks at the per-job app_config (cron_<key>_enabled / cron_<key>_schedule).
 */

if (!function_exists('cronJobDefinitions')) {
/**
 * @return array<string,array> keyed job definitions
 */
function cronJobDefinitions(): array
{
    return [
        'ai_queue' => [
            'name'            => 'AI Job Queue',
            'description'     => "Processes queued AI tasks so long-running AI calls don't block web workers",
            'script'          => 'ai-queue.php',
            'log'             => '/var/log/php/cron-ai-queue.log',
            'default_schedule' => '* * * * *',
            'history_key'     => 'ai-queue',
        ],
        'rescore_queue' => [
            'name'            => 'Rescore Queue',
            'description'     => 'Processes the UpGuard SRS rescore queue',
            'script'          => 'rescore-queue.php',
            'log'             => '/var/log/php/cron-rescore-queue.log',
            'default_schedule' => '* * * * *',
            'history_key'     => 'rescore-queue',
        ],
        'breach_scan_queue' => [
            'name'            => 'Breach Scan Queue',
            'description'     => 'Processes on-demand breach scan requests from the "Run Scan Now" button',
            'script'          => 'breach-scan-queue.php',
            'log'             => '/var/log/php/cron-breach-scan-queue.log',
            'default_schedule' => '* * * * *',
            'timeout'         => 1800,
            'history_key'     => 'breach-scan-queue',
        ],
        'srs_rescore' => [
            'name'            => 'SRS Rescoring',
            'description'     => 'Scheduled UpGuard SRS rescoring for all vendors',
            'script'          => 'srs-rescore.php',
            'log'             => '/var/log/php/cron-srs-rescore.log',
            'default_schedule' => '0 * * * *',
            'history_key'     => 'srs-rescore',
        ],
        'annual_review' => [
            'name'            => 'Annual Review Reminders',
            'description'     => 'Sends reminder emails for upcoming annual reviews',
            'script'          => 'annual-review-reminders.php',
            'log'             => '/var/log/php/cron-annual-review.log',
            'default_schedule' => '0 2 * * *',
            'history_key'     => 'annual-review-reminders',
        ],
        'contract_expiry' => [
            'name'            => 'Contract Expiry Reminders',
            'description'     => 'Sends reminder emails for expiring contracts',
            'script'          => 'contract-expiry-reminders.php',
            'log'             => '/var/log/php/cron-contract-expiry.log',
            'default_schedule' => '0 3 * * *',
            'history_key'     => 'contract-expiry-reminders',
        ],
        'assessment_reminders' => [
            'name'            => 'Assessment Reminders',
            'description'     => 'Sends reminder emails for pending vendor assessments',
            'script'          => 'assessment-reminders.php',
            'log'             => '/var/log/php/cron-assessment-reminders.log',
            'default_schedule' => '0 4 * * *',
            'history_key'     => 'assessment-reminders',
        ],
        'grc_policy_reminders' => [
            'name'            => 'GRC Policy Reminders',
            'description'     => 'Sends reminder emails for policies due for review and unacknowledged policies',
            'script'          => 'grc-policy-reminders.php',
            'log'             => '/var/log/php/cron-grc-policy-reminders.log',
            'default_schedule' => '0 5 * * *',
            'history_key'     => 'grc-policy-reminders',
        ],
        'grc_monitors' => [
            'name'            => 'GRC Continuous Monitors',
            'description'     => 'Runs due continuous monitors, collects evidence, and updates compliance status',
            'script'          => 'grc-continuous-monitors.php',
            'log'             => '/var/log/php/cron-grc-monitors.log',
            'default_schedule' => '*/5 * * * *',
            'history_key'     => 'grc-continuous-monitors',
        ],
        'grc_task_digest' => [
            'name'            => 'GRC Task Digest',
            'description'     => 'Emails each assignee a consolidated digest of their open GRC tasks',
            'script'          => 'grc-task-digest.php',
            'log'             => '/var/log/php/cron-grc-task-digest.log',
            'default_schedule' => '0 6 * * *',
            'history_key'     => 'grc-task-digest',
        ],
        'breach_monitor' => [
            'name'            => 'Breach / Cyber Alert Monitor',
            'description'     => 'Researches credible breaches and vulnerabilities affecting monitored vendors',
            'script'          => 'breach-monitor.php',
            'log'             => '/var/log/php/cron-breach-monitor.log',
            'default_schedule' => '0 7 * * *',
            'timeout'         => 1800,
            'history_key'     => 'breach-monitor',
        ],
        'osint_company' => [
            'name'            => 'OSINT Company Scanner',
            'description'     => 'Scans GitHub for exposed credentials tied to your own organization domains',
            'script'          => 'osint-company.php',
            'log'             => '/var/log/php/cron-osint-company.log',
            'default_schedule' => '0 8 * * *',
            'timeout'         => 600,
            'history_key'     => 'osint-company',
        ],
        'osint_vendors' => [
            'name'            => 'OSINT Vendor Scanner',
            'description'     => 'Weekly GitHub scan for exposed credentials across all monitored vendor domains',
            'script'          => 'osint-vendors.php',
            'log'             => '/var/log/php/cron-osint-vendors.log',
            'default_schedule' => '0 2 * * 0',
            'timeout'         => 7200,
            'history_key'     => 'osint-vendors',
        ],
        'procurement_digest' => [
            'name'            => 'Procurement Update Digest',
            'description'     => 'Emails a weekly digest of vendors in review with their latest procurement update',
            'script'          => 'procurement-digest.php',
            'log'             => '/var/log/php/cron-procurement-digest.log',
            'default_schedule' => '0 7 * * 1',
            'timeout'         => 600,
            'history_key'     => 'procurement-digest',
        ],
        'onboarding_scheduled_actions' => [
            'name'            => 'Vendor Remediation Schedule',
            'description'     => 'Fires due vendor Action Plan items (send assessment, force annual review, contact vendor/stakeholder)',
            'script'          => 'onboarding-scheduled-actions.php',
            'log'             => '/var/log/php/cron-onboarding-scheduled-actions.log',
            'default_schedule' => '0 7 * * *',
            'timeout'         => 600,
            'history_key'     => 'onboarding-scheduled-actions',
        ],
        // Shared Shadow SaaS rehydration. Runs whichever single provider (Grip or
        // Hero) is enabled — they are mutually exclusive. Managed on the Shadow
        // SaaS admin page (admin.php?section=shadow-saas), NOT the Scheduler page,
        // hence 'hidden'. Still a real, crontab-installed job: regenerateCrontab()
        // honours its cron_shadow_saas_rehydrate_enabled / _schedule config keys.
        'shadow_saas_rehydrate' => [
            'name'            => 'Shadow SaaS Rehydration',
            'description'     => 'Re-pulls the enabled Shadow SaaS provider (Grip/Hero) into the mirror tables and Shadow SaaS list',
            'script'          => 'shadow-saas-sync.php',
            'log'             => '/var/log/php/cron-shadow-saas-rehydrate.log',
            'default_schedule' => '0 2 * * *',
            'timeout'         => 3600,
            'history_key'     => 'shadow-saas-rehydrate',
            'hidden'          => true,
        ],
    ];
}
} // end function_exists('cronJobDefinitions')

if (!function_exists('regenerateCrontab')) {
/**
 * Rebuild /etc/cron.d/open-tprm from app_config. Honours cron_<key>_enabled and
 * cron_<key>_schedule (falling back to the job's default_schedule), and the
 * optional per-job `timeout` wrapper. Installs hidden jobs too — visibility is a
 * UI concern, not a scheduling one.
 */
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

        // Long-running jobs are wrapped in `timeout` so a hung run can't pile up.
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

if (!function_exists('isValidCronExpression')) {
/**
 * Validate a 5-field cron expression.
 */
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

if (!function_exists('describeCron')) {
/**
 * Human-readable description of a cron expression.
 */
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
