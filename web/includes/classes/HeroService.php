<?php
/**
 * HeroService — HERO Security public API client + Shadow SaaS ingest.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Parallel to GripService: talks to the HERO public API
 * (default https://api.herosecurity.ai/stable) and hydrates the
 * shadow_saas_hero_* mirror tables, then projects normalized rows into the
 * shared shadow_saas table so the existing UI renders HERO data with no further
 * wiring. Grip and Hero are MUTUALLY EXCLUSIVE (only one enabled at a time).
 *
 * Auth: OAuth 2.0 client-credentials. POST /v1/auth/token with client_id +
 * client_secret (form-urlencoded) -> Bearer token (~1h), sent as
 * "Authorization: Bearer <token>" on all data endpoints.
 *
 * Endpoints:
 *   GET  /v1/health                       — health (no auth)
 *   POST /v1/auth/token                   — client-credentials token exchange
 *   GET  /v1/vendors                      — list vendors (cursor paginated)
 *   GET  /v1/vendors/{domain}             — one vendor
 *   GET  /v1/vendors/{domain}/users       — users for a vendor (cursor paginated)
 *   GET  /v1/issues                       — list issues (cursor paginated)
 *   GET  /v1/issues/{issue_id}            — one issue
 *
 * Pagination is cursor-based (next_cursor -> cursor), page_size max 250.
 * HERO has no native vendor risk score, so we DERIVE a 1-5 score from the worst
 * open issue per vendor (low=2/medium=3/high=4/critical=5) and STORE it on
 * shadow_saas.risk_score — the same 1-5 scale Grip stores.
 */

class HeroService
{
    private const DEFAULT_BASE_URL = 'https://api.herosecurity.ai/stable';
    private const PAGE_SIZE = 250;
    private const REQUEST_TIMEOUT = 30;
    private const USERS_TIMEOUT = 15;       // shorter timeout for per-vendor users so a
                                            // slow huge-tenant /users call fails fast and
                                            // is skipped, rather than burning 30s each
    private const MAX_RETRIES = 5;          // 429 backoff attempts per request
    // HERO's limit is ~60 requests / 60s (account-wide, all endpoints), returning
    // Retry-After: 50 on 429. Pace per-vendor calls at ~1.1s (~55/min) to stay
    // under the cap and avoid 429 churn — the quota is the real floor anyway
    // (671 vendors / 60-per-min ≈ 11 min minimum).
    private const THROTTLE_USEC = 1100000;  // 1.1s between per-vendor calls

    // Worst-severity ranking + the 1-5 risk score we STORE (in the DB, not just a
    // UX transform) so Hero matches the same 1-5 scale Grip uses.
    //   low=2, medium=3, high=4, critical=5  (mirrors Grip's 0-100 -> 1-5 buckets)
    private const SEVERITY_RANK  = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
    private const SEVERITY_SCORE = ['low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];

    private Database $db;
    private string $baseUrl = '';
    private string $clientId = '';
    private string $clientSecret = '';
    private bool $enabled = false;

    private string $token = '';
    private int $tokenExpiry = 0;

    // Admin-tunable pacing (defaults to the constants; overridable in the GUI).
    private int $usersTimeout = self::USERS_TIMEOUT;  // seconds, per-vendor users call
    private int $throttleUsec = self::THROTTLE_USEC;  // microseconds between vendor calls

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
            ['hero_%']
        );
        foreach ($rows as $row) {
            $val = ($row['is_encrypted'] && !empty($row['config_value']))
                ? $encryption->decrypt($row['config_value'])
                : $row['config_value'];
            switch ($row['config_key']) {
                case 'hero_enabled':       $this->enabled = ($val === '1'); break;
                case 'hero_base_url':      $this->baseUrl = is_string($val) ? trim($val) : ''; break;
                case 'hero_client_id':     $this->clientId = is_string($val) ? trim($val) : ''; break;
                case 'hero_client_secret': $this->clientSecret = is_string($val) ? trim($val) : ''; break;
                case 'hero_users_timeout': // seconds (5–120)
                    $n = (int)$val; if ($n >= 5 && $n <= 120) $this->usersTimeout = $n; break;
                case 'hero_throttle_ms':   // milliseconds between vendor calls (0–10000) -> usec
                    $n = (int)$val; if ($n >= 0 && $n <= 10000) $this->throttleUsec = $n * 1000; break;
            }
        }
        if ($this->baseUrl === '') $this->baseUrl = self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function isConfigured(): bool { return $this->clientId !== '' && $this->clientSecret !== '' && $this->baseUrl !== ''; }
    public function isEnabled(): bool { return $this->enabled && $this->isConfigured(); }
    public function getBaseUrl(): string { return $this->baseUrl; }

