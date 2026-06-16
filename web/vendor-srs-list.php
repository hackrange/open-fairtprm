<?php
/**
 * SRS Vendor List - The Big Board of Vendor Security Scores
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Think of this as the "report card day" page for all your third-party vendors.
 * It pulls every vendor that has a domain on file, checks their UpGuard security
 * scores, slaps a letter grade on them (A through F, just like school), and lays
 * it all out in a sortable, filterable, paginated table. You can rescore vendors
 * on the fly, export the whole mess to CSV, or drill into individual details.
 * Basically the TPRM team's daily driver -- if this page is down, someone's
 * having a very bad Monday.
 */

// Boot up the app framework and make sure the user is actually logged in
// (no freeloaders allowed past this point)
require_once 'includes/init.php';
requireAuth();

// Grab all the singletons we'll need -- the usual suspects
$auth = Auth::getInstance();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();

// Bouncer check: only cyber_tprm folks, admins, and auditors get past the velvet rope
$isAdmin = $acl->hasGroup('administrator');
$isCyberTPRM = $acl->hasGroup('cyber_tprm');
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die('Access denied. You do not have permission to view SRS scoring.');
}

// Fire up the SRS (Security Rating Service) -- our connection to the UpGuard API
// If the API key isn't configured, we'll still render the page but with a warning banner
require_once __DIR__ . '/includes/classes/SRSService.php';
require_once __DIR__ . '/includes/classes/ShodanService.php';
$srsService = new SRSService();
$shodanService = new ShodanService();
$srsAvailable = $srsService->isAvailable();
$shodanAvailable = $shodanService->isAvailable();

// Check if cron-based rescoring is enabled
$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'upguard_use_cron'");
$upguardUseCron = ($_cronRow && $_cronRow['config_value'] === '1');
$_cronRow = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'shodan_use_cron'");
$shodanUseCron = ($_cronRow && $_cronRow['config_value'] === '1');

// Check if Shodan DB columns exist (migration may not have been applied yet)
$shodanColumnsExist = false;
try {
    $colCheck = $db->fetchOne(
        "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_onboarding_requests' AND COLUMN_NAME = 'current_shodan_score'"
    );
    $shodanColumnsExist = !empty($colCheck);
} catch (Exception $e) {
    $shodanColumnsExist = false;
}

// Check if enhanced Shodan columns exist (traffic_light in vendor_shodan_scores)
$shodanEnhancedExist = false;
try {
    $enhCheck = $db->fetchOne(
        "SELECT 1 AS ok FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_shodan_scores' AND COLUMN_NAME = 'traffic_light'"
    );
    $shodanEnhancedExist = !empty($enhCheck);
} catch (Exception $e) {
    $shodanEnhancedExist = false;
}

$error = '';
$success = '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['rescore_vendor_id'])) {
        // Individual vendor rescore (supports provider selection)
        $vendorId = intval($_POST['rescore_vendor_id']);
        $provider = $_POST['rescore_provider'] ?? 'all';
        $useUpguard = in_array($provider, ['all', 'upguard']) && $srsAvailable;
        $useShodan = in_array($provider, ['all', 'shodan']) && $shodanAvailable;

        if (!$useUpguard && !$useShodan) {
            $error = 'Selected scoring provider is not configured or available.';
        } else {
            $vendor = $db->fetchOne(
                'SELECT id, vendor_name, vendor_domain, status FROM vendor_onboarding_requests WHERE id = :id',
                [':id' => $vendorId]
            );

            if ($vendor && ($vendor['status'] ?? '') === 'inactive') {
                $error = 'Cannot score an inactive vendor. Re-activate the vendor first.';
            } elseif ($vendor && !empty($vendor['vendor_domain'])) {
                // Determine if cron mode applies
                $useCron = false;
                if ($provider === 'upguard') $useCron = $upguardUseCron;
                elseif ($provider === 'shodan') $useCron = $shodanUseCron;
                else $useCron = ($upguardUseCron || $shodanUseCron);

                if ($useCron) {
                    // CRON MODE: Queue the job — cron/rescore-queue.php picks it up
                    $statusLabel = $provider === 'all' ? 'rescoring' : 'rescoring_' . $provider;
                    $db->query(
                        'UPDATE vendor_onboarding_requests SET rescore_status = ?, rescore_started_at = NOW(), rescore_result = NULL WHERE id = ?',
                        [$statusLabel, $vendorId]
                    );
                    $success = "Scoring queued for '{$vendor['vendor_name']}'. The background processor will handle it shortly.";
                } else {
                    // REALTIME MODE: Score synchronously
                    $messages = [];
                    $errors = [];

                    if ($useUpguard) {
                        $result = $srsService->scoreVendor($vendorId, $vendor['vendor_domain'], $vendor['vendor_name']);
                        if ($result) {
                            $messages[] = "UpGuard: {$result['score']} ({$result['grade']})";
                        } else {
                            $errors[] = 'UpGuard: ' . ($srsService->getLastError() ?? 'Unknown error');
                        }
                    }

                    if ($useShodan) {
                        try {
                            $shodanResult = $shodanService->scoreVendor($vendorId, $vendor['vendor_domain']);
                            if ($shodanResult) {
                                $messages[] = "Shodan: {$shodanResult['score']} ({$shodanResult['grade']})";
                            } else {
                                $errors[] = 'Shodan: ' . ($shodanService->getLastError() ?? 'Unknown error');
                            }
                        } catch (Exception $e) {
                            $errors[] = 'Shodan: ' . $e->getMessage();
                        }
                    }

                    // Refresh favicon while we're at it
                    try {
                        require_once __DIR__ . '/includes/classes/FaviconService.php';
                        $faviconService = new FaviconService();
                        $favicon = $faviconService->fetchFavicon($vendor['vendor_domain']);
                        if ($favicon) {
                            $db->query(
                                'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                                [$favicon['data'], $favicon['mime'], $vendorId]
                            );
                        }
                    } catch (Exception $e) {
                        // Non-critical -- don't report favicon failures to the user
                    }

                    // If any provider scored successfully, ensure last_srs_score_at is set
                    // (ShodanService doesn't update this field, causing perpetual "Needs Rescore")
                    if (!empty($messages)) {
                        $db->query(
                            'UPDATE vendor_onboarding_requests SET last_srs_score_at = NOW() WHERE id = ?',
                            [$vendorId]
                        );
                        $success = "Vendor '{$vendor['vendor_name']}' scored: " . implode(' | ', $messages);
                    }
                    if (!empty($errors)) {
                        $error = 'Some scoring failed: ' . implode(' | ', $errors);
                    }
                }
            } else {
                $error = 'Vendor not found or has no domain configured.';
            }
        }
    } elseif (isset($_POST['score_selected'])) {
        // Score selected vendors (checkbox bulk action)
        $selectedIds = array_filter(array_map('intval', explode(',', $_POST['selected_vendor_ids'] ?? '')));
        if (empty($selectedIds)) {
            $error = 'No vendors selected for scoring.';
        } else {
            $placeholders = implode(',', $selectedIds);
            $vendors = $db->fetchAll(
                "SELECT id, vendor_name, vendor_domain FROM vendor_onboarding_requests WHERE id IN ($placeholders) AND vendor_domain IS NOT NULL AND vendor_domain != '' AND status != 'inactive'"
            );

            if (empty($vendors)) {
                $error = 'No valid vendors found with domains configured.';
            } else {
                $useCronSelected = ($upguardUseCron || $shodanUseCron);

                if ($useCronSelected) {
                    $queued = 0;
                    foreach ($vendors as $v) {
                        $db->query(
                            'UPDATE vendor_onboarding_requests SET rescore_status = ?, rescore_started_at = NOW(), rescore_result = NULL WHERE id = ? AND rescore_status IS NULL',
                            ['rescoring', $v['id']]
                        );
                        $queued++;
                    }
                    $success = "Scoring queued for {$queued} selected vendor(s). The background processor will handle them shortly.";
                } else {
                    $scored = 0;
                    $failed = 0;
                    require_once __DIR__ . '/includes/classes/FaviconService.php';
                    $faviconService = new FaviconService();

                    foreach ($vendors as $v) {
                        if ($srsAvailable) {
                            try {
                                $result = $srsService->scoreVendor($v['id'], $v['vendor_domain'], $v['vendor_name']);
                                if ($result) $scored++; else $failed++;
                            } catch (Exception $e) {
                                $failed++;
                            }
                        }
                        if ($shodanAvailable) {
                            try {
                                $sResult = $shodanService->scoreVendor($v['id'], $v['vendor_domain']);
                                if ($sResult && !$srsAvailable) $scored++;
                                elseif (!$sResult && !$srsAvailable) $failed++;
                            } catch (Exception $e) {
                                if (!$srsAvailable) $failed++;
                            }
                        }
                        try {
                            $favicon = $faviconService->fetchFavicon($v['vendor_domain']);
                            if ($favicon) {
                                $db->query(
                                    'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                                    [$favicon['data'], $favicon['mime'], $v['id']]
                                );
                            }
                        } catch (Exception $e) {}
                        $db->query(
                            'UPDATE vendor_onboarding_requests SET last_srs_score_at = COALESCE(last_srs_score_at, NOW()) WHERE id = ? AND (current_srs_score IS NOT NULL OR current_shodan_score IS NOT NULL)',
                            [$v['id']]
                        );
                    }
                    $success = "Selected vendor scoring complete: {$scored} scored, {$failed} failed out of " . count($vendors) . " vendors.";
                }
            }
        }
    } elseif (isset($_POST['retier_selected'])) {
        // Re-tier selected vendors (bulk tier change)
        $selectedIds = array_filter(array_map('intval', explode(',', $_POST['retier_vendor_ids'] ?? '')));
        $newTier = $_POST['new_tier'] ?? '';
        $justification = trim($_POST['tier_justification'] ?? '');

        if (empty($selectedIds)) {
            $error = 'No vendors selected for re-tiering.';
        } elseif (!in_array($newTier, ['1', '2', '3'])) {
            $error = 'Invalid tier selected. Please choose Tier 1, 2, or 3.';
        } elseif (empty($justification)) {
            $error = 'A justification is required for tier changes.';
        } else {
            $placeholders = implode(',', $selectedIds);
            $vendors = $db->fetchAll(
                "SELECT id, vendor_name, vendor_tier FROM vendor_onboarding_requests WHERE id IN ($placeholders)"
            );

            if (empty($vendors)) {
                $error = 'No valid vendors found.';
            } else {
                $updated = 0;
                foreach ($vendors as $v) {
                    $oldTier = $v['vendor_tier'];
                    if ($oldTier === $newTier) continue;

                    $db->query(
                        'UPDATE vendor_onboarding_requests SET vendor_tier = ? WHERE id = ?',
                        [$newTier, $v['id']]
                    );

                    $auth->audit($user['id'], 'vendor_retier', 'vendor_onboarding_requests', $v['id'], [
                        'old' => ['vendor_tier' => $oldTier],
                        'new' => ['vendor_tier' => $newTier],
                        'justification' => $justification
                    ]);

                    $updated++;
                }

                $tierNames = ['1' => 'Tier 1', '2' => 'Tier 2', '3' => 'Tier 3'];
                $success = "{$updated} vendor(s) updated to {$tierNames[$newTier]}.";
            }
        }
    } elseif (isset($_POST['add_case']) && ($isAdmin || $isCyberTPRM)) {
        // Add a case note for a vendor from the SRS list
        $caseVendorId = intval($_POST['case_vendor_id'] ?? 0);
        $caseTitle = trim($security->cleanInput($_POST['case_title'] ?? ''));
        $caseDescription = trim($security->cleanInput($_POST['case_description'] ?? ''));
        $caseDueDate = trim($security->cleanInput($_POST['case_due_date'] ?? ''));
        $caseAssignedTo = intval($_POST['case_assigned_to'] ?? 0);

        if ($caseVendorId <= 0) {
            $error = 'Invalid vendor.';
        } elseif (empty($caseTitle)) {
            $error = 'Case title is required.';
        } else {
            // Validate the vendor exists
            $caseVendor = $db->fetchOne(
                'SELECT id, vendor_name FROM vendor_onboarding_requests WHERE id = :id',
                [':id' => $caseVendorId]
            );
            if (!$caseVendor) {
                $error = 'Vendor not found.';
            } else {
                // Validate assignee belongs to an allowed group
                $allowedCaseGroups = 'cyber_tprm,administrator';
                if ($caseAssignedTo > 0) {
                    $validAssignee = false;
                    try {
                        $assigneeGroups = $acl->getUserGroups($caseAssignedTo);
                        $allowedList = array_filter(array_map('trim', explode(',', $allowedCaseGroups)));
                        foreach ($assigneeGroups as $ag) {
                            $groupName = $ag['group_name'] ?? ($ag['name'] ?? '');
                            if (in_array($groupName, $allowedList)) {
                                $validAssignee = true;
                                break;
                            }
                        }
                    } catch (Exception $e) {}
                    if (!$validAssignee) {
                        $caseAssignedTo = 0;
                    }
                }

                try {
                    $insertData = [
                        'todo_type' => 'custom',
                        'reference_type' => 'vendor_onboarding_requests',
                        'reference_id' => $caseVendorId,
                        'activity_type' => 'note',
                        'title' => $caseTitle,
                        'description' => $caseDescription,
                        'status' => 'open',
                        'created_by' => $user['id']
                    ];
                    if (!empty($caseDueDate)) {
                        $insertData['due_date'] = $caseDueDate;
                    }
                    if ($caseAssignedTo > 0) {
                        $insertData['assigned_to'] = $caseAssignedTo;
                    }
                    $db->insert('cyber_todo_activities', $insertData);
                    $auth->audit($user['id'], 'case_add_note', 'cyber_todo_activities', $caseVendorId, [
                        'new' => ['title' => $caseTitle, 'assigned_to' => $caseAssignedTo ?: null, 'vendor_request_id' => $caseVendorId]
                    ]);
                    $success = "Case created for '" . $caseVendor['vendor_name'] . "'.";
                } catch (Exception $e) {
                    error_log('Add case error (SRS list): ' . $e->getMessage());
                    $error = 'Failed to create case. Please try again.';
                }
            }
        }
    } elseif ((isset($_POST['create_ai_risk']) || isset($_POST['update_ai_risk'])) && ($isAdmin || $isCyberTPRM || $acl->hasGroup('cyber_grc'))) {
        // Create or update the "Uses AI" risk register entry for a vendor
        $aiVid = intval($_POST['ai_risk_vendor_id'] ?? 0);
        $vRow = $aiVid > 0 ? $db->fetchOne('SELECT id, vendor_name FROM vendor_onboarding_requests WHERE id = :id', [':id' => $aiVid]) : null;
        if (!$vRow) {
            $error = 'Invalid vendor.';
        } else {
            $aiTitle = trim($security->cleanInput($_POST['ai_risk_title'] ?? ''));
            if ($aiTitle === '') {
                $aiTitle = $vRow['vendor_name'] . ' - Uses AI in their services';
            }

            // Resolve owner: explicit id preferred, fall back to name lookup limited to cyber_grc/cyber_tprm
            $aiOwnerId = intval($_POST['ai_risk_owner_id'] ?? 0);
            if ($aiOwnerId <= 0 && !empty($_POST['ai_risk_owner_name'])) {
                $ownerNameQuery = trim($security->cleanInput($_POST['ai_risk_owner_name']));
                if ($ownerNameQuery !== '') {
                    $ownerRow = $db->fetchOne(
                        "SELECT DISTINCT u.id FROM users u
                         JOIN user_acl_groups uag ON uag.user_id = u.id
                         JOIN acl_groups ag ON ag.id = uag.group_id
                         WHERE u.is_active = 1 AND u.full_name = :n
                           AND ag.group_name IN ('cyber_grc','cyber_tprm')
                         LIMIT 1",
                        [':n' => $ownerNameQuery]
                    );
                    if ($ownerRow) $aiOwnerId = (int)$ownerRow['id'];
                }
            }
            if ($aiOwnerId > 0) {
                $ownerChk = $db->fetchOne(
                    "SELECT 1 FROM user_acl_groups uag
                     JOIN acl_groups ag ON ag.id = uag.group_id
                     WHERE uag.user_id = :uid AND ag.group_name IN ('cyber_grc','cyber_tprm')
                     LIMIT 1",
                    [':uid' => $aiOwnerId]
                );
                if (!$ownerChk) $aiOwnerId = 0;
            }

            $likelihoodNumeric = ['rare'=>1,'unlikely'=>2,'possible'=>3,'likely'=>4,'almost_certain'=>5];
            $impactNumeric = ['insignificant'=>1,'minor'=>2,'moderate'=>3,'major'=>4,'catastrophic'=>5];
            $aiLikelihood = $_POST['ai_risk_likelihood'] ?? 'possible';
            $aiImpact = $_POST['ai_risk_impact'] ?? 'moderate';
            if (!isset($likelihoodNumeric[$aiLikelihood])) $aiLikelihood = 'possible';
            if (!isset($impactNumeric[$aiImpact])) $aiImpact = 'moderate';
            $aiInherent = $likelihoodNumeric[$aiLikelihood] * $impactNumeric[$aiImpact];
            $aiResidual = (isset($_POST['ai_risk_residual']) && $_POST['ai_risk_residual'] !== '')
                ? (float)$_POST['ai_risk_residual'] : $aiInherent;

            $allowedTreatment = ['accept','mitigate','transfer','avoid'];
            $aiTreatment = $_POST['ai_risk_treatment'] ?? 'mitigate';
            if (!in_array($aiTreatment, $allowedTreatment, true)) $aiTreatment = 'mitigate';

            $allowedCats = ['strategic','operational','financial','compliance','reputational','technology','third_party'];
            $aiCategory = $_POST['ai_risk_category'] ?? 'technology';
            if (!in_array($aiCategory, $allowedCats, true)) $aiCategory = 'technology';

            $allowedStatus = ['identified','assessing','treating','monitoring','closed'];
            $aiStatus = $_POST['ai_risk_status'] ?? 'identified';
            if (!in_array($aiStatus, $allowedStatus, true)) $aiStatus = 'identified';

            $aiControlIdStr = null;
            if (!empty($_POST['ai_risk_control_ids'])) {
                $rawIds = is_array($_POST['ai_risk_control_ids'])
                    ? $_POST['ai_risk_control_ids']
                    : explode(',', $_POST['ai_risk_control_ids']);
                $cleanIds = array_values(array_filter(array_map('intval', $rawIds)));
                if (!empty($cleanIds)) $aiControlIdStr = implode(',', $cleanIds);
            }

            $aiData = [
                'title' => $aiTitle,
                'description' => trim($security->cleanInput($_POST['ai_risk_description'] ?? '')) ?: null,
                'risk_category' => $aiCategory,
                'likelihood' => $aiLikelihood,
                'impact' => $aiImpact,
                'inherent_risk_score' => $aiInherent,
                'residual_risk_score' => $aiResidual,
                'risk_treatment' => $aiTreatment,
                'treatment_plan' => trim($security->cleanInput($_POST['ai_risk_treatment_plan'] ?? '')) ?: null,
                'owner_user_id' => $aiOwnerId > 0 ? $aiOwnerId : null,
                'control_ids' => $aiControlIdStr,
                'status' => $aiStatus,
                'review_date' => !empty($_POST['ai_risk_review_date']) ? $_POST['ai_risk_review_date'] : null,
                'vendor_id' => $aiVid,
            ];

            // Look up existing AI risk for this vendor (link by vendor_id or fall back to title pattern)
            $existingAiRisk = $db->fetchOne(
                "SELECT id FROM grc_risk_register
                 WHERE (vendor_id = :vid OR title LIKE :pat)
                 ORDER BY (vendor_id = :vid_order) DESC
                 LIMIT 1",
                [
                    ':vid' => $aiVid,
                    ':vid_order' => $aiVid,
                    ':pat' => $vRow['vendor_name'] . ' - Uses AI in their services',
                ]
            );

            try {
                if ($existingAiRisk) {
                    $db->update('grc_risk_register', $aiData, 'id = :id', [':id' => (int)$existingAiRisk['id']]);
                    $auth->audit($user['id'], 'ai_risk_update', 'grc_risk_register', (int)$existingAiRisk['id'], [
                        'new' => ['vendor_id' => $aiVid, 'title' => $aiTitle]
                    ]);
                    $success = "AI risk updated for '" . $vRow['vendor_name'] . "'.";
                } else {
                    $lastRef = $db->fetchOne("SELECT risk_ref FROM grc_risk_register WHERE risk_ref LIKE 'RSK-%' ORDER BY id DESC LIMIT 1");
                    $newRef = $lastRef
                        ? 'RSK-' . str_pad((int)substr($lastRef['risk_ref'], 4) + 1, 3, '0', STR_PAD_LEFT)
                        : 'RSK-001';
                    $aiData['risk_ref'] = $newRef;
                    $aiData['created_by'] = (int)$user['id'];
                    $newId = $db->insert('grc_risk_register', $aiData);
                    $auth->audit($user['id'], 'ai_risk_create', 'grc_risk_register', (int)$newId, [
                        'new' => ['vendor_id' => $aiVid, 'title' => $aiTitle, 'risk_ref' => $newRef]
                    ]);
                    $success = "AI risk created for '" . $vRow['vendor_name'] . "' (" . $newRef . ").";
                }
            } catch (Exception $e) {
                error_log('AI risk save error: ' . $e->getMessage());
                $error = 'Failed to save AI risk. Please try again.';
            }
        }
    } elseif (isset($_POST['eval_selected'])) {
        // Mark selected vendors as Evaluation status
        $selectedIds = array_filter(array_map('intval', explode(',', $_POST['eval_vendor_ids'] ?? '')));
        if (empty($selectedIds)) {
            $error = 'No vendors selected.';
        } else {
            $placeholders = implode(',', $selectedIds);
            $vendors = $db->fetchAll(
                "SELECT id, vendor_name, vendor_id, status FROM vendor_onboarding_requests WHERE id IN ($placeholders)"
            );

            if (empty($vendors)) {
                $error = 'No valid vendors found.';
            } else {
                $updated = 0;
                foreach ($vendors as $v) {
                    if ($v['status'] === 'evaluation') continue;

                    $updateData = ['status' => 'evaluation'];
                    if (empty($v['vendor_id'])) {
                        $updateData['vendor_id'] = 99999;
                    }

                    $db->update('vendor_onboarding_requests', $updateData, 'id = :id', [':id' => $v['id']]);

                    $auth->audit($user['id'], 'vendor_evaluation', 'vendor_onboarding_requests', $v['id'], [
                        'old' => ['status' => $v['status']],
                        'new' => ['status' => 'evaluation']
                    ]);

                    $updated++;
                }
                $success = "{$updated} vendor(s) marked as Evaluation.";
            }
        }
    }
}

