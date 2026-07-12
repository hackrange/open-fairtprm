<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Version Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Dual-mode version management:
 *
 * Docker mode (detected via /.dockerenv):
 *   - Queries a Docker registry v2 API for available fairtprm image tags
 *   - Shows only versions newer than the currently installed version
 *   - Never allows downgrade
 *   - Settings, database, branding, and all persistent data are safe across
 *     upgrades because they live on Docker volumes (/persistent, mariadb_data)
 *
 * Non-Docker mode (bare-metal / VM):
 *   - Pulls application updates from fairtprm.com as tar.gz release archives
 *   - CSV manifest at https://fairtprm.com/versions/versions.csv
 *   - Full upgrade process with backup, extract, migrate
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

$webRoot = dirname(dirname(__DIR__));
$isDocker = is_dir('/persistent');

// Non-Docker settings
$versionsUrl = 'https://fairtprm.com/versions/versions.csv';
$downloadBaseUrl = 'https://fairtprm.com/versions/';

// Docker settings
$defaultRegistryHost = 'dockerregistry.fairtprm.com';
$dockerImageName = 'fairtprm';

// Guard against double-declaration: admin.php includes section files twice
// (once for POST handling under ob_start, once for rendering).
if (!function_exists('fetchVersionsCsv')):

/**
 * Validate that a URL points to an allowed domain (SSRF prevention).
 */
function validateVersionUrl(string $url): bool {
    $allowed = ['fairtprm.com', 'www.fairtprm.com'];
    $host = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $host !== null && in_array($host, $allowed, true) && in_array($scheme, ['https'], true);
}

/**
 * Fetch and parse the versions CSV from the remote server.
 *
 * CSV format: version,filename,date
 * Example:    2.5.3,opensrs_version-2.5.3.tar.gz,2025-11-11
 *
 * @param string $url  URL to versions.csv
 * @return array|false  Array of version entries or false on failure
 */
function fetchVersionsCsv($url) {
    if (!validateVersionUrl($url)) { error_log('Version check blocked: invalid URL'); return false; }
    $content = false;

    // Try curl first
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($content === false || $httpCode !== 200) {
            $content = false;
        }
    }

    // Fallback to file_get_contents
    if ($content === false) {
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'method' => 'GET'],
            'ssl'  => ['verify_peer' => true],
        ]);
        $content = @file_get_contents($url, false, $context);
    }

    if ($content === false) {
        return false;
    }

    $versions = [];
    $lines = array_filter(array_map('trim', explode("\n", $content)));
    foreach ($lines as $line) {
        if (empty($line)) continue;
        $parts = str_getcsv($line);
        if (count($parts) >= 3) {
            $versions[] = [
                'version'  => trim($parts[0]),
                'filename' => trim($parts[1]),
                'date'     => trim($parts[2]),
            ];
        }
    }
    return $versions;
}

/**
 * Download a file from a URL to a local path.
 *
 * @param string $url       Remote URL to download
 * @param string $destPath  Local file path to save to
 * @return bool  True on success, false on failure
 */
function downloadArchive($url, $destPath) {
    if (!validateVersionUrl($url)) { error_log('Download blocked: invalid URL'); return false; }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($destPath, 'wb');
        if (!$fp) return false;
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$result || $httpCode !== 200) {
            @unlink($destPath);
            return false;
        }
        return true;
    }

    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => ['timeout' => 300],
        'ssl'  => ['verify_peer' => true],
    ]);
    $content = @file_get_contents($url, false, $context);
    if ($content === false) return false;
    return file_put_contents($destPath, $content) !== false;
}

/**
 * Run a shell command via proc_open (no shell interpretation).
 * Command is passed as an array to proc_open, which calls execve()
 * directly -- immune to injection.
 *
 * @param array  $cmd  Command and arguments as array
 * @param string $cwd  Working directory
 * @return array [output_lines, exit_code]
 */
function runShellCommand(array $cmd, $cwd) {
    if (!function_exists('proc_open') || !is_callable('proc_open')) {
        return [['proc_open is not available'], 1];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return [['Failed to start process'], 1];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $combined = trim($stdout . "\n" . $stderr);
    $output = $combined !== '' ? explode("\n", $combined) : [];
    return [$output, $exitCode];
}

/**
 * Validate a Docker registry hostname (no scheme, no path, just host with optional port).
 */
function validateRegistryHost(string $host): bool {
    if (strlen($host) > 253 || strlen($host) < 1) return false;
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?(:\d{1,5})?$/', $host);
}

/**
 * Fetch available tags from a Docker registry v2 API.
 *
 * @param string $registryHost  Registry hostname (e.g. dockerregistry.fairtprm.com)
 * @param string $imageName     Image name (e.g. fairtprm)
 * @param string &$errorDetail  Populated with a human-readable error on failure
 * @return array|false  Array of tag strings or false on failure
 */
function fetchDockerTags(string $registryHost, string $imageName, string &$errorDetail = '', ?string $username = null, ?string $password = null) {
    if (!function_exists('curl_init')) {
        $errorDetail = 'PHP curl extension is not available.';
        return false;
    }

    $url = 'https://' . $registryHost . '/v2/' . $imageName . '/tags/list';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,  // SECURITY: verify registry TLS cert (prevents MITM of image/SQL artifacts)
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($username !== null && $username !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . ($password ?? ''));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode === 0) {
        $errorDetail = 'Could not connect to ' . $registryHost . '.';
        if ($curlErr) $errorDetail .= ' (' . $curlErr . ')';
        return false;
    }

    if ($httpCode === 401 || $httpCode === 403) {
        if ($username !== null && $username !== '') {
            $errorDetail = 'Authentication failed for ' . $registryHost . '. Check your username and password.';
        } else {
            $errorDetail = 'Authentication required by ' . $registryHost . '. Provide a username and password below.';
        }
        return false;
    }

    if ($httpCode !== 200) {
        $errorDetail = 'Registry returned HTTP ' . $httpCode . ' from ' . $registryHost . '.';
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['tags']) || !is_array($data['tags'])) {
        $errorDetail = 'Unexpected response format from ' . $registryHost . '.';
        return false;
    }

    return $data['tags'];
}

/**
 * Parse a Docker tag into a clean version string.
 * Only accepts strict semver: vX.Y.Z or X.Y.Z
 *
 * @return string|null  Cleaned version (e.g. "2.5.6") or null if not valid semver
 */
function parseDockerTagVersion(string $tag): ?string {
    if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Filter Docker tags to only those newer than the current version.
 * Returns sorted array (newest first). Never includes current or older versions.
 */
function getNewerDockerVersions(array $tags, string $currentVersion): array {
    $currentClean = ltrim($currentVersion, 'v');
    $newer = [];

    foreach ($tags as $tag) {
        $ver = parseDockerTagVersion($tag);
        if ($ver !== null && version_compare($ver, $currentClean, '>')) {
            $newer[] = [
                'tag'     => $tag,
                'version' => $ver,
            ];
        }
    }

    usort($newer, function ($a, $b) {
        return version_compare($b['version'], $a['version']);
    });

    return $newer;
}

/**
 * Encrypt a string for session storage using AES-256-GCM.
 * Key is derived from the session ID so ciphertext is useless outside this session.
 */
function encryptForSession(string $plaintext): string {
    $key = hash('sha256', session_id() . __FILE__, true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptFromSession(string $encoded): string {
    $key = hash('sha256', session_id() . __FILE__, true);
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ($plaintext !== false) ? $plaintext : '';
}


/**
 * Fetch a single image manifest (or index/list) from a Docker registry v2 API
 * by tag or digest. Returns the decoded JSON, or false on failure.
 */
function fetchDockerManifestRaw(string $registryHost, string $imageName, string $ref, ?string $username = null, ?string $password = null) {
    if (!function_exists('curl_init')) return false;

    $url = 'https://' . $registryHost . '/v2/' . $imageName . '/manifests/' . $ref;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,  // SECURITY: verify registry TLS cert (prevents MITM of image/SQL artifacts)
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            // Accept plain image manifests AND multi-arch indexes / manifest lists,
            // so BuildKit-pushed images (which publish an attestation index by
            // default) resolve correctly instead of 404'ing.
            'Accept: application/vnd.docker.distribution.manifest.v2+json, '
                  . 'application/vnd.oci.image.manifest.v1+json, '
                  . 'application/vnd.docker.distribution.manifest.list.v2+json, '
                  . 'application/vnd.oci.image.index.v1+json',
        ],
    ]);
    if ($username !== null && $username !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . ($password ?? ''));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) return false;
    $data = json_decode($response, true);
    return is_array($data) ? $data : false;
}

/**
 * Fetch an image manifest from a Docker registry v2 API.
 *
 * Handles both plain image manifests (with a `layers` array) and multi-arch
 * indexes / manifest lists (with a `manifests` array). For an index, the
 * linux/amd64 platform manifest is resolved by digest; attestation/unknown
 * sub-manifests (BuildKit provenance/SBOM) are skipped.
 *
 * @return array|false  Decoded image manifest (with `layers`) or false on failure
 */
