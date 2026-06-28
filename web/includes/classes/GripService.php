<?php
/**
 * GripService - Grip Security public API client + Shadow SaaS ingest.
 *
 * Talks to the Grip Security public SaaS API (default base
 * https://tenant.dep.grip.security/public/saas) and hydrates the
 * shadow_saas_grip_* mirror tables, then projects normalized rows into
 * the existing shadow_saas table so the existing UI can render Grip data
 * with no further wiring.
 *
 * Auth: header "access-token: <token>". This is what the working reference
 * Python client uses; Bearer/X-API-Key/Basic all return 401 on this API.
 *
 * Documented endpoints (apidocs.grip.security):
 *   GET  /public/saas                - list SaaS apps (paginated)
 *   GET  /public/saas/{id}           - get one SaaS by Grip id
 *   GET  /public/saas/name/{name}    - find SaaS id by name
 *   GET  /public/users               - list users
 *   GET  /public/alerts              - list alerts
 */

class GripService
{
    private const DEFAULT_BASE_URL = 'https://tenant.dep.grip.security/public/saas';
    private const PAGE_SIZE = 200;
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    // Transient-failure handling: how many times to retry a timed-out / 429 / 5xx
    // request, and the exponential backoff base (ms). Backoff is capped and jittered.
    private const MAX_RETRIES = 3;
    private const BACKOFF_BASE_MS = 250;
    private const BACKOFF_CAP_MS = 8000;
    // Gentle pacing between per-app roster fetches so a full hydration does not
    // hammer Grip's API. Skipped (delta) apps incur no call and no delay.
    private const ROSTER_THROTTLE_MS = 100;

    private Database $db;
    private string $baseUrl = '';
    private string $apiToken = '';
    private bool $enabled = false;
    private bool $breachSyncEnabled = false;
    /** Data source mode: 'live' (query API per view) or 'local' (serve hydrated DB snapshot). */
    private string $dataSource = 'live';
    /** Per-run cache of grip_id => resolved registrable domain. */
    private array $appDomainCache = [];
    /** Lazily-built Encryption helper (keyed from config.php encryption.key). */
    private ?Encryption $enc = null;

    // PII columns stored ENCRYPTED-AT-REST in each Grip mirror table. Encrypted on
    // write (GripService), decrypted on read via decryptGripRow(). These hold
    // personal data (emails, names, org units, manager, and the full raw payload),
    // so they are never persisted in plaintext. The encrypted blob is base64 and
    // larger than the source, so the underlying columns are TEXT (see v2.6.2.sql).
    // NOTE: aggregate/app-level fields (counts, dates, categories, app name/domain)
    // are NOT personal data and stay plaintext so they remain sortable/searchable.
    private const ENCRYPTED_COLUMNS = [
        'shadow_saas_grip_users'     => ['mail', 'full_name', 'display_name', 'organizational_unit', 'manager_email', 'aliases', 'custom_fields', 'raw_payload'],
        'shadow_saas_grip_app_users' => ['mail', 'full_name', 'display_name', 'organizational_unit', 'manager_email'],
        'shadow_saas_grip_apps'      => ['primary_contact_name', 'primary_contact_email', 'primary_contact_user_id', 'business_owner', 'raw_payload'],
        'shadow_saas_grip_alerts'    => ['raw_payload'],
    ];

    /** Shared Encryption instance (key comes from config.php, same as everywhere else). */
    private function enc(): Encryption
    {
        return $this->enc ??= new Encryption();
    }

    /**
     * Encrypt the PII columns of a row for a given mirror table, in place.
     * Null/empty values are left untouched. Safe to call right before insert/upsert.
     */
    private function encryptRow(string $table, array $row): array
    {
        foreach (self::ENCRYPTED_COLUMNS[$table] ?? [] as $col) {
            if (array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '') {
                $row[$col] = $this->enc()->encrypt((string)$row[$col]);
            }
        }
        return $row;
    }

    /**
     * Decrypt the PII columns of a row read back from a mirror table, in place.
     * Tolerant: a value that isn't valid ciphertext (e.g. legacy plaintext written
     * before encryption shipped) is left exactly as-is rather than throwing, so a
     * mixed table never breaks a page render. Pages call this on each DB-read row.
     */
    public function decryptGripRow(string $table, array $row): array
    {
        foreach (self::ENCRYPTED_COLUMNS[$table] ?? [] as $col) {
            if (isset($row[$col]) && $row[$col] !== '') {
                try { $row[$col] = $this->enc()->decrypt((string)$row[$col]); }
                catch (Throwable $e) { /* not ciphertext (legacy plaintext) — keep original */ }
            }
        }
        return $row;
    }

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $encryption = new Encryption();
        $rows = $this->db->fetchAll(
            'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
            ['grip_%']
        );
        foreach ($rows as $row) {
            $val = ($row['is_encrypted'] && !empty($row['config_value']))
                ? $encryption->decrypt($row['config_value'])
                : $row['config_value'];
            switch ($row['config_key']) {
                case 'grip_enabled':            $this->enabled = ($val === '1'); break;
                case 'grip_base_url':           $this->baseUrl = is_string($val) ? trim($val) : ''; break;
                case 'grip_api_token':          $this->apiToken = is_string($val) ? trim($val) : ''; break;
                case 'grip_breach_sync_enabled':$this->breachSyncEnabled = ($val === '1'); break;
                case 'grip_data_source':        $this->dataSource = ($val === 'local') ? 'local' : 'live'; break;
            }
        }
        if ($this->baseUrl === '') $this->baseUrl = self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function isConfigured(): bool { return $this->apiToken !== '' && $this->baseUrl !== ''; }
    public function isEnabled(): bool { return $this->enabled && $this->isConfigured(); }
    public function getBaseUrl(): string { return $this->baseUrl; }