// Generate a fresh CSRF token so our forms aren't vulnerable to cross-site shenanigans
$csrfToken = $security->generateCSRFToken();

// Pull in the scoring config -- this tells us what score ranges map to A/B/C/D/F
// and how often each tier needs rescoring (because Tier 1 vendors get a lot more love)
$scoringConfig = $srsService->getScoringConfig();
$upguardDisplayMode = $scoringConfig['display_mode'] ?? 'raw';
$upguardMaxScore = (int)($scoringConfig['max_score'] ?? 950);
$upguardName = $scoringConfig['display_name'] ?? 'UpGuard';
$shodanName = $shodanService->getScoringConfig()['display_name'] ?? 'Shodan';

function displayUpguardScore(int $rawScore, string $mode, int $maxScore): string
{
    if ($mode === 'percentage') {
        $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
        return $pct . '%';
    }
    return (string)$rawScore;
}

// Calculate a grade for the normalized average score (0-100 percentage scale).
// Uses Shodan config thresholds since they're already percentage-based,
// with sensible defaults if Shodan isn't configured.
function calculateAvgGrade(?int $avgScore, array $shodanConfig): ?string
{
    if ($avgScore === null) return null;
    $aMin = (int)($shodanConfig['grade_a_min'] ?? 90);
    $bMin = (int)($shodanConfig['grade_b_min'] ?? 75);
    $cMin = (int)($shodanConfig['grade_c_min'] ?? 60);
    $dMin = (int)($shodanConfig['grade_d_min'] ?? 40);
    if ($avgScore >= $aMin) return 'A';
    if ($avgScore >= $bMin) return 'B';
    if ($avgScore >= $cMin) return 'C';
    if ($avgScore >= $dMin) return 'D';
    return 'F';
}

// Grab Shodan scoring config for use in average grade calculation
$shodanScoringConfig = $shodanService->getScoringConfig();

// Filter parameters
$tierFilter = isset($_GET['tier']) ? $_GET['tier'] : '';
$gradeFilter = isset($_GET['grade']) ? strtoupper($_GET['grade']) : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$showNeedsRescore = isset($_GET['needs_rescore']) && $_GET['needs_rescore'] === '1';
$showShadowSaas = isset($_GET['show_shadow_saas']) && $_GET['show_shadow_saas'] === '1';
$showUsesAi = isset($_GET['uses_ai']) && $_GET['uses_ai'] === '1';
$statFilter = $_GET['filter'] ?? '';

// Map stat tile filter to specific filter behavior
if ($statFilter === 'needs_rescore') {
    $showNeedsRescore = true;
}

// Pagination and sorting via reusable helper
require_once __DIR__ . '/includes/classes/Pagination.php';
$validSortColumns = ['vendor_name', 'vendor_tier', 'current_srs_score', 'last_srs_score_at', 'scheduled_score_at', 'stakeholder_name', 'avg_score'];
if ($shodanColumnsExist) {
    $validSortColumns[] = 'current_shodan_score';
}
$pgParams = Pagination::getParams([
    'per_page' => 25,
    'sort_column' => 'vendor_name',
    'sort_dir' => 'ASC',
    'valid_sort_columns' => $validSortColumns,
]);
$sortColumn = $pgParams['sort_column'];
// Defense-in-depth: map validated sort columns to their SQL expressions
$safeColumns = array_fill_keys(['vendor_name', 'vendor_tier', 'current_srs_score', 'last_srs_score_at', 'scheduled_score_at', 'stakeholder_name', 'avg_score', 'current_shodan_score'], true);
$sortColumn = isset($safeColumns[$sortColumn]) ? $sortColumn : 'vendor_name';
$sortOrder = $pgParams['sort_dir'];
$perPage = $pgParams['per_page'];
$currentPage = $pgParams['page'];

// SQL computed expressions for server-side sorting/filtering
$tier1Days = (int)($scoringConfig['tier1_days'] ?? 30);
$tier2Days = (int)($scoringConfig['tier2_days'] ?? 90);
$tier3Days = (int)($scoringConfig['tier3_days'] ?? 365);
$maxScoreInt = max(1, (int)$upguardMaxScore);

// avg_score: normalized 0-100% average across all scoring platforms (UpGuard, Shodan, Custom)
$avgScoreExpr = $shodanColumnsExist
    ? "CASE
        WHEN r.current_srs_score > 0 AND r.current_shodan_score > 0 AND r.custom_score > 0
        THEN ROUND((FLOOR(r.current_srs_score / {$maxScoreInt} * 100) + r.current_shodan_score + r.custom_score) / 3)
        WHEN r.current_srs_score > 0 AND r.current_shodan_score > 0
        THEN ROUND((FLOOR(r.current_srs_score / {$maxScoreInt} * 100) + r.current_shodan_score) / 2)
        WHEN r.current_srs_score > 0 AND r.custom_score > 0
        THEN ROUND((FLOOR(r.current_srs_score / {$maxScoreInt} * 100) + r.custom_score) / 2)
        WHEN r.current_shodan_score > 0 AND r.custom_score > 0
        THEN ROUND((r.current_shodan_score + r.custom_score) / 2)
        WHEN r.current_srs_score > 0
        THEN FLOOR(r.current_srs_score / {$maxScoreInt} * 100)
        WHEN r.current_shodan_score > 0
        THEN CAST(r.current_shodan_score AS SIGNED)
        WHEN r.custom_score > 0
        THEN r.custom_score
        ELSE NULL
    END"
    : "CASE
        WHEN r.current_srs_score > 0 AND r.custom_score > 0
        THEN ROUND((FLOOR(r.current_srs_score / {$maxScoreInt} * 100) + r.custom_score) / 2)
        WHEN r.current_srs_score > 0
        THEN FLOOR(r.current_srs_score / {$maxScoreInt} * 100)
        WHEN r.custom_score > 0
        THEN r.custom_score
        ELSE NULL
    END";

// scheduled_score_at: next rescore date based on tier interval
$scheduledScoreExpr = "CASE
    WHEN r.last_srs_score_at IS NOT NULL AND r.vendor_tier IS NOT NULL THEN
        DATE_ADD(r.last_srs_score_at, INTERVAL
            CASE r.vendor_tier
                WHEN '1' THEN {$tier1Days}
                WHEN '2' THEN {$tier2Days}
                WHEN '3' THEN {$tier3Days}
                ELSE 0
            END DAY)
    ELSE NULL
END";

// needs_rescore: boolean expression for tier-based rescore check
$needsRescoreExpr = "(
    r.vendor_tier IS NOT NULL
    AND r.vendor_domain IS NOT NULL AND r.vendor_domain != ''
    AND r.status != 'inactive'
    AND (
        r.last_srs_score_at IS NULL
        OR r.last_srs_score_at < DATE_SUB(NOW(), INTERVAL
            CASE r.vendor_tier
                WHEN '1' THEN {$tier1Days}
                WHEN '2' THEN {$tier2Days}
                WHEN '3' THEN {$tier3Days}
                ELSE 99999
            END DAY)
    )
)";

// Grade thresholds for SQL HAVING and stat queries
$aMin = (int)($shodanScoringConfig['grade_a_min'] ?? 90);
$bMin = (int)($shodanScoringConfig['grade_b_min'] ?? 75);
$cMin = (int)($shodanScoringConfig['grade_c_min'] ?? 60);
$dMin = (int)($shodanScoringConfig['grade_d_min'] ?? 40);

