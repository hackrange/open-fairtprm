<?php
/**
 * PDF Report Generator - The Executive Summary Printer
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This page generates a printer-friendly executive summary of a FAIR analysis
 * result. It renders a nicely formatted HTML page that's designed to look good
 * when you hit Ctrl+P (or Cmd+P for the Mac folks). Not an actual PDF library --
 * we're using the browser's built-in print-to-PDF because why add another
 * dependency when Chrome already does this perfectly?
 *
 * The page can also optionally call an OpenWebUI instance to generate an
 * AI-powered executive summary. Because nothing says "I did thorough analysis"
 * like having an LLM write your summary for you. (Hey, at least we're honest.)
 *
 * Features:
 * - FAIR metrics display (ALE, LEF, loss magnitudes, recommended insurance)
 * - Vendor security profile (score, ISO cert status, scope of work)
 * - AI-generated executive summary via OpenWebUI API
 * - Print-optimized CSS with page breaks in all the right places
 */

require_once 'includes/init.php';

// The standard toolkit -- auth, database, encryption, security
$auth = Auth::getInstance();
$db = Database::getInstance();
$encryption = new Encryption();
$security = Security::getInstance();

// Gotta be logged in to see reports. No freeloaders.
if (!$auth->isAuthenticated()) {
    redirect('login.php');
}

$user = $auth->getUser();

// Which analysis are we looking at? Better be a valid number.
$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    redirect('fair_results.php'); // Nice try with that negative ID, buddy
}

// Pull the analysis record from the database
$result = $db->fetchOne(
    "SELECT * FROM tprm_results WHERE id = :id",
    [':id' => $id]
);

// If it doesn't exist or the user doesn't own it (and isn't admin), back to the results list
if (!$result || ($result['user_id'] != $user['id'] && !$auth->isAdmin())) {
    header('Location: fair_results.php');
    exit;
}

// Decrypt ALL the fields. Some are stored in plaintext, some are encrypted.
// We build a clean $decryptedResult array so the rest of the page doesn't
// have to worry about which fields are which. It's like laundry -- sort first.
$decryptedResult = [];

// Non-encrypted fields (copy directly -- these were too boring to encrypt)
$decryptedResult['vendor_name'] = $result['vendor_name'] ?? '';
$decryptedResult['vendor_domain'] = $result['vendor_domain'] ?? '';
$decryptedResult['security_score'] = $result['security_score'] ?? '';
$decryptedResult['iso_27001_certified'] = $result['iso_27001_certified'] ?? 0;
$decryptedResult['pii_record_count'] = $result['pii_record_count'] ?? 0;
$decryptedResult['spii_record_count'] = $result['spii_record_count'] ?? 0;
$decryptedResult['sox_record_count'] = $result['sox_record_count'] ?? 0;
$decryptedResult['risk_output'] = $result['risk_output'] ?? '';

// Fields that need decryption (including FAIR output fields)
$fieldsToDecrypt = [
    'msa', 'scope_of_work', 'medium_of_data', 'certifications',
    'compliance', 'security_governance', 'incident_response_plan', 'continuous_monitoring',
    'supply_chain_risk_mgmt', 'security_awareness_training', 'vulnerability_management',
    'patch_management', 'access_controls', 'data_encryption', 'network_security',
    'vulnerability_data', 'configuration_data', 'compliance_data', 'risk_assessment',
    'threat_intelligence', 'vendor_risk_assessment',
    'security_questionnaire', 'compliance_questionnaire', 'data_classification',
    'data_sharing', 'business_impact', 'vendor_performance', 'third_party_vendor_list',
    'third_party_risk_assessment', 'third_party_security_questionnaire',
    'third_party_compliance_questionnaire', 'vendor_cyber_insurance_coverage',
    // FAIR output fields - THESE ARE ENCRYPTED!
    'ale', 'loss_event_frequency', 'loss_magnitude',
    'primary_loss_magnitude', 'secondary_loss_magnitude', 'recommended_liability',
    'cost_of_outage', 'sec_fines', 'compliance_fines', 'total_cost_of_breach',
    'pii_breach_cost', 'spii_breach_cost', 'sox_breach_cost'
];

