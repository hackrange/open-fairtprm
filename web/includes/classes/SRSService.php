<?php
/**
 * SRS (Security Rating Service) - The Vendor Report Card Generator
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Think of this as the brain behind vendor security scoring. It wraps the UpGuard
 * integration, keeps a historical record of every score (because auditors love
 * history), and handles tier-based rescoring schedules so your critical Tier 1
 * vendors get eyeballed monthly while your "meh, they just supply coffee" Tier 3
 * vendors get checked annually. Also does grade calculations with configurable
 * thresholds, because one company's A is another company's B+ and everyone has
 * opinions about grading curves.
 */

require_once __DIR__ . '/UpGuardClient.php';

class SRSService
{
    private Database $db;
    private UpGuardClient $upguard;
    private ?array $scoringConfig = null;  // Cached so we don't hammer the DB on every call

    // Default tier rescoring intervals -- how often we bug UpGuard about each tier
    // Tier 1 = "we really care about these guys" (monthly)
    // Tier 3 = "we'll get around to it" (annually)
    private const DEFAULT_TIER_DAYS = [
        '1' => 30,   // Tier 1: 30 days (monthly) -- the VIPs
        '2' => 90,   // Tier 2: 90 days (quarterly) -- the regulars
        '3' => 365   // Tier 3: 365 days (annual) -- the "we forgot they existed"
    ];

    // Default scoring thresholds -- UpGuard's standard grading system.
    // Think of it like school grades but for cybersecurity, and failing
    // means your vendor might get hacked, not just held back a year.
    private const DEFAULT_SCORING = [
        'method' => 'range',
        'display_mode' => 'raw', // 'raw' = 0-950, 'percentage' = 0-100%
        'display_name' => 'UpGuard',
        'max_score' => 950,
        'grade_a_min' => 850,   // Honor roll
        'grade_b_min' => 700,   // Above average, parents are proud
        'grade_c_min' => 500,   // Average -- technically passing
        'grade_d_min' => 300,   // Below average -- might want to have a talk
        'tier1_days' => 30,     // Everything below 300 is an F. Yikes.
        'tier2_days' => 90,
        'tier3_days' => 365,
        'trending_days' => 90
    ];

    /**
     * Boot up the service. Grabs the DB singleton and creates an UpGuard client.
     * If UpGuard isn't configured, isAvailable() will return false and we'll
     * gracefully sit here doing nothing like an intern on day one.
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->upguard = new UpGuardClient();
    }

    /**
     * Loads scoring configuration from the app_config table.
     * Uses a local cache so we only hit the DB once per request.
     * Falls back to hardcoded defaults if the DB decides to be uncooperative.
     * The config lets admins tweak grade thresholds and rescore intervals
     * without touching code -- which is how it should be.
     */
    private function loadScoringConfig(): array
    {
        // Already loaded? Return the cached version. No need to bother the DB again.
        if ($this->scoringConfig !== null) {
            return $this->scoringConfig;
        }

        // Start with sane defaults, then override with whatever's in the DB
        $this->scoringConfig = self::DEFAULT_SCORING;

        try {
            $configs = $this->db->fetchAll(
                "SELECT config_key, config_value FROM app_config WHERE config_key LIKE 'upguard_%'"
            );

            // Walk through each config row and slot it into the right place.
            // The switch statement is verbose but makes it crystal clear what maps where.
            foreach ($configs as $row) {
                $key = str_replace('upguard_', '', $row['config_key']);
                switch ($key) {
                    case 'scoring_method':
                        $this->scoringConfig['method'] = $row['config_value'] ?: 'range';
                        break;
                    case 'display_mode':
                        $this->scoringConfig['display_mode'] = in_array($row['config_value'], ['raw', 'percentage']) ? $row['config_value'] : 'raw';
                        break;
                    case 'display_name':
                        $this->scoringConfig['display_name'] = !empty($row['config_value']) ? $row['config_value'] : 'UpGuard';
                        break;
                    case 'max_score':
                        $this->scoringConfig['max_score'] = (int)($row['config_value'] ?: 950);
                        break;
                    case 'grade_a_min':
                        $this->scoringConfig['grade_a_min'] = (int)($row['config_value'] ?: 850);
                        break;
                    case 'grade_b_min':
                        $this->scoringConfig['grade_b_min'] = (int)($row['config_value'] ?: 700);
                        break;
                    case 'grade_c_min':
                        $this->scoringConfig['grade_c_min'] = (int)($row['config_value'] ?: 500);
                        break;
                    case 'grade_d_min':
                        $this->scoringConfig['grade_d_min'] = (int)($row['config_value'] ?: 300);
                        break;
                    case 'tier1_days':
                        $this->scoringConfig['tier1_days'] = (int)($row['config_value'] ?: 30);
                        break;
                    case 'tier2_days':
                        $this->scoringConfig['tier2_days'] = (int)($row['config_value'] ?: 90);
                        break;
                    case 'tier3_days':
                        $this->scoringConfig['tier3_days'] = (int)($row['config_value'] ?: 365);
                        break;
                    case 'trending_days':
                        $this->scoringConfig['trending_days'] = (int)($row['config_value'] ?: 90);
                        break;
                }
            }
        } catch (Exception $e) {
            // DB hiccup -- we'll just roll with the defaults and log the incident
            error_log('SRSService: Failed to load scoring config: ' . $e->getMessage());
        }

        return $this->scoringConfig;
    }

