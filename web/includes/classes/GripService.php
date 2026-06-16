<?php
/**
 * GripService — Grip Security public API client + Shadow SaaS ingest.
 *
 * Talks to the Grip Security public SaaS API (default base
 * https://<tenant>.dep.grip.security/public/saas) and hydrates the
 * shadow_saas_grip_* mirror tables, then projects normalized rows into
 * the existing shadow_saas table so the existing UI can render Grip data
 * with no further wiring.
 *
 * Auth: header "access-token: <token>". This is what the working reference
 * Python client uses; Bearer/X-API-Key/Basic all return 401 on this API.
 *
 * Documented endpoints (apidocs.grip.security):
 *   GET  /public/saas                — list SaaS apps (paginated)
 *   GET  /public/saas/{id}           — get one SaaS by Grip id
 *   GET  /public/saas/name/{name}    — find SaaS id by name
 *   GET  /public/users               — list users
 *   GET  /public/alerts              — list alerts
 */

class GripService
{
    private const DEFAULT_BASE_URL = 'https://tenant.dep.grip.security/public/saas';
    private const PAGE_SIZE = 200;
    private const REQUEST_TIMEOUT = 30;

    private Database $db;
    private string $baseUrl = '';
    private string $apiToken = '';
    private bool $enabled = false;

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
                case 'grip_enabled':   $this->enabled = ($val === '1'); break;
                case 'grip_base_url':  $this->baseUrl = is_string($val) ? trim($val) : ''; break;
                case 'grip_api_token': $this->apiToken = is_string($val) ? trim($val) : ''; break;
            }
        }
        if ($this->baseUrl === '') $this->baseUrl = self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function isConfigured(): bool { return $this->apiToken !== '' && $this->baseUrl !== ''; }
    public function isEnabled(): bool { return $this->enabled && $this->isConfigured(); }
    public function getBaseUrl(): string { return $this->baseUrl; }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * Call a Grip endpoint. $path is appended to base URL — pass '' for the
     * SaaS list endpoint, or '/users', '/alerts', '/{id}' as relative paths
     * (rooted at /public for /public/users and /public/alerts).
     */
    private function request(string $relativeUrl, array $query = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'http_code' => 0, 'error' => 'Grip is not configured', 'data' => null];
        }
        $url = $this->buildUrl($relativeUrl, $query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'access-token: ' . $this->apiToken,
                'User-Agent: FairTPRM-Grip-Integration/1.0',
            ],
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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

        // Two-label public suffixes we care about — keep last 3 labels for these.
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
        try {
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

            $usersData = $alertsData = [];
            try { $usersData  = $this->fetchAll('/public/users');   $usersCount  = $this->upsertUsers($usersData); }   catch (Throwable $e) { error_log('Grip users fetch failed: ' . $e->getMessage()); }
            try { $alertsData = $this->fetchAll('/public/alerts');  $alertsCount = $this->upsertAlerts($alertsData); } catch (Throwable $e) { error_log('Grip alerts fetch failed: ' . $e->getMessage()); }

            // Tombstone cleanup: any row in a mirror table whose last_synced_at
            // pre-dates this run is no longer present in Grip. Skip cleanup
            // when the corresponding fetch returned zero rows — that usually
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
            $this->upsert('shadow_saas_grip_apps', $row, 'grip_id');
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
            $this->upsert('shadow_saas_grip_users', $row, 'grip_id');
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
            $this->upsert('shadow_saas_grip_alerts', $row, 'alert_id');
            $count++;
        }
        return $count;
    }

    /**
     * Project SaaS apps into the existing shadow_saas table so the legacy UI
     * continues to render. Match by grip_id (when present) or by domain;
     * otherwise insert new rows. Skip any domain already onboarded as a vendor.
     *
     * Returns ['upserts' => int, 'grip_ids' => string[]] — the grip_ids
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
                'risk_score'           => isset($g['riskScore']) ? (int)$g['riskScore'] : null,
                'risk_type'            => $riskTypeParts ? implode('; ', $riskTypeParts) : null,
                'number_of_users'      => isset($g['numberOfUsers']) ? (int)$g['numberOfUsers'] : null,
                'application_category' => $g['category'] ?? null,
                'mfasupport'           => !empty($g['mfaSupported']) ? 'yes' : null,
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
                // Dismissed rows are an explicit admin decision — leave them
                // fully intact (no field updates from Grip).
                if (($existing['status'] ?? '') === 'dismissed') {
                    continue;
                }
                // Only stamp the relationship manager if the row doesn't already
                // have one — manual edits in the UI win over Grip's value.
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

    /** Latest sync log row, or null if none. */
    public function getLastSync(): ?array
    {
        try {
            $row = $this->db->fetchOne("SELECT * FROM shadow_saas_grip_sync_log ORDER BY id DESC LIMIT 1");
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}
