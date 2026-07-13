<?php
/**
 * Shodan Service - Business Logic Layer for Shodan SRS Integration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The brains behind the Shodan scoring operation. Wraps ShodanClient with
 * database persistence, grade calculations, and history tracking. Same
 * pattern as SRSService (which wraps UpGuard) but tailored for Shodan's
 * 0-100 scoring scale and port/CVE-based findings.
 *
 * Shodan's score is deduction-based: start at 100, lose points for open
 * high-risk ports, known CVEs, etc. UpGuard's scale is 0-950 additive.
 * Different tools, different philosophies, but both tell you if a vendor
 * is leaving the digital front door wide open.
 */

require_once __DIR__ . '/ShodanClient.php';

class ShodanService
{
    private Database $db;
    private ShodanClient $shodan;
    private ?array $scoringConfig = null;

    // Default scoring thresholds for Shodan's 0-100 scale.
    // More lenient than UpGuard's because Shodan's deduction-based scoring
    // means even decent vendors rarely hit 100 if they have any web presence.
    private const DEFAULT_SCORING = [
        'method' => 'range',
        'display_name' => 'Shodan',
        'max_score' => 100,
        'grade_a_min' => 90,
        'grade_b_min' => 75,
        'grade_c_min' => 60,
        'grade_d_min' => 40
    ];

    /**
     * Technology name normalization map.
     * Maps variant names (lowercase) → canonical display name.
     * Shodan detects the same product under different labels depending on
     * banner strings, HTTP headers, and probe type. This map collapses
     * those variants so the concentration grid doesn't show duplicates.
     */
    private const TECH_NAME_MAP = [
        // Apache
        'apache http server'                    => 'Apache',
        'apache httpd'                          => 'Apache',
        // Amazon S3
        'amazons3'                              => 'Amazon S3',
        'aws s3'                                => 'Amazon S3',
        // Amazon ELB
        'aws elb'                               => 'Amazon ELB',
        'awselb'                                => 'Amazon ELB',
        // Amazon CloudFront
        'cloudfront'                            => 'Amazon CloudFront',
        'cloudfront httpd'                      => 'Amazon CloudFront',
        // AWS
        'amazon web services'                   => 'AWS',
        // Akamai
        'akamaighost'                           => 'Akamai',
        // Microsoft IIS
        'iis'                                   => 'Microsoft IIS',
        'microsoft iis httpd'                   => 'Microsoft IIS',
        'microsoft-iis'                         => 'Microsoft IIS',
        // Microsoft HTTPAPI
        'microsoft httpapi httpd'               => 'Microsoft HTTPAPI',
        'microsoft-httpapi'                     => 'Microsoft HTTPAPI',
        // Microsoft Azure Application Gateway
        'microsoft-azure-application-gateway'   => 'Microsoft Azure Application Gateway',
        // F5 BIG-IP
        'f5 bigip'                              => 'F5 BIG-IP',
        'bigip'                                 => 'F5 BIG-IP',
        // Google Cloud
        'gcp'                                   => 'Google Cloud',
        // Envoy
        'istio-envoy'                           => 'Envoy',
        'envoy'                                 => 'Envoy',
        // Visa
        'visa'                                  => 'Visa',
        'visa inc.'                             => 'Visa',
        // Cloudflare (case normalization)
        'cloudflare'                            => 'Cloudflare',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->shodan = new ShodanClient();
    }

    /**
     * Technology names to exclude from the concentration grid.
     * These are protocol flags, version strings, or generic detections
     * that don't represent meaningful technologies for supply chain analysis.
     */
    private const TECH_EXCLUDE = [
        'hsts',
        'http/3',
        'http/2',
        'webserver',
        'localhost',
    ];

    /**
     * SQL WHERE clause fragment to exclude non-technology entries from queries.
     */
    private function techExcludeSQL(string $column = 'vt.technology_name'): string
    {
        $quoted = array_map(function ($t) {
            return "'" . addslashes($t) . "'";
        }, self::TECH_EXCLUDE);
        return "LOWER({$column}) NOT IN (" . implode(',', $quoted) . ")";
    }

    /**
     * Normalize a technology name to its canonical form.
     * Returns the canonical name, or null to exclude from the concentration grid.
     * Filters out bare version strings (e.g. "8.9p1", "10.0") and protocol flags.
     */
    public function normalizeTechName(string $name): ?string
    {
        $lower = strtolower(trim($name));

        // Skip excluded entries (protocol flags, etc.)
        if (in_array($lower, self::TECH_EXCLUDE, true)) {
            return null;
        }

        // Skip bare version strings — orphaned version detections
        if (preg_match('/^\d+[\.\-]/', $lower)) {
            // Allow MariaDB version strings to map to MariaDB
            if (stripos($lower, 'mariadb') !== false) {
                return 'MariaDB';
            }
            return null;
        }

        return self::TECH_NAME_MAP[$lower] ?? $name;
    }

    /**
     * Get all raw technology names that map to a given canonical name.
     * Used to expand a search to all aliases when drilling down.
     */
    public function getTechAliases(string $canonicalName): array
    {
        $aliases = [$canonicalName];
        foreach (self::TECH_NAME_MAP as $variant => $canonical) {
            if ($canonical === $canonicalName) {
                $aliases[] = $variant;
            }
        }
        return array_unique($aliases);
    }

    /**
     * Loads Shodan scoring config from app_config.
     * Caches per-request so we don't keep bugging the database.
     */
    private function loadScoringConfig(): array
    {
        if ($this->scoringConfig !== null) {
            return $this->scoringConfig;
        }

        $this->scoringConfig = self::DEFAULT_SCORING;

        try {
            $configs = $this->db->fetchAll(
                "SELECT config_key, config_value FROM app_config WHERE config_key LIKE 'shodan_%'"
            );

            foreach ($configs as $row) {
                $key = str_replace('shodan_', '', $row['config_key']);
                switch ($key) {
                    case 'scoring_method':
                        $this->scoringConfig['method'] = $row['config_value'] ?: 'range';
                        break;
                    case 'display_name':
                        $this->scoringConfig['display_name'] = !empty($row['config_value']) ? $row['config_value'] : 'Shodan';
                        break;
                    case 'max_score':
                        $this->scoringConfig['max_score'] = (int)($row['config_value'] ?: 100);
                        break;
                    case 'grade_a_min':
                        $this->scoringConfig['grade_a_min'] = (int)($row['config_value'] ?: 90);
                        break;
                    case 'grade_b_min':
                        $this->scoringConfig['grade_b_min'] = (int)($row['config_value'] ?: 75);
                        break;
                    case 'grade_c_min':
                        $this->scoringConfig['grade_c_min'] = (int)($row['config_value'] ?: 60);
                        break;
                    case 'grade_d_min':
                        $this->scoringConfig['grade_d_min'] = (int)($row['config_value'] ?: 40);
                        break;
                }
            }
        } catch (Exception $e) {
            error_log('ShodanService: Failed to load scoring config: ' . $e->getMessage());
        }

        return $this->scoringConfig;
    }

    /**
     * Public getter for the scoring config. Used by admin settings pages.
     */
    public function getScoringConfig(): array
    {
        return $this->loadScoringConfig();
    }

    /**
     * Checks if Shodan is configured and ready to score.
     */
    public function isAvailable(): bool
    {
        return $this->shodan->isConfigured();
    }

    /**
     * Passes through the last error from the Shodan client.
     */
    public function getLastError(): ?string
    {
        return $this->shodan->getLastError();
    }

    /**
     * Request an on-demand rescan of a specific IP address.
     * Proxies to the ShodanClient rescan method.
     *
     * @param string $ip IP address to rescan
     * @return array ['success' => bool, 'message' => string, 'scan_id' => string|null]
     */
    public function requestRescan(string $ip): array
    {
        return $this->shodan->requestRescan($ip);
    }

    /**
     * Request Shodan to rescan multiple IPs in a single API call.
     *
     * @param array $ips Array of IP address strings
     * @return array ['success' => bool, 'message' => string, 'scan_id' => string|null]
     */
    public function requestRescanBatch(array $ips): array
    {
        return $this->shodan->requestRescanBatch($ips);
    }

    /**
     * Override on-demand scan setting for this request (ad-hoc usage).
     */
    public function setOnDemandScan(bool $enabled): void
    {
        $this->shodan->setOnDemandScan($enabled);
    }

    /**
     * Returns the current category weights (integer percentages).
     */
    public function getCategoryWeights(): array
    {
        return $this->shodan->getCategoryWeights();
    }

    /**
     * Tests the Shodan API connection.
     */
    public function testConnection(): array
    {
        return $this->shodan->testConnection();
    }

