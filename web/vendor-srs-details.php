<?php
/**
 * SRS Vendor Details - The Full Dossier on a Single Vendor's Security Posture
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is where you go when you want to know EVERYTHING about a specific vendor's
 * security rating. It's basically the vendor's permanent record. You get the current
 * UpGuard score with a big fat letter grade, a trend chart showing score history over
 * time (so you can see if they're getting better or slowly catching fire), risk counts
 * broken down by severity (critical/high/medium/low/info), category-level scores,
 * and a table of every identified vulnerability. Oh, and you can edit the vendor's
 * tier, type, status, and stakeholder right from here because who wants to click
 * through five pages just to change a dropdown? Also ties into FAIR analysis and
 * vendor assessments because we believe in one-stop shopping.
 */

// Standard init: load the framework and demand authentication
require_once 'includes/init.php';
requireAuth();

// Assemble the Justice League of singleton instances
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$session = Session::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Grab the vendor ID from the URL -- no ID means you probably got here by accident
$vendorId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Permission check -- admins, cyber_tprm, super admins, and assigned stakeholders (read-only).
$canView = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm') || $acl->hasGroup('auditor') || $session->get('is_super_admin');
$isAuditor = $acl->hasGroup('auditor');
$isStakeholderView = false;

if (!$canView) {
    if ($acl->hasGroup('stakeholder') && $vendorId) {
        $stCheck = $db->fetchOne(
            'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
            [':rid' => $vendorId, ':uid' => $auth->getUser()['id']]
        );
        if (!empty($stCheck)) {
            $canView = true;
            $isStakeholderView = true;
        }
    }
}

if (!$canView) {
    http_response_code(403);
    die(e(t('vendor-srs-details.access_denied')));
}

$canDelete = !$isStakeholderView && ($acl->hasPermission('onboarding.delete') || $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm'));

if (!$vendorId) {
    redirect('vendor-onboarding-list.php');
}

// Fetch the full vendor record from the database. If they don't exist, bail out.
$vendor = $db->fetchOne(
    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
    [':id' => $vendorId]
);

if (!$vendor) {
    redirect('vendor-onboarding-list.php?error=not_found');
}

// Spin up the SRS service for scoring operations
require_once __DIR__ . '/includes/classes/SRSService.php';
$srsService = new SRSService();

// Spin up Shodan service if available
require_once __DIR__ . '/includes/classes/ShodanService.php';
$shodanService = new ShodanService();
$shodanAvailable = $shodanService->isAvailable();
$shodanName = $shodanService->getScoringConfig()['display_name'] ?? 'Shodan';
$upguardName = $srsService->getScoringConfig()['display_name'] ?? 'UpGuard';

// Check if cron-based rescoring is enabled for either service
$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'upguard_use_cron'");
$upguardUseCron = ($_cronRow && $_cronRow['config_value'] === '1');
$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'shodan_use_cron'");
$shodanUseCron = ($_cronRow && $_cronRow['config_value'] === '1');

// Detect where the user came from so the "Back" button actually goes back to
// wherever they were, not just a hardcoded page. It's called being a good host.
$referrer = $_SERVER['HTTP_REFERER'] ?? '';
$backUrl = 'vendor-srs-list.php'; // Default fallback
$backTitle = 'SRS Vendor List'; // Default title

if (!empty($referrer)) {
    // Extract the page name from referrer
    $referrerPath = parse_url($referrer, PHP_URL_PATH);
    $referrerPage = basename($referrerPath);

    // Map page names to titles
    $pageTitles = [
        'index.php' => 'Dashboard',
        'cyber-todo.php' => 'Cyber To-Do',
        'vendor-srs-list.php' => 'SRS Vendor List',
        'vendor-onboarding-list.php' => 'Vendor Onboarding',
        'vendor-onboarding.php' => 'Vendor Details',
        'fair_dashboard.php' => 'FAIR Dashboard',
        'reports.php' => 'Reports'
    ];

    if (isset($pageTitles[$referrerPage])) {
        $backUrl = $referrerPage;
        $backTitle = $pageTitles[$referrerPage];

        // Preserve query string if present
        $referrerQuery = parse_url($referrer, PHP_URL_QUERY);
        if (!empty($referrerQuery)) {
            $backUrl .= '?' . $referrerQuery;
        }
    }
}

$error = '';
$success = '';
$restoreScroll = 0;

// The POST handler section -- this page can do a LOT of things via POST:
// - Rescore a vendor (hit the UpGuard API again)
// - Update the vendor tier (with mandatory justification, because auditors love paper trails)
// - Update vendor type, status, or stakeholder assignment
// Basically, this is the Swiss Army knife of vendor management forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isStakeholderView && !$isAuditor) {
    $restoreScroll = intval($_POST['_scroll'] ?? 0);

    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-srs-details.err_invalid_request');
    } elseif (isset($_POST['update_tier'])) {
        // Tier update -- changes how often this vendor gets rescored.
        // We log every tier change into the additional_information field
        // so there's an audit trail. Compliance people love audit trails.
        $newTier = $_POST['new_tier'] ?? '';
        $justification = trim($_POST['tier_justification'] ?? '');
        $oldTier = $vendor['vendor_tier'] ?? '';

        if (!in_array($newTier, ['', '1', '2', '3'])) {
            $error = t('vendor-srs-details.err_invalid_tier');
        } elseif (empty($justification)) {
            $error = t('vendor-srs-details.err_tier_justification_required');
        } else {
            // Build tier change log entry
            $currentDate = date('m/d/Y, h:i A');
            $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown User';

            $tierMap = [
                '' => 'Not Assigned',
                '1' => 'Tier 1 - Critical (Monthly rescoring)',
                '2' => 'Tier 2 - Standard (90-day rescoring)',
                '3' => 'Tier 3 - Low Priority (Annual rescoring)'
            ];

            $oldTierText = $tierMap[$oldTier] ?? 'Unknown';
            $newTierText = $tierMap[$newTier] ?? 'Unknown';

            $logEntry = "\n\n--- Tier Change Log ---\nDate: {$currentDate}\nUser: {$userName}\nChanged From: {$oldTierText}\nChanged To: {$newTierText}\nReason: {$justification}\n-----------------------";

            // Get current additional_information and append log
            $currentAdditionalInfo = $vendor['additional_information'] ?? '';
            $newAdditionalInfo = $currentAdditionalInfo . $logEntry;

            // Update vendor tier and additional information
            try {
                $db->update('vendor_onboarding_requests', [
                    'vendor_tier' => $newTier === '' ? null : $newTier,
                    'additional_information' => $newAdditionalInfo
                ], 'id = :id', [':id' => $vendorId]);

                $auth->audit($user['id'], 'vendor_tier_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['vendor_tier' => $oldTier],
                    'new' => ['vendor_tier' => $newTier, 'justification' => $justification]
                ]);
                $success = t('vendor-srs-details.success_tier_updated');

                // Refresh vendor data
                $vendor = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorId]
                );
            } catch (Exception $e) {
                error_log('Failed to update vendor tier: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_tier_update_failed');
            }
        }
    } elseif (isset($_POST['update_vendor_name'])) {
        // Vendor name update -- sometimes you just need to fix a typo
        $newVendorName = trim($_POST['new_vendor_name'] ?? '');

        if (empty($newVendorName)) {
            $error = t('vendor-srs-details.err_vendor_name_empty');
        } else {
            try {
                $oldName = $vendor['vendor_name'] ?? '';
                $db->update('vendor_onboarding_requests', [
                    'vendor_name' => $newVendorName
                ], 'id = :id', [':id' => $vendorId]);

                // Update FAIR analyses that reference the old vendor name
                if (!empty($oldName) && $oldName !== $newVendorName) {
                    $db->query(
                        'UPDATE tprm_results SET vendor_name = :new WHERE vendor_name = :old',
                        [':new' => $newVendorName, ':old' => $oldName]
                    );

                    // Update vendor assessments so search and display stay in sync
                    $db->query(
                        'UPDATE vendor_assessments SET vendor_name = :new WHERE vendor_request_id = :vid',
                        [':new' => $newVendorName, ':vid' => $vendorId]
                    );
                }

                $auth->audit($user['id'], 'vendor_name_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['vendor_name' => $oldName],
                    'new' => ['vendor_name' => $newVendorName]
                ]);
                $success = t('vendor-srs-details.success_vendor_name_updated');

                // Refresh vendor data
                $vendor = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorId]
                );
            } catch (Exception $e) {
                error_log('Failed to update vendor name: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_vendor_name_update_failed');
            }
        }
    } elseif (isset($_POST['update_contact_info'])) {
        $newContactName = trim($_POST['new_contact_name'] ?? '');
        $newContactTitle = trim($_POST['new_contact_title'] ?? '');
        $newContactEmail = trim($_POST['new_contact_email'] ?? '');
        $newContactPhone = trim($_POST['new_contact_phone'] ?? '');

        if ($newContactEmail !== '' && !filter_var($newContactEmail, FILTER_VALIDATE_EMAIL)) {
            $error = t('vendor-srs-details.err_invalid_email');
        } else {
            try {
                $db->update('vendor_onboarding_requests', [
                    'primary_contact_details' => $newContactName,
                    'primary_contact_title' => $newContactTitle,
                    'primary_contact_email' => $newContactEmail,
                    'primary_contact_phone' => $newContactPhone,
                ], 'id = :id', [':id' => $vendorId]);

                $auth->audit($user['id'], 'vendor_contact_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => [
                        'primary_contact_details' => $vendor['primary_contact_details'] ?? '',
                        'primary_contact_title' => $vendor['primary_contact_title'] ?? '',
                        'primary_contact_email' => $vendor['primary_contact_email'] ?? '',
                        'primary_contact_phone' => $vendor['primary_contact_phone'] ?? '',
                    ],
                    'new' => [
                        'primary_contact_details' => $newContactName,
                        'primary_contact_title' => $newContactTitle,
                        'primary_contact_email' => $newContactEmail,
                        'primary_contact_phone' => $newContactPhone,
                    ]
                ]);
                $success = t('vendor-srs-details.success_contact_updated');

                $vendor = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorId]
                );
            } catch (Exception $e) {
                error_log('Failed to update contact info: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_contact_update_failed');
            }
        }
    } elseif (isset($_POST['update_vendor_type'])) {
        // Vendor type update -- categorize them (TECHNOLOGY, LEGAL, HR, etc.)
        // so the reports look organized and the execs feel warm and fuzzy
        $newVendorType = trim($_POST['new_vendor_type'] ?? '');

        $validTypes = [
            'GENERAL OPERATIONS',
            'TECHNOLOGY',
            'PROFESSIONAL SERVICES',
            'FINANCIAL SERVICES',
            'MARKETING',
            'HR/BENEFITS',
            'FACILITIES',
            'LEGAL',
            'OTHER'
        ];

        if ($newVendorType !== '' && !in_array($newVendorType, $validTypes)) {
            $error = t('vendor-srs-details.err_invalid_vendor_type');
        } else {
            // Update vendor type
            try {
                $db->update('vendor_onboarding_requests', [
                    'vendor_type' => $newVendorType
                ], 'id = :id', [':id' => $vendorId]);

                $auth->audit($user['id'], 'vendor_type_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['vendor_type' => $vendor['vendor_type'] ?? ''],
                    'new' => ['vendor_type' => $newVendorType]
                ]);
                $success = t('vendor-srs-details.success_vendor_type_updated');

                // Refresh vendor data
                $vendor = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorId]
                );
            } catch (Exception $e) {
                error_log('Failed to update vendor type: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_vendor_type_update_failed');
            }
        }
    } elseif (isset($_POST['update_status'])) {
        // Status update -- move the vendor through the lifecycle: draft -> submitted -> in_review -> approved/rejected
        $newStatus = trim($_POST['new_status'] ?? '');

        $validStatuses = ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive'];

        if (!in_array($newStatus, $validStatuses)) {
            $error = t('vendor-srs-details.err_invalid_status');
        } else {
            // Update vendor status
            try {
                $db->update('vendor_onboarding_requests', [
                    'status' => $newStatus
                ], 'id = :id', [':id' => $vendorId]);

                $auth->audit($user['id'], 'vendor_status_update', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['status' => $vendor['status'] ?? ''],
                    'new' => ['status' => $newStatus]
                ]);
                $success = t('vendor-srs-details.success_status_updated');

                // Refresh vendor data
                $vendor = $db->fetchOne(
                    'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                    [':id' => $vendorId]
                );
            } catch (Exception $e) {
                error_log('Failed to update vendor status: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_status_update_failed');
            }
        }
    } elseif (isset($_POST['update_stakeholder'])) {
        // Stakeholder reassignment -- when someone leaves the team or you realize
        // the wrong person has been getting all the vendor security emails
        $newStakeholderId = intval($_POST['stakeholder_user_id'] ?? 0);

        if ($newStakeholderId <= 0) {
            $error = t('vendor-srs-details.err_select_stakeholder');
        } else {
            // Verify the user exists
            $userExists = $db->fetchOne(
                'SELECT id FROM users WHERE id = :id',
                [':id' => $newStakeholderId]
            );

            if (!$userExists) {
                $error = t('vendor-srs-details.err_user_not_exist');
            } else {
                try {
                    // Remove existing stakeholder assignments (role = 'stakeholder')
                    $db->delete(
                        'vendor_onboarding_stakeholders',
                        "request_id = :rid AND role = 'stakeholder'",
                        [':rid' => $vendorId]
                    );

                    // Add new stakeholder
                    $db->insert('vendor_onboarding_stakeholders', [
                        'request_id' => $vendorId,
                        'user_id' => $newStakeholderId,
                        'role' => 'stakeholder',
                        'assigned_at' => date('Y-m-d H:i:s')
                    ]);

                    $auth->audit($user['id'], 'vendor_stakeholder_update', 'vendor_onboarding_stakeholders', $vendorId, [
                        'new' => ['stakeholder_user_id' => $newStakeholderId]
                    ]);
                    $success = t('vendor-srs-details.success_stakeholder_updated');

                    // Refresh vendor data
                    $vendor = $db->fetchOne(
                        'SELECT * FROM vendor_onboarding_requests WHERE id = :id',
                        [':id' => $vendorId]
                    );
                } catch (Exception $e) {
                    error_log('Failed to update stakeholder: ' . $e->getMessage());
                    $error = t('vendor-srs-details.err_stakeholder_update_failed');
                }
            }
        }
    } elseif (isset($_POST['delete_vendor'])) {
        // Delete vendor and all associated assessments -- the nuclear option.
        // Assessments use ON DELETE SET NULL (not CASCADE), so we must explicitly
        // delete them before removing the vendor record.
        if (!$canDelete) {
            $error = t('vendor-srs-details.err_no_delete_permission');
        } else {
            try {
                $auth->audit($user['id'], 'vendor_delete', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['vendor_name' => $vendor['vendor_name'] ?? '', 'status' => $vendor['status'] ?? '']
                ]);
                // Load the assessment service to properly delete assessments (responses + files)
                require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
                $assessmentService = new VendorAssessmentService();

                // Fetch all assessments linked to this vendor
                $linkedAssessments = $db->fetchAll(
                    'SELECT id FROM vendor_assessments WHERE vendor_request_id = :vid',
                    [':vid' => $vendorId]
                );

                // Delete each assessment (responses, files cascade automatically)
                foreach ($linkedAssessments as $assessment) {
                    $assessmentService->deleteAssessment($assessment['id']);
                }

                // Delete any FAIR analysis reports tied to this vendor (linked by vendor_name)
                if (!empty($vendor['vendor_name'])) {
                    $db->delete('tprm_results', 'vendor_name = :vn', [':vn' => $vendor['vendor_name']]);
                }

                // Delete UpGuard SRS risks and scores
                $srsScoreIds = $db->fetchAll(
                    'SELECT id FROM vendor_srs_scores WHERE vendor_onboarding_id = :vid',
                    [':vid' => $vendorId]
                );
                foreach ($srsScoreIds as $row) {
                    $db->delete('vendor_srs_risks', 'srs_score_id = :sid', [':sid' => $row['id']]);
                }
                $db->delete('vendor_srs_scores', 'vendor_onboarding_id = :vid', [':vid' => $vendorId]);

                // Delete Shodan scores, findings, and waivers
                try {
                    $shodanScoreIds = $db->fetchAll(
                        'SELECT id FROM vendor_shodan_scores WHERE vendor_onboarding_id = :vid',
                        [':vid' => $vendorId]
                    );
                    foreach ($shodanScoreIds as $row) {
                        $db->delete('vendor_shodan_findings', 'shodan_score_id = :sid', [':sid' => $row['id']]);
                    }
                    $db->delete('vendor_shodan_scores', 'vendor_onboarding_id = :vid', [':vid' => $vendorId]);
                    $db->delete('vendor_shodan_waivers', 'vendor_onboarding_id = :vid', [':vid' => $vendorId]);
                } catch (Exception $e) {
                    // Shodan tables may not exist in all environments
                }

                // Now delete the vendor record itself.
                // DB cascades handle: stakeholders, annual reviews, reminders
                $db->delete('vendor_onboarding_requests', 'id = :id', [':id' => $vendorId]);

                redirect('vendor-srs-list.php');
            } catch (Exception $e) {
                error_log('Failed to delete vendor: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_delete_failed');
            }
        }
    } elseif (isset($_POST['clear_score_history'])) {
        if (!$canDelete) {
            $error = t('vendor-srs-details.err_no_clear_permission');
        } else {
            try {
                // Delete UpGuard SRS risks, then scores
                $srsScoreIds = $db->fetchAll(
                    'SELECT id FROM vendor_srs_scores WHERE vendor_onboarding_id = :vid',
                    [':vid' => $vendorId]
                );
                foreach ($srsScoreIds as $row) {
                    $db->delete('vendor_srs_risks', 'srs_score_id = :sid', [':sid' => $row['id']]);
                }
                $db->delete('vendor_srs_scores', 'vendor_onboarding_id = :vid', [':vid' => $vendorId]);

                // Delete Shodan findings, then scores
                try {
                    $shodanScoreIds = $db->fetchAll(
                        'SELECT id FROM vendor_shodan_scores WHERE vendor_onboarding_id = :vid',
                        [':vid' => $vendorId]
                    );
                    foreach ($shodanScoreIds as $row) {
                        $db->delete('vendor_shodan_findings', 'shodan_score_id = :sid', [':sid' => $row['id']]);
                    }
                    $db->delete('vendor_shodan_scores', 'vendor_onboarding_id = :vid', [':vid' => $vendorId]);
                } catch (Exception $e) {
                    // Shodan tables may not exist
                }

                // Clear current score fields
                $db->query(
                    'UPDATE vendor_onboarding_requests SET current_srs_score = NULL, current_shodan_score = NULL, last_srs_score_at = NULL, last_shodan_score_at = NULL WHERE id = :id',
                    [':id' => $vendorId]
                );

                $success = t('vendor-srs-details.success_history_cleared');
                // Reload vendor data to reflect cleared scores
                $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
            } catch (Exception $e) {
                error_log('Failed to clear score history: ' . $e->getMessage());
                $error = t('vendor-srs-details.err_clear_history_failed');
            }
        }
    } elseif (isset($_POST['cancel_rescore'])) {
        // Cancel a pending (not yet processing) background rescore
        $rs = $vendor['rescore_status'] ?? '';
        if ($rs && strpos($rs, 'processing') !== 0) {
            $db->query(
                'UPDATE vendor_onboarding_requests SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = NULL WHERE id = ? AND rescore_status = ?',
                [$vendorId, $rs]
            );
            $success = t('vendor-srs-details.success_scan_cancelled');
            // Refresh vendor data
            $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
        } else {
            $error = t('vendor-srs-details.err_cannot_cancel');
        }
    } elseif (isset($_POST['rescore']) || isset($_POST['rescore_upguard']) || isset($_POST['rescore_shodan'])) {
        if (($vendor['status'] ?? '') === 'inactive') {
            $error = t('vendor-srs-details.err_inactive_vendor');
        } elseif (empty($vendor['vendor_domain'])) {
            $error = t('vendor-srs-details.err_no_domain');
        } elseif (!$srsService->isAvailable() && !$shodanAvailable) {
            $error = t('vendor-srs-details.err_no_integration');
        } else {
            $scoreType = 'all';
            if (isset($_POST['rescore_upguard'])) $scoreType = 'upguard';
            elseif (isset($_POST['rescore_shodan'])) $scoreType = 'shodan';

            // Determine if cron mode applies for the service(s) being scored
            $useCron = false;
            if ($scoreType === 'upguard') $useCron = $upguardUseCron;
            elseif ($scoreType === 'shodan') $useCron = $shodanUseCron;
            else $useCron = ($upguardUseCron || $shodanUseCron); // "all": cron if either service uses it

            if ($useCron) {
                // CRON MODE: Queue the job and redirect immediately.
                // The cron/rescore-queue.php worker picks it up within ~1 minute.
                $statusLabel = $scoreType === 'all' ? 'rescoring' : 'rescoring_' . $scoreType;
                $db->query(
                    'UPDATE vendor_onboarding_requests SET rescore_status = ?, rescore_started_at = NOW(), rescore_result = NULL WHERE id = ?',
                    [$statusLabel, $vendorId]
                );
                header('Location: vendor-srs-details.php?id=' . $vendorId);
                exit;
            }

            // REALTIME MODE: Score synchronously in this request
            $domain = $vendor['vendor_domain'];
            $messages = [];
            $scoreErrors = [];

            $scoreUpguard = ($scoreType === 'all' || $scoreType === 'upguard');
            $scoreShodan  = ($scoreType === 'all' || $scoreType === 'shodan');

            if ($scoreUpguard && $srsService->isAvailable()) {
                try {
                    $result = $srsService->scoreVendor($vendorId, $domain, $vendor['vendor_name']);
                    if ($result) {
                        $messages[] = $upguardName . ": {$result['score']} ({$result['grade']})";
                    } else {
                        $scoreErrors[] = $upguardName . ': ' . ($srsService->getLastError() ?? 'Unknown error');
                    }
                } catch (Exception $e) {
                    $scoreErrors[] = $upguardName . ': ' . $e->getMessage();
                    error_log("Rescore UpGuard error for vendor $vendorId: " . $e->getMessage());
                }
            }

            if ($scoreShodan && $shodanAvailable) {
                try {
                    $shodanResult = $shodanService->scoreVendor($vendorId, $domain);
                    if ($shodanResult) {
                        $messages[] = $shodanName . ": {$shodanResult['score']} ({$shodanResult['grade']})";
                    } else {
                        $scoreErrors[] = $shodanName . ': ' . ($shodanService->getLastError() ?? 'Unknown error');
                    }
                } catch (Exception $e) {
                    $scoreErrors[] = $shodanName . ': ' . $e->getMessage();
                    error_log("Rescore Shodan error for vendor $vendorId: " . $e->getMessage());
                }
            }

            // Refresh favicon
            try {
                require_once __DIR__ . '/includes/classes/FaviconService.php';
                $faviconService = new FaviconService();
                $favicon = $faviconService->fetchFavicon($domain);
                if ($favicon) {
                    $db->query(
                        'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                        [$favicon['data'], $favicon['mime'], $vendorId]
                    );
                }
            } catch (Exception $e) {
                // Non-critical
            }

            // Build result
            if (!empty($messages)) {
                $success = implode(' | ', $messages);
            }
            if (!empty($scoreErrors)) {
                $error = 'ERRORS: ' . implode(' | ', $scoreErrors);
            }

            // Audit log
            if (!empty($messages)) {
                try {
                    $auth->audit($user['id'], 'vendor_rescore', 'vendor_onboarding_requests', $vendorId, [
                        'new' => ['results' => implode(' | ', $messages)]
                    ]);
                } catch (Exception $e) { /* non-critical */ }
            }

            // Re-fetch vendor data after scoring
            $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
        }
    } elseif (isset($_POST['waive_risk'])) {
        $signalName = trim($_POST['waive_signal'] ?? '');
        $category = trim($_POST['waive_category'] ?? '');
        $subdomain = trim($_POST['waive_subdomain'] ?? '');
        $label = trim($_POST['waive_label'] ?? '');
        $reason = trim($_POST['waive_reason'] ?? '');
        $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown';

        if (empty($signalName) || empty($category) || empty($subdomain)) {
            $error = t('vendor-srs-details.err_missing_waiver_info');
        } elseif (empty($reason)) {
            $error = t('vendor-srs-details.err_waiver_reason_required');
        } else {
            $result = $shodanService->addWaiver($vendorId, $signalName, $category, $subdomain, $label, $reason, $userName);
            if ($result !== false) {
                $auth->audit($user['id'], 'vendor_waive_risk', 'vendor_shodan_waivers', $vendorId, [
                    'new' => ['signal' => $signalName, 'category' => $category, 'subdomain' => $subdomain, 'reason' => $reason]
                ]);
                $shodanService->recalculateScoreFromFindings($vendorId);
                $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
                $success = t('vendor-srs-details.success_risk_waived');
            } else {
                $error = t('vendor-srs-details.err_waive_failed');
            }
        }
    } elseif (isset($_POST['unwaive_risk'])) {
        $waiverId = intval($_POST['waiver_id'] ?? 0);
        // BOLA fix: the waiver must belong to THIS vendor, else one vendor's page
        // could delete another vendor's waiver (cross-object) by tampering waiver_id.
        $ownWaiver = $waiverId > 0 ? $db->fetchOne(
            'SELECT id FROM vendor_shodan_waivers WHERE id = :id AND vendor_onboarding_id = :vid',
            [':id' => $waiverId, ':vid' => $vendorId]) : null;
        if ($waiverId > 0 && !empty($ownWaiver)) {
            if ($shodanService->removeWaiver($waiverId)) {
                $auth->audit($user['id'], 'vendor_unwaive_risk', 'vendor_shodan_waivers', $waiverId);
                $shodanService->recalculateScoreFromFindings($vendorId);
                $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
                $success = t('vendor-srs-details.success_waiver_removed');
            } else {
                $error = t('vendor-srs-details.err_remove_waiver_failed');
            }
        } elseif ($waiverId > 0) {
            $error = 'Failed to remove waiver.';
        }
    } elseif (isset($_POST['rescan_ip'])) {
        $rescanIp = trim($_POST['rescan_ip'] ?? '');
        if (!empty($rescanIp) && filter_var($rescanIp, FILTER_VALIDATE_IP)) {
            $cooldownRow = $db->fetchOne(
                'SELECT config_value FROM app_config WHERE config_key = ?',
                ['shodan_rescan_cooldown_hours']
            );
            $cooldownHours = max(0, intval($cooldownRow['config_value'] ?? 24));
            $recent = null;
            if ($cooldownHours > 0) {
                $recent = $db->fetchOne(
                    'SELECT requested_at FROM shodan_rescan_log WHERE ip_address = ? AND requested_at > NOW() - INTERVAL ? HOUR ORDER BY requested_at DESC LIMIT 1',
                    [$rescanIp, $cooldownHours]
                );
            }
            if ($recent) {
                $error = "Rescan skipped: {$rescanIp} was already rescanned at {$recent['requested_at']} (within the {$cooldownHours}-hour cooldown).";
            } else {
                $rescanResult = $shodanService->requestRescan($rescanIp);
                if ($rescanResult['success']) {
                    $db->insert('shodan_rescan_log', [
                        'ip_address' => $rescanIp,
                        'scan_id' => $rescanResult['scan_id'] ?? null,
                        'requested_by' => $user['username'] ?? null,
                    ]);
                    $success = $rescanResult['message'];
                } else {
                    $error = 'Rescan failed: ' . $rescanResult['message'];
                }
            }
        } else {
            $error = t('vendor-srs-details.err_invalid_ip');
        }
    } elseif (isset($_POST['rescan_all_ips'])) {
        // Request Shodan to rescan all IPs in a single batch API call,
        // skipping any IP rescanned within the configured cooldown window.
        $latestScore = $shodanService->getLatestScore($vendorId);
        $allIps = [];
        if ($latestScore && !empty($latestScore['ip_addresses'])) {
            $allIps = json_decode($latestScore['ip_addresses'], true) ?: [];
        }
        if (empty($allIps)) {
            $error = t('vendor-srs-details.err_no_ips');
        } else {
            $validIps = array_values(array_filter($allIps, function ($ip) {
                return filter_var($ip, FILTER_VALIDATE_IP);
            }));
            if (empty($validIps)) {
                $error = t('vendor-srs-details.err_no_valid_ips');
            } else {
                $cooldownRow = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = ?',
                    ['shodan_rescan_cooldown_hours']
                );
                $cooldownHours = max(0, intval($cooldownRow['config_value'] ?? 24));

                $toScan = $validIps;
                $skipped = [];
                if ($cooldownHours > 0) {
                    $placeholders = implode(',', array_fill(0, count($validIps), '?'));
                    $params = $validIps;
                    $params[] = $cooldownHours;
                    $recentRows = $db->fetchAll(
                        "SELECT DISTINCT ip_address FROM shodan_rescan_log WHERE ip_address IN ($placeholders) AND requested_at > NOW() - INTERVAL ? HOUR",
                        $params
                    );
                    $recentIps = array_column($recentRows, 'ip_address');
                    $skipped = array_values(array_intersect($validIps, $recentIps));
                    $toScan = array_values(array_diff($validIps, $recentIps));
                }

                if (empty($toScan)) {
                    $skipCount = count($skipped);
                    $error = "No rescan requested: all {$skipCount} IP" . ($skipCount > 1 ? 's were' : ' was') . " rescanned within the last {$cooldownHours} hour" . ($cooldownHours !== 1 ? 's' : '') . ".";
                } else {
                    $result = $shodanService->requestRescanBatch($toScan);
                    if ($result['success']) {
                        $scanId = $result['scan_id'] ?? null;
                        $requestedBy = $user['username'] ?? null;
                        foreach ($toScan as $ip) {
                            $db->insert('shodan_rescan_log', [
                                'ip_address' => $ip,
                                'scan_id' => $scanId,
                                'requested_by' => $requestedBy,
                            ]);
                        }
                        $scanCount = count($toScan);
                        $skipCount = count($skipped);
                        $msg = "Rescan requested for {$scanCount} IP" . ($scanCount > 1 ? 's' : '') . ".";
                        if ($skipCount > 0) {
                            $msg .= " {$skipCount} IP" . ($skipCount > 1 ? 's' : '') . " skipped (rescanned within last {$cooldownHours} hour" . ($cooldownHours !== 1 ? 's' : '') . ").";
                        }
                        $msg .= " {$result['message']}";
                        $success = $msg;
                    } else {
                        $error = "Rescan request failed. {$result['message']}";
                    }
                }
            }
        }
    } elseif (isset($_POST['exclude_subdomain'])) {
        $excludeDomain = strtolower(trim($_POST['exclude_subdomain'] ?? ''));
        if (empty($excludeDomain)) {
            $error = t('vendor-srs-details.err_no_subdomain');
        } else {
            // Fetch current excluded domains list
            $currentExcluded = $db->fetchOne(
                'SELECT config_value FROM app_config WHERE config_key = ?',
                ['shodan_excluded_domains']
            );
            $existingList = trim($currentExcluded['config_value'] ?? '');
            $existingDomains = array_filter(array_map(
                fn($d) => strtolower(trim($d)),
                preg_split('/[\r\n,]+/', $existingList)
            ));

            if (in_array($excludeDomain, $existingDomains, true)) {
                $success = "'{$excludeDomain}' is already in the excluded domains list.";
            } else {
                $existingDomains[] = $excludeDomain;
                $newValue = implode("\n", $existingDomains);
                $db->query(
                    'UPDATE app_config SET config_value = ? WHERE config_key = ?',
                    [$newValue, 'shodan_excluded_domains']
                );
                $auth->audit($user['id'], 'vendor_exclude_subdomain', 'app_config', $vendorId, [
                    'new' => ['excluded_domain' => $excludeDomain]
                ]);
                $success = "'{$excludeDomain}' has been added to the excluded domains list. It will be skipped on the next rescore.";
            }
        }
    } elseif (isset($_POST['update_custom_score'])) {
        $customScoreInput = trim($_POST['custom_score'] ?? '');
        if ($customScoreInput === '' || $customScoreInput === 'null') {
            // Un-score: set to NULL
            $db->query(
                'UPDATE vendor_onboarding_requests SET custom_score = NULL WHERE id = ?',
                [$vendorId]
            );
            $auth->audit($user['id'], 'vendor_custom_score_cleared', 'vendor_onboarding_requests', $vendorId, [
                'old' => ['custom_score' => $vendor['custom_score'] ?? null]
            ]);
            $success = 'Custom score has been removed.';
        } else {
            $customScoreVal = (int)$customScoreInput;
            if ($customScoreVal < 1 || $customScoreVal > 100) {
                $error = 'Custom score must be between 1 and 100.';
            } else {
                $db->query(
                    'UPDATE vendor_onboarding_requests SET custom_score = ? WHERE id = ?',
                    [$customScoreVal, $vendorId]
                );
                $auth->audit($user['id'], 'vendor_custom_score_updated', 'vendor_onboarding_requests', $vendorId, [
                    'old' => ['custom_score' => $vendor['custom_score'] ?? null],
                    'new' => ['custom_score' => $customScoreVal]
                ]);
                $success = 'Custom score updated to ' . $customScoreVal . '.';
            }
        }
        // Re-fetch vendor data after custom score change
        $vendor = $db->fetchOne('SELECT * FROM vendor_onboarding_requests WHERE id = :id', [':id' => $vendorId]);
    }
}

