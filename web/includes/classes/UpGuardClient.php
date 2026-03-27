<?php
/**
 * UpGuard CyberRisk API Client - Our Window into Vendor Security Scores
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This class is the designated driver for all communication with the UpGuard
 * CyberRisk API. It handles vendor monitoring (watch/unwatch), fetching security
 * scores, and pulling risk details. The main workflow in scoreVendor() is:
 * check if vendor is monitored -> monitor if not -> grab score -> grab risks
 * -> unmonitor if we added them (no orphaned monitoring). Think of it as
 * borrowing a library book, reading it, and actually returning it on time.
 *
 * API docs: https://cyber-risk.upguard.com/api/public
 */

class UpGuardClient
{
    private const API_BASE_URL = 'https://cyber-risk.upguard.com/api/public';
    private const TIMEOUT = 30;  // 30 seconds before we give up on UpGuard

    private ?string $apiKey = null;
    private bool $enabled = false;
    private ?string $orgDomain = null;  // Organization's own domain (uses /domains instead of /vendor/domains)
    private ?string $lastError = null;  // Stores the last thing that went wrong for debugging

    /**
     * Constructor -- loads API key and enabled flag from the database.
     * If the DB is down or the config is missing, we just silently disable ourselves.
     * No API key, no party.
     */
    public function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Pulls UpGuard config from the app_config table.
     * Decrypts the API key if it's stored encrypted (as it should be).
     * If anything goes wrong, we log it and the client stays disabled.
     * This is a "fail closed" design -- no config = no API calls.
     */
    private function loadConfig(): void
    {
        try {
            $db = Database::getInstance();
            $encryption = new Encryption();

            // Grab all upguard_* config rows in one query
            $config = $db->fetchAll(
                'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
                ['upguard_%']
            );

            foreach ($config as $row) {
                // Decrypt if needed -- because storing API keys in plaintext is how you end up on the news
                $value = $row['is_encrypted'] ? $encryption->decrypt($row['config_value']) : $row['config_value'];

                switch ($row['config_key']) {
                    case 'upguard_enabled':
                        $this->enabled = ($value === '1');
                        break;
                    case 'upguard_api_key':
                        $this->apiKey = $value ?: null;
                        break;
                    case 'upguard_org_domain':
                        $this->orgDomain = $value ? strtolower(trim($value)) : null;
                        break;
                }
            }
        } catch (Exception $e) {
            error_log('UpGuard: Failed to load config: ' . $e->getMessage());
        }
    }

