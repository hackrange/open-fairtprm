<?php
/**
 * Shadow SaaS - Unmanaged SaaS Application Tracking
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Allows administrators and cyber-tprm users to import, review, and onboard
 * unmanaged SaaS applications discovered in the organization. Users import
 * Shadow SaaS entries via CSV, review them in a list, then selectively
 * "onboard" them as real vendors with a checkbox + button click.
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
$isAuditor = $acl->hasGroup('auditor');

if (!$isAdmin && !$isCyberTPRM && !$isAuditor) {
    http_response_code(403);
    die(e(t('shadow-saas.access_denied')));
}

// SECURITY: auditors get read-only access to this page. Only admin/cyber_tprm may
// perform the write actions (import/add/edit/delete/onboard/dismiss/Zscaler allow-block).
$canManage = $isAdmin || $isCyberTPRM;

$error = '';
$success = '';

// SRS scoring services for grade calculations
require_once __DIR__ . '/includes/classes/SRSService.php';
require_once __DIR__ . '/includes/classes/ShodanService.php';
$srsService = new SRSService();
$shodanService = new ShodanService();

$scoringConfig = $srsService->getScoringConfig();
$upguardDisplayMode = $scoringConfig['display_mode'] ?? 'raw';
$upguardMaxScore = (int)($scoringConfig['max_score'] ?? 950);
$upguardName = $scoringConfig['display_name'] ?? 'UpGuard';
$shodanName = $shodanService->getScoringConfig()['display_name'] ?? 'Shodan';
$shodanScoringConfig = $shodanService->getScoringConfig();

// Check if Shodan columns exist
$shodanColumnsExist = false;
try {
    $db->fetchOne("SELECT current_shodan_score FROM shadow_saas LIMIT 1");
    $shodanColumnsExist = true;
} catch (Exception $e) {}

function displayUpguardScore(int $rawScore, string $mode, int $maxScore): string
{
    if ($mode === 'percentage') {
        $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
        return $pct . '%';
    }
    return (string)$rawScore;
}

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

function formatBytes($bytes) {
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

function computeAvgScore($entry, $shodanColumnsExist, $upguardMaxScore): ?int
{
    $srs = !empty($entry['current_srs_score']) ? (int)$entry['current_srs_score'] : 0;
    $shodan = ($shodanColumnsExist && !empty($entry['current_shodan_score'])) ? (int)$entry['current_shodan_score'] : 0;
    $srsNorm = $srs > 0 ? (int)floor($srs / max(1, $upguardMaxScore) * 100) : 0;
    if ($srsNorm > 0 && $shodan > 0) return (int)round(($srsNorm + $shodan) / 2);
    if ($srsNorm > 0) return $srsNorm;
    if ($shodan > 0) return $shodan;
    return null;
}

/**
 * Onboard a shadow_saas row into vendor_onboarding_requests, deleting the
 * shadow_saas row on success. Mirrors the long-form logic from the
 * onboard_selected POST handler so the Allow button and bulk Onboard share
 * one code path. Caller is expected to have already verified $entry is a
 * non-empty row from shadow_saas. Returns the new vendor_onboarding_requests
 * id, or throws on insert failure.
 */
function onboardShadowSaasRow($db, array $entry, int $userId): int
{
    $onboardData = [
        'status' => 'draft',
        'created_by' => $userId,
        'vendor_name' => $entry['vendor_name'],
        'vendor_domain' => $entry['vendor_domain'],
        'relationship_manager' => $entry['relationship_manager'],
        'vendor_tier' => '3',
        'rescore_status' => 'rescoring',
        'rescore_started_at' => date('Y-m-d H:i:s'),
    ];

    if (!empty($entry['description'])) {
        $onboardData['product_service_description'] = $entry['description'];
    }
    if (!empty($entry['number_of_users'])) {
        $onboardData['target_user_count'] = (int)$entry['number_of_users'];
    }

    if (!empty($entry['risk_type'])) {
        $riskTypes = array_map('trim', explode(';', $entry['risk_type']));
        foreach ($riskTypes as $rt) {
            $rtLower = strtolower($rt);
            if ($rtLower === 'shadow it') {
                $onboardData['is_saas'] = 'yes';
            } elseif ($rtLower === 'data leakage') {
                $onboardData['confidential_info_shared'] = 'yes';
                $onboardData['confidential_info_justification'] = 'Shadow SaaS risk assessment identified data leakage risk.';
            } elseif ($rtLower === 'account takeover risk') {
                $onboardData['remote_network_access'] = 'yes';
                $onboardData['remote_access_justification'] = 'Shadow SaaS risk assessment identified account takeover risk.';
            } elseif ($rtLower === 'intellectual property exposure') {
                $onboardData['source_code_access'] = 'yes';
                $onboardData['source_code_justification'] = 'Shadow SaaS risk assessment identified intellectual property exposure risk.';
            } elseif ($rtLower === 'third-party breach risk') {
                $onboardData['offsite_data_hosting'] = 'yes';
                $onboardData['offsite_data_justification'] = 'Shadow SaaS risk assessment identified third-party breach risk.';
            }
        }
    }

    if (!empty($entry['breaches_in_three_years']) && (int)$entry['breaches_in_three_years'] > 0) {
        $breachCount = (int)$entry['breaches_in_three_years'];
        $onboardData['pii_phi_exchange'] = 'yes';
        $onboardData['pii_phi_justification'] = "Vendor has had {$breachCount} breach(es) in the past 3 years.";
    }

    if (!empty($entry['filesharing']) && strtolower($entry['filesharing']) === 'yes') {
        if (empty($onboardData['confidential_info_shared'])) {
            $onboardData['confidential_info_shared'] = 'yes';
        }
    }

    if (!empty($entry['mfasupport'])) {
        $mfaLower = strtolower($entry['mfasupport']);
        if ($mfaLower === 'no') {
            $onboardData['saml_sso_support'] = 'no';
        } elseif ($mfaLower === 'yes') {
            $onboardData['saml_sso_support'] = 'yes';
        }
    }

    $vendorId = $db->insert('vendor_onboarding_requests', $onboardData);
    $db->query("DELETE FROM shadow_saas WHERE id = ?", [(int)$entry['id']]);

    if (!empty($entry['vendor_domain'])) {
        try {
            require_once __DIR__ . '/includes/classes/FaviconService.php';
            $favService = new FaviconService();
            $favicon = $favService->fetchFavicon($entry['vendor_domain']);
            if ($favicon) {
                $db->query(
                    'UPDATE vendor_onboarding_requests SET vendor_favicon = ?, vendor_favicon_mime = ? WHERE id = ?',
                    [$favicon['data'], $favicon['mime'], $vendorId]
                );
            }
        } catch (Exception $e) {
            // Non-critical
        }
    }

    return (int)$vendorId;
}

// Ensure shadow_saas table exists (auto-create if missing)
$tableExists = true;
try {
    $db->fetchOne("SELECT 1 FROM shadow_saas LIMIT 1");
} catch (Exception $e) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS shadow_saas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendor_name VARCHAR(500) NOT NULL,
            vendor_domain VARCHAR(255) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            risk_score INT UNSIGNED DEFAULT NULL,
            risk_type TEXT DEFAULT NULL,
            relationship_manager VARCHAR(255) DEFAULT NULL,
            number_of_users INT UNSIGNED DEFAULT NULL,
            application_category VARCHAR(255) DEFAULT NULL,
            breaches_in_three_years INT UNSIGNED DEFAULT NULL,
            downloadbytes BIGINT UNSIGNED DEFAULT NULL,
            uploadbytes BIGINT UNSIGNED DEFAULT NULL,
            filesharing VARCHAR(10) DEFAULT NULL,
            mfasupport VARCHAR(10) DEFAULT NULL,
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
        $tableExists = true;
    } catch (Exception $e2) {
        error_log('Failed to auto-create shadow_saas table: ' . $e2->getMessage());
        $tableExists = false;
    }
}

// Auto-add missing columns for deployments with older table schema
if ($tableExists) {
    $missingCols = [
        'description'              => "ALTER TABLE shadow_saas ADD COLUMN description TEXT DEFAULT NULL AFTER vendor_domain",
        'risk_score'               => "ALTER TABLE shadow_saas ADD COLUMN risk_score INT UNSIGNED DEFAULT NULL AFTER description",
        'risk_type'                => "ALTER TABLE shadow_saas ADD COLUMN risk_type TEXT DEFAULT NULL AFTER risk_score",
        'number_of_users'          => "ALTER TABLE shadow_saas ADD COLUMN number_of_users INT UNSIGNED DEFAULT NULL AFTER relationship_manager",
        'application_category'     => "ALTER TABLE shadow_saas ADD COLUMN application_category VARCHAR(255) DEFAULT NULL AFTER number_of_users",
        'breaches_in_three_years'  => "ALTER TABLE shadow_saas ADD COLUMN breaches_in_three_years INT UNSIGNED DEFAULT NULL AFTER application_category",
        'downloadbytes'            => "ALTER TABLE shadow_saas ADD COLUMN downloadbytes BIGINT UNSIGNED DEFAULT NULL AFTER breaches_in_three_years",
        'uploadbytes'              => "ALTER TABLE shadow_saas ADD COLUMN uploadbytes BIGINT UNSIGNED DEFAULT NULL AFTER downloadbytes",
        'filesharing'              => "ALTER TABLE shadow_saas ADD COLUMN filesharing VARCHAR(10) DEFAULT NULL AFTER uploadbytes",
        'mfasupport'               => "ALTER TABLE shadow_saas ADD COLUMN mfasupport VARCHAR(10) DEFAULT NULL AFTER filesharing",
        'current_srs_score'        => "ALTER TABLE shadow_saas ADD COLUMN current_srs_score INT DEFAULT NULL AFTER mfasupport",
        'last_srs_score_at'        => "ALTER TABLE shadow_saas ADD COLUMN last_srs_score_at DATETIME DEFAULT NULL AFTER current_srs_score",
        'current_shodan_score'     => "ALTER TABLE shadow_saas ADD COLUMN current_shodan_score INT DEFAULT NULL AFTER last_srs_score_at",
        'last_shodan_score_at'     => "ALTER TABLE shadow_saas ADD COLUMN last_shodan_score_at DATETIME DEFAULT NULL AFTER current_shodan_score",
        'rescore_status'           => "ALTER TABLE shadow_saas ADD COLUMN rescore_status VARCHAR(30) DEFAULT NULL AFTER last_shodan_score_at",
        'rescore_started_at'       => "ALTER TABLE shadow_saas ADD COLUMN rescore_started_at DATETIME DEFAULT NULL AFTER rescore_status",
        'rescore_result'           => "ALTER TABLE shadow_saas ADD COLUMN rescore_result TEXT DEFAULT NULL AFTER rescore_started_at",
        'grip_id'                  => "ALTER TABLE shadow_saas ADD COLUMN grip_id VARCHAR(64) DEFAULT NULL AFTER id, ADD INDEX idx_grip_id (grip_id)",
        'source'                   => "ALTER TABLE shadow_saas ADD COLUMN source VARCHAR(20) DEFAULT 'csv' AFTER grip_id",
    ];
    foreach ($missingCols as $col => $alterSql) {
        try {
            $db->fetchOne("SELECT {$col} FROM shadow_saas LIMIT 1");
        } catch (Exception $e) {
            try { $db->query($alterSql); } catch (Exception $e2) {}
        }
    }
}