// Fetch the currently assigned stakeholder for this vendor.
// Falls back to the vendor creator if nobody's been explicitly assigned.
// Someone's gotta be responsible, even if they don't want to be.
$assignedStakeholder = $db->fetchOne(
    "SELECT u.id, u.full_name, u.username, u.email
     FROM vendor_onboarding_stakeholders vs
     JOIN users u ON vs.user_id = u.id
     WHERE vs.request_id = :request_id AND vs.role = 'stakeholder'
     ORDER BY vs.assigned_at DESC
     LIMIT 1",
    [':request_id' => $vendorId]
);

// If no assigned stakeholder, show creator as fallback
if (!$assignedStakeholder && !empty($vendor['created_by'])) {
    $assignedStakeholder = $db->fetchOne(
        "SELECT id, full_name, username, email FROM users WHERE id = :id",
        [':id' => $vendor['created_by']]
    );
}

// Pull the last 30 score snapshots for the history table
$scoreHistory = $srsService->getScoreHistory($vendorId, 30);

// Get the most recent score with all the juicy details (risk counts, categories, etc.)
$latestScore = $srsService->getLatestScore($vendorId);

// Grab the individual risk items (vulnerabilities) tied to the latest score.
// These are the specific things UpGuard found wrong -- think of them as the vendor's rap sheet.
$risks = [];
if ($latestScore) {
    $risks = $srsService->getRisksForScore($latestScore['id']);
}

// Fetch vendor subdomain scores if the feature is enabled
$vendorSubdomains = [];
$vendorDomainsEnabled = $srsService->isVendorDomainsEnabled();
if ($vendorDomainsEnabled) {
    $vendorSubdomains = $srsService->getVendorSubdomains($vendorId);
}

// Load Shodan score data if available (wrapped in try/catch in case
// migration hasn't been applied yet and tables don't exist)
$shodanLatestScore = null;
$shodanFindings = [];
$shodanScoreHistory = [];
$shodanTrendData = [];
if ($shodanAvailable || !empty($vendor['current_shodan_score'])) {
    try {
        $shodanLatestScore = $shodanService->getLatestScore($vendorId);
        if ($shodanLatestScore) {
            $shodanFindings = $shodanService->getFindingsForScore($shodanLatestScore['id']);
        }
        $shodanScoreHistory = $shodanService->getScoreHistory($vendorId, 10);
        $shodanTrendData = $shodanService->getScoreTrend($vendorId, $trendingDays ?? 90);
        if (empty($shodanTrendData) && !empty($shodanScoreHistory)) {
            $shodanTrendData = array_reverse(array_map(function($h) {
                return [
                    'date' => date('Y-m-d', strtotime($h['scored_at'])),
                    'score' => (int)$h['score'],
                    'grade' => $h['score_grade']
                ];
            }, $shodanScoreHistory));
        }
    } catch (Exception $e) {
        error_log('Shodan data loading failed (migration may not be applied): ' . $e->getMessage());
    }
}

// Load active waivers for this vendor (for showing "Waived" badges on findings)
$shodanWaivers = [];
$shodanWaiverLookup = []; // signal_name:subdomain => waiver row
$shodanWaivedSubdomains = []; // set of subdomains that have any active waiver
try {
    $shodanWaivers = $shodanService->getWaiversForVendor($vendorId);
    foreach ($shodanWaivers as $w) {
        $shodanWaiverLookup[$w['signal_name'] . ':' . $w['subdomain']] = $w;
        $shodanWaivedSubdomains[$w['subdomain']] = true;
    }
} catch (Exception $e) {
    // Table may not exist yet
}

// Load 4th party technology inventory for this vendor
$vendorTechnologies = [];
try {
    if ($shodanService->hasTechnologiesTable()) {
        $vendorTechnologies = $shodanService->getTechnologiesForVendor($vendorId);
    }
} catch (Exception $e) {
    // Table may not exist yet
}

// Check if AI platform is enabled for executive summary generation (OpenWebUI or LibreChat)
$aiEnabled = false;
try {
    $aiEnabled = AIPlatformService::getInstance()->isEnabled();
} catch (Exception $e) {}

// Grab the 5 most recent vendor assessments (questionnaires, reviews, etc.)
// to show alongside the security score for a complete picture
$vendorAssessments = $db->fetchAll(
    "SELECT va.id, va.uuid, va.status, va.completed_at, va.created_at, t.name as template_name
     FROM vendor_assessments va
     JOIN assessment_templates t ON va.template_id = t.id
     WHERE va.vendor_request_id = :vendor_id
     ORDER BY va.created_at DESC
     LIMIT 5",
    [':vendor_id' => $vendorId]
);

// Check if there's a FAIR (Factor Analysis of Information Risk) assessment for this vendor.
// FAIR gives us actual dollar amounts for potential losses, which is way more
// convincing in board meetings than just saying "their security is bad."
$fairAnalysis = null;
$encryption = new Encryption();
if (!empty($vendor['vendor_name'])) {
    $fairAnalysis = $db->fetchOne(
        "SELECT id, vendor_name, status, risk_output, loss_event_frequency, ale,
                primary_loss_magnitude, secondary_loss_magnitude, recommended_liability,
                vendor_cyber_insurance_coverage, scope_of_work, iso_27001_certified, created_at
         FROM tprm_results
         WHERE vendor_name = :vendor_name
         ORDER BY created_at DESC
         LIMIT 1",
        [':vendor_name' => $vendor['vendor_name']]
    );

    // Decrypt encrypted fields
    if ($fairAnalysis) {
        $encryptedFairFields = ['loss_event_frequency', 'ale', 'primary_loss_magnitude',
            'secondary_loss_magnitude', 'recommended_liability', 'vendor_cyber_insurance_coverage', 'scope_of_work'];
        foreach ($encryptedFairFields as $field) {
            if (!empty($fairAnalysis[$field])) {
                $fairAnalysis[$field] = $encryption->decrypt($fairAnalysis[$field]);
            }
        }
    }
}

// Build the trend data for the Chart.js line graph. We use a configurable
// trending period (default 90 days) but fall back to all available history
// if there's no data in the recent window. Nobody likes an empty chart.
$scoringConfig = $srsService->getScoringConfig();
$trendingDays = $scoringConfig['trending_days'] ?? 90;
$upguardDisplayMode = $scoringConfig['display_mode'] ?? 'raw';
$upguardMaxScore = (int)($scoringConfig['max_score'] ?? 950);
$upguardName = $scoringConfig['display_name'] ?? 'UpGuard';

/**
 * Converts a raw UpGuard score for display based on the configured display mode.
 * Returns the score as-is in 'raw' mode, or as a percentage (0-100) in 'percentage' mode.
 */
function displayUpguardScore(int $rawScore, string $mode, int $maxScore): string
{
    if ($mode === 'percentage') {
        $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
        return $pct . '%';
    }
    return (string)$rawScore;
}

/**
 * Convert CVE IDs in text to clickable NVD links (opens in new tab).
 * Input text is already escaped or will be escaped here.
 */
function linkifyCves(string $text, bool $alreadyEscaped = false): string
{
    $safe = $alreadyEscaped ? $text : e($text);
    return preg_replace(
        '/\b(CVE-\d{4}-\d{4,})\b/',
        '<a href="https://nvd.nist.gov/vuln/detail/$1" target="_blank" rel="noopener" style="color:#991b1b;font-weight:600;text-decoration:none;" title="View $1 on NVD">$1</a>',
        $safe
    );
}
$trendData = $srsService->getScoreTrend($vendorId, $trendingDays);
// If no data in 90 days but we have score history, use all history for trend
if (empty($trendData) && !empty($scoreHistory)) {
    $trendData = array_reverse(array_map(function($h) {
        return [
            'date' => date('Y-m-d', strtotime($h['scored_at'])),
            'score' => (int)$h['score'],
            'grade' => $h['score_grade']
        ];
    }, $scoreHistory));
}