function fetchDockerManifest(string $registryHost, string $imageName, string $tag, ?string $username = null, ?string $password = null) {
    $data = fetchDockerManifestRaw($registryHost, $imageName, $tag, $username, $password);
    if ($data === false) return false;

    // If we got an index / manifest list, resolve the concrete image manifest.
    if (empty($data['layers']) && !empty($data['manifests']) && is_array($data['manifests'])) {
        $chosen = null;
        foreach ($data['manifests'] as $m) {
            if (empty($m['digest'])) continue;
            $plat = $m['platform'] ?? [];
            $os   = $plat['os'] ?? '';
            $arch = $plat['architecture'] ?? '';
            // Skip BuildKit attestation manifests (platform "unknown/unknown").
            if ($os === 'unknown' || $arch === 'unknown') continue;
            // Prefer linux/amd64; otherwise fall back to the first real platform.
            if ($os === 'linux' && $arch === 'amd64') { $chosen = $m; break; }
            if ($chosen === null) $chosen = $m;
        }
        if ($chosen === null) return false;
        $data = fetchDockerManifestRaw($registryHost, $imageName, $chosen['digest'], $username, $password);
        if ($data === false) return false;
    }

    if (!is_array($data) || empty($data['layers'])) return false;
    return $data;
}

/**
 * Download a blob (image layer) from a Docker registry v2 API.
 *
 * @return bool  True on success
 */
function downloadDockerBlob(string $registryHost, string $imageName, string $digest, string $destPath, ?string $username = null, ?string $password = null): bool {
    if (!function_exists('curl_init')) return false;

    $url = 'https://' . $registryHost . '/v2/' . $imageName . '/blobs/' . $digest;
    $fp = fopen($destPath, 'wb');
    if (!$fp) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_SSL_VERIFYPEER => true,  // SECURITY: verify registry TLS cert (prevents MITM of image/SQL artifacts)
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
    ]);
    if ($username !== null && $username !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . ($password ?? ''));
    }
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$result || $httpCode !== 200) {
        @unlink($destPath);
        return false;
    }
    return true;
}

/**
 * Post-upgrade refresh: flush the PHP opcode cache and gracefully reload Apache
 * so the freshly-extracted code is actually served. Upgrade layers preserve the
 * original file mtimes, so OPcache can otherwise keep serving the previous
 * version's bytecode (missing pages / stale menus). This runs in the Apache
 * SAPI, so opcache_reset() clears the web server's cache.
 */
function postUpgradeRefresh(array &$upgradeLog): void {
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $upgradeLog[] = ['step' => 'Flush OPcache', 'status' => 'ok', 'detail' => 'PHP opcode cache reset'];
    }
    // Graceful reload via the same sudo mechanism LockdownService uses. Graceful
    // means the current request finishes first, so this upgrade response still
    // completes before workers cycle.
    if (function_exists('proc_open') && is_callable('proc_open')) {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('sudo /usr/sbin/apachectl graceful', $desc, $pipes);
        if (is_resource($proc)) {
            foreach ([1, 2] as $i) {
                if (isset($pipes[$i]) && is_resource($pipes[$i])) { stream_get_contents($pipes[$i]); fclose($pipes[$i]); }
            }
            $rc = proc_close($proc);
            $upgradeLog[] = ['step' => 'Reload Apache', 'status' => $rc === 0 ? 'ok' : 'warn', 'detail' => $rc === 0 ? 'Graceful reload issued' : 'apachectl returned ' . $rc];
        } else {
            $upgradeLog[] = ['step' => 'Reload Apache', 'status' => 'warn', 'detail' => 'Could not invoke apachectl graceful'];
        }
    }
}

endif; // function guard

// ============================================================================
// Current version (shared by both modes)
// ============================================================================
$currentVersion = getAppVersion();

// ============================================================================
// DOCKER MODE
// ============================================================================
if ($isDocker):

$registryHost = $defaultRegistryHost;
$registryUser = '';
$registryPass = '';

// Pick up values from POST (form submit) or fall back to session
if (isset($_POST['docker_registry_host']) && trim($_POST['docker_registry_host']) !== '') {
    $registryHost = trim($_POST['docker_registry_host']);
    $registryUser = trim($_POST['docker_registry_user'] ?? '');
    $registryPass = $_POST['docker_registry_pass'] ?? '';

    // Store in session — credentials are encrypted at rest
    $_SESSION['docker_registry_host'] = $registryHost;
    $_SESSION['docker_registry_user'] = $registryUser !== '' ? encryptForSession($registryUser) : '';
    $_SESSION['docker_registry_pass'] = $registryPass !== '' ? encryptForSession($registryPass) : '';
} elseif (isset($_SESSION['docker_registry_host'])) {
    $registryHost = $_SESSION['docker_registry_host'];
    $registryUser = !empty($_SESSION['docker_registry_user']) ? decryptFromSession($_SESSION['docker_registry_user']) : '';
    $registryPass = !empty($_SESSION['docker_registry_pass']) ? decryptFromSession($_SESSION['docker_registry_pass']) : '';
}

$dockerTags     = [];
$newerVersions  = [];
$registryError  = false;
$registryErrMsg = '';

if (validateRegistryHost($registryHost)) {
    $fetchError = '';
    $dockerTags = fetchDockerTags(
        $registryHost, $dockerImageName, $fetchError,
        $registryUser !== '' ? $registryUser : null,
        $registryPass !== '' ? $registryPass : null
    );
    if ($dockerTags === false) {
        $registryError  = true;
        $registryErrMsg = $fetchError;
        $dockerTags     = [];
    } else {
        $newerVersions = getNewerDockerVersions($dockerTags, $currentVersion);
    }
} else {
    $registryError  = true;
    $registryErrMsg = t('admin_version.err_invalid_registry_host');
}

// Build "all versions" list for reference table (only valid semver tags)
$allVersions = [];
foreach ($dockerTags as $tag) {
    $ver = parseDockerTagVersion($tag);
    if ($ver !== null) {
        $allVersions[] = ['tag' => $tag, 'version' => $ver];
    }
}
usort($allVersions, function ($a, $b) {
    return version_compare($b['version'], $a['version']);
});