    /**
     * The main scoring method -- scores a vendor and stores everything.
     *
     * 1. Ask Shodan to score the domain (resolve, scan IPs, calculate)
     * 2. Apply waivers (filter out waived findings, recompute scores)
     * 3. Calculate grade using our configurable thresholds
     * 4. Store score + findings in the database
     * 5. Update the vendor's current_shodan_score
     *
     * Returns the score data array or null if scoring failed.
     */
    public function scoreVendor(int $vendorOnboardingId, string $domain, string $sourceTable = 'vendor_onboarding_requests'): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Score the primary domain independently
        $scoreData = $this->scoreSingleDomain($vendorOnboardingId, $domain);
        if (!$scoreData) {
            return null;
        }

        // Score each sister domain independently, one by one
        // (shadow_saas entries don't have sister domains)
        $sisterDomains = [];
        if ($sourceTable === 'vendor_onboarding_requests') {
            try {
                $vendor = $this->db->fetchOne(
                    'SELECT vendor_sisterdomains FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorOnboardingId]
                );
                if (!empty($vendor['vendor_sisterdomains'])) {
                    $sisterDomains = array_filter(
                        array_map('trim', explode("\n", $vendor['vendor_sisterdomains'])),
                        fn($d) => !empty($d)
                    );
                }
            } catch (Exception $e) {
                // Column may not exist yet on older deployments -- ignore
            }
        }

        $allScores = [$scoreData];
        foreach ($sisterDomains as $sister) {
            $sister = strtolower(trim($sister));
            if (empty($sister) || $sister === strtolower($domain)) {
                continue;
            }
            $sisterScore = $this->scoreSingleDomain($vendorOnboardingId, $sister);
            if ($sisterScore) {
                $allScores[] = $sisterScore;
            }
        }

        // Aggregate scores from all domains into a combined vendor score
        if (count($allScores) > 1) {
            // Merge subdomains scanned
            $allSubdomains = [];
            foreach ($allScores as $s) {
                foreach ($s['subdomains_scanned'] ?? [] as $sub) {
                    if (!in_array($sub, $allSubdomains)) {
                        $allSubdomains[] = $sub;
                    }
                }
            }
            // Add sister domain roots to subdomains list
            foreach ($sisterDomains as $sd) {
                $sd = strtolower(trim($sd));
                if (!empty($sd) && !in_array($sd, $allSubdomains)) {
                    $allSubdomains[] = $sd;
                }
            }

            // Average score across all domains
            $totalScore = array_sum(array_column($allScores, 'score'));
            $avgScore = (int)round($totalScore / count($allScores));

            // Merge all findings, ports, vulns, services, IPs
            $mergedFindings = [];
            $mergedPorts = [];
            $mergedVulns = [];
            $mergedServices = [];
            $mergedIps = [];
            $totalOpenPorts = 0;
            $totalVulns = 0;
            $totalCritical = 0;
            $totalHigh = 0;
            $totalMedium = 0;
            $totalLow = 0;
            $totalPositive = 0;
            $totalNegative = 0;

            // Average category scores
            $categoryTotals = [];
            $categoryCount = 0;

            foreach ($allScores as $s) {
                $mergedFindings = array_merge($mergedFindings, $s['findings'] ?? []);
                $mergedPorts = array_merge($mergedPorts, $s['open_ports'] ?? []);
                $mergedVulns = array_merge($mergedVulns, $s['vulns'] ?? []);
                $mergedServices = array_merge($mergedServices, $s['services'] ?? []);
                foreach ($s['ip_addresses'] ?? [] as $ip) {
                    if (!in_array($ip, $mergedIps)) {
                        $mergedIps[] = $ip;
                    }
                }
                $totalOpenPorts += $s['open_ports_count'] ?? 0;
                $totalVulns += $s['vuln_count'] ?? 0;
                $totalCritical += $s['critical_vulns'] ?? 0;
                $totalHigh += $s['high_vulns'] ?? 0;
                $totalMedium += $s['medium_vulns'] ?? 0;
                $totalLow += $s['low_vulns'] ?? 0;
                $totalPositive += $s['positive_count'] ?? 0;
                $totalNegative += $s['negative_count'] ?? 0;
                if (!empty($s['category_scores'])) {
                    $categoryCount++;
                    foreach ($s['category_scores'] as $cat => $catScore) {
                        $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0) + $catScore;
                    }
                }
            }

            // Dedupe findings merged from primary + sister domain scoring passes.
            // Each scoring pass produces its own copy of aggregate signals like
            // "HSTS Enabled" or "WAF Detected" anchored at whichever subdomain it
            // happened to see first — so a plain array_merge kept both rows with
            // different anchors, inflating counts and doubling scoring points.
            //
            // Collapse by (category, signal). The anchor subdomain is cosmetic
            // because the richer "Observed Subdomains" list in proof already
            // carries the full set of detections. When duplicates collide, keep
            // the row with the richest proof AND merge the Observed Subdomains
            // lists so no detection info is lost across passes.
            if (!empty($mergedFindings)) {
                $dedup = [];
                foreach ($mergedFindings as $f) {
                    $key = ($f['category'] ?? '') . '|' . ($f['signal'] ?? '');
                    if (!isset($dedup[$key])) {
                        $dedup[$key] = $f;
                        continue;
                    }
                    // Collide: pick the row with more proof keys as the base,
                    // then union the Observed Subdomains lists from both rows.
                    $existing = $dedup[$key];
                    $existingProofCount = is_array($existing['proof'] ?? null) ? count($existing['proof']) : 0;
                    $incomingProofCount = is_array($f['proof'] ?? null) ? count($f['proof']) : 0;
                    $base  = ($incomingProofCount > $existingProofCount) ? $f : $existing;
                    $other = ($incomingProofCount > $existingProofCount) ? $existing : $f;

                    $baseProof  = is_array($base['proof'] ?? null) ? $base['proof'] : [];
                    $otherProof = is_array($other['proof'] ?? null) ? $other['proof'] : [];

                    $parseSubs = function($v) {
                        if (empty($v)) return [];
                        if (is_array($v)) return array_map('trim', $v);
                        return array_values(array_filter(array_map('trim', explode(',', (string)$v))));
                    };
                    $unionSubs = array_values(array_unique(array_merge(
                        $parseSubs($baseProof['Observed Subdomains'] ?? null),
                        $parseSubs($otherProof['Observed Subdomains'] ?? null),
                        // Include the anchor subdomains as well so nothing is dropped
                        !empty($base['subdomain']) ? [$base['subdomain']] : [],
                        !empty($other['subdomain']) ? [$other['subdomain']] : []
                    )));
                    if (!empty($unionSubs)) {
                        $baseProof['Observed Subdomains'] = implode(', ', $unionSubs);
                    }
                    $base['proof'] = $baseProof;
                    $dedup[$key] = $base;
                }
                $mergedFindings = array_values($dedup);

                // Recount positive/negative after dedupe so UI counts match reality
                $totalPositive = 0;
                $totalNegative = 0;
                foreach ($mergedFindings as $f) {
                    $type = $f['type'] ?? '';
                    if ($type === 'positive') $totalPositive++;
                    elseif ($type === 'negative') $totalNegative++;
                }
            }

            $avgCategories = [];
            if ($categoryCount > 0) {
                foreach ($categoryTotals as $cat => $total) {
                    $avgCategories[$cat] = (int)round($total / $categoryCount);
                }
            }

            // Determine traffic light from averaged score
            $trafficLight = 'red';
            if ($avgScore >= 75) $trafficLight = 'green';
            elseif ($avgScore >= 50) $trafficLight = 'yellow';

