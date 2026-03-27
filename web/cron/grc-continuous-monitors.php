<?php
/**
 * GRC Continuous Monitor Runner - Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Runs all due continuous monitors, collects evidence automatically, and
 * updates compliance status across all mapped frameworks. This is the
 * "Drata-like" continuous monitoring engine that keeps evidence fresh.
 *
 * Schedule: Every 5 minutes
 * Usage: php /var/www/html/cron/grc-continuous-monitors.php [--verbose] [--help]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Parse arguments
$verbose = in_array('--verbose', $argv ?? []);
$help = in_array('--help', $argv ?? []);

if ($help) {
    echo "GRC Continuous Monitor Runner\n";
    echo "Usage: php grc-continuous-monitors.php [--verbose] [--help]\n";
    echo "  --verbose  Show detailed output\n";
    echo "  --help     Show this help message\n";
    exit(0);
}

// Lock file to prevent overlapping runs
$lockFile = '/tmp/grc-monitors.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 300) {
        if ($verbose) echo "Lock file exists ({$lockAge}s old), skipping.\n";
        exit(0);
    }
    // Stale lock, remove it
    unlink($lockFile);
}
file_put_contents($lockFile, getmypid());

// Bootstrap the application
define('APP_ROOT', dirname(__DIR__));
define('INCLUDES_PATH', APP_ROOT . '/includes');
define('CLASSES_PATH', APP_ROOT . '/includes/classes');
define('CONFIG_PATH', APP_ROOT . '/config');
require_once INCLUDES_PATH . '/init.php';

try {
    $cm = ContinuousMonitor::getInstance();
    $dueMonitors = $cm->getDueMonitors();

    if ($verbose) {
        echo date('Y-m-d H:i:s') . " - Found " . count($dueMonitors) . " due monitor(s)\n";
    }

    $results = $cm->runDueMonitors();

    foreach ($results as $monitorId => $result) {
        if ($verbose) {
            echo "  Monitor #{$monitorId}: {$result['result']} - {$result['summary']}\n";
        }

        // Log to cron execution history
        try {
            $db = Database::getInstance();
            $db->insert('cron_execution_history', [
                'job_name' => 'grc_monitor_' . $monitorId,
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s'),
                'status' => ($result['result'] === 'error') ? 'failed' : 'success',
                'details' => json_encode(['result' => $result['result'], 'summary' => $result['summary']]),
            ]);
        } catch (Exception $e) {
            error_log('Failed to log cron execution: ' . $e->getMessage());
        }
    }

    // Take daily snapshots for all active frameworks
    $grc = GRCService::getInstance();
    foreach ($grc->getFrameworks() as $fw) {
        $grc->takeSnapshot((int)$fw['id']);
    }

    if ($verbose) {
        echo date('Y-m-d H:i:s') . " - Complete\n";
    }
} catch (Exception $e) {
    error_log('GRC monitor cron error: ' . $e->getMessage());
    if ($verbose) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} finally {
    @unlink($lockFile);
}