// ============================================================================
// Docker In-Place Upgrade POST Handler
// ============================================================================
$upgradeLog = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_version.err_invalid_request');
    } elseif (isset($_POST['perform_docker_upgrade']) || isset($_POST['perform_docker_redeploy'])) {
        // Redeploy re-pulls and re-applies the CURRENTLY installed version. It exists
        // for the case where a fix was published under the same tag (a rolling re-push):
        // the normal upgrade list never offers it because getNewerDockerVersions() only
        // surfaces strictly-newer versions. Everything downstream (backup, layer
        // download, in-place file apply, SQL migration, OPcache flush + Apache reload)
        // is shared with the upgrade path.
        $isRedeploy = isset($_POST['perform_docker_redeploy']);
        $upgradeLog = [];
        $selectedVersion = $isRedeploy ? ltrim($currentVersion, 'v') : ($_POST['target_version'] ?? '');
        $upgradeFailed = false;

        if ($isRedeploy) {
            // Redeploy always targets the installed version -- nothing to validate
            // against the "newer versions" list.
            $versionValid = true;
            $upgradeLog[] = ['step' => 'Validate version', 'status' => 'ok', 'detail' => 'redeploy v' . $selectedVersion];
        } else {
            // Validate the selected version exists in newer versions from registry
            $versionValid = false;
            foreach ($newerVersions as $nv) {
                if ($nv['version'] === $selectedVersion) {
                    $versionValid = true;
                    break;
                }
            }

            if (!$versionValid) {
                $error = t('admin_version.err_version_not_available');
                $upgradeLog[] = ['step' => 'Validate version', 'status' => 'error', 'detail' => 'v' . $selectedVersion . ' not in available upgrades'];
                $upgradeFailed = true;
            } else {
                $upgradeLog[] = ['step' => 'Validate version', 'status' => 'ok', 'detail' => 'v' . $selectedVersion];
            }
        }

        // ---- Step 1: Fetch image manifests from Docker registry ----
        $targetTag = 'v' . $selectedVersion;
        $currentTag = ltrim($currentVersion, 'v');
        $currentTag = 'v' . $currentTag;

        $regUser = $registryUser !== '' ? $registryUser : null;
        $regPass = $registryPass !== '' ? $registryPass : null;

        $targetManifest = null;
        $currentManifest = null;
        $newLayers = [];

        if (!$upgradeFailed) {
            $targetManifest = fetchDockerManifest($registryHost, $dockerImageName, $targetTag, $regUser, $regPass);
            if ($targetManifest === false) {
                $error = t('admin_version.err_manifest_fetch_prefix') . $targetTag . t('admin_version.err_manifest_fetch_suffix');
                $upgradeLog[] = ['step' => 'Fetch manifests', 'status' => 'error', 'detail' => 'Failed to get manifest for ' . $registryHost . '/' . $dockerImageName . ':' . $targetTag];
                $upgradeFailed = true;
            }
        }

        if (!$upgradeFailed) {
            if ($isRedeploy) {
                // Same tag in and out, so a current-vs-target layer diff would be empty.
                // The Dockerfile places the whole app in its final layer: the closing RUN
                // cp's every patched file into /var/www/html and then `chown -R` re-owns
                // the entire web root, so that top layer is a complete /var/www/html
                // snapshot. Re-applying just that layer refreshes all application code
                // without re-downloading the multi-hundred-MB OS base layers.
                $topLayer = !empty($targetManifest['layers']) ? end($targetManifest['layers']) : null;
                if ($topLayer === null) {
                    $error = t('admin_version.err_manifest_no_layers');
                    $upgradeLog[] = ['step' => 'Fetch manifests', 'status' => 'error', 'detail' => 'Empty layer list for ' . $targetTag];
                    $upgradeFailed = true;
                } else {
                    $newLayers[] = $topLayer;
                    $sz = $topLayer['size'] ?? 0;
                    $sizeStr = $sz > 1048576 ? round($sz / 1048576, 2) . ' MB' : round($sz / 1024, 2) . ' KB';
                    $upgradeLog[] = ['step' => 'Fetch manifests', 'status' => 'ok', 'detail' => 'Redeploy: applying top application layer, ' . $sizeStr . ' to download'];
                }
            } else {
                $currentManifest = fetchDockerManifest($registryHost, $dockerImageName, $currentTag, $regUser, $regPass);
                // Build set of current layer digests
                $currentDigests = [];
                if ($currentManifest !== false && !empty($currentManifest['layers'])) {
                    foreach ($currentManifest['layers'] as $layer) {
                        $currentDigests[$layer['digest']] = true;
                    }
                }

                // Find layers in target that don't exist in current
                foreach ($targetManifest['layers'] as $layer) {
                    if (!isset($currentDigests[$layer['digest']])) {
                        $newLayers[] = $layer;
                    }
                }

                if (empty($newLayers)) {
                    $error = t('admin_version.err_no_new_layers');
                    $upgradeLog[] = ['step' => 'Fetch manifests', 'status' => 'error', 'detail' => 'Target image has no new layers compared to current (are they identical?)'];
                    $upgradeFailed = true;
                } else {
                    $totalSize = 0;
                    foreach ($newLayers as $l) $totalSize += ($l['size'] ?? 0);
                    $sizeStr = $totalSize > 1048576
                        ? round($totalSize / 1048576, 2) . ' MB'
                        : round($totalSize / 1024, 2) . ' KB';
                    $upgradeLog[] = ['step' => 'Fetch manifests', 'status' => 'ok', 'detail' => count($newLayers) . ' new layer(s), ' . $sizeStr . ' to download'];
                }
            }
        }

        // ---- Step 2: Auto-backup database ----
        if (!$upgradeFailed) {
            try {
                $backupDir = '/persistent/db_backup';
                if (!is_dir($backupDir)) {
                    mkdir($backupDir, 0700, true);
                }

                $timestamp = date('Y-m-d_His');
                $backupFilename = 'tprm_database_backup_' . $timestamp . '.sql';
                $backupPath = $backupDir . '/' . $backupFilename;

                $backupService = new BackupService($config->get('database'));
                $backupService->dump($backupPath);

                if (!file_exists($backupPath) || filesize($backupPath) === 0) {
                    if (file_exists($backupPath)) unlink($backupPath);
                    throw new Exception('Backup file was empty or not created');
                }
                chmod($backupPath, 0600);

                $backupSize = filesize($backupPath);
                $formattedSize = $backupSize > 1048576
                    ? round($backupSize / 1048576, 2) . ' MB'
                    : round($backupSize / 1024, 2) . ' KB';

                $auth->audit($user['id'], 'backup_create', 'database', null, [
                    'new' => ['filename' => $backupFilename, 'size' => $formattedSize, 'trigger' => 'docker_upgrade']
                ]);
                $upgradeLog[] = ['step' => 'Database backup', 'status' => 'ok', 'detail' => $backupFilename . ' (' . $formattedSize . ')'];
            } catch (Exception $e) {
                error_log('Pre-upgrade backup failed: ' . $e->getMessage());
                $error = t('admin_version.err_backup_failed');
                $upgradeLog[] = ['step' => 'Database backup', 'status' => 'error', 'detail' => $e->getMessage()];
                $upgradeFailed = true;
            }
        }

        // ---- Step 3: Download and extract new layers from registry ----
        $tempDir = null;
        $stagingDir = null;
        if (!$upgradeFailed) {
            $tempDir = sys_get_temp_dir() . '/tprm_upgrade_' . time() . '_' . getmypid();
            $stagingDir = $tempDir . '/staging';
            if (!@mkdir($stagingDir, 0700, true)) {
                $error = t('admin_version.err_temp_dir');
                $upgradeLog[] = ['step' => 'Download layers', 'status' => 'error', 'detail' => 'Could not create temp dir'];
                $upgradeFailed = true;
                $tempDir = null;
            }
        }

        if (!$upgradeFailed && $tempDir) {
            $downloadedCount = 0;
            foreach ($newLayers as $idx => $layer) {
                $layerFile = $tempDir . '/layer_' . $idx . '.tar.gz';
                $ok = downloadDockerBlob($registryHost, $dockerImageName, $layer['digest'], $layerFile, $regUser, $regPass);
                if (!$ok) {
                    $error = t('admin_version.err_download_layer');
                    $upgradeLog[] = ['step' => 'Download layers', 'status' => 'error', 'detail' => 'Could not download layer ' . ($idx + 1) . ' (' . substr($layer['digest'], 7, 12) . '...)'];
                    $upgradeFailed = true;
                    break;
                }

                // Extract layer to staging directory (layers stack in order)
                list($tarOut, $tarCode) = runShellCommand(['tar', '-xzf', $layerFile, '-C', $stagingDir], $tempDir);
                @unlink($layerFile);
                if ($tarCode !== 0) {
                    $error = t('admin_version.err_extract_layer');
                    $upgradeLog[] = ['step' => 'Download layers', 'status' => 'error', 'detail' => 'Extraction failed for layer ' . ($idx + 1) . ': ' . implode(' ', $tarOut)];
                    $upgradeFailed = true;
                    break;
                }
                $downloadedCount++;
            }

            if (!$upgradeFailed) {
                $upgradeLog[] = ['step' => 'Download layers', 'status' => 'ok', 'detail' => 'Downloaded and extracted ' . $downloadedCount . ' layer(s) from registry'];
            }
        }

        // ---- Step 4: Apply updated files from staging to web root ----
        if (!$upgradeFailed && $stagingDir) {
            $stagedWebRoot = $stagingDir . '/var/www/html';
            if (!is_dir($stagedWebRoot)) {
                // The layers may not contain /var/www/html changes (e.g., only VERSION changed at root)
                // Check for VERSION file at the root of the layer
                $stagedVersion = $stagingDir . '/var/www/html';
                $error = t('admin_version.err_no_app_files');
                $upgradeLog[] = ['step' => 'Apply files', 'status' => 'error', 'detail' => 'Layer extraction did not contain /var/www/html. The image may not have application code changes.'];
                $upgradeFailed = true;
            } else {
                // Copy files individually to handle symlink-to-directory conflicts
                list($findStagedOut, $findStagedCode) = runShellCommand(['find', $stagedWebRoot, '-type', 'f', '-o', '-type', 'l'], $tempDir);
                $fileCount = 0;
                $cpErrors = [];
                foreach (array_filter($findStagedOut) as $stagedFile) {
                    $stagedFile = trim($stagedFile);
                    if ($stagedFile === '') continue;
                    // Skip symlinks pointing to directories (persistent mount points)
                    if (is_link($stagedFile) && is_dir($stagedFile)) continue;
                    // Skip OverlayFS whiteout markers (.wh.*). These are layer
                    // delete-markers (e.g. sql_updates purged old migration files), not
                    // real content. Copying them fails when the target is a symlink to a
                    // persistent dir, and we must NOT honor them as deletions either --
                    // that would wipe migration-tracking state (.applied_hashes /
                    // applied_updates.json) on the persistent volume. An in-place apply
                    // only ever adds/updates files, never deletes.
                    if (strpos(basename($stagedFile), '.wh.') === 0) continue;
                    $relPath = substr($stagedFile, strlen($stagedWebRoot));
                    $targetFile = $webRoot . $relPath;
                    $targetDir = dirname($targetFile);
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    if (file_exists($targetFile) || is_link($targetFile)) {
                        @unlink($targetFile);
                    }
                    if (@copy($stagedFile, $targetFile)) {
                        @chmod($targetFile, fileperms($stagedFile) & 0777);
                        $fileCount++;
                    } else {
                        $cpErrors[] = 'Failed to copy: ' . $relPath;
                    }
                }
                if (!empty($cpErrors)) {
                    $error = t('admin_version.err_copy_files');
                    $upgradeLog[] = ['step' => 'Apply files', 'status' => 'error', 'detail' => implode("\n", $cpErrors)];
                    $upgradeFailed = true;
                } else {
                    $upgradeLog[] = ['step' => 'Apply files', 'status' => 'ok', 'detail' => $fileCount . ' file(s) applied to web root'];
                }
            }

            // Cleanup temp dir
            if ($tempDir) {
                runShellCommand(['rm', '-rf', $tempDir], sys_get_temp_dir());
            }
        }

        // ---- Step 5: Re-create Docker persistent symlinks ----
        if (!$upgradeFailed) {
            $restoredLinks = [];

            // Sync any new SQL files from the upgrade into /persistent/sql_updates/
            $extractedSqlDir = $webRoot . '/sql_updates';
            if (is_dir($extractedSqlDir) && !is_link($extractedSqlDir)) {
                $newSqlFiles = glob($extractedSqlDir . '/*.sql') ?: [];
                foreach ($newSqlFiles as $sf) {
                    $dest = '/persistent/sql_updates/' . basename($sf);
                    if (!file_exists($dest) || md5_file($sf) !== md5_file($dest)) {
                        copy($sf, $dest);
                    }
                }
            }

            // Directory symlinks
            $dirSymlinks = [
                '/persistent/config/logs'    => $webRoot . '/config/logs',
                '/persistent/sql_updates'    => $webRoot . '/sql_updates',
                '/persistent/db_backup'      => $webRoot . '/db_backup',
            ];
            foreach ($dirSymlinks as $target => $link) {
                if (is_dir($link) && !is_link($link)) {
                    runShellCommand(['rm', '-rf', $link], $webRoot);
                } elseif (is_link($link)) {
                    @unlink($link);
                }
                @symlink($target, $link);
                $restoredLinks[] = basename($link);
            }

            // File symlinks
            $fileSymlinks = [
                '/persistent/config/config.php' => $webRoot . '/config/config.php',
            ];
            foreach (['logo-default-418x78.png', 'logo-inverse-416x78.png', 'favicon.ico'] as $brandFile) {
                if (file_exists('/persistent/branding/' . $brandFile)) {
                    $fileSymlinks['/persistent/branding/' . $brandFile] = $webRoot . '/app/images/' . $brandFile;
                }
            }
            foreach ($fileSymlinks as $target => $link) {
                if ((file_exists($link) || is_link($link)) && !is_link($link)) {
                    @unlink($link);
                } elseif (is_link($link)) {
                    @unlink($link);
                }
                @symlink($target, $link);
                $restoredLinks[] = basename($link);
            }

            if (file_exists($webRoot . '/setup.php')) {
                @unlink($webRoot . '/setup.php');
                $restoredLinks[] = 'removed setup.php';
            }

            $upgradeLog[] = ['step' => 'Restore symlinks', 'status' => 'ok', 'detail' => 'Restored: ' . implode(', ', $restoredLinks)];

            // Update VERSION file
            file_put_contents($webRoot . '/VERSION', 'v' . $selectedVersion);
        }

        // ---- Step 6: Apply SQL migration ----
        if (!$upgradeFailed) {
            $sqlFile = '/persistent/sql_updates/v' . $selectedVersion . '.sql';
            if (!file_exists($sqlFile)) {
                $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'skip', 'detail' => 'No migration file: v' . $selectedVersion . '.sql'];
            } else {
                $hashDir = '/persistent/sql_updates/.applied_hashes';
                $hashFile = $hashDir . '/v' . $selectedVersion . '.sql.md5';
                $currentHash = md5_file($sqlFile);
                $alreadyApplied = file_exists($hashFile) && trim(file_get_contents($hashFile)) === $currentHash;

                if ($alreadyApplied) {
                    $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'skip', 'detail' => 'v' . $selectedVersion . '.sql already applied (hash match)'];
                } else {
                    try {
                        $sqlContent = file_get_contents($sqlFile);
                        if ($sqlContent === false || trim($sqlContent) === '') {
                            throw new Exception('Migration file is empty');
                        }

                        $dbConfig = $config->get('database');
                        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                            $dbConfig['host'],
                            $dbConfig['port'] ?? 3306,
                            $dbConfig['database'],
                            $dbConfig['charset'] ?? 'utf8mb4'
                        );
                        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        ]);

                        // Quote-aware SQL splitter
                        $statements = [];
                        $current = '';
                        $inString = false;
                        $len = strlen($sqlContent);
                        for ($i = 0; $i < $len; $i++) {
                            $ch = $sqlContent[$i];
                            if ($inString) {
                                $current .= $ch;
                                if ($ch === '\\' && $i + 1 < $len) {
                                    $current .= $sqlContent[++$i];
                                } elseif ($ch === "'") {
                                    if ($i + 1 < $len && $sqlContent[$i + 1] === "'") {
                                        $current .= $sqlContent[++$i];
                                    } else {
                                        $inString = false;
                                    }
                                }
                            } else {
                                if ($ch === "'") {
                                    $inString = true;
                                    $current .= $ch;
                                } elseif ($ch === ';') {
                                    $stmt = trim($current);
                                    if ($stmt !== '') $statements[] = $stmt;
                                    $current = '';
                                } elseif ($ch === '-' && $i + 1 < $len && $sqlContent[$i + 1] === '-') {
                                    $eol = strpos($sqlContent, "\n", $i);
                                    $i = ($eol === false) ? $len : $eol;
                                } elseif ($ch === '/' && $i + 1 < $len && $sqlContent[$i + 1] === '*') {
                                    if ($i + 2 < $len && $sqlContent[$i + 2] === '!') {
                                        $end = strpos($sqlContent, '*/', $i + 2);
                                        if ($end !== false) {
                                            $current .= substr($sqlContent, $i, $end + 2 - $i);
                                            $i = $end + 1;
                                        } else {
                                            $current .= $ch;
                                        }
                                    } else {
                                        $end = strpos($sqlContent, '*/', $i + 2);
                                        $i = ($end === false) ? $len : $end + 1;
                                    }
                                } else {
                                    $current .= $ch;
                                }
                            }
                        }
                        $stmt = trim($current);
                        if ($stmt !== '') $statements[] = $stmt;

                        $stmtCount = 0;
                        $stmtNotices = [];
                        foreach ($statements as $stmt) {
                            if (empty($stmt)) continue;
                            try {
                                $pdo->exec($stmt);
                                $stmtCount++;
                            } catch (PDOException $stmtEx) {
                                $errCode = isset($stmtEx->errorInfo[1]) ? (int)$stmtEx->errorInfo[1] : 0;
                                if (in_array($errCode, [1060, 1061, 1050, 1068, 1091, 1553])) {
                                    $stmtNotices[] = $stmtEx->getMessage();
                                    error_log('Docker upgrade SQL notice (ignorable): ' . $stmtEx->getMessage());
                                    $stmtCount++;
                                } else {
                                    throw $stmtEx;
                                }
                            }
                        }

                        if (!is_dir($hashDir)) mkdir($hashDir, 0700, true);
                        file_put_contents($hashFile, $currentHash);

                        $logFile = '/persistent/sql_updates/applied_updates.json';
                        $logData = [];
                        if (file_exists($logFile)) {
                            $existing = json_decode(file_get_contents($logFile), true);
                            if (is_array($existing)) $logData = $existing;
                        }
                        $migrationFilename = 'v' . $selectedVersion . '.sql';
                        if (array_is_list($logData) || empty($logData)) {
                            $logData[] = [
                                'filename'   => $migrationFilename,
                                'applied_at' => date('Y-m-d H:i:s'),
                                'applied_by' => $user['username'] ?? 'unknown',
                                'file_size'  => filesize($sqlFile),
                            ];
                        } else {
                            $logData[$migrationFilename] = [
                                'applied_at' => date('Y-m-d H:i:s'),
                                'exit_code'  => 0,
                            ];
                        }
                        file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT));

                        $detail = $stmtCount . ' statement(s) executed';
                        if (!empty($stmtNotices)) {
                            $detail .= ', ' . count($stmtNotices) . ' ignorable notice(s)';
                        }
                        $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'ok', 'detail' => $detail];
                    } catch (Exception $e) {
                        error_log('Docker upgrade SQL error: ' . $e->getMessage());
                        $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'error', 'detail' => $e->getMessage()];
                        $upgradeFailed = true;
                    }
                }
            }
        }

        // Audit log
        $auth->audit($user['id'], $isRedeploy ? 'docker_redeploy' : 'docker_upgrade', 'system', null, [
            'new' => [
                'target_version' => 'v' . $selectedVersion,
                'success' => !$upgradeFailed,
                'steps' => count($upgradeLog),
            ]
        ]);

        if (!$upgradeFailed) {
            postUpgradeRefresh($upgradeLog);
            $success = ($isRedeploy ? t('admin_version.success_redeploy_prefix') : t('admin_version.success_upgrade_prefix')) . $selectedVersion . t('admin_version.success_completed_suffix');
            $currentVersion = getAppVersion();
            $newerVersions = getNewerDockerVersions($dockerTags, $currentVersion);
        }
    }
}