    /** Configured data source: 'live' (per-view API calls) or 'local' (hydrated DB snapshot). */
    public function dataSource(): string { return $this->dataSource; }
    /** True when the UI should read the hydrated DB snapshot instead of calling the live API. */
    public function useLocalData(): bool { return $this->dataSource === 'local'; }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * Call a Grip endpoint. $path is appended to base URL - pass '' for the
     * SaaS list endpoint, or '/users', '/alerts', '/{id}' as relative paths
     * (rooted at /public for /public/users and /public/alerts).
     */
    private function request(string $relativeUrl, array $query = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'http_code' => 0, 'error' => 'Grip is not configured', 'data' => null];
        }
        $url = $this->buildUrl($relativeUrl, $query);

        $attempt = 0;
        while (true) {
            $retryAfter = null; // seconds, parsed from a Retry-After response header
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'access-token: ' . $this->apiToken,
                    'User-Agent: FairTPRM-Grip-Integration/1.0',
                ],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                    if (stripos($header, 'Retry-After:') === 0) {
                        $val = trim(substr($header, 12));
                        if (is_numeric($val)) {
                            $retryAfter = (int)$val;
                        } elseif (($ts = strtotime($val)) !== false) {
                            $retryAfter = max(0, $ts - time());
                        }
                    }
                    return strlen($header);
                },
            ]);
            $body  = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // curl_close() is a deprecated no-op since PHP 8.0; the handle is freed
            // when $ch goes out of scope. Unset to be explicit without the warning.
            unset($ch);

            // A timeout or dropped connection is worth retrying; so are 429 and 5xx.
            // Numeric libcurl error codes: 28 operation timed out, 7 couldn't connect,
            // 52 got nothing, 56 recv error, 55 send error, 35 SSL connect error.
            $timedOut  = in_array($errno, [28, 7, 52, 56, 55, 35], true);
            $transient = $timedOut || $code === 429 || ($code >= 500 && $code < 600);

            if ($transient && $attempt < self::MAX_RETRIES) {
                $attempt++;
                if ($retryAfter !== null) {
                    $waitMs = min($retryAfter * 1000, self::BACKOFF_CAP_MS * 2);
                } else {
                    $waitMs = min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * (1 << ($attempt - 1)));
                    $waitMs += random_int(0, (int)($waitMs / 2)); // jitter
                }
                usleep($waitMs * 1000);
                continue;
            }

            if ($body === false) {
                return [
                    'success' => false, 'http_code' => $code,
                    'error' => $err ?: 'cURL error', 'data' => null,
                    'timed_out' => $timedOut, 'attempts' => $attempt + 1,
                ];
            }
            $decoded = json_decode($body, true);
            $jsonOk = (json_last_error() === JSON_ERROR_NONE);

            return [
                'success'   => ($code >= 200 && $code < 300 && $jsonOk),
                'http_code' => $code,
                'error'     => $jsonOk ? '' : ('Invalid JSON: ' . json_last_error_msg()),
                'data'      => $jsonOk ? $decoded : null,
                'timed_out' => false,
                'attempts'  => $attempt + 1,
            ];
        }
    }

    /**
     * The user's BASE_URL points at /public/saas. Sibling endpoints
     * (/public/users, /public/alerts) hang off /public, so we resolve
     * relative paths starting with '/public/' against the host root and
     * everything else against the SaaS base URL.
     */
    private function buildUrl(string $relativeUrl, array $query): string
    {
        $relativeUrl = (string)$relativeUrl;
        if ($relativeUrl !== '' && strpos($relativeUrl, '/public/') === 0) {
            $parts = parse_url($this->baseUrl);
            $host = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) $host .= ':' . $parts['port'];
            $url = $host . $relativeUrl;
        } else {
            $url = $this->baseUrl . $relativeUrl;
        }
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        return $url;
    }

    public function testConnection(): array
    {
        $res = $this->request('', ['offset' => 0, 'limit' => 1]);
        if (!$res['success']) {
            $reason = $res['error'] ?: ('HTTP ' . $res['http_code']);
            if ($res['http_code'] === 401) $reason = 'Unauthorized — token rejected';
            if ($res['http_code'] === 404) $reason = 'Endpoint not found — check base URL';
            return ['success' => false, 'message' => $reason];
        }
        $count = is_array($res['data']) ? count($res['data']) : 0;
        return ['success' => true, 'message' => "Connected to Grip — sample returned {$count} record(s)"];
    }

    // ------------------------------------------------------------------
    // Pagination helpers
    // ------------------------------------------------------------------

    /** @return array<int,array> All items across pages. */
    private function fetchAll(string $relativeUrl): array
    {
        $offset = 0;
        $all = [];
        while (true) {
            $res = $this->request($relativeUrl, ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            if (!$res['success']) {
                throw new RuntimeException("Grip fetch {$relativeUrl} failed at offset {$offset}: " . ($res['error'] ?: 'HTTP ' . $res['http_code']));
            }
            $page = is_array($res['data']) ? $res['data'] : [];
            if (empty($page)) break;
            foreach ($page as $row) $all[] = $row;
            if (count($page) < self::PAGE_SIZE) break;
            $offset += self::PAGE_SIZE;
        }
        return $all;
    }

    // ------------------------------------------------------------------
    // Domain normalization
    // ------------------------------------------------------------------

    /**
     * Reduce a URL/host to its registrable domain.
     *   https://www.united.com         -> united.com
     *   http://color.redhat.com        -> redhat.com
     *   https://api.foo.bar.example.io -> example.io
     *   https://foo.example.co.uk      -> example.co.uk
     */
    public static function extractDomain(?string $urlOrHost): string
    {
        if (!$urlOrHost) return '';
        $s = trim($urlOrHost);
        if ($s === '') return '';
        if (strpos($s, '://') === false) $s = '//' . $s;
        $parts = @parse_url($s);
        if (!$parts || empty($parts['host'])) {
            $s = ltrim($s, '/');
            $parts = ['host' => preg_replace('#[/?#].*$#', '', $s)];
        }
        $host = strtolower($parts['host']);
        if (strpos($host, ':') !== false) $host = explode(':', $host, 2)[0];
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        if (filter_var($host, FILTER_VALIDATE_IP)) return $host;

        $labels = array_values(array_filter(explode('.', $host), fn($l) => $l !== ''));
        $n = count($labels);
        if ($n <= 2) return implode('.', $labels);

        // Two-label public suffixes we care about - keep last 3 labels for these.
        $multiLabelTlds = [
            'co.uk','org.uk','ac.uk','gov.uk','com.au','net.au','org.au','co.nz',
            'co.jp','com.br','com.mx','co.in','co.za','com.sg','com.cn',
        ];
        $lastTwo = $labels[$n - 2] . '.' . $labels[$n - 1];
        if (in_array($lastTwo, $multiLabelTlds, true) && $n >= 3) {
            return $labels[$n - 3] . '.' . $lastTwo;
        }
        return $labels[$n - 2] . '.' . $labels[$n - 1];
    }

    // ------------------------------------------------------------------
    // Sync
    // ------------------------------------------------------------------

    /**
     * Run a full hydration: pull SaaS / users / alerts, upsert mirror tables,
     * project SaaS rows into shadow_saas. Returns a summary array.
     */
    public function syncAll(string $triggerSource = 'cron', ?int $userId = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Grip is not configured'];
        }

        $logId = $this->openSyncLog($triggerSource, $userId);
        $appsCount = $usersCount = $alertsCount = $shadowUpserts = 0;
        $appsRemoved = $usersRemoved = $alertsRemoved = $shadowRemoved = 0;
        $breachesCreated = 0;
        try {
            // Hydrated (cached) mode refreshes the Grip mirror from the API on every
            // run. The bulk single-fetch tables (users, alerts) are cheap to rebuild
            // wholesale, so we truncate them for an exact reflection. The APPS and
            // per-app ROSTER tables are NOT truncated: they carry the delta state
            // (roster_signature) and the cached rosters that let the next sync SKIP
            // re-fetching unchanged apps - wiping them would force a full ~1-call-per-app
            // re-hydration every run. Apps/app_users staleness is instead handled by
            // upsert + tombstone (apps: last_synced_at; app_users: orphan cleanup below).
            // shadow_saas itself is left intact so onboarded/dismissed admin state survives.
            if ($this->useLocalData()) {
                foreach (['shadow_saas_grip_alerts', 'shadow_saas_grip_users'] as $mirror) {
                    try { $this->db->query("TRUNCATE TABLE `{$mirror}`"); }
                    catch (Throwable $e) { error_log("Grip hydrate truncate {$mirror} failed: " . $e->getMessage()); }
                }
            }

            // Use the DB clock as the tombstone reference so PHP/MySQL
            // clock skew can't trip the cleanup. Grab it BEFORE any upsert
            // bumps last_synced_at on the touched rows.
            $nowRow   = $this->db->fetchOne('SELECT NOW() AS now');
            $runStart = $nowRow['now'] ?? date('Y-m-d H:i:s');

            $apps = $this->fetchAll('');
            $appsCount = $this->upsertApps($apps);
            $shadowResult = $this->projectAppsToShadowSaas($apps, $userId);
            $shadowUpserts   = (int)($shadowResult['upserts'] ?? 0);
            $shadowGripIds   = $shadowResult['grip_ids'] ?? [];

            // SecurityScorecard ratings for onboarded vendors (matched by domain to
            // a Grip app). Onboarded vendors are excluded from the Shadow SaaS list,
            // so their SSC rating is stamped on vendor_onboarding_requests directly.
            try { $this->enrichVendorScorecardRatings($apps); }
            catch (Throwable $e) { error_log('Grip vendor SSC enrich failed: ' . $e->getMessage()); }

            // Cooperative cancellation: an admin can abort a running sync from the
            // Last Sync card, which flips this run's status to 'aborting'.
            if ($this->isCancelled($logId)) {
                $this->closeSyncLog($logId, 'aborted', $appsCount, $usersCount, $alertsCount, $shadowUpserts, 'Aborted by admin.');
                return ['success' => false, 'error' => 'aborted', 'log_id' => $logId, 'aborted' => true];
            }

            $usersData = $alertsData = [];
            try { $usersData  = $this->fetchAll('/public/users');   $usersCount  = $this->upsertUsers($usersData); }   catch (Throwable $e) { error_log('Grip users fetch failed: ' . $e->getMessage()); }
            try { $alertsData = $this->fetchAll('/public/alerts');  $alertsCount = $this->upsertAlerts($alertsData); } catch (Throwable $e) { error_log('Grip alerts fetch failed: ' . $e->getMessage()); }

            // Tombstone cleanup: any row in a mirror table whose last_synced_at
            // pre-dates this run is no longer present in Grip. Skip cleanup
            // when the corresponding fetch returned zero rows - that usually
            // means a partial / failed pull and we don't want a bad fetch to
            // wipe the mirror.
            if (!empty($apps)) {
                $appsRemoved = $this->db->delete('shadow_saas_grip_apps', 'last_synced_at < :ts', [':ts' => $runStart]);
            }
            if (!empty($usersData)) {
                $usersRemoved = $this->db->delete('shadow_saas_grip_users', 'last_synced_at < :ts', [':ts' => $runStart]);
            }
            if (!empty($alertsData)) {
                $alertsRemoved = $this->db->delete('shadow_saas_grip_alerts', 'last_synced_at < :ts', [':ts' => $runStart]);
            }

            // shadow_saas tombstone: only drop pending Grip-projected rows
            // whose grip_id wasn't seen this run. Onboarded / dismissed rows
            // survive even after Grip drops the underlying app, so an admin's
            // workflow state is never silently lost.
            if (!empty($apps) && !empty($shadowGripIds)) {
                $placeholders = [];
                $params = [];
                foreach ($shadowGripIds as $i => $gid) {
                    $key = ':gid' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $gid;
                }
                $shadowRemoved = $this->db->delete(
                    'shadow_saas',
                    "source = 'grip' AND status = 'pending' AND grip_id IS NOT NULL AND grip_id NOT IN (" . implode(',', $placeholders) . ')',
                    $params
                );
            }

            // PER-APP USER HYDRATION (Local/Hydrated mode only).
            //
            // In Local mode the UI serves the per-app roster exclusively from
            // shadow_saas_grip_app_users and never calls the API on a page view, so
            // the sync must snapshot the roster for every app that has users (not
            // just breached apps, which the breach projection below already covers).
            //
            // DELTA hydration: an app's roster is only re-fetched when it actually
            // changed. We key on a roster signature (user count + latest/last-usage
            // timestamps from the /saas list - already in hand, no extra call). When
            // the signature matches the stored one AND we still hold a roster for the
            // app, the cached rows are kept and NO API call is made. This drops a
            // repeat sync from ~one-call-per-app to just the changed apps - far less
            // taxing on Grip and much faster. Actual fetches are gently throttled.
            // Cooperative-cancellable; per-app failures are logged, counted, and
            // never abort the run (and never wipe a good cached roster).
            $rosterFetched = $rosterSkipped = $rosterFailed = 0;
            if ($this->useLocalData() && !empty($apps)) {
                // Worklist: apps that have users, mapped to their current signature.
                $work = [];
                foreach ($apps as $app) {
                    $appGid = (string)($app['id'] ?? '');
                    $g = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
                    if ($appGid === '' || (int)($g['numberOfUsers'] ?? 0) <= 0) continue;
                    $work[$appGid] = $this->rosterSignature($g);
                }

                // Preload delta state in two queries: the stored signatures and which
                // apps already hold a cached roster.
                $storedSig = [];
                foreach ($this->db->fetchAll("SELECT grip_id, roster_signature FROM shadow_saas_grip_apps WHERE roster_signature IS NOT NULL") as $r) {
                    $storedSig[(string)$r['grip_id']] = (string)$r['roster_signature'];
                }
                $haveRoster = [];
                foreach ($this->db->fetchAll("SELECT DISTINCT grip_saas_id FROM shadow_saas_grip_app_users") as $r) {
                    $haveRoster[(string)$r['grip_saas_id']] = true;
                }

                $total = count($work);
                $done = 0;
                $this->setRosterProgress($logId, 0, $total);
                foreach ($work as $appGid => $sig) {
                    if ($this->isCancelled($logId)) break;
                    $done++;
                    // Unchanged signature + roster already cached -> keep cache, no call.
                    if (isset($haveRoster[$appGid]) && ($storedSig[$appGid] ?? null) === $sig) {
                        $rosterSkipped++;
                        if ($done % 50 === 0 || $done === $total) $this->setRosterProgress($logId, $done, $total);
                        continue;
                    }
                    try {
                        $this->syncAppUsers($appGid);
                        $this->db->update('shadow_saas_grip_apps', ['roster_signature' => $sig], 'grip_id = :g', [':g' => $appGid]);
                        $rosterFetched++;
                    } catch (Throwable $e) {
                        $rosterFailed++;
                        error_log('Grip per-app hydration failed for ' . $appGid . ': ' . $e->getMessage());
                    }
                    // Gentle pacing between actual API fetches (skips cost nothing).
                    if (self::ROSTER_THROTTLE_MS > 0) usleep(self::ROSTER_THROTTLE_MS * 1000);
                    if ($done % 10 === 0 || $done === $total) $this->setRosterProgress($logId, $done, $total);
                }
                $this->setRosterProgress($logId, $total, $total);

                // Orphan cleanup: drop cached rosters whose app no longer exists in
                // the mirror (the apps tombstone above already removed vanished apps).
                try {
                    $this->db->query("DELETE FROM shadow_saas_grip_app_users WHERE grip_saas_id NOT IN (SELECT grip_id FROM shadow_saas_grip_apps)");
                } catch (Throwable $e) { error_log('Grip app_users orphan cleanup failed: ' . $e->getMessage()); }

                error_log("Grip roster hydration: {$rosterFetched} fetched, {$rosterSkipped} unchanged, {$rosterFailed} failed of {$total} apps with users");
            }

            // Optional: flow Grip "Security Incident Detected" alerts into the
            // Breach Alerts system. Gated by three switches (all required):
            //   1. Grip Shadow SaaS enabled  - implicit (this sync only runs for
            //      the enabled provider), but re-checked defensively.
            //   2. grip_breach_sync_enabled  - the per-feature toggle on the
            //      Shadow SaaS > Grip admin tab.
            //   3. breach_alert_enabled      - the Breach/Cyber Alert master
            //      switch on admin.php?section=email.
            // Only onboarded (approved) vendors receive breach rows, and
            // BreachAlertService::createAlert() dedups so re-runs never duplicate.
            // Reuses $alertsData already fetched above - no extra Grip API call.
            if ($this->breachSyncEnabled && $this->enabled && $this->isBreachSystemEnabled() && !empty($alertsData)) {
                try {
                    $breachesCreated = $this->projectIncidentAlertsToBreachSystem($alertsData);
                } catch (Throwable $e) {
                    error_log('Grip breach projection failed: ' . $e->getMessage());
                }
            }

            // Attach Grip user telemetry to vendor breaches (from any source) whose
            // vendor maps to a known Grip app - so onboarded vendors show the same
            // impacted-user count + drill-down as Grip-detected Shadow SaaS breaches.
            if ($this->breachSyncEnabled && $this->enabled && $this->isBreachSystemEnabled()) {
                try {
                    $this->enrichVendorBreachesWithGripTelemetry($apps);
                } catch (Throwable $e) {
                    error_log('Grip vendor-breach telemetry enrich failed: ' . $e->getMessage());
                }
            }

            $this->closeSyncLog($logId, 'success', $appsCount, $usersCount, $alertsCount, $shadowUpserts, null);
            return [
                'success'              => true,
                'apps_fetched'         => $appsCount,
                'users_fetched'        => $usersCount,
                'alerts_fetched'       => $alertsCount,
                'shadow_saas_upserted' => $shadowUpserts,
                'apps_removed'         => $appsRemoved,
                'users_removed'        => $usersRemoved,
                'alerts_removed'       => $alertsRemoved,
                'shadow_saas_removed'  => $shadowRemoved,
                'breaches_created'     => $breachesCreated,
                'rosters_fetched'      => $rosterFetched,
                'rosters_unchanged'    => $rosterSkipped,
                'rosters_failed'       => $rosterFailed,
                'log_id'               => $logId,
            ];
        } catch (Throwable $e) {
            $this->closeSyncLog($logId, 'error', $appsCount, $usersCount, $alertsCount, $shadowUpserts, $e->getMessage());
            error_log('GripService::syncAll error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'log_id' => $logId];
        }
    }

    private function openSyncLog(string $source, ?int $userId): int
    {
        $this->db->insert('shadow_saas_grip_sync_log', [
            'started_at'     => date('Y-m-d H:i:s'),
            'trigger_source' => in_array($source, ['cron', 'manual', 'test'], true) ? $source : 'cron',
            'status'         => 'running',
            'triggered_by'   => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Record roster-hydration progress on the running sync-log row so the admin
     * Last Sync card can render a live percentage. Best-effort: a failed update
     * never disrupts the sync. $total of 0 means "no roster phase".
     */
    private function setRosterProgress(int $logId, int $done, int $total): void
    {
        try {
            $this->db->update('shadow_saas_grip_sync_log', [
                'roster_done'  => max(0, $done),
                'roster_total' => max(0, $total),
            ], 'id = :id', [':id' => $logId]);
        } catch (Throwable $e) { /* progress is non-critical */ }
    }

    private function closeSyncLog(int $logId, string $status, int $apps, int $users, int $alerts, int $shadowSaas, ?string $err): void
    {
        try {
            $this->db->update('shadow_saas_grip_sync_log', [
                'finished_at'         => date('Y-m-d H:i:s'),
                'status'              => $status,
                'apps_fetched'        => $apps,
                'users_fetched'       => $users,
                'alerts_fetched'      => $alerts,
                'shadow_saas_upserted'=> $shadowSaas,
                'error_message'       => $err,
            ], 'id = :id', [':id' => $logId]);
        } catch (Throwable $e) { error_log('Grip sync log update failed: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------
    // Upserts
    // ------------------------------------------------------------------

    private function upsertApps(array $apps): int
    {
        $count = 0;
        foreach ($apps as $app) {
            $g  = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
            $oa = is_array($g['oauthScopes'] ?? null) ? $g['oauthScopes'] : [];
            $pc = is_array($g['primaryContact'] ?? null) ? $g['primaryContact'] : [];
            $tc = is_array($g['termsAndConditions'] ?? null) ? $g['termsAndConditions'] : [];
            $ai = is_array($g['aiDepth'] ?? null) ? $g['aiDepth'] : [];

            $row = [
                'grip_id'                      => (string)($app['id'] ?? ''),
                'name'                         => $app['name'] ?? null,
                'url'                          => $app['url'] ?? null,
                'domain'                       => self::extractDomain($app['url'] ?? null),
                'logo_url'                     => $app['logoUrl'] ?? null,
                'category'                     => $g['category'] ?? null,
                'risk_score'                   => isset($g['riskScore']) ? (int)$g['riskScore'] : null,
                'sanction_tag'                 => $g['sanctionTag'] ?? null,
                'number_of_users'              => isset($g['numberOfUsers']) ? (int)$g['numberOfUsers'] : null,
                'number_of_onboarded_users'    => isset($g['numberOfOnboardedUsers']) ? (int)$g['numberOfOnboardedUsers'] : null,
                'number_of_offboarded_users'   => isset($g['numberOfOffboardedUsers']) ? (int)$g['numberOfOffboardedUsers'] : null,
                'sso_percentage'               => isset($g['SSOPercentage']) ? (int)$g['SSOPercentage'] : null,
                'access_removal_support'       => $g['accessRemovalSupport'] ?? null,
                'business_owner'               => $g['buisnessOwner'] ?? ($g['businessOwner'] ?? null),
                'app_instances_count'          => isset($g['appInstancesCount']) ? (int)$g['appInstancesCount'] : null,
                'last_known_usage'             => $this->parseDate($g['lastKnownUsage'] ?? null),
                'first_event_time'             => $this->parseDate($g['firstEventTime'] ?? null),
                'latest_event_time'            => $this->parseDate($g['latestEventTime'] ?? null),
                'unique_scopes_count'          => isset($g['uniqueScopesCount']) ? (int)$g['uniqueScopesCount'] : null,
                'mfa_supported'                => is_string($g['mfaSupported'] ?? null) ? $g['mfaSupported'] : (isset($g['mfaSupported']) ? json_encode($g['mfaSupported']) : null),
                'ai_depth_level'               => $ai['level'] ?? null,
                'ai_depth_description'         => $ai['description'] ?? null,
                'oauth_total'                  => isset($oa['total']) ? (int)$oa['total'] : null,
                'oauth_high'                   => isset($oa['high']['count']) ? (int)$oa['high']['count'] : null,
                'oauth_medium'                 => isset($oa['medium']['count']) ? (int)$oa['medium']['count'] : null,
                'oauth_low'                    => isset($oa['low']['count']) ? (int)$oa['low']['count'] : null,
                'primary_contact_name'         => $pc['name'] ?? null,
                'primary_contact_email'        => $pc['email'] ?? null,
                'primary_contact_user_id'      => $pc['userId'] ?? null,
                'labels'                       => json_encode($g['labels'] ?? []),
                'source_platforms'             => json_encode($g['sourcePlatforms'] ?? []),
                'assets'                       => json_encode($g['assets'] ?? []),
                'compliances'                  => json_encode($g['compliances'] ?? []),
                'saml_supported'               => json_encode($g['samlSupported'] ?? new stdClass()),
                'saas_users_roles'             => json_encode($g['saasUsersRoles'] ?? []),
                'terms_questions'              => json_encode($tc['questions'] ?? null),
                'terms_references'             => json_encode($tc['references'] ?? null),
                'oauth_scopes_high'            => json_encode($oa['high']['scopes'] ?? []),
                'oauth_scopes_medium'          => json_encode($oa['medium']['scopes'] ?? []),
                'oauth_scopes_low'             => json_encode($oa['low']['scopes'] ?? []),
                'raw_payload'                  => json_encode($app),
            ];
            if ($row['grip_id'] === '') continue;
            $this->upsert('shadow_saas_grip_apps', $this->encryptRow('shadow_saas_grip_apps', $row), 'grip_id');
            $count++;
        }
        return $count;
    }

    private function upsertUsers(array $users): int
    {
        $count = 0;
        foreach ($users as $u) {
            $g = is_array($u['gripData'] ?? null) ? $u['gripData'] : [];
            $activity = is_array($g['activity'] ?? null) ? $g['activity'] : [];
            $row = [
                'grip_id'                        => (string)($u['id'] ?? ''),
                'mail'                           => $u['mail'] ?? null,
                'aliases'                        => json_encode($u['aliases'] ?? []),
                'full_name'                      => $u['fullName'] ?? null,
                'display_name'                   => $u['displayName'] ?? null,
                'organizational_unit'            => $u['organizationalUnit'] ?? null,
                'manager_email'                  => $u['managerEmail'] ?? null,
                'user_type'                      => $u['type'] ?? null,
                'number_of_saas'                 => isset($g['numberOfSaas']) ? (int)$g['numberOfSaas'] : null,
                'sso_percentage'                 => isset($g['SSOPercentage']) ? (int)$g['SSOPercentage'] : null,
                'has_active_mailbox'             => isset($activity['hasActiveMailbox']) ? (int)(bool)$activity['hasActiveMailbox'] : null,
                'last_usage'                     => $this->parseDate($g['lastUsage'] ?? null),
                'first_event_time'               => $this->parseDate($g['firstEventTime'] ?? null),
                'latest_event_time'              => $this->parseDate($g['latestEventTime'] ?? null),
                'offboarding_workflow_status'    => $g['offboardingWorkflowStatus'] ?? null,
                'offboarding_workflow_timestamp' => $this->parseDate($g['offboardingWorkflowTimestamp'] ?? null),
                'roles'                          => json_encode($g['roles'] ?? []),
                'labels'                         => json_encode($g['labels'] ?? []),
                'platforms_activity'             => json_encode($activity['platformsActivity'] ?? []),
                'custom_fields'                  => json_encode($u['customFields'] ?? null),
                'raw_payload'                    => json_encode($u),
            ];
            if ($row['grip_id'] === '') continue;
            $this->upsert('shadow_saas_grip_users', $this->encryptRow('shadow_saas_grip_users', $row), 'grip_id');
            $count++;
        }
        return $count;
    }

    private function upsertAlerts(array $alerts): int
    {
        $count = 0;
        foreach ($alerts as $a) {
            $rel = is_array($a['relatedEntity'] ?? null) ? $a['relatedEntity'] : [];
            $row = [
                'alert_id'             => (string)($a['alertId'] ?? ''),
                'alert_type'           => $a['alertType'] ?? null,
                'alert_severity'       => $a['alertSeverity'] ?? null,
                'category'             => $a['category'] ?? null,
                'status'               => $a['status'] ?? null,
                'description'          => $a['description'] ?? null,
                'mitigation_steps'     => json_encode($a['mitigationSteps'] ?? null),
                'potential_impacts'    => json_encode($a['potentialImpacts'] ?? []),
                'unique_fields_data'   => json_encode($a['uniqueFieldsData'] ?? null),
                'related_entity_id'    => $rel['id'] ?? null,
                'related_entity_name'  => $rel['name'] ?? null,
                'related_entity_type'  => $rel['entityType'] ?? null,
                'alert_url'            => $a['alertUrl'] ?? null,
                'created_at_grip'      => $this->parseDate($a['createdAt'] ?? null),
                'updated_at_grip'      => $this->parseDate($a['updatedAt'] ?? null),
                'raw_payload'          => json_encode($a),
            ];
            if ($row['alert_id'] === '') continue;
            $this->upsert('shadow_saas_grip_alerts', $this->encryptRow('shadow_saas_grip_alerts', $row), 'alert_id');
            $count++;
        }
        return $count;
    }

    /**
     * Project SaaS apps into the existing shadow_saas table so the legacy UI
     * continues to render. Match by grip_id (when present) or by domain;
     * otherwise insert new rows. Skip any domain already onboarded as a vendor.
     *
     * Returns ['upserts' => int, 'grip_ids' => string[]] - the grip_ids
     * touched in this run feed the tombstone-delete pass in syncAll().
     */
    private function projectAppsToShadowSaas(array $apps, ?int $userId): array
    {
        $upserts = 0;
        $seenGripIds = [];
        $createdBy = $userId ?: 0;
        foreach ($apps as $app) {
            $g  = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
            $pc = is_array($g['primaryContact'] ?? null) ? $g['primaryContact'] : [];
            $domain = self::extractDomain($app['url'] ?? null);
            $name = trim((string)($app['name'] ?? ''));
            if ($name === '') continue;

            // Skip if domain already exists as an active vendor onboarding row.
            if ($domain !== '') {
                try {
                    $existingVendor = $this->db->fetchOne(
                        "SELECT id FROM vendor_onboarding_requests WHERE vendor_domain = :d AND status != 'inactive'",
                        [':d' => $domain]
                    );
                    if ($existingVendor) continue;
                } catch (Throwable $e) {}
            }

            $oa = is_array($g['oauthScopes'] ?? null) ? $g['oauthScopes'] : [];
            $oauthHigh = (int)($oa['high']['count'] ?? 0);
            $riskTypeParts = [];
            if (!empty($g['sanctionTag']))           $riskTypeParts[] = 'Sanction: ' . $g['sanctionTag'];
            if (!empty($ai = $g['aiDepth']['level'] ?? '')) $riskTypeParts[] = 'AI: ' . $ai;
            if ($oauthHigh > 0)                      $riskTypeParts[] = $oauthHigh . ' high-risk OAuth scope(s)';
            $compliances = is_array($g['compliances'] ?? null) ? implode(', ', $g['compliances']) : '';

            // Best-available relationship contact: primary contact name, then
            // primary contact email, then business owner. (buisnessOwner is a
            // typo present in some Grip payloads; honor both spellings.)
            $relationshipManager = trim((string)($pc['name'] ?? ''));
            if ($relationshipManager === '') {
                $relationshipManager = trim((string)($pc['email'] ?? ''));
            }
            if ($relationshipManager === '') {
                $relationshipManager = trim((string)($g['buisnessOwner'] ?? $g['businessOwner'] ?? ''));
            }

            $gripId = (string)($app['id'] ?? '') ?: null;
            if ($gripId !== null) { $seenGripIds[] = $gripId; }

            $update = [
                'vendor_name'          => $name,
                'vendor_domain'        => $domain ?: null,
                'description'          => trim(($g['aiDepth']['description'] ?? '') . ($compliances ? "\nCompliances: " . $compliances : '')) ?: null,
                // Store the 1-5 score in the DB (Grip's API gives 0-100; bucket it
                // here so shadow_saas holds 1-5 directly, not just at display time).
                'risk_score'           => self::bucketScore($g['riskScore'] ?? null),
                'risk_type'            => $riskTypeParts ? implode('; ', $riskTypeParts) : null,
                'number_of_users'      => isset($g['numberOfUsers']) ? (int)$g['numberOfUsers'] : null,
                'application_category' => $g['category'] ?? null,
                'mfasupport'           => !empty($g['mfaSupported']) ? 'yes' : null,
                // SecurityScorecard letter rating (A/B/C/D/F) - Grip exposes no
                // numeric SSC score, only this grade.
                'security_scorecard_rating' => (isset($g['securityScorecardRating']) && is_string($g['securityScorecardRating']) && $g['securityScorecardRating'] !== '')
                    ? strtoupper(substr(trim($g['securityScorecardRating']), 0, 1)) : null,
                'grip_id'              => $gripId,
                'source'               => 'grip',
            ];

            $existing = null;
            if (!empty($update['grip_id'])) {
                $existing = $this->db->fetchOne("SELECT id, relationship_manager, status FROM shadow_saas WHERE grip_id = :gid LIMIT 1", [':gid' => $update['grip_id']]);
            }
            if (!$existing && $domain !== '') {
                $existing = $this->db->fetchOne("SELECT id, relationship_manager, status FROM shadow_saas WHERE vendor_domain = :d LIMIT 1", [':d' => $domain]);
            }
            if ($existing) {
                // Dismissed rows are an explicit admin decision - leave them
                // fully intact (no field updates from Grip).
                if (($existing['status'] ?? '') === 'dismissed') {
                    continue;
                }
                // Only stamp the relationship manager if the row doesn't already
                // have one - manual edits in the UI win over Grip's value.
                if (empty($existing['relationship_manager']) && $relationshipManager !== '') {
                    $update['relationship_manager'] = $relationshipManager;
                }
                $this->db->update('shadow_saas', $update, 'id = :id', [':id' => $existing['id']]);
            } else {
                $update['created_by'] = $createdBy;
                $update['status'] = 'pending';
                if ($relationshipManager !== '') {
                    $update['relationship_manager'] = $relationshipManager;
                }
                $this->db->insert('shadow_saas', $update);
            }
            $upserts++;
        }
        return ['upserts' => $upserts, 'grip_ids' => $seenGripIds];
    }

    // ------------------------------------------------------------------
    // Breach projection (optional - Grip security incidents -> Breach Alerts)
    // ------------------------------------------------------------------

    /**
     * Whether the Breach / Cyber Alert subsystem itself is enabled
     * (admin.php?section=email -> breach_alert_enabled). The Grip breach feed
     * must never write into a disabled breach system. Mirrors the gate used by
     * cron/breach-monitor.php.
     */
    private function isBreachSystemEnabled(): bool
    {
        try {
            $r = $this->db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_alert_enabled'");
            return $r && ($r['config_value'] ?? '') === '1';
        } catch (Throwable $e) { return false; }
    }

    /**
     * Project Grip "Security Incident Detected" alerts into the Breach Alerts
     * system (cyber_breach_alerts, via BreachAlertService). Each such alert is a
     * breach/incident Grip has flagged against a SaaS application.
     *
     *  - If the app's registrable domain matches an ONBOARDED (approved) vendor,
     *    the breach is linked to that vendor (affected_entity_type 'vendor').
     *  - Otherwise the breach is STILL recorded, tagged as Shadow SaaS
     *    (affected_entity_type 'shadow_saas') with the number of users
     *    potentially impacted, so an admin sees e.g. "Shadow SaaS / 17 users".
     *
     * createAlert() dedups on affected_entity|alert_type, so re-runs (scheduled
     * or manual) never create duplicates. Reuses the alerts already fetched by
     * syncAll() - no additional Grip API call.
     *
     * @return int number of NEW breach rows created this run.
     */
    private function projectIncidentAlertsToBreachSystem(array $alerts): int
    {
        if (!class_exists('BreachAlertService')) {
            error_log('Grip breach projection: BreachAlertService unavailable');
            return 0;
        }
        $breach  = BreachAlertService::getInstance();
        $created = 0;

        foreach ($alerts as $a) {
            // Grip's breach/incident alerts are exactly this alertType; everything
            // else (unmanaged app, OAuth grant, etc.) is posture noise, not a breach.
            if (($a['alertType'] ?? '') !== 'Security Incident Detected') continue;
            $rel = is_array($a['relatedEntity'] ?? null) ? $a['relatedEntity'] : [];
            if (($rel['entityType'] ?? '') !== 'saas_application') continue;

            $gripId  = (string)($rel['id'] ?? '');
            $appRow  = $gripId !== ''
                ? $this->db->fetchOne("SELECT name, domain, number_of_users FROM shadow_saas_grip_apps WHERE grip_id = :g", [':g' => $gripId])
                : null;
            // Resolve the app's registrable domain from Grip's telemetry: the apps
            // mirror, the Shadow SaaS list, then a live single-app API fetch. This
            // keeps the breach labelled "Company (domain)" even for incident apps
            // that aren't in the local Shadow SaaS list (e.g. OpenAI).
            $domain  = $this->resolveGripAppDomain($gripId, trim((string)($appRow['domain'] ?? '')));
            $appName = trim((string)($rel['name'] ?? '')) ?: trim((string)($appRow['name'] ?? ''));
            if ($appName === '') $appName = $domain;
            if ($appName === '') continue; // nothing to key the breach on
            $impacted = (isset($appRow['number_of_users']) && $appRow['number_of_users'] !== null)
                ? (int)$appRow['number_of_users'] : null;

            // Onboarded (approved) vendor match by registrable domain.
            $vendor = null;
            if ($domain !== '') {
                $vendor = $this->db->fetchOne(
                    "SELECT id, vendor_name FROM vendor_onboarding_requests WHERE vendor_domain = :d AND status = 'approved' LIMIT 1",
                    [':d' => $domain]
                );
            }

            $sev = strtolower((string)($a['alertSeverity'] ?? 'high'));
            if (!in_array($sev, ['critical', 'high', 'medium', 'low'], true)) $sev = 'high';

            $uf       = is_array($a['uniqueFieldsData'] ?? null) ? $a['uniqueFieldsData'] : [];
            $moreUrl  = trim((string)($uf['more_info_url'] ?? ''));
            $detected = $this->parseDate($a['createdAt'] ?? null);
            $descr    = trim((string)($a['description'] ?? ''));

            $meta = [];
            if ($detected)          $meta[] = 'Detected by Grip: ' . $detected;
            if ($impacted !== null) $meta[] = $impacted . ' user(s) potentially impacted in your environment';
            if (!empty($uf['incident_type']) && is_array($uf['incident_type'])) {
                $meta[] = 'Incident type: ' . implode(', ', array_map('strval', $uf['incident_type']));
            }
            $meta[]  = 'Source: Grip Security' . (!empty($a['alertId']) ? ' (alert ' . $a['alertId'] . ')' : '') . '.';
            $summary = trim($descr . ($meta ? "\n\n" . implode("\n", $meta) : ''));

            $sources = [];
            if ($moreUrl !== '')        $sources[] = $moreUrl;
            if (!empty($a['alertUrl'])) $sources[] = $a['alertUrl'];

            $data = [
                'alert_type'  => 'data_breach',
                'severity'    => $sev,
                'summary'     => $summary,
                'source_urls' => $sources,
            ];
            if ($vendor) {
                $data['title']                = $this->clip($vendor['vendor_name'] . ' — security incident reported by Grip', 500);
                $data['affected_entity']      = $vendor['vendor_name'];
                $data['affected_entity_type'] = 'vendor';
                $data['affected_vendor_ids']  = json_encode([(int)$vendor['id']]);
                $data['vendor_count']         = 1;
            } else {
                // No onboarded vendor - record as a Shadow SaaS breach.
                $data['title']                = $this->clip($appName . ' — security incident reported by Grip', 500);
                $data['affected_entity']      = $appName;
                $data['affected_entity_type'] = 'shadow_saas';
                $data['vendor_count']         = 0;
            }

            // Has this Grip app already produced a breach? Key off the stored
            // grip_saas_id so a Shadow SaaS breach can be promoted to a vendor
            // breach when that vendor is onboarded later - without creating a
            // duplicate even when the Grip app name and vendor name differ.
            $existing = ($gripId !== '')
                ? $this->db->fetchOne("SELECT id FROM cyber_breach_alerts WHERE grip_saas_id = :g ORDER BY id LIMIT 1", [':g' => $gripId])
                : null;

            $newId = 0;
            if ($existing) {
                $newId = (int)$existing['id'];
            } else {
                try {
                    $res = $breach->createAlert($data);
                } catch (Throwable $e) {
                    error_log('Grip breach createAlert failed for ' . $appName . ': ' . $e->getMessage());
                    continue;
                }
                if (!empty($res['success']))      { $created++; $newId = (int)$res['id']; }
                elseif (!empty($res['existing_id'])) { $newId = (int)$res['existing_id']; }
            }

            // Persist impacted user count, the Grip app id, and the resolved domain
            // (columns added in v2.6.2.sql) so the Breach UI can drill into affected
            // users and label the alert "Company (domain)". Applied on create and on
            // re-sync so older rows backfill. Guarded so a pre-migration schema can't
            // fatal the sync.
            if ($newId > 0) {
                $upd = [];
                if ($impacted !== null) $upd['impacted_user_count'] = $impacted;
                if ($gripId !== '')     $upd['grip_saas_id'] = $gripId;
                if ($domain !== '')     $upd['affected_domain'] = $domain;
                // Promote to a vendor breach (moves it into "Your Vendors" and drops the
                // Shadow SaaS indicator) when the vendor is now onboarded. The impacted
                // user count / Grip app id are preserved, so the affected-users drill-down
                // stays available.
                if ($vendor) {
                    $upd['title']                = $this->clip($vendor['vendor_name'] . ' — security incident reported by Grip', 500);
                    $upd['affected_entity']      = $vendor['vendor_name'];
                    $upd['affected_entity_type'] = 'vendor';
                    $upd['affected_vendor_ids']  = json_encode([(int)$vendor['id']]);
                    $upd['vendor_count']         = 1;
                }
                if ($upd) {
                    try { $this->db->update('cyber_breach_alerts', $upd, 'id = :id', [':id' => $newId]); }
                    catch (Throwable $e) { error_log('Grip breach row enrich failed: ' . $e->getMessage()); }
                }
            }

            // Snapshot this app's users into shadow_saas_grip_app_users for the
            // breach drill-down, regardless of dedup outcome, so the affected-users
            // list stays current. Best-effort: a failure never aborts the sync.
            if ($gripId !== '') {
                try { $this->syncAppUsers($gripId); }
                catch (Throwable $e) { error_log('Grip app-users snapshot failed for ' . $gripId . ': ' . $e->getMessage()); }
            }
        }
        return $created;
    }

    /**
     * Attach Grip user telemetry to existing vendor breaches. A breach about an
     * onboarded vendor may have come from another source (the AI breach monitor)
     * and so carry no Grip data. When that vendor's registrable domain matches a
     * known Grip SaaS app, stamp the breach with the app's user count + id (and
     * snapshot its users) so it shows the same impacted-user drill-down as a
     * Grip-detected Shadow SaaS breach. Only fills gaps - never overwrites a
     * breach that already has a Grip app id. @return int breaches enriched
     */
    private function enrichVendorBreachesWithGripTelemetry(array $apps): int
    {
        // Build a registrable-domain → {grip_id, users} map from the apps already
        // fetched this run (authoritative; the persisted mirror is sparse). When a
        // domain has several app instances, keep the one with the most users.
        $byDomain = [];
        foreach ($apps as $app) {
            $gid = (string)($app['id'] ?? '');
            $dom = !empty($app['url']) ? (string)self::extractDomain($app['url']) : '';
            if ($gid === '' || $dom === '') continue;
            $g   = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
            $num = isset($g['numberOfUsers']) ? (int)$g['numberOfUsers'] : 0;
            if (!isset($byDomain[$dom]) || $num > $byDomain[$dom]['num']) {
                $byDomain[$dom] = ['grip_id' => $gid, 'num' => $num];
            }
        }
        if (!$byDomain) return 0;

        $enriched = 0;
        $rows = $this->db->fetchAll(
            "SELECT id, affected_vendor_ids, affected_domain FROM cyber_breach_alerts
             WHERE affected_entity_type = 'vendor' AND (grip_saas_id IS NULL OR grip_saas_id = '')"
        );
        foreach ($rows as $r) {
            // Resolve the vendor's registrable domain.
            $domain = trim((string)($r['affected_domain'] ?? ''));
            if ($domain === '') {
                $ids = json_decode($r['affected_vendor_ids'] ?? '[]', true);
                $vid = (is_array($ids) && !empty($ids)) ? (int)$ids[0] : 0;
                if ($vid > 0) {
                    $v = $this->db->fetchOne("SELECT vendor_domain FROM vendor_onboarding_requests WHERE id = :id", [':id' => $vid]);
                    $domain = $v ? trim((string)($v['vendor_domain'] ?? '')) : '';
                }
            }
            if ($domain === '') continue;
            $domain = (string)self::extractDomain($domain) ?: $domain;
            if (!isset($byDomain[$domain])) continue;

            $app = $byDomain[$domain];
            $upd = [
                'grip_saas_id'        => $app['grip_id'],
                'affected_domain'     => $domain,
                'impacted_user_count' => (int)$app['num'],
            ];
            // The affected-users roster is captured lazily by grip-saas-users.php on
            // first view, so no (potentially large) snapshot fetch is done here.
            try {
                $this->db->update('cyber_breach_alerts', $upd, 'id = :id', [':id' => (int)$r['id']]);
                $enriched++;
            } catch (Throwable $e) {
                error_log('Vendor breach Grip enrich failed for alert ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        return $enriched;
    }

    /**
     * Stamp onboarded vendors with the SecurityScorecard letter rating (A/B/C/D/F)
     * of the Grip app matching their registrable domain. Builds the domain→rating
     * map from the apps already fetched this run (the persisted mirror is sparse).
     * Onboarded vendors are excluded from the Shadow SaaS list, so this is how they
     * get an SSC value for the sortable column on vendor-srs-list.php.
     */
    private function enrichVendorScorecardRatings(array $apps): void
    {
        // domain => ['rating' => letter|'', 'data' => curated array|null]
        $byDomain = [];
        foreach ($apps as $app) {
            $dom = !empty($app['url']) ? (string)self::extractDomain($app['url']) : '';
            if ($dom === '') continue;
            $g = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
            $r = (isset($g['securityScorecardRating']) && is_string($g['securityScorecardRating']) && $g['securityScorecardRating'] !== '')
                ? strtoupper(substr(trim($g['securityScorecardRating']), 0, 1)) : '';
            if (!in_array($r, ['A', 'B', 'C', 'D', 'F'], true)) $r = '';
            $data = $this->curateVendorGripAppData($g);
            // Carry the Grip app id so the "SaaS Data" tab can deep-link the
            // active-accounts count to grip-saas-users.php?id=<grip_id>.
            $gid = (string)($app['id'] ?? '');
            if ($data !== null && $gid !== '') $data['grip_id'] = $gid;
            // First instance of a domain wins; merge so a later instance can fill
            // a gap the first one left (rating present here, data present there).
            if (!isset($byDomain[$dom])) {
                $byDomain[$dom] = ['rating' => $r, 'data' => $data];
            } else {
                if ($byDomain[$dom]['rating'] === '' && $r !== '') $byDomain[$dom]['rating'] = $r;
                if (empty($byDomain[$dom]['data']) && !empty($data))  $byDomain[$dom]['data'] = $data;
            }
        }
        if (!$byDomain) return;
        foreach ($byDomain as $dom => $info) {
            $fields = [];
            if ($info['rating'] !== '')    $fields['security_scorecard_rating'] = $info['rating'];
            if (!empty($info['data']))     $fields['grip_app_data'] = json_encode($info['data']);
            if (!$fields) continue;
            try {
                $this->db->update(
                    'vendor_onboarding_requests',
                    $fields,
                    "LOWER(vendor_domain) = :d AND status != 'inactive'",
                    [':d' => strtolower($dom)]
                );
            } catch (Throwable $e) {
                error_log('Vendor Grip enrich update failed for ' . $dom . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Curate the subset of a Grip app's gripData surfaced on the vendor-onboarding
     * "SaaS Data" tab. Returns null when nothing useful is present so we never
     * stamp an empty JSON blob over a vendor record. Values are normalized to
     * display-ready scalars/arrays; the view does the final formatting.
     */
    private function curateVendorGripAppData(array $g): ?array
    {
        $ai = is_array($g['aiDepth'] ?? null) ? $g['aiDepth'] : [];
        $aiLevel = trim((string)($ai['level'] ?? ''));
        $aiDesc  = trim((string)($ai['description'] ?? ''));
        $compliances = [];
        if (is_array($g['compliances'] ?? null)) {
            foreach ($g['compliances'] as $c) {
                $c = trim((string)$c);
                if ($c !== '') $compliances[] = $c;
            }
        }
        // SAML/MFA support in Grip can be a bool, a string, or an object map.
        $saml = $this->normalizeSupportFlag($g['samlSupported'] ?? null);
        $mfa  = $this->normalizeSupportFlag($g['mfaSupported'] ?? null);

        $data = [
            'first_event_time'    => $this->parseDate($g['firstEventTime'] ?? null),
            'number_of_users'     => isset($g['numberOfUsers']) ? (int)$g['numberOfUsers'] : null,
            'last_known_usage'    => $this->parseDate($g['lastKnownUsage'] ?? null),
            'sanction_tag'        => isset($g['sanctionTag']) && $g['sanctionTag'] !== '' ? (string)$g['sanctionTag'] : null,
            'category'            => isset($g['category']) && $g['category'] !== '' ? (string)$g['category'] : null,
            'ai_depth'            => ($aiLevel !== '' || $aiDesc !== '') ? trim($aiLevel . ($aiDesc !== '' ? ($aiLevel !== '' ? ' — ' : '') . $aiDesc : '')) : null,
            'compliances'         => $compliances ?: null,
            'saml_supported'      => $saml,
            'mfa_supported'       => $mfa,
        ];
        foreach ($data as $v) {
            if ($v !== null && $v !== [] && $v !== '') return $data;
        }
        return null;
    }

    /**
     * Reduce Grip's SAML/MFA support telemetry (bool | string | object map of
     * providers) to a human label: 'Yes'/'No', the raw string, or a comma list
     * of the supported providers. Returns null when nothing is known.
     */
    private function normalizeSupportFlag($val): ?string
    {
        if ($val === null) return null;
        if (is_bool($val)) return $val ? 'Yes' : 'No';
        if (is_string($val)) { $val = trim($val); return $val !== '' ? $val : null; }
        if (is_array($val)) {
            $providers = [];
            foreach ($val as $k => $v) {
                $on = is_bool($v) ? $v : (is_string($v) ? !in_array(strtolower(trim($v)), ['', '0', 'false', 'no'], true) : (bool)$v);
                if ($on) $providers[] = is_string($k) ? $k : (string)$v;
            }
            if ($providers) return implode(', ', $providers);
            return !empty($val) ? 'Yes' : 'No';
        }
        return null;
    }

    /**
     * Resolve a Grip SaaS app's registrable domain, most-local source first:
     *   1. the value already known to the caller (apps-mirror domain),
     *   2. the apps mirror url, 3. the Shadow SaaS list (vendor_domain),
     *   4. a live single-app API fetch (GET /public/saas/{id} → url) - Grip's
     *      domain telemetry, which covers incident apps absent from local tables.
     * Cached per run. Returns '' when no domain can be determined.
     */
    private function resolveGripAppDomain(string $gripId, string $known = ''): string
    {
        $known = trim($known);
        if ($known !== '') return $known;
        if ($gripId === '') return '';
        if (array_key_exists($gripId, $this->appDomainCache)) return $this->appDomainCache[$gripId];

        $domain = '';
        $row = $this->db->fetchOne("SELECT domain, url FROM shadow_saas_grip_apps WHERE grip_id = :g", [':g' => $gripId]);
        if ($row) {
            $domain = trim((string)($row['domain'] ?? ''));
            if ($domain === '' && !empty($row['url'])) $domain = (string)self::extractDomain($row['url']);
        }
        if ($domain === '') {
            $ss = $this->db->fetchOne("SELECT vendor_domain FROM shadow_saas WHERE grip_id = :g LIMIT 1", [':g' => $gripId]);
            if ($ss) $domain = trim((string)($ss['vendor_domain'] ?? ''));
        }
        if ($domain === '' && $this->isConfigured()) {
            try {
                $res = $this->request('/' . rawurlencode($gripId));
                if (!empty($res['success']) && is_array($res['data']) && !empty($res['data']['url'])) {
                    $domain = (string)self::extractDomain($res['data']['url']);
                }
            } catch (Throwable $e) {
                error_log('Grip app domain fetch failed for ' . $gripId . ': ' . $e->getMessage());
            }
        }
        $this->appDomainCache[$gripId] = $domain;
        return $domain;
    }

    /**
     * Live fetch of a single Grip SaaS app's detail (GET /public/saas/{id}).
     * Returns the raw app record (name, url, gripData, ...) or null. Used to
     * resolve an app's display name/domain when it's absent from local tables
     * (e.g. an onboarded vendor's app, excluded from the Shadow SaaS mirror).
     */
    public function getAppDetail(string $gripId): ?array
    {
        $gripId = trim($gripId);
        if ($gripId === '') return null;
        try {
            $res = $this->request('/' . rawurlencode($gripId));
            if (!empty($res['success']) && is_array($res['data'] ?? null)) return $res['data'];
        } catch (Throwable $e) {
            error_log('Grip app detail fetch failed for ' . $gripId . ': ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Live fetch of the users for one Grip SaaS app
     * (GET /public/saas/{id}/users). Returns the raw array of {user, saasUser}.
     *
     * A page request that still fails after request()'s built-in retry/backoff is
     * a HARD failure: we throw rather than return a partial/empty roster, so the
     * snapshot sync can preserve the app's existing rows instead of replacing a
     * good roster with a truncated one. Callers that prefer best-effort (the live
     * page) catch the exception.
     */
    public function getUsersForApp(string $gripId): array
    {
        $gripId = trim($gripId);
        if ($gripId === '') return [];
        // Paginate - large apps can have many users (offset/limit, like the other
        // Grip list endpoints). Iterate until a short page.
        $all = [];
        $offset = 0;
        $base = '/' . rawurlencode($gripId) . '/users';
        while (true) {
            $res = $this->request($base, ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            if (!$res['success']) {
                $why = $res['error'] ?: ('HTTP ' . $res['http_code']);
                throw new RuntimeException("Grip roster fetch failed for {$gripId} at offset {$offset}: {$why}");
            }
            if (!is_array($res['data'])) break;
            $page = $res['data'];
            if (empty($page)) break;
            foreach ($page as $row) $all[] = $row;
            if (count($page) < self::PAGE_SIZE) break;
            $offset += self::PAGE_SIZE;
        }
        return $all;
    }

    /**
     * Refresh the per-app user snapshot (shadow_saas_grip_app_users) for one Grip
     * SaaS app - replaces the app's rows wholesale (per-app sets are small).
     * @return int users stored
     */
    /**
     * Roster change signature for an app, derived from cheap fields already present
     * in the /saas list response (no extra API call). When this is unchanged between
     * syncs the per-app roster is treated as unchanged and skipped. We fold in the
     * user counts plus the latest-activity / last-usage timestamps so both
     * membership changes and activity changes invalidate the cache.
     */
    private function rosterSignature(array $gripData): string
    {
        return sha1(implode('|', [
            (int)($gripData['numberOfUsers'] ?? 0),
            (int)($gripData['numberOfOnboardedUsers'] ?? 0),
            (int)($gripData['numberOfOffboardedUsers'] ?? 0),
            (string)($gripData['latestEventTime'] ?? ''),
            (string)($gripData['lastKnownUsage'] ?? ''),
        ]));
    }

    private function syncAppUsers(string $gripId): int
    {
        $gripId = trim($gripId);
        if ($gripId === '') return 0;

        // Fetch FIRST. getUsersForApp() throws on a hard (post-retry) API failure,
        // so a timeout propagates here BEFORE we touch the stored roster - the
        // existing rows are left intact and the caller logs the app as failed.
        $users = $this->getUsersForApp($gripId);

        // Normalise, de-duplicating on the table's PK (grip_saas_id, user_grip_id)
        // so a repeated user can't break a multi-row insert.
        $rows = [];
        foreach ($users as $entry) {
            $row = $this->mapGripAppUser($entry, $gripId);
            // Encrypt PII on the way to the DB. (mapGripAppUser is shared with the
            // live page fetch, which renders plaintext, so we encrypt HERE on the
            // persistence path only - never inside the mapper.)
            if ($row !== null) $rows[(string)$row['user_grip_id']] = $this->encryptRow('shadow_saas_grip_app_users', $row);
        }

        // Replace the app's roster atomically: delete + re-insert inside one
        // transaction so a mid-write error rolls back to the previous roster
        // rather than leaving the app half-populated.
        $this->db->beginTransaction();
        try {
            $this->db->delete('shadow_saas_grip_app_users', 'grip_saas_id = :g', [':g' => $gripId]);
            $count = 0;
            if ($rows) {
                // Bulk insert in chunks - one statement per ~200 rows instead of one
                // per user, so a large roster loads in seconds, not minutes.
                $cols  = array_keys(reset($rows));
                $colSql = '`' . implode('`,`', $cols) . '`';
                foreach (array_chunk(array_values($rows), 200) as $chunk) {
                    $placeholders = [];
                    $bind = [];
                    foreach ($chunk as $i => $row) {
                        $ph = [];
                        foreach ($cols as $c) { $k = ':' . $c . $i; $ph[] = $k; $bind[$k] = $row[$c]; }
                        $placeholders[] = '(' . implode(',', $ph) . ')';
                    }
                    $sql = "INSERT INTO `shadow_saas_grip_app_users` ($colSql) VALUES " . implode(',', $placeholders);
                    $this->db->query($sql, $bind);
                    $count += count($chunk);
                }
            }
            $this->db->commit();
            return $count;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Normalise one Grip {user, saasUser} entry into a shadow_saas_grip_app_users
     * row (same shape whether persisted or rendered live). Returns null if the
     * entry has no user id. Used by both the snapshot sync and the live page fetch.
     */
    private function mapGripAppUser(array $entry, string $gripId): ?array
    {
        $u   = is_array($entry['user'] ?? null) ? $entry['user'] : [];
        $su  = is_array($entry['saasUser'] ?? null) ? $entry['saasUser'] : [];
        $g   = is_array($u['gripData'] ?? null) ? $u['gripData'] : [];
        $act = is_array($g['activity'] ?? null) ? $g['activity'] : [];
        $userGripId = (string)($u['id'] ?? ($su['userId'] ?? ''));
        if ($userGripId === '') return null;
        return [
            'grip_saas_id'        => $gripId,
            'user_grip_id'        => $userGripId,
            'mail'                => $u['mail'] ?? null,
            'full_name'           => $u['fullName'] ?? null,
            'display_name'        => $u['displayName'] ?? null,
            'organizational_unit' => $u['organizationalUnit'] ?? null,
            'manager_email'       => $u['managerEmail'] ?? null,
            'user_type'           => $u['type'] ?? null,
            'authentication_type' => $su['authenticationType'] ?? null,
            'sso'                 => isset($su['SSO']) ? (int)(bool)$su['SSO'] : null,
            'unique_scopes_count' => isset($su['uniqueScopesCount']) ? (int)$su['uniqueScopesCount'] : null,
            'number_of_saas'      => isset($g['numberOfSaas']) ? (int)$g['numberOfSaas'] : null,
            'has_active_mailbox'  => isset($act['hasActiveMailbox']) ? (int)(bool)$act['hasActiveMailbox'] : null,
            'first_event_time'    => $this->parseDate($su['firstEventTime'] ?? ($g['firstEventTime'] ?? null)),
            'latest_event_time'   => $this->parseDate($su['latestEventTime'] ?? ($g['latestEventTime'] ?? null)),
            'last_usage'          => $this->parseDate($su['lastUsage'] ?? ($g['lastUsage'] ?? null)),
            'platforms_activity'  => json_encode($act['platformsActivity'] ?? []),
            // NOTE: raw_payload is intentionally NOT stored on the per-app roster.
            // It was an encrypted copy of the full entry JSON that nothing renders
            // (grip-saas-users.php drops it before display) yet dominated this
            // table's size (~80% of each row). Omitting it shrinks the roster table
            // ~4-5x; the flattened columns above carry everything the UI/API need.
        ];
    }

    /**
     * Fetch a single page of an app's users live from Grip, normalised to the
     * snapshot row shape - so the drill-down can render just the requested page
     * (e.g. 100 of 4862) instead of syncing the whole roster on first view.
     * @return array list of normalised user rows for [offset, offset+limit)
     */
    public function getAppUsersPage(string $gripId, int $offset, int $limit): array
    {
        $gripId = trim($gripId);
        if ($gripId === '' || !$this->isConfigured()) return [];
        $offset = max(0, $offset);
        $limit  = max(1, min($limit, 1000));
        $base   = '/' . rawurlencode($gripId) . '/users';
        $rows   = [];
        $cursor = $offset;
        // Grip caps a page at PAGE_SIZE, so gather in chunks until we have $limit.
        while (count($rows) < $limit) {
            $chunk = min(self::PAGE_SIZE, $limit - count($rows));
            $res = $this->request($base, ['offset' => $cursor, 'limit' => $chunk]);
            if (empty($res['success']) || !is_array($res['data']) || empty($res['data'])) break;
            foreach ($res['data'] as $entry) {
                $row = $this->mapGripAppUser($entry, $gripId);
                if ($row !== null) $rows[] = $row;
            }
            $got = count($res['data']);
            $cursor += $got;
            if ($got < $chunk) break;
        }
        return $rows;
    }

    /**
     * Public, on-demand refresh of one app's user snapshot. Used by the
     * grip-saas-users.php drill-down to lazily populate
     * shadow_saas_grip_app_users the first time a (non-breached) app's user
     * count is opened, since the breach projection only snapshots breached apps.
     * No-op unless Grip is configured. @return int users stored
     */
    public function refreshAppUsers(string $gripId): int
    {
        if (!$this->isConfigured()) return 0;
        return $this->syncAppUsers($gripId);
    }

    // ------------------------------------------------------------------
    // Tiny helpers
    // ------------------------------------------------------------------

    /** Trim a string to a max length (multibyte-safe). */
    private function clip(string $s, int $max): string
    {
        return (mb_strlen($s) > $max) ? mb_substr($s, 0, $max) : $s;
    }

    private function upsert(string $table, array $row, string $key): void
    {
        $existing = $this->db->fetchOne("SELECT {$key} FROM {$table} WHERE {$key} = :k LIMIT 1", [':k' => $row[$key]]);
        if ($existing) {
            $this->db->update($table, $row, "{$key} = :k", [':k' => $row[$key]]);
        } else {
            $this->db->insert($table, $row);
        }
    }

    private function parseDate($val): ?string
    {
        if (empty($val) || !is_string($val)) return null;
        $ts = strtotime($val);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Bucket Grip's 0-100 risk score to the stored 1-5 scale used across Shadow
     * SaaS. Null in -> null out. (Same thresholds the UI historically applied at
     * render; now applied at write so the DB holds 1-5.)
     *   <=0 -> 0, <=20 -> 1, <=40 -> 2, <=60 -> 3, <=80 -> 4, else 5
     */
    public static function bucketScore($raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        $n = (int)$raw;
        if ($n <= 0)  return 0;
        if ($n <= 20) return 1;
        if ($n <= 40) return 2;
        if ($n <= 60) return 3;
        if ($n <= 80) return 4;
        return 5;
    }

    /** True if an admin has requested this run be aborted (status flipped to 'aborting'). */
    private function isCancelled(int $logId): bool
    {
        try {
            $r = $this->db->fetchOne("SELECT status FROM shadow_saas_grip_sync_log WHERE id = :id", [':id' => $logId]);
            return $r && ($r['status'] ?? '') === 'aborting';
        } catch (Throwable $e) { return false; }
    }

    /** Latest sync log row, or null if none. */
    public function getLastSync(): ?array
    {
        try {
            $row = $this->db->fetchOne("SELECT * FROM shadow_saas_grip_sync_log ORDER BY id DESC LIMIT 1");
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}
