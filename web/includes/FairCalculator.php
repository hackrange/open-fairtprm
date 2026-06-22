<?php
/**
 * FAIR (Factor Analysis of Information Risk) Calculator - Putting Dollar Signs on Fear
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Ever wondered "yeah but how much would it actually COST if this vendor gets hacked?"
 * This calculator answers that question using the FAIR methodology. Takes vendor security
 * data, feeds it through a series of risk calculations, and spits out an Annualized Loss
 * Expectancy (ALE) -- basically the dollar amount you should expect to lose per year from
 * this vendor relationship. The math boils down to:
 * Risk = How Often Bad Stuff Happens x How Much It Costs When It Does.
 * Or in FAIR-speak: ALE = Loss Event Frequency x Loss Magnitude, where
 * LEF = Threat Event Frequency x Vulnerability.
 *
 * v2: Now pulls real security intelligence from Shodan (CVEs, signals) and UpGuard
 * (risks, scores) directly from the database. Also enforces a revenue-based cap so
 * a $5M company doesn't get a $10M ALE.
 *
 * More info: https://www.fairinstitute.org/
 */

class FairCalculator
{
    // ---------------------------------------------------------------
    // THREAT EVENT FREQUENCY MULTIPLIERS
    // How attractive is this vendor to attackers? Baseline is 1 event/year
    // and we multiply up based on data classification and threat landscape.
    // ---------------------------------------------------------------
    // TEF here means the frequency of MATERIAL threat events (serious attempts capable
    // of causing a loss), NOT raw scan/contact volume. Calibrated so an unremarkable
    // vendor sits below 1/yr and only prime, exposed targets approach the ceiling.
    const TEF_BASELINE             = 0.5;   // Baseline material threat events/year for an average vendor
    const TEF_CRITICAL_DATA        = 2.5;   // Crown-jewel data draws more serious, targeted attempts
    const TEF_SENSITIVE_DATA       = 1.8;   // Sensitive but not crown-jewels level
    const TEF_PUBLIC_DATA          = 0.4;   // Public/no sensitive data = a less interesting target
    const TEF_DATA_SHARING_BOOST   = 1.25;  // Sharing data = a somewhat larger attack surface
    const TEF_THREAT_INTEL_HIGH    = 1.5;   // Active threats in the sector raise attempt frequency
    const TEF_THREAT_INTEL_MEDIUM  = 1.2;   // Some chatter, stay alert

    // Shodan-driven TEF boosts (additive, not multiplicative)
    const TEF_PER_CRITICAL_CVE     = 0.15;  // Each critical CVE attracts some targeted exploitation
    const TEF_PER_HIGH_CVE         = 0.05;  // Each high CVE, a smaller pull
    const TEF_CRITICAL_CVE_CAP     = 3;     // Max CVEs counted for TEF boost
    const TEF_HIGH_CVE_CAP         = 3;
    const TEF_EXPOSED_DB           = 0.5;   // Exposed database port = scanner magnet
    const TEF_EXPOSED_ADMIN        = 0.25;  // Exposed admin panel = unwanted attention
    const TEF_MANY_OPEN_PORTS      = 0.25;  // >10 open ports = larger attack surface
    const TEF_CEILING              = 6.0;   // Even prime targets see a bounded rate of material attempts

    // ---------------------------------------------------------------
    // VULNERABILITY BASELINES & GRADE MULTIPLIERS
    // Maps security grades to "probability an attack succeeds."
    // Start at 50% (coin flip) and adjust based on actual security posture.
    // ---------------------------------------------------------------
    // Vulnerability = P(a material threat event becomes an actual loss event). This is a
    // CONDITIONAL probability, not "how bad is the vendor" -- most serious attempts still
    // do NOT end in a material breach, so it stays well below 1.0. The grade constants are
    // RELATIVE multipliers on the baseline (C = average = 1.0x). There is deliberately no
    // separate D/F penalty: the grade multiplier already encodes posture (double-counting
    // it previously slammed every D/F vendor into the ceiling, erasing all other signal).
    const VULN_BASELINE            = 0.20;  // Average vendor: ~1 in 5 material attempts lands
    const VULN_GRADE_A             = 0.40;  // Excellent posture -> 0.20 x 0.40 = 0.08
    const VULN_GRADE_B             = 0.60;  // Good            -> 0.12
    const VULN_GRADE_C             = 1.00;  // Average         -> 0.20
    const VULN_GRADE_D             = 1.60;  // Below average   -> 0.32
    const VULN_GRADE_F             = 2.20;  // Failing         -> 0.44
    const VULN_ISO_27001_DISCOUNT  = 0.55;  // ISO cert = 45% reduction (research-backed)
    const VULN_NO_VULN_MGMT        = 1.5;   // No vuln management? 50% penalty
    const VULN_EXCELLENT_VULN_MGMT = 0.7;   // Automated scanning? Gold star. 30% off.
    const VULN_NO_PATCHING         = 1.4;   // Unpatched = 40% more vulnerable
    const VULN_AUTO_PATCHING       = 0.8;   // Auto-patch within 24hrs? 20% off
    const VULN_MFA_DISCOUNT        = 0.7;   // MFA alone prevents a massive chunk of attacks
    const VULN_LEAST_PRIVILEGE     = 0.85;  // Least privilege = 15% reduction on top
    const VULN_FLOOR               = 0.01;  // Nothing is perfectly secure (1% minimum)
    const VULN_CEILING             = 0.55;  // A single threat event rarely exceeds ~55% chance of material loss