    /**
     * Are we actually ready to make API calls?
     * Both the enabled flag AND a valid API key must be present.
     * This is the bouncer at the door of every API method.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }

    /**
     * Returns whatever went wrong last time. Useful for error messages
     * and debugging sessions that make you question your career choices.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The low-level HTTP request method -- the workhorse behind every API call.
     *
     * Supports GET, POST, PUT, DELETE. Some UpGuard endpoints are quirky and
     * want query parameters even on POST requests (looking at you, /vendor/monitor),
     * hence the $paramsAsQuery flag.
     *
     * Sets up cURL with auth headers, handles the response, and translates
     * HTTP errors into something useful. Returns parsed JSON or null on failure.
     */
    private function request(string $method, string $endpoint, array $params = [], bool $paramsAsQuery = false): ?array
    {
        // First things first -- are we even configured?
        if (!$this->isConfigured()) {
            $this->lastError = 'UpGuard integration is not configured';
            return null;
        }

        $url = self::API_BASE_URL . $endpoint;

        // GET and DELETE always use query params. POST/PUT only do if explicitly told to.
        // This is because UpGuard's API has some... creative design choices.
        if (!empty($params) && (in_array($method, ['GET', 'DELETE']) || $paramsAsQuery)) {
            $url .= '?' . http_build_query($params);
        }

        // Fire up cURL -- our trusty HTTP Swiss Army knife
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->apiKey,  // UpGuard uses bare API key auth, no Bearer prefix
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        // Configure the HTTP method and body
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                // Only send a JSON body if we're NOT using query params for this request
                if (!$paramsAsQuery && !empty($params)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!$paramsAsQuery && !empty($params)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        // Send it and see what happens
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // Note: curl_close() is deprecated in PHP 8.5, handle auto-closes when out of scope
        unset($ch);

        // cURL itself failed (network error, DNS issue, timeout, etc.)
        if ($curlError) {
            $this->lastError = 'cURL error: ' . $curlError;
            error_log('UpGuard API error: ' . $curlError);
            return null;
        }

        // 204 No Content = success with no body (some endpoints do this for monitoring ops)
        if ($httpCode === 204) {
            return ['success' => true];
        }

        $data = json_decode($response, true);

        // 4xx/5xx = UpGuard is unhappy with us
        if ($httpCode >= 400) {
            $this->lastError = $data['error'] ?? $data['message'] ?? "HTTP error $httpCode";
            error_log("UpGuard API error ($httpCode): " . json_encode($data));
            return null;
        }

        return $data;
    }

    /**
     * Checks if a vendor domain is already being monitored in our UpGuard account.
     * Pulls the full vendor list (up to 2000 -- should be enough for most orgs)
     * and does a case-insensitive hostname match.
     *
     * Fun fact: if you have more than 2000 monitored vendors, you probably have
     * bigger problems than this pagination limit.
     */
    public function isVendorMonitored(string $hostname): bool
    {
        $result = $this->request('GET', '/vendors', [
            'page_size' => 2000
        ]);

        if (!$result || !isset($result['vendors'])) {
            return false;
        }

        // Linear scan through the vendor list -- not ideal, but UpGuard doesn't
        // have a "is this specific vendor monitored?" endpoint. Gotta love it.
        foreach ($result['vendors'] as $vendor) {
            if (isset($vendor['primary_hostname']) &&
                strtolower($vendor['primary_hostname']) === strtolower($hostname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tells UpGuard to start monitoring a vendor domain.
     * Note: UpGuard's /vendor/monitor endpoint is one of those weird ones that
     * wants params as query strings on a POST request. Hence paramsAsQuery = true.
     */
    public function watchVendor(string $hostname, ?string $vendorName = null): ?array
    {
        $params = ['hostname' => $hostname];
        if ($vendorName) {
            $params['vendor_name'] = $vendorName;
        }

        // UpGuard /vendor/monitor uses query parameters, not request body. Yes, really.
        return $this->request('POST', '/vendor/monitor', $params, true);
    }

    /**
     * Tells UpGuard to stop monitoring a vendor domain.
     * Used when we temporarily monitored a vendor just to get their score
     * and now we're cleaning up after ourselves like responsible adults.
     */
    public function unwatchVendor(string $hostname): bool
    {
        // Same query-params-on-POST quirk as watchVendor
        $result = $this->request('POST', '/vendor/unmonitor', [
            'hostname' => $hostname
        ], true);

        return $result !== null;
    }

    /**
     * Gets the full vendor details from UpGuard, including their score.
     * The $waitForScan flag tells UpGuard to hold the response until they've
     * completed an initial scan -- useful for newly monitored vendors so we
     * don't get back a "we haven't scanned them yet" empty response.
     */
    public function getVendorDetails(string $hostname, bool $waitForScan = false): ?array
    {
        $params = ['hostname' => $hostname];
        if ($waitForScan) {
            $params['wait_for_scan'] = true;
        }
        return $this->request('GET', '/vendor', $params);
    }

    /**
     * Fetches the detailed risk findings for a vendor.
     * Note the endpoint is /risks/vendors (plural), not /vendor/risks.
     * The API naming convention here is... inconsistent, to put it diplomatically.
     */
    public function getVendorRisks(string $hostname): ?array
    {
        return $this->request('GET', '/risks/vendors', [
            'primary_hostname' => $hostname
        ]);
    }

    /**
     * Fetches all domains/subdomains for a vendor from UpGuard.
     * Returns both active and inactive domains with their individual scores.
     *
     * UpGuard uses separate API endpoints depending on who you're querying:
     * - /vendor/domains for third-party vendors you monitor
     * - /domains for your organization's own domain (BreachSight)
     *
     * If the org domain is configured in settings, we check that first to
     * pick the right endpoint without an extra API call.
     */
    public function getVendorDomains(string $hostname): ?array
    {
        // If the hostname matches the configured org domain, use /domains directly
        if ($this->orgDomain && strtolower($hostname) === $this->orgDomain) {
            error_log('UpGuard: ' . $hostname . ' matches org domain, using /domains endpoint');
            return $this->request('GET', '/domains', [
                'active' => 'true',
                'inactive' => 'true',
                'page_size' => 1000
            ]);
        }

        // For third-party vendors, use /vendor/domains
        return $this->request('GET', '/vendor/domains', [
            'vendor_primary_hostname' => $hostname,
            'active' => 'true',
            'inactive' => 'true',
            'page_size' => 1000
        ]);
    }

    /**
     * Static utility to convert an UpGuard numeric score (0-950) to a letter grade.
     * Uses hardcoded thresholds that match UpGuard's standard grading.
     * For configurable thresholds, use SRSService::calculateGrade() instead.
     */
    public static function scoreToGrade(int $score): string
    {
        if ($score >= 850) return 'A';  // Gold star
        if ($score >= 700) return 'B';  // Pretty good
        if ($score >= 500) return 'C';  // Could be worse
        if ($score >= 300) return 'D';  // Needs improvement (HR-speak for "yikes")
        return 'F';                     // Time to have "the talk" with this vendor
    }

    /**
     * The grand orchestrator -- scores a vendor end-to-end.
     *
     * This is the method you actually call when you want to score someone. It:
     * 1. Normalizes the hostname (lowercase, trimmed)
     * 2. Checks if the vendor is already monitored in UpGuard
     * 3. If not, starts monitoring them (temporarily)
     * 4. Fetches their score and details (waits for scan if newly added)
     * 5. Fetches their detailed risk findings
     * 6. Counts up risks by severity level
     * 7. If we added monitoring, removes it (clean up after yourself!)
     * 8. Returns a comprehensive score data array
     *
     * If anything fails mid-way and we added monitoring, we unmonitor
     * to avoid leaving orphaned vendors in our UpGuard account. Tidy!
     */
    public function scoreVendor(string $hostname, ?string $vendorName = null, bool $fetchDomains = false): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'UpGuard integration is not configured';
            return null;
        }

        // Normalize hostname -- "ExAmPlE.CoM " becomes "example.com"
        $hostname = strtolower(trim($hostname));

        // Step 1: Get vendor details. The /vendor endpoint returns data for ANY vendor
        // UpGuard has indexed -- NOT just ones we monitor. So we check 'first_monitored'
        // to tell if the vendor is actually in our monitoring list.
        $vendorDetails = $this->getVendorDetails($hostname);
        $wasAlreadyMonitored = ($vendorDetails !== null && !empty($vendorDetails['first_monitored']));

        // Step 2: If not in our monitoring list, start monitoring.
        // Even if UpGuard knows the vendor (vendorDetails != null), the /vendor/domains
        // endpoint requires the vendor to be actively monitored by our account.
        if (!$wasAlreadyMonitored) {
            $watchResult = $this->watchVendor($hostname, $vendorName);
            if (!$watchResult) {
                // If watch failed but we already have vendor details from the public lookup,
                // we can still return a score (just no domains)
                if ($vendorDetails) {
                    error_log('UpGuard: watchVendor failed for ' . $hostname . ' but have public details, continuing without domains');
                } else {
                    return null; // No details at all -- bail out
                }
            } else {
                // Refresh details after monitoring -- may include additional fields
                $freshDetails = $this->getVendorDetails($hostname, !$vendorDetails);
                if ($freshDetails) {
                    $vendorDetails = $freshDetails;
                } elseif (!$vendorDetails) {
                    $this->unwatchVendor($hostname);
                    return null;
                }
            }
        }

        // Use UpGuard's actual primary_hostname for all subsequent calls.
        $upguardHostname = $vendorDetails['primary_hostname'] ?? $hostname;

        error_log('UpGuard scoring ' . $hostname . ': primary_hostname=' . $upguardHostname
            . ', name=' . ($vendorDetails['name'] ?? 'null')
            . ', first_monitored=' . ($vendorDetails['first_monitored'] ?? 'null')
            . ', was_already_monitored=' . ($wasAlreadyMonitored ? 'yes' : 'no'));

        // Step 4: Get detailed risk findings (use UpGuard's hostname)
        $risks = $this->getVendorRisks($upguardHostname);

        // Step 5: Initialize risk severity counters
        // Start with overall_risk_counts from vendor details (most reliable source)
        $riskCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'info' => 0
        ];

        // Pull counts from the vendor details response if available
        if (isset($vendorDetails['overall_risk_counts'])) {
            $riskCounts['critical'] = $vendorDetails['overall_risk_counts']['critical'] ?? 0;
            $riskCounts['high'] = $vendorDetails['overall_risk_counts']['high'] ?? 0;
            $riskCounts['medium'] = $vendorDetails['overall_risk_counts']['medium'] ?? 0;
            $riskCounts['low'] = $vendorDetails['overall_risk_counts']['low'] ?? 0;
            // Note: UpGuard doesn't include 'info' in overall_risk_counts. Typical.
        }

        // Step 6: Build detailed risk array from the /risks/vendors endpoint
        $riskDetails = [];
        if ($risks && isset($risks['risks'])) {
            // Debug logging for the first risk to see what fields UpGuard gives us
            // (their API docs and actual responses don't always agree, shocking I know)
            if (!empty($risks['risks'][0])) {
                error_log('UpGuard risk fields: ' . implode(', ', array_keys($risks['risks'][0])));
            }

            foreach ($risks['risks'] as $risk) {
                $severity = strtolower($risk['severity'] ?? 'info');

                // Count info risks here since overall_risk_counts doesn't include them
                if ($severity === 'info') {
                    $riskCounts['info']++;
                }

                // UpGuard's field names are a fun guessing game. They've changed over
                // API versions, so we check multiple possible field names for each value.
                // firstDetected, firstSeenAt, first_seen_at -- pick a convention, UpGuard!
                $firstSeenRaw = $risk['firstDetected'] ?? $risk['firstSeenAt'] ?? $risk['first_seen_at'] ?? null;
                $firstSeen = null;
                if ($firstSeenRaw) {
                    try {
                        // Convert whatever ISO 8601 format they gave us into MySQL datetime
                        $dt = new DateTime($firstSeenRaw);
                        $firstSeen = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $firstSeen = null; // If the date is garbage, just null it out
                    }
                }

                // Same deal with the risk name -- could be 'finding', 'risk', or 'name'
                $riskName = $risk['finding'] ?? $risk['risk'] ?? $risk['name'] ?? '';

                // Extract hostname(s) where the risk was detected
                $riskHost = '';
                if (!empty($risk['hostnames']) && is_array($risk['hostnames'])) {
                    $riskHost = implode(', ', $risk['hostnames']);
                }

                $riskDetails[] = [
                    'risk_id' => $risk['id'] ?? '',
                    'risk_name' => $riskName,
                    'risk_category' => $risk['category'] ?? '',
                    'severity' => $severity,
                    'risk_host' => $riskHost,
                    'description' => $risk['description'] ?? '',
                    'first_seen' => $firstSeen
                ];
            }
        }

        // Step 7: Extract the score -- UpGuard has multiple score fields because why not
        // automatedScore = score without questionnaire (what we usually want)
        // overallScore = score including questionnaire (if vendor completed one)
        // score = deprecated but still around in some responses
        $score = $vendorDetails['automatedScore'] ?? $vendorDetails['overallScore'] ?? $vendorDetails['score'] ?? 0;
        $grade = self::scoreToGrade($score);

        // Extract category-level scores if UpGuard provided them (email security, DNS, etc.)
        $categoryScores = [];
        if (isset($vendorDetails['categoryScores'])) {
            $categoryScores = $vendorDetails['categoryScores'];
        }

        // Step 8: Fetch vendor subdomain scores if enabled
        // This must happen BEFORE unmonitoring (Step 9) since the API requires an active vendor
        $vendorDomains = [];
        if ($fetchDomains) {
            $domainsResult = $this->getVendorDomains($upguardHostname);
            if ($domainsResult !== null) {
                // Debug: log response structure on first call
                if (!empty($domainsResult)) {
                    error_log('UpGuard /vendor/domains response keys for ' . $hostname . ': ' . implode(', ', array_keys($domainsResult)));
                }
                // UpGuard returns: { "domains": [...], "next_page_token": "...", "total_results": N }
                $domainList = $domainsResult['domains'] ?? $domainsResult['results'] ?? null;
                if ($domainList === null && isset($domainsResult[0])) {
                    $domainList = $domainsResult; // Flat array fallback
                }
                if (is_array($domainList)) {
                    // Debug: log first domain's keys so we know the field names
                    if (!empty($domainList[0])) {
                        error_log('UpGuard domain object keys: ' . implode(', ', array_keys($domainList[0])));
                    }
                    foreach ($domainList as $d) {
                        // UpGuard field names: hostname, automated_score, active, scanned_at, primary_domain, labels
                        $vendorDomains[] = [
                            'hostname' => $d['hostname'] ?? $d['domain'] ?? $d['name'] ?? '',
                            'score' => $d['automated_score'] ?? $d['score'] ?? null,
                            'active' => isset($d['active']) ? (bool)$d['active'] : true,
                            'scanned_at' => $d['scanned_at'] ?? $d['scannedAt'] ?? $d['lastScannedAt'] ?? $d['last_scanned_at'] ?? null,
                        ];
                    }
                    error_log('UpGuard vendor domains for ' . $hostname . ': ' . count($vendorDomains) . ' domains found');
                }
            } else {
                // Non-fatal: domains may not be available for newly/temporarily monitored vendors
                error_log('UpGuard vendor domains unavailable for ' . $hostname . ' (was_monitored=' . ($wasAlreadyMonitored ? 'yes' : 'no') . '): ' . $this->lastError);
            }
        }

        // Step 9: Clean up -- if we temporarily added monitoring, remove it now
        // We don't want to leave vendors monitored when we were just doing a one-off score
        // IMPORTANT: Only unmonitor if WE added the monitoring in step 2
        if (!$wasAlreadyMonitored) {
            $this->unwatchVendor($upguardHostname);
        }

        // Package everything up and ship it back
        return [
            'score' => $score,
            'grade' => $grade,
            'was_already_monitored' => $wasAlreadyMonitored,
            'critical_risks' => $riskCounts['critical'],
            'high_risks' => $riskCounts['high'],
            'medium_risks' => $riskCounts['medium'],
            'low_risks' => $riskCounts['low'],
            'info_risks' => $riskCounts['info'],
            'risk_details' => $riskDetails,
            'category_scores' => $categoryScores,
            'vendor_name' => $vendorDetails['primary_hostname'] ?? $vendorDetails['name'] ?? $hostname,
            'vendor_domains' => $vendorDomains
        ];
    }

    /**
     * Simple connection test -- tries to list vendors with a tiny page size.
     * Used by the admin "Test Connection" button to verify the API key is valid.
     * UpGuard requires page_size >= 10 even for test calls, because they have standards.
     */
    public function testConnection(): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'UpGuard integration is disabled'];
        }

        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'API key is not configured'];
        }

        // Try a lightweight API call to verify auth works
        $result = $this->request('GET', '/vendors', ['page_size' => 10]);

        if ($result === null) {
            return ['success' => false, 'message' => $this->lastError ?? 'API request failed'];
        }

        return ['success' => true, 'message' => 'Connection successful'];
    }
}