foreach ($fieldsToDecrypt as $field) {
    if (isset($result[$field]) && !empty($result[$field])) {
        $decrypted = $encryption->decrypt($result[$field]);
        $decryptedResult[$field] = $decrypted !== false ? $decrypted : '';
    } else {
        $decryptedResult[$field] = '';
    }
}

// ============================================================================
// AI SUMMARY GENERATOR -- Calls the OpenWebUI API (basically an LLM endpoint)
// to generate a fancy executive summary. The prompt is carefully crafted to
// get concise, HTML-formatted output that fits on one page. If OpenWebUI is
// disabled or the API call fails, we fall back to a generic summary.
// Think of it as the "make me sound smart" button.
// ============================================================================
function generateExecutiveSummary($data) {
    // Load OpenWebUI configuration from app_config
    $db = Database::getInstance();
    $encryption = new Encryption();

    $config = $db->fetchAll(
        'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
        ['openwebui_%']
    );

    $openWebUIConfig = [];
    foreach ($config as $setting) {
        $key = str_replace('openwebui_', '', $setting['config_key']);
        $value = $setting['is_encrypted'] ? $encryption->decrypt($setting['config_value']) : $setting['config_value'];
        $openWebUIConfig[$key] = $value;
    }

    // Check if OpenWebUI is enabled
    if (empty($openWebUIConfig['enabled']) || $openWebUIConfig['enabled'] !== '1') {
        return t('download-pdf.ai_disabled');
    }

    // Validate required settings
    if (empty($openWebUIConfig['api_url']) || empty($openWebUIConfig['jwt_token'])) {
        return t('download-pdf.ai_not_configured');
    }

    $apiUrl = $openWebUIConfig['api_url'];
    $jwtToken = $openWebUIConfig['jwt_token'];
    $model = $openWebUIConfig['model'] ?? 'novita.google/gemma-3-27b-it';
    $temperature = floatval($openWebUIConfig['temperature'] ?? 0.7);
    $maxTokens = intval($openWebUIConfig['max_tokens'] ?? 500);

    $prompt = "Generate a concise executive summary for a Third-Party Risk Management (TPRM) FAIR analysis report.

CRITICAL INSTRUCTIONS:
- Keep total length under 1500 characters to fit on one page
- Use ONLY the exact values provided below - DO NOT perform any calculations or recalculate any numbers
- When citing financial figures, use the EXACT dollar amounts shown in the data
- DO NOT include any meta-information about your response (no character counts, word counts, or notes about the response itself)
- Output ONLY the requested HTML content - no commentary, disclaimers, or explanations about the output

Based on the following data, provide:

1. **Executive Overview** (2-3 sentences summarizing the overall risk level and key findings)
2. **Key Risk Factors** (3-4 bullet points highlighting the most critical risks)
3. **Financial Impact Summary** (1-2 sentences citing the EXACT ALE value of \${$data['ale']})
4. **Recommendations** (3-4 brief, actionable recommendations)

VENDOR DATA (use these EXACT values):
Vendor: {$data['vendor_name']}
Domain: {$data['vendor_domain']}
Risk Level: {$data['risk_output']}
Annual Loss Expectancy (ALE): \${$data['ale']} (use this EXACT value)
Security Score: {$data['security_score']}
ISO 27001 Certified: " . ($data['iso_27001_certified'] ? 'Yes' : 'No') . "
Loss Event Frequency: {$data['loss_event_frequency']} events/year
Primary Loss Magnitude: \${$data['primary_loss_magnitude']}
Secondary Loss Magnitude: \${$data['secondary_loss_magnitude']}

Format the response in clean HTML with <h4> for headers, <p> for paragraphs, and <ul>/<li> for lists. Keep it professional and CONCISE. Output ONLY the HTML content - no character counts, no meta-commentary, no notes about the response.";

    $payload = [
        "model" => $model,
        "messages" => [
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => $temperature,
        "max_tokens" => $maxTokens
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $jwtToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            $content = $result['choices'][0]['message']['content'];

            // Clean up markdown code fences if present
            $content = preg_replace('/```html\s*/i', '', $content);
            $content = preg_replace('/```\s*$/s', '', $content);
            $content = preg_replace('/```/s', '', $content);

            // Remove any <!DOCTYPE html> and <html>/<body> tags if present
            $content = preg_replace('/<\!DOCTYPE[^>]*>/i', '', $content);
            $content = preg_replace('/<\/?html[^>]*>/i', '', $content);
            $content = preg_replace('/<\/?head[^>]*>/i', '', $content);
            $content = preg_replace('/<\/?body[^>]*>/i', '', $content);
            $content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);

            return trim($content);
        }
    }

    // Fallback if API fails
    $safeVendorName = htmlspecialchars($data['vendor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return t('download-pdf.fallback_overview_prefix') . $safeVendorName . t('download-pdf.fallback_overview_suffix');
}

/**
 * Sanitize HTML from AI-generated content.
 * Only allows a safe whitelist of tags and strips everything else.
 * This prevents stored XSS from LLM output while preserving formatting.
 */
function sanitizeExecutiveSummaryHtml($html) {
    if (empty($html)) {
        return '';
    }
    // Allow only safe structural/formatting HTML tags
    $allowed = '<h3><h4><h5><p><ul><ol><li><strong><em><b><i><br>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/<(\w+)\s+[^>]*>/', '<$1>', $html);
    return $html;
}