    // Shodan signal-based vulnerability adjustments (additive to the 0-1 score)
    const VULN_PER_CRITICAL_CVE    = 0.04;  // Each critical CVE adds 4% conditional loss probability
    const VULN_PER_HIGH_CVE        = 0.02;  // Each high CVE adds 2%
    const VULN_NO_DMARC            = 0.03;  // No DMARC = phishing door wide open
    const VULN_NO_SPF              = 0.03;  // No SPF = spoofable
    const VULN_TLS10_ENABLED       = 0.05;  // TLS 1.0 = downgrade attacks possible
    const VULN_SELF_SIGNED         = 0.05;  // Self-signed cert = trust issues
    const VULN_EXPOSED_DB          = 0.06;  // Exposed database = direct data access risk
    const VULN_EXPOSED_ADMIN       = 0.06;  // Exposed admin = brute-force target
    const VULN_SHARED_HOSTING      = 0.05;  // Shared/residential = noisy neighbors
    const VULN_WAF_DISCOUNT        = -0.06; // WAF/CDN = attacks get filtered
    const VULN_TLS13_DISCOUNT      = -0.03; // TLS 1.3 = modern crypto
    const VULN_HSTS_DISCOUNT       = -0.03; // HSTS = no SSL stripping
    const VULN_ENTERPRISE_CLOUD    = -0.03; // Enterprise cloud = baseline security
    const VULN_NO_CVES_DISCOUNT    = -0.05; // Clean CVE slate = well maintained
    const VULN_DMARC_REJECT        = -0.03; // DMARC reject = phishing protection
    const VULN_SPF_HARD_FAIL       = -0.02; // SPF hard fail = spoofing protection
    const VULN_STD_PORTS_ONLY      = -0.02; // Only standard ports = minimal exposure

    // UpGuard-based vulnerability adjustments
    const VULN_PER_UG_CRITICAL     = 0.02;  // Each UpGuard critical risk
    const VULN_PER_UG_HIGH         = 0.01;  // Each UpGuard high risk
    const VULN_UG_CRITICAL_CAP     = 5;     // Max UpGuard risks counted
    const VULN_UG_HIGH_CAP         = 5;
    const VULN_UG_GRADE_A_BONUS    = -0.05; // UpGuard A-grade bonus
    const VULN_UG_GRADE_F_PENALTY  = 0.10;  // UpGuard F-grade penalty

    // UpGuard high-risk reputational damage escalation threshold
    const UG_HIGH_RISK_THRESHOLD   = 10;    // >10 high+critical risks = increased rep damage
    const REP_DAMAGE_ESCALATED     = 0.30;  // Escalated reputational damage rate

    // ---------------------------------------------------------------
    // SECONDARY LOSS MULTIPLIERS
    // The aftermath costs that keep CFOs up at night.
    // ---------------------------------------------------------------
    const INSURANCE_PREMIUM_MULTIPLIER = 2.0;  // Premiums roughly triple after a breach (2x delta)
    const REPUTATIONAL_DAMAGE_PERCENT  = 0.20; // Conservative: 20% of total breach cost
    const LOW_RISK_ALE_CAP             = 1000; // Cap for vendors with no data sharing + low impact

    // ---------------------------------------------------------------
    // RISK LEVEL THRESHOLDS
    // Translates dollar amounts into "how worried should I be?" labels.
    // ---------------------------------------------------------------
    const RISK_VERY_LOW_MAX  = 1000;     // Sleep well
    const RISK_LOW_MAX       = 10000;    // Keep an eye on it
    const RISK_MEDIUM_MAX    = 50000;    // Budget for it
    const RISK_HIGH_MAX      = 250000;   // Management should know
    const RISK_VERY_HIGH_MAX = 1000000;  // Board should know
    // Above RISK_VERY_HIGH_MAX = Critical (everyone should know, and possibly panic)

    // ---------------------------------------------------------------
    // LIABILITY / INSURANCE
    // ---------------------------------------------------------------
    const LIABILITY_MULTIPLIER = 3.0; // Recommend 3x ALE for worst-case buffer

    // ---------------------------------------------------------------
    // LOSS EVENT FREQUENCY CEILING
    // Hard real-world backstop. Published breach base rates (Verizon DBIR, Cyentia
    // IRIS, insurer actuarial data) put a single organization's annual probability of
    // a MATERIAL breach in the ~5-15% range, with even poor-posture firms rarely above
    // ~50%. An LEF above 1.0 implies more than one material breach per year, every
    // year -- which is not realistic for one vendor. No input path may exceed this.
    // ---------------------------------------------------------------
    const LEF_CEILING = 1.0;

    // ---------------------------------------------------------------
    // DEFAULT BREACH COSTS (fallbacks if app_config is empty)
    // ---------------------------------------------------------------
    const DEFAULT_PII_COST  = 160;      // $160/record (Ponemon Institute average)
    const DEFAULT_SPII_COST = 200;      // $200/record for SSNs, health data
    const DEFAULT_SOX_PENALTY = 5000000; // $5M flat -- Sarbanes-Oxley doesn't mess around

