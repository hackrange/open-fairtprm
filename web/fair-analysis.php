<?php
/**
 * FAIR Analysis Form - The Big Kahuna Questionnaire
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the main FAIR (Factor Analysis of Information Risk) assessment form.
 * It's basically a giant questionnaire where you fill in vendor details, data
 * classification info, security control assessments, and the system crunches
 * the numbers to give you an Annualized Loss Expectancy (ALE). It's like doing
 * your taxes, but for cybersecurity risk, and somehow equally painful.
 *
 * This file does a LOT of heavy lifting:
 * - Create new analyses from scratch
 * - Edit existing draft analyses
 * - Pre-populate from vendor onboarding requests (with ?from_onboarding=ID)
 * - Auto-fill fields from completed vendor assessments (ISO 27001, Tier 2)
 * - Map assessment questionnaire responses to FAIR analysis fields
 * - Calculate FAIR metrics on form submission via FairCalculator
 * - Encrypt all sensitive fields before storing in the database
 * - Handle both "Save Draft" and "Submit & Calculate" workflows
 *
 * The pre-population logic alone is like 300 lines because we map data from
 * onboarding requests, SRS scores, ISO certificates, and Tier 2 questionnaire
 * responses into the appropriate FAIR fields. It's a beautiful Rube Goldberg
 * machine of data transformation.
 */

require_once 'includes/init.php';
require_once 'includes/FairCalculator.php';
require_once 'includes/classes/SRSService.php';
requireAuth();

// All the usual suspects -- singletons, helpers, and encrypted stuff
$auth = Auth::getInstance();
$db = Database::getInstance();
$security = Security::getInstance();
$encryption = new Encryption();
$user = $auth->getUser();
$theme = getUserTheme();

// Check if the SRS (Security Rating Service via UpGuard) integration is available.
// If it is, we can auto-populate security scores from vendor SRS data. Pretty slick.
$srsService = new SRSService();
$srsAvailable = $srsService->isAvailable();

// Load the admin-configured breach cost defaults from app_config.
// These numbers feed into the FAIR calculator -- they're the cost-per-record
// figures for PII breaches, SPII breaches, and SOX penalties.
// The defaults here are industry averages; admins can tweak them in the admin panel.
$calculationDefaultsRows = $db->fetchAll(
    'SELECT config_key, config_value FROM app_config WHERE config_key IN (?, ?, ?)',
    ['pii_breach_cost_per_record', 'spii_breach_cost_per_record', 'sox_breach_penalty']
);
$calculationDefaults = [
    'pii_breach_cost_per_record' => 160,    // IBM says it's actually higher now, but hey
    'spii_breach_cost_per_record' => 200,   // Sensitive PII costs extra. Of course it does.
    'sox_breach_penalty' => 5000000         // SOX violations: where the real money disappears
];
foreach ($calculationDefaultsRows as $row) {
    $calculationDefaults[$row['config_key']] = floatval($row['config_value']);
}

// Check if AI-assisted FAIR analysis is available (supports OpenWebUI or LibreChat)
$fairAiAvailable = false;
try {
    $fairAiRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'fair_ai_enabled'");
    $fairAiAvailable = (AIPlatformService::getInstance()->isEnabled() && ($fairAiRow['config_value'] ?? '0') === '1');
} catch (Exception $e) {
    // AI features are optional
}

// State variables for the form
$error = '';
$success = '';
$editMode = false;
$analysisId = isset($_GET['id']) ? intval($_GET['id']) : 0;           // Editing an existing analysis?
$onboardingId = isset($_GET['from_onboarding']) ? intval($_GET['from_onboarding']) : 0;  // Pre-populate from onboarding?
$analysis = null;
$onboardingSource = null;

// ============================================================================
// PRE-POPULATE FROM VENDOR ONBOARDING -- When someone creates a FAIR analysis
// from the vendor onboarding page (via ?from_onboarding=ID), we pull in as
// much data as we can to save the analyst from retyping everything. This
// includes vendor name, domain, record counts, SRS scores, ISO certificate
// status, and even questionnaire responses from completed assessments.
// It's basically copy-paste but automated. You're welcome, analysts.
// ============================================================================
if ($onboardingId > 0 && !$analysisId) {
    $acl = ACL::getInstance();
    $canCreateFromOnboarding = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

    if ($canCreateFromOnboarding) {
        $onboardingSource = $db->fetchOne(
            'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
            [':id' => $onboardingId]
        );

        if ($onboardingSource) {
            // Map onboarding fields to FAIR analysis fields
            $analysis = [
                'vendor_name' => $onboardingSource['vendor_name'] ?? '',
                'vendor_domain' => $onboardingSource['vendor_domain'] ?? '',
                'pii_record_count' => $onboardingSource['pii_record_count'] ?? 0,
                'spii_record_count' => $onboardingSource['spii_record_count'] ?? 0,
                'sox_record_count' => $onboardingSource['sox_record_count'] ?? 0,
                'business_impact' => $onboardingSource['business_impact'] ?? '',
                'scope_of_work' => $onboardingSource['product_service_description'] ?? '',
            ];

            // Pre-populate security score grade from combined score if available
            $combined = $srsService->getCombinedScore($onboardingSource);
            if ($combined['grade']) {
                $analysis['security_score'] = $combined['grade'];
            }

            // Build additional context from onboarding answers
            $additionalContext = [];
            if (!empty($onboardingSource['confidential_info_shared']) && $onboardingSource['confidential_info_shared'] === 'yes') {
                $additionalContext[] = "Confidential Info Shared: Yes" .
                    (!empty($onboardingSource['confidential_info_justification']) ? " - " . $onboardingSource['confidential_info_justification'] : '');
            }
            if (!empty($onboardingSource['cross_border_transfer']) && $onboardingSource['cross_border_transfer'] === 'yes') {
                $additionalContext[] = "Cross-Border Data Transfer: Yes" .
                    (!empty($onboardingSource['cross_border_justification']) ? " - " . $onboardingSource['cross_border_justification'] : '');
            }
            if (!empty($onboardingSource['offsite_data_hosting']) && $onboardingSource['offsite_data_hosting'] === 'yes') {
                $additionalContext[] = "Off-site Data Hosting: Yes" .
                    (!empty($onboardingSource['offsite_data_justification']) ? " - " . $onboardingSource['offsite_data_justification'] : '');
            }
            if (!empty($onboardingSource['remote_network_access']) && $onboardingSource['remote_network_access'] === 'yes') {
                $additionalContext[] = "Remote Network Access: Yes" .
                    (!empty($onboardingSource['remote_access_justification']) ? " - " . $onboardingSource['remote_access_justification'] : '');
            }
            if (!empty($onboardingSource['critical_business_function']) && $onboardingSource['critical_business_function'] === 'yes') {
                $additionalContext[] = "Critical Business Function: Yes" .
                    (!empty($onboardingSource['critical_function_justification']) ? " - " . $onboardingSource['critical_function_justification'] : '');
            }
            if (!empty($onboardingSource['is_saas']) && $onboardingSource['is_saas'] === 'yes') {
                $additionalContext[] = "SaaS Product: Yes";
            }

            // Add impact assessments
            if (!empty($onboardingSource['unauthorized_disclosure_impact'])) {
                $additionalContext[] = "Unauthorized Disclosure Impact: " . ucfirst($onboardingSource['unauthorized_disclosure_impact']);
            }
            if (!empty($onboardingSource['unauthorized_modification_impact'])) {
                $additionalContext[] = "Unauthorized Modification Impact: " . ucfirst($onboardingSource['unauthorized_modification_impact']);
            }
            if (!empty($onboardingSource['disruption_impact'])) {
                $additionalContext[] = "Disruption Impact: " . ucfirst($onboardingSource['disruption_impact']);
            }

            if (!empty($additionalContext)) {
                $analysis['vendor_risk_assessment'] = "From Vendor Onboarding Request #" . $onboardingId . ":\n\n" . implode("\n", $additionalContext);
            }

            if (!empty($onboardingSource['additional_information'])) {
                $analysis['vendor_risk_assessment'] = ($analysis['vendor_risk_assessment'] ?? '') .
                    "\n\nAdditional Information:\n" . $onboardingSource['additional_information'];
            }

            // Check for completed vendor assessments with certificates
            $completedAssessment = $db->fetchOne(
                "SELECT va.*, t.slug as template_slug, t.name as template_name
                 FROM vendor_assessments va
                 JOIN assessment_templates t ON va.template_id = t.id
                 WHERE va.vendor_request_id = :vendor_id
                   AND va.status = 'completed'
                 ORDER BY va.completed_at DESC
                 LIMIT 1",
                [':vendor_id' => $onboardingId]
            );

            if ($completedAssessment) {
                // Check if it's an ISO 27001 assessment with certificate uploaded
                if ($completedAssessment['template_slug'] === 'iso-27001-2022' && $completedAssessment['certificate_uploaded']) {
                    $certExpiry = $completedAssessment['certificate_expiry']
                        ? ' (Expires: ' . date('M j, Y', strtotime($completedAssessment['certificate_expiry'])) . ')'
                        : '';
                    $isoValue = 'Valid ISO 27001:2022 Certificate' . $certExpiry;

                    // Check the ISO 27001 Certified checkbox (#7 in Vendor Risk section)
                    $analysis['iso_27001_certified'] = 1;

                    // Populate questions 4-15 with ISO certification info
                    $analysis['certifications'] = $isoValue;
                    $analysis['compliance'] = $isoValue;
                    $analysis['security_governance'] = $isoValue;
                    $analysis['incident_response_plan'] = $isoValue;
                    $analysis['continuous_monitoring'] = $isoValue;
                    $analysis['supply_chain_risk_mgmt'] = $isoValue;
                    $analysis['security_awareness_training'] = $isoValue;
                    $analysis['vulnerability_management'] = $isoValue;
                    $analysis['patch_management'] = $isoValue;
                    $analysis['access_controls'] = $isoValue;
                    $analysis['data_encryption'] = $isoValue;
                    $analysis['network_security'] = $isoValue;
                } elseif ($completedAssessment['status'] === 'completed' && !$completedAssessment['certificate_uploaded']) {
                    // Assessment was completed via questionnaire - fetch responses
                    $assessmentResponses = $db->fetchAll(
                        "SELECT q.question_text, r.response_value, s.name as section_name
                         FROM vendor_assessment_responses r
                         JOIN assessment_questions q ON r.question_id = q.id
                         JOIN assessment_sections s ON q.section_id = s.id
                         WHERE r.assessment_id = :assessment_id
                         ORDER BY s.sort_order, q.sort_order",
                        [':assessment_id' => $completedAssessment['id']]
                    );

                    if ($assessmentResponses) {
                        // Build summary from assessment responses by section
                        $sectionSummaries = [];
                        foreach ($assessmentResponses as $resp) {
                            if (!empty($resp['response_value'])) {
                                $sectionSummaries[$resp['section_name']][] = $resp['question_text'] . ': ' . $resp['response_value'];

                                // Map Tier 2 assessment responses to FAIR analysis fields
                                if ($completedAssessment['template_slug'] === 'tier-2-vendor') {
                                    $questionText = strtolower($resp['question_text']);
                                    $responseValue = $resp['response_value'];

                                    // Security awareness training -> #10
                                    if (strpos($questionText, 'security awareness training') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['security_awareness_training'] = 'Yes - Employees are required to complete security awareness training.';
                                        } else {
                                            $analysis['security_awareness_training'] = 'No - Security awareness training not required.';
                                        }
                                    }

                                    // Firewall -> #15 Network Security
                                    if (strpos($questionText, 'use a firewall') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['network_security'] = 'Firewalls are used.';
                                        } else {
                                            $analysis['network_security'] = 'No firewall in use.';
                                        }
                                    }

                                    // Security patches -> #12 Patch Management
                                    if (strpos($questionText, 'applying security patches') !== false) {
                                        if ($responseValue === 'Yes, automated') {
                                            $analysis['patch_management'] = 'Yes, automated - Vendor has automated patch management process.';
                                        } elseif ($responseValue === 'Yes, manual') {
                                            $analysis['patch_management'] = 'Yes, manual - Vendor has manual patch management process.';
                                        } else {
                                            $analysis['patch_management'] = 'No - No formal patch management process.';
                                        }
                                    }

                                    // Information security policy -> #6 Security Governance
                                    if (strpos($questionText, 'information security policy') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['security_governance'] = 'Yes - Vendor has a documented information security policy.';
                                        } elseif ($responseValue === 'In Development') {
                                            $analysis['security_governance'] = 'In Development - Information security policy is being developed.';
                                        } else {
                                            $analysis['security_governance'] = 'No - No documented information security policy.';
                                        }
                                    }

                                    // Antivirus/anti-malware -> #11 Vulnerability Management
                                    if (strpos($questionText, 'antivirus') !== false || strpos($questionText, 'anti-malware') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['vulnerability_management'] = 'Antivirus/anti-malware software is in use.';
                                        } else {
                                            $analysis['vulnerability_management'] = 'No antivirus/anti-malware software.';
                                        }
                                    }

                                    // Data encryption -> #14
                                    if (strpos($questionText, 'encrypt data at rest and in transit') !== false) {
                                        $analysis['data_encryption'] = $responseValue;
                                    }

                                    // Will you store or process any of our data? -> Show warning modal
                                    if (strpos($questionText, 'store or process any of our data') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['_show_data_warning'] = true;
                                        }
                                    }

                                    // Where will the data be stored? -> #3 Medium of Data
                                    if (strpos($questionText, 'where will the data be stored') !== false) {
                                        if (!empty($responseValue) && $responseValue !== 'Not Applicable') {
                                            $analysis['medium_of_data'] = $responseValue;
                                        }
                                    }

                                    // Cyber liability insurance -> Vendor Cyber Insurance Coverage
                                    if (strpos($questionText, 'cyber liability insurance') !== false) {
                                        if ($responseValue === 'Yes') {
                                            $analysis['vendor_cyber_insurance_coverage'] = 500000;
                                        }
                                    }
                                }
                            }
                        }

                        $summaryText = "From " . $completedAssessment['template_name'] . " Assessment (Completed: " .
                            date('M j, Y', strtotime($completedAssessment['completed_at'])) . "):\n\n";

                        foreach ($sectionSummaries as $section => $items) {
                            $summaryText .= "[$section]\n" . implode("\n", $items) . "\n\n";
                        }

                        // Add to vendor risk assessment notes
                        $analysis['vendor_risk_assessment'] = ($analysis['vendor_risk_assessment'] ?? '') .
                            "\n\n" . $summaryText;
                    }
                }
            }
        }
    }
}