    /**
     * Public getter for the scoring config. Just a polite wrapper around loadScoringConfig().
     * Useful for admin settings pages that need to display current thresholds.
     */
    public function getScoringConfig(): array
    {
        return $this->loadScoringConfig();
    }

    /**
     * Checks if vendor domains feature is enabled in admin settings.
     */
    public function isVendorDomainsEnabled(): bool
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT config_value FROM app_config WHERE config_key = 'upguard_vendor_domains'"
            );
            return $row && $row['config_value'] === '1';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if we can actually talk to UpGuard.
     * Returns false if the API key is missing or the integration is disabled.
     * Basically the "is anyone home?" check.
     */
    public function isAvailable(): bool
    {
        return $this->upguard->isConfigured();
    }

    /**
     * Passes through the last error from the UpGuard client.
     * Handy for showing users why their score request failed
     * instead of just a generic "something broke" message.
     */
    public function getLastError(): ?string
    {
        return $this->upguard->getLastError();
    }

    /**
     * The big kahuna -- scores a vendor and stores everything in the database.
     *
     * Here's the play-by-play:
     * 1. Ask UpGuard to score the vendor's domain
     * 2. Recalculate the grade using OUR configurable thresholds (not UpGuard's hardcoded ones)
     * 3. Store the score in the history table (auditors love paper trails)
     * 4. Update the vendor's current score on their main record
     *
     * Returns the full score data array or null if something went sideways.
     */
    public function scoreVendor(int $vendorOnboardingId, string $domain, ?string $vendorName = null, string $sourceTable = 'vendor_onboarding_requests'): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Check if vendor domains feature is enabled
        $fetchDomains = $this->isVendorDomainsEnabled();

        // Phone home to UpGuard and get the score
        $scoreData = $this->upguard->scoreVendor($domain, $vendorName, $fetchDomains);

        if (!$scoreData) {
            return null;
        }

        // UpGuardClient uses hardcoded grade thresholds, but we want to use
        // our admin-configurable thresholds. So we override the grade here.
        $scoreData['grade'] = $this->calculateGrade((int)$scoreData['score']);

        // Update the vendor's live score FIRST, then save history.
        // This ensures the current score is always set even if risk detail
        // storage fails (e.g. oversized risk_host values from UpGuard).
        $this->updateCurrentScore($vendorOnboardingId, $scoreData['score'], $sourceTable);

        // Only store history/risks for vendor_onboarding_requests (shadow_saas
        // entries don't have corresponding FK rows in history tables)
        if ($sourceTable === 'vendor_onboarding_requests') {
            $this->storeScore($vendorOnboardingId, $domain, $scoreData);
        }

        return $scoreData;
    }

    /**
     * Stuffs a score record into the vendor_srs_scores history table.
     * Also stores individual risk findings in vendor_srs_risks if we got 'em.
     * This gives us a full audit trail of how a vendor's security posture
     * has changed over time -- great for trend charts and "told you so" moments.
     */
    private function storeScore(int $vendorOnboardingId, string $domain, array $scoreData): void
    {
        $insertData = [
            'vendor_onboarding_id' => $vendorOnboardingId,
            'vendor_domain' => $domain,
            'score' => $scoreData['score'],
            'score_grade' => $scoreData['grade'],
            'category_scores' => !empty($scoreData['category_scores']) ? json_encode($scoreData['category_scores']) : null,
            'critical_risks' => $scoreData['critical_risks'] ?? 0,
            'high_risks' => $scoreData['high_risks'],
            'medium_risks' => $scoreData['medium_risks'],
            'low_risks' => $scoreData['low_risks'],
            'info_risks' => $scoreData['info_risks'],
            'was_already_monitored' => $scoreData['was_already_monitored'] ? 1 : 0,
            'scored_at' => date('Y-m-d H:i:s')
        ];

        $scoreId = $this->db->insert('vendor_srs_scores', $insertData);

        // If we got detailed risk findings from UpGuard, store those too.
        // Each risk gets its own row so we can query/filter/sort them later.
        // Individual risk insertions are wrapped in try-catch so a single
        // oversized field (e.g. risk_host > column limit) doesn't prevent
        // the rest of the risks from being stored.
        if (!empty($scoreData['risk_details']) && $scoreId) {
            foreach ($scoreData['risk_details'] as $risk) {
                try {
                    $riskRow = [
                        'srs_score_id' => $scoreId,
                        'risk_id' => $risk['risk_id'],
                        'risk_name' => $risk['risk_name'],
                        'risk_category' => $risk['risk_category'],
                        'severity' => $risk['severity'],
                        'description' => $risk['description'],
                        'first_seen' => $risk['first_seen']
                    ];
                    if (!empty($risk['risk_host'])) {
                        $riskRow['risk_host'] = $risk['risk_host'];
                    }
                    $this->db->insert('vendor_srs_risks', $riskRow);
                } catch (\Exception $e) {
                    error_log("SRS risk insert failed for score {$scoreId}, risk {$risk['risk_id']}: " . $e->getMessage());
                }
            }
        }

        // Store vendor subdomain scores if present (upsert pattern)
        if (!empty($scoreData['vendor_domains'])) {
            $this->storeVendorSubdomains($vendorOnboardingId, $domain, $scoreData['vendor_domains']);
        }

        // Clean up old scores beyond retention limit (risks cascade-delete)
        $this->cleanupOldScores($vendorOnboardingId);
    }

    /**
     * Upserts vendor subdomain score rows.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so rescoring updates existing rows.
     */
    private function storeVendorSubdomains(int $vendorOnboardingId, string $vendorDomain, array $domains): void
    {
        foreach ($domains as $d) {
            $subdomain = $d['hostname'] ?? '';
            if (empty($subdomain)) {
                continue;
            }

            $score = isset($d['score']) ? (int)$d['score'] : null;
            $grade = $score !== null ? UpGuardClient::scoreToGrade($score) : null;
            $isActive = !empty($d['active']) ? 1 : 0;

            $scannedAt = null;
            if (!empty($d['scanned_at'])) {
                try {
                    $dt = new DateTime($d['scanned_at']);
                    $scannedAt = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $scannedAt = null;
                }
            }

            $this->db->query(
                "INSERT INTO vendor_subdomain_scores
                    (vendor_onboarding_id, vendor_domain, subdomain, score, score_grade, is_active, last_scanned)
                 VALUES (:vid, :vd, :sub, :score, :grade, :active, :scanned)
                 ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    score_grade = VALUES(score_grade),
                    is_active = VALUES(is_active),
                    last_scanned = VALUES(last_scanned),
                    vendor_domain = VALUES(vendor_domain)",
                [
                    ':vid' => $vendorOnboardingId,
                    ':vd' => $vendorDomain,
                    ':sub' => $subdomain,
                    ':score' => $score,
                    ':grade' => $grade,
                    ':active' => $isActive,
                    ':scanned' => $scannedAt
                ]
            );
        }
    }

    /**
     * Removes old SRS score records beyond the retention limit.
     * Keeps the most recent N scores per vendor. Associated risks are
     * automatically deleted via ON DELETE CASCADE foreign key.
     */
    private const SCORE_RETENTION_LIMIT = 10;

    private function cleanupOldScores(int $vendorOnboardingId): void
    {
        try {
            $cutoff = $this->db->fetchOne(
                'SELECT id FROM vendor_srs_scores
                 WHERE vendor_onboarding_id = :vid
                 ORDER BY scored_at DESC
                 LIMIT 1 OFFSET ' . self::SCORE_RETENTION_LIMIT,
                [':vid' => $vendorOnboardingId]
            );

            if (!empty($cutoff['id'])) {
                $this->db->delete(
                    'vendor_srs_scores',
                    'vendor_onboarding_id = :vid AND id <= :cutoff_id',
                    [':vid' => $vendorOnboardingId, ':cutoff_id' => $cutoff['id']]
                );
            }
        } catch (Exception $e) {
            error_log('SRS score cleanup error: ' . $e->getMessage());
        }
    }

    /**
     * Stamps the vendor's main record with their latest score and timestamp.
     * This is the "at a glance" score you see on the vendor list page.
     */
    private function updateCurrentScore(int $vendorOnboardingId, int $score, string $tableName = 'vendor_onboarding_requests'): void
    {
        $this->db->update(
            $tableName,
            [
                'current_srs_score' => $score,
                'last_srs_score_at' => date('Y-m-d H:i:s')
            ],
            'id = :id',
            [':id' => $vendorOnboardingId]
        );
    }

    /**
     * Pull score history for a vendor -- newest first.
     * Default limit is 30 records, which is plenty for a chart
     * or a "look how much they've improved" executive summary.
     */
    public function getScoreHistory(int $vendorOnboardingId, int $limit = 30): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vendor_srs_scores
             WHERE vendor_onboarding_id = :id
             ORDER BY scored_at DESC
             LIMIT ' . intval($limit),
            [':id' => $vendorOnboardingId]
        );
    }

    /**
     * Get the most recent score for a vendor.
     * Returns null if they've never been scored (new vendor? lazy admin? who knows).
     */
    public function getLatestScore(int $vendorOnboardingId): ?array
    {
        $result = $this->db->fetchOne(
            'SELECT * FROM vendor_srs_scores
             WHERE vendor_onboarding_id = :id
             ORDER BY scored_at DESC
             LIMIT 1',
            [':id' => $vendorOnboardingId]
        );

        return $result ?: null;
    }

    /**
     * Get all risk findings for a specific score record.
     * Sorted by severity because you want to see the "critical" stuff first,
     * not buried under 47 "informational" findings about missing DNS TXT records.
     */
    public function getRisksForScore(int $scoreId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vendor_srs_risks
             WHERE srs_score_id = :id
             ORDER BY
                CASE severity
                    WHEN \'critical\' THEN 1
                    WHEN \'high\' THEN 2
                    WHEN \'medium\' THEN 3
                    WHEN \'low\' THEN 4
                    ELSE 5
                END,
                risk_name',
            [':id' => $scoreId]
        );
    }

    /**
     * Converts tier-based day intervals to seconds.
     * Because PHP's time functions think in seconds, not days,
     * and multiplying by 86400 in twelve different places is a code smell.
     */
    public function getTierIntervalSeconds(string $tier): int
    {
        $config = $this->loadScoringConfig();

        // Match the tier to its configured interval (in days)
        $days = match ($tier) {
            '1' => $config['tier1_days'] ?? self::DEFAULT_TIER_DAYS['1'],
            '2' => $config['tier2_days'] ?? self::DEFAULT_TIER_DAYS['2'],
            '3' => $config['tier3_days'] ?? self::DEFAULT_TIER_DAYS['3'],
            default => $config['tier2_days'] ?? self::DEFAULT_TIER_DAYS['2'] // Unknown tier? Treat 'em like Tier 2.
        };

        // days * hours * minutes * seconds -- the universal "convert days to seconds" dance
        return $days * 24 * 60 * 60;
    }

    /**
     * Decides if a vendor is due for a rescore based on their tier schedule.
     *
     * Rules of engagement:
     * - No tier assigned? Not our problem. (returns false)
     * - No domain? Can't score what doesn't exist. (returns false)
     * - Never been scored? Definitely needs scoring. (returns true)
     * - Last scored beyond the tier interval? Time for a checkup. (returns true)
     */
    public function needsRescore(array $vendor): bool
    {
        // No tier = not enrolled in the rescoring program
        if (empty($vendor['vendor_tier'])) {
            return false;
        }

        // No domain = nothing to point UpGuard at
        if (empty($vendor['vendor_domain'])) {
            return false;
        }

        // Never been scored? That's a yes from me, dawg.
        if (empty($vendor['last_srs_score_at'])) {
            return true;
        }

        // Check if enough time has passed since the last score
        $lastScored = strtotime($vendor['last_srs_score_at']);
        $interval = $this->getTierIntervalSeconds($vendor['vendor_tier']);

        return (time() - $lastScored) >= $interval;
    }

    /**
     * Queries all vendors that have tiers and domains, then filters to those
     * that actually need rescoring. Results are sorted so never-scored vendors
     * bubble to the top (NULL last_srs_score_at first), followed by the stalest scores.
     *
     * Limit defaults to 50 because we don't want to fire off 500 API calls
     * to UpGuard in one go -- that's how you get rate limited and sad.
     */
    public function getVendorsNeedingRescore(int $limit = 50): array
    {
        // Resolve the per-tier rescore intervals (admin-configurable, days).
        $config = $this->loadScoringConfig();
        $t1 = (int)($config['tier1_days'] ?? self::DEFAULT_TIER_DAYS['1']);
        $t2 = (int)($config['tier2_days'] ?? self::DEFAULT_TIER_DAYS['2']);
        $t3 = (int)($config['tier3_days'] ?? self::DEFAULT_TIER_DAYS['3']);

        // Only pull vendors that are ACTUALLY due for a rescore, deciding
        // due-ness in SQL *before* the LIMIT is applied.
        //
        // This ordering matters a great deal. The previous version fetched a
        // tier-ordered page of candidates and only checked due-ness afterward
        // in PHP. On a large tenant that quietly starved the lower tiers: a
        // batch could fill up entirely with not-yet-due Tier 1 vendors (they
        // sort first purely by tier), every one of them then gets filtered out
        // as "not due," and the genuinely-overdue Tier 2 / Tier 3 vendors --
        // which sort *after* every Tier 1 row -- are never fetched at all. The
        // result is a handful of correctly-tiered, active vendors stuck on
        // "Needs Rescore" forever while the cron reports "nothing to do."
        // Filtering in SQL means the LIMIT can only ever select vendors that
        // are truly due, so lower tiers can no longer be crowded out.
        // $t1/$t2/$t3 are cast to int above, so they are safe to interpolate.
        $vendors = $this->db->fetchAll(
            "SELECT * FROM vendor_onboarding_requests
             WHERE vendor_tier IS NOT NULL
               AND vendor_domain IS NOT NULL
               AND vendor_domain != ''
               AND status NOT IN ('inactive', 'rejected')
               AND (
                   last_srs_score_at IS NULL
                   OR last_srs_score_at < DATE_SUB(NOW(), INTERVAL
                       CASE vendor_tier
                           WHEN '1' THEN {$t1}
                           WHEN '2' THEN {$t2}
                           WHEN '3' THEN {$t3}
                           ELSE {$t2}
                       END DAY)
               )
             ORDER BY vendor_tier ASC, last_srs_score_at IS NULL DESC, last_srs_score_at ASC
             LIMIT " . intval($limit)
        );

        // Defense in depth: re-run the same tier check in PHP so any drift
        // between the SQL interval math and needsRescore() can only ever
        // REMOVE rows, never add ones that aren't genuinely due.
        return array_values(array_filter($vendors, function ($vendor) {
            return $this->needsRescore($vendor);
        }));
    }

    /**
     * The cron job's best friend -- processes a batch of vendors that need rescoring.
     *
     * Loops through vendors, scores each one, and keeps a running tally of
     * successes and failures. Has a 0.5 second delay between API calls
     * because UpGuard's rate limiter is not something you want to anger.
     *
     * Returns a nice summary array: how many processed, succeeded, failed,
     * and what went wrong (for the error-curious among us).
     */
    public function runScheduledRescoring(int $batchSize = 10): array
    {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Can't do much if UpGuard isn't configured
        if (!$this->isAvailable()) {
            $results['errors'][] = 'SRS integration is not configured';
            return $results;
        }

        $vendors = $this->getVendorsNeedingRescore($batchSize);

        foreach ($vendors as $vendor) {
            $results['processed']++;

            try {
                $score = $this->scoreVendor(
                    $vendor['id'],
                    $vendor['vendor_domain'],
                    $vendor['vendor_name']
                );

                if ($score) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to score {$vendor['vendor_domain']}: " . $this->getLastError();
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error scoring {$vendor['vendor_domain']}: " . $e->getMessage();
            }

            // Be nice to UpGuard's API -- half a second nap between calls.
            // Nobody likes the guy who hammers the API 100 times per second.
            usleep(500000); // 0.5 second delay
        }

        return $results;
    }

    /**
     * Pulls score trend data formatted nicely for chart libraries (Chart.js, etc).
     * Defaults to 90 days of history -- enough to see trends without drowning in data.
     * Returns an array of date/score/grade objects, sorted oldest-first for charting.
     */
    public function getScoreTrend(int $vendorOnboardingId, int $days = 90): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $scores = $this->db->fetchAll(
            'SELECT score, score_grade, scored_at
             FROM vendor_srs_scores
             WHERE vendor_onboarding_id = :id
               AND scored_at >= :start_date
             ORDER BY scored_at ASC',
            [':id' => $vendorOnboardingId, ':start_date' => $startDate]
        );

        // Reshape into clean chart-friendly objects
        return array_map(function ($row) {
            return [
                'date' => date('Y-m-d', strtotime($row['scored_at'])),
                'score' => (int)$row['score'],
                'grade' => $row['score_grade']
            ];
        }, $scores);
    }

    /**
     * Pings UpGuard to make sure the API key works and we can reach them.
     * Used by the admin settings page "Test Connection" button.
     */
    public function testConnection(): array
    {
        return $this->upguard->testConnection();
    }

    /**
     * Converts a raw numeric score to a letter grade (A through F).
     * Supports two modes:
     * - 'range' (default): compares score directly against threshold values
     * - 'percentage': converts score to a percentage of max_score first
     *
     * The thresholds are pulled from the configurable scoring config,
     * so admins can be as generous or ruthless with grading as they want.
     */
    public function calculateGrade(int $score): string
    {
        $config = $this->loadScoringConfig();

        // Use percentage grading when either the scoring method OR display mode
        // is set to percentage. This prevents mismatches where the score displays
        // as a percentage but the grade compares against raw thresholds.
        $usePercentage = $config['method'] === 'percentage'
                      || $config['display_mode'] === 'percentage';

        if ($usePercentage) {
            // Percentage mode: normalize the score against the max, then grade it
            // Use floor() so the grade matches the displayed (floored) percentage
            $maxScore = $config['max_score'] > 0 ? $config['max_score'] : 950;
            $percentage = floor(($score / $maxScore) * 100);

            if ($percentage >= $config['grade_a_min']) return 'A';
            if ($percentage >= $config['grade_b_min']) return 'B';
            if ($percentage >= $config['grade_c_min']) return 'C';
            if ($percentage >= $config['grade_d_min']) return 'D';
            return 'F';
        }

        // Range mode: just compare the raw score against the thresholds directly
        if ($score >= $config['grade_a_min']) return 'A';
        if ($score >= $config['grade_b_min']) return 'B';
        if ($score >= $config['grade_c_min']) return 'C';
        if ($score >= $config['grade_d_min']) return 'D';
        return 'F';  // Anything below grade_d_min is a big ol' F
    }

    /**
     * Computes a combined security score from all available sources (UpGuard, Shodan, Custom).
     * Each source is normalized to 0-100%, then averaged. Null/zero scores are excluded.
     *
     * @param array $vendor Row from vendor_onboarding_requests with score columns
     * @return array ['score' => int|null, 'grade' => string|null, 'source_count' => int]
     */
    public function getCombinedScore(array $vendor): array
    {
        $config = $this->loadScoringConfig();
        $maxScore = $config['max_score'] > 0 ? $config['max_score'] : 950;

        $percents = [];

        // UpGuard: normalize raw 0-950 (or whatever max) to 0-100
        if (!empty($vendor['current_srs_score']) && (int)$vendor['current_srs_score'] > 0) {
            $percents[] = ((int)$vendor['current_srs_score'] / $maxScore) * 100;
        }

        // Shodan: already 0-100
        if (!empty($vendor['current_shodan_score']) && (int)$vendor['current_shodan_score'] > 0) {
            $percents[] = (int)$vendor['current_shodan_score'];
        }

        // Custom: already 1-100
        if (!empty($vendor['custom_score']) && (int)$vendor['custom_score'] > 0) {
            $percents[] = (int)$vendor['custom_score'];
        }

        if (empty($percents)) {
            return ['score' => null, 'grade' => null, 'source_count' => 0];
        }

        $combined = (int)round(array_sum($percents) / count($percents));

        // Grade using admin-configured percentage thresholds
        $aMin = (int)($config['grade_a_min'] ?? 90);
        $bMin = (int)($config['grade_b_min'] ?? 80);
        $cMin = (int)($config['grade_c_min'] ?? 70);
        $dMin = (int)($config['grade_d_min'] ?? 60);

        if ($combined >= $aMin) $grade = 'A';
        elseif ($combined >= $bMin) $grade = 'B';
        elseif ($combined >= $cMin) $grade = 'C';
        elseif ($combined >= $dMin) $grade = 'D';
        else $grade = 'F';

        return ['score' => $combined, 'grade' => $grade, 'source_count' => count($percents)];
    }

    /**
     * Static grade calculation using hardcoded UpGuard defaults.
     * @deprecated Use calculateGrade() instead -- this one doesn't read the config.
     * Kept around for backward compatibility because ripping it out would
     * break who-knows-what in the codebase. Classic legacy code dilemma.
     */
    public static function scoreToGrade(int $score): string
    {
        return UpGuardClient::scoreToGrade($score);
    }

    /**
     * Returns a human-friendly tier label with the configured day interval.
     * e.g., "Tier 1 (30 days)" or "Tier 3 (365 days)".
     * Unknown tiers get labeled "Unassigned" because honesty is the best policy.
     */
    public function getTierDisplayName(string $tier): string
    {
        $config = $this->loadScoringConfig();
        $days = match ($tier) {
            '1' => $config['tier1_days'] ?? 30,
            '2' => $config['tier2_days'] ?? 90,
            '3' => $config['tier3_days'] ?? 365,
            default => null
        };

        if ($days === null) {
            return 'Unassigned';
        }

        return "Tier {$tier} ({$days} days)";
    }

    /**
     * Returns a description of how often a tier gets rescored.
     * e.g., "Rescored every 30 days". Good for tooltips and help text.
     */
    public function getTierIntervalDescription(string $tier): string
    {
        $config = $this->loadScoringConfig();
        $days = match ($tier) {
            '1' => $config['tier1_days'] ?? 30,
            '2' => $config['tier2_days'] ?? 90,
            '3' => $config['tier3_days'] ?? 365,
            default => null
        };

        if ($days === null) {
            return 'No automatic rescoring';
        }

        return "Rescored every {$days} days";
    }

    /**
     * Static tier name using hardcoded "Monthly/Quarterly/Annual" labels.
     * @deprecated Use getTierDisplayName() for configurable intervals.
     * This exists for the same reason your grandma still has a landline.
     */
    public static function getTierName(string $tier): string
    {
        return match ($tier) {
            '1' => 'Tier 1 (Monthly)',
            '2' => 'Tier 2 (Quarterly)',
            '3' => 'Tier 3 (Annual)',
            default => 'Unassigned'
        };
    }

    /**
     * Static tier interval description using hardcoded defaults.
     * @deprecated Use getTierIntervalDescription() for configurable intervals.
     * Another relic of simpler times.
     */
    public static function getTierInterval(string $tier): string
    {
        return match ($tier) {
            '1' => 'Rescored monthly',
            '2' => 'Rescored quarterly',
            '3' => 'Rescored annually',
            default => 'No automatic rescoring'
        };
    }

    /**
     * Fetches the latest SRS data for a vendor identified by domain.
     * This is the "give me everything you know about this domain" method,
     * primarily used to pre-populate FAIR analysis forms so users don't
     * have to manually re-enter risk data we already have.
     *
     * Returns a nicely packaged array with scores, grades, risk counts,
     * and even pre-categorized vulnerability/configuration data strings
     * ready to paste into FAIR form fields. Or null if we've got nothing.
     */
    public function getVendorDataByDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if (empty($domain)) {
            return null;
        }

        // Look up the vendor onboarding record for this domain
        $vendor = $this->db->fetchOne(
            'SELECT id, vendor_name, vendor_domain, current_srs_score
             FROM vendor_onboarding_requests
             WHERE LOWER(vendor_domain) = :domain
             ORDER BY last_srs_score_at DESC
             LIMIT 1',
            [':domain' => $domain]
        );

        if (!$vendor) {
            return null; // Never heard of 'em
        }

        // Get their latest score record
        $latestScore = $this->getLatestScore($vendor['id']);
        if (!$latestScore) {
            return null; // We know the vendor but they've never been scored
        }

        // Grab all the risk findings associated with that score
        $risks = $this->getRisksForScore($latestScore['id']);

        // Calculate the grade using our configurable thresholds
        $grade = $this->calculateGrade((int)$latestScore['score']);

        // Now the fun part: categorize risks into "vulnerability" vs "configuration"
        // buckets for the FAIR form. This saves the analyst from manually sorting
        // through 50 risk findings to figure out what goes where.
        $vulnerabilityRisks = [];
        $configurationRisks = [];

        // These UpGuard risk categories map to the "Vulnerability Data" FAIR field
        $vulnCategories = ['vulnerability_management', 'software_patching', 'network_security'];

        // These map to the "Configuration Data" FAIR field
        $configCategories = ['email_sec', 'email_security', 'encryption', 'website_sec', 'website_security', 'dns', 'dns_health', 'ip_reputation', 'ssl', 'tls'];

        foreach ($risks as $risk) {
            // Only pull in Critical and High severity -- no one wants to read 200 "info" findings
            $severity = strtolower($risk['severity'] ?? '');
            if (!in_array($severity, ['critical', 'high'])) {
                continue;
            }

            $category = strtolower($risk['risk_category'] ?? '');
            $riskEntry = sprintf(
                "[%s] %s: %s",
                strtoupper($severity),
                $risk['risk_name'] ?? 'Unknown',
                $risk['description'] ?? ''
            );

            // Sort each risk into the appropriate bucket
            foreach ($vulnCategories as $vulnCat) {
                if (strpos($category, $vulnCat) !== false) {
                    $vulnerabilityRisks[] = $riskEntry;
                    break;
                }
            }

            foreach ($configCategories as $confCat) {
                if (strpos($category, $confCat) !== false) {
                    $configurationRisks[] = $riskEntry;
                    break;
                }
            }
        }

        // Parse category scores JSON if we stored it
        $categoryScores = [];
        if (!empty($latestScore['category_scores'])) {
            $categoryScores = json_decode($latestScore['category_scores'], true) ?: [];
        }

        // Package it all up in a nice tidy array
        return [
            'vendor_id' => $vendor['id'],
            'vendor_name' => $vendor['vendor_name'],
            'vendor_domain' => $vendor['vendor_domain'],
            'score' => (int)$latestScore['score'],
            'grade' => $grade,
            'scored_at' => $latestScore['scored_at'],
            'critical_risks' => (int)($latestScore['critical_risks'] ?? 0),
            'high_risks' => (int)($latestScore['high_risks'] ?? 0),
            'medium_risks' => (int)($latestScore['medium_risks'] ?? 0),
            'low_risks' => (int)($latestScore['low_risks'] ?? 0),
            'category_scores' => $categoryScores,
            'vulnerability_data' => implode("\n\n", $vulnerabilityRisks),
            'configuration_data' => implode("\n\n", $configurationRisks)
        ];
    }

    /**
     * Fetches all vendor subdomain scores for a specific vendor.
     * Ordered by score ascending (worst first) so the riskiest domains bubble up.
     */
    public function getVendorSubdomains(int $vendorOnboardingId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vendor_subdomain_scores
             WHERE vendor_onboarding_id = :id
             ORDER BY score ASC, subdomain ASC',
            [':id' => $vendorOnboardingId]
        );
    }

    /**
     * Fetches all vendor subdomain scores across all vendors, with optional
     * domain search filter. Used for the dedicated Vendor Domains list page.
     */
    public function getAllVendorSubdomains(?string $searchDomain = null): array
    {
        $sql = 'SELECT r.*, v.vendor_name, v.vendor_domain AS parent_domain, v.id AS vendor_id
                FROM vendor_subdomain_scores r
                JOIN vendor_onboarding_requests v ON v.id = r.vendor_onboarding_id';
        $params = [];

        if (!empty($searchDomain)) {
            $sql .= ' WHERE r.vendor_domain LIKE :search OR r.subdomain LIKE :search2 OR v.vendor_name LIKE :search3';
            $params[':search'] = '%' . $searchDomain . '%';
            $params[':search2'] = '%' . $searchDomain . '%';
            $params[':search3'] = '%' . $searchDomain . '%';
        }

        $sql .= ' ORDER BY r.score ASC, r.subdomain ASC';

        return $this->db->fetchAll($sql, $params);
    }
}