    /**
     * Loads configurable defaults from the app_config table.
     * Now includes breach costs, revenue, cap percentage, and AI toggle.
     */
    private static function getCalculationDefaults(): array
    {
        static $defaults = null;

        if ($defaults !== null) {
            return $defaults;
        }

        try {
            $db = Database::getInstance();
            $config = $db->fetchAll(
                'SELECT config_key, config_value FROM app_config WHERE config_key IN (?, ?, ?, ?, ?, ?)',
                [
                    'pii_breach_cost_per_record',
                    'spii_breach_cost_per_record',
                    'sox_breach_penalty',
                    'fair_annual_revenue',
                    'fair_revenue_cap_pct',
                    'fair_ai_enabled',
                ]
            );

            $defaults = [
                'pii_breach_cost_per_record' => self::DEFAULT_PII_COST,
                'spii_breach_cost_per_record' => self::DEFAULT_SPII_COST,
                'sox_breach_penalty' => self::DEFAULT_SOX_PENALTY,
                'fair_annual_revenue' => 0,
                'fair_revenue_cap_pct' => 10,
                'fair_ai_enabled' => '1',
            ];

            foreach ($config as $row) {
                $defaults[$row['config_key']] = $row['config_value'];
            }

            // Ensure numeric types
            $defaults['pii_breach_cost_per_record'] = floatval($defaults['pii_breach_cost_per_record']);
            $defaults['spii_breach_cost_per_record'] = floatval($defaults['spii_breach_cost_per_record']);
            $defaults['sox_breach_penalty'] = floatval($defaults['sox_breach_penalty']);
            $defaults['fair_annual_revenue'] = floatval($defaults['fair_annual_revenue']);
            $defaults['fair_revenue_cap_pct'] = floatval($defaults['fair_revenue_cap_pct']);
        } catch (Exception $e) {
            error_log('Failed to load calculation defaults: ' . $e->getMessage());
            $defaults = [
                'pii_breach_cost_per_record' => self::DEFAULT_PII_COST,
                'spii_breach_cost_per_record' => self::DEFAULT_SPII_COST,
                'sox_breach_penalty' => self::DEFAULT_SOX_PENALTY,
                'fair_annual_revenue' => 0,
                'fair_revenue_cap_pct' => 10,
                'fair_ai_enabled' => '1',
            ];
        }

        return $defaults;
    }