// ====================================================================
// STAT TILE COUNTS -- independent of current page filters
// ====================================================================
$baseWhere = "r.vendor_domain IS NOT NULL AND r.vendor_domain != ''";
$statsRow = $db->fetchOne("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ({$avgScoreExpr}) IS NOT NULL THEN 1 ELSE 0 END) AS scored,
        SUM(CASE WHEN ({$avgScoreExpr}) IS NULL THEN 1 ELSE 0 END) AS unscored,
        SUM(CASE WHEN {$needsRescoreExpr} THEN 1 ELSE 0 END) AS needs_rescore,
        ROUND(AVG(({$avgScoreExpr}))) AS avg_score,
        SUM(CASE WHEN r.status = 'evaluation' THEN 1 ELSE 0 END) AS eval,
        SUM(CASE WHEN r.status = 'in_review' THEN 1 ELSE 0 END) AS in_review
    FROM vendor_onboarding_requests r
    WHERE {$baseWhere}
");

$gradeRow = $db->fetchOne("
    SELECT
        SUM(CASE WHEN avg_s >= {$aMin} THEN 1 ELSE 0 END) AS grade_a,
        SUM(CASE WHEN avg_s >= {$bMin} AND avg_s < {$aMin} THEN 1 ELSE 0 END) AS grade_b,
        SUM(CASE WHEN avg_s >= {$cMin} AND avg_s < {$bMin} THEN 1 ELSE 0 END) AS grade_c,
        SUM(CASE WHEN avg_s >= {$dMin} AND avg_s < {$cMin} THEN 1 ELSE 0 END) AS grade_d,
        SUM(CASE WHEN avg_s < {$dMin} THEN 1 ELSE 0 END) AS grade_f
    FROM (
        SELECT ({$avgScoreExpr}) AS avg_s
        FROM vendor_onboarding_requests r
        WHERE {$baseWhere}
    ) sub
    WHERE sub.avg_s IS NOT NULL
");

$stats = [
    'total' => (int)($statsRow['total'] ?? 0),
    'scored' => (int)($statsRow['scored'] ?? 0),
    'unscored' => (int)($statsRow['unscored'] ?? 0),
    'needs_rescore' => (int)($statsRow['needs_rescore'] ?? 0),
    'avg_score' => (int)($statsRow['avg_score'] ?? 0),
    'eval' => (int)($statsRow['eval'] ?? 0),
    'in_review' => (int)($statsRow['in_review'] ?? 0),
    'grades' => [
        'A' => (int)($gradeRow['grade_a'] ?? 0),
        'B' => (int)($gradeRow['grade_b'] ?? 0),
        'C' => (int)($gradeRow['grade_c'] ?? 0),
        'D' => (int)($gradeRow['grade_d'] ?? 0),
        'F' => (int)($gradeRow['grade_f'] ?? 0),
    ]
];

// ====================================================================
// BUILD FILTERED WHERE CLAUSE
// ====================================================================
$params = [];
$whereConditions = ["r.vendor_domain IS NOT NULL", "r.vendor_domain != ''"];

if ($tierFilter === 'none') {
    $whereConditions[] = "(r.vendor_tier IS NULL OR r.vendor_tier = '')";
} elseif (!empty($tierFilter) && in_array($tierFilter, ['1', '2', '3'])) {
    $whereConditions[] = "r.vendor_tier = :tier";
    $params[':tier'] = $tierFilter;
}

// Stat tile filters
if ($statFilter === 'scored') {
    if ($shodanColumnsExist) {
        $whereConditions[] = "(r.current_srs_score > 0 OR r.current_shodan_score > 0)";
    } else {
        $whereConditions[] = "r.current_srs_score > 0";
    }
} elseif ($statFilter === 'evaluation') {
    $whereConditions[] = "r.status = 'evaluation'";
} elseif ($statFilter === 'in_review') {
    $whereConditions[] = "r.status = 'in_review'";
} elseif ($statFilter === 'not_scored') {
    if ($shodanColumnsExist) {
        $whereConditions[] = "(r.current_srs_score IS NULL OR r.current_srs_score = 0) AND (r.current_shodan_score IS NULL OR r.current_shodan_score = 0)";
    } else {
        $whereConditions[] = "(r.current_srs_score IS NULL OR r.current_srs_score = 0)";
    }
}

// Needs rescore filter (from checkbox or stat tile click)
if ($showNeedsRescore) {
    $whereConditions[] = $needsRescoreExpr;
}

// Uses AI filter: match vendors where Services Use AI checkbox is checked
if ($showUsesAi) {
    $whereConditions[] = "r.vendor_use_ai = 'yes'";
}

$isCveSearch = false;
$cveSearchQuery = '';
if (!empty($searchQuery) && preg_match('/^CVE-/i', trim($searchQuery))) {
    $isCveSearch = true;
    $cveSearchQuery = strtoupper(trim($searchQuery));
    $cveVendorIds = [];
    try {
        $isExact = (bool) preg_match('/^CVE-\d{4}-\d+$/i', $cveSearchQuery);
        if ($isExact) {
            $cveRows = $db->fetchAll('SELECT DISTINCT vendor_onboarding_id FROM view_cve_search WHERE cve_id = :cve', [':cve' => $cveSearchQuery]);
        } else {
            $cveRows = $db->fetchAll('SELECT DISTINCT vendor_onboarding_id FROM view_cve_search WHERE cve_id LIKE :cve', [':cve' => $cveSearchQuery . '%']);
        }
        foreach ($cveRows as $cr) {
            $cveVendorIds[] = (int) $cr['vendor_onboarding_id'];
        }
    } catch (Exception $e) {
        try {
            $cveRows = $db->fetchAll('SELECT DISTINCT vendor_onboarding_id FROM vendor_technologies WHERE is_current = 1 AND cves LIKE :cve', [':cve' => '%' . $cveSearchQuery . '%']);
            foreach ($cveRows as $cr) {
                $cveVendorIds[] = (int) $cr['vendor_onboarding_id'];
            }
        } catch (Exception $e2) {
        }
    }
    if (!empty($cveVendorIds)) {
        $placeholders = implode(',', $cveVendorIds);
        $whereConditions[] = "r.id IN ($placeholders)";
    } else {
        $whereConditions[] = "1 = 0";
    }
} elseif (!empty($searchQuery)) {
    $whereConditions[] = "(r.vendor_name LIKE :search OR r.vendor_domain LIKE :search2 OR stakeholder_user.full_name LIKE :search3 OR stakeholder_user.email LIKE :search4 OR owner_user.full_name LIKE :search5 OR owner_user.email LIKE :search6 OR r.project LIKE :search7 OR r.cost_center LIKE :search8)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
    $params[':search3'] = '%' . $searchQuery . '%';
    $params[':search4'] = '%' . $searchQuery . '%';
    $params[':search5'] = '%' . $searchQuery . '%';
    $params[':search6'] = '%' . $searchQuery . '%';
    $params[':search7'] = '%' . $searchQuery . '%';
    $params[':search8'] = '%' . $searchQuery . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Grade filter via HAVING on computed avg_score
$havingClause = '';
if (!empty($gradeFilter)) {
    $gradeRanges = [
        'A' => "avg_score >= {$aMin}",
        'B' => "avg_score >= {$bMin} AND avg_score < {$aMin}",
        'C' => "avg_score >= {$cMin} AND avg_score < {$bMin}",
        'D' => "avg_score >= {$dMin} AND avg_score < {$cMin}",
        'F' => "avg_score < {$dMin}",
    ];
    if (isset($gradeRanges[$gradeFilter])) {
        $havingClause = "HAVING {$gradeRanges[$gradeFilter]}";
    }
}

// ====================================================================
// FILTERED AVERAGE SCORE -- reflects current filters (tier, stat card, etc.)
// ====================================================================
$filteredAvgQuery = "
    SELECT ROUND(AVG(avg_score)) AS filtered_avg FROM (
        SELECT ({$avgScoreExpr}) AS avg_score
        FROM vendor_onboarding_requests r
        LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
            ON r.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
        LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
        LEFT JOIN vendor_onboarding_stakeholders vos_owner
            ON r.id = vos_owner.request_id AND vos_owner.role = 'owner'
        LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id
        {$whereClause}
        GROUP BY r.id
        {$havingClause}
    ) sub WHERE sub.avg_score IS NOT NULL
";
$filteredAvgRow = $db->fetchOne($filteredAvgQuery, $params);
$filteredAvgScore = (int)($filteredAvgRow['filtered_avg'] ?? 0);

// Build dynamic label for the avg score card
$avgScoreLabel = 'Avg Score';
$hasActiveFilter = !empty($statFilter) || $showNeedsRescore || $showUsesAi || !empty($tierFilter) || !empty($gradeFilter) || !empty($searchQuery);
if ($hasActiveFilter) {
    $parts = [];
    if ($tierFilter === 'none') $parts[] = 'No Tier';
    elseif (!empty($tierFilter)) $parts[] = 'Tier ' . $tierFilter;
    if ($statFilter === 'scored') $parts[] = 'Scored';
    elseif ($statFilter === 'not_scored') $parts[] = 'Not Scored';
    elseif ($statFilter === 'needs_rescore' || $showNeedsRescore) $parts[] = 'Rescore';
    elseif ($statFilter === 'evaluation') $parts[] = 'Eval';
    elseif ($statFilter === 'in_review') $parts[] = 'In Review';
    if (!empty($gradeFilter)) $parts[] = 'Grade ' . $gradeFilter;
    if ($showUsesAi) $parts[] = 'Uses AI';
    if (!empty($searchQuery)) $parts[] = 'Search';
    $avgScoreLabel = 'Avg ' . (!empty($parts) ? implode(' + ', $parts) : 'Selected');
}

// ====================================================================
// SHARED FROM/JOIN
// ====================================================================
$shodanSelectCols = $shodanColumnsExist ? ', r.current_shodan_score, r.last_shodan_score_at' : '';
$shodanGroupCols = $shodanColumnsExist ? ', r.current_shodan_score, r.last_shodan_score_at' : '';

$trafficLightSelect = '';
if ($shodanEnhancedExist) {
    $trafficLightSelect = ", (SELECT vss.traffic_light FROM vendor_shodan_scores vss WHERE vss.vendor_onboarding_id = r.id ORDER BY vss.scored_at DESC LIMIT 1) AS shodan_traffic_light";
}

$fromJoin = "
    FROM vendor_onboarding_requests r
    LEFT JOIN vendor_onboarding_stakeholders vos_stakeholder
        ON r.id = vos_stakeholder.request_id AND vos_stakeholder.role = 'stakeholder'
    LEFT JOIN users stakeholder_user ON vos_stakeholder.user_id = stakeholder_user.id
    LEFT JOIN vendor_onboarding_stakeholders vos_owner
        ON r.id = vos_owner.request_id AND vos_owner.role = 'owner'
    LEFT JOIN users owner_user ON vos_owner.user_id = owner_user.id";

$groupBy = "GROUP BY r.id, r.vendor_name, r.vendor_domain, r.vendor_tier, r.vendor_type,
             r.current_srs_score, r.last_srs_score_at{$shodanGroupCols},
             r.status, r.created_at,
             r.primary_contact_email, r.primary_contact_details, r.primary_contact_title, r.primary_contact_phone,
             r.product_service_description, r.vendor_use_ai";

// ====================================================================
// COUNT QUERY (total filtered rows)
// ====================================================================
$countQuery = "SELECT COUNT(*) AS cnt FROM (
    SELECT r.id, ({$avgScoreExpr}) AS avg_score
    {$fromJoin}
    {$whereClause}
    GROUP BY r.id
    {$havingClause}
) AS filtered";
$countRow = $db->fetchOne($countQuery, $params);
$totalVendors = (int)($countRow['cnt'] ?? 0);

// Compute pagination metadata
$pg = Pagination::paginate($totalVendors, $perPage, $currentPage);
$currentPage = $pg['current_page'];
$totalPages = $pg['total_pages'];
$offset = $pg['offset'];

// SQL ORDER BY mapping
$orderMap = [
    'vendor_name' => 'r.vendor_name',
    'vendor_tier' => 'CAST(r.vendor_tier AS UNSIGNED)',
    'current_srs_score' => 'r.current_srs_score',
    'current_shodan_score' => 'r.current_shodan_score',
    'last_srs_score_at' => 'r.last_srs_score_at',
    'scheduled_score_at' => 'scheduled_score_at',
    'stakeholder_name' => 'stakeholder_name',
    'avg_score' => 'avg_score',
];
$sqlOrderCol = $orderMap[$sortColumn] ?? 'r.vendor_name';

// ====================================================================
// PAGINATED DATA QUERY
// ====================================================================
$dataQuery = "
    SELECT r.id, r.vendor_name, r.vendor_domain, r.vendor_tier, r.vendor_type,
           r.current_srs_score, r.last_srs_score_at{$shodanSelectCols},
           r.status, r.created_at, r.vendor_use_ai,
           r.primary_contact_email, r.primary_contact_details, r.primary_contact_title, r.primary_contact_phone,
           r.product_service_description,
           MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name)) as stakeholder_name,
           MAX(COALESCE(stakeholder_user.email, owner_user.email)) as stakeholder_email,
           ({$avgScoreExpr}) AS avg_score,
           ({$scheduledScoreExpr}) AS scheduled_score_at
           {$trafficLightSelect}
    {$fromJoin}
    {$whereClause}
    {$groupBy}
    {$havingClause}
    ORDER BY {$sqlOrderCol} {$sortOrder}
    LIMIT {$perPage} OFFSET {$offset}
";
$paginatedVendors = $db->fetchAll($dataQuery, $params);

