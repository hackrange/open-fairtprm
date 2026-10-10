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
    // Hard stop for list pagination (200k rows) so an endpoint that ignores
    // offset can never loop forever.
    private const MAX_PAGES = 1000;
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
    // Live mode does not snapshot every app's roster, but newly discovered apps
    // always get one (their rosters are tiny) so the Shadow SaaS "New" tab can
    // name patient zero - the earliest user Grip saw - without per-view calls.
    public const RECENT_ROSTER_DAYS = 90;

    private Database $db;
    private string $baseUrl = '';
    private string $apiToken = '';
    private bool $enabled = false;
    private bool $breachSyncEnabled = false;
    /** TPRM -> Grip write-back (labels, primary contact); opt-in via grip_writeback_enabled. */
    private bool $writebackEnabled = false;
    /** Per-run cache of lower-cased email => Grip userId (or '' when not a Grip user). */
    private array $userIdCache = [];
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
        'shadow_saas_grip_alerts'    => ['raw_payload', 'related_identity_mail'],
        // Free-text scan output; can quote user and resource names.
        'shadow_saas_grip_posture_checks' => ['report_details'],
        // ITDR threats: who, and the IPs / countries / events that tripped it.
        'shadow_saas_grip_threats' => ['related_entity_name', 'related_identity_mail', 'unique_fields_data', 'event_logs'],
        // AI agents: the owner, the file names it was given, and who it is shared with.
        'shadow_saas_grip_ai_agents' => ['owner_email', 'knowledge_files', 'identities_permissions'],
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
                case 'grip_writeback_enabled':  $this->writebackEnabled = ($val === '1'); break;
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
     * $method is GET, POST or DELETE. Most Grip write endpoints take their
     * arguments as query parameters with an empty body; label and action
     * endpoints take a JSON body ($jsonBody). A write that is not idempotent
     * (sending a justification request) passes $idempotent = false so a timeout
     * or 5xx is not retried into a duplicate - only a 429, which Grip rejected
     * before acting, is retried.
     */
    private function request(string $relativeUrl, array $query = [], string $method = 'GET', ?array $jsonBody = null, bool $idempotent = true): array
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
                CURLOPT_HTTPHEADER     => array_merge([
                    'Accept: application/json',
                    'access-token: ' . $this->apiToken,
                    'User-Agent: FairTPRM-Grip-Integration/1.0',
                ], $jsonBody !== null ? ['Content-Type: application/json'] : []),
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
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody !== null ? json_encode($jsonBody) : '');
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if ($jsonBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            }
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
            $transient = $idempotent
                ? ($timedOut || $code === 429 || ($code >= 500 && $code < 600))
                : ($code === 429);

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
            // A write endpoint can answer 200 with an empty body.
            $decoded = trim((string)$body) === '' ? null : json_decode($body, true);
            $jsonOk = trim((string)$body) === '' || (json_last_error() === JSON_ERROR_NONE);

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
    /**
     * Page through a Grip list endpoint (offset/limit).
     *
     * Grip sometimes returns FEWER rows than the limit in the middle of a list
     * (e.g. 199 of 200), so a short page is NOT the end of the data. Stopping
     * there silently truncated every sync to a fraction of the tenant's apps.
     * We advance the offset by the page size we asked for and stop only on an
     * empty page; a repeated page or the MAX_PAGES cap also ends the loop.
     */
    private function fetchAll(string $relativeUrl, ?string $what = null): array
    {
        $what = $what ?? "Grip fetch {$relativeUrl}";
        $all = [];
        $prevHash = null;
        for ($page = 0, $offset = 0; $page < self::MAX_PAGES; $page++, $offset += self::PAGE_SIZE) {
            $res = $this->request($relativeUrl, ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            if (!$res['success']) {
                throw new RuntimeException("{$what} failed at offset {$offset}: " . ($res['error'] ?: 'HTTP ' . $res['http_code']));
            }
            $rows = is_array($res['data']) ? $res['data'] : [];
            if (empty($rows)) return $all;
            $hash = md5(json_encode($rows));
            if ($hash === $prevHash) {
                error_log("{$what}: offset {$offset} repeated the previous page; stopping");
                return $all;
            }
            $prevHash = $hash;
            foreach ($rows as $row) $all[] = $row;
        }
        error_log("{$what}: hit the " . self::MAX_PAGES . "-page cap; list may be incomplete");
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
            try { $alertsData = $this->fetchAlerts();               $alertsCount = $this->upsertAlerts($alertsData); } catch (Throwable $e) { error_log('Grip alerts fetch failed: ' . $e->getMessage()); }
            // SSPM posture checks for the Shadow SaaS > Posture tab. One request.
            try { $this->syncPostureChecks(); } catch (Throwable $e) { error_log('Grip posture-checks sync failed: ' . $e->getMessage()); }
            // Waived posture findings: re-check coverage against Grip's current findings.
            try { $this->refreshPostureWaiverCoverage(); } catch (Throwable $e) { error_log('Grip waiver coverage refresh failed: ' . $e->getMessage()); }
            // Identity threats for the Shadow SaaS > ITDR page. One request.
            try { $this->syncThreats(); } catch (Throwable $e) { error_log('Grip threats sync failed: ' . $e->getMessage()); }
            // AI agents for Shadow SaaS > AI Security. One request.
            try { $this->syncAiAgents(); } catch (Throwable $e) { error_log('Grip AI agents sync failed: ' . $e->getMessage()); }

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
            // Live mode runs the same loop over newly discovered apps only.
            $rosterFetched = $rosterSkipped = $rosterFailed = 0;
            if (!empty($apps)) {
                // Worklist: apps that have users, mapped to their current signature.
                $recentCutoff = time() - self::RECENT_ROSTER_DAYS * 86400;
                $work = [];
                foreach ($apps as $app) {
                    $appGid = (string)($app['id'] ?? '');
                    $g = is_array($app['gripData'] ?? null) ? $app['gripData'] : [];
                    if ($appGid === '' || (int)($g['numberOfUsers'] ?? 0) <= 0) continue;
                    if (!$this->useLocalData()) {
                        $first = strtotime((string)($g['firstEventTime'] ?? '')) ?: 0;
                        if ($first < $recentCutoff) continue;
                    }
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

            // Queued sanction writes from bulk Shadow SaaS actions.
            try { $this->drainPendingSanctions(1000, 900); }
            catch (Throwable $e) { error_log('Grip queued sanction drain failed: ' . $e->getMessage()); }

            // TPRM -> Grip write-back (labels, primary contact) and justification
            // status refresh. Both are best effort and never fail the sync.
            $writeback = ['apps' => 0, 'writes' => 0, 'errors' => 0];
            try { $writeback = $this->reconcileWriteback(); }
            catch (Throwable $e) { error_log('Grip write-back reconcile failed: ' . $e->getMessage()); }
            try { $this->refreshJustificationStatuses(); }
            catch (Throwable $e) { error_log('Grip justification status refresh failed: ' . $e->getMessage()); }

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
                'writeback_writes'     => $writeback['writes'],
                'writeback_errors'     => $writeback['errors'],
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
                'breach_history'               => ($bh = self::normalizeBreachHistory($g['breachHistory'] ?? null)) !== null ? json_encode($bh) : null,
                'app_classification'           => self::normalizeEnum($g['appClassification'] ?? null, self::APP_CLASSIFICATIONS),
                'mfa_status'                   => self::normalizeEnum($g['mfaStatus'] ?? null, self::MFA_STATUSES),
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

    /**
     * Alerts from Grip's v2 list (PolicyAlertPayload): every v1 field plus
     * alertName, policyReadableId, mitigationDescription, flat relatedEntity*
     * fields, relatedIdentityMail and lastUsed. Same alert ids as v1. Falls back
     * to the v1 list if a tenant does not serve v2.
     */
    private function fetchAlerts(): array
    {
        try {
            return $this->fetchAll('/public/v2/alerts', 'Grip v2 alerts fetch');
        } catch (Throwable $e) {
            error_log('Grip v2 alerts unavailable, falling back to v1: ' . $e->getMessage());
            return $this->fetchAll('/public/alerts', 'Grip alerts fetch');
        }
    }

    /** True when an alert's related entity is a SaaS application (v1 or v2 shape). */
    private static function alertIsOnApp(array $a): bool
    {
        $rel = is_array($a['relatedEntity'] ?? null) ? $a['relatedEntity'] : [];
        return ($rel['entityType'] ?? '') === 'saas_application'
            || ($a['relatedEntityType'] ?? '') === 'Application';
    }

    private function upsertAlerts(array $alerts): int
    {
        $count = 0;
        foreach ($alerts as $a) {
            $rel = is_array($a['relatedEntity'] ?? null) ? $a['relatedEntity'] : [];
            // v2 also carries the entity flat; prefer the nested object, fill gaps.
            $rel += array_filter([
                'id'         => $a['relatedEntityId'] ?? null,
                'name'       => $a['relatedEntityName'] ?? null,
                'entityType' => $a['relatedEntityType'] ?? null,
            ], fn($v) => $v !== null && $v !== '');
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
                'alert_name'           => isset($a['alertName']) && is_string($a['alertName']) ? $this->clip(trim($a['alertName']), 500) : null,
                'policy_readable_id'   => isset($a['policyReadableId']) && is_numeric($a['policyReadableId']) ? (int)$a['policyReadableId'] : null,
                'mitigation_description' => isset($a['mitigationDescription']) && is_string($a['mitigationDescription']) ? $a['mitigationDescription'] : null,
                'related_identity_mail' => isset($a['relatedIdentityMail']) && is_string($a['relatedIdentityMail']) && $a['relatedIdentityMail'] !== '' ? $a['relatedIdentityMail'] : null,
                'last_used'            => $this->parseDate($a['lastUsed'] ?? null),
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
     * Mirror Grip's SSPM posture checks (GET /public/posture-checks) into
     * shadow_saas_grip_posture_checks for the Shadow SaaS > Posture tab. The set
     * is replaced as a whole inside a transaction, so checks Grip no longer
     * returns disappear. An empty response (tenant without SSPM) or a failed
     * fetch (throws) leaves the existing rows untouched.
     * @return int checks stored
     */
    public function syncPostureChecks(): int
    {
        $checks = $this->fetchAll('/public/posture-checks', 'Grip posture-checks fetch');
        if (!$checks) return 0;
        // Grip HTML-escapes some display strings (e.g. "Data Security &amp; Privacy").
        $txt = fn($v) => ($v === null || $v === '') ? null : html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rows = [];
        foreach ($checks as $c) {
            $uuid = (string)($c['uuid'] ?? '');
            if (!preg_match('/^[0-9a-f-]{36}$/i', $uuid)) continue;
            $rows[$uuid] = [
                'uuid'                     => $uuid,
                'posture_check_id'         => $this->clip((string)$txt($c['postureCheckId'] ?? ''), 255) ?: null,
                'description'              => $txt($c['description'] ?? null),
                'security_domain'          => $this->clip((string)$txt($c['securityDomain'] ?? ''), 255) ?: null,
                'severity'                 => $this->clip((string)($c['severity'] ?? ''), 20) ?: null,
                'severity_override_reason' => $txt($c['severityOverrideReason'] ?? null),
                'status'                   => $this->clip((string)($c['status'] ?? ''), 20) ?: null,
                'status_override_reason'   => $txt($c['statusOverrideReason'] ?? null),
                'module'                   => $this->clip((string)($c['module'] ?? ''), 100) ?: null,
                'tenant_name'              => $this->clip((string)$txt($c['tenantName'] ?? ''), 255) ?: null,
                'integration_type'         => $this->clip((string)($c['integrationType'] ?? ''), 100) ?: null,
                'tenant_id'                => $this->clip((string)($c['tenantId'] ?? ''), 255) ?: null,
                'findings_count'           => isset($c['findingsCount']) && is_numeric($c['findingsCount']) ? max(0, (int)$c['findingsCount']) : null,
                'report_details'           => is_string($c['reportDetails'] ?? null) ? $c['reportDetails'] : (isset($c['reportDetails']) ? json_encode($c['reportDetails']) : null),
                'risk_description'         => $txt($c['riskDescription'] ?? null),
                'mitigation_steps'         => is_string($c['mitigationSteps'] ?? null) ? $c['mitigationSteps'] : (isset($c['mitigationSteps']) ? json_encode($c['mitigationSteps']) : null),
                'compliance'               => json_encode(is_array($c['compliance'] ?? null) ? $c['compliance'] : []),
                'updated_at_grip'          => $this->parseDate($c['updatedAt'] ?? null),
            ];
        }
        if (!$rows) return 0;
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->upsert('shadow_saas_grip_posture_checks', $this->encryptRow('shadow_saas_grip_posture_checks', $row), 'uuid');
            }
            $keep = array_keys($rows);
            $ph = [];
            $params = [];
            foreach ($keep as $i => $u) { $ph[] = ":u{$i}"; $params[":u{$i}"] = $u; }
            $this->db->query('DELETE FROM shadow_saas_grip_posture_checks WHERE uuid NOT IN (' . implode(',', $ph) . ')', $params);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return count($rows);
    }

    /**
     * Mirror Grip's Identity Threat Detection & Response alerts (GET
     * /public/threats) into shadow_saas_grip_threats. Replaced as a whole inside a
     * transaction, so threats Grip no longer returns disappear; a failed fetch
     * throws and leaves the existing rows. Personal fields are encrypted.
     * @return int threats stored
     */
    public function syncThreats(): int
    {
        $threats = $this->fetchAll('/public/threats', 'Grip threats fetch');
        $txt = fn($v) => ($v === null || $v === '') ? null : html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rows = [];
        foreach ($threats as $t) {
            $id = (string)($t['alertId'] ?? '');
            if ($id === '' || strlen($id) > 64) continue;
            $rel = is_array($t['relatedEntity'] ?? null) ? $t['relatedEntity'] : [];
            $mit = ['description' => $txt($t['mitigationDescription'] ?? null), 'steps' => $t['mitigationSteps'] ?? null];
            $rows[$id] = [
                'alert_id'              => $id,
                'alert_name'            => $this->clip((string)$txt($t['alertName'] ?? ''), 500) ?: null,
                'alert_type'            => $this->clip((string)($t['alertType'] ?? ''), 255) ?: null,
                'severity'              => $this->clip((string)($t['alertSeverity'] ?? ''), 50) ?: null,
                'category'              => $this->clip((string)$txt($t['category'] ?? ''), 255) ?: null,
                'status'                => $this->clip((string)($t['status'] ?? ''), 50) ?: null,
                'source'                => $this->clip((string)$txt($t['source'] ?? ''), 255) ?: null,
                'description'           => $txt($t['description'] ?? null),
                'mitigation'            => json_encode($mit),
                'potential_impacts'     => json_encode(is_array($t['potentialImpacts'] ?? null) ? $t['potentialImpacts'] : []),
                'unique_fields_data'    => isset($t['uniqueFieldsData']) ? json_encode($t['uniqueFieldsData']) : null,
                'event_logs'            => isset($t['eventLogs']) ? json_encode($t['eventLogs']) : null,
                'related_entity_id'     => $this->clip((string)($t['relatedEntityId'] ?? ($rel['id'] ?? '')), 64) ?: null,
                'related_entity_type'   => $this->clip((string)($t['relatedEntityType'] ?? ($rel['entityType'] ?? '')), 64) ?: null,
                'related_entity_name'   => $txt($t['relatedEntityName'] ?? ($rel['name'] ?? null)),
                'related_identity_mail' => $t['relatedIdentityMail'] ?? ($rel['email'] ?? null),
                'alert_url'             => (isset($t['alertUrl']) && preg_match('#^https://#i', (string)$t['alertUrl'])) ? $this->clip((string)$t['alertUrl'], 2048) : null,
                'created_at_grip'       => $this->parseDate($t['createdAt'] ?? null),
                'updated_at_grip'       => $this->parseDate($t['updatedAt'] ?? null),
            ];
        }
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->upsert('shadow_saas_grip_threats', $this->encryptRow('shadow_saas_grip_threats', $row), 'alert_id');
            }
            if ($rows) {
                $ph = [];
                $params = [];
                foreach (array_keys($rows) as $i => $u) { $ph[] = ":a{$i}"; $params[":a{$i}"] = $u; }
                $this->db->query('DELETE FROM shadow_saas_grip_threats WHERE alert_id NOT IN (' . implode(',', $ph) . ')', $params);
            } else {
                // A successful, empty response means Grip has no threats now.
                $this->db->query('DELETE FROM shadow_saas_grip_threats');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return count($rows);
    }

    /**
     * Mirror Grip's AI agents (GET /public/ai-agents; served by tenants although
     * absent from Grip's published spec) into shadow_saas_grip_ai_agents.
     * Replaced as a whole inside a transaction; a failed fetch throws and keeps
     * the existing rows. Owner, knowledge files and permissions are encrypted.
     * @return int agents stored
     */
    public function syncAiAgents(): int
    {
        $agents = $this->fetchAll('/public/ai-agents', 'Grip AI agents fetch');
        $txt = fn($v) => ($v === null || $v === '') ? null : html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rows = [];
        foreach ($agents as $a) {
            $id = (string)($a['id'] ?? '');
            if ($id === '' || strlen($id) > 64) continue;
            $files = is_array($a['knowledgeFiles'] ?? null) ? $a['knowledgeFiles'] : [];
            $site = (string)($a['siteUrl'] ?? '');
            $rows[$id] = [
                'grip_id'                => $id,
                'api_id'                 => $this->clip((string)($a['aiAgentApiId'] ?? ''), 255) ?: null,
                'name'                   => $this->clip((string)$txt($a['name'] ?? ''), 500) ?: null,
                'site_url'               => preg_match('#^https?://#i', $site) ? $this->clip($site, 2048) : null,
                'owner_email'            => $a['ownerEmail'] ?? null,
                'owner_id'               => $this->clip((string)($a['ownerId'] ?? ''), 64) ?: null,
                'creation_date'          => $this->parseDate($a['creationDate'] ?? null),
                'last_seen'              => $this->parseDate($a['lastSeen'] ?? null),
                'last_usage'             => $this->parseDate($a['lastUsage'] ?? null),
                'score'                  => isset($a['score']) && is_numeric($a['score']) ? (int)$a['score'] : null,
                'sanction_state'         => $this->clip((string)($a['sanctionState'] ?? ''), 50) ?: null,
                'sharing_attributes'     => $this->clip((string)$txt($a['sharingAttributes'] ?? ''), 255) ?: null,
                'connected_to_internet'  => isset($a['isConnectedToInternet']) ? (int)(bool)$a['isConnectedToInternet'] : null,
                'knowledge_files_count'  => count($files),
                'knowledge_files'        => $files ? json_encode($files) : null,
                'identities_permissions' => !empty($a['identitiesPermissions']) ? json_encode($a['identitiesPermissions']) : null,
                'saas_scopes'            => json_encode(is_array($a['saasScopes'] ?? null) ? $a['saasScopes'] : []),
            ];
        }
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->upsert('shadow_saas_grip_ai_agents', $this->encryptRow('shadow_saas_grip_ai_agents', $row), 'grip_id');
            }
            if ($rows) {
                $ph = [];
                $params = [];
                foreach (array_keys($rows) as $i => $u) { $ph[] = ":g{$i}"; $params[":g{$i}"] = $u; }
                $this->db->query('DELETE FROM shadow_saas_grip_ai_agents WHERE grip_id NOT IN (' . implode(',', $ph) . ')', $params);
            } else {
                $this->db->query('DELETE FROM shadow_saas_grip_ai_agents');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return count($rows);
    }

    /**
     * One page of a posture check's violating entities, fetched live from Grip
     * (GET /public/posture-checks/{uuid}/findings). Never stored: findings name
     * users and resources. Returns ['success' => bool, 'rows' => array, 'error' => string].
     */
    public function getPostureFindingsPage(string $uuid, int $offset, int $limit): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'rows' => [], 'error' => 'Grip integration is not enabled'];
        if (!preg_match('/^[0-9a-f-]{36}$/i', $uuid)) return ['success' => false, 'rows' => [], 'error' => 'Invalid posture check id'];
        $res = $this->request('/public/posture-checks/' . rawurlencode($uuid) . '/findings', [
            'offset' => max(0, $offset),
            'limit'  => max(1, min($limit, 500)),
        ]);
        if (empty($res['success'])) {
            return ['success' => false, 'rows' => [], 'error' => $res['error'] ?: ('HTTP ' . $res['http_code'])];
        }
        return ['success' => true, 'rows' => is_array($res['data']) ? array_values($res['data']) : [], 'error' => ''];
    }

    // ------------------------------------------------------------------
    // Waivers: posture findings and ITDR threats
    // ------------------------------------------------------------------

    public const WAIVER_KINDS = ['posture', 'threat'];
    public const WAIVER_JUSTIFICATION_MIN = 10;
    public const WAIVER_JUSTIFICATION_MAX = 2000;
    /** Most findings one check is paged through when checking waiver coverage. */
    private const WAIVER_FINDINGS_CAP = 5000;

    /** Stable key for a posture finding: Grip's entity type + identifier (the finding id is not guaranteed stable). */
    public static function findingKey(array $finding): string
    {
        return hash('sha256', strtolower(trim((string)($finding['entityType'] ?? ''))) . '|' . trim((string)($finding['entityIdentifier'] ?? '')));
    }

    /** SQL fragment: waiver row w is active. */
    private const WAIVER_ACTIVE_SQL = "w.revoked_at IS NULL AND (w.expires_at IS NULL OR w.expires_at >= CURDATE())";

    /**
     * Active waivers, keyed by subject_id then entity_key ('' for threats), with
     * the waiving user's name and the decrypted entity label.
     */
    public function activeWaivers(string $kind, ?string $subjectId = null): array
    {
        if (!in_array($kind, self::WAIVER_KINDS, true)) return [];
        $sql = "SELECT w.*, u.full_name AS waived_by_name FROM shadow_saas_grip_waivers w
                  LEFT JOIN users u ON u.id = w.waived_by
                 WHERE w.kind = :k AND " . self::WAIVER_ACTIVE_SQL;
        $params = [':k' => $kind];
        if ($subjectId !== null) { $sql .= ' AND w.subject_id = :s'; $params[':s'] = $subjectId; }
        $out = [];
        try {
            foreach ($this->db->fetchAll($sql . ' ORDER BY w.id', $params) as $r) {
                if (!empty($r['entity_label'])) {
                    try { $r['entity_label'] = $this->enc()->decrypt((string)$r['entity_label']); } catch (Throwable $e) {}
                }
                $out[(string)$r['subject_id']][(string)$r['entity_key']] = $r;
            }
        } catch (Throwable $e) {
            error_log('Grip waivers load failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Validate a waiver's justification and optional expiry (Y-m-d, today to
     * three years out). Returns [justification, expires|null] or an error key.
     */
    public static function validateWaiverInput(string $justification, string $expires): array
    {
        $j = trim(preg_replace('/\s+/u', ' ', $justification));
        $len = mb_strlen($j);
        if ($len < self::WAIVER_JUSTIFICATION_MIN) return ['error' => 'justification_short'];
        if ($len > self::WAIVER_JUSTIFICATION_MAX) return ['error' => 'justification_long'];
        $exp = trim($expires);
        if ($exp !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $exp);
            if (!$d || $d->format('Y-m-d') !== $exp) return ['error' => 'expiry_invalid'];
            if ($exp < date('Y-m-d') || $exp > date('Y-m-d', strtotime('+3 years'))) return ['error' => 'expiry_range'];
        }
        return ['justification' => $j, 'expires' => $exp !== '' ? $exp : null];
    }

    /**
     * Record waivers. $entities is a list of ['key' => entity_key, 'label' =>
     * display name]; for a threat pass one entity with key ''. An entity that
     * already has an active waiver is left as is. Returns the number added.
     */
    public function addWaivers(string $kind, string $subjectId, array $entities, string $justification, ?string $expires, int $userId): int
    {
        if (!in_array($kind, self::WAIVER_KINDS, true)) return 0;
        $active = $this->activeWaivers($kind, $subjectId)[$subjectId] ?? [];
        $n = 0;
        foreach ($entities as $en) {
            $key = (string)($en['key'] ?? '');
            if ($kind === 'posture' && !preg_match('/^[0-9a-f]{64}$/', $key)) continue;
            if ($kind === 'threat') $key = '';
            if (isset($active[$key])) continue;
            $label = trim((string)($en['label'] ?? ''));
            $this->db->insert('shadow_saas_grip_waivers', [
                'kind'          => $kind,
                'subject_id'    => $this->clip($subjectId, 64),
                'entity_key'    => $key,
                'entity_label'  => $label !== '' ? $this->enc()->encrypt($this->clip($label, 500)) : null,
                'justification' => $justification,
                'expires_at'    => $expires,
                'waived_by'     => $userId ?: null,
            ]);
            $active[$key] = true;
            $n++;
        }
        return $n;
    }

    /** Revoke one active waiver. Returns the revoked row (kind, subject_id) or null. */
    public function revokeWaiver(int $id, int $userId, string $reason): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM shadow_saas_grip_waivers w WHERE w.id = :id AND " . self::WAIVER_ACTIVE_SQL, [':id' => $id]);
        if (!$row) return null;
        $this->db->update('shadow_saas_grip_waivers', [
            'revoked_by'    => $userId ?: null,
            'revoked_at'    => date('Y-m-d H:i:s'),
            'revoke_reason' => trim($reason) !== '' ? $this->clip(trim($reason), 2000) : null,
        ], 'id = :id', [':id' => $id]);
        return $row;
    }

    /** Every current finding of a posture check (paged, capped), or null when Grip could not be read. */
    public function getAllPostureFindings(string $uuid): ?array
    {
        $all = [];
        for ($off = 0; $off < self::WAIVER_FINDINGS_CAP; $off += self::PAGE_SIZE) {
            $res = $this->getPostureFindingsPage($uuid, $off, self::PAGE_SIZE);
            if (!$res['success']) return null;
            if (!$res['rows']) break;
            foreach ($res['rows'] as $f) if (is_array($f)) $all[] = $f;
        }
        return $all;
    }

    /**
     * Re-check how many of a posture check's current findings are covered by an
     * active waiver, against Grip's live findings, and store the counts on the
     * check. Without $uuid: every failing check that has (or had) waivers.
     * A check is fully waived when it has waived findings and no unwaived ones.
     * @return int checks updated
     */
    public function refreshPostureWaiverCoverage(?string $uuid = null): int
    {
        if (!$this->isEnabled()) return 0;
        $waivers = $this->activeWaivers('posture', $uuid);
        if ($uuid !== null) {
            $targets = [$uuid];
        } else {
            $targets = array_keys($waivers);
            foreach ($this->db->fetchAll("SELECT uuid FROM shadow_saas_grip_posture_checks WHERE waived_findings IS NOT NULL") as $r) $targets[] = (string)$r['uuid'];
            $targets = array_values(array_unique($targets));
        }
        $n = 0;
        foreach ($targets as $u) {
            $check = $this->db->fetchOne("SELECT uuid, status FROM shadow_saas_grip_posture_checks WHERE uuid = :u", [':u' => $u]);
            if (!$check) continue;
            $mine = $waivers[$u] ?? [];
            if (!$mine) {
                $this->db->update('shadow_saas_grip_posture_checks', ['waived_findings' => null, 'unwaived_findings' => null, 'waivers_checked_at' => date('Y-m-d H:i:s')], 'uuid = :u', [':u' => $u]);
                $n++;
                continue;
            }
            $findings = $this->getAllPostureFindings($u);
            if ($findings === null) continue; // Grip unreachable: keep the last known coverage
            $waived = $unwaived = 0;
            foreach ($findings as $f) {
                if (array_key_exists('isCurrentlyViolating', $f) && $f['isCurrentlyViolating'] === false) continue;
                if (isset($mine[self::findingKey($f)])) $waived++; else $unwaived++;
            }
            $this->db->update('shadow_saas_grip_posture_checks', ['waived_findings' => $waived, 'unwaived_findings' => $unwaived, 'waivers_checked_at' => date('Y-m-d H:i:s')], 'uuid = :u', [':u' => $u]);
            $n++;
        }
        return $n;
    }

    /** FairTPRM's view of a posture check's status: a failing check whose current findings are all waived is 'Waived'. */
    public static function effectivePostureStatus(array $check): string
    {
        $st = (string)($check['status'] ?? '');
        if ($st === 'Fail' && (int)($check['waived_findings'] ?? 0) > 0 && ($check['unwaived_findings'] ?? null) !== null && (int)$check['unwaived_findings'] === 0) {
            return 'Waived';
        }
        return $st;
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
                // Grip's own sanction decision, kept as a column (not only inside
                // risk_type) so "sanctioned in Grip, not in TPRM" can be filtered.
                'grip_sanction_tag'    => (isset($g['sanctionTag']) && is_string($g['sanctionTag']) && trim($g['sanctionTag']) !== '')
                    ? $this->clip(strtolower(trim($g['sanctionTag'])), 20) : null,
                'grip_id'              => $gripId,
                'source'               => 'grip',
                'grip_app_classification' => self::normalizeEnum($g['appClassification'] ?? null, self::APP_CLASSIFICATIONS),
                'grip_mfa_status'      => self::normalizeEnum($g['mfaStatus'] ?? null, self::MFA_STATUSES),
                // Grip's AI depth, kept as its own columns so the AI badge and the
                // row details never depend on parsing risk_type.
                'grip_ai_depth_level'  => (is_string($g['aiDepth']['level'] ?? null) && trim($g['aiDepth']['level']) !== '') ? $this->clip(trim($g['aiDepth']['level']), 255) : null,
                'grip_ai_depth_description' => (is_string($g['aiDepth']['description'] ?? null) && trim($g['aiDepth']['description']) !== '') ? trim($g['aiDepth']['description']) : null,
            ];
            // Grip's per-app breach history. breaches_in_three_years feeds the
            // onboarding PII/PHI justification, so it is filled from Grip when
            // Grip reports a history (an empty list means "none recorded" -> 0).
            $breaches = self::normalizeBreachHistory($g['breachHistory'] ?? null);
            if ($breaches !== null) {
                $update['grip_breach_history'] = $breaches ? json_encode($breaches) : null;
                $update['breaches_in_three_years'] = self::countRecentBreaches($breaches, 3);
            }

            $existing = null;
            if (!empty($update['grip_id'])) {
                $existing = $this->db->fetchOne("SELECT id, relationship_manager, status, grip_sanction_tag FROM shadow_saas WHERE grip_id = :gid LIMIT 1", [':gid' => $update['grip_id']]);
            }
            if (!$existing && $domain !== '') {
                $existing = $this->db->fetchOne("SELECT id, relationship_manager, status, grip_sanction_tag FROM shadow_saas WHERE vendor_domain = :d LIMIT 1", [':d' => $domain]);
            }
            if ($existing) {
                // Dismissed rows are an explicit admin decision - leave them
                // fully intact (no field updates from Grip) until Grip's own
                // sanction state changes. Moving off Tolerated in Grip
                // (Sanctioned, Unsanctioned, Under Review, ...) brings the row
                // back to Pending so the two systems agree.
                if (($existing['status'] ?? '') === 'dismissed') {
                    if ($update['grip_sanction_tag'] === ($existing['grip_sanction_tag'] ?? null)) {
                        continue;
                    }
                    if ($update['grip_sanction_tag'] !== 'tolerated') {
                        $update['status'] = 'pending';
                    }
                }
                // Only stamp the relationship manager if the row doesn't already
                // have one - manual edits in the UI win over Grip's value.
                if (empty($existing['relationship_manager']) && $relationshipManager !== '') {
                    $update['relationship_manager'] = $relationshipManager;
                }
                // A locally Denied row (status 'unsanctioned', Grip Unsanctioned)
                // follows Grip when someone there moves it on: Sanctioned, Under
                // Review or New bring it back to Pending.
                if (($existing['status'] ?? '') === 'unsanctioned'
                    && $update['grip_sanction_tag'] !== ($existing['grip_sanction_tag'] ?? null)
                    && !in_array($update['grip_sanction_tag'], ['unsanctioned', 'tolerated', null], true)) {
                    $update['status'] = 'pending';
                }
                // Tolerated in Grip is the counterpart of Dismissed here
                // (shadow-saas.php marks dismissed Grip apps Tolerated).
                if ($update['grip_sanction_tag'] === 'tolerated') {
                    $update['status'] = 'dismissed';
                }
                $this->db->update('shadow_saas', $update, 'id = :id', [':id' => $existing['id']]);
            } else {
                $update['created_by'] = $createdBy;
                $update['status'] = $update['grip_sanction_tag'] === 'tolerated' ? 'dismissed' : 'pending';
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
            if (!self::alertIsOnApp($a)) continue;
            $rel = is_array($a['relatedEntity'] ?? null) ? $a['relatedEntity'] : [];

            $gripId  = (string)($rel['id'] ?? ($a['relatedEntityId'] ?? ''));
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
            $mitigation = trim((string)($a['mitigationDescription'] ?? ''));
            if ($mitigation !== '') $meta[] = 'Recommended action: ' . $mitigation;
            $meta[]  = 'Source: Grip Security' . (!empty($a['alertId']) ? ' (alert ' . $a['alertId'] . ')' : '')
                     . (!empty($a['policyReadableId']) ? ', policy #' . (int)$a['policyReadableId'] : '') . '.';
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
            // First-party feed: Grip tells us which SaaS app the incident is on,
            // so there is no AI mis-attribution risk and no evidence to fetch.
            $data['skip_verification'] = true;

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
    /** Grip SanctionState (write) => SanctionTag (as read back on gripData.sanctionTag). */
    private const SANCTION_STATES = [
        'Sanctioned'    => 'sanctioned',
        'NotSanctioned' => 'unsanctioned',
        'Tolerated'     => 'tolerated',
        'UnderReview'   => 'under review',
        'New'           => 'new',
    ];

    /**
     * Set an app's sanction state in Grip
     * (POST /public/saas/{id}/sanction_status?new_sanction_state=...).
     * On success the local mirror row is updated to match, so the change shows
     * before the next sync. Returns ['success' => bool, 'error' => string].
     */
    public function setSanctionState(string $gripId, string $state, string $comment = ''): array
    {
        $gripId = trim($gripId);
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Grip integration is not enabled'];
        }
        if (!isset(self::SANCTION_STATES[$state])) {
            return ['success' => false, 'error' => 'Unknown sanction state'];
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $gripId)) {
            return ['success' => false, 'error' => 'Invalid Grip app id'];
        }
        $query = ['new_sanction_state' => $state];
        $comment = trim($comment);
        if ($comment !== '') $query['comment'] = $this->clip($comment, 500);

        $res = $this->request('/' . rawurlencode($gripId) . '/sanction_status', $query, 'POST');
        if (empty($res['success'])) {
            $why = $res['error'] ?: ('HTTP ' . $res['http_code']);
            error_log("Grip sanction update failed for {$gripId}: {$why}");
            return ['success' => false, 'error' => $why];
        }
        try {
            $this->db->update('shadow_saas_grip_apps', ['sanction_tag' => self::SANCTION_STATES[$state]], 'grip_id = :gid', [':gid' => $gripId]);
            $this->db->update('shadow_saas', ['grip_sanction_tag' => self::SANCTION_STATES[$state]], 'grip_id = :gid', [':gid' => $gripId]);
        } catch (Throwable $e) {
            error_log('Grip sanction mirror update failed for ' . $gripId . ': ' . $e->getMessage());
        }
        return ['success' => true, 'error' => ''];
    }

    // ------------------------------------------------------------------
    // Queued sanction writes (bulk Shadow SaaS actions)
    // ------------------------------------------------------------------

    /** Grip tag each queueable sanction state leaves on the app. */
    private const QUEUEABLE_STATES = ['Tolerated' => 'tolerated', 'UnderReview' => 'under review', 'NotSanctioned' => 'unsanctioned'];

    /**
     * Queue a Grip sanction write for a Shadow SaaS row. Rows that are not from
     * Grip, or that Grip already shows in that state, are not queued. Returns
     * true when queued.
     */
    public function queueSanction(array $entry, string $state, int $userId): bool
    {
        if (!isset(self::QUEUEABLE_STATES[$state])) return false;
        if (($entry['source'] ?? '') !== 'grip' || !self::isGripUuid((string)($entry['grip_id'] ?? ''))) return false;
        if (($entry['grip_sanction_tag'] ?? '') === self::QUEUEABLE_STATES[$state]) return false;
        $this->db->update('shadow_saas', [
            'grip_pending_state' => $state,
            'grip_pending_by'    => $userId ?: null,
            'grip_pending_at'    => date('Y-m-d H:i:s'),
            'grip_pending_error' => null,
        ], 'id = :id', [':id' => (int)$entry['id']]);
        return true;
    }

    /**
     * Write queued sanction states to Grip, oldest first, until $cap rows or
     * $seconds have been spent. A failure stays queued (error recorded) and is
     * retried on the next drain. Each write is audited like an inline one.
     * @return array{sent:int, failed:int, remaining:int}
     */
    public function drainPendingSanctions(int $cap = 200, int $seconds = 240): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'remaining' => 0];
        if (!$this->isEnabled()) return $out;
        $deadline = microtime(true) + max(1, $seconds);
        $rows = $this->db->fetchAll(
            "SELECT s.id, s.grip_id, s.grip_pending_state, s.grip_pending_by, u.full_name, u.username
               FROM shadow_saas s LEFT JOIN users u ON u.id = s.grip_pending_by
              WHERE s.grip_pending_state IS NOT NULL AND s.source = 'grip' AND s.grip_id IS NOT NULL
              ORDER BY s.grip_pending_at, s.id LIMIT " . max(1, min(1000, $cap))
        );
        $labels = ['Tolerated' => 'Dismissed', 'UnderReview' => 'Restored for review', 'NotSanctioned' => 'Denied'];
        foreach ($rows as $r) {
            if (microtime(true) > $deadline) break;
            $state = (string)$r['grip_pending_state'];
            if (!isset(self::QUEUEABLE_STATES[$state])) {
                $this->db->update('shadow_saas', ['grip_pending_state' => null], 'id = :id', [':id' => (int)$r['id']]);
                continue;
            }
            $who = trim((string)($r['full_name'] ?? '')) ?: (string)($r['username'] ?? ('user #' . (int)$r['grip_pending_by']));
            $res = $this->setSanctionState((string)$r['grip_id'], $state, ($labels[$state] ?? $state) . ' in FairTPRM Shadow SaaS by ' . $who);
            try {
                Auth::getInstance()->audit($r['grip_pending_by'] ? (int)$r['grip_pending_by'] : null, 'shadow_saas_grip_sanction', 'shadow_saas', (int)$r['id'], [
                    'new' => ['grip_id' => (string)$r['grip_id'], 'state' => $state, 'success' => $res['success'], 'error' => $res['error'], 'queued' => true],
                ]);
            } catch (Throwable $e) {}
            if ($res['success']) {
                $this->db->update('shadow_saas', ['grip_pending_state' => null, 'grip_pending_error' => null], 'id = :id', [':id' => (int)$r['id']]);
                $out['sent']++;
                try { $this->applyWriteback((string)$r['grip_id']); } catch (Throwable $e) {}
            } else {
                $this->db->update('shadow_saas', ['grip_pending_error' => $this->clip((string)$res['error'], 500)], 'id = :id', [':id' => (int)$r['id']]);
                $out['failed']++;
            }
        }
        $out['remaining'] = (int)($this->db->fetchOne("SELECT COUNT(*) AS c FROM shadow_saas WHERE grip_pending_state IS NOT NULL AND source = 'grip'")['c'] ?? 0);
        return $out;
    }

    // ------------------------------------------------------------------
    // Fast discovery of newly detected apps
    // ------------------------------------------------------------------

    /** Most unknown apps one discovery run fetches individually. */
    private const DISCOVERY_CAP = 50;

    /**
     * Pick up apps Grip has just detected without waiting for the full sync.
     * Reads the alert list (a couple of requests), and for every "New
     * Unmanaged App/AI App Detected" alert on an app the mirror does not hold
     * yet, fetches that one app (GET /saas/{id}), mirrors it, projects it into
     * Shadow SaaS and snapshots its roster. Apps Grip discovers without an alert
     * are still picked up by the next full sync. Never deletes anything.
     * @return array{alerts:int, new_apps:int, failed:int}
     */
    public function discoverNewApps(): array
    {
        $out = ['alerts' => 0, 'new_apps' => 0, 'failed' => 0];
        if (!$this->isEnabled()) return $out;
        $alerts = $this->fetchAlerts();
        $out['alerts'] = $this->upsertAlerts($alerts);

        $known = [];
        foreach ($this->db->fetchAll("SELECT grip_id FROM shadow_saas_grip_apps") as $r) $known[strtolower((string)$r['grip_id'])] = true;
        $want = [];
        foreach ($alerts as $a) {
            if (!self::alertIsOnApp($a)) continue;
            if (stripos((string)($a['alertType'] ?? ''), 'New Unmanaged') !== 0) continue;
            $gid = (string)($a['relatedEntityId'] ?? ($a['relatedEntity']['id'] ?? ''));
            if (!self::isGripUuid($gid) || isset($known[strtolower($gid)])) continue;
            $want[$gid] = true;
        }
        $apps = [];
        foreach (array_slice(array_keys($want), 0, self::DISCOVERY_CAP) as $gid) {
            $app = $this->getAppDetail($gid);
            if (is_array($app) && !empty($app['id'])) $apps[] = $app;
            else $out['failed']++;
        }
        if (!$apps) return $out;
        $this->upsertApps($apps);
        $this->projectAppsToShadowSaas($apps, null);
        foreach ($apps as $app) {
            try { $this->syncAppUsers((string)$app['id']); }
            catch (Throwable $e) { error_log('Grip discovery roster fetch failed for ' . $app['id'] . ': ' . $e->getMessage()); }
            try { $this->applyWriteback((string)$app['id']); }
            catch (Throwable $e) { error_log('Grip discovery write-back failed for ' . $app['id'] . ': ' . $e->getMessage()); }
        }
        $out['new_apps'] = count($apps);
        return $out;
    }

    // ------------------------------------------------------------------
    // TPRM -> Grip write-back: labels and primary contact
    // ------------------------------------------------------------------

    /** Every label FairTPRM writes starts with this; no other label is ever touched. */
    public const LABEL_PREFIX = 'FairTPRM: ';
    /** Most Grip writes one sync's reconcile step may make (the rest wait for the next run). */
    private const WRITEBACK_CAP = 300;
    private const WRITEBACK_THROTTLE_MS = 100;

    public function writebackEnabled(): bool { return $this->writebackEnabled && $this->isEnabled(); }

    /** POST /public/saas/{id}/label with {"label": ...}. */
    public function addLabel(string $gripId, string $label): array
    {
        if (!self::isGripUuid($gripId)) return ['success' => false, 'error' => 'Invalid Grip app id'];
        $res = $this->request('/' . rawurlencode($gripId) . '/label', [], 'POST', ['label' => $label]);
        return ['success' => !empty($res['success']), 'error' => !empty($res['success']) ? '' : ($res['error'] ?: 'HTTP ' . $res['http_code'])];
    }

    /** DELETE /public/saas/{id}/label with {"label": ...}. A 404 (already gone) counts as success. */
    public function removeLabel(string $gripId, string $label): array
    {
        if (!self::isGripUuid($gripId)) return ['success' => false, 'error' => 'Invalid Grip app id'];
        $res = $this->request('/' . rawurlencode($gripId) . '/label', [], 'DELETE', ['label' => $label]);
        $ok = !empty($res['success']) || (int)($res['http_code'] ?? 0) === 404;
        return ['success' => $ok, 'error' => $ok ? '' : ($res['error'] ?: 'HTTP ' . $res['http_code'])];
    }

    /** GET /public/users/{mail}/id. Returns the Grip userId, or null when the email is not a Grip user. */
    public function getUserIdByEmail(string $email): ?string
    {
        $key = strtolower(trim($email));
        if ($key === '' || !filter_var($key, FILTER_VALIDATE_EMAIL)) return null;
        if (array_key_exists($key, $this->userIdCache)) return $this->userIdCache[$key] ?: null;
        $res = $this->request('/public/users/' . rawurlencode($key) . '/id');
        $id = (!empty($res['success']) && is_array($res['data'] ?? null)) ? (string)($res['data']['userId'] ?? '') : '';
        if ($id !== '' && !self::isGripUuid($id)) $id = '';
        // Only cache a definite answer; a transient failure is retried next time.
        if ($id !== '' || in_array((int)($res['http_code'] ?? 0), [404, 422], true)) $this->userIdCache[$key] = $id;
        return $id !== '' ? $id : null;
    }

    /** POST /public/saas/{id}/update-primary-contact?user_id=... */
    public function setPrimaryContact(string $gripId, string $userId): array
    {
        if (!self::isGripUuid($gripId) || !self::isGripUuid($userId)) return ['success' => false, 'error' => 'Invalid Grip id'];
        $res = $this->request('/' . rawurlencode($gripId) . '/update-primary-contact', ['user_id' => $userId], 'POST');
        return ['success' => !empty($res['success']), 'error' => !empty($res['success']) ? '' : ($res['error'] ?: 'HTTP ' . $res['http_code'])];
    }

    public static function isGripUuid(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($id));
    }

    /**
     * The TPRM picture of one Grip app: the onboarded vendor it maps to (by
     * registrable domain, any status but inactive) and/or its Shadow SaaS row.
     */
    private function tprmStateForApp(string $gripId): array
    {
        $app = $this->db->fetchOne("SELECT grip_id, domain, labels, primary_contact_user_id FROM shadow_saas_grip_apps WHERE grip_id = :g", [':g' => $gripId]);
        $vendor = null;
        $domain = trim((string)($app['domain'] ?? ''));
        if ($domain !== '') {
            $vendor = $this->db->fetchOne(
                "SELECT id, vendor_tier, relationship_manager FROM vendor_onboarding_requests
                  WHERE vendor_domain = :d AND status NOT IN ('inactive', 'rejected') ORDER BY id LIMIT 1",
                [':d' => $domain]
            ) ?: null;
        }
        $row = $this->db->fetchOne("SELECT status, grip_sanction_tag FROM shadow_saas WHERE grip_id = :g LIMIT 1", [':g' => $gripId]) ?: null;
        return ['app' => $app ?: null, 'vendor' => $vendor, 'row' => $row];
    }

    /**
     * Labels FairTPRM wants on a Grip app:
     *   onboarded vendor (not inactive/rejected) -> Onboarded + Tier N
     *   Shadow SaaS dismissed     -> Tolerated
     *   Shadow SaaS denied        -> Denied
     *   pending, Under Review     -> Under Review
     */
    public static function desiredLabels(?array $vendor, ?array $row): array
    {
        $p = self::LABEL_PREFIX;
        if ($vendor) {
            $out = [$p . 'Onboarded'];
            if (in_array((string)($vendor['vendor_tier'] ?? ''), ['1', '2', '3'], true)) $out[] = $p . 'Tier ' . $vendor['vendor_tier'];
            return $out;
        }
        if (!$row) return [];
        switch ($row['status'] ?? '') {
            case 'dismissed':    return [$p . 'Tolerated'];
            case 'unsanctioned': return [$p . 'Denied'];
            case 'pending':      return ($row['grip_sanction_tag'] ?? '') === 'under review' ? [$p . 'Under Review'] : [];
        }
        return [];
    }

    /**
     * Resolve a relationship manager value to an email: an email as-is, else the
     * one active FairTPRM user whose full name or username matches exactly.
     */
    private function resolveContactEmail(string $rm): ?string
    {
        $rm = trim($rm);
        if ($rm === '') return null;
        if (filter_var($rm, FILTER_VALIDATE_EMAIL)) return strtolower($rm);
        $rows = $this->db->fetchAll(
            "SELECT email FROM users WHERE is_active = 1 AND (full_name = :n OR username = :u) LIMIT 2",
            [':n' => $rm, ':u' => $rm]
        );
        if (count($rows) !== 1) return null;
        $email = strtolower(trim((string)$rows[0]['email']));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Bring one Grip app's FairTPRM labels and primary contact in line with
     * TPRM. Writes only differences. Returns ['writes' => n, 'errors' => [..]].
     * No-op unless write-back is enabled.
     */
    public function applyWriteback(string $gripId): array
    {
        $out = ['writes' => 0, 'errors' => []];
        if (!$this->writebackEnabled() || !self::isGripUuid($gripId)) return $out;
        $st = $this->tprmStateForApp($gripId);
        if (!$st['app']) return $out; // not in the mirror yet; the next sync picks it up
        $wb = $this->db->fetchOne("SELECT * FROM shadow_saas_grip_writeback WHERE grip_id = :g", [':g' => $gripId]) ?: [];

        // ---- labels ----
        $mirrorLabels = json_decode((string)($st['app']['labels'] ?? '[]'), true);
        $mirrorLabels = is_array($mirrorLabels) ? array_values(array_filter($mirrorLabels, 'is_string')) : [];
        $written = json_decode((string)($wb['labels'] ?? '[]'), true);
        $written = is_array($written) ? $written : [];
        $ours = fn(array $l) => array_values(array_filter($l, fn($x) => strpos($x, self::LABEL_PREFIX) === 0));
        $current = array_values(array_unique(array_merge($ours($mirrorLabels), $ours($written))));
        $desired = self::desiredLabels($st['vendor'], $st['row']);
        $final = $current;
        foreach (array_diff($current, $desired) as $label) {
            $r = $this->removeLabel($gripId, $label);
            $out['writes']++;
            if ($r['success']) $final = array_values(array_diff($final, [$label]));
            else $out['errors'][] = "remove label {$label}: {$r['error']}";
        }
        foreach (array_diff($desired, $current) as $label) {
            $r = $this->addLabel($gripId, $label);
            $out['writes']++;
            if ($r['success']) $final[] = $label;
            else $out['errors'][] = "add label {$label}: {$r['error']}";
        }
        $final = array_values(array_unique($final));
        $nonOurs = array_values(array_filter($mirrorLabels, fn($x) => strpos($x, self::LABEL_PREFIX) !== 0));

        // ---- primary contact (onboarded vendors only) ----
        $contact = [
            'contact_source'  => $wb['contact_source'] ?? null,
            'contact_user_id' => $wb['contact_user_id'] ?? null,
            'contact_status'  => $wb['contact_status'] ?? null,
        ];
        $mirrorContact = $this->decryptGripRow('shadow_saas_grip_apps', ['primary_contact_user_id' => $st['app']['primary_contact_user_id'] ?? null])['primary_contact_user_id'];
        $rm = trim((string)($st['vendor']['relationship_manager'] ?? ''));
        if ($rm !== '') {
            $unchanged = ($contact['contact_source'] === $rm)
                && (in_array($contact['contact_status'], ['not_grip_user', 'no_email'], true)
                    || ($contact['contact_status'] === 'set' && (string)$contact['contact_user_id'] === (string)$mirrorContact));
            if (!$unchanged) {
                $contact['contact_source'] = $rm;
                $email = $this->resolveContactEmail($rm);
                if ($email === null) {
                    $contact['contact_status'] = 'no_email';
                    $contact['contact_user_id'] = null;
                } elseif (($uid = $this->getUserIdByEmail($email)) === null) {
                    $contact['contact_status'] = 'not_grip_user';
                    $contact['contact_user_id'] = null;
                } elseif (strcasecmp($uid, (string)$mirrorContact) === 0) {
                    $contact['contact_status'] = 'set';
                    $contact['contact_user_id'] = $uid;
                } else {
                    $r = $this->setPrimaryContact($gripId, $uid);
                    $out['writes']++;
                    if ($r['success']) {
                        $contact['contact_status'] = 'set';
                        $contact['contact_user_id'] = $uid;
                        $mirrorContact = $uid;
                        $this->db->update('shadow_saas_grip_apps',
                            $this->encryptRow('shadow_saas_grip_apps', ['primary_contact_user_id' => $uid, 'primary_contact_email' => $email]),
                            'grip_id = :g', [':g' => $gripId]);
                    } else {
                        $contact['contact_status'] = 'error';
                        $out['errors'][] = "primary contact: {$r['error']}";
                    }
                }
            }
        }

        try {
            $this->db->update('shadow_saas_grip_apps', ['labels' => json_encode(array_merge($nonOurs, $final))], 'grip_id = :g', [':g' => $gripId]);
            $this->upsert('shadow_saas_grip_writeback', [
                'grip_id'         => $gripId,
                'vendor_id'       => isset($st['vendor']['id']) ? (int)$st['vendor']['id'] : null,
                'labels'          => json_encode($final),
                'contact_source'  => $contact['contact_source'] !== null ? $this->clip((string)$contact['contact_source'], 255) : null,
                'contact_user_id' => $contact['contact_user_id'],
                'contact_status'  => $contact['contact_status'],
                'last_error'      => $out['errors'] ? $this->clip(implode('; ', $out['errors']), 500) : null,
            ], 'grip_id');
        } catch (Throwable $e) {
            error_log('Grip write-back state save failed for ' . $gripId . ': ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Sync step: walk every Grip app TPRM has an opinion on (onboarded vendors
     * matched by domain, Shadow SaaS rows, and apps FairTPRM labeled before) and
     * apply write-back, up to WRITEBACK_CAP writes per run.
     */
    public function reconcileWriteback(): array
    {
        $sum = ['apps' => 0, 'writes' => 0, 'errors' => 0];
        if (!$this->writebackEnabled()) return $sum;
        $ids = [];
        foreach ($this->db->fetchAll(
            "SELECT DISTINCT a.grip_id FROM shadow_saas_grip_apps a
               JOIN vendor_onboarding_requests v ON v.vendor_domain = a.domain AND v.status NOT IN ('inactive', 'rejected')
              WHERE a.domain IS NOT NULL AND a.domain != ''") as $r) $ids[$r['grip_id']] = true;
        foreach ($this->db->fetchAll(
            "SELECT grip_id FROM shadow_saas WHERE source = 'grip' AND grip_id IS NOT NULL
               AND (status IN ('dismissed', 'unsanctioned') OR (status = 'pending' AND grip_sanction_tag = 'under review'))") as $r) $ids[$r['grip_id']] = true;
        foreach ($this->db->fetchAll("SELECT grip_id FROM shadow_saas_grip_writeback WHERE labels IS NOT NULL AND labels != '[]'") as $r) $ids[$r['grip_id']] = true;
        foreach (array_keys($ids) as $gid) {
            if ($sum['writes'] >= self::WRITEBACK_CAP) {
                error_log('Grip write-back: reached the per-run cap of ' . self::WRITEBACK_CAP . ' writes; the rest continue next sync');
                break;
            }
            try {
                $r = $this->applyWriteback((string)$gid);
            } catch (Throwable $e) {
                $r = ['writes' => 0, 'errors' => [$e->getMessage()]];
                error_log('Grip write-back failed for ' . $gid . ': ' . $e->getMessage());
            }
            $sum['apps']++;
            $sum['writes'] += $r['writes'];
            $sum['errors'] += count($r['errors']);
            if ($r['writes'] > 0 && self::WRITEBACK_THROTTLE_MS > 0) usleep(self::WRITEBACK_THROTTLE_MS * 1000);
        }
        return $sum;
    }

    // ------------------------------------------------------------------
    // Justification requests ("why do you use this app?")
    // ------------------------------------------------------------------

    public const JUSTIFICATION_PLATFORMS = ['Email', 'Slack', 'Microsoft Teams'];
    /** Grip statuses after which a request no longer changes. */
    private const JUSTIFICATION_FINAL = ['Responded', 'Canceled'];

    /**
     * POST /public/actions/justification/send for one user of one app. Never
     * retried on timeout (a retry could message the user twice). Records the
     * request in shadow_saas_grip_justifications either way.
     */
    public function sendJustification(string $gripSaasId, string $userGripId, string $userMail, string $platform, int $sentBy): array
    {
        if (!$this->isEnabled()) return ['success' => false, 'error' => 'Grip integration is not enabled'];
        if (!self::isGripUuid($gripSaasId) || !self::isGripUuid($userGripId)) return ['success' => false, 'error' => 'Invalid Grip id'];
        if (!in_array($platform, self::JUSTIFICATION_PLATFORMS, true)) return ['success' => false, 'error' => 'Unknown platform'];
        $res = $this->request('/public/actions/justification/send', [], 'POST', [
            'userId'                => $userGripId,
            'saasApplicationId'     => $gripSaasId,
            'communicationPlatform' => $platform,
        ], false);
        $reqId = (!empty($res['success']) && is_array($res['data'] ?? null)) ? (string)($res['data']['requestId'] ?? '') : '';
        $ok = !empty($res['success']);
        $err = $ok ? '' : ($res['error'] ?: 'HTTP ' . $res['http_code']);
        $this->db->insert('shadow_saas_grip_justifications', [
            'grip_saas_id' => $gripSaasId,
            'user_grip_id' => $userGripId,
            'user_mail'    => $userMail !== '' ? $this->enc()->encrypt($userMail) : null,
            'platform'     => $platform,
            'request_id'   => $reqId !== '' ? $this->clip($reqId, 64) : null,
            'status'       => $ok ? 'Sent' : 'failed',
            'last_error'   => $ok ? null : $this->clip($err, 500),
            'sent_by'      => $sentBy ?: null,
        ]);
        return ['success' => $ok, 'error' => $err, 'request_id' => $reqId];
    }

    /** Refresh the status of open justification requests (newest first, capped). */
    public function refreshJustificationStatuses(?string $gripSaasId = null, int $cap = 100): int
    {
        if (!$this->isEnabled()) return 0;
        $final = "'" . implode("','", self::JUSTIFICATION_FINAL) . "'";
        $sql = "SELECT id, request_id FROM shadow_saas_grip_justifications
                 WHERE request_id IS NOT NULL AND status NOT IN ({$final}, 'failed')
                   AND created_at > (NOW() - INTERVAL 60 DAY)";
        $params = [];
        if ($gripSaasId !== null) { $sql .= ' AND grip_saas_id = :g'; $params[':g'] = $gripSaasId; }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $cap));
        $n = 0;
        foreach ($this->db->fetchAll($sql, $params) as $j) {
            if (!self::isGripUuid((string)$j['request_id'])) continue;
            $res = $this->request('/public/actions/justification/' . rawurlencode($j['request_id']) . '/status');
            $status = (!empty($res['success']) && is_array($res['data'] ?? null)) ? (string)($res['data']['status'] ?? '') : '';
            if ($status !== '') {
                $this->db->update('shadow_saas_grip_justifications', ['status' => $this->clip($status, 32)], 'id = :id', [':id' => (int)$j['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Justification requests for one app, PII decrypted, newest first. */
    public function getJustifications(string $gripSaasId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT j.*, u.full_name AS sent_by_name FROM shadow_saas_grip_justifications j
               LEFT JOIN users u ON u.id = j.sent_by
              WHERE j.grip_saas_id = :g ORDER BY j.id DESC",
            [':g' => $gripSaasId]
        );
        foreach ($rows as &$r) {
            if (!empty($r['user_mail'])) {
                try { $r['user_mail'] = $this->enc()->decrypt((string)$r['user_mail']); } catch (Throwable $e) {}
            }
        }
        return $rows;
    }

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
        // Paginate - large apps can have many users. fetchAll() pages until an
        // empty page (Grip can return a short page mid-roster) and throws on a
        // hard failure.
        return $this->fetchAll('/' . rawurlencode($gripId) . '/users', "Grip roster fetch for {$gripId}");
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
        // Grip caps a page at PAGE_SIZE, so gather the window [offset, offset+limit)
        // in chunks. Bound by the cursor, not the row count: a short page leaves
        // fewer rows than asked for, and topping up would spill past the window.
        $end = $offset + $limit;
        while ($cursor < $end) {
            $chunk = min(self::PAGE_SIZE, $end - $cursor);
            $res = $this->request($base, ['offset' => $cursor, 'limit' => $chunk]);
            if (empty($res['success']) || !is_array($res['data']) || empty($res['data'])) break;
            foreach ($res['data'] as $entry) {
                $row = $this->mapGripAppUser($entry, $gripId);
                if ($row !== null) $rows[] = $row;
            }
            // Advance by what we ASKED for: Grip can return a short page mid-list,
            // so neither the row count nor a short page marks the end.
            $cursor += $chunk;
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
    /**
     * On-demand roster fetch for apps Grip first saw in the last $days days that
     * have users but no cached roster yet (the "New" tab's Refresh button, so
     * patient zero is available before the next scheduled sync). Capped and
     * throttled. @return int rosters fetched
     */
    public function refreshRecentRosters(int $days, int $cap = 200): int
    {
        if (!$this->isEnabled()) return 0;
        $days = max(1, min($days, 365));
        // first_event_time is stored in the app time zone (parseDate), so the
        // cutoff is computed in PHP rather than with the database's NOW().
        $rows = $this->db->fetchAll(
            "SELECT a.grip_id FROM shadow_saas_grip_apps a
              WHERE a.first_event_time >= :cutoff
                AND a.number_of_users > 0
                AND NOT EXISTS (SELECT 1 FROM shadow_saas_grip_app_users u WHERE u.grip_saas_id = a.grip_id)
              ORDER BY a.first_event_time DESC
              LIMIT " . max(1, min($cap, 500)),
            [':cutoff' => date('Y-m-d H:i:s', time() - $days * 86400)]
        );
        $n = 0;
        foreach ($rows as $r) {
            try {
                $this->syncAppUsers((string)$r['grip_id']);
                $n++;
            } catch (Throwable $e) {
                error_log('Grip recent roster fetch failed for ' . $r['grip_id'] . ': ' . $e->getMessage());
            }
            if (self::ROSTER_THROTTLE_MS > 0) usleep(self::ROSTER_THROTTLE_MS * 1000);
        }
        return $n;
    }

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
    /** Grip AI depth levels that mean the app has AI capability, most to least AI. */
    public const AI_DEPTH_LEVELS = ['AI-Native App', 'AI Copilot Experience', 'AI Features/Add-on'];

    /**
     * Grip's AI privacy Q&A (gripData.termsAndConditions.questions, stored as
     * JSON in shadow_saas_grip_apps.terms_questions) keyed by question text:
     * ['Trains on customer data' => ['answer' => 'Yes', 'detail' => '...'], ...].
     */
    public static function aiPrivacyAnswers(?string $termsJson): array
    {
        $out = [];
        $q = json_decode((string)$termsJson, true);
        if (!is_array($q)) return $out;
        foreach ($q as $item) {
            if (!is_array($item) || empty($item['question'])) continue;
            $out[(string)$item['question']] = [
                'answer' => trim((string)($item['answer'] ?? '')),
                'detail' => trim((string)($item['detailedAnswer'] ?? '')),
            ];
        }
        return $out;
    }

    /** Normalize a "Trains on customer data" answer to yes / no / optional / unknown. */
    public static function aiTrainsBucket(?string $answer): string
    {
        $a = strtolower(trim((string)$answer));
        if ($a === '') return 'unknown';
        if (str_starts_with($a, 'yes')) return 'yes';
        if (str_starts_with($a, 'no')) return 'no';
        if (str_starts_with($a, 'optional')) return 'optional';
        return 'unknown';
    }

    /** Grip appClassification values (how the app is used in the organization). */
    public const APP_CLASSIFICATIONS = ['workspace', 'mixed_use'];
    /** Grip mfaStatus values (whether the app actually enforces MFA for its users). */
    public const MFA_STATUSES = ['Enabled', 'Not Enabled', 'Partial', 'Unknown'];

    /** Return $val if it is one of $allowed (case-insensitive), in canonical case; else null. */
    public static function normalizeEnum($val, array $allowed): ?string
    {
        if (!is_string($val)) return null;
        foreach ($allowed as $a) {
            if (strcasecmp(trim($val), $a) === 0) return $a;
        }
        return null;
    }

    /**
     * Normalize Grip's gripData.breachHistory into [{date: Y-m-d|null, types: [..],
     * url: http(s) url|null}], newest first. Returns null when Grip sent no
     * history field at all (so callers can leave existing values alone), [] for
     * an empty history.
     */
    public static function normalizeBreachHistory($raw): ?array
    {
        if (!is_array($raw)) return null;
        $out = [];
        foreach ($raw as $b) {
            if (!is_array($b)) continue;
            $ts = isset($b['date']) && is_string($b['date']) ? strtotime($b['date']) : false;
            $types = [];
            foreach ((array)($b['breachTypes'] ?? []) as $t) {
                if (is_string($t) && trim($t) !== '') $types[] = mb_substr(trim($t), 0, 100);
            }
            $url = isset($b['url']) && is_string($b['url']) && preg_match('#^https?://#i', trim($b['url'])) ? mb_substr(trim($b['url']), 0, 2048) : null;
            $out[] = ['date' => $ts ? date('Y-m-d', $ts) : null, 'types' => array_slice($types, 0, 20), 'url' => $url];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
        return array_slice($out, 0, 100);
    }

    /** Number of breaches dated within the last $years years. */
    public static function countRecentBreaches(array $breaches, int $years): int
    {
        $cut = date('Y-m-d', strtotime("-{$years} years"));
        return count(array_filter($breaches, fn($b) => !empty($b['date']) && $b['date'] >= $cut));
    }

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