?>

<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_version.page_title')); ?></h1>
    <p><?php echo e(t('admin_version.subtitle_docker')); ?></p>
</div>

<!-- Current Status -->
<div class="card">
    <h3><?php echo e(t('admin_version.current_status_heading')); ?></h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.installed_version_label')); ?></div>
            <div style="font-size: 16px; font-weight: 600;">
                <span style="display: inline-block; padding: 2px 10px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 14px;"><?php echo e($currentVersion); ?></span>
            </div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.environment_label')); ?></div>
            <div style="font-size: 14px;">
                <span style="display: inline-block; padding: 2px 10px; background: #f3e8ff; color: #7c3aed; border-radius: 4px; font-size: 13px;"><?php echo e(t('admin_version.docker_container_badge')); ?></span>
            </div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.latest_available_label')); ?></div>
            <div style="font-size: 14px;">
                <?php if ($registryError): ?>
                    <span style="color: #991b1b;"><?php echo e(t('admin_version.unable_to_check')); ?></span>
                <?php elseif (!empty($newerVersions)): ?>
                    <span style="color: #b45309;">v<?php echo e($newerVersions[0]['version']); ?> <?php echo e(t('admin_version.update_available_suffix')); ?></span>
                <?php elseif (!empty($allVersions)): ?>
                    <span style="color: #166534;"><?php echo e($currentVersion); ?> <?php echo e(t('admin_version.up_to_date_suffix')); ?></span>
                <?php else: ?>
                    <span style="color: #999;"><?php echo e(t('admin_version.no_versions_found')); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Server -->
<div class="card">
    <h3><?php echo e(t('admin_version.update_server_heading')); ?></h3>
    <form method="POST" action="admin.php?section=version">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label for="docker_registry_host"><?php echo e(t('admin_version.registry_hostname_label')); ?></label>
            <input type="text" id="docker_registry_host" name="docker_registry_host" class="form-control" style="max-width: 500px;"
                   value="<?php echo e($registryHost); ?>"
                   placeholder="dockerregistry.fairtprm.com">
            <div class="form-help"><?php echo e(t('admin_version.registry_hostname_help')); ?></div>
        </div>
        <div style="display: flex; gap: 15px; max-width: 500px;">
            <div class="form-group" style="flex: 1;">
                <label for="docker_registry_user"><?php echo e(t('admin_version.username_label')); ?> <span style="color: #999; font-weight: 400;"><?php echo e(t('admin_version.optional_note')); ?></span></label>
                <input type="text" id="docker_registry_user" name="docker_registry_user" class="form-control"
                       value="<?php echo e($registryUser); ?>"
                       placeholder="<?php echo e(t('admin_version.username_placeholder')); ?>" autocomplete="off">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="docker_registry_pass"><?php echo e(t('admin_version.password_label')); ?> <span style="color: #999; font-weight: 400;"><?php echo e(t('admin_version.optional_note')); ?></span></label>
                <input type="password" id="docker_registry_pass" name="docker_registry_pass" class="form-control"
                       value="<?php echo e($registryPass); ?>"
                       placeholder="<?php echo e(t('admin_version.password_placeholder')); ?>" autocomplete="off">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo e(t('admin_version.check_for_updates_button')); ?></button>
    </form>
    <?php if ($registryError && $registryErrMsg): ?>
        <div class="alert alert-danger" style="margin-top: 10px;"><?php echo e($registryErrMsg); ?></div>
    <?php endif; ?>
