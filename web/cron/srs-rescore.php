#!/usr/bin/env php
<?php
/**
 * SRS Automatic Vendor Rescoring Cron Job
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The tireless robot that wakes up on a schedule and re-checks vendor security
 * scores via UpGuard's SRS API. Think of it as that one coworker who never
 * forgets to follow up on things -- except it's a PHP script and it doesn't
 * need coffee breaks. Processes vendors in batches because hammering an API
 * all at once is a great way to get rate-limited into oblivion.
 *
 * Tier-based rescoring means critical vendors get checked more often (every 30 days)
 * while the low-risk ones can chill for up to a year. Priorities, people.
 *
 * Usage:
 *   php srs-rescore.php [options]
 *
 * Options:
 *   --batch-size=N   Number of vendors to process per run (default: 10)
 *   --dry-run        Show what would be processed without making changes
 *   --verbose        Show detailed output during execution
 *   --help           Display this help message
 *
 * Cron Setup Examples:
 *   # Run every hour
 *   0 * * * * /usr/bin/php /path/to/cron/srs-rescore.php >> /var/log/tprm-srs.log 2>&1
 *
 *   # Run every 4 hours with batch of 20
 *   0 0,4,8,12,16,20 * * * /usr/bin/php /path/to/cron/srs-rescore.php --batch-size=20 >> /var/log/tprm-srs.log 2>&1
 *
 *   # Run daily at 2 AM
 *   0 2 * * * /usr/bin/php /path/to/cron/srs-rescore.php --batch-size=50 >> /var/log/tprm-srs.log 2>&1
 */

// ---- SAFETY CHECK: CLI ONLY ----
// If someone tries to hit this from a browser, we politely tell them to buzz off.
// Cron jobs and web requests don't mix, like pineapple on pizza (fight me).
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ---- PATH SETUP ----
// Figure out where we are and where the app root is so includes work properly.
define('CRON_ROOT', dirname(__FILE__));
$_appRoot = dirname(CRON_ROOT);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $_appRoot);
    define('INCLUDES_PATH', $_appRoot . '/includes');
    define('CLASSES_PATH', $_appRoot . '/includes/classes');
    define('CONFIG_PATH', $_appRoot . '/config');
}
chdir(APP_ROOT);

// ---- PARSE CLI ARGUMENTS ----
// PHP's getopt() is... functional. It gets the job done even if it's not pretty.
$options = getopt('', ['batch-size:', 'dry-run', 'verbose', 'help']);

// If they asked for help, show it and peace out
if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// ---- CONFIGURATION ----
$cliBatchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : null;
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// ---- BOOTSTRAP THE APP ----
try {
    require_once APP_ROOT . '/includes/init.php';
    require_once APP_ROOT . '/includes/classes/SRSService.php';
    require_once APP_ROOT . '/includes/classes/ShodanService.php';
    require_once APP_ROOT . '/includes/classes/FaviconService.php';
} catch (Exception $e) {
    CronHelper::error("Failed to initialize application: " . $e->getMessage());
    exit(1);
}

// Sanity check the batch size. Less than 1 is useless, more than 100 is ambitious.
// Priority: CLI --batch-size > app_config cron_srs_rescore_batch_size > default 20
$cron = new CronHelper('srs-rescore');

if ($cliBatchSize !== null) {
    $batchSize = $cliBatchSize;
} else {
    $batchSize = (int)(getAppConfig('cron_srs_rescore_batch_size', '20') ?: 20);
}

if ($batchSize < 1 || $batchSize > 100) {
    CronHelper::error("Invalid batch size: $batchSize. Must be between 1 and 100.");
    exit(1);
}

// ---- LOCK FILE ----
// Prevent multiple instances from running simultaneously.
// Without this, two cron runs could score the same vendor twice and
// the API provider would send us a very stern email.
if (!$cron->acquireLock()) {
    CronHelper::warn("Another instance is already running. Exiting.");
    exit(0);
}