if ($editMode = ($analysisId > 0)) {
    $analysis = $db->fetchOne(
        'SELECT * FROM tprm_results WHERE id = :id AND user_id = :user_id',
        [':id' => $analysisId, ':user_id' => $user['id']]
    );

    if (!$analysis) {
        redirect('fair_results.php');
    }

    foreach ($analysis as $key => $value) {
        if (in_array($key, ['id', 'user_id', 'vendor_name', 'vendor_domain', 'security_score', 'securityscorecard_rating', 'iso_27001_certified', 'pii_record_count', 'spii_record_count', 'sox_record_count', 'risk_output', 'status', 'completed_at', 'created_at', 'updated_at', 'executive_summary'])) {
            continue;
        }
        if (!empty($value)) {
            $analysis[$key] = $encryption->decrypt($value);
        }
    }

    // In edit mode, check for completed vendor assessments and populate empty fields
    if (!empty($analysis['vendor_name']) || !empty($analysis['vendor_domain'])) {
        // Find the vendor onboarding request
        $vendorOnboarding = null;
        if (!empty($analysis['vendor_domain'])) {
            $vendorOnboarding = $db->fetchOne(
                'SELECT * FROM vendor_onboarding_requests WHERE vendor_domain = :domain ORDER BY created_at DESC LIMIT 1',
                [':domain' => $analysis['vendor_domain']]
            );
        }
        if (!$vendorOnboarding && !empty($analysis['vendor_name'])) {
            $vendorOnboarding = $db->fetchOne(
                'SELECT * FROM vendor_onboarding_requests WHERE vendor_name = :name ORDER BY created_at DESC LIMIT 1',
                [':name' => $analysis['vendor_name']]
            );
        }

        if ($vendorOnboarding) {
            // Populate empty fields from onboarding data
            if (empty($analysis['pii_record_count']) && !empty($vendorOnboarding['pii_record_count'])) {
                $analysis['pii_record_count'] = $vendorOnboarding['pii_record_count'];
            }
            if (empty($analysis['spii_record_count']) && !empty($vendorOnboarding['spii_record_count'])) {
                $analysis['spii_record_count'] = $vendorOnboarding['spii_record_count'];
            }
            if (empty($analysis['sox_record_count']) && !empty($vendorOnboarding['sox_record_count'])) {
                $analysis['sox_record_count'] = $vendorOnboarding['sox_record_count'];
            }
            if (empty($analysis['scope_of_work']) && !empty($vendorOnboarding['product_service_description'])) {
                $analysis['scope_of_work'] = $vendorOnboarding['product_service_description'];
            }
            if (empty($analysis['business_impact']) && !empty($vendorOnboarding['business_impact'])) {
                $analysis['business_impact'] = $vendorOnboarding['business_impact'];
            }

            // Get combined score and set security_score if empty
            if (empty($analysis['security_score'])) {
                $combined = $srsService->getCombinedScore($vendorOnboarding);
                if ($combined['grade']) {
                    $analysis['security_score'] = $combined['grade'];
                }
            }

            // Check for completed vendor assessments
            $completedAssessment = $db->fetchOne(
                "SELECT va.*, t.slug as template_slug, t.name as template_name
                 FROM vendor_assessments va
                 JOIN assessment_templates t ON va.template_id = t.id
                 WHERE va.vendor_request_id = :vendor_id
                   AND va.status = 'completed'
                 ORDER BY va.completed_at DESC
                 LIMIT 1",
                [':vendor_id' => $vendorOnboarding['id']]
            );

            if ($completedAssessment) {
                // Handle ISO 27001 certificate
                if ($completedAssessment['template_slug'] === 'iso-27001-2022' && $completedAssessment['certificate_uploaded']) {
                    $certExpiry = $completedAssessment['certificate_expiry']
                        ? ' (Expires: ' . date('M j, Y', strtotime($completedAssessment['certificate_expiry'])) . ')'
                        : '';
                    $isoValue = 'Valid ISO 27001:2022 Certificate' . $certExpiry;

                    if (empty($analysis['iso_27001_certified'])) {
                        $analysis['iso_27001_certified'] = 1;
                    }

                    // Only populate empty fields
                    $isoFields = ['certifications', 'compliance', 'security_governance', 'incident_response_plan',
                                  'continuous_monitoring', 'supply_chain_risk_mgmt', 'security_awareness_training',
                                  'vulnerability_management', 'patch_management', 'access_controls',
                                  'data_encryption', 'network_security'];
                    foreach ($isoFields as $field) {
                        if (empty($analysis[$field])) {
                            $analysis[$field] = $isoValue;
                        }
                    }
                } elseif (!$completedAssessment['certificate_uploaded']) {
                    // Assessment completed via questionnaire - fetch and map responses
                    $assessmentResponses = $db->fetchAll(
                        "SELECT q.question_text, r.response_value, s.name as section_name
                         FROM vendor_assessment_responses r
                         JOIN assessment_questions q ON r.question_id = q.id
                         JOIN assessment_sections s ON q.section_id = s.id
                         WHERE r.assessment_id = :assessment_id
                         ORDER BY s.sort_order, q.sort_order",
                        [':assessment_id' => $completedAssessment['id']]
                    );

                    if ($assessmentResponses) {
                        // Map assessment responses to FAIR fields (only if field is empty)
                        foreach ($assessmentResponses as $resp) {
                            if (empty($resp['response_value'])) continue;

                            $questionText = strtolower($resp['question_text']);
                            $responseValue = $resp['response_value'];

                            // === ISO 27001 Mappings ===
                            if ($completedAssessment['template_slug'] === 'iso-27001-2022') {
                                // Information Security Policy -> Security Governance
                                if (strpos($questionText, 'documented information security policy') !== false && empty($analysis['security_governance'])) {
                                    $analysis['security_governance'] = "Information Security Policy: {$responseValue}";
                                }

                                // Security awareness training program
                                if (strpos($questionText, 'security awareness training program') !== false && empty($analysis['security_awareness_training'])) {
                                    $analysis['security_awareness_training'] = $responseValue;
                                }

                                // Data encrypted at rest
                                if (strpos($questionText, 'data encrypted at rest') !== false && empty($analysis['data_encryption'])) {
                                    $analysis['data_encryption'] = "At Rest: {$responseValue}";
                                }
                                // Data encrypted in transit - append to existing
                                if (strpos($questionText, 'data encrypted in transit') !== false) {
                                    $existing = $analysis['data_encryption'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['data_encryption'] = "In Transit: {$responseValue}";
                                    } elseif (strpos($existing, 'In Transit') === false) {
                                        $analysis['data_encryption'] = $existing . "; In Transit: {$responseValue}";
                                    }
                                }
                                // Encryption standards
                                if (strpos($questionText, 'encryption standards') !== false) {
                                    $existing = $analysis['data_encryption'] ?? '';
                                    if (!empty($responseValue)) {
                                        if (empty($existing)) {
                                            $analysis['data_encryption'] = "Standards: {$responseValue}";
                                        } else {
                                            $analysis['data_encryption'] = $existing . "; Standards: {$responseValue}";
                                        }
                                    }
                                }

                                // Least privilege access
                                if (strpos($questionText, 'principle of least privilege') !== false && empty($analysis['access_controls'])) {
                                    $analysis['access_controls'] = "Least Privilege: {$responseValue}";
                                }
                                // MFA
                                if (strpos($questionText, 'multi-factor authentication') !== false) {
                                    $existing = $analysis['access_controls'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['access_controls'] = "MFA: {$responseValue}";
                                    } elseif (strpos($existing, 'MFA') === false) {
                                        $analysis['access_controls'] = $existing . "; MFA: {$responseValue}";
                                    }
                                }
                                // Password policy
                                if (strpos($questionText, 'password policy') !== false) {
                                    $existing = $analysis['access_controls'] ?? '';
                                    if (!empty($responseValue)) {
                                        if (empty($existing)) {
                                            $analysis['access_controls'] = "Password Policy: {$responseValue}";
                                        } else {
                                            $analysis['access_controls'] = $existing . "; Password Policy: {$responseValue}";
                                        }
                                    }
                                }

                                // Incident response plan
                                if (strpos($questionText, 'incident response plan') !== false && empty($analysis['incident_response_plan'])) {
                                    $analysis['incident_response_plan'] = "Incident Response Plan: {$responseValue}";
                                }
                                // Breach notification
                                if (strpos($questionText, 'notify affected parties') !== false) {
                                    $existing = $analysis['incident_response_plan'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['incident_response_plan'] = "Breach Notification: {$responseValue}";
                                    } elseif (strpos($existing, 'Notification') === false) {
                                        $analysis['incident_response_plan'] = $existing . "; Breach Notification: {$responseValue}";
                                    }
                                }

                                // Vulnerability assessments
                                if (strpos($questionText, 'vulnerability assessment') !== false && empty($analysis['vulnerability_management'])) {
                                    $analysis['vulnerability_management'] = "Vulnerability Assessments: {$responseValue}";
                                }
                                // Penetration testing
                                if (strpos($questionText, 'penetration testing') !== false) {
                                    $existing = $analysis['vulnerability_management'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['vulnerability_management'] = "Penetration Testing: {$responseValue}";
                                    } elseif (strpos($existing, 'Penetration') === false) {
                                        $analysis['vulnerability_management'] = $existing . "; Penetration Testing: {$responseValue}";
                                    }
                                }

                                // BCP/DRP -> Continuous Monitoring
                                if (strpos($questionText, 'business continuity plan') !== false && empty($analysis['continuous_monitoring'])) {
                                    $analysis['continuous_monitoring'] = "Business Continuity Plan: {$responseValue}";
                                }
                                if (strpos($questionText, 'disaster recovery plan') !== false) {
                                    $existing = $analysis['continuous_monitoring'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['continuous_monitoring'] = "Disaster Recovery Plan: {$responseValue}";
                                    } elseif (strpos($existing, 'Disaster Recovery') === false) {
                                        $analysis['continuous_monitoring'] = $existing . "; Disaster Recovery Plan: {$responseValue}";
                                    }
                                }

                                // Compliance frameworks
                                if (strpos($questionText, 'compliance frameworks') !== false && empty($analysis['compliance'])) {
                                    $analysis['compliance'] = "Frameworks: {$responseValue}";
                                }

                                // Third-party data center certifications
                                if (strpos($questionText, 'certifications do they hold') !== false && empty($analysis['certifications'])) {
                                    $analysis['certifications'] = "Data Center Certifications: {$responseValue}";
                                }

                                // Change management -> Supply Chain Risk
                                if (strpos($questionText, 'change management') !== false && empty($analysis['supply_chain_risk_mgmt'])) {
                                    $analysis['supply_chain_risk_mgmt'] = "Change Management: {$responseValue}";
                                }
                            }

                            // === Tier 2 Mappings ===
                            if ($completedAssessment['template_slug'] === 'tier-2-vendor') {
                                // Security awareness training
                                if (strpos($questionText, 'security awareness training') !== false && empty($analysis['security_awareness_training'])) {
                                    $analysis['security_awareness_training'] = $responseValue === 'Yes'
                                        ? 'Yes - Employees are required to complete security awareness training.'
                                        : 'No - Security awareness training not required.';
                                }

                                // Firewall -> Network Security
                                if (strpos($questionText, 'use a firewall') !== false && empty($analysis['network_security'])) {
                                    $analysis['network_security'] = $responseValue === 'Yes' ? 'Firewalls are used.' : 'No firewall in use.';
                                }

                                // Security patches -> Patch Management
                                if (strpos($questionText, 'applying security patches') !== false && empty($analysis['patch_management'])) {
                                    if ($responseValue === 'Yes, automated') {
                                        $analysis['patch_management'] = 'Yes, automated - Vendor has automated patch management process.';
                                    } elseif ($responseValue === 'Yes, manual') {
                                        $analysis['patch_management'] = 'Yes, manual - Vendor has manual patch management process.';
                                    } else {
                                        $analysis['patch_management'] = 'No - No formal patch management process.';
                                    }
                                }

                                // Information security policy -> Security Governance
                                if (strpos($questionText, 'information security policy') !== false && empty($analysis['security_governance'])) {
                                    if ($responseValue === 'Yes') {
                                        $analysis['security_governance'] = 'Yes - Vendor has a documented information security policy.';
                                    } elseif ($responseValue === 'In Development') {
                                        $analysis['security_governance'] = 'In Development - Information security policy is being developed.';
                                    } else {
                                        $analysis['security_governance'] = 'No - No documented information security policy.';
                                    }
                                }

                                // Antivirus -> Vulnerability Management
                                if ((strpos($questionText, 'antivirus') !== false || strpos($questionText, 'anti-malware') !== false) && empty($analysis['vulnerability_management'])) {
                                    $analysis['vulnerability_management'] = $responseValue === 'Yes'
                                        ? 'Antivirus/anti-malware software is in use.'
                                        : 'No antivirus/anti-malware software.';
                                }

                                // Data encryption
                                if (strpos($questionText, 'encrypt data at rest and in transit') !== false && empty($analysis['data_encryption'])) {
                                    $analysis['data_encryption'] = $responseValue;
                                }

                                // Data storage location -> Medium of Data
                                if (strpos($questionText, 'where will the data be stored') !== false && empty($analysis['medium_of_data'])) {
                                    if (!empty($responseValue) && $responseValue !== 'Not Applicable') {
                                        $analysis['medium_of_data'] = $responseValue;
                                    }
                                }

                                // Cyber liability insurance
                                if (strpos($questionText, 'cyber liability insurance') !== false && empty($analysis['vendor_cyber_insurance_coverage'])) {
                                    if ($responseValue === 'Yes') {
                                        $analysis['vendor_cyber_insurance_coverage'] = 500000;
                                    }
                                }

                                // Data retention policy
                                if (strpos($questionText, 'data retention and disposal') !== false) {
                                    $existing = $analysis['compliance'] ?? '';
                                    if (empty($existing)) {
                                        $analysis['compliance'] = "Data Retention Policy: {$responseValue}";
                                    }
                                }

                                // Brief description of services -> scope_of_work if empty
                                if (strpos($questionText, 'description of services') !== false && empty($analysis['scope_of_work'])) {
                                    $analysis['scope_of_work'] = $responseValue;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$acl = ACL::getInstance();
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$acl->hasPermission("analysis.create")) {
    http_response_code(403);
    $error = t('fair-analysis.access_denied');
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('fair-analysis.invalid_request');
    } else {
        $vendorName = $security->cleanInput($_POST['vendor_name'] ?? '');
        $vendorDomain = $security->cleanInput($_POST['vendor_domain'] ?? '');

        if (empty($vendorName)) {
            $error = t('fair-analysis.vendor_name_required');
        } else {
            try {
                // Fields that need encryption
                $fields = [
                    'msa', 'scope_of_work', 'medium_of_data', 'certifications', 'compliance',
                    'security_governance', 'incident_response_plan', 'continuous_monitoring',
                    'supply_chain_risk_mgmt', 'security_awareness_training', 'vulnerability_management',
                    'patch_management', 'access_controls', 'data_encryption', 'network_security',
                    'vulnerability_data', 'configuration_data', 'compliance_data',
                    'risk_assessment', 'threat_intelligence',
                    'vendor_risk_assessment', 'security_questionnaire', 'compliance_questionnaire',
                    'business_impact', 'vendor_performance',
                    'third_party_vendor_list', 'third_party_risk_assessment',
                    'third_party_security_questionnaire', 'third_party_compliance_questionnaire',
                    'vendor_cyber_insurance_coverage'
                ];

                $encryptedData = [
                    'vendor_name' => $vendorName,
                    'vendor_domain' => $vendorDomain,
                    'user_id' => $user['id']
                ];

                foreach ($fields as $field) {
                    $value = $_POST[$field] ?? '';
                    $encryptedData[$field] = !empty($value) ? $encryption->encrypt($value) : null;
                }

                // Non-encrypted fields
                $encryptedData['security_score'] = $_POST['security_score'] ?? null;
                $encryptedData['iso_27001_certified'] = isset($_POST['iso_27001_certified']) ? 1 : 0;
                $encryptedData['pii_record_count'] = max(0, intval($_POST['pii_record_count'] ?? 0));
                $encryptedData['spii_record_count'] = max(0, intval($_POST['spii_record_count'] ?? 0));
                $encryptedData['sox_record_count'] = max(0, intval($_POST['sox_record_count'] ?? 0));

                $status = isset($_POST['save_draft']) ? 'draft' : 'completed';
                $encryptedData['status'] = $status;

                if ($status === 'completed') {
                    // Always update completed_at when submitting
                    $encryptedData['completed_at'] = date('Y-m-d H:i:s');

                    // Calculate FAIR risk metrics
                    // Prepare decrypted data for FAIR calculator
                    $decryptedData = [
                        'security_score' => $encryptedData['security_score'],
                        'iso_27001_certified' => $encryptedData['iso_27001_certified'],
                        'pii_record_count' => $encryptedData['pii_record_count'],
                        'spii_record_count' => $encryptedData['spii_record_count'],
                        'sox_record_count' => $encryptedData['sox_record_count']
                    ];

                    // Add decrypted field values needed for FAIR calculations
                    foreach ($fields as $field) {
                        $value = $_POST[$field] ?? '';
                        $decryptedData[$field] = $value;
                    }

                    // Look up vendor onboarding ID for Shodan/UpGuard intelligence
                    $vendorOnboardingId = null;
                    if (!empty($vendorName)) {
                        $onboardingLookup = $db->fetchOne(
                            'SELECT id FROM vendor_onboarding_requests WHERE vendor_name = :name AND status != :status ORDER BY updated_at DESC LIMIT 1',
                            [':name' => $vendorName, ':status' => 'inactive']
                        );
                        if ($onboardingLookup) {
                            $vendorOnboardingId = intval($onboardingLookup['id']);
                        }
                    }

                    // Calculate FAIR metrics with real security intelligence
                    $fairResults = FairCalculator::calculate($decryptedData, $vendorOnboardingId);

                    // If AI estimates were applied, override FairCalculator financial results
                    if (isset($_POST['ai_override_applied']) && $_POST['ai_override_applied'] === '1') {
                        $fairResults['annualized_loss_expectancy'] = floatval($_POST['ai_ale'] ?? 0);
                        $fairResults['loss_event_frequency'] = floatval($_POST['ai_lef'] ?? 0);
                        $fairResults['primary_loss'] = floatval($_POST['ai_primary_loss'] ?? 0);
                        $fairResults['secondary_loss'] = floatval($_POST['ai_secondary_loss'] ?? 0);
                        $fairResults['loss_magnitude'] = floatval($_POST['ai_loss_magnitude'] ?? 0);
                        $fairResults['recommended_liability'] = floatval($_POST['ai_recommended_liability'] ?? 0);
                        $fairResults['risk_level'] = trim($_POST['ai_risk_level'] ?? '');

                        // Tier-based LEF reduction also applies to AI-generated estimates.
                        // Tier 2: LEF x 0.30, Tier 3: LEF x 0.10. Recompute ALE from reduced LEF.
                        if ($vendorOnboardingId) {
                            $tierRow = $db->fetchOne(
                                'SELECT vendor_tier FROM vendor_onboarding_requests WHERE id = :id',
                                [':id' => $vendorOnboardingId]
                            );
                            $tier = $tierRow['vendor_tier'] ?? null;
                            $lefMultiplier = 1.0;
                            if ($tier === '2' || $tier === 2) {
                                $lefMultiplier = 0.30;
                            } elseif ($tier === '3' || $tier === 3) {
                                $lefMultiplier = 0.10;
                            }
                            if ($lefMultiplier < 1.0) {
                                $fairResults['loss_event_frequency'] *= $lefMultiplier;
                                $fairResults['annualized_loss_expectancy'] =
                                    $fairResults['loss_event_frequency'] * $fairResults['loss_magnitude'];
                            }
                        }
                    }

                    // Store FAIR calculation results (encrypted)
                    $encryptedData['loss_event_frequency'] = $encryption->encrypt((string)$fairResults['loss_event_frequency']);
                    $encryptedData['loss_magnitude'] = $encryption->encrypt((string)$fairResults['loss_magnitude']);
                    $encryptedData['primary_loss_magnitude'] = $encryption->encrypt((string)$fairResults['primary_loss']);
                    $encryptedData['secondary_loss_magnitude'] = $encryption->encrypt((string)$fairResults['secondary_loss']);
                    $encryptedData['ale'] = $encryption->encrypt((string)$fairResults['annualized_loss_expectancy']);
                    $encryptedData['recommended_liability'] = $encryption->encrypt((string)$fairResults['recommended_liability']);
                    $encryptedData['risk_output'] = $fairResults['risk_level'];

                    // Store auto-calculated financial fields (encrypted)
                    $encryptedData['cost_of_outage'] = $encryption->encrypt((string)$fairResults['cost_of_outage']);
                    $encryptedData['sec_fines'] = $encryption->encrypt((string)$fairResults['sec_fines']);
                    $encryptedData['compliance_fines'] = $encryption->encrypt((string)$fairResults['compliance_fines']);
                    $encryptedData['total_cost_of_breach'] = $encryption->encrypt((string)$fairResults['total_cost_of_breach']);
                    $encryptedData['pii_breach_cost'] = $encryption->encrypt((string)$fairResults['pii_breach_cost']);
                    $encryptedData['spii_breach_cost'] = $encryption->encrypt((string)$fairResults['spii_breach_cost']);
                    $encryptedData['sox_breach_cost'] = $encryption->encrypt((string)$fairResults['sox_breach_cost']);
                }

                if ($editMode) {
                    $db->update('tprm_results', $encryptedData, 'id = :id', [':id' => $analysisId]);
                    $auth->audit($user['id'], 'fair_analysis_update', 'tprm_results', $analysisId, [
                        'new' => ['vendor_name' => $vendorName ?? '', 'status' => $status]
                    ]);
                    $success = t('fair-analysis.update_success');
                } else {
                    $db->insert('tprm_results', $encryptedData);
                    $auth->audit($user['id'], 'fair_analysis_create', 'tprm_results', null, [
                        'new' => ['vendor_name' => $vendorName ?? '', 'status' => $status]
                    ]);
                    $success = t('fair-analysis.save_success');
                }

                if ($status === 'completed') {
                    // Check if user is admin or cyber_tprm, redirect to vendor SRS details if so
                    $acl = ACL::getInstance();
                    $isAdminOrCyber = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

                    if ($isAdminOrCyber && !empty($vendorName)) {
                        // Try to find the vendor onboarding record by name
                        $vendorRecord = $db->fetchOne(
                            'SELECT id FROM vendor_onboarding_requests WHERE vendor_name = :name ORDER BY created_at DESC LIMIT 1',
                            [':name' => $vendorName]
                        );

                        if ($vendorRecord) {
                            redirect('vendor-srs-details.php?id=' . $vendorRecord['id']);
                        }
                    }

                    // Default redirect for non-admin/cyber_tprm users or if vendor not found
                    redirect('fair_results.php');
                }
            } catch (Exception $e) {
                error_log('Error saving analysis: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                error_log('Stack trace: ' . $e->getTraceAsString());
                $error = t('fair-analysis.save_failed');
            }
        }
    }
}

// Generate CSRF token only for GET requests or after successful POST
// This prevents overwriting the token before validation on POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($success)) {
    $csrfToken = $security->generateCSRFToken();
} else {
    $csrfToken = $security->getCSRFToken() ?? $security->generateCSRFToken();
}
?>
<?php
$acl = ACL::getInstance();
$isAdmin = (Session::getInstance()->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator'));
$isCyberTPRM = hasGroup('cyber_tprm');
$isProcurement = hasGroup('procurement');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo e(t('fair-analysis.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
            --nav-fill-color: <?php echo e($theme['nav_fill_color']); ?>;
            --nav-font-color: <?php echo e($theme['nav_font_color']); ?>;
            --sidebar-width: <?php echo e($theme['nav_width']); ?>px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; }

        .page { display: flex !important; flex-direction: column; min-height: 100vh; opacity: 1 !important; visibility: visible !important; }
        .page-header { display: none !important; }

        /* Top Bar */
        .top-bar {
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px; display: flex; justify-content: flex-end;
            align-items: center; flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }

        /* Main Layout */
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important;
            max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important;
            padding: 0; flex-shrink: 0; display: flex; flex-direction: column;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5;
            padding: 0 20px; margin-bottom: 10px;
        }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a {
            display: flex; align-items: center; gap: 10px; padding: 11px 20px;
            color: var(--nav-font-color); opacity: 0.85; text-decoration: none;
            font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }

        /* Welcome header */
        .welcome-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; flex-wrap: wrap; gap: 15px;
        }

        /* Form styles */
        .section-header {
            background: var(--theme-header-color, #35a0a3); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; margin-top: 25px;
        }
        .section-header:first-of-type { margin-top: 0; }
        .section-header h3 { margin: 0; font-size: 18px; font-weight: 500; }
        .section-content {
            background: white; border: 1px solid #ddd; border-top: none;
            border-radius: 0 0 8px 8px; padding: 25px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; color: #333; font-weight: 500; font-size: 14px; }
        .form-control {
            width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px;
            font-size: 14px; font-family: 'Roboto', sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-control:focus { outline: none; border-color: #ff6543; box-shadow: 0 0 0 3px rgba(255,101,67,0.1); }
        textarea.form-control { min-height: 80px; resize: vertical; }
        .form-actions {
            margin-top: 25px; padding-top: 25px; border-top: 2px solid #f0f0f0; text-align: right;
        }
        .btn {
            padding: 10px 24px; border: none; border-radius: 4px; font-size: 14px;
            font-weight: 500; cursor: pointer; transition: all 0.3s; margin-left: 10px;
        }
        .btn-primary { background: var(--theme-button-color, #ffc211); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-outline { background: white; color: #ff6543; border: 2px solid #ff6543; }
        .btn-outline:hover { background: #ff6543; color: white; }
        .alert { padding: 15px 20px; margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .required::after { content: " *"; color: #dc3545; }

        /* Compact form grid rows */
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) {
            .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
            .main-content { padding: 20px 15px; }
        }

        /* Autocomplete styles */
        .autocomplete-container { position: relative; }
        .autocomplete-results {
            position: absolute; top: 100%; left: 0; right: 0; background: white;
            border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;
            max-height: 250px; overflow-y: auto; z-index: 1000; display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .autocomplete-results.active { display: block; }
        .autocomplete-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover, .autocomplete-item.selected { background: #f0f7ff; }
        .autocomplete-item .vendor-domain { font-weight: 500; color: #333; }
        .autocomplete-item .vendor-name { font-size: 12px; color: #666; }
        .autocomplete-item .vendor-source { font-size: 11px; color: #999; float: right; }

        /* Enrichment indicator */
        .enrichment-badge {
            display: inline-block; background: #d4edda; color: #155724;
            font-size: 12px; padding: 4px 10px; border-radius: 12px; margin-top: 8px;
        }
        .enrichment-loading {
            display: inline-block; background: #fff3cd; color: #856404;
            font-size: 12px; padding: 4px 10px; border-radius: 12px; margin-top: 8px;
        }

        /* Footer */
        .footer-modern, .bg-gray-13 { background-color: var(--theme-footer-color) !important; padding: 30px 0; color: #fff; width: 100%; overflow: visible; }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }
    </style>
</head>
<body>
    <?php renderImpersonationBanner(); ?>
    <div class="page">
        <!-- Top Bar -->
        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <!-- Main Layout with Sidebar -->
        <div class="main-layout">
            <?php $currentPage = 'fair'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
                <!-- Welcome Header with Search -->
                <div class="welcome-header">
                    <div>
                        <h1 style="font-size: 18px; font-weight: 600; color: #333; margin: 0 0 5px 0;"><?php echo e(t('fair-analysis.heading')); ?></h1>
                        <p style="color: #666; margin: 0; font-size: 14px;"><?php echo e(t('fair-analysis.subheading')); ?></p>
                    </div>
                    <?php if ($isAdmin || $isCyberTPRM || $isProcurement): ?>
                    <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                        <input type="text" id="vendorSearchInput" placeholder="<?php echo e(t('fair-analysis.search_placeholder')); ?>"
                               class="focus-ring"
                               style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                               <?php if ($isProcurement && !$isAdmin && !$isCyberTPRM): ?>data-link-base="vendor-onboarding.php"<?php endif; ?>>
                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128270;</span>
                        <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
                    </div>
                    <?php endif; ?>

                </div>

                <div id="enrichmentStatus"></div>

                <!-- Security Intelligence Summary (populated via JS after vendor enrichment) -->
                <div id="securityIntelCard" style="display: none; margin-bottom: 20px;">
                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px 20px;">
                        <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #0c4a6e; font-weight: 600;"><?php echo e(t('fair-analysis.security_intel_summary')); ?></h4>
                        <div style="display: flex; gap: 24px; flex-wrap: wrap; font-size: 13px; color: #334155;">
                            <div id="intelShodanSection" style="display: none;">
                                <strong style="color: #0c4a6e;">Shodan:</strong>
                                <span id="intelShodanScore"></span>
                                <div id="intelShodanPositive" style="color: #059669; margin-top: 4px;"></div>
                                <div id="intelShodanNegative" style="color: #dc2626; margin-top: 2px;"></div>
                            </div>
                            <div id="intelUpguardSection" style="display: none;">
                                <strong style="color: #0c4a6e;">UpGuard:</strong>
                                <span id="intelUpguardScore"></span>
                                <div id="intelUpguardRisks" style="margin-top: 4px;"></div>
                            </div>
                            <div id="intelRevCapSection" style="display: none;">
                                <strong style="color: #0c4a6e;"><?php echo e(t('fair-analysis.revenue_cap')); ?></strong>
                                <span id="intelRevCap"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger" style="white-space: pre-line;"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="fair-analysis.php<?php echo $editMode ? '?id=' . $analysisId : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <!-- AI Override hidden fields (populated by applyAIEstimates JS) -->
                    <input type="hidden" name="ai_override_applied" id="ai_override_applied" value="">
                    <input type="hidden" name="ai_ale" id="ai_ale" value="">
                    <input type="hidden" name="ai_lef" id="ai_lef" value="">
                    <input type="hidden" name="ai_primary_loss" id="ai_primary_loss" value="">
                    <input type="hidden" name="ai_secondary_loss" id="ai_secondary_loss" value="">
                    <input type="hidden" name="ai_loss_magnitude" id="ai_loss_magnitude" value="">
                    <input type="hidden" name="ai_recommended_liability" id="ai_recommended_liability" value="">
                    <input type="hidden" name="ai_risk_level" id="ai_risk_level" value="">

                    <!-- Vendor Information -->
                    <div class="section-header">
                        <h3><?php echo e(t('fair-analysis.section_vendor_info')); ?></h3>
                    </div>
                    <div class="section-content">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="vendor_name" class="required"><?php echo e(t('fair-analysis.lbl_vendor_name')); ?></label>
                                <div class="autocomplete-container" style="position: relative;">
                                    <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                           value="<?php echo e($analysis['vendor_name'] ?? ''); ?>"
                                           autocomplete="off" required>
                                    <div id="vendor_name_results" class="autocomplete-results"></div>
                                </div>
                                <small style="color: #666; font-size: 12px;"><?php echo e(t('fair-analysis.hint_type_search')); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="vendor_domain"><?php echo e(t('fair-analysis.lbl_vendor_domain')); ?></label>
                                <div class="autocomplete-container" style="position: relative;">
                                    <input type="text" id="vendor_domain" name="vendor_domain" class="form-control"
                                           placeholder="example.com"
                                           value="<?php echo e($analysis['vendor_domain'] ?? ''); ?>"
                                           autocomplete="off">
                                    <div id="vendor_domain_results" class="autocomplete-results"></div>
                                </div>
                                <small style="color: #666; font-size: 12px;"><?php echo e(t('fair-analysis.hint_enter_domain')); ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Cyber Security Section -->
                    <div class="section-header">
                        <h3><?php echo e(t('fair-analysis.section_cyber_security')); ?></h3>
                    </div>
                    <div class="section-content">
                        <div class="form-group">
                            <label for="msa"><?php echo e(t('fair-analysis.lbl_msa')); ?></label>
                            <textarea id="msa" name="msa" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_msa')); ?>"><?php echo e($analysis['msa'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="scope_of_work"><?php echo e(t('fair-analysis.lbl_scope_of_work')); ?></label>
                            <textarea id="scope_of_work" name="scope_of_work" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_scope_of_work')); ?>"><?php echo e($analysis['scope_of_work'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="medium_of_data"><?php echo e(t('fair-analysis.lbl_medium_of_data')); ?></label>
                            <textarea id="medium_of_data" name="medium_of_data" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_medium_of_data')); ?>"><?php echo e($analysis['medium_of_data'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="certifications"><?php echo e(t('fair-analysis.lbl_certifications')); ?></label>
                            <textarea id="certifications" name="certifications" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_certifications')); ?>"><?php echo e($analysis['certifications'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="compliance"><?php echo e(t('fair-analysis.lbl_compliance')); ?></label>
                            <textarea id="compliance" name="compliance" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_compliance')); ?>"><?php echo e($analysis['compliance'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="security_governance"><?php echo e(t('fair-analysis.lbl_security_governance')); ?></label>
                            <textarea id="security_governance" name="security_governance" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_security_governance')); ?>"><?php echo e($analysis['security_governance'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="incident_response_plan"><?php echo e(t('fair-analysis.lbl_incident_response')); ?></label>
                            <textarea id="incident_response_plan" name="incident_response_plan" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_incident_response')); ?>"><?php echo e($analysis['incident_response_plan'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="continuous_monitoring"><?php echo e(t('fair-analysis.lbl_continuous_monitoring')); ?></label>
                            <textarea id="continuous_monitoring" name="continuous_monitoring" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_continuous_monitoring')); ?>"><?php echo e($analysis['continuous_monitoring'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="supply_chain_risk_mgmt"><?php echo e(t('fair-analysis.lbl_supply_chain')); ?></label>
                            <textarea id="supply_chain_risk_mgmt" name="supply_chain_risk_mgmt" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_supply_chain')); ?>"><?php echo e($analysis['supply_chain_risk_mgmt'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="security_awareness_training"><?php echo e(t('fair-analysis.lbl_security_awareness')); ?></label>
                            <textarea id="security_awareness_training" name="security_awareness_training" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_security_awareness')); ?>"><?php echo e($analysis['security_awareness_training'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="vulnerability_management"><?php echo e(t('fair-analysis.lbl_vuln_management')); ?></label>
                            <textarea id="vulnerability_management" name="vulnerability_management" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_vuln_management')); ?>"><?php echo e($analysis['vulnerability_management'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="patch_management"><?php echo e(t('fair-analysis.lbl_patch_management')); ?></label>
                            <textarea id="patch_management" name="patch_management" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_patch_management')); ?>"><?php echo e($analysis['patch_management'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="access_controls"><?php echo e(t('fair-analysis.lbl_access_controls')); ?></label>
                            <textarea id="access_controls" name="access_controls" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_access_controls')); ?>"><?php echo e($analysis['access_controls'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="data_encryption"><?php echo e(t('fair-analysis.lbl_data_encryption')); ?></label>
                            <textarea id="data_encryption" name="data_encryption" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_data_encryption')); ?>"><?php echo e($analysis['data_encryption'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="network_security"><?php echo e(t('fair-analysis.lbl_network_security')); ?></label>
                            <textarea id="network_security" name="network_security" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_network_security')); ?>"><?php echo e($analysis['network_security'] ?? ''); ?></textarea>
                        </div>

                    </div>

                    <!-- Vendor Risk Section -->
                    <div class="section-header">
                        <h3><?php echo e(t('fair-analysis.section_vendor_risk')); ?></h3>
                    </div>
                    <div class="section-content">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="security_score" class="required"><?php echo e(t('fair-analysis.lbl_security_score')); ?></label>
                                <select id="security_score" name="security_score" class="form-control" required>
                                    <option value=""><?php echo e(t('fair-analysis.opt_select_grade')); ?></option>
                                    <option value="A" <?php echo (($analysis['security_score'] ?? '') === 'A') ? 'selected' : ''; ?>><?php echo e(t('fair-analysis.opt_grade_a')); ?></option>
                                    <option value="B" <?php echo (($analysis['security_score'] ?? '') === 'B') ? 'selected' : ''; ?>><?php echo e(t('fair-analysis.opt_grade_b')); ?></option>
                                    <option value="C" <?php echo (($analysis['security_score'] ?? '') === 'C') ? 'selected' : ''; ?>><?php echo e(t('fair-analysis.opt_grade_c')); ?></option>
                                    <option value="D" <?php echo (($analysis['security_score'] ?? '') === 'D') ? 'selected' : ''; ?>><?php echo e(t('fair-analysis.opt_grade_d')); ?></option>
                                    <option value="F" <?php echo (($analysis['security_score'] ?? '') === 'F') ? 'selected' : ''; ?>><?php echo e(t('fair-analysis.opt_grade_f')); ?></option>
                                </select>
                                <small style="color: #666; font-size: 12px;">
                                    <?php echo e(t('fair-analysis.hint_grade_multipliers')); ?>
                                    <?php if ($srsAvailable): ?>
                                    <span style="color: #28a745;"><?php echo e(t('fair-analysis.hint_srs_active')); ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="form-group">
                                <label for="iso_27001_certified"><?php echo e(t('fair-analysis.lbl_iso_cert')); ?></label>
                                <div style="padding: 10px 0;">
                                    <input type="checkbox" id="iso_27001_certified" name="iso_27001_certified" value="1" <?php echo isset($analysis['iso_27001_certified']) && $analysis['iso_27001_certified'] ? 'checked' : ''; ?>>
                                    <label for="iso_27001_certified" style="display: inline; margin-left: 8px; font-weight: normal;">
                                        <?php echo e(t('fair-analysis.lbl_iso_certified_check')); ?>
                                    </label>
                                </div>
                                <small style="color: #666; font-size: 12px;"><?php echo e(t('fair-analysis.hint_iso_indicates')); ?></small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="vulnerability_data"><?php echo e(t('fair-analysis.lbl_vuln_data')); ?></label>
                            <textarea id="vulnerability_data" name="vulnerability_data" class="form-control"
                                      placeholder="Vendor's vulnerability data, including the number and severity of vulnerabilities<?php echo $srsAvailable ? ' (Auto-populated from SRS)' : ''; ?>"><?php echo e($analysis['vulnerability_data'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="configuration_data"><?php echo e(t('fair-analysis.lbl_config_data')); ?></label>
                            <textarea id="configuration_data" name="configuration_data" class="form-control"
                                      placeholder="Vendor's configuration data<?php echo $srsAvailable ? ' (Auto-populated from SRS)' : ''; ?>"><?php echo e($analysis['configuration_data'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="compliance_data"><?php echo e(t('fair-analysis.lbl_compliance_data')); ?></label>
                            <textarea id="compliance_data" name="compliance_data" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_compliance_data')); ?>"><?php echo e($analysis['compliance_data'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="risk_assessment"><?php echo e(t('fair-analysis.lbl_risk_assessment')); ?></label>
                            <textarea id="risk_assessment" name="risk_assessment" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_risk_assessment')); ?>"><?php echo e($analysis['risk_assessment'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="threat_intelligence"><?php echo e(t('fair-analysis.lbl_threat_intel')); ?></label>
                            <textarea id="threat_intelligence" name="threat_intelligence" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_threat_intel')); ?>"><?php echo e($analysis['threat_intelligence'] ?? ''); ?></textarea>
                        </div>

                    </div>

                    <!-- SRS Input Section -->
                    <div class="section-header">
                        <h3><?php echo e(t('fair-analysis.section_srs_input')); ?></h3>
                    </div>
                    <div class="section-content">
                        <div class="form-group">
                            <label for="vendor_risk_assessment"><?php echo e(t('fair-analysis.lbl_vendor_risk_assessment')); ?></label>
                            <textarea id="vendor_risk_assessment" name="vendor_risk_assessment" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_vendor_risk_assessment')); ?>"><?php echo e($analysis['vendor_risk_assessment'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="security_questionnaire"><?php echo e(t('fair-analysis.lbl_security_questionnaire')); ?></label>
                            <textarea id="security_questionnaire" name="security_questionnaire" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_security_questionnaire')); ?>"><?php echo e($analysis['security_questionnaire'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="compliance_questionnaire"><?php echo e(t('fair-analysis.lbl_compliance_questionnaire')); ?></label>
                            <textarea id="compliance_questionnaire" name="compliance_questionnaire" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_compliance_questionnaire')); ?>"><?php echo e($analysis['compliance_questionnaire'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group">
                                <label for="pii_record_count"><?php echo e(t('fair-analysis.lbl_pii_records')); ?></label>
                                <input type="number" id="pii_record_count" name="pii_record_count" class="form-control" min="0"
                                       value="<?php echo e($analysis['pii_record_count'] ?? '0'); ?>"
                                       placeholder="<?php echo e(t('fair-analysis.ph_pii_count')); ?>">
                                <small style="color: #666; font-size: 12px;">$<?php echo number_format($calculationDefaults['pii_breach_cost_per_record'], 2); ?>/record</small>
                            </div>
                            <div class="form-group">
                                <label for="spii_record_count"><?php echo e(t('fair-analysis.lbl_spii_records')); ?></label>
                                <input type="number" id="spii_record_count" name="spii_record_count" class="form-control" min="0"
                                       value="<?php echo e($analysis['spii_record_count'] ?? '0'); ?>"
                                       placeholder="<?php echo e(t('fair-analysis.ph_spii_count')); ?>">
                                <small style="color: #666; font-size: 12px;">$<?php echo number_format($calculationDefaults['spii_breach_cost_per_record'], 2); ?>/record</small>
                            </div>
                            <div class="form-group">
                                <label for="sox_record_count"><?php echo e(t('fair-analysis.lbl_sox_records')); ?></label>
                                <input type="number" id="sox_record_count" name="sox_record_count" class="form-control" min="0"
                                       value="<?php echo e($analysis['sox_record_count'] ?? '0'); ?>"
                                       placeholder="<?php echo e(t('fair-analysis.ph_sox_count')); ?>">
                                <small style="color: #666; font-size: 12px;">$<?php echo number_format($calculationDefaults['sox_breach_penalty'], 2); ?> flat fine</small>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="business_impact"><?php echo e(t('fair-analysis.lbl_business_impact')); ?></label>
                                <input type="number" id="business_impact" name="business_impact" class="form-control"
                                       min="0" step="0.01"
                                       value="<?php echo e($analysis['business_impact'] ?? ''); ?>"
                                       placeholder="e.g., 100000">
                                <small style="color: #666; font-size: 12px;"><?php echo e(t('fair-analysis.hint_business_impact')); ?></small>
                            </div>
                            <div class="form-group">
                                <label for="vendor_cyber_insurance_coverage"><?php echo e(t('fair-analysis.lbl_cyber_insurance')); ?></label>
                                <input type="number" id="vendor_cyber_insurance_coverage" name="vendor_cyber_insurance_coverage"
                                       class="form-control" min="0" step="0.01"
                                       value="<?php echo e($analysis['vendor_cyber_insurance_coverage'] ?? ''); ?>"
                                       placeholder="e.g., 1000000">
                                <small style="color: #666; font-size: 12px;"><?php echo e(t('fair-analysis.hint_cyber_insurance')); ?></small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="vendor_performance"><?php echo e(t('fair-analysis.lbl_vendor_performance')); ?></label>
                            <textarea id="vendor_performance" name="vendor_performance" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_vendor_performance')); ?>"><?php echo e($analysis['vendor_performance'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Vendor's Third-Party Vendors Section -->
                    <div class="section-header">
                        <h3><?php echo e(t('fair-analysis.section_third_party')); ?></h3>
                    </div>
                    <div class="section-content">
                        <div class="form-group">
                            <label for="third_party_vendor_list"><?php echo e(t('fair-analysis.lbl_tp_vendor_list')); ?></label>
                            <textarea id="third_party_vendor_list" name="third_party_vendor_list" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_tp_vendor_list')); ?>"><?php echo e($analysis['third_party_vendor_list'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="third_party_risk_assessment"><?php echo e(t('fair-analysis.lbl_tp_risk_assessment')); ?></label>
                            <textarea id="third_party_risk_assessment" name="third_party_risk_assessment" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_tp_risk_assessment')); ?>"><?php echo e($analysis['third_party_risk_assessment'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="third_party_security_questionnaire"><?php echo e(t('fair-analysis.lbl_tp_security_q')); ?></label>
                            <textarea id="third_party_security_questionnaire" name="third_party_security_questionnaire" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_tp_security_q')); ?>"><?php echo e($analysis['third_party_security_questionnaire'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="third_party_compliance_questionnaire"><?php echo e(t('fair-analysis.lbl_tp_compliance_q')); ?></label>
                            <textarea id="third_party_compliance_questionnaire" name="third_party_compliance_questionnaire" class="form-control"
                                      placeholder="<?php echo e(t('fair-analysis.ph_tp_compliance_q')); ?>"><?php echo e($analysis['third_party_compliance_questionnaire'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- AI-Assisted FAIR Analysis Section (only shown when enabled) -->
                    <?php if ($fairAiAvailable): ?>
                    <div class="section-header" id="aiAnalysisSection">
                        <h3><?php echo e(t('fair-analysis.section_assisted')); ?></h3>
                    </div>
                    <div class="section-content">
                        <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;"><?php echo e(t('fair-analysis.assisted_desc')); ?></p>
                        <button type="button" id="aiAnalysisBtn" class="btn btn-secondary" style="margin-bottom: 15px;">
                            <img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);"><?php echo e(t('fair-analysis.btn_run_assisted')); ?>
                        </button>
                        <div id="aiAnalysisLoading" style="display: none; padding: 20px; text-align: center; color: #64748b;">
                            <div style="display: inline-block; width: 24px; height: 24px; border: 3px solid #e2e8f0; border-top: 3px solid #1e3a5f; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 8px;"></div>
                            <p style="margin: 0; font-size: 13px;"><?php echo e(t('fair-analysis.analyzing')); ?></p>
                            <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
                        </div>
                        <div id="aiResultsPanel" style="display: none;">
                            <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 15px;">
                                <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #92400e;"><?php echo e(t('fair-analysis.results_title')); ?></h4>
                                <div style="overflow-x: auto;">
                                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                        <thead>
                                            <tr style="border-bottom: 2px solid #fde68a;">
                                                <th style="text-align: left; padding: 6px 8px; color: #78716c;"><?php echo e(t('fair-analysis.th_metric')); ?></th>
                                                <th style="text-align: right; padding: 6px 8px; color: #78716c;"><?php echo e(t('fair-analysis.th_estimate')); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="aiResultsBody">
                                            <!-- Populated by JS -->
                                        </tbody>
                                    </table>
                                </div>
                                <div id="aiConfidenceRow" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #fde68a; font-size: 13px;">
                                    <strong><?php echo e(t('fair-analysis.lbl_confidence')); ?></strong> <span id="aiConfidence"></span>
                                </div>
                                <div id="aiReasoningRow" style="margin-top: 8px; font-size: 13px; color: #57534e;">
                                    <strong><?php echo e(t('fair-analysis.lbl_reasoning')); ?></strong> <span id="aiReasoning"></span>
                                </div>
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <button type="button" id="aiApplyBtn" class="btn btn-primary" style="font-size: 13px;"><?php echo e(t('fair-analysis.btn_apply_estimates')); ?></button>
                                    <button type="button" id="aiDismissBtn" class="btn btn-outline" style="font-size: 13px;"><?php echo e(t('fair-analysis.btn_dismiss')); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="fair_results.php" class="btn btn-outline"><?php echo e(t('fair-analysis.btn_cancel')); ?></a>
                        <button type="submit" name="save_draft" class="btn btn-secondary"><?php echo e(t('fair-analysis.btn_save_draft')); ?></button>
                        <button type="submit" name="submit" class="btn btn-primary"><?php echo e(t('fair-analysis.btn_submit')); ?></button>
                    </div>
                </form>
            </main>
        </div>

        <!-- Footer -->
        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span>All Rights Reserved</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Data Storage Warning Modal -->
    <?php if (!empty($analysis['_show_data_warning'])): ?>
    <div id="dataWarningModal" style="display: block; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);">
        <div style="background-color: #fff; margin: 10% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <div style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 20px; border-radius: 8px 8px 0 0;">
                <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">&#9888;</span>
                    <?php echo e(t('fair-analysis.modal_warning_title')); ?>
                </h3>
            </div>
            <div style="padding: 25px;">
                <p style="font-size: 16px; color: #333; margin: 0 0 15px 0;">
                    <strong><?php echo e(t('fair-analysis.modal_warning_strong')); ?></strong>
                </p>
                <p style="color: #666; margin: 0 0 20px 0;">
                    <?php echo e(t('fair-analysis.modal_warning_body')); ?>
                </p>
                <?php if (!empty($analysis['medium_of_data'])): ?>
                <p style="background: #fef3c7; padding: 12px; border-radius: 6px; color: #92400e; margin: 0 0 20px 0;">
                    <strong><?php echo e(t('fair-analysis.modal_data_location')); ?></strong> <?php echo e($analysis['medium_of_data']); ?>
                </p>
                <?php endif; ?>
                <div style="text-align: right;">
                    <button data-close="dataWarningModal"
                            style="background: #f59e0b; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">
                        <?php echo e(t('fair-analysis.modal_understand')); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Vendor autocomplete for both name and domain fields + enrichment
        (function() {
            const vendorNameInput = document.getElementById('vendor_name');
            const vendorNameResults = document.getElementById('vendor_name_results');
            const domainInput = document.getElementById('vendor_domain');
            const domainResults = document.getElementById('vendor_domain_results');
            const piiInput = document.getElementById('pii_record_count');
            const spiiInput = document.getElementById('spii_record_count');
            const soxInput = document.getElementById('sox_record_count');
            const businessImpactInput = document.getElementById('business_impact');
            const securityScoreInput = document.getElementById('security_score');
            const iso27001Input = document.getElementById('iso_27001_certified');
            const scopeOfWorkInput = document.getElementById('scope_of_work');
            const enrichmentStatus = document.getElementById('enrichmentStatus');

            let currentResults = [];
            let selectedIndex = -1;
            let activeResultsContainer = null;

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            function setupAutocomplete(input, resultsContainer) {
                if (!input || !resultsContainer) return;

                let debounceTimer = null;

                input.addEventListener('input', function() {
                    const query = this.value.trim();
                    if (debounceTimer) clearTimeout(debounceTimer);

                    if (query.length < 2) {
                        hideResults(resultsContainer);
                        return;
                    }

                    debounceTimer = setTimeout(function() {
                        searchVendors(query, resultsContainer);
                    }, 300);
                });

                input.addEventListener('keydown', function(e) {
                    if (!resultsContainer.classList.contains('active')) return;

                    const items = resultsContainer.querySelectorAll('.autocomplete-item');
                    if (items.length === 0) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelection(items);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, 0);
                        updateSelection(items);
                    } else if (e.key === 'Enter' && selectedIndex >= 0) {
                        e.preventDefault();
                        selectVendor(currentResults[selectedIndex]);
                    } else if (e.key === 'Escape') {
                        hideResults(resultsContainer);
                    }
                });

                input.addEventListener('blur', function() {
                    setTimeout(function() { hideResults(resultsContainer); }, 200);
                });
            }

            function searchVendors(query, resultsContainer) {
                activeResultsContainer = resultsContainer;
                fetch('api/search-fair-vendors.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.vendors.length > 0) {
                            currentResults = data.vendors;
                            showResults(data.vendors, resultsContainer);
                        } else {
                            hideResults(resultsContainer);
                        }
                    })
                    .catch(err => {
                        console.error('Search error:', err);
                        hideResults(resultsContainer);
                    });
            }

            function showResults(vendors, container) {
                container.innerHTML = '';
                selectedIndex = -1;

                vendors.forEach((vendor, index) => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-item';

                    const nameDisplay = vendor.name || '(No name)';
                    const domainDisplay = vendor.domain || '(No domain)';

                    div.innerHTML =
                        '<span class="vendor-source">' + (vendor.source === 'fair' ? 'FAIR' : 'Onboarding') + '</span>' +
                        '<div class="vendor-domain">' + escapeHtml(nameDisplay) + '</div>' +
                        '<div class="vendor-name">' + escapeHtml(domainDisplay) + '</div>';

                    div.addEventListener('click', function() {
                        selectVendor(vendor);
                    });

                    container.appendChild(div);
                });

                container.classList.add('active');
            }

            function hideResults(container) {
                if (container) {
                    container.classList.remove('active');
                }
                selectedIndex = -1;
            }

            function updateSelection(items) {
                items.forEach((item, i) => {
                    item.classList.toggle('selected', i === selectedIndex);
                });
                if (selectedIndex >= 0) {
                    items[selectedIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            // Show enrichment loading indicator
            function showEnrichmentLoading() {
                if (enrichmentStatus) {
                    enrichmentStatus.innerHTML = '<span class="enrichment-loading">Enriching vendor data...</span>';
                }
            }

            // Show enrichment results
            function showEnrichmentResult(count) {
                if (enrichmentStatus && count > 0) {
                    enrichmentStatus.innerHTML = '<span class="enrichment-badge">' + count + ' fields auto-populated from vendor data</span>';
                    setTimeout(function() { enrichmentStatus.innerHTML = ''; }, 5000);
                } else if (enrichmentStatus) {
                    enrichmentStatus.innerHTML = '';
                }
            }

            // Apply enrichment data from the API
            function applyEnrichmentData(data) {
                let fieldsPopulated = 0;

                // Helper: set a textarea/input value if it's currently empty
                function setIfEmpty(id, value) {
                    if (!value) return false;
                    var el = document.getElementById(id);
                    if (el && !el.value) {
                        el.value = value;
                        fieldsPopulated++;
                        return true;
                    }
                    return false;
                }

                // PII/SPII/SOX
                if (piiInput && (!piiInput.value || piiInput.value === '0') && data.pii_record_count > 0) {
                    piiInput.value = data.pii_record_count; fieldsPopulated++;
                }
                if (spiiInput && (!spiiInput.value || spiiInput.value === '0') && data.spii_record_count > 0) {
                    spiiInput.value = data.spii_record_count; fieldsPopulated++;
                }
                if (soxInput && (!soxInput.value || soxInput.value === '0') && data.sox_record_count > 0) {
                    soxInput.value = data.sox_record_count; fieldsPopulated++;
                }

                // Security score
                if (securityScoreInput && !securityScoreInput.value && data.security_score) {
                    securityScoreInput.value = data.security_score; fieldsPopulated++;
                }

                // ISO 27001
                if (iso27001Input && data.has_valid_iso_27001) {
                    iso27001Input.checked = true; fieldsPopulated++;
                }

                // Certifications
                setIfEmpty('certifications', data.certifications);

                // Third-party vendor list (from technologies)
                setIfEmpty('third_party_vendor_list', data.third_party_vendor_list);

                // Scope of work
                if (scopeOfWorkInput && !scopeOfWorkInput.value && data.scope_of_work) {
                    scopeOfWorkInput.value = data.scope_of_work; fieldsPopulated++;
                }

                // Business impact
                if (businessImpactInput && !businessImpactInput.value && data.business_impact) {
                    businessImpactInput.value = data.business_impact; fieldsPopulated++;
                }

                // Threat intelligence (enhanced with signal summary)
                setIfEmpty('threat_intelligence', data.threat_intelligence);

                // Fallback: legacy vulnerability_summary if no full threat_intelligence
                if (!data.threat_intelligence) {
                    var threatInput = document.getElementById('threat_intelligence');
                    if (threatInput && !threatInput.value && data.vulnerability_summary) {
                        threatInput.value = '[Shodan Findings] ' + data.vulnerability_summary;
                        fieldsPopulated++;
                    }
                }

                // New enrichment fields from SRS, assessments, onboarding
                setIfEmpty('vulnerability_data', data.vulnerability_data);
                setIfEmpty('configuration_data', data.configuration_data);
                setIfEmpty('msa', data.msa);
                setIfEmpty('risk_assessment', data.risk_assessment);
                setIfEmpty('vendor_risk_assessment', data.vendor_risk_assessment);
                setIfEmpty('compliance_data', data.compliance_data);
                setIfEmpty('medium_of_data', data.medium_of_data);
                setIfEmpty('security_questionnaire', data.security_questionnaire);
                setIfEmpty('vendor_performance', data.vendor_performance);
                setIfEmpty('supply_chain_risk_mgmt', data.supply_chain_risk_mgmt);

                // Assessment-mapped security control fields (only if not already filled)
                setIfEmpty('security_governance', data.security_governance);
                setIfEmpty('incident_response_plan', data.incident_response_plan);
                setIfEmpty('continuous_monitoring', data.continuous_monitoring);
                setIfEmpty('security_awareness_training', data.security_awareness_training);
                setIfEmpty('vulnerability_management', data.vulnerability_management);
                setIfEmpty('patch_management', data.patch_management);
                setIfEmpty('access_controls', data.access_controls);
                setIfEmpty('data_encryption', data.data_encryption);
                setIfEmpty('network_security', data.network_security);

                // Vendor cyber insurance coverage
                var insuranceInput = document.getElementById('vendor_cyber_insurance_coverage');
                if (insuranceInput && (!insuranceInput.value || insuranceInput.value === '0') && data.vendor_cyber_insurance_coverage) {
                    insuranceInput.value = data.vendor_cyber_insurance_coverage;
                    fieldsPopulated++;
                }

                // ISO 27001 inferred fields -- only fill empty fields (after assessment fields,
                // so assessment-specific data takes priority over generic ISO inferences)
                if (data.iso_inferred_fields) {
                    var inferredMap = {
                        'vulnerability_management': 'vulnerability_management',
                        'compliance': 'compliance',
                        'security_governance': 'security_governance',
                        'incident_response_plan': 'incident_response_plan',
                        'continuous_monitoring': 'continuous_monitoring',
                        'security_awareness_training': 'security_awareness_training',
                        'patch_management': 'patch_management',
                        'access_controls': 'access_controls',
                        'data_encryption': 'data_encryption',
                        'network_security': 'network_security'
                    };
                    for (var key in inferredMap) {
                        var el = document.getElementById(inferredMap[key]);
                        if (el && !el.value && data.iso_inferred_fields[key]) {
                            el.value = data.iso_inferred_fields[key];
                            fieldsPopulated++;
                        }
                    }
                }

                // Populate Security Intelligence Summary card
                populateIntelCard(data);

                return fieldsPopulated;
            }

            // Populate the Security Intelligence Summary card from enrichment data
            function populateIntelCard(data) {
                var card = document.getElementById('securityIntelCard');
                if (!card) return;

                var hasIntel = false;

                // Shodan section
                var shodanSection = document.getElementById('intelShodanSection');
                if (data.shodan_intel && data.shodan_intel.available) {
                    var si = data.shodan_intel;
                    document.getElementById('intelShodanScore').textContent = si.score + '/100 (Grade ' + si.grade + ')';

                    var positiveItems = [];
                    if (si.has_waf) positiveItems.push('WAF/CDN');
                    if (si.has_tls13) positiveItems.push('TLS 1.3');
                    if (si.has_hsts) positiveItems.push('HSTS');
                    if (si.has_enterprise_cloud) positiveItems.push('Enterprise Cloud');
                    if (si.has_no_cves) positiveItems.push('No CVEs');
                    if (si.has_dmarc_reject) positiveItems.push('DMARC Reject');
                    if (si.has_spf_hard_fail) positiveItems.push('SPF Hard Fail');

                    var negativeItems = [];
                    if (si.critical_cves > 0) negativeItems.push(si.critical_cves + ' Critical CVEs');
                    if (si.high_cves > 0) negativeItems.push(si.high_cves + ' High CVEs');
                    if (si.has_exposed_db) negativeItems.push('DB Exposed');
                    if (si.has_exposed_admin) negativeItems.push('Admin Exposed');
                    if (si.has_tls10) negativeItems.push('TLS 1.0');
                    if (si.has_no_dmarc) negativeItems.push('No DMARC');
                    if (si.has_no_spf) negativeItems.push('No SPF');
                    if (si.has_self_signed) negativeItems.push('Self-Signed Cert');
                    if (si.has_shared_hosting) negativeItems.push('Shared Hosting');

                    var posEl = document.getElementById('intelShodanPositive');
                    var negEl = document.getElementById('intelShodanNegative');
                    posEl.innerHTML = positiveItems.length > 0 ? positiveItems.map(function(s){return '<span style="margin-right:6px;">&#10003; '+s+'</span>';}).join('') : '';
                    negEl.innerHTML = negativeItems.length > 0 ? negativeItems.map(function(s){return '<span style="margin-right:6px;">&#10007; '+s+'</span>';}).join('') : '';

                    shodanSection.style.display = 'block';
                    hasIntel = true;
                }

                // UpGuard section
                var ugSection = document.getElementById('intelUpguardSection');
                if (data.upguard_intel && data.upguard_intel.available) {
                    var ui = data.upguard_intel;
                    document.getElementById('intelUpguardScore').textContent = ui.score + '/950 (Grade ' + ui.grade + ')';
                    document.getElementById('intelUpguardRisks').textContent =
                        'Critical: ' + ui.critical_risks + '  High: ' + ui.high_risks + '  Medium: ' + ui.medium_risks;
                    ugSection.style.display = 'block';
                    hasIntel = true;
                }

                // Revenue cap
                var rcSection = document.getElementById('intelRevCapSection');
                if (data.revenue_cap && data.revenue_cap > 0) {
                    document.getElementById('intelRevCap').textContent = '$' + data.revenue_cap.toLocaleString('en-US', {maximumFractionDigits: 0}) + ' max ALE';
                    rcSection.style.display = 'block';
                    hasIntel = true;
                }

                card.style.display = hasIntel ? 'block' : 'none';
            }

            function selectVendor(vendor) {
                // Fill vendor name and domain immediately
                if (vendorNameInput) vendorNameInput.value = vendor.name || '';
                if (domainInput) domainInput.value = vendor.domain || '';

                // Fill basic fields from search results
                if (piiInput && (!piiInput.value || piiInput.value === '0') && vendor.pii_record_count != null) {
                    piiInput.value = vendor.pii_record_count;
                }
                if (spiiInput && (!spiiInput.value || spiiInput.value === '0') && vendor.spii_record_count != null) {
                    spiiInput.value = vendor.spii_record_count;
                }
                if (soxInput && (!soxInput.value || soxInput.value === '0') && vendor.sox_record_count != null) {
                    soxInput.value = vendor.sox_record_count;
                }
                if (businessImpactInput && !businessImpactInput.value && vendor.business_impact) {
                    businessImpactInput.value = vendor.business_impact;
                }
                if (securityScoreInput && !securityScoreInput.value && vendor.security_score) {
                    securityScoreInput.value = vendor.security_score;
                }
                if (iso27001Input && vendor.iso_27001_certified) {
                    iso27001Input.checked = vendor.iso_27001_certified == 1;
                }
                if (scopeOfWorkInput && !scopeOfWorkInput.value && vendor.scope_of_work) {
                    scopeOfWorkInput.value = vendor.scope_of_work;
                }

                hideResults(vendorNameResults);
                hideResults(domainResults);

                // Call enrichment API for additional data (technologies, certs, ISO inference)
                showEnrichmentLoading();
                fetch('api/enrich-fair-vendor.php?vendor_name=' + encodeURIComponent(vendor.name || '') +
                      '&vendor_domain=' + encodeURIComponent(vendor.domain || ''))
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result.success && result.data) {
                            var count = applyEnrichmentData(result.data);
                            showEnrichmentResult(count);
                        } else {
                            showEnrichmentResult(0);
                        }
                    })
                    .catch(function() { showEnrichmentResult(0); });

                // Also fetch SRS data if domain exists (for vuln/config data from UpGuard)
                if (vendor.domain) {
                    fetchSRSData(vendor.domain);
                }
            }

            // SRS data lookup
            const srsAvailable = <?php echo $srsAvailable ? 'true' : 'false'; ?>;
            const vulnerabilityDataInput = document.getElementById('vulnerability_data');
            const configurationDataInput = document.getElementById('configuration_data');
            let srsLookupTimer = null;

            function fetchSRSData(domain) {
                if (!srsAvailable || !domain) return;

                fetch('api/get-srs-data.php?domain=' + encodeURIComponent(domain))
                    .then(response => response.json())
                    .then(result => {
                        if (result.success && result.data) {
                            applySRSData(result.data);
                        }
                    })
                    .catch(err => {
                        console.error('SRS lookup error:', err);
                    });
            }

            function applySRSData(data) {
                if (securityScoreInput && data.grade) {
                    if (!securityScoreInput.value) {
                        securityScoreInput.value = data.grade;
                    }
                }

                if (vulnerabilityDataInput && data.vulnerability_data) {
                    if (vulnerabilityDataInput.value && !vulnerabilityDataInput.value.includes('[SRS Data]')) {
                        vulnerabilityDataInput.value = '[SRS Data - Score: ' + data.score + ' (' + data.grade + ')]\n' +
                            data.vulnerability_data + '\n\n' +
                            '[Previous Notes]\n' + vulnerabilityDataInput.value;
                    } else if (!vulnerabilityDataInput.value) {
                        vulnerabilityDataInput.value = '[SRS Data - Score: ' + data.score + ' (' + data.grade + ')]\n' +
                            data.vulnerability_data;
                    }
                }

                if (configurationDataInput && data.configuration_data) {
                    if (configurationDataInput.value && !configurationDataInput.value.includes('[SRS Data]')) {
                        configurationDataInput.value = '[SRS Data - Score: ' + data.score + ' (' + data.grade + ')]\n' +
                            data.configuration_data + '\n\n' +
                            '[Previous Notes]\n' + configurationDataInput.value;
                    } else if (!configurationDataInput.value) {
                        configurationDataInput.value = '[SRS Data - Score: ' + data.score + ' (' + data.grade + ')]\n' +
                            data.configuration_data;
                    }
                }
            }

            // Trigger SRS lookup when domain field loses focus
            if (domainInput && srsAvailable) {
                domainInput.addEventListener('blur', function() {
                    const domain = this.value.trim();
                    if (domain && domain.includes('.')) {
                        if (srsLookupTimer) clearTimeout(srsLookupTimer);
                        srsLookupTimer = setTimeout(function() {
                            fetchSRSData(domain);
                        }, 500);
                    }
                });
            }

            // Initialize autocomplete on both fields
            setupAutocomplete(vendorNameInput, vendorNameResults);
            setupAutocomplete(domainInput, domainResults);

            // Auto-enrich on page load if editing an existing analysis with a vendor.
            // This fills any empty fields from onboarding, SRS, Shodan, assessments, etc.
            // The applyEnrichmentData() function only sets fields that are currently empty,
            // so existing data is never overwritten.
            var isEditMode = <?php echo json_encode($editMode); ?>;
            if (isEditMode && vendorNameInput && vendorNameInput.value) {
                showEnrichmentLoading();
                fetch('api/enrich-fair-vendor.php?vendor_name=' + encodeURIComponent(vendorNameInput.value) +
                      '&vendor_domain=' + encodeURIComponent((domainInput && domainInput.value) || ''))
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result.success && result.data) {
                            var count = applyEnrichmentData(result.data);
                            showEnrichmentResult(count);
                        } else {
                            showEnrichmentResult(0);
                        }
                    })
                    .catch(function() { showEnrichmentResult(0); });

                // Also fetch SRS data for vulnerability/configuration fields
                if (domainInput && domainInput.value) {
                    fetchSRSData(domainInput.value);
                }
            }
        })();

        // ================================================================
        // AI-Assisted FAIR Analysis Functions
        // ================================================================
        var fairAiCsrf = <?php echo json_encode($csrfToken); ?>;
        var lastAiEstimates = null;

        function runAIAnalysis() {
            var btn = document.getElementById('aiAnalysisBtn');
            var loading = document.getElementById('aiAnalysisLoading');
            var resultsPanel = document.getElementById('aiResultsPanel');
            if (!btn) return;

            // Collect form data to send to the AI endpoint
            var formData = {
                csrf_token: fairAiCsrf,
                vendor_name: (document.getElementById('vendor_name') || {}).value || '',
                vendor_domain: (document.getElementById('vendor_domain') || {}).value || '',
                security_score: (document.getElementById('security_score') || {}).value || 'C',
                iso_27001_certified: (document.getElementById('iso_27001_certified') || {}).checked ? 1 : 0,
                pii_record_count: parseInt((document.getElementById('pii_record_count') || {}).value) || 0,
                spii_record_count: parseInt((document.getElementById('spii_record_count') || {}).value) || 0,
                sox_record_count: parseInt((document.getElementById('sox_record_count') || {}).value) || 0,
                data_classification: (document.getElementById('data_classification') || {}).value || '',
                data_sharing: (document.getElementById('medium_of_data') || {}).value || '',
                business_impact: (document.getElementById('business_impact') || {}).value || '0',
                vulnerability_management: (document.getElementById('vulnerability_management') || {}).value || '',
                patch_management: (document.getElementById('patch_management') || {}).value || '',
                access_controls: (document.getElementById('access_controls') || {}).value || '',
                threat_intelligence: (document.getElementById('threat_intelligence') || {}).value || '',
                vendor_cyber_insurance_coverage: (document.getElementById('vendor_cyber_insurance_coverage') || {}).value || '0'
            };

            if (!formData.vendor_name) {
                alert('Please select or enter a vendor name before running analysis.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<img src="app/icons/hourglass-01.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Analyzing...';
            loading.style.display = 'block';
            resultsPanel.style.display = 'none';

            fetch('api/generate-fair-ai-analysis.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(formData)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_token) {
                    fairAiCsrf = data.csrf_token;
                    var hiddenCsrf = document.querySelector('input[name="csrf_token"]');
                    if (hiddenCsrf) hiddenCsrf.value = data.csrf_token;
                }

                if (data.error) {
                    btn.disabled = false;
                    btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Run Assisted Analysis';
                    loading.style.display = 'none';
                    alert('Analysis Error:' + data.error);
                    return;
                }

                if (data.queued && data.job_id) {
                    pollAiJob(data.job_id, btn, loading, resultsPanel);
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Run Assisted Analysis';
                loading.style.display = 'none';
                alert('Failed to run analysis. Please try again.');
                console.error('Analysis error:', err);
            });
        }

        function pollAiJob(jobId, btn, loading, resultsPanel) {
            var pollInterval = setInterval(function() {
                fetch('api/ai-job-status.php?id=' + jobId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.csrf_token) {
                        fairAiCsrf = data.csrf_token;
                        var hiddenCsrf = document.querySelector('input[name="csrf_token"]');
                        if (hiddenCsrf) hiddenCsrf.value = data.csrf_token;
                    }

                    if (data.status === 'completed' && data.result) {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Run Assisted Analysis';
                        loading.style.display = 'none';

                        if (data.result.ai_estimates) {
                            lastAiEstimates = data.result.ai_estimates;
                            displayAIResults(data.result.ai_estimates);
                        }
                    } else if (data.status === 'failed') {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Run Assisted Analysis';
                        loading.style.display = 'none';
                        alert('Analysis Error:' + (data.error || 'Processing failed. Please try again.'));
                    }
                    // pending/processing: keep polling
                })
                .catch(function() {
                    clearInterval(pollInterval);
                    btn.disabled = false;
                    btn.innerHTML = '<img src="app/icons/lightning-02.svg" alt="" width="16" height="16" style="vertical-align: middle; margin-right: 6px; filter: brightness(0) invert(0.3);">Run Assisted Analysis';
                    loading.style.display = 'none';
                    alert('Failed to check analysis status. Please try again.');
                });
            }, 3000);
        }

        function displayAIResults(estimates) {
            var tbody = document.getElementById('aiResultsBody');
            var panel = document.getElementById('aiResultsPanel');
            if (!tbody || !panel) return;

            var rows = [
                ['Threat Event Frequency', estimates.threat_event_frequency.toFixed(2) + ' events/yr'],
                ['Vulnerability Score', (estimates.vulnerability_score * 100).toFixed(1) + '%'],
                ['Loss Event Frequency', estimates.loss_event_frequency.toFixed(2) + ' breaches/yr'],
                ['Primary Loss Magnitude', '$' + Math.round(estimates.primary_loss_magnitude).toLocaleString()],
                ['Secondary Loss Magnitude', '$' + Math.round(estimates.secondary_loss_magnitude).toLocaleString()],
                ['Annualized Loss Expectancy (ALE)', '$' + Math.round(estimates.annualized_loss_expectancy).toLocaleString()],
                ['Recommended Cyber Insurance', '$' + Math.round(estimates.recommended_cyber_insurance).toLocaleString()],
                ['Risk Level', '<span style="font-weight:600;">' + estimates.risk_level + '</span>']
            ];

            if (estimates.revenue_cap_applied) {
                rows.push(['Revenue Cap Applied', 'Yes - ALE capped to revenue limit']);
            }

            tbody.innerHTML = '';
            for (var i = 0; i < rows.length; i++) {
                var tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #fef3c7';
                tr.innerHTML = '<td style="padding:6px 8px;color:#57534e;">' + rows[i][0] + '</td>' +
                    '<td style="padding:6px 8px;text-align:right;font-weight:500;color:#1e3a5f;">' + rows[i][1] + '</td>';
                tbody.appendChild(tr);
            }

            // Confidence and reasoning
            var confEl = document.getElementById('aiConfidence');
            var reasonEl = document.getElementById('aiReasoning');
            if (confEl) {
                var confColor = estimates.confidence === 'High' ? '#059669' : (estimates.confidence === 'Low' ? '#dc2626' : '#d97706');
                confEl.innerHTML = '<span style="color:' + confColor + ';font-weight:600;">' + estimates.confidence + '</span>';
            }
            if (reasonEl) {
                reasonEl.textContent = estimates.reasoning || '';
            }

            panel.style.display = 'block';
            panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }

        function applyAIEstimates() {
            if (!lastAiEstimates) return;

            // Populate hidden form fields so AI values survive form submission
            document.getElementById('ai_override_applied').value = '1';
            document.getElementById('ai_ale').value = lastAiEstimates.annualized_loss_expectancy || 0;
            document.getElementById('ai_lef').value = lastAiEstimates.loss_event_frequency || 0;
            document.getElementById('ai_primary_loss').value = lastAiEstimates.primary_loss_magnitude || 0;
            document.getElementById('ai_secondary_loss').value = lastAiEstimates.secondary_loss_magnitude || 0;
            document.getElementById('ai_loss_magnitude').value =
                (parseFloat(lastAiEstimates.primary_loss_magnitude) || 0) +
                (parseFloat(lastAiEstimates.secondary_loss_magnitude) || 0);
            document.getElementById('ai_recommended_liability').value = lastAiEstimates.recommended_cyber_insurance || 0;
            document.getElementById('ai_risk_level').value = lastAiEstimates.risk_level || '';

            // Also store the AI recommendation as a note in the threat_intelligence field
            var threatInput = document.getElementById('threat_intelligence');
            if (threatInput) {
                var aiNote = '[FAIR Analysis] Risk Level: ' + lastAiEstimates.risk_level +
                    ', ALE: $' + Math.round(lastAiEstimates.annualized_loss_expectancy).toLocaleString() +
                    ', Recommended Insurance: $' + Math.round(lastAiEstimates.recommended_cyber_insurance).toLocaleString() +
                    ', Confidence: ' + lastAiEstimates.confidence +
                    '. ' + lastAiEstimates.reasoning;
                if (threatInput.value && !threatInput.value.includes('[FAIR Analysis]')) {
                    threatInput.value = aiNote + '\n\n' + threatInput.value;
                } else if (!threatInput.value) {
                    threatInput.value = aiNote;
                }
            }

            // Show confirmation
            var panel = document.getElementById('aiResultsPanel');
            if (panel) {
                panel.innerHTML = '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;font-size:13px;color:#065f46;">' +
                    '<strong>FAIR estimates applied.</strong> The financial values (ALE, LEF, loss magnitudes) will be used when you submit this form.</div>';
                setTimeout(function() { panel.style.display = 'none'; }, 8000);
            }
        }

        function dismissAIResults() {
            var panel = document.getElementById('aiResultsPanel');
            if (panel) panel.style.display = 'none';
            lastAiEstimates = null;
        }

        // Bind AI buttons via addEventListener (onclick attributes blocked by CSP nonce policy)
        (function() {
            var aiBtn = document.getElementById('aiAnalysisBtn');
            if (aiBtn) aiBtn.addEventListener('click', runAIAnalysis);
            var applyBtn = document.getElementById('aiApplyBtn');
            if (applyBtn) applyBtn.addEventListener('click', applyAIEstimates);
            var dismissBtn = document.getElementById('aiDismissBtn');
            if (dismissBtn) dismissBtn.addEventListener('click', dismissAIResults);
        })();
    </script>
    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