    /**
     * Pulls Shodan security intelligence for a vendor from the database.
     * Returns structured signal data for TEF and vulnerability adjustments.
     */
    private static function getShodanIntelligence(int $vendorOnboardingId): array
    {
        $intel = [
            'available' => false,
            'score' => 0,
            'grade' => '',
            'critical_cves' => 0,
            'high_cves' => 0,
            'medium_cves' => 0,
            'open_ports' => 0,
            'positive_count' => 0,
            'negative_count' => 0,
            // Signal flags for vulnerability adjustments
            'has_waf' => false,
            'has_tls13' => false,
            'has_hsts' => false,
            'has_enterprise_cloud' => false,
            'has_no_cves' => false,
            'has_dmarc_reject' => false,
            'has_spf_hard_fail' => false,
            'has_std_ports_only' => false,
            'has_tls10' => false,
            'has_self_signed' => false,
            'has_exposed_db' => false,
            'has_exposed_admin' => false,
            'has_shared_hosting' => false,
            'has_no_dmarc' => false,
            'has_no_spf' => false,
        ];

        try {
            $db = Database::getInstance();

            // Get latest Shodan score
            $score = $db->fetchOne(
                'SELECT * FROM vendor_shodan_scores
                 WHERE vendor_onboarding_id = :vid
                 ORDER BY scored_at DESC LIMIT 1',
                [':vid' => $vendorOnboardingId]
            );

            if (!$score) {
                return $intel;
            }

            $intel['available'] = true;
            $intel['score'] = intval($score['score'] ?? 0);
            $intel['grade'] = $score['score_grade'] ?? '';
            $intel['critical_cves'] = intval($score['critical_vulns'] ?? 0);
            $intel['high_cves'] = intval($score['high_vulns'] ?? 0);
            $intel['medium_cves'] = intval($score['medium_vulns'] ?? 0);
            $intel['open_ports'] = intval($score['open_ports_count'] ?? 0);
            $intel['positive_count'] = intval($score['positive_count'] ?? 0);
            $intel['negative_count'] = intval($score['negative_count'] ?? 0);

            // Get individual findings to determine specific signal flags
            $scoreId = intval($score['id']);
            $findings = $db->fetchAll(
                "SELECT finding_type, signal_type, category, service_name, port
                 FROM vendor_shodan_findings
                 WHERE shodan_score_id = :sid",
                [':sid' => $scoreId]
            );

            foreach ($findings as $f) {
                $signal = $f['signal_type'] ?? '';
                $svc = strtolower($f['service_name'] ?? '');

                if ($signal === 'positive') {
                    // Map positive signal findings to flags by service_name
                    if (stripos($svc, 'waf') !== false || stripos($svc, 'cdn') !== false) {
                        $intel['has_waf'] = true;
                    }
                    if (stripos($svc, 'tls_1_3') !== false || stripos($svc, 'tls 1.3') !== false) {
                        $intel['has_tls13'] = true;
                    }
                    if (stripos($svc, 'hsts') !== false) {
                        $intel['has_hsts'] = true;
                    }
                    if (stripos($svc, 'enterprise_cloud') !== false || stripos($svc, 'enterprise cloud') !== false) {
                        $intel['has_enterprise_cloud'] = true;
                    }
                    if (stripos($svc, 'no_cves') !== false || stripos($svc, 'no cve') !== false) {
                        $intel['has_no_cves'] = true;
                    }
                    if (stripos($svc, 'dmarc_reject') !== false || stripos($svc, 'dmarc reject') !== false) {
                        $intel['has_dmarc_reject'] = true;
                    }
                    if (stripos($svc, 'spf_hard_fail') !== false || stripos($svc, 'spf hard') !== false) {
                        $intel['has_spf_hard_fail'] = true;
                    }
                    if (stripos($svc, 'standard_ports_only') !== false || stripos($svc, 'standard ports') !== false) {
                        $intel['has_std_ports_only'] = true;
                    }
                } elseif ($signal === 'negative') {
                    // Map negative signal findings to flags by service_name
                    if (stripos($svc, 'tls_1_0') !== false || stripos($svc, 'tls 1.0') !== false) {
                        $intel['has_tls10'] = true;
                    }
                    if (stripos($svc, 'self_signed') !== false || stripos($svc, 'self-signed') !== false) {
                        $intel['has_self_signed'] = true;
                    }
                    if (stripos($svc, 'db_port') !== false || stripos($svc, 'database port') !== false) {
                        $intel['has_exposed_db'] = true;
                    }
                    if (stripos($svc, 'admin_panel') !== false || stripos($svc, 'admin panel') !== false
                        || stripos($svc, 'db_ui') !== false || stripos($svc, 'database ui') !== false) {
                        $intel['has_exposed_admin'] = true;
                    }
                    if (stripos($svc, 'shared_hosting') !== false || stripos($svc, 'residential') !== false) {
                        $intel['has_shared_hosting'] = true;
                    }
                    if (stripos($svc, 'no_dmarc') !== false || stripos($svc, 'no dmarc') !== false) {
                        $intel['has_no_dmarc'] = true;
                    }
                    if (stripos($svc, 'no_spf') !== false || stripos($svc, 'no spf') !== false) {
                        $intel['has_no_spf'] = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('FairCalculator: Shodan intelligence lookup failed: ' . $e->getMessage());
        }

        return $intel;
    }

    /**
     * Pulls UpGuard security intelligence for a vendor from the database.
     * Returns score, grade, and risk counts.
     */
    private static function getUpGuardIntelligence(int $vendorOnboardingId): array
    {
        $intel = [
            'available' => false,
            'score' => 0,
            'grade' => '',
            'critical_risks' => 0,
            'high_risks' => 0,
            'medium_risks' => 0,
            'low_risks' => 0,
            'info_risks' => 0,
        ];

        try {
            $db = Database::getInstance();

            $score = $db->fetchOne(
                'SELECT * FROM vendor_srs_scores
                 WHERE vendor_onboarding_id = :vid
                 ORDER BY scored_at DESC LIMIT 1',
                [':vid' => $vendorOnboardingId]
            );

            if (!$score) {
                return $intel;
            }

            $intel['available'] = true;
            $intel['score'] = intval($score['score'] ?? 0);
            $intel['grade'] = $score['score_grade'] ?? '';
            $intel['critical_risks'] = intval($score['critical_risks'] ?? 0);
            $intel['high_risks'] = intval($score['high_risks'] ?? 0);
            $intel['medium_risks'] = intval($score['medium_risks'] ?? 0);
            $intel['low_risks'] = intval($score['low_risks'] ?? 0);
            $intel['info_risks'] = intval($score['info_risks'] ?? 0);

            // Calculate grade from score if not stored
            if (empty($intel['grade'])) {
                if ($intel['score'] >= 850) $intel['grade'] = 'A';
                elseif ($intel['score'] >= 700) $intel['grade'] = 'B';
                elseif ($intel['score'] >= 500) $intel['grade'] = 'C';
                elseif ($intel['score'] >= 300) $intel['grade'] = 'D';
                else $intel['grade'] = 'F';
            }
        } catch (Exception $e) {
            error_log('FairCalculator: UpGuard intelligence lookup failed: ' . $e->getMessage());
        }

        return $intel;
    }

    /**
     * The main calculation engine -- takes decrypted TPRM data and cranks out
     * a full FAIR risk assessment.
     *
     * When $vendorOnboardingId is provided, the calculator enriches the
     * assessment with real Shodan and UpGuard intelligence from the database
     * instead of relying solely on manual form inputs.
     *
     * The 8-step process:
     * 1. Pull security intelligence (Shodan + UpGuard) if vendor ID available
     * 2. Calculate TEF (how often are bad guys knocking on the door?)
     * 3. Calculate Vulnerability (how likely are the defenses to fail?)
     * 4. Calculate LEF (TEF x Vulnerability = how often do breaches happen?)
     * 5. Calculate Primary Loss (direct costs: breach response, fines, etc.)
     * 6. Calculate Secondary Loss (indirect costs: reputation, insurance hikes)
     * 7. Calculate ALE (LEF x Total Loss = annual expected damage in $$$)
     * 8. Apply risk modifiers + revenue cap
     * 9. Determine risk level and recommended insurance coverage
     */
    public static function calculate(array $data, ?int $vendorOnboardingId = null): array
    {
        $defaults = self::getCalculationDefaults();

        // Step 1: Pull real security intelligence if we have a vendor ID
        $shodanIntel = ['available' => false];
        $upguardIntel = ['available' => false];

        if ($vendorOnboardingId) {
            $shodanIntel = self::getShodanIntelligence($vendorOnboardingId);
            $upguardIntel = self::getUpGuardIntelligence($vendorOnboardingId);
        }

        // Initialize all result fields to zero
        $results = [
            'threat_event_frequency' => 0,
            'vulnerability_score' => 0,
            'loss_event_frequency' => 0,
            'primary_loss' => 0,
            'secondary_loss' => 0,
            'loss_magnitude' => 0,
            'annualized_loss_expectancy' => 0,
            'risk_level' => 'Unknown',
            'recommended_liability' => 0,
            'calculations_performed' => true,
            // Auto-calculated financial breakdown fields
            'cost_of_outage' => 0,
            'sec_fines' => 0,
            'compliance_fines' => 0,
            'total_cost_of_breach' => 0,
            'pii_breach_cost' => 0,
            'spii_breach_cost' => 0,
            'sox_breach_cost' => 0,
            // Intelligence metadata (for display/audit)
            'shodan_available' => $shodanIntel['available'],
            'upguard_available' => $upguardIntel['available'],
            'revenue_cap_applied' => false,
            'revenue_cap_max' => 0,
        ];

        // Step 2: Threat Event Frequency
        $results['threat_event_frequency'] = self::calculateThreatEventFrequency($data, $shodanIntel);

        // Step 3: Vulnerability
        $results['vulnerability_score'] = self::calculateVulnerability($data, $shodanIntel, $upguardIntel);

        // Step 4: Loss Event Frequency = TEF x Vulnerability, capped at the real-world
        // ceiling (>1 material loss event/yr for a single vendor is not realistic).
        $results['loss_event_frequency'] = min(
            self::LEF_CEILING,
            $results['threat_event_frequency'] * $results['vulnerability_score']
        );

        // Step 4a: Tier-based LEF reduction
        // Tier 2 vendors have less frequent exposure to high-impact data, so cut LEF by 70%.
        // Tier 3 vendors have minimal exposure, so cut LEF by 90%. Tier 1 is unchanged.
        if ($vendorOnboardingId) {
            try {
                $db = Database::getInstance();
                $tierRow = $db->fetchOne(
                    'SELECT vendor_tier FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorOnboardingId]
                );
                $tier = $tierRow['vendor_tier'] ?? null;
                if ($tier === '2' || $tier === 2) {
                    $results['loss_event_frequency'] *= 0.30;
                } elseif ($tier === '3' || $tier === 3) {
                    $results['loss_event_frequency'] *= 0.10;
                }
            } catch (Exception $e) {
                // If the lookup fails, leave LEF untouched rather than blowing up the calc
            }
        }

        // Step 5: Primary Loss
        $results['primary_loss'] = self::calculatePrimaryLoss($data, $results);

        // Step 6: Secondary Loss
        $results['secondary_loss'] = self::calculateSecondaryLoss($data, $results, $upguardIntel);

        // Total loss if a breach occurs = primary + secondary
        $results['loss_magnitude'] = $results['primary_loss'] + $results['secondary_loss'];

        // Step 7: ALE = LEF x Loss Magnitude
        $results['annualized_loss_expectancy'] = $results['loss_event_frequency'] * $results['loss_magnitude'];

        // Step 8: Apply risk modifiers + revenue cap
        $results = self::applyRiskModifiers($results, $data);
        $results = self::applyRevenueCap($results, $defaults);

        // Step 9: Risk level and insurance recommendation
        $results['risk_level'] = self::determineRiskLevel($results['annualized_loss_expectancy']);
        $results['recommended_liability'] = self::calculateRecommendedLiability($results['annualized_loss_expectancy']);

        return $results;
    }

    /**
     * Calculates how often threat actors attempt attacks against this vendor.
     *
     * Now enhanced with Shodan intelligence:
     * - Known CVEs attract targeted exploitation attempts
     * - Exposed databases and admin panels draw automated scanners
     * - Large attack surfaces (many open ports) invite more probing
     */
    private static function calculateThreatEventFrequency(array $data, array $shodanIntel): float
    {
        $tef = self::TEF_BASELINE;

        // Factor 1: Data classification
        $dataClassification = strtolower($data['data_classification'] ?? '');
        if (stripos($dataClassification, 'critical') !== false || stripos($dataClassification, 'confidential') !== false) {
            $tef *= self::TEF_CRITICAL_DATA;
        } elseif (stripos($dataClassification, 'sensitive') !== false) {
            $tef *= self::TEF_SENSITIVE_DATA;
        } elseif (stripos($dataClassification, 'public') !== false || stripos($dataClassification, 'none') !== false) {
            $tef *= self::TEF_PUBLIC_DATA;
        }

        // Factor 2: Data sharing
        $dataSharing = strtolower($data['data_sharing'] ?? '');
        if (stripos($dataSharing, 'yes') !== false || stripos($dataSharing, 'shared') !== false) {
            $tef *= self::TEF_DATA_SHARING_BOOST;
        }

        // Factor 3: Threat intelligence
        $threatIntel = strtolower($data['threat_intelligence'] ?? '');
        if (stripos($threatIntel, 'high') !== false || stripos($threatIntel, 'active') !== false) {
            $tef *= self::TEF_THREAT_INTEL_HIGH;
        } elseif (stripos($threatIntel, 'medium') !== false || stripos($threatIntel, 'moderate') !== false) {
            $tef *= self::TEF_THREAT_INTEL_MEDIUM;
        }

        // Factor 4: Shodan-driven TEF boosts (additive)
        // Known CVEs attract targeted attacks -- bots scan for these constantly
        if ($shodanIntel['available']) {
            $critCVEs = min($shodanIntel['critical_cves'], self::TEF_CRITICAL_CVE_CAP);
            $highCVEs = min($shodanIntel['high_cves'], self::TEF_HIGH_CVE_CAP);
            $tef += $critCVEs * self::TEF_PER_CRITICAL_CVE;
            $tef += $highCVEs * self::TEF_PER_HIGH_CVE;

            // Exposed infrastructure = automated scanners will find you
            if ($shodanIntel['has_exposed_db']) {
                $tef += self::TEF_EXPOSED_DB;
            }
            if ($shodanIntel['has_exposed_admin']) {
                $tef += self::TEF_EXPOSED_ADMIN;
            }
            if ($shodanIntel['open_ports'] > 10) {
                $tef += self::TEF_MANY_OPEN_PORTS;
            }
        }

        return min(self::TEF_CEILING, $tef);
    }

    /**
     * Calculates the probability that an attack will succeed (0.0 to 1.0).
     *
     * Now enhanced with Shodan signals and UpGuard risk data:
     * - Negative Shodan signals (CVEs, exposed services) increase vulnerability
     * - Positive Shodan signals (WAF, TLS 1.3, HSTS) decrease vulnerability
     * - UpGuard critical/high risks increase vulnerability
     * - UpGuard A-grade scores provide an additional discount
     */
    private static function calculateVulnerability(array $data, array $shodanIntel, array $upguardIntel): float
    {
        $vulnerability = self::VULN_BASELINE;

        // Factor 1: Security Score (the big one)
        $securityScore = strtoupper($data['security_score'] ?? 'C');
        $scoreMultipliers = [
            'A' => self::VULN_GRADE_A,
            'B' => self::VULN_GRADE_B,
            'C' => self::VULN_GRADE_C,
            'D' => self::VULN_GRADE_D,
            'F' => self::VULN_GRADE_F,
        ];
        $vulnerability *= ($scoreMultipliers[$securityScore] ?? self::VULN_GRADE_C);

        // (No separate D/F penalty: the grade multiplier above already encodes posture.
        // The old extra x5 / x15 penalty double-counted the grade and pinned every D/F
        // vendor at the ceiling, discarding every other signal.)

        // Factor 2: ISO 27001 Certification
        if (isset($data['iso_27001_certified']) && $data['iso_27001_certified']) {
            $vulnerability *= self::VULN_ISO_27001_DISCOUNT;
        }

        // Factor 3: Vulnerability Management
        $vulnMgmt = strtolower($data['vulnerability_management'] ?? '');
        if (stripos($vulnMgmt, 'none') !== false || stripos($vulnMgmt, 'poor') !== false) {
            $vulnerability *= self::VULN_NO_VULN_MGMT;
        } elseif (stripos($vulnMgmt, 'excellent') !== false || stripos($vulnMgmt, 'automated') !== false) {
            $vulnerability *= self::VULN_EXCELLENT_VULN_MGMT;
        }

        // Factor 4: Patch Management
        $patchMgmt = strtolower($data['patch_management'] ?? '');
        if (stripos($patchMgmt, 'none') !== false || stripos($patchMgmt, 'poor') !== false) {
            $vulnerability *= self::VULN_NO_PATCHING;
        } elseif (stripos($patchMgmt, 'automated') !== false || stripos($patchMgmt, '24') !== false) {
            $vulnerability *= self::VULN_AUTO_PATCHING;
        }

        // Factor 5: Access Controls
        $accessControls = strtolower($data['access_controls'] ?? '');
        if (stripos($accessControls, 'mfa') !== false || stripos($accessControls, 'multi-factor') !== false) {
            $vulnerability *= self::VULN_MFA_DISCOUNT;
        }
        if (stripos($accessControls, 'least privilege') !== false) {
            $vulnerability *= self::VULN_LEAST_PRIVILEGE;
        }

        // ---------------------------------------------------------------
        // Factor 6: Shodan Signal Adjustments (additive)
        // Real data from actual port scans and vulnerability detection.
        // These adjust the vulnerability score based on what's actually
        // exposed on the internet, not just what the vendor claims.
        // ---------------------------------------------------------------
        if ($shodanIntel['available']) {
            // Negative signals -- things that make attacks more likely to succeed
            $critCVEs = min($shodanIntel['critical_cves'], self::TEF_CRITICAL_CVE_CAP);
            $highCVEs = min($shodanIntel['high_cves'], self::TEF_HIGH_CVE_CAP);
            $vulnerability += $critCVEs * self::VULN_PER_CRITICAL_CVE;
            $vulnerability += $highCVEs * self::VULN_PER_HIGH_CVE;

            if ($shodanIntel['has_tls10'])          $vulnerability += self::VULN_TLS10_ENABLED;
            if ($shodanIntel['has_self_signed'])     $vulnerability += self::VULN_SELF_SIGNED;
            if ($shodanIntel['has_exposed_db'])      $vulnerability += self::VULN_EXPOSED_DB;
            if ($shodanIntel['has_exposed_admin'])   $vulnerability += self::VULN_EXPOSED_ADMIN;
            if ($shodanIntel['has_shared_hosting'])  $vulnerability += self::VULN_SHARED_HOSTING;
            if ($shodanIntel['has_no_dmarc'])        $vulnerability += self::VULN_NO_DMARC;
            if ($shodanIntel['has_no_spf'])          $vulnerability += self::VULN_NO_SPF;

            // Positive signals -- things that make attacks less likely to succeed
            if ($shodanIntel['has_waf'])             $vulnerability += self::VULN_WAF_DISCOUNT;
            if ($shodanIntel['has_tls13'])           $vulnerability += self::VULN_TLS13_DISCOUNT;
            if ($shodanIntel['has_hsts'])            $vulnerability += self::VULN_HSTS_DISCOUNT;
            if ($shodanIntel['has_enterprise_cloud'])$vulnerability += self::VULN_ENTERPRISE_CLOUD;
            if ($shodanIntel['has_no_cves'])         $vulnerability += self::VULN_NO_CVES_DISCOUNT;
            if ($shodanIntel['has_dmarc_reject'])    $vulnerability += self::VULN_DMARC_REJECT;
            if ($shodanIntel['has_spf_hard_fail'])   $vulnerability += self::VULN_SPF_HARD_FAIL;
            if ($shodanIntel['has_std_ports_only'])  $vulnerability += self::VULN_STD_PORTS_ONLY;
        }

        // ---------------------------------------------------------------
        // Factor 7: UpGuard Risk Adjustments (additive)
        // Risk counts from UpGuard's continuous monitoring.
        // ---------------------------------------------------------------
        if ($upguardIntel['available']) {
            $ugCritical = min($upguardIntel['critical_risks'], self::VULN_UG_CRITICAL_CAP);
            $ugHigh = min($upguardIntel['high_risks'], self::VULN_UG_HIGH_CAP);
            $vulnerability += $ugCritical * self::VULN_PER_UG_CRITICAL;
            $vulnerability += $ugHigh * self::VULN_PER_UG_HIGH;

            // Grade-level bonuses/penalties
            $ugGrade = strtoupper($upguardIntel['grade']);
            if ($ugGrade === 'A') {
                $vulnerability += self::VULN_UG_GRADE_A_BONUS;
            } elseif ($ugGrade === 'F') {
                $vulnerability += self::VULN_UG_GRADE_F_PENALTY;
            }
        }

        // Clamp to floor-ceiling range
        return min(self::VULN_CEILING, max(self::VULN_FLOOR, $vulnerability));
    }

    /**
     * Calculates the direct costs if a breach actually occurs (Primary Loss).
     */
    private static function calculatePrimaryLoss(array &$data, array &$results): float
    {
        $primaryLoss = 0;
        $defaults = self::getCalculationDefaults();

        // Cost of Outage
        $businessImpact = self::parseMonetaryValue($data['business_impact'] ?? '0');
        $results['cost_of_outage'] = $businessImpact;
        $primaryLoss += $businessImpact;

        // PII breach costs
        $piiRecords = intval($data['pii_record_count'] ?? 0);
        $results['pii_breach_cost'] = $piiRecords * $defaults['pii_breach_cost_per_record'];
        $primaryLoss += $results['pii_breach_cost'];

        // SPII breach costs
        $spiiRecords = intval($data['spii_record_count'] ?? 0);
        $results['spii_breach_cost'] = $spiiRecords * $defaults['spii_breach_cost_per_record'];
        $primaryLoss += $results['spii_breach_cost'];

        // SOX records trigger compliance fine in secondary loss
        $results['sox_breach_cost'] = 0;

        return $primaryLoss;
    }

    /**
     * Calculates the indirect/secondary costs of a breach.
     *
     * Now enhanced: if UpGuard shows high risk counts (>10 critical+high),
     * the reputational damage estimate escalates from 20% to 30% because
     * a vendor with many known public vulnerabilities will draw more
     * media attention and customer scrutiny after a breach.
     */
    private static function calculateSecondaryLoss(array $data, array &$results, array $upguardIntel): float
    {
        $secondaryLoss = 0;
        $defaults = self::getCalculationDefaults();
        $soxRecords = intval($data['sox_record_count'] ?? 0);

        // SEC Fines: Placeholder
        $results['sec_fines'] = 0;
        $secondaryLoss += $results['sec_fines'];

        // Compliance Fines: SOX
        $results['compliance_fines'] = ($soxRecords > 0) ? $defaults['sox_breach_penalty'] : 0;
        $secondaryLoss += $results['compliance_fines'];

        // Total Cost of Breach summary
        $results['total_cost_of_breach'] = $results['cost_of_outage'] + $results['sec_fines'] + $results['compliance_fines'];

        // Insurance premium increases
        $currentCoverage = self::parseMonetaryValue($data['vendor_cyber_insurance_coverage'] ?? '0');
        $secondaryLoss += $currentCoverage * self::INSURANCE_PREMIUM_MULTIPLIER;

        // Reputational damage -- escalated if UpGuard shows many critical/high risks
        $repDamageRate = self::REPUTATIONAL_DAMAGE_PERCENT;
        if ($upguardIntel['available']) {
            $totalHighRisks = $upguardIntel['critical_risks'] + $upguardIntel['high_risks'];
            if ($totalHighRisks > self::UG_HIGH_RISK_THRESHOLD) {
                $repDamageRate = self::REP_DAMAGE_ESCALATED;
            }
        }
        $reputationalDamage = $results['total_cost_of_breach'] * $repDamageRate;
        $secondaryLoss += $reputationalDamage;

        return $secondaryLoss;
    }

    /**
     * Applies special-case risk modifiers that can override the raw calculations.
     * The big one: if a vendor has NO data sharing AND low business impact,
     * the risk is capped at $1,000. Context matters.
     */
    private static function applyRiskModifiers(array $results, array $data): array
    {
        $originalALE = $results['annualized_loss_expectancy'];

        $dataSharing = strtolower($data['data_sharing'] ?? '');
        $businessImpact = self::parseMonetaryValue($data['business_impact'] ?? '0');
        $soxRecords = intval($data['sox_record_count'] ?? 0);
        $piiRecords = intval($data['pii_record_count'] ?? 0);
        $spiiRecords = intval($data['spii_record_count'] ?? 0);

        $noDataExchange = (stripos($dataSharing, 'no') !== false || empty($dataSharing));
        $noSensitiveRecords = ($soxRecords == 0 && $piiRecords == 0 && $spiiRecords == 0);
        $lowBusinessImpact = ($businessImpact < self::RISK_LOW_MAX);

        if ($noDataExchange && $lowBusinessImpact) {
            $results['annualized_loss_expectancy'] = min($originalALE, self::LOW_RISK_ALE_CAP);
            $results['risk_level'] = 'Very Low';

            if ($noSensitiveRecords) {
                $results['secondary_loss'] = $businessImpact * self::REPUTATIONAL_DAMAGE_PERCENT;
                $results['compliance_fines'] = 0;
                $results['sec_fines'] = 0;
                $results['total_cost_of_breach'] = $results['cost_of_outage'];
                $results['loss_magnitude'] = $results['primary_loss'] + $results['secondary_loss'];
            }
        }

        return $results;
    }

    /**
     * Applies the revenue-based cap to ALE and loss magnitudes.
     *
     * A $5M company at 10% cap means no single vendor's ALE exceeds $500K.
     * Loss magnitudes are also capped at annual revenue -- a $5M company
     * cannot realistically suffer $8B in per-incident losses from one vendor.
     */
    private static function applyRevenueCap(array $results, array $defaults): array
    {
        $annualRevenue = floatval($defaults['fair_annual_revenue'] ?? 0);
        $capPct = floatval($defaults['fair_revenue_cap_pct'] ?? 10);

        if ($annualRevenue > 0 && $capPct > 0) {
            $maxALE = $annualRevenue * ($capPct / 100);
            $results['revenue_cap_max'] = $maxALE;

            if ($results['annualized_loss_expectancy'] > $maxALE) {
                $results['annualized_loss_expectancy'] = $maxALE;
                $results['revenue_cap_applied'] = true;
            }

            // Cap per-incident loss magnitudes at annual revenue
            if ($results['primary_loss'] > $annualRevenue) {
                $results['primary_loss'] = $annualRevenue;
                $results['revenue_cap_applied'] = true;
            }
            if ($results['secondary_loss'] > $annualRevenue) {
                $results['secondary_loss'] = $annualRevenue;
                $results['revenue_cap_applied'] = true;
            }
            $results['loss_magnitude'] = $results['primary_loss'] + $results['secondary_loss'];
        }

        return $results;
    }

    /**
     * Turns an ALE dollar amount into a human-friendly risk level label.
     */
    private static function determineRiskLevel(float $ale): string
    {
        if ($ale < self::RISK_VERY_LOW_MAX) {
            return 'Very Low';
        } elseif ($ale < self::RISK_LOW_MAX) {
            return 'Low';
        } elseif ($ale < self::RISK_MEDIUM_MAX) {
            return 'Medium';
        } elseif ($ale < self::RISK_HIGH_MAX) {
            return 'High';
        } elseif ($ale < self::RISK_VERY_HIGH_MAX) {
            return 'Very High';
        } else {
            return 'Critical';
        }
    }

    /**
     * Recommends cyber liability insurance coverage at 3x the ALE.
     */
    private static function calculateRecommendedLiability(float $ale): float
    {
        return $ale * self::LIABILITY_MULTIPLIER;
    }

    /**
     * Parses a monetary value from a human-written string into a float.
     * Handles: "$1,000", "1000", "$1M", "1.5 million", "$500K", etc.
     */
    private static function parseMonetaryValue(string $value): float
    {
        $cleaned = preg_replace('/[$,\s]/', '', strtolower($value));

        if (stripos($cleaned, 'm') !== false || stripos($cleaned, 'million') !== false) {
            $number = (float) preg_replace('/[^0-9.]/', '', $cleaned);
            return $number * 1000000;
        } elseif (stripos($cleaned, 'k') !== false || stripos($cleaned, 'thousand') !== false) {
            $number = (float) preg_replace('/[^0-9.]/', '', $cleaned);
            return $number * 1000;
        }

        return (float) preg_replace('/[^0-9.]/', '', $cleaned);
    }

    /**
     * Formats the raw calculation results into a display-ready array.
     */
    public static function formatResults(array $results): array
    {
        $formatted = [
            'Threat Event Frequency (TEF)' => number_format($results['threat_event_frequency'], 2) . ' events/year',
            'Vulnerability Score' => number_format($results['vulnerability_score'] * 100, 1) . '%',
            'Loss Event Frequency (LEF)' => number_format($results['loss_event_frequency'], 2) . ' breaches/year',
            'PII Breach Cost' => '$' . number_format($results['pii_breach_cost'] ?? 0, 2),
            'SPII Breach Cost' => '$' . number_format($results['spii_breach_cost'] ?? 0, 2),
            'Cost of Outage' => '$' . number_format($results['cost_of_outage'] ?? 0, 2),
            'SEC Fines' => '$' . number_format($results['sec_fines'] ?? 0, 2),
            'Compliance Fines (SOX)' => '$' . number_format($results['compliance_fines'] ?? 0, 2),
            'Total Cost of Breach' => '$' . number_format($results['total_cost_of_breach'] ?? 0, 2),
            'Primary Loss Magnitude' => '$' . number_format($results['primary_loss'], 2),
            'Secondary Loss Magnitude' => '$' . number_format($results['secondary_loss'], 2),
            'Total Loss Magnitude' => '$' . number_format($results['loss_magnitude'], 2),
            'Annualized Loss Expectancy (ALE)' => '$' . number_format($results['annualized_loss_expectancy'], 2),
            'Risk Level' => $results['risk_level'],
            'Recommended Cyber Liability Coverage' => '$' . number_format($results['recommended_liability'], 2)
        ];

        // Add revenue cap note if it was applied
        if (!empty($results['revenue_cap_applied'])) {
            $formatted['Revenue Cap Applied'] = 'Yes (max $' . number_format($results['revenue_cap_max'], 2) . ')';
        }

        // Note which intelligence sources were used
        $sources = [];
        if (!empty($results['shodan_available'])) $sources[] = 'Shodan';
        if (!empty($results['upguard_available'])) $sources[] = 'UpGuard';
        if (!empty($sources)) {
            $formatted['Security Intelligence Sources'] = implode(', ', $sources);
        }

        return $formatted;
    }

    /**
     * Returns the current calculation defaults (for use by AI analysis and other consumers).
     */
    public static function getDefaults(): array
    {
        return self::getCalculationDefaults();
    }

    /**
     * Returns Shodan intelligence for a vendor (public accessor for API endpoints).
     */
    public static function getShodanIntel(int $vendorOnboardingId): array
    {
        return self::getShodanIntelligence($vendorOnboardingId);
    }

    /**
     * Returns UpGuard intelligence for a vendor (public accessor for API endpoints).
     */
    public static function getUpGuardIntel(int $vendorOnboardingId): array
    {
        return self::getUpGuardIntelligence($vendorOnboardingId);
    }
}