$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SRS Details - <?php echo e($vendor['vendor_name'] ?? 'Vendor'); ?> - TPRM</title>
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
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px;
            border-radius: 4px; background: rgba(255,101,67,0.1);
            transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .user-menu .btn-configure {
            background: var(--theme-button-color);
            color: white !important;
        }
        .user-menu .btn-configure:hover {
            filter: brightness(1.1);
        }

        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            max-width: var(--sidebar-width);
            background: var(--nav-fill-color);
            padding: 0;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand a { display: block; }
        .sidebar-brand img { max-width: 100%; height: auto; }
        .sidebar-brand .brand-title {
            color: var(--nav-font-color);
            font-size: 13px;
            font-weight: 500;
            margin-top: 8px;
            opacity: 0.9;
        }
        .sidebar-content {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
        }
        .sidebar-section { margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--nav-font-color);
            opacity: 0.5;
            padding: 0 20px;
            margin-bottom: 10px;
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '\25BC';
            font-size: 8px;
            opacity: 0.5;
            transition: transform 0.2s ease;
            margin-right: 2px;
        }
        .sidebar-section:not([open]) .sidebar-section-title::after {
            transform: rotate(-90deg);
        }
        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 20px;
            color: var(--nav-font-color);
            opacity: 0.85;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .sidebar-nav li a:hover {
            background: rgba(255,255,255,0.1);
            opacity: 1;
            border-left-color: var(--nav-font-color);
        }
        .sidebar-nav li a.active {
            background: rgba(255,255,255,0.15);
            opacity: 1;
            border-left-color: var(--nav-font-color);
            font-weight: 500;
        }
        .sidebar-nav li a .icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
            opacity: 0.9;
        }

        .main-content { flex: 1; padding: 25px; overflow-y: auto; }

        .back-link { display: inline-flex; align-items: center; gap: 5px; color: #666; text-decoration: none; margin-bottom: 20px; }
        .back-link:hover { color: var(--theme-header-color); }

        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0 0 10px 0; color: #333; font-size: 28px; }
        .page-header .subtitle { color: #666; font-size: 14px; }

        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }

        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card h3 { margin: 0 0 20px 0; color: var(--theme-header-color); font-size: 16px; font-weight: 600; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }

        .score-display { text-align: center; padding: 30px 20px; }
        .score-value { font-size: 72px; font-weight: 700; line-height: 1; }
        .score-grade { font-size: 48px; font-weight: 600; margin-left: 10px; }
        .score-grade.grade-a { color: #166534; }
        .score-grade.grade-b { color: #15803d; }
        .score-grade.grade-c { color: #ca8a04; }
        .score-grade.grade-d { color: #ea580c; }
        .score-grade.grade-f { color: #dc2626; }
        .score-label { color: #666; font-size: 14px; margin-top: 10px; }
        .score-date { color: #999; font-size: 12px; margin-top: 5px; }

        .risk-counts { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-top: 20px; }
        .risk-count { text-align: center; padding: 15px; border-radius: 8px; }
        .risk-count.critical { background: #450a0a; border: 1px solid #7f1d1d; }
        .risk-count.high { background: #fef2f2; border: 1px solid #fecaca; }
        .risk-count.medium { background: #fff7ed; border: 1px solid #fed7aa; }
        .risk-count.low { background: #fefce8; border: 1px solid #fef08a; }
        .risk-count.info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .risk-count .count { font-size: 28px; font-weight: 700; }
        .risk-count.critical .count { color: #fef2f2; }
        .risk-count.critical .label { color: #fef2f2; }
        .risk-count.high .count { color: #dc2626; }
        .risk-count.medium .count { color: #ea580c; }
        .risk-count.low .count { color: #ca8a04; }
        .risk-count.info .count { color: #0284c7; }
        .risk-count .label { font-size: 12px; color: #666; margin-top: 5px; }

        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .info-item label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; }
        .info-item .value { font-size: 14px; color: #333; font-weight: 500; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--theme-header-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; padding: 10px; text-align: left; font-weight: 500; border-bottom: 2px solid #dee2e6; }
        td { padding: 10px; border-bottom: 1px solid #dee2e6; }
        tr:hover { background: #f9fafb; }

        .badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; }
        .badge-critical { background: #450a0a; color: #fef2f2; }
        .badge-high { background: #fef2f2; color: #991b1b; }
        .badge-medium { background: #fff7ed; color: #9a3412; }
        .badge-low { background: #fefce8; color: #854d0e; }
        .badge-info { background: #f0f9ff; color: #075985; }

        .tier-badge { padding: 4px 10px; border-radius: 15px; font-size: 12px; font-weight: 500; }
        .tier-1 { background: #fef2f2; color: #991b1b; }
        .tier-2 { background: #fef3c7; color: #92400e; }
        .tier-3 { background: #f0fdf4; color: #166534; }

        .chart-container { height: 300px; }

        .no-data { text-align: center; padding: 40px 20px; color: #666; }
        .no-data .icon { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

        .action-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }

        /* Footer styling */
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
            overflow: visible;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        .footer-modern .brand img { max-height: 45px; }

        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
            .grid { grid-template-columns: 1fr; }
            .vendor-search-container { width: 100% !important; min-width: 0 !important; }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>

        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($acl->hasGroup('administrator')): ?>
                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                    <a href="admin.php?section=srs" class="btn-configure"><?php echo e(t('vendor-srs-details.configure_srs')); ?></a>
                <?php endif; ?>
                <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
            </div>
        </div>

        <div class="main-layout">
            <?php $currentPage = 'srs'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
        <a href="<?php echo e($backUrl); ?>" class="back-link">&larr; <?php echo e(t('vendor-srs-details.back_to', $backTitle)); ?></a>

        <!-- Page Header Row -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 class="page-title" style="margin: 0 0 10px 0;"><?php echo e(t('vendor-srs-details.heading')); ?></h1>
                <div class="subtitle">
                    <?php echo e($vendor['vendor_name'] ?? 'Unknown Vendor'); ?>
                    <?php if (!empty($vendor['vendor_domain'])): ?>
                        &middot; <?php echo e($vendor['vendor_domain']); ?>
                    <?php endif; ?>
                    <?php if (!empty($vendor['vendor_tier'])): ?>
                        &middot;
                        <span class="tier-badge tier-<?php echo e($vendor['vendor_tier']); ?>">
                            Tier <?php echo e($vendor['vendor_tier']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($vendor['last_srs_score_at'])): ?>
                <div style="color: #666; font-size: 13px; margin-top: 4px;">
                    <?php echo e(t('vendor-srs-details.last_scored_prefix')); ?> <?php echo date('M j, Y g:i A', strtotime($vendor['last_srs_score_at'])); ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="vendor-search-container" style="position: relative; min-width: 300px;">
                <input
                    type="text"
                    id="vendorSearchInput"
                    placeholder="<?php echo e(t('vendor-srs-details.search_placeholder')); ?>"
                    style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                    class="focus-ring"
                >
                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128269;</span>
                <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php
        $isRescoring = !empty($vendor['rescore_status']);
        // Safety: if rescore_started_at is older than 10 minutes, assume it failed/hung
        if ($isRescoring && !empty($vendor['rescore_started_at'])) {
            $startedAt = strtotime($vendor['rescore_started_at']);
            if ($startedAt && (time() - $startedAt) > 600) {
                $isRescoring = false;
                $db->query('UPDATE vendor_onboarding_requests SET rescore_status = NULL, rescore_started_at = NULL, rescore_result = ? WHERE id = ?',
                    ['Timed out after 10 minutes', $vendorId]);
            }
        }
        // Show result from last background rescore (one-time flash)
        $rescoreResult = $vendor['rescore_result'] ?? '';
        if (!empty($rescoreResult) && !$isRescoring && empty($success)) {
            $isError = stripos($rescoreResult, 'ERROR') !== false;
            echo '<div class="alert ' . ($isError ? 'alert-danger' : 'alert-success') . '">' . e($rescoreResult) . '</div>';
            $db->query('UPDATE vendor_onboarding_requests SET rescore_result = NULL WHERE id = ?', [$vendorId]);
        }
        ?>
        <?php if ($isRescoring): ?>
            <div class="alert" id="rescoreStatusBanner" style="background: #eff6ff; color: #1e40af; border: 1px solid #93c5fd; display: flex; align-items: center; gap: 10px;">
                <span class="rescore-spinner"></span>
                <span>Scoring queued<?php
                    $rs = $vendor['rescore_status'];
                    if ($rs === 'rescoring_shodan') echo ' (' . e($shodanName) . ')';
                    elseif ($rs === 'rescoring_upguard') echo ' (' . e($upguardName) . ')';
                    $isProcessing = (strpos($rs, 'processing') === 0);
                    if ($isProcessing) {
                        echo '... Scoring in progress';
                    } else {
                        echo '... Waiting for background processor';
                    }
                ?>. This page will refresh automatically when complete.</span>
                <?php if (!$isProcessing && !$isStakeholderView): ?>
                <form method="POST" style="margin-left: auto;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <button type="submit" name="cancel_rescore" id="cancelRescoreBtn" class="btn" style="padding: 4px 12px; font-size: 12px; background: #fff; color: #dc2626; border: 1px solid #fca5a5; border-radius: 4px; cursor: pointer;"><?php echo e(t('vendor-srs-details.cancel')); ?></button>
                </form>
                <?php endif; ?>
            </div>
            <style nonce="<?php echo cspNonce(); ?>">
                @keyframes rescore-spin { to { transform: rotate(360deg); } }
                .rescore-spinner {
                    display: inline-block; width: 16px; height: 16px;
                    border: 2px solid #93c5fd; border-top-color: #1e40af;
                    border-radius: 50%; animation: rescore-spin 0.8s linear infinite;
                }
            </style>
        <?php endif; ?>

        <?php if (!$srsService->isAvailable() && !$shodanAvailable): ?>
            <div class="alert alert-warning">
                No SRS integration is configured. Please configure <a href="admin.php?section=srs">UpGuard</a> or <a href="admin.php?section=shodan">Shodan</a> in Administration > Integrations.
            </div>
        <?php endif; ?>

        <div class="action-bar">
            <?php
            $hasDomain = !empty($vendor['vendor_domain']);
            $ugAvail = $srsService->isAvailable();
            $shAvail = $shodanAvailable;
            $bothAvail = $ugAvail && $shAvail;
            ?>
            <?php if (!$isStakeholderView && !$isAuditor): ?>
            <?php if ($bothAvail): ?>
            <div style="display: inline-block; position: relative;" id="scoreDropdown">
                <button type="button" class="btn btn-primary" data-toggle="scoreMenu" <?php echo (!$hasDomain || $isRescoring) ? 'disabled' : ''; ?>>
                    <?php echo $isRescoring ? '<img src="app/icons/hourglass-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> Scoring...' : '<img src="app/icons/graduation-hat-02.svg" alt="" width="16" height="16" style="vertical-align: middle;"> Score &#9662;'; ?>
                </button>
                <div id="scoreMenu" style="display: none; position: absolute; top: 100%; left: 0; margin-top: 4px; background: white; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; min-width: 180px; overflow: hidden;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <button type="submit" name="rescore" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray">
                            <?php echo e(t('vendor-srs-details.score_all')); ?>
                        </button>
                        <button type="submit" name="rescore_upguard" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray">
                            <?php echo e($upguardName); ?>
                        </button>
                        <button type="submit" name="rescore_shodan" style="display: block; width: 100%; padding: 8px 14px; border: none; background: none; text-align: left; cursor: pointer; font-size: 13px; color: #374151;" class="hover-bg-gray">
                            <?php echo e($shodanName); ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <button type="submit" name="rescore" class="btn btn-primary" <?php echo !$hasDomain || (!$ugAvail && !$shAvail) || $isRescoring ? 'disabled' : ''; ?>>
                    <?php echo $isRescoring ? '<img src="app/icons/hourglass-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> Scoring...' : '<img src="app/icons/graduation-hat-02.svg" alt="" width="16" height="16" style="vertical-align: middle;"> Score Now'; ?>
                </button>
            </form>
            <?php endif; ?>
            <?php if ($fairAnalysis): ?>
                <a href="download-pdf.php?id=<?php echo intval($fairAnalysis['id']); ?>" class="btn btn-secondary" target="_blank">
                    <img src="app/icons/bar-chart-square-02.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.review_fair')); ?>
                </a>
            <?php elseif (!$isAuditor): ?>
                <a href="fair-analysis.php?from_onboarding=<?php echo $vendorId; ?>" class="btn btn-secondary">
                    <img src="app/icons/plus-square.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.create_fair')); ?>
                </a>
            <?php endif; ?>
            <?php if ($aiEnabled && ($latestScore || $shodanLatestScore)): ?>
                <button type="button" class="btn btn-secondary" data-action="generateExecSummary" id="execSummaryBtn">
                    <img src="app/icons/file-check-02.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.executive_summary')); ?>
                </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($latestScore || $shodanLatestScore): ?>
                <a href="vendor-detailed-summary.php?id=<?php echo $vendorId; ?>" class="btn btn-secondary" target="_blank">
                    <img src="app/icons/file-06.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.detailed_summary')); ?>
                </a>
                <button type="button" data-action="exportRawData" class="btn btn-secondary" style="margin-left: 4px;">
                    <img src="app/icons/dataflow-02.svg" alt="" width="14" height="14" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.export_raw_data')); ?>
                </button>
            <?php endif; ?>
            <?php if (!$isStakeholderView && !$isAuditor): ?>
                <a href="vendor-assessments.php?link_vendor=<?php echo (int)$vendorId; ?>" class="btn btn-secondary">
                    <img src="app/icons/mail-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.send_assessment')); ?>
                </a>
            <?php if ($canDelete && ($latestScore || $shodanLatestScore)): ?>
                <form id="clearScoreHistoryForm" method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="clear_score_history" value="1">
                    <button type="button" class="btn btn-secondary" data-action="clearScoreHistory" style="margin-left: 4px;">
                        <img src="app/icons/trash-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.clear_score_history')); ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form id="deleteVendorForm" method="POST" style="display: inline; margin-left: auto;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="delete_vendor" value="1">
                    <button type="button" class="btn btn-danger" data-action="deleteVendor">
                        <img src="app/icons/trash-01.svg" alt="" width="16" height="16" style="vertical-align: middle;"> <?php echo e(t('vendor-srs-details.delete_vendor')); ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($latestScore || $shodanLatestScore): ?>
            <div class="grid">
                <?php if ($latestScore): ?>
                <div class="card">
                    <h3><?php echo e($upguardName); ?> Score</h3>
                    <div class="score-display">
                        <?php
                        $score = intval($latestScore['score']);
                        // Recalculate grade using current configurable thresholds
                        $grade = $srsService->calculateGrade($score);
                        $gradeClass = 'grade-' . strtolower($grade);
                        ?>
                        <span class="score-value"><?php echo displayUpguardScore($score, $upguardDisplayMode, $upguardMaxScore); ?></span>
                        <span class="score-grade <?php echo $gradeClass; ?>"><?php echo $grade; ?></span>
                        <div class="score-label"><?php echo $upguardDisplayMode === 'percentage' ? 'percentage score' : 'out of ' . e($upguardMaxScore); ?></div>
                        <div class="score-date"><?php echo e(t('vendor-srs-details.scored_on_prefix')); ?> <?php echo date('M j, Y g:i A', strtotime($latestScore['scored_at'])); ?></div>
                    </div>

                    <div class="risk-counts">
                        <div class="risk-count critical" style="cursor: pointer;" data-action="scrollToSection" data-arg="upguard-risks" title="Jump to Identified Risks">
                            <div class="count"><?php echo intval($latestScore['critical_risks'] ?? 0); ?></div>
                            <div class="label"><?php echo e(t('vendor-srs-details.col_critical')); ?></div>
                        </div>
                        <div class="risk-count high" style="cursor: pointer;" data-action="scrollToSection" data-arg="upguard-risks" title="Jump to Identified Risks">
                            <div class="count"><?php echo intval($latestScore['high_risks']); ?></div>
                            <div class="label"><?php echo e(t('vendor-srs-details.col_high')); ?></div>
                        </div>
                        <div class="risk-count medium" style="cursor: pointer;" data-action="scrollToSection" data-arg="upguard-risks" title="Jump to Identified Risks">
                            <div class="count"><?php echo intval($latestScore['medium_risks']); ?></div>
                            <div class="label"><?php echo e(t('vendor-srs-details.col_medium')); ?></div>
                        </div>
                        <div class="risk-count low" style="cursor: pointer;" data-action="scrollToSection" data-arg="upguard-risks" title="Jump to Identified Risks">
                            <div class="count"><?php echo intval($latestScore['low_risks']); ?></div>
                            <div class="label"><?php echo e(t('vendor-srs-details.col_low')); ?></div>
                        </div>
                        <div class="risk-count info" style="cursor: pointer;" data-action="scrollToSection" data-arg="upguard-risks" title="Jump to Identified Risks">
                            <div class="count"><?php echo intval($latestScore['info_risks']); ?></div>
                            <div class="label"><?php echo e(t('vendor-srs-details.col_info')); ?></div>
                        </div>
                    </div>

                    <?php
                    // Display category scores if available
                    $categoryScores = [];
                    if (!empty($latestScore['category_scores'])) {
                        $categoryScores = is_string($latestScore['category_scores'])
                            ? json_decode($latestScore['category_scores'], true)
                            : $latestScore['category_scores'];
                    }
                    if (!empty($categoryScores)):
                    ?>
                    <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                        <h4 style="font-size: 11px; color: #666; margin: 0 0 8px 0; font-weight: 600;"><?php echo e(t('vendor-srs-details.category_scores')); ?></h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 6px;">
                            <?php foreach ($categoryScores as $category => $catScore): ?>
                                <?php
                                // Convert camelCase to Title Case
                                $categoryName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $category);
                                $categoryName = ucwords($categoryName);

                                $catScoreInt = is_numeric($catScore) ? intval($catScore) : 0;
                                // Convert category score for display
                                $catDisplayVal = ($upguardDisplayMode === 'percentage' && $upguardMaxScore > 0)
                                    ? (int)floor(($catScoreInt / $upguardMaxScore) * 100)
                                    : $catScoreInt;

                                // Use percentage for coloring (normalize to 0-100 for consistent color mapping)
                                $catPct = $upguardMaxScore > 0 ? ($catScoreInt / $upguardMaxScore) * 100 : 0;
                                $scoreColor = '#6b7280';
                                if ($catPct >= 85) {
                                    $scoreColor = '#166534'; // Green
                                } elseif ($catPct >= 65) {
                                    $scoreColor = '#15803d'; // Light green
                                } elseif ($catPct >= 45) {
                                    $scoreColor = '#ca8a04'; // Yellow
                                } elseif ($catPct >= 25) {
                                    $scoreColor = '#ea580c'; // Orange
                                } else {
                                    $scoreColor = '#dc2626'; // Red
                                }
                                ?>
                                <div style="background: #f8f9fa; padding: 6px; border-radius: 4px; text-align: center;">
                                    <div style="font-size: 9px; color: #666; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px;">
                                        <?php echo e($categoryName); ?>
                                    </div>
                                    <div style="font-size: 16px; font-weight: 700; color: <?php echo $scoreColor; ?>;">
                                        <?php echo $catDisplayVal; ?><?php echo $upguardDisplayMode === 'percentage' ? '%' : ''; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($shodanLatestScore): ?>
                <div class="card">
                    <h3><?php echo e($shodanName); ?> Score</h3>
                    <div class="score-display">
                        <?php
                        $shodanScore = intval($shodanLatestScore['score']);
                        $shodanGrade = $shodanService->calculateGrade($shodanScore);
                        $shodanGradeClass = 'grade-' . strtolower($shodanGrade);
                        $shodanTrafficLight = $shodanLatestScore['traffic_light'] ?? null;
                        $tlColors = ['green' => '#16a34a', 'yellow' => '#eab308', 'red' => '#dc2626'];
                        $tlLabels = ['green' => t('vendor-srs-details.tl_acceptable'), 'yellow' => t('vendor-srs-details.tl_needs_improvement'), 'red' => t('vendor-srs-details.tl_unacceptable')];
                        ?>
                        <span class="score-value"><?php echo $shodanScore; ?>%</span>
                        <span class="score-grade <?php echo $shodanGradeClass; ?>"><?php echo $shodanGrade; ?></span>
                        <?php if ($shodanTrafficLight): ?>
                        <span style="display: inline-block; width: 18px; height: 18px; border-radius: 50%; background: <?php echo $tlColors[$shodanTrafficLight] ?? '#9ca3af'; ?>; vertical-align: middle; margin-left: 8px; box-shadow: 0 0 6px <?php echo $tlColors[$shodanTrafficLight] ?? '#9ca3af'; ?>;" title="Risk: <?php echo e($tlLabels[$shodanTrafficLight] ?? 'Unknown'); ?>"></span>
                        <?php endif; ?>
                        <div class="score-label">percentage score (<?php echo e($shodanName); ?>)</div>
                        <div class="score-date"><?php echo e(t('vendor-srs-details.scored_on_prefix')); ?> <?php echo date('M j, Y g:i A', strtotime($shodanLatestScore['scored_at'])); ?></div>
                    </div>

                    <?php
                    // Enhanced category scores display
                    $shodanCategoryScores = null;
                    if (!empty($shodanLatestScore['category_scores'])) {
                        $shodanCategoryScores = is_string($shodanLatestScore['category_scores'])
                            ? json_decode($shodanLatestScore['category_scores'], true)
                            : $shodanLatestScore['category_scores'];
                    }

                    if ($shodanCategoryScores):
                        $categoryLabels = [
                            'tls_crypto' => 'TLS / Crypto',
                            'network_security' => 'Network Security',
                            'app_hardening' => 'App Hardening',
                            'vuln_exposure' => 'Vulnerability Exposure',
                            'email_security' => 'Email Security',
                        ];
                        $shodanWeights = $shodanService->getCategoryWeights();
                        $categoryWeights = [];
                        foreach ($shodanWeights as $wk => $wv) {
                            $categoryWeights[$wk] = $wv . '%';
                        }
                    ?>
                    <div style="margin-top: 15px; padding-top: 12px; border-top: 1px solid #f0f0f0;">
                        <h4 style="font-size: 12px; color: #666; margin: 0 0 10px 0; font-weight: 600;"><?php echo e(t('vendor-srs-details.category_scores')); ?></h4>
                        <?php foreach ($shodanCategoryScores as $catKey => $catScore):
                            $catScoreInt = intval($catScore);
                            $barColor = '#6b7280';
                            if ($catScoreInt >= 80) $barColor = '#16a34a';
                            elseif ($catScoreInt >= 60) $barColor = '#65a30d';
                            elseif ($catScoreInt >= 40) $barColor = '#eab308';
                            elseif ($catScoreInt >= 20) $barColor = '#ea580c';
                            else $barColor = '#dc2626';
                        ?>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;" data-action="scrollToCategory" data-arg="<?php echo e($catKey); ?>" title="Click to jump to <?php echo e($categoryLabels[$catKey] ?? $catKey); ?> signals">
                            <div style="width: 120px; font-size: 11px; color: #555; text-align: right;">
                                <?php echo e($categoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey))); ?>
                                <span style="color: #999; font-size: 9px;">(<?php echo e($categoryWeights[$catKey] ?? ''); ?>)</span>
                            </div>
                            <div style="flex: 1; background: #f3f4f6; border-radius: 4px; height: 16px; overflow: hidden;">
                                <div style="width: <?php echo $catScoreInt; ?>%; height: 100%; background: <?php echo $barColor; ?>; border-radius: 4px; transition: width 0.5s;"></div>
                            </div>
                            <div style="width: 36px; font-size: 12px; font-weight: 700; color: <?php echo $barColor; ?>; text-align: right;"><?php echo $catScoreInt; ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 15px;">
                        <?php
                        $shodanPositive = intval($shodanLatestScore['positive_count'] ?? 0);
                        $shodanNegative = intval($shodanLatestScore['negative_count'] ?? 0);
                        if ($shodanPositive > 0 || $shodanNegative > 0):
                        ?>
                        <div style="text-align: center; padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-signals" title="Jump to Security Signals">
                            <div style="font-size: 24px; font-weight: 700; color: #16a34a;"><?php echo $shodanPositive; ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.positive_signals')); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-signals" title="Jump to Security Signals">
                            <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo $shodanNegative; ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.negative_signals')); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-findings" title="Jump to Raw Findings">
                            <div style="font-size: 24px; font-weight: 700; color: #0284c7;"><?php echo intval($shodanLatestScore['open_ports_count']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.col_open_ports')); ?></div>
                        </div>
                        <?php if (!empty($shodanWaivers)): ?>
                        <div style="text-align: center; padding: 10px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-signals" title="Jump to Security Signals">
                            <div style="font-size: 24px; font-weight: 700; color: #4f46e5;"><?php echo count($shodanWaivers); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.waived_risks_label')); ?></div>
                        </div>
                        <?php else: ?>
                        <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="cat_section_vuln_exposure" title="Jump to Vulnerability Exposure">
                            <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo intval($shodanLatestScore['vuln_count']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.total_cves_label')); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div style="text-align: center; padding: 10px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-findings" title="Jump to Findings">
                            <div style="font-size: 24px; font-weight: 700; color: #0284c7;"><?php echo intval($shodanLatestScore['open_ports_count']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.col_open_ports')); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-findings" title="Jump to Findings">
                            <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo intval($shodanLatestScore['critical_vulns']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.critical_cves_label')); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-findings" title="Jump to Findings">
                            <div style="font-size: 24px; font-weight: 700; color: #ea580c;"><?php echo intval($shodanLatestScore['high_vulns']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.high_cves_label')); ?></div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; cursor: pointer;" data-action="scrollToSection" data-arg="shodan-findings" title="Jump to Findings">
                            <div style="font-size: 24px; font-weight: 700; color: #ca8a04;"><?php echo intval($shodanLatestScore['medium_vulns'] + $shodanLatestScore['low_vulns']); ?></div>
                            <div style="font-size: 11px; color: #666; margin-top: 4px;"><?php echo e(t('vendor-srs-details.medlow_cves_label')); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Subdomains scanned
                    $shodanSubdomains = [];
                    if (!empty($shodanLatestScore['subdomains_scanned'])) {
                        $shodanSubdomains = is_string($shodanLatestScore['subdomains_scanned'])
                            ? json_decode($shodanLatestScore['subdomains_scanned'], true) ?: []
                            : $shodanLatestScore['subdomains_scanned'];
                    }
                    if (!empty($shodanSubdomains)):
                    ?>
                    <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #f0f0f0;">
                        <span style="font-size: 11px; color: #666; font-weight: 600; cursor: pointer; user-select: none;" data-toggle="shodanSubsList" data-toggle-arrow="shodanSubsArrow">
                            <span id="shodanSubsArrow" style="font-size: 9px; margin-right: 3px; display: inline-block;">&#9654;</span>
                            <?php echo e(t('vendor-srs-details.subdomains_scanned_label')); ?> (<?php echo count($shodanSubdomains); ?>)
                        </span>
                        <div id="shodanSubsList" style="display: none; margin-top: 6px; font-size: 12px; color: #333; font-family: monospace; padding: 6px 8px; background: #f9fafb; border-radius: 4px; line-height: 1.8;">
                            <?php echo e(implode(', ', $shodanSubdomains)); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    $shodanIPs = [];
                    if (!empty($shodanLatestScore['ip_addresses'])) {
                        $shodanIPs = json_decode($shodanLatestScore['ip_addresses'], true) ?: [];
                    }
                    if (!empty($shodanIPs)):
                    ?>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f0f0f0;">
                        <span style="font-size: 11px; color: #666; font-weight: 600; cursor: pointer; user-select: none;" data-toggle="shodanIPsList" data-toggle-arrow="shodanIPsArrow">
                            <span id="shodanIPsArrow" style="font-size: 9px; margin-right: 3px; display: inline-block;">&#9654;</span>
                            <?php echo e(t('vendor-srs-details.ips_scanned_label')); ?> (<?php echo count($shodanIPs); ?>)
                        </span>
                        <div id="shodanIPsList" style="display: none; margin-top: 6px; font-size: 12px; color: #333; font-family: monospace; padding: 6px 8px; background: #f9fafb; border-radius: 4px; line-height: 1.8;">
                            <?php echo e(implode(', ', $shodanIPs)); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                // Custom Score Card
                $customScoringEnabled = getAppConfig('custom_scoring_enabled', '0') === '1';
                if ($customScoringEnabled):
                    $customScore = $vendor['custom_score'] ?? null;
                    $customGrade = null;
                    if ($customScore !== null && (int)$customScore > 0) {
                        $cs = (int)$customScore;
                        $customGrade = $cs >= 90 ? 'A' : ($cs >= 75 ? 'B' : ($cs >= 60 ? 'C' : ($cs >= 40 ? 'D' : 'F')));
                    }
                ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.custom_score')); ?></h3>
                    </div>
                    <?php if ($customScore !== null && (int)$customScore > 0): ?>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <span style="font-size: 32px; font-weight: 700; color: #1f2937;"><?php echo (int)$customScore; ?>%</span>
                        <?php
                        $cgColor = $customGrade === 'A' ? '#166534' : ($customGrade === 'B' ? '#15803d' : ($customGrade === 'C' ? '#ca8a04' : ($customGrade === 'D' ? '#ea580c' : '#dc2626')));
                        $cgBg = $customGrade === 'A' ? '#f0fdf4' : ($customGrade === 'B' ? '#f0fdf4' : ($customGrade === 'C' ? '#fefce8' : ($customGrade === 'D' ? '#fff7ed' : '#fef2f2')));
                        ?>
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; font-weight: 700; font-size: 16px; background: <?php echo $cgBg; ?>; color: <?php echo $cgColor; ?>;"><?php echo $customGrade; ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isStakeholderView): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="_scroll" value="0" class="scroll-tracker">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="number" name="custom_score" min="1" max="100" class="form-control" style="width: 100px;" value="<?php echo $customScore !== null ? (int)$customScore : ''; ?>" placeholder="1-100">
                            <button type="submit" name="update_custom_score" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px;"><?php echo e(t('vendor-srs-details.save')); ?></button>
                            <?php if ($customScore !== null): ?>
                            <button type="submit" name="update_custom_score" class="btn btn-secondary" style="padding: 6px 14px; font-size: 12px;" onclick="this.form.querySelector('input[name=custom_score]').value='null';"><?php echo e(t('vendor-srs-details.un_score')); ?></button>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;"><?php echo e(t('vendor-srs-details.custom_score_hint')); ?></div>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                // Combined Score Summary Card -- only show when 2+ score sources exist
                $combined = $srsService->getCombinedScore($vendor);
                if ($combined['source_count'] >= 2):
                    $combGrade = $combined['grade'];
                    $combScore = $combined['score'];
                    $combColor = $combGrade === 'A' ? '#166534' : ($combGrade === 'B' ? '#15803d' : ($combGrade === 'C' ? '#ca8a04' : ($combGrade === 'D' ? '#ea580c' : '#dc2626')));
                    $combBg = $combGrade === 'A' ? '#f0fdf4' : ($combGrade === 'B' ? '#f0fdf4' : ($combGrade === 'C' ? '#fefce8' : ($combGrade === 'D' ? '#fff7ed' : '#fef2f2')));

                    // Build source labels
                    $sources = [];
                    if (!empty($vendor['current_srs_score']) && (int)$vendor['current_srs_score'] > 0) $sources[] = $upguardName;
                    if (!empty($vendor['current_shodan_score']) && (int)$vendor['current_shodan_score'] > 0) $sources[] = $shodanName;
                    if (!empty($vendor['custom_score']) && (int)$vendor['custom_score'] > 0) $sources[] = 'Custom';
                ?>
                <div class="card" style="background: linear-gradient(135deg, #f8fafc 0%, #f0f4ff 100%); border: 1px solid #c7d2fe;">
                    <h3 style="margin: 0 0 12px 0;"><?php echo e(t('vendor-srs-details.combined_srs_score')); ?></h3>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                        <span style="font-size: 36px; font-weight: 700; color: #1f2937;"><?php echo $combScore; ?>%</span>
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; font-weight: 700; font-size: 18px; background: <?php echo $combBg; ?>; color: <?php echo $combColor; ?>;"><?php echo $combGrade; ?></span>
                    </div>
                    <div style="font-size: 12px; color: #6b7280;"><?php echo e(t('vendor-srs-details.average_of_prefix')); ?> <?php echo e(implode(' + ', $sources)); ?></div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.vendor_information')); ?></h3>
                        <a href="vendor-onboarding.php?id=<?php echo $vendorId; ?>" class="btn btn-secondary" style="padding: 5px 14px; font-size: 12px; text-decoration: none;"><?php echo e(t('vendor-srs-details.view')); ?></a>
                    </div>
                    <div class="info-grid" style="margin-top: 12px;">
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_vendor_name')); ?></label>
                            <div class="value">
                                <?php echo e($vendor['vendor_name'] ?? 'Not specified'); ?>
                                <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openVendorNameModal" style="margin-left: 8px; color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_vendor_name')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_domain')); ?></label>
                            <div class="value"><?php echo e($vendor['vendor_domain'] ?? 'Not specified'); ?></div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_vendor_tier')); ?></label>
                            <div class="value">
                                <?php if (!empty($vendor['vendor_tier'])): ?>
                                    <?php echo e($srsService->getTierDisplayName($vendor['vendor_tier'])); ?>
                                <?php else: ?>
                                    <span style="color: #dc3545; font-weight: 600;"><?php echo e(t('vendor-srs-details.not_tiered_yet')); ?></span>
                                <?php endif; ?>
                                <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openTierModal" style="margin-left: 8px; color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_vendor_tier')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_rescore_schedule')); ?></label>
                            <div class="value">
                                <?php if (!empty($vendor['vendor_tier'])): ?>
                                    <?php echo e($srsService->getTierIntervalDescription($vendor['vendor_tier'])); ?>
                                <?php else: ?>
                                    <span style="color: #999;"><?php echo e(t('vendor-srs-details.no_auto_rescore')); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_vendor_type')); ?></label>
                            <div class="value">
                                <?php echo e($vendor['vendor_type'] ?? 'Not specified'); ?>
                                <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openVendorTypeModal" style="margin-left: 8px; color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_vendor_type')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_status')); ?></label>
                            <div class="value">
                                <?php echo e(ucfirst(str_replace('_', ' ', $vendor['status'] ?? 'Unknown'))); ?>
                                <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openStatusModal" style="margin-left: 8px; color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_vendor_status')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label><?php echo e(t('vendor-srs-details.lbl_stakeholder')); ?></label>
                            <div class="value">
                                <?php if ($assignedStakeholder): ?>
                                    <?php echo e($assignedStakeholder['full_name'] ?? $assignedStakeholder['username']); ?>
                                <?php else: ?>
                                    <span style="color: #999;"><?php echo e(t('vendor-srs-details.not_assigned')); ?></span>
                                <?php endif; ?>
                                <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openStakeholderModal" style="margin-left: 8px; color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_stakeholder')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (true): // Contact Information - always show ?>
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h4 style="font-size: 14px; color: #666; margin: 0 0 10px 0;"><?php echo e(t('vendor-srs-details.contact_information')); ?></h4>
                            <?php if (!$isStakeholderView && !$isAuditor): ?><a href="#" data-action="openContactModal" style="color: var(--theme-header-color); text-decoration: none; font-size: 12px; font-weight: 500;" title="<?php echo e(t('vendor-srs-details.edit_contact')); ?>"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <label><?php echo e(t('vendor-srs-details.lbl_primary_contact')); ?></label>
                                <div class="value"><?php echo e($vendor['primary_contact_details'] ?? ''); ?><?php if (empty($vendor['primary_contact_details'])): ?><span style="color: #999;"><?php echo e(t('vendor-srs-details.not_specified')); ?></span><?php endif; ?></div>
                            </div>
                            <div class="info-item">
                                <label><?php echo e(t('vendor-srs-details.lbl_title')); ?></label>
                                <div class="value"><?php echo e($vendor['primary_contact_title'] ?? ''); ?><?php if (empty($vendor['primary_contact_title'])): ?><span style="color: #999;"><?php echo e(t('vendor-srs-details.not_specified')); ?></span><?php endif; ?></div>
                            </div>
                            <div class="info-item">
                                <label><?php echo e(t('vendor-srs-details.lbl_email')); ?></label>
                                <div class="value"><?php if (!empty($vendor['primary_contact_email'])): ?><a href="mailto:<?php echo e($vendor['primary_contact_email']); ?>" style="color: var(--theme-header-color); text-decoration: none;"><?php echo e($vendor['primary_contact_email']); ?></a><?php else: ?><span style="color: #999;"><?php echo e(t('vendor-srs-details.not_specified')); ?></span><?php endif; ?></div>
                            </div>
                            <div class="info-item">
                                <label><?php echo e(t('vendor-srs-details.lbl_phone')); ?></label>
                                <div class="value"><?php echo e($vendor['primary_contact_phone'] ?? ''); ?><?php if (empty($vendor['primary_contact_phone'])): ?><span style="color: #999;"><?php echo e(t('vendor-srs-details.not_specified')); ?></span><?php endif; ?></div>
                            </div>
                            <?php if (!empty($vendor['relationship_manager'])): ?>
                            <div class="info-item">
                                <label><?php echo e(t('vendor-srs-details.lbl_relationship_manager')); ?></label>
                                <div class="value"><?php echo e($vendor['relationship_manager']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($vendorAssessments)): ?>
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                        <h4 style="font-size: 14px; color: #666; margin: 0 0 10px 0;"><?php echo e(t('vendor-srs-details.recent_assessments')); ?></h4>
                        <table style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('vendor-srs-details.col_template')); ?></th>
                                    <th><?php echo e(t('vendor-srs-details.col_status')); ?></th>
                                    <th><?php echo e(t('vendor-srs-details.col_completed')); ?></th>
                                    <th><?php echo e(t('vendor-srs-details.col_action')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vendorAssessments as $assessment): ?>
                                <tr>
                                    <td><?php echo e($assessment['template_name']); ?></td>
                                    <td>
                                        <span class="badge" style="background: <?php echo $assessment['status'] === 'completed' ? '#dcfce7' : ($assessment['status'] === 'in_progress' ? '#fef3c7' : '#f3f4f6'); ?>; color: <?php echo $assessment['status'] === 'completed' ? '#166534' : ($assessment['status'] === 'in_progress' ? '#92400e' : '#6b7280'); ?>;">
                                            <?php echo e(ucfirst(str_replace('_', ' ', $assessment['status']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $assessment['completed_at'] ? date('M j, Y', strtotime($assessment['completed_at'])) : '-'; ?></td>
                                    <td>
                                        <a href="vendor-assessment-view.php?id=<?php echo $assessment['id']; ?>" target="_blank" style="color: var(--theme-header-color); text-decoration: none; font-weight: 500;"><?php echo e(t('vendor-srs-details.view')); ?></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php if ($fairAnalysis): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.fair_analysis')); ?></h3>
                    <div style="display: flex; gap: 8px;">
                        <a href="download-pdf.php?id=<?php echo intval($fairAnalysis['id']); ?>" target="_blank" class="btn btn-secondary" style="padding: 5px 14px; font-size: 12px; text-decoration: none;"><?php echo e(t('vendor-srs-details.view_pdf')); ?></a>
                        <?php if (!$isStakeholderView && !$isAuditor): ?><a href="fair-analysis.php?id=<?php echo intval($fairAnalysis['id']); ?>" target="_blank" class="btn btn-secondary" style="padding: 5px 14px; font-size: 12px; text-decoration: none;"><?php echo e(t('vendor-srs-details.edit')); ?></a><?php endif; ?>
                    </div>
                </div>
                <table style="font-size: 12px; margin-top: 12px;">
                    <thead>
                        <tr>
                            <th><?php echo e(t('vendor-srs-details.col_status')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_risk_level')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_lef')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_ale')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_iso27001')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_created')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <span class="badge" style="background: <?php echo $fairAnalysis['status'] === 'completed' ? '#dcfce7' : '#fef3c7'; ?>; color: <?php echo $fairAnalysis['status'] === 'completed' ? '#166534' : '#92400e'; ?>;">
                                    <?php echo e(ucfirst($fairAnalysis['status'] ?? 'Draft')); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $riskLevel = $fairAnalysis['risk_output'] ?? '-';
                                $riskColor = '#6b7280';
                                $riskBg = '#f3f4f6';
                                if ($riskLevel === 'Critical') { $riskColor = '#991b1b'; $riskBg = '#fef2f2'; }
                                elseif ($riskLevel === 'High') { $riskColor = '#c2410c'; $riskBg = '#fff7ed'; }
                                elseif ($riskLevel === 'Medium') { $riskColor = '#b45309'; $riskBg = '#fffbeb'; }
                                elseif ($riskLevel === 'Low') { $riskColor = '#166534'; $riskBg = '#f0fdf4'; }
                                ?>
                                <span class="badge" style="background: <?php echo $riskBg; ?>; color: <?php echo $riskColor; ?>;">
                                    <?php echo e($riskLevel); ?>
                                </span>
                            </td>
                            <td><?php echo !empty($fairAnalysis['loss_event_frequency']) ? e(number_format((float)$fairAnalysis['loss_event_frequency'], 2)) : '-'; ?></td>
                            <td><?php echo !empty($fairAnalysis['ale']) ? '$' . e(number_format((float)$fairAnalysis['ale'], 0)) : '-'; ?></td>
                            <td>
                                <?php if ($fairAnalysis['iso_27001_certified']): ?>
                                    <span style="color: #166534;">&#10003; <?php echo e(t('vendor-srs-details.yes')); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;"><?php echo e(t('vendor-srs-details.no')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($fairAnalysis['created_at'])); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (count($trendData) >= 1): ?>
            <div class="card">
                <h3>Score Trend (<?php echo $trendingDays; ?> Days)<?php echo count($trendData) > 1 ? ' - ' . count($trendData) . ' scores' : ''; ?></h3>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($risks)): ?>
            <div class="card" id="upguard-risks">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleIdentifiedRisks">
                    <h3 style="margin: 0;"><?php echo e($upguardName); ?> Identified Risks (<?php echo count($risks); ?>)</h3>
                    <span id="identifiedRisksArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                </div>
                <div id="identifiedRisksBody" style="display: none; margin-top: 12px;">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo e(t('vendor-srs-details.col_severity')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_risk_name')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_host')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_category')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_description')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_first_seen')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="identifiedRisksTbody"></tbody>
                </table>
                <div id="identifiedRisksPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                const risksData = <?php echo json_encode(array_values(array_map(function($r) {
                    return [
                        'severity' => $r['severity'] ?? '',
                        'name' => $r['risk_name'] ?? '',
                        'host' => $r['risk_host'] ?? '',
                        'category' => $r['risk_category'] ?? '',
                        'description' => $r['description'] ?? '',
                        'first_seen' => !empty($r['first_seen']) ? date('M j, Y', strtotime($r['first_seen'])) : '-',
                    ];
                }, $risks))); ?>;
                const perPage = 20;
                const totalPages = Math.ceil(risksData.length / perPage);
                function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                function renderRisksPage(page) {
                    const start = (page - 1) * perPage;
                    const slice = risksData.slice(start, start + perPage);
                    let html = '';
                    slice.forEach(r => {
                        html += '<tr><td><span class="badge badge-' + esc(r.severity) + '">' + esc(r.severity.charAt(0).toUpperCase() + r.severity.slice(1)) + '</span></td>'
                            + '<td>' + esc(r.name) + '</td>'
                            + '<td style="font-family:monospace;font-size:11px;max-width:200px;word-break:break-all;">' + esc(r.host || '-') + '</td>'
                            + '<td>' + esc(r.category) + '</td>'
                            + '<td style="max-width:300px;">' + esc(r.description) + '</td>'
                            + '<td>' + esc(r.first_seen) + '</td></tr>';
                    });
                    document.getElementById('identifiedRisksTbody').innerHTML = html;

                    let pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(start+perPage, risksData.length) + ' of ' + risksData.length + '</span>';
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="identifiedRisksPage" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        for (let i = 1; i <= totalPages; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="identifiedRisksPage" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (page < totalPages) pag += '<button data-action="identifiedRisksPage" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    document.getElementById('identifiedRisksPagination').innerHTML = pag;
                }

                window.identifiedRisksPage = renderRisksPage;
                let risksInit = false;
                window.toggleIdentifiedRisks = function() {
                    const body = document.getElementById('identifiedRisksBody');
                    const arrow = document.getElementById('identifiedRisksArrow');
                    const showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !risksInit) { risksInit = true; renderRisksPage(1); }
                };
            })();
            </script>
            <?php endif; ?>

            <?php if (!empty($vendorSubdomains)): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleVendorDomains">
                    <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.vendor_domains_heading')); ?> (<span id="vdCountDisplay"><?php echo count($vendorSubdomains); ?></span>)</h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" data-action="exportVendorDomainsCSV" style="padding: 4px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                        <span id="vendorDomainsArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                    </div>
                </div>
                <div id="vendorDomainsBody" style="display: none; margin-top: 12px;">
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 12px;">
                        <input type="text" id="vdSearch" placeholder="<?php echo e(t('vendor-srs-details.search_subdomain')); ?>" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; width: 220px;">
                        <select id="vdStatusFilter" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; cursor: pointer;">
                            <option value=""><?php echo e(t('vendor-srs-details.all_status')); ?></option>
                            <option value="1"><?php echo e(t('vendor-srs-details.active')); ?></option>
                            <option value="0"><?php echo e(t('vendor-srs-details.inactive')); ?></option>
                        </select>
                        <div style="display: flex; gap: 4px; align-items: center;">
                            <span style="font-size: 11px; color: #6b7280; margin-right: 2px;"><?php echo e(t('vendor-srs-details.grade_label')); ?></span>
                            <button type="button" class="vd-grade-btn" data-grade="" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid var(--theme-header-color,#2563eb);background:var(--theme-header-color,#2563eb);color:white;cursor:pointer;"><?php echo e(t('vendor-srs-details.all')); ?></button>
                            <button type="button" class="vd-grade-btn" data-grade="A" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid #166534;background:transparent;color:#166534;cursor:pointer;">A</button>
                            <button type="button" class="vd-grade-btn" data-grade="B" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid #1e40af;background:transparent;color:#1e40af;cursor:pointer;">B</button>
                            <button type="button" class="vd-grade-btn" data-grade="C" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid #92400e;background:transparent;color:#92400e;cursor:pointer;">C</button>
                            <button type="button" class="vd-grade-btn" data-grade="D" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid #9a3412;background:transparent;color:#9a3412;cursor:pointer;">D</button>
                            <button type="button" class="vd-grade-btn" data-grade="F" style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;border:2px solid #991b1b;background:transparent;color:#991b1b;cursor:pointer;">F</button>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th data-action="vdSort" data-arg="domain" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_subdomain')); ?> <span id="vdSort_domain" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vdSort" data-arg="score" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_score')); ?> <span id="vdSort_score" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vdSort" data-arg="grade" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_grade')); ?> <span id="vdSort_grade" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vdSort" data-arg="active" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_status')); ?> <span id="vdSort_active" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vdSort" data-arg="last_scanned" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_last_scanned')); ?> <span id="vdSort_last_scanned" style="font-size:10px;color:#9ca3af;"></span></th>
                            </tr>
                        </thead>
                        <tbody id="vendorDomainsTbody"></tbody>
                    </table>
                    <div id="vendorDomainsPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                const vdDataAll = <?php echo json_encode(array_values(array_map(function($r) use ($srsService) {
                    $score = $r['score'] !== null ? (int)$r['score'] : null;
                    $grade = $r['score_grade'] ?? ($score !== null ? $srsService->calculateGrade($score) : '-');
                    return [
                        'domain' => $r['subdomain'] ?? '',
                        'score' => $score,
                        'grade' => $grade,
                        'active' => (int)($r['is_active'] ?? 1),
                        'last_scanned' => !empty($r['last_scanned']) ? date('M j, Y', strtotime($r['last_scanned'])) : '-',
                        'last_scanned_raw' => !empty($r['last_scanned']) ? $r['last_scanned'] : '',
                    ];
                }, $vendorSubdomains))); ?>;
                const vdPerPage = 20;
                let vdFiltered = vdDataAll.slice();
                let vdSortCol = null;
                let vdSortAsc = true;
                let vdGradeFilter = '';
                let vdSearchTerm = '';
                let vdStatusFilter = '';

                function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                function gradeColor(g) {
                    switch(g) {
                        case 'A': return 'background:#dcfce7;color:#166534;';
                        case 'B': return 'background:#dbeafe;color:#1e40af;';
                        case 'C': return 'background:#fef3c7;color:#92400e;';
                        case 'D': return 'background:#fed7aa;color:#9a3412;';
                        case 'F': return 'background:#fecaca;color:#991b1b;';
                        default: return 'background:#f3f4f6;color:#6b7280;';
                    }
                }
                function scoreColor(s) {
                    if (s === null) return '#6b7280';
                    if (s >= 850) return '#166534';
                    if (s >= 700) return '#1e40af';
                    if (s >= 500) return '#92400e';
                    if (s >= 300) return '#9a3412';
                    return '#991b1b';
                }

                const gradeOrder = {A:1, B:2, C:3, D:4, F:5, '-':6};

                function applyFiltersAndSort() {
                    vdFiltered = vdDataAll.filter(function(r) {
                        if (vdSearchTerm && r.domain.toLowerCase().indexOf(vdSearchTerm) === -1) return false;
                        if (vdStatusFilter !== '' && String(r.active) !== vdStatusFilter) return false;
                        if (vdGradeFilter && r.grade !== vdGradeFilter) return false;
                        return true;
                    });
                    if (vdSortCol) {
                        vdFiltered.sort(function(a, b) {
                            let va = a[vdSortCol], vb = b[vdSortCol];
                            if (vdSortCol === 'grade') {
                                va = gradeOrder[va] || 99;
                                vb = gradeOrder[vb] || 99;
                            } else if (vdSortCol === 'score') {
                                va = va !== null ? va : -1;
                                vb = vb !== null ? vb : -1;
                            } else if (vdSortCol === 'last_scanned') {
                                va = a.last_scanned_raw || '';
                                vb = b.last_scanned_raw || '';
                            } else if (vdSortCol === 'domain') {
                                va = (va || '').toLowerCase();
                                vb = (vb || '').toLowerCase();
                            }
                            if (va < vb) return vdSortAsc ? -1 : 1;
                            if (va > vb) return vdSortAsc ? 1 : -1;
                            return 0;
                        });
                    }
                    document.getElementById('vdCountDisplay').textContent = vdFiltered.length === vdDataAll.length ? vdDataAll.length : vdFiltered.length + ' / ' + vdDataAll.length;
                    updateSortIndicators();
                    renderVdPage(1);
                }

                function updateSortIndicators() {
                    ['domain','score','grade','active','last_scanned'].forEach(function(col) {
                        var el = document.getElementById('vdSort_' + col);
                        if (el) el.textContent = vdSortCol === col ? (vdSortAsc ? '\u25B2' : '\u25BC') : '';
                    });
                }

                function renderVdPage(page) {
                    var totalPages = Math.ceil(vdFiltered.length / vdPerPage);
                    if (page < 1) page = 1;
                    if (page > totalPages && totalPages > 0) page = totalPages;
                    var start = (page - 1) * vdPerPage;
                    var slice = vdFiltered.slice(start, start + vdPerPage);
                    var html = '';
                    if (slice.length === 0) {
                        html = '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:20px;">No domains match the current filters.</td></tr>';
                    }
                    slice.forEach(function(r) {
                        var scoreStr = r.score !== null ? r.score : '-';
                        var sColor = scoreColor(r.score);
                        var statusBadge = r.active ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">Active</span>' : '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">Inactive</span>';
                        html += '<tr>'
                            + '<td style="font-family:monospace;font-size:12px;">' + esc(r.domain) + '</td>'
                            + '<td style="font-weight:600;color:' + sColor + ';">' + esc(String(scoreStr)) + '</td>'
                            + '<td><span style="padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600;' + gradeColor(r.grade) + '">' + esc(r.grade) + '</span></td>'
                            + '<td>' + statusBadge + '</td>'
                            + '<td>' + esc(r.last_scanned) + '</td>'
                            + '</tr>';
                    });
                    document.getElementById('vendorDomainsTbody').innerHTML = html;

                    var pag = '';
                    if (vdFiltered.length > 0) {
                        pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(start+vdPerPage, vdFiltered.length) + ' of ' + vdFiltered.length + '</span>';
                    } else {
                        pag = '<span style="font-size:12px;color:#6b7280;">0 results</span>';
                    }
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="vendorDomainsPage" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        var startP = Math.max(1, page - 3), endP = Math.min(totalPages, page + 3);
                        if (startP > 1) pag += '<button data-action="vendorDomainsPage" data-arg="1" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">1</button><span style="padding:3px 4px;color:#9ca3af;">...</span>';
                        for (var i = startP; i <= endP; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="vendorDomainsPage" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (endP < totalPages) pag += '<span style="padding:3px 4px;color:#9ca3af;">...</span><button data-action="vendorDomainsPage" data-arg="' + totalPages + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + totalPages + '</button>';
                        if (page < totalPages) pag += '<button data-action="vendorDomainsPage" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    document.getElementById('vendorDomainsPagination').innerHTML = pag;
                }

                window.vendorDomainsPage = renderVdPage;

                window.vdSort = function(col) {
                    if (vdSortCol === col) { vdSortAsc = !vdSortAsc; }
                    else { vdSortCol = col; vdSortAsc = (col === 'domain'); }
                    applyFiltersAndSort();
                };

                document.getElementById('vdSearch').addEventListener('input', function() {
                    vdSearchTerm = this.value.toLowerCase().trim();
                    applyFiltersAndSort();
                });
                document.getElementById('vdStatusFilter').addEventListener('change', function() {
                    vdStatusFilter = this.value;
                    applyFiltersAndSort();
                });

                var gradeButtons = document.querySelectorAll('.vd-grade-btn');
                var gradeActiveColors = {'': 'var(--theme-header-color,#2563eb)', A:'#166534', B:'#1e40af', C:'#92400e', D:'#9a3412', F:'#991b1b'};
                function setGradeFilter(grade) {
                    vdGradeFilter = grade;
                    gradeButtons.forEach(function(btn) {
                        var g = btn.getAttribute('data-grade');
                        if (g === grade) {
                            btn.style.background = gradeActiveColors[g] || gradeActiveColors[''];
                            btn.style.color = 'white';
                        } else {
                            btn.style.background = 'transparent';
                            btn.style.color = gradeActiveColors[g] || '#6b7280';
                        }
                    });
                    applyFiltersAndSort();
                }
                gradeButtons.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        setGradeFilter(this.getAttribute('data-grade'));
                    });
                });

                let vdInit = false;
                window.toggleVendorDomains = function() {
                    const body = document.getElementById('vendorDomainsBody');
                    const arrow = document.getElementById('vendorDomainsArrow');
                    const showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !vdInit) { vdInit = true; applyFiltersAndSort(); }
                };
                window.exportVendorDomainsCSV = function() {
                    var data = vdFiltered.length < vdDataAll.length ? vdFiltered : vdDataAll;
                    var csv = 'Subdomain,Score,Grade,Status,Last Scanned\n';
                    data.forEach(function(r) {
                        csv += '"' + (r.domain||'').replace(/"/g,'""') + '",' + (r.score !== null ? r.score : '') + ',' + r.grade + ',' + (r.active ? 'Active' : 'Inactive') + ',' + r.last_scanned + '\n';
                    });
                    var blob = new Blob([csv], {type: 'text/csv'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'vendor_domains_<?php echo preg_replace('/[^a-zA-Z0-9_.-]/', '_', $vendor['vendor_domain'] ?? 'vendor'); ?>.csv';
                    a.click();
                };
            })();
            </script>
            <?php endif; ?>

            <?php if (!empty($vendorTechnologies)): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleVendorTech">
                    <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.fourth_party_tech_heading')); ?> (<span id="vtCountDisplay"><?php echo count($vendorTechnologies); ?></span>)</h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="fourth-party-risk.php" style="padding: 4px 12px; background: var(--theme-header-color, #2563eb); color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; text-decoration: none;"><?php echo e(t('vendor-srs-details.fourth_party_risk_link')); ?></a>
                        <button type="button" data-action="exportVendorTechCSV" style="padding: 4px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                        <span id="vendorTechArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                    </div>
                </div>
                <div id="vendorTechBody" style="display: none; margin-top: 12px;">
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 12px;">
                        <input type="text" id="vtSearch" placeholder="<?php echo e(t('vendor-srs-details.search_technology')); ?>" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; width: 220px;">
                        <select id="vtCategoryFilter" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; cursor: pointer;">
                            <option value=""><?php echo e(t('vendor-srs-details.all_categories')); ?></option>
                        </select>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th data-action="vtSort" data-arg="name" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_technology')); ?> <span id="vtSort_name" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="version" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_version')); ?> <span id="vtSort_version" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="category" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_category')); ?> <span id="vtSort_category" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="detected_on" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_detected_on')); ?> <span id="vtSort_detected_on" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="port" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_port')); ?> <span id="vtSort_port" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="confidence" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_confidence')); ?> <span id="vtSort_confidence" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="cve_count" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_cves')); ?> <span id="vtSort_cve_count" style="font-size:10px;color:#9ca3af;"></span></th>
                                <th data-action="vtSort" data-arg="last_seen" style="cursor:pointer;user-select:none;"><?php echo e(t('vendor-srs-details.col_last_seen')); ?> <span id="vtSort_last_seen" style="font-size:10px;color:#9ca3af;"></span></th>
                            </tr>
                        </thead>
                        <tbody id="vendorTechTbody"></tbody>
                    </table>
                    <div id="vendorTechPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                var vtDataAll = <?php echo json_encode(array_values(array_map(function($t) {
                    $cves = !empty($t['cves']) ? (is_string($t['cves']) ? json_decode($t['cves'], true) : $t['cves']) : [];
                    return [
                        'name' => $t['technology_name'],
                        'version' => $t['technology_version'] ?? '-',
                        'category' => $t['technology_category'],
                        'detected_on' => $t['detected_on'] ?? '-',
                        'port' => $t['detected_port'] ? (int)$t['detected_port'] : null,
                        'confidence' => $t['detection_confidence'] ?? 'medium',
                        'method' => $t['detection_method'] ?? '-',
                        'cves' => is_array($cves) ? $cves : [],
                        'cve_count' => is_array($cves) ? count($cves) : 0,
                        'last_seen' => !empty($t['last_seen_at']) ? date('M j, Y', strtotime($t['last_seen_at'])) : '-',
                        'last_seen_raw' => $t['last_seen_at'] ?? ''
                    ];
                }, $vendorTechnologies))); ?>;

                var vtPerPage = 20;
                var vtFiltered = vtDataAll.slice();
                var vtSortCol = null;
                var vtSortAsc = true;
                var vtSearchTerm = '';
                var vtCategoryFilter = '';

                // Populate category dropdown
                var categories = {};
                vtDataAll.forEach(function(r) { if (r.category) categories[r.category] = true; });
                var catSelect = document.getElementById('vtCategoryFilter');
                Object.keys(categories).sort().forEach(function(c) {
                    var opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    catSelect.appendChild(opt);
                });

                function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                var categoryColors = {
                    'web_server': '#1e40af', 'cdn_waf': '#7c3aed', 'cloud_platform': '#0891b2',
                    'framework': '#059669', 'js_library': '#d97706', 'database': '#dc2626',
                    'email_gateway': '#9333ea', 'dns_provider': '#0d9488', 'programming_language': '#4f46e5',
                    'cms': '#e11d48', 'ssl_ca': '#6b7280', 'admin_panel': '#be123c', 'other': '#6b7280'
                };
                function catBadge(cat) {
                    var color = categoryColors[cat] || '#6b7280';
                    var label = cat.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    return '<span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:' + color + '15;color:' + color + ';">' + esc(label) + '</span>';
                }
                function confBadge(conf) {
                    var colors = { high: '#166534', medium: '#92400e', low: '#991b1b' };
                    var bgs = { high: '#dcfce7', medium: '#fef3c7', low: '#fef2f2' };
                    return '<span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:' + (bgs[conf]||'#f3f4f6') + ';color:' + (colors[conf]||'#6b7280') + ';">' + esc(conf||'medium') + '</span>';
                }
                function cveBadges(cves) {
                    if (!cves || !cves.length) return '<span style="font-size:11px;color:#9ca3af;">-</span>';
                    var sevColors = { critical:'#991b1b', high:'#c2410c', medium:'#b45309', low:'#166534', unknown:'#6b7280' };
                    var sevBgs = { critical:'#fef2f2', high:'#fff7ed', medium:'#fffbeb', low:'#f0fdf4', unknown:'#f3f4f6' };
                    var html = '';
                    var shown = cves.slice(0, 3);
                    shown.forEach(function(c) {
                        var sev = c.severity || 'unknown';
                        html += '<a href="https://nvd.nist.gov/vuln/detail/' + encodeURIComponent(c.id) + '" target="_blank" rel="noopener" style="display:inline-block;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:600;margin:1px 2px;background:' + (sevBgs[sev]||'#f3f4f6') + ';color:' + (sevColors[sev]||'#6b7280') + ';text-decoration:none;" title="CVSS: ' + (c.cvss||'N/A') + ' (' + sev + ')">' + esc(c.id) + '</a>';
                    });
                    if (cves.length > 3) {
                        html += '<span style="font-size:9px;color:#6b7280;margin-left:2px;">+' + (cves.length - 3) + ' more</span>';
                    }
                    return html;
                }

                function applyVtFiltersAndSort() {
                    vtFiltered = vtDataAll.filter(function(r) {
                        if (vtSearchTerm && r.name.toLowerCase().indexOf(vtSearchTerm) === -1 && r.detected_on.toLowerCase().indexOf(vtSearchTerm) === -1) return false;
                        if (vtCategoryFilter && r.category !== vtCategoryFilter) return false;
                        return true;
                    });
                    if (vtSortCol) {
                        vtFiltered.sort(function(a, b) {
                            var va = a[vtSortCol], vb = b[vtSortCol];
                            if (vtSortCol === 'last_seen') { va = a.last_seen_raw; vb = b.last_seen_raw; }
                            if (vtSortCol === 'port') { va = va || 0; vb = vb || 0; return vtSortAsc ? va - vb : vb - va; }
                            if (va === null) va = '';
                            if (vb === null) vb = '';
                            va = String(va).toLowerCase(); vb = String(vb).toLowerCase();
                            if (va < vb) return vtSortAsc ? -1 : 1;
                            if (va > vb) return vtSortAsc ? 1 : -1;
                            return 0;
                        });
                    }
                    document.getElementById('vtCountDisplay').textContent = vtFiltered.length;
                    ['name','version','category','detected_on','port','confidence','cve_count','last_seen'].forEach(function(c) {
                        document.getElementById('vtSort_' + c).textContent = vtSortCol === c ? (vtSortAsc ? '▲' : '▼') : '';
                    });
                    renderVtPage(1);
                }

                function renderVtPage(page) {
                    var totalPages = Math.max(1, Math.ceil(vtFiltered.length / vtPerPage));
                    if (page > totalPages) page = totalPages;
                    var start = (page - 1) * vtPerPage;
                    var rows = vtFiltered.slice(start, start + vtPerPage);
                    var html = '';
                    rows.forEach(function(r) {
                        html += '<tr>'
                            + '<td style="font-weight:600;font-size:12px;">' + esc(r.name) + '</td>'
                            + '<td style="font-size:12px;font-family:monospace;">' + esc(r.version) + '</td>'
                            + '<td>' + catBadge(r.category) + '</td>'
                            + '<td style="font-size:12px;font-family:monospace;">' + esc(r.detected_on) + '</td>'
                            + '<td style="font-size:12px;">' + (r.port ? esc(String(r.port)) : '-') + '</td>'
                            + '<td>' + confBadge(r.confidence) + '</td>'
                            + '<td>' + cveBadges(r.cves) + '</td>'
                            + '<td style="font-size:12px;">' + esc(r.last_seen) + '</td>'
                            + '</tr>';
                    });
                    document.getElementById('vendorTechTbody').innerHTML = html;

                    var pag = '';
                    if (vtFiltered.length > 0) {
                        pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(start+vtPerPage, vtFiltered.length) + ' of ' + vtFiltered.length + '</span>';
                    } else {
                        pag = '<span style="font-size:12px;color:#6b7280;">0 results</span>';
                    }
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="vendorTechPage" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        var startP = Math.max(1, page - 3), endP = Math.min(totalPages, page + 3);
                        if (startP > 1) pag += '<button data-action="vendorTechPage" data-arg="1" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">1</button><span style="padding:3px 4px;color:#9ca3af;">...</span>';
                        for (var i = startP; i <= endP; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="vendorTechPage" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (endP < totalPages) pag += '<span style="padding:3px 4px;color:#9ca3af;">...</span><button data-action="vendorTechPage" data-arg="' + totalPages + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + totalPages + '</button>';
                        if (page < totalPages) pag += '<button data-action="vendorTechPage" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    document.getElementById('vendorTechPagination').innerHTML = pag;
                }

                window.vendorTechPage = renderVtPage;

                window.vtSort = function(col) {
                    if (vtSortCol === col) { vtSortAsc = !vtSortAsc; }
                    else { vtSortCol = col; vtSortAsc = (col === 'name'); }
                    applyVtFiltersAndSort();
                };

                document.getElementById('vtSearch').addEventListener('input', function() {
                    vtSearchTerm = this.value.toLowerCase().trim();
                    applyVtFiltersAndSort();
                });
                document.getElementById('vtCategoryFilter').addEventListener('change', function() {
                    vtCategoryFilter = this.value;
                    applyVtFiltersAndSort();
                });

                var vtInit = false;
                window.toggleVendorTech = function() {
                    var body = document.getElementById('vendorTechBody');
                    var arrow = document.getElementById('vendorTechArrow');
                    var showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !vtInit) { vtInit = true; applyVtFiltersAndSort(); }
                };
                window.exportVendorTechCSV = function() {
                    var data = vtFiltered.length < vtDataAll.length ? vtFiltered : vtDataAll;
                    var csv = 'Technology,Version,Category,Detected On,Port,Confidence,Method,CVEs,Last Seen\n';
                    data.forEach(function(r) {
                        var cveIds = (r.cves||[]).map(function(c){return c.id;}).join('; ');
                        csv += '"' + (r.name||'').replace(/"/g,'""') + '","' + (r.version||'').replace(/"/g,'""') + '",' + r.category + ',"' + (r.detected_on||'').replace(/"/g,'""') + '",' + (r.port||'') + ',' + r.confidence + ',' + r.method + ',"' + cveIds.replace(/"/g,'""') + '",' + r.last_seen + '\n';
                    });
                    var blob = new Blob([csv], {type: 'text/csv'});
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'vendor_technologies_<?php echo preg_replace('/[^a-zA-Z0-9_.-]/', '_', $vendor['vendor_domain'] ?? 'vendor'); ?>.csv';
                    a.click();
                };
            })();
            </script>
            <?php endif; ?>

            <?php if (!empty($shodanFindings)):
                // Check if enhanced findings exist (have category/signal_type columns populated)
                $hasEnhancedFindings = false;
                foreach ($shodanFindings as $f) {
                    if (!empty($f['category']) || !empty($f['signal_type'])) {
                        $hasEnhancedFindings = true;
                        break;
                    }
                }

                if ($hasEnhancedFindings):
                    // Group findings by category for enhanced display
                    $categoryNames = [
                        'tls_crypto' => 'TLS / Crypto',
                        'network_security' => 'Network Security',
                        'app_hardening' => 'App Hardening',
                        'email_security' => 'Email Security',
                        'vuln_exposure' => 'Vulnerability Exposure',
                    ];
                    $groupedFindings = [];
                    $legacyFindings = [];
                    foreach ($shodanFindings as $f) {
                        $cat = $f['category'] ?? null;
                        $sigType = $f['signal_type'] ?? null;
                        // Only group category signal findings, not raw port/service/vuln rows
                        if ($cat && $sigType && in_array($f['finding_type'], ['tls_crypto', 'network_security', 'app_hardening', 'email_security', 'positive_signal', 'negative_signal'])) {
                            // Skip waived signals entirely
                            $fSignal = $f['service_name'] ?? '';
                            $fSub = $f['subdomain'] ?? '';
                            $wKey = $fSignal . ':' . $fSub;
                            if (!empty($fSignal) && !empty($fSub) && isset($shodanWaiverLookup[$wKey])) {
                                continue;
                            }
                            $groupedFindings[$cat][] = $f;
                        } else {
                            $legacyFindings[] = $f;
                        }
                    }
                    // Full set of scanned hosts, used to show, per negative signal, which
                    // hosts carry it (WITH) and which were scanned but do not (WITHOUT).
                    $allScannedHosts = [];
                    foreach ($shodanSubdomains as $s) {
                        $s = trim((string)$s);
                        if ($s !== '') { $allScannedHosts[$s] = true; }
                    }
                    foreach ($vendorSubdomains as $vs) {
                        $s = trim((string)($vs['subdomain'] ?? ''));
                        if ($s !== '') { $allScannedHosts[$s] = true; }
                    }
                    $allScannedHosts = array_keys($allScannedHosts);
            ?>
            <div class="card" id="shodan-signals">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;"><?php echo e($shodanName); ?> Security Signals</h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if (!empty($shodanIPs) && !$isStakeholderView): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <button type="submit" name="rescan_all_ips"
                                    style="padding: 6px 14px; background: #0891b2; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;"
                                    data-confirm="Request Shodan to rescan <?php echo count($shodanIPs); ?> IP<?php echo count($shodanIPs) !== 1 ? 's' : ''; ?>? This uses scan credits."
                                    title="Request Shodan to rescan all <?php echo count($shodanIPs); ?> IP(s) — uses scan credits">
                                &#8635; <?php echo e(t('vendor-srs-details.request_rescan')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <button type="button" data-action="exportShodanSignals" style="padding: 6px 14px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                    </div>
                </div>
                <?php foreach ($categoryNames as $catKey => $catLabel):
                    $catFindings = $groupedFindings[$catKey] ?? [];
                    $catPositive = count(array_filter($catFindings, fn($f) => ($f['signal_type'] ?? '') === 'positive'));
                    $catNegative = count(array_filter($catFindings, fn($f) => ($f['signal_type'] ?? '') === 'negative'));
                    // Compute missing positive signals by diffing detected names against defaults
                    $detectedSignals = [];
                    foreach ($catFindings as $f) {
                        $sn = $f['service_name'] ?? '';
                        if (!empty($sn)) $detectedSignals[$sn] = true;
                    }
                    // Mutually exclusive signal groups: if any member is detected, suppress the rest
                    $exclusiveGroups = [
                        ['no_cves', 'low_cve_count'],
                        ['dmarc_reject', 'dmarc_quarantine', 'dmarc_none'],
                    ];
                    $suppressedSignals = [];
                    foreach ($exclusiveGroups as $group) {
                        foreach ($group as $sig) {
                            if (isset($detectedSignals[$sig])) {
                                foreach ($group as $other) {
                                    if ($other !== $sig) $suppressedSignals[$other] = true;
                                }
                                break;
                            }
                        }
                    }
                    $missingSignals = [];
                    $catDefaults = ShodanClient::DEFAULT_SIGNAL_POINTS[$catKey] ?? [];
                    $catLabels = ShodanClient::SIGNAL_LABELS[$catKey] ?? [];
                    foreach ($catDefaults as $sigName => $sigPts) {
                        if ($sigPts > 0 && !isset($detectedSignals[$sigName]) && !isset($suppressedSignals[$sigName])) {
                            $missingSignals[$sigName] = $catLabels[$sigName] ?? ucwords(str_replace('_', ' ', $sigName));
                        }
                    }
                    $catMissingCount = count($missingSignals);
                    // Collect unique subdomains in this category (including from proof data)
                    $catSubdomains = [];
                    foreach ($catFindings as $f) {
                        $sub = $f['subdomain'] ?? '';
                        if (!empty($sub)) $catSubdomains[] = $sub;
                        $pj = $f['proof'] ?? null;
                        $pd = $pj ? (is_array($pj) ? $pj : json_decode($pj, true)) : null;
                        if (!empty($pd['Observed Subdomains'])) {
                            foreach (explode(', ', $pd['Observed Subdomains']) as $ps) {
                                $ps = trim($ps);
                                if (!empty($ps)) $catSubdomains[] = $ps;
                            }
                        }
                    }
                    $catSubdomains = array_values(array_unique($catSubdomains));
                ?>
                <div id="cat_section_<?php echo $catKey; ?>" style="margin-bottom: 15px;">
                    <div style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f8f9fa; border-radius: 6px 6px 0 0; border-bottom: 2px solid #e5e7eb; flex-wrap: wrap;">
                        <strong style="font-size: 13px; color: #333;"><?php echo e($catLabel); ?></strong>
                        <?php if ($catPositive > 0): ?>
                        <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;"><?php echo $catPositive; ?> positive</span>
                        <?php endif; ?>
                        <?php if ($catNegative > 0): ?>
                        <span style="background: #fef2f2; color: #991b1b; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;"><?php echo $catNegative; ?> negative</span>
                        <?php endif; ?>
                        <?php if ($catMissingCount > 0): ?>
                        <span style="background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;"><?php echo $catMissingCount; ?> not detected</span>
                        <?php endif; ?>
                        <?php if (!empty($catSubdomains)): ?>
                        <span style="margin-left: auto; font-size: 10px; color: #6b7280; cursor: pointer; text-decoration: underline;" data-action="toggleCatSubdomains" data-arg="<?php echo $catKey; ?>" title="Show subdomains in this category"><?php echo count($catSubdomains); ?> subdomain<?php echo count($catSubdomains) !== 1 ? 's' : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($catSubdomains)): ?>
                    <div id="catSubs_<?php echo $catKey; ?>" style="display: none; padding: 6px 10px; background: #f0f4ff; border-bottom: 1px solid #e5e7eb; font-size: 11px;">
                        <span style="color: #6b7280; font-weight: 600;"><?php echo e(t('vendor-srs-details.subdomains_label')); ?></span>
                        <span style="font-family: monospace; color: #333;"><?php echo e(implode(', ', $catSubdomains)); ?></span>
                    </div>
                    <?php endif; ?>
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php echo e(t('vendor-srs-details.col_signal')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_finding')); ?></th>
                                <th style="width: 60px;"><?php echo e(t('vendor-srs-details.col_points')); ?></th>
                                <th style="width: 80px;"><?php echo e(t('vendor-srs-details.col_confidence')); ?></th>
                                <th style="width: 100px;"><?php echo e(t('vendor-srs-details.col_ip')); ?></th>
                                <th style="width: 130px;"><?php echo e(t('vendor-srs-details.col_subdomain')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($catFindings as $fIdx => $finding):
                                $isPositive = ($finding['signal_type'] ?? '') === 'positive';
                                $pointsVal = intval($finding['points'] ?? 0);
                                $proofJson = $finding['proof'] ?? null;
                                $proofData = null;
                                if ($proofJson) {
                                    $proofData = is_array($proofJson) ? $proofJson : json_decode($proofJson, true);
                                }
                                $hasProof = !empty($proofData) && is_array($proofData);
                                $proofId = $catKey . '_' . $fIdx;
                                $signalName = $finding['service_name'] ?? '';
                                $findingSubdomain = $finding['subdomain'] ?? '';
                                $waiverKey = $signalName . ':' . $findingSubdomain;
                                $isWaived = isset($shodanWaiverLookup[$waiverKey]);
                                $waiverInfo = $isWaived ? $shodanWaiverLookup[$waiverKey] : null;
                                $canExpand = $hasProof || (!$isPositive && !empty($signalName) && !empty($findingSubdomain));
                            ?>
                            <tr style="background: <?php echo $isWaived ? '#f9fafb' : ($isPositive ? '#fafff7' : '#fffafa'); ?>; <?php echo $canExpand ? 'cursor: pointer;' : ''; ?> <?php echo $isWaived ? 'opacity: 0.6;' : ''; ?>" <?php if ($canExpand): ?>data-action="toggleProof" data-arg="<?php echo $proofId; ?>" title="Click to view details"<?php endif; ?>>
                                <td>
                                    <?php if ($isWaived): ?>
                                    <span style="background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">W</span>
                                    <?php elseif ($isPositive): ?>
                                    <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">+</span>
                                    <?php else: ?>
                                    <span style="background: #fef2f2; color: #991b1b; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isWaived): ?><span style="text-decoration: line-through;"><?php endif; ?>
                                    <?php echo linkifyCves($finding['description'] ?? ''); ?>
                                    <?php if ($isWaived): ?></span>
                                    <span style="background: #e0e7ff; color: #3730a3; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 600; margin-left: 4px;">WAIVED</span>
                                    <?php endif; ?>
                                    <?php if ($canExpand): ?>
                                    <span style="color: #9ca3af; font-size: 10px; margin-left: 4px;" id="proofArrow_<?php echo $proofId; ?>">&#9654;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: 600; color: <?php echo $isWaived ? '#9ca3af' : ($isPositive ? '#166534' : '#991b1b'); ?>;">
                                    <?php echo $isWaived ? '<s>' . (($pointsVal > 0 ? '+' : '') . $pointsVal) . '</s>' : (($pointsVal > 0 ? '+' : '') . $pointsVal); ?>
                                </td>
                                <td>
                                    <?php
                                    $conf = $finding['confidence'] ?? 'high';
                                    $confColors = ['high' => '#166534', 'medium' => '#92400e', 'low' => '#6b7280'];
                                    $confBg = ['high' => '#dcfce7', 'medium' => '#fef3c7', 'low' => '#f3f4f6'];
                                    ?>
                                    <span style="background: <?php echo $confBg[$conf] ?? '#f3f4f6'; ?>; color: <?php echo $confColors[$conf] ?? '#6b7280'; ?>; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: 500;"><?php echo e(ucfirst($conf)); ?></span>
                                </td>
                                <td style="font-family: monospace; font-size: 11px; color: #666;">
                                    <?php echo e($finding['ip_address'] ?? '-'); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 11px; color: #666;">
                                    <?php
                                    $adoptionText = ($isPositive && $proofData && isset($proofData['Adoption'])) ? $proofData['Adoption'] : null;
                                    echo e($adoptionText ?? $finding['subdomain'] ?? '-');
                                    ?>
                                    <?php if (!empty($findingSubdomain) && !$isPositive && !$isWaived && !$isStakeholderView): ?>
                                    <form method="POST" style="display: inline;" data-stop-propagation>
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="exclude_subdomain" value="<?php echo e($findingSubdomain); ?>">
                                        <button type="submit" style="padding: 1px 5px; background: #ef4444; color: white; border: none; border-radius: 3px; font-size: 9px; cursor: pointer; vertical-align: middle; margin-left: 2px;" data-confirm="Exclude '<?php echo e($findingSubdomain); ?>' from all future Shodan scans? This is a global setting." title="Add to excluded domains list">&#10005;</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($canExpand): ?>
                            <tr id="proof_<?php echo $proofId; ?>" style="display: none;">
                                <td colspan="6" style="padding: 0;">
                                    <div style="margin: 0 12px 8px 32px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px;">
                                        <?php if ($hasProof): ?>
                                        <div style="font-weight: 600; color: #475569; margin-bottom: 6px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-srs-details.evidence_from')); ?> <?php echo e($shodanName); ?></div>
                                        <table style="width: 100%; font-size: 11px; border-collapse: collapse; margin: 0;">
                                            <?php foreach ($proofData as $proofLabel => $proofValue): ?>
                                            <tr>
                                                <td style="padding: 3px 10px 3px 0; color: #64748b; font-weight: 600; white-space: nowrap; vertical-align: top; width: 1%; border: none;"><?php echo e($proofLabel); ?></td>
                                                <td style="padding: 3px 0; font-family: monospace; color: #1e293b; word-break: break-all; border: none;"><?php echo linkifyCves($proofValue); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </table>
                                        <?php endif; ?>
                                        <?php
                                        // Per-signal host breakdown: which scanned hosts carry this
                                        // negative signal, and which were scanned but do NOT. Only
                                        // meaningful for host-specific signals (those tied to a
                                        // subdomain); host-agnostic ones (e.g. DNS-level SPF/DMARC)
                                        // are skipped.
                                        $withHosts = [];
                                        if (!empty($findingSubdomain)) { $withHosts[$findingSubdomain] = true; }
                                        if ($hasProof && !empty($proofData['Observed Subdomains'])) {
                                            foreach (explode(', ', $proofData['Observed Subdomains']) as $ps) {
                                                $ps = trim($ps);
                                                if ($ps !== '') { $withHosts[$ps] = true; }
                                            }
                                        }
                                        $withHosts = array_keys($withHosts);
                                        $withoutHosts = array_values(array_diff($allScannedHosts, $withHosts));
                                        if (!$isPositive && !empty($withHosts)):
                                        ?>
                                        <div style="margin-top: <?php echo $hasProof ? '10px; padding-top: 8px; border-top: 1px solid #e2e8f0' : '0'; ?>;">
                                            <div style="margin-bottom: 6px;">
                                                <span style="font-weight: 600; color: #991b1b; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;">Hosts with this signal (<?php echo count($withHosts); ?>)</span>
                                                <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
                                                    <?php foreach ($withHosts as $wh): ?>
                                                    <span style="background: #fef2f2; color: #991b1b; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-family: monospace;"><?php echo e($wh); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <span style="font-weight: 600; color: #166534; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;">Hosts scanned without this signal (<?php echo count($withoutHosts); ?>)</span>
                                                <?php if (!empty($withoutHosts)): ?>
                                                <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px; max-height: 96px; overflow-y: auto;">
                                                    <?php foreach ($withoutHosts as $woh): ?>
                                                    <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-family: monospace;"><?php echo e($woh); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php else: ?>
                                                <div style="margin-top: 4px; font-size: 10px; color: #9ca3af; font-style: italic;">All scanned hosts carry this signal.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php
                                        // Show rescan button if we have a valid IP address
                                        $findingIp = $finding['ip_address'] ?? '';
                                        if (!empty($findingIp) && filter_var($findingIp, FILTER_VALIDATE_IP) && !$isStakeholderView):
                                        ?>
                                        <div style="margin-top: <?php echo $hasProof ? '10px; padding-top: 8px; border-top: 1px solid #e2e8f0' : '0'; ?>;">
                                            <form method="POST" data-stop-propagation style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="rescan_ip" value="<?php echo e($findingIp); ?>">
                                                <button type="submit" style="padding: 4px 10px; background: #0891b2; color: white; border: none; border-radius: 4px; font-size: 11px; font-weight: 500; cursor: pointer;" data-confirm="Request Shodan to rescan <?php echo e($findingIp); ?>? This uses scan credits." title="Request fresh scan from Shodan">
                                                    &#8635; <?php echo e(t('vendor-srs-details.request_rescan')); ?>
                                                </button>
                                                <span style="font-size: 10px; color: #6b7280; margin-left: 6px;"><?php echo e(t('vendor-srs-details.stale_data_note')); ?></span>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($isWaived): ?>
                                        <div style="margin-top: <?php echo $hasProof ? '10px; padding-top: 8px; border-top: 1px solid #e2e8f0' : '0'; ?>;">
                                            <div style="font-weight: 600; color: #3730a3; margin-bottom: 4px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-srs-details.waiver_information')); ?></div>
                                            <div style="font-size: 11px; color: #555;">
                                                <strong><?php echo e(t('vendor-srs-details.reason_label')); ?></strong> <?php echo e($waiverInfo['reason'] ?? 'No reason provided'); ?><br>
                                                <strong><?php echo e(t('vendor-srs-details.waived_by_label')); ?></strong> <?php echo e($waiverInfo['waived_by'] ?? 'Unknown'); ?> on <?php echo e(date('M j, Y', strtotime($waiverInfo['created_at'] ?? 'now'))); ?>
                                            </div>
                                            <?php if (!$isStakeholderView): ?>
                                            <form method="POST" style="display: inline; margin-top: 6px;" data-stop-propagation class="scroll-preserve-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="waiver_id" value="<?php echo intval($waiverInfo['id']); ?>">
                                                <input type="hidden" name="_scroll" value="0">
                                                <button type="submit" name="unwaive_risk" style="margin-top: 6px; padding: 3px 10px; background: #dc2626; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;" data-confirm="Remove this waiver? The finding will be included in future scoring."><?php echo e(t('vendor-srs-details.remove_waiver')); ?></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php elseif (!$isPositive && !empty($signalName) && !empty($findingSubdomain) && !$isStakeholderView): ?>
                                        <div style="margin-top: <?php echo $hasProof ? '10px; padding-top: 8px; border-top: 1px solid #e2e8f0' : '0'; ?>;">
                                            <form method="POST" data-stop-propagation id="waiveForm_<?php echo $proofId; ?>" class="scroll-preserve-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="waive_signal" value="<?php echo e($signalName); ?>">
                                                <input type="hidden" name="waive_category" value="<?php echo e($finding['category'] ?? ''); ?>">
                                                <input type="hidden" name="waive_subdomain" value="<?php echo e($findingSubdomain); ?>">
                                                <input type="hidden" name="waive_label" value="<?php echo e($finding['description'] ?? ''); ?>">
                                                <input type="hidden" name="_scroll" value="0">
                                                <div style="display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap;">
                                                    <div style="flex: 1; min-width: 200px;">
                                                        <label style="font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo e(t('vendor-srs-details.justification_for_waiver')); ?></label>
                                                        <input type="text" name="waive_reason" required placeholder="<?php echo e(t('vendor-srs-details.waiver_placeholder')); ?>" style="width: 100%; padding: 5px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; margin-top: 2px;">
                                                    </div>
                                                    <button type="submit" name="waive_risk" style="padding: 5px 12px; background: #4f46e5; color: white; border: none; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap;" title="Waive this risk for <?php echo e($findingSubdomain); ?> only"><?php echo e(t('vendor-srs-details.waive_risk')); ?></button>
                                                </div>
                                                <div style="font-size: 10px; color: #9ca3af; margin-top: 4px;">This waiver applies only to <strong><?php echo e($findingSubdomain); ?></strong>. The finding will be excluded from future scoring for this subdomain.</div>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($missingSignals as $mSigName => $mSigLabel): ?>
                            <tr style="opacity: 0.55; background: #fafafa;">
                                <td>
                                    <span style="background: #f3f4f6; color: #9ca3af; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600;">?</span>
                                </td>
                                <td style="font-style: italic; color: #9ca3af;">
                                    <?php echo e($mSigLabel); ?> &mdash; Not Detected
                                </td>
                                <td style="text-align: center; color: #d1d5db;">&mdash;</td>
                                <td style="color: #d1d5db;">&mdash;</td>
                                <td style="color: #d1d5db;">&mdash;</td>
                                <td style="color: #d1d5db;">&mdash;</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            function scrollToCategory(catKey) {
                const el = document.getElementById('cat_section_' + catKey);
                if (!el) return;
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                el.style.transition = 'background 0.3s';
                el.style.background = '#fef9c3';
                setTimeout(function() { el.style.background = ''; }, 1200);
            }
            function toggleCatSubdomains(catKey) {
                const el = document.getElementById('catSubs_' + catKey);
                if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
            }
            function toggleProof(proofId) {
                const row = document.getElementById('proof_' + proofId);
                const arrow = document.getElementById('proofArrow_' + proofId);
                if (row) {
                    const showing = row.style.display === 'none';
                    row.style.display = showing ? '' : 'none';
                    if (arrow) arrow.innerHTML = showing ? '&#9660;' : '&#9654;';
                }
            }
            function exportShodanSignals() {
                const allFindings = <?php
                    // Filter out waived findings from export
                    $exportFindings = array_filter($shodanFindings, function($f) use ($shodanWaiverLookup) {
                        $sig = $f['service_name'] ?? '';
                        $sub = $f['subdomain'] ?? '';
                        if (!empty($sig) && !empty($sub) && isset($shodanWaiverLookup[$sig . ':' . $sub])) {
                            return false;
                        }
                        return true;
                    });
                    echo json_encode(array_values(array_map(function($f) {
                    $proofStr = '';
                    if (!empty($f['proof'])) {
                        $p = is_array($f['proof']) ? $f['proof'] : json_decode($f['proof'], true);
                        if (is_array($p)) {
                            $parts = [];
                            foreach ($p as $k => $v) { $parts[] = $k . ': ' . $v; }
                            $proofStr = implode(' | ', $parts);
                        }
                    }
                    return [
                        'category' => $f['category'] ?? '',
                        'finding_type' => $f['finding_type'] ?? '',
                        'signal_type' => $f['signal_type'] ?? '',
                        'description' => $f['description'] ?? '',
                        'points' => $f['points'] ?? '',
                        'confidence' => $f['confidence'] ?? '',
                        'ip_address' => $f['ip_address'] ?? '',
                        'subdomain' => $f['subdomain'] ?? '',
                        'port' => $f['port'] ?? '',
                        'protocol' => $f['protocol'] ?? '',
                        'service_name' => $f['service_name'] ?? '',
                        'cve_id' => $f['cve_id'] ?? '',
                        'cvss_score' => $f['cvss_score'] ?? '',
                        'severity' => $f['severity'] ?? '',
                        'proof' => $proofStr,
                    ];
                }, $exportFindings))); ?>;

                const headers = ['Category','Finding Type','Signal','Description','Points','Confidence','IP Address','Subdomain','Port','Protocol','Service','CVE','CVSS','Severity','Proof'];
                const csvRows = [headers.join(',')];
                allFindings.forEach(f => {
                    csvRows.push([
                        f.category, f.finding_type, f.signal_type,
                        '"' + (f.description || '').replace(/"/g, '""') + '"',
                        f.points, f.confidence, f.ip_address, f.subdomain,
                        f.port, f.protocol, f.service_name, f.cve_id, f.cvss_score, f.severity,
                        '"' + (f.proof || '').replace(/"/g, '""') + '"'
                    ].join(','));
                });

                const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendor['vendor_name']) . '_shodan_signals_' . date('Y-m-d') . '.csv'); ?>;
                a.click();
                URL.revokeObjectURL(url);
            }
            </script>

            <?php if (!empty($legacyFindings)): ?>
            <div class="card" id="shodan-findings">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleRawFindings">
                    <h3 style="margin: 0;"><?php echo e($shodanName); ?> Raw Findings (<?php echo count($legacyFindings); ?>)</h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <?php if (!empty($shodanIPs) && !$isStakeholderView): ?>
                        <form method="POST" style="display: inline;" data-stop-propagation>
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <button type="submit" name="rescan_all_ips"
                                    style="padding: 4px 12px; background: #0891b2; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"
                                    data-confirm="Request Shodan to rescan <?php echo count($shodanIPs); ?> IP<?php echo count($shodanIPs) !== 1 ? 's' : ''; ?>? This uses scan credits."
                                    title="Request Shodan to rescan all <?php echo count($shodanIPs); ?> IP(s) — uses scan credits">
                                &#8635; <?php echo e(t('vendor-srs-details.request_rescan')); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <button type="button" data-action="exportRawFindings" style="padding: 4px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                        <span id="rawFindingsArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                    </div>
                </div>
                <div id="rawFindingsBody" style="display: none; margin-top: 12px;">
                    <?php
                    $lowFidelityCveCount = count(array_filter($legacyFindings, function ($f) {
                        return ($f['finding_type'] ?? '') === 'vulnerability'
                            && array_key_exists('verified', $f) && $f['verified'] !== null
                            && (int)$f['verified'] === 0;
                    }));
                    if ($lowFidelityCveCount > 0):
                    ?>
                    <div style="margin-bottom: 10px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; color: #475569;">
                        <strong style="color: #334155;"><?php echo $lowFidelityCveCount; ?></strong> CVE<?php echo $lowFidelityCveCount !== 1 ? 's are' : ' is'; ?> tagged <span style="background:#f3f4f6;color:#6b7280;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:600;">LOW FIDELITY &mdash; NOT SCORED</span>: these are version/banner-inferred by <?php echo e($shodanName); ?> and not actively confirmed, so they are shown for awareness but excluded from the score.
                    </div>
                    <?php endif; ?>
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th><?php echo e(t('vendor-srs-details.col_type')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_ip_address')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_subdomain')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_port')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_details')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_cvss')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="rawFindingsTbody"></tbody>
                    </table>
                    <div id="rawFindingsPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            const isStakeholderView = <?php echo json_encode($isStakeholderView); ?>;
            function linkifyCvesJs(text) {
                if (!text) return text;
                return text.replace(/\b(CVE-\d{4}-\d{4,})\b/g, '<a href="https://nvd.nist.gov/vuln/detail/$1" target="_blank" rel="noopener" style="color:#991b1b;font-weight:600;text-decoration:none;" title="View $1 on NVD">$1</a>');
            }
            (function() {
                const rawCsrfToken = <?php echo json_encode($csrfToken); ?>;
                const rawData = <?php echo json_encode(array_values(array_map(function($f) {
                    return [
                        'type' => $f['finding_type'] ?? '',
                        'ip' => $f['ip_address'] ?? '-',
                        'subdomain' => $f['subdomain'] ?? '-',
                        'port' => $f['port'] ? ($f['port'] . ($f['protocol'] ? '/' . $f['protocol'] : '')) : '-',
                        'cve_id' => $f['cve_id'] ?? '',
                        'service_name' => $f['service_name'] ?? '',
                        'description' => $f['description'] ?? '',
                        'cvss' => $f['cvss_score'] ?? null,
                        'protocol' => $f['protocol'] ?? '',
                        // 1 = Shodan-confirmed, 0 = version-inferred (low fidelity, not
                        // scored), null = pre-fidelity data / not applicable.
                        'verified' => array_key_exists('verified', $f) && $f['verified'] !== null ? (int)$f['verified'] : null,
                    ];
                }, $legacyFindings))); ?>;
                const perPage = 10;
                let currentPage = 1;
                const totalPages = Math.ceil(rawData.length / perPage);

                function typeBadge(t) {
                    const m = {vulnerability: 'badge-critical', open_port: 'badge-medium', service: 'badge-info'};
                    const l = {vulnerability: 'CVE', open_port: 'Port', service: 'Service'};
                    return '<span class="badge ' + (m[t]||'badge-info') + '">' + (l[t]||t) + '</span>';
                }
                function cvssHtml(v) {
                    if (v === null) return '-';
                    v = parseFloat(v);
                    let c = '#6b7280';
                    if (v >= 9) c = '#991b1b'; else if (v >= 7) c = '#dc2626'; else if (v >= 4) c = '#ea580c'; else c = '#ca8a04';
                    return '<span style="font-weight:600;color:' + c + ';">' + v.toFixed(1) + '</span>';
                }
                function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                function renderRawPage(page) {
                    currentPage = page;
                    const start = (page - 1) * perPage;
                    const slice = rawData.slice(start, start + perPage);
                    let html = '';
                    slice.forEach(f => {
                        let details = '';
                        if (f.cve_id) details += '<strong>' + linkifyCvesJs(esc(f.cve_id)) + '</strong>';
                        else if (f.service_name) details += esc(f.service_name);
                        if (f.description) details += '<span style="color:#666;"> - ' + linkifyCvesJs(esc(f.description)) + '</span>';
                        if (f.type === 'vulnerability' && f.verified === 0) {
                            details += ' <span style="background:#f3f4f6;color:#6b7280;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:600;white-space:nowrap;" title="Version/banner-inferred by Shodan and not actively confirmed. Shown for awareness but excluded from the score (low fidelity).">LOW FIDELITY &mdash; NOT SCORED</span>';
                        }
                        let excludeBtn = '';
                        if (f.subdomain && f.subdomain !== '-' && !isStakeholderView) {
                            excludeBtn = ' <form method="POST" style="display:inline;" data-stop-propagation>'
                                + '<input type="hidden" name="csrf_token" value="' + esc(rawCsrfToken) + '">'
                                + '<input type="hidden" name="exclude_subdomain" value="' + esc(f.subdomain) + '">'
                                + '<button type="submit" style="padding:1px 5px;background:#ef4444;color:white;border:none;border-radius:3px;font-size:9px;cursor:pointer;vertical-align:middle;margin-left:2px;" data-confirm="Exclude \'' + esc(f.subdomain) + '\' from all future Shodan scans? This is a global setting." title="Add to excluded domains list">&#10005;</button>'
                                + '</form>';
                        }
                        html += '<tr><td>' + typeBadge(f.type) + '</td>'
                            + '<td style="font-family:monospace;font-size:12px;">' + esc(f.ip) + '</td>'
                            + '<td style="font-family:monospace;font-size:12px;">' + esc(f.subdomain) + excludeBtn + '</td>'
                            + '<td>' + esc(f.port) + '</td>'
                            + '<td style="max-width:300px;">' + details + '</td>'
                            + '<td>' + cvssHtml(f.cvss) + '</td></tr>';
                    });
                    document.getElementById('rawFindingsTbody').innerHTML = html;

                    // Pagination controls
                    let pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(start+perPage, rawData.length) + ' of ' + rawData.length + '</span>';
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="rawFindingsPage" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        for (let i = 1; i <= totalPages; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="rawFindingsPage" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (page < totalPages) pag += '<button data-action="rawFindingsPage" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    document.getElementById('rawFindingsPagination').innerHTML = pag;
                }

                window.rawFindingsPage = renderRawPage;

                window.toggleRawFindings = function() {
                    const body = document.getElementById('rawFindingsBody');
                    const arrow = document.getElementById('rawFindingsArrow');
                    const showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !document.getElementById('rawFindingsTbody').innerHTML) renderRawPage(1);
                };

                window.exportRawFindings = function() {
                    const headers = ['Type','IP Address','Subdomain','Port','CVE ID','Service','Description','CVSS'];
                    const rows = [headers.join(',')];
                    rawData.forEach(f => {
                        rows.push([f.type, f.ip, f.subdomain, f.port, f.cve_id, f.service_name,
                            '"' + (f.description||'').replace(/"/g,'""') + '"',
                            f.cvss !== null ? f.cvss : ''
                        ].join(','));
                    });
                    const blob = new Blob([rows.join('\n')], {type:'text/csv'});
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendor['vendor_name']) . '_raw_findings_' . date('Y-m-d') . '.csv'); ?>;
                    a.click();
                    URL.revokeObjectURL(a.href);
                };
            })();
            </script>
            <?php endif; ?>

            <?php else: ?>
            <!-- Backward compatible: old-style findings without enhanced columns -->
            <?php if (!empty($shodanFindings)): ?>
            <div class="card" id="shodan-findings">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleOldFindings">
                    <h3 style="margin: 0;"><?php echo e($shodanName); ?> Findings (<?php echo count($shodanFindings); ?>)</h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" data-action="exportOldFindings" style="padding: 4px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                        <span id="oldFindingsArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                    </div>
                </div>
                <div id="oldFindingsBody" style="display: none; margin-top: 12px;">
                    <table style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th><?php echo e(t('vendor-srs-details.col_type')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_ip_address')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_subdomain')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_port')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_details')); ?></th>
                                <th><?php echo e(t('vendor-srs-details.col_cvss')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="oldFindingsTbody"></tbody>
                    </table>
                    <div id="oldFindingsPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                const oldCsrfToken = <?php echo json_encode($csrfToken); ?>;
                const oldData = <?php echo json_encode(array_values(array_map(function($f) {
                    return [
                        'type' => $f['finding_type'] ?? '',
                        'ip' => $f['ip_address'] ?? '-',
                        'subdomain' => $f['subdomain'] ?? '-',
                        'port' => $f['port'] ? ($f['port'] . ($f['protocol'] ? '/' . $f['protocol'] : '')) : '-',
                        'cve_id' => $f['cve_id'] ?? '',
                        'service_name' => $f['service_name'] ?? '',
                        'description' => $f['description'] ?? '',
                        'cvss' => $f['cvss_score'] ?? null,
                    ];
                }, $shodanFindings))); ?>;
                const perPage = 10;
                let currentPage = 1;
                const totalPages = Math.ceil(oldData.length / perPage);

                function typeBadge(t) {
                    const m = {vulnerability: 'badge-critical', open_port: 'badge-medium', service: 'badge-info'};
                    const l = {vulnerability: 'CVE', open_port: 'Port', service: 'Service'};
                    return '<span class="badge ' + (m[t]||'badge-info') + '">' + (l[t]||t) + '</span>';
                }
                function cvssHtml(v) {
                    if (v === null) return '-';
                    v = parseFloat(v);
                    let c = '#6b7280';
                    if (v >= 9) c = '#991b1b'; else if (v >= 7) c = '#dc2626'; else if (v >= 4) c = '#ea580c'; else c = '#ca8a04';
                    return '<span style="font-weight:600;color:' + c + ';">' + v.toFixed(1) + '</span>';
                }
                function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                function renderOldPage(page) {
                    currentPage = page;
                    const start = (page - 1) * perPage;
                    const slice = oldData.slice(start, start + perPage);
                    let html = '';
                    slice.forEach(f => {
                        let details = '';
                        if (f.cve_id) details += '<strong>' + linkifyCvesJs(esc(f.cve_id)) + '</strong>';
                        else if (f.service_name) details += esc(f.service_name);
                        if (f.description) details += '<span style="color:#666;"> - ' + linkifyCvesJs(esc(f.description)) + '</span>';
                        if (f.type === 'vulnerability' && f.verified === 0) {
                            details += ' <span style="background:#f3f4f6;color:#6b7280;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:600;white-space:nowrap;" title="Version/banner-inferred by Shodan and not actively confirmed. Shown for awareness but excluded from the score (low fidelity).">LOW FIDELITY &mdash; NOT SCORED</span>';
                        }
                        let excludeBtn = '';
                        if (f.subdomain && f.subdomain !== '-' && !isStakeholderView) {
                            excludeBtn = ' <form method="POST" style="display:inline;" data-stop-propagation>'
                                + '<input type="hidden" name="csrf_token" value="' + esc(oldCsrfToken) + '">'
                                + '<input type="hidden" name="exclude_subdomain" value="' + esc(f.subdomain) + '">'
                                + '<button type="submit" style="padding:1px 5px;background:#ef4444;color:white;border:none;border-radius:3px;font-size:9px;cursor:pointer;vertical-align:middle;margin-left:2px;" data-confirm="Exclude \'' + esc(f.subdomain) + '\' from all future Shodan scans? This is a global setting." title="Add to excluded domains list">&#10005;</button>'
                                + '</form>';
                        }
                        html += '<tr><td>' + typeBadge(f.type) + '</td>'
                            + '<td style="font-family:monospace;font-size:12px;">' + esc(f.ip) + '</td>'
                            + '<td style="font-family:monospace;font-size:12px;">' + esc(f.subdomain) + excludeBtn + '</td>'
                            + '<td>' + esc(f.port) + '</td>'
                            + '<td style="max-width:300px;">' + details + '</td>'
                            + '<td>' + cvssHtml(f.cvss) + '</td></tr>';
                    });
                    document.getElementById('oldFindingsTbody').innerHTML = html;

                    let pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(start+perPage, oldData.length) + ' of ' + oldData.length + '</span>';
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="oldFindingsPage" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        for (let i = 1; i <= totalPages; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="oldFindingsPage" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (page < totalPages) pag += '<button data-action="oldFindingsPage" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    document.getElementById('oldFindingsPagination').innerHTML = pag;
                }

                window.oldFindingsPage = renderOldPage;

                window.toggleOldFindings = function() {
                    const body = document.getElementById('oldFindingsBody');
                    const arrow = document.getElementById('oldFindingsArrow');
                    const showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !document.getElementById('oldFindingsTbody').innerHTML) renderOldPage(1);
                };

                window.exportOldFindings = function() {
                    const headers = ['Type','IP Address','Subdomain','Port','CVE ID','Service','Description','CVSS'];
                    const rows = [headers.join(',')];
                    oldData.forEach(f => {
                        rows.push([f.type, f.ip, f.subdomain, f.port, f.cve_id, f.service_name,
                            '"' + (f.description||'').replace(/"/g,'""') + '"',
                            f.cvss !== null ? f.cvss : ''
                        ].join(','));
                    });
                    const blob = new Blob([rows.join('\n')], {type:'text/csv'});
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendor['vendor_name']) . '_findings_' . date('Y-m-d') . '.csv'); ?>;
                    a.click();
                    URL.revokeObjectURL(a.href);
                };
            })();
            </script>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (count($scoreHistory) > 1 || count($shodanScoreHistory) > 1): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" data-action="toggleScoreHistory">
                    <h3 style="margin: 0;"><?php echo e(t('vendor-srs-details.score_history')); ?></h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" data-action="exportScoreHistory" style="padding: 4px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;"><?php echo e(t('vendor-srs-details.export_csv')); ?></button>
                        <span id="scoreHistArrow" style="color: #9ca3af; font-size: 14px; transition: transform 0.2s;">&#9660;</span>
                    </div>
                </div>
                <div id="scoreHistBody" style="display: none; margin-top: 12px;">

                <?php if (count($scoreHistory) > 1 && count($shodanScoreHistory) > 1): ?>
                <div style="display: flex; gap: 8px; margin-bottom: 15px;">
                    <button type="button" data-action="toggleHistoryTab" data-arg="upguard" id="histTabUpguard" style="padding: 6px 16px; border: 1px solid #e5e7eb; border-radius: 6px; background: var(--theme-header-color); color: white; cursor: pointer; font-size: 13px; font-weight: 500;"><?php echo e($upguardName); ?></button>
                    <button type="button" data-action="toggleHistoryTab" data-arg="shodan" id="histTabShodan" style="padding: 6px 16px; border: 1px solid #e5e7eb; border-radius: 6px; background: white; color: #374151; cursor: pointer; font-size: 13px; font-weight: 500;"><?php echo e($shodanName); ?></button>
                </div>
                <?php endif; ?>

                <?php if (count($scoreHistory) > 1): ?>
                <div id="historyUpguard">
                <table style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th><?php echo e(t('vendor-srs-details.col_date')); ?></th>
                            <th><?php echo e($upguardName); ?> Score</th>
                            <th><?php echo e(t('vendor-srs-details.col_grade')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_critical')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_high')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_medium')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_low')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_info')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ugHistTbody"></tbody>
                </table>
                <div id="ugHistPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
                <?php endif; ?>

                <?php if (count($shodanScoreHistory) > 1): ?>
                <div id="historyShodan" style="<?php echo count($scoreHistory) > 1 ? 'display: none;' : ''; ?>">
                <table style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th><?php echo e(t('vendor-srs-details.col_date')); ?></th>
                            <th><?php echo e($shodanName); ?> Score</th>
                            <th><?php echo e(t('vendor-srs-details.col_grade')); ?></th>
                            <?php if ($shodanScoreHistory[0]['traffic_light'] ?? null): ?>
                            <th><?php echo e(t('vendor-srs-details.col_risk')); ?></th>
                            <?php endif; ?>
                            <th><?php echo e(t('vendor-srs-details.col_open_ports')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_vulns')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_critical')); ?></th>
                            <th><?php echo e(t('vendor-srs-details.col_high')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="shHistTbody"></tbody>
                </table>
                <div id="shHistPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 8px 0;"></div>
                </div>
                <?php endif; ?>

                </div>
            </div>
            <script nonce="<?php echo cspNonce(); ?>">
            (function() {
                const gradeClasses = {A:'grade-a',B:'grade-b',C:'grade-c',D:'grade-d',F:'grade-f'};
                const perPage = 10;
                function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
                function pagHtml(page, totalPages, fnName, total, start, end) {
                    let pag = '<span style="font-size:12px;color:#6b7280;">Showing ' + (start+1) + '-' + Math.min(end, total) + ' of ' + total + '</span>';
                    if (totalPages > 1) {
                        pag += '<span style="display:flex;gap:4px;">';
                        if (page > 1) pag += '<button data-action="' + fnName + '" data-arg="' + (page-1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">&laquo; Prev</button>';
                        for (let i = 1; i <= totalPages; i++) {
                            if (i === page) pag += '<button style="padding:3px 10px;border:1px solid var(--theme-header-color,#2563eb);border-radius:4px;background:var(--theme-header-color,#2563eb);color:white;font-size:12px;cursor:default;">' + i + '</button>';
                            else pag += '<button data-action="' + fnName + '" data-arg="' + i + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">' + i + '</button>';
                        }
                        if (page < totalPages) pag += '<button data-action="' + fnName + '" data-arg="' + (page+1) + '" style="padding:3px 10px;border:1px solid #d1d5db;border-radius:4px;background:white;cursor:pointer;font-size:12px;">Next &raquo;</button>';
                        pag += '</span>';
                    }
                    return pag;
                }

                // UpGuard history
                <?php if (count($scoreHistory) > 1): ?>
                const ugHistData = <?php echo json_encode(array_values(array_map(function($h) use ($srsService, $upguardDisplayMode, $upguardMaxScore) {
                    $grade = $srsService->calculateGrade(intval($h['score']));
                    return [
                        'date' => date('M j, Y g:i A', strtotime($h['scored_at'])),
                        'score' => displayUpguardScore(intval($h['score']), $upguardDisplayMode, $upguardMaxScore),
                        'grade' => $grade,
                        'critical' => intval($h['critical_risks'] ?? 0),
                        'high' => intval($h['high_risks']),
                        'medium' => intval($h['medium_risks']),
                        'low' => intval($h['low_risks']),
                        'info' => intval($h['info_risks']),
                        'raw_score' => intval($h['score']),
                    ];
                }, $scoreHistory))); ?>;
                const ugTotalPages = Math.ceil(ugHistData.length / perPage);
                function renderUgHist(page) {
                    const start = (page - 1) * perPage;
                    const slice = ugHistData.slice(start, start + perPage);
                    let html = '';
                    slice.forEach(h => {
                        const gc = gradeClasses[h.grade] || 'grade-f';
                        html += '<tr><td>' + esc(h.date) + '</td><td><strong>' + esc(String(h.score)) + '</strong></td>'
                            + '<td><span class="score-grade ' + gc + '" style="font-size:16px;">' + esc(h.grade) + '</span></td>'
                            + '<td>' + h.critical + '</td><td>' + h.high + '</td><td>' + h.medium + '</td><td>' + h.low + '</td><td>' + h.info + '</td></tr>';
                    });
                    document.getElementById('ugHistTbody').innerHTML = html;
                    document.getElementById('ugHistPagination').innerHTML = pagHtml(page, ugTotalPages, 'renderUgHist', ugHistData.length, start, start + perPage);
                }
                window.renderUgHist = renderUgHist;
                <?php endif; ?>

                // Shodan history
                <?php if (count($shodanScoreHistory) > 1): ?>
                const hasTL = <?php echo json_encode(!empty($shodanScoreHistory[0]['traffic_light'])); ?>;
                const shHistData = <?php echo json_encode(array_values(array_map(function($sh) use ($shodanService) {
                    $grade = $shodanService->calculateGrade(intval($sh['score']));
                    return [
                        'date' => date('M j, Y g:i A', strtotime($sh['scored_at'])),
                        'score' => intval($sh['score']),
                        'grade' => $grade,
                        'traffic_light' => $sh['traffic_light'] ?? null,
                        'open_ports' => intval($sh['open_ports_count'] ?? 0),
                        'vulns' => intval($sh['vuln_count'] ?? 0),
                        'critical' => intval($sh['critical_vulns'] ?? 0),
                        'high' => intval($sh['high_vulns'] ?? 0),
                    ];
                }, $shodanScoreHistory))); ?>;
                const shTotalPages = Math.ceil(shHistData.length / perPage);
                const tlColors = {green:'#16a34a', yellow:'#eab308', red:'#dc2626'};
                const riskLabels = {green:<?php echo json_encode(t('vendor-srs-details.tl_acceptable')); ?>, yellow:<?php echo json_encode(t('vendor-srs-details.tl_needs_improvement')); ?>, red:<?php echo json_encode(t('vendor-srs-details.tl_unacceptable')); ?>};
                function renderShHist(page) {
                    const start = (page - 1) * perPage;
                    const slice = shHistData.slice(start, start + perPage);
                    let html = '';
                    slice.forEach(h => {
                        const gc = gradeClasses[h.grade] || 'grade-f';
                        let tlHtml = '';
                        if (hasTL) {
                            if (h.traffic_light) {
                                const c = tlColors[h.traffic_light] || '#9ca3af';
                                const label = riskLabels[h.traffic_light] || h.traffic_light;
                                tlHtml = '<td><span style="display:inline-flex;align-items:center;gap:5px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + c + ';box-shadow:0 0 4px ' + c + ';"></span>' + label + '</span></td>';
                            } else tlHtml = '<td>-</td>';
                        }
                        html += '<tr><td>' + esc(h.date) + '</td><td><strong>' + h.score + '%</strong></td>'
                            + '<td><span class="score-grade ' + gc + '" style="font-size:16px;">' + esc(h.grade) + '</span></td>'
                            + tlHtml
                            + '<td>' + h.open_ports + '</td><td>' + h.vulns + '</td><td>' + h.critical + '</td><td>' + h.high + '</td></tr>';
                    });
                    document.getElementById('shHistTbody').innerHTML = html;
                    document.getElementById('shHistPagination').innerHTML = pagHtml(page, shTotalPages, 'renderShHist', shHistData.length, start, start + perPage);
                }
                window.renderShHist = renderShHist;
                <?php endif; ?>

                let histInitialized = false;
                window.toggleScoreHistory = function() {
                    const body = document.getElementById('scoreHistBody');
                    const arrow = document.getElementById('scoreHistArrow');
                    const showing = body.style.display === 'none';
                    body.style.display = showing ? '' : 'none';
                    arrow.style.transform = showing ? 'rotate(180deg)' : 'rotate(0deg)';
                    if (showing && !histInitialized) {
                        histInitialized = true;
                        <?php if (count($scoreHistory) > 1): ?>renderUgHist(1);<?php endif; ?>
                        <?php if (count($shodanScoreHistory) > 1): ?>renderShHist(1);<?php endif; ?>
                    }
                };

                window.toggleHistoryTab = function(tab) {
                    const ugDiv = document.getElementById('historyUpguard');
                    const shDiv = document.getElementById('historyShodan');
                    const ugBtn = document.getElementById('histTabUpguard');
                    const shBtn = document.getElementById('histTabShodan');
                    if (tab === 'upguard') {
                        if (ugDiv) ugDiv.style.display = '';
                        if (shDiv) shDiv.style.display = 'none';
                        if (ugBtn) { ugBtn.style.background = 'var(--theme-header-color)'; ugBtn.style.color = 'white'; }
                        if (shBtn) { shBtn.style.background = 'white'; shBtn.style.color = '#374151'; }
                    } else {
                        if (ugDiv) ugDiv.style.display = 'none';
                        if (shDiv) shDiv.style.display = '';
                        if (shBtn) { shBtn.style.background = 'var(--theme-header-color)'; shBtn.style.color = 'white'; }
                        if (ugBtn) { ugBtn.style.background = 'white'; ugBtn.style.color = '#374151'; }
                    }
                };

                window.exportScoreHistory = function() {
                    const rows = [];
                    <?php if (count($scoreHistory) > 1): ?>
                    const ugName = <?php echo json_encode($upguardName); ?>;
                    rows.push(['Provider','Date','Score','Grade','Critical','High','Medium','Low','Info'].join(','));
                    ugHistData.forEach(h => {
                        rows.push([ugName, '"' + h.date + '"', h.raw_score, h.grade, h.critical, h.high, h.medium, h.low, h.info].join(','));
                    });
                    <?php endif; ?>
                    <?php if (count($shodanScoreHistory) > 1): ?>
                    const shName = <?php echo json_encode($shodanName); ?>;
                    <?php if (count($scoreHistory) <= 1): ?>
                    rows.push(['Provider','Date','Score','Grade','Risk','Open Ports','Vulns','Critical','High'].join(','));
                    <?php else: ?>
                    rows.push('');
                    rows.push(['Provider','Date','Score','Grade','Risk','Open Ports','Vulns','Critical','High'].join(','));
                    <?php endif; ?>
                    const csvRiskLabels = {green:'Acceptable', yellow:'Needs Improvement', red:'Unacceptable'};
                    shHistData.forEach(h => {
                        rows.push([shName, '"' + h.date + '"', h.score, h.grade, csvRiskLabels[h.traffic_light] || h.traffic_light || '', h.open_ports, h.vulns, h.critical, h.high].join(','));
                    });
                    <?php endif; ?>
                    const blob = new Blob([rows.join('\n')], {type:'text/csv'});
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendor['vendor_name']) . '_score_history_' . date('Y-m-d') . '.csv'); ?>;
                    a.click();
                    URL.revokeObjectURL(a.href);
                };
            })();
            </script>
            <?php endif; ?>

            <?php if ($latestScore || $shodanLatestScore): ?>
            <script nonce="<?php echo cspNonce(); ?>">
            window.exportRawData = function() {
                function csvEsc(val) {
                    if (val === null || val === undefined) return '';
                    val = String(val);
                    if (val.indexOf(',') !== -1 || val.indexOf('"') !== -1 || val.indexOf('\n') !== -1 || val.indexOf('\r') !== -1) {
                        return '"' + val.replace(/"/g, '""') + '"';
                    }
                    return val;
                }

                var rows = [];
                var ugName = <?php echo json_encode($upguardName); ?>;
                var shName = <?php echo json_encode($shodanName); ?>;
                var vendorName = <?php echo json_encode($vendor['vendor_name']); ?>;
                var vendorDomain = <?php echo json_encode($vendor['vendor_domain'] ?? ''); ?>;

                // === VENDOR SUMMARY ===
                rows.push('=== VENDOR SUMMARY ===');
                rows.push(['Vendor','Domain','Source','Score','Grade','Scored At'].map(csvEsc).join(','));
                <?php if ($latestScore): ?>
                rows.push([vendorName, vendorDomain, ugName,
                    <?php echo json_encode((string)displayUpguardScore(intval($latestScore['score']), $upguardDisplayMode, $upguardMaxScore)); ?>,
                    <?php echo json_encode($srsService->calculateGrade(intval($latestScore['score']))); ?>,
                    <?php echo json_encode(date('M j, Y g:i A', strtotime($latestScore['scored_at']))); ?>
                ].map(csvEsc).join(','));
                <?php endif; ?>
                <?php if ($shodanLatestScore): ?>
                rows.push([vendorName, vendorDomain, shName,
                    <?php echo json_encode((string)intval($shodanLatestScore['score'])); ?>,
                    <?php echo json_encode($shodanService->calculateGrade(intval($shodanLatestScore['score']))); ?>,
                    <?php echo json_encode(date('M j, Y g:i A', strtotime($shodanLatestScore['scored_at']))); ?>
                ].map(csvEsc).join(','));
                <?php endif; ?>

                // === FINDINGS ===
                <?php
                $hasUgRisks = !empty($risks);
                $exportFindingsRaw = array_filter($shodanFindings, function($f) use ($shodanWaiverLookup) {
                    $sig = $f['service_name'] ?? '';
                    $sub = $f['subdomain'] ?? '';
                    if (!empty($sig) && !empty($sub) && isset($shodanWaiverLookup[$sig . ':' . $sub])) {
                        return false;
                    }
                    return true;
                });
                $hasShFindings = !empty($exportFindingsRaw);
                if ($hasUgRisks || $hasShFindings):
                ?>
                rows.push('');
                rows.push('=== FINDINGS ===');
                rows.push(['Source','Category','Type','Severity','Name','Description','Host','IP Address','Port','Protocol','Service','CVE ID','CVSS','Points','Confidence','Proof','First Seen'].map(csvEsc).join(','));
                var findingsSeen = {};
                <?php if ($hasUgRisks): ?>
                var ugRisks = <?php echo json_encode(array_values(array_map(function($r) {
                    return [
                        'severity' => $r['severity'] ?? '',
                        'name' => $r['risk_name'] ?? '',
                        'host' => $r['risk_host'] ?? '',
                        'category' => $r['risk_category'] ?? '',
                        'description' => $r['description'] ?? '',
                        'first_seen' => !empty($r['first_seen']) ? date('M j, Y', strtotime($r['first_seen'])) : '',
                    ];
                }, $risks))); ?>;
                ugRisks.forEach(function(r) {
                    var key = ugName + '|' + r.category + '|' + r.name + '|' + r.host + '||';
                    if (findingsSeen[key]) return;
                    findingsSeen[key] = true;
                    rows.push([ugName, r.category, 'Risk', r.severity, r.name, r.description, r.host, '', '', '', '', '', '', '', '', '', r.first_seen].map(csvEsc).join(','));
                });
                <?php endif; ?>
                <?php if ($hasShFindings): ?>
                var shFindings = <?php echo json_encode(array_values(array_map(function($f) {
                    $proofStr = '';
                    if (!empty($f['proof'])) {
                        $p = is_array($f['proof']) ? $f['proof'] : json_decode($f['proof'], true);
                        if (is_array($p)) {
                            $parts = [];
                            foreach ($p as $k => $v) { $parts[] = $k . ': ' . $v; }
                            $proofStr = implode(' | ', $parts);
                        }
                    }
                    return [
                        'category' => $f['category'] ?? '',
                        'finding_type' => $f['finding_type'] ?? '',
                        'severity' => $f['severity'] ?? '',
                        'service_name' => $f['service_name'] ?? '',
                        'description' => $f['description'] ?? '',
                        'subdomain' => $f['subdomain'] ?? '',
                        'ip_address' => $f['ip_address'] ?? '',
                        'port' => $f['port'] ?? '',
                        'protocol' => $f['protocol'] ?? '',
                        'cve_id' => $f['cve_id'] ?? '',
                        'cvss_score' => $f['cvss_score'] ?? '',
                        'points' => $f['points'] ?? '',
                        'confidence' => $f['confidence'] ?? '',
                        'proof' => $proofStr,
                    ];
                }, $exportFindingsRaw))); ?>;
                shFindings.forEach(function(f) {
                    var key = shName + '|' + f.category + '|' + f.service_name + '|' + f.subdomain + '|' + f.port + '|' + f.cve_id;
                    if (findingsSeen[key]) return;
                    findingsSeen[key] = true;
                    rows.push([shName, f.category, f.finding_type, f.severity, f.service_name, f.description, f.subdomain, f.ip_address, f.port, f.protocol, f.service_name, f.cve_id, f.cvss_score, f.points, f.confidence, f.proof, ''].map(csvEsc).join(','));
                });
                <?php endif; ?>
                <?php endif; ?>

                // === CATEGORY SCORES ===
                <?php
                $ugCatExport = [];
                if (!empty($latestScore['category_scores'])) {
                    $ugCatExport = is_string($latestScore['category_scores'])
                        ? json_decode($latestScore['category_scores'], true) ?: []
                        : $latestScore['category_scores'];
                }
                $shCatExport = [];
                if ($shodanLatestScore && !empty($shodanLatestScore['category_scores'])) {
                    $shCatExport = is_string($shodanLatestScore['category_scores'])
                        ? json_decode($shodanLatestScore['category_scores'], true) ?: []
                        : $shodanLatestScore['category_scores'];
                }
                if (!empty($ugCatExport) || !empty($shCatExport)):
                ?>
                var ugCats = <?php echo json_encode($ugCatExport); ?>;
                var shCats = <?php echo json_encode($shCatExport); ?>;
                if (Object.keys(ugCats).length || Object.keys(shCats).length) {
                    rows.push('');
                    rows.push('=== CATEGORY SCORES ===');
                    rows.push(['Source','Category','Score'].map(csvEsc).join(','));
                    Object.keys(ugCats).forEach(function(k) {
                        rows.push([ugName, k.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }), ugCats[k]].map(csvEsc).join(','));
                    });
                    Object.keys(shCats).forEach(function(k) {
                        rows.push([shName, k.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }), shCats[k]].map(csvEsc).join(','));
                    });
                }
                <?php endif; ?>

                // === SCORE HISTORY ===
                <?php if (count($scoreHistory) > 1 || count($shodanScoreHistory) > 1): ?>
                rows.push('');
                rows.push('=== SCORE HISTORY ===');
                rows.push(['Source','Date','Score','Grade','Critical','High','Medium','Low','Info','Risk Level','Open Ports','Vulns'].map(csvEsc).join(','));
                <?php if (count($scoreHistory) > 1): ?>
                var ugHist = <?php echo json_encode(array_values(array_map(function($h) use ($srsService, $upguardDisplayMode, $upguardMaxScore) {
                    return [
                        'date' => date('M j, Y g:i A', strtotime($h['scored_at'])),
                        'score' => displayUpguardScore(intval($h['score']), $upguardDisplayMode, $upguardMaxScore),
                        'grade' => $srsService->calculateGrade(intval($h['score'])),
                        'critical' => intval($h['critical_risks'] ?? 0),
                        'high' => intval($h['high_risks']),
                        'medium' => intval($h['medium_risks']),
                        'low' => intval($h['low_risks']),
                        'info' => intval($h['info_risks']),
                    ];
                }, $scoreHistory))); ?>;
                ugHist.forEach(function(h) {
                    rows.push([ugName, h.date, h.score, h.grade, h.critical, h.high, h.medium, h.low, h.info, '', '', ''].map(csvEsc).join(','));
                });
                <?php endif; ?>
                <?php if (count($shodanScoreHistory) > 1): ?>
                var csvRiskLabels = {green:'Acceptable', yellow:'Needs Improvement', red:'Unacceptable'};
                var shHist = <?php echo json_encode(array_values(array_map(function($sh) use ($shodanService) {
                    return [
                        'date' => date('M j, Y g:i A', strtotime($sh['scored_at'])),
                        'score' => intval($sh['score']),
                        'grade' => $shodanService->calculateGrade(intval($sh['score'])),
                        'traffic_light' => $sh['traffic_light'] ?? '',
                        'open_ports' => intval($sh['open_ports_count'] ?? 0),
                        'vulns' => intval($sh['vuln_count'] ?? 0),
                        'critical' => intval($sh['critical_vulns'] ?? 0),
                        'high' => intval($sh['high_vulns'] ?? 0),
                    ];
                }, $shodanScoreHistory))); ?>;
                shHist.forEach(function(h) {
                    rows.push([shName, h.date, h.score, h.grade, h.critical, h.high, '', '', '', csvRiskLabels[h.traffic_light] || h.traffic_light || '', h.open_ports, h.vulns].map(csvEsc).join(','));
                });
                <?php endif; ?>
                <?php endif; ?>

                // === DOMAINS ===
                <?php if (!empty($vendorSubdomains)): ?>
                var vdData = <?php echo json_encode(array_values(array_map(function($r) use ($srsService) {
                    $score = $r['score'] !== null ? (int)$r['score'] : null;
                    $grade = $r['score_grade'] ?? ($score !== null ? $srsService->calculateGrade($score) : '');
                    return [
                        'domain' => $r['subdomain'] ?? '',
                        'score' => $score,
                        'grade' => $grade,
                        'active' => (int)($r['is_active'] ?? 1),
                        'last_scanned' => !empty($r['last_scanned']) ? date('M j, Y', strtotime($r['last_scanned'])) : '',
                    ];
                }, $vendorSubdomains))); ?>;
                if (vdData.length) {
                    rows.push('');
                    rows.push('=== DOMAINS ===');
                    rows.push(['Source','Subdomain','Score','Grade','Status','Last Scanned'].map(csvEsc).join(','));
                    vdData.forEach(function(d) {
                        rows.push([ugName, d.domain, d.score !== null ? d.score : '', d.grade, d.active ? 'Active' : 'Inactive', d.last_scanned].map(csvEsc).join(','));
                    });
                }
                <?php endif; ?>

                // === TECHNOLOGIES ===
                <?php if (!empty($vendorTechnologies)): ?>
                var vtData = <?php echo json_encode(array_values(array_map(function($t) {
                    $cves = !empty($t['cves']) ? (is_string($t['cves']) ? json_decode($t['cves'], true) : $t['cves']) : [];
                    return [
                        'name' => $t['technology_name'],
                        'version' => $t['technology_version'] ?? '',
                        'category' => $t['technology_category'],
                        'detected_on' => $t['detected_on'] ?? '',
                        'port' => $t['detected_port'] ? (int)$t['detected_port'] : '',
                        'confidence' => $t['detection_confidence'] ?? '',
                        'method' => $t['detection_method'] ?? '',
                        'cves' => is_array($cves) ? implode('; ', $cves) : '',
                        'last_seen' => !empty($t['last_seen_at']) ? date('M j, Y', strtotime($t['last_seen_at'])) : '',
                    ];
                }, $vendorTechnologies))); ?>;
                if (vtData.length) {
                    rows.push('');
                    rows.push('=== TECHNOLOGIES ===');
                    rows.push(['Source','Technology','Version','Category','Detected On','Port','Confidence','Method','CVEs','Last Seen'].map(csvEsc).join(','));
                    vtData.forEach(function(t) {
                        rows.push([shName, t.name, t.version, t.category, t.detected_on, t.port, t.confidence, t.method, t.cves, t.last_seen].map(csvEsc).join(','));
                    });
                }
                <?php endif; ?>

                // Download CSV
                var blob = new Blob([rows.join('\n')], {type:'text/csv'});
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendor['vendor_name']) . '_raw_data_' . date('Y-m-d') . '.csv'); ?>;
                a.click();
                URL.revokeObjectURL(a.href);
            };
            </script>
            <?php endif; ?>

        <?php else: ?>
            <div class="card">
                <div class="no-data">
                    <div class="icon"><img src="app/icons/bar-chart-square-02.svg" alt="" width="32" height="32"></div>
                    <h3><?php echo e(t('vendor-srs-details.no_score_data')); ?></h3>
                    <p><?php echo e(t('vendor-srs-details.no_score_data_desc')); ?></p>
                    <?php if (empty($vendor['vendor_domain'])): ?>
                        <p style="color: #dc2626;"><?php echo e(t('vendor-srs-details.domain_required_note')); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Modals (Tier, Type, Status, Stakeholder) -->
    <?php if (!$isStakeholderView): ?>
    <?php include __DIR__ . '/includes/partials/srs-modals.php'; ?>
    <?php endif; ?>

    <?php if (count($trendData) >= 1 || count($shodanTrendData) >= 1): ?>
    <script nonce="<?php echo cspNonce(); ?>">
        // Store trend data for use after Chart.js loads
        window.trendChartData = <?php echo json_encode($trendData); ?>;
        window.shodanTrendData = <?php echo json_encode($shodanTrendData); ?>;
        window.trendChartMaxScore = <?php echo intval($upguardMaxScore); ?>;
        window.trendChartColor = <?php echo json_encode($theme['header_color']); ?>;
        window.upguardDisplayMode = <?php echo json_encode($upguardDisplayMode); ?>;
        window.upguardName = <?php echo json_encode($upguardName); ?>;
        window.shodanName = <?php echo json_encode($shodanName); ?>;

        function initTrendChart() {
            try {
                const trendData = window.trendChartData;
                const shodanData = window.shodanTrendData;
                const maxScore = window.trendChartMaxScore;
                const displayMode = window.upguardDisplayMode || 'raw';
                const isPercentage = displayMode === 'percentage';

                if ((!trendData || trendData.length === 0) && (!shodanData || shodanData.length === 0)) {
                    console.warn('No trend data available');
                    return;
                }

                const canvas = document.getElementById('trendChart');
                if (!canvas) {
                    console.error('Canvas element not found');
                    return;
                }

                if (typeof Chart === 'undefined') {
                    console.error('Chart.js not loaded');
                    document.getElementById('trendChart').parentElement.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">Chart library not available</p>';
                    return;
                }

                // Merge all dates from both datasets for labels
                const allDates = new Set();
                if (trendData) trendData.forEach(d => allDates.add(d.date));
                if (shodanData) shodanData.forEach(d => allDates.add(d.date));
                const labels = Array.from(allDates).sort();

                // Build datasets
                const datasets = [];
                const ugName = window.upguardName || 'UpGuard';
                const shName = window.shodanName || 'Shodan';

                if (trendData && trendData.length > 0) {
                    const upguardMap = {};
                    trendData.forEach(d => {
                        upguardMap[d.date] = isPercentage && maxScore > 0
                            ? Math.floor((d.score / maxScore) * 100)
                            : d.score;
                    });
                    datasets.push({
                        label: isPercentage ? ugName + ' Score (%)' : ugName + ' Score',
                        data: labels.map(d => upguardMap[d] ?? null),
                        borderColor: window.trendChartColor,
                        backgroundColor: window.trendChartColor + '20',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        borderWidth: 2,
                        spanGaps: true,
                        yAxisID: 'y'
                    });
                }

                const hasUpguard = trendData && trendData.length > 0;

                if (shodanData && shodanData.length > 0) {
                    const shodanMap = {};
                    shodanData.forEach(d => { shodanMap[d.date] = d.score; });
                    datasets.push({
                        label: shName + ' Score (%)',
                        data: labels.map(d => shodanMap[d] ?? null),
                        borderColor: '#0ea5e9',
                        backgroundColor: '#0ea5e920',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        borderWidth: 2,
                        borderDash: hasUpguard ? [5, 3] : [],
                        spanGaps: true,
                        yAxisID: hasUpguard ? 'y2' : 'y'
                    });
                }

                // Add average score line when both platforms have data
                const hasBoth = hasUpguard && shodanData && shodanData.length > 0;
                if (hasBoth) {
                    const upguardPctMap = {};
                    trendData.forEach(d => {
                        upguardPctMap[d.date] = maxScore > 0 ? Math.floor((d.score / maxScore) * 100) : 0;
                    });
                    const shodanMap = {};
                    shodanData.forEach(d => { shodanMap[d.date] = d.score; });

                    const avgData = labels.map(d => {
                        const ug = upguardPctMap[d];
                        const sh = shodanMap[d];
                        if (ug !== undefined && sh !== undefined) return Math.round((ug + sh) / 2);
                        if (ug !== undefined) return ug;
                        if (sh !== undefined) return sh;
                        return null;
                    });

                    datasets.push({
                        label: <?php echo json_encode(t('vendor-srs-details.chart_avg_score')); ?>,
                        data: avgData,
                        borderColor: '#8b5cf6',
                        backgroundColor: '#8b5cf620',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2,
                        borderDash: [2, 2],
                        spanGaps: true,
                        yAxisID: 'y2'
                    });
                }

                const ctx = canvas.getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                min: 0,
                                max: (hasUpguard ? (isPercentage ? 100 : maxScore) : 100),
                                position: 'left',
                                title: {
                                    display: true,
                                    text: hasBoth
                                        ? (isPercentage ? ugName + ' (%)' : ugName + ' Score')
                                        : (hasUpguard ? (isPercentage ? 'Score (%)' : 'Score') : shName + ' (%)')
                                }
                            },
                            y2: {
                                min: 0,
                                max: 100,
                                position: 'right',
                                display: hasBoth,
                                title: {
                                    display: hasBoth,
                                    text: shName + ' / Avg (%)'
                                },
                                grid: {
                                    drawOnChartArea: false
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: <?php echo json_encode(t('vendor-srs-details.chart_axis_date')); ?>
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: datasets.length > 1
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const color = context.dataset.borderColor;
                                        const isPercentDataset = color === '#0ea5e9' || color === '#8b5cf6';
                                        const suffix = isPercentDataset ? '%' : (isPercentage ? '%' : '');
                                        return context.dataset.label + ': ' + context.parsed.y + suffix;
                                    }
                                }
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Error creating chart:', error);
            }
        }
    </script>
    <script src="app/js/chart.min.js"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initTrendChart);
        } else {
            initTrendChart();
        }
    </script>
    <?php endif; ?>

    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <?php if ($canDelete): ?>
    <script nonce="<?php echo cspNonce(); ?>">
        function deleteVendor() {
            const vendorName = <?php echo json_encode($vendor['vendor_name'] ?? 'this vendor'); ?>;
            if (!confirm('Are you sure you want to PERMANENTLY DELETE this vendor and all associated assessments, FAIR analyses, and SRS scores? This cannot be undone!')) return;
            if (!confirm('This is your final warning. Click OK to permanently delete "' + vendorName + '" and all related data.')) return;
            document.getElementById('deleteVendorForm').submit();
        }
    </script>
    <?php endif; ?>
    <script nonce="<?php echo cspNonce(); ?>">
        function clearScoreHistory() {
            if (!confirm('Are you sure you want to clear ALL score history (UpGuard + Shodan) for this vendor? This cannot be undone.')) return;
            document.getElementById('clearScoreHistoryForm').submit();
        }
    </script>
            </main>
        </div>

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div class="footer-modern-brand" style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($theme['footer_logo_url'])): ?>
                            <a class="brand" href="index.php">
                                <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('vendor-srs-details.footer_logo_alt')); ?>" style="max-height: 45px;">
                            </a>
                        <?php endif; ?>
                        <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                    </div>
                    <p class="rights" style="margin: 0;">
                        <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                        <span class="copyright-year"><?php echo date('Y'); ?></span>
                        <span>.&nbsp;</span>
                        <span><?php echo e(t('vendor-srs-details.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

<?php if ($aiEnabled && ($latestScore || $shodanLatestScore)): ?>
<script nonce="<?php echo cspNonce(); ?>">
var execSummaryCsrf = <?php echo json_encode($csrfToken); ?>;

function generateExecSummary() {
    const btn = document.getElementById('execSummaryBtn');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<img src="app/icons/hourglass-01.svg" alt="" width="16" height="16" style="vertical-align: middle; filter: brightness(0) invert(1);"> Generating...';

    // Open window immediately (in click context) to avoid popup blocker
    const w = window.open('', '_blank');
    if (w) {
        w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Generating Executive Summary...</title>'
            + '<style>body{font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#475569;}'
            + '.loader{text-align:center;}.spinner{width:40px;height:40px;border:4px solid #e2e8f0;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 16px;}'
            + '@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style></head>'
            + '<body><div class="loader"><div class="spinner"></div><p>Generating Executive Summary...</p><p style="font-size:12px;color:#94a3b8;">This may take a moment</p></div></body></html>');
        w.document.close();
    }

    fetch('api/generate-srs-summary.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            vendor_id: <?php echo $vendorId; ?>,
            csrf_token: execSummaryCsrf
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.csrf_token) execSummaryCsrf = data.csrf_token;

        if (data.error) {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (w) w.close();
            alert('Error: ' + data.error);
            return;
        }

        if (data.queued && data.job_id) {
            var pollInterval = setInterval(function() {
                fetch('api/ai-job-status.php?id=' + data.job_id)
                .then(function(r) { return r.json(); })
                .then(function(poll) {
                    if (poll.csrf_token) execSummaryCsrf = poll.csrf_token;
                    if (poll.status === 'completed' && poll.result) {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.innerHTML = origText;
                        openExecReport(poll.result.summary, w);
                    } else if (poll.status === 'failed') {
                        clearInterval(pollInterval);
                        btn.disabled = false;
                        btn.innerHTML = origText;
                        if (w) w.close();
                        alert('Error: ' + (poll.error || 'Processing failed.'));
                    }
                })
                .catch(function() {
                    clearInterval(pollInterval);
                    btn.disabled = false;
                    btn.innerHTML = origText;
                    if (w) w.close();
                    alert('Failed to check status. Please try again.');
                });
            }, 3000);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = origText;
        if (w) w.close();
        alert('Failed to generate summary. Please try again.');
        console.error(err);
    });
}

