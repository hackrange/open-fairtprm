<?php
/**
 * FAIR Vendor Enrichment API
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * When the user picks a vendor from the FAIR analysis typeahead, this endpoint
 * pulls together ALL the data we know about that vendor from every source we have:
 * onboarding records, SRS scores, technologies (4th party), documents/certs,
 * assessments, and Shodan findings. One fetch to rule them all instead of making
 * the browser play twenty questions with the server.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/init.php';

if (!Auth::getInstance()->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();

// Permission check -- same as search-fair-vendors.php
$canAccess = $acl->hasPermission('analysis.create') ||
             $acl->hasPermission('analysis.read') ||
             $acl->hasGroup('administrator') ||
             $acl->hasGroup('cyber_tprm');

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$vendorName = trim($_GET['vendor_name'] ?? '');
$vendorDomain = trim($_GET['vendor_domain'] ?? '');

if (empty($vendorName) && empty($vendorDomain)) {
    echo json_encode(['success' => false, 'error' => 'vendor_name or vendor_domain required']);
    exit;
}

require_once __DIR__ . '/../includes/classes/SRSService.php';

try {
    $data = [];

    // ---------------------------------------------------------------
    // 1. ONBOARDING REQUEST
    // Find the vendor record -- try name+domain first, then name only
    // ---------------------------------------------------------------
    $onboarding = null;
    if (!empty($vendorName) && !empty($vendorDomain)) {
        $onboarding = $db->fetchOne(
            "SELECT * FROM vendor_onboarding_requests
             WHERE vendor_name = :name AND vendor_domain = :domain AND status != 'inactive'
             ORDER BY updated_at DESC LIMIT 1",
            [':name' => $vendorName, ':domain' => $vendorDomain]
        );
    }
    if (!$onboarding && !empty($vendorName)) {
        $onboarding = $db->fetchOne(
            "SELECT * FROM vendor_onboarding_requests
             WHERE vendor_name = :name AND status != 'inactive'
             ORDER BY updated_at DESC LIMIT 1",
            [':name' => $vendorName]
        );
    }
    if (!$onboarding && !empty($vendorDomain)) {
        $onboarding = $db->fetchOne(
            "SELECT * FROM vendor_onboarding_requests
             WHERE vendor_domain = :domain AND status != 'inactive'
             ORDER BY updated_at DESC LIMIT 1",
            [':domain' => $vendorDomain]
        );
    }

    // ---------------------------------------------------------------
    // 2. TECHNOLOGIES (Fourth-Party)
    // Moved outside onboarding block -- technologies can be fetched
    // by vendor name or domain even without an onboarding record.
    // ---------------------------------------------------------------
    try {
        if (class_exists('ShodanService') || file_exists(__DIR__ . '/../includes/classes/ShodanService.php')) {
            require_once __DIR__ . '/../includes/classes/ShodanService.php';
            $shodanService = new ShodanService();
            $technologies = [];

            // Try domain first (most specific), then name
            if (!empty($vendorDomain)) {
                $technologies = $shodanService->getTechnologiesByVendor($vendorDomain, 50);
            }
            if (empty($technologies) && !empty($vendorName)) {
                $technologies = $shodanService->getTechnologiesByVendor($vendorName, 50);
            }

            if (!empty($technologies)) {
                // Extract unique technology names (these are the 4th-party dependencies)
                $techNames = [];
                foreach ($technologies as $tech) {
                    $tn = $tech['technology_name'] ?? '';
                    if (!empty($tn) && !in_array($tn, $techNames)) {
                        $techNames[] = $tn;
                    }
                }
                if (!empty($techNames)) {
                    $data['third_party_vendor_list'] = implode(', ', $techNames);
                }
            }
        }
    } catch (Exception $e) {
        // Technologies are optional, don't fail the whole enrichment
    }

    if ($onboarding) {
        $data['onboarding_id'] = (int)$onboarding['id'];
        $data['pii_record_count'] = (int)($onboarding['pii_record_count'] ?? 0);
        $data['spii_record_count'] = (int)($onboarding['spii_record_count'] ?? 0);
        $data['sox_record_count'] = (int)($onboarding['sox_record_count'] ?? 0);
        $data['business_impact'] = $onboarding['business_impact'] ?? '';
        $data['scope_of_work'] = $onboarding['product_service_description'] ?? '';

        // ---------------------------------------------------------------
        // 3. COMBINED SRS SCORE
        // ---------------------------------------------------------------
        $srsService = new SRSService();
        $combined = $srsService->getCombinedScore($onboarding);
        $data['security_score'] = $combined['grade'];
        $data['combined_score_pct'] = $combined['score'];

        // ---------------------------------------------------------------
        // 3a. VENDOR RISK ASSESSMENT (onboarding context)
        // Build the same context string the server-side PHP builds for
        // from_onboarding mode -- confidential info, cross-border, etc.
        // ---------------------------------------------------------------
        $additionalContext = [];
        if (!empty($onboarding['confidential_info_shared']) && $onboarding['confidential_info_shared'] === 'yes') {
            $additionalContext[] = "Confidential Info Shared: Yes" .
                (!empty($onboarding['confidential_info_justification']) ? " - " . $onboarding['confidential_info_justification'] : '');
        }
        if (!empty($onboarding['cross_border_transfer']) && $onboarding['cross_border_transfer'] === 'yes') {
            $additionalContext[] = "Cross-Border Data Transfer: Yes" .
                (!empty($onboarding['cross_border_justification']) ? " - " . $onboarding['cross_border_justification'] : '');
        }
        if (!empty($onboarding['offsite_data_hosting']) && $onboarding['offsite_data_hosting'] === 'yes') {
            $additionalContext[] = "Off-site Data Hosting: Yes" .
                (!empty($onboarding['offsite_data_justification']) ? " - " . $onboarding['offsite_data_justification'] : '');
        }
        if (!empty($onboarding['remote_network_access']) && $onboarding['remote_network_access'] === 'yes') {
            $additionalContext[] = "Remote Network Access: Yes" .
                (!empty($onboarding['remote_access_justification']) ? " - " . $onboarding['remote_access_justification'] : '');
        }
        if (!empty($onboarding['critical_business_function']) && $onboarding['critical_business_function'] === 'yes') {
            $additionalContext[] = "Critical Business Function: Yes" .
                (!empty($onboarding['critical_function_justification']) ? " - " . $onboarding['critical_function_justification'] : '');
        }
        if (!empty($onboarding['is_saas']) && $onboarding['is_saas'] === 'yes') {
            $additionalContext[] = "SaaS Product: Yes";
        }
        if (!empty($onboarding['unauthorized_disclosure_impact'])) {
            $additionalContext[] = "Unauthorized Disclosure Impact: " . ucfirst($onboarding['unauthorized_disclosure_impact']);
        }
        if (!empty($onboarding['unauthorized_modification_impact'])) {
            $additionalContext[] = "Unauthorized Modification Impact: " . ucfirst($onboarding['unauthorized_modification_impact']);
        }
        if (!empty($onboarding['disruption_impact'])) {
            $additionalContext[] = "Disruption Impact: " . ucfirst($onboarding['disruption_impact']);
        }

        if (!empty($additionalContext)) {
            $data['vendor_risk_assessment'] = "From Vendor Onboarding Request #" . $onboarding['id'] . ":\n\n" . implode("\n", $additionalContext);
        }
        if (!empty($onboarding['additional_information'])) {
            $data['vendor_risk_assessment'] = ($data['vendor_risk_assessment'] ?? '') .
                "\n\nAdditional Information:\n" . $onboarding['additional_information'];
        }

        // ---------------------------------------------------------------
        // 4. DOCUMENTS / CERTIFICATIONS + MSA/CONTRACTS
        // ---------------------------------------------------------------
        $vendorId = (int)$onboarding['id'];
        try {
            $docs = $db->fetchAll(
                "SELECT document_type, certification_type, certification_expiration_date, file_name
                 FROM vendor_documents
                 WHERE vendor_id = :vid AND is_active = 1
                 ORDER BY created_at DESC",
                [':vid' => $vendorId]
            );

            $certsList = [];
            $contractTypes = [];
            $hasValidIso27001 = false;

            foreach ($docs as $doc) {
                if (!empty($doc['certification_type'])) {
                    $certName = $doc['certification_type'];
                    $expiry = $doc['certification_expiration_date'] ?? null;

                    // Check for valid ISO 27001
                    if (stripos($certName, 'ISO') !== false && stripos($certName, '27001') !== false) {
                        if ($expiry && strtotime($expiry) > time()) {
                            $hasValidIso27001 = true;
                            $certsList[] = $certName . ' (expires ' . date('Y-m-d', strtotime($expiry)) . ')';
                        } elseif (!$expiry) {
                            // No expiry date -- assume valid
                            $hasValidIso27001 = true;
                            $certsList[] = $certName;
                        } else {
                            $certsList[] = $certName . ' (expired)';
                        }
                    } else {
                        if ($expiry && strtotime($expiry) > time()) {
                            $certsList[] = $certName . ' (expires ' . date('Y-m-d', strtotime($expiry)) . ')';
                        } elseif ($expiry) {
                            $certsList[] = $certName . ' (expired)';
                        } else {
                            $certsList[] = $certName;
                        }
                    }
                }

                if ($doc['document_type'] === 'contract') {
                    $contractTypes[] = $doc['file_name'] ?? 'Contract';
                }
            }

            $data['has_valid_iso_27001'] = $hasValidIso27001;
            if (!empty($certsList)) {
                $data['certifications'] = implode(', ', $certsList);
            }
            if (!empty($contractTypes)) {
                $data['contract_types'] = implode(', ', $contractTypes);
                $data['msa'] = "Active contracts: " . implode(', ', $contractTypes);
            }
        } catch (Exception $e) {
            // Documents table might not exist or have different schema
        }

        // ---------------------------------------------------------------
        // 4a. SRS / UPGUARD RISK DATA -> vulnerability_data, configuration_data
        // Pre-categorized risk findings from SRSService
        // ---------------------------------------------------------------
        try {
            $vendorDomainForSRS = $onboarding['vendor_domain'] ?? $vendorDomain;
            if (!empty($vendorDomainForSRS)) {
                $srsVendorData = $srsService->getVendorDataByDomain($vendorDomainForSRS);
                if ($srsVendorData) {
                    if (!empty($srsVendorData['vulnerability_data'])) {
                        $data['vulnerability_data'] = '[SRS Data - Score: ' . $srsVendorData['score'] . ' (' . $srsVendorData['grade'] . ")] \n" . $srsVendorData['vulnerability_data'];
                    }
                    if (!empty($srsVendorData['configuration_data'])) {
                        $data['configuration_data'] = '[SRS Data - Score: ' . $srsVendorData['score'] . ' (' . $srsVendorData['grade'] . ")] \n" . $srsVendorData['configuration_data'];
                    }
                }
            }
        } catch (Exception $e) {
            // SRS data is optional
        }

        // ---------------------------------------------------------------
        // 5. ASSESSMENT RESPONSES
        // Look for completed assessments (ISO 27001 or Tier 2) and
        // extract relevant answers for FAIR fields
        // ---------------------------------------------------------------
        try {
            $assessments = $db->fetchAll(
                "SELECT va.id, va.status, at.name as template_name, at.slug as template_slug, va.completed_at,
                        va.certificate_uploaded, va.certificate_expiry
                 FROM vendor_assessments va
                 JOIN assessment_templates at ON at.id = va.template_id
                 WHERE va.vendor_id = :vid AND va.status = 'completed'
                 ORDER BY va.completed_at DESC",
                [':vid' => $vendorId]
            );

            foreach ($assessments as $assessment) {
                $templateName = strtolower($assessment['template_name'] ?? '');
                $templateSlug = $assessment['template_slug'] ?? '';

                // Look for ISO 27001 assessments
                if (strpos($templateName, 'iso') !== false && strpos($templateName, '27001') !== false) {
                    $data['has_valid_iso_27001'] = true;
                }

                // Fetch and map assessment responses for each completed assessment
                $assessmentResponses = $db->fetchAll(
                    "SELECT q.question_text, r.response_value, s.name as section_name
                     FROM vendor_assessment_responses r
                     JOIN assessment_questions q ON r.question_id = q.id
                     JOIN assessment_sections s ON q.section_id = s.id
                     WHERE r.assessment_id = :assessment_id
                     ORDER BY s.sort_order, q.sort_order",
                    [':assessment_id' => $assessment['id']]
                );

                if (!$assessmentResponses) continue;

                // Build full questionnaire summary for security_questionnaire field
                $sectionSummaries = [];
                foreach ($assessmentResponses as $resp) {
                    if (!empty($resp['response_value'])) {
                        $sectionSummaries[$resp['section_name']][] = $resp['question_text'] . ': ' . $resp['response_value'];
                    }
                }

                if (!empty($sectionSummaries)) {
                    $summaryText = "From " . ($assessment['template_name'] ?? $templateSlug) . " Assessment";
                    if (!empty($assessment['completed_at'])) {
                        $summaryText .= " (Completed: " . date('M j, Y', strtotime($assessment['completed_at'])) . ")";
                    }
                    $summaryText .= ":\n\n";
                    foreach ($sectionSummaries as $section => $items) {
                        $summaryText .= "[$section]\n" . implode("\n", $items) . "\n\n";
                    }

                    // Append to security_questionnaire (accumulates across assessments)
                    $data['security_questionnaire'] = ($data['security_questionnaire'] ?? '') . $summaryText;

                    // Also append to vendor_risk_assessment for context
                    $data['vendor_risk_assessment'] = ($data['vendor_risk_assessment'] ?? '') . "\n\n" . $summaryText;
                }

                // Map individual responses to specific FAIR fields
                foreach ($assessmentResponses as $resp) {
                    if (empty($resp['response_value'])) continue;
                    $questionText = strtolower($resp['question_text']);
                    $responseValue = $resp['response_value'];

                    // --- Tier 2 Mappings ---
                    if ($templateSlug === 'tier-2-vendor') {
                        if (strpos($questionText, 'security awareness training') !== false && empty($data['security_awareness_training'])) {
                            $data['security_awareness_training'] = $responseValue === 'Yes'
                                ? 'Yes - Employees are required to complete security awareness training.'
                                : 'No - Security awareness training not required.';
                        }
                        if (strpos($questionText, 'use a firewall') !== false && empty($data['network_security'])) {
                            $data['network_security'] = $responseValue === 'Yes' ? 'Firewalls are used.' : 'No firewall in use.';
                        }
                        if (strpos($questionText, 'applying security patches') !== false && empty($data['patch_management'])) {
                            if ($responseValue === 'Yes, automated') {
                                $data['patch_management'] = 'Yes, automated - Vendor has automated patch management process.';
                            } elseif ($responseValue === 'Yes, manual') {
                                $data['patch_management'] = 'Yes, manual - Vendor has manual patch management process.';
                            } else {
                                $data['patch_management'] = 'No - No formal patch management process.';
                            }
                        }
                        if (strpos($questionText, 'information security policy') !== false && empty($data['security_governance'])) {
                            if ($responseValue === 'Yes') {
                                $data['security_governance'] = 'Yes - Vendor has a documented information security policy.';
                            } elseif ($responseValue === 'In Development') {
                                $data['security_governance'] = 'In Development - Information security policy is being developed.';
                            } else {
                                $data['security_governance'] = 'No - No documented information security policy.';
                            }
                        }
                        if ((strpos($questionText, 'antivirus') !== false || strpos($questionText, 'anti-malware') !== false) && empty($data['vulnerability_management'])) {
                            $data['vulnerability_management'] = $responseValue === 'Yes'
                                ? 'Antivirus/anti-malware software is in use.'
                                : 'No antivirus/anti-malware software.';
                        }
                        if (strpos($questionText, 'encrypt data at rest and in transit') !== false && empty($data['data_encryption'])) {
                            $data['data_encryption'] = $responseValue;
                        }
                        if (strpos($questionText, 'where will the data be stored') !== false && empty($data['medium_of_data'])) {
                            if (!empty($responseValue) && $responseValue !== 'Not Applicable') {
                                $data['medium_of_data'] = $responseValue;
                            }
                        }
                        if (strpos($questionText, 'cyber liability insurance') !== false && empty($data['vendor_cyber_insurance_coverage'])) {
                            if ($responseValue === 'Yes') {
                                $data['vendor_cyber_insurance_coverage'] = '500000';
                            }
                        }
                        if (strpos($questionText, 'data retention and disposal') !== false && empty($data['compliance_data'])) {
                            $data['compliance_data'] = "Data Retention Policy: {$responseValue}";
                        }
                        if (strpos($questionText, 'description of services') !== false && empty($data['scope_of_work'])) {
                            $data['scope_of_work'] = $responseValue;
                        }
                    }

                    // --- ISO 27001 Mappings ---
                    if ($templateSlug === 'iso-27001-2022') {
                        if (strpos($questionText, 'documented information security policy') !== false && empty($data['security_governance'])) {
                            $data['security_governance'] = "Information Security Policy: {$responseValue}";
                        }
                        if (strpos($questionText, 'security awareness training program') !== false && empty($data['security_awareness_training'])) {
                            $data['security_awareness_training'] = $responseValue;
                        }
                        if (strpos($questionText, 'data encrypted at rest') !== false && empty($data['data_encryption'])) {
                            $data['data_encryption'] = "At Rest: {$responseValue}";
                        }
                        if (strpos($questionText, 'data encrypted in transit') !== false) {
                            $existing = $data['data_encryption'] ?? '';
                            if (empty($existing)) {
                                $data['data_encryption'] = "In Transit: {$responseValue}";
                            } elseif (strpos($existing, 'In Transit') === false) {
                                $data['data_encryption'] = $existing . "; In Transit: {$responseValue}";
                            }
                        }
                        if (strpos($questionText, 'principle of least privilege') !== false && empty($data['access_controls'])) {
                            $data['access_controls'] = "Least Privilege: {$responseValue}";
                        }
                        if (strpos($questionText, 'multi-factor authentication') !== false) {
                            $existing = $data['access_controls'] ?? '';
                            if (empty($existing)) {
                                $data['access_controls'] = "MFA: {$responseValue}";
                            } elseif (strpos($existing, 'MFA') === false) {
                                $data['access_controls'] = $existing . "; MFA: {$responseValue}";
                            }
                        }
                        if (strpos($questionText, 'incident response plan') !== false && empty($data['incident_response_plan'])) {
                            $data['incident_response_plan'] = "Incident Response Plan: {$responseValue}";
                        }
                        if (strpos($questionText, 'notify affected parties') !== false) {
                            $existing = $data['incident_response_plan'] ?? '';
                            if (empty($existing)) {
                                $data['incident_response_plan'] = "Breach Notification: {$responseValue}";
                            } elseif (strpos($existing, 'Notification') === false) {
                                $data['incident_response_plan'] = $existing . "; Breach Notification: {$responseValue}";
                            }
                        }
                        if (strpos($questionText, 'vulnerability assessment') !== false && empty($data['vulnerability_management'])) {
                            $data['vulnerability_management'] = "Vulnerability Assessments: {$responseValue}";
                        }
                        if (strpos($questionText, 'penetration testing') !== false) {
                            $existing = $data['vulnerability_management'] ?? '';
                            if (empty($existing)) {
                                $data['vulnerability_management'] = "Penetration Testing: {$responseValue}";
                            } elseif (strpos($existing, 'Penetration') === false) {
                                $data['vulnerability_management'] = $existing . "; Penetration Testing: {$responseValue}";
                            }
                        }
                        if (strpos($questionText, 'business continuity plan') !== false && empty($data['continuous_monitoring'])) {
                            $data['continuous_monitoring'] = "Business Continuity Plan: {$responseValue}";
                        }
                        if (strpos($questionText, 'disaster recovery plan') !== false) {
                            $existing = $data['continuous_monitoring'] ?? '';
                            if (empty($existing)) {
                                $data['continuous_monitoring'] = "Disaster Recovery Plan: {$responseValue}";
                            } elseif (strpos($existing, 'Disaster Recovery') === false) {
                                $data['continuous_monitoring'] = $existing . "; Disaster Recovery Plan: {$responseValue}";
                            }
                        }
                        if (strpos($questionText, 'compliance frameworks') !== false && empty($data['compliance_data'])) {
                            $data['compliance_data'] = "Frameworks: {$responseValue}";
                        }
                        if (strpos($questionText, 'change management') !== false && empty($data['supply_chain_risk_mgmt'])) {
                            $data['supply_chain_risk_mgmt'] = "Change Management: {$responseValue}";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Assessments are optional
        }

        // ---------------------------------------------------------------
        // 6. SHODAN INTELLIGENCE (detailed signals for FAIR calculation)
        // Pull the full security intelligence that FairCalculator uses
        // so the UI can display what's feeding into the calculation.
        // ---------------------------------------------------------------
        try {
            require_once __DIR__ . '/../includes/FairCalculator.php';
            $shodanIntel = FairCalculator::getShodanIntel($vendorId);
            if ($shodanIntel['available']) {
                $data['shodan_intel'] = $shodanIntel;

                // Build enhanced threat_intelligence with signal summary + CVE counts
                $threatParts = [];
                $posSignals = [];
                $negSignals = [];

                if (!empty($shodanIntel['has_waf'])) $posSignals[] = 'WAF/CDN';
                if (!empty($shodanIntel['has_tls13'])) $posSignals[] = 'TLS 1.3';
                if (!empty($shodanIntel['has_hsts'])) $posSignals[] = 'HSTS';
                if (!empty($shodanIntel['has_enterprise_cloud'])) $posSignals[] = 'Enterprise Cloud';
                if (!empty($shodanIntel['has_dmarc_reject'])) $posSignals[] = 'DMARC Reject';
                if (!empty($shodanIntel['has_spf_hard_fail'])) $posSignals[] = 'SPF Hard Fail';

                if ($shodanIntel['critical_cves'] > 0) $negSignals[] = $shodanIntel['critical_cves'] . ' Critical CVEs';
                if ($shodanIntel['high_cves'] > 0) $negSignals[] = $shodanIntel['high_cves'] . ' High CVEs';
                if ($shodanIntel['medium_cves'] > 0) $negSignals[] = $shodanIntel['medium_cves'] . ' Medium CVEs';
                if (!empty($shodanIntel['has_exposed_db'])) $negSignals[] = 'Database Exposed';
                if (!empty($shodanIntel['has_exposed_admin'])) $negSignals[] = 'Admin Panel Exposed';
                if (!empty($shodanIntel['has_tls10'])) $negSignals[] = 'TLS 1.0 (deprecated)';
                if (!empty($shodanIntel['has_self_signed'])) $negSignals[] = 'Self-Signed Certificate';

                $threatParts[] = '[Shodan Score: ' . ($shodanIntel['score'] ?? 'N/A') . '/100 (Grade ' . ($shodanIntel['grade'] ?? 'N/A') . ')]';
                if (!empty($posSignals)) {
                    $threatParts[] = 'Positive: ' . implode(', ', $posSignals);
                }
                if (!empty($negSignals)) {
                    $threatParts[] = 'Negative: ' . implode(', ', $negSignals);
                }

                $data['threat_intelligence'] = implode("\n", $threatParts);

                // Also build the legacy vulnerability_summary
                $vulnParts = [];
                if ($shodanIntel['critical_cves'] > 0) $vulnParts[] = $shodanIntel['critical_cves'] . ' Critical';
                if ($shodanIntel['high_cves'] > 0) $vulnParts[] = $shodanIntel['high_cves'] . ' High';
                if ($shodanIntel['medium_cves'] > 0) $vulnParts[] = $shodanIntel['medium_cves'] . ' Medium';
                if (!empty($vulnParts)) {
                    $data['vulnerability_summary'] = implode(', ', $vulnParts) . ' CVEs';
                }
            }

            // UpGuard intelligence
            $upguardIntel = FairCalculator::getUpGuardIntel($vendorId);
            if ($upguardIntel['available']) {
                $data['upguard_intel'] = $upguardIntel;
            }

            // ---------------------------------------------------------------
            // 6a. RISK ASSESSMENT SUMMARY
            // Combine UpGuard + Shodan scores into a risk overview string
            // ---------------------------------------------------------------
            $riskParts = [];
            if (!empty($upguardIntel['available'])) {
                $riskParts[] = 'UpGuard: ' . $upguardIntel['score'] . '/950 (Grade ' . $upguardIntel['grade'] . '). '
                    . 'Critical Risks: ' . $upguardIntel['critical_risks'] . ', High: ' . $upguardIntel['high_risks']
                    . ', Medium: ' . $upguardIntel['medium_risks'];
            }
            if (!empty($shodanIntel['available'])) {
                $riskParts[] = 'Shodan: ' . ($shodanIntel['score'] ?? 'N/A') . '/100 (Grade ' . ($shodanIntel['grade'] ?? 'N/A') . ')';
            }
            if (!empty($riskParts)) {
                $data['risk_assessment'] = implode("\n", $riskParts);
            }

            // Revenue cap info (for display in Security Intelligence Summary)
            $fairDefaults = FairCalculator::getDefaults();
            $annualRev = floatval($fairDefaults['fair_annual_revenue'] ?? 0);
            $capPct = floatval($fairDefaults['fair_revenue_cap_pct'] ?? 10);
            if ($annualRev > 0 && $capPct > 0) {
                $data['revenue_cap'] = $annualRev * ($capPct / 100);
            }
            $data['fair_ai_enabled'] = ($fairDefaults['fair_ai_enabled'] ?? '0') === '1';
        } catch (Exception $e) {
            // Shodan/UpGuard data is optional
        }

        // ---------------------------------------------------------------
        // 7. ISO 27001 INFERRED FIELDS
        // If vendor has valid ISO 27001 cert, we can infer a bunch of
        // security controls are in place based on the standard's Annex A
        // ---------------------------------------------------------------
        if (!empty($data['has_valid_iso_27001'])) {
            $data['iso_inferred_fields'] = [
                'vulnerability_management' => 'Yes - ISO 27001 certified (A.12.6)',
                'compliance' => 'Yes - ISO 27001:2022 certified',
                'security_governance' => 'Yes - ISO 27001 ISMS in place',
                'incident_response_plan' => 'Yes - ISO 27001 (A.16)',
                'continuous_monitoring' => 'Yes - ISO 27001 (A.12.4)',
                'security_awareness_training' => 'Yes - ISO 27001 (A.7.2.2)',
                'patch_management' => 'Yes - ISO 27001 (A.12.6)',
                'access_controls' => 'Yes - ISO 27001 (A.9)',
                'data_encryption' => 'Yes - ISO 27001 (A.10)',
                'network_security' => 'Yes - ISO 27001 (A.13)',
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log('FAIR vendor enrichment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Enrichment failed']);
}
