<?php
/**
 * LockdownService - Web Application Firewall (ModSecurity) Management
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Manages the positive security model (whitelist-based) WAF lifecycle:
 *   1. Learning mode  - ModSecurity in DetectionOnly, logs all traffic
 *   2. Whitelist gen  - Parse audit logs, build whitelist from observed patterns
 *   3. Enforcement    - Switch to blocking, deny anything not whitelisted
 *   4. Exceptions     - View blocked requests, add manual exceptions
 *
 * Config files are written via proc_open + sudo tee (same pattern as scheduler).
 * Apache reloads use proc_open + sudo apachectl graceful.
 */
class LockdownService
{
    private Database $db;

    private const MODSEC_CONF = '/etc/modsecurity/modsecurity.conf';
    private const WHITELIST_CONF = '/etc/modsecurity/lockdown-whitelist.conf';
    private const SCANNER_CONF = '/etc/modsecurity/scanner-detection.conf';
    private const PERSISTENT_CONF = '/persistent/modsecurity/modsecurity.conf';
    private const PERSISTENT_WHITELIST = '/persistent/modsecurity/lockdown-whitelist.conf';
    private const PERSISTENT_SCANNER = '/persistent/modsecurity/scanner-detection.conf';
    private const GRC_ASSESS_CONF = '/etc/modsecurity/grc-assessment-rules.conf';
    private const PERSISTENT_GRC_ASSESS = '/persistent/modsecurity/grc-assessment-rules.conf';
    private const AUDIT_LOG = '/persistent/modsecurity/audit_log/modsec_audit.json';

    /** Rule ID range for generated whitelist rules */
    private const RULE_ID_START = 500000;
    private const RULE_ID_DENY  = 599999;

    /** Paths always whitelisted — every legitimate PHP endpoint in the app */
    private const SAFE_PATHS = [
        // Core pages
        '/',
        '/index.php',
        '/login.php',
        '/logout.php',
        '/profile.php',
        '/admin.php',
        '/error.php',
        // Vendor management
        '/vendor-onboarding.php',
        '/vendor-onboarding-list.php',
        '/vendor-onboarding-import.php',
        '/vendor-onboarding-export.php',
        '/vendor-srs-list.php',
        '/vendor-srs-details.php',
        '/vendor-srs-import.php',
        '/vendor-srs-export.php',
        '/vendor-assessments.php',
        '/vendor-assessment.php',
        '/vendor-assessment-view.php',
        '/vendor-domains.php',
        '/vendor-detailed-summary.php',
        '/vendor-annual-reviews-list.php',
        '/vendor-annual-review.php',
        '/vendor-annual-review-history.php',
        // FAIR analysis
        '/fair-analysis.php',
        '/fair_dashboard.php',
        '/fair_results.php',
        '/fair_import-analysis.php',
        // Other modules
        '/cyber-todo.php',
        '/fourth-party-risk.php',
        '/shadow-saas.php',
        '/procurement-contracts.php',
        '/procurement-cyber-status.php',
        '/reports.php',
        '/download-pdf.php',
        '/admin-users-import.php',
        // API endpoints
        '/api/ai-job-status.php',
        '/api/ai-models.php',
        '/api/assessment-ai-apply.php',
        '/api/assessment-ai-autofill.php',
        '/api/assessment-autosave.php',
        '/api/assessment-certificate-download.php',
        '/api/assessment-certificate-upload.php',
        '/api/assessment-file-download.php',
        '/api/assessment-file-upload.php',
        '/api/assessment-submit.php',
        '/api/annual-review-submit.php',
        '/api/contract-pricing-ai-extract.php',
        '/api/csrf-refresh.php',
        '/api/enrich-fair-vendor.php',
        '/api/generate-detailed-summary.php',
        '/api/generate-fair-ai-analysis.php',
        '/api/generate-srs-summary.php',
        '/api/get-srs-data.php',
        '/api/get-user-vendors.php',
        '/api/search-fair-vendors.php',
        '/api/search-users.php',
        '/api/search-vendor-domains.php',
        '/api/search-vendors.php',
        '/api/search-waiver-vendors.php',
        '/api/send-assessment-email.php',
        '/api/template-question-delete.php',
        '/api/template-question-reorder.php',
        '/api/template-question-save.php',
        '/api/template-section-delete.php',
        '/api/template-section-reorder.php',
        '/api/template-section-save.php',
        '/api/vendor-document-delete.php',
        '/api/procurement-update-save.php',
        '/api/procurement-notes-list.php',
        '/api/vendor-document-download.php',
        '/api/vendor-document-edit.php',
        '/api/vendor-document-toggle-status.php',
        '/api/vendor-document-upload.php',
        '/api/vendor-favicon.php',
        '/api/vendor-subprocessor-add.php',
        '/api/vendor-subprocessor-remove.php',
        '/api/vendor-subprocessor-edit.php',
        '/api/search-subprocessors.php',
        '/api/api-rescore-status.php',
        '/api/preview-sql-update.php',
        '/api/view-result.php',
        // GRC module pages
        '/grc-dashboard.php',
        '/grc-frameworks.php',
        '/grc-controls.php',
        '/grc-evidence.php',
        '/grc-policies.php',
        '/grc-audits.php',
        '/grc-crosswalk.php',
        '/grc-monitors.php',
        '/grc-risks.php',
        '/grc-gaps.php',
        '/grc-tasks.php',
        // GRC Unified Assessment (FairScore) pages
        '/grc-assessment.php',
        '/grc-fairscore.php',
        '/grc-assessment-report.php',
        // GRC API endpoints
        '/api/grc-autosave.php',
        '/api/grc-assessment-report.php',
        '/api/grc-unified-assessment.php',
        '/api/grc-assessment-evidence-upload.php',
        '/api/grc-refine-notes.php',
        '/api/grc-requirement-detail.php',
        '/api/grc-assessment-evidence.php',
        '/api/grc-assessment-control.php',
        '/api/grc-suggest-control.php',
        '/api/grc-audit-finding.php',
        '/api/grc-generate-report.php',
        '/api/grc-report-save.php',
        '/api/grc-report-download.php',
        // SAML 2.0 SSO endpoints (v2.6.1). The IdP POSTs the signed SAMLResponse
        // to /saml/acs.php and may GET/POST logout to /saml/sls.php, so these must
        // stay reachable under positive-security lockdown. @streq matches on URI
        // only (any method), which is required for the IdP's cross-site POST.
        '/saml/acs.php',
        '/saml/login.php',
        '/saml/sls.php',
        '/saml/metadata.php',
    ];

