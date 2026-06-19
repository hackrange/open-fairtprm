<?php
/**
 * FAIR Analysis Detail View - Where Risk Gets Quantified in Cold Hard Cash
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This page renders the full read-only view of a completed FAIR (Factor Analysis
 * of Information Risk) assessment. It decrypts all the sensitive fields (because
 * we encrypt everything at rest like proper paranoid security people), then lays
 * out the vendor info, cybersecurity posture, risk data, SRS inputs, third-party
 * vendor info, cost projections, and the final FAIR calculations (LEF, ALE, loss
 * magnitudes, recommended insurance coverage). The FAIR results section is the
 * money shot -- literally, because it tells you in dollars how much a breach would
 * cost. Also supports printing and PDF download, because someone always asks for
 * "a hard copy" like it's 1998.
 */

// Load framework and require login
require_once 'includes/init.php';
requireAuth();

// Grab our services
$auth = Auth::getInstance();
$db = Database::getInstance();
$encryption = new Encryption();
$user = $auth->getUser();
$theme = getUserTheme();

// Which analysis are we looking at?
$analysisId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$acl = ACL::getInstance();
$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isProcurement = $acl->hasGroup('procurement');
$isAuditor = $acl->hasGroup('auditor');

// SECURITY (IDOR): FAIR analyses are per-user owned -- the list page (fair_results.php)
// scopes every role to its own user_id. Only the oversight roles (administrator /
// cyber_tprm) may open another user's analysis by id; procurement, auditor, and all
// other roles are limited to their own, matching the list. This closes the cross-user
// FAIR-analysis disclosure (procurement/auditor reading any analysis by id).
if ($isAdmin || $isCyberTPRM) {
    $analysis = $db->fetchOne(
        'SELECT * FROM tprm_results WHERE id = :id',
        [':id' => $analysisId]
    );
} else {
    $analysis = $db->fetchOne(
        'SELECT * FROM tprm_results WHERE id = :id AND user_id = :user_id',
        [':id' => $analysisId, ':user_id' => $user['id']]
    );
}

if (!$analysis) {
    redirect('fair_results.php');
}