// Handle Generate Summary button click
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_summary'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('download-pdf.invalid_request');
    } else {
        // Generate executive summary and sanitize HTML before storing
        $executiveSummary = sanitizeExecutiveSummaryHtml(generateExecutiveSummary($decryptedResult));

        // Store it in the database
        try {
            $db->update('tprm_results',
                ['executive_summary' => $executiveSummary],
                'id = :id',
                [':id' => $id]
            );
            $result['executive_summary'] = $executiveSummary;
            $success = t('download-pdf.summary_generated');
        } catch (Exception $e) {
            error_log('Failed to save executive summary: ' . $e->getMessage());
            $error = t('download-pdf.summary_save_failed');
        }
    }
}

// Use existing executive summary from database (or empty if not generated yet)
$executiveSummary = $result['executive_summary'] ?? '';
$csrfToken = $security->generateCSRFToken();

// Check if AI/OpenWebUI is actually configured -- no point showing summary buttons
// if there's no LLM to talk to. We check the same config the generator function uses.
$aiConfigured = false;
try {
    $aiRows = $db->fetchAll(
        'SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ?',
        ['openwebui_%']
    );
    $aiConf = [];
    foreach ($aiRows as $row) {
        $key = str_replace('openwebui_', '', $row['config_key']);
        $aiConf[$key] = $row['is_encrypted'] ? $encryption->decrypt($row['config_value']) : $row['config_value'];
    }
    $aiConfigured = !empty($aiConf['enabled']) && $aiConf['enabled'] === '1'
                 && !empty($aiConf['api_url']) && !empty($aiConf['jwt_token']);
} catch (Exception $e) {
    // Config tables might not exist yet -- no AI for you
}

// Get logo from user theme (falls back to app default, then hardcoded default)
$theme = getUserTheme();
$logoUrl = $theme['logo_url'];

$reportDate = date('F j, Y');
$reportTime = date('g:i A');