    /** Path prefixes always whitelisted (static assets, SCIM) */
    private const SAFE_PREFIXES = [
        '/api/v1/',
        '/api/v2/',
        '/scim/',
        '/app/css/',
        '/app/js/',
        '/app/icons/',
        '/app/images/',
        '/app/fonts/',
        '/app/template/',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Check if the ModSecurity Apache module is installed.
     * Checks for unicode.mapping under /etc/modsecurity/ (already in
     * open_basedir) — this file is installed by libapache2-mod-security2.
     */
    public function isModSecurityAvailable(): bool
    {
        return file_exists('/etc/modsecurity/unicode.mapping');
    }

    /**
     * Get the current WAF status and statistics.
     */
    public function getStatus(): array
    {
        $mode = $this->getConfigValue('waf_mode', 'disabled');
        $learningStarted = $this->getConfigValue('waf_learning_started_at', '');

        $patternCount = 0;
        $ruleCount = 0;
        $blockCount = 0;

        try {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS cnt FROM waf_learned_patterns");
            $patternCount = (int)($row['cnt'] ?? 0);
        } catch (\Exception $e) { /* table may not exist yet */ }

        try {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS cnt FROM waf_whitelist_rules WHERE is_active = 1");
            $ruleCount = (int)($row['cnt'] ?? 0);
        } catch (\Exception $e) {}

        try {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS cnt FROM waf_block_events WHERE resolved = 0");
            $blockCount = (int)($row['cnt'] ?? 0);
        } catch (\Exception $e) {}

        $learningDuration = '';
        if ($mode === 'learning' && $learningStarted) {
            $start = new \DateTime($learningStarted);
            $now = new \DateTime();
            $diff = $now->diff($start);
            if ($diff->days > 0) {
                $learningDuration = $diff->days . 'd ' . $diff->h . 'h';
            } else {
                $learningDuration = $diff->h . 'h ' . $diff->i . 'm';
            }
        }

        return [
            'mode'              => $mode,
            'learning_started'  => $learningStarted,
            'learning_duration' => $learningDuration,
            'pattern_count'     => $patternCount,
            'rule_count'        => $ruleCount,
            'block_count'       => $blockCount,
        ];
    }

    /**
     * Set the WAF mode: disabled, learning, enforcing.
     */
    public function setMode(string $mode): bool
    {
        $engineDirective = match ($mode) {
            'learning'  => 'DetectionOnly',
            'enforcing' => 'On',
            default     => 'Off',
        };

        // Build the modsecurity.conf with the new engine setting
        $conf = $this->buildModSecConf($engineDirective);

        // Save the current config so we can roll back on failure
        $backupConf = file_exists(self::MODSEC_CONF) ? file_get_contents(self::MODSEC_CONF) : '';

        // Write to active config
        if (!$this->writeFileViaSudo(self::MODSEC_CONF, $conf)) {
            return false;
        }

        // Validate the new config before committing
        if (!$this->apacheConfigTest()) {
            // Roll back — restore the previous config
            $this->writeFileViaSudo(self::MODSEC_CONF, $backupConf);
            error_log('LockdownService::setMode: Apache config test failed, rolled back');
            return false;
        }

        // Config is valid — persist it and update DB
        $this->writeFileViaSudo(self::PERSISTENT_CONF, $conf);

        $this->setConfigValue('waf_mode', $mode);
        if ($mode === 'learning') {
            $this->setConfigValue('waf_learning_started_at', date('Y-m-d H:i:s'));
        }

        // Reload Apache to pick up changes
        return $this->reloadApache();
    }

    /**
     * Parse the ModSecurity audit log and ingest patterns into the database.
     * Returns the number of patterns ingested.
     *
     * ModSecurity 2.9.x (libapache2-mod-security2) uses Serial audit log
     * format with section markers like --boundary-A--, --boundary-B--, etc.
     * Section B = request headers, Section F = response headers.
     */
    public function ingestAuditLog(): int
    {
        $logFile = self::AUDIT_LOG;
        if (!file_exists($logFile) || filesize($logFile) === 0) {
            return 0;
        }

        $content = file_get_contents($logFile);
        if ($content === false || $content === '') {
            return 0;
        }

        // Split into individual audit entries by the Z-section (end marker)
        // Each entry ends with --boundary-Z--
        $entries = preg_split('/^--[a-f0-9]+-Z--\s*$/m', $content);

        $count = 0;

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }

            // Detect the boundary ID for this entry (e.g., "faff6a55")
            if (!preg_match('/^--([a-f0-9]+)-A--/m', $entry, $bm)) {
                continue;
            }
            $bid = $bm[1];

            // Extract Section B (request headers)
            $sectionB = $this->extractSerialSection($entry, $bid, 'B');
            if (empty($sectionB)) {
                continue;
            }

            // First line of Section B is the request line: "GET /path HTTP/1.1"
            $bLines = explode("\n", $sectionB);
            $requestLine = trim($bLines[0] ?? '');
            $requestParts = explode(' ', $requestLine, 3);
            $method = strtoupper($requestParts[0] ?? 'GET');
            $rawUri = $requestParts[1] ?? '';

            if (empty($rawUri)) {
                continue;
            }

            // Extract headers from Section B
            $contentType = '';
            $xRealIp = '';
            $xForwardedFor = '';
            $userAgent = '';
            foreach ($bLines as $headerLine) {
                $headerLine = trim($headerLine);
                if (stripos($headerLine, 'Content-Type:') === 0) {
                    $contentType = trim(substr($headerLine, 13));
                } elseif (stripos($headerLine, 'X-Real-IP:') === 0) {
                    $xRealIp = trim(substr($headerLine, 10));
                } elseif (stripos($headerLine, 'X-Forwarded-For:') === 0) {
                    $xForwardedFor = trim(substr($headerLine, 16));
                } elseif (stripos($headerLine, 'User-Agent:') === 0) {
                    $userAgent = trim(substr($headerLine, 11));
                }
            }

            // Determine client IP: prefer the first (leftmost) IP in
            // X-Forwarded-For (original client), then X-Real-IP, then
            // source IP from Section A.  The leftmost X-Forwarded-For
            // entry is the true visitor; X-Real-IP is often an
            // intermediate proxy.
            $clientIp = '';
            if (!empty($xForwardedFor)) {
                $clientIp = trim(explode(',', $xForwardedFor)[0]);
            }
            if (empty($clientIp) && !empty($xRealIp)) {
                $clientIp = $xRealIp;
            }
            if (empty($clientIp)) {
                $sectionA = $this->extractSerialSection($entry, $bid, 'A');
                if (preg_match('/\]\s+\S+\s+([\d.]+)\s/', $sectionA, $am)) {
                    $clientIp = $am[1];
                }
            }

            // Extract Section F (response headers) for status code
            $sectionF = $this->extractSerialSection($entry, $bid, 'F');
            $responseStatus = 200;
            if (!empty($sectionF)) {
                $fLines = explode("\n", $sectionF);
                $statusLine = trim($fLines[0] ?? '');
                // Parse "HTTP/1.1 200 OK"
                if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $statusLine, $sm)) {
                    $responseStatus = (int)$sm[1];
                }
            }

            // Only learn from successful responses (2xx/3xx)
            if ($responseStatus >= 400) {
                continue;
            }

            // Strip query string from URI, but capture it for param extraction
            $queryString = '';
            $uri = $rawUri;
            if (str_contains($rawUri, '?')) {
                $uri = strtok($rawUri, '?');
                $queryString = substr($rawUri, strlen($uri) + 1);
            }

            // Extract parameter names from query string
            $paramNames = [];
            if (!empty($queryString)) {
                parse_str($queryString, $params);
                $paramNames = array_keys($params);
            }

            // Truncate content type to base (strip charset etc.)
            if (str_contains($contentType, ';')) {
                $contentType = trim(strtok($contentType, ';'));
            }

            $paramNamesJson = !empty($paramNames) ? json_encode(array_values(array_unique($paramNames))) : '[]';

            try {
                $this->db->query(
                    "INSERT INTO waf_learned_patterns (uri, method, param_names, content_type, response_status, frequency, first_seen, last_seen)
                     VALUES (:uri, :method, :params, :ct, :status, 1, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                         frequency = frequency + 1,
                         last_seen = NOW(),
                         param_names = IF(LENGTH(:params2) > LENGTH(param_names), :params3, param_names),
                         response_status = :status2",
                    [
                        ':uri'     => substr($uri, 0, 500),
                        ':method'  => $method,
                        ':params'  => $paramNamesJson,
                        ':ct'      => substr($contentType, 0, 200),
                        ':status'  => $responseStatus,
                        ':params2' => $paramNamesJson,
                        ':params3' => $paramNamesJson,
                        ':status2' => $responseStatus,
                    ]
                );
                $count++;
            } catch (\Exception $e) {
                error_log('LockdownService::ingestAuditLog error: ' . $e->getMessage());
            }

            // Store individual request for drill-down
            try {
                // Extract timestamp from Section A: [01/Mar/2026:17:33:18.380161 +0000]
                $requestAt = date('Y-m-d H:i:s');
                $sectionA = $this->extractSerialSection($entry, $bid, 'A');
                if (preg_match('/\[(\d{2})\/(\w{3})\/(\d{4}):(\d{2}:\d{2}:\d{2})/', $sectionA, $tm)) {
                    $parsed = strtotime("{$tm[1]} {$tm[2]} {$tm[3]} {$tm[4]}");
                    if ($parsed) {
                        $requestAt = date('Y-m-d H:i:s', $parsed);
                    }
                }
                $this->db->query(
                    "INSERT INTO waf_learned_requests (uri, method, client_ip, user_agent, query_string, content_type, response_status, request_at)
                     VALUES (:uri, :method, :ip, :ua, :qs, :ct, :status, :rat)",
                    [
                        ':uri'    => substr($uri, 0, 500),
                        ':method' => $method,
                        ':ip'     => substr($clientIp, 0, 45),
                        ':ua'     => substr($userAgent, 0, 500),
                        ':qs'     => $queryString ?: null,
                        ':ct'     => substr($contentType, 0, 200),
                        ':status' => $responseStatus,
                        ':rat'    => $requestAt,
                    ]
                );
            } catch (\Exception $e) {
                error_log('LockdownService::ingestAuditLog request insert error: ' . $e->getMessage());
            }
        }

        // Rotate the audit log after ingestion (truncate it)
        $this->writeFileViaSudo($logFile, '');

        // Graceful restart so ModSecurity re-opens the fresh log file
        $this->reloadApache();

        return $count;
    }

    /**
     * Extract a section from a ModSecurity Serial audit log entry.
     * Sections are delimited by --boundary-LETTER-- markers.
     */
    private function extractSerialSection(string $entry, string $boundary, string $section): string
    {
        $startMarker = "--{$boundary}-{$section}--";
        $startPos = strpos($entry, $startMarker);
        if ($startPos === false) {
            return '';
        }

        $startPos += strlen($startMarker);
        // Find the next section marker
        $nextMarker = "--{$boundary}-";
        $endPos = strpos($entry, $nextMarker, $startPos);
        if ($endPos === false) {
            return trim(substr($entry, $startPos));
        }

        return trim(substr($entry, $startPos, $endPos - $startPos));
    }

    /**
     * Generate whitelist rules from learned patterns.
     * Returns the number of rules generated.
     */
    public function generateWhitelistRules(): int
    {
        // Clear existing auto-generated rules
        try {
            $this->db->query("DELETE FROM waf_whitelist_rules WHERE is_auto_generated = 1");
        } catch (\Exception $e) {
            error_log('LockdownService: failed to clear old rules: ' . $e->getMessage());
        }

        $rules = [];
        $seenPaths = []; // Track URI patterns to prevent duplicates
        $ruleId = self::RULE_ID_START;

        // 1. Hardcoded safe exact paths (deduplicated)
        foreach (array_unique(self::SAFE_PATHS) as $path) {
            if (isset($seenPaths[$path . '|*'])) {
                continue;
            }
            $seenPaths[$path . '|*'] = true;
            $ruleText = sprintf(
                'SecRule REQUEST_URI "@streq %s" "id:%d,phase:1,pass,nolog,setvar:TX.lockdown_allowed=1"',
                $path,
                $ruleId
            );
            $rules[] = [
                'rule_id'     => $ruleId,
                'uri_pattern' => $path,
                'method_pattern' => '*',
                'rule_text'   => $ruleText,
                'description' => "Safe path: {$path}",
                'is_auto'     => 1,
            ];
            $ruleId++;
        }

        // 2. Hardcoded safe path prefixes (deduplicated)
        foreach (array_unique(self::SAFE_PREFIXES) as $prefix) {
            $key = $prefix . '*|*';
            if (isset($seenPaths[$key])) {
                continue;
            }
            $seenPaths[$key] = true;
            $ruleText = sprintf(
                'SecRule REQUEST_URI "@beginsWith %s" "id:%d,phase:1,pass,nolog,setvar:TX.lockdown_allowed=1"',
                $prefix,
                $ruleId
            );
            $rules[] = [
                'rule_id'     => $ruleId,
                'uri_pattern' => $prefix . '*',
                'method_pattern' => '*',
                'rule_text'   => $ruleText,
                'description' => "Safe prefix: {$prefix}",
                'is_auto'     => 1,
            ];
            $ruleId++;
        }

        // 3. Learned patterns from traffic observation
        try {
            $patterns = $this->db->fetchAll(
                "SELECT uri, method, param_names, content_type, frequency
                 FROM waf_learned_patterns
                 ORDER BY frequency DESC"
            );
        } catch (\Exception $e) {
            $patterns = [];
        }

        foreach ($patterns as $pattern) {
            $uri = $pattern['uri'];
            $method = $pattern['method'];

            // SECURITY: learned patterns originate from raw HTTP request lines (a
            // network-reachable, attacker-influenced source). Never emit one that could
            // break out of the rule operand / inject a directive — skip it entirely.
            if (!$this->isWafTokenSafe($uri) || !$this->isWafTokenSafe($method)) {
                continue;
            }

            // Skip if already covered by safe paths or seen as a duplicate
            if ($this->isSafePath($uri)) {
                continue;
            }
            $key = $uri . '|' . $method;
            if (isset($seenPaths[$key])) {
                continue;
            }
            $seenPaths[$key] = true;

            // Build chained rule: URI match + method match
            $ruleText = sprintf(
                'SecRule REQUEST_URI "@streq %s" "id:%d,phase:1,pass,nolog,chain,setvar:TX.lockdown_allowed=1"' . "\n" .
                '    SecRule REQUEST_METHOD "@streq %s" ""',
                $uri,
                $ruleId,
                $method
            );

            $rules[] = [
                'rule_id'     => $ruleId,
                'uri_pattern' => $uri,
                'method_pattern' => $method,
                'rule_text'   => $ruleText,
                'description' => "Learned: {$method} {$uri} (freq: {$pattern['frequency']})",
                'is_auto'     => 1,
            ];
            $ruleId++;

            // Stay within the rule ID range
            if ($ruleId >= self::RULE_ID_DENY - 1) {
                break;
            }
        }

        // Insert rules into the database
        foreach ($rules as $rule) {
            try {
                $this->db->query(
                    "INSERT INTO waf_whitelist_rules (rule_id, uri_pattern, method_pattern, rule_text, description, is_auto_generated, is_active)
                     VALUES (:rid, :uri, :method, :text, :desc, :auto, 1)
                     ON DUPLICATE KEY UPDATE
                         uri_pattern = :uri2, method_pattern = :method2, rule_text = :text2,
                         description = :desc2, is_auto_generated = :auto2, is_active = 1",
                    [
                        ':rid'     => $rule['rule_id'],
                        ':uri'     => $rule['uri_pattern'],
                        ':method'  => $rule['method_pattern'],
                        ':text'    => $rule['rule_text'],
                        ':desc'    => $rule['description'],
                        ':auto'    => $rule['is_auto'],
                        ':uri2'    => $rule['uri_pattern'],
                        ':method2' => $rule['method_pattern'],
                        ':text2'   => $rule['rule_text'],
                        ':desc2'   => $rule['description'],
                        ':auto2'   => $rule['is_auto'],
                    ]
                );
            } catch (\Exception $e) {
                error_log('LockdownService: failed to insert rule ' . $rule['rule_id'] . ': ' . $e->getMessage());
            }
        }

        // Write the whitelist config file
        $this->writeWhitelistConf();

        return count($rules);
    }

    /**
     * Get learned patterns for display in the admin UI.
     * Optionally filter to only patterns seen from a specific client IP.
     */
    public function getLearnedPatterns(int $limit = 500, string $ipFilter = ''): array
    {
        try {
            if ($ipFilter !== '') {
                // When filtering by IP, aggregate from waf_learned_requests
                return $this->db->fetchAll(
                    "SELECT r.uri, r.method,
                            COALESCE(p.param_names, '[]') AS param_names,
                            r.content_type, r.response_status,
                            COUNT(*) AS frequency,
                            MIN(r.request_at) AS first_seen,
                            MAX(r.request_at) AS last_seen
                     FROM waf_learned_requests r
                     LEFT JOIN waf_learned_patterns p ON p.uri = r.uri AND p.method = r.method
                     WHERE r.client_ip = :ip
                     GROUP BY r.uri, r.method, r.content_type, r.response_status
                     ORDER BY frequency DESC
                     LIMIT :limit",
                    [':ip' => $ipFilter, ':limit' => $limit]
                );
            }
            return $this->db->fetchAll(
                "SELECT uri, method, param_names, content_type, response_status, frequency, first_seen, last_seen
                 FROM waf_learned_patterns
                 ORDER BY frequency DESC
                 LIMIT :limit",
                [':limit' => $limit]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get individual requests for a specific pattern (URI + method).
     */
    public function getRequestsForPattern(string $uri, string $method, string $ipFilter = '', int $limit = 500): array
    {
        try {
            $sql = "SELECT id, uri, method, client_ip, user_agent, query_string, content_type, response_status, request_at
                    FROM waf_learned_requests
                    WHERE uri = :uri AND method = :method";
            $params = [':uri' => $uri, ':method' => $method];

            if ($ipFilter !== '') {
                $sql .= " AND client_ip = :ip";
                $params[':ip'] = $ipFilter;
            }

            $sql .= " ORDER BY request_at DESC LIMIT :limit";
            $params[':limit'] = $limit;

            return $this->db->fetchAll($sql, $params);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all distinct client IPs from learned requests.
     */
    public function getDistinctClientIPs(): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT client_ip, COUNT(*) AS cnt
                 FROM waf_learned_requests
                 WHERE client_ip != ''
                 GROUP BY client_ip
                 ORDER BY cnt DESC"
            );
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get active whitelist rules for display.
     */
    public function getWhitelistRules(): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT rule_id, uri_pattern, method_pattern, rule_text, description, is_auto_generated, is_active, created_at
                 FROM waf_whitelist_rules
                 ORDER BY rule_id ASC"
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get recent block events for display.
     */
    public function getRecentBlocks(int $limit = 50): array
    {
        // Ingest any new blocks from the Apache error log first
        $this->ingestBlockLog();

        try {
            $sql = "SELECT id, blocked_at, client_ip, uri, method, rule_id, rule_message, user_agent, request_headers, resolved
                    FROM waf_block_events
                    ORDER BY blocked_at DESC";
            if ($limit > 0) {
                $sql .= " LIMIT :limit";
                return $this->db->fetchAll($sql, [':limit' => $limit]);
            }
            return $this->db->fetchAll($sql);
        } catch (\Exception $e) {
            return [];
        }
    }

    // =========================================================================
    // IP Whitelist Management
    // =========================================================================

    /**
     * Get all whitelisted IPs.
     */
    public function getWhitelistedIPs(): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT id, ip_address, label, created_at FROM waf_ip_whitelist ORDER BY created_at DESC"
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Add an IP (or CIDR) to the whitelist. Regenerates scanner-detection.conf.
     */
    public function addWhitelistedIP(string $ip, string $label = ''): bool
    {
        $ip = trim($ip);
        if ($ip === '') return false;

        // Validate IP or CIDR
        if (strpos($ip, '/') !== false) {
            [$addr, $mask] = explode('/', $ip, 2);
            if (!filter_var($addr, FILTER_VALIDATE_IP)) return false;
            if (!ctype_digit($mask) || (int)$mask < 0 || (int)$mask > 128) return false;
        } else {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        }

        try {
            // Skip if already present
            $existing = $this->db->fetchOne(
                "SELECT id FROM waf_ip_whitelist WHERE ip_address = :ip LIMIT 1",
                [':ip' => $ip]
            );
            if ($existing) return true;

            $this->db->query(
                "INSERT INTO waf_ip_whitelist (ip_address, label) VALUES (:ip, :label)",
                [':ip' => $ip, ':label' => $label]
            );
        } catch (\Exception $e) {
            return false;
        }

        return $this->rebuildAndReload();
    }

    /**
     * Remove an IP from the whitelist. Regenerates scanner-detection.conf.
     */
    public function removeWhitelistedIP(int $id): bool
    {
        try {
            $this->db->query("DELETE FROM waf_ip_whitelist WHERE id = :id", [':id' => $id]);
        } catch (\Exception $e) {
            return false;
        }
        return $this->rebuildAndReload();
    }

    /**
     * Get the current rate limit settings.
     */
    public function getRateLimitSettings(): array
    {
        return [
            'enabled'   => (bool)$this->getConfigValue('waf_rate_limit_enabled', '0'),
            'requests'  => (int)$this->getConfigValue('waf_rate_limit_requests', '100'),
            'period'    => (int)$this->getConfigValue('waf_rate_limit_period', '60'),
            'block_duration' => (int)$this->getConfigValue('waf_rate_limit_block', '300'),
        ];
    }

    /**
     * Update rate limit settings. Regenerates scanner-detection.conf.
     */
    public function updateRateLimitSettings(bool $enabled, int $requests, int $period, int $blockDuration): bool
    {
        $requests = max(10, min(10000, $requests));
        $period = max(10, min(3600, $period));
        $blockDuration = max(60, min(86400, $blockDuration));

        $this->setConfigValue('waf_rate_limit_enabled', $enabled ? '1' : '0');
        $this->setConfigValue('waf_rate_limit_requests', (string)$requests);
        $this->setConfigValue('waf_rate_limit_period', (string)$period);
        $this->setConfigValue('waf_rate_limit_block', (string)$blockDuration);

        return $this->rebuildAndReload();
    }

    /**
     * Rebuild scanner-detection.conf (and whitelist conf) and reload Apache.
     */
    private function rebuildAndReload(): bool
    {
        $content = $this->buildScannerDetectionConf();
        $ok1 = $this->writeFileViaSudo(self::SCANNER_CONF, $content);
        $ok2 = $this->writeFileViaSudo(self::PERSISTENT_SCANNER, $content);

        // Update GRC assessment rules IP whitelist
        $this->updateGrcAssessmentWhitelist();

        // Reload Apache
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('sudo /usr/sbin/apachectl graceful', $desc, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
            stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($proc);
        }

        return $ok1 && $ok2;
    }

    /**
     * Update the GRC assessment rules to skip rate limiting for whitelisted IPs.
     * Reads the current grc-assessment-rules.conf file and replaces the IP whitelist
     * skip rule (501039) with the current set of whitelisted IPs from the database.
     */
    private function updateGrcAssessmentWhitelist(): void
    {
        $confPath = self::GRC_ASSESS_CONF;
        if (!file_exists($confPath)) return;

        $conf = file_get_contents($confPath);
        if ($conf === false) return;

        // Build the IP list from the database
        $ipList = '';
        try {
            $ips = $this->db->fetchAll("SELECT ip_address FROM waf_ip_whitelist ORDER BY id ASC");
            if (!empty($ips)) {
                $ipList = implode(',', array_map(function($r) { return $r['ip_address']; }, $ips));
            }
        } catch (\Exception $e) {
            return;
        }

        // Build the skip rule block
        $marker_start = '# ── GRC Rate Limit IP Whitelist (auto-managed by LockdownService) ──';
        $marker_end   = '# ── End GRC Rate Limit IP Whitelist ──';

        if (!empty($ipList)) {
            $skipBlock = $marker_start . "\n";
            $skipBlock .= "# Last updated: " . $this->timestamp() . "\n";
            $skipBlock .= "# Whitelisted IPs skip GRC assessment rate limiting and all ModSecurity deny rules.\n";
            $skipBlock .= "SecRule REMOTE_ADDR \"@ipMatch {$ipList}\" \\\n";
            $skipBlock .= "    \"id:501039,phase:1,pass,nolog,\\\n";
            $skipBlock .= "    ctl:ruleRemoveById=501040;501041;501042;501043;501044;501045;501078;501079;501080,\\\n";
            $skipBlock .= "    tag:'grc-assessment',tag:'ip-whitelist'\"\n";
            $skipBlock .= $marker_end;
        } else {
            $skipBlock = $marker_start . "\n";
            $skipBlock .= "# No whitelisted IPs configured.\n";
            $skipBlock .= $marker_end;
        }

        // Replace existing block or insert before SECTION 5
        if (strpos($conf, $marker_start) !== false) {
            $conf = preg_replace(
                '/' . preg_quote($marker_start, '/') . '.*?' . preg_quote($marker_end, '/') . '/s',
                $skipBlock,
                $conf
            );
        } else {
            // Insert before SECTION 5: Rate Limiting
            $section5 = '# ── SECTION 5: Rate Limiting';
            $conf = str_replace($section5, $skipBlock . "\n\n" . $section5, $conf);
        }

        $this->writeFileViaSudo($confPath, $conf);
        $this->writeFileViaSudo(self::PERSISTENT_GRC_ASSESS, $conf);
    }

    /**
     * Parse the Apache error log for ModSecurity "Access denied" entries and
     * insert any new ones into waf_block_events. Uses a file-offset bookmark
     * so we only read new lines on each call.
     */
    public function ingestBlockLog(): void
    {
        $logFile = '/var/log/apache2/error.log';
        if (!is_readable($logFile)) return;

        $bookmarkFile = '/tmp/.waf_block_ingest_offset';
        $lastOffset = 0;
        if (file_exists($bookmarkFile)) {
            $lastOffset = (int)file_get_contents($bookmarkFile);
        }

        $fileSize = filesize($logFile);
        // If the file shrank (log rotation), reset
        if ($fileSize < $lastOffset) {
            $lastOffset = 0;
        }
        if ($fileSize === $lastOffset) return; // nothing new

        $fh = fopen($logFile, 'r');
        if (!$fh) return;
        fseek($fh, $lastOffset);

        $newEntries = [];
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, 'ModSecurity: Access denied') === false) continue;

            // Parse timestamp: [Thu Mar 05 17:55:57.681441 2026]
            if (!preg_match('/^\[(\w+ \w+ \d+ [\d:]+)[\.\d]* (\d{4})\]/', $line, $tsMatch)) continue;
            $blockedAt = date('Y-m-d H:i:s', strtotime($tsMatch[1] . ' ' . $tsMatch[2]));

            // Parse client IP: [client 1.2.3.4:0] or [client 1.2.3.4]
            $clientIp = '';
            if (preg_match('/\[client ([\d.]+)/', $line, $ipMatch)) {
                $clientIp = $ipMatch[1];
            }

            // Parse rule ID: [id "400026"]
            $ruleId = 0;
            if (preg_match('/\[id "(\d+)"\]/', $line, $idMatch)) {
                $ruleId = (int)$idMatch[1];
            }

            // Parse message: [msg "..."]
            $ruleMsg = '';
            if (preg_match('/\[msg "([^"]+)"\]/', $line, $msgMatch)) {
                $ruleMsg = $msgMatch[1];
            }

            // Parse URI: [uri "/admin.php"]
            $uri = '';
            if (preg_match('/\[uri "([^"]+)"\]/', $line, $uriMatch)) {
                $uri = $uriMatch[1];
            }

            // Determine method from phase (phase 1 = GET-like, phase 2 = POST-like)
            $method = 'GET';
            if (preg_match('/phase (\d)/', $line, $phaseMatch)) {
                $method = ($phaseMatch[1] === '2') ? 'POST' : 'GET';
            }

            // Parse additional fields for detail view
            $tag = '';
            if (preg_match('/\[tag "([^"]+)"\]/', $line, $tagMatch)) {
                $tag = $tagMatch[1];
            }
            $hostname = '';
            if (preg_match('/\[hostname "([^"]+)"\]/', $line, $hostMatch)) {
                $hostname = $hostMatch[1];
            }
            $file = '';
            if (preg_match('/\[file "([^"]+)"\]/', $line, $fileMatch)) {
                $file = $fileMatch[1];
            }
            $matchDetail = '';
            if (preg_match('/Access denied with code \d+ \(phase \d\)\.\s*(.+?)\s*\[file/', $line, $detMatch)) {
                $matchDetail = $detMatch[1];
            }
            $uniqueId = '';
            if (preg_match('/\[unique_id "([^"]+)"\]/', $line, $uidMatch)) {
                $uniqueId = $uidMatch[1];
            }

            // Store extended details as JSON in request_headers column
            $details = json_encode([
                'tag' => $tag,
                'hostname' => $hostname,
                'file' => $file,
                'match_detail' => $matchDetail,
                'unique_id' => $uniqueId,
            ], JSON_UNESCAPED_SLASHES);

            $newEntries[] = [
                'blocked_at' => $blockedAt,
                'client_ip' => $clientIp,
                'uri' => $uri,
                'method' => $method,
                'rule_id' => $ruleId,
                'rule_message' => $ruleMsg,
                'details' => $details,
            ];
        }

        $newOffset = ftell($fh);
        fclose($fh);

        // Insert new entries (skip duplicates by unique_id or timestamp+ip+uri)
        foreach ($newEntries as $entry) {
            try {
                // Avoid duplicates: check if an entry with same timestamp+ip+uri+rule exists
                $existing = $this->db->fetchOne(
                    "SELECT id FROM waf_block_events WHERE blocked_at = :at AND client_ip = :ip AND uri = :uri AND rule_id = :rid LIMIT 1",
                    [':at' => $entry['blocked_at'], ':ip' => $entry['client_ip'], ':uri' => $entry['uri'], ':rid' => $entry['rule_id']]
                );
                if ($existing) continue;

                $this->db->query(
                    "INSERT INTO waf_block_events (blocked_at, client_ip, uri, method, rule_id, rule_message, request_headers, resolved)
                     VALUES (:at, :ip, :uri, :method, :rid, :msg, :details, 0)",
                    [
                        ':at' => $entry['blocked_at'],
                        ':ip' => $entry['client_ip'],
                        ':uri' => $entry['uri'],
                        ':method' => $entry['method'],
                        ':rid' => $entry['rule_id'],
                        ':msg' => $entry['rule_message'],
                        ':details' => $entry['details'] ?? null,
                    ]
                );
            } catch (\Exception $e) {
                // Skip individual insert failures
            }
        }

        // Save bookmark
        file_put_contents($bookmarkFile, (string)$newOffset);
    }

    /**
     * Add a manual exception (whitelist rule) for a blocked URI+method.
     */
    /**
     * SECURITY: a URI / method / pattern is only safe to embed inside a ModSecurity
     * rule operand if it contains none of  "  \  CR  LF  — any of those would break out
     * of the quoted operand or inject a new directive into the .conf that is written to
     * the live Apache config via `sudo tee`. Legitimate request URIs and HTTP methods
     * never contain these characters, so we reject (not escape) unsafe values.
     */
    private function isWafTokenSafe(string $value): bool
    {
        return !preg_match('/["\\\\\r\n]/', $value);
    }

    public function addException(string $uri, string $method, string $description = ''): bool
    {
        // SECURITY: reject values that could inject ModSecurity directives.
        if (!$this->isWafTokenSafe($uri) || !$this->isWafTokenSafe($method)) {
            return false;
        }

        // Check if this URI+method combination already has an active rule
        try {
            $existing = $this->db->fetchOne(
                "SELECT rule_id FROM waf_whitelist_rules WHERE uri_pattern = :uri AND method_pattern = :method AND is_active = 1",
                [':uri' => $uri, ':method' => $method]
            );
            if ($existing) {
                return true; // Already whitelisted, nothing to do
            }
        } catch (\Exception $e) {}

        // Find the next available rule ID for manual rules (start after auto-generated)
        $nextId = self::RULE_ID_START;
        try {
            $row = $this->db->fetchOne("SELECT MAX(rule_id) AS max_id FROM waf_whitelist_rules");
            $nextId = max(self::RULE_ID_START, (int)($row['max_id'] ?? 0) + 1);
        } catch (\Exception $e) {}

        if ($nextId >= self::RULE_ID_DENY) {
            return false; // No room for more rules
        }

        $ruleText = sprintf(
            'SecRule REQUEST_URI "@streq %s" "id:%d,phase:1,pass,nolog,chain,setvar:TX.lockdown_allowed=1"' . "\n" .
            '    SecRule REQUEST_METHOD "@streq %s" ""',
            $uri,
            $nextId,
            $method
        );

        if (empty($description)) {
            $description = "Manual exception: {$method} {$uri}";
        }

        try {
            $this->db->query(
                "INSERT INTO waf_whitelist_rules (rule_id, uri_pattern, method_pattern, rule_text, description, is_auto_generated, is_active)
                 VALUES (:rid, :uri, :method, :text, :desc, 0, 1)
                 ON DUPLICATE KEY UPDATE rule_text = :text2, description = :desc2, is_active = 1",
                [
                    ':rid'    => $nextId,
                    ':uri'    => $uri,
                    ':method' => $method,
                    ':text'   => $ruleText,
                    ':desc'   => $description,
                    ':text2'  => $ruleText,
                    ':desc2'  => $description,
                ]
            );
        } catch (\Exception $e) {
            error_log('LockdownService::addException error: ' . $e->getMessage());
            return false;
        }

        // Mark related block events as resolved
        try {
            $this->db->query(
                "UPDATE waf_block_events SET resolved = 1 WHERE uri = :uri AND method = :method",
                [':uri' => $uri, ':method' => $method]
            );
        } catch (\Exception $e) {}

        // Rewrite the whitelist config and reload Apache
        $this->writeWhitelistConf();
        $this->reloadApache();

        return true;
    }

    /**
     * Remove a whitelist rule by rule_id.
     */
    public function removeRule(int $ruleId): bool
    {
        try {
            $this->db->query(
                "DELETE FROM waf_whitelist_rules WHERE rule_id = :rid",
                [':rid' => $ruleId]
            );
        } catch (\Exception $e) {
            return false;
        }

        $this->writeWhitelistConf();
        $this->reloadApache();

        return true;
    }

    /**
     * Clear all learned patterns (reset learning data).
     */
    public function clearLearnedPatterns(): bool
    {
        try {
            $this->db->query("DELETE FROM waf_learned_patterns");
            $this->db->query("DELETE FROM waf_learned_requests");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the current whitelist config file content for download.
     */
    public function getWhitelistContent(): string
    {
        $file = self::PERSISTENT_WHITELIST;
        if (file_exists($file)) {
            return file_get_contents($file) ?: '';
        }
        return '';
    }

    /**
     * Export all whitelist rules and learned patterns as a JSON structure.
     */
    public function exportRules(): array
    {
        $rules = [];
        $patterns = [];

        try {
            $rules = $this->db->fetchAll(
                "SELECT rule_id, uri_pattern, method_pattern, rule_text, description, is_auto_generated, is_active
                 FROM waf_whitelist_rules ORDER BY rule_id ASC"
            );
        } catch (\Exception $e) {}

        try {
            $patterns = $this->db->fetchAll(
                "SELECT uri, method, param_names, content_type, response_status, frequency, first_seen, last_seen
                 FROM waf_learned_patterns ORDER BY frequency DESC"
            );
        } catch (\Exception $e) {}

        return [
            'export_version' => 1,
            'exported_at'    => date('Y-m-d H:i:s T'),
            'waf_mode'       => $this->getConfigValue('waf_mode', 'disabled'),
            'rules'          => $rules,
            'patterns'       => $patterns,
        ];
    }

    /**
     * Import rules and patterns from an exported JSON structure.
     * Returns [rules_imported, patterns_imported] counts.
     */
    public function importRules(array $data): array
    {
        $rulesImported = 0;
        $patternsImported = 0;

        $importRules = $data['rules'] ?? [];
        $importPatterns = $data['patterns'] ?? [];

        foreach ($importRules as $rule) {
            $ruleId = (int)($rule['rule_id'] ?? 0);
            if ($ruleId < self::RULE_ID_START || $ruleId >= self::RULE_ID_DENY) {
                continue;
            }

            // SECURITY: never trust the imported rule_text — it is written verbatim into
            // the live ModSecurity config. Rebuild it server-side from the (validated)
            // pattern fields so a tampered import file cannot inject arbitrary directives.
            $uriPattern = (string)($rule['uri_pattern'] ?? '');
            $methodPattern = (string)($rule['method_pattern'] ?? '*');
            if ($uriPattern === '' || !$this->isWafTokenSafe($uriPattern) || !$this->isWafTokenSafe($methodPattern)) {
                continue; // skip unsafe/malformed rule
            }
            $isPrefix = substr($uriPattern, -1) === '*';
            $matchUri = $isPrefix ? rtrim($uriPattern, '*') : $uriPattern;
            $uriOp = $isPrefix ? '@beginsWith' : '@streq';
            if ($methodPattern === '*') {
                $ruleText = sprintf(
                    'SecRule REQUEST_URI "%s %s" "id:%d,phase:1,pass,nolog,setvar:TX.lockdown_allowed=1"',
                    $uriOp, $matchUri, $ruleId
                );
            } else {
                $ruleText = sprintf(
                    'SecRule REQUEST_URI "%s %s" "id:%d,phase:1,pass,nolog,chain,setvar:TX.lockdown_allowed=1"' . "\n" .
                    '    SecRule REQUEST_METHOD "@streq %s" ""',
                    $uriOp, $matchUri, $ruleId, $methodPattern
                );
            }
            $desc = str_replace(["\r", "\n"], ' ', (string)($rule['description'] ?? ''));

            try {
                $this->db->query(
                    "INSERT INTO waf_whitelist_rules (rule_id, uri_pattern, method_pattern, rule_text, description, is_auto_generated, is_active)
                     VALUES (:rid, :uri, :method, :text, :desc, :auto, :active)
                     ON DUPLICATE KEY UPDATE
                         uri_pattern = :uri2, method_pattern = :method2, rule_text = :text2,
                         description = :desc2, is_auto_generated = :auto2, is_active = :active2",
                    [
                        ':rid'     => $ruleId,
                        ':uri'     => $uriPattern,
                        ':method'  => $methodPattern,
                        ':text'    => $ruleText,
                        ':desc'    => $desc,
                        ':auto'    => (int)($rule['is_auto_generated'] ?? 1),
                        ':active'  => (int)($rule['is_active'] ?? 1),
                        ':uri2'    => $uriPattern,
                        ':method2' => $methodPattern,
                        ':text2'   => $ruleText,
                        ':desc2'   => $desc,
                        ':auto2'   => (int)($rule['is_auto_generated'] ?? 1),
                        ':active2' => (int)($rule['is_active'] ?? 1),
                    ]
                );
                $rulesImported++;
            } catch (\Exception $e) {
                error_log('LockdownService::importRules rule error: ' . $e->getMessage());
            }
        }

        foreach ($importPatterns as $p) {
            $uri = $p['uri'] ?? '';
            if (empty($uri)) {
                continue;
            }
            try {
                $this->db->query(
                    "INSERT INTO waf_learned_patterns (uri, method, param_names, content_type, response_status, frequency, first_seen, last_seen)
                     VALUES (:uri, :method, :params, :ct, :status, :freq, :first, :last)
                     ON DUPLICATE KEY UPDATE
                         frequency = frequency + :freq2,
                         last_seen = GREATEST(last_seen, :last2),
                         first_seen = LEAST(first_seen, :first2),
                         param_names = IF(LENGTH(:params2) > LENGTH(param_names), :params3, param_names)",
                    [
                        ':uri'     => substr($uri, 0, 500),
                        ':method'  => $p['method'] ?? 'GET',
                        ':params'  => $p['param_names'] ?? '[]',
                        ':ct'      => substr($p['content_type'] ?? '', 0, 200),
                        ':status'  => (int)($p['response_status'] ?? 200),
                        ':freq'    => max(1, (int)($p['frequency'] ?? 1)),
                        ':first'   => $p['first_seen'] ?? date('Y-m-d H:i:s'),
                        ':last'    => $p['last_seen'] ?? date('Y-m-d H:i:s'),
                        ':freq2'   => max(1, (int)($p['frequency'] ?? 1)),
                        ':last2'   => $p['last_seen'] ?? date('Y-m-d H:i:s'),
                        ':first2'  => $p['first_seen'] ?? date('Y-m-d H:i:s'),
                        ':params2' => $p['param_names'] ?? '[]',
                        ':params3' => $p['param_names'] ?? '[]',
                    ]
                );
                $patternsImported++;
            } catch (\Exception $e) {
                error_log('LockdownService::importRules pattern error: ' . $e->getMessage());
            }
        }

        // Rewrite the whitelist config if rules were imported
        if ($rulesImported > 0) {
            $this->writeWhitelistConf();
        }

        return [$rulesImported, $patternsImported];
    }

    /**
     * Rotate audit log if it exceeds the configured maximum size.
     */
    public function rotateAuditLog(): bool
    {
        $maxMb = (int)$this->getConfigValue('waf_audit_log_max_mb', '100');
        $logFile = self::AUDIT_LOG;

        if (!file_exists($logFile)) {
            return false;
        }

        $sizeMb = filesize($logFile) / (1024 * 1024);
        if ($sizeMb < $maxMb) {
            return false; // No rotation needed
        }

        // Truncate the log
        return $this->writeFileViaSudo($logFile, '');
    }

    // =========================================================================
    // Scanner Blocking
    // =========================================================================

    /**
     * Check if automated scanner blocking is enabled.
     */
    public function isScannerBlockingEnabled(): bool
    {
        return $this->getConfigValue('waf_scanner_blocking', '0') === '1';
    }

    /**
     * Enable or disable automated scanner blocking.
     * Writes/clears the scanner-detection.conf and reloads Apache.
     */
    public function setScannerBlocking(bool $enabled): bool
    {
        $conf = $enabled ? $this->buildScannerDetectionConf() : '';

        // Back up current config for rollback
        $backupConf = file_exists(self::SCANNER_CONF) ? file_get_contents(self::SCANNER_CONF) : '';

        if (!$this->writeFileViaSudo(self::SCANNER_CONF, $conf)) {
            return false;
        }

        // Validate before committing
        if (!$this->apacheConfigTest()) {
            $this->writeFileViaSudo(self::SCANNER_CONF, $backupConf);
            error_log('LockdownService::setScannerBlocking: Apache config test failed, rolled back');
            return false;
        }

        // Persist and update DB state
        $this->writeFileViaSudo(self::PERSISTENT_SCANNER, $conf);
        $this->setConfigValue('waf_scanner_blocking', $enabled ? '1' : '0');

        return $this->reloadApache();
    }

    /**
     * Build the ModSecurity scanner-detection.conf content.
     * Rules use IDs 400001–400099 and run in phase:1/phase:2 before whitelist
     * evaluation. Covers scanner UA detection, SQL/code injection, SSTI, XSS,
     * path traversal, OAST callbacks, recon probes, and fuzzed input.
     */
    private function buildScannerDetectionConf(): string
    {
        $scannerAgents = implode('|', [
            'Burp Suite', 'BurpSuite',
            'Nikto',
            'sqlmap',
            'OWASP ZAP', 'ZAP',
            'Nmap', 'Nmap Scripting Engine',
            'Acunetix',
            'Nessus',
            'w3af',
            'Arachni',
            'Skipfish',
            'Wapiti',
            'DirBuster', 'Dirbuster',
            'Gobuster',
            'ffuf',
            'Nuclei', 'nuclei',
            'httpx',
            'Masscan', 'masscan',
            'OpenVAS',
            'Qualys',
            'WPScan',
            'Commix',
            'XSStrike',
            'Hydra',
            'Medusa',
        ]);

        // Build IP whitelist skip rules
        $ipWhitelistBlock = '';
        try {
            $ips = $this->db->fetchAll("SELECT ip_address FROM waf_ip_whitelist ORDER BY id ASC");
            if (!empty($ips)) {
                $ipWhitelistBlock .= "# ── SECTION 0: IP Whitelist (skip all rules for trusted IPs) ───────────\n\n";
                foreach ($ips as $idx => $row) {
                    $ruleId = 399900 + $idx;
                    $ipEsc = addcslashes($row['ip_address'], '"\\');
                    if (strpos($row['ip_address'], '/') !== false) {
                        // CIDR notation
                        $ipWhitelistBlock .= "SecRule REMOTE_ADDR \"@ipMatch {$ipEsc}\" \\\n";
                        $ipWhitelistBlock .= "    \"id:{$ruleId},phase:1,allow,nolog,msg:'IP whitelist: {$ipEsc}'\"\n\n";
                    } else {
                        // Single IP
                        $ipWhitelistBlock .= "SecRule REMOTE_ADDR \"@ipMatch {$ipEsc}\" \\\n";
                        $ipWhitelistBlock .= "    \"id:{$ruleId},phase:1,allow,nolog,msg:'IP whitelist: {$ipEsc}'\"\n\n";
                    }
                }
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        // Build rate limiting rules
        $rateLimitBlock = '';
        $rl = $this->getRateLimitSettings();
        if ($rl['enabled']) {
            $rateLimitBlock = <<<RL

# ── SECTION 10: Rate Limiting ─────────────────────────────────────────────
# Tracks requests per IP using ModSecurity IP collections.
# Threshold: {$rl['requests']} requests per {$rl['period']}s, block for {$rl['block_duration']}s

# Initialize IP collection and increment request counter
SecAction "id:400060,phase:1,nolog,pass,initcol:ip=%{REMOTE_ADDR},setvar:ip.req_count=+1,expirevar:ip.req_count={$rl['period']}"

# If already flagged as blocked, deny immediately
SecRule IP:blocked "@eq 1" \
    "id:400061,phase:1,deny,status:429,log,msg:'Rate limit: IP temporarily blocked',tag:'rate-limit'"

# If request count exceeds threshold, flag IP as blocked and deny
SecRule IP:req_count "@gt {$rl['requests']}" \
    "id:400062,phase:1,deny,status:429,log,msg:'Rate limit exceeded: %{ip.req_count} requests in {$rl['period']}s',tag:'rate-limit',setvar:ip.blocked=1,expirevar:ip.blocked={$rl['block_duration']},setvar:ip.req_count=0"

RL;
        }

        return <<<CONF
# =============================================================================
# Open TPRM - Automated Scanner & Attack Detection Rules
# Managed by Admin > Maintenance > Lockdown > Scanner Blocking
# =============================================================================
# Rule ID range: 399900-400099 (separate from whitelist 500000-599999)
# These rules run in phase:1/phase:2 BEFORE whitelist evaluation, so attacks
# are blocked immediately regardless of whether the path is whitelisted.
# Last updated: {$this->timestamp()}
# =============================================================================

{$ipWhitelistBlock}
# ── SECTION 1: Known Scanner Fingerprints ─────────────────────────────────

# Rule 400001: Block known scanner User-Agent strings
SecRule REQUEST_HEADERS:User-Agent "@rx (?i)({$scannerAgents})" \
    "id:400001,phase:1,deny,status:403,log,msg:'Scanner detected: known scanner User-Agent',tag:'scanner-detection'"

# Rule 400002: Block requests with missing User-Agent
SecRule &REQUEST_HEADERS:User-Agent "@eq 0" \
    "id:400002,phase:1,deny,status:403,log,msg:'Scanner detected: missing User-Agent header',tag:'scanner-detection'"

# Rule 400003: Block requests with empty User-Agent
SecRule REQUEST_HEADERS:User-Agent "@streq " \
    "id:400003,phase:1,deny,status:403,log,msg:'Scanner detected: empty User-Agent header',tag:'scanner-detection'"

# Rule 400004: Block requests with scanner-specific headers
SecRule REQUEST_HEADERS_NAMES "@rx (?i)^(X-Scanner|X-Burp-|X-ZAP-)" \
    "id:400004,phase:1,deny,status:403,log,msg:'Scanner detected: scanner-specific header present',tag:'scanner-detection'"

# Rule 400005: Block fuzzed User-Agents (valid browser UA with random suffix appended)
# Scanners append random tokens to real UAs for tracking; real browsers never do this.
SecRule REQUEST_HEADERS:User-Agent "@rx Safari/[\d.]+\s+[a-z0-9]{6,}" \
    "id:400005,phase:1,deny,status:403,log,msg:'Scanner detected: fuzzed User-Agent suffix',tag:'scanner-detection'"

# Rule 400006: Block template injection / SSTI payloads in User-Agent
SecRule REQUEST_HEADERS:User-Agent "@rx [\$%]\{\{|\\\\z\x60z" \
    "id:400006,phase:1,deny,status:403,log,msg:'Scanner detected: SSTI payload in User-Agent',tag:'scanner-detection'"


# ── SECTION 2: SQL Injection ──────────────────────────────────────────────

# Rule 400010: SQL injection in URI path (time-based blind: sleep, waitfor, pg_sleep)
SecRule REQUEST_URI "@rx (?i)(?:sleep\(|waitfor\s+delay|pg_sleep\(|benchmark\()" \
    "id:400010,phase:1,deny,status:403,log,msg:'SQLi detected: time-based blind injection in URI',tag:'attack-sqli',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400011: SQL injection in URI path (UNION-based, error-based)
SecRule REQUEST_URI "@rx (?i)(?:union\s+(?:all\s+)?select|select\s.*from\s|insert\s+into|drop\s+table|alter\s+table|delete\s+from)" \
    "id:400011,phase:1,deny,status:403,log,msg:'SQLi detected: SQL statement in URI',tag:'attack-sqli',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400012: SQL injection in query string
SecRule QUERY_STRING "@rx (?i)(?:sleep\(|waitfor\s+delay|pg_sleep\(|union\s+(?:all\s+)?select|select\s.*from\s)" \
    "id:400012,phase:1,deny,status:403,log,msg:'SQLi detected: injection payload in query string',tag:'attack-sqli',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400013: SQL injection in POST body
SecRule REQUEST_BODY "@rx (?i)(?:sleep\(|waitfor\s+delay|pg_sleep\(|union\s+(?:all\s+)?select|;\s*drop\s+table|;\s*delete\s+from)" \
    "id:400013,phase:2,deny,status:403,log,msg:'SQLi detected: injection payload in POST body',tag:'attack-sqli',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400014: SQL comment/terminator probing in parameters
# Matches SQL-style comments only when preceded by typical injection closers (' " ) or digits)
SecRule ARGS "@rx (?:['\")0-9])\s*(?:--|#|/\*)\s*$" \
    "id:400014,phase:2,deny,status:403,log,msg:'SQLi detected: SQL comment terminator in parameter',tag:'attack-sqli',t:none,t:urlDecodeUni,chain"
SecRule REQUEST_URI "@rx \.php" ""


# ── SECTION 3: Code Injection / RCE ──────────────────────────────────────

# Rule 400020: Python code injection (eval/compile/exec/import)
SecRule REQUEST_URI "@rx (?i)(?:eval\(|compile\(|exec\(|import\s+os|__import__)" \
    "id:400020,phase:1,deny,status:403,log,msg:'RCE detected: Python code injection in URI',tag:'attack-rce',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400021: OS command injection patterns
SecRule REQUEST_URI "@rx (?:;\s*(?:cat|ls|id|whoami|uname|curl|wget|nc|bash|sh)\s|(?:\||\x60)(?:cat|ls|id|whoami|curl|wget))" \
    "id:400021,phase:1,deny,status:403,log,msg:'RCE detected: OS command injection in URI',tag:'attack-rce',t:none,t:urlDecodeUni"

# Rule 400022: OS command injection in POST body
SecRule REQUEST_BODY "@rx (?:;\s*(?:cat|ls|id|whoami|uname|curl|wget|nc|bash|sh)\b|(?:\||\x60)(?:cat|ls|id|whoami|curl|wget))" \
    "id:400022,phase:2,deny,status:403,log,msg:'RCE detected: OS command injection in POST body',tag:'attack-rce',t:none,t:urlDecodeUni"


# ── SECTION 4: Template Injection / SSTI ──────────────────────────────────

# Rule 400024: Exempt the admin panel from the POST-body SSTI and XSS content
# rules. /admin.php is authenticated and CSRF-protected, and the email template
# editor (admin.php?section=email) legitimately authors HTML email bodies that
# contain {{placeholder}} markers (e.g. {{vendor_name}}, {{assessment_url}} — the
# SSTI signature, rule 400026) and arbitrary HTML markup / event-handler
# attributes (the XSS signature, rule 400031). Unauthenticated requests never
# reach an admin POST handler (they are redirected to login), so these body rules
# only ever gate trusted admin input here.
#
# Two correctness notes — do not "tighten" this back into either broken form:
#  1. Matched via REQUEST_FILENAME, NOT REQUEST_URI. REQUEST_URI includes the
#     query string (e.g. ?section=email), so "@streq /admin.php" against
#     REQUEST_URI never matches a real admin request and the exemption silently
#     fails. This was the original bug behind the email-template-save 403.
#     REQUEST_FILENAME holds the path only — the same convention rule 400041 uses.
#  2. ctl:ruleRemoveById CANNOT be scoped with a chain (e.g. to section=email).
#     A ctl action on a chain starter executes the moment the starter matches,
#     before the chained conditions are evaluated, so the removal applies to all
#     of /admin.php regardless of any chained ARGS:section test. The exemption is
#     therefore panel-wide by necessity, which also matches the original intent.
SecRule REQUEST_FILENAME "@streq /admin.php" \
    "id:400024,phase:1,pass,nolog,ctl:ruleRemoveById=400026,ctl:ruleRemoveById=400031"

# Rule 400027: Exempt the procurement update API from SSTI POST-body detection.
# Procurement status-note free text may legitimately contain template-style
# markers like {{ }} that would otherwise trigger rule 400026. Uses
# REQUEST_FILENAME so a future query string cannot defeat the comparison.
SecRule REQUEST_FILENAME "@streq /api/procurement-update-save.php" \
    "id:400027,phase:1,pass,nolog,ctl:ruleRemoveById=400026"

# Rule 400025: SSTI payloads in URI, query string, or POST body
SecRule REQUEST_URI|QUERY_STRING "@rx [\$%]\{\{|#\{.*\}|\{\{.*\}\}|\{%.*%\}" \
    "id:400025,phase:1,deny,status:403,log,msg:'SSTI detected: template expression in request',tag:'attack-ssti',t:none,t:urlDecodeUni"

# Rule 400026: SSTI payloads in POST body
SecRule REQUEST_BODY "@rx [\$%]\{\{|#\{.*\}|\{\{.*\}\}|\{%.*%\}" \
    "id:400026,phase:2,deny,status:403,log,msg:'SSTI detected: template expression in POST body',tag:'attack-ssti',t:none,t:urlDecodeUni"


# ── SECTION 5: XSS ───────────────────────────────────────────────────────

# Rule 400030: Reflected XSS via script tags in URI or query string
SecRule REQUEST_URI|QUERY_STRING "@rx (?i)<script[\s>]|javascript\s*:|on(?:error|load|click|mouseover)\s*=" \
    "id:400030,phase:1,deny,status:403,log,msg:'XSS detected: script injection in request',tag:'attack-xss',t:none,t:urlDecodeUni,t:htmlEntityDecode,t:lowercase"

# Rule 400031: XSS via event handlers or script in POST body
SecRule REQUEST_BODY "@rx (?i)<script[\s>]|javascript\s*:|on(?:error|load|click|mouseover)\s*=\s*[\"']" \
    "id:400031,phase:2,deny,status:403,log,msg:'XSS detected: script injection in POST body',tag:'attack-xss',t:none,t:urlDecodeUni,t:htmlEntityDecode,t:lowercase"


# ── SECTION 6: Path Traversal / LFI ──────────────────────────────────────

# Rule 400035: Directory traversal in URI
SecRule REQUEST_URI "@rx (?:\.\./|\.\.\\\\|%2e%2e[/\\\\%])" \
    "id:400035,phase:1,deny,status:403,log,msg:'Path traversal detected in URI',tag:'attack-lfi',t:none,t:urlDecodeUni"

# Rule 400036: Sensitive file access attempts
SecRule REQUEST_URI "@rx (?i)(?:/etc/(?:passwd|shadow|hosts)|/proc/self|/\.env|/\.git/|/wp-config|/config\.php$)" \
    "id:400036,phase:1,deny,status:403,log,msg:'Sensitive file access attempt',tag:'attack-lfi',t:none,t:urlDecodeUni,t:lowercase"


# ── SECTION 7: Reconnaissance / Probing ──────────────────────────────────

# Rule 400040: Common scanner probe paths that don't exist in this application
SecRule REQUEST_URI "@rx (?i)^/(?:wp-(?:admin|login|content|includes)|phpmyadmin|adminer|\.well-known/security\.txt|cgi-bin|xmlrpc\.php|wp-cron\.php|readme\.html|license\.txt|server-(?:status|info)|\.aws|\.docker)" \
    "id:400040,phase:1,deny,status:403,log,msg:'Recon detected: probe for non-existent framework paths',tag:'recon-probe'"

# Rule 400041: Backup/archive file probing (uses REQUEST_FILENAME to check
# the path only, not query string — the admin backup download puts .sql in
# the query parameter and must not be blocked)
SecRule REQUEST_FILENAME "@rx (?i)\.(?:bak|old|orig|save|swp|sav|sql|tar|gz|zip|rar|7z|dump|log|conf|ini)$" \
    "id:400041,phase:1,deny,status:403,log,msg:'Recon detected: backup/archive file probe',tag:'recon-probe'"


# ── SECTION 8: Burp Collaborator / OAST Callbacks ────────────────────────

# Rule 400045: Burp Collaborator / OAST interaction domains in any input
SecRule REQUEST_URI|QUERY_STRING|REQUEST_BODY|REQUEST_HEADERS "@rx (?i)(?:\.oast(?:ify|i)?\.com|\.burpcollaborator\.net|\.interact\.sh|\.canarytokens\.)" \
    "id:400045,phase:2,deny,status:403,log,msg:'OAST detected: Burp Collaborator / interaction domain in request',tag:'scanner-detection',t:none,t:urlDecodeUni,t:lowercase"

# Rule 400046: OAST callbacks via spoofed Host header (DNS rebinding)
SecRule REQUEST_HEADERS:Host "@rx (?i)(?:oast|burp|interact\.sh|canarytokens)" \
    "id:400046,phase:1,deny,status:403,log,msg:'OAST detected: interaction domain in Host header',tag:'scanner-detection'"


# ── SECTION 9: Protocol Abuse ─────────────────────────────────────────────

# Rule 400050: Abnormally long URI (legitimate app URIs are well under 500 chars)
SecRule REQUEST_URI "@rx ^.{500,}$" \
    "id:400050,phase:1,deny,status:403,log,msg:'Protocol abuse: abnormally long URI',tag:'protocol-abuse'"

# Rule 400051: NULL bytes in request (classic injection technique)
SecRule REQUEST_URI|QUERY_STRING|REQUEST_BODY "@rx %00|\\\\x00" \
    "id:400051,phase:2,deny,status:403,log,msg:'Protocol abuse: NULL byte injection',tag:'protocol-abuse',t:none,t:urlDecodeUni"

# Rule 400052: HTTP method not used by this application
SecRule REQUEST_METHOD "!@rx ^(?:GET|POST|PUT|DELETE|HEAD|OPTIONS)$" \
    "id:400052,phase:1,deny,status:403,log,msg:'Protocol abuse: disallowed HTTP method',tag:'protocol-abuse'"
{$rateLimitBlock}
CONF;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function isSafePath(string $uri): bool
    {
        foreach (self::SAFE_PATHS as $path) {
            if ($uri === $path) {
                return true;
            }
        }
        foreach (self::SAFE_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the modsecurity.conf file content with the given engine directive.
     */
    private function buildModSecConf(string $engineDirective): string
    {
        $auditEngine = ($engineDirective === 'Off') ? 'Off' : 'RelevantOnly';
        $auditRelevant = ($engineDirective === 'On')
            ? 'SecAuditLogRelevantStatus "^(?:4|5)"'
            : 'SecAuditLogRelevantStatus "^."';

        return <<<CONF
# =============================================================================
# Open TPRM - ModSecurity Base Configuration
# Managed by Admin > Maintenance > Lockdown
# =============================================================================
# WARNING: This file is rewritten by the application when toggling WAF modes.
# Do not edit manually — changes will be overwritten.
# Last updated: {$this->timestamp()}
# =============================================================================

SecRuleEngine {$engineDirective}

# -- Request Body Handling --
SecRequestBodyAccess On
SecRequestBodyLimit 2147483648
SecRequestBodyNoFilesLimit 1048576
SecRequestBodyLimitAction Reject

# -- Response Body Handling --
SecResponseBodyAccess Off

# -- Audit Logging --
SecAuditEngine {$auditEngine}
{$auditRelevant}
SecAuditLogParts ABCFHZ
SecAuditLogType Serial
SecAuditLog /persistent/modsecurity/audit_log/modsec_audit.json

# -- Debug Log --
SecDebugLog /persistent/modsecurity/audit_log/modsec_debug.log
SecDebugLogLevel 0

# -- Misc --
SecArgumentSeparator &
SecCookieFormat 0
SecUnicodeMapFile /etc/modsecurity/unicode.mapping 20127

# -- Temp/Data directories --
SecTmpDir /tmp/
SecDataDir /tmp/
CONF;
    }

    /**
     * Write the lockdown-whitelist.conf from the database rules.
     */
    private function writeWhitelistConf(): bool
    {
        $lines = [];
        $lines[] = '# =============================================================================';
        $lines[] = '# Open TPRM - Lockdown Whitelist Rules';
        $lines[] = '# Auto-generated: ' . $this->timestamp();
        $lines[] = '# =============================================================================';
        $lines[] = '';

        try {
            $rules = $this->db->fetchAll(
                "SELECT rule_id, rule_text FROM waf_whitelist_rules WHERE is_active = 1 ORDER BY rule_id ASC"
            );
        } catch (\Exception $e) {
            $rules = [];
        }

        if (!empty($rules)) {
            foreach ($rules as $rule) {
                $lines[] = $rule['rule_text'];
                $lines[] = '';
            }

            // Final deny rule: block anything not explicitly allowed
            $lines[] = '# --- Final deny rule: block if not whitelisted ---';
            $lines[] = sprintf(
                'SecRule TX:lockdown_allowed "!@eq 1" "id:%d,phase:2,deny,status:403,log,msg:\'Lockdown: request not in whitelist\'"',
                self::RULE_ID_DENY
            );
            $lines[] = '';
        }

        $content = implode("\n", $lines);

        // Write to both active and persistent locations
        $ok1 = $this->writeFileViaSudo(self::WHITELIST_CONF, $content);
        $ok2 = $this->writeFileViaSudo(self::PERSISTENT_WHITELIST, $content);

        return $ok1 && $ok2;
    }

    /**
     * Write a file using proc_open + sudo tee (popen is disabled).
     */
    private function writeFileViaSudo(string $path, string $content): bool
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('sudo /usr/bin/tee ' . escapeshellarg($path), $descriptors, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fwrite($pipes[0], $content);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return $exitCode === 0;
    }

    /**
     * Test Apache config validity without reloading.
     */
    private function apacheConfigTest(): bool
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('sudo /usr/sbin/apachectl configtest', $descriptors, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        if ($exitCode !== 0) {
            error_log('LockdownService: Apache configtest failed: ' . $stderr);
        }
        return $exitCode === 0;
    }

    /**
     * Reload Apache to pick up config changes.
     */
    private function reloadApache(): bool
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('sudo /usr/sbin/apachectl graceful', $descriptors, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        return $exitCode === 0;
    }

    private function getConfigValue(string $key, string $default = ''): string
    {
        return getAppConfig($key, $default);
    }

    private function setConfigValue(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO app_config (config_key, config_value, description)
             VALUES (:key, :val, :desc)
             ON DUPLICATE KEY UPDATE config_value = :val2",
            [
                ':key'  => $key,
                ':val'  => $value,
                ':val2' => $value,
                ':desc' => "WAF lockdown: {$key}",
            ]
        );
        // Clear config cache
        getAppConfig('', null, true);
    }

    private function timestamp(): string
    {
        return date('Y-m-d H:i:s T');
    }
}