function openExecReport(aiSummary, w) {
    // Collect page data for the report
    const vendorName = <?php echo json_encode($vendor['vendor_name'] ?? 'Unknown Vendor'); ?>;
    const vendorDomain = <?php echo json_encode($vendor['vendor_domain'] ?? 'Not specified'); ?>;
    const vendorType = <?php echo json_encode($vendor['vendor_type'] ?? 'Not specified'); ?>;
    const vendorStatus = <?php echo json_encode(ucfirst(str_replace('_', ' ', $vendor['status'] ?? 'Unknown'))); ?>;
    const vendorTier = <?php echo json_encode(!empty($vendor['vendor_tier']) ? 'Tier ' . $vendor['vendor_tier'] : 'Not tiered'); ?>;
    const reportDate = <?php echo json_encode(date('F j, Y')); ?>;
    const logoUrl = <?php echo json_encode('app/images/logo-default-418x78.png'); ?>;
    const themeHeaderColor = <?php echo json_encode($theme['header_color']); ?>;
    const themeFooterColor = <?php echo json_encode($theme['footer_color']); ?>;

    // UpGuard data
    const ugName = <?php echo json_encode($upguardName); ?>;
    <?php if ($latestScore): ?>
    const ugScore = <?php echo json_encode(displayUpguardScore(intval($latestScore['score']), $upguardDisplayMode, $upguardMaxScore)); ?>;
    const ugGrade = <?php echo json_encode($srsService->calculateGrade(intval($latestScore['score']))); ?>;
    const ugScoredAt = <?php echo json_encode(date('M j, Y g:i A', strtotime($latestScore['scored_at']))); ?>;
    const ugRisks = {
        critical: <?php echo intval($latestScore['critical_risks'] ?? 0); ?>,
        high: <?php echo intval($latestScore['high_risks'] ?? 0); ?>,
        medium: <?php echo intval($latestScore['medium_risks'] ?? 0); ?>,
        low: <?php echo intval($latestScore['low_risks'] ?? 0); ?>,
        info: <?php echo intval($latestScore['info_risks'] ?? 0); ?>
    };
    <?php
    $ugCatScores = [];
    if (!empty($latestScore['category_scores'])) {
        $ugCatScores = is_string($latestScore['category_scores'])
            ? json_decode($latestScore['category_scores'], true) ?: []
            : $latestScore['category_scores'];
    }
    ?>
    const ugCategories = <?php echo json_encode($ugCatScores); ?>;
    const hasUg = true;
    <?php else: ?>
    const hasUg = false;
    const ugScore = '', ugGrade = '', ugScoredAt = '', ugRisks = {}, ugCategories = {};
    <?php endif; ?>

    // Shodan data
    const shName = <?php echo json_encode($shodanName); ?>;
    <?php if ($shodanLatestScore): ?>
    const shScore = <?php echo intval($shodanLatestScore['score']); ?>;
    const shGrade = <?php echo json_encode($shodanService->calculateGrade(intval($shodanLatestScore['score']))); ?>;
    const shTrafficLight = <?php echo json_encode($shodanLatestScore['traffic_light'] ?? null); ?>;
    const shScoredAt = <?php echo json_encode(date('M j, Y g:i A', strtotime($shodanLatestScore['scored_at']))); ?>;
    const shPorts = <?php echo intval($shodanLatestScore['open_ports_count'] ?? 0); ?>;
    const shVulns = <?php echo intval($shodanLatestScore['vuln_count'] ?? 0); ?>;
    const shCritVulns = <?php echo intval($shodanLatestScore['critical_vulns'] ?? 0); ?>;
    const shHighVulns = <?php echo intval($shodanLatestScore['high_vulns'] ?? 0); ?>;
    <?php
    $shCatScores = [];
    if (!empty($shodanLatestScore['category_scores'])) {
        $shCatScores = is_string($shodanLatestScore['category_scores'])
            ? json_decode($shodanLatestScore['category_scores'], true) ?: []
            : $shodanLatestScore['category_scores'];
    }
    ?>
    const shCategories = <?php echo json_encode($shCatScores); ?>;
    const hasSh = true;
    <?php else: ?>
    const hasSh = false;
    const shScore = 0, shGrade = '', shTrafficLight = null, shScoredAt = '', shPorts = 0, shVulns = 0, shCritVulns = 0, shHighVulns = 0, shCategories = {};
    <?php endif; ?>

    const stakeholder = <?php echo json_encode($assignedStakeholder ? ($assignedStakeholder['full_name'] ?? $assignedStakeholder['username']) : 'Not assigned'); ?>;

    // FAIR Analysis data
    <?php if ($fairAnalysis): ?>
    const hasFair = true;
    const fairData = {
        status: <?php echo json_encode(ucfirst($fairAnalysis['status'] ?? 'Draft')); ?>,
        riskLevel: <?php echo json_encode($fairAnalysis['risk_output'] ?? '-'); ?>,
        ale: <?php echo json_encode($fairAnalysis['ale'] ?? null); ?>,
        lef: <?php echo json_encode($fairAnalysis['loss_event_frequency'] ?? null); ?>,
        plm: <?php echo json_encode($fairAnalysis['primary_loss_magnitude'] ?? null); ?>,
        slm: <?php echo json_encode($fairAnalysis['secondary_loss_magnitude'] ?? null); ?>,
        recommendedCoverage: <?php echo json_encode($fairAnalysis['recommended_liability'] ?? null); ?>,
        cyberInsurance: <?php echo json_encode($fairAnalysis['vendor_cyber_insurance_coverage'] ?? null); ?>,
        scopeOfWork: <?php echo json_encode($fairAnalysis['scope_of_work'] ?? null); ?>,
        iso27001: <?php echo $fairAnalysis['iso_27001_certified'] ? 'true' : 'false'; ?>,
        createdAt: <?php echo json_encode(date('M j, Y', strtotime($fairAnalysis['created_at']))); ?>
    };
    <?php else: ?>
    const hasFair = false;
    const fairData = {};
    <?php endif; ?>

    // Build category score bars HTML
    function catBars(cats, label, maxVal) {
        if (!cats || Object.keys(cats).length === 0) return '';
        let html = '<h4 style="font-size:12px;color:#555;margin:12px 0 8px 0;">' + esc(label) + ' Category Scores</h4>';
        html += '<table style="width:100%;font-size:11px;border-collapse:collapse;">';
        for (const [cat, val] of Object.entries(cats)) {
            const name = cat.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const pct = maxVal > 0 ? Math.min(100, (val / maxVal) * 100) : 0;
            const color = pct >= 75 ? '#16a34a' : (pct >= 50 ? '#eab308' : '#dc2626');
            html += '<tr><td style="padding:3px 8px 3px 0;width:140px;color:#555;">' + esc(name) + '</td>'
                + '<td style="padding:3px 0;"><div style="background:#f3f4f6;border-radius:4px;height:14px;position:relative;">'
                + '<div style="background:' + color + ';height:100%;border-radius:4px;width:' + pct + '%;"></div>'
                + '</div></td>'
                + '<td style="padding:3px 0 3px 8px;width:40px;text-align:right;font-weight:600;color:#333;">' + val + '</td></tr>';
        }
        html += '</table>';
        return html;
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // Build the risk counts table
    let riskCountsHtml = '';
    if (hasUg) {
        riskCountsHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;">'
            + '<tr><td style="padding:4px 0;color:#991b1b;font-weight:600;">Critical</td><td style="text-align:right;font-weight:700;">' + ugRisks.critical + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#dc2626;">High</td><td style="text-align:right;font-weight:700;">' + ugRisks.high + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#ea580c;">Medium</td><td style="text-align:right;font-weight:700;">' + ugRisks.medium + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#ca8a04;">Low</td><td style="text-align:right;font-weight:700;">' + ugRisks.low + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#6b7280;">Info</td><td style="text-align:right;font-weight:700;">' + ugRisks.info + '</td></tr>'
            + '</table>';
    }

    // Risk level HTML
    let tlHtml = '';
    if (hasSh && shTrafficLight) {
        const tlColors = {green:'#16a34a', yellow:'#eab308', red:'#dc2626'};
        const execRiskLabels = {green:<?php echo json_encode(t('vendor-srs-details.tl_acceptable')); ?>, yellow:<?php echo json_encode(t('vendor-srs-details.tl_needs_improvement')); ?>, red:<?php echo json_encode(t('vendor-srs-details.tl_unacceptable')); ?>};
        const c = tlColors[shTrafficLight] || '#9ca3af';
        const rLabel = execRiskLabels[shTrafficLight] || shTrafficLight;
        tlHtml = '<span style="display:inline-flex;align-items:center;gap:5px;margin-left:10px;">'
            + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + c + ';"></span>'
            + rLabel + '</span>';
    }

    // Build full report HTML
    let reportHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Executive Summary - ' + esc(vendorName) + '</title>'
        + '<style>'
        + '@page { size: A4; margin: 20mm 15mm 25mm 15mm; }'
        + '@media print {'
        + '  body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }'
        + '  .no-print { display: none !important; }'
        + '  .page-break { page-break-before: always; }'
        + '}'
        + 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #1e293b; line-height: 1.5; margin: 0; padding: 0; font-size: 13px; }'
        + '.report-page { max-width: 800px; margin: 0 auto; padding: 30px 40px; }'
        + '.header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1e3a5f; padding-bottom: 15px; margin-bottom: 20px; }'
        + '.header img { max-height: 50px; }'
        + '.header-right { text-align: right; font-size: 11px; color: #64748b; }'
        + '.report-title { font-size: 22px; font-weight: 700; color: #1e3a5f; margin: 0 0 4px 0; }'
        + '.report-subtitle { font-size: 14px; color: #475569; margin: 0; }'
        + '.section { margin-bottom: 18px; }'
        + '.section h3 { font-size: 15px; color: #1e3a5f; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin: 0 0 10px 0; }'
        + '.info-table { width: 100%; border-collapse: collapse; font-size: 12px; }'
        + '.info-table td { padding: 5px 10px; border: 1px solid #e2e8f0; }'
        + '.info-table .label { background: #f8fafc; font-weight: 600; color: #475569; width: 160px; }'
        + '.score-box { display: inline-block; text-align: center; padding: 12px 20px; border-radius: 8px; margin-right: 12px; }'
        + '.score-box .val { font-size: 28px; font-weight: 800; }'
        + '.score-box .lbl { font-size: 10px; color: #64748b; margin-top: 2px; }'
        + '.ai-summary h4 { font-size: 13px; color: #1e3a5f; margin: 14px 0 6px 0; }'
        + '.ai-summary p { margin: 0 0 8px 0; font-size: 12px; }'
        + '.ai-summary ul, .ai-summary ol { margin: 4px 0 8px 0; padding-left: 20px; font-size: 12px; }'
        + '.ai-summary li { margin-bottom: 3px; }'
        + '.footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 10px; color: #94a3b8; padding: 8px 0; border-top: 1px solid #e2e8f0; }'
        + '.print-bar { background: #f1f5f9; padding: 10px 40px; display: flex; gap: 10px; align-items: center; border-bottom: 1px solid #e2e8f0; }'
        + '.print-bar button { padding: 6px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; cursor: pointer; font-size: 13px; transition: background 0.2s, border-color 0.2s; }'
        + '.print-bar button:hover { background: ' + themeHeaderColor + '; color: white; border-color: ' + themeHeaderColor + '; }'
        + '.print-bar .btn-primary { background: ' + themeFooterColor + '; color: white; border-color: ' + themeFooterColor + '; }'
        + '.print-bar .btn-primary:hover { background: ' + themeHeaderColor + '; border-color: ' + themeHeaderColor + '; }'
        + '</style></head><body>';

    // Print toolbar
    reportHtml += '<div class="print-bar no-print">'
        + '<button class="btn-primary" data-print>&#128424; Print / Save PDF</button>'
        + '<button data-close-window>Close</button>'
        + '<span style="margin-left:auto;font-size:12px;color:#64748b;">Use your browser\'s Print dialog to save as PDF</span>'
        + '</div>';

    reportHtml += '<div class="report-page">';

    // Header with logo
    reportHtml += '<div class="header">'
        + '<div><img src="' + logoUrl + '" alt="Logo"><h1 class="report-title" style="margin-top:10px;">Vendor Security Assessment</h1>'
        + '<p class="report-subtitle">Executive Summary Report</p></div>'
        + '<div class="header-right"><strong>' + esc(vendorName) + '</strong><br>' + esc(vendorDomain) + '<br><br>Report Date: ' + esc(reportDate) + '</div>'
        + '</div>';

    // Vendor Information
    reportHtml += '<div class="section"><h3>Vendor Information</h3>'
        + '<table class="info-table">'
        + '<tr><td class="label">Vendor Name</td><td>' + esc(vendorName) + '</td><td class="label">Domain</td><td>' + esc(vendorDomain) + '</td></tr>'
        + '<tr><td class="label">Vendor Type</td><td>' + esc(vendorType) + '</td><td class="label">Status</td><td>' + esc(vendorStatus) + '</td></tr>'
        + '<tr><td class="label">Tier</td><td>' + esc(vendorTier) + '</td><td class="label">Stakeholder</td><td>' + esc(stakeholder) + '</td></tr>'
        + '</table></div>';

    // Score Summary
    reportHtml += '<div class="section"><h3>Score Summary</h3><div>';
    if (hasUg) {
        const gradeColors = {A:'#16a34a',B:'#22c55e',C:'#eab308',D:'#ea580c',F:'#dc2626'};
        const gc = gradeColors[ugGrade] || '#6b7280';
        reportHtml += '<div class="score-box" style="border:2px solid ' + gc + ';">'
            + '<div class="val" style="color:' + gc + ';">' + esc(ugScore) + '</div>'
            + '<div class="lbl">' + esc(ugName) + ' Score</div>'
            + '<div style="font-size:20px;font-weight:700;color:' + gc + ';margin-top:2px;">Grade ' + esc(ugGrade) + '</div>'
            + '</div>';
    }
    if (hasSh) {
        const gradeColors = {A:'#16a34a',B:'#22c55e',C:'#eab308',D:'#ea580c',F:'#dc2626'};
        const gc2 = gradeColors[shGrade] || '#6b7280';
        reportHtml += '<div class="score-box" style="border:2px solid ' + gc2 + ';">'
            + '<div class="val" style="color:' + gc2 + ';">' + shScore + '%</div>'
            + '<div class="lbl">' + esc(shName) + ' Score' + tlHtml + '</div>'
            + '<div style="font-size:20px;font-weight:700;color:' + gc2 + ';margin-top:2px;">Grade ' + esc(shGrade) + '</div>'
            + '</div>';
    }
    reportHtml += '</div>';

    // Risk counts
    if (hasUg) {
        reportHtml += '<div style="margin-top:12px;"><h4 style="font-size:12px;color:#555;margin:0 0 4px 0;">' + esc(ugName) + ' Risk Counts</h4>' + riskCountsHtml + '</div>';
    }
    if (hasSh) {
        reportHtml += '<div style="margin-top:12px;"><h4 style="font-size:12px;color:#555;margin:0 0 4px 0;">' + esc(shName) + ' Infrastructure</h4>'
            + '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            + '<tr><td style="padding:4px 0;color:#555;">Open Ports</td><td style="text-align:right;font-weight:700;">' + shPorts + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#555;">Total Vulnerabilities</td><td style="text-align:right;font-weight:700;">' + shVulns + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#991b1b;">Critical CVEs</td><td style="text-align:right;font-weight:700;">' + shCritVulns + '</td></tr>'
            + '<tr><td style="padding:4px 0;color:#dc2626;">High CVEs</td><td style="text-align:right;font-weight:700;">' + shHighVulns + '</td></tr>'
            + '</table></div>';
    }

    // Category scores
    if (hasUg) reportHtml += catBars(ugCategories, ugName, 100);
    if (hasSh) reportHtml += catBars(shCategories, shName, 100);

    reportHtml += '</div>';

    // Helper functions for FAIR formatting
    function fmtDollar(v) { if (!v) return '-'; const n = parseFloat(v); return isNaN(n) ? esc(String(v)) : '$' + n.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0}); }
    function fmtNum(v) { if (!v) return '-'; const n = parseFloat(v); return isNaN(n) ? esc(String(v)) : n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    // FAIR Analysis section
    if (hasFair) {
        const riskColors = {Critical:'#991b1b',High:'#c2410c',Medium:'#b45309',Low:'#166534'};
        const riskBgs = {Critical:'#fef2f2',High:'#fff7ed',Medium:'#fffbeb',Low:'#f0fdf4'};
        const rl = fairData.riskLevel || '-';
        const rlc = riskColors[rl] || '#6b7280';
        const rlb = riskBgs[rl] || '#f3f4f6';

        reportHtml += '<div class="section"><h3>FAIR Analysis</h3>';

        // Scope of Work
        if (fairData.scopeOfWork) {
            reportHtml += '<div style="margin-bottom:14px;"><h4 style="font-size:12px;color:#555;margin:0 0 6px 0;">Scope of Work</h4>'
                + '<p style="font-size:12px;margin:0;color:#374151;">' + esc(fairData.scopeOfWork) + '</p></div>';
        }

        // FAIR metrics table
        reportHtml += '<table class="info-table">'
            + '<tr><td class="label">Risk Level</td><td><span style="display:inline-block;padding:2px 10px;border-radius:4px;background:' + rlb + ';color:' + rlc + ';font-weight:600;font-size:12px;">' + esc(rl) + '</span></td>'
            + '<td class="label">Status</td><td>' + esc(fairData.status || '-') + '</td></tr>'
            + '<tr><td class="label">Annual Loss Expectancy (ALE)</td><td><strong>' + fmtDollar(fairData.ale) + '</strong></td>'
            + '<td class="label">Loss Event Frequency (LEF)</td><td>' + fmtNum(fairData.lef) + '</td></tr>'
            + '<tr><td class="label">Primary Loss Magnitude (PLM)</td><td>' + fmtDollar(fairData.plm) + '</td>'
            + '<td class="label">Secondary Loss Magnitude (SLM)</td><td>' + fmtDollar(fairData.slm) + '</td></tr>'
            + '<tr><td class="label">Recommended Cyber Insurance</td><td><strong>' + fmtDollar(fairData.recommendedCoverage) + '</strong></td>'
            + '<td class="label">Current Vendor Coverage</td><td>' + fmtDollar(fairData.cyberInsurance) + '</td></tr>'
            + '</table></div>';
    }

    // AI-generated executive summary
    reportHtml += '<div class="section page-break">'
        + '<div class="ai-summary">' + aiSummary + '</div></div>';

    // Footer with page numbers (CSS counter)
    reportHtml += '</div>';
    reportHtml += '<style>'
        + 'body { counter-reset: page; }'
        + '@media print { @page { @bottom-center { content: "Page " counter(page); } } }'
        + '.page-number { display: none; }'
        + '@media print { .page-number { display: block; position: fixed; bottom: 10px; right: 15mm; font-size: 10px; color: #94a3b8; } }'
        + '</style>';
    reportHtml += '<div class="page-number"></div>';
    reportHtml += '<div class="footer no-print" style="position:fixed;bottom:0;left:0;right:0;text-align:center;padding:8px;background:white;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">Confidential - ' + esc(vendorName) + ' Security Assessment - ' + esc(reportDate) + '</div>';
    reportHtml += '</body></html>';

    // Write report to the target window, then attach event listeners
    // directly from the parent context (inline scripts are blocked by CSP)
    function writeReportAndBind(targetWindow) {
        targetWindow.document.open();
        targetWindow.document.write(reportHtml);
        targetWindow.document.close();
        var printBtn = targetWindow.document.querySelector('[data-print]');
        if (printBtn) printBtn.addEventListener('click', function() { targetWindow.print(); });
        var closeBtn = targetWindow.document.querySelector('[data-close-window]');
        if (closeBtn) closeBtn.addEventListener('click', function() { targetWindow.close(); });
    }

    if (!w || w.closed) {
        var w2 = window.open('', '_blank');
        if (!w2) { alert('Please allow popups for this site to view the report.'); return; }
        writeReportAndBind(w2);
    } else {
        writeReportAndBind(w);
    }
}
</script>
<?php endif; ?>
<script src="app/js/mobile-nav.js"></script>
<script nonce="<?php echo cspNonce(); ?>">
function scrollToSection(sectionId) {
    var el = document.getElementById(sectionId);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.style.transition = 'box-shadow 0.3s';
    el.style.boxShadow = '0 0 0 3px #fbbf24';
    setTimeout(function() { el.style.boxShadow = ''; }, 1200);
}
</script>
<script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
<script nonce="<?php echo cspNonce(); ?>">
document.querySelectorAll('.scroll-preserve-form').forEach(function(form) {
    form.addEventListener('submit', function() {
        var scrollInput = form.querySelector('input[name="_scroll"]');
        if (scrollInput) scrollInput.value = window.scrollY;
    });
});
</script>
<?php if ($restoreScroll > 0): ?>
<script nonce="<?php echo cspNonce(); ?>">window.scrollTo(0, <?php echo intval($restoreScroll); ?>);</script>
<?php endif; ?>
<?php if ($isRescoring): ?>
<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var vendorId = <?php echo $vendorId; ?>;
    var banner = document.getElementById('rescoreStatusBanner');

    // Poll for rescore completion every 3 seconds.
    // The cron/rescore-queue.php worker processes the job in the background.
    var pollInterval = setInterval(function() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'api-rescore-status.php?id=' + vendorId, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (!data.status) {
                        clearInterval(pollInterval);
                        window.location.href = 'vendor-srs-details.php?id=' + vendorId;
                    } else if (data.status && data.status.indexOf('processing') === 0 && banner) {
                        var span = banner.querySelector('span:last-child');
                        if (span && span.textContent.indexOf('Scoring in progress') === -1) {
                            span.textContent = span.textContent.replace('Waiting for background processor', 'Scoring in progress');
                        }
                        var cancelBtn = document.getElementById('cancelRescoreBtn');
                        if (cancelBtn) cancelBtn.closest('form').style.display = 'none';
                    }
                } catch (e) {}
            }
        };
        xhr.send();
    }, 3000);
})();
</script>
<?php endif; ?>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