// Decrypt all the encrypted fields. Most of the analysis data is stored encrypted
// at rest because it contains sensitive vendor security information. The whitelist
// below is the stuff that's stored in plain text (IDs, names, scores, dates, etc.).
// Everything else gets run through the decryptor. If you add new unencrypted fields
// to tprm_results, add them to this whitelist or you'll get garbled nonsense.
foreach ($analysis as $key => $value) {
    if (in_array($key, ['id', 'user_id', 'vendor_name', 'vendor_domain', 'security_score', 'securityscorecard_rating', 'iso_27001_certified', 'pii_record_count', 'spii_record_count', 'sox_record_count', 'risk_output', 'executive_summary', 'status', 'completed_at', 'created_at', 'updated_at'])) {
        continue;
    }
    if (!empty($value)) {
        $analysis[$key] = $encryption->decrypt($value);
    }
}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>View Analysis - <?php echo e($analysis['vendor_name']); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
        }

        /* Remove yellow background from navbar pseudo-element */
        nav.rd-navbar.rd-navbar-modern.rd-navbar-modern-1::before,
        nav.rd-navbar.rd-navbar-modern.rd-navbar-static::before {
            display: none !important;
        }
        .view-container {
            padding: 40px 0;
        }
        .section-header {
            
            color: white;
            padding: 15px 25px;
            border-radius: 8px 8px 0 0;
            margin-top: 25px;
        }
        .section-header:first-of-type {
            margin-top: 0;
        }
        .section-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 500;
        }
        .section-content {
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 25px;
        }
        .field-row {
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .field-row:last-child {
            border-bottom: none;
        }
        .field-label {
            font-weight: 500;
            color: #666;
            margin-bottom: 8px;
        }
        .field-value {
            color: #333;
            white-space: pre-wrap;
        }
        .empty-value {
            color: #999;
            font-style: italic;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            display: inline-block;
        }
        .status-draft {
            background: #fff3cd;
            color: #856404;
        }
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            font-weight: 500;
        }
        .btn-primary {
            background: #ffc211;
            
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .print-btn {
            background: #007bff;
            color: white;
        }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }
        @media print {
            .no-print {
                display: none;
            }
            .section-header {
                background: #ff6543 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
        }
        .user-menu a {
            color: #333;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            background: rgba(255,101,67,0.1);
            transition: background 0.2s;
        }
        .user-menu a:hover {
            background: rgba(255,101,67,0.2);
        }
        .rd-navbar-brand {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            background-color: var(--theme-header-color);
            padding: 15px 20px;
            border-radius: 8px;
        }
        .rd-navbar-brand a,
        .rd-navbar-brand div {
            color: white !important;
        }
    </style>
</head>
<body style="background: #fff;">
    <?php renderImpersonationBanner(); ?>
    <div class="page" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
        <header class="section page-header no-print">
            <div class="rd-navbar-wrap">
                <nav class="rd-navbar rd-navbar-modern rd-navbar-modern-1">
                    <div class="rd-navbar-main-outer">
                        <div class="rd-navbar-main">
                            <div class="rd-navbar-panel">
                                <div class="rd-navbar-brand">
                                    <a class="brand" href="index.php">
                                        <img class="brand-logo-dark" src="app/images/logo-default-418x78.png" alt="" width="209" height="39"/>
                                    </a>
                                    <div style="margin-top: 5px; color: #333; font-size: 16px; font-weight: 500;">
                                        <?php echo e(t('view-result.view_analysis')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: #333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('view-result.dashboard')); ?></a>
                                <?php if ($auth->isAdmin()): ?>
                                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                                <?php endif; ?>
                                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <div class="view-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;" class="no-print">
                    <div>
                        <h1 class="page-title" style="margin: 0;"><?php echo e(t('view-result.fair_analysis')); ?></h1>
                        <p style="color: #666; margin: 5px 0 0 0;"><?php echo e(t('view-result.vendor')); ?> <?php echo e($analysis['vendor_name']); ?></p>
                    </div>
                    <div>
                        <a href="fair-analysis.php?id=<?php echo $analysisId; ?>" class="btn btn-primary"><?php echo e(t('view-result.edit')); ?></a>
                        <a href="download-pdf.php?id=<?php echo $analysisId; ?>" class="btn btn-primary" style="background: #28a745;" target="_blank">📄 <?php echo e(t('view-result.download_pdf')); ?></a>
                        <button type="button" class="btn print-btn" data-print><?php echo e(t('view-result.print')); ?></button>
                        <a href="fair_results.php" class="btn btn-secondary"><?php echo e(t('view-result.back_to_results')); ?></a>
                    </div>
                </div>

                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?php echo e(t('view-result.status')); ?></strong>
                            <span class="status-badge status-<?php echo e($analysis['status']); ?>">
                                <?php echo ucfirst($analysis['status']); ?>
                            </span>
                        </div>
                        <div>
                            <strong><?php echo e(t('view-result.created')); ?></strong> <?php echo date('M d, Y H:i', strtotime($analysis['created_at'])); ?>
                        </div>
                        <div>
                            <strong><?php echo e(t('view-result.last_updated')); ?></strong> <?php echo date('M d, Y H:i', strtotime($analysis['updated_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Vendor Information Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.vendor_information')); ?></h3>
                </div>
                <div class="section-content">
                    <div class="field-row">
                        <div class="field-label"><?php echo e(t('view-result.vendor_name')); ?></div>
                        <div class="field-value"><?php echo e($analysis['vendor_name'] ?? 'N/A'); ?></div>
                    </div>
                    <?php if (!empty($analysis['vendor_domain'])): ?>
                    <div class="field-row">
                        <div class="field-label"><?php echo e(t('view-result.vendor_domain')); ?></div>
                        <div class="field-value"><?php echo e($analysis['vendor_domain']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Cyber Security Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.cyber_security')); ?></h3>
                </div>
                <div class="section-content">
                    <?php
                    $csFields = [
                        'msa' => 'MSA (Master Services Agreement)',
                        'scope_of_work' => 'Scope of Work',
                        'medium_of_data' => 'Medium of Data',
                        'certifications' => 'Certifications',
                        'compliance' => 'Compliance',
                        'security_governance' => 'Security Governance',
                        'incident_response_plan' => 'Incident Response Plan',
                        'continuous_monitoring' => 'Continuous Monitoring',
                        'supply_chain_risk_mgmt' => 'Supply Chain Risk Management',
                        'security_awareness_training' => 'Security Awareness Training',
                        'vulnerability_management' => 'Vulnerability Management',
                        'patch_management' => 'Patch Management',
                        'access_controls' => 'Access Controls',
                        'data_encryption' => 'Data Encryption',
                        'network_security' => 'Network Security',
                        'daily_impact' => 'Daily Impact'
                    ];

                    foreach ($csFields as $field => $label):
                        $value = $analysis[$field] ?? '';
                    ?>
                        <div class="field-row">
                            <div class="field-label"><?php echo e($label); ?></div>
                            <div class="field-value <?php echo empty($value) ? 'empty-value' : ''; ?>">
                                <?php echo !empty($value) ? e($value) : e(t('view-result.no_data_provided')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Vendor Risk Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.vendor_risk')); ?></h3>
                </div>
                <div class="section-content">
                    <!-- Security Score (Not Encrypted) -->
                    <div class="field-row">
                        <div class="field-label"><?php echo e(t('view-result.security_score')); ?></div>
                        <div class="field-value <?php echo empty($analysis['security_score']) ? 'empty-value' : ''; ?>">
                            <?php
                            if (!empty($analysis['security_score'])) {
                                $scoreColors = ['A' => '#28a745', 'B' => '#5cb85c', 'C' => '#ffc107', 'D' => '#ff9800', 'F' => '#dc3545'];
                                $color = $scoreColors[$analysis['security_score']] ?? '#666';
                                $riskNote = '';
                                if ($analysis['security_score'] === 'D') {
                                    $riskNote = ' <span style="color: #dc3545;">(5× higher risk)</span>';
                                } elseif ($analysis['security_score'] === 'F') {
                                    $riskNote = ' <span style="color: #dc3545;">(15× higher risk)</span>';
                                }
                                echo '<span style="font-weight: bold; color: ' . $color . ';">Grade ' . e($analysis['security_score']) . '</span>' . $riskNote;
                            } else {
                                echo 'No data provided';
                            }
                            ?>
                        </div>
                    </div>

                    <?php
                    $vrFields = [
                        'vulnerability_data' => 'Vulnerability Data',
                        'configuration_data' => 'Configuration Data',
                        'compliance_data' => 'Compliance Data',
                        'risk_assessment' => 'Risk Assessment',
                        'threat_intelligence' => 'Threat Intelligence'
                    ];

                    foreach ($vrFields as $field => $label):
                        $value = $analysis[$field] ?? '';
                    ?>
                        <div class="field-row">
                            <div class="field-label"><?php echo e($label); ?></div>
                            <div class="field-value <?php echo empty($value) ? 'empty-value' : ''; ?>">
                                <?php echo !empty($value) ? e($value) : e(t('view-result.no_data_provided')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- ISO 27001 Certification -->
                    <div class="field-row">
                        <div class="field-label"><?php echo e(t('view-result.iso_27001_certification')); ?></div>
                        <div class="field-value">
                            <?php
                            if (isset($analysis['iso_27001_certified']) && $analysis['iso_27001_certified']) {
                                echo '<span style="color: #28a745; font-weight: bold;">✓ Certified</span> <span style="color: #666;">(35-55% lower breach risk)</span>';
                            } else {
                                echo '<span style="color: #666;">Not Certified</span>';
                            }
                            ?>
                        </div>
                    </div>

                </div>

                <!-- SRS Input Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.srs_input')); ?></h3>
                </div>
                <div class="section-content">
                    <?php
                    $srsFields = [
                        'vendor_risk_assessment' => 'Vendor Risk Assessment',
                        'security_questionnaire' => 'Security Questionnaire',
                        'compliance_questionnaire' => 'Compliance Questionnaire',
                        'data_classification' => 'Data Classification',
                        'data_sharing' => 'Data Sharing',
                        'business_impact' => 'Business Impact',
                        'vendor_performance' => 'Vendor Performance'
                    ];

                    foreach ($srsFields as $field => $label):
                        $value = $analysis[$field] ?? '';
                    ?>
                        <div class="field-row">
                            <div class="field-label"><?php echo e($label); ?></div>
                            <div class="field-value <?php echo empty($value) ? 'empty-value' : ''; ?>">
                                <?php echo !empty($value) ? e($value) : e(t('view-result.no_data_provided')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Vendor's Third-Party Vendors Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.third_party_vendors')); ?></h3>
                </div>
                <div class="section-content">
                    <?php
                    $tpFields = [
                        'third_party_vendor_list' => 'Third-Party Vendor List',
                        'third_party_risk_assessment' => 'Third-Party Vendor Risk Assessment',
                        'third_party_security_questionnaire' => 'Third-Party Vendor Security Questionnaire',
                        'third_party_compliance_questionnaire' => 'Third-Party Vendor Compliance Questionnaire'
                    ];

                    foreach ($tpFields as $field => $label):
                        $value = $analysis[$field] ?? '';
                    ?>
                        <div class="field-row">
                            <div class="field-label"><?php echo e($label); ?></div>
                            <div class="field-value <?php echo empty($value) ? 'empty-value' : ''; ?>">
                                <?php echo !empty($value) ? e($value) : e(t('view-result.no_data_provided')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Cost and Financial Information Section -->
                <div class="section-header">
                    <h3><?php echo e(t('view-result.cost_financial_info')); ?></h3>
                </div>
                <div class="section-content">
                    <?php
                    $costFields = [
                        'cost_of_breach' => 'Cost of Breach',
                        'cost_of_outage' => 'Cost of Outage',
                        'sec_fines' => 'SEC Fines',
                        'compliance_fines' => 'Compliance Fines',
                        'insurance_premiums' => 'Insurance Premiums'
                    ];

                    foreach ($costFields as $field => $label):
                        $value = $analysis[$field] ?? '';
                    ?>
                        <div class="field-row">
                            <div class="field-label"><?php echo e($label); ?></div>
                            <div class="field-value <?php echo empty($value) ? 'empty-value' : ''; ?>">
                                <?php echo !empty($value) ? e($value) : e(t('view-result.no_data_provided')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- FAIR Analysis Results Section -->
                <?php if (!empty($analysis['ale']) || !empty($analysis['loss_event_frequency'])): ?>
                    <div class="section-header" style="margin-top: 30px; background: linear-gradient(135deg, #ff6543 0%, #ffc211 100%); color: white; padding: 15px 20px; border-radius: 8px;">
                        <h3 style="margin: 0; color: white;">📊 <?php echo e(t('view-result.fair_results_title')); ?></h3>
                        <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;"><?php echo e(t('view-result.fair_results_subtitle')); ?></p>
                    </div>
                    <div class="section-content" style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-top: 10px;">
                        <?php
                        $fairFields = [
                            'loss_event_frequency' => ['Loss Event Frequency (LEF)', 'Expected number of successful attacks per year'],
                            'loss_magnitude' => ['Total Loss Magnitude', 'Expected financial impact per security event'],
                            'primary_loss_magnitude' => ['Primary Loss Magnitude', 'Direct costs (response, recovery, replacement)'],
                            'secondary_loss_magnitude' => ['Secondary Loss Magnitude', 'Indirect costs (fines, legal, reputation, insurance)'],
                            'ale' => ['Annualized Loss Expectancy (ALE)', 'Expected annual financial loss from cyber risk'],
                            'recommended_liability' => ['Recommended Cyber Insurance Coverage', 'Suggested minimum liability coverage (3× ALE)'],
                            'risk_output' => ['Overall Risk Level', 'Risk classification based on ALE']
                        ];

                        foreach ($fairFields as $field => [$label, $description]):
                            $value = $analysis[$field] ?? '';
                            if (empty($value)) continue;

                            // Format numeric values as currency
                            $displayValue = $value;
                            if (is_numeric($value)) {
                                if ($field === 'loss_event_frequency') {
                                    $displayValue = number_format((float)$value, 2) . ' events/year';
                                } else {
                                    $displayValue = '$' . number_format((float)$value, 2);
                                }
                            }

                            // Color code the risk level
                            $riskColors = [
                                'Very Low' => '#28a745',
                                'Low' => '#5cb85c',
                                'Medium' => '#ffc107',
                                'High' => '#ff9800',
                                'Very High' => '#ff5722',
                                'Critical' => '#dc3545'
                            ];
                            $color = $field === 'risk_output' ? ($riskColors[$value] ?? '#666') : '#333';
                        ?>
                            <div class="field-row" style="border-left: 4px solid #ff6543; padding-left: 15px; margin-bottom: 20px;">
                                <div class="field-label" style="font-weight: bold; color: #ff6543; margin-bottom: 3px;">
                                    <?php echo e($label); ?>
                                </div>
                                <div class="field-value" style="font-size: 18px; font-weight: bold; color: <?php echo $color; ?>; margin-bottom: 5px;">
                                    <?php echo e($displayValue); ?>
                                </div>
                                <div style="font-size: 12px; color: #666; font-style: italic;">
                                    <?php echo e($description); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 25px; padding: 15px; background: white; border-radius: 6px; border: 2px solid #ff6543;">
                            <h4 style="margin: 0 0 10px 0; color: #ff6543;">💡 <?php echo e(t('view-result.fair_methodology')); ?></h4>
                            <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #666;">
                                <strong>Risk = Loss Event Frequency (LEF) × Loss Magnitude (LM)</strong><br>
                                • LEF = Threat Event Frequency × Vulnerability (probability of successful attack)<br>
                                • LM = Primary Loss + Secondary Loss<br>
                                • ALE (Annualized Loss Expectancy) represents the expected annual financial impact<br>
                                • Risk modifiers applied based on ISO 27001 certification and SecurityScorecard rating<br>
                                <em>Reference: <a href="https://www.fairinstitute.org/" target="_blank" style="color: #ff6543;">FAIR Institute</a></em>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 30px;" class="no-print">
                    <a href="fair_results.php" style="color: #ff6543; text-decoration: none;">&larr; <?php echo e(t('view-result.back_to_results')); ?></a>
                </div>
            </div>
        </div>

        <footer class="section footer-modern bg-gray-13 no-print">
            <div class="footer-modern-body section-lg">
                <div class="container">
                    <div class="row">
                        <div class="col-md-12 text-center">
                            <p class="rights">
                                <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                                <span class="copyright-year"><?php echo date('Y'); ?></span>
                                <span>.&nbsp;</span>
                                <span><?php echo e(t('view-result.all_rights_reserved')); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
