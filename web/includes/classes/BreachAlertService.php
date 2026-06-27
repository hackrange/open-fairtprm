<?php
/**
 * Breach / Cyber Alert Service
 *
 * Manages breach alert lifecycle: creation, deduplication, status transitions,
 * email notifications, and AI-powered breach research via the configured
 * AI platform (OpenWebUI / LibreChat).
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

class BreachAlertService {
    private static ?BreachAlertService $instance = null;
    private Database $db;

    /** Flag set when completeScan() has been called — prevents the shutdown handler from overwriting the result. */
    private bool $scanCompleted = false;

    private function __construct() {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // ALERT CRUD
    // =========================================================================

    public function getAlerts(?string $status = null, int $limit = 200, int $offset = 0, bool $vendorsOnly = false): array {
        $sql = 'SELECT a.*, '
             . 'u_ack.full_name AS acknowledged_by_name, '
             . 'u_inv.full_name AS investigating_by_name, '
             . 'u_res.full_name AS resolved_by_name '
             . 'FROM cyber_breach_alerts a '
             . 'LEFT JOIN users u_ack ON a.acknowledged_by = u_ack.id '
             . 'LEFT JOIN users u_inv ON a.investigating_by = u_inv.id '
             . 'LEFT JOIN users u_res ON a.resolved_by = u_res.id '
             . 'WHERE 1=1';
        $params = [];
        if ($status) {
            $sql .= ' AND a.status = :status';
            $params[':status'] = $status;
        }
        if ($vendorsOnly) {
            $sql .= ' AND a.vendor_count > 0';
        }
        $sql .= ' ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset';
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function getAlert(int $id): ?array {
        return $this->db->fetchOne('SELECT * FROM cyber_breach_alerts WHERE id = :id', [':id' => $id]) ?: null;
    }

    public function countByStatus(): array {
        $rows = $this->db->fetchAll("SELECT status, COUNT(*) as cnt FROM cyber_breach_alerts GROUP BY status");
        $counts = ['new' => 0, 'acknowledged' => 0, 'investigating' => 0, 'resolved' => 0, 'false_positive' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $counts[$r['status']] = (int)$r['cnt'];
            $counts['total'] += (int)$r['cnt'];
        }
        return $counts;
    }

    public function countNew(): int {
        $row = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM cyber_breach_alerts WHERE status = 'new'");
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Create an alert with deduplication via SHA-256 hash.
     * Returns ['success' => true, 'id' => int] or ['success' => false, 'duplicate' => true].
     */
    public function createAlert(array $data): array {
        // Dedup on entity + alert_type so the same vendor/breach isn't reported twice
        $hash = hash('sha256', mb_strtolower(trim($data['affected_entity'])) . '|' . mb_strtolower(trim($data['alert_type'] ?? 'other')));

        // Dedup check - also check for unresolved alerts on the same entity
        $existing = $this->db->fetchOne(
            "SELECT id FROM cyber_breach_alerts WHERE (alert_hash = :h OR (LOWER(affected_entity) = LOWER(:entity) AND status NOT IN ('resolved','false_positive')))",
            [':h' => $hash, ':entity' => trim($data['affected_entity'])]
        );
        if ($existing) {
            return ['success' => false, 'duplicate' => true, 'existing_id' => (int)$existing['id']];
        }

        $this->db->insert('cyber_breach_alerts', [
            'alert_hash'           => $hash,
            'alert_type'           => $data['alert_type'] ?? 'other',
            'severity'             => $data['severity'] ?? 'medium',
            'title'                => trim($data['title']),
            'summary'              => trim($data['summary'] ?? ''),
            'affected_entity'      => trim($data['affected_entity']),
            'affected_entity_type' => $data['affected_entity_type'] ?? 'vendor',
            'affected_vendor_ids'  => $data['affected_vendor_ids'] ?? null,
            'affected_technology'  => $data['affected_technology'] ?? null,
            'vendor_count'         => (int)($data['vendor_count'] ?? 0),
            'source_urls'          => is_array($data['source_urls'] ?? null) ? json_encode($data['source_urls']) : ($data['source_urls'] ?? '[]'),
            'ai_analysis'          => $data['ai_analysis'] ?? null,
            'status'               => 'new',
        ]);

        return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    public function updateStatus(int $id, string $status, ?int $userId = null, ?string $notes = null): array {
        $alert = $this->getAlert($id);
        if (!$alert) return ['success' => false, 'error' => 'Alert not found.'];

        $update = ['status' => $status];

        if ($status === 'new') {
            // Reverting to New (e.g. Un-Acknowledge) clears every prior workflow
            // stamp so the alert genuinely shows as not acknowledged / investigated
            // / resolved — no lingering "Resolved By" on an unset alert.
            $update['acknowledged_by']  = null;
            $update['acknowledged_at']  = null;
            $update['investigating_by'] = null;
            $update['investigating_at'] = null;
            $update['resolved_by']      = null;
            $update['resolved_at']      = null;
            $update['resolution_notes'] = null;
        } elseif ($status === 'acknowledged') {
            $update['acknowledged_by'] = $userId;
            $update['acknowledged_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'investigating') {
            $update['investigating_by'] = $userId;
            $update['investigating_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'resolved' || $status === 'false_positive') {
            $update['resolved_by'] = $userId;
            $update['resolved_at'] = date('Y-m-d H:i:s');
            if ($notes) $update['resolution_notes'] = $notes;
        }

        $this->db->update('cyber_breach_alerts', $update, 'id = :id', [':id' => $id]);
        return ['success' => true];
    }

    public function deleteAlert(int $id): array {
        $alert = $this->getAlert($id);
        if (!$alert) return ['success' => false, 'error' => 'Alert not found.'];
        $this->db->query('DELETE FROM cyber_breach_alerts WHERE id = :id', [':id' => $id]);
        return ['success' => true];
    }

    public function markEmailSent(int $id): void {
        $this->db->update('cyber_breach_alerts', ['email_sent_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
    }

    /**
     * New, not-yet-emailed alerts that qualify for the breach digest: alerts
     * affecting a monitored vendor (vendor_count > 0) plus Grip-sourced Shadow
     * SaaS breaches (no onboarded vendor, but still worth notifying). Driven by
     * email_sent_at and source-agnostic, so alerts created outside the
     * breach-monitor run — e.g. the Grip Shadow SaaS breach feed in
     * GripService::syncAll() — are included the next time the digest cron fires.
     * Resolved / false-positive alerts are never (re)emailed.
     */
    public function getPendingDigestAlerts(int $limit = 200): array {
        return $this->db->fetchAll(
            "SELECT * FROM cyber_breach_alerts
             WHERE email_sent_at IS NULL
               AND status NOT IN ('resolved','false_positive')
               AND (vendor_count > 0 OR affected_entity_type = 'shadow_saas')
             ORDER BY created_at DESC
             LIMIT :limit",
            [':limit' => (int)$limit]
        );
    }

    // =========================================================================
    // ON-DEMAND SCAN STATUS (background queue pattern)
    // =========================================================================

    /**
     * Queue an on-demand breach scan for background processing.
     * Returns false if a scan is already running.
     *
     * @param int $userId       User who requested the scan
     * @param string $scanType  'full' (breach + all vendor OSINT), 'company' (breach + company OSINT only)
     */
    public function queueScan(int $userId, string $scanType = 'full'): bool {
        $current = $this->getScanStatus();
        if ($current['status'] === 'scanning') {
            return false;
        }

        $this->setConfigValue('breach_scan_status', 'scanning');
        $this->setConfigValue('breach_scan_started_at', date('Y-m-d H:i:s'));
        $this->setConfigValue('breach_scan_result', '');
        $this->setConfigValue('breach_scan_requested_by', (string)$userId);
        $this->setConfigValue('breach_scan_type', $scanType);

        return true;
    }

    /**
     * Get the current scan status.
     */
    public function getScanStatus(): array {
        $keys = ['breach_scan_status', 'breach_scan_started_at', 'breach_scan_result', 'breach_scan_requested_by', 'breach_scan_type'];
        $rows = $this->db->fetchAll(
            "SELECT config_key, config_value FROM app_config WHERE config_key IN ('" . implode("','", $keys) . "')"
        );
        $config = [];
        foreach ($rows as $r) {
            $config[$r['config_key']] = $r['config_value'];
        }

        return [
            'status'       => $config['breach_scan_status'] ?? null,
            'started_at'   => $config['breach_scan_started_at'] ?? null,
            'result'       => $config['breach_scan_result'] ?? null,
            'requested_by' => $config['breach_scan_requested_by'] ?? null,
            'scan_type'    => $config['breach_scan_type'] ?? 'full',
        ];
    }

    /**
     * Complete a scan — clear status and write result message.
     */
    public function completeScan(string $resultMessage): void {
        $this->setConfigValue('breach_scan_status', '');
        $this->setConfigValue('breach_scan_result', $resultMessage);
        $this->scanCompleted = true;
    }

    /**
     * Whether completeScan() has been called during this process lifecycle.
     * Used by the shutdown handler to avoid overwriting a successful result.
     */
    public function isScanCompleted(): bool {
        return $this->scanCompleted;
    }

    /**
     * Clear a stale scan that has been running longer than the given threshold.
     * Returns true if a stale scan was cleared.
     */
    public function clearStaleScan(int $maxAgeSeconds = 600): bool {
        $status = $this->getScanStatus();
        if ($status['status'] !== 'scanning' || empty($status['started_at'])) {
            return false;
        }

        $startedAt = strtotime($status['started_at']);
        if (!$startedAt || (time() - $startedAt) <= $maxAgeSeconds) {
            return false;
        }

        $this->setConfigValue('breach_scan_status', '');
        $this->setConfigValue('breach_scan_result', 'Timed out — scan exceeded ' . round($maxAgeSeconds / 60) . ' minute limit.');
        return true;
    }

    /**
     * Upsert a value into app_config.
     */
    private function setConfigValue(string $key, string $value): void {
        $existing = $this->db->fetchOne("SELECT id FROM app_config WHERE config_key = :k", [':k' => $key]);
        if ($existing) {
            $this->db->query("UPDATE app_config SET config_value = :v WHERE config_key = :k", [':v' => $value, ':k' => $key]);
        } else {
            $this->db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => 0]);
        }
    }

    // =========================================================================
    // VENDOR / TECHNOLOGY CONTEXT GATHERING
    // =========================================================================

    /**
     * Build context for AI prompt: active vendors, subprocessors, and technologies.
     */
    public function gatherMonitoringContext(): array {
        $vendors = $this->db->fetchAll(
            "SELECT id, vendor_name, vendor_domain, vendor_tier
             FROM vendor_onboarding_requests
             WHERE status NOT IN ('rejected','inactive','draft')
             ORDER BY vendor_name"
        );

        $subprocessors = $this->db->fetchAll(
            "SELECT DISTINCT subprocessor_name, subprocessor_domain
             FROM vendor_subprocessors
             ORDER BY subprocessor_name"
        );

        $technologies = $this->db->fetchAll(
            "SELECT technology_name, technology_category,
                    COUNT(DISTINCT vendor_onboarding_id) as vendor_count
             FROM vendor_technologies
             WHERE is_current = 1
             GROUP BY technology_name, technology_category
             ORDER BY vendor_count DESC"
        );

        return [
            'vendors'       => $vendors,
            'subprocessors' => $subprocessors,
            'technologies'  => $technologies,
        ];
    }

    /**
     * Find vendor IDs affected by a technology name.
     */
    public function getVendorsByTechnology(string $techName): array {
        return $this->db->fetchAll(
            "SELECT DISTINCT vt.vendor_onboarding_id, v.vendor_name
             FROM vendor_technologies vt
             JOIN vendor_onboarding_requests v ON v.id = vt.vendor_onboarding_id
             WHERE vt.technology_name = :tech AND vt.is_current = 1
               AND v.status NOT IN ('rejected','inactive','draft')",
            [':tech' => $techName]
        );
    }

    /**
     * Find vendor by name or domain (fuzzy match).
     */
    public function findVendorByName(string $name): ?array {
        return $this->db->fetchOne(
            "SELECT id, vendor_name, vendor_domain FROM vendor_onboarding_requests
             WHERE (vendor_name LIKE :name OR vendor_domain LIKE :domain) AND status NOT IN ('rejected','inactive','draft')
             LIMIT 1",
            [':name' => '%' . $name . '%', ':domain' => '%' . $name . '%']
        ) ?: null;
    }

    // =========================================================================
    // AI-POWERED BREACH RESEARCH
    // =========================================================================

    /**
     * Get the configured batch size for breach/OSINT research.
     * Controls how many vendors are sent per AI call (default: 15).
     */
    public function getBatchSize(): int {
        $row = $this->db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'breach_scan_batch_size'");
        $size = (int)($row['config_value'] ?? 15);
        return max(5, min(50, $size));
    }

    /**
     * Update the scan progress message (visible to the UI via polling).
     */
    public function updateScanProgress(string $message): void {
        $this->setConfigValue('breach_scan_result', $message);
    }

    /**
     * Use the configured AI platform to research breaches for monitored entities.
     * Processes vendors in batches for comprehensive coverage with several hundred vendors.
     * Returns an array of structured alert objects or an error.
     *
     * @param callable|null $onProgress  Optional callback: fn(string $message) called between batches
     */
    public function runAIBreachResearch(?callable $onProgress = null): array {
        $aiService = AIPlatformService::getInstance();
        if (!$aiService->isEnabled()) {
            return ['success' => false, 'error' => 'AI platform is not enabled.'];
        }

        $context = $this->gatherMonitoringContext();

        if (empty($context['vendors']) && empty($context['subprocessors']) && empty($context['technologies'])) {
            return ['success' => true, 'alerts' => [], 'message' => 'No entities to monitor.'];
        }

        $batchSize = $this->getBatchSize();
        $vendors = $context['vendors'];
        $batches = array_chunk($vendors, $batchSize);
        $totalBatches = count($batches);
        $allAlerts = [];
        $batchErrors = [];

        // Shared context (subprocessors + technologies) goes in every batch
        $sharedContext = [
            'subprocessors' => $context['subprocessors'],
            'technologies'  => $context['technologies'],
        ];

        foreach ($batches as $batchIndex => $batchVendors) {
            $batchNum = $batchIndex + 1;
            $progressMsg = "Breach research: processing vendor batch {$batchNum}/{$totalBatches} (" . count($batchVendors) . " vendors)...";
            error_log("BreachAlertService: {$progressMsg}");

            if ($onProgress) {
                $onProgress($progressMsg);
            }

            // Build context for this batch
            $batchContext = array_merge($sharedContext, ['vendors' => $batchVendors]);

            // Only include technologies/subprocessors in the first batch to avoid
            // duplicate tech vulnerability alerts; subsequent batches focus on vendors
            if ($batchIndex > 0) {
                $batchContext['subprocessors'] = [];
                $batchContext['technologies']  = [];
            }

            $prompt = $this->buildResearchPrompt($batchContext);
            $searchQueries = $this->buildSearchQueries($batchContext);

            $result = $this->callAIWithRetry($aiService, $prompt, 'breach_research', null, $searchQueries);

            if (!$result['success']) {
                $batchErrors[] = "Batch {$batchNum}/{$totalBatches} failed: " . ($result['error'] ?? 'Unknown');
                error_log("BreachAlertService: Batch {$batchNum} failed — " . ($result['error'] ?? 'Unknown'));
                continue; // Don't abort the entire scan; try remaining batches
            }

            $batchAlerts = $this->parseAIResponse($result['content'], $batchContext);
            $allAlerts = array_merge($allAlerts, $batchAlerts);

            error_log("BreachAlertService: Batch {$batchNum} returned " . count($batchAlerts) . " alert(s)");

            // Brief delay between batches to avoid rate-limiting
            if ($batchNum < $totalBatches) {
                sleep(2);
            }
        }

        if (empty($allAlerts) && !empty($batchErrors)) {
            return ['success' => false, 'error' => 'All batches failed: ' . implode('; ', $batchErrors)];
        }

        return [
            'success'      => true,
            'alerts'       => $allAlerts,
            'batch_count'  => $totalBatches,
            'batch_errors' => $batchErrors,
        ];
    }

    /**
     * Call the AI platform with retry logic. Shared by breach and OSINT research.
     *
     * $searchQueries is forwarded to the active platform via $options. Passing
     * it from the breach path lets the Custom dispatcher (FairTPRM orchestrator)
     * pre-fetch /v1/safe-search hits and prepend them as evidence — matching
     * the way LibreChat agents transparently browse during a chat completion.
     * Other platforms ignore the option, so this is safe to always pass.
     */
    private function callAIWithRetry(AIPlatformService $aiService, string $prompt, string $purpose, ?string $systemPrompt = null, array $searchQueries = []): array {
        if ($systemPrompt === null) {
            $systemPrompt = 'You are a cybersecurity threat intelligence analyst. You ONLY report real, verified, credible security incidents and data breaches. Never fabricate incidents. If you cannot confirm an incident is real, do not include it. Every alert MUST include at least 2 credible source URLs from reputable cybersecurity news outlets, government advisories (CISA, NVD, CERT), or the affected organization\'s official disclosure.';
        }

        // OSINT calls use web search and need a longer timeout (8 min vs 5 min)
        $timeout = ($purpose === 'osint_research') ? 480 : 300;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];
        $options = [
            'temperature' => 0.1,
            'max_tokens'  => 4000,
            'purpose'     => $purpose,
            'timeout'     => $timeout,
        ];
        if (!empty($searchQueries)) {
            $options['search_queries']     = $searchQueries;
            // Match the 7-day window enforced in buildResearchPrompt(). The
            // Custom dispatcher uses this to drop stale hits at the source so
            // the model never sees out-of-window evidence in the first place.
            $options['search_max_age_days'] = 7;
        }

        $maxAttempts = 3;
        $retryDelays = [2, 4];
        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $aiService->chatCompletion($messages, $options);

            if ($result['success']) {
                break;
            }

            if ($attempt < $maxAttempts && $this->isRetryableError($result['error'] ?? '')) {
                error_log("BreachAlertService [{$purpose}]: Attempt {$attempt}/{$maxAttempts} failed ({$result['error']}), retrying in {$retryDelays[$attempt - 1]}s...");
                sleep($retryDelays[$attempt - 1]);
            } else {
                break;
            }
        }

        return $result;
    }

    /**
     * Build a bounded list of web-search queries for one batch of monitored
     * entities. The Custom platform uses these to pre-fetch evidence via the
     * orchestrator's /v1/safe-search; LibreChat/OpenWebUI ignore them.
     *
     * The cap keeps full-vendor scans within a reasonable time budget — at
     * roughly 1-2s per query, 12 per batch × 18 batches stays under 6 min of
     * search overhead.
     */
    private function buildSearchQueries(array $context): array {
        $queries = [];
        $maxPerBatch = 12;
        $window = date('Y-m'); // current month, for recency hinting in queries

        foreach ($context['technologies'] ?? [] as $t) {
            $name = trim($t['technology_name'] ?? '');
            if ($name !== '') {
                $queries[] = "\"{$name}\" CVE OR vulnerability OR exploited {$window}";
            }
        }
        foreach ($context['subprocessors'] ?? [] as $s) {
            $name = trim($s['subprocessor_name'] ?? '');
            if ($name !== '') {
                $queries[] = "\"{$name}\" data breach OR ransomware OR cyberattack {$window}";
            }
        }
        foreach ($context['vendors'] ?? [] as $v) {
            $name = trim($v['vendor_name'] ?? '');
            if ($name !== '') {
                $queries[] = "\"{$name}\" breach OR ransomware OR cyberattack OR vulnerability {$window}";
            }
        }

        if (count($queries) > $maxPerBatch) {
            $queries = array_slice($queries, 0, $maxPerBatch);
        }
        return $queries;
    }

    private function buildResearchPrompt(array $context): string {
        $vendorList = '';
        foreach ($context['vendors'] as $v) {
            $vendorList .= "  - {$v['vendor_name']} (domain: {$v['vendor_domain']}, tier: {$v['vendor_tier']})\n";
        }

        $subList = '';
        foreach ($context['subprocessors'] as $s) {
            $subList .= "  - {$s['subprocessor_name']}";
            if (!empty($s['subprocessor_domain'])) $subList .= " (domain: {$s['subprocessor_domain']})";
            $subList .= "\n";
        }

        $techList = '';
        foreach ($context['technologies'] as $t) {
            $techList .= "  - {$t['technology_name']} (category: {$t['technology_category']}, used by {$t['vendor_count']} vendor(s))\n";
        }

        $today = date('Y-m-d');

        // Calculate the date window
        $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

        return <<<PROMPT
Research and report any REAL, CREDIBLE, VERIFIED security breaches, data breaches, ransomware attacks, cyber attacks, or critical vulnerability disclosures that affect any of the following entities.

CRITICAL DATE REQUIREMENT: Only report incidents that were FIRST DISCLOSED or DISCOVERED between {$sevenDaysAgo} and {$today}. Do NOT report older incidents, even if they are still being discussed. Check the publication date of your source articles — if the earliest source is older than {$sevenDaysAgo}, do NOT include it. For example, a breach from 2023 or 2024 that appears in search results must be EXCLUDED even if it involves a monitored vendor.

MONITORED VENDORS:
{$vendorList}

MONITORED SUBPROCESSORS (Fourth Parties):
{$subList}

MONITORED TECHNOLOGIES (with vendor impact count):
{$techList}

RULES:
1. ONLY report incidents you are confident are REAL and VERIFIED. Do not speculate or fabricate.
2. Each alert MUST have at least 2 credible source URLs (news articles, CVE databases, CISA advisories, vendor disclosures, etc.)
3. ALL source URLs MUST be dated between {$sevenDaysAgo} and {$today}. If you cannot find sources from the last 7 days, the incident is too old — do not include it.
4. Do NOT report vulnerabilities unless they are actively exploited or have a CVSS score >= 7.0
5. For technology vulnerabilities, note how many of our vendors use that technology (vendor_count from the list above)
6. If no credible incidents from the last 7 days are found, return an empty alerts array

Respond ONLY with valid JSON in this exact format (no markdown, no code fences, no explanation):
{
  "alerts": [
    {
      "title": "Brief descriptive title of the incident",
      "alert_type": "data_breach|cyber_attack|vulnerability|supply_chain|ransomware|other",
      "severity": "critical|high|medium|low",
      "affected_entity": "Name of affected vendor/subprocessor/technology",
      "affected_entity_type": "vendor|subprocessor|technology",
      "summary": "2-3 sentence factual summary of the incident, its impact, and any known scope",
      "source_urls": ["https://credible-source-1.com/article", "https://credible-source-2.com/article"]
    }
  ]
}

If there are no credible incidents to report, respond with: {"alerts": []}
PROMPT;
    }

    private function parseAIResponse(string $content, array $context): array {
        // Strip markdown code fences if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        // If direct decode fails, extract JSON object from surrounding prose
        if (!is_array($data) || !isset($data['alerts'])) {
            $firstBrace = strpos($content, '{');
            $lastBrace  = strrpos($content, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
                $data = json_decode($jsonStr, true);
            }
        }

        if (!is_array($data) || !isset($data['alerts'])) {
            return [];
        }

        $alerts = [];
        foreach ($data['alerts'] as $raw) {
            if (empty($raw['title']) || empty($raw['affected_entity'])) continue;

            // Require at least 2 source URLs
            $urls = $raw['source_urls'] ?? [];
            if (!is_array($urls) || count($urls) < 2) continue;

            // Validate alert_type
            $validTypes = ['data_breach', 'cyber_attack', 'vulnerability', 'supply_chain', 'ransomware', 'osint_exposure', 'other'];
            $alertType = in_array($raw['alert_type'] ?? '', $validTypes) ? $raw['alert_type'] : 'other';

            // Validate severity
            $validSeverities = ['critical', 'high', 'medium', 'low'];
            $severity = in_array($raw['severity'] ?? '', $validSeverities) ? $raw['severity'] : 'medium';

            // Validate entity type
            $validEntityTypes = ['vendor', 'subprocessor', 'technology', 'organization'];
            $entityType = in_array($raw['affected_entity_type'] ?? '', $validEntityTypes) ? $raw['affected_entity_type'] : 'vendor';

            // Resolve affected vendor IDs and count
            $vendorIds = [];
            $vendorCount = 0;
            $affectedTech = null;

            if ($entityType === 'technology') {
                $affectedTech = $raw['affected_entity'];
                $affectedVendors = $this->getVendorsByTechnology($raw['affected_entity']);
                foreach ($affectedVendors as $av) {
                    $vendorIds[] = (int)$av['vendor_onboarding_id'];
                }
                $vendorCount = count($vendorIds);
            } elseif ($entityType === 'vendor') {
                $match = $this->findVendorByName($raw['affected_entity']);
                if ($match) {
                    $vendorIds[] = (int)$match['id'];
                    $vendorCount = 1;
                }
            }

            // Build AI analysis with fourth-party context
            $aiAnalysis = $raw['summary'] ?? '';
            if ($entityType === 'technology' && $vendorCount > 0) {
                $vendorNames = array_map(function ($av) { return $av['vendor_name']; }, $affectedVendors ?? []);
                $aiAnalysis .= "\n\nFourth-Party Impact: This technology is used by {$vendorCount} vendor(s) in your supply chain: " . implode(', ', $vendorNames) . '.';
            }

            $alerts[] = [
                'title'                => $this->stripAIMarkup($raw['title']),
                'alert_type'           => $alertType,
                'severity'             => $severity,
                'affected_entity'      => $this->stripAIMarkup($raw['affected_entity']),
                'affected_entity_type' => $entityType,
                'affected_vendor_ids'  => !empty($vendorIds) ? json_encode($vendorIds) : null,
                'affected_technology'  => $affectedTech,
                'vendor_count'         => $vendorCount,
                'source_urls'          => $urls,
                'summary'              => $this->stripAIMarkup($raw['summary'] ?? ''),
                'ai_analysis'          => $this->stripAIMarkup($aiAnalysis),
            ];
        }

        return $alerts;
    }

    /**
     * Determine if an error is retryable (timeouts, rate limits, server errors).
     */
    private function isRetryableError(string $error): bool {
        $error = strtolower($error);
        // Timeouts (cURL timeout, connection timeout)
        if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
            return true;
        }
        // Rate limiting
        if (strpos($error, '429') !== false) {
            return true;
        }
        // Server errors (500, 502, 503, 504)
        if (preg_match('/\b(500|502|503|504)\b/', $error)) {
            return true;
        }
        return false;
    }

    // =========================================================================
    // OSINT EXPOSURE RESEARCH
    // =========================================================================

    /**
     * Gather context for OSINT research: company name, domains, vendor domains.
     */
    public function gatherOSINTContext(): array {
        $db = $this->db;

        // Company name from general settings
        $companyRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'company_name'");
        $companyName = trim($companyRow['config_value'] ?? '');

        // Application URL from general settings
        $appUrlRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'app_url'");
        $appUrl = trim($appUrlRow['config_value'] ?? '');

        // Extract domain from app URL (e.g., https://tprmapi.example.com -> example.com)
        $appDomain = '';
        if ($appUrl) {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if ($host) {
                // Get the registrable domain (last two parts for standard TLDs)
                $parts = explode('.', $host);
                if (count($parts) >= 2) {
                    $appDomain = implode('.', array_slice($parts, -2));
                } else {
                    $appDomain = $host;
                }
            }
        }

        // Organization domain from SRS/UpGuard settings
        $orgDomainRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'upguard_org_domain'");
        $orgDomain = strtolower(trim($orgDomainRow['config_value'] ?? ''));

        // Collect unique domains to search
        $searchDomains = array_filter(array_unique([$appDomain, $orgDomain]));

        // Vendor domains for expanded OSINT coverage (no limit; batching handles large sets)
        $vendorDomains = $db->fetchAll(
            "SELECT DISTINCT vendor_name, vendor_domain
             FROM vendor_onboarding_requests
             WHERE vendor_domain IS NOT NULL AND vendor_domain != ''
               AND status NOT IN ('rejected','inactive','draft')
             ORDER BY vendor_name"
        );

        return [
            'company_name'   => $companyName,
            'app_url'        => $appUrl,
            'app_domain'     => $appDomain,
            'org_domain'     => $orgDomain,
            'search_domains' => $searchDomains,
            'vendor_domains' => $vendorDomains,
        ];
    }

    /**
     * Build the OSINT-focused research prompt.
     * Uses natural web search queries that work with Anthropic's web_search tool
     * rather than specific URLs that the tool can't visit directly.
     */
    private function buildOSINTPrompt(array $context): string {
        $companyName = $context['company_name'] ?: 'the organization';
        $today = date('Y-m-d');

        $domainList = '';
        foreach ($context['search_domains'] as $d) {
            $domainList .= "  - {$d}\n";
        }

        $vendorDomainList = '';
        foreach ($context['vendor_domains'] as $v) {
            $vendorDomainList .= "  - {$v['vendor_name']} ({$v['vendor_domain']})\n";
        }

        // Collect all domains and build search queries
        $allDomains = [];
        foreach ($context['search_domains'] as $d) {
            $allDomains[] = $d;
        }
        foreach ($context['vendor_domains'] as $v) {
            if (!empty($v['vendor_domain'])) {
                $allDomains[] = $v['vendor_domain'];
            }
        }
        $allDomains = array_unique($allDomains);

        // Build focused search queries for each domain.
        // Keep to ~5 high-value queries per domain so the AI can spend its
        // web search budget thoroughly examining results rather than skimming.
        $searchQueries = '';
        $n = 1;
        foreach ($allDomains as $domain) {
            $domainBase = preg_replace('/\.(com|org|net|io|co|gov|edu|us|uk|ca|au|de|fr|in|jp)$/i', '', $domain);
            // #1: GitHub — broadest credential search
            $searchQueries .= "  {$n}. github.com \"{$domainBase}\" password OR secret OR api_key OR token\n"; $n++;
            // #2: GitHub — config files with credentials
            $searchQueries .= "  {$n}. github.com \"{$domainBase}\" filename:.env OR filename:application.properties OR filename:config.yml OR filename:docker-compose.yml\n"; $n++;
            // #3: GitHub — database and cloud credentials
            $searchQueries .= "  {$n}. github.com \"{$domainBase}\" datasource OR jdbc OR aws_access_key OR s3 OR rds.amazonaws.com\n"; $n++;
            // #4: Non-GitHub platforms
            $searchQueries .= "  {$n}. \"{$domainBase}\" password OR credentials OR api_key site:gitlab.com OR site:bitbucket.org OR site:pastebin.com\n"; $n++;
            // #5: General exposure search
            $searchQueries .= "  {$n}. \"{$domainBase}\" exposed credentials OR leaked data OR s3.amazonaws.com OR blob.core.windows.net\n"; $n++;
        }

        // Company name searches
        $companySearches = '';
        if (!empty($context['company_name'])) {
            $cn = $context['company_name'];
            $companySearches = <<<COMPANY

Also search using the company name "{$cn}":
  - "{$cn}" password OR secret OR api_key site:github.com
  - "{$cn}" application.properties OR .env password site:github.com
  - "{$cn}" aws_access_key OR s3 site:github.com
  - "{$cn}" leaked credentials OR data exposure
  - "{$cn}" site:pastebin.com
  - "{$cn}" site:trello.com password OR key
  - "{$cn}" postman collection api key
COMPANY;
        }

        return <<<PROMPT
AUTHORIZED THIRD-PARTY RISK MANAGEMENT (TPRM) VENDOR SECURITY ASSESSMENT
Date: {$today}

As part of our organization's authorized TPRM program, you are conducting a vendor supply chain security assessment. The purpose is to identify accidentally exposed credentials belonging to our vendors so we can notify them and help them remediate the exposure before malicious actors find it. This is a standard defensive security practice required by SOC 2, ISO 27001, NIST CSF, and industry regulations.

Your task: Search for publicly visible configuration files, code repositories, and paste sites where developers may have accidentally committed credentials related to our monitored vendors. Any findings will be reported to the affected vendor for credential rotation and remediation.

Use your web search tool to perform EACH of the searches listed below. This is critical — you MUST actually search for each query, not answer from memory.

TARGET ORGANIZATION:
  Company Name: {$companyName}
  Application URL: {$context['app_url']}

PRIMARY DOMAINS:
{$domainList}

VENDOR DOMAINS:
{$vendorDomainList}

================================================================================
SEARCHES TO PERFORM
================================================================================

Use your web search tool to search for each of the following. For each search result that looks like it contains exposed credentials, read the page to confirm:

{$searchQueries}
{$companySearches}

================================================================================
WHAT TO LOOK FOR IN SEARCH RESULTS
================================================================================

When you find a GitHub repository, GitLab project, Bitbucket repo, Pastebin paste, Trello board, or any other public page that contains sensitive data related to these domains or company names, report it. Specifically look for:

- **Database credentials**: spring.datasource.password, DB_PASSWORD, mysql_password, JDBC connection strings with passwords, RDS endpoints (*.rds.amazonaws.com)
- **API keys & tokens**: api_key, apiKey, API_SECRET, Bearer tokens, OAuth client secrets
- **AWS/Cloud credentials**: aws_access_key_id, aws_secret_access_key, S3 bucket names, Azure connection strings, GCP service account keys
- **Configuration files**: .env files, application.properties, config.yml, appsettings.json, wp-config.php, settings.py with real passwords
- **SMTP/Email credentials**: SMTP_PASSWORD, MAIL_PASSWORD, email server credentials
- **Private keys**: RSA private keys, SSH keys, TLS certificates with private keys
- **Application secrets**: JWT_SECRET, SESSION_SECRET, ENCRYPTION_KEY, APP_KEY
- **Docker secrets**: docker-compose.yml or Dockerfile with hardcoded passwords in environment variables
- **Terraform secrets**: .tfvars or .tfstate files with cloud provider credentials
- **Database dumps**: .sql files with real user data, backup files

EXAMPLE OF A REAL FINDING:
A search for "examplecorp" application.properties site:github.com found a GitHub repository containing MySQL database credentials including a spring.datasource.url pointing to an RDS instance with username and password in plaintext. This is CRITICAL because production database credentials are publicly exposed.

================================================================================
OUTPUT FORMAT
================================================================================

RULES:
1. You MUST use web search for each query — do not answer from memory
2. Each finding MUST include the exact source URL where you found the exposure
3. Include the type of sensitive data found but do NOT include actual credential values
4. Severity: critical (production DB passwords, cloud keys, private keys), high (API tokens, SMTP creds), medium (internal URLs, staging creds), low (clearly-labeled test credentials)
5. If a search returns nothing sensitive, move on — do NOT fabricate findings

Respond ONLY with valid JSON (no markdown, no code fences):
{
  "alerts": [
    {
      "title": "Exposed [type] credentials found in [platform]",
      "alert_type": "osint_exposure",
      "severity": "critical|high|medium|low",
      "affected_entity": "Name of the organization or vendor affected",
      "affected_entity_type": "organization|vendor",
      "summary": "What was found, where, and why it is a risk. Include the repo name, file path, and credential type WITHOUT actual secret values.",
      "source_urls": ["https://exact-url-where-exposure-was-found"],
      "exposed_data_types": ["mysql_password", "aws_access_key", "smtp_credentials", "api_token", "s3_bucket", "private_key", "connection_string", "jwt_secret", "rds_endpoint", "internal_url", "postman_collection", "docker_env", "terraform_state", "database_dump", "ssh_key", "oauth_secret", "trello_board", "other"]
    }
  ]
}

If no credible exposures found, respond with: {"alerts": []}
PROMPT;
    }

    /**
     * Run company-only OSINT scan — searches only the org domain(s) and company name.
     * Fast scan suitable for daily cron. Does NOT scan vendor domains.
     *
     * @param callable|null $onProgress  Optional callback for progress updates
     */
    public function runCompanyOSINT(?callable $onProgress = null): array {
        $context = $this->gatherOSINTContext();

        if (empty($context['company_name']) && empty($context['search_domains'])) {
            return ['success' => true, 'alerts' => [], 'message' => 'No company name or domains configured.'];
        }

        $patRow = $this->db->fetchOne("SELECT config_value, is_encrypted FROM app_config WHERE config_key = 'github_pat'");
        $pat = null;
        if ($patRow && !empty($patRow['config_value'])) {
            $pat = $patRow['is_encrypted'] ? (new Encryption())->decrypt($patRow['config_value']) : $patRow['config_value'];
        }

        require_once __DIR__ . '/GitHubOSINTScanner.php';
        $scanner = new GitHubOSINTScanner($pat);

        // Company domains only — no vendor domains
        $domains = $context['search_domains'];

        // Map org domains to company name for display
        $nameMap = [];
        foreach ($domains as $d) {
            $nameMap[$d] = $context['company_name'] ?: $d;
        }

        error_log("BreachAlertService OSINT: Starting COMPANY scan for " . count($domains) . " org domain(s)");

        if ($onProgress) {
            $onProgress("OSINT: Scanning company domains via GitHub Code Search...");
        }

        $alerts = $scanner->scan($domains, $context['company_name'], $onProgress, $nameMap);

        // Resolve vendor IDs for findings
        foreach ($alerts as &$alert) {
            $match = $this->findVendorByName($alert['affected_entity']);
            if ($match) {
                $alert['affected_vendor_ids'] = json_encode([(int)$match['id']]);
                $alert['vendor_count'] = 1;
                $alert['affected_entity'] = $match['vendor_name']; // Use canonical vendor name
            }
        }
        unset($alert);

        error_log("BreachAlertService OSINT: Company scan complete — " . count($alerts) . " finding(s)");

        return ['success' => true, 'alerts' => $alerts];
    }

    /**
     * Run OSINT exposure research using direct GitHub Code Search API.
     * No AI model required — searches GitHub directly for exposed credentials
     * in public repositories related to monitored vendors and domains.
     *
     * Optionally uses a GitHub Personal Access Token (app_config: github_pat)
     * for higher rate limits (30/min vs 10/min unauthenticated).
     *
     * @param callable|null $onProgress  Optional callback: fn(string $message) called during scanning
     */
    public function runOSINTResearch(?callable $onProgress = null): array {
        $context = $this->gatherOSINTContext();

        if (empty($context['company_name']) && empty($context['search_domains']) && empty($context['vendor_domains'])) {
            return ['success' => true, 'alerts' => [], 'message' => 'No company name or domains configured for OSINT research.'];
        }

        // Get optional GitHub PAT for higher rate limits
        $patRow = $this->db->fetchOne("SELECT config_value, is_encrypted FROM app_config WHERE config_key = 'github_pat'");
        $pat = null;
        if ($patRow && !empty($patRow['config_value'])) {
            $pat = $patRow['is_encrypted'] ? (new Encryption())->decrypt($patRow['config_value']) : $patRow['config_value'];
        }

        require_once __DIR__ . '/GitHubOSINTScanner.php';
        $scanner = new GitHubOSINTScanner($pat);

        // Collect all domains to scan + build domain→name map
        $allDomains = $context['search_domains'];
        $nameMap = [];
        foreach ($context['search_domains'] as $d) {
            $nameMap[$d] = $context['company_name'] ?: $d;
        }
        foreach ($context['vendor_domains'] as $v) {
            if (!empty($v['vendor_domain'])) {
                $allDomains[] = $v['vendor_domain'];
                $nameMap[$v['vendor_domain']] = $v['vendor_name'];
            }
        }
        $allDomains = array_unique($allDomains);

        error_log("BreachAlertService OSINT: Starting GitHub Code Search scan for " . count($allDomains) . " domain(s)");

        if ($onProgress) {
            $onProgress("OSINT: Scanning " . count($allDomains) . " domains via GitHub Code Search...");
        }

        $alerts = $scanner->scan($allDomains, $context['company_name'], $onProgress, $nameMap);

        // Resolve vendor IDs for findings
        foreach ($alerts as &$alert) {
            $match = $this->findVendorByName($alert['affected_entity']);
            if ($match) {
                $alert['affected_vendor_ids'] = json_encode([(int)$match['id']]);
                $alert['vendor_count'] = 1;
                $alert['affected_entity'] = $match['vendor_name']; // Use canonical vendor name
            }
        }
        unset($alert);

        error_log("BreachAlertService OSINT: GitHub scan complete — " . count($alerts) . " finding(s)");

        return [
            'success' => true,
            'alerts'  => $alerts,
        ];
    }

    /**
     * Parse the AI's OSINT research response into structured alerts.
     */
    private function parseOSINTResponse(string $content, array $context): array {
        // Strip markdown code fences if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        // If direct decode fails, extract JSON object from surrounding prose
        if (!is_array($data) || !isset($data['alerts'])) {
            $firstBrace = strpos($content, '{');
            $lastBrace  = strrpos($content, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
                $data = json_decode($jsonStr, true);
            }
        }

        if (!is_array($data) || !isset($data['alerts'])) {
            return [];
        }

        $alerts = [];
        foreach ($data['alerts'] as $raw) {
            if (empty($raw['title']) || empty($raw['affected_entity'])) continue;

            // OSINT findings require at least 1 source URL (the exposure location)
            $urls = $raw['source_urls'] ?? [];
            if (!is_array($urls) || count($urls) < 1) continue;

            // Validate severity
            $validSeverities = ['critical', 'high', 'medium', 'low'];
            $severity = in_array($raw['severity'] ?? '', $validSeverities) ? $raw['severity'] : 'high';

            // Determine entity type
            $entityType = ($raw['affected_entity_type'] ?? '') === 'vendor' ? 'vendor' : 'organization';

            // Resolve vendor IDs if the affected entity matches a vendor
            $vendorIds = [];
            $vendorCount = 0;
            if ($entityType === 'vendor') {
                $match = $this->findVendorByName($raw['affected_entity']);
                if ($match) {
                    $vendorIds[] = (int)$match['id'];
                    $vendorCount = 1;
                }
            }

            // Build enhanced summary with exposed data types
            $summary = $this->stripAIMarkup($raw['summary'] ?? '');
            $exposedTypes = $raw['exposed_data_types'] ?? [];
            if (!empty($exposedTypes) && is_array($exposedTypes)) {
                $typeLabels = [
                    'mysql_password' => 'MySQL Password',
                    'aws_access_key' => 'AWS Access Key',
                    'smtp_credentials' => 'SMTP Credentials',
                    'api_token' => 'API Token',
                    's3_bucket' => 'S3 Bucket',
                    'private_key' => 'Private Key',
                    'connection_string' => 'Connection String',
                    'jwt_secret' => 'JWT Secret',
                    'rds_endpoint' => 'RDS Endpoint',
                    'internal_url' => 'Internal URL',
                    'postman_collection' => 'Postman Collection',
                    'docker_env' => 'Docker ENV Secret',
                    'terraform_state' => 'Terraform State',
                    'database_dump' => 'Database Dump',
                    'ssh_key' => 'SSH Key',
                    'oauth_secret' => 'OAuth Secret',
                    'trello_board' => 'Trello Board',
                    'other' => 'Other Sensitive Data',
                ];
                $exposedLabels = array_map(function ($t) use ($typeLabels) {
                    return $typeLabels[$t] ?? ucfirst(str_replace('_', ' ', $t));
                }, $exposedTypes);
                $summary .= "\n\nExposed Data Types: " . implode(', ', $exposedLabels) . '.';
            }

            $alerts[] = [
                'title'                => $this->stripAIMarkup($raw['title']),
                'alert_type'           => 'osint_exposure',
                'severity'             => $severity,
                'affected_entity'      => $this->stripAIMarkup($raw['affected_entity']),
                'affected_entity_type' => $entityType,
                'affected_vendor_ids'  => !empty($vendorIds) ? json_encode($vendorIds) : null,
                'affected_technology'  => null,
                'vendor_count'         => $vendorCount,
                'source_urls'          => $urls,
                'summary'              => $summary,
                'ai_analysis'          => $this->stripAIMarkup($raw['summary'] ?? '') . "\n\nThis finding was identified through automated OSINT (Open Source Intelligence) scanning of public repositories, code sharing platforms, and cloud storage services. Immediate action is recommended to rotate any exposed credentials and remove or secure the source of the exposure.",
            ];
        }

        return $alerts;
    }

    /**
     * Strip AI citation markup, HTML tags, and markdown artifacts from text.
     */
    private function stripAIMarkup(string $text): string {
        // Remove <cite ...>...</cite> keeping inner text
        $text = preg_replace('/<cite[^>]*>(.*?)<\/cite>/si', '$1', $text);
        // Remove any remaining HTML tags
        $text = strip_tags($text);
        // Remove markdown bold/italic
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        // Clean up extra whitespace
        $text = preg_replace('/\s{2,}/', ' ', $text);
        return trim($text);
    }
}
