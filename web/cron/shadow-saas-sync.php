#!/usr/bin/env php
<?php
/**
 * Shadow SaaS — shared rehydration cron driver.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Runs whichever SINGLE Shadow SaaS provider is enabled and refreshes its mirror
 * tables + the shared shadow_saas list. Grip and Hero are MUTUALLY EXCLUSIVE
 * (only one enabled at a time), so this driver picks the first enabled provider
 * and syncs it. This is the provider-agnostic replacement for the old, never-
 * installed cron/grip-sync.php hint — it is registered as the hidden
 * `shadow_saas_rehydrate` job (see includes/cron-jobs.php) and is what both the
 * scheduled run and the Shadow SaaS "Run Now" button invoke.
 *
 * Provider contract: each provider service exposes
 *   - isEnabled(): bool
 *   - syncAll(string $trigger, ?int $userId): array{success:bool, error?:string,
 *       apps_fetched?:int, users_fetched?:int, alerts_fetched?:int, shadow_saas_upserted?:int}
 * GripService already satisfies this; HeroService will too once wired.
 *
 * Usage: php shadow-saas-sync.php [--manual] [--user-id=N] [--verbose]
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

$options = getopt('', ['manual', 'verbose', 'user-id::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php shadow-saas-sync.php [--manual] [--user-id=N] [--verbose]\n";
    exit(0);
}
$verbose = isset($options['verbose']);
$trigger = isset($options['manual']) ? 'manual' : 'cron';
$userId  = isset($options['user-id']) ? (int)$options['user-id'] : null;

// Single lock for the shared job — providers are mutually exclusive, so one lock
// is correct and also prevents a scheduled run colliding with a "Run Now".
$lockFile = sys_get_temp_dir() . '/fairtprm_shadow_saas_sync.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[shadow-saas-sync] Another Shadow SaaS sync is already running. Exiting.\n");
    exit(0);
}
register_shutdown_function(function () use ($lockFp, $lockFile) {
    @flock($lockFp, LOCK_UN); @fclose($lockFp); @unlink($lockFile);
});

require_once APP_ROOT . '/includes/init.php';
require_once CLASSES_PATH . '/GripService.php';
require_once CLASSES_PATH . '/HeroService.php';

$start = microtime(true);

// Provider registry — ordered. Grip and Hero are mutually exclusive, so we run
// the FIRST enabled provider. Add HeroService here (same contract) to wire Hero.
$providers = [];
if (class_exists('GripService')) { $providers['Grip'] = new GripService(); }
if (class_exists('HeroService')) { $providers['Hero'] = new HeroService(); }

$active = null;
$activeName = null;
foreach ($providers as $name => $svc) {
    try {
        if ($svc->isEnabled()) { $active = $svc; $activeName = $name; break; }
    } catch (Throwable $e) {
        fwrite(STDERR, "[shadow-saas-sync] {$name} isEnabled() check failed: " . $e->getMessage() . "\n");
    }
}

if ($active === null) {
    fwrite(STDOUT, "[shadow-saas-sync] No Shadow SaaS provider is enabled; nothing to do.\n");
    exit(0);
}

if ($verbose) fwrite(STDOUT, "[shadow-saas-sync] starting (provider={$activeName}, trigger={$trigger})\n");

try {
    $res = $active->syncAll($trigger, $userId);
    $elapsed = round(microtime(true) - $start, 2);
    if (!empty($res['success'])) {
        fwrite(STDOUT, sprintf(
            "[shadow-saas-sync] %s OK in %ss — apps=%d users=%d alerts=%d shadow_saas=%d breaches=%d\n",
            $activeName, $elapsed, $res['apps_fetched'] ?? 0, $res['users_fetched'] ?? 0,
            $res['alerts_fetched'] ?? 0, $res['shadow_saas_upserted'] ?? 0, $res['breaches_created'] ?? 0
        ));
        exit(0);
    }
    fwrite(STDERR, "[shadow-saas-sync] {$activeName} FAILED: " . ($res['error'] ?? 'unknown') . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "[shadow-saas-sync] {$activeName} EXCEPTION: " . $e->getMessage() . "\n");
    exit(1);
}