</div>

<!-- Re-apply current version (redeploy) -->
<?php if (!$registryError): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.reapply_heading')); ?></h3>
    <p style="color: #555; font-size: 14px; margin-top: 0;">
        <?php echo e(t('admin_version.reapply_desc_prefix')); ?><strong>v<?php echo e(ltrim($currentVersion, 'v')); ?></strong><?php echo t('admin_version.reapply_desc_suffix'); ?>
    </p>
    <button type="button" class="btn btn-primary docker-redeploy-btn"
            data-version="<?php echo e(ltrim($currentVersion, 'v')); ?>">
        <?php echo e(t('admin_version.redeploy_button_label')); ?> v<?php echo e(ltrim($currentVersion, 'v')); ?>
    </button>
</div>
<?php endif; ?>

<!-- Available Updates (only newer versions) -->
<?php if (!empty($newerVersions)): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.available_updates_heading')); ?></h3>
    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
        <strong style="color: #166534;">&#10003; <?php echo e(t('admin_version.settings_safe_heading')); ?></strong>
        <span style="font-size: 13px; color: #15803d;">
            <?php echo e(t('admin_version.settings_safe_detail_updates')); ?>
        </span>
    </div>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_version.th_version')); ?></th>
                <th><?php echo e(t('admin_version.th_image')); ?></th>
                <th><?php echo e(t('admin_version.th_status')); ?></th>
                <th style="width: 120px;"><?php echo e(t('admin_version.th_action')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($newerVersions as $nv): ?>
            <tr>
                <td><strong>v<?php echo e($nv['version']); ?></strong></td>
                <td style="font-size: 12px; color: #666;"><?php echo e($registryHost . '/' . $dockerImageName . ':v' . $nv['version']); ?></td>
                <td><span class="badge badge-blue"><?php echo e(t('admin_version.badge_available')); ?></span></td>
                <td>
                    <button type="button" class="btn btn-sm btn-primary docker-upgrade-btn"
                            data-version="<?php echo e($nv['version']); ?>"
                            data-registry="<?php echo e($registryHost); ?>">
                        <?php echo e(t('admin_version.upgrade_button')); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- All Registry Versions (reference) -->
<?php if (!empty($allVersions)): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.all_registry_versions_heading')); ?></h3>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_version.th_tag')); ?></th>
                <th><?php echo e(t('admin_version.th_version')); ?></th>
                <th><?php echo e(t('admin_version.th_status')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allVersions as $av):
                $cmp = version_compare($av['version'], ltrim($currentVersion, 'v'));
            ?>
            <tr>
                <td><strong><?php echo e($av['tag']); ?></strong></td>
                <td>v<?php echo e($av['version']); ?></td>
                <td>
                    <?php if ($cmp === 0): ?>
                        <span class="badge badge-success"><?php echo e(t('admin_version.badge_installed')); ?></span>
                    <?php elseif ($cmp > 0): ?>
                        <span class="badge badge-blue"><?php echo e(t('admin_version.badge_newer')); ?></span>
                    <?php else: ?>
                        <span class="badge" style="background: #f3f4f6; color: #6b7280;"><?php echo e(t('admin_version.badge_older')); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($upgradeLog !== null): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.upgrade_log_heading')); ?></h3>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_version.th_step')); ?></th>
                <th><?php echo e(t('admin_version.th_status')); ?></th>
                <th><?php echo e(t('admin_version.th_details')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($upgradeLog as $entry): ?>
            <tr>
                <td><strong><?php echo e($entry['step']); ?></strong></td>
                <td>
                    <?php if ($entry['status'] === 'ok'): ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #dcfce7; color: #166534; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_ok')); ?></span>
                    <?php elseif ($entry['status'] === 'skip'): ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_skipped')); ?></span>
                    <?php else: ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #fef2f2; color: #991b1b; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_error')); ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size: 13px; color: #555; white-space: pre-line;"><?php echo e($entry['detail']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Docker Upgrade Modal -->
<div id="dockerUpgradeModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" data-close="dockerUpgradeModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_version.confirm_docker_upgrade_heading')); ?></h3>

        <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: start; gap: 10px;">
                <span style="font-size: 20px;">&#10003;</span>
                <div>
                    <strong style="color: #166534;"><?php echo e(t('admin_version.settings_safe_heading')); ?></strong>
                    <p style="margin: 5px 0 0; font-size: 13px; color: #15803d;">
                        <?php echo e(t('admin_version.settings_safe_detail_upgrade')); ?>
                    </p>
                </div>
            </div>
        </div>

        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <strong style="color: #92400e;"><?php echo e(t('admin_version.what_happens_label')); ?></strong>
            <ul style="margin: 8px 0 0; padding-left: 20px; font-size: 13px; color: #78350f;">
                <li><?php echo e(t('admin_version.step_backup_auto')); ?></li>
                <li><?php echo e(t('admin_version.step_archive_applied')); ?></li>
                <li><?php echo e(t('admin_version.step_symlinks_restored')); ?></li>
                <li><?php echo e(t('admin_version.step_sql_applied')); ?></li>
            </ul>
        </div>

        <p style="color: #666; margin-bottom: 15px;">
            <?php echo e(t('admin_version.upgrading_to_label')); ?> <strong id="docker_upgrade_version_display" style="color: #333;"></strong>
        </p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="docker_upgrade_confirm_input" style="font-weight: 500;"><?php echo t('admin_version.type_upgrade_confirm_label'); ?></label>
            <input type="text" id="docker_upgrade_confirm_input" class="form-control" placeholder="<?php echo e(t('admin_version.type_upgrade_confirm_placeholder')); ?>" autocomplete="off">
        </div>

        <form method="POST" action="admin.php?section=version" id="dockerUpgradeForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="docker_upgrade_target_version" name="target_version" value="">
            <input type="hidden" name="perform_docker_upgrade" value="1">

            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn" style="background: #6b7280; color: white;" data-close="dockerUpgradeModal"><?php echo e(t('admin_version.cancel_button')); ?></button>
                <button type="button" id="dockerConfirmUpgradeBtn" class="btn btn-primary" disabled><?php echo e(t('admin_version.upgrade_button')); ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var modal        = document.getElementById('dockerUpgradeModal');
    var confirmInput = document.getElementById('docker_upgrade_confirm_input');
    var confirmBtn   = document.getElementById('dockerConfirmUpgradeBtn');
    var verDisplay   = document.getElementById('docker_upgrade_version_display');
    var hiddenVer    = document.getElementById('docker_upgrade_target_version');
    var form         = document.getElementById('dockerUpgradeForm');

    function openModal(version) {
        verDisplay.textContent = 'v' + version;
        hiddenVer.value = version;
        confirmInput.value = '';
        confirmBtn.disabled = true;
        modal.style.display = 'flex';
    }

    document.querySelectorAll('.docker-upgrade-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal(this.getAttribute('data-version'));
        });
    });

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            confirmBtn.disabled = (this.value !== 'UPGRADE');
        });
    }

    if (confirmBtn && form) {
        confirmBtn.addEventListener('click', function() {
            if (confirmInput.value !== 'UPGRADE') return;
            confirmBtn.disabled = true;
            confirmBtn.textContent = <?php echo json_encode(t('admin_version.js_upgrading_progress')); ?>;
            form.submit();
        });
    }
})();
</script>