            // Build the aggregated scoreData
            $scoreData = [
                'score' => $avgScore,
                'grade' => $this->calculateGrade($avgScore),
                'traffic_light' => $trafficLight,
                'category_scores' => $avgCategories,
                'positive_count' => $totalPositive,
                'negative_count' => $totalNegative,
                'subdomains_scanned' => $allSubdomains,
                'findings' => $mergedFindings,
                'open_ports' => $mergedPorts,
                'vulns' => $mergedVulns,
                'services' => $mergedServices,
                'ip_addresses' => $mergedIps,
                'open_ports_count' => $totalOpenPorts,
                'vuln_count' => $totalVulns,
                'critical_vulns' => $totalCritical,
                'high_vulns' => $totalHigh,
                'medium_vulns' => $totalMedium,
                'low_vulns' => $totalLow,
            ];

        }

        // Only store history for vendor_onboarding_requests (shadow_saas
        // entries don't have corresponding FK rows in history tables)
        if ($sourceTable === 'vendor_onboarding_requests') {
            $this->storeScore($vendorOnboardingId, $domain, $scoreData);
        }

        // Update the vendor's current score with the aggregated result
        $this->updateCurrentScore($vendorOnboardingId, $scoreData['score'], $sourceTable);

        return $scoreData;
    }

    /**
     * Score a single domain and return the results (without storing).
     * Each domain is scored independently to avoid cross-contamination
     * from shared IPs, CDN infrastructure, and finding misattribution.
     * Storage is handled by scoreVendor() after aggregation.
     */
    private function scoreSingleDomain(int $vendorOnboardingId, string $domain): ?array
    {
        $scoreData = $this->shodan->scoreVendor($domain);

        if (!$scoreData) {
            return null;
        }

        // Apply per-subdomain signal waivers before storing
        if ($this->hasWaiversTable()) {
            $scoreData = $this->applyWaivers($vendorOnboardingId, $scoreData);
        }

        // Apply vendor-wide CVE waivers (remove false positive CVEs from scoring)
        if ($this->hasCveWaiversTable()) {
            $scoreData = $this->applyCveWaivers($vendorOnboardingId, $scoreData);
        }

        // Calculate grade using our configurable thresholds
        $scoreData['grade'] = $this->calculateGrade((int)$scoreData['score']);

        return $scoreData;
    }

    // =========================================================================
    // Risk Waiver Management (per-vendor, per-subdomain)
    // =========================================================================

    /**
     * Checks if the vendor_shodan_waivers table exists (migrate_08).
     */
    private ?bool $waiversTableCache = null;
    private function hasWaiversTable(): bool
    {
        if ($this->waiversTableCache !== null) {
            return $this->waiversTableCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_waivers'"
            );
            $this->waiversTableCache = !empty($result);
        } catch (Exception $e) {
            $this->waiversTableCache = false;
        }

        return $this->waiversTableCache;
    }

    /**
     * Get all active waivers for a vendor.
     */
    public function getWaiversForVendor(int $vendorOnboardingId): array
    {
        if (!$this->hasWaiversTable()) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT * FROM vendor_shodan_waivers
             WHERE vendor_onboarding_id = :vid
             ORDER BY category, subdomain, signal_name',
            [':vid' => $vendorOnboardingId]
        );
    }

    /**
     * Get all waivers across all vendors (for admin management page).
     */
    public function getAllWaivers(): array
    {
        if (!$this->hasWaiversTable()) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT w.*, v.vendor_name, v.vendor_domain
             FROM vendor_shodan_waivers w
             JOIN vendor_onboarding_requests v ON v.id = w.vendor_onboarding_id
             ORDER BY v.vendor_name, w.category, w.subdomain, w.signal_name'
        );
    }

    /**
     * Add a waiver for a specific signal + subdomain on a vendor.
     * Returns the waiver ID on success, false on failure.
     */
    public function addWaiver(int $vendorOnboardingId, string $signalName, string $category, string $subdomain, string $label, string $reason, string $waivedBy): int|false
    {
        if (!$this->hasWaiversTable()) {
            return false;
        }

        try {
            // Check if waiver already exists (UNIQUE constraint would catch it, but let's be explicit)
            $existing = $this->db->fetchOne(
                'SELECT id FROM vendor_shodan_waivers
                 WHERE vendor_onboarding_id = :vid AND signal_name = :sig AND subdomain = :sub',
                [':vid' => $vendorOnboardingId, ':sig' => $signalName, ':sub' => $subdomain]
            );

            if ($existing) {
                return (int)$existing['id'];
            }

            return $this->db->insert('vendor_shodan_waivers', [
                'vendor_onboarding_id' => $vendorOnboardingId,
                'signal_name' => $signalName,
                'category' => $category,
                'subdomain' => $subdomain,
                'label' => $label,
                'reason' => $reason,
                'waived_by' => $waivedBy,
            ]);
        } catch (Exception $e) {
            error_log('Failed to add Shodan waiver: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a waiver by its ID.
     */
    public function removeWaiver(int $waiverId): bool
    {
        if (!$this->hasWaiversTable()) {
            return false;
        }

        try {
            $this->db->delete('vendor_shodan_waivers', 'id = :id', [':id' => $waiverId]);
            return true;
        } catch (Exception $e) {
            error_log('Failed to remove Shodan waiver: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove all waivers for a specific vendor.
     * Returns the number of waivers removed, or false on failure.
     */
    public function removeAllWaiversForVendor(int $vendorOnboardingId): int|false
    {
        if (!$this->hasWaiversTable()) {
            return false;
        }

        try {
            $count = $this->db->fetchOne(
                'SELECT COUNT(*) as cnt FROM vendor_shodan_waivers WHERE vendor_onboarding_id = :vid',
                [':vid' => $vendorOnboardingId]
            );
            $this->db->delete('vendor_shodan_waivers', 'vendor_onboarding_id = :vid', [':vid' => $vendorOnboardingId]);
            return (int)($count['cnt'] ?? 0);
        } catch (Exception $e) {
            error_log('Failed to remove all Shodan waivers for vendor: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // CVE Waiver Management (vendor-wide CVE false positive suppression)
    // =========================================================================

    /**
     * Checks if the vendor_shodan_cve_waivers table exists.
     */
    private ?bool $cveWaiversTableCache = null;
    public function hasCveWaiversTable(): bool
    {
        if ($this->cveWaiversTableCache !== null) {
            return $this->cveWaiversTableCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_cve_waivers'"
            );
            $this->cveWaiversTableCache = !empty($result);
        } catch (Exception $e) {
            $this->cveWaiversTableCache = false;
        }

        return $this->cveWaiversTableCache;
    }

    /**
     * Bulk-add CVE waivers for a vendor.
     * Returns ['added' => N, 'skipped' => N] counts.
     */
    public function addCveWaivers(int $vendorOnboardingId, array $cveIds, string $reason, string $waivedBy): array
    {
        if (!$this->hasCveWaiversTable()) {
            return ['added' => 0, 'skipped' => 0];
        }

        $added = 0;
        $skipped = 0;

        foreach ($cveIds as $cveId) {
            $cveId = strtoupper(trim($cveId));
            if (empty($cveId) || !preg_match('/^CVE-\d{4}-\d+$/', $cveId)) {
                continue;
            }

            try {
                $existing = $this->db->fetchOne(
                    'SELECT id FROM vendor_shodan_cve_waivers WHERE vendor_onboarding_id = :vid AND cve_id = :cve',
                    [':vid' => $vendorOnboardingId, ':cve' => $cveId]
                );

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $this->db->insert('vendor_shodan_cve_waivers', [
                    'vendor_onboarding_id' => $vendorOnboardingId,
                    'cve_id' => $cveId,
                    'reason' => $reason ?: null,
                    'waived_by' => $waivedBy,
                ]);
                $added++;
            } catch (Exception $e) {
                error_log('Failed to add CVE waiver ' . $cveId . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        // Delete waived CVE findings from the database
        if ($added > 0) {
            $this->deleteWaivedCveFindings($vendorOnboardingId);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Delete CVE findings from vendor_shodan_findings that match active CVE waivers.
     * Removes them from the latest score's findings so they don't appear in search.
     */
    private function deleteWaivedCveFindings(int $vendorOnboardingId): void
    {
        try {
            $waivedCves = $this->getCveWaiversForVendor($vendorOnboardingId);
            if (empty($waivedCves)) return;

            $latestScore = $this->getLatestScore($vendorOnboardingId);
            if (!$latestScore) return;

            $scoreId = (int)$latestScore['id'];

            foreach ($waivedCves as $w) {
                // Delete vulnerability findings by cve_id
                $this->db->query(
                    'DELETE FROM vendor_shodan_findings
                     WHERE shodan_score_id = :sid AND cve_id = :cve',
                    [':sid' => $scoreId, ':cve' => $w['cve_id']]
                );
                // Delete category signal findings where service_name matches the CVE
                $this->db->query(
                    'DELETE FROM vendor_shodan_findings
                     WHERE shodan_score_id = :sid AND service_name = :cve AND category = :cat',
                    [':sid' => $scoreId, ':cve' => $w['cve_id'], ':cat' => 'vuln_exposure']
                );
            }
        } catch (Exception $e) {
            error_log('ShodanService: Failed to delete waived CVE findings: ' . $e->getMessage());
        }
    }

    /**
     * Remove a CVE waiver by its ID.
     */
    public function removeCveWaiver(int $waiverId): bool
    {
        if (!$this->hasCveWaiversTable()) {
            return false;
        }

        try {
            $this->db->delete('vendor_shodan_cve_waivers', 'id = :id', [':id' => $waiverId]);
            return true;
        } catch (Exception $e) {
            error_log('Failed to remove CVE waiver: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all CVE waivers for a vendor.
     */
    public function getCveWaiversForVendor(int $vendorOnboardingId): array
    {
        if (!$this->hasCveWaiversTable()) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT * FROM vendor_shodan_cve_waivers
             WHERE vendor_onboarding_id = :vid
             ORDER BY cve_id',
            [':vid' => $vendorOnboardingId]
        );
    }

    /**
     * Get all distinct CVE IDs for a vendor matching a prefix (e.g. "CVE-2007").
     * Searches the latest Shodan findings for this vendor.
     */
    public function getCveIdsForVendorByPrefix(int $vendorOnboardingId, string $prefix): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT vsf.cve_id
                 FROM vendor_shodan_findings vsf
                 INNER JOIN vendor_shodan_scores vss ON vss.id = vsf.shodan_score_id
                 INNER JOIN (
                     SELECT vendor_onboarding_id, MAX(id) AS latest_score_id
                     FROM vendor_shodan_scores
                     GROUP BY vendor_onboarding_id
                 ) latest ON latest.latest_score_id = vss.id
                 WHERE vss.vendor_onboarding_id = :vid
                   AND vsf.cve_id IS NOT NULL
                   AND vsf.cve_id LIKE :prefix
                 ORDER BY vsf.cve_id',
                [':vid' => $vendorOnboardingId, ':prefix' => $prefix . '%']
            );
            return array_column($rows, 'cve_id');
        } catch (Exception $e) {
            error_log('ShodanService: getCveIdsForVendorByPrefix error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Apply CVE waivers to score data during scoring.
     * Removes waived CVEs from the vulns array and recomputes vuln_exposure
     * category score, weighted final score, and traffic light.
     */
    public function applyCveWaivers(int $vendorOnboardingId, array $scoreData): array
    {
        if (!$this->hasCveWaiversTable()) {
            return $scoreData;
        }

        $waivers = $this->getCveWaiversForVendor($vendorOnboardingId);
        if (empty($waivers)) {
            return $scoreData;
        }

        $waivedCves = array_column($waivers, 'cve_id');
        $waivedLookup = array_flip($waivedCves);

        // Filter waived CVEs from vulns array
        $changed = false;
        if (!empty($scoreData['vulns'])) {
            $filtered = [];
            foreach ($scoreData['vulns'] as $vuln) {
                $cve = $vuln['cve_id'] ?? '';
                if (!empty($cve) && isset($waivedLookup[$cve])) {
                    $changed = true;
                    continue; // Skip waived CVEs
                }
                $filtered[] = $vuln;
            }
            $scoreData['vulns'] = $filtered;
        }

        // Also mark waived findings in the findings array (category signals)
        if (!empty($scoreData['findings'])) {
            foreach ($scoreData['findings'] as &$finding) {
                $signal = $finding['signal'] ?? '';
                if (!empty($signal) && isset($waivedLookup[$signal])) {
                    $finding['waived'] = true;
                    $changed = true;
                }
            }
            unset($finding);
        }

        if (!$changed) {
            return $scoreData;
        }

        // Recount vulns
        $criticalVulns = 0;
        $highVulns = 0;
        $mediumVulns = 0;
        $lowVulns = 0;
        foreach ($scoreData['vulns'] as $v) {
            $sev = strtolower($v['severity'] ?? 'unknown');
            if ($sev === 'critical') $criticalVulns++;
            elseif ($sev === 'high') $highVulns++;
            elseif ($sev === 'medium') $mediumVulns++;
            elseif ($sev === 'low') $lowVulns++;
        }
        $scoreData['vuln_count'] = count($scoreData['vulns']);
        $scoreData['critical_vulns'] = $criticalVulns;
        $scoreData['high_vulns'] = $highVulns;
        $scoreData['medium_vulns'] = $mediumVulns;
        $scoreData['low_vulns'] = $lowVulns;

        // Recompute category scores from non-waived findings
        $categoryPoints = [];
        $categoriesWithWaivedFindings = [];
        $positiveCount = 0;
        $negativeCount = 0;

        if (!empty($scoreData['findings'])) {
            foreach ($scoreData['findings'] as $f) {
                $cat = $f['category'] ?? '';
                if (!empty($f['waived'])) {
                    if (!empty($cat)) {
                        $categoriesWithWaivedFindings[$cat] = true;
                    }
                    continue;
                }
                if (!empty($cat)) {
                    $categoryPoints[$cat][] = (int)($f['points'] ?? 0);
                }
                if (($f['type'] ?? '') === 'positive') $positiveCount++;
                elseif (($f['type'] ?? '') === 'negative') $negativeCount++;
            }
        }

        $categoryScores = $scoreData['category_scores'] ?? [];
        foreach ($categoryPoints as $cat => $points) {
            $score = 50;
            foreach ($points as $p) $score += $p;
            $categoryScores[$cat] = max(0, min(100, $score));
        }

        // Handle categories where ALL findings were waived (no non-waived points collected)
        foreach ($categoriesWithWaivedFindings as $cat => $_) {
            if (!isset($categoryPoints[$cat])) {
                // All findings in this category are waived — score = baseline 50
                $score = 50;
                // For vuln_exposure with 0 remaining vulns, add the no_cves bonus
                if ($cat === 'vuln_exposure' && ($scoreData['vuln_count'] ?? 0) === 0) {
                    $score += $this->shodan->getSignalPoints('vuln_exposure', 'no_cves');
                }
                $categoryScores[$cat] = max(0, min(100, $score));
            }
        }

        $scoreData['category_scores'] = $categoryScores;
        $scoreData['positive_count'] = $positiveCount;
        $scoreData['negative_count'] = $negativeCount;
        $scoreData['score'] = $this->shodan->computeWeightedScore($categoryScores, $scoreData['findings']);
        $scoreData['traffic_light'] = $this->shodan->computeTrafficLight($categoryScores, $scoreData['findings']);

        return $scoreData;
    }

    /**
     * Get waivers for a vendor by vendor_onboarding_id, with vendor info included.
     * Used by the admin waiver search.
     */
    public function getWaiversForVendorWithInfo(int $vendorOnboardingId): array
    {
        if (!$this->hasWaiversTable()) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT w.*, v.vendor_name, v.vendor_domain
             FROM vendor_shodan_waivers w
             JOIN vendor_onboarding_requests v ON v.id = w.vendor_onboarding_id
             WHERE w.vendor_onboarding_id = :vid
             ORDER BY w.category, w.subdomain, w.signal_name',
            [':vid' => $vendorOnboardingId]
        );
    }

    /**
     * Check if a specific finding is waived for a vendor + subdomain.
     */
    public function isWaived(int $vendorOnboardingId, string $signalName, string $subdomain): bool
    {
        if (!$this->hasWaiversTable()) {
            return false;
        }

        $result = $this->db->fetchOne(
            'SELECT 1 AS ok FROM vendor_shodan_waivers
             WHERE vendor_onboarding_id = :vid AND signal_name = :sig AND subdomain = :sub',
            [':vid' => $vendorOnboardingId, ':sig' => $signalName, ':sub' => $subdomain]
        );

        return !empty($result);
    }

    /**
     * Apply waivers to score data: mark waived findings and recompute category scores.
     * Waived findings remain in the data (for display with "Waived" badge) but
     * their points are excluded from scoring.
     */
    private function applyWaivers(int $vendorOnboardingId, array $scoreData): array
    {
        $waivers = $this->getWaiversForVendor($vendorOnboardingId);
        if (empty($waivers)) {
            return $scoreData;
        }

        // Build lookup: category -> signal_name -> [subdomain1, subdomain2, ...]
        $waiverLookup = [];
        foreach ($waivers as $w) {
            $waiverLookup[$w['signal_name']][$w['subdomain']] = true;
        }

        $waived = false;
        $positiveCount = 0;
        $negativeCount = 0;

        // Mark waived findings and collect non-waived points per category
        $categoryPoints = []; // category => [points1, points2, ...]
        if (!empty($scoreData['findings'])) {
            foreach ($scoreData['findings'] as &$finding) {
                $signal = $finding['signal'] ?? '';
                $subdomain = $finding['subdomain'] ?? '';

                if (!empty($signal) && isset($waiverLookup[$signal][$subdomain])) {
                    $finding['waived'] = true;
                    $waived = true;
                } else {
                    $finding['waived'] = false;
                    $cat = $finding['category'] ?? '';
                    if (!empty($cat)) {
                        $categoryPoints[$cat][] = (int)($finding['points'] ?? 0);
                    }
                    // Count non-waived findings for positive/negative counts
                    if (($finding['type'] ?? '') === 'positive') {
                        $positiveCount++;
                    } elseif (($finding['type'] ?? '') === 'negative') {
                        $negativeCount++;
                    }
                }
            }
            unset($finding);
        }

        if (!$waived) {
            return $scoreData;
        }

        // Recompute category scores from non-waived findings (baseline 50, clamp 0-100)
        $categoryScores = $scoreData['category_scores'] ?? [];
        foreach ($categoryPoints as $cat => $points) {
            $score = 50;
            foreach ($points as $p) {
                $score += $p;
            }
            $categoryScores[$cat] = max(0, min(100, $score));
        }

        // For categories that had all findings waived, check if they had any findings at all
        $allCategories = ['tls_crypto', 'network_security', 'app_hardening', 'vuln_exposure', 'email_security'];
        foreach ($allCategories as $cat) {
            if (!isset($categoryPoints[$cat])) {
                // Check if this category had any findings (all waived)
                $hadFindings = false;
                foreach ($scoreData['findings'] as $f) {
                    if (($f['category'] ?? '') === $cat) {
                        $hadFindings = true;
                        break;
                    }
                }
                if ($hadFindings) {
                    // All findings in this category were waived -> baseline 50
                    $categoryScores[$cat] = 50;
                }
            }
        }

        $scoreData['category_scores'] = $categoryScores;
        $scoreData['positive_count'] = $positiveCount;
        $scoreData['negative_count'] = $negativeCount;

        // Recompute weighted final score (with clean posture bonus)
        $scoreData['score'] = $this->shodan->computeWeightedScore($categoryScores, $scoreData['findings']);

        // Recompute traffic light
        $scoreData['traffic_light'] = $this->shodan->computeTrafficLight($categoryScores, $scoreData['findings']);

        return $scoreData;
    }

    /**
     * Stores a score record and its individual findings in the database.
     * The score goes into vendor_shodan_scores, and each finding (port, CVE,
     * service, category signal) gets its own row in vendor_shodan_findings.
     */
    private function storeScore(int $vendorOnboardingId, string $domain, array $scoreData): void
    {
        $scoreRow = [
            'vendor_onboarding_id' => $vendorOnboardingId,
            'vendor_domain' => $domain,
            'score' => $scoreData['score'],
            'score_grade' => $scoreData['grade'],
            'open_ports_count' => $scoreData['open_ports_count'] ?? 0,
            'vuln_count' => $scoreData['vuln_count'] ?? 0,
            'critical_vulns' => $scoreData['critical_vulns'] ?? 0,
            'high_vulns' => $scoreData['high_vulns'] ?? 0,
            'medium_vulns' => $scoreData['medium_vulns'] ?? 0,
            'low_vulns' => $scoreData['low_vulns'] ?? 0,
            'ip_addresses' => !empty($scoreData['ip_addresses']) ? json_encode($scoreData['ip_addresses']) : null,
            'scored_at' => date('Y-m-d H:i:s'),
        ];

        // Enhanced columns (added by migrate_06) -- only include if DB columns exist
        $enhancedColumnsExist = $this->hasEnhancedColumns();
        if ($enhancedColumnsExist) {
            if (isset($scoreData['category_scores'])) {
                $scoreRow['category_scores'] = json_encode($scoreData['category_scores']);
            }
            if (isset($scoreData['traffic_light'])) {
                $scoreRow['traffic_light'] = $scoreData['traffic_light'];
            }
            if (isset($scoreData['positive_count'])) {
                $scoreRow['positive_count'] = $scoreData['positive_count'];
            }
            if (isset($scoreData['negative_count'])) {
                $scoreRow['negative_count'] = $scoreData['negative_count'];
            }
            if (isset($scoreData['subdomains_scanned'])) {
                $scoreRow['subdomains_scanned'] = json_encode($scoreData['subdomains_scanned']);
            }
        }

        $scoreId = $this->db->insert('vendor_shodan_scores', $scoreRow);

        if (!$scoreId) {
            return;
        }

        // Store enhanced findings (category-based signals from the scoring engine)
        if ($enhancedColumnsExist && !empty($scoreData['findings'])) {
            foreach ($scoreData['findings'] as $finding) {
                $findingType = $finding['category'] ?? 'positive_signal';
                // Map category to finding_type ENUM
                $validTypes = ['tls_crypto', 'network_security', 'app_hardening', 'email_security'];
                if (!in_array($findingType, $validTypes)) {
                    $findingType = ($finding['type'] ?? 'positive') === 'positive' ? 'positive_signal' : 'negative_signal';
                }

                $findingRow = [
                    'shodan_score_id' => $scoreId,
                    'finding_type'    => $findingType,
                    'ip_address'      => $finding['ip'] ?? null,
                    'port'            => $finding['port'] ?? null,
                    'service_name'    => $finding['signal'] ?? null,
                    'description'     => ($finding['label'] ?? '') . ': ' . ($finding['description'] ?? ''),
                    'severity'        => $finding['type'] === 'negative' ? 'negative' : 'positive',
                    'signal_type'     => $finding['type'] ?? null,
                    'category'        => $finding['category'] ?? null,
                    'points'          => $finding['points'] ?? null,
                    'confidence'      => $finding['confidence'] ?? null,
                    'subdomain'       => $finding['subdomain'] ?? null,
                ];
                if ($this->hasProofColumn() && !empty($finding['proof'])) {
                    $findingRow['proof'] = json_encode($finding['proof']);
                }
                $this->db->insert('vendor_shodan_findings', $findingRow);
            }
        }

        // Store open port findings
        if (!empty($scoreData['open_ports'])) {
            foreach ($scoreData['open_ports'] as $port) {
                $row = [
                    'shodan_score_id' => $scoreId,
                    'finding_type' => 'open_port',
                    'ip_address' => $port['ip'] ?? null,
                    'port' => $port['port'] ?? null,
                    'description' => ($port['is_high_risk'] ?? false) ? 'High-risk port' : 'Open port',
                ];
                if ($enhancedColumnsExist) {
                    $row['signal_type'] = ($port['is_high_risk'] ?? false) ? 'negative' : null;
                    $row['category'] = 'network_security';
                    $row['subdomain'] = $port['subdomain'] ?? null;
                }
                $this->db->insert('vendor_shodan_findings', $row);
            }
        }

        // Store vulnerability findings
        if (!empty($scoreData['vulns'])) {
            $hasVerifiedCol = $this->hasVerifiedColumn();
            foreach ($scoreData['vulns'] as $vuln) {
                // Shodan-confirmed vs. version-inferred. Inferred CVEs are surfaced
                // but excluded from scoring, so mark them low-confidence for the UI.
                $isVerified = !empty($vuln['verified']);
                $row = [
                    'shodan_score_id' => $scoreId,
                    'finding_type' => 'vulnerability',
                    'ip_address' => $vuln['ip'] ?? null,
                    'port' => $vuln['port'] ?? null,
                    'cve_id' => $vuln['cve_id'] ?? null,
                    'cvss_score' => $vuln['cvss'] ?? null,
                    'description' => $vuln['severity'] ?? 'Unknown severity',
                ];
                if ($enhancedColumnsExist) {
                    $row['severity'] = $vuln['severity'] ?? null;
                    $row['signal_type'] = 'negative';
                    $row['category'] = 'vuln_exposure';
                    $row['subdomain'] = $vuln['subdomain'] ?? null;
                    $row['confidence'] = $isVerified ? 'high' : 'low';
                }
                if ($hasVerifiedCol) {
                    $row['verified'] = $isVerified ? 1 : 0;
                }
                $this->db->insert('vendor_shodan_findings', $row);
            }
        }

        // Store service findings
        if (!empty($scoreData['services'])) {
            foreach ($scoreData['services'] as $service) {
                $row = [
                    'shodan_score_id' => $scoreId,
                    'finding_type' => 'service',
                    'ip_address' => $service['ip'] ?? null,
                    'port' => $service['port'] ?? null,
                    'protocol' => $service['protocol'] ?? null,
                    'service_name' => $service['service_name'] ?? null,
                    'description' => isset($service['version']) ? $service['service_name'] . ' ' . $service['version'] : null,
                ];
                if ($enhancedColumnsExist) {
                    $row['subdomain'] = $service['subdomain'] ?? null;
                }
                $this->db->insert('vendor_shodan_findings', $row);
            }
        }

        // Store technology fingerprints for 4th Party Risk
        if (!empty($scoreData['technologies'])) {
            $this->storeTechnologies($vendorOnboardingId, $domain, $scoreData['technologies']);
        }

        // Clean up old scores beyond retention limit (findings cascade-delete)
        $this->cleanupOldScores($vendorOnboardingId);
    }

    /**
     * Checks if the enhanced Shodan columns (migrate_06) exist in the database.
     * Caches the result per-request to avoid repeated INFORMATION_SCHEMA queries.
     */
    private ?bool $enhancedColumnsCache = null;
    private function hasEnhancedColumns(): bool
    {
        if ($this->enhancedColumnsCache !== null) {
            return $this->enhancedColumnsCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_scores' AND COLUMN_NAME = 'traffic_light'"
            );
            $this->enhancedColumnsCache = !empty($result);
        } catch (Exception $e) {
            $this->enhancedColumnsCache = false;
        }

        return $this->enhancedColumnsCache;
    }

    /**
     * Checks if the proof column (migrate_07) exists on vendor_shodan_findings.
     */
    private ?bool $proofColumnCache = null;
    private function hasProofColumn(): bool
    {
        if ($this->proofColumnCache !== null) {
            return $this->proofColumnCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_findings' AND COLUMN_NAME = 'proof'"
            );
            $this->proofColumnCache = !empty($result);
        } catch (Exception $e) {
            $this->proofColumnCache = false;
        }

        return $this->proofColumnCache;
    }

    private ?bool $verifiedColumnCache = null;
    private function hasVerifiedColumn(): bool
    {
        if ($this->verifiedColumnCache !== null) {
            return $this->verifiedColumnCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_findings' AND COLUMN_NAME = 'verified'"
            );
            $this->verifiedColumnCache = !empty($result);
        } catch (Exception $e) {
            $this->verifiedColumnCache = false;
        }

        return $this->verifiedColumnCache;
    }

    /**
     * Removes old score records (and their cascading findings) beyond the retention limit.
     * Keeps the most recent N scores per vendor to prevent unbounded database growth.
     * Findings are automatically deleted via ON DELETE CASCADE foreign key.
     */
    private const SCORE_RETENTION_LIMIT = 10;

    private function cleanupOldScores(int $vendorOnboardingId): void
    {
        try {
            // Find the ID cutoff: the Nth most recent score for this vendor
            $cutoff = $this->db->fetchOne(
                'SELECT id FROM vendor_shodan_scores
                 WHERE vendor_onboarding_id = :vid
                 ORDER BY scored_at DESC
                 LIMIT 1 OFFSET ' . self::SCORE_RETENTION_LIMIT,
                [':vid' => $vendorOnboardingId]
            );

            if (!empty($cutoff['id'])) {
                // Delete all scores older than the cutoff (findings cascade-delete)
                $this->db->delete(
                    'vendor_shodan_scores',
                    'vendor_onboarding_id = :vid AND id <= :cutoff_id',
                    [':vid' => $vendorOnboardingId, ':cutoff_id' => $cutoff['id']]
                );
            }
        } catch (Exception $e) {
            error_log('Shodan score cleanup error: ' . $e->getMessage());
        }
    }

    /**
     * Updates the vendor's current Shodan score on their main record.
     */
    private function updateCurrentScore(int $vendorOnboardingId, int $score, string $tableName = 'vendor_onboarding_requests'): void
    {
        $this->db->update(
            $tableName,
            [
                'current_shodan_score' => $score,
                'last_shodan_score_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            [':id' => $vendorOnboardingId]
        );
    }

    /**
     * Pull score history for a vendor, newest first.
     */
    public function getScoreHistory(int $vendorOnboardingId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vendor_shodan_scores
             WHERE vendor_onboarding_id = :id
             ORDER BY scored_at DESC, id DESC
             LIMIT ' . intval($limit),
            [':id' => $vendorOnboardingId]
        );
    }

    /**
     * Get the most recent Shodan score for a vendor.
     */
    public function getLatestScore(int $vendorOnboardingId): ?array
    {
        $result = $this->db->fetchOne(
            'SELECT * FROM vendor_shodan_scores
             WHERE vendor_onboarding_id = :id
             ORDER BY scored_at DESC, id DESC
             LIMIT 1',
            [':id' => $vendorOnboardingId]
        );

        return $result ?: null;
    }

    /**
     * Get all findings for a specific Shodan score record.
     * Sorted: category signals first (grouped by category), then vulns, ports, services.
     */
    public function getFindingsForScore(int $scoreId): array
    {
        // Use enhanced ordering if migrate_06 columns exist
        if ($this->hasEnhancedColumns()) {
            return $this->db->fetchAll(
                'SELECT * FROM vendor_shodan_findings
                 WHERE shodan_score_id = :id
                 ORDER BY
                    CASE finding_type
                        WHEN \'tls_crypto\' THEN 1
                        WHEN \'network_security\' THEN 2
                        WHEN \'app_hardening\' THEN 3
                        WHEN \'email_security\' THEN 4
                        WHEN \'positive_signal\' THEN 5
                        WHEN \'negative_signal\' THEN 6
                        WHEN \'vulnerability\' THEN 7
                        WHEN \'open_port\' THEN 8
                        WHEN \'service\' THEN 9
                        ELSE 10
                    END,
                    signal_type ASC,
                    cvss_score DESC,
                    port ASC',
                [':id' => $scoreId]
            );
        }

        // Pre-migration fallback ordering
        return $this->db->fetchAll(
            'SELECT * FROM vendor_shodan_findings
             WHERE shodan_score_id = :id
             ORDER BY
                CASE finding_type
                    WHEN \'vulnerability\' THEN 1
                    WHEN \'open_port\' THEN 2
                    WHEN \'service\' THEN 3
                END,
                cvss_score DESC,
                port ASC',
            [':id' => $scoreId]
        );
    }

    /**
     * Get score trend data for charting. Oldest first for proper chart rendering.
     */
    public function getScoreTrend(int $vendorOnboardingId, int $days = 90): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $scores = $this->db->fetchAll(
            'SELECT score, score_grade, scored_at
             FROM vendor_shodan_scores
             WHERE vendor_onboarding_id = :id
               AND scored_at >= :start_date
             ORDER BY scored_at ASC',
            [':id' => $vendorOnboardingId, ':start_date' => $startDate]
        );

        return array_map(function ($row) {
            return [
                'date' => date('Y-m-d', strtotime($row['scored_at'])),
                'score' => (int)$row['score'],
                'grade' => $row['score_grade']
            ];
        }, $scores);
    }

    /**
     * Recalculate the Shodan score from stored DB findings after a waiver change.
     * This avoids a full API rescan — it reads the existing findings from the
     * database, applies current waivers, recomputes category/overall scores,
     * and updates the score record in place.
     */
    public function recalculateScoreFromFindings(int $vendorOnboardingId): bool
    {
        $latestScore = $this->getLatestScore($vendorOnboardingId);
        if (!$latestScore) {
            return false;
        }

        $scoreId = (int)$latestScore['id'];
        $findings = $this->getFindingsForScore($scoreId);
        if (empty($findings)) {
            return false;
        }

        $waivers = $this->getWaiversForVendor($vendorOnboardingId);

        // Build signal waiver lookup: signal_name:subdomain => true
        $waiverLookup = [];
        foreach ($waivers as $w) {
            $waiverLookup[$w['signal_name'] . ':' . $w['subdomain']] = true;
        }

        // Build CVE waiver lookup
        $cveWaiverLookup = [];
        if ($this->hasCveWaiversTable()) {
            $cveWaivers = $this->getCveWaiversForVendor($vendorOnboardingId);
            foreach ($cveWaivers as $cw) {
                $cveWaiverLookup[$cw['cve_id']] = true;
            }
        }

        // Walk findings, collect non-waived points per category
        $categoryPoints = [];
        $positiveCount = 0;
        $negativeCount = 0;

        // Build a findings array compatible with computeWeightedScore/computeTrafficLight
        $findingsForCompute = [];

        foreach ($findings as $f) {
            $signal = $f['service_name'] ?? '';
            $subdomain = $f['subdomain'] ?? '';
            $type = $f['signal_type'] ?? '';
            $category = $f['category'] ?? '';
            $points = (int)($f['points'] ?? 0);

            // Skip non-signal findings (open_port, service, vulnerability legacy rows)
            if (empty($category) || empty($type)) {
                continue;
            }

            // Check both signal waivers and CVE waivers
            $isWaived = (!empty($signal) && !empty($subdomain) && isset($waiverLookup[$signal . ':' . $subdomain]))
                || (!empty($signal) && isset($cveWaiverLookup[$signal]));

            // Build finding entry for computeWeightedScore/computeTrafficLight
            $computeFinding = [
                'signal' => $signal,
                'subdomain' => $subdomain,
                'type' => $type,
                'category' => $category,
                'points' => $points,
                'waived' => $isWaived,
            ];
            $findingsForCompute[] = $computeFinding;

            if (!$isWaived) {
                $categoryPoints[$category][] = $points;
                if ($type === 'positive') {
                    $positiveCount++;
                } elseif ($type === 'negative') {
                    $negativeCount++;
                }
            }
        }

        // Count remaining (non-waived) vulnerability findings for vuln_exposure bonus
        $remainingVulnCount = 0;
        foreach ($findings as $f) {
            if (($f['finding_type'] ?? '') !== 'vulnerability') continue;
            $cveId = $f['cve_id'] ?? '';
            if (!empty($cveId) && isset($cveWaiverLookup[$cveId])) continue;
            $signal = $f['service_name'] ?? '';
            $subdomain = $f['subdomain'] ?? '';
            if (!empty($signal) && !empty($subdomain) && isset($waiverLookup[$signal . ':' . $subdomain])) continue;
            $remainingVulnCount++;
        }

        // Recompute category scores from non-waived findings (baseline 50, clamp 0-100)
        $allCategories = ['tls_crypto', 'network_security', 'app_hardening', 'vuln_exposure', 'email_security'];
        $categoryScores = [];

        foreach ($allCategories as $cat) {
            if (isset($categoryPoints[$cat])) {
                $score = 50;
                foreach ($categoryPoints[$cat] as $p) {
                    $score += $p;
                }
                $categoryScores[$cat] = max(0, min(100, $score));
            } else {
                // Check if this category had any findings (all waived) → baseline + bonuses
                $hadFindings = false;
                foreach ($findingsForCompute as $cf) {
                    if ($cf['category'] === $cat) {
                        $hadFindings = true;
                        break;
                    }
                }
                if ($hadFindings) {
                    $score = 50;
                    // For vuln_exposure with 0 remaining vulns, add the no_cves bonus
                    if ($cat === 'vuln_exposure' && $remainingVulnCount === 0) {
                        $score += $this->shodan->getSignalPoints('vuln_exposure', 'no_cves');
                    }
                    $categoryScores[$cat] = max(0, min(100, $score));
                }
            }
        }

        // Fall back to stored category scores for categories with no findings at all
        $storedCategoryScores = !empty($latestScore['category_scores'])
            ? json_decode($latestScore['category_scores'], true)
            : [];
        foreach ($allCategories as $cat) {
            if (!isset($categoryScores[$cat]) && isset($storedCategoryScores[$cat])) {
                $categoryScores[$cat] = (int)$storedCategoryScores[$cat];
            }
        }

        // Recount vulnerability findings excluding CVE-waived ones
        $vulnCount = 0;
        $criticalVulns = 0;
        $highVulns = 0;
        $mediumVulns = 0;
        $lowVulns = 0;
        foreach ($findings as $f) {
            if (($f['finding_type'] ?? '') !== 'vulnerability') continue;
            $cveId = $f['cve_id'] ?? '';
            if (!empty($cveId) && isset($cveWaiverLookup[$cveId])) continue;
            $vulnCount++;
            $sev = strtolower($f['severity'] ?? 'unknown');
            if ($sev === 'critical') $criticalVulns++;
            elseif ($sev === 'high') $highVulns++;
            elseif ($sev === 'medium') $mediumVulns++;
            elseif ($sev === 'low') $lowVulns++;
        }

        // Recompute weighted final score and traffic light
        $newScore = $this->shodan->computeWeightedScore($categoryScores, $findingsForCompute);
        $trafficLight = $this->shodan->computeTrafficLight($categoryScores, $findingsForCompute);
        $grade = $this->calculateGrade($newScore);

        // Update the score record in place
        try {
            $updateData = [
                'score' => $newScore,
                'score_grade' => $grade,
                'positive_count' => $positiveCount,
                'negative_count' => $negativeCount,
                'vuln_count' => $vulnCount,
                'critical_vulns' => $criticalVulns,
                'high_vulns' => $highVulns,
                'medium_vulns' => $mediumVulns,
                'low_vulns' => $lowVulns,
            ];

            if ($this->hasEnhancedColumns()) {
                $updateData['category_scores'] = json_encode($categoryScores);
                $updateData['traffic_light'] = $trafficLight;
            }

            $this->db->update(
                'vendor_shodan_scores',
                $updateData,
                'id = :id',
                [':id' => $scoreId]
            );

            $this->updateCurrentScore($vendorOnboardingId, $newScore);
            return true;
        } catch (Exception $e) {
            error_log('ShodanService: Failed to recalculate score from findings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Converts a Shodan score to a letter grade using configurable thresholds.
     * Supports range and percentage modes, just like SRSService.
     */
    public function calculateGrade(int $score): string
    {
        $config = $this->loadScoringConfig();

        if ($config['method'] === 'percentage') {
            $maxScore = $config['max_score'] > 0 ? $config['max_score'] : 100;
            $percentage = ($score / $maxScore) * 100;

            if ($percentage >= $config['grade_a_min']) return 'A';
            if ($percentage >= $config['grade_b_min']) return 'B';
            if ($percentage >= $config['grade_c_min']) return 'C';
            if ($percentage >= $config['grade_d_min']) return 'D';
            return 'F';
        }

        if ($score >= $config['grade_a_min']) return 'A';
        if ($score >= $config['grade_b_min']) return 'B';
        if ($score >= $config['grade_c_min']) return 'C';
        if ($score >= $config['grade_d_min']) return 'D';
        return 'F';
    }

    // =========================================================================
    // 4TH PARTY RISK — TECHNOLOGY INVENTORY
    // =========================================================================

    /**
     * Checks if the vendor_technologies table exists.
     */
    private ?bool $technologiesTableCache = null;
    public function hasTechnologiesTable(): bool
    {
        if ($this->technologiesTableCache !== null) {
            return $this->technologiesTableCache;
        }

        try {
            $result = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_technologies'"
            );
            $this->technologiesTableCache = !empty($result);
        } catch (Exception $e) {
            $this->technologiesTableCache = false;
        }

        return $this->technologiesTableCache;
    }

    /**
     * Stores extracted technologies for a vendor.
     * Pattern: Mark existing rows is_current=0, then upsert detected technologies back to is_current=1.
     */
    public function storeTechnologies(int $vendorId, string $domain, array $technologies): void
    {
        if (!$this->hasTechnologiesTable() || empty($technologies)) {
            return;
        }

        try {
            // Mark all existing rows for this vendor as not current
            $this->db->query(
                'UPDATE vendor_technologies SET is_current = 0 WHERE vendor_onboarding_id = :vid',
                [':vid' => $vendorId]
            );

            $now = date('Y-m-d H:i:s');

            foreach ($technologies as $tech) {
                // detected_on can be null — default to domain
                $detectedOn = $tech['detected_on'] ?? $domain;

                // Serialize CVEs to JSON (empty array becomes null to save space)
                $cvesJson = !empty($tech['cves']) ? json_encode($tech['cves']) : null;

                // Try to INSERT with ON DUPLICATE KEY UPDATE (upsert)
                $sql = "INSERT INTO vendor_technologies
                    (vendor_onboarding_id, vendor_domain, technology_name, technology_category,
                     technology_version, detected_on, detected_port, detection_method,
                     detection_confidence, first_seen_at, last_seen_at, is_current, raw_evidence, cves)
                    VALUES
                    (:vid, :domain, :name, :category,
                     :version, :detected_on, :port, :method,
                     :confidence, :now, :now2, 1, :evidence, :cves)
                    ON DUPLICATE KEY UPDATE
                        technology_version = VALUES(technology_version),
                        detected_port = VALUES(detected_port),
                        detection_method = VALUES(detection_method),
                        detection_confidence = VALUES(detection_confidence),
                        last_seen_at = VALUES(last_seen_at),
                        is_current = 1,
                        raw_evidence = VALUES(raw_evidence),
                        cves = VALUES(cves)";

                $this->db->query($sql, [
                    ':vid'        => $vendorId,
                    ':domain'     => $domain,
                    ':name'       => $tech['technology_name'],
                    ':category'   => $tech['technology_category'],
                    ':version'    => $tech['technology_version'] ?? null,
                    ':detected_on' => $detectedOn,
                    ':port'       => $tech['detected_port'] ?? null,
                    ':method'     => $tech['detection_method'] ?? null,
                    ':confidence' => $tech['detection_confidence'] ?? 'medium',
                    ':now'        => $now,
                    ':now2'       => $now,
                    ':evidence'   => $tech['raw_evidence'] ?? null,
                    ':cves'       => $cvesJson,
                ]);
            }
        } catch (Exception $e) {
            error_log('ShodanService: Failed to store technologies: ' . $e->getMessage());
        }
    }

    /**
     * Get all current technologies for a vendor.
     */
    public function getTechnologiesForVendor(int $vendorId): array
    {
        if (!$this->hasTechnologiesTable()) {
            return [];
        }

        try {
            $exclude = $this->techExcludeSQL('technology_name');
            return $this->db->fetchAll(
                "SELECT * FROM vendor_technologies
                 WHERE vendor_onboarding_id = :vid AND is_current = 1
                   AND {$exclude}
                 ORDER BY technology_category, technology_name",
                [':vid' => $vendorId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get all vendors using a specific technology.
     */
    public function getVendorsByTechnology(string $techName, int $limit = 200, int $offset = 0): array
    {
        if (!$this->hasTechnologiesTable()) {
            return [];
        }

        try {
            $aliases = $this->getTechAliases($techName);
            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            return $this->db->fetchAll(
                "SELECT vt.*, vor.vendor_name, vor.vendor_domain AS primary_domain, vor.status AS vendor_status
                 FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.technology_name IN ($placeholders) AND vt.is_current = 1
                   AND {$this->techExcludeSQL()}
                 ORDER BY vor.vendor_name
                 LIMIT {$limit} OFFSET {$offset}",
                array_values($aliases)
            );
        } catch (Exception $e) {
            return [];
        }
    }

    public function countVendorsByTechnology(string $techName): int
    {
        if (!$this->hasTechnologiesTable()) {
            return 0;
        }
        try {
            $aliases = $this->getTechAliases($techName);
            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.technology_name IN ($placeholders) AND vt.is_current = 1
                   AND {$this->techExcludeSQL()}",
                array_values($aliases)
            );
            return (int)($row['cnt'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get all technology detections that have a specific CVE.
     */
    public function getVendorsByCVE(string $cveId, int $limit = 200, int $offset = 0): array
    {
        if (!$this->hasTechnologiesTable()) {
            return [];
        }

        try {
            return $this->db->fetchAll(
                "SELECT vt.*, vor.vendor_name, vor.vendor_domain AS primary_domain, vor.status AS vendor_status
                 FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.cves LIKE :cve_pattern AND vt.is_current = 1
                 ORDER BY vor.vendor_name, vt.technology_name
                 LIMIT {$limit} OFFSET {$offset}",
                [':cve_pattern' => '%' . $cveId . '%']
            );
        } catch (Exception $e) {
            return [];
        }
    }

    public function countVendorsByCVE(string $cveId): int
    {
        if (!$this->hasTechnologiesTable()) {
            return 0;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS cnt FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.cves LIKE :cve_pattern AND vt.is_current = 1',
                [':cve_pattern' => '%' . $cveId . '%']
            );
            return (int)($row['cnt'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get technology concentration — count of vendors per technology, ranked.
     */
    public function getTechnologyConcentration(int $limit = 50): array
    {
        if (!$this->hasTechnologiesTable()) {
            return [];
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT technology_name,
                        GROUP_CONCAT(DISTINCT technology_category ORDER BY technology_category SEPARATOR \',\') AS technology_category,
                        COUNT(DISTINCT vendor_onboarding_id) AS vendor_count
                 FROM vendor_technologies
                 WHERE is_current = 1
                 GROUP BY technology_name
                 ORDER BY vendor_count DESC, technology_name ASC'
            );

            // Normalize names and re-aggregate
            $merged = [];
            foreach ($rows as $row) {
                $canonical = $this->normalizeTechName($row['technology_name']);
                if ($canonical === null) {
                    continue; // skip bare version strings
                }
                if (!isset($merged[$canonical])) {
                    $merged[$canonical] = [
                        'technology_name' => $canonical,
                        'categories' => [],
                        'vendor_ids' => [],
                    ];
                }
                foreach (explode(',', $row['technology_category']) as $cat) {
                    $merged[$canonical]['categories'][trim($cat)] = true;
                }
                // We need actual distinct vendor IDs for accurate counts when merging aliases
                $merged[$canonical]['vendor_ids'][$row['technology_name']] = true;
            }

            // Now get accurate vendor counts for merged entries by re-querying
            $result = [];
            foreach ($merged as $canonical => $info) {
                $aliases = array_keys($info['vendor_ids']);
                if (count($aliases) === 1 && $aliases[0] === $canonical) {
                    // No merging needed — use the original count from the row
                    $origRow = null;
                    foreach ($rows as $r) {
                        if ($r['technology_name'] === $canonical) {
                            $origRow = $r;
                            break;
                        }
                    }
                    $vendorCount = $origRow ? (int)$origRow['vendor_count'] : 0;
                } else {
                    // Multiple aliases merged — count distinct vendors across all of them
                    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                    $countRow = $this->db->fetchOne(
                        "SELECT COUNT(DISTINCT vendor_onboarding_id) AS vc FROM vendor_technologies WHERE is_current = 1 AND technology_name IN ($placeholders)",
                        array_values($aliases)
                    );
                    $vendorCount = $countRow ? (int)$countRow['vc'] : 0;
                }

                $cats = array_keys($info['categories']);
                sort($cats);
                $result[] = [
                    'technology_name' => $canonical,
                    'technology_category' => implode(',', $cats),
                    'vendor_count' => $vendorCount,
                ];
            }

            // Sort by vendor count desc, name asc
            usort($result, function ($a, $b) {
                return $b['vendor_count'] <=> $a['vendor_count'] ?: strcmp($a['technology_name'], $b['technology_name']);
            });

            return array_slice($result, 0, $limit);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Search technologies by partial name match.
     */
    public function searchTechnologies(string $query): array
    {
        if (!$this->hasTechnologiesTable() || empty(trim($query))) {
            return [];
        }

        try {
            return $this->db->fetchAll(
                'SELECT technology_name, technology_category,
                        COUNT(DISTINCT vendor_onboarding_id) AS vendor_count
                 FROM vendor_technologies
                 WHERE is_current = 1 AND technology_name LIKE :q
                 GROUP BY technology_name, technology_category
                 ORDER BY vendor_count DESC, technology_name ASC
                 LIMIT 50',
                [':q' => '%' . trim($query) . '%']
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Search CVEs using the view_cve_search database VIEW.
     * Returns per-finding rows with vendor info, subdomain, IP, and source detection flags.
     */
    public function searchCVEView(string $cveId): array
    {
        try {
            // Check if the view exists before querying
            $viewCheck = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'view_cve_search'"
            );
            if (empty($viewCheck)) {
                return [];
            }

            // Support both exact match (CVE-2024-38474) and partial/prefix match (CVE-2024)
            $isExact = (bool) preg_match('/^CVE-\d{4}-\d+$/i', $cveId);

            if ($isExact) {
                return $this->db->fetchAll(
                    'SELECT *
                     FROM view_cve_search
                     WHERE cve_id = :cve
                     ORDER BY vendor_name, subdomain, ip_address',
                    [':cve' => $cveId]
                );
            }

            // Partial match: prefix search with LIKE
            return $this->db->fetchAll(
                'SELECT *
                 FROM view_cve_search
                 WHERE cve_id LIKE :cve
                 ORDER BY cve_id, vendor_name, subdomain, ip_address',
                [':cve' => $cveId . '%']
            );
        } catch (Exception $e) {
            error_log('ShodanService: searchCVEView error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search CVE view by vendor name or domain. Returns all CVEs for matching vendors.
     */
    public function searchCVEByVendor(string $query): array
    {
        try {
            $viewCheck = $this->db->fetchOne(
                "SELECT 1 AS ok FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'view_cve_search'"
            );
            if (empty($viewCheck)) {
                return [];
            }

            $searchTerm = '%' . $query . '%';
            return $this->db->fetchAll(
                'SELECT *
                 FROM view_cve_search
                 WHERE vendor_name LIKE :name
                    OR vendor_domain LIKE :domain
                    OR vendor_domain = :exact
                    OR subdomain LIKE :sub
                 ORDER BY vendor_name, cve_id, subdomain, ip_address',
                [':name' => $searchTerm, ':domain' => $searchTerm, ':exact' => $query, ':sub' => $searchTerm]
            );
        } catch (Exception $e) {
            error_log('ShodanService: searchCVEByVendor error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all technologies for a vendor domain (used by 4th Party Risk page).
     */
    public function getTechnologiesByVendor(string $query, int $limit = 200, int $offset = 0): array
    {
        if (!$this->hasTechnologiesTable()) {
            return [];
        }

        try {
            $searchTerm = '%' . $query . '%';
            return $this->db->fetchAll(
                "SELECT vt.*, vor.vendor_name
                 FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.is_current = 1
                   AND {$this->techExcludeSQL()}
                   AND (vt.vendor_domain = :exact
                        OR vt.vendor_domain LIKE :search
                        OR vor.vendor_name LIKE :search2
                        OR vt.detected_on LIKE :search3)
                 ORDER BY vor.vendor_name, vt.technology_category, vt.technology_name
                 LIMIT {$limit} OFFSET {$offset}",
                [':exact' => $query, ':search' => $searchTerm, ':search2' => $searchTerm, ':search3' => $searchTerm]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    public function countTechnologiesByVendor(string $query): int
    {
        if (!$this->hasTechnologiesTable()) {
            return 0;
        }
        try {
            $searchTerm = '%' . $query . '%';
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS cnt FROM vendor_technologies vt
                 JOIN vendor_onboarding_requests vor ON vor.id = vt.vendor_onboarding_id
                 WHERE vt.is_current = 1
                   AND ' . $this->techExcludeSQL() . '
                   AND (vt.vendor_domain = :exact
                        OR vt.vendor_domain LIKE :search
                        OR vor.vendor_name LIKE :search2
                        OR vt.detected_on LIKE :search3)',
                [':exact' => $query, ':search' => $searchTerm, ':search2' => $searchTerm, ':search3' => $searchTerm]
            );
            return (int)($row['cnt'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * @deprecated Use getTechnologiesByVendor() instead
     */
    public function getTechnologiesByDomain(string $domain): array
    {
        return $this->getTechnologiesByVendor($domain);
    }
}