try {
    $db = Database::getInstance();
    $srsService = new SRSService();
    $shodanService = new ShodanService();

    // If neither SRS service is configured, there's nothing to do
    $upguardAvailable = $srsService->isAvailable();
    $shodanAvailable = $shodanService->isAvailable();

    if (!$upguardAvailable && !$shodanAvailable) {
        CronHelper::warn("No SRS integrations are configured or enabled. Skipping.");
        $cron->releaseLock();
        exit(0);
    }

    // Log that we're starting so the DB knows what we're up to
    $cron->logExecutionStart($db);

    CronHelper::info("Starting SRS rescoring job");
    CronHelper::info("Services: " . ($upguardAvailable ? 'UpGuard' : '') . ($upguardAvailable && $shodanAvailable ? ' + ' : '') . ($shodanAvailable ? 'Shodan' : ''));
    CronHelper::info("Batch size: $batchSize" . ($dryRun ? " (DRY RUN)" : ""));

    // ---- FIND VENDORS NEEDING A RESCORE ----
    // The SRS service figures out who's overdue based on their tier schedule.
    // Tier 1 = every 30 days, Tier 2 = every 90 days, Tier 3 = every 365 days.
    $vendors = $srsService->getVendorsNeedingRescore($batchSize);
    $vendorCount = count($vendors);

    // If nobody needs rescoring, we log it and head home early. Nice.
    if ($vendorCount === 0) {
        CronHelper::info("No vendors require rescoring at this time.");
        $cron->logExecutionComplete($db, 0, 0, 0, []);
        $cron->releaseLock();
        exit(0);
    }

    CronHelper::info("Found $vendorCount vendor(s) requiring rescore");

    // ---- PROCESS EACH VENDOR ----
    // Track our results like a responsible adult
    $results = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'errors' => []
    ];

    foreach ($vendors as $vendor) {
        $results['processed']++;

        // Build a nice readable string for logging so we know who we're looking at
        $vendorInfo = sprintf(
            "[%d/%d] %s (%s) - Tier %s",
            $results['processed'],
            $vendorCount,
            $vendor['vendor_name'] ?? 'Unknown',
            $vendor['vendor_domain'],
            $vendor['vendor_tier']
        );

        if ($verbose) {
            CronHelper::info("Processing: $vendorInfo");
        }

        // Dry run mode: just pretend we did it. Good for testing without
        // burning through API credits.
        if ($dryRun) {
            CronHelper::info("  [DRY RUN] Would rescore: {$vendor['vendor_domain']}");
            $results['success']++;
            continue;
        }

        // Score with UpGuard if available
        $vendorSuccess = false;
        if ($upguardAvailable) {
            try {
                $score = $srsService->scoreVendor(
                    $vendor['id'],
                    $vendor['vendor_domain'],
                    $vendor['vendor_name']
                );

                if ($score) {
                    $vendorSuccess = true;
                    if ($verbose) {
                        CronHelper::info("  UpGuard: {$score['score']} (Grade: {$score['grade']})");
                        CronHelper::info("  Risks - Critical: {$score['critical_risks']}, High: {$score['high_risks']}, Medium: {$score['medium_risks']}");
                    }
                } else {
                    $error = "UpGuard failed for {$vendor['vendor_domain']}: " . ($srsService->getLastError() ?? 'Unknown error');
                    $results['errors'][] = $error;
                    CronHelper::error("  $error");
                }
            } catch (Exception $e) {
                $error = "UpGuard error for {$vendor['vendor_domain']}: " . $e->getMessage();
                $results['errors'][] = $error;
                CronHelper::error("  $error");
            }
        }

        // Score with Shodan if available
        if ($shodanAvailable) {
            try {
                $shodanScore = $shodanService->scoreVendor(
                    $vendor['id'],
                    $vendor['vendor_domain']
                );

                if ($shodanScore) {
                    $vendorSuccess = true;
                    if ($verbose) {
                        CronHelper::info("  Shodan: {$shodanScore['score']} (Grade: {$shodanScore['grade']})");
                        CronHelper::info("  Ports: {$shodanScore['open_ports_count']}, CVEs: {$shodanScore['vuln_count']}");
                    }
                } else {
                    $error = "Shodan failed for {$vendor['vendor_domain']}: " . ($shodanService->getLastError() ?? 'Unknown error');
                    $results['errors'][] = $error;
                    CronHelper::error("  $error");
                }
            } catch (Exception $e) {
                $error = "Shodan error for {$vendor['vendor_domain']}: " . $e->getMessage();
                $results['errors'][] = $error;
                CronHelper::error("  $error");
            }
        }

        // Refresh favicon on successful rescore
        if ($vendorSuccess && !$dryRun) {
            try {
                $faviconService = $faviconService ?? new FaviconService();
                $favicon = $faviconService->fetchFavicon($vendor['vendor_domain']);
                if ($favicon) {
                    $db->query(
                        'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                        [$favicon['data'], $favicon['mime'], $vendor['id']]
                    );
                }
            } catch (Exception $e) {
                // Non-critical -- don't count as a scoring failure
            }
        }

        // Ensure last_srs_score_at is set after any successful scoring
        // (ShodanService doesn't update this field, causing perpetual "Needs Rescore")
        if ($vendorSuccess && !$dryRun) {
            $db->query(
                'UPDATE vendor_onboarding_requests SET last_srs_score_at = NOW() WHERE id = ?',
                [$vendor['id']]
            );
        }

        if ($vendorSuccess) {
            $results['success']++;
        } else {
            $results['failed']++;
        }

        // ---- RATE LIMITING ----
        // Be a good API citizen and wait half a second between calls.
        if (!$dryRun && $results['processed'] < $vendorCount) {
            usleep(500000);
        }
    }

    // ---- SUMMARY ----
    CronHelper::info("-------------------------------------------");
    CronHelper::info("Rescoring Complete");
    CronHelper::info("  Processed: {$results['processed']}");
    CronHelper::info("  Success:   {$results['success']}");
    CronHelper::info("  Failed:    {$results['failed']}");

    if (!empty($results['errors'])) {
        CronHelper::info("  Errors:");
        foreach ($results['errors'] as $error) {
            CronHelper::info("    - $error");
        }
    }

    $cron->logExecutionComplete(
        $db,
        $results['processed'],
        $results['success'],
        $results['failed'],
        $results['errors']
    );

    $exitCode = ($results['failed'] > 0) ? 1 : 0;

} catch (Exception $e) {
    CronHelper::error("Fatal error: " . $e->getMessage());
    if (isset($db)) {
        $cron->logExecutionFailed($db, $e->getMessage());
    }
    $exitCode = 1;
}