    // ------------------------------------------------------------------
    // Auth + HTTP
    // ------------------------------------------------------------------

    /** Exchange client credentials for a Bearer token (cached for the run). */
    private function authenticate(): array
    {
        if ($this->token !== '' && $this->tokenExpiry > time() + 30) {
            return ['success' => true];
        }
        if (!$this->isConfigured()) {
            return ['success' => false, 'http_code' => 0, 'error' => 'HERO is not configured'];
        }
        $ch = curl_init($this->baseUrl . '/v1/auth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: FairTPRM-Hero-Integration/1.0',
            ],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'http_code' => $code, 'error' => $err ?: 'cURL error'];
        }
        $d = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($d) || empty($d['access_token'])) {
            $msg = ($code === 401) ? 'Unauthorized — client credentials rejected' : ('Token request failed (HTTP ' . $code . ')');
            return ['success' => false, 'http_code' => $code, 'error' => $msg];
        }
        $this->token = (string)$d['access_token'];
        $this->tokenExpiry = time() + (int)($d['expires_in'] ?? 3600);
        return ['success' => true];
    }

    private function request(string $path, array $query = [], int $attempt = 1, ?int $timeout = null): array
    {
        $auth = $this->authenticate();
        if (empty($auth['success'])) {
            return ['success' => false, 'http_code' => $auth['http_code'] ?? 0, 'error' => $auth['error'] ?? 'auth failed', 'data' => null];
        }
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout ?? self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: FairTPRM-Hero-Integration/1.0',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = (int)trim(substr($header, 12));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Rate limited — honour Retry-After (capped) or exponential backoff, then retry.
        if ($code === 429 && $attempt <= self::MAX_RETRIES) {
            // Honour Retry-After fully (HERO sends 50); cap at 60s as a safety net.
            $sleep = $retryAfter > 0 ? min($retryAfter, 60) : min((int)pow(2, $attempt), 60);
            sleep(max(1, $sleep));
            return $this->request($path, $query, $attempt + 1, $timeout);
        }

        if ($body === false) {
            return ['success' => false, 'http_code' => $code, 'error' => $err ?: 'cURL error', 'data' => null];
        }
        $decoded = json_decode($body, true);
        $jsonOk = (json_last_error() === JSON_ERROR_NONE);
        return [
            'success'   => ($code >= 200 && $code < 300 && $jsonOk),
            'http_code' => $code,
            'error'     => $jsonOk ? '' : ('Invalid JSON: ' . json_last_error_msg()),
            'data'      => $jsonOk ? $decoded : null,
        ];
    }

    public function testConnection(): array
    {
        $res = $this->request('/v1/vendors', ['page_size' => 1]);
        if (!$res['success']) {
            $reason = $res['error'] ?: ('HTTP ' . $res['http_code']);
            if ($res['http_code'] === 401) $reason = 'Unauthorized — client credentials rejected';
            if ($res['http_code'] === 404) $reason = 'Endpoint not found — check base URL';
            return ['success' => false, 'message' => $reason];
        }
        $count = isset($res['data']['data']) && is_array($res['data']['data']) ? count($res['data']['data']) : 0;
        return ['success' => true, 'message' => "Connected to HERO — sample returned {$count} record(s)"];
    }

    // ------------------------------------------------------------------
    // Cursor pagination
    // ------------------------------------------------------------------

    /**
     * Fetch only the FIRST page of a list endpoint (up to PAGE_SIZE items). Used
     * for per-vendor users, where paging every vendor's full list would make the
     * scheduled sync run for ~an hour. Exact for vendors with <= PAGE_SIZE users.
     */
    private function fetchUsersPage(string $path): array
    {
        // Use the shorter USERS_TIMEOUT: huge-tenant /users calls that hang should
        // fail fast and be skipped instead of stalling the whole run for 30s each.
        $res = $this->request($path, ['page_size' => self::PAGE_SIZE], 1, $this->usersTimeout);
        if (!$res['success']) {
            throw new RuntimeException("HERO fetch {$path} failed: " . ($res['error'] ?: 'HTTP ' . $res['http_code']));
        }
        $d = is_array($res['data']) ? $res['data'] : [];
        return is_array($d['data'] ?? null) ? $d['data'] : [];
    }

    /** @return array<int,array> All items across all pages of a list endpoint. */
    private function fetchAllCursor(string $path): array
    {
        $all = [];
        $cursor = null;
        $guard = 0;
        while (true) {
            $query = ['page_size' => self::PAGE_SIZE];
            if ($cursor !== null && $cursor !== '') $query['cursor'] = $cursor;
            $res = $this->request($path, $query);
            if (!$res['success']) {
                throw new RuntimeException("HERO fetch {$path} failed: " . ($res['error'] ?: 'HTTP ' . $res['http_code']));
            }
            $d = is_array($res['data']) ? $res['data'] : [];
            $page = is_array($d['data'] ?? null) ? $d['data'] : [];
            foreach ($page as $row) $all[] = $row;
            $cursor = $d['next_cursor'] ?? null;
            if ($cursor === null || $cursor === '' || empty($page)) break;
            if (++$guard > 1000) break; // runaway-pagination backstop
        }
        return $all;
    }

    // ------------------------------------------------------------------
    // Domain normalization (kept identical to GripService for consistent matching)
    // ------------------------------------------------------------------

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
     * Full hydration: pull issues + vendors (+ per-vendor users), upsert the
     * shadow_saas_hero_* mirror tables, and project vendors into shadow_saas.
     */
    public function syncAll(string $triggerSource = 'cron', ?int $userId = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'HERO is not configured'];
        }

        $logId = $this->openSyncLog($triggerSource, $userId);
        $vendorsCount = $usersCount = $issuesCount = $shadowUpserts = 0;
        try {
            $nowRow   = $this->db->fetchOne('SELECT NOW() AS now');
            $runStart = $nowRow['now'] ?? date('Y-m-d H:i:s');

            // Issues first — they drive the derived per-vendor risk score.
            $issues = $this->fetchAllCursor('/v1/issues');
            $issuesCount = $this->upsertIssues($issues);
            $issueAgg = $this->aggregateIssuesByDomain($issues);

            // Vendors → mirror + project (with derived score) into shadow_saas.
            $vendors = $this->fetchAllCursor('/v1/vendors');
            $vendorsCount = $this->upsertVendors($vendors, $issueAgg);
            $shadowResult = $this->projectVendorsToShadowSaas($vendors, $issueAgg, $userId);
            $shadowUpserts  = (int)($shadowResult['upserts'] ?? 0);
            $seenHeroIds    = $shadowResult['hero_ids'] ?? [];

            // Per-vendor users (HERO has no global users list). Best-effort; a
            // single vendor's failure must not abort the whole run.
            $usersSeen = false;
            $vIdx = 0;
            foreach ($vendors as $v) {
                // Cooperative cancellation: an admin can abort a running sync from
                // the Last Sync card, which flips this run's status to 'aborting'.
                if ((++$vIdx % 5) === 0 && $this->isCancelled($logId)) {
                    $this->closeSyncLog($logId, 'aborted', $vendorsCount, $usersCount, $issuesCount, $shadowUpserts, 'Aborted by admin.');
                    return ['success' => false, 'error' => 'aborted', 'log_id' => $logId, 'aborted' => true];
                }
                $cdomain = (string)($v['vendor']['canonical_domain'] ?? '');
                if ($cdomain === '') continue;
                // Gentle proactive throttle so 671 sequential user calls don't trip
                // HERO's rate limiter (429); request() also backs off on a 429.
                usleep($this->throttleUsec);
                try {
                    // Sample the FIRST PAGE of the vendor's users to pick the
                    // primary contact (highest email_count) and an observed-user
                    // count. Paging every vendor's full user list across 671
                    // vendors is far too slow for the scheduled job (some vendors
                    // have thousands of users); first-page is exact for the vast
                    // majority and a good representative for the rest.
                    $vu = $this->fetchUsersPage('/v1/vendors/' . rawurlencode($cdomain) . '/users');
                    // Number of users observed interacting with the vendor — the
                    // closest HERO analog to Grip's numberOfUsers. Always refreshed.
                    $this->applyVendorUserCount($cdomain, count($vu));
                    $top = $this->pickTopContact($vu);
                    if ($top !== null) {
                        $usersSeen = true;
                        $usersCount += $this->upsertUsers($cdomain, [$top]);
                        $this->applyRelationshipManager($cdomain, [$top]);
                    }
                } catch (Throwable $e) {
                    error_log("HERO users fetch failed for {$cdomain}: " . $e->getMessage());
                }
            }

            // Tombstone cleanup (scoped to this provider). Guarded by a non-empty
            // fetch so a partial/failed pull can't wipe the mirror.
            if (!empty($vendors)) {
                $this->db->delete('shadow_saas_hero_vendors', 'last_synced_at < :ts', [':ts' => $runStart]);
            }
            if ($usersSeen) {
                $this->db->delete('shadow_saas_hero_users', 'last_synced_at < :ts', [':ts' => $runStart]);
            }
            if (!empty($issues)) {
                $this->db->delete('shadow_saas_hero_issues', 'last_synced_at < :ts', [':ts' => $runStart]);
            }

            // shadow_saas tombstone: drop only pending hero-projected rows whose
            // hero_id wasn't seen this run; onboarded/dismissed rows survive.
            if (!empty($vendors) && !empty($seenHeroIds)) {
                $placeholders = [];
                $params = [];
                foreach ($seenHeroIds as $i => $hid) {
                    $k = ':hid' . $i;
                    $placeholders[] = $k;
                    $params[$k] = $hid;
                }
                $this->db->delete(
                    'shadow_saas',
                    "source = 'hero' AND status = 'pending' AND hero_id IS NOT NULL AND hero_id NOT IN (" . implode(',', $placeholders) . ')',
                    $params
                );
            }

            $this->closeSyncLog($logId, 'success', $vendorsCount, $usersCount, $issuesCount, $shadowUpserts, null);
            return [
                'success'              => true,
                'apps_fetched'         => $vendorsCount,   // generic keys for the shared driver/UI
                'users_fetched'        => $usersCount,
                'alerts_fetched'       => $issuesCount,
                'shadow_saas_upserted' => $shadowUpserts,
                'log_id'               => $logId,
            ];
        } catch (Throwable $e) {
            $this->closeSyncLog($logId, 'error', $vendorsCount, $usersCount, $issuesCount, $shadowUpserts, $e->getMessage());
            error_log('HeroService::syncAll error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'log_id' => $logId];
        }
    }

    /** Build domain -> ['count'=>open issue count, 'worst'=>severity string]. */
    private function aggregateIssuesByDomain(array $issues): array
    {
        $agg = [];
        foreach ($issues as $a) {
            if (($a['status'] ?? '') !== 'open') continue;
            $domain = self::extractDomain($a['vendor']['domain'] ?? '');
            if ($domain === '') continue;
            $sev = strtolower((string)($a['severity'] ?? ''));
            if (!isset($agg[$domain])) $agg[$domain] = ['count' => 0, 'worst' => ''];
            $agg[$domain]['count']++;
            $curRank = self::SEVERITY_RANK[$agg[$domain]['worst']] ?? 0;
            $newRank = self::SEVERITY_RANK[$sev] ?? 0;
            if ($newRank > $curRank) $agg[$domain]['worst'] = $sev;
        }
        return $agg;
    }

    private function openSyncLog(string $source, ?int $userId): int
    {
        $this->db->insert('shadow_saas_hero_sync_log', [
            'started_at'     => date('Y-m-d H:i:s'),
            'trigger_source' => in_array($source, ['cron', 'manual', 'test'], true) ? $source : 'cron',
            'status'         => 'running',
            'triggered_by'   => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function closeSyncLog(int $logId, string $status, int $vendors, int $users, int $issues, int $shadowSaas, ?string $err): void
    {
        try {
            $this->db->update('shadow_saas_hero_sync_log', [
                'finished_at'          => date('Y-m-d H:i:s'),
                'status'               => $status,
                'vendors_fetched'      => $vendors,
                'users_fetched'        => $users,
                'issues_fetched'       => $issues,
                'shadow_saas_upserted' => $shadowSaas,
                'error_message'        => $err,
            ], 'id = :id', [':id' => $logId]);
        } catch (Throwable $e) { error_log('HERO sync log update failed: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------
    // Upserts
    // ------------------------------------------------------------------

    private function upsertVendors(array $vendors, array $issueAgg): int
    {
        $count = 0;
        foreach ($vendors as $v) {
            $vendor = is_array($v['vendor'] ?? null) ? $v['vendor'] : [];
            $aspects = is_array($v['aspects'] ?? null) ? $v['aspects'] : [];
            $canonical = (string)($vendor['canonical_domain'] ?? '');
            if ($canonical === '') continue;
            $domain = self::extractDomain($canonical);
            $agg = $issueAgg[$domain] ?? ['count' => 0, 'worst' => ''];
            $row = [
                'hero_id'               => $canonical,
                'commercial_name'       => $vendor['commercial_name'] ?? null,
                'canonical_domain'      => $canonical,
                'domain'                => $domain ?: null,
                'associated_domains'    => json_encode($vendor['associated_domains'] ?? []),
                'status'                => $v['status'] ?? null,
                'commercial_engagement' => $aspects['commercial_engagement'] ?? null,
                'authorization'         => $aspects['authorization'] ?? null,
                'activity'              => $aspects['activity'] ?? null,
                'hero_url'              => $v['hero_url'] ?? null,
                'open_issue_count'      => (int)($agg['count'] ?? 0),
                'worst_issue_severity'  => ($agg['worst'] ?? '') ?: null,
                'derived_risk_score'    => $this->deriveScore($agg),
                'updated_at_hero'       => $this->parseDate($v['updated_at'] ?? null),
                'raw_payload'           => json_encode($v),
            ];
            $this->upsert('shadow_saas_hero_vendors', $row, 'hero_id');
            $count++;
        }
        return $count;
    }

    private function upsertIssues(array $issues): int
    {
        $count = 0;
        foreach ($issues as $a) {
            $vendor = is_array($a['vendor'] ?? null) ? $a['vendor'] : [];
            $id = (string)($a['id'] ?? '');
            if ($id === '') continue;
            $row = [
                'issue_id'        => $id,
                'type'            => $a['type'] ?? null,
                'severity'        => $a['severity'] ?? null,
                'status'          => $a['status'] ?? null,
                'title'           => $a['title'] ?? null,
                'vendor_domain'   => self::extractDomain($vendor['domain'] ?? '') ?: null,
                'vendor_name'     => $vendor['name'] ?? null,
                'opened_at'       => $this->parseDate($a['opened_at'] ?? null),
                'updated_at_hero' => $this->parseDate($a['updated_at'] ?? null),
                'resolved_at'     => $this->parseDate($a['resolved_at'] ?? null),
                'resolution'      => json_encode($a['resolution'] ?? null),
                'raw_payload'     => json_encode($a),
            ];
            $this->upsert('shadow_saas_hero_issues', $row, 'issue_id');
            $count++;
        }
        return $count;
    }

    private function upsertUsers(string $vendorDomain, array $users): int
    {
        $count = 0;
        $vd = self::extractDomain($vendorDomain) ?: $vendorDomain;
        foreach ($users as $u) {
            $email = (string)($u['email'] ?? '');
            if ($email === '') continue;
            $row = [
                // Fixed-length surrogate PK (domain+email can exceed index limits).
                'user_key'      => sha1($vd . '|' . strtolower($email)),
                'vendor_domain' => $vd,
                'email'         => $email,
                'name'          => $u['name'] ?? null,
                'email_count'   => isset($u['email_count']) ? (int)$u['email_count'] : 0,
                'sources'       => json_encode($u['sources'] ?? []),
                'raw_payload'   => json_encode($u),
            ];
            $this->upsert('shadow_saas_hero_users', $row, 'user_key');
            $count++;
        }
        return $count;
    }

    /**
     * Project vendors into shadow_saas (source='hero'). Match by hero_id then
     * domain; skip already-onboarded domains; preserve dismissed rows.
     * @return array{upserts:int, hero_ids:string[]}
     */
    private function projectVendorsToShadowSaas(array $vendors, array $issueAgg, ?int $userId): array
    {
        $upserts = 0;
        $seenHeroIds = [];
        $createdBy = $userId ?: 0;
        foreach ($vendors as $v) {
            $vendor = is_array($v['vendor'] ?? null) ? $v['vendor'] : [];
            $aspects = is_array($v['aspects'] ?? null) ? $v['aspects'] : [];
            $canonical = (string)($vendor['canonical_domain'] ?? '');
            if ($canonical === '') continue;
            $domain = self::extractDomain($canonical);
            $name = trim((string)($vendor['commercial_name'] ?? '')) ?: $domain;
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

            $agg = $issueAgg[$domain] ?? ['count' => 0, 'worst' => ''];

            // risk_type: open-issue summary + notable aspects (parallels Grip's
            // synthesized "; "-joined risk_type string).
            $riskTypeParts = [];
            if (($agg['count'] ?? 0) > 0) {
                $riskTypeParts[] = $agg['count'] . ' open issue(s)' . (!empty($agg['worst']) ? ' (worst: ' . $agg['worst'] . ')' : '');
            }
            if (!empty($aspects['authorization']) && $aspects['authorization'] !== 'no_indication') $riskTypeParts[] = 'Authorization: ' . $aspects['authorization'];
            if (!empty($aspects['activity']) && $aspects['activity'] !== 'no_indication')           $riskTypeParts[] = 'Activity: ' . $aspects['activity'];
            if (!empty($aspects['commercial_engagement']) && $aspects['commercial_engagement'] !== 'none') $riskTypeParts[] = 'Commercial: ' . $aspects['commercial_engagement'];

            $descParts = [];
            if (!empty($v['status'])) $descParts[] = 'HERO status: ' . $v['status'];
            if (!empty($vendor['associated_domains']) && is_array($vendor['associated_domains'])) {
                $descParts[] = 'Associated domains: ' . implode(', ', $vendor['associated_domains']);
            }

            $heroId = $canonical;
            $seenHeroIds[] = $heroId;

            $update = [
                'vendor_name'          => $name,
                'vendor_domain'        => $domain ?: null,
                'description'          => $descParts ? implode("\n", $descParts) : null,
                'risk_score'           => $this->deriveScore($agg),
                'risk_type'            => $riskTypeParts ? implode('; ', $riskTypeParts) : null,
                'application_category' => null,
                'hero_id'              => $heroId,
                'source'               => 'hero',
            ];

            $existing = null;
            if (!empty($update['hero_id'])) {
                $existing = $this->db->fetchOne("SELECT id, status FROM shadow_saas WHERE hero_id = :hid LIMIT 1", [':hid' => $update['hero_id']]);
            }
            if (!$existing && $domain !== '') {
                $existing = $this->db->fetchOne("SELECT id, status FROM shadow_saas WHERE vendor_domain = :d LIMIT 1", [':d' => $domain]);
            }
            if ($existing) {
                if (($existing['status'] ?? '') === 'dismissed') {
                    continue;
                }
                $this->db->update('shadow_saas', $update, 'id = :id', [':id' => $existing['id']]);
            } else {
                $update['created_by'] = $createdBy;
                $update['status'] = 'pending';
                $this->db->insert('shadow_saas', $update);
            }
            $upserts++;
        }
        return ['upserts' => $upserts, 'hero_ids' => $seenHeroIds];
    }

    /** Set shadow_saas.number_of_users for a Hero vendor (always refreshed). */
    private function applyVendorUserCount(string $vendorDomain, int $count): void
    {
        $domain = self::extractDomain($vendorDomain) ?: $vendorDomain;
        try {
            $this->db->update(
                'shadow_saas',
                ['number_of_users' => $count],
                "source = 'hero' AND vendor_domain = :d",
                [':d' => $domain]
            );
        } catch (Throwable $e) { /* non-fatal */ }
    }

    /**
     * Pick the single primary contact for a vendor: the highest-email_count user
     * (prefer a named one on ties). Returns null if there are no usable users.
     */
    private function pickTopContact(array $users): ?array
    {
        $best = null;
        $bestCount = -1;
        $bestNamed = false;
        foreach ($users as $u) {
            if (empty($u['email'])) continue;
            $cnt = (int)($u['email_count'] ?? 0);
            $named = trim((string)($u['name'] ?? '')) !== '';
            if ($cnt > $bestCount || ($cnt === $bestCount && $named && !$bestNamed)) {
                $best = $u;
                $bestCount = $cnt;
                $bestNamed = $named;
            }
        }
        return $best;
    }

    /**
     * Derive the Shadow SaaS relationship manager for a vendor from its most-
     * active observed user (highest email_count; prefer one with a display name).
     * Only stamps rows where it's still empty, so manual UI edits win — exactly
     * like GripService treats relationship_manager.
     */
    private function applyRelationshipManager(string $vendorDomain, array $users): void
    {
        if (empty($users)) return;
        $domain = self::extractDomain($vendorDomain) ?: $vendorDomain;

        $bestCount = -1;
        $bestNamed = false;
        $contact = '';
        foreach ($users as $u) {
            $cnt = (int)($u['email_count'] ?? 0);
            $name = trim((string)($u['name'] ?? ''));
            $email = trim((string)($u['email'] ?? ''));
            $named = ($name !== '');
            // Prefer higher email_count; on a tie prefer a user that has a name.
            if ($cnt > $bestCount || ($cnt === $bestCount && $named && !$bestNamed)) {
                $candidate = $name !== '' ? $name : $email;
                if ($candidate !== '') {
                    $bestCount = $cnt;
                    $bestNamed = $named;
                    $contact = $candidate;
                }
            }
        }
        if ($contact === '') return;

        try {
            $this->db->update(
                'shadow_saas',
                ['relationship_manager' => $contact],
                "source = 'hero' AND vendor_domain = :d AND (relationship_manager IS NULL OR relationship_manager = '')",
                [':d' => $domain]
            );
        } catch (Throwable $e) { /* non-fatal */ }
    }

    /** Map a per-vendor issue aggregate to a stored 1-5 score (null when no open issues). */
    private function deriveScore(array $agg): ?int
    {
        $worst = $agg['worst'] ?? '';
        if ($worst === '' || ($agg['count'] ?? 0) <= 0) return null;
        return self::SEVERITY_SCORE[$worst] ?? null;
    }

    // ------------------------------------------------------------------
    // Tiny helpers
    // ------------------------------------------------------------------

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

    /** True if an admin has requested this run be aborted (status flipped to 'aborting'). */
    private function isCancelled(int $logId): bool
    {
        try {
            $r = $this->db->fetchOne("SELECT status FROM shadow_saas_hero_sync_log WHERE id = :id", [':id' => $logId]);
            return $r && ($r['status'] ?? '') === 'aborting';
        } catch (Throwable $e) { return false; }
    }

    /** Latest sync log row, or null if none. */
    public function getLastSync(): ?array
    {
        try {
            $row = $this->db->fetchOne("SELECT * FROM shadow_saas_hero_sync_log ORDER BY id DESC LIMIT 1");
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}