// ====================================================================
// SHADOW SAAS ENTRIES (pending entries with domains appear in SRS list)
// ====================================================================
// Show shadow SaaS when no scoring-specific filters are active
$shadowSaasTableExists = false;
try {
    $db->fetchOne("SELECT 1 FROM shadow_saas LIMIT 1");
    $shadowSaasTableExists = true;
} catch (Exception $e) {
    // Auto-create table if missing
    try {
        $db->query("CREATE TABLE IF NOT EXISTS shadow_saas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_name VARCHAR(500) NOT NULL,
            vendor_domain VARCHAR(255) DEFAULT NULL,
            relationship_manager VARCHAR(255) DEFAULT NULL,
            number_of_users INT UNSIGNED DEFAULT NULL,
            current_srs_score INT DEFAULT NULL,
            last_srs_score_at DATETIME DEFAULT NULL,
            current_shodan_score INT DEFAULT NULL,
            last_shodan_score_at DATETIME DEFAULT NULL,
            rescore_status VARCHAR(30) DEFAULT NULL,
            rescore_started_at DATETIME DEFAULT NULL,
            rescore_result TEXT DEFAULT NULL,
            status ENUM('pending', 'onboarded', 'dismissed') DEFAULT 'pending',
            onboarded_vendor_id INT UNSIGNED DEFAULT NULL,
            onboarded_at DATETIME DEFAULT NULL,
            onboarded_by INT UNSIGNED DEFAULT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_vendor_name (vendor_name),
            INDEX idx_vendor_domain (vendor_domain),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $shadowSaasTableExists = true;
    } catch (Exception $e2) {}
}

$includeShadowSaas = $shadowSaasTableExists && $showShadowSaas
    && empty($tierFilter) && empty($gradeFilter) && !$showNeedsRescore && !$isCveSearch
    && !in_array($statFilter, ['scored', 'not_scored', 'needs_rescore', 'in_review']);

$shadowSaasTotal = 0;
if ($includeShadowSaas) {
    // Build shadow SaaS WHERE clause with separate param names
    $ssWhereConditions = ["ss.status = 'pending'", "ss.vendor_domain IS NOT NULL", "ss.vendor_domain != ''"];
    $ssParams = [];
    if (!empty($searchQuery)) {
        $ssWhereConditions[] = "(ss.vendor_name LIKE :ss_search OR ss.vendor_domain LIKE :ss_search2)";
        $ssParams[':ss_search'] = '%' . $searchQuery . '%';
        $ssParams[':ss_search2'] = '%' . $searchQuery . '%';
    }
    $ssWhereClause = 'WHERE ' . implode(' AND ', $ssWhereConditions);

    // Count shadow SaaS entries
    try {
        $ssCntRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM shadow_saas ss {$ssWhereClause}", $ssParams);
        $shadowSaasTotal = (int)($ssCntRow['cnt'] ?? 0);
    } catch (Exception $e) { $shadowSaasTotal = 0; }

    // Adjust total for pagination
    $totalVendors += $shadowSaasTotal;
    $pg = Pagination::paginate($totalVendors, $perPage, $currentPage);
    $currentPage = $pg['current_page'];
    $totalPages = $pg['total_pages'];
    $offset = $pg['offset'];

    // Re-fetch paginated data using UNION ALL
    $ssShodanCols = $shodanColumnsExist ? ', ss.current_shodan_score, ss.last_shodan_score_at' : '';
    $ssTrafficLight = $shodanEnhancedExist ? ', NULL AS shodan_traffic_light' : '';

    // Compute avg_score for shadow SaaS (no custom_score column)
    $ssAvgScoreExpr = $shodanColumnsExist
        ? "CASE
            WHEN ss.current_srs_score > 0 AND ss.current_shodan_score > 0
            THEN ROUND((FLOOR(ss.current_srs_score / {$maxScoreInt} * 100) + ss.current_shodan_score) / 2)
            WHEN ss.current_srs_score > 0
            THEN FLOOR(ss.current_srs_score / {$maxScoreInt} * 100)
            WHEN ss.current_shodan_score > 0
            THEN CAST(ss.current_shodan_score AS SIGNED)
            ELSE NULL
        END"
        : "CASE
            WHEN ss.current_srs_score > 0
            THEN FLOOR(ss.current_srs_score / {$maxScoreInt} * 100)
            ELSE NULL
        END";

    $shadowSaasSelect = "
        SELECT ss.id AS id, ss.vendor_name, ss.vendor_domain,
               NULL AS vendor_tier, NULL AS vendor_type,
               ss.current_srs_score, ss.last_srs_score_at{$ssShodanCols},
               'shadow_saas' AS status, ss.created_at,
               NULL AS primary_contact_email, NULL AS primary_contact_details, NULL AS primary_contact_title, NULL AS primary_contact_phone,
               NULL AS product_service_description,
               ss.relationship_manager AS stakeholder_name,
               NULL AS stakeholder_email,
               ({$ssAvgScoreExpr}) AS avg_score,
               NULL AS scheduled_score_at
               {$ssTrafficLight},
               1 AS is_shadow_saas
        FROM shadow_saas ss
        {$ssWhereClause}
    ";

    // Add is_shadow_saas flag to the main query
    $mainDataQuery = "
        SELECT r.id, r.vendor_name, r.vendor_domain, r.vendor_tier, r.vendor_type,
               r.current_srs_score, r.last_srs_score_at{$shodanSelectCols},
               r.status, r.created_at,
               r.primary_contact_email, r.primary_contact_details, r.primary_contact_title, r.primary_contact_phone,
               r.product_service_description,
               MAX(COALESCE(stakeholder_user.full_name, owner_user.full_name)) as stakeholder_name,
               MAX(COALESCE(stakeholder_user.email, owner_user.email)) as stakeholder_email,
               ({$avgScoreExpr}) AS avg_score,
               ({$scheduledScoreExpr}) AS scheduled_score_at
               {$trafficLightSelect},
               0 AS is_shadow_saas
        {$fromJoin}
        {$whereClause}
        {$groupBy}
        {$havingClause}
    ";

    // Combine via UNION ALL, wrap in outer query for sorting/pagination
    // Merge params from both queries
    $combinedParams = array_merge($params, $ssParams);

    $combinedQuery = "
        SELECT * FROM (
            {$mainDataQuery}
            UNION ALL
            {$shadowSaasSelect}
        ) AS combined
        ORDER BY combined.{$sortColumn} {$sortOrder}
        LIMIT {$perPage} OFFSET {$offset}
    ";

    $paginatedVendors = $db->fetchAll($combinedQuery, $combinedParams);
}

$displayTotalVendors = $totalVendors;

// Flag vendors that are also subprocessors of other vendors
$subprocessorVendorIds = [];
try {
    $spRows = $db->fetchAll(
        "SELECT DISTINCT linked_vendor_id FROM vendor_subprocessors WHERE linked_vendor_id IS NOT NULL"
    );
    foreach ($spRows as $sp) {
        $subprocessorVendorIds[(int)$sp['linked_vendor_id']] = true;
    }
} catch (Exception $e) {}

// ====================================================================
// AI RISK REGISTER LOOKUP (for AI risk action button in detail row)
// ====================================================================
$aiRiskMap = [];          // vendor_id => risk row (for prefill)
$aiEligibleOwners = [];   // users in cyber_grc/cyber_tprm for typeahead & validation
$aiAllControls = [];      // grc_internal_controls for mitigating controls multi-select
try {
    $pageVendorIds = [];
    foreach ($paginatedVendors as $pv) {
        if (!empty($pv['id']) && (($pv['status'] ?? '') !== 'shadow_saas')) {
            $pageVendorIds[] = (int)$pv['id'];
        }
    }
    if (!empty($pageVendorIds)) {
        $inList = implode(',', $pageVendorIds);
        $aiRiskRows = $db->fetchAll(
            "SELECT r.id, r.risk_ref, r.vendor_id, r.title, r.description, r.risk_category,
                    r.likelihood, r.impact, r.inherent_risk_score, r.residual_risk_score,
                    r.risk_treatment, r.treatment_plan, r.owner_user_id, r.control_ids,
                    r.status, r.review_date, u.full_name AS owner_name
             FROM grc_risk_register r
             LEFT JOIN users u ON u.id = r.owner_user_id
             WHERE r.vendor_id IN ($inList)
               AND r.title LIKE '% - Uses AI in their services'
             ORDER BY r.id DESC"
        );
        foreach ($aiRiskRows as $ar) {
            $vid = (int)$ar['vendor_id'];
            if (!isset($aiRiskMap[$vid])) {
                $aiRiskMap[$vid] = $ar;
            }
        }
    }

    $aiEligibleOwners = $db->fetchAll(
        "SELECT DISTINCT u.id, u.full_name, u.email
         FROM users u
         JOIN user_acl_groups uag ON uag.user_id = u.id
         JOIN acl_groups ag ON ag.id = uag.group_id
         WHERE u.is_active = 1
           AND ag.group_name IN ('cyber_grc','cyber_tprm')
         ORDER BY u.full_name"
    );

    $aiAllControls = $db->fetchAll(
        "SELECT id, control_ref, title FROM grc_internal_controls
         WHERE is_active = 1 ORDER BY control_ref"
    );
} catch (Exception $e) {
    error_log('AI risk lookup error: ' . $e->getMessage());
}

// Helper functions delegate to Pagination class
function buildSortUrl($column, $currentSort, $currentOrder) {
    return Pagination::buildSortUrl($column, $currentSort, $currentOrder);
}
function buildPageUrl($page) {
    return Pagination::buildPageUrl($page);
}
function getSortIndicator($column, $currentSort, $currentOrder) {
    return Pagination::getSortIndicator($column, $currentSort, $currentOrder);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SRS Vendor Scoring - TPRM</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=2">
    <!-- style.css removed - causes layout conflicts with custom page styling -->
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

        /* Sidebar - Matching cyber-todo.php style */
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
        .sidebar-nav li a .badge {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: var(--nav-font-color);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }

        .main-content { flex: 1; padding: 25px; overflow-y: auto; }

        /* Expandable row */
        tr.vendor-row { cursor: pointer; }
        tr.vendor-row:hover { background: #f9fafb; }
        tr.vendor-row.expanded { background: #f0f9ff; }
        tr.vendor-detail-row { display: none; }
        tr.vendor-detail-row.open { display: table-row; }
        tr.vendor-detail-row td { padding: 0 !important; border-top: none !important; }
        .detail-panel {
            background: #f8fafc;
            border-top: 1px dashed #e5e7eb;
            padding: 16px 24px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            font-size: 13px;
        }
        .detail-item { }
        .detail-item .detail-label { font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; }
        .detail-item .detail-value { color: #111827; word-break: break-word; }
        .detail-item .detail-value.empty { color: #9ca3af; font-style: italic; }
        .detail-item .detail-value.scrollable { max-height: 150px; overflow-y: auto; white-space: pre-wrap; padding-right: 4px; }
        .autocomplete-wrapper { position: relative; }
        .autocomplete-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; max-height: 250px; overflow-y: auto; z-index: 10001; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .autocomplete-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover, .autocomplete-item.highlighted { background: #f8f9fa; }
        .autocomplete-item .user-name { font-weight: 500; color: #333; }
        .autocomplete-item .user-email { font-size: 12px; color: #666; margin-top: 2px; }
        .autocomplete-no-results { padding: 12px 14px; color: #666; font-style: italic; }
        .autocomplete-loading { padding: 12px 14px; color: #666; text-align: center; }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
            text-align: center;
        }
        .stat-card .value { font-size: 28px; font-weight: 600; color: var(--theme-header-color); }
        .stat-card .label { font-size: 12px; color: #666; margin-top: 4px; }
        .stat-card.warning .value { color: #f59e0b; }
        .stat-card.info .value { color: #0ea5e9; }
        .stat-card.danger .value { color: #dc2626; }
        .stat-card.success .value { color: #10b981; }
        .stat-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); transform: translateY(-1px); transition: all 0.2s; }
        .stat-card.stat-active { border-color: var(--theme-header-color); box-shadow: 0 0 0 2px var(--theme-header-color); }

        .filters-bar {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters-bar .filter-group { display: flex; align-items: center; gap: 8px; }
        .filters-bar label { font-size: 13px; color: #666; font-weight: 500; }
        .filters-bar select, .filters-bar input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            min-width: 140px;
        }
        .filters-bar .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        .filters-bar .btn-primary { background: var(--theme-button-color); color: white; }
        .filters-bar .btn-secondary { background: #6b7280; color: white; }
        .filters-bar .btn:hover { filter: brightness(1.1); }

        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; color: #374151; }
        td { padding: 12px 15px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        tr:hover { background: #f9fafb; }

        .vendor-name { font-weight: 500; color: #333; }
        .vendor-domain { font-size: 12px; color: #6b7280; }

        .score-display { display: flex; align-items: center; gap: 8px; }
        .score-value { font-weight: 600; font-size: 15px; }
        .score-grade {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-weight: 700;
            font-size: 14px;
        }
        .grade-a { background: #dcfce7; color: #166534; }
        .grade-b { background: #d1fae5; color: #065f46; }
        .grade-c { background: #fef3c7; color: #92400e; }
        .grade-d { background: #fed7aa; color: #9a3412; }
        .grade-f { background: #fecaca; color: #991b1b; }
        .no-score { color: #9ca3af; font-style: italic; }

        .tier-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .tier-1 { background: #fef2f2; color: #991b1b; }
        .tier-2 { background: #fef3c7; color: #92400e; }
        .tier-3 { background: #ecfdf5; color: #065f46; }
        .tier-none { background: #f3f4f6; color: #6b7280; }

        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-needs-rescore { background: #fef3c7; color: #92400e; }
        .status-current { background: #dcfce7; color: #166534; }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-view { background: var(--theme-button-color); color: white; }
        .btn-view:hover { filter: brightness(1.1); color: white; text-decoration: none; }
        .btn-rescore { background: #059669; color: white; margin-left: 5px; }
        .btn-rescore:hover { background: #047857; }
        .btn-rescore:disabled { background: #9ca3af; cursor: not-allowed; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }

        .grade-filter-item:hover { background: #f3f4f6; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .empty-state p { margin: 0; font-size: 14px; }

        /* Per-page selector */
        .per-page-group { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .per-page-group label { font-size: 12px; color: #666; }
        .per-page-group select {
            padding: 6px 10px; border: 1px solid #ddd;
            border-radius: 4px; font-size: 12px;
        }

        /* Sortable headers */
        th.sortable {
            cursor: pointer; user-select: none;
            transition: background 0.2s;
        }
        th.sortable:hover { background: #e9ecef; }
        th.sortable a { color: inherit; text-decoration: none; display: block; }
        th.sortable .sort-icon { font-size: 10px; margin-left: 4px; opacity: 0.5; }
        th.sortable.active .sort-icon { opacity: 1; color: var(--theme-header-color); }

        /* Pagination */
        .pagination-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 20px; padding: 15px 20px; border-top: 1px solid #e5e7eb;
            flex-wrap: wrap; gap: 10px;
        }
        .pagination-info { font-size: 13px; color: #666; }
        .pagination {
            display: flex; gap: 4px; list-style: none; margin: 0; padding: 0;
        }
        .pagination li a, .pagination li span {
            display: inline-block; padding: 6px 12px;
            border: 1px solid #ddd; border-radius: 4px;
            text-decoration: none; color: #333; font-size: 13px;
            transition: all 0.2s;
        }
        .pagination li a:hover { background: #f3f4f6; border-color: #ccc; }
        .pagination li.active span {
            background: var(--theme-header-color); color: white;
            border-color: var(--theme-header-color);
        }
        .pagination li.disabled span { color: #ccc; cursor: not-allowed; }

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
            .filters-bar { flex-direction: column; align-items: stretch; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            table { font-size: 12px; }
            th, td { padding: 10px; }
            .vendor-search-container { width: 100% !important; min-width: 0 !important; }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>

        <div class="top-bar">
            <span style="color: #666; margin-right: 15px;">Welcome, <?php echo e($user['full_name']); ?></span>
            <div class="user-menu">
                <?php if ($isAdmin): ?>
                    <a href="admin.php">Admin</a>
                    <a href="admin.php?section=srs" class="btn-configure">Configure SRS</a>
                <?php endif; ?>
                <a href="profile.php">Profile</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <div class="main-layout">
            <?php $_savedPageNum = $currentPage; $currentPage = 'srs'; include __DIR__ . '/includes/sidebar_nav.php'; $currentPage = $_savedPageNum; ?>

            <main class="main-content">
        <?php if (!$srsAvailable && !$shodanAvailable): ?>
        <div class="alert alert-warning">
            <strong>No Scoring Providers Configured</strong> - Neither UpGuard nor Shodan API integration is enabled.
            <?php if ($isAdmin): ?>
            <a href="admin.php?section=srs">Configure SRS settings</a> to enable vendor security scoring.
            <?php else: ?>
            Contact an administrator to enable scoring integration.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <!-- Page Header Row -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 class="page-title" style="margin: 0 0 10px 0;">SRS Vendor Scoring</h1>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <a href="vendor-srs-export.php<?php echo (!empty($tierFilter) || !empty($gradeFilter) || !empty($searchQuery)) ? '?' . http_build_query(array_filter(['tier' => $tierFilter, 'grade' => $gradeFilter, 'search' => $searchQuery])) : ''; ?>" class="btn" style="background: #6b7280; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px;">Export CSV</a>
                    <a href="vendor-srs-import.php" class="btn" style="background: #059669; color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px;">Import CSV</a>
                    <button type="button" data-action="openComplianceReportModal" class="btn" style="background: var(--theme-button-color); color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer;">Compliance Report</button>
                </div>
            </div>
            <div class="vendor-search-container" style="position: relative; min-width: 375px;">
                <input
                    type="text"
                    id="vendorSearchInput"
                    placeholder="Search vendors, domains, shadow SaaS..."
                    class="focus-ring"
                    style="width: 100%; padding: 10px 40px 10px 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s;"
                >
                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #000; font-size: 18px; opacity: 0.1; pointer-events: none; filter: grayscale(100%);">&#128269;</span>
                <div id="vendorSearchResults" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 5px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 400px; overflow-y: auto; z-index: 1000;"></div>
            </div>
        </div>

        <!-- Stats (clickable tiles filter the table) -->
        <div class="stats-grid">
            <a href="vendor-srs-list.php" class="stat-card<?php echo empty($statFilter) && !$showNeedsRescore && empty($gradeFilter) ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $stats['total']; ?></div>
                <div class="label">Total Vendors</div>
            </a>
            <a href="vendor-srs-list.php?filter=scored" class="stat-card success<?php echo $statFilter === 'scored' ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $stats['scored']; ?></div>
                <div class="label">Scored</div>
            </a>
            <a href="vendor-srs-list.php?filter=not_scored" class="stat-card<?php echo $statFilter === 'not_scored' ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $stats['unscored']; ?></div>
                <div class="label">Not Scored</div>
            </a>
            <a href="vendor-srs-list.php?filter=needs_rescore" class="stat-card warning<?php echo $statFilter === 'needs_rescore' || ($showNeedsRescore && empty($statFilter)) ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $stats['needs_rescore']; ?></div>
                <div class="label">Needs Rescore</div>
            </a>
            <a href="vendor-srs-list.php?filter=evaluation" class="stat-card info<?php echo $statFilter === 'evaluation' ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $stats['eval']; ?></div>
                <div class="label">Eval</div>
            </a>
            <a href="vendor-srs-list.php?filter=in_review" class="stat-card<?php echo $statFilter === 'in_review' ? ' stat-active' : ''; ?>" style="text-decoration: none; cursor: pointer;">
                <div class="value" style="color: #8b5cf6;"><?php echo $stats['in_review']; ?></div>
                <div class="label">In Review</div>
            </a>
            <div class="stat-card">
                <div class="value"><?php echo $filteredAvgScore; ?>%</div>
                <div class="label"><?php echo e($avgScoreLabel); ?></div>
            </div>
            <?php if ($shadowSaasTotal > 0): ?>
            <a href="shadow-saas.php" class="stat-card warning" style="text-decoration: none; cursor: pointer;">
                <div class="value"><?php echo $shadowSaasTotal; ?></div>
                <div class="label">Shadow SaaS</div>
            </a>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" name="search" value="<?php echo e($searchQuery); ?>" placeholder="Vendor, domain, project, cost center, or CVE-ID...">
            </div>
            <div class="filter-group">
                <label>Tier:</label>
                <select name="tier">
                    <option value="">All Tiers</option>
                    <option value="1" <?php echo $tierFilter === '1' ? 'selected' : ''; ?>>Tier 1 (<?php echo $scoringConfig['tier1_days']; ?> days)</option>
                    <option value="2" <?php echo $tierFilter === '2' ? 'selected' : ''; ?>>Tier 2 (<?php echo $scoringConfig['tier2_days']; ?> days)</option>
                    <option value="3" <?php echo $tierFilter === '3' ? 'selected' : ''; ?>>Tier 3 (<?php echo $scoringConfig['tier3_days']; ?> days)</option>
                    <option value="none" <?php echo $tierFilter === 'none' ? 'selected' : ''; ?>>No Tier</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Grade:</label>
                <select name="grade">
                    <option value="">All Grades</option>
                    <option value="A" <?php echo $gradeFilter === 'A' ? 'selected' : ''; ?>>A (<?php echo $scoringConfig['grade_a_min']; ?>+)</option>
                    <option value="B" <?php echo $gradeFilter === 'B' ? 'selected' : ''; ?>>B (<?php echo $scoringConfig['grade_b_min']; ?>+)</option>
                    <option value="C" <?php echo $gradeFilter === 'C' ? 'selected' : ''; ?>>C (<?php echo $scoringConfig['grade_c_min']; ?>+)</option>
                    <option value="D" <?php echo $gradeFilter === 'D' ? 'selected' : ''; ?>>D (<?php echo $scoringConfig['grade_d_min']; ?>+)</option>
                    <option value="F" <?php echo $gradeFilter === 'F' ? 'selected' : ''; ?>>F (Below <?php echo $scoringConfig['grade_d_min']; ?>)</option>
                </select>
            </div>
            <div class="filter-group">
                <label>
                    <input type="checkbox" name="needs_rescore" value="1" <?php echo $showNeedsRescore ? 'checked' : ''; ?>>
                    Needs Rescore Only
                </label>
            </div>
            <div class="filter-group">
                <label>
                    <input type="checkbox" name="uses_ai" value="1" <?php echo $showUsesAi ? 'checked' : ''; ?>>
                    Uses AI
                </label>
            </div>
            <?php if ($shadowSaasTableExists): ?>
            <div class="filter-group">
                <label>
                    <input type="checkbox" name="show_shadow_saas" value="1" <?php echo $showShadowSaas ? 'checked' : ''; ?>>
                    Include Shadow SaaS
                </label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="vendor-srs-list.php" class="btn btn-secondary">Reset</a>

            <div class="per-page-group">
                <label>Show:</label>
                <select data-action="changePerPage">
                    <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                    <option value="500" <?php echo $perPage == 500 ? 'selected' : ''; ?>>500</option>
                </select>
                <label>per page</label>
            </div>
        </form>

        <!-- Grade Distribution -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <h2>Grade Distribution</h2>
                <?php if (!empty($gradeFilter)): ?>
                <a href="vendor-srs-list.php<?php echo !empty($tierFilter) || !empty($searchQuery) ? '?' . http_build_query(array_filter(['tier' => $tierFilter, 'search' => $searchQuery])) : ''; ?>" style="font-size: 13px; color: #6b7280; text-decoration: none;">Clear grade filter</a>
                <?php endif; ?>
            </div>
            <div style="padding: 20px; display: flex; gap: 15px; flex-wrap: wrap;">
                <?php foreach ($stats['grades'] as $grade => $count): ?>
                <a href="vendor-srs-list.php?grade=<?php echo $grade; ?><?php echo !empty($tierFilter) ? '&tier=' . urlencode($tierFilter) : ''; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>"
                   style="text-align: center; min-width: 60px; text-decoration: none; padding: 10px; border-radius: 8px; transition: background 0.2s; <?php echo $gradeFilter === $grade ? 'background: #e5e7eb;' : ''; ?>"
                   class="grade-filter-item"
                   title="Filter by Grade <?php echo $grade; ?>">
                    <div class="score-grade grade-<?php echo strtolower($grade); ?>" style="width: 40px; height: 40px; font-size: 18px; margin: 0 auto 5px;">
                        <?php echo $grade; ?>
                    </div>
                    <div style="font-size: 18px; font-weight: 600; color: #333;"><?php echo $count; ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$isAuditor): ?>
        <!-- Hidden form for Score Selected -->
        <form id="scoreSelectedForm" method="POST" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="score_selected" value="1">
            <input type="hidden" name="selected_vendor_ids" id="selectedVendorIds" value="">
        </form>

        <!-- Hidden form for Eval Selected -->
        <form id="evalSelectedForm" method="POST" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="eval_selected" value="1">
            <input type="hidden" name="eval_vendor_ids" id="evalVendorIds" value="">
        </form>
        <?php endif; ?>

        <!-- 90-Day Compliance Report Modal -->
        <div id="complianceReportModal" data-modal-backdrop style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 0; width: 460px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,0.3);" data-stop-propagation>
                <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #333;">90-Day Compliance Report</h3>
                    <button type="button" data-close="compliance" style="background: none; border: none; font-size: 20px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <form id="complianceReportForm" method="GET" action="vendor-srs-compliance-report.php" target="_blank">
                    <div style="padding: 24px;">
                        <p style="margin: 0 0 18px; font-size: 13px; color: #666;">
                            Generates a fiscal-quarter compliance summary showing the average UpGuard SRS, Shodan, and combined scores for all Tier&nbsp;1 vendors and for FairTPRM.
                        </p>
                        <div style="margin-bottom: 18px;">
                            <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px;">Fiscal Quarter</label>
                            <select name="quarter" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                <option value="Q1">Q1 &mdash; Jan 1 to Apr 1</option>
                                <option value="Q2">Q2 &mdash; Apr 1 to Jul 1</option>
                                <option value="Q3">Q3 &mdash; Jul 1 to Oct 1</option>
                                <option value="Q4">Q4 &mdash; Oct 1 to Dec 31</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 0;">
                            <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px;">Year</label>
                            <input type="number" name="year" required min="2020" max="2099" value="<?php echo (int)date('Y'); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                    </div>
                    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; border-radius: 0 0 12px 12px;">
                        <button type="button" data-close="compliance" style="padding: 8px 18px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; cursor: pointer; color: #374151;">Cancel</button>
                        <button type="submit" style="padding: 8px 18px; border: none; border-radius: 6px; background: var(--theme-button-color); color: white; font-size: 13px; font-weight: 500; cursor: pointer;">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Re-Tier Modal -->
        <div id="retierModal" data-modal-backdrop style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 0; width: 460px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,0.3);" data-stop-propagation>
                <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #333;">Re-Tier Selected Vendors</h3>
                    <button type="button" data-action="closeRetierModal" style="background: none; border: none; font-size: 20px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                </div>
                <form id="retierForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="retier_selected" value="1">
                    <input type="hidden" name="retier_vendor_ids" id="retierVendorIds" value="">
                    <div style="padding: 24px;">
                        <p id="retierCountLabel" style="margin: 0 0 20px; font-size: 13px; color: #666;">0 vendor(s) will be updated.</p>
                        <div style="margin-bottom: 18px;">
                            <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px;">New Tier</label>
                            <select name="new_tier" id="retierTierSelect" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                <option value="">-- Select Tier --</option>
                                <option value="1">Tier 1 (<?php echo $scoringConfig['tier1_days']; ?>-day rescore)</option>
                                <option value="2">Tier 2 (<?php echo $scoringConfig['tier2_days']; ?>-day rescore)</option>
                                <option value="3">Tier 3 (<?php echo $scoringConfig['tier3_days']; ?>-day rescore)</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 0;">
                            <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px;">Justification</label>
                            <textarea name="tier_justification" id="retierJustification" required rows="3" placeholder="Reason for tier change..." style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical; font-family: inherit;"></textarea>
                        </div>
                    </div>
                    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; border-radius: 0 0 12px 12px;">
                        <button type="button" data-action="closeRetierModal" style="padding: 8px 18px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; cursor: pointer; color: #374151;">Cancel</button>
                        <button type="submit" style="padding: 8px 18px; border: none; border-radius: 6px; background: #6366f1; color: white; font-size: 13px; font-weight: 500; cursor: pointer;">Apply Tier Change</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Vendors Table -->
        <div class="card">
            <div class="card-header">
                <h2>Vendors (<?php echo $displayTotalVendors; ?>)</h2>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="selectedCount" style="font-size: 13px; color: #666; display: none;">0 selected</span>
                    <?php if (!$isAuditor): ?>
                    <button type="button" id="evalSelectedBtn" style="display: none; background: #0ea5e9; color: white; padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer;" data-action="evalSelected">Mark as Eval</button>
                    <button type="button" id="retierSelectedBtn" style="display: none; background: #6366f1; color: white; padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer;" data-action="openRetierModal">Re-Tier Selected</button>
                    <?php if ($srsAvailable || $shodanAvailable): ?>
                    <button type="button" id="scoreSelectedBtn" style="display: none; background: #059669; color: white; padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer;" data-action="scoreSelected">Score Selected</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($paginatedVendors)): ?>
            <div class="empty-state">
                <div class="icon"><img src="app/icons/file-shield-02.svg" alt="" width="32" height="32"></div>
                <p>No vendors found with domains configured.</p>
                <p style="margin-top: 8px; font-size: 13px;">Add vendor domains in the onboarding requests to enable SRS scoring.</p>
            </div>
            <?php else: ?>
            <?php if ($isCveSearch): ?>
            <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; font-size: 13px; color: #991b1b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <span><strong>CVE Search:</strong> <?php echo count($vendors); ?> vendor(s) affected by <?php echo e($cveSearchQuery); ?></span>
                <?php if (preg_match('/^CVE-\d{4}-\d+$/', $cveSearchQuery)): ?>
                <a href="https://nvd.nist.gov/vuln/detail/<?php echo urlencode($cveSearchQuery); ?>" target="_blank" rel="noopener" style="padding: 4px 12px; background: #991b1b; color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600;">View on NVD</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;"><input type="checkbox" id="selectAllVendors" title="Select all"></th>
                        <th class="sortable <?php echo $sortColumn === 'vendor_name' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('vendor_name', $sortColumn, $sortOrder); ?>">
                                Vendor<span class="sort-icon"><?php echo getSortIndicator('vendor_name', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $sortColumn === 'vendor_tier' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('vendor_tier', $sortColumn, $sortOrder); ?>">
                                Tier<span class="sort-icon"><?php echo getSortIndicator('vendor_tier', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $sortColumn === 'current_srs_score' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('current_srs_score', $sortColumn, $sortOrder); ?>">
                                <?php echo e($upguardName); ?><span class="sort-icon"><?php echo getSortIndicator('current_srs_score', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <?php if ($shodanColumnsExist): ?>
                        <th class="sortable <?php echo $sortColumn === 'current_shodan_score' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('current_shodan_score', $sortColumn, $sortOrder); ?>">
                                <?php echo e($shodanName); ?><span class="sort-icon"><?php echo getSortIndicator('current_shodan_score', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <?php endif; ?>
                        <th class="sortable <?php echo $sortColumn === 'avg_score' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('avg_score', $sortColumn, $sortOrder); ?>">
                                Avg Score<span class="sort-icon"><?php echo getSortIndicator('avg_score', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $sortColumn === 'last_srs_score_at' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('last_srs_score_at', $sortColumn, $sortOrder); ?>">
                                Last Scored<span class="sort-icon"><?php echo getSortIndicator('last_srs_score_at', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $sortColumn === 'scheduled_score_at' ? 'active' : ''; ?>">
                            <a href="<?php echo buildSortUrl('scheduled_score_at', $sortColumn, $sortOrder); ?>">
                                Scheduled Score<span class="sort-icon"><?php echo getSortIndicator('scheduled_score_at', $sortColumn, $sortOrder); ?></span>
                            </a>
                        </th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paginatedVendors as $vendor):
                        $isShadowSaas = !empty($vendor['is_shadow_saas']);
                        $hasScore = !empty($vendor['current_srs_score']);
                        $grade = $hasScore ? $srsService->calculateGrade((int)$vendor['current_srs_score']) : null;
                        $needsRescore = !$isShadowSaas ? $srsService->needsRescore($vendor) : false;
                    ?>
                    <tr class="vendor-row<?php echo $isShadowSaas ? '' : ''; ?>" data-vendor-id="<?php echo (int)$vendor['id']; ?>"<?php echo $isShadowSaas ? ' style="background: #fffbeb;"' : ''; ?>>
                        <td style="text-align: center;" onclick="event.stopPropagation();">
                            <?php if (!$isShadowSaas && !empty($vendor['vendor_domain'])): ?>
                            <input type="checkbox" class="vendor-checkbox" value="<?php echo (int)$vendor['id']; ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="vendor-name">
                                <?php if ($isShadowSaas): ?>
                                <img src="app/icons/alert-square.svg"
                                     alt="Shadow SaaS" width="16" height="16"
                                     style="vertical-align: middle; margin-right: 4px; opacity: 0.7;">
                                <?php else: ?>
                                <img src="api/vendor-favicon.php?vendor_id=<?php echo (int)$vendor['id']; ?>"
                                     alt="" width="16" height="16"
                                     style="vertical-align: middle; margin-right: 4px;"
                                     onerror="this.style.display='none'">
                                <?php endif; ?>
                                <?php echo e($vendor['vendor_name'] ?? 'Unnamed'); ?>
                                <?php if (($vendor['status'] ?? '') === 'evaluation'): ?>
                                <span style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 8px; margin-left: 4px; font-weight: 500;">Eval</span>
                                <?php endif; ?>
                                <?php if (($vendor['vendor_use_ai'] ?? '') === 'yes'): ?>
                                <span title="Services Use AI" style="display: inline-flex; align-items: center; gap: 2px; font-size: 10px; background: linear-gradient(135deg, #ede9fe, #fce7f3); color: #6d28d9; padding: 1px 6px; border-radius: 8px; margin-left: 4px; font-weight: 600; vertical-align: middle;"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2zm6 10l.9 2.6L21.5 15.5l-2.6.9L18 19l-.9-2.6L14.5 15.5l2.6-.9L18 12zM6 14l.7 2L8.5 16.5l-1.8.5L6 19l-.7-2L3.5 16.5l1.8-.5L6 14z"/></svg>AI</span>
                                <?php endif; ?>
                                <?php if ($isShadowSaas): ?>
                                <span style="font-size: 10px; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 8px; margin-left: 4px; font-weight: 500;">Shadow</span>
                                <?php endif; ?>
                                <?php if (!empty($subprocessorVendorIds[(int)$vendor['id']])): ?>
                                <span style="font-size: 10px; background: #f3e8ff; color: #7c3aed; padding: 1px 6px; border-radius: 8px; margin-left: 4px; font-weight: 500;">Subprocessor</span>
                                <?php endif; ?>
                            </div>
                            <div class="vendor-domain"><?php echo e($vendor['vendor_domain']); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($vendor['vendor_tier'])): ?>
                            <span class="tier-badge tier-<?php echo e($vendor['vendor_tier']); ?>">
                                <?php echo e($srsService->getTierDisplayName($vendor['vendor_tier'])); ?>
                            </span>
                            <?php else: ?>
                            <span class="tier-badge tier-none">No Tier</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasScore): ?>
                            <div class="score-display">
                                <span class="score-value"><?php echo displayUpguardScore((int)$vendor['current_srs_score'], $upguardDisplayMode, $upguardMaxScore); ?></span>
                                <span class="score-grade grade-<?php echo strtolower($grade); ?>"><?php echo $grade; ?></span>
                            </div>
                            <?php else: ?>
                            <span class="no-score">Not scored</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($shodanColumnsExist): ?>
                        <td>
                            <?php
                            $hasShodanScore = !empty($vendor['current_shodan_score']);
                            $shodanGrade = $hasShodanScore ? $shodanService->calculateGrade((int)$vendor['current_shodan_score']) : null;
                            $vendorTL = $vendor['shodan_traffic_light'] ?? null;
                            $tlDotColors = ['green' => '#16a34a', 'yellow' => '#eab308', 'red' => '#dc2626'];
                            ?>
                            <?php if ($hasShodanScore): ?>
                            <div class="score-display">
                                <?php if ($vendorTL && isset($tlDotColors[$vendorTL])): ?>
                                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $tlDotColors[$vendorTL]; ?>; box-shadow: 0 0 4px <?php echo $tlDotColors[$vendorTL]; ?>; flex-shrink: 0;" title="<?php echo e(ucfirst($vendorTL)); ?>"></span>
                                <?php endif; ?>
                                <span class="score-value"><?php echo e($vendor['current_shodan_score']); ?>%</span>
                                <span class="score-grade grade-<?php echo strtolower($shodanGrade); ?>"><?php echo $shodanGrade; ?></span>
                            </div>
                            <?php else: ?>
                            <span class="no-score">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php
                            $avgScore = $vendor['avg_score'];
                            $avgGrade = calculateAvgGrade($avgScore, $shodanScoringConfig);
                            ?>
                            <?php if ($avgScore !== null): ?>
                            <div class="score-display">
                                <span class="score-value"><?php echo $avgScore; ?>%</span>
                                <span class="score-grade grade-<?php echo strtolower($avgGrade); ?>"><?php echo $avgGrade; ?></span>
                            </div>
                            <?php else: ?>
                            <span class="no-score">Not scored</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($vendor['last_srs_score_at'])): ?>
                            <?php echo date('M j, Y', strtotime($vendor['last_srs_score_at'])); ?>
                            <?php else: ?>
                            <span style="color: #9ca3af;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($vendor['scheduled_score_at'])): ?>
                                <?php
                                $scheduledDate = strtotime($vendor['scheduled_score_at']);
                                $isPastDue = $scheduledDate < time();
                                ?>
                                <span style="<?php echo $isPastDue ? 'color: #dc2626; font-weight: 500;' : 'color: #374151;'; ?>">
                                    <?php echo date('M j, Y', $scheduledDate); ?>
                                </span>
                            <?php elseif (empty($vendor['vendor_tier'])): ?>
                                <span style="color: #9ca3af;">No tier set</span>
                            <?php elseif (empty($vendor['last_srs_score_at'])): ?>
                                <span style="color: #9ca3af;">Not yet scored</span>
                            <?php else: ?>
                                <span style="color: #9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isShadowSaas): ?>
                            <span class="status-badge" style="background: #fef3c7; color: #92400e;">Shadow SaaS</span>
                            <?php elseif (($vendor['status'] ?? '') === 'inactive'): ?>
                            <span class="status-badge" style="background: #f3f4f6; color: #6b7280;">Inactive</span>
                            <?php elseif (($vendor['status'] ?? '') === 'in_review'): ?>
                            <span class="status-badge" style="background: #ede9fe; color: #6d28d9;">In Review</span>
                            <?php elseif ($needsRescore): ?>
                            <span class="status-badge status-needs-rescore">Needs Rescore</span>
                            <?php elseif ($hasScore): ?>
                            <span class="status-badge status-current">Current</span>
                            <?php endif; ?>
                        </td>
                        <td onclick="event.stopPropagation();">
                            <?php if ($isShadowSaas): ?>
                            <a href="shadow-saas.php?search=<?php echo urlencode($vendor['vendor_domain']); ?>" class="btn-sm btn-view">Manage</a>
                            <?php else: ?>
                            <a href="vendor-srs-details.php?id=<?php echo $vendor['id']; ?>" class="btn-sm btn-view">View</a>
                            <?php endif; ?>
                            <?php if (!$isShadowSaas && ($vendor['vendor_use_ai'] ?? '') === 'yes' && ($isAdmin || $isCyberTPRM || $acl->hasGroup('cyber_grc'))): ?>
                            <?php $existingAiBtn = $aiRiskMap[(int)$vendor['id']] ?? null; ?>
                            <a href="#" class="ai-risk-link btn-sm"
                               data-vendor-id="<?php echo (int)$vendor['id']; ?>"
                               data-vendor-name="<?php echo e($vendor['vendor_name'] ?? ''); ?>"
                               data-existing="<?php echo $existingAiBtn ? '1' : '0'; ?>"
                               title="<?php echo $existingAiBtn ? 'Update AI risk register entry' : 'Add AI risk register entry'; ?>"
                               style="background: <?php echo $existingAiBtn ? '#ede9fe' : '#f5f3ff'; ?>; color: #6d28d9; border: 1px solid #ddd6fe; text-decoration: none; margin-left: 4px; display: inline-flex; align-items: center; gap: 3px;">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2zm6 10l.9 2.6L21.5 15.5l-2.6.9L18 19l-.9-2.6L14.5 15.5l2.6-.9L18 12z"/></svg>
                                <?php echo $existingAiBtn ? 'Update AI Risk' : 'AI Risk'; ?>
                            </a>
                            <?php endif; ?>
                            <?php if (!$isAuditor): ?>
                            <?php if (!$isShadowSaas && ($srsAvailable || $shodanAvailable) && !empty($vendor['vendor_domain'])): ?>
                            <?php if ($srsAvailable && $shodanAvailable): ?>
                            <div style="display: inline-block; position: relative;" class="vendor-score-dropdown">
                                <button type="button" class="btn-sm btn-rescore" data-action="toggleVendorScoreMenu">Score &#9662;</button>
                                <div class="vendor-score-menu" style="display: none; position: absolute; right: 0; top: 100%; margin-top: 2px; background: white; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; min-width: 150px; overflow: hidden;">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                        <input type="hidden" name="rescore_vendor_id" value="<?php echo $vendor['id']; ?>">
                                        <button type="submit" name="rescore_provider" value="all" data-confirm="Score <?php echo e($vendor['vendor_name']); ?> with all providers?" style="display: block; width: 100%; padding: 6px 12px; border: none; background: none; text-align: left; cursor: pointer; font-size: 12px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray">Score All</button>
                                        <button type="submit" name="rescore_provider" value="upguard" data-confirm="Score <?php echo e($vendor['vendor_name']); ?> with <?php echo e($upguardName); ?>?" style="display: block; width: 100%; padding: 6px 12px; border: none; background: none; text-align: left; cursor: pointer; font-size: 12px; color: #374151; border-bottom: 1px solid #f3f4f6;" class="hover-bg-gray"><?php echo e($upguardName); ?></button>
                                        <button type="submit" name="rescore_provider" value="shodan" data-confirm="Score <?php echo e($vendor['vendor_name']); ?> with <?php echo e($shodanName); ?>?" style="display: block; width: 100%; padding: 6px 12px; border: none; background: none; text-align: left; cursor: pointer; font-size: 12px; color: #374151;" class="hover-bg-gray"><?php echo e($shodanName); ?></button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="rescore_vendor_id" value="<?php echo $vendor['id']; ?>">
                                <input type="hidden" name="rescore_provider" value="all">
                                <button type="submit" class="btn-sm btn-rescore" data-confirm="Score <?php echo e($vendor['vendor_name']); ?>?">Score</button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        // Prepare detail row data
                        $colCount = 9 + ($shodanColumnsExist ? 1 : 0);
                        $contactName = $vendor['primary_contact_details'] ?? '';
                        $contactTitle = $vendor['primary_contact_title'] ?? '';
                        $contactPhone = $vendor['primary_contact_phone'] ?? '';
                        $contactEmail = $vendor['primary_contact_email'] ?? '';
                        $productService = $vendor['product_service_description'] ?? '';
                        $stk = $vendor['stakeholder_name'] ?? '';
                        $stkEmail = $vendor['stakeholder_email'] ?? '';
                    ?>
                    <tr class="vendor-detail-row" data-detail-for="<?php echo (int)$vendor['id']; ?>">
                        <td colspan="<?php echo $colCount; ?>">
                            <div class="detail-panel">
                                <div class="detail-item">
                                    <div class="detail-label">Domain</div>
                                    <div class="detail-value<?php echo empty($vendor['vendor_domain']) ? ' empty' : ''; ?>"><?php echo !empty($vendor['vendor_domain']) ? e($vendor['vendor_domain']) : 'Not set'; ?></div>
                                    <?php if ($isAdmin || $isCyberTPRM): ?>
                                    <a href="#" class="add-case-link" data-vendor-id="<?php echo (int)$vendor['id']; ?>" data-vendor-name="<?php echo e($vendor['vendor_name'] ?? ''); ?>" style="display: inline-block; margin-top: 6px; font-size: 12px; color: var(--theme-header-color, #35a0a3); text-decoration: none; font-weight: 500;">+ Add Case</a>
                                    <?php endif; ?>
                                    <?php if (($vendor['vendor_use_ai'] ?? '') === 'yes' && ($isAdmin || $isCyberTPRM || $acl->hasGroup('cyber_grc'))): ?>
                                    <?php $existingAi = $aiRiskMap[(int)$vendor['id']] ?? null; ?>
                                    <a href="#" class="ai-risk-link" data-vendor-id="<?php echo (int)$vendor['id']; ?>" data-vendor-name="<?php echo e($vendor['vendor_name'] ?? ''); ?>" data-existing="<?php echo $existingAi ? '1' : '0'; ?>" style="display: inline-block; margin-top: 6px; margin-left: 10px; font-size: 12px; color: #6d28d9; text-decoration: none; font-weight: 500;"><?php echo $existingAi ? '✎ Update AI Risk' : '+ Add AI Risk'; ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Stakeholder</div>
                                    <div class="detail-value<?php echo empty($stk) ? ' empty' : ''; ?>">
                                        <?php if (!empty($stk)): ?>
                                            <?php echo e($stk); ?>
                                            <?php if (!empty($stkEmail)): ?><br><span style="font-size: 12px; color: #6b7280;"><?php echo e($stkEmail); ?></span><?php endif; ?>
                                        <?php else: ?>
                                            Not assigned
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Contact Information</div>
                                    <div class="detail-value<?php echo (empty($contactName) && empty($contactEmail)) ? ' empty' : ''; ?>">
                                        <?php if (!empty($contactName) || !empty($contactEmail)): ?>
                                            <?php if (!empty($contactName)): ?><?php echo e($contactName); ?><?php endif; ?>
                                            <?php if (!empty($contactTitle)): ?><br><span style="font-size: 12px; color: #6b7280;"><?php echo e($contactTitle); ?></span><?php endif; ?>
                                            <?php if (!empty($contactEmail)): ?><br><span style="font-size: 12px; color: #6b7280;"><?php echo e($contactEmail); ?></span><?php endif; ?>
                                            <?php if (!empty($contactPhone)): ?><br><span style="font-size: 12px; color: #6b7280;"><?php echo e($contactPhone); ?></span><?php endif; ?>
                                        <?php else: ?>
                                            Not provided
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Product / Service</div>
                                    <div class="detail-value scrollable<?php echo empty($productService) ? ' empty' : ''; ?>"><?php echo !empty($productService) ? e($productService) : 'Not provided'; ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1 || $totalVendors > 0): ?>
            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalVendors); ?> of <?php echo $totalVendors; ?> vendors<?php if ($shadowSaasTotal > 0): ?> (incl. <?php echo $shadowSaasTotal; ?> shadow SaaS)<?php endif; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                <ul class="pagination">
                    <?php if ($currentPage > 1): ?>
                    <li><a href="<?php echo buildPageUrl(1); ?>">&laquo;</a></li>
                    <li><a href="<?php echo buildPageUrl($currentPage - 1); ?>">&lsaquo;</a></li>
                    <?php else: ?>
                    <li class="disabled"><span>&laquo;</span></li>
                    <li class="disabled"><span>&lsaquo;</span></li>
                    <?php endif; ?>

                    <?php
                    // Show page numbers
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);

                    if ($startPage > 1): ?>
                    <li><a href="<?php echo buildPageUrl(1); ?>">1</a></li>
                    <?php if ($startPage > 2): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="<?php echo $i === $currentPage ? 'active' : ''; ?>">
                        <?php if ($i === $currentPage): ?>
                        <span><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                    <li><a href="<?php echo buildPageUrl($totalPages); ?>"><?php echo $totalPages; ?></a></li>
                    <?php endif; ?>

                    <?php if ($currentPage < $totalPages): ?>
                    <li><a href="<?php echo buildPageUrl($currentPage + 1); ?>">&rsaquo;</a></li>
                    <li><a href="<?php echo buildPageUrl($totalPages); ?>">&raquo;</a></li>
                    <?php else: ?>
                    <li class="disabled"><span>&rsaquo;</span></li>
                    <li class="disabled"><span>&raquo;</span></li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

            <?php if ($isAdmin || $isCyberTPRM): ?>
            <!-- Add Case Modal -->
            <div id="addCaseModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                <div style="background: white; padding: 30px; border-radius: 8px; max-width: 550px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
                    <h3 style="margin: 0 0 5px 0; color: #333; font-size: 20px;">Create Case</h3>
                    <div id="addCaseVendorLabel" style="margin-bottom: 20px; font-size: 13px; color: #666;"></div>
                    <form method="POST" action="vendor-srs-list.php" id="addCaseForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="add_case" value="1">
                        <input type="hidden" name="case_vendor_id" id="addCaseVendorId" value="">
                        <input type="hidden" name="case_assigned_to" id="addCaseAssignedTo" value="">

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;">Title <span style="color: #dc3545;">*</span></label>
                            <input type="text" name="case_title" id="addCaseTitle" required placeholder="Case title..." style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;">Description</label>
                            <textarea name="case_description" id="addCaseDescription" rows="3" placeholder="Add details..." style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box;"></textarea>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;">Due Date</label>
                            <input type="date" name="case_due_date" id="addCaseDueDate" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: #333;">Assign To</label>
                            <div class="autocomplete-wrapper" style="position: relative;">
                                <input type="text" id="addCaseAssigneeInput" placeholder="Search by name or username..." autocomplete="off" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                <div id="addCaseAssigneeSuggestions" class="autocomplete-suggestions" style="display: none;"></div>
                            </div>
                            <div style="font-size: 11px; color: #999; margin-top: 4px;">Start typing to search for a user to assign this case to.</div>
                        </div>

                        <div style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" id="addCaseCancelBtn" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 13px;">Cancel</button>
                            <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-button-color, #35a0a3); color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Create Case</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isAdmin || $isCyberTPRM || $acl->hasGroup('cyber_grc')): ?>
            <!-- AI Risk Register Modal (does not close on backdrop click) -->
            <div id="aiRiskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10001; align-items: center; justify-content: center;">
                <div style="background: white; padding: 28px 32px; border-radius: 8px; max-width: 720px; width: 92%; box-shadow: 0 4px 24px rgba(0,0,0,0.3); max-height: 92vh; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                        <h3 id="aiRiskModalTitle" style="margin: 0; color: #333; font-size: 20px;">Add AI Risk to Register</h3>
                        <button type="button" id="aiRiskCloseX" aria-label="Close" style="background: none; border: none; font-size: 22px; color: #9ca3af; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
                    </div>
                    <div id="aiRiskVendorLabel" style="margin-bottom: 18px; font-size: 13px; color: #666;"></div>
                    <form method="POST" action="vendor-srs-list.php" id="aiRiskForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="create_ai_risk" id="aiRiskActionCreate" value="1">
                        <input type="hidden" name="update_ai_risk" id="aiRiskActionUpdate" value="" disabled>
                        <input type="hidden" name="ai_risk_vendor_id" id="aiRiskVendorId" value="">
                        <input type="hidden" name="ai_risk_id" id="aiRiskId" value="">
                        <input type="hidden" name="ai_risk_owner_id" id="aiRiskOwnerId" value="">

                        <div style="margin-bottom: 14px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Risk Title <span style="color: #dc3545;">*</span></label>
                            <input type="text" name="ai_risk_title" id="aiRiskTitle" required style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box; background: #f9fafb;">
                        </div>

                        <div style="margin-bottom: 14px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Description</label>
                            <textarea name="ai_risk_description" id="aiRiskDescription" rows="2" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Category</label>
                                <select name="ai_risk_category" id="aiRiskCategory" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <option value="strategic">Strategic</option>
                                    <option value="operational">Operational</option>
                                    <option value="financial">Financial</option>
                                    <option value="compliance">Compliance</option>
                                    <option value="reputational">Reputational</option>
                                    <option value="technology" selected>Technology</option>
                                    <option value="third_party">Third Party</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Risk Owner</label>
                                <div style="position: relative;">
                                    <input type="text" id="aiRiskOwnerInput" placeholder="Search cyber_grc / cyber_tprm..." autocomplete="off" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <div id="aiRiskOwnerSuggestions" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 220px; overflow-y: auto; z-index: 10; margin-top: 2px;"></div>
                                </div>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Status</label>
                                <select name="ai_risk_status" id="aiRiskStatus" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <option value="identified">Identified</option>
                                    <option value="assessing">Assessing</option>
                                    <option value="treating">Treating</option>
                                    <option value="monitoring">Monitoring</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                        </div>

                        <h4 style="font-size: 13px; color: #555; margin: 16px 0 8px; border-top: 1px solid #e5e7eb; padding-top: 14px;">Risk Assessment</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Likelihood</label>
                                <select name="ai_risk_likelihood" id="aiRiskLikelihood" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <option value="rare">Rare (1)</option>
                                    <option value="unlikely">Unlikely (2)</option>
                                    <option value="possible" selected>Possible (3)</option>
                                    <option value="likely">Likely (4)</option>
                                    <option value="almost_certain">Almost Certain (5)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Impact</label>
                                <select name="ai_risk_impact" id="aiRiskImpact" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <option value="insignificant">Insignificant (1)</option>
                                    <option value="minor">Minor (2)</option>
                                    <option value="moderate" selected>Moderate (3)</option>
                                    <option value="major">Major (4)</option>
                                    <option value="catastrophic">Catastrophic (5)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Residual Score</label>
                                <input type="number" name="ai_risk_residual" id="aiRiskResidual" min="0" max="25" step="0.01" placeholder="Auto" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                            </div>
                        </div>

                        <h4 style="font-size: 13px; color: #555; margin: 16px 0 8px; border-top: 1px solid #e5e7eb; padding-top: 14px;">Treatment</h4>
                        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 14px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Treatment Strategy</label>
                                <select name="ai_risk_treatment" id="aiRiskTreatment" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                    <option value="accept">Accept</option>
                                    <option value="mitigate" selected>Mitigate</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="avoid">Avoid</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Treatment Plan</label>
                                <textarea name="ai_risk_treatment_plan" id="aiRiskTreatmentPlan" rows="2" style="width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box;"></textarea>
                            </div>
                        </div>

                        <div style="margin-bottom: 14px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Mitigating Controls</label>
                            <select name="ai_risk_control_ids[]" id="aiRiskControls" multiple style="width: 100%; min-height: 110px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                                <?php foreach ($aiAllControls as $ac): ?>
                                <option value="<?php echo (int)$ac['id']; ?>"><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size: 11px; color: #9ca3af; margin-top: 3px;">Hold Ctrl/Cmd to select multiple.</div>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333;">Next Review Date</label>
                            <input type="date" name="ai_risk_review_date" id="aiRiskReviewDate" style="width: 200px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                        </div>

                        <div style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid #e5e7eb; padding-top: 16px;">
                            <button type="button" id="aiRiskCancelBtn" style="padding: 10px 20px; border: 1px solid #d1d5db; background: white; color: #666; border-radius: 6px; cursor: pointer; font-size: 13px;">Cancel</button>
                            <button type="submit" id="aiRiskSubmitBtn" style="padding: 10px 22px; border: none; background: #6d28d9; color: white; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Create Risk</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            </main>
        </div>

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

    <script src="app/js/vendor-search.js?v=2.5.5b"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Per-vendor score dropdown toggle
        function toggleVendorScoreMenu(btn) {
            document.querySelectorAll('.vendor-score-menu').forEach(function(m) {
                if (m !== btn.nextElementSibling) m.style.display = 'none';
            });
            var menu = btn.nextElementSibling;
            if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.vendor-score-dropdown')) {
                document.querySelectorAll('.vendor-score-menu').forEach(function(m) { m.style.display = 'none'; });
            }
        });

        // Per-page selector (matches vendor-onboarding-list.php pattern)
        document.addEventListener('change', function(e) {
            var el = e.target.closest('[data-action="changePerPage"]');
            if (!el) return;
            var params = new URLSearchParams(window.location.search);
            params.set('per_page', el.value);
            params.delete('page');
            window.location.href = '?' + params.toString();
        });

        // Select All / Score Selected / Re-Tier Selected
        (function() {
            var selectAll = document.getElementById('selectAllVendors');
            if (!selectAll) return;

            function updateSelectedCount() {
                var checked = document.querySelectorAll('.vendor-checkbox:checked');
                var countEl = document.getElementById('selectedCount');
                var scoreBtn = document.getElementById('scoreSelectedBtn');
                var retierBtn = document.getElementById('retierSelectedBtn');
                var evalBtn = document.getElementById('evalSelectedBtn');
                var show = checked.length > 0;

                if (countEl) {
                    countEl.style.display = show ? '' : 'none';
                    countEl.textContent = checked.length + ' selected';
                }
                if (scoreBtn) scoreBtn.style.display = show ? '' : 'none';
                if (retierBtn) retierBtn.style.display = show ? '' : 'none';
                if (evalBtn) evalBtn.style.display = show ? '' : 'none';
            }

            selectAll.addEventListener('change', function() {
                var checkboxes = document.querySelectorAll('.vendor-checkbox');
                checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateSelectedCount();
            });

            document.body.addEventListener('change', function(e) {
                if (!e.target.classList.contains('vendor-checkbox')) return;
                var all = document.querySelectorAll('.vendor-checkbox');
                var checked = document.querySelectorAll('.vendor-checkbox:checked');
                selectAll.checked = all.length === checked.length && all.length > 0;
                updateSelectedCount();
            });

            window.scoreSelected = function() {
                var checked = document.querySelectorAll('.vendor-checkbox:checked');
                if (checked.length === 0) return;
                if (!confirm('Score ' + checked.length + ' selected vendor(s)?')) return;
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                document.getElementById('selectedVendorIds').value = ids.join(',');
                document.getElementById('scoreSelectedForm').submit();
            };

            window.evalSelected = function() {
                var checked = document.querySelectorAll('.vendor-checkbox:checked');
                if (checked.length === 0) return;
                if (!confirm('Mark ' + checked.length + ' selected vendor(s) as Evaluation? This will set their onboarding status to Evaluation and assign VID 99999.')) return;
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                document.getElementById('evalVendorIds').value = ids.join(',');
                document.getElementById('evalSelectedForm').submit();
            };

            // Re-Tier modal
            var retierModal = document.getElementById('retierModal');

            window.openRetierModal = function() {
                var checked = document.querySelectorAll('.vendor-checkbox:checked');
                if (checked.length === 0) return;
                var ids = [];
                checked.forEach(function(cb) { ids.push(cb.value); });
                document.getElementById('retierVendorIds').value = ids.join(',');
                document.getElementById('retierCountLabel').textContent = checked.length + ' vendor(s) will be updated.';
                document.getElementById('retierTierSelect').value = '';
                document.getElementById('retierJustification').value = '';
                retierModal.style.display = 'flex';
            };

            window.closeRetierModal = function() {
                retierModal.style.display = 'none';
            };

            // Close on backdrop click
            retierModal.addEventListener('click', function(e) {
                if (e.target === retierModal) retierModal.style.display = 'none';
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && retierModal.style.display === 'flex') {
                    retierModal.style.display = 'none';
                }
            });

            // 90-Day Compliance Report modal
            // Close button is wired via document-level CAPTURE-phase delegation.
            // event-handlers.js attaches a [data-stop-propagation] handler on
            // document.body in capture, which calls stopPropagation() and
            // prevents both bubble-phase delegation and element-level listeners
            // inside the modal from firing. Listening on document in capture
            // runs before body in the capture order, so our handler executes.
            var complianceModal = document.getElementById('complianceReportModal');
            window.openComplianceReportModal = function() {
                if (complianceModal) complianceModal.style.display = 'flex';
            };
            if (complianceModal) {
                var closeCompliance = function() { complianceModal.style.display = 'none'; };
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('[data-close="compliance"]');
                    if (btn && complianceModal.contains(btn)) {
                        e.preventDefault();
                        closeCompliance();
                    } else if (e.target === complianceModal) {
                        closeCompliance();
                    }
                }, true);
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && complianceModal.style.display === 'flex') closeCompliance();
                });
            }
        })();
    </script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Expandable vendor rows
        document.querySelectorAll('tr.vendor-row').forEach(function(row) {
            row.addEventListener('click', function(e) {
                // Don't expand if clicking a link, button, input, or form
                if (e.target.closest('a, button, input, form, .vendor-score-dropdown')) return;
                var vid = this.getAttribute('data-vendor-id');
                var detail = document.querySelector('tr.vendor-detail-row[data-detail-for="' + vid + '"]');
                if (!detail) return;
                var isOpen = detail.classList.contains('open');
                // Close all other open rows
                document.querySelectorAll('tr.vendor-detail-row.open').forEach(function(r) { r.classList.remove('open'); });
                document.querySelectorAll('tr.vendor-row.expanded').forEach(function(r) { r.classList.remove('expanded'); });
                if (!isOpen) {
                    detail.classList.add('open');
                    this.classList.add('expanded');
                }
            });
        });
    </script>
    <?php if ($isAdmin || $isCyberTPRM): ?>
    <script nonce="<?php echo cspNonce(); ?>">
        // Add Case modal
        (function() {
            var modal = document.getElementById('addCaseModal');
            if (!modal) return;

            var cancelBtn = document.getElementById('addCaseCancelBtn');
            var titleInput = document.getElementById('addCaseTitle');
            var descInput = document.getElementById('addCaseDescription');
            var dueDateInput = document.getElementById('addCaseDueDate');
            var assigneeInput = document.getElementById('addCaseAssigneeInput');
            var assigneeHidden = document.getElementById('addCaseAssignedTo');
            var vendorIdInput = document.getElementById('addCaseVendorId');
            var vendorLabel = document.getElementById('addCaseVendorLabel');
            var suggestionsBox = document.getElementById('addCaseAssigneeSuggestions');
            var allowedGroups = 'cyber_tprm,administrator';

            function openAddCaseModal(vendorId, vendorName) {
                vendorIdInput.value = vendorId;
                vendorLabel.textContent = vendorName ? 'Vendor: ' + vendorName : '';
                titleInput.value = '';
                descInput.value = '';
                dueDateInput.value = '';
                assigneeInput.value = '';
                assigneeHidden.value = '';
                if (suggestionsBox) suggestionsBox.style.display = 'none';
                modal.style.display = 'flex';
                titleInput.focus();
            }

            function closeAddCaseModal() {
                modal.style.display = 'none';
            }

            // Attach click handlers to all "Add Case" links
            document.querySelectorAll('.add-case-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openAddCaseModal(this.dataset.vendorId, this.dataset.vendorName);
                });
            });

            cancelBtn.addEventListener('click', closeAddCaseModal);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeAddCaseModal();
            });

            // Assignee type-ahead
            if (!assigneeInput || !suggestionsBox) return;

            var debounceTimer = null;
            var highlightedIdx = -1;
            var results = [];

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function searchAssignees(query) {
                if (query.length < 2) {
                    suggestionsBox.style.display = 'none';
                    return;
                }
                suggestionsBox.innerHTML = '<div class="autocomplete-loading">Searching...</div>';
                suggestionsBox.style.display = 'block';

                var url = 'api/search-users.php?q=' + encodeURIComponent(query);
                if (allowedGroups) url += '&groups=' + encodeURIComponent(allowedGroups);

                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.users.length > 0) {
                            results = data.users;
                            highlightedIdx = -1;
                            renderSuggestions(data.users);
                        } else {
                            results = [];
                            suggestionsBox.innerHTML = '<div class="autocomplete-no-results">No users found</div>';
                        }
                    })
                    .catch(function() {
                        results = [];
                        suggestionsBox.innerHTML = '<div class="autocomplete-no-results">Search error</div>';
                    });
            }

            function renderSuggestions(users) {
                suggestionsBox.innerHTML = users.map(function(u, i) {
                    return '<div class="autocomplete-item" data-user-id="' + u.id + '" data-display="' + escapeHtml(u.display) + '" data-index="' + i + '">' +
                        '<div class="user-name">' + escapeHtml(u.display) + '</div>' +
                        (u.email ? '<div class="user-email">' + escapeHtml(u.email) + '</div>' : '') +
                        '</div>';
                }).join('');

                suggestionsBox.querySelectorAll('.autocomplete-item').forEach(function(item) {
                    item.addEventListener('click', function() { selectAssignee(item); });
                });
            }

            function selectAssignee(item) {
                assigneeHidden.value = item.dataset.userId;
                assigneeInput.value = item.dataset.display;
                suggestionsBox.style.display = 'none';
                results = [];
                highlightedIdx = -1;
            }

            function updateHighlight() {
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                items.forEach(function(item, i) {
                    item.classList.toggle('highlighted', i === highlightedIdx);
                });
            }

            assigneeInput.addEventListener('input', function() {
                assigneeHidden.value = '';
                clearTimeout(debounceTimer);
                var val = this.value;
                debounceTimer = setTimeout(function() { searchAssignees(val); }, 300);
            });

            assigneeInput.addEventListener('keydown', function(e) {
                if (suggestionsBox.style.display === 'none') return;
                var items = suggestionsBox.querySelectorAll('.autocomplete-item');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1);
                    updateHighlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIdx = Math.max(highlightedIdx - 1, 0);
                    updateHighlight();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIdx >= 0 && items[highlightedIdx]) {
                        selectAssignee(items[highlightedIdx]);
                    }
                } else if (e.key === 'Escape') {
                    suggestionsBox.style.display = 'none';
                }
            });

            document.addEventListener('click', function(e) {
                if (!assigneeInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });
        })();
    </script>
    <?php endif; ?>
    <?php if ($isAdmin || $isCyberTPRM || $acl->hasGroup('cyber_grc')): ?>
    <script nonce="<?php echo cspNonce(); ?>">
        // AI Risk Register modal — stays open on backdrop click, prefills for update
        (function() {
            var modal = document.getElementById('aiRiskModal');
            if (!modal) return;

            var aiRiskMap = <?php
                $jsMap = [];
                foreach ($aiRiskMap as $vid => $r) {
                    $cids = [];
                    if (!empty($r['control_ids'])) {
                        $cids = array_values(array_filter(array_map('intval', explode(',', $r['control_ids']))));
                    }
                    $jsMap[(string)$vid] = [
                        'id' => (int)$r['id'],
                        'title' => $r['title'] ?? '',
                        'description' => $r['description'] ?? '',
                        'risk_category' => $r['risk_category'] ?? 'technology',
                        'likelihood' => $r['likelihood'] ?? 'possible',
                        'impact' => $r['impact'] ?? 'moderate',
                        'residual_risk_score' => $r['residual_risk_score'] ?? '',
                        'risk_treatment' => $r['risk_treatment'] ?? 'mitigate',
                        'treatment_plan' => $r['treatment_plan'] ?? '',
                        'status' => $r['status'] ?? 'identified',
                        'review_date' => $r['review_date'] ?? '',
                        'owner_user_id' => (int)($r['owner_user_id'] ?? 0),
                        'owner_name' => $r['owner_name'] ?? '',
                        'control_ids' => $cids,
                    ];
                }
                echo json_encode($jsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>;

            var ownersList = <?php
                $jsOwners = [];
                foreach ($aiEligibleOwners as $u) {
                    $jsOwners[] = [
                        'id' => (int)$u['id'],
                        'name' => $u['full_name'] ?? '',
                        'email' => $u['email'] ?? '',
                    ];
                }
                echo json_encode($jsOwners, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>;

            var titleInput = document.getElementById('aiRiskTitle');
            var descInput = document.getElementById('aiRiskDescription');
            var categorySel = document.getElementById('aiRiskCategory');
            var statusSel = document.getElementById('aiRiskStatus');
            var likelihoodSel = document.getElementById('aiRiskLikelihood');
            var impactSel = document.getElementById('aiRiskImpact');
            var residualInput = document.getElementById('aiRiskResidual');
            var treatmentSel = document.getElementById('aiRiskTreatment');
            var treatmentPlanInput = document.getElementById('aiRiskTreatmentPlan');
            var controlsSel = document.getElementById('aiRiskControls');
            var reviewDateInput = document.getElementById('aiRiskReviewDate');
            var ownerInput = document.getElementById('aiRiskOwnerInput');
            var ownerHidden = document.getElementById('aiRiskOwnerId');
            var ownerBox = document.getElementById('aiRiskOwnerSuggestions');
            var vendorIdInput = document.getElementById('aiRiskVendorId');
            var riskIdInput = document.getElementById('aiRiskId');
            var vendorLabel = document.getElementById('aiRiskVendorLabel');
            var modalTitle = document.getElementById('aiRiskModalTitle');
            var submitBtn = document.getElementById('aiRiskSubmitBtn');
            var actionCreate = document.getElementById('aiRiskActionCreate');
            var actionUpdate = document.getElementById('aiRiskActionUpdate');
            var cancelBtn = document.getElementById('aiRiskCancelBtn');
            var closeX = document.getElementById('aiRiskCloseX');
            var form = document.getElementById('aiRiskForm');

            function escapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s == null ? '' : String(s);
                return d.innerHTML;
            }

            function resetForm() {
                titleInput.value = '';
                descInput.value = '';
                categorySel.value = 'technology';
                statusSel.value = 'identified';
                likelihoodSel.value = 'possible';
                impactSel.value = 'moderate';
                residualInput.value = '';
                treatmentSel.value = 'mitigate';
                treatmentPlanInput.value = '';
                reviewDateInput.value = '';
                ownerInput.value = '';
                ownerHidden.value = '';
                for (var i = 0; i < controlsSel.options.length; i++) {
                    controlsSel.options[i].selected = false;
                }
            }

            function setSelect(sel, val) {
                if (val == null || val === '') return;
                for (var i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === String(val)) { sel.selectedIndex = i; return; }
                }
            }

            function openAiRiskModal(vendorId, vendorName, hasExisting) {
                resetForm();
                vendorIdInput.value = vendorId;
                vendorLabel.textContent = 'Vendor: ' + vendorName;
                var defaultTitle = vendorName + ' - Uses AI in their services';
                titleInput.value = defaultTitle;

                var existing = aiRiskMap[String(vendorId)];
                if (hasExisting && existing) {
                    modalTitle.textContent = 'Update AI Risk (' + (existing.id ? 'RSK #' + existing.id : '') + ')';
                    submitBtn.textContent = 'Update Risk';
                    actionCreate.disabled = true;
                    actionCreate.removeAttribute('name');
                    actionUpdate.disabled = false;
                    actionUpdate.name = 'update_ai_risk';
                    actionUpdate.value = '1';
                    riskIdInput.value = existing.id;

                    if (existing.title) titleInput.value = existing.title;
                    descInput.value = existing.description || '';
                    setSelect(categorySel, existing.risk_category);
                    setSelect(statusSel, existing.status);
                    setSelect(likelihoodSel, existing.likelihood);
                    setSelect(impactSel, existing.impact);
                    residualInput.value = existing.residual_risk_score || '';
                    setSelect(treatmentSel, existing.risk_treatment);
                    treatmentPlanInput.value = existing.treatment_plan || '';
                    reviewDateInput.value = existing.review_date || '';

                    if (existing.owner_user_id && existing.owner_name) {
                        ownerHidden.value = existing.owner_user_id;
                        ownerInput.value = existing.owner_name;
                    }

                    if (existing.control_ids && existing.control_ids.length) {
                        var wanted = {};
                        existing.control_ids.forEach(function(id) { wanted[String(id)] = true; });
                        for (var i = 0; i < controlsSel.options.length; i++) {
                            controlsSel.options[i].selected = !!wanted[controlsSel.options[i].value];
                        }
                    }
                } else {
                    modalTitle.textContent = 'Add AI Risk to Register';
                    submitBtn.textContent = 'Create Risk';
                    actionCreate.disabled = false;
                    actionCreate.name = 'create_ai_risk';
                    actionCreate.value = '1';
                    actionUpdate.disabled = true;
                    actionUpdate.removeAttribute('name');
                    riskIdInput.value = '';
                }
                modal.style.display = 'flex';
                setTimeout(function() { titleInput.focus(); }, 30);
            }

            function closeAiRiskModal() {
                modal.style.display = 'none';
                ownerBox.style.display = 'none';
            }

            document.querySelectorAll('.ai-risk-link').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openAiRiskModal(
                        this.dataset.vendorId,
                        this.dataset.vendorName,
                        this.dataset.existing === '1'
                    );
                });
            });

            cancelBtn.addEventListener('click', closeAiRiskModal);
            closeX.addEventListener('click', closeAiRiskModal);

            // Intentionally do NOT close on backdrop click — modal stays open when clicked off of
            // (User explicitly requested this behavior.)

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') closeAiRiskModal();
            });

            // Owner typeahead (client-side filter over preloaded list)
            function renderOwnerSuggestions(query) {
                var q = query.trim().toLowerCase();
                var matches = ownersList.filter(function(u) {
                    if (!q) return true;
                    return u.name.toLowerCase().indexOf(q) !== -1 ||
                           (u.email && u.email.toLowerCase().indexOf(q) !== -1);
                }).slice(0, 20);

                if (matches.length === 0) {
                    ownerBox.innerHTML = '<div style="padding: 10px 12px; font-size: 12px; color: #9ca3af;">No users found</div>';
                } else {
                    ownerBox.innerHTML = matches.map(function(u) {
                        return '<div class="ai-owner-item" data-user-id="' + u.id +
                            '" data-user-name="' + escapeHtml(u.name) +
                            '" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px;">' +
                            '<div style="color: #111;">' + escapeHtml(u.name) + '</div>' +
                            (u.email ? '<div style="color: #6b7280; font-size: 11px;">' + escapeHtml(u.email) + '</div>' : '') +
                            '</div>';
                    }).join('');
                    ownerBox.querySelectorAll('.ai-owner-item').forEach(function(item) {
                        item.addEventListener('mouseenter', function() { this.style.background = '#f3f4f6'; });
                        item.addEventListener('mouseleave', function() { this.style.background = 'white'; });
                        item.addEventListener('click', function() {
                            ownerHidden.value = this.dataset.userId;
                            ownerInput.value = this.dataset.userName;
                            ownerBox.style.display = 'none';
                        });
                    });
                }
                ownerBox.style.display = 'block';
            }

            ownerInput.addEventListener('input', function() {
                ownerHidden.value = '';
                renderOwnerSuggestions(this.value);
            });
            ownerInput.addEventListener('focus', function() {
                renderOwnerSuggestions(this.value);
            });
            document.addEventListener('click', function(e) {
                if (!ownerInput.contains(e.target) && !ownerBox.contains(e.target)) {
                    ownerBox.style.display = 'none';
                }
            });

            // Fallback: if user types a name that exactly matches an owner, set the id on submit
            form.addEventListener('submit', function(e) {
                if (!ownerHidden.value && ownerInput.value.trim() !== '') {
                    var typed = ownerInput.value.trim().toLowerCase();
                    var match = ownersList.find(function(u) { return u.name.toLowerCase() === typed; });
                    if (match) ownerHidden.value = match.id;
                }
            });
        })();
    </script>
    <?php endif; ?>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
