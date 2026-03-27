#!/usr/bin/env php
<?php
/**
 * On-Demand Rescore Queue Processor
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Processes manual vendor rescore requests that were queued via the vendor
 * details page when "Use Cron for Rescoring" is enabled. Designed to run
 * every minute via cron. Uses lock files to prevent overlapping executions
 * and atomic DB claims to prevent race conditions.
 *
 * Flow:
 *   1. User clicks "Score" on vendor-srs-details.php
 *   2. POST handler sets rescore_status = 'rescoring' (or 'rescoring_upguard'/'rescoring_shodan')
 *   3. This cron picks it up, atomically claims it, scores, and clears the status
 *   4. Polling JS on the vendor page detects completion and refreshes
 *
 * Usage:
 *   php rescore-queue.php [options]
 *
 * Options:
 *   --verbose   Show detailed output during execution
 *   --help      Display this help message
 *
 * Cron Setup:
 *   * * * * * /usr/bin/php /path/to/cron/rescore-queue.php >> /var/log/tprm-rescore-queue.log 2>&1
 */

// ---- CLI GUARD ----
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ---- PATH SETUP ----
// Define APP_ROOT and related path constants used by init.php's autoloader.
// These must be defined BEFORE requiring init.php because it skips them
// if APP_ROOT is already set.
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
$options = getopt('', ['verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

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

// ---- LOCK FILE ----
$cron = new CronHelper('rescore-queue');

if (!$cron->acquireLock()) {
    if ($verbose) {
        CronHelper::warn("Another instance is already running. Exiting.");
    }
    exit(0);
}

$exitCode = 0;

try {
    $db = Database::getInstance();

    // ---- FIND PENDING RESCORES ----
    // Look for vendors with rescore_status set (queued by the web UI).
    // Only pick up 'rescoring', 'rescoring_upguard', 'rescoring_shodan' statuses.
    // Skip anything already being processed (safety net).
    // Fetch pending rescores from both vendor_onboarding_requests and shadow_saas
    $shadowSaasQuery = '';
    try {
        $db->fetchOne("SELECT 1 FROM shadow_saas LIMIT 1");
        $shadowSaasQuery = "
         UNION ALL
         SELECT id, vendor_name, vendor_domain, rescore_status, rescore_started_at, 'shadow_saas' AS source_table
         FROM shadow_saas
         WHERE rescore_status IN ('rescoring', 'rescoring_upguard', 'rescoring_shodan')";
    } catch (Exception $e) {
        // shadow_saas table doesn't exist yet
    }

    $pending = $db->fetchAll(
        "SELECT id, vendor_name, vendor_domain, rescore_status, rescore_started_at, 'vendor_onboarding_requests' AS source_table
         FROM vendor_onboarding_requests
         WHERE rescore_status IN ('rescoring', 'rescoring_upguard', 'rescoring_shodan')
           AND status != 'inactive'
         {$shadowSaasQuery}
         ORDER BY rescore_started_at ASC
         LIMIT 5"
    );

    if (empty($pending)) {
        if ($verbose) {
            CronHelper::info("No pending rescores found.");
        }
        $cron->releaseLock();
        exit(0);
    }

    $cron->logExecutionStart($db);

    CronHelper::info("Found " . count($pending) . " pending rescore(s)");

    $srsService = new SRSService();
    $shodanService = new ShodanService();
    $faviconService = new FaviconService();

    $upguardAvailable = $srsService->isAvailable();
    $shodanAvailable = $shodanService->isAvailable();

    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    $errors = [];

    foreach ($pending as $vendor) {
        $vendorId = (int) $vendor['id'];
        $domain = $vendor['vendor_domain'];
        $rescoreStatus = $vendor['rescore_status'];
        $sourceTable = $vendor['source_table'] ?? 'vendor_onboarding_requests';

        // Safety: skip if rescore_started_at is older than 10 minutes (probably orphaned)
        if (!empty($vendor['rescore_started_at'])) {
            $startedAt = strtotime($vendor['rescore_started_at']);
            if ($startedAt && (time() - $startedAt) > 600) {
                CronHelper::warn("Vendor #{$vendorId} ({$domain}): rescore request is over 10 minutes old, clearing stale status");
                $db->query(
                    "UPDATE {$sourceTable} SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = ? WHERE id = ?",
                    ['Timed out (queue processor)', $vendorId]
                );
                continue;
            }
        }

        if (empty($domain)) {
            CronHelper::warn("Vendor #{$vendorId}: no domain, skipping");
            $db->query(
                "UPDATE {$sourceTable} SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = ? WHERE id = ?",
                ['No domain configured', $vendorId]
            );
            continue;
        }

        // Atomic claim: set status to processing to prevent duplicate work
        $processingStatus = str_replace('rescoring', 'processing', $rescoreStatus);
        $claimed = $db->query(
            "UPDATE {$sourceTable} SET rescore_status = ? WHERE id = ? AND rescore_status = ?",
            [$processingStatus, $vendorId, $rescoreStatus]
        );

        // If another process already claimed this one, skip
        $checkRow = $db->fetchOne(
            "SELECT rescore_status FROM {$sourceTable} WHERE id = ?",
            [$vendorId]
        );
        if (!$checkRow || $checkRow['rescore_status'] !== $processingStatus) {
            if ($verbose) {
                CronHelper::info("Vendor #{$vendorId} ({$domain}): already claimed by another process, skipping");
            }
            continue;
        }

        $processed++;
        $vendorInfo = "#{$vendorId} " . ($vendor['vendor_name'] ?? 'Unknown') . " ({$domain})";

        CronHelper::info("Processing: $vendorInfo (type: $rescoreStatus)");

        // Determine what to score
        $scoreType = 'all';
        if ($rescoreStatus === 'rescoring_upguard') $scoreType = 'upguard';
        elseif ($rescoreStatus === 'rescoring_shodan') $scoreType = 'shodan';

        $scoreUpguard = ($scoreType === 'all' || $scoreType === 'upguard');
        $scoreShodan  = ($scoreType === 'all' || $scoreType === 'shodan');

        $messages = [];
        $vendorErrors = [];

        // Score with UpGuard
        if ($scoreUpguard && $upguardAvailable) {
            try {
                $result = $srsService->scoreVendor($vendorId, $domain, $vendor['vendor_name'], $sourceTable);
                if ($result) {
                    $messages[] = "UpGuard: {$result['score']} ({$result['grade']})";
                    if ($verbose) {
                        CronHelper::info("  UpGuard: {$result['score']} (Grade: {$result['grade']})");
                    }
                } else {
                    $vendorErrors[] = 'UpGuard: ' . ($srsService->getLastError() ?? 'Unknown error');
                }
            } catch (Exception $e) {
                $vendorErrors[] = 'UpGuard: ' . $e->getMessage();
                CronHelper::error("  UpGuard error for vendor $vendorId: " . $e->getMessage());
            }
        }

        // Score with Shodan
        if ($scoreShodan && $shodanAvailable) {
            try {
                $shodanResult = $shodanService->scoreVendor($vendorId, $domain, $sourceTable);
                if ($shodanResult) {
                    $messages[] = "Shodan: {$shodanResult['score']} ({$shodanResult['grade']})";
                    if ($verbose) {
                        CronHelper::info("  Shodan: {$shodanResult['score']} (Grade: {$shodanResult['grade']})");
                    }
                } else {
                    $vendorErrors[] = 'Shodan: ' . ($shodanService->getLastError() ?? 'Unknown error');
                }
            } catch (Exception $e) {
                $vendorErrors[] = 'Shodan: ' . $e->getMessage();
                CronHelper::error("  Shodan error for vendor $vendorId: " . $e->getMessage());
            }
        }

        // Refresh favicon (only for vendor_onboarding_requests)
        if ($sourceTable === 'vendor_onboarding_requests') {
            try {
                $favicon = $faviconService->fetchFavicon($domain);
                if ($favicon) {
                    $db->query(
                        'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                        [$favicon['data'], $favicon['mime'], $vendorId]
                    );
                }
            } catch (Exception $e) {
                // Non-critical
            }
        }

        // Build result message
        $resultMsg = '';
        if (!empty($messages)) {
            $resultMsg = implode(' | ', $messages);
        }
        if (!empty($vendorErrors)) {
            $resultMsg .= (!empty($resultMsg) ? ' | ' : '') . 'ERRORS: ' . implode(' | ', $vendorErrors);
            $errors[] = "Vendor {$vendorInfo}: " . implode(', ', $vendorErrors);
        }

        // Clear rescore status, store result, and ensure last_srs_score_at is set
        // (ShodanService doesn't update last_srs_score_at, causing perpetual "Needs Rescore")
        if (!empty($messages)) {
            $db->query(
                "UPDATE {$sourceTable} SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = ?, last_srs_score_at = NOW() WHERE id = ?",
                [$resultMsg, $vendorId]
            );
        } else {
            $db->query(
                "UPDATE {$sourceTable} SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = ? WHERE id = ?",
                [$resultMsg, $vendorId]
            );
        }

        if (!empty($messages)) {
            $succeeded++;
            CronHelper::info("  Complete: $resultMsg");
        } else {
            $failed++;
            CronHelper::warn("  Failed: $resultMsg");
        }

        // Rate limiting between vendors
        if ($processed < count($pending)) {
            usleep(500000); // 500ms
        }
    }

    // Summary
    if ($processed > 0) {
        CronHelper::info("Queue processing complete: $processed processed, $succeeded succeeded, $failed failed");
    }

    $cron->logExecutionComplete($db, $processed, $succeeded, $failed, $errors);

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

function showHelp(): void
{
    echo <<<HELP
On-Demand Rescore Queue Processor

Processes manual vendor rescore requests queued from the vendor details page.
Designed to run every minute via cron when "Use Cron for Rescoring" is enabled.

USAGE:
    php rescore-queue.php [OPTIONS]

OPTIONS:
    --verbose   Show detailed progress information
    --help      Display this help message

CRON SETUP:
    # Run every minute (recommended for on-demand responsiveness)
    * * * * * /usr/bin/php /path/to/cron/rescore-queue.php >> /var/log/tprm-rescore-queue.log 2>&1

HOW IT WORKS:
    1. User clicks "Score" on vendor details page
    2. Web app sets rescore_status on the vendor record
    3. This script picks up the pending job and processes it
    4. Browser polling detects completion and auto-refreshes

SAFETY:
    - Lock file prevents overlapping executions
    - Atomic DB claims prevent duplicate processing
    - Stale requests (>10 min) are automatically cleared

HELP;
}