$cron->releaseLock();
exit($exitCode);

// =============================================================================
// HELP TEXT
// =============================================================================

/**
 * Display help message
 * Prints the user manual to stdout. Someone actually typed --help, so let's
 * reward that rare behavior with useful information.
 */
function showHelp(): void
{
    echo <<<HELP
SRS Automatic Vendor Rescoring

Automatically rescores vendors based on their assigned tier schedules.

USAGE:
    php srs-rescore.php [OPTIONS]

OPTIONS:
    --batch-size=N   Number of vendors to process per run (default: 10, max: 100)
    --dry-run        Show what would be processed without making API calls
    --verbose        Display detailed progress information
    --help           Display this help message

TIER SCHEDULES (configurable in Admin > SRS Settings):
    Tier 1 (Critical):   Rescored every 30 days
    Tier 2 (Standard):   Rescored every 90 days
    Tier 3 (Low-Risk):   Rescored every 365 days

CRON EXAMPLES:
    # Run every hour (recommended for most setups)
    0 * * * * /usr/bin/php /path/to/cron/srs-rescore.php >> /var/log/tprm-srs.log 2>&1

    # Run every 4 hours with larger batch
    0 */4 * * * /usr/bin/php /path/to/cron/srs-rescore.php --batch-size=20 >> /var/log/tprm-srs.log 2>&1

    # Run daily at 2 AM for less critical environments
    0 2 * * * /usr/bin/php /path/to/cron/srs-rescore.php --batch-size=50 >> /var/log/tprm-srs.log 2>&1

TESTING:
    # Test without making changes
    php srs-rescore.php --dry-run --verbose

    # Run with verbose output
    php srs-rescore.php --verbose

EXIT CODES:
    0    Success (all vendors scored successfully or nothing to process)
    1    Partial failure (some vendors failed) or fatal error

HELP;
}