<!-- Docker Redeploy Modal -->
<div id="dockerRedeployModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" data-close="dockerRedeployModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_version.confirm_redeploy_heading')); ?></h3>

        <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: start; gap: 10px;">
                <span style="font-size: 20px;">&#10003;</span>
                <div>
                    <strong style="color: #166534;"><?php echo e(t('admin_version.settings_safe_heading')); ?></strong>
                    <p style="margin: 5px 0 0; font-size: 13px; color: #15803d;">
                        <?php echo e(t('admin_version.settings_safe_detail_redeploy')); ?>
                    </p>
                </div>
            </div>
        </div>

        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <strong style="color: #92400e;"><?php echo e(t('admin_version.what_happens_label')); ?></strong>
            <ul style="margin: 8px 0 0; padding-left: 20px; font-size: 13px; color: #78350f;">
                <li><?php echo e(t('admin_version.step_backup_auto')); ?></li>
                <li><?php echo e(t('admin_version.step_reapply_files')); ?></li>
                <li><?php echo e(t('admin_version.step_symlinks_restored')); ?></li>
                <li><?php echo e(t('admin_version.step_sql_opcache')); ?></li>
            </ul>
        </div>

        <p style="color: #666; margin-bottom: 15px;">
            <?php echo e(t('admin_version.reapplying_label')); ?> <strong id="docker_redeploy_version_display" style="color: #333;"></strong>
        </p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="docker_redeploy_confirm_input" style="font-weight: 500;"><?php echo t('admin_version.type_redeploy_confirm_label'); ?></label>
            <input type="text" id="docker_redeploy_confirm_input" class="form-control" placeholder="<?php echo e(t('admin_version.type_redeploy_confirm_placeholder')); ?>" autocomplete="off">
        </div>

        <form method="POST" action="admin.php?section=version" id="dockerRedeployForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="docker_redeploy_target_version" name="target_version" value="">
            <input type="hidden" name="perform_docker_redeploy" value="1">

            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn" style="background: #6b7280; color: white;" data-close="dockerRedeployModal"><?php echo e(t('admin_version.cancel_button')); ?></button>
                <button type="button" id="dockerConfirmRedeployBtn" class="btn btn-primary" disabled><?php echo e(t('admin_version.redeploy_button_label')); ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var modal        = document.getElementById('dockerRedeployModal');
    var confirmInput = document.getElementById('docker_redeploy_confirm_input');
    var confirmBtn   = document.getElementById('dockerConfirmRedeployBtn');
    var verDisplay   = document.getElementById('docker_redeploy_version_display');
    var hiddenVer    = document.getElementById('docker_redeploy_target_version');
    var form         = document.getElementById('dockerRedeployForm');

    function openModal(version) {
        verDisplay.textContent = 'v' + version;
        hiddenVer.value = version;
        confirmInput.value = '';
        confirmBtn.disabled = true;
        modal.style.display = 'flex';
    }

    document.querySelectorAll('.docker-redeploy-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal(this.getAttribute('data-version'));
        });
    });

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            confirmBtn.disabled = (this.value !== 'REDEPLOY');
        });
    }

    if (confirmBtn && form) {
        confirmBtn.addEventListener('click', function() {
            if (confirmInput.value !== 'REDEPLOY') return;
            confirmBtn.disabled = true;
            confirmBtn.textContent = <?php echo json_encode(t('admin_version.js_redeploying_progress')); ?>;
            form.submit();
        });
    }
})();
</script>

<?php
// ============================================================================
// NON-DOCKER MODE: Original tar.gz upgrade system
// ============================================================================
else:

// Available versions from remote CSV
$availableVersions = fetchVersionsCsv($versionsUrl);
$versionsFetchError = ($availableVersions === false);
if ($versionsFetchError) {
    $availableVersions = [];
}