// Handle POST requests (write actions require manage privilege; auditors are read-only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists && !$canManage) {
    http_response_code(403);
    $error = t('shadow-saas.access_denied');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('shadow-saas.invalid_request');

    } elseif (isset($_POST['action']) && $_POST['action'] === 'import_csv' && isset($_FILES['csv_file'])) {
        // CSV Import
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = t('shadow-saas.file_upload_failed');
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = t('shadow-saas.file_too_large');
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                $error = t('shadow-saas.could_not_read');
            } else {
                // Read header row
                $header = fgetcsv($handle);
                if (!$header) {
                    $error = t('shadow-saas.csv_empty');
                } else {
                    $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
                    $nameIdx = array_search('vendor_name', $header);
                    $domainIdx = array_search('vendor_domain', $header);
                    $rmIdx = array_search('relationship_manager', $header);
                    $usersIdx = array_search('number_of_users', $header);
                    $catIdx = array_search('application_category', $header);
                    $breachIdx = array_search('breaches_in_three_years', $header);
                    $dlIdx = array_search('downloadbytes', $header);
                    $ulIdx = array_search('uploadbytes', $header);
                    $fsIdx = array_search('filesharing', $header);
                    $mfaIdx = array_search('mfasupport', $header);
                    $descIdx = array_search('description', $header);
                    $riskIdx = array_search('risk', $header);
                    $riskTypeIdx = array_search('type of risk', $header);

                    if ($nameIdx === false || $domainIdx === false || $riskIdx === false) {
                        $error = t('shadow-saas.csv_missing_columns');
                    } else {
                        $imported = 0;
                        $updated = 0;
                        $skipped = 0;
                        $errors = [];
                        $rowNum = 1;

                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNum++;
                            $vendorName = trim($row[$nameIdx] ?? '');
                            $vendorDomain = $domainIdx !== false ? trim($row[$domainIdx] ?? '') : '';
                            $rm = $rmIdx !== false ? trim($row[$rmIdx] ?? '') : '';
                            $numUsers = $usersIdx !== false ? trim($row[$usersIdx] ?? '') : '';
                            $csvCategory = $catIdx !== false ? trim($row[$catIdx] ?? '') : '';
                            $csvBreaches = $breachIdx !== false ? trim($row[$breachIdx] ?? '') : '';
                            $csvDl = $dlIdx !== false ? trim($row[$dlIdx] ?? '') : '';
                            $csvUl = $ulIdx !== false ? trim($row[$ulIdx] ?? '') : '';
                            $csvFs = $fsIdx !== false ? trim($row[$fsIdx] ?? '') : '';
                            $csvMfa = $mfaIdx !== false ? trim($row[$mfaIdx] ?? '') : '';
                            $csvDesc = $descIdx !== false ? trim($row[$descIdx] ?? '') : '';
                            $csvRisk = $riskIdx !== false ? trim($row[$riskIdx] ?? '') : '';
                            $csvRiskType = $riskTypeIdx !== false ? trim($row[$riskTypeIdx] ?? '') : '';

                            if ($vendorName === '') {
                                $skipped++;
                                continue;
                            }

                            // Clean domain (strip protocol/path)
                            if ($vendorDomain !== '') {
                                $vendorDomain = preg_replace('#^https?://#i', '', $vendorDomain);
                                $vendorDomain = preg_replace('#/.*$#', '', $vendorDomain);
                                $vendorDomain = strtolower(trim($vendorDomain));
                            }

                            if ($vendorDomain !== '') {
                                // Skip if an active vendor already exists with this domain
                                $existingVendor = $db->fetchOne(
                                    "SELECT id FROM vendor_onboarding_requests WHERE vendor_domain = :domain AND status != 'inactive'",
                                    [':domain' => $vendorDomain]
                                );
                                if ($existingVendor) {
                                    $skipped++;
                                    continue;
                                }

                                // Update existing shadow_saas entry if domain matches
                                $existing = $db->fetchOne(
                                    "SELECT id, vendor_name, vendor_domain, description, risk_score, risk_type, relationship_manager, number_of_users, application_category, breaches_in_three_years, downloadbytes, uploadbytes, filesharing, mfasupport FROM shadow_saas WHERE vendor_domain = :domain",
                                    [':domain' => $vendorDomain]
                                );
                                if ($existing) {
                                    $updateData = [];
                                    if ($vendorName !== '' && $vendorName !== $existing['vendor_name']) {
                                        $updateData['vendor_name'] = $vendorName;
                                    }
                                    if ($rm !== '' && $rm !== ($existing['relationship_manager'] ?? '')) {
                                        $updateData['relationship_manager'] = $rm;
                                    }
                                    $newUsers = ($numUsers !== '' && is_numeric($numUsers)) ? (int)$numUsers : null;
                                    if ($newUsers !== null && $newUsers !== (int)($existing['number_of_users'] ?? 0)) {
                                        $updateData['number_of_users'] = $newUsers;
                                    }
                                    if ($csvCategory !== '' && $csvCategory !== ($existing['application_category'] ?? '')) {
                                        $updateData['application_category'] = $csvCategory;
                                    }
                                    $newBreaches = ($csvBreaches !== '' && is_numeric($csvBreaches)) ? (int)$csvBreaches : null;
                                    if ($newBreaches !== null && $newBreaches !== (int)($existing['breaches_in_three_years'] ?? 0)) {
                                        $updateData['breaches_in_three_years'] = $newBreaches;
                                    }
                                    $newDl = ($csvDl !== '' && is_numeric($csvDl)) ? (int)$csvDl : null;
                                    if ($newDl !== null && $newDl !== (int)($existing['downloadbytes'] ?? 0)) {
                                        $updateData['downloadbytes'] = $newDl;
                                    }
                                    $newUl = ($csvUl !== '' && is_numeric($csvUl)) ? (int)$csvUl : null;
                                    if ($newUl !== null && $newUl !== (int)($existing['uploadbytes'] ?? 0)) {
                                        $updateData['uploadbytes'] = $newUl;
                                    }
                                    if ($csvFs !== '' && $csvFs !== ($existing['filesharing'] ?? '')) {
                                        $updateData['filesharing'] = $csvFs;
                                    }
                                    if ($csvMfa !== '' && $csvMfa !== ($existing['mfasupport'] ?? '')) {
                                        $updateData['mfasupport'] = $csvMfa;
                                    }
                                    if ($csvDesc !== '' && $csvDesc !== ($existing['description'] ?? '')) {
                                        $updateData['description'] = $csvDesc;
                                    }
                                    $newRisk = ($csvRisk !== '' && is_numeric($csvRisk)) ? (int)$csvRisk : null;
                                    if ($newRisk !== null && $newRisk !== (int)($existing['risk_score'] ?? 0)) {
                                        $updateData['risk_score'] = $newRisk;
                                    }
                                    if ($csvRiskType !== '' && $csvRiskType !== ($existing['risk_type'] ?? '')) {
                                        $updateData['risk_type'] = $csvRiskType;
                                    }
                                    if (!empty($updateData)) {
                                        try {
                                            $setClauses = [];
                                            $updateParams = [];
                                            foreach ($updateData as $col => $val) {
                                                $setClauses[] = "{$col} = ?";
                                                $updateParams[] = $val;
                                            }
                                            $updateParams[] = $existing['id'];
                                            $db->query(
                                                "UPDATE shadow_saas SET " . implode(', ', $setClauses) . " WHERE id = ?",
                                                $updateParams
                                            );
                                            $updated++;
                                        } catch (Exception $e) {
                                            error_log("Shadow SaaS import update row {$rowNum}: " . $e->getMessage());
                                            $errors[] = "Row {$rowNum}: " . $e->getMessage();
                                        }
                                    } else {
                                        $skipped++;
                                    }
                                    continue;
                                }
                            }

                            try {
                                $insertData = [
                                    'vendor_name' => $vendorName,
                                    'vendor_domain' => $vendorDomain ?: null,
                                    'description' => $csvDesc ?: null,
                                    'risk_score' => ($csvRisk !== '' && is_numeric($csvRisk)) ? (int)$csvRisk : null,
                                    'risk_type' => $csvRiskType ?: null,
                                    'relationship_manager' => $rm ?: null,
                                    'number_of_users' => ($numUsers !== '' && is_numeric($numUsers)) ? (int)$numUsers : null,
                                    'application_category' => $csvCategory ?: null,
                                    'breaches_in_three_years' => ($csvBreaches !== '' && is_numeric($csvBreaches)) ? (int)$csvBreaches : null,
                                    'downloadbytes' => ($csvDl !== '' && is_numeric($csvDl)) ? (int)$csvDl : null,
                                    'uploadbytes' => ($csvUl !== '' && is_numeric($csvUl)) ? (int)$csvUl : null,
                                    'filesharing' => $csvFs ?: null,
                                    'mfasupport' => $csvMfa ?: null,
                                    'status' => 'pending',
                                    'created_by' => $user['id'],
                                ];
                                $db->insert('shadow_saas', $insertData);
                                $imported++;
                            } catch (Exception $e) {
                                error_log("Shadow SaaS import row {$rowNum}: " . $e->getMessage());
                                $errors[] = "Row {$rowNum}: " . $e->getMessage();
                            }
                        }

                        fclose($handle);

                        if ($imported > 0 || $updated > 0) {
                            $auth->audit($user['id'], 'shadow_saas_import', 'shadow_saas', null, [
                                'new' => ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]
                            ]);
                        }

                        $parts = [];
                        if ($imported > 0) $parts[] = "{$imported} imported";
                        if ($updated > 0) $parts[] = "{$updated} updated";
                        $msg = !empty($parts) ? implode(', ', $parts) . '.' : 'No changes.';
                        if ($skipped > 0) $msg .= " {$skipped} skipped (empty name, no changes, or already an active vendor).";
                        if (!empty($errors)) $msg .= " " . count($errors) . " errors.";
                        $success = $msg;
                    }
                }
            }
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'onboard_selected' && !empty($_POST['selected'])) {
        // Onboard selected entries
        $selectedIds = array_map('intval', (array)$_POST['selected']);
        $onboarded = 0;
        $failedOnboard = 0;

        foreach ($selectedIds as $sid) {
            $entry = $db->fetchOne(
                "SELECT * FROM shadow_saas WHERE id = :id AND status = 'pending'",
                [':id' => $sid]
            );
            if (!$entry) continue;

            try {
                $vendorId = onboardShadowSaasRow($db, $entry, (int)$user['id']);
                $auth->audit($user['id'], 'shadow_saas_onboard', 'shadow_saas', $sid, [
                    'new' => ['vendor_onboarding_id' => $vendorId, 'vendor_name' => $entry['vendor_name']]
                ]);
                $onboarded++;
            } catch (Exception $e) {
                error_log("Shadow SaaS onboard #{$sid}: " . $e->getMessage());
                $failedOnboard++;
            }
        }

        if ($onboarded > 0) {
            $success = "{$onboarded} vendor(s) onboarded successfully. Background scoring will begin shortly.";
        }
        if ($failedOnboard > 0) {
            $error = "{$failedOnboard} vendor(s) failed to onboard.";
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'dismiss' && !empty($_POST['dismiss_id'])) {
        // Dismiss a single entry
        $dismissId = intval($_POST['dismiss_id']);
        try {
            $db->query(
                "UPDATE shadow_saas SET status = 'dismissed' WHERE id = ? AND status IN ('pending', 'unsanctioned')",
                [$dismissId]
            );
            $auth->audit($user['id'], 'shadow_saas_dismiss', 'shadow_saas', $dismissId, []);
            $success = t('shadow-saas.entry_dismissed');
        } catch (Exception $e) {
            $error = t('shadow-saas.dismiss_failed');
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'deny_zscaler' && !empty($_POST['deny_id'])) {
        // Deny: always flips the shadow_saas row to status='unsanctioned' (the
        // local equivalent of Grip's Unsanctioned sanctionTag — Grip has no
        // write endpoint to push this back, so we just keep our own copy).
        // When Zscaler is configured, also push the domain into the URL Category
        // and stamp is_zscaler_blocked = 1 so the Allow button renders.
        $denyId = intval($_POST['deny_id']);
        try {
            $entry = $db->fetchOne(
                "SELECT id, vendor_name, vendor_domain FROM shadow_saas WHERE id = :id",
                [':id' => $denyId]
            );
            if (!$entry) {
                $error = t('shadow-saas.entry_not_found');
            } else {
                $db->query(
                    "UPDATE shadow_saas SET status = 'unsanctioned' WHERE id = ?",
                    [$denyId]
                );
                $auth->audit($user['id'], 'shadow_saas_unsanction', 'shadow_saas', $denyId, [
                    'vendor_name' => $entry['vendor_name'],
                ]);

                require_once __DIR__ . '/includes/classes/ZscalerService.php';
                $zsvc = new ZscalerService();
                if (empty($entry['vendor_domain'])) {
                    $success = t('shadow-saas.unsanctioned_no_domain');
                } elseif (!$zsvc->isEnabled()) {
                    $success = t('shadow-saas.unsanctioned_zscaler_off');
                } else {
                    $result = $zsvc->denyDomain($entry['vendor_domain']);
                    if ($result['success']) {
                        $db->query(
                            "UPDATE shadow_saas SET is_zscaler_blocked = 1, zscaler_blocked_at = NOW() WHERE id = ?",
                            [$denyId]
                        );
                        $auth->audit($user['id'], 'shadow_saas_zscaler_deny', 'shadow_saas', $denyId, [
                            'domain' => $entry['vendor_domain'],
                            'category' => $zsvc->getUrlCategory(),
                        ]);
                        $success = 'Marked unsanctioned. Zscaler: ' . $result['message'];
                    } else {
                        $error = 'Marked unsanctioned, but Zscaler deny failed: ' . $result['message'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Shadow SaaS deny_zscaler error: ' . $e->getMessage());
            $error = 'Failed to deny entry: ' . $e->getMessage();
        }

    } elseif (isset($_POST['action']) && in_array($_POST['action'], ['deny_zscaler_oneoff', 'allow_zscaler_oneoff'], true)) {
        // One-off domain push/pull against the Zscaler URL Category — not tied to a shadow_saas row.
        $oneoffDomain = trim((string)($_POST['oneoff_domain'] ?? ''));
        $isDeny = ($_POST['action'] === 'deny_zscaler_oneoff');
        if ($oneoffDomain === '') {
            $error = t('shadow-saas.domain_required');
        } else {
            try {
                require_once __DIR__ . '/includes/classes/ZscalerService.php';
                $zsvc = new ZscalerService();
                if (!$zsvc->isEnabled()) {
                    $error = t('shadow-saas.zscaler_not_enabled_config');
                } else {
                    $result = $isDeny ? $zsvc->denyDomain($oneoffDomain) : $zsvc->allowDomain($oneoffDomain);
                    if ($result['success']) {
                        $auth->audit($user['id'], $isDeny ? 'shadow_saas_zscaler_deny_oneoff' : 'shadow_saas_zscaler_allow_oneoff', 'shadow_saas', null, [
                            'domain' => $oneoffDomain,
                            'category' => $zsvc->getUrlCategory(),
                        ]);
                        $success = 'Zscaler: ' . $result['message'];
                    } else {
                        $error = 'Zscaler ' . ($isDeny ? 'deny' : 'allow') . ' failed: ' . $result['message'];
                    }
                }
            } catch (Exception $e) {
                error_log('Shadow SaaS one-off Zscaler error: ' . $e->getMessage());
                $error = 'Failed to update Zscaler: ' . $e->getMessage();
            }
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'allow_zscaler' && !empty($_POST['allow_id'])) {
        // Allow: onboard the vendor into TPRM (same logic as Onboard Selected)
        // AND, if the domain was Zscaler-blocked, pull it out of the URL Category.
        // Capture domain/is_zscaler_blocked before onboarding because the helper
        // deletes the shadow_saas row on success.
        $allowId = intval($_POST['allow_id']);
        try {
            $entry = $db->fetchOne(
                "SELECT * FROM shadow_saas WHERE id = :id",
                [':id' => $allowId]
            );
            if (!$entry) {
                $error = t('shadow-saas.entry_not_found');
            } else {
                $vendorDomain = $entry['vendor_domain'] ?? '';
                $wasBlocked = !empty($entry['is_zscaler_blocked']);

                $vendorId = onboardShadowSaasRow($db, $entry, (int)$user['id']);
                $auth->audit($user['id'], 'shadow_saas_onboard', 'shadow_saas', $allowId, [
                    'new' => ['vendor_onboarding_id' => $vendorId, 'vendor_name' => $entry['vendor_name']],
                    'via' => 'allow',
                ]);
                $success = t('shadow-saas.vendor_onboarded');

                if ($wasBlocked && $vendorDomain !== '') {
                    require_once __DIR__ . '/includes/classes/ZscalerService.php';
                    $zsvc = new ZscalerService();
                    if ($zsvc->isEnabled()) {
                        $result = $zsvc->allowDomain($vendorDomain);
                        if ($result['success']) {
                            $auth->audit($user['id'], 'shadow_saas_zscaler_allow', 'shadow_saas', $allowId, [
                                'domain' => $vendorDomain,
                                'category' => $zsvc->getUrlCategory(),
                            ]);
                            $success .= ' Zscaler: ' . $result['message'];
                        } else {
                            $error = 'Vendor onboarded, but Zscaler allow failed: ' . $result['message'];
                        }
                    } else {
                        $success .= ' Zscaler integration is not enabled; domain not removed from URL Category.';
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Shadow SaaS allow_zscaler error: ' . $e->getMessage());
            $error = 'Failed to allow entry: ' . $e->getMessage();
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'undismiss' && !empty($_POST['undismiss_id'])) {
        // Restore a dismissed entry
        $undismissId = intval($_POST['undismiss_id']);
        try {
            $db->query(
                "UPDATE shadow_saas SET status = 'pending' WHERE id = ? AND status = 'dismissed'",
                [$undismissId]
            );
            $success = t('shadow-saas.entry_restored');
        } catch (Exception $e) {
            $error = t('shadow-saas.restore_failed');
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'scan_selected' && !empty($_POST['selected'])) {
        // Scan selected entries (queue for rescore)
        $selectedIds = array_map('intval', (array)$_POST['selected']);
        $queued = 0;
        $skippedNoDomain = 0;
        $skippedAlready = 0;
        foreach ($selectedIds as $sid) {
            $entry = $db->fetchOne(
                "SELECT id, vendor_domain, rescore_status FROM shadow_saas WHERE id = :id AND status = 'pending'",
                [':id' => $sid]
            );
            if (!$entry) continue;
            if (empty($entry['vendor_domain'])) {
                $skippedNoDomain++;
                continue;
            }
            if (!empty($entry['rescore_status']) && in_array($entry['rescore_status'], ['rescoring', 'processing'])) {
                $skippedAlready++;
                continue;
            }
            try {
                $db->query(
                    "UPDATE shadow_saas SET rescore_status = 'rescoring', rescore_started_at = NOW() WHERE id = ?",
                    [$sid]
                );
                $queued++;
            } catch (Exception $e) {
                error_log("Shadow SaaS scan #{$sid}: " . $e->getMessage());
            }
        }
        if ($queued > 0) {
            $auth->audit($user['id'], 'shadow_saas_scan', 'shadow_saas', null, [
                'new' => ['queued_count' => $queued, 'ids' => $selectedIds]
            ]);
            $success = "{$queued} entry(ies) queued for scanning. Background scoring will begin shortly.";
        }
        if ($skippedNoDomain > 0) {
            $success .= " {$skippedNoDomain} skipped (no domain).";
        }
        if ($skippedAlready > 0) {
            $success .= " {$skippedAlready} already scanning.";
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'dismiss_selected' && !empty($_POST['selected'])) {
        // Dismiss selected entries
        $selectedIds = array_map('intval', (array)$_POST['selected']);
        $dismissed = 0;
        foreach ($selectedIds as $sid) {
            try {
                $db->query("UPDATE shadow_saas SET status = 'dismissed' WHERE id = ? AND status IN ('pending', 'unsanctioned')", [$sid]);
                $dismissed++;
            } catch (Exception $e) {
                error_log("Shadow SaaS dismiss #{$sid}: " . $e->getMessage());
            }
        }
        if ($dismissed > 0) {
            $auth->audit($user['id'], 'shadow_saas_dismiss_selected', 'shadow_saas', null, [
                'new' => ['dismissed_count' => $dismissed, 'ids' => $selectedIds]
            ]);
            $success = "{$dismissed} entry(ies) dismissed.";
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'restore_selected' && !empty($_POST['selected'])) {
        // Restore selected dismissed entries
        $selectedIds = array_map('intval', (array)$_POST['selected']);
        $restored = 0;
        foreach ($selectedIds as $sid) {
            try {
                $db->query("UPDATE shadow_saas SET status = 'pending' WHERE id = ? AND status = 'dismissed'", [$sid]);
                $restored++;
            } catch (Exception $e) {
                error_log("Shadow SaaS restore #{$sid}: " . $e->getMessage());
            }
        }
        if ($restored > 0) {
            $auth->audit($user['id'], 'shadow_saas_restore_selected', 'shadow_saas', null, [
                'new' => ['restored_count' => $restored, 'ids' => $selectedIds]
            ]);
            $success = "{$restored} entry(ies) restored to pending.";
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_selected' && !empty($_POST['selected'])) {
        // Delete selected entries
        $selectedIds = array_map('intval', (array)$_POST['selected']);
        $deleted = 0;
        foreach ($selectedIds as $sid) {
            try {
                $db->query("DELETE FROM shadow_saas WHERE id = ? AND status IN ('pending', 'dismissed', 'unsanctioned')", [$sid]);
                $deleted++;
            } catch (Exception $e) {
                error_log("Shadow SaaS delete #{$sid}: " . $e->getMessage());
            }
        }
        if ($deleted > 0) {
            $auth->audit($user['id'], 'shadow_saas_delete', 'shadow_saas', null, [
                'new' => ['deleted_count' => $deleted, 'ids' => $selectedIds]
            ]);
            $success = "{$deleted} entry(ies) deleted.";
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_entry' && !empty($_POST['edit_id'])) {
        // Edit a single entry
        $editId = intval($_POST['edit_id']);
        $editName = trim($_POST['edit_vendor_name'] ?? '');
        $editDomain = trim($_POST['edit_vendor_domain'] ?? '');
        $editRm = trim($_POST['edit_relationship_manager'] ?? '');
        $editUsers = trim($_POST['edit_number_of_users'] ?? '');
        $editCategory = trim($_POST['edit_application_category'] ?? '');
        $editBreaches = trim($_POST['edit_breaches_in_three_years'] ?? '');
        $editDl = trim($_POST['edit_downloadbytes'] ?? '');
        $editUl = trim($_POST['edit_uploadbytes'] ?? '');
        $editFs = trim($_POST['edit_filesharing'] ?? '');
        $editMfa = trim($_POST['edit_mfasupport'] ?? '');
        $editDesc = trim($_POST['edit_description'] ?? '');
        $editRisk = trim($_POST['edit_risk_score'] ?? '');
        $editRiskType = trim($_POST['edit_risk_type'] ?? '');

        if ($editName === '') {
            $error = t('shadow-saas.vendor_name_required');
        } else {
            // Clean domain
            if ($editDomain !== '') {
                $editDomain = preg_replace('#^https?://#i', '', $editDomain);
                $editDomain = preg_replace('#/.*$#', '', $editDomain);
                $editDomain = strtolower(trim($editDomain));
            }
            try {
                $db->query(
                    "UPDATE shadow_saas SET vendor_name = ?, vendor_domain = ?, description = ?, risk_score = ?, risk_type = ?, relationship_manager = ?, number_of_users = ?, application_category = ?, breaches_in_three_years = ?, downloadbytes = ?, uploadbytes = ?, filesharing = ?, mfasupport = ? WHERE id = ?",
                    [
                        $editName,
                        $editDomain ?: null,
                        $editDesc ?: null,
                        ($editRisk !== '' && is_numeric($editRisk)) ? (int)$editRisk : null,
                        $editRiskType ?: null,
                        $editRm ?: null,
                        ($editUsers !== '' && is_numeric($editUsers)) ? (int)$editUsers : null,
                        $editCategory ?: null,
                        ($editBreaches !== '' && is_numeric($editBreaches)) ? (int)$editBreaches : null,
                        ($editDl !== '' && is_numeric($editDl)) ? (int)$editDl : null,
                        ($editUl !== '' && is_numeric($editUl)) ? (int)$editUl : null,
                        $editFs ?: null,
                        $editMfa ?: null,
                        $editId
                    ]
                );
                $success = t('shadow-saas.entry_updated');
            } catch (Exception $e) {
                $error = t('shadow-saas.update_failed');
            }
        }

    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_entry') {
        // Add a single entry manually
        $addName = trim($_POST['add_vendor_name'] ?? '');
        $addDomain = trim($_POST['add_vendor_domain'] ?? '');
        $addRm = trim($_POST['add_relationship_manager'] ?? '');
        $addUsers = trim($_POST['add_number_of_users'] ?? '');
        $addCategory = trim($_POST['add_application_category'] ?? '');
        $addBreaches = trim($_POST['add_breaches_in_three_years'] ?? '');
        $addDl = trim($_POST['add_downloadbytes'] ?? '');
        $addUl = trim($_POST['add_uploadbytes'] ?? '');
        $addFs = trim($_POST['add_filesharing'] ?? '');
        $addMfa = trim($_POST['add_mfasupport'] ?? '');
        $addDesc = trim($_POST['add_description'] ?? '');
        $addRisk = trim($_POST['add_risk_score'] ?? '');
        $addRiskType = trim($_POST['add_risk_type'] ?? '');

        if ($addName === '') {
            $error = t('shadow-saas.vendor_name_required');
        } else {
            // Clean domain
            if ($addDomain !== '') {
                $addDomain = preg_replace('#^https?://#i', '', $addDomain);
                $addDomain = preg_replace('#/.*$#', '', $addDomain);
                $addDomain = strtolower(trim($addDomain));
            }

            // Check for existing vendor with same domain
            if ($addDomain !== '') {
                $existingVendor = $db->fetchOne(
                    "SELECT id FROM vendor_onboarding_requests WHERE vendor_domain = :domain AND status != 'inactive'",
                    [':domain' => $addDomain]
                );
                if ($existingVendor) {
                    $error = t('shadow-saas.active_vendor_exists');
                }
                if (empty($error)) {
                    $existingShadow = $db->fetchOne(
                        "SELECT id FROM shadow_saas WHERE vendor_domain = :domain",
                        [':domain' => $addDomain]
                    );
                    if ($existingShadow) {
                        $error = t('shadow-saas.shadow_entry_exists');
                    }
                }
            }

            if (empty($error)) {
                try {
                    $insertData = [
                        'vendor_name' => $addName,
                        'vendor_domain' => $addDomain ?: null,
                        'description' => $addDesc ?: null,
                        'risk_score' => ($addRisk !== '' && is_numeric($addRisk)) ? (int)$addRisk : null,
                        'risk_type' => $addRiskType ?: null,
                        'relationship_manager' => $addRm ?: null,
                        'number_of_users' => ($addUsers !== '' && is_numeric($addUsers)) ? (int)$addUsers : null,
                        'application_category' => $addCategory ?: null,
                        'breaches_in_three_years' => ($addBreaches !== '' && is_numeric($addBreaches)) ? (int)$addBreaches : null,
                        'downloadbytes' => ($addDl !== '' && is_numeric($addDl)) ? (int)$addDl : null,
                        'uploadbytes' => ($addUl !== '' && is_numeric($addUl)) ? (int)$addUl : null,
                        'filesharing' => $addFs ?: null,
                        'mfasupport' => $addMfa ?: null,
                        'status' => 'pending',
                        'created_by' => $user['id'],
                    ];
                    $db->insert('shadow_saas', $insertData);
                    $auth->audit($user['id'], 'shadow_saas_add', 'shadow_saas', null, [
                        'new' => ['vendor_name' => $addName, 'vendor_domain' => $addDomain]
                    ]);
                    $success = t('shadow-saas.entry_added');
                } catch (Exception $e) {
                    error_log('Shadow SaaS add entry: ' . $e->getMessage());
                    $error = t('shadow-saas.add_failed');
                }
            }
        }
    }
}

$csrfToken = $security->generateCSRFToken();

// Stats
$stats = ['pending' => 0, 'dismissed' => 0, 'unsanctioned' => 0, 'total' => 0];
if ($tableExists) {
    try {
        $statsRow = $db->fetchOne("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) AS dismissed,
                SUM(CASE WHEN status = 'unsanctioned' THEN 1 ELSE 0 END) AS unsanctioned
            FROM shadow_saas
        ");
        $stats = [
            'total' => (int)($statsRow['total'] ?? 0),
            'pending' => (int)($statsRow['pending'] ?? 0),
            'dismissed' => (int)($statsRow['dismissed'] ?? 0),
            'unsanctioned' => (int)($statsRow['unsanctioned'] ?? 0),
        ];
    } catch (Exception $e) {}
}

// Filter
$statusFilter = $_GET['status'] ?? 'pending';
if ($statusFilter === 'all') $statusFilter = '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$riskFilter = $_GET['risk'] ?? '';
if ($riskFilter !== '' && !in_array($riskFilter, ['1', '2', '3', '4', '5', 'unscored'])) $riskFilter = '';
$riskTypeFilter = isset($_GET['risk_type']) ? trim($_GET['risk_type']) : '';

// Fetch distinct risk types (semicolon-separated values split into individual options)
$allRiskTypes = [];
if ($tableExists) {
    try {
        $rtRows = $db->fetchAll("SELECT DISTINCT risk_type FROM shadow_saas WHERE risk_type IS NOT NULL AND risk_type != ''");
        foreach ($rtRows as $rtRow) {
            foreach (explode(';', $rtRow['risk_type']) as $rt) {
                $rt = trim($rt);
                if ($rt !== '') $allRiskTypes[$rt] = true;
            }
        }
        $allRiskTypes = array_keys($allRiskTypes);
        sort($allRiskTypes, SORT_STRING | SORT_FLAG_CASE);
    } catch (Exception $e) {}
}
if ($riskTypeFilter !== '' && !in_array($riskTypeFilter, $allRiskTypes)) $riskTypeFilter = '';
$mfaFilter = isset($_GET['mfa']) ? trim($_GET['mfa']) : '';

// Fetch distinct MFA values
$allMfaValues = [];
if ($tableExists) {
    try {
        $mfaRows = $db->fetchAll("SELECT DISTINCT mfasupport FROM shadow_saas WHERE mfasupport IS NOT NULL AND mfasupport != '' ORDER BY mfasupport");
        foreach ($mfaRows as $mfaRow) {
            $allMfaValues[] = $mfaRow['mfasupport'];
        }
    } catch (Exception $e) {}
}
if ($mfaFilter !== '' && !in_array($mfaFilter, $allMfaValues)) $mfaFilter = '';

// Pagination
require_once __DIR__ . '/includes/classes/Pagination.php';
$pgParams = Pagination::getParams([
    'per_page' => 100,
    'per_page_options' => [100, 500, 1000],
    'sort_column' => 'created_at',
    'sort_dir' => 'DESC',
    'valid_sort_columns' => ['vendor_name', 'vendor_domain', 'description', 'risk_score', 'risk_type', 'relationship_manager', 'number_of_users', 'application_category', 'breaches_in_three_years', 'downloadbytes', 'uploadbytes', 'filesharing', 'mfasupport', 'security_scorecard_rating', 'current_srs_score', 'current_shodan_score', 'status', 'created_at'],
]);
$sortColumn = $pgParams['sort_column'];
$sortOrder = $pgParams['sort_dir'];
$perPage = $pgParams['per_page'];
$currentPageNum = $pgParams['page'];

// Build query
$whereConditions = [];
$params = [];
if (!empty($statusFilter) && in_array($statusFilter, ['pending', 'dismissed', 'unsanctioned'])) {
    $whereConditions[] = "s.status = :status";
    $params[':status'] = $statusFilter;
}
if (!empty($searchQuery)) {
    $whereConditions[] = "(s.vendor_name LIKE :search OR s.vendor_domain LIKE :search2 OR s.relationship_manager LIKE :search3)";
    $params[':search'] = '%' . $searchQuery . '%';
    $params[':search2'] = '%' . $searchQuery . '%';
    $params[':search3'] = '%' . $searchQuery . '%';
}
if ($riskFilter === 'unscored') {
    $whereConditions[] = "s.risk_score IS NULL";
} elseif ($riskFilter !== '') {
    $whereConditions[] = "s.risk_score = :risk";
    $params[':risk'] = (int)$riskFilter;
}
if ($riskTypeFilter !== '') {
    // risk_type holds a "; "-separated list (GripService writes implode('; '),
    // as does the CSV import). Normalize the separators to a bare ';' and wrap
    // the whole value in ';' so the first and last elements are bounded, then
    // match the selected type as a single delimited element. This tolerates
    // optional spaces around the ';' - a plain ";Type;" LIKE misses any type
    // that is not the first element because of the space after the semicolon.
    $whereConditions[] = "CONCAT(';', REPLACE(REPLACE(s.risk_type, '; ', ';'), ' ;', ';'), ';') LIKE :rt_like";
    $params[':rt_like'] = '%;' . $riskTypeFilter . ';%';
}
if ($mfaFilter !== '') {
    $whereConditions[] = "s.mfasupport = :mfa";
    $params[':mfa'] = $mfaFilter;
}
// Hide rows whose domain matches an active onboarded vendor — by definition
// an onboarded vendor is not Shadow SaaS. Match the "active" rule used
// elsewhere in the app (status != 'inactive').
$whereConditions[] = "NOT EXISTS (
    SELECT 1 FROM vendor_onboarding_requests v
    WHERE v.vendor_domain = s.vendor_domain
      AND v.vendor_domain IS NOT NULL AND v.vendor_domain != ''
      AND v.status != 'inactive'
)";
$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$entries = [];
$totalEntries = 0;
if ($tableExists) {
    try {
        $countRow = $db->fetchOne("SELECT COUNT(*) AS cnt FROM shadow_saas s {$whereClause}", $params);
        $totalEntries = (int)($countRow['cnt'] ?? 0);
    } catch (Exception $e) {}

    $pg = Pagination::paginate($totalEntries, $perPage, $currentPageNum);
    $currentPageNum = $pg['current_page'];
    $totalPages = $pg['total_pages'];
    $offset = $pg['offset'];

    $orderMap = [
        'vendor_name' => 's.vendor_name',
        'vendor_domain' => 's.vendor_domain',
        'description' => 's.description',
        'risk_score' => 's.risk_score',
        'risk_type' => 's.risk_type',
        'relationship_manager' => 's.relationship_manager',
        'number_of_users' => 's.number_of_users',
        'application_category' => 's.application_category',
        'breaches_in_three_years' => 's.breaches_in_three_years',
        'downloadbytes' => 's.downloadbytes',
        'uploadbytes' => 's.uploadbytes',
        'filesharing' => 's.filesharing',
        'mfasupport' => 's.mfasupport',
        'current_srs_score' => 's.current_srs_score',
        'current_shodan_score' => 's.current_shodan_score',
        'status' => 's.status',
        'created_at' => 's.created_at',
    ];
    $sqlOrderCol = $orderMap[$sortColumn] ?? 's.created_at';

    try {
        $entries = $db->fetchAll("
            SELECT s.*, u.full_name AS created_by_name
            FROM shadow_saas s
            LEFT JOIN users u ON s.created_by = u.id
            {$whereClause}
            ORDER BY {$sqlOrderCol} {$sortOrder}
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);
    } catch (Exception $e) {}
} else {
    $totalPages = 0;
    $offset = 0;
}

// Handle CSV export (all matching rows, not just current page)
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tableExists) {
    $orderMap = [
        'vendor_name' => 's.vendor_name',
        'vendor_domain' => 's.vendor_domain',
        'description' => 's.description',
        'risk_score' => 's.risk_score',
        'risk_type' => 's.risk_type',
        'relationship_manager' => 's.relationship_manager',
        'number_of_users' => 's.number_of_users',
        'application_category' => 's.application_category',
        'breaches_in_three_years' => 's.breaches_in_three_years',
        'downloadbytes' => 's.downloadbytes',
        'uploadbytes' => 's.uploadbytes',
        'filesharing' => 's.filesharing',
        'mfasupport' => 's.mfasupport',
        'current_srs_score' => 's.current_srs_score',
        'current_shodan_score' => 's.current_shodan_score',
        'status' => 's.status',
        'created_at' => 's.created_at',
    ];
    $sqlExportOrderCol = $orderMap[$sortColumn] ?? 's.created_at';
    try {
        $exportRows = $db->fetchAll("
            SELECT s.*
            FROM shadow_saas s
            {$whereClause}
            ORDER BY {$sqlExportOrderCol} {$sortOrder}
        ", $params);
    } catch (Exception $e) {
        $exportRows = [];
    }

    $csvHeaders = [
        'vendor_name', 'vendor_domain', 'description', 'risk_score', 'risk_type',
        'relationship_manager', 'number_of_users', 'application_category',
        'breaches_in_three_years', 'downloadbytes', 'uploadbytes',
        'filesharing', 'mfasupport', 'status', 'created_at',
    ];
    if ($shodanColumnsExist) {
        $csvHeaders[] = 'current_srs_score';
        $csvHeaders[] = 'current_shodan_score';
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="shadow-saas-export-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $csvHeaders);
    foreach ($exportRows as $row) {
        $csvRow = [];
        foreach ($csvHeaders as $col) {
            $csvRow[] = $row[$col] ?? '';
        }
        fputcsv($out, array_map('csvSafeCell', $csvRow)); // SECURITY: neutralize CSV formula injection
    }
    fclose($out);
    exit;
}

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
    <title><?php echo e(t('shadow-saas.page_title')); ?></title>
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
            background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px;
            display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0;
        }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a {
            color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px;
            background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px;
        }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
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
        .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5; padding: 0 20px; margin-bottom: 10px; }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-font-color); opacity: 0.85; text-decoration: none; font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .main-content { flex: 1; padding: 25px; overflow-y: auto; }
        .page-title { font-size: 18px; font-weight: 600; color: #333; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; text-align: center; text-decoration: none; cursor: pointer; }
        .stat-card .value { font-size: 28px; font-weight: 600; color: var(--theme-header-color); }
        .stat-card .label { font-size: 12px; color: #666; margin-top: 4px; }
        .stat-card.warning .value { color: #f59e0b; }
        .stat-card.success .value { color: #10b981; }
        .stat-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); transform: translateY(-1px); transition: all 0.2s; }
        .stat-card.stat-active { border-color: var(--theme-header-color); box-shadow: 0 0 0 2px var(--theme-header-color); }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 20px; }
        .card-header { padding: 15px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
        .card-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-weight: 500; border-bottom: 2px solid #e5e7eb; color: #374151; }
        td { padding: 12px 15px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        tr:hover { background: #f9fafb; }
        .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 500; text-transform: uppercase; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-dismissed { background: #f3f4f6; color: #6b7280; }
        .status-unsanctioned { background: #fee2e2; color: #991b1b; }
        .score-grade { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; font-weight: 700; font-size: 11px; margin-left: 3px; }
        .grade-a { background: #dcfce7; color: #166534; }
        .grade-b { background: #d1fae5; color: #065f46; }
        .grade-c { background: #fef3c7; color: #92400e; }
        .grade-d { background: #fed7aa; color: #9a3412; }
        .grade-f { background: #fecaca; color: #991b1b; }
        .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--theme-button-color); color: white; }
        .btn-primary:hover { filter: brightness(1.1); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        th.sortable { cursor: pointer; user-select: none; transition: background 0.2s; }
        th.sortable:hover { background: #e9ecef; }
        th.sortable a { color: inherit; text-decoration: none; display: block; }
        th.sortable .sort-icon { font-size: 10px; margin-left: 4px; opacity: 0.5; }
        th.sortable.active .sort-icon { opacity: 1; color: var(--theme-header-color); }
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px 20px; border-top: 1px solid #e5e7eb; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 13px; color: #666; }
        .pagination { display: flex; gap: 4px; list-style: none; margin: 0; padding: 0; }
        .pagination li a, .pagination li span { display: inline-block; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px; transition: all 0.2s; }
        .pagination li a:hover { background: #f3f4f6; border-color: #ccc; }
        .pagination li.active span { background: var(--theme-header-color); color: white; border-color: var(--theme-header-color); }
        .pagination li.disabled span { color: #ccc; cursor: not-allowed; }
        .filters-bar { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center; }
        .filters-bar .filter-group { display: flex; align-items: center; gap: 8px; }
        .filters-bar label { font-size: 13px; color: #666; font-weight: 500; }
        .filters-bar select, .filters-bar input[type="text"] { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }
        .filters-bar .btn { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; }
        .import-card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .import-card h3 { margin: 0 0 15px 0; font-size: 15px; font-weight: 600; color: #333; }
        .footer-modern, .bg-gray-13 { background-color: var(--theme-footer-color) !important; padding: 30px 0; color: #fff; width: 100%; overflow: visible; }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; white-space: nowrap; }
        tbody tr:not(.detail-row) { cursor: pointer; }
        .detail-row { display: none; }
        .detail-row.open { display: table-row; }
        .detail-row > td { padding: 0 16px 16px 56px !important; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .detail-row:hover > td { background: #f9fafb !important; }
        .detail-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 24px; font-size: 12px; }
        .detail-grid .detail-item { display: flex; flex-direction: column; gap: 2px; }
        .detail-grid .detail-label { color: #6b7280; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
        .detail-grid .detail-value { color: #374151; font-size: 13px; }
        @media (max-width: 768px) {
            .main-layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php renderImpersonationBanner(); ?>

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

        <div class="main-layout">
            <?php $currentPage = 'shadow_saas'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

            <main class="main-content">
        <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
            <strong><?php echo e(t('shadow-saas.table_not_found')); ?></strong> <?php echo e(t('shadow-saas.table_not_found_desc')); ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 class="page-title" style="margin: 0 0 10px 0;"><?php echo e(t('shadow-saas.heading')); ?></h1>
                <p style="margin: 0; font-size: 13px; color: #6b7280;"><?php echo e(t('shadow-saas.subtitle')); ?></p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <?php if (!$isAuditor): ?>
                <a href="data:text/csv;charset=utf-8,vendor_name,vendor_domain,description,risk,type%20of%20risk,relationship_manager,number_of_users,application_category,breaches_in_three_years,downloadbytes,uploadbytes,filesharing,mfasupport%0AExample%20Corp,example.com,Cloud%20CRM%20platform,3,Shadow%20IT%3B%20Data%20Leakage,John%20Doe,50,CRM,0,1024,512,Yes,Yes" download="shadow-saas-template.csv" class="btn-sm btn-secondary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.download_template')); ?></a>
                <button type="button" id="importToggleBtn" class="btn-sm btn-primary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.import_csv')); ?></button>
                <button type="button" id="zscalerToggleBtn" class="btn-sm btn-secondary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.zscaler')); ?></button>
                <button type="button" id="addEntryBtn" class="btn-sm btn-success" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.add')); ?></button>
                <?php endif; ?>
                <a href="?export=csv<?php echo $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '&status=all'; ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $riskFilter !== '' ? '&risk=' . urlencode($riskFilter) : ''; ?><?php echo $riskTypeFilter !== '' ? '&risk_type=' . urlencode($riskTypeFilter) : ''; ?><?php echo $mfaFilter !== '' ? '&mfa=' . urlencode($mfaFilter) : ''; ?>" class="btn-sm btn-secondary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.export_csv')); ?></a>
            </div>
        </div>

        <?php if (!$isAuditor): ?>
        <!-- Import Section (hidden by default) -->
        <div id="importSection" style="display: none;">
            <div class="import-card">
                <h3><?php echo e(t('shadow-saas.import_heading')); ?></h3>
                <p style="font-size: 13px; color: #6b7280; margin: 0 0 15px 0;">
                    <?php echo t('shadow-saas.import_help'); ?>
                </p>
                <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="import_csv">
                    <input type="file" name="csv_file" accept=".csv" required style="font-size: 13px;">
                    <button type="submit" class="btn-sm btn-success" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.upload_import')); ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$isAuditor): ?>
        <!-- Zscaler one-off URL Category Management (hidden by default) -->
        <div id="zscalerSection" style="display: none;">
            <div class="import-card">
                <h3><?php echo e(t('shadow-saas.zscaler_oneoff_heading')); ?></h3>
                <p style="font-size: 13px; color: #6b7280; margin: 0 0 15px 0;">
                    <?php echo t('shadow-saas.zscaler_oneoff_help'); ?>
                </p>
                <form method="POST" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" id="zscalerOneoffAction" value="">
                    <input type="text" name="oneoff_domain" id="zscalerOneoffDomain" placeholder="example.com" required style="font-size: 13px; padding: 6px 10px; min-width: 280px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <button type="submit" class="btn-sm" style="padding: 8px 16px; background: #b91c1c; color: white;" data-action="deny_zscaler_oneoff"><?php echo e(t('shadow-saas.deny')); ?></button>
                    <button type="submit" class="btn-sm" style="padding: 8px 16px; background: #15803d; color: white;" data-action="allow_zscaler_oneoff"><?php echo e(t('shadow-saas.allow')); ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats Tiles -->
        <div class="stats-grid">
            <a href="shadow-saas.php?status=all" class="stat-card<?php echo empty($statusFilter) ? ' stat-active' : ''; ?>">
                <div class="value"><?php echo $stats['total']; ?></div>
                <div class="label"><?php echo e(t('shadow-saas.stat_total')); ?></div>
            </a>
            <a href="shadow-saas.php?status=pending" class="stat-card warning<?php echo $statusFilter === 'pending' ? ' stat-active' : ''; ?>">
                <div class="value"><?php echo $stats['pending']; ?></div>
                <div class="label"><?php echo e(t('shadow-saas.stat_pending')); ?></div>
            </a>
            <a href="shadow-saas.php?status=unsanctioned" class="stat-card<?php echo $statusFilter === 'unsanctioned' ? ' stat-active' : ''; ?>">
                <div class="value"><?php echo $stats['unsanctioned']; ?></div>
                <div class="label"><?php echo e(t('shadow-saas.stat_unsanctioned')); ?></div>
            </a>
            <a href="shadow-saas.php?status=dismissed" class="stat-card<?php echo $statusFilter === 'dismissed' ? ' stat-active' : ''; ?>">
                <div class="value"><?php echo $stats['dismissed']; ?></div>
                <div class="label"><?php echo e(t('shadow-saas.stat_dismissed')); ?></div>
            </a>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters-bar">
            <div class="filter-group">
                <label><?php echo e(t('shadow-saas.search_label')); ?></label>
                <input type="text" name="search" value="<?php echo e($searchQuery); ?>" placeholder="<?php echo e(t('shadow-saas.search_placeholder')); ?>">
            </div>
            <div class="filter-group">
                <label><?php echo e(t('shadow-saas.status_label')); ?></label>
                <select name="status">
                    <option value="all" <?php echo empty($statusFilter) ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.opt_all')); ?></option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.opt_pending')); ?></option>
                    <option value="unsanctioned" <?php echo $statusFilter === 'unsanctioned' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.opt_unsanctioned')); ?></option>
                    <option value="dismissed" <?php echo $statusFilter === 'dismissed' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.opt_dismissed')); ?></option>
                </select>
            </div>
            <div class="filter-group">
                <label><?php echo e(t('shadow-saas.risk_label')); ?></label>
                <select name="risk">
                    <option value="" <?php echo $riskFilter === '' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.opt_all')); ?></option>
                    <option value="1" <?php echo $riskFilter === '1' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_1')); ?></option>
                    <option value="2" <?php echo $riskFilter === '2' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_2')); ?></option>
                    <option value="3" <?php echo $riskFilter === '3' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_3')); ?></option>
                    <option value="4" <?php echo $riskFilter === '4' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_4')); ?></option>
                    <option value="5" <?php echo $riskFilter === '5' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_5')); ?></option>
                    <option value="unscored" <?php echo $riskFilter === 'unscored' ? 'selected' : ''; ?>><?php echo e(t('shadow-saas.risk_unscored')); ?></option>
                </select>
            </div>
            <?php if (!empty($allRiskTypes)): ?>
            <div class="filter-group">
                <label><?php echo e(t('shadow-saas.risk_type_label')); ?></label>
                <select name="risk_type">
                    <option value=""><?php echo e(t('shadow-saas.opt_all')); ?></option>
                    <?php foreach ($allRiskTypes as $rt): ?>
                    <option value="<?php echo e($rt); ?>" <?php echo $riskTypeFilter === $rt ? 'selected' : ''; ?>><?php echo e($rt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (!empty($allMfaValues)): ?>
            <div class="filter-group">
                <label><?php echo e(t('shadow-saas.mfa_label')); ?></label>
                <select name="mfa">
                    <option value=""><?php echo e(t('shadow-saas.opt_all')); ?></option>
                    <?php foreach ($allMfaValues as $mv): ?>
                    <option value="<?php echo e($mv); ?>" <?php echo $mfaFilter === $mv ? 'selected' : ''; ?>><?php echo e($mv); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?php echo e(t('shadow-saas.filter')); ?></button>
            <a href="shadow-saas.php" class="btn btn-secondary"><?php echo e(t('shadow-saas.reset')); ?></a>
        </form>

        <!-- Main Table -->
        <form method="POST" id="onboardForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" id="bulkAction" value="onboard_selected">

            <div class="card">
                <div class="card-header">
                    <h2><?php echo e(t('shadow-saas.entries_heading')); ?> (<?php echo $totalEntries; ?>)</h2>
                    <?php if (!$isAuditor): ?>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-sm btn-primary" style="padding: 8px 16px;" id="scanBtn"><img src="app/icons/search-refraction.svg" alt="" width="14" height="14" style="vertical-align: middle; filter: brightness(0) invert(1); margin-right: 4px;"><?php echo e(t('shadow-saas.scan_selected')); ?></button>
                        <?php if ($statusFilter === 'dismissed' || $statusFilter === ''): ?>
                        <button type="button" class="btn-sm" style="padding: 8px 16px; background: #2563eb; color: white;" id="restoreSelectedBtn"><?php echo e(t('shadow-saas.restore_selected')); ?></button>
                        <?php endif; ?>
                        <button type="button" class="btn-sm" style="padding: 8px 16px; background: #6b7280; color: white;" id="dismissSelectedBtn"><?php echo e(t('shadow-saas.dismiss_selected')); ?></button>
                        <button type="button" class="btn-sm btn-secondary" style="padding: 8px 16px;" id="deleteBtn"><?php echo e(t('shadow-saas.delete_selected')); ?></button>
                        <button type="button" class="btn-sm btn-success" style="padding: 8px 16px;" id="onboardBtn"><?php echo e(t('shadow-saas.onboard_selected')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($entries)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #6b7280;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">
                        <img src="app/icons/alert-square.svg" alt="" width="32" height="32">
                    </div>
                    <p style="margin: 0; font-size: 14px;"><?php echo e(t('shadow-saas.no_entries')); ?></p>
                    <p style="margin-top: 8px; font-size: 13px;"><?php echo e(t('shadow-saas.no_entries_hint')); ?></p>
                </div>
                <?php else: ?>
                <?php
                    // SecurityScorecard "SSC" column shows only when Grip is connected
                    // (Grip is the rating's data source). Letter grade A/B/C/D/F badge.
                    $gripEnabled = (function_exists('getAppConfig') && getAppConfig('grip_enabled', '0') === '1');
                    if (!function_exists('sscBadge')) {
                        function sscBadge($rating): string {
                            $r = strtoupper(trim((string)$rating));
                            if (!in_array($r, ['A', 'B', 'C', 'D', 'F'], true)) {
                                return '<span style="color:#9ca3af;font-size:11px;">—</span>';
                            }
                            $map = ['A' => ['#dcfce7', '#166534'], 'B' => ['#d1fae5', '#065f46'], 'C' => ['#fef3c7', '#92400e'], 'D' => ['#ffedd5', '#9a3412'], 'F' => ['#fee2e2', '#991b1b']];
                            [$bg, $fg] = $map[$r];
                            return '<span title="SecurityScorecard rating ' . $r . '" style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-weight:700;font-size:11px;background:' . $bg . ';color:' . $fg . ';">' . $r . '</span>';
                        }
                    }
                ?>
                <table>
                    <thead>
                        <tr>
                            <?php if (!$isAuditor): ?><th style="width: 40px;"><input type="checkbox" id="selectAll"></th><?php endif; ?>
                            <th class="sortable <?php echo $sortColumn === 'vendor_name' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('vendor_name', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_vendor_name')); ?><span class="sort-icon"><?php echo getSortIndicator('vendor_name', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th class="sortable <?php echo $sortColumn === 'vendor_domain' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('vendor_domain', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_domain')); ?><span class="sort-icon"><?php echo getSortIndicator('vendor_domain', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th class="sortable <?php echo $sortColumn === 'risk_score' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('risk_score', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_risk')); ?><span class="sort-icon"><?php echo getSortIndicator('risk_score', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th class="sortable <?php echo $sortColumn === 'risk_type' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('risk_type', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_risk_type')); ?><span class="sort-icon"><?php echo getSortIndicator('risk_type', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th class="sortable <?php echo $sortColumn === 'application_category' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('application_category', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_category')); ?><span class="sort-icon"><?php echo getSortIndicator('application_category', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th class="sortable <?php echo $sortColumn === 'number_of_users' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('number_of_users', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_users')); ?><span class="sort-icon"><?php echo getSortIndicator('number_of_users', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <?php if ($gripEnabled): ?>
                            <th class="sortable <?php echo $sortColumn === 'security_scorecard_rating' ? 'active' : ''; ?>" title="SecurityScorecard rating (Grip)">
                                <a href="<?php echo buildSortUrl('security_scorecard_rating', $sortColumn, $sortOrder); ?>">
                                    SSC<span class="sort-icon"><?php echo getSortIndicator('security_scorecard_rating', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <?php endif; ?>
                            <th><?php echo e(t('shadow-saas.col_avg_score')); ?></th>
                            <th class="sortable <?php echo $sortColumn === 'status' ? 'active' : ''; ?>">
                                <a href="<?php echo buildSortUrl('status', $sortColumn, $sortOrder); ?>">
                                    <?php echo e(t('shadow-saas.col_status')); ?><span class="sort-icon"><?php echo getSortIndicator('status', $sortColumn, $sortOrder); ?></span>
                                </a>
                            </th>
                            <th><?php echo e(t('shadow-saas.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry):
                            $hasUpguard = !empty($entry['current_srs_score']);
                            $upguardGrade = $hasUpguard ? $srsService->calculateGrade((int)$entry['current_srs_score']) : null;
                            $hasShodan = $shodanColumnsExist && !empty($entry['current_shodan_score']);
                            $shodanGrade = $hasShodan ? $shodanService->calculateGrade((int)$entry['current_shodan_score']) : null;
                            $avgScore = computeAvgScore($entry, $shodanColumnsExist, $upguardMaxScore);
                            $avgGrade = calculateAvgGrade($avgScore, $shodanScoringConfig);
                            $isScoring = !empty($entry['rescore_status']) && $entry['rescore_status'] === 'rescoring';
                        ?>
                        <!-- Summary row -->
                        <tr>
                            <?php if (!$isAuditor): ?>
                            <td>
                                <?php if ($entry['status'] === 'pending' || $entry['status'] === 'dismissed'): ?>
                                <input type="checkbox" name="selected[]" value="<?php echo (int)$entry['id']; ?>" class="row-checkbox">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td style="font-weight: 500; color: #333;">
                                <?php echo e($entry['vendor_name']); ?>
                            </td>
                            <td style="font-size: 12px; color: #6b7280;">
                                <?php if (!empty($entry['vendor_domain'])): ?>
                                <a href="https://<?php echo e($entry['vendor_domain']); ?>" target="_blank" rel="noopener noreferrer" style="color: var(--theme-button-color, #2563eb); text-decoration: none;"><?php echo e($entry['vendor_domain']); ?></a>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($entry['risk_score'] !== null): ?>
                                <?php
                                    $rs = (int)$entry['risk_score'];
                                    // Scores are STORED on a 1-5 scale (Grip & Hero both bucket at write
                                    // time). This is only a defensive fallback for any legacy row still
                                    // holding a 0-100 value (i.e. > 5) that predates the migration.
                                    if ($rs > 5 && in_array(($entry['source'] ?? ''), ['grip', 'hero'], true)) {
                                        if      ($rs <= 20) { $rs = 1; }
                                        elseif  ($rs <= 40) { $rs = 2; }
                                        elseif  ($rs <= 60) { $rs = 3; }
                                        elseif  ($rs <= 80) { $rs = 4; }
                                        else                { $rs = 5; }
                                    }
                                    $riskColor = $rs <= 2 ? '#059669' : ($rs <= 3 ? '#d97706' : '#dc2626');
                                    $riskBg = $rs <= 2 ? '#d1fae5' : ($rs <= 3 ? '#fef3c7' : '#fee2e2');
                                ?>
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; background: <?php echo $riskBg; ?>; color: <?php echo $riskColor; ?>;"><?php echo $rs; ?></span>
                                <?php else: ?>
                                <span style="color: #9ca3af; font-size: 11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <?php
                                // Strip the "Sanction:" label from the displayed Risk Type (data is unchanged).
                                $rtRaw = $entry['risk_type'] ?? '';
                                $rtClean = trim(preg_replace('/\bSanction:\s*/i', '', $rtRaw));
                                if ($rtClean === '') { $rtClean = '—'; }
                            ?>
                            <td style="font-size: 12px; color: #374151; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($rtClean); ?>"><?php echo e($rtClean); ?></td>
                            <td style="font-size: 12px; color: #374151;"><?php echo e($entry['application_category'] ?? '—'); ?></td>
                            <td style="text-align: center; font-size: 13px; color: #374151;"><?php
                                if ($entry['number_of_users'] !== null) {
                                    $userCount = number_format((int)$entry['number_of_users']);
                                    // Grip-sourced apps expose a per-app user roster; link the count to the
                                    // drill-down (new tab). Param is "id" so Pagination preserves it across pages.
                                    if (!empty($entry['grip_id']) && ($entry['source'] ?? '') === 'grip') {
                                        echo '<a href="grip-saas-users.php?id=' . urlencode($entry['grip_id']) . '" target="_blank" rel="noopener" title="' . e(t('shadow-saas.grip_users_title')) . '" style="color:#2563eb;font-weight:600;text-decoration:none;">' . $userCount . '</a>';
                                    } else {
                                        echo $userCount;
                                    }
                                } else {
                                    echo '—';
                                }
                            ?></td>
                            <?php if ($gripEnabled): ?>
                            <td style="text-align: center;"><?php echo sscBadge($entry['security_scorecard_rating'] ?? null); ?></td>
                            <?php endif; ?>
                            <td style="text-align: center;">
                                <?php if ($avgScore !== null): ?>
                                <span style="font-weight: 600; font-size: 13px; color: #374151;"><?php echo $avgScore; ?>%</span>
                                <span class="score-grade grade-<?php echo strtolower($avgGrade); ?>"><?php echo $avgGrade; ?></span>
                                <?php elseif ($isScoring): ?>
                                <span style="color: #9ca3af; font-size: 11px; font-style: italic;"><?php echo e(t('shadow-saas.scoring')); ?></span>
                                <?php else: ?>
                                <span style="color: #9ca3af; font-size: 11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo e($entry['status']); ?>">
                                    <?php echo e(ucfirst($entry['status'])); ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;">
                                <?php if (!$isAuditor): ?>
                                <?php if ($entry['status'] === 'pending' || $entry['status'] === 'dismissed'): ?>
                                <button type="button" class="btn-sm btn-primary edit-btn" style="padding: 4px 8px; font-size: 11px;"
                                    data-id="<?php echo (int)$entry['id']; ?>"
                                    data-name="<?php echo e($entry['vendor_name']); ?>"
                                    data-domain="<?php echo e($entry['vendor_domain'] ?? ''); ?>"
                                    data-description="<?php echo e($entry['description'] ?? ''); ?>"
                                    data-risk="<?php echo e($entry['risk_score'] ?? ''); ?>"
                                    data-risktype="<?php echo e($entry['risk_type'] ?? ''); ?>"
                                    data-rm="<?php echo e($entry['relationship_manager'] ?? ''); ?>"
                                    data-users="<?php echo e($entry['number_of_users'] ?? ''); ?>"
                                    data-category="<?php echo e($entry['application_category'] ?? ''); ?>"
                                    data-breaches="<?php echo e($entry['breaches_in_three_years'] ?? ''); ?>"
                                    data-dl="<?php echo e($entry['downloadbytes'] ?? ''); ?>"
                                    data-ul="<?php echo e($entry['uploadbytes'] ?? ''); ?>"
                                    data-fs="<?php echo e($entry['filesharing'] ?? ''); ?>"
                                    data-mfa="<?php echo e($entry['mfasupport'] ?? ''); ?>"><?php echo e(t('shadow-saas.edit')); ?></button>
                                <?php endif; ?>
                                <?php if ($entry['status'] === 'pending'): ?>
                                <?php if (!empty($entry['is_zscaler_blocked'])): ?>
                                <button type="button" class="btn-sm allow-btn" style="padding: 4px 8px; font-size: 11px; background: #15803d; color: white;" data-id="<?php echo (int)$entry['id']; ?>" title="<?php echo e(t('shadow-saas.allow_title_blocked')); ?>"><?php echo e(t('shadow-saas.allow')); ?></button>
                                <?php else: ?>
                                <button type="button" class="btn-sm deny-btn" style="padding: 4px 8px; font-size: 11px; background: #b91c1c; color: white;" data-id="<?php echo (int)$entry['id']; ?>" title="<?php echo e(t('shadow-saas.deny_title')); ?>"><?php echo e(t('shadow-saas.deny')); ?></button>
                                <?php endif; ?>
                                <button type="button" class="btn-sm btn-danger dismiss-btn" style="padding: 4px 8px; font-size: 11px;" data-id="<?php echo (int)$entry['id']; ?>"><?php echo e(t('shadow-saas.dismiss')); ?></button>
                                <?php elseif ($entry['status'] === 'unsanctioned'): ?>
                                <button type="button" class="btn-sm allow-btn" style="padding: 4px 8px; font-size: 11px; background: #15803d; color: white;" data-id="<?php echo (int)$entry['id']; ?>" title="<?php echo e(t('shadow-saas.allow_title_unsanctioned')); ?>"><?php echo e(t('shadow-saas.allow')); ?></button>
                                <button type="button" class="btn-sm btn-danger dismiss-btn" style="padding: 4px 8px; font-size: 11px;" data-id="<?php echo (int)$entry['id']; ?>"><?php echo e(t('shadow-saas.dismiss')); ?></button>
                                <?php elseif ($entry['status'] === 'dismissed'): ?>
                                <button type="button" class="btn-sm btn-secondary undismiss-btn" style="padding: 4px 8px; font-size: 11px;" data-id="<?php echo (int)$entry['id']; ?>"><?php echo e(t('shadow-saas.restore')); ?></button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <!-- Detail row (hidden by default) -->
                        <tr class="detail-row">
                            <td colspan="<?php echo $gripEnabled ? 11 : 10; ?>">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_description')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['description'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_rm')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['relationship_manager'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_breaches')); ?></span>
                                        <span class="detail-value"><?php echo $entry['breaches_in_three_years'] !== null ? (int)$entry['breaches_in_three_years'] : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_download')); ?></span>
                                        <span class="detail-value"><?php echo $entry['downloadbytes'] !== null ? formatBytes((int)$entry['downloadbytes']) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_upload')); ?></span>
                                        <span class="detail-value"><?php echo $entry['uploadbytes'] !== null ? formatBytes((int)$entry['uploadbytes']) : '—'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_filesharing')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['filesharing'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_mfa')); ?></span>
                                        <span class="detail-value"><?php echo e($entry['mfasupport'] ?? '—'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e($upguardName); ?> <?php echo e(t('shadow-saas.score_suffix')); ?></span>
                                        <span class="detail-value">
                                            <?php if ($hasUpguard): ?>
                                            <?php echo displayUpguardScore((int)$entry['current_srs_score'], $upguardDisplayMode, $upguardMaxScore); ?>
                                            <span class="score-grade grade-<?php echo strtolower($upguardGrade); ?>"><?php echo $upguardGrade; ?></span>
                                            <?php elseif ($isScoring): ?>
                                            <span style="color: #9ca3af; font-style: italic;"><?php echo e(t('shadow-saas.scoring')); ?></span>
                                            <?php else: ?>—<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if ($shodanColumnsExist): ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e($shodanName); ?> <?php echo e(t('shadow-saas.score_suffix')); ?></span>
                                        <span class="detail-value">
                                            <?php if ($hasShodan): ?>
                                            <?php echo e($entry['current_shodan_score']); ?>%
                                            <span class="score-grade grade-<?php echo strtolower($shodanGrade); ?>"><?php echo $shodanGrade; ?></span>
                                            <?php elseif ($isScoring): ?>
                                            <span style="color: #9ca3af; font-style: italic;"><?php echo e(t('shadow-saas.scoring')); ?></span>
                                            <?php else: ?>—<?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <span class="detail-label"><?php echo e(t('shadow-saas.detail_date_added')); ?></span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($entry['created_at'])); ?></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1 || $totalEntries > 0): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalEntries); ?> of <?php echo $totalEntries; ?> entries
                        <span style="margin-left: 15px;">
                            <select id="perPageSelect" style="padding: 3px 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                <?php foreach ([100, 500, 1000] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $perPage == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span style="font-size: 12px; color: #999;"><?php echo e(t('shadow-saas.per_page')); ?></span>
                        </span>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <ul class="pagination">
                        <?php if ($currentPageNum > 1): ?>
                        <li><a href="<?php echo buildPageUrl(1); ?>">&laquo;</a></li>
                        <li><a href="<?php echo buildPageUrl($currentPageNum - 1); ?>">&lsaquo;</a></li>
                        <?php else: ?>
                        <li class="disabled"><span>&laquo;</span></li>
                        <li class="disabled"><span>&lsaquo;</span></li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $currentPageNum - 2);
                        $endPage = min($totalPages, $currentPageNum + 2);
                        if ($startPage > 1): ?>
                        <li><a href="<?php echo buildPageUrl(1); ?>">1</a></li>
                        <?php if ($startPage > 2): ?><li class="disabled"><span>...</span></li><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="<?php echo $i === $currentPageNum ? 'active' : ''; ?>">
                            <?php if ($i === $currentPageNum): ?>
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

                        <?php if ($currentPageNum < $totalPages): ?>
                        <li><a href="<?php echo buildPageUrl($currentPageNum + 1); ?>">&rsaquo;</a></li>
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
        </form>

        <!-- Hidden form for per-row actions (outside onboardForm to avoid nested forms) -->
        <form method="POST" id="rowActionForm" style="display: none;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" id="rowAction" value="">
            <input type="hidden" name="dismiss_id" id="rowDismissId" value="">
            <input type="hidden" name="undismiss_id" id="rowUndismissId" value="">
            <input type="hidden" name="deny_id" id="rowDenyId" value="">
            <input type="hidden" name="allow_id" id="rowAllowId" value="">
        </form>

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
                        <span><?php echo e(t('shadow-saas.all_rights_reserved')); ?></span>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; padding: 25px; max-width: 550px; width: 90%; margin: auto; position: relative; top: 50%; transform: translateY(-50%); max-height: 90vh; overflow-y: auto;">
            <h3 style="margin: 0 0 20px 0; font-size: 16px; font-weight: 600;"><?php echo e(t('shadow-saas.edit_modal_title')); ?></h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="edit_entry">
                <input type="hidden" name="edit_id" id="editId">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_vendor_name')); ?> *</label>
                    <input type="text" name="edit_vendor_name" id="editVendorName" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_domain')); ?></label>
                    <input type="text" name="edit_vendor_domain" id="editVendorDomain" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_description')); ?></label>
                    <textarea name="edit_description" id="editDescription" rows="3" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; resize: vertical;"></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_risk_score')); ?></label>
                        <input type="number" name="edit_risk_score" id="editRiskScore" min="1" max="5" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_risk_type')); ?></label>
                        <input type="text" name="edit_risk_type" id="editRiskType" placeholder="<?php echo e(t('shadow-saas.risk_type_placeholder')); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_rm')); ?></label>
                    <input type="text" name="edit_relationship_manager" id="editRm" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_num_users')); ?></label>
                    <input type="number" name="edit_number_of_users" id="editUsers" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_app_category')); ?></label>
                    <input type="text" name="edit_application_category" id="editCategory" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_breaches')); ?></label>
                    <input type="number" name="edit_breaches_in_three_years" id="editBreaches" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_download_bytes')); ?></label>
                        <input type="number" name="edit_downloadbytes" id="editDl" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_upload_bytes')); ?></label>
                        <input type="number" name="edit_uploadbytes" id="editUl" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_file_sharing')); ?></label>
                        <select name="edit_filesharing" id="editFs" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                            <option value="">—</option>
                            <option value="Yes"><?php echo e(t('shadow-saas.opt_yes')); ?></option>
                            <option value="No"><?php echo e(t('shadow-saas.opt_no')); ?></option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_mfa_support')); ?></label>
                        <select name="edit_mfasupport" id="editMfa" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                            <option value="">—</option>
                            <option value="Yes"><?php echo e(t('shadow-saas.opt_yes')); ?></option>
                            <option value="No"><?php echo e(t('shadow-saas.opt_no')); ?></option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="editCancelBtn" class="btn-sm btn-secondary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.btn_cancel')); ?></button>
                    <button type="submit" class="btn-sm btn-primary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.btn_save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Modal -->
    <div id="addModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 10px; padding: 25px; max-width: 550px; width: 90%; margin: auto; position: relative; top: 50%; transform: translateY(-50%); max-height: 90vh; overflow-y: auto;">
            <h3 style="margin: 0 0 20px 0; font-size: 16px; font-weight: 600;"><?php echo e(t('shadow-saas.add_modal_title')); ?></h3>
            <form method="POST" id="addForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="add_entry">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_vendor_name')); ?> *</label>
                    <input type="text" name="add_vendor_name" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_domain')); ?></label>
                    <input type="text" name="add_vendor_domain" placeholder="<?php echo e(t('shadow-saas.placeholder_domain_example')); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_description')); ?></label>
                    <textarea name="add_description" rows="3" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; resize: vertical;"></textarea>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.add_label_risk_score')); ?></label>
                        <input type="number" name="add_risk_score" min="1" max="5" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_risk_type')); ?></label>
                        <input type="text" name="add_risk_type" placeholder="<?php echo e(t('shadow-saas.add_risk_type_placeholder')); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_rm')); ?></label>
                    <input type="text" name="add_relationship_manager" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_num_users')); ?></label>
                    <input type="number" name="add_number_of_users" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_app_category')); ?></label>
                    <input type="text" name="add_application_category" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_breaches')); ?></label>
                    <input type="number" name="add_breaches_in_three_years" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_download_bytes')); ?></label>
                        <input type="number" name="add_downloadbytes" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_upload_bytes')); ?></label>
                        <input type="number" name="add_uploadbytes" min="0" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_file_sharing')); ?></label>
                        <select name="add_filesharing" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                            <option value="">—</option>
                            <option value="Yes"><?php echo e(t('shadow-saas.opt_yes')); ?></option>
                            <option value="No"><?php echo e(t('shadow-saas.opt_no')); ?></option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('shadow-saas.label_mfa_support')); ?></label>
                        <select name="add_mfasupport" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                            <option value="">—</option>
                            <option value="Yes"><?php echo e(t('shadow-saas.opt_yes')); ?></option>
                            <option value="No"><?php echo e(t('shadow-saas.opt_no')); ?></option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="addCancelBtn" class="btn-sm btn-secondary" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.btn_cancel')); ?></button>
                    <button type="submit" class="btn-sm btn-success" style="padding: 8px 16px;"><?php echo e(t('shadow-saas.btn_add_entry')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        // Import CSV toggle
        document.getElementById('importToggleBtn')?.addEventListener('click', function() {
            var el = document.getElementById('importSection');
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        });

        // Zscaler one-off toggle
        document.getElementById('zscalerToggleBtn')?.addEventListener('click', function() {
            var el = document.getElementById('zscalerSection');
            if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
        });

        // Zscaler one-off Deny/Allow: stamp the action on the hidden input before submit,
        // and confirm with the user what's about to happen.
        document.querySelectorAll('#zscalerSection button[data-action]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                var act = this.dataset.action;
                var domain = (document.getElementById('zscalerOneoffDomain').value || '').trim();
                if (!domain) return; // required attribute will catch
                var verb = act === 'deny_zscaler_oneoff' ? 'Add' : 'Remove';
                var prep = act === 'deny_zscaler_oneoff' ? 'to'  : 'from';
                if (!confirm(verb + " '" + domain + "' " + prep + " the configured Zscaler URL Category?")) {
                    e.preventDefault();
                    return;
                }
                document.getElementById('zscalerOneoffAction').value = act;
            });
        });

        // Select all checkbox
        document.getElementById('selectAll')?.addEventListener('change', function() {
            document.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.checked = this.checked;
            }.bind(this));
        });

        // Onboard selected confirmation
        document.getElementById('onboardBtn')?.addEventListener('click', function() {
            var form = document.getElementById('onboardForm');
            var checked = form.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) {
                alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>);
                return;
            }
            if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_onboard_prefix')); ?> + checked.length + <?php echo json_encode(t('shadow-saas.js_confirm_onboard_suffix')); ?>)) {
                document.getElementById('bulkAction').value = 'onboard_selected';
                form.submit();
            }
        });

        // Scan selected confirmation
        document.getElementById('scanBtn')?.addEventListener('click', function() {
            var form = document.getElementById('onboardForm');
            var checked = form.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) {
                alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>);
                return;
            }
            if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_scan_prefix')); ?> + checked.length + <?php echo json_encode(t('shadow-saas.js_confirm_scan_suffix')); ?>)) {
                document.getElementById('bulkAction').value = 'scan_selected';
                form.submit();
            }
        });

        // Restore selected confirmation
        document.getElementById('restoreSelectedBtn')?.addEventListener('click', function() {
            var form = document.getElementById('onboardForm');
            var checked = form.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) {
                alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>);
                return;
            }
            if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_restore_prefix')); ?> + checked.length + <?php echo json_encode(t('shadow-saas.js_confirm_restore_suffix')); ?>)) {
                document.getElementById('bulkAction').value = 'restore_selected';
                form.submit();
            }
        });

        // Dismiss selected confirmation
        document.getElementById('dismissSelectedBtn')?.addEventListener('click', function() {
            var form = document.getElementById('onboardForm');
            var checked = form.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) {
                alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>);
                return;
            }
            if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_dismiss_prefix')); ?> + checked.length + <?php echo json_encode(t('shadow-saas.js_confirm_dismiss_suffix')); ?>)) {
                document.getElementById('bulkAction').value = 'dismiss_selected';
                form.submit();
            }
        });

        // Delete selected confirmation
        document.getElementById('deleteBtn')?.addEventListener('click', function() {
            var form = document.getElementById('onboardForm');
            var checked = form.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) {
                alert(<?php echo json_encode(t('shadow-saas.js_select_one')); ?>);
                return;
            }
            if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_delete_prefix')); ?> + checked.length + <?php echo json_encode(t('shadow-saas.js_confirm_delete_suffix')); ?>)) {
                document.getElementById('bulkAction').value = 'delete_selected';
                form.submit();
            }
        });

        // Dismiss buttons (via separate row action form)
        document.querySelectorAll('.dismiss-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId = this.dataset.id;
                if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_dismiss_one')); ?>)) {
                    var form = document.getElementById('rowActionForm');
                    document.getElementById('rowAction').value = 'dismiss';
                    document.getElementById('rowDismissId').value = entryId;
                    form.submit();
                }
            });
        });

        // Undismiss/Restore buttons (via separate row action form)
        document.querySelectorAll('.undismiss-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId = this.dataset.id;
                if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_restore_one')); ?>)) {
                    var form = document.getElementById('rowActionForm');
                    document.getElementById('rowAction').value = 'undismiss';
                    document.getElementById('rowUndismissId').value = entryId;
                    form.submit();
                }
            });
        });

        // Deny buttons — mark the row Unsanctioned and (when Zscaler is enabled)
        // push the vendor domain into the URL Category.
        document.querySelectorAll('.deny-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId = this.dataset.id;
                if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_deny')); ?>)) {
                    var form = document.getElementById('rowActionForm');
                    document.getElementById('rowAction').value = 'deny_zscaler';
                    document.getElementById('rowDenyId').value = entryId;
                    form.submit();
                }
            });
        });

        // Allow buttons — onboard the vendor to TPRM (same as Onboard Selected)
        // and, if the domain was Zscaler-blocked, remove it from the URL Category.
        document.querySelectorAll('.allow-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId = this.dataset.id;
                if (confirm(<?php echo json_encode(t('shadow-saas.js_confirm_allow')); ?>)) {
                    var form = document.getElementById('rowActionForm');
                    document.getElementById('rowAction').value = 'allow_zscaler';
                    document.getElementById('rowAllowId').value = entryId;
                    form.submit();
                }
            });
        });

        // Expand/collapse detail rows (event delegation) — click anywhere on the row
        document.querySelector('table')?.addEventListener('click', function(e) {
            // Skip if clicking an interactive element
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            var summaryRow = e.target.closest('tr');
            if (!summaryRow || summaryRow.classList.contains('detail-row')) return;
            var detailRow = summaryRow.nextElementSibling;
            if (!detailRow || !detailRow.classList.contains('detail-row')) return;
            detailRow.classList.toggle('open');
        });

        // Edit modal
        var editModal = document.getElementById('editModal');
        document.querySelectorAll('.edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('editId').value = this.dataset.id;
                document.getElementById('editVendorName').value = this.dataset.name;
                document.getElementById('editVendorDomain').value = this.dataset.domain;
                document.getElementById('editDescription').value = this.dataset.description;
                document.getElementById('editRiskScore').value = this.dataset.risk;
                document.getElementById('editRiskType').value = this.dataset.risktype;
                document.getElementById('editRm').value = this.dataset.rm;
                document.getElementById('editUsers').value = this.dataset.users;
                document.getElementById('editCategory').value = this.dataset.category;
                document.getElementById('editBreaches').value = this.dataset.breaches;
                document.getElementById('editDl').value = this.dataset.dl;
                document.getElementById('editUl').value = this.dataset.ul;
                document.getElementById('editFs').value = this.dataset.fs;
                document.getElementById('editMfa').value = this.dataset.mfa;
                editModal.style.display = 'block';
            });
        });
        document.getElementById('editCancelBtn')?.addEventListener('click', function() {
            editModal.style.display = 'none';
        });
        editModal?.addEventListener('click', function(e) {
            if (e.target === editModal) editModal.style.display = 'none';
        });

        // Per-page selector
        document.getElementById('perPageSelect')?.addEventListener('change', function() {
            var p = new URLSearchParams(window.location.search);
            p.set('per_page', this.value);
            p.set('page', '1');
            window.location.search = p.toString();
        });

        // Add modal
        var addModal = document.getElementById('addModal');
        document.getElementById('addEntryBtn')?.addEventListener('click', function() {
            addModal.style.display = 'block';
        });
        document.getElementById('addCancelBtn')?.addEventListener('click', function() {
            addModal.style.display = 'none';
        });
        addModal?.addEventListener('click', function(e) {
            if (e.target === addModal) addModal.style.display = 'none';
        });
    </script>
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
    <?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
