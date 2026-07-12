<?php
/**
 * 4th Party Risk - Technology Inventory & Supply Chain Analysis
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * When a vulnerability is announced in a product like Adobe AEM, CloudFlare, or Apache,
 * you need to instantly know which vendors in your portfolio are affected.
 *
 * Two search modes:
 *   - Search by Vendor Domain — see all technologies a vendor uses
 *   - Search by Technology — see all vendors using that technology (blast radius)
 *
 * Default view: Technology Concentration dashboard showing top technologies ranked
 * by vendor count, each clickable to drill down.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isSuperAdmin = Session::getInstance()->get('is_super_admin');
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isSuperAdmin && !$isAuditor) {
    http_response_code(403);
    die(t('fourth-party-risk.access_denied'));
}

require_once __DIR__ . '/includes/classes/ShodanService.php';
require_once __DIR__ . '/includes/classes/SRSService.php';
require_once __DIR__ . '/includes/classes/Pagination.php';
require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
$shodanService = new ShodanService();
$srsService = new SRSService();

// Get display names for source columns
$shodanDisplayName = $shodanService->getScoringConfig()['display_name'] ?? 'Shodan';
$upguardDisplayName = $srsService->getScoringConfig()['display_name'] ?? 'UpGuard';

$techTableExists = $shodanService->hasTechnologiesTable();

// Handle single waive/unwaive CVE via AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['waive_single_cve', 'unwaive_single_cve'])) {
    header('Content-Type: application/json');
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => t('fourth-party-risk.api_csrf_invalid')]);
        exit;
    }
    $vid = intval($_POST['vendor_id'] ?? 0);
    $cve = strtoupper(trim($_POST['cve_id'] ?? ''));

    if ($_POST['action'] === 'waive_single_cve') {
        if (!$vid || !preg_match('/^CVE-\d{4}-\d+$/', $cve)) {
            echo json_encode(['success' => false, 'error' => t('fourth-party-risk.api_invalid_vendor_cve')]);
            exit;
        }
        $result = $shodanService->addCveWaivers($vid, [$cve], '', $user['username']);
        $shodanService->recalculateScoreFromFindings($vid);
        echo json_encode(['success' => true, 'added' => $result['added'], 'waived_by' => $user['username']]);
    } else {
        // unwaive — find and remove the waiver
        if (!$vid || empty($cve)) {
            echo json_encode(['success' => false, 'error' => t('fourth-party-risk.api_invalid_vendor_cve')]);
            exit;
        }
        $waiver = $db->fetchOne(
            'SELECT id FROM vendor_shodan_cve_waivers WHERE vendor_onboarding_id = :vid AND cve_id = :cve',
            [':vid' => $vid, ':cve' => $cve]
        );
        if ($waiver) {
            $shodanService->removeCveWaiver((int)$waiver['id']);
            $shodanService->recalculateScoreFromFindings($vid);
            echo json_encode(['success' => true, 'removed' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => t('fourth-party-risk.api_waiver_not_found')]);
        }
    }
    exit;
}

// Handle Mass Waive CVEs POST (before CSRF token generation so validation uses the old token)
$massWaiveMessage = '';
$massWaiveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mass_waive_cves') {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $massWaiveError = t('fourth-party-risk.csrf_invalid');
    } else {
        $waiveVendorId = intval($_POST['vendor_id'] ?? 0);
        $waiveCveText = trim($_POST['cve_ids'] ?? '');
        $waiveReason = trim($_POST['reason'] ?? '');

        if (!$waiveVendorId) {
            $massWaiveError = t('fourth-party-risk.select_vendor_error');
        } elseif (empty($waiveCveText)) {
            $massWaiveError = t('fourth-party-risk.enter_cve_error');
        } else {
            // Parse CVE IDs from textarea (one per line, or comma-separated)
            // Supports exact IDs (CVE-2024-38474) and wildcard prefixes (CVE-2007-*, CVE-2007-)
            $rawCves = preg_split('/[\s,]+/', $waiveCveText);
            $cveIds = [];
            $expandedCount = 0;
            foreach ($rawCves as $raw) {
                $raw = strtoupper(trim($raw));
                if (empty($raw)) continue;
                if (preg_match('/^CVE-\d{4}-\d+$/', $raw)) {
                    // Exact CVE ID
                    $cveIds[] = $raw;
                } elseif (preg_match('/^(CVE(?:-\d{0,4}(?:-\d*)?)?)[\-\*]*$/', $raw, $m)) {
                    // Wildcard prefix like CVE-2007-*, CVE-200*, CVE-*, CVE-2007
                    $matched = $shodanService->getCveIdsForVendorByPrefix($waiveVendorId, $m[1]);
                    $expandedCount += count($matched);
                    $cveIds = array_merge($cveIds, $matched);
                }
            }
            $cveIds = array_unique($cveIds);

            if (empty($cveIds)) {
                $massWaiveError = t('fourth-party-risk.no_matching_cve_error');
            } else {
                $result = $shodanService->addCveWaivers($waiveVendorId, $cveIds, $waiveReason, $user['username']);

                // Recalculate score in real time
                if ($result['added'] > 0) {
                    $shodanService->recalculateScoreFromFindings($waiveVendorId);
                }

                // Audit log
                $vendor = $db->fetchOne('SELECT vendor_name FROM vendor_onboarding_requests WHERE id = :id', [':id' => $waiveVendorId]);
                $vendorName = $vendor['vendor_name'] ?? 'ID:' . $waiveVendorId;
                try {
                    $db->insert('audit_log', [
                        'user_id' => $user['id'],
                        'action' => 'mass_waive_cves',
                        'table_name' => 'vendor_shodan_cve_waivers',
                        'record_id' => $waiveVendorId,
                        'new_values' => json_encode(['added' => $result['added'], 'skipped' => $result['skipped'], 'cves' => array_slice($cveIds, 0, 20), 'vendor' => $vendorName]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                } catch (Exception $e) {
                    error_log('CVE waiver audit log failed: ' . $e->getMessage());
                }

                $massWaiveMessage = "Added {$result['added']} CVE waiver(s) for {$vendorName}."
                    . ($expandedCount > 0 ? " ({$expandedCount} matched from wildcard pattern.)" : '')
                    . ($result['skipped'] > 0 ? " {$result['skipped']} already existed." : '');
            }
        }
    }
}

// Handle Bulk Assign Assessment POST
$bulkAssessmentMessage = '';
$bulkAssessmentError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_assign_assessment' && !$isAuditor) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $bulkAssessmentError = t('fourth-party-risk.csrf_invalid');
    } else {
        $assessmentService = new VendorAssessmentService();
        $vendorIdsJson = $_POST['vendor_ids'] ?? '[]';
        $vendorIds = json_decode($vendorIdsJson, true);
        $templateId = intval($_POST['template_id'] ?? 0);
        $expiresInDays = intval($_POST['expires_in_days'] ?? 30);
        if ($expiresInDays < 1) $expiresInDays = 30;

        if (empty($vendorIds) || !is_array($vendorIds)) {
            $bulkAssessmentError = t('fourth-party-risk.no_vendors_selected_error');
        } elseif (!$templateId) {
            $bulkAssessmentError = t('fourth-party-risk.select_template_error');
        } elseif (!$assessmentService->getTemplate($templateId)) {
            $bulkAssessmentError = t('fourth-party-risk.invalid_template_error');
        } else {
            $created = 0;
            $emailed = 0;
            $skipped = 0;
            $failed = 0;
            $skippedNames = [];
            $failedNames = [];

            // Stand up the email service once for the whole batch. A failure here
            // (or email being disabled) must not block assessment creation -- the
            // reminder cron's Phase 1 picks up any unsent assessment later because
            // we populate vendor_contact_email below.
            $emailService = null;
            $emailEnabled = false;
            try {
                require_once __DIR__ . '/includes/classes/EmailService.php';
                $encryption = new Encryption();
                $emailService = new EmailService($db, $encryption);
                $emailEnabled = $emailService->isEnabled();
            } catch (Exception $e) {
                error_log('Bulk assessment email service init failed: ' . $e->getMessage());
            }

            foreach ($vendorIds as $vid) {
                $vid = intval($vid);
                if (!$vid) continue;
                $vendor = $db->fetchOne(
                    'SELECT id, vendor_name, primary_contact_email, primary_contact_details FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vid]
                );
                if (!$vendor || empty($vendor['primary_contact_email'])) {
                    $skipped++;
                    if ($vendor) $skippedNames[] = $vendor['vendor_name'];
                    continue;
                }
                $contactEmail = $vendor['primary_contact_email'];
                $contactName = !empty($vendor['primary_contact_details']) ? $vendor['primary_contact_details'] : null;
                try {
                    // Populate BOTH vendor_email AND vendor_contact_email (5th arg):
                    // the single-send logic and the reminder cron both gate on and
                    // deliver to vendor_contact_email, so leaving it NULL orphans the
                    // record from every send path. This is the bug that left ids
                    // 529-637 undelivered.
                    $result = $assessmentService->createAssessment(
                        $templateId,
                        $vendor['vendor_name'],
                        $contactEmail,
                        $contactName,
                        $contactEmail,
                        $vendor['id'],
                        $user['id'],
                        $expiresInDays
                    );
                    $created++;
                } catch (Exception $e) {
                    error_log('Bulk assessment create failed for vendor ' . $vid . ': ' . $e->getMessage());
                    $skipped++;
                    $skippedNames[] = $vendor['vendor_name'];
                    continue;
                }

                // Auto-send the assessment email and record the 'initial' tracking
                // row, mirroring the proven single-create path in
                // vendor-assessments.php. One failed send must not abort the batch;
                // the cron will retry it since vendor_contact_email is set.
                if ($emailEnabled && $emailService) {
                    try {
                        $assessmentUrl = baseUrl('vendor-assessment.php?token=' . $result['uuid']);
                        $emailResult = $emailService->sendAssessmentEmail(
                            $contactEmail,
                            $vendor['vendor_name'],
                            $assessmentUrl,
                            $contactName,
                            $templateId,
                            $contactName,
                            $result['expires_at']
                        );
                        if (!empty($emailResult['success'])) {
                            $emailed++;
                            try {
                                $expiresDate = !empty($result['expires_at']) ? date('Y-m-d', strtotime($result['expires_at'])) : date('Y-m-d', strtotime('+30 days'));
                                $db->query(
                                    "INSERT INTO vendor_assessment_reminders (assessment_id, reminder_type, expires_at, sent_at, email_sent_to, status)
                                     VALUES (?, 'initial', ?, NOW(), ?, 'sent')
                                     ON DUPLICATE KEY UPDATE status = 'sent', sent_at = NOW(), email_sent_to = VALUES(email_sent_to)",
                                    [$result['id'], $expiresDate, $contactEmail]
                                );
                            } catch (Exception $trackEx) {
                                error_log('Bulk assessment reminder tracking insert failed for assessment ' . $result['id'] . ': ' . $trackEx->getMessage());
                            }
                        } else {
                            $failed++;
                            $failedNames[] = $vendor['vendor_name'];
                            error_log('Bulk assessment email send failed for vendor ' . $vid . ': ' . ($emailResult['message'] ?? 'unknown error'));
                        }
                    } catch (Exception $emailEx) {
                        $failed++;
                        $failedNames[] = $vendor['vendor_name'];
                        error_log('Bulk assessment email send exception for vendor ' . $vid . ': ' . $emailEx->getMessage());
                    }
                }
            }

            $bulkAssessmentMessage = "Created {$created} assessment(s)";
            if ($emailEnabled) {
                $bulkAssessmentMessage .= ", emailed {$emailed}";
                if ($failed > 0) {
                    $bulkAssessmentMessage .= ", {$failed} email(s) failed and will be retried by the reminder cron"
                        . (!empty($failedNames) ? ' (' . implode(', ', array_slice($failedNames, 0, 5)) . (count($failedNames) > 5 ? '...' : '') . ')' : '');
                }
            } else {
                $bulkAssessmentMessage .= ' (email sending is disabled; the reminder cron will deliver these once enabled)';
            }
            $bulkAssessmentMessage .= '.';
            if ($skipped > 0) {
                $bulkAssessmentMessage .= " {$skipped} vendor(s) skipped (no email on file"
                    . (!empty($skippedNames) ? ': ' . implode(', ', array_slice($skippedNames, 0, 5)) : '')
                    . (count($skippedNames) > 5 ? '...' : '') . ').';
            }
        }
    }
}

// Generate CSRF token for the page (after POST validation so it doesn't invalidate the submitted token)
$csrfToken = $security->generateCSRFToken();

// Load assessment templates for bulk assign modal
$assessmentService = isset($assessmentService) ? $assessmentService : new VendorAssessmentService();
$assessmentTemplates = $assessmentService->getTemplates(true);

// Determine search mode and execute
$searchMode = isset($_GET['mode']) ? $_GET['mode'] : '';
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];
$resultType = ''; // 'vendor_tech', 'tech_vendors', 'cve_vendors', or ''
$concentration = [];
$techSuggestions = [];
$isCveSearch = false;
$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';
$fpPgParams = Pagination::getParams(['per_page' => 50, 'sort_column' => '', 'sort_dir' => 'ASC']);
$fpPerPage = $fpPgParams['per_page'];
$fpCurrentPage = $fpPgParams['page'];
$totalResults = 0;
$fpPg = null;

if ($techTableExists) {
    if (!empty($searchQuery)) {
        if ($searchMode === 'vendor') {
            if (!$isExport) {
                $totalResults = $shodanService->countTechnologiesByVendor($searchQuery);
                $fpPg = Pagination::paginate($totalResults, $fpPerPage, $fpCurrentPage);
                $results = $shodanService->getTechnologiesByVendor($searchQuery, $fpPerPage, $fpPg['offset']);
            } else {
                $results = $shodanService->getTechnologiesByVendor($searchQuery, 50000, 0);
            }
            $resultType = 'vendor_tech';
        } elseif ($searchMode === 'cve') {
            // Dedicated CVE search mode — auto-detect CVE ID vs vendor/domain
            $isCveSearch = true;
            if (preg_match('/^CVE/i', trim($searchQuery))) {
                // CVE ID search
                $searchQuery = strtoupper(trim($searchQuery));
                $results = $shodanService->searchCVEView($searchQuery);
                if (empty($results)) {
                    $results = $shodanService->getVendorsByCVE($searchQuery);
                }
            } else {
                // Vendor name or domain search within CVE view
                $results = $shodanService->searchCVEByVendor($searchQuery);
            }
            $resultType = 'cve_vendors';
            $totalResults = count($results);
        } elseif ($searchMode === 'technology') {
            // Detect CVE pattern (e.g., CVE-2024-1234) and route to CVE VIEW search
            if (preg_match('/^CVE-\d{4}-\d+$/i', trim($searchQuery))) {
                $isCveSearch = true;
                $searchQuery = strtoupper(trim($searchQuery));
                // Use the database VIEW for precise per-finding CVE results
                $results = $shodanService->searchCVEView($searchQuery);
                // Fallback to technology-based CVE search if VIEW not available
                if (empty($results)) {
                    $results = $shodanService->getVendorsByCVE($searchQuery);
                }
                $resultType = 'cve_vendors';
                $totalResults = count($results);
            } else {
                // Load all detections with safety limit, then group & paginate in PHP
                $results = $shodanService->getVendorsByTechnology($searchQuery, $isExport ? 50000 : 2000, 0);
                $resultType = 'tech_vendors';
                $totalResults = count($results);
            }
        }
    }

    // Always load concentration data for default view
    $concentrationRaw = $shodanService->getTechnologyConcentration(500);

    // Also load technology name suggestions for autocomplete
    $techSuggestions = array_column($concentrationRaw, 'technology_name');

    // Concentration pagination, filtering, and sorting
    $concParams = Pagination::getParams([
        'prefix' => 'c_', 'per_page' => 25, 'sort_column' => 'vendor_count',
        'sort_dir' => 'DESC', 'valid_sort_columns' => ['technology_name', 'technology_category', 'vendor_count']
    ]);

    $concentration = $concentrationRaw;

    // Collect unique categories for filter dropdown
    $concCategories = [];
    foreach ($concentrationRaw as $c) {
        foreach (explode(',', $c['technology_category']) as $cat) {
            $concCategories[trim($cat)] = true;
        }
    }
    $concCategories = array_keys($concCategories);
    sort($concCategories);

    // Filter by category
    $concCatFilter = isset($_GET['c_cat']) ? trim($_GET['c_cat']) : '';
    if ($concCatFilter !== '') {
        $concentration = array_filter($concentration, function($c) use ($concCatFilter) {
            return strpos(',' . $c['technology_category'] . ',', ',' . $concCatFilter . ',') !== false;
        });
    }

    // Filter by text search
    $concSearchFilter = isset($_GET['c_q']) ? trim($_GET['c_q']) : '';
    if ($concSearchFilter !== '') {
        $q = strtolower($concSearchFilter);
        $concentration = array_filter($concentration, function($c) use ($q) {
            return strpos(strtolower($c['technology_name']), $q) !== false;
        });
    }

    // Sort
    $concSortCol = $concParams['sort_column'];
    $concSortDir = $concParams['sort_dir'];
    usort($concentration, function($a, $b) use ($concSortCol, $concSortDir) {
        if ($concSortCol === 'vendor_count') {
            $cmp = (int)$a['vendor_count'] - (int)$b['vendor_count'];
        } else {
            $cmp = strcasecmp($a[$concSortCol] ?? '', $b[$concSortCol] ?? '');
        }
        return $concSortDir === 'ASC' ? $cmp : -$cmp;
    });

    // Paginate
    $concentration = array_values($concentration);
    $concPg = Pagination::paginate(count($concentration), $concParams['per_page'], $concParams['page']);
    $concentrationPage = array_slice($concentration, $concPg['offset'], $concParams['per_page']);
}

// Subprocessor concentration data (mode=subprocessors)
$spConcentration = [];
$spConcentrationPage = [];
$spConcPg = null;
if ($searchMode === 'subprocessors') {
    try {
        $spConcentrationRaw = $db->fetchAll(
            "SELECT s.id, s.subprocessor_name, s.subprocessor_domain, s.country, s.linked_vendor_id,
                    COUNT(m.id) AS vendor_count,
                    GROUP_CONCAT(DISTINCT v.vendor_name ORDER BY v.vendor_name SEPARATOR ', ') AS vendor_names
             FROM vendor_subprocessors s
             JOIN vendor_subprocessor_mappings m ON m.subprocessor_id = s.id
             JOIN vendor_onboarding_requests v ON m.vendor_onboarding_id = v.id
             GROUP BY s.id
             ORDER BY vendor_count DESC, s.subprocessor_name ASC"
        );
    } catch (Exception $e) {
        $spConcentrationRaw = [];
    }

    // Filter by text search
    $spSearchFilter = isset($_GET['sp_q']) ? trim($_GET['sp_q']) : '';
    $spConcentration = $spConcentrationRaw;
    if ($spSearchFilter !== '') {
        $sq = strtolower($spSearchFilter);
        $spConcentration = array_filter($spConcentration, function($r) use ($sq) {
            return strpos(strtolower($r['subprocessor_name']), $sq) !== false
                || strpos(strtolower($r['subprocessor_domain'] ?? ''), $sq) !== false
                || strpos(strtolower($r['country'] ?? ''), $sq) !== false;
        });
    }

    // Sort & paginate
    $spConcParams = Pagination::getParams([
        'prefix' => 'sp_', 'per_page' => 25, 'sort_column' => 'vendor_count',
        'sort_dir' => 'DESC', 'valid_sort_columns' => ['subprocessor_name', 'country', 'vendor_count']
    ]);
    $spSortCol = $spConcParams['sort_column'];
    $spSortDir = $spConcParams['sort_dir'];
    usort($spConcentration, function($a, $b) use ($spSortCol, $spSortDir) {
        if ($spSortCol === 'vendor_count') {
            $cmp = (int)$a['vendor_count'] - (int)$b['vendor_count'];
        } else {
            $cmp = strcasecmp($a[$spSortCol] ?? '', $b[$spSortCol] ?? '');
        }
        return $spSortDir === 'ASC' ? $cmp : -$cmp;
    });
    $spConcentration = array_values($spConcentration);
    $spConcPg = Pagination::paginate(count($spConcentration), $spConcParams['per_page'], $spConcParams['page']);
    $spConcentrationPage = array_slice($spConcentration, $spConcPg['offset'], $spConcParams['per_page']);

    // Per-subprocessor vendor lists (id + name) for the "Send Assessment" selector.
    // Built once and keyed by subprocessor id; the bulk_assign_assessment POST handler
    // re-validates every vendor id, so this is only used to populate the selection UI.
    $spVendorsBySub = [];
    if (!empty($spConcentrationPage)) {
        try {
            $spVendorRows = $db->fetchAll(
                "SELECT DISTINCT m.subprocessor_id, v.id AS vendor_id, v.vendor_name
                 FROM vendor_subprocessor_mappings m
                 JOIN vendor_onboarding_requests v ON m.vendor_onboarding_id = v.id
                 ORDER BY v.vendor_name ASC"
            );
            foreach ($spVendorRows as $vr) {
                $spVendorsBySub[(int)$vr['subprocessor_id']][] = [
                    'id'   => (int)$vr['vendor_id'],
                    'name' => $vr['vendor_name'],
                ];
            }
        } catch (Exception $e) {
            $spVendorsBySub = [];
        }
    }
}

// Handle CSV exports
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($resultType === 'vendor_tech') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="vendor-technologies-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $searchQuery) . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Technology', 'Version', 'Category', 'Vendor', 'Domain', 'Detected On', 'Port', 'Confidence', 'Method', 'CVEs', 'Last Seen']);
        foreach ($results as $r) {
            $cveIds = '';
            $cRaw = !empty($r['cves']) ? (is_string($r['cves']) ? json_decode($r['cves'], true) : $r['cves']) : [];
            if (is_array($cRaw)) $cveIds = implode('; ', array_column($cRaw, 'id'));
            fputcsv($out, array_map('csvSafeCell', [
                $r['technology_name'], $r['technology_version'] ?? '', $r['technology_category'],
                $r['vendor_name'] ?? '', $r['vendor_domain'], $r['detected_on'] ?? '',
                $r['detected_port'] ?? '', $r['detection_confidence'] ?? '', $r['detection_method'] ?? '',
                $cveIds, $r['last_seen_at'] ?? ''
            ])); // SECURITY: neutralize CSV formula injection
        }
        fclose($out);
        exit;
    } elseif ($resultType === 'tech_vendors') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="technology-vendors-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $searchQuery) . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Vendor Name', 'Domain', 'Version', 'Category', 'Detected On', 'Port', 'Confidence', 'CVEs', 'Last Seen']);
        foreach ($results as $r) {
            $cveIds2 = '';
            $cRaw2 = !empty($r['cves']) ? (is_string($r['cves']) ? json_decode($r['cves'], true) : $r['cves']) : [];
            if (is_array($cRaw2)) $cveIds2 = implode('; ', array_column($cRaw2, 'id'));
            fputcsv($out, array_map('csvSafeCell', [
                $r['vendor_name'] ?? '', $r['vendor_domain'], $r['technology_version'] ?? '',
                $r['technology_category'], $r['detected_on'] ?? '', $r['detected_port'] ?? '',
                $r['detection_confidence'] ?? '', $cveIds2, $r['last_seen_at'] ?? ''
            ])); // SECURITY: neutralize CSV formula injection
        }
        fclose($out);
        exit;
    } elseif ($resultType === 'cve_vendors') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cve-impact-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $searchQuery) . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        // Check if results are from VIEW (have cve_id column) or from tech-based search
        $isViewResult = !empty($results) && isset($results[0]['cve_id']);
        if ($isViewResult) {
            fputcsv($out, ['Vendor Name', 'Domain', 'Subdomain', 'IP Address', 'CVE', 'CVSS', 'Severity', 'Waived', $shodanDisplayName, $upguardDisplayName]);
            foreach ($results as $r) {
                fputcsv($out, array_map('csvSafeCell', [
                    $r['vendor_name'] ?? '', $r['vendor_domain'] ?? '', $r['subdomain'] ?? '',
                    $r['ip_address'] ?? '', $r['cve_id'] ?? '', $r['cvss_score'] ?? '',
                    $r['severity'] ?? '', !empty($r['waived']) ? 'Yes' : 'No',
                    $r['shodan_detected'] ? 'Yes' : 'No',
                    $r['upguard_detected'] ? 'Yes' : 'No'
                ])); // SECURITY: neutralize CSV formula injection
            }
        } else {
            fputcsv($out, ['Vendor Name', 'Domain', 'Technology', 'Version', 'Category', 'Detected On', 'Port', 'CVEs', 'Last Seen']);
            foreach ($results as $r) {
                $cveIds3 = '';
                $cRaw3 = !empty($r['cves']) ? (is_string($r['cves']) ? json_decode($r['cves'], true) : $r['cves']) : [];
                if (is_array($cRaw3)) $cveIds3 = implode('; ', array_column($cRaw3, 'id'));
                fputcsv($out, array_map('csvSafeCell', [
                    $r['vendor_name'] ?? '', $r['vendor_domain'], $r['technology_name'],
                    $r['technology_version'] ?? '', $r['technology_category'],
                    $r['detected_on'] ?? '', $r['detected_port'] ?? '', $cveIds3, $r['last_seen_at'] ?? ''
                ])); // SECURITY: neutralize CSV formula injection
            }
        }
        fclose($out);
        exit;
    } elseif (!empty($concentrationRaw)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="technology-concentration-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Technology', 'Category', 'Vendor Count']);
        foreach ($concentrationRaw as $r) {
            fputcsv($out, array_map('csvSafeCell', [$r['technology_name'], $r['technology_category'], $r['vendor_count']])); // SECURITY: neutralize CSV formula injection
        }
        fclose($out);
        exit;
    }
}

// Helper: render CVE badges from JSON or array
function renderCveBadges($cvesRaw): string {
    $cves = [];
    if (is_string($cvesRaw) && !empty($cvesRaw)) {
        $cves = json_decode($cvesRaw, true) ?: [];
    } elseif (is_array($cvesRaw)) {
        $cves = $cvesRaw;
    }
    if (empty($cves)) return '<span style="font-size:11px;color:#9ca3af;">-</span>';

    $sevColors = ['critical'=>'#991b1b','high'=>'#c2410c','medium'=>'#b45309','low'=>'#166534','unknown'=>'#6b7280'];
    $sevBgs = ['critical'=>'#fef2f2','high'=>'#fff7ed','medium'=>'#fffbeb','low'=>'#f0fdf4','unknown'=>'#f3f4f6'];
    $html = '';
    $shown = array_slice($cves, 0, 3);
    foreach ($shown as $c) {
        $id = $c['id'] ?? '';
        $sev = $c['severity'] ?? 'unknown';
        $cvss = $c['cvss'] ?? 'N/A';
        $bg = $sevBgs[$sev] ?? '#f3f4f6';
        $fg = $sevColors[$sev] ?? '#6b7280';
        $html .= '<a href="https://nvd.nist.gov/vuln/detail/' . urlencode($id) . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:600;margin:1px 2px;'
            . 'background:' . $bg . ';color:' . $fg . ';text-decoration:none;" '
            . 'title="CVSS: ' . e((string)$cvss) . ' (' . e($sev) . ')">' . e($id) . '</a>';
    }
    if (count($cves) > 3) {
        $html .= '<span style="font-size:9px;color:#6b7280;margin-left:2px;">+' . (count($cves) - 3) . ' more</span>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $searchMode === 'cve' ? e(t('fourth-party-risk.cve_search')) : e(t('fourth-party-risk.page_title')); ?> - TPRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
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
        body { margin: 0; font-family: 'Roboto', sans-serif; background: #f9fafb; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
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
        .user-menu .btn-configure { background: var(--theme-button-color); color: white !important; }
        .user-menu .btn-configure:hover { filter: brightness(1.1); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar {
            width: var(--sidebar-width); min-width: var(--sidebar-width); max-width: var(--sidebar-width);
            background: var(--nav-fill-color); padding: 0; flex-shrink: 0; display: flex; flex-direction: column;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title { color: var(--nav-font-color); font-size: 13px; font-weight: 500; margin-top: 8px; opacity: 0.9; }
        .sidebar-content { flex: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            color: var(--nav-font-color); font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1.5px; padding: 0 20px;
            margin-bottom: 10px; opacity: 0.6;
            cursor: pointer; list-style: none;
            display: flex; align-items: center; justify-content: space-between;
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '\25BC'; font-size: 8px; opacity: 0.5;
            transition: transform 0.2s ease; margin-right: 2px;
        }
        .sidebar-section:not([open]) .sidebar-section-title::after {
            transform: rotate(-90deg);
        }
        .sidebar-nav { list-style: none; margin: 0; padding: 0; }
        .sidebar-nav li a {
            display: flex; align-items: center; gap: 10px; padding: 10px 20px;
            color: var(--nav-font-color); text-decoration: none; font-size: 14px;
            transition: all 0.2s; opacity: 0.85;
        }
        .sidebar-nav li a:hover { opacity: 1; background: rgba(255,255,255,0.1); }
        .sidebar-nav li a.active { opacity: 1; background: rgba(255,255,255,0.15); border-right: 3px solid var(--nav-font-color); }
        .sidebar-nav li a .icon { font-size: 16px; }
        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        .page-title { font-size: 24px; font-weight: 600; color: #1f2937; }

        .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 10px; padding: 20px; border: 1px solid #e5e7eb; }
        .stat-card .stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: #1f2937; }

        .search-section { background: white; border-radius: 10px; border: 1px solid #e5e7eb; padding: 20px; margin-bottom: 25px; }
        .search-row { display: flex; gap: 15px; flex-wrap: wrap; }
        .search-box { flex: 1; min-width: 300px; }
        .search-box label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .search-box .search-input-row { display: flex; gap: 8px; }
        .search-box input[type="text"] {
            flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 14px; outline: none;
        }
        .search-box input[type="text"]:focus { border-color: var(--theme-button-color); box-shadow: 0 0 0 3px rgba(255,101,67,0.1); }
        .btn-search {
            padding: 10px 20px; background: var(--theme-button-color); color: white;
            border: none; border-radius: 8px; font-size: 14px; cursor: pointer;
        }
        .btn-search:hover { filter: brightness(1.1); }

        .card { background: white; border-radius: 10px; border: 1px solid #e5e7eb; padding: 20px; margin-bottom: 20px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 12px; font-size: 12px; font-weight: 600; color: #6b7280; border-bottom: 2px solid #e5e7eb; }
        th.sortable { cursor: pointer; user-select: none; position: relative; padding-right: 22px; }
        th.sortable:hover { color: #374151; }
        th.sortable::after { content: '\2195'; position: absolute; right: 6px; top: 50%; transform: translateY(-50%); font-size: 10px; opacity: 0.4; }
        th.sortable.sort-asc::after { content: '\25B2'; opacity: 0.8; }
        th.sortable.sort-desc::after { content: '\25BC'; opacity: 0.8; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #374151; }
        tr:hover { background: #f9fafb; }

        .badge { padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }

        .conc-row { cursor: pointer; }
        .conc-row:hover { background: #f0f4ff !important; }

        .pagination-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 20px; flex-wrap: wrap; gap: 12px;
        }
        .pagination-info { font-size: 13px; color: #6b7280; }
        .pagination {
            display: flex; list-style: none; gap: 4px; padding: 0; margin: 0;
        }
        .pagination li a, .pagination li span {
            display: inline-block; padding: 6px 12px; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 13px; text-decoration: none; color: #374151;
        }
        .pagination li a:hover { background: #f3f4f6; }
        .pagination li.active span {
            background: var(--theme-button-color); color: white; border-color: var(--theme-button-color);
        }
        .pagination li.disabled span { color: #d1d5db; cursor: not-allowed; }
        .per-page-group { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }

        .footer { background: var(--theme-footer-color); padding: 20px 30px; flex-shrink: 0; }
        .footer-content { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 15px; }
        .footer p { margin: 0; color: rgba(255,255,255,0.7); font-size: 13px; }
    </style>
</head>
<body>
    <div class="page">
        <header class="top-bar">
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                <a href="admin.php" class="btn-configure"><?php echo e(t('fourth-party-risk.admin')); ?></a>
                <?php endif; ?>
                <a href="?export=csv<?php echo !empty($searchQuery) ? '&mode=' . urlencode($searchMode) . '&q=' . urlencode($searchQuery) : ''; ?>"><?php echo e(t('fourth-party-risk.export_csv')); ?></a>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </header>

        <div class="main-layout">
            <?php $currentPage = ($searchMode === 'subprocessors') ? 'subprocessors' : (($searchMode === 'cve') ? 'cve_search' : '4th_party'); include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">

                <?php if ($searchMode === 'cve'): ?>
                <!-- ============================================================ -->
                <!-- CVE SEARCH MODE -->
                <!-- ============================================================ -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h1 class="page-title" style="margin: 0 0 5px 0;"><?php echo e(t('fourth-party-risk.cve_search')); ?></h1>
                        <p style="margin: 0; color: #6b7280; font-size: 14px;"><?php echo e(t('fourth-party-risk.cve_search_desc')); ?></p>
                    </div>
                    <button type="button" id="massWaiveBtn" style="padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"><?php echo e(t('fourth-party-risk.mass_waive_cves')); ?></button>
                </div>

                <?php if (!empty($massWaiveMessage)): ?>
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #166534;">
                    <?php echo e($massWaiveMessage); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($massWaiveError)): ?>
                <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b;">
                    <?php echo e($massWaiveError); ?>
                </div>
                <?php endif; ?>

                <div class="search-section">
                    <div class="search-row">
                        <div class="search-box" style="max-width: 600px;">
                            <label><?php echo e(t('fourth-party-risk.search_cve_label')); ?></label>
                            <form method="get" class="search-input-row">
                                <input type="hidden" name="mode" value="cve">
                                <input type="text" name="q" placeholder="<?php echo e(t('fourth-party-risk.search_cve_placeholder')); ?>" value="<?php echo $searchMode === 'cve' ? e($searchQuery) : ''; ?>" autofocus>
                                <button type="submit" class="btn-search"><?php echo e(t('fourth-party-risk.search')); ?></button>
                                <?php if (!empty($searchQuery) && $searchMode === 'cve'): ?>
                                <a href="?mode=cve" style="font-size: 12px; color: #6b7280; text-decoration: none; white-space: nowrap; align-self: center;"><?php echo e(t('fourth-party-risk.clear')); ?></a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    <p style="margin: 10px 0 0 0; font-size: 12px; color: #9ca3af;"><?php echo e(t('fourth-party-risk.cve_search_source_note_prefix')); ?><?php echo e($shodanDisplayName); ?> / <?php echo e($upguardDisplayName); ?><?php echo e(t('fourth-party-risk.cve_search_source_note_suffix')); ?></p>
                </div>

                <?php elseif ($searchMode === 'subprocessors'): ?>
                <!-- ============================================================ -->
                <!-- SUBPROCESSOR CONCENTRATION MODE -->
                <!-- ============================================================ -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h1 class="page-title" style="margin: 0 0 5px 0;"><?php echo e(t('fourth-party-risk.subprocessor_concentration')); ?></h1>
                        <p style="margin: 0; color: #6b7280; font-size: 14px;"><?php echo e(t('fourth-party-risk.subprocessor_desc')); ?></p>
                    </div>
                </div>

                <?php if (!empty($bulkAssessmentMessage)): ?>
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #166534;">
                    <?php echo e($bulkAssessmentMessage); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($bulkAssessmentError)): ?>
                <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b;">
                    <?php echo e($bulkAssessmentError); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($spConcentrationRaw)): ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                        <h3 style="margin: 0;"><?php echo e(t('fourth-party-risk.subprocessor_concentration')); ?></h3>
                        <form method="get" style="display: flex; gap: 8px; align-items: center;">
                            <input type="hidden" name="mode" value="subprocessors">
                            <input type="text" name="sp_q" placeholder="<?php echo e(t('fourth-party-risk.filter_subprocessors')); ?>" value="<?php echo e($spSearchFilter); ?>" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; width: 200px;">
                            <button type="submit" style="padding: 6px 14px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;"><?php echo e(t('fourth-party-risk.filter')); ?></button>
                            <?php if ($spSearchFilter !== ''): ?>
                            <a href="?mode=subprocessors" style="font-size: 12px; color: #6b7280; text-decoration: none;"><?php echo e(t('fourth-party-risk.clear')); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <p style="color: #6b7280; font-size: 13px; margin: 0 0 15px 0;"><?php echo e(t('fourth-party-risk.subprocessor_risk_note')); ?></p>
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $spSortCol === 'subprocessor_name' ? ($spSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>">
                                    <a href="<?php echo Pagination::buildSortUrl('subprocessor_name', $spSortCol, $spSortDir, 'sp_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_subprocessor')); ?><?php echo Pagination::getSortIndicator('subprocessor_name', $spSortCol, $spSortDir); ?></a>
                                </th>
                                <th><?php echo e(t('fourth-party-risk.col_domain')); ?></th>
                                <th class="sortable <?php echo $spSortCol === 'country' ? ($spSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>">
                                    <a href="<?php echo Pagination::buildSortUrl('country', $spSortCol, $spSortDir, 'sp_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_country')); ?><?php echo Pagination::getSortIndicator('country', $spSortCol, $spSortDir); ?></a>
                                </th>
                                <th class="sortable <?php echo $spSortCol === 'vendor_count' ? ($spSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>" style="text-align: right;">
                                    <a href="<?php echo Pagination::buildSortUrl('vendor_count', $spSortCol, $spSortDir, 'sp_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_vendor_count')); ?><?php echo Pagination::getSortIndicator('vendor_count', $spSortCol, $spSortDir); ?></a>
                                </th>
                                <th><?php echo e(t('fourth-party-risk.col_vendors')); ?></th>
                                <?php if (!$isAuditor): ?>
                                <th style="text-align: right;"><?php echo e(t('fourth-party-risk.col_actions')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($spConcentrationPage as $sp): ?>
                            <tr>
                                <td style="font-weight: 600; font-size: 13px;">
                                    <?php echo e($sp['subprocessor_name']); ?>
                                    <?php if ($sp['linked_vendor_id']): ?>
                                    <a href="vendor-onboarding.php?id=<?php echo (int)$sp['linked_vendor_id']; ?>" style="background: #dbeafe; color: #1d4ed8; font-size: 10px; padding: 1px 6px; border-radius: 8px; font-weight: 600; text-decoration: none; margin-left: 6px;"><?php echo e(t('fourth-party-risk.direct_vendor')); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 13px; color: #6b7280;"><?php echo e($sp['subprocessor_domain'] ?? ''); ?></td>
                                <td style="font-size: 13px; color: #6b7280;"><?php echo e($sp['country'] ?? ''); ?></td>
                                <td style="text-align: right; font-weight: 700; font-size: 15px; color: <?php echo (int)$sp['vendor_count'] > 1 ? '#7c3aed' : 'var(--theme-header-color, #2563eb)'; ?>;"><?php echo (int)$sp['vendor_count']; ?></td>
                                <td style="font-size: 12px; color: #6b7280; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($sp['vendor_names']); ?>"><?php echo e($sp['vendor_names']); ?></td>
                                <?php if (!$isAuditor): ?>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="sp-send-assessment-btn"
                                            data-sp-name="<?php echo e($sp['subprocessor_name']); ?>"
                                            data-sp-vendors='<?php echo e(json_encode($spVendorsBySub[(int)$sp['id']] ?? [], JSON_UNESCAPED_UNICODE)); ?>'
                                            style="padding: 6px 12px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <?php echo e(t('fourth-party-risk.send_assessment')); ?>
                                    </button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($spConcentrationPage)): ?>
                            <tr><td colspan="<?php echo $isAuditor ? 5 : 6; ?>" style="text-align: center; padding: 20px; color: #9ca3af;"><?php echo e(t('fourth-party-risk.no_subprocessors_filter')); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php Pagination::renderControls($spConcPg, 'subprocessors', [25, 50, 100], 'sp_'); ?>
                </div>
                <?php else: ?>
                <div class="card" style="text-align: center; padding: 40px;">
                    <p style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px 0;"><?php echo e(t('fourth-party-risk.no_subprocessors_tracked')); ?></p>
                    <p style="color: #6b7280; margin: 0;"><?php echo e(t('fourth-party-risk.no_subprocessors_tracked_desc')); ?></p>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <!-- ============================================================ -->
                <!-- 4TH PARTY RISK MODE (default) -->
                <!-- ============================================================ -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h1 class="page-title" style="margin: 0 0 5px 0;"><?php echo e(t('fourth-party-risk.heading')); ?></h1>
                        <p style="margin: 0; color: #6b7280; font-size: 14px;"><?php echo e(t('fourth-party-risk.subheading')); ?></p>
                    </div>
                </div>

                <!-- Stats Cards -->
                <?php
                $totalTech = count($concentrationRaw);
                $totalVendorCount = 0;
                $topTech = !empty($concentrationRaw) ? $concentrationRaw[0]['technology_name'] : '-';
                $categories = [];
                foreach ($concentrationRaw as $c) {
                    $totalVendorCount += (int)$c['vendor_count'];
                    foreach (explode(',', $c['technology_category']) as $cat) {
                        $categories[trim($cat)] = true;
                    }
                }
                ?>
                <div class="stat-cards">
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('fourth-party-risk.unique_technologies')); ?></div>
                        <div class="stat-value"><?php echo $totalTech; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('fourth-party-risk.technology_categories')); ?></div>
                        <div class="stat-value"><?php echo count($categories); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('fourth-party-risk.most_common')); ?></div>
                        <div class="stat-value" style="font-size: 18px;"><?php echo e($topTech); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><?php echo e(t('fourth-party-risk.total_detections')); ?></div>
                        <div class="stat-value"><?php echo $totalVendorCount; ?></div>
                    </div>
                </div>

                <!-- Dual Search -->
                <div class="search-section">
                    <div class="search-row">
                        <div class="search-box">
                            <label><?php echo e(t('fourth-party-risk.search_vendor_label')); ?></label>
                            <form method="get" class="search-input-row">
                                <input type="hidden" name="mode" value="vendor">
                                <input type="text" name="q" placeholder="<?php echo e(t('fourth-party-risk.search_vendor_placeholder')); ?>" value="<?php echo $searchMode === 'vendor' ? e($searchQuery) : ''; ?>">
                                <button type="submit" class="btn-search"><?php echo e(t('fourth-party-risk.search')); ?></button>
                                <?php if ($searchMode === 'vendor'): ?>
                                <a href="fourth-party-risk.php" style="font-size: 12px; color: #6b7280; text-decoration: none; white-space: nowrap; align-self: center;"><?php echo e(t('fourth-party-risk.clear')); ?></a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="search-box">
                            <label><?php echo e(t('fourth-party-risk.search_tech_label')); ?></label>
                            <form method="get" class="search-input-row">
                                <input type="hidden" name="mode" value="technology">
                                <input type="text" name="q" id="techSearchInput" placeholder="<?php echo e(t('fourth-party-risk.search_tech_placeholder')); ?>" value="<?php echo $searchMode === 'technology' ? e($searchQuery) : ''; ?>" list="techSuggestions" autocomplete="off">
                                <datalist id="techSuggestions">
                                    <?php foreach ($techSuggestions as $s): ?>
                                    <option value="<?php echo e($s); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <button type="submit" class="btn-search"><?php echo e(t('fourth-party-risk.search')); ?></button>
                                <?php if ($searchMode === 'technology'): ?>
                                <a href="fourth-party-risk.php" style="font-size: 12px; color: #6b7280; text-decoration: none; white-space: nowrap; align-self: center;"><?php echo e(t('fourth-party-risk.clear')); ?></a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($resultType === 'vendor_tech' && !empty($results)): ?>
                <!-- Vendor Technology Results -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0;"><?php echo e(t('fourth-party-risk.results_technologies_for')); ?> &ldquo;<?php echo e($searchQuery); ?>&rdquo; (<?php echo $totalResults; ?>)</h3>
                        <a href="?export=csv&mode=vendor&q=<?php echo urlencode($searchQuery); ?>" style="padding: 6px 14px; background: #6b7280; color: white; border-radius: 6px; text-decoration: none; font-size: 12px;"><?php echo e(t('fourth-party-risk.export_csv')); ?></a>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <input type="text" id="vtResultSearch" placeholder="<?php echo e(t('fourth-party-risk.filter_results')); ?>" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; width: 280px;">
                    </div>
                    <table data-sortable-table="vtResultsBody">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort-col="0"><?php echo e(t('fourth-party-risk.col_technology')); ?></th>
                                <th class="sortable" data-sort-col="1"><?php echo e(t('fourth-party-risk.col_version')); ?></th>
                                <th class="sortable" data-sort-col="2"><?php echo e(t('fourth-party-risk.col_category')); ?></th>
                                <th class="sortable" data-sort-col="3"><?php echo e(t('fourth-party-risk.col_vendor')); ?></th>
                                <th class="sortable" data-sort-col="4"><?php echo e(t('fourth-party-risk.col_detected_on')); ?></th>
                                <th class="sortable" data-sort-col="5"><?php echo e(t('fourth-party-risk.col_port')); ?></th>
                                <th class="sortable" data-sort-col="6"><?php echo e(t('fourth-party-risk.col_confidence')); ?></th>
                                <th><?php echo e(t('fourth-party-risk.col_cves')); ?></th>
                                <th class="sortable" data-sort-col="8"><?php echo e(t('fourth-party-risk.col_last_seen')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="vtResultsBody">
                            <?php
                            $catColors = [
                                'web_server' => '#1e40af', 'cdn_waf' => '#7c3aed', 'cloud_platform' => '#0891b2',
                                'framework' => '#059669', 'js_library' => '#d97706', 'database' => '#dc2626',
                                'email_gateway' => '#9333ea', 'dns_provider' => '#0d9488', 'programming_language' => '#4f46e5',
                                'cms' => '#e11d48', 'ssl_ca' => '#6b7280', 'admin_panel' => '#be123c', 'other' => '#6b7280'
                            ];
                            $confColors = ['high' => ['#166534','#dcfce7'], 'medium' => ['#92400e','#fef3c7'], 'low' => ['#991b1b','#fef2f2']];
                            ?>
                            <?php foreach ($results as $r): ?>
                            <tr class="vt-result-row">
                                <td style="font-weight: 600;"><?php echo e($r['technology_name']); ?></td>
                                <td style="font-family: monospace;"><?php echo e($r['technology_version'] ?? '-'); ?></td>
                                <td><?php
                                    $cc = $catColors[$r['technology_category']] ?? '#6b7280';
                                    $catLabel = ucwords(str_replace('_', ' ', $r['technology_category']));
                                ?><span class="badge" style="background: <?php echo $cc; ?>15; color: <?php echo $cc; ?>;"><?php echo e($catLabel); ?></span></td>
                                <td>
                                    <?php if (!empty($r['vendor_onboarding_id'])): ?>
                                    <a href="vendor-srs-details.php?id=<?php echo (int)$r['vendor_onboarding_id']; ?>" style="color: var(--theme-header-color); text-decoration: none; font-weight: 500;"><?php echo e($r['vendor_name'] ?? $r['vendor_domain']); ?></a>
                                    <?php else: ?>
                                    <?php echo e($r['vendor_name'] ?? $r['vendor_domain']); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family: monospace; font-size: 12px;"><?php echo e($r['detected_on'] ?? '-'); ?></td>
                                <td><?php echo $r['detected_port'] ? e($r['detected_port']) : '-'; ?></td>
                                <td><?php
                                    $conf = $r['detection_confidence'] ?? 'medium';
                                    $confC = $confColors[$conf] ?? ['#6b7280','#f3f4f6'];
                                ?><span class="badge" style="background: <?php echo $confC[1]; ?>; color: <?php echo $confC[0]; ?>;"><?php echo e($conf); ?></span></td>
                                <td><?php echo renderCveBadges($r['cves'] ?? null); ?></td>
                                <td><?php echo !empty($r['last_seen_at']) ? e(date('M j, Y', strtotime($r['last_seen_at']))) : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($fpPg && $fpPg['total_pages'] > 1): ?>
                    <div style="margin-top: 15px;">
                        <?php Pagination::renderControls($fpPg, 'detections'); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php elseif ($resultType === 'vendor_tech' && empty($results)): ?>
                <div class="card">
                    <p style="color: #6b7280; text-align: center; padding: 20px;"><?php echo e(t('fourth-party-risk.no_tech_found_prefix')); ?> &ldquo;<?php echo e($searchQuery); ?>&rdquo;<?php echo e(t('fourth-party-risk.no_tech_found_suffix')); ?></p>
                </div>

                <?php elseif ($resultType === 'tech_vendors' && !empty($results)): ?>
                <!-- Technology Blast Radius — grouped by vendor -->
                <?php
                // Group results by vendor
                $vendorGroups = [];
                foreach ($results as $r) {
                    $vid = $r['vendor_onboarding_id'];
                    if (!isset($vendorGroups[$vid])) {
                        $vendorGroups[$vid] = [
                            'vendor_name' => $r['vendor_name'] ?? 'Unknown',
                            'vendor_domain' => $r['vendor_domain'],
                            'vendor_onboarding_id' => $vid,
                            'vendor_status' => $r['vendor_status'] ?? '',
                            'detections' => [],
                        ];
                    }
                    $vendorGroups[$vid]['detections'][] = $r;
                }
                $uniqueVendorCount = count($vendorGroups);

                // PHP-level pagination on vendor groups
                $vendorGroupsList = array_values($vendorGroups);
                $tvPg = Pagination::paginate($uniqueVendorCount, $fpPerPage, $fpCurrentPage);
                $paginatedVendorGroups = array_slice($vendorGroupsList, $tvPg['offset'], $fpPerPage);
                ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0;"><?php echo e(t('fourth-party-risk.vendors_using')); ?> &ldquo;<?php echo e($searchQuery); ?>&rdquo; (<?php echo $uniqueVendorCount; ?> vendor<?php echo $uniqueVendorCount !== 1 ? 's' : ''; ?>, <?php echo count($results); ?> detection<?php echo count($results) !== 1 ? 's' : ''; ?>)</h3>
                        <a href="?export=csv&mode=technology&q=<?php echo urlencode($searchQuery); ?>" style="padding: 6px 14px; background: #6b7280; color: white; border-radius: 6px; text-decoration: none; font-size: 12px;"><?php echo e(t('fourth-party-risk.export_csv')); ?></a>
                    </div>
                    <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #92400e;">
                        <strong>Blast Radius:</strong> If a vulnerability is announced in <?php echo e($searchQuery); ?>, these <?php echo $uniqueVendorCount; ?> vendor(s) in your portfolio may be affected.
                    </div>

                    <?php if (!empty($bulkAssessmentMessage)): ?>
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #166534;">
                        <?php echo e($bulkAssessmentMessage); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($bulkAssessmentError)): ?>
                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b;">
                        <?php echo e($bulkAssessmentError); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isAuditor): ?>
                    <!-- Selection toolbar -->
                    <div id="blastSelectionToolbar" style="display: flex; align-items: center; gap: 15px; padding: 10px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #374151; margin: 0; user-select: none;">
                            <input type="checkbox" id="blastSelectAll" style="width: 16px; height: 16px; cursor: pointer;">
                            <?php echo e(t('fourth-party-risk.select_all_visible')); ?>
                        </label>
                        <button type="button" id="blastAssignBtn" disabled style="padding: 8px 18px; background: var(--theme-button-color); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; opacity: 0.5;">
                            <?php echo e(t('fourth-party-risk.assign_assessment')); ?> (<span id="blastSelectedCount">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php $vgIdx = ($tvPg['offset']); foreach ($paginatedVendorGroups as $vg): $vgIdx++; ?>
                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px; overflow: hidden;">
                        <!-- Vendor header row -->
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; cursor: pointer;" data-action="toggleBlastVendor" data-arg="blastVendor<?php echo $vgIdx; ?>">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!$isAuditor): ?>
                                <label onclick="event.stopPropagation();" style="display: flex; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" class="vendor-select-cb"
                                           value="<?php echo (int)$vg['vendor_onboarding_id']; ?>"
                                           data-vendor-name="<?php echo e($vg['vendor_name']); ?>"
                                           style="width: 16px; height: 16px; cursor: pointer;">
                                </label>
                                <?php endif; ?>
                                <span id="blastArrowblastVendor<?php echo $vgIdx; ?>" style="font-size: 10px; color: #9ca3af; transition: transform 0.2s; display: inline-block;">&#9654;</span>
                                <div>
                                    <?php if (!empty($vg['vendor_onboarding_id'])): ?>
                                    <a href="vendor-srs-details.php?id=<?php echo (int)$vg['vendor_onboarding_id']; ?>" style="color: var(--theme-header-color); text-decoration: none; font-weight: 600; font-size: 14px;" onclick="event.stopPropagation();"><?php echo e($vg['vendor_name']); ?></a>
                                    <?php else: ?>
                                    <span style="font-weight: 600; font-size: 14px;"><?php echo e($vg['vendor_name']); ?></span>
                                    <?php endif; ?>
                                    <span style="font-size: 12px; color: #6b7280; margin-left: 8px; font-family: monospace;"><?php echo e($vg['vendor_domain']); ?></span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php
                                $vendorCveCount = 0;
                                foreach ($vg['detections'] as $d) {
                                    $dCves = !empty($d['cves']) ? (is_string($d['cves']) ? json_decode($d['cves'], true) : $d['cves']) : [];
                                    $vendorCveCount += is_array($dCves) ? count($dCves) : 0;
                                }
                                if ($vendorCveCount > 0): ?>
                                <span class="badge" style="background: #fef2f2; color: #991b1b; font-weight: 700;"><?php echo $vendorCveCount; ?> CVE<?php echo $vendorCveCount !== 1 ? 's' : ''; ?></span>
                                <?php endif; ?>
                                <span class="badge" style="background: #f3f4f6; color: #6b7280;"><?php echo count($vg['detections']); ?> detection<?php echo count($vg['detections']) !== 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                        <!-- Expandable detection details -->
                        <div id="blastVendor<?php echo $vgIdx; ?>" style="display: none; border-top: 1px solid #e5e7eb;">
                            <table style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th><?php echo e(t('fourth-party-risk.col_detected_on')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_port')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_version')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_category')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_method')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_confidence')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_cves')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_last_seen')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vg['detections'] as $det): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 12px; font-weight: 500;"><?php echo e($det['detected_on'] ?? '-'); ?></td>
                                        <td style="font-size: 12px;"><?php echo $det['detected_port'] ? e($det['detected_port']) : '-'; ?></td>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo e($det['technology_version'] ?? '-'); ?></td>
                                        <td><?php
                                            $cc2 = $catColors[$det['technology_category']] ?? '#6b7280';
                                            $catLabel2 = ucwords(str_replace('_', ' ', $det['technology_category']));
                                        ?><span class="badge" style="background: <?php echo $cc2; ?>15; color: <?php echo $cc2; ?>;"><?php echo e($catLabel2); ?></span></td>
                                        <td style="font-size: 12px; color: #6b7280;"><?php echo e($det['detection_method'] ?? '-'); ?></td>
                                        <td><?php
                                            $conf2 = $det['detection_confidence'] ?? 'medium';
                                            $confC2 = $confColors[$conf2] ?? ['#6b7280','#f3f4f6'];
                                        ?><span class="badge" style="background: <?php echo $confC2[1]; ?>; color: <?php echo $confC2[0]; ?>;"><?php echo e($conf2); ?></span></td>
                                        <td><?php echo renderCveBadges($det['cves'] ?? null); ?></td>
                                        <td style="font-size: 12px;"><?php echo !empty($det['last_seen_at']) ? e(date('M j, Y', strtotime($det['last_seen_at']))) : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($tvPg['total_pages'] > 1): ?>
                    <div style="margin-top: 15px;">
                        <?php Pagination::renderControls($tvPg, 'vendors'); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php elseif ($resultType === 'tech_vendors' && empty($results)): ?>
                <div class="card">
                    <p style="color: #6b7280; text-align: center; padding: 20px;"><?php echo e(t('fourth-party-risk.no_vendors_found_prefix')); ?> &ldquo;<?php echo e($searchQuery); ?>&rdquo;<?php echo e(t('fourth-party-risk.no_vendors_found_suffix')); ?></p>
                </div>

                <?php elseif ($resultType === 'cve_vendors' && !empty($results)): ?>
                <!-- CVE Impact — VIEW-based results with source detection -->
                <?php
                // Detect if results come from the VIEW (have cve_id) or fallback tech search
                $isViewResult = isset($results[0]['cve_id']);

                // Group by vendor for summary
                $cveVendorGroups = [];
                foreach ($results as $r) {
                    $vid = $r['vendor_onboarding_id'];
                    if (!isset($cveVendorGroups[$vid])) {
                        $cveVendorGroups[$vid] = [
                            'vendor_name' => $r['vendor_name'] ?? 'Unknown',
                            'vendor_domain' => $r['vendor_domain'] ?? '',
                            'vendor_onboarding_id' => $vid,
                            'findings' => [],
                        ];
                    }
                    $cveVendorGroups[$vid]['findings'][] = $r;
                }
                $cveUniqueVendors = count($cveVendorGroups);
                ?>
                <div class="card">
                    <?php $isCveId = (bool) preg_match('/^CVE-\d{4}/i', $searchQuery); ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                        <h3 style="margin: 0;"><?php echo $isCveId ? e(t('fourth-party-risk.cve_impact')) : e(t('fourth-party-risk.cves_for')); ?>: <?php echo e($searchQuery); ?> (<?php echo $cveUniqueVendors; ?> vendor<?php echo $cveUniqueVendors !== 1 ? 's' : ''; ?>, <?php echo count($results); ?> finding<?php echo count($results) !== 1 ? 's' : ''; ?>)</h3>
                        <div style="display: flex; gap: 8px;">
                            <?php if (preg_match('/^CVE-\d{4}-\d+$/i', $searchQuery)): ?>
                            <a href="https://nvd.nist.gov/vuln/detail/<?php echo urlencode($searchQuery); ?>" target="_blank" rel="noopener" style="padding: 6px 14px; background: var(--theme-button-color); color: white; border-radius: 6px; text-decoration: none; font-size: 12px;"><?php echo e(t('fourth-party-risk.view_on_nvd')); ?></a>
                            <?php endif; ?>
                            <a href="?export=csv&mode=cve&q=<?php echo urlencode($searchQuery); ?>" style="padding: 6px 14px; background: #6b7280; color: white; border-radius: 6px; text-decoration: none; font-size: 12px;"><?php echo e(t('fourth-party-risk.export_csv')); ?></a>
                        </div>
                    </div>
                    <?php if ($isCveId): ?>
                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b;">
                        <strong>CVE Impact:</strong> <?php echo e($searchQuery); ?> was detected across <?php echo $cveUniqueVendors; ?> vendor(s) in your portfolio on <?php echo count($results); ?> host(s). <?php echo e(t('fourth-party-risk.red_dot_note')); ?>
                    </div>
                    <?php else: ?>
                    <div style="background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #1e40af;">
                        <strong>Vendor CVEs:</strong> Showing all CVE findings detected across hosts for &ldquo;<?php echo e($searchQuery); ?>&rdquo;. <?php echo e(t('fourth-party-risk.red_dot_note')); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($bulkAssessmentMessage)): ?>
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #166534;">
                        <?php echo e($bulkAssessmentMessage); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($bulkAssessmentError)): ?>
                    <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b;">
                        <?php echo e($bulkAssessmentError); ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($isViewResult): ?>
                    <?php if (!$isAuditor): ?>
                    <!-- Selection toolbar for CVE results -->
                    <div id="cveSelectionToolbar" style="display: flex; align-items: center; gap: 15px; padding: 10px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #374151; margin: 0; user-select: none;">
                            <input type="checkbox" id="cveSelectAll" style="width: 16px; height: 16px; cursor: pointer;">
                            <?php echo e(t('fourth-party-risk.select_all_visible')); ?>
                        </label>
                        <button type="button" id="cveAssignBtn" disabled style="padding: 8px 18px; background: var(--theme-button-color); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; opacity: 0.5;">
                            <?php echo e(t('fourth-party-risk.assign_assessment')); ?> (<span id="cveSelectedCount">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Severity filter buttons -->
                    <?php
                    $sevCounts = ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];
                    foreach ($results as $r) {
                        $s = strtolower($r['severity'] ?? 'unknown');
                        if (isset($sevCounts[$s])) $sevCounts[$s]++;
                    }
                    ?>
                    <div style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                        <span style="font-size: 12px; font-weight: 600; color: #6b7280; margin-right: 4px;"><?php echo e(t('fourth-party-risk.filter_label')); ?></span>
                        <button class="sev-filter-btn active" data-sev-filter="all" style="padding: 5px 14px; border-radius: 16px; border: 1px solid #d1d5db; background: #374151; color: #fff; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.sev_all', count($results))); ?></button>
                        <?php if ($sevCounts['critical'] > 0): ?>
                        <button class="sev-filter-btn" data-sev-filter="critical" style="padding: 5px 14px; border-radius: 16px; border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.sev_critical', $sevCounts['critical'])); ?></button>
                        <?php endif; ?>
                        <?php if ($sevCounts['high'] > 0): ?>
                        <button class="sev-filter-btn" data-sev-filter="high" style="padding: 5px 14px; border-radius: 16px; border: 1px solid #fed7aa; background: #fff7ed; color: #c2410c; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.sev_high', $sevCounts['high'])); ?></button>
                        <?php endif; ?>
                        <?php if ($sevCounts['medium'] > 0): ?>
                        <button class="sev-filter-btn" data-sev-filter="medium" style="padding: 5px 14px; border-radius: 16px; border: 1px solid #fcd34d; background: #fffbeb; color: #b45309; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.sev_medium', $sevCounts['medium'])); ?></button>
                        <?php endif; ?>
                        <?php if ($sevCounts['low'] > 0): ?>
                        <button class="sev-filter-btn" data-sev-filter="low" style="padding: 5px 14px; border-radius: 16px; border: 1px solid #86efac; background: #f0fdf4; color: #166534; font-size: 12px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.sev_low', $sevCounts['low'])); ?></button>
                        <?php endif; ?>
                    </div>
                    <!-- VIEW-based results: flat table with source columns -->
                    <div style="overflow-x: auto;">
                        <table data-sortable-table="cveResultsBody">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"></th>
                                    <th class="sortable" data-sort-col="1"><?php echo e(t('fourth-party-risk.col_vendor')); ?></th>
                                    <th class="sortable" data-sort-col="2"><?php echo e(t('fourth-party-risk.col_domain')); ?></th>
                                    <th class="sortable" data-sort-col="3"><?php echo e(t('fourth-party-risk.col_subdomain')); ?></th>
                                    <th class="sortable" data-sort-col="4"><?php echo e(t('fourth-party-risk.col_ip')); ?></th>
                                    <th class="sortable" data-sort-col="5"><?php echo e(t('fourth-party-risk.col_cve')); ?></th>
                                    <th class="sortable" data-sort-col="6" data-sort-type="numeric"><?php echo e(t('fourth-party-risk.col_cvss')); ?></th>
                                    <th class="sortable" data-sort-col="7"><?php echo e(t('fourth-party-risk.col_severity')); ?></th>
                                    <th><?php echo e(t('fourth-party-risk.col_status')); ?></th>
                                    <th style="text-align: center;"><?php echo e($upguardDisplayName); ?></th>
                                    <th style="text-align: center;"><?php echo e($shodanDisplayName); ?></th>
                                </tr>
                            </thead>
                            <tbody id="cveResultsBody">
                                <?php foreach ($results as $r):
                                    $sev = strtolower($r['severity'] ?? 'unknown');
                                    $sevColors = ['critical'=>['#991b1b','#fef2f2'],'high'=>['#c2410c','#fff7ed'],'medium'=>['#b45309','#fffbeb'],'low'=>['#166534','#f0fdf4'],'positive'=>['#166534','#f0fdf4'],'negative'=>['#991b1b','#fef2f2'],'unknown'=>['#6b7280','#f3f4f6']];
                                    $sc = $sevColors[$sev] ?? $sevColors['unknown'];
                                ?>
                                <tr class="cve-result-row" data-severity="<?php echo e($sev); ?>">
                                    <?php if (!$isAuditor): ?>
                                    <td>
                                        <?php if (!empty($r['vendor_onboarding_id'])): ?>
                                        <input type="checkbox" class="cve-vendor-select-cb"
                                               value="<?php echo (int)$r['vendor_onboarding_id']; ?>"
                                               data-vendor-name="<?php echo e($r['vendor_name']); ?>"
                                               style="width: 16px; height: 16px; cursor: pointer;">
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if (!empty($r['vendor_onboarding_id'])): ?>
                                        <a href="vendor-srs-details.php?id=<?php echo (int)$r['vendor_onboarding_id']; ?>" style="color: var(--theme-header-color); text-decoration: none; font-weight: 600;"><?php echo e($r['vendor_name']); ?></a>
                                        <?php else: ?>
                                        <span style="font-weight: 600;"><?php echo e($r['vendor_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 12px;"><?php echo e($r['vendor_domain'] ?? '-'); ?></td>
                                    <td style="font-family: monospace; font-size: 12px;"><?php echo e($r['subdomain'] ?? '-'); ?></td>
                                    <td style="font-family: monospace; font-size: 12px;"><?php echo e($r['ip_address'] ?? '-'); ?></td>
                                    <td><a href="https://nvd.nist.gov/vuln/detail/<?php echo urlencode($r['cve_id']); ?>" target="_blank" rel="noopener" style="color: #991b1b; text-decoration: none; font-weight: 600; font-size: 12px;"><?php echo e($r['cve_id']); ?></a></td>
                                    <td style="font-weight: 600;"><?php echo $r['cvss_score'] !== null ? e(number_format((float)$r['cvss_score'], 1)) : '-'; ?></td>
                                    <td><span class="badge" style="background: <?php echo $sc[1]; ?>; color: <?php echo $sc[0]; ?>;"><?php echo e(ucfirst($sev)); ?></span></td>
                                    <td class="cve-status-cell" data-vendor-id="<?php echo (int)$r['vendor_onboarding_id']; ?>" data-cve-id="<?php echo e($r['cve_id']); ?>">
                                        <?php if (!empty($r['waived'])): ?>
                                        <span class="badge" style="background: #ede9fe; color: #7c3aed;" title="<?php echo e(($r['waived_reason'] ?? '') . ($r['waived_by'] ? ' (by ' . $r['waived_by'] . ')' : '')); ?>"><?php echo e(t('fourth-party-risk.waived')); ?></span>
                                        <button type="button" class="cve-unwaive-btn" title="<?php echo e(t('fourth-party-risk.remove_waiver')); ?>" style="background: none; border: none; cursor: pointer; font-size: 14px; padding: 2px 4px; vertical-align: middle; color: #991b1b; opacity: 0.5;" onmouseenter="this.style.opacity='1'" onmouseleave="this.style.opacity='0.5'">&#10005;</button>
                                        <?php else: ?>
                                        <button type="button" class="cve-waive-btn" title="<?php echo e(t('fourth-party-risk.waive_cve_title')); ?>" style="background: none; border: none; cursor: pointer; font-size: 15px; padding: 2px 4px; vertical-align: middle; color: #7c3aed; opacity: 0.4;" onmouseenter="this.style.opacity='1'" onmouseleave="this.style.opacity='0.4'">&#128683;</button>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if (!empty($r['upguard_detected'])): ?>
                                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #dc2626;" title="<?php echo e($upguardDisplayName); ?> detected risks on this host"></span>
                                        <?php else: ?>
                                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #e5e7eb;" title="No <?php echo e($upguardDisplayName); ?> detection on this host"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if (!empty($r['shodan_detected'])): ?>
                                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #dc2626;" title="<?php echo e($shodanDisplayName); ?> detected this CVE"></span>
                                        <?php else: ?>
                                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #e5e7eb;" title="No <?php echo e($shodanDisplayName); ?> detection"></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>
                    <!-- Fallback: tech-based CVE search (grouped by vendor) -->
                    <?php if (!$isAuditor): ?>
                    <!-- Selection toolbar for CVE grouped fallback -->
                    <div id="cveSelectionToolbar" style="display: flex; align-items: center; gap: 15px; padding: 10px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #374151; margin: 0; user-select: none;">
                            <input type="checkbox" id="cveSelectAll" style="width: 16px; height: 16px; cursor: pointer;">
                            <?php echo e(t('fourth-party-risk.select_all_visible')); ?>
                        </label>
                        <button type="button" id="cveAssignBtn" disabled style="padding: 8px 18px; background: var(--theme-button-color); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; opacity: 0.5;">
                            <?php echo e(t('fourth-party-risk.assign_assessment')); ?> (<span id="cveSelectedCount">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php $cvIdx = 0; foreach ($cveVendorGroups as $vid => $cvg): $cvIdx++; ?>
                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px; overflow: hidden;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; cursor: pointer;" data-action="toggleBlastVendor" data-arg="cveVendor<?php echo $cvIdx; ?>">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($cvg['vendor_onboarding_id']) && !$isAuditor): ?>
                                <label onclick="event.stopPropagation();" style="display: flex; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" class="cve-vendor-select-cb"
                                           value="<?php echo (int)$cvg['vendor_onboarding_id']; ?>"
                                           data-vendor-name="<?php echo e($cvg['vendor_name']); ?>"
                                           style="width: 16px; height: 16px; cursor: pointer;">
                                </label>
                                <?php endif; ?>
                                <span id="blastArrowcveVendor<?php echo $cvIdx; ?>" style="font-size: 10px; color: #9ca3af; transition: transform 0.2s; display: inline-block;">&#9654;</span>
                                <div>
                                    <?php if (!empty($cvg['vendor_onboarding_id'])): ?>
                                    <a href="vendor-srs-details.php?id=<?php echo (int)$cvg['vendor_onboarding_id']; ?>" style="color: var(--theme-header-color); text-decoration: none; font-weight: 600; font-size: 14px;" onclick="event.stopPropagation();"><?php echo e($cvg['vendor_name']); ?></a>
                                    <?php else: ?>
                                    <span style="font-weight: 600; font-size: 14px;"><?php echo e($cvg['vendor_name']); ?></span>
                                    <?php endif; ?>
                                    <span style="font-size: 12px; color: #6b7280; margin-left: 8px; font-family: monospace;"><?php echo e($cvg['vendor_domain']); ?></span>
                                </div>
                            </div>
                            <span class="badge" style="background: #fef2f2; color: #991b1b;"><?php echo count($cvg['findings']); ?> affected tech<?php echo count($cvg['findings']) !== 1 ? 's' : ''; ?></span>
                        </div>
                        <div id="cveVendor<?php echo $cvIdx; ?>" style="display: none; border-top: 1px solid #e5e7eb;">
                            <table style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th><?php echo e(t('fourth-party-risk.col_technology')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_version')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_category')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_detected_on')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_port')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_all_cves')); ?></th>
                                        <th><?php echo e(t('fourth-party-risk.col_last_seen')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cvg['findings'] as $det): ?>
                                    <tr>
                                        <td style="font-weight: 600; font-size: 13px;"><?php echo e($det['technology_name']); ?></td>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo e($det['technology_version'] ?? '-'); ?></td>
                                        <td><?php
                                            $cc3 = $catColors[$det['technology_category']] ?? '#6b7280';
                                            $catLabel3 = ucwords(str_replace('_', ' ', $det['technology_category']));
                                        ?><span class="badge" style="background: <?php echo $cc3; ?>15; color: <?php echo $cc3; ?>;"><?php echo e($catLabel3); ?></span></td>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo e($det['detected_on'] ?? '-'); ?></td>
                                        <td style="font-size: 12px;"><?php echo $det['detected_port'] ? e($det['detected_port']) : '-'; ?></td>
                                        <td><?php echo renderCveBadges($det['cves'] ?? null); ?></td>
                                        <td style="font-size: 12px;"><?php echo !empty($det['last_seen_at']) ? e(date('M j, Y', strtotime($det['last_seen_at']))) : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php elseif ($resultType === 'cve_vendors' && empty($results)): ?>
                <div class="card">
                    <p style="color: #6b7280; text-align: center; padding: 20px;"><?php echo e(t('fourth-party-risk.no_cve_results_prefix')); ?> &ldquo;<?php echo e($searchQuery); ?>&rdquo;. <?php echo preg_match('/^CVE-/i', $searchQuery) ? e(t('fourth-party-risk.no_cve_results_cve_msg')) : e(t('fourth-party-risk.no_cve_results_general_msg')); ?></p>
                </div>
                <?php endif; ?>

                <!-- Technology Concentration Dashboard (shown in 4th party risk mode, not CVE mode) -->
                <?php if (!empty($concentrationRaw) && $searchMode !== 'cve'): ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                        <h3 style="margin: 0;"><?php echo e(t('fourth-party-risk.technology_concentration')); ?></h3>
                        <form method="get" style="display: flex; gap: 8px; align-items: center;">
                            <?php if (!empty($searchMode)): ?><input type="hidden" name="mode" value="<?php echo e($searchMode); ?>"><?php endif; ?>
                            <?php if (!empty($searchQuery)): ?><input type="hidden" name="q" value="<?php echo e($searchQuery); ?>"><?php endif; ?>
                            <select name="c_cat" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; cursor: pointer;" data-submit-form>
                                <option value=""><?php echo e(t('fourth-party-risk.all_categories')); ?></option>
                                <?php foreach ($concCategories as $cat): ?>
                                <option value="<?php echo e($cat); ?>" <?php echo $concCatFilter === $cat ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $cat))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="c_q" placeholder="<?php echo e(t('fourth-party-risk.filter_technologies')); ?>" value="<?php echo e($concSearchFilter); ?>" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; width: 200px;">
                            <button type="submit" style="padding: 6px 14px; background: var(--theme-button-color); color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;"><?php echo e(t('fourth-party-risk.filter')); ?></button>
                            <?php if ($concCatFilter !== '' || $concSearchFilter !== ''): ?>
                            <a href="?<?php echo !empty($searchMode) ? 'mode=' . urlencode($searchMode) . '&q=' . urlencode($searchQuery) : ''; ?>" style="font-size: 12px; color: #6b7280; text-decoration: none;"><?php echo e(t('fourth-party-risk.clear')); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <p style="color: #6b7280; font-size: 13px; margin: 0 0 15px 0;"><?php echo e(t('fourth-party-risk.click_tech_hint')); ?></p>
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $concSortCol === 'technology_name' ? ($concSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>">
                                    <a href="<?php echo Pagination::buildSortUrl('technology_name', $concSortCol, $concSortDir, 'c_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_technology')); ?><?php echo Pagination::getSortIndicator('technology_name', $concSortCol, $concSortDir); ?></a>
                                </th>
                                <th class="sortable <?php echo $concSortCol === 'technology_category' ? ($concSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>">
                                    <a href="<?php echo Pagination::buildSortUrl('technology_category', $concSortCol, $concSortDir, 'c_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_category')); ?><?php echo Pagination::getSortIndicator('technology_category', $concSortCol, $concSortDir); ?></a>
                                </th>
                                <th class="sortable <?php echo $concSortCol === 'vendor_count' ? ($concSortDir === 'ASC' ? 'sort-asc' : 'sort-desc') : ''; ?>" style="text-align: right;">
                                    <a href="<?php echo Pagination::buildSortUrl('vendor_count', $concSortCol, $concSortDir, 'c_'); ?>" style="text-decoration: none; color: inherit;"><?php echo e(t('fourth-party-risk.col_vendor_count')); ?><?php echo Pagination::getSortIndicator('vendor_count', $concSortCol, $concSortDir); ?></a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $catColors = [
                                'cdn' => '#2563eb', 'web-server' => '#059669', 'cms' => '#7c3aed',
                                'javascript' => '#d97706', 'programming-language' => '#dc2626',
                                'analytics' => '#0891b2', 'security' => '#16a34a', 'hosting' => '#9333ea',
                                'cache' => '#ca8a04', 'paas' => '#0d9488', 'dns' => '#4f46e5',
                                'ssl' => '#15803d', 'web-framework' => '#c026d3', 'font' => '#64748b',
                                'marketing' => '#ea580c', 'email' => '#2563eb', 'database' => '#b91c1c',
                            ];
                            foreach ($concentrationPage as $c): ?>
                            <tr class="conc-row" data-action="searchTechConcentration" data-arg="<?php echo e($c['technology_name']); ?>">
                                <td style="font-weight: 600; font-size: 13px;"><?php echo e($c['technology_name']); ?></td>
                                <td><?php
                                    $cats = array_map('trim', explode(',', $c['technology_category']));
                                    $badges = [];
                                    foreach ($cats as $cat) {
                                        $cc = $catColors[$cat] ?? '#6b7280';
                                        $badges[] = '<span class="badge" style="background: ' . $cc . '15; color: ' . $cc . ';">' . e(ucwords(str_replace('_', ' ', $cat))) . '</span>';
                                    }
                                    echo implode(' ', $badges);
                                ?></td>
                                <td style="text-align: right; font-weight: 700; font-size: 15px; color: var(--theme-header-color, #2563eb);"><?php echo (int)$c['vendor_count']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($concentrationPage)): ?>
                            <tr><td colspan="3" style="text-align: center; padding: 20px; color: #9ca3af;"><?php echo e(t('fourth-party-risk.no_technologies_filter')); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php Pagination::renderControls($concPg, 'technologies', [25, 50, 100], 'c_'); ?>
                </div>
                <?php endif; ?>

                <?php if ($searchMode !== 'cve'): ?>
                <?php if (!$techTableExists): ?>
                <div class="card" style="text-align: center; padding: 40px;">
                    <p style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px 0;"><?php echo e(t('fourth-party-risk.no_tech_data_title')); ?></p>
                    <p style="color: #6b7280; margin: 0;"><?php echo t('fourth-party-risk.no_tech_data_desc'); ?></p>
                </div>
                <?php elseif (empty($concentrationRaw) && empty($results)): ?>
                <div class="card" style="text-align: center; padding: 40px;">
                    <p style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px 0;"><?php echo e(t('fourth-party-risk.no_tech_detected_title')); ?></p>
                    <p style="color: #6b7280; margin: 0;"><?php echo e(t('fourth-party-risk.no_tech_detected_desc')); ?></p>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </main>
        </div>

        <!-- Mass Waive CVEs Modal -->
        <div id="massWaiveModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; width: 95%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px; color: #1f2937;"><?php echo e(t('fourth-party-risk.mass_waive_cves')); ?></h3>
                    <button type="button" id="massWaiveClose" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <form method="post" action="?mode=cve<?php echo !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : ''; ?>">
                    <input type="hidden" name="action" value="mass_waive_cves">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="vendor_id" id="massWaiveVendorId" value="">
                    <div style="padding: 24px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_vendor')); ?></label>
                            <div style="position: relative;">
                                <input type="text" id="massWaiveVendorSearch" placeholder="<?php echo e(t('fourth-party-risk.mass_waive_vendor_search_placeholder')); ?>" autocomplete="off" style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                <div id="massWaiveVendorDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto; z-index: 10; box-shadow: 0 4px 12px rgba(0,0,0,0.1);"></div>
                            </div>
                            <div id="massWaiveVendorSelected" style="display: none; margin-top: 8px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; font-size: 13px; color: #166534;">
                                <span id="massWaiveVendorLabel"></span>
                                <button type="button" id="massWaiveVendorClear" style="float: right; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 12px; padding: 0;"><?php echo e(t('fourth-party-risk.clear')); ?></button>
                            </div>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_cve_ids')); ?></label>
                            <textarea name="cve_ids" id="massWaiveCveIds" rows="6" placeholder="Enter CVE IDs or wildcard prefixes, one per line:&#10;CVE-2024-38474&#10;CVE-2007-*&#10;CVE-2023-12345" style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: monospace; outline: none; resize: vertical; box-sizing: border-box;"></textarea>
                            <p style="margin: 4px 0 0; font-size: 11px; color: #9ca3af;"><?php echo e(t('fourth-party-risk.mass_waive_help')); ?></p>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_reason_optional')); ?></label>
                            <input type="text" name="reason" placeholder="<?php echo e(t('fourth-party-risk.mass_waive_reason_placeholder')); ?>" style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>
                    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; border-radius: 0 0 12px 12px;">
                        <button type="button" id="massWaiveCancel" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; cursor: pointer;"><?php echo e(t('fourth-party-risk.cancel')); ?></button>
                        <button type="submit" style="padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.waive_cves_btn')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!$isAuditor): ?>
        <!-- Bulk Assign Assessment Modal -->
        <div id="bulkAssessmentModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; width: 95%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px; color: #1f2937;"><?php echo e(t('fourth-party-risk.assign_assessment')); ?></h3>
                    <button type="button" id="bulkAssessmentClose" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <form method="post" action="?mode=<?php echo urlencode($searchMode ?: 'technology'); ?>&q=<?php echo urlencode($searchQuery); ?>">
                    <input type="hidden" name="action" value="bulk_assign_assessment">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="vendor_ids" id="bulkAssessmentVendorIds" value="[]">
                    <div style="padding: 24px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_selected_vendors')); ?></label>
                            <div id="bulkAssessmentVendorSummary" style="font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 6px;"></div>
                            <div id="bulkAssessmentVendorList" style="max-height: 150px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; font-size: 12px; color: #374151; background: #f9fafb;"></div>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_assessment_template')); ?></label>
                            <select name="template_id" id="bulkAssessmentTemplate" required style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; background: white;">
                                <option value=""><?php echo e(t('fourth-party-risk.select_template_option')); ?></option>
                                <?php
                                $templatesByCategory = [];
                                foreach ($assessmentTemplates as $t) {
                                    $cat = $t['category'] ?? 'other';
                                    $templatesByCategory[$cat][] = $t;
                                }
                                foreach ($templatesByCategory as $cat => $templates): ?>
                                <optgroup label="<?php echo e(ucwords(str_replace('_', ' ', $cat))); ?>">
                                    <?php foreach ($templates as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?> (<?php echo (int)$t['question_count']; ?> <?php echo e(t('fourth-party-risk.questions_label')); ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;"><?php echo e(t('fourth-party-risk.label_expires_in')); ?></label>
                            <select name="expires_in_days" style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; background: white;">
                                <option value="14"><?php echo e(t('fourth-party-risk.expires_14_days')); ?></option>
                                <option value="30" selected><?php echo e(t('fourth-party-risk.expires_30_days')); ?></option>
                                <option value="60"><?php echo e(t('fourth-party-risk.expires_60_days')); ?></option>
                                <option value="90"><?php echo e(t('fourth-party-risk.expires_90_days')); ?></option>
                            </select>
                        </div>
                    </div>
                    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; border-radius: 0 0 12px 12px;">
                        <button type="button" id="bulkAssessmentCancel" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; cursor: pointer;"><?php echo e(t('fourth-party-risk.cancel')); ?></button>
                        <button type="submit" id="bulkAssessmentSubmit" style="padding: 10px 20px; background: var(--theme-button-color); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;"><?php echo e(t('fourth-party-risk.assign_assessment')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subprocessor "Send Assessment" vendor selector -->
        <div id="spVendorSelectModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; width: 95%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.25);">
                <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px; color: #1f2937;"><?php echo e(t('fourth-party-risk.send_assessment')); ?></h3>
                    <button type="button" id="spVendorSelectClose" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <div style="padding: 24px;">
                    <p style="margin: 0 0 6px 0; font-size: 13px; color: #6b7280;"><?php echo e(t('fourth-party-risk.select_vendors_to_assess')); ?></p>
                    <div id="spVendorSelectSubName" style="font-size: 14px; font-weight: 700; color: #1f2937; margin-bottom: 14px;"></div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #374151; margin: 0 0 8px 0; user-select: none;">
                        <input type="checkbox" id="spVendorSelectAll" style="width: 16px; height: 16px; cursor: pointer;">
                        <?php echo e(t('fourth-party-risk.select_all_visible')); ?>
                    </label>
                    <div id="spVendorSelectList" style="max-height: 260px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; background: #f9fafb;"></div>
                </div>
                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; border-radius: 0 0 12px 12px;">
                    <button type="button" id="spVendorSelectCancel" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; cursor: pointer;"><?php echo e(t('fourth-party-risk.cancel')); ?></button>
                    <button type="button" id="spVendorSelectContinue" disabled style="padding: 10px 20px; background: var(--theme-button-color); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; opacity: 0.5;"><?php echo e(t('fourth-party-risk.assign_assessment')); ?> (<span id="spVendorSelectCount">0</span>)</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <footer class="footer">
            <div class="footer-content" style="justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($theme['footer_logo_url'])): ?>
                        <a href="index.php">
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="Footer Logo" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.5); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.7);">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('fourth-party-risk.all_rights_reserved')); ?></span>
                </p>
            </div>
        </footer>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
    // Click on tech row to search
    window.searchTechConcentration = function(techName) {
        window.location.href = '?mode=technology&q=' + encodeURIComponent(techName);
    };

    // Toggle blast radius vendor detail (works for blastVendorN and cveVendorN)
    window.toggleBlastVendor = function(id) {
        var body = document.getElementById(id);
        if (!body) return;
        var arrow = document.getElementById('blastArrow' + id);
        var showing = body.style.display === 'none';
        body.style.display = showing ? '' : 'none';
        if (arrow) arrow.style.transform = showing ? 'rotate(90deg)' : 'rotate(0deg)';
    };

    // Per-page selector with prefix support (for independent paginators)
    document.addEventListener('change', function(e) {
        var el = e.target.closest('[data-action="changePerPage"]');
        if (!el) return;
        var prefix = el.getAttribute('data-prefix') || '';
        var params = new URLSearchParams(window.location.search);
        params.set(prefix + 'per_page', el.value);
        params.delete(prefix + 'page');
        window.location.href = '?' + params.toString();
    });

    // Vendor tech result filtering
    (function() {
        var searchInput = document.getElementById('vtResultSearch');
        if (!searchInput) return;
        searchInput.addEventListener('input', function() {
            var term = this.value.toLowerCase().trim();
            document.querySelectorAll('.vt-result-row').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
            });
        });
    })();

    // Severity filter for CVE results
    (function() {
        var buttons = document.querySelectorAll('.sev-filter-btn');
        if (!buttons.length) return;
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sev = btn.getAttribute('data-sev-filter');
                buttons.forEach(function(b) {
                    b.classList.remove('active');
                    b.style.opacity = '0.6';
                });
                btn.classList.add('active');
                btn.style.opacity = '1';
                document.querySelectorAll('.cve-result-row').forEach(function(row) {
                    if (sev === 'all' || row.getAttribute('data-severity') === sev) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
            // Set initial opacity
            if (!btn.classList.contains('active')) btn.style.opacity = '0.6';
        });
    })();

    // Inline waive/unwaive single CVE
    (function() {
        var csrfToken = <?php echo json_encode($csrfToken); ?>;

        document.addEventListener('click', function(e) {
            var waiveBtn = e.target.closest('.cve-waive-btn');
            var unwaiveBtn = e.target.closest('.cve-unwaive-btn');
            if (!waiveBtn && !unwaiveBtn) return;

            var cell = (waiveBtn || unwaiveBtn).closest('.cve-status-cell');
            if (!cell) return;

            var vendorId = cell.getAttribute('data-vendor-id');
            var cveId = cell.getAttribute('data-cve-id');
            var action = waiveBtn ? 'waive_single_cve' : 'unwaive_single_cve';

            var btn = waiveBtn || unwaiveBtn;
            btn.style.opacity = '0.3';
            btn.disabled = true;

            var formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);
            formData.append('vendor_id', vendorId);
            formData.append('cve_id', cveId);

            fetch(window.location.pathname + '?mode=cve', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(<?php echo json_encode(t('fourth-party-risk.js_error_prefix')); ?> + (data.error || <?php echo json_encode(t('fourth-party-risk.js_unknown_error')); ?>));
                    btn.style.opacity = '';
                    btn.disabled = false;
                    return;
                }

                if (action === 'waive_single_cve') {
                    cell.innerHTML = '<span class="badge" style="background: #ede9fe; color: #7c3aed;" title="by ' + (data.waived_by || '') + '">Waived</span> '
                        + '<button type="button" class="cve-unwaive-btn" title="Remove waiver" style="background: none; border: none; cursor: pointer; font-size: 14px; padding: 2px 4px; vertical-align: middle; color: #991b1b; opacity: 0.5;" onmouseenter="this.style.opacity=\'1\'" onmouseleave="this.style.opacity=\'0.5\'">&#10005;</button>';
                } else {
                    cell.innerHTML = '<button type="button" class="cve-waive-btn" title="Waive this CVE for this vendor" style="background: none; border: none; cursor: pointer; font-size: 15px; padding: 2px 4px; vertical-align: middle; color: #7c3aed; opacity: 0.4;" onmouseenter="this.style.opacity=\'1\'" onmouseleave="this.style.opacity=\'0.4\'">&#128683;</button>';
                }
            })
            .catch(function() {
                alert(<?php echo json_encode(t('fourth-party-risk.js_network_error')); ?>);
                btn.style.opacity = '';
                btn.disabled = false;
            });
        });
    })();

    // Mass Waive CVEs modal
    (function() {
        var modal = document.getElementById('massWaiveModal');
        var openBtn = document.getElementById('massWaiveBtn');
        var closeBtn = document.getElementById('massWaiveClose');
        var cancelBtn = document.getElementById('massWaiveCancel');
        var vendorSearch = document.getElementById('massWaiveVendorSearch');
        var vendorDropdown = document.getElementById('massWaiveVendorDropdown');
        var vendorIdInput = document.getElementById('massWaiveVendorId');
        var vendorSelected = document.getElementById('massWaiveVendorSelected');
        var vendorLabel = document.getElementById('massWaiveVendorLabel');
        var vendorClear = document.getElementById('massWaiveVendorClear');
        if (!modal || !openBtn) return;

        function openModal() { modal.style.display = 'flex'; vendorSearch.focus(); }
        function closeModal() { modal.style.display = 'none'; }

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.style.display === 'flex') closeModal(); });

        // Vendor type-ahead search with debounce
        var debounceTimer = null;
        vendorSearch.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var q = vendorSearch.value.trim();
            if (q.length < 2) { vendorDropdown.style.display = 'none'; return; }
            debounceTimer = setTimeout(function() {
                fetch('api/search-waiver-vendors.php?action=search&q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.vendors.length) {
                            vendorDropdown.innerHTML = '<div style="padding: 10px 14px; color: #9ca3af; font-size: 13px;">' + <?php echo json_encode(t('fourth-party-risk.js_no_vendors_found')); ?> + '</div>';
                            vendorDropdown.style.display = 'block';
                            return;
                        }
                        var html = '';
                        data.vendors.forEach(function(v) {
                            html += '<div class="mw-vendor-option" data-id="' + v.id + '" data-name="' + (v.vendor_name || '').replace(/"/g, '&quot;') + '" data-domain="' + (v.vendor_domain || '').replace(/"/g, '&quot;') + '" style="padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px;">';
                            html += '<div style="font-weight: 600;">' + (v.vendor_name || '') + '</div>';
                            html += '<div style="font-size: 11px; color: #6b7280; font-family: monospace;">' + (v.vendor_domain || '') + '</div>';
                            html += '</div>';
                        });
                        vendorDropdown.innerHTML = html;
                        vendorDropdown.style.display = 'block';

                        vendorDropdown.querySelectorAll('.mw-vendor-option').forEach(function(opt) {
                            opt.addEventListener('mouseenter', function() { opt.style.background = '#f3f4f6'; });
                            opt.addEventListener('mouseleave', function() { opt.style.background = ''; });
                            opt.addEventListener('click', function() {
                                vendorIdInput.value = opt.getAttribute('data-id');
                                vendorLabel.textContent = opt.getAttribute('data-name') + ' (' + opt.getAttribute('data-domain') + ')';
                                vendorSelected.style.display = 'block';
                                vendorSearch.style.display = 'none';
                                vendorDropdown.style.display = 'none';
                            });
                        });
                    });
            }, 300);
        });

        vendorClear.addEventListener('click', function() {
            vendorIdInput.value = '';
            vendorLabel.textContent = '';
            vendorSelected.style.display = 'none';
            vendorSearch.style.display = '';
            vendorSearch.value = '';
            vendorSearch.focus();
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!vendorSearch.contains(e.target) && !vendorDropdown.contains(e.target)) {
                vendorDropdown.style.display = 'none';
            }
        });
    })();

    // Bulk Assign Assessment — selection and modal
    (function() {
        var selectAll = document.getElementById('blastSelectAll');
        var assignBtn = document.getElementById('blastAssignBtn');
        var countSpan = document.getElementById('blastSelectedCount');
        var modal = document.getElementById('bulkAssessmentModal');
        var closeBtn = document.getElementById('bulkAssessmentClose');
        var cancelBtn = document.getElementById('bulkAssessmentCancel');
        var vendorIdsInput = document.getElementById('bulkAssessmentVendorIds');
        var vendorSummary = document.getElementById('bulkAssessmentVendorSummary');
        var vendorList = document.getElementById('bulkAssessmentVendorList');
        if (!selectAll || !assignBtn || !modal) return;

        function getCheckboxes() {
            return document.querySelectorAll('.vendor-select-cb');
        }

        function getChecked() {
            return document.querySelectorAll('.vendor-select-cb:checked');
        }

        function updateState() {
            var all = getCheckboxes();
            var checked = getChecked();
            var count = checked.length;
            countSpan.textContent = count;
            assignBtn.disabled = count === 0;
            assignBtn.style.opacity = count === 0 ? '0.5' : '1';

            if (all.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === all.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
        }

        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            getCheckboxes().forEach(function(cb) { cb.checked = checked; });
            updateState();
        });

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('vendor-select-cb')) {
                updateState();
            }
        });

        function openModal() {
            var checked = getChecked();
            if (!checked.length) return;
            var ids = [];
            var names = [];
            checked.forEach(function(cb) {
                ids.push(parseInt(cb.value, 10));
                names.push(cb.getAttribute('data-vendor-name'));
            });
            vendorIdsInput.value = JSON.stringify(ids);
            vendorSummary.textContent = ids.length + ' vendor' + (ids.length !== 1 ? 's' : '') + ' selected';
            vendorList.innerHTML = names.map(function(n) {
                return '<div style="padding: 3px 0; border-bottom: 1px solid #f3f4f6;">' + n.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
            }).join('');
            modal.style.display = 'flex';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        assignBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
    })();

    // CVE Bulk Assign Assessment — selection and modal
    (function() {
        var selectAll = document.getElementById('cveSelectAll');
        var assignBtn = document.getElementById('cveAssignBtn');
        var countSpan = document.getElementById('cveSelectedCount');
        var modal = document.getElementById('bulkAssessmentModal');
        var vendorIdsInput = document.getElementById('bulkAssessmentVendorIds');
        var vendorSummary = document.getElementById('bulkAssessmentVendorSummary');
        var vendorList = document.getElementById('bulkAssessmentVendorList');
        if (!selectAll || !assignBtn || !modal) return;

        function getCheckboxes() {
            return document.querySelectorAll('.cve-vendor-select-cb');
        }

        // De-duplicate checked vendors by vendor_onboarding_id
        function getUniqueChecked() {
            var seen = {};
            var unique = [];
            document.querySelectorAll('.cve-vendor-select-cb:checked').forEach(function(cb) {
                var vid = cb.value;
                if (!seen[vid]) {
                    seen[vid] = true;
                    unique.push(cb);
                }
            });
            return unique;
        }

        function updateCveState() {
            var all = getCheckboxes();
            var unique = getUniqueChecked();
            var count = unique.length;
            countSpan.textContent = count;
            assignBtn.disabled = count === 0;
            assignBtn.style.opacity = count === 0 ? '0.5' : '1';

            // Count unique vendor IDs among all checkboxes
            var allVids = {};
            all.forEach(function(cb) { allVids[cb.value] = true; });
            var totalUnique = Object.keys(allVids).length;

            if (totalUnique === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === totalUnique) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
        }

        // When a checkbox is toggled in flat table, sync all checkboxes with same vendor ID
        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('cve-vendor-select-cb')) return;
            var vid = e.target.value;
            var checked = e.target.checked;
            document.querySelectorAll('.cve-vendor-select-cb[value="' + vid + '"]').forEach(function(cb) {
                cb.checked = checked;
            });
            updateCveState();
        });

        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            getCheckboxes().forEach(function(cb) { cb.checked = checked; });
            updateCveState();
        });

        assignBtn.addEventListener('click', function() {
            var unique = getUniqueChecked();
            if (!unique.length) return;
            var ids = [];
            var names = [];
            unique.forEach(function(cb) {
                ids.push(parseInt(cb.value, 10));
                names.push(cb.getAttribute('data-vendor-name'));
            });
            vendorIdsInput.value = JSON.stringify(ids);
            vendorSummary.textContent = ids.length + ' vendor' + (ids.length !== 1 ? 's' : '') + ' selected';
            vendorList.innerHTML = names.map(function(n) {
                return '<div style="padding: 3px 0; border-bottom: 1px solid #f3f4f6;">' + n.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
            }).join('');
            modal.style.display = 'flex';
        });

        var closeBtn = document.getElementById('bulkAssessmentClose');
        var cancelBtn = document.getElementById('bulkAssessmentCancel');
        function closeModal() { modal.style.display = 'none'; }
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
    })();

    // Subprocessor "Send Assessment" — pick vendors using a subprocessor, then assign
    (function() {
        var modal = document.getElementById('spVendorSelectModal');
        var bulkModal = document.getElementById('bulkAssessmentModal');
        if (!modal || !bulkModal) return;
        var subNameEl = document.getElementById('spVendorSelectSubName');
        var listEl = document.getElementById('spVendorSelectList');
        var selectAll = document.getElementById('spVendorSelectAll');
        var continueBtn = document.getElementById('spVendorSelectContinue');
        var countSpan = document.getElementById('spVendorSelectCount');
        var closeBtn = document.getElementById('spVendorSelectClose');
        var cancelBtn = document.getElementById('spVendorSelectCancel');
        var vendorIdsInput = document.getElementById('bulkAssessmentVendorIds');
        var vendorSummary = document.getElementById('bulkAssessmentVendorSummary');
        var vendorList = document.getElementById('bulkAssessmentVendorList');

        function getCheckboxes() { return listEl.querySelectorAll('.sp-vendor-cb'); }
        function getChecked() { return listEl.querySelectorAll('.sp-vendor-cb:checked'); }

        function updateState() {
            var all = getCheckboxes();
            var count = getChecked().length;
            countSpan.textContent = count;
            continueBtn.disabled = count === 0;
            continueBtn.style.opacity = count === 0 ? '0.5' : '1';
            if (all.length === 0 || count === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === all.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
        }

        function openModal(name, vendors) {
            subNameEl.textContent = name;
            listEl.innerHTML = '';
            selectAll.checked = false;
            selectAll.indeterminate = false;
            if (!vendors.length) {
                var empty = document.createElement('div');
                empty.style.cssText = 'padding: 8px 0; font-size: 13px; color: #9ca3af;';
                empty.textContent = <?php echo json_encode(t('fourth-party-risk.js_no_vendors_linked')); ?>;
                listEl.appendChild(empty);
            }
            vendors.forEach(function(v) {
                var label = document.createElement('label');
                label.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #374151; cursor: pointer;';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'sp-vendor-cb';
                cb.value = String(v.id);
                cb.setAttribute('data-vendor-name', v.name);
                cb.style.cssText = 'width: 15px; height: 15px; cursor: pointer;';
                var span = document.createElement('span');
                span.textContent = v.name;
                label.appendChild(cb);
                label.appendChild(span);
                listEl.appendChild(label);
            });
            updateState();
            modal.style.display = 'flex';
        }

        function closeModal() { modal.style.display = 'none'; }

        document.querySelectorAll('.sp-send-assessment-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var name = btn.getAttribute('data-sp-name') || '';
                var vendors = [];
                try { vendors = JSON.parse(btn.getAttribute('data-sp-vendors') || '[]'); } catch (e) { vendors = []; }
                openModal(name, vendors);
            });
        });

        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            getCheckboxes().forEach(function(cb) { cb.checked = checked; });
            updateState();
        });
        listEl.addEventListener('change', function(e) {
            if (e.target.classList.contains('sp-vendor-cb')) updateState();
        });

        continueBtn.addEventListener('click', function() {
            var checked = getChecked();
            if (!checked.length) return;
            var ids = [], names = [];
            checked.forEach(function(cb) {
                ids.push(parseInt(cb.value, 10));
                names.push(cb.getAttribute('data-vendor-name'));
            });
            vendorIdsInput.value = JSON.stringify(ids);
            vendorSummary.textContent = ids.length + ' vendor' + (ids.length !== 1 ? 's' : '') + ' selected';
            vendorList.innerHTML = names.map(function(n) {
                return '<div style="padding: 3px 0; border-bottom: 1px solid #f3f4f6;">' + n.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
            }).join('');
            closeModal();
            bulkModal.style.display = 'flex';
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });

        // The Assign Assessment modal's own close/cancel handlers live in the
        // blast/cve IIFEs, which early-return in subprocessors mode (their
        // toolbars don't render there). Wire them here so the modal is always
        // dismissable when opened from the subprocessor flow. Idempotent with
        // the blast/cve wiring in technology/CVE modes (both just hide it).
        var bulkClose = document.getElementById('bulkAssessmentClose');
        var bulkCancel = document.getElementById('bulkAssessmentCancel');
        function closeBulk() { bulkModal.style.display = 'none'; }
        if (bulkClose) bulkClose.addEventListener('click', closeBulk);
        if (bulkCancel) bulkCancel.addEventListener('click', closeBulk);
        bulkModal.addEventListener('click', function(e) { if (e.target === bulkModal) closeBulk(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && bulkModal.style.display === 'flex') closeBulk();
        });
    })();

    // Generic sortable table handler
    (function() {
        document.querySelectorAll('th.sortable').forEach(function(th) {
            th.addEventListener('click', function() {
                var table = th.closest('table');
                var tbodyId = table.getAttribute('data-sortable-table');
                var tbody = document.getElementById(tbodyId);
                if (!tbody) return;

                var colIdx = parseInt(th.getAttribute('data-sort-col'), 10);
                var isNumeric = th.hasAttribute('data-sort-type') && th.getAttribute('data-sort-type') === 'numeric';

                // Toggle direction
                var wasAsc = th.classList.contains('sort-asc');
                // Clear all sort indicators in this table
                table.querySelectorAll('th.sortable').forEach(function(h) {
                    h.classList.remove('sort-asc', 'sort-desc');
                });
                var dir = wasAsc ? -1 : 1;
                th.classList.add(dir === 1 ? 'sort-asc' : 'sort-desc');

                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    var aText = (a.cells[colIdx] ? a.cells[colIdx].textContent.trim() : '');
                    var bText = (b.cells[colIdx] ? b.cells[colIdx].textContent.trim() : '');
                    if (isNumeric) {
                        var aNum = parseFloat(aText), bNum = parseFloat(bText);
                        if (isNaN(aNum)) aNum = -1;
                        if (isNaN(bNum)) bNum = -1;
                        return (aNum - bNum) * dir;
                    }
                    // Auto-detect numeric values (port numbers, scores)
                    var aNum2 = parseFloat(aText), bNum2 = parseFloat(bText);
                    if (!isNaN(aNum2) && !isNaN(bNum2) && aText.match(/^[\d.]+$/) && bText.match(/^[\d.]+$/)) {
                        return (aNum2 - bNum2) * dir;
                    }
                    return aText.localeCompare(bText) * dir;
                });
                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    })();
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