// ============================================================================
// POST Handler: perform_version_upgrade (non-Docker only)
// ============================================================================
$upgradeLog = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_version.err_invalid_request');
    } elseif (isset($_POST['perform_version_upgrade'])) {
        $upgradeLog = [];
        $selectedVersion = $_POST['target_version'] ?? '';
        $selectedFilename = $_POST['target_filename'] ?? '';

        // Validate version against the remote CSV manifest
        $availableVersions = fetchVersionsCsv($versionsUrl);
        $validVersion = false;
        if ($availableVersions !== false) {
            foreach ($availableVersions as $v) {
                if ($v['version'] === $selectedVersion && $v['filename'] === $selectedFilename) {
                    $validVersion = true;
                    break;
                }
            }
        }

        if (!$validVersion) {
            $error = t('admin_version.err_invalid_version_selected');
            $upgradeLog[] = ['step' => 'Validate version', 'status' => 'error', 'detail' => 'Version not found in manifest: ' . $selectedVersion];
        } else {
            $upgradeLog[] = ['step' => 'Validate version', 'status' => 'ok', 'detail' => 'v' . $selectedVersion];
            $upgradeFailed = false;

            // ---- Step 1: Auto-backup database ----
            try {
                $backupDir = $webRoot . '/db_backup';
                if (!is_dir($backupDir)) {
                    if (!mkdir($backupDir, 0700, true)) {
                        throw new Exception('Failed to create backup directory');
                    }
                    file_put_contents($backupDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
                    file_put_contents($backupDir . '/index.html', '');
                }

                $timestamp = date('Y-m-d_His');
                $backupFilename = 'tprm_database_backup_' . $timestamp . '.sql';
                $backupPath = $backupDir . '/' . $backupFilename;

                $backupService = new BackupService($config->get('database'));
                $backupService->dump($backupPath);

                if (!file_exists($backupPath) || filesize($backupPath) === 0) {
                    if (file_exists($backupPath)) {
                        unlink($backupPath);
                    }
                    throw new Exception('Backup file was empty or not created');
                }
                chmod($backupPath, 0600);

                $backupSize = filesize($backupPath);
                $formattedSize = $backupSize > 1048576
                    ? round($backupSize / 1048576, 2) . ' MB'
                    : round($backupSize / 1024, 2) . ' KB';

                $auth->audit($user['id'], 'backup_create', 'database', null, [
                    'new' => ['filename' => $backupFilename, 'size' => $formattedSize, 'trigger' => 'version_upgrade']
                ]);
                $upgradeLog[] = ['step' => 'Database backup', 'status' => 'ok', 'detail' => $backupFilename . ' (' . $formattedSize . ')'];
            } catch (Exception $e) {
                error_log('Pre-upgrade backup failed: ' . $e->getMessage());
                $error = t('admin_version.err_pre_upgrade_backup_failed');
                $upgradeLog[] = ['step' => 'Database backup', 'status' => 'error', 'detail' => $e->getMessage()];
                $upgradeFailed = true;
            }

            // ---- Step 2: Download the release archive ----
            $tarPath = null;
            if (!$upgradeFailed) {
                // Sanitize filename -- only allow alphanumeric, hyphens, underscores, dots
                if (!preg_match('/^[a-zA-Z0-9._-]+\.tar\.gz$/', $selectedFilename)) {
                    $error = t('admin_version.err_invalid_archive_filename');
                    $upgradeLog[] = ['step' => 'Download archive', 'status' => 'error', 'detail' => 'Filename failed validation'];
                    $upgradeFailed = true;
                } else {
                    $tarPath = $webRoot . '/' . $selectedFilename;
                    $downloadUrl = $downloadBaseUrl . $selectedFilename;

                    if (!downloadArchive($downloadUrl, $tarPath)) {
                        $error = t('admin_version.err_download_archive');
                        $upgradeLog[] = ['step' => 'Download archive', 'status' => 'error', 'detail' => 'Could not download: ' . $selectedFilename];
                        $upgradeFailed = true;
                        $tarPath = null;
                    } else {
                        $tarSize = filesize($tarPath);
                        $formattedTarSize = $tarSize > 1048576
                            ? round($tarSize / 1048576, 2) . ' MB'
                            : round($tarSize / 1024, 2) . ' KB';
                        $upgradeLog[] = ['step' => 'Download archive', 'status' => 'ok', 'detail' => $selectedFilename . ' (' . $formattedTarSize . ')'];
                    }
                }
            }

            // (Step 3 merged into Step 6 below)

            // ---- Step 4: Record protected file state and save copies ----
            $setupExisted = false;
            $savedFavicon = null;
            $savedLogos = [];
            $savedConfig = null;
            $shelteredGit = false;
            $shelteredClaude = false;
            $shelteredDbBackup = false;
            if (!$upgradeFailed) {
                $setupExisted = file_exists($webRoot . '/setup.php');

                // Save config/config.php -- site-specific DB credentials and settings
                if (file_exists($webRoot . '/config/config.php')) {
                    $savedConfig = file_get_contents($webRoot . '/config/config.php');
                }

                // Save favicon content if it exists
                if (file_exists($webRoot . '/app/images/favicon.ico')) {
                    $savedFavicon = file_get_contents($webRoot . '/app/images/favicon.ico');
                }

                // Save logo file contents if they exist
                $logoFiles = glob($webRoot . '/app/images/logo-*') ?: [];
                foreach ($logoFiles as $logoFile) {
                    $savedLogos[basename($logoFile)] = file_get_contents($logoFile);
                }
            }

            // ---- Step 5: Shelter directories that must survive extraction ----
            if (!$upgradeFailed) {
                // Move .git out of the way so tar can't overwrite it
                if (is_dir($webRoot . '/.git') && !is_link($webRoot . '/.git')) {
                    if (@rename($webRoot . '/.git', $webRoot . '/.git.upgrade_shelter')) {
                        $shelteredGit = true;
                    }
                }
                // Move .claude out of the way
                if (is_dir($webRoot . '/.claude') && !is_link($webRoot . '/.claude')) {
                    if (@rename($webRoot . '/.claude', $webRoot . '/.claude.upgrade_shelter')) {
                        $shelteredClaude = true;
                    }
                }
                // Move db_backup out of the way -- contains database backup SQL files
                if (is_dir($webRoot . '/db_backup') && !is_link($webRoot . '/db_backup')) {
                    if (@rename($webRoot . '/db_backup', $webRoot . '/db_backup.upgrade_shelter')) {
                        $shelteredDbBackup = true;
                    }
                }
            }

            // ---- Step 6: Extract archive to temp dir, then copy to web root ----
            // Uses only POSIX-compatible flags (no --strip-components, --overwrite, etc.)
            $tempDir = null;
            if (!$upgradeFailed && $tarPath) {
                $tempDir = sys_get_temp_dir() . '/tprm_upgrade_' . time() . '_' . getmypid();
                if (!@mkdir($tempDir, 0700, true)) {
                    $error = t('admin_version.err_temp_extract_dir');
                    $upgradeLog[] = ['step' => 'Extract archive', 'status' => 'error', 'detail' => 'Could not create temp directory'];
                    $upgradeFailed = true;
                    $tempDir = null;
                }
            }

            // Extract to temp dir
            if (!$upgradeFailed && $tempDir) {
                list($tarOut, $tarCode) = runShellCommand(['tar', '-xzf', $tarPath, '-C', $tempDir], $webRoot);
                if ($tarCode !== 0) {
                    $error = t('admin_version.err_extract_archive');
                    $upgradeLog[] = ['step' => 'Extract archive', 'status' => 'error', 'detail' => implode("\n", $tarOut)];
                    runShellCommand(['rm', '-rf', $tempDir], $webRoot);
                    $tempDir = null;
                    $upgradeFailed = true;
                } else {
                    // Detect wrapper directory: if archive has a single top-level dir, use it
                    $sourceDir = $tempDir;
                    $entries = @scandir($tempDir);
                    if ($entries !== false) {
                        $entries = array_values(array_diff($entries, ['.', '..']));
                        if (count($entries) === 1 && is_dir($tempDir . '/' . $entries[0])) {
                            $sourceDir = $tempDir . '/' . $entries[0];
                            $upgradeLog[] = ['step' => 'Extract archive', 'status' => 'ok', 'detail' => 'Extracted (wrapper: ' . $entries[0] . '/)'];
                        } else {
                            $upgradeLog[] = ['step' => 'Extract archive', 'status' => 'ok', 'detail' => 'Extracted (no wrapper)'];
                        }
                    }

                    // Copy files individually to handle symlink-to-directory conflicts
                    list($findSrcOut, $findSrcCode) = runShellCommand(['find', $sourceDir, '-type', 'f', '-o', '-type', 'l'], $sourceDir);
                    $copyCount = 0;
                    $copyErrors = [];
                    foreach (array_filter($findSrcOut) as $srcFile) {
                        $srcFile = trim($srcFile);
                        if ($srcFile === '') continue;
                        // Skip symlinks pointing to directories (persistent mount points)
                        if (is_link($srcFile) && is_dir($srcFile)) continue;
                        $relPath = substr($srcFile, strlen($sourceDir));
                        $targetFile = $webRoot . $relPath;
                        $targetDir = dirname($targetFile);
                        if (!is_dir($targetDir)) {
                            @mkdir($targetDir, 0755, true);
                        }
                        if (file_exists($targetFile) || is_link($targetFile)) {
                            @unlink($targetFile);
                        }
                        if (@copy($srcFile, $targetFile)) {
                            @chmod($targetFile, fileperms($srcFile) & 0777);
                            $copyCount++;
                        } else {
                            $copyErrors[] = 'Failed to copy: ' . $relPath;
                        }
                    }
                    if (!empty($copyErrors)) {
                        $error = t('admin_version.err_copy_files_webroot');
                        $upgradeLog[] = ['step' => 'Copy files', 'status' => 'error', 'detail' => implode("\n", $copyErrors)];
                        $upgradeFailed = true;
                    } else {
                        $upgradeLog[] = ['step' => 'Copy files', 'status' => 'ok', 'detail' => $copyCount . ' file(s) applied to web root'];
                    }

                    // Clean up temp dir (rm -rf is safe -- does not follow symlinks)
                    runShellCommand(['rm', '-rf', $tempDir], $webRoot);
                    $tempDir = null;
                }
            }

            // ---- Step 7: Restore sheltered directories ----
            // Always restore these, even if extraction failed
            if ($shelteredGit) {
                // If tar extracted a .git, remove it first
                if (is_dir($webRoot . '/.git') || is_link($webRoot . '/.git')) {
                    runShellCommand(['rm', '-rf', $webRoot . '/.git'], $webRoot);
                }
                @rename($webRoot . '/.git.upgrade_shelter', $webRoot . '/.git');
            }
            if ($shelteredClaude) {
                // If tar extracted a .claude, remove it first
                if (is_dir($webRoot . '/.claude') || is_link($webRoot . '/.claude')) {
                    runShellCommand(['rm', '-rf', $webRoot . '/.claude'], $webRoot);
                }
                @rename($webRoot . '/.claude.upgrade_shelter', $webRoot . '/.claude');
            }
            if ($shelteredDbBackup) {
                // If tar extracted a db_backup, remove it first
                if (is_dir($webRoot . '/db_backup') || is_link($webRoot . '/db_backup')) {
                    runShellCommand(['rm', '-rf', $webRoot . '/db_backup'], $webRoot);
                }
                @rename($webRoot . '/db_backup.upgrade_shelter', $webRoot . '/db_backup');
            }

            // ---- Step 8: Handle protected files ----
            $protected = [];
            if (!$upgradeFailed) {
                // .git, .claude, and db_backup were sheltered
                $protected[] = '.git';
                $protected[] = '.claude';
                $protected[] = 'db_backup';

                // Restore config/config.php -- never overwrite site-specific config
                if ($savedConfig !== null) {
                    if (!is_dir($webRoot . '/config')) {
                        mkdir($webRoot . '/config', 0755, true);
                    }
                    file_put_contents($webRoot . '/config/config.php', $savedConfig);
                    $protected[] = 'config/config.php';
                }

                // Don't restore setup.php if it didn't already exist
                if (!$setupExisted && file_exists($webRoot . '/setup.php')) {
                    unlink($webRoot . '/setup.php');
                    $protected[] = 'setup.php';
                }

                // Restore saved favicon (overwrite whatever tar extracted)
                if ($savedFavicon !== null) {
                    file_put_contents($webRoot . '/app/images/favicon.ico', $savedFavicon);
                    $protected[] = 'favicon.ico';
                }

                // Restore saved logo files (overwrite whatever tar extracted)
                foreach ($savedLogos as $basename => $content) {
                    $logoPath = $webRoot . '/app/images/' . $basename;
                    file_put_contents($logoPath, $content);
                    $protected[] = $basename;
                }

                $protectedStr = !empty($protected) ? ' (preserved: ' . implode(', ', $protected) . ')' : '';
                $upgradeLog[] = ['step' => 'Protect files', 'status' => 'ok', 'detail' => 'File protections applied' . $protectedStr];

                // Update VERSION file
                file_put_contents($webRoot . '/VERSION', 'v' . $selectedVersion);
            }

            // ---- Cleanup: delete downloaded archive ----
            if ($tarPath !== null && file_exists($tarPath)) {
                @unlink($tarPath);
            }

            // ---- Step 9: Apply SQL migration ----
            if (!$upgradeFailed) {
                $sqlFile = $webRoot . '/sql_updates/v' . $selectedVersion . '.sql';
                if (!file_exists($sqlFile)) {
                    $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'skip', 'detail' => 'No migration file found: v' . $selectedVersion . '.sql'];
                } else {
                    // Check applied_updates.json
                    $logFile = $webRoot . '/sql_updates/applied_updates.json';
                    $appliedLog = [];
                    if (file_exists($logFile)) {
                        $logData = json_decode(file_get_contents($logFile), true);
                        if (is_array($logData)) {
                            $appliedLog = $logData;
                        }
                    }

                    $migrationFilename = 'v' . $selectedVersion . '.sql';
                    $alreadyApplied = false;
                    foreach ($appliedLog as $entry) {
                        if ($entry['filename'] === $migrationFilename) {
                            $alreadyApplied = true;
                            break;
                        }
                    }

                    if ($alreadyApplied) {
                        $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'skip', 'detail' => $migrationFilename . ' was already applied'];
                    } else {
                        // Apply the migration
                        try {
                            $sqlContent = file_get_contents($sqlFile);
                            if ($sqlContent === false || trim($sqlContent) === '') {
                                throw new Exception('Unable to read migration file or file is empty');
                            }

                            $dbConfig = $config->get('database');
                            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                                $dbConfig['host'],
                                $dbConfig['port'] ?? 3306,
                                $dbConfig['database'],
                                $dbConfig['charset'] ?? 'utf8mb4'
                            );
                            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            ]);

                            $cleanSql = preg_replace('/--.*$/m', '', $sqlContent);
                            $cleanSql = preg_replace('/\/\*.*?\*\//s', '', $cleanSql);
                            $cleanSql = preg_replace('/^\s*DELIMITER\s+.*$/mi', '', $cleanSql);
                            $statements = array_filter(array_map('trim', explode(';', $cleanSql)));

                            $stmtCount = 0;
                            $stmtNotices = [];
                            foreach ($statements as $stmt) {
                                if (empty($stmt)) continue;
                                try {
                                    $pdo->exec($stmt);
                                    $stmtCount++;
                                } catch (PDOException $stmtEx) {
                                    $errCode = isset($stmtEx->errorInfo[1]) ? (int)$stmtEx->errorInfo[1] : 0;
                                    if (in_array($errCode, [1060, 1061, 1050, 1068])) {
                                        $stmtNotices[] = $stmtEx->getMessage();
                                        error_log('Version upgrade SQL notice (ignorable): ' . $stmtEx->getMessage());
                                        $stmtCount++;
                                    } else {
                                        throw $stmtEx;
                                    }
                                }
                            }

                            // Log to applied_updates.json
                            $appliedLog[] = [
                                'filename'   => $migrationFilename,
                                'applied_at' => date('Y-m-d H:i:s'),
                                'applied_by' => $user['username'] ?? 'unknown',
                                'file_size'  => filesize($sqlFile),
                            ];
                            file_put_contents($logFile, json_encode($appliedLog, JSON_PRETTY_PRINT));

                            $detail = $stmtCount . ' statement(s) executed';
                            if (!empty($stmtNotices)) {
                                $detail .= ', ' . count($stmtNotices) . ' ignorable notice(s)';
                            }
                            $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'ok', 'detail' => $detail];
                        } catch (Exception $e) {
                            error_log('Version upgrade SQL error: ' . $e->getMessage());
                            $upgradeLog[] = ['step' => 'SQL migration', 'status' => 'error', 'detail' => 'Migration failed: ' . $e->getMessage()];
                            $upgradeFailed = true;
                        }
                    }
                }
            }

            // Audit log
            $auth->audit($user['id'], 'version_upgrade', 'system', null, [
                'new' => [
                    'target_version' => 'v' . $selectedVersion,
                    'success' => !$upgradeFailed,
                    'steps' => count($upgradeLog),
                ]
            ]);

            if (!$upgradeFailed) {
                postUpgradeRefresh($upgradeLog);
                $success = t('admin_version.success_upgrade_prefix') . $selectedVersion . t('admin_version.success_completed_suffix');
            }
        }
    }
}