// Risk level color coding
$riskColors = [
    'Very Low' => '#28a745',
    'Low' => '#5cb85c',
    'Medium' => '#ffc107',
    'High' => '#ff9800',
    'Very High' => '#ff5722',
    'Critical' => '#dc3545'
];
$riskColor = $riskColors[$decryptedResult['risk_output']] ?? '#666';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('download-pdf.page_title')); ?> - <?php echo e($decryptedResult['vendor_name']); ?></title>
    <style>
        /* Print-specific styles */
        @media print {
            @page {
                size: letter;
                margin: 0.75in 0.5in;
            }

            body {
                margin: 0;
                padding: 0;
            }

            .page-break {
                page-break-after: always;
            }

            .no-print {
                display: none !important;
            }

            .print-only {
                display: block !important;
            }
        }

        /* Screen-only styles */
        @media screen {
            .print-only {
                display: none !important;
            }
        }

        /* General styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            background: #fff;
        }

        .container {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header styles */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            border-bottom: 3px solid #ff6543;
            margin-bottom: 15px;
        }

        .report-header .logo {
            width: 209px;
            height: 39px;
            object-fit: contain;
        }

        .report-header .report-info {
            text-align: right;
        }

        .report-header .report-info h1 {
            font-size: 20pt;
            color: #000000;
            margin-bottom: 5px;
        }

        .report-header .report-info .subtitle {
            font-size: 12pt;
            color: #666;
            font-weight: 600;
        }

        .report-header .report-info p {
            font-size: 9pt;
            color: #999;
            margin-top: 3px;
        }

        /* Vendor title */
        .vendor-title {
            background: #000000;
            color: white;
            padding: 12px 20px;
            margin-bottom: 15px;
            border-radius: 6px;
            text-align: center;
        }

        .vendor-title h2 {
            font-size: 18pt;
            margin-bottom: 5px;
        }

        .vendor-title .subtitle {
            font-size: 10pt;
            opacity: 0.9;
        }

        /* FAIR Results Box */
        .fair-results {
            background: #f8f9fa;
            border: 3px solid #ff6543;
            border-radius: 6px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .fair-results h3 {
            color: #ff6543;
            font-size: 14pt;
            margin-bottom: 12px;
            text-align: center;
            border-bottom: 2px solid #ffc211;
            padding-bottom: 8px;
        }

        .fair-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 12px;
        }

        .fair-metric {
            background: white;
            padding: 10px 12px;
            border-radius: 4px;
            border-left: 4px solid #ffc211;
        }

        .fair-metric .label {
            font-size: 8pt;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .fair-metric .value {
            font-size: 14pt;
            font-weight: bold;
            color: #333;
        }

        .fair-metric .description {
            font-size: 7pt;
            color: #999;
            margin-top: 2px;
            font-style: italic;
        }

        .risk-level-box {
            background: white;
            padding: 12px;
            border-radius: 4px;
            border: 2px solid <?php echo $riskColor; ?>;
            text-align: center;
        }

        .risk-level-box .label {
            font-size: 9pt;
            color: #666;
            margin-bottom: 5px;
        }

        .risk-level-box .value {
            font-size: 20pt;
            font-weight: bold;
            color: <?php echo $riskColor; ?>;
        }

        /* Executive Summary Section */
        .executive-summary {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .executive-summary h3 {
            color: #ff6543;
            font-size: 13pt;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f0f0;
        }

        .executive-summary h4 {
            color: #333;
            font-size: 11pt;
            margin-top: 15px;
            margin-bottom: 8px;
        }

        .executive-summary p {
            margin-bottom: 10px;
            text-align: justify;
            font-size: 10pt;
            line-height: 1.5;
        }

        .executive-summary ul {
            margin-left: 18px;
            margin-bottom: 12px;
        }

        .executive-summary li {
            margin-bottom: 6px;
            font-size: 10pt;
            line-height: 1.5;
        }

        .executive-summary strong {
            font-weight: 600;
        }

        /* Section styles */
        .section {
            margin-bottom: 15px;
        }

        .section-header {
            background: #000000;
            color: white;
            padding: 8px 15px;
            font-size: 10pt;
            font-weight: 600;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .field {
            margin-bottom: 8px;
            padding: 8px 10px;
            background: #f8f9fa;
            border-left: 3px solid #ffc211;
            border-radius: 3px;
        }

        .field-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 3px;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .field-value {
            color: #333;
            font-size: 9pt;
            line-height: 1.4;
        }

        /* Compact vendor profile for page 1 */
        .vendor-profile-compact {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .vendor-profile-compact .field {
            margin-bottom: 0;
        }

        /* Print button */
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 30px;
            background: #ffc211;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14pt;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255, 194, 17, 0.3);
            z-index: 1000;
        }

        .print-button:hover {
            background: #e6ae0f;
        }

        .generate-button {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 12px 24px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12pt;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            z-index: 1000;
        }

        .generate-button:hover {
            background: #218838;
        }

        .alert-message {
            position: fixed;
            top: 260px;
            right: 20px;
            max-width: 300px;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 11pt;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .summary-placeholder {
            background: #fff3cd;
            border: 2px dashed #ffc107;
            padding: 30px;
            text-align: center;
            border-radius: 8px;
            color: #856404;
        }

        .summary-placeholder h4 {
            color: #856404;
            margin-bottom: 10px;
        }

        /* Footer */
        .report-footer {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            font-size: 9pt;
            color: #999;
        }

        .confidential-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            margin: 15px 0 10px 0;
            border-radius: 4px;
            text-align: center;
            font-weight: 600;
            font-size: 9pt;
            color: #856404;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" data-print><?php echo e(t('download-pdf.print_save')); ?></button>

    <a href="fair-analysis.php?id=<?php echo $id; ?>" class="no-print" style="position: fixed; top: 80px; right: 20px; padding: 12px 24px; background: #6c757d; color: white; border: none; border-radius: 6px; font-size: 12pt; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3); z-index: 1000; text-decoration: none; display: inline-block;">
        <?php echo e(t('download-pdf.edit_fair_form')); ?>
    </a>

    <?php if ($aiConfigured): ?>
    <form method="POST" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <button type="submit" name="generate_summary" class="generate-button no-print" style="top: 140px;">
            <?php echo e(empty($executiveSummary) ? t('download-pdf.generate_summary') : t('download-pdf.regenerate_summary')); ?>
        </button>
    </form>
    <?php endif; ?>

    <?php if (isset($success)): ?>
    <div class="alert-message alert-success no-print">
        ✓ <?php echo e($success); ?>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
    <div class="alert-message alert-error no-print">
        ✗ <?php echo e($error); ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <!-- Report Header -->
        <div class="report-header">
            <div>
                <img src="<?php echo e($logoUrl); ?>" alt="Logo" class="logo" data-hide-on-error>
            </div>
            <div class="report-info">
                <h1><?php echo e(t('download-pdf.report_title')); ?></h1>
                <div class="subtitle"><?php echo e(t('download-pdf.executive_summary')); ?></div>
                <p><?php echo e($reportDate); ?> <?php echo e(t('download-pdf.at_time')); ?> <?php echo e($reportTime); ?></p>
            </div>
        </div>

        <!-- Vendor Title -->
        <div class="vendor-title">
            <h2><?php echo e($decryptedResult['vendor_name']); ?></h2>
            <div class="subtitle">
                <?php echo e(t('download-pdf.tprm_assessment')); ?>
                <?php if ($decryptedResult['vendor_domain']): ?>
                    | <?php echo e($decryptedResult['vendor_domain']); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- FAIR Risk Analysis Results (TOP SECTION) -->
        <div class="fair-results">
            <div class="fair-grid">
                <?php if (!empty($decryptedResult['ale'])): ?>
                <div class="fair-metric">
                    <div class="label"><?php echo e(t('download-pdf.ale_label')); ?></div>
                    <div class="value">$<?php echo number_format((float)$decryptedResult['ale'], 2); ?></div>
                    <div class="description"><?php echo e(t('download-pdf.ale_desc')); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($decryptedResult['loss_event_frequency'])): ?>
                <div class="fair-metric">
                    <div class="label"><?php echo e(t('download-pdf.lef_label')); ?></div>
                    <div class="value"><?php echo number_format((float)$decryptedResult['loss_event_frequency'], 2); ?></div>
                    <div class="description"><?php echo e(t('download-pdf.lef_desc')); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($decryptedResult['primary_loss_magnitude'])): ?>
                <div class="fair-metric">
                    <div class="label"><?php echo e(t('download-pdf.primary_loss_label')); ?></div>
                    <div class="value">$<?php echo number_format((float)$decryptedResult['primary_loss_magnitude'], 2); ?></div>
                    <div class="description"><?php echo e(t('download-pdf.primary_loss_desc')); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($decryptedResult['secondary_loss_magnitude'])): ?>
                <div class="fair-metric">
                    <div class="label"><?php echo e(t('download-pdf.secondary_loss_label')); ?></div>
                    <div class="value">$<?php echo number_format((float)$decryptedResult['secondary_loss_magnitude'], 2); ?></div>
                    <div class="description"><?php echo e(t('download-pdf.secondary_loss_desc')); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($decryptedResult['recommended_liability'])): ?>
                <div class="fair-metric" style="border-left-color: #28a745;">
                    <div class="label"><?php echo e(t('download-pdf.recommended_coverage_label')); ?></div>
                    <div class="value" style="color: #28a745;">$<?php echo number_format((float)$decryptedResult['recommended_liability'], 2); ?></div>
                    <div class="description"><?php echo e(t('download-pdf.recommended_coverage_desc')); ?></div>
                </div>
                <?php endif; ?>

                <!-- Risk Level Assessment - right side -->
                <div class="risk-level-box">
                    <div class="label"><?php echo e(t('download-pdf.overall_risk')); ?></div>
                    <div class="value"><?php echo e($decryptedResult['risk_output'] ?: t('download-pdf.not_available')); ?></div>
                </div>
            </div>
        </div>

        <!-- Vendor Security Profile (Compact) -->
        <div class="section">
            <div class="section-header"><?php echo e(t('download-pdf.vendor_profile')); ?></div>
            <div class="vendor-profile-compact">
                <?php if (!empty($decryptedResult['security_score'])): ?>
                <div class="field">
                    <div class="field-label"><?php echo e(t('download-pdf.security_score')); ?></div>
                    <div class="field-value">
                        <?php
                        $scoreColors = ['A' => '#28a745', 'B' => '#5cb85c', 'C' => '#ffc107', 'D' => '#ff9800', 'F' => '#dc3545'];
                        $color = $scoreColors[$decryptedResult['security_score']] ?? '#666';
                        ?>
                        <strong style="color: <?php echo $color; ?>; font-size: 11pt;"><?php echo e(t('download-pdf.grade')); ?> <?php echo e($decryptedResult['security_score']); ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <div class="field">
                    <div class="field-label"><?php echo e(t('download-pdf.iso_certification')); ?></div>
                    <div class="field-value">
                        <?php if ($decryptedResult['iso_27001_certified']): ?>
                            <strong style="color: #28a745; font-size: 10pt;">✓ <?php echo e(t('download-pdf.certified')); ?></strong>
                            <span style="color: #666; font-size: 7pt;"><?php echo e(t('download-pdf.lower_breach_risk')); ?></span>
                        <?php else: ?>
                            <span style="color: #999;"><?php echo e(t('download-pdf.not_certified')); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($decryptedResult['scope_of_work'])): ?>
                <div class="field">
                    <div class="field-label"><?php echo e(t('download-pdf.scope_of_work')); ?></div>
                    <div class="field-value" style="font-size: 8pt; line-height: 1.3;"><?php echo e(substr($decryptedResult['scope_of_work'], 0, 150)); ?><?php echo strlen($decryptedResult['scope_of_work']) > 150 ? '...' : ''; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($aiConfigured || !empty($executiveSummary)): ?>
        <!-- AI-Generated Executive Summary -->
        <div class="page-break"></div>
        <div class="executive-summary">
            <h3><?php echo e(t('download-pdf.executive_summary')); ?></h3>
            <?php if (!empty($executiveSummary)): ?>
                <?php echo sanitizeExecutiveSummaryHtml($executiveSummary); ?>
            <?php else: ?>
                <div class="summary-placeholder no-print">
                    <h4>📝 <?php echo e(t('download-pdf.summary_not_generated')); ?></h4>
                    <p><?php echo e(t('download-pdf.summary_not_generated_hint')); ?></p>
                </div>
                <div style="display: none;" class="print-only">
                    <p><em><?php echo e(t('download-pdf.summary_not_generated_print')); ?></em></p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Report Footer -->
        <div class="report-footer">
            <p><?php echo e(t('download-pdf.generated_on')); ?> <?php echo e($reportDate); ?> <?php echo e(t('download-pdf.at_time')); ?> <?php echo e($reportTime); ?> | <?php echo e(t('download-pdf.confidential_notice')); ?></p>
            <p style="margin-top: 10px; font-size: 8pt;">
                <em><?php echo e(t('download-pdf.methodology_note')); ?></em>
            </p>
        </div>
    </div>

    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Add keyboard shortcut for printing
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>
