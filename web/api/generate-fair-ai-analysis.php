<?php
/**
 * API: Generate AI-Assisted FAIR Analysis via AI Platform
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Called via AJAX from fair-analysis.php when the analyst clicks the
 * "AI-Assisted Analysis" button. Collects ALL available vendor data
 * (Shodan signals, UpGuard risks, financials, certifications) and sends
 * a structured FAIR model prompt to the AI Platform LLM. The AI returns
 * a JSON object with estimated TEF, vulnerability, loss magnitudes, ALE,
 * recommended insurance, risk level, confidence, and reasoning.
 *
 * The analyst can review AI estimates side-by-side with the formula-based
 * calculation and choose to accept or reject.
 */

require_once __DIR__ . '/../includes/init.php';
requireAuth();
requirePermission("analysis.create");

header('Content-Type: application/json');

$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF validation
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['csrf_token']) || !$security->validateCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token', 'csrf_token' => $security->getCSRFToken()]);
    exit;
}

$newCsrfToken = $security->getCSRFToken();

// ============================================================================
// Validate: AI must be enabled in both AI Platform and FAIR settings
// ============================================================================
require_once __DIR__ . '/../includes/classes/AIPlatformService.php';
$ai = AIPlatformService::getInstance();

if (!$ai->isEnabled()) {
    echo json_encode(['error' => 'Analysis service is not configured. Enable it in Admin > Settings.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Load FAIR-specific config
$fairConfigRows = $db->fetchAll(
    'SELECT config_key, config_value FROM app_config WHERE config_key LIKE ?',
    ['fair_%']
);
$fairConfig = [];
foreach ($fairConfigRows as $row) {
    $fairConfig[$row['config_key']] = $row['config_value'];
}

// Also load breach cost defaults
$breachDefaults = $db->fetchAll(
    'SELECT config_key, config_value FROM app_config WHERE config_key IN (?, ?, ?)',
    ['pii_breach_cost_per_record', 'spii_breach_cost_per_record', 'sox_breach_penalty']
);
$breachCosts = [];
foreach ($breachDefaults as $row) {
    $breachCosts[$row['config_key']] = floatval($row['config_value']);
}

if (empty($fairConfig['fair_ai_enabled']) || $fairConfig['fair_ai_enabled'] !== '1') {
    echo json_encode(['error' => 'Assisted FAIR Analysis is not enabled. Enable it in Admin > FAIR Defaults.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// ============================================================================
// Collect vendor data from the form submission
// ============================================================================
$vendorName = trim($input['vendor_name'] ?? '');
$vendorDomain = trim($input['vendor_domain'] ?? '');

if (empty($vendorName)) {
    echo json_encode(['error' => 'Vendor name is required.', 'csrf_token' => $newCsrfToken]);
    exit;
}

// Form field values (already decrypted since they come from the browser form)
$formData = [
    'security_score' => $input['security_score'] ?? 'C',
    'iso_27001_certified' => !empty($input['iso_27001_certified']),
    'pii_record_count' => intval($input['pii_record_count'] ?? 0),
    'spii_record_count' => intval($input['spii_record_count'] ?? 0),
    'sox_record_count' => intval($input['sox_record_count'] ?? 0),
    'data_classification' => $input['data_classification'] ?? '',
    'data_sharing' => $input['data_sharing'] ?? '',
    'business_impact' => $input['business_impact'] ?? '0',
    'vulnerability_management' => $input['vulnerability_management'] ?? '',
    'patch_management' => $input['patch_management'] ?? '',
    'access_controls' => $input['access_controls'] ?? '',
    'threat_intelligence' => $input['threat_intelligence'] ?? '',
    'vendor_cyber_insurance_coverage' => $input['vendor_cyber_insurance_coverage'] ?? '0',
];

// ============================================================================
// Pull security intelligence from database
// ============================================================================
require_once __DIR__ . '/../includes/FairCalculator.php';
require_once __DIR__ . '/../includes/classes/SRSService.php';

$shodanIntel = ['available' => false];
$upguardIntel = ['available' => false];
$vendorOnboardingId = null;

// Find vendor onboarding record
$onboarding = null;
if (!empty($vendorName)) {
    $onboarding = $db->fetchOne(
        "SELECT id FROM vendor_onboarding_requests WHERE vendor_name = :name AND status != 'inactive' ORDER BY updated_at DESC LIMIT 1",
        [':name' => $vendorName]
    );
}
if (!$onboarding && !empty($vendorDomain)) {
    $onboarding = $db->fetchOne(
        "SELECT id FROM vendor_onboarding_requests WHERE vendor_domain = :domain AND status != 'inactive' ORDER BY updated_at DESC LIMIT 1",
        [':domain' => $vendorDomain]
    );
}

if ($onboarding) {
    $vendorOnboardingId = intval($onboarding['id']);
    $shodanIntel = FairCalculator::getShodanIntel($vendorOnboardingId);
    $upguardIntel = FairCalculator::getUpGuardIntel($vendorOnboardingId);
}

// Revenue and cap settings
$annualRevenue = floatval($fairConfig['fair_annual_revenue'] ?? 0);
$revCapPct = floatval($fairConfig['fair_revenue_cap_pct'] ?? 10);
$maxALE = ($annualRevenue > 0 && $revCapPct > 0) ? $annualRevenue * ($revCapPct / 100) : 0;

// ============================================================================
// Build the AI prompt
// ============================================================================
$piiRate = $breachCosts['pii_breach_cost_per_record'] ?? 160;
$spiiRate = $breachCosts['spii_breach_cost_per_record'] ?? 200;
$soxPenalty = $breachCosts['sox_breach_penalty'] ?? 5000000;

$promptParts = [];
$promptParts[] = "VENDOR IDENTIFICATION:";
$promptParts[] = "- Vendor Name: {$vendorName}";
$promptParts[] = "- Vendor Domain: " . ($vendorDomain ?: 'Not specified');
$promptParts[] = "";

$promptParts[] = "ASSESSING ORGANIZATION FINANCIAL PROFILE:";
$promptParts[] = "- Annual Revenue: $" . number_format($annualRevenue, 0);
if ($maxALE > 0) {
    $promptParts[] = "- Maximum ALE Cap: $" . number_format($maxALE, 0) . " ({$revCapPct}% of revenue)";
}
$promptParts[] = "";

$promptParts[] = "SECURITY POSTURE DATA:";
$promptParts[] = "- Combined Security Grade: {$formData['security_score']}";

if ($upguardIntel['available']) {
    $promptParts[] = "- UpGuard Score: {$upguardIntel['score']}/950 (Grade: {$upguardIntel['grade']})";
    $promptParts[] = "  Critical Risks: {$upguardIntel['critical_risks']}, High: {$upguardIntel['high_risks']}, Medium: {$upguardIntel['medium_risks']}, Low: {$upguardIntel['low_risks']}";
}

if ($shodanIntel['available']) {
    $promptParts[] = "- Shodan Score: {$shodanIntel['score']}/100 (Grade: {$shodanIntel['grade']})";
    $promptParts[] = "  Critical CVEs: {$shodanIntel['critical_cves']}, High CVEs: {$shodanIntel['high_cves']}, Medium CVEs: {$shodanIntel['medium_cves']}";
    $promptParts[] = "  Open Ports: {$shodanIntel['open_ports']}";
    $promptParts[] = "  Positive Security Signals: {$shodanIntel['positive_count']}, Negative Signals: {$shodanIntel['negative_count']}";

    // Summarize key signal flags
    $positiveFlags = [];
    $negativeFlags = [];
    if ($shodanIntel['has_waf'])             $positiveFlags[] = 'WAF/CDN';
    if ($shodanIntel['has_tls13'])           $positiveFlags[] = 'TLS 1.3';
    if ($shodanIntel['has_hsts'])            $positiveFlags[] = 'HSTS';
    if ($shodanIntel['has_enterprise_cloud'])$positiveFlags[] = 'Enterprise Cloud';
    if ($shodanIntel['has_no_cves'])         $positiveFlags[] = 'No CVEs';
    if ($shodanIntel['has_dmarc_reject'])    $positiveFlags[] = 'DMARC Reject';
    if ($shodanIntel['has_spf_hard_fail'])   $positiveFlags[] = 'SPF Hard Fail';
    if ($shodanIntel['has_std_ports_only'])  $positiveFlags[] = 'Standard Ports Only';

    if ($shodanIntel['has_tls10'])           $negativeFlags[] = 'TLS 1.0 Enabled';
    if ($shodanIntel['has_self_signed'])     $negativeFlags[] = 'Self-Signed Cert';
    if ($shodanIntel['has_exposed_db'])      $negativeFlags[] = 'Database Port Exposed';
    if ($shodanIntel['has_exposed_admin'])   $negativeFlags[] = 'Admin Panel Exposed';
    if ($shodanIntel['has_shared_hosting'])  $negativeFlags[] = 'Shared/Residential Hosting';
    if ($shodanIntel['has_no_dmarc'])        $negativeFlags[] = 'No DMARC';
    if ($shodanIntel['has_no_spf'])          $negativeFlags[] = 'No SPF';

    if (!empty($positiveFlags)) {
        $promptParts[] = "  Positive Signals: " . implode(', ', $positiveFlags);
    }
    if (!empty($negativeFlags)) {
        $promptParts[] = "  Negative Signals: " . implode(', ', $negativeFlags);
    }
}

$promptParts[] = "";
$promptParts[] = "DATA EXPOSURE:";
$promptParts[] = "- PII Records at Risk: " . number_format($formData['pii_record_count']);
$promptParts[] = "- Sensitive PII Records (SSN/Financial/Health): " . number_format($formData['spii_record_count']);
$promptParts[] = "- SOX-Regulated Records: " . number_format($formData['sox_record_count']);
$promptParts[] = "- Data Classification: " . ($formData['data_classification'] ?: 'Not specified');
$promptParts[] = "- Data Sharing with Vendor: " . ($formData['data_sharing'] ?: 'Not specified');
$promptParts[] = "";

$promptParts[] = "CURRENT CONTROLS:";
$promptParts[] = "- ISO 27001 Certified: " . ($formData['iso_27001_certified'] ? 'Yes' : 'No');
$promptParts[] = "- Vulnerability Management: " . ($formData['vulnerability_management'] ?: 'Not specified');
$promptParts[] = "- Patch Management: " . ($formData['patch_management'] ?: 'Not specified');
$promptParts[] = "- Access Controls: " . ($formData['access_controls'] ?: 'Not specified');
$promptParts[] = "- Cyber Insurance Coverage: $" . $formData['vendor_cyber_insurance_coverage'];
$promptParts[] = "";

$promptParts[] = "FINANCIAL CONTEXT:";
$promptParts[] = "- Business Impact (outage cost): $" . $formData['business_impact'];
$promptParts[] = "- PII breach cost rate: \${$piiRate}/record";
$promptParts[] = "- SPII breach cost rate: \${$spiiRate}/record";
$promptParts[] = "- SOX breach penalty (if SOX records > 0): \${$soxPenalty}";

$vendorData = implode("\n", $promptParts);

// Pre-compute expected loss values so the prompt can include concrete numbers
$businessImpact = floatval($formData['business_impact']);
$expectedPrimary = $businessImpact
    + ($formData['pii_record_count'] * $piiRate)
    + ($formData['spii_record_count'] * $spiiRate);
$expectedSecondary = ($formData['sox_record_count'] > 0 ? $soxPenalty : 0)
    + ($expectedPrimary * 0.10)   // insurance increase ~10% of primary
    + ($expectedPrimary * 0.05);  // reputational damage ~5% of primary

// Determine low-risk cap applicability (mirrors FairCalculator::applyRiskModifiers)
$dataSharing = strtolower($formData['data_sharing'] ?? '');
$noDataExchange = (stripos($dataSharing, 'no') !== false || empty($dataSharing));
$lowBusinessImpact = ($businessImpact < 10000);  // RISK_LOW_MAX
$isLowRiskCapped = ($noDataExchange && $lowBusinessImpact);

// Build the revenue cap instruction
$capInstruction = '';
if ($maxALE > 0) {
    $capInstruction = "IMPORTANT: The assessing organization's annual revenue is \$" . number_format($annualRevenue, 0) .
        ". The ALE MUST NOT exceed {$revCapPct}% of annual revenue (\$" . number_format($maxALE, 0) .
        "). This is a hard cap representing the realistic maximum financial exposure from a single vendor breach.";
}

// Build low-risk cap instruction for the prompt
$lowRiskCapInstruction = '';
if ($isLowRiskCapped) {
    $lowRiskCapInstruction = <<<'LOWCAP'

*** LOW-RISK CAP APPLIES ***
This vendor has NO data sharing and business impact < $10,000.
MANDATORY: primary_loss_magnitude = 0, secondary_loss_magnitude = 0,
annualized_loss_expectancy <= 1000, risk_level = "Very Low".
Security findings (Shodan/UpGuard) affect ONLY TEF and vulnerability_score,
they do NOT create dollar losses when there is no data exposure.
LOWCAP;
}

$prompt = <<<PROMPT
You are a FAIR (Factor Analysis of Information Risk) calculator. Compute vendor breach risk using ONLY the formulas below. Do NOT invent losses — use the provided data and rates exactly.

{$capInstruction}
{$lowRiskCapInstruction}

{$vendorData}

=== CALCULATION RULES (follow exactly) ===

STEP 1 — Primary Loss Magnitude:
  Formula: outage_cost + (PII_records × PII_rate) + (SPII_records × SPII_rate)
  With the data above: \${$businessImpact} + ({$formData['pii_record_count']} × \${$piiRate}) + ({$formData['spii_record_count']} × \${$spiiRate}) = \$EXPECTED_PRIMARY
  Pre-computed expected value: \$PRIMARY_EXPECTED = {$expectedPrimary}
  If PII=0 AND SPII=0 AND outage_cost=0, then primary_loss_magnitude = 0.

STEP 2 — Secondary Loss Magnitude:
  Formula: SOX_fines + insurance_increase + reputational_damage
  - SOX fines = (SOX_records > 0) ? SOX_penalty : 0
  - Insurance increase ≈ 10% of primary loss
  - Reputational damage ≈ 5% of primary loss
  Pre-computed expected value: \$SECONDARY_EXPECTED = {$expectedSecondary}
  If primary_loss = 0 AND SOX_records = 0, then secondary_loss_magnitude = 0.

STEP 3 — TEF & Vulnerability:
  Use security posture data (Shodan, UpGuard, grades, CVEs) to estimate:
  - threat_event_frequency = frequency of MATERIAL threat events/year (serious attempts
    capable of causing a loss, NOT raw scan/probe volume). Typically 0.1–4.0; only a prime,
    exposed target approaches the high end.
  - vulnerability_score = P(a material threat event becomes an actual loss event). This is a
    CONDITIONAL probability, typically 0.02–0.45; even weak vendors rarely exceed ~0.55,
    because most serious attempts still do NOT end in a material breach.
  Security findings affect ONLY these two values. They do NOT add to loss magnitudes.

STEP 4 — LEF & ALE:
  loss_event_frequency = TEF × vulnerability_score
  *** loss_event_frequency MUST be between 0.02 and 1.0. ***
  Real-world breach base rates (Verizon DBIR, Cyentia IRIS, cyber-insurer actuarial data) put a
  single organization's annual probability of a MATERIAL breach at ~0.05–0.15; a poor-posture
  vendor is ~0.3–0.5. An LEF above 1.0 implies more than one material breach per year, every
  year, which is not realistic — if your product exceeds 1.0, set it to 1.0.
  annualized_loss_expectancy = LEF × (primary_loss_magnitude + secondary_loss_magnitude)

STEP 5 — Risk Level (based on ALE):
  ALE < \$1,000 → "Very Low"
  ALE < \$10,000 → "Low"
  ALE < \$50,000 → "Medium"
  ALE < \$250,000 → "High"
  ALE < \$1,000,000 → "Very High"
  ALE >= \$1,000,000 → "Critical"

STEP 6 — Insurance:
  recommended_cyber_insurance = ALE × 3 (range 2–4x)

=== CRITICAL RULES ===
- Security scan findings (CVEs, open ports, missing headers) affect TEF and vulnerability ONLY.
  They NEVER create dollar losses by themselves. Losses come from data exposure and outage costs.
- If data_sharing = "No" or empty AND business_impact < \$10,000: this is a low-risk vendor.
  Cap ALE at \$1,000 and set risk_level = "Very Low".
- Your primary_loss_magnitude and secondary_loss_magnitude MUST be close to the pre-computed
  expected values above. Do not deviate by more than 20% without clear justification.

Respond with ONLY valid JSON, no markdown fences:
{"threat_event_frequency":0.0,"vulnerability_score":0.0,"loss_event_frequency":0.0,"primary_loss_magnitude":0.0,"secondary_loss_magnitude":0.0,"annualized_loss_expectancy":0.0,"recommended_cyber_insurance":0.0,"risk_level":"Low","confidence":"Medium","reasoning":"Brief explanation."}

Constraints: All USD values are positive floats. reasoning is 1-2 sentences max.
PROMPT;

// ============================================================================
// Queue AI job for background processing
// ============================================================================
$messages = [
    ['role' => 'user', 'content' => $prompt]
];

$jobPayload = json_encode([
    'messages' => $messages,
    'ai_options' => [
        'purpose' => 'fair',
        'temperature' => max(0.1, min(0.5, $ai->getTemperature())),
        'max_tokens' => 500,
    ],
    'context' => [
        'max_ale' => $maxALE,
        'is_low_risk_capped' => $isLowRiskCapped,
        'vendor_name' => $vendorName,
    ],
]);

$jobId = $db->insert('ai_job_queue', [
    'job_type' => 'fair_analysis',
    'status' => 'pending',
    'user_id' => $user['id'],
    'request_payload' => $jobPayload,
]);

// Audit log
$auth->audit($user['id'], 'fair_ai_analysis', 'tprm_results', null, [
    'vendor_name' => $vendorName,
    'queued_job_id' => $jobId,
]);

echo json_encode([
    'success' => true,
    'queued' => true,
    'job_id' => $jobId,
    'csrf_token' => $newCsrfToken,
]);