// Last applied migration
$lastApplied = null;
$logFile = $webRoot . '/sql_updates/applied_updates.json';
if (file_exists($logFile)) {
    $logData = json_decode(file_get_contents($logFile), true);
    if (is_array($logData) && !empty($logData)) {
        $lastApplied = end($logData);
    }
}

// ============================================================================
// HTML: Non-Docker Version Management UI
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_version.page_title')); ?></h1>
    <p><?php echo e(t('admin_version.subtitle_nondocker')); ?></p>
</div>

<div class="card">
    <h3><?php echo e(t('admin_version.current_status_heading')); ?></h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.installed_version_label')); ?></div>
            <div style="font-size: 16px; font-weight: 600; color: #333;">
                <span style="display: inline-block; padding: 2px 10px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 14px;"><?php echo e($currentVersion); ?></span>
            </div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.latest_available_label')); ?></div>
            <div style="font-size: 14px; color: #333;">
                <?php if ($versionsFetchError): ?>
                    <span style="color: #991b1b;"><?php echo e(t('admin_version.could_not_fetch_version')); ?></span>
                <?php elseif (!empty($availableVersions)): ?>
                    <?php
                    $latest = end($availableVersions);
                    $latestDisplay = 'v' . $latest['version'];
                    $isUpToDate = ($currentVersion === $latestDisplay);
                    ?>
                    <?php if ($isUpToDate): ?>
                        <span style="color: #166534;">v<?php echo e($latest['version']); ?> <?php echo e(t('admin_version.up_to_date_suffix')); ?></span>
                    <?php else: ?>
                        <span style="color: #b45309;">v<?php echo e($latest['version']); ?> <?php echo e(t('admin_version.update_available_suffix')); ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: #999;"><?php echo e(t('admin_version.no_versions_found')); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;"><?php echo e(t('admin_version.last_migration_label')); ?></div>
            <div style="font-size: 14px; color: #333;">
                <?php if ($lastApplied): ?>
                    <strong><?php echo e($lastApplied['filename']); ?></strong>
                    <span style="color: #666; font-size: 12px;"><?php echo e(t('admin_version.migration_on_label')); ?> <?php echo e($lastApplied['applied_at']); ?> <?php echo e(t('admin_version.migration_by_label')); ?> <?php echo e($lastApplied['applied_by']); ?></span>
                <?php else: ?>
                    <span style="color: #999;"><?php echo e(t('admin_version.none_label')); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($availableVersions)): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.available_versions_heading')); ?></h3>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_version.th_version')); ?></th>
                <th><?php echo e(t('admin_version.th_release_date')); ?></th>
                <th><?php echo e(t('admin_version.th_archive')); ?></th>
                <th><?php echo e(t('admin_version.th_status')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($availableVersions) as $ver): ?>
            <tr>
                <td><strong>v<?php echo e($ver['version']); ?></strong></td>
                <td><?php echo e($ver['date']); ?></td>
                <td style="font-size: 12px; color: #666;"><?php echo e($ver['filename']); ?></td>
                <td>
                    <?php if ($currentVersion === 'v' . $ver['version']): ?>
                        <span class="badge badge-success"><?php echo e(t('admin_version.badge_installed')); ?></span>
                    <?php else: ?>
                        <span class="badge badge-blue"><?php echo e(t('admin_version.badge_available')); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo e(t('admin_version.version_upgrade_heading')); ?></h3>
    <?php if ($versionsFetchError): ?>
        <div class="alert alert-danger"><?php echo e(t('admin_version.unable_fetch_versions_alert')); ?></div>
    <?php elseif (empty($availableVersions)): ?>
        <div class="alert alert-danger"><?php echo e(t('admin_version.no_versions_available_alert')); ?></div>
    <?php else: ?>
        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <strong style="color: #92400e;"><?php echo e(t('admin_version.important_label')); ?></strong>
            <span style="font-size: 13px; color: #78350f;">
                <?php echo e(t('admin_version.upgrade_important_note')); ?>
            </span>
        </div>

        <div class="form-group">
            <label for="target_version"><?php echo e(t('admin_version.target_version_label')); ?></label>
            <select id="target_version" class="form-control" style="max-width: 300px;">
                <option value=""><?php echo e(t('admin_version.select_version_option')); ?></option>
                <?php foreach (array_reverse($availableVersions) as $ver): ?>
                    <option value="<?php echo e($ver['version']); ?>" data-filename="<?php echo e($ver['filename']); ?>">
                        v<?php echo e($ver['version']); ?> (<?php echo e($ver['date']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-help"><?php echo e(t('admin_version.target_version_help')); ?></div>
        </div>

        <button type="button" class="btn btn-primary" id="openUpgradeModalBtn" disabled><?php echo e(t('admin_version.upgrade_button')); ?></button>
    <?php endif; ?>
</div>

<?php if ($upgradeLog !== null): ?>
<div class="card">
    <h3><?php echo e(t('admin_version.upgrade_log_heading')); ?></h3>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_version.th_step')); ?></th>
                <th><?php echo e(t('admin_version.th_status')); ?></th>
                <th><?php echo e(t('admin_version.th_details')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($upgradeLog as $entry): ?>
            <tr>
                <td><strong><?php echo e($entry['step']); ?></strong></td>
                <td>
                    <?php if ($entry['status'] === 'ok'): ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #dcfce7; color: #166534; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_ok')); ?></span>
                    <?php elseif ($entry['status'] === 'skip'): ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_skipped')); ?></span>
                    <?php else: ?>
                        <span style="display: inline-block; padding: 2px 8px; background: #fef2f2; color: #991b1b; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_version.status_error')); ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size: 13px; color: #555; white-space: pre-line;"><?php echo e($entry['detail']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Upgrade Confirmation Modal -->
<div id="upgradeModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" id="closeUpgradeModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_version.confirm_version_upgrade_heading')); ?></h3>

        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: start; gap: 10px;">
                <span style="font-size: 20px;">&#9888;</span>
                <div>
                    <strong style="color: #92400e;"><?php echo e(t('admin_version.modify_files_heading')); ?></strong>
                    <p style="margin: 5px 0 0; font-size: 13px; color: #78350f;">
                        <?php echo e(t('admin_version.modify_files_detail')); ?>
                    </p>
                </div>
            </div>
        </div>

        <p style="color: #666; margin-bottom: 15px;">
            <?php echo e(t('admin_version.upgrading_to_label')); ?> <strong id="upgrade_version_display" style="color: #333;"></strong>
        </p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="upgrade_confirm_input" style="font-weight: 500;"><?php echo t('admin_version.type_upgrade_confirm_label'); ?></label>
            <input type="text" id="upgrade_confirm_input" class="form-control" placeholder="<?php echo e(t('admin_version.type_upgrade_confirm_placeholder')); ?>" autocomplete="off">
        </div>

        <form method="POST" action="admin.php?section=version" id="upgradeForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="upgrade_target_version" name="target_version" value="">
            <input type="hidden" id="upgrade_target_filename" name="target_filename" value="">
            <input type="hidden" name="perform_version_upgrade" value="1">

            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary" id="cancelUpgradeBtn"><?php echo e(t('admin_version.cancel_button')); ?></button>
                <button type="button" id="confirmUpgradeBtn" class="btn btn-primary" disabled><?php echo e(t('admin_version.upgrade_button')); ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var targetSelect = document.getElementById('target_version');
    var openBtn = document.getElementById('openUpgradeModalBtn');
    var modal = document.getElementById('upgradeModal');
    var closeBtn = document.getElementById('closeUpgradeModal');
    var cancelBtn = document.getElementById('cancelUpgradeBtn');
    var confirmInput = document.getElementById('upgrade_confirm_input');
    var confirmBtn = document.getElementById('confirmUpgradeBtn');
    var versionDisplay = document.getElementById('upgrade_version_display');
    var hiddenVersion = document.getElementById('upgrade_target_version');
    var hiddenFilename = document.getElementById('upgrade_target_filename');
    var form = document.getElementById('upgradeForm');

    if (targetSelect && openBtn) {
        targetSelect.addEventListener('change', function() {
            openBtn.disabled = !this.value;
        });
    }

    function openModal() {
        if (!targetSelect || !targetSelect.value) return;
        var selected = targetSelect.options[targetSelect.selectedIndex];
        versionDisplay.textContent = 'v' + targetSelect.value;
        hiddenVersion.value = targetSelect.value;
        hiddenFilename.value = selected.getAttribute('data-filename') || '';
        confirmInput.value = '';
        confirmBtn.disabled = true;
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
        confirmInput.value = '';
        confirmBtn.disabled = true;
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            confirmBtn.disabled = (this.value !== 'UPGRADE');
        });
    }

    if (confirmBtn && form) {
        confirmBtn.addEventListener('click', function() {
            if (confirmInput.value !== 'UPGRADE') return;
            confirmBtn.disabled = true;
            confirmBtn.textContent = <?php echo json_encode(t('admin_version.js_upgrading_progress')); ?>;
            form.submit();
        });
    }
})();
</script>
<?php endif; // end Docker vs non-Docker ?>
