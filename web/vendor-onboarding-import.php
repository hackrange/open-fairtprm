<?php
/**
 * Vendor Onboarding CSV Import / Mass Update - The Bulk Loading Dock
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Upload a CSV to bulk-create or mass-update vendor onboarding requests.
 * If a vendor_domain in the CSV matches an existing record, that record
 * is updated rather than duplicated. Stakeholders are assigned by username;
 * if no stakeholder is specified, the importing user is assigned. Features
 * a downloadable template with sample data, extensive field validation
 * (yes/no fields, impact levels, status values, vendor types), and audit
 * logging to the cyber_todo_activities table. Everything runs in a database
 * transaction so if row 47 blows up, you don't lose rows 1-46. Max file
 * size is 10MB because your vendor list shouldn't be bigger than a feature film.
 */

require_once 'includes/init.php';
requireAuth(); // No anonymous data imports, please

// The usual suspects
$auth = Auth::getInstance();
$user = $auth->getUser();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();

// ============================================================================
// PERMISSION CHECK
// Only admins and cyber TPRM can import -- too much power for mere mortals.
// One bad CSV could create hundreds of garbage records.
// ============================================================================
$canImport = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if (!$canImport) {
    http_response_code(403);
    die(e(t('vendor-onboarding-import.access_denied')));
}

// Get theme for the page styling
$theme = getUserTheme($user['id']);

$success = '';
$error = '';
$importResults = [];

// ============================================================================
// CSV TEMPLATE DOWNLOAD HANDLER
// When someone hits ?download_template=1, spit out a CSV with headers and
// one sample row so they know exactly what format we expect.
// ============================================================================
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vendor_onboarding_import_template.csv');

    $output = fopen('php://output', 'w');

    // All the columns we accept -- matches the export format minus read-only fields
    $headers = [
        'vendor_name',
        'vendor_domain',
        'vendor_id',
        'vendor_type',
        'relationship_manager',
        'expected_procurement_date',
        'product_service_description',
        'target_user_count',
        'primary_contact_email',
        'primary_contact_details',
        'primary_contact_title',
        'primary_contact_phone',
        'nda_in_place',
        'vendor_competitors',
        'pii_phi_exchange',
        'pii_phi_justification',
        'confidential_info_shared',
        'confidential_info_justification',
        'cross_border_transfer',
        'cross_border_justification',
        'offsite_data_hosting',
        'offsite_data_justification',
        'remote_network_access',
        'remote_access_justification',
        'source_code_access',
        'source_code_justification',
        'critical_business_function',
        'critical_function_justification',
        'unauthorized_disclosure_impact',
        'unauthorized_disclosure_justification',
        'unauthorized_modification_impact',
        'disruption_impact',
        'saml_sso_support',
        'is_saas',
        'pii_record_count',
        'spii_record_count',
        'sox_record_count',
        'business_impact',
        'vsu_onboarded',
        'vendor_tier',
        'vendor_tier_justification',
        'additional_information',
        'status',
        'stakeholder_username'
    ];

    // Append a custom:<field_name> column for each custom field defined on any
    // onboarding template, so importers know exactly which custom fields exist.
    require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
    $tplCustomFields = (new VendorAssessmentService())->getOnboardingCustomFieldNames();
    foreach ($tplCustomFields as $fn) {
        $headers[] = 'custom:' . $fn;
    }

    fputcsv($output, $headers);

    // A realistic-looking sample row so people don't have to guess the format
    $sampleData = [
        'Acme Cloud Services',           // vendor_name
        'acmecloud.com',                  // vendor_domain
        '1234',                           // vendor_id
        'TECHNOLOGY',                     // vendor_type
        'John Smith',                     // relationship_manager
        '2025-06-01',                     // expected_procurement_date
        'Cloud storage and backup services for enterprise data', // product_service_description
        '100-500',                        // target_user_count
        'vendor@acmecloud.com',           // primary_contact_email
        'Jane Doe',                           // primary_contact_details
        'VP Sales',                           // primary_contact_title
        '555-123-4567',                       // primary_contact_phone
        'yes',                                // nda_in_place
        'CloudCorp, DataSafe Inc',        // vendor_competitors
        'yes',                            // pii_phi_exchange
        'Customer data for backup',       // pii_phi_justification
        'yes',                            // confidential_info_shared
        'Internal documents backed up',   // confidential_info_justification
        'no',                             // cross_border_transfer
        '',                               // cross_border_justification
        'yes',                            // offsite_data_hosting
        'AWS US-East region',             // offsite_data_justification
        'no',                             // remote_network_access
        '',                               // remote_access_justification
        'no',                             // source_code_access
        '',                               // source_code_justification
        'yes',                            // critical_business_function
        'Business continuity depends on backups', // critical_function_justification
        'high',                           // unauthorized_disclosure_impact
        'Contains customer PII',          // unauthorized_disclosure_justification
        'moderate',                       // unauthorized_modification_impact
        'high',                           // disruption_impact
        'yes',                            // saml_sso_support
        'yes',                            // is_saas
        '50000',                          // pii_record_count
        '5000',                           // spii_record_count
        '1000',                           // sox_record_count
        '100000',                         // business_impact
        'yes',                            // vsu_onboarded
        '1',                              // vendor_tier
        'Critical vendor - handles customer PII', // vendor_tier_justification
        'Priority vendor for Q2 rollout', // additional_information
        'draft',                          // status
        'jsmith'                          // stakeholder_username
    ];

    // One (blank) sample cell per custom field column, keeping the row aligned
    // with the header. Custom values are only written to vendors that already have
    // a matching onboarding assessment question.
    foreach ($tplCustomFields as $fn) {
        $sampleData[] = '';
    }

    fputcsv($output, $sampleData);
    fclose($output);
    exit;
}

// ============================================================================
// CSV IMPORT HANDLER (POST)
// The big one -- reads the uploaded CSV, validates every field, inserts
// records into the database, assigns stakeholders, and logs the whole thing
// for audit purposes. All wrapped in a transaction for safety.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // CSRF validation -- because we're responsible adults
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('vendor-onboarding-import.invalid_token');
        goto render;
    }

    $file = $_FILES['csv_file'];

    // Basic file validation -- check for upload errors, size, and type
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = t('vendor-onboarding-import.upload_error');
    } elseif ($file['size'] > 10485760) { // 10MB -- if your CSV is bigger than this, something's wrong
        $error = t('vendor-onboarding-import.file_too_large');
    } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt'])) {
        $error = t('vendor-onboarding-import.invalid_file_type');
    } else {
        // Open the file and start processing
        $handle = fopen($file['tmp_name'], 'r');

        if ($handle !== false) {
            // First row is headers
            $headers = fgetcsv($handle, 0, ',', '"', '');

            $rowNumber = 1;
            $successCount = 0;
            $createCount = 0;
            $updateCount = 0;
            $errorCount = 0;
            $importedVendorIds = []; // Track for audit trail
            // Custom onboarding field import (custom:<field_name> columns)
            $customWrittenCount = 0;
            $customSkippedCount = 0;
            require_once __DIR__ . '/includes/classes/VendorAssessmentService.php';
            $vasImport = new VendorAssessmentService();

            // ================================================================
            // VALID ENUM VALUES
            // These are the only values we accept for constrained fields.
            // Anything else gets silently cleared to empty string.
            // ================================================================
            $validYesNo = ['yes', 'no', ''];
            $validYesNoUnknown = ['yes', 'no', 'unknown', ''];
            $validImpact = ['low', 'moderate', 'high', 'severe', ''];
            $validStatus = ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'inactive'];
            $validVendorTypes = ['GENERAL OPERATIONS', 'TECHNOLOGY', 'OFFICE SERVICES', 'PROFESSIONAL SERVICES', 'HUMAN RESOURCES', 'FINANCIAL SERVICES', 'MARKETING', 'FACILITIES', 'LEGAL', 'OTHER'];
            $validTiers = ['1', '2', '3', ''];

            // Wrap everything in a transaction -- all or nothing
            $db->beginTransaction();

            try {
                while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $rowNumber++;

                    // Skip empty rows -- happens a lot with Excel exports
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    try {
                        // Make sure the column count matches the header count
                        if (count($headers) !== count($data)) {
                            throw new Exception('Column count mismatch. Expected ' . count($headers) . ' columns, got ' . count($data));
                        }
                        $row = array_combine($headers, $data);

                        // Vendor name is the only truly required field
                        if (empty($row['vendor_name'])) {
                            throw new Exception('Vendor name is required');
                        }

                        // Validate and normalize the NDA field
                        $row['nda_in_place'] = strtolower(trim($row['nda_in_place'] ?? ''));
                        if (!empty($row['nda_in_place']) && !in_array($row['nda_in_place'], $validYesNo)) {
                            $row['nda_in_place'] = '';
                        }

                        // Validate all yes/no fields in bulk -- there are a lot of them
                        $yesNoFields = ['pii_phi_exchange', 'confidential_info_shared', 'cross_border_transfer',
                                       'offsite_data_hosting', 'remote_network_access', 'source_code_access',
                                       'critical_business_function', 'is_saas', 'vsu_onboarded'];
                        foreach ($yesNoFields as $field) {
                            $row[$field] = strtolower(trim($row[$field] ?? ''));
                            if (!empty($row[$field]) && !in_array($row[$field], $validYesNo)) {
                                $row[$field] = '';
                            }
                        }

                        // SAML/SSO has three valid values: yes, no, unknown
                        $row['saml_sso_support'] = strtolower(trim($row['saml_sso_support'] ?? ''));
                        if (!empty($row['saml_sso_support']) && !in_array($row['saml_sso_support'], $validYesNoUnknown)) {
                            $row['saml_sso_support'] = '';
                        }

                        // Impact fields -- low, moderate, high, severe
                        $impactFields = ['unauthorized_disclosure_impact', 'unauthorized_modification_impact', 'disruption_impact'];
                        foreach ($impactFields as $field) {
                            $row[$field] = strtolower(trim($row[$field] ?? ''));
                            if (!empty($row[$field]) && !in_array($row[$field], $validImpact)) {
                                $row[$field] = '';
                            }
                        }

                        // Vendor tier -- 1, 2, or 3
                        $row['vendor_tier'] = trim($row['vendor_tier'] ?? '');
                        if (!in_array($row['vendor_tier'], $validTiers)) {
                            $row['vendor_tier'] = '';
                        }

                        // Status defaults to draft if not specified or invalid
                        $row['status'] = strtolower(trim($row['status'] ?? 'draft'));
                        if (!in_array($row['status'], $validStatus)) {
                            $row['status'] = 'draft';
                        }

                        // Vendor type is uppercased for consistency
                        $row['vendor_type'] = strtoupper(trim($row['vendor_type'] ?? ''));
                        if (!empty($row['vendor_type']) && !in_array($row['vendor_type'], $validVendorTypes)) {
                            // Non-standard types are kept as-is -- we're not that strict
                        }

                        // Parse the procurement date -- accepts any format strtotime understands
                        $procDate = null;
                        if (!empty($row['expected_procurement_date'])) {
                            $parsedDate = strtotime($row['expected_procurement_date']);
                            if ($parsedDate !== false) {
                                $procDate = date('Y-m-d', $parsedDate);
                            }
                        }

                        // Build the insert data array -- the big mapping from CSV to database
                        $insertData = [
                            'created_by' => $user['id'],
                            'vendor_name' => trim($row['vendor_name']),
                            'vendor_domain' => trim($row['vendor_domain'] ?? '') ?: null,
                            'vendor_id' => trim($row['vendor_id'] ?? '') ?: null,
                            'vendor_type' => $row['vendor_type'] ?: null,
                            'relationship_manager' => trim($row['relationship_manager'] ?? '') ?: null,
                            'expected_procurement_date' => $procDate,
                            'product_service_description' => trim($row['product_service_description'] ?? '') ?: null,
                            'target_user_count' => trim($row['target_user_count'] ?? '') ?: null,
                            'primary_contact_email' => trim($row['primary_contact_email'] ?? '') ?: null,
                            'primary_contact_details' => trim($row['primary_contact_details'] ?? '') ?: null,
                            'primary_contact_title' => trim($row['primary_contact_title'] ?? '') ?: null,
                            'primary_contact_phone' => trim($row['primary_contact_phone'] ?? '') ?: null,
                            'nda_in_place' => $row['nda_in_place'] ?: '',
                            'vendor_competitors' => trim($row['vendor_competitors'] ?? '') ?: null,
                            // Data & Security questions
                            'pii_phi_exchange' => $row['pii_phi_exchange'] ?: '',
                            'pii_phi_justification' => trim($row['pii_phi_justification'] ?? '') ?: null,
                            'confidential_info_shared' => $row['confidential_info_shared'] ?: '',
                            'confidential_info_justification' => trim($row['confidential_info_justification'] ?? '') ?: null,
                            'cross_border_transfer' => $row['cross_border_transfer'] ?: '',
                            'cross_border_justification' => trim($row['cross_border_justification'] ?? '') ?: null,
                            'offsite_data_hosting' => $row['offsite_data_hosting'] ?: '',
                            'offsite_data_justification' => trim($row['offsite_data_justification'] ?? '') ?: null,
                            'remote_network_access' => $row['remote_network_access'] ?: '',
                            'remote_access_justification' => trim($row['remote_access_justification'] ?? '') ?: null,
                            'source_code_access' => $row['source_code_access'] ?: '',
                            'source_code_justification' => trim($row['source_code_justification'] ?? '') ?: null,
                            'critical_business_function' => $row['critical_business_function'] ?: '',
                            'critical_function_justification' => trim($row['critical_function_justification'] ?? '') ?: null,
                            'unauthorized_disclosure_impact' => $row['unauthorized_disclosure_impact'] ?: '',
                            'unauthorized_disclosure_justification' => trim($row['unauthorized_disclosure_justification'] ?? '') ?: null,
                            'unauthorized_modification_impact' => $row['unauthorized_modification_impact'] ?: '',
                            'disruption_impact' => $row['disruption_impact'] ?: '',
                            'saml_sso_support' => $row['saml_sso_support'] ?: '',
                            'is_saas' => $row['is_saas'] ?: '',
                            // FAIR fields -- numeric values clamped to 0 minimum
                            'pii_record_count' => max(0, intval($row['pii_record_count'] ?? 0)),
                            'spii_record_count' => max(0, intval($row['spii_record_count'] ?? 0)),
                            'sox_record_count' => max(0, intval($row['sox_record_count'] ?? 0)),
                            'business_impact' => !empty($row['business_impact']) ? floatval($row['business_impact']) : null,
                            // VSU field
                            'vsu_onboarded' => $row['vsu_onboarded'] ?: '',
                            // Vendor Tier
                            'vendor_tier' => !empty($row['vendor_tier']) ? $row['vendor_tier'] : null,
                            // Additional info
                            'additional_information' => trim($row['additional_information'] ?? '') ?: null,
                            // Status
                            'status' => $row['status']
                        ];

                        // If status is anything other than draft, set the submitted timestamp
                        if ($row['status'] !== 'draft') {
                            $insertData['submitted_at'] = date('Y-m-d H:i:s');
                        }

                        // ====================================================
                        // UPSERT LOGIC
                        // Check if a vendor with this domain already exists.
                        // If so, update the existing record instead of creating
                        // a duplicate. Domain match is the key identifier.
                        // ====================================================
                        $existingVendor = null;
                        $isUpdate = false;
                        $vendorDomain = trim($row['vendor_domain'] ?? '');
                        if (!empty($vendorDomain)) {
                            $existingVendor = $db->fetchOne(
                                'SELECT id, vendor_tier, additional_information FROM vendor_onboarding_requests WHERE vendor_domain = :domain',
                                [':domain' => $vendorDomain]
                            );
                        }

                        // If a tier is specified with a justification, append a tier change log
                        // to additional_information (same format as the tier change modal)
                        $tierJustification = trim($row['vendor_tier_justification'] ?? '');
                        if (!empty($row['vendor_tier']) && !empty($tierJustification)) {
                            $tierMap = [
                                '' => 'Not Assigned',
                                '1' => 'Tier 1 - Critical (Monthly rescoring)',
                                '2' => 'Tier 2 - Standard (90-day rescoring)',
                                '3' => 'Tier 3 - Low Priority (Annual rescoring)'
                            ];
                            $oldTier = $existingVendor ? ($existingVendor['vendor_tier'] ?? '') : '';
                            $userName = $user['full_name'] ?? $user['username'] ?? 'Unknown User';
                            $logEntry = "\n\n--- Tier Change Log ---\nDate: " . date('m/d/Y, h:i A') . "\nUser: {$userName}\nChanged From: " . ($tierMap[$oldTier] ?? 'Not Assigned') . "\nChanged To: " . ($tierMap[$row['vendor_tier']] ?? 'Unknown') . "\nReason: {$tierJustification}\n-----------------------";

                            // Build the final additional_information with tier log appended
                            $csvAdditionalInfo = trim($row['additional_information'] ?? '');
                            if ($existingVendor) {
                                // For updates: start with existing info, append CSV info (if any), then tier log
                                $base = $existingVendor['additional_information'] ?? '';
                                if (!empty($csvAdditionalInfo)) {
                                    $base = $csvAdditionalInfo;
                                }
                                $insertData['additional_information'] = $base . $logEntry;
                            } else {
                                // For inserts: CSV info + tier log
                                $insertData['additional_information'] = ($csvAdditionalInfo ?: '') . $logEntry;
                            }
                        }

                        if ($existingVendor) {
                            // Update existing record -- don't overwrite created_by
                            $isUpdate = true;
                            $recordId = $existingVendor['id'];
                            unset($insertData['created_by']);
                            $db->update('vendor_onboarding_requests', $insertData, 'id = :id', [':id' => $recordId]);
                        } else {
                            // Insert new record
                            $recordId = $db->insert('vendor_onboarding_requests', $insertData);
                        }

                        // ====================================================
                        // STAKEHOLDER ASSIGNMENT
                        // Look up by username. If stakeholder_username is empty,
                        // default to the user performing the import.
                        // ====================================================
                        $stakeholderMessage = '';
                        $stakeholderUsername = trim($row['stakeholder_username'] ?? '');
                        $stakeholderUserId = null;

                        if (!empty($stakeholderUsername)) {
                            $stakeholderUser = $db->fetchOne(
                                'SELECT id FROM users WHERE username = :username AND is_active = 1',
                                [':username' => $stakeholderUsername]
                            );

                            if ($stakeholderUser) {
                                $stakeholderUserId = $stakeholderUser['id'];
                            } else {
                                $stakeholderMessage = ' (Stakeholder user not found: ' . $stakeholderUsername . ')';
                            }
                        } else {
                            // No stakeholder specified -- default to importing user
                            $stakeholderUserId = $user['id'];
                        }

                        if ($stakeholderUserId) {
                            try {
                                // Check if this stakeholder is already assigned
                                $existingStakeholder = $db->fetchOne(
                                    'SELECT id FROM vendor_onboarding_stakeholders WHERE request_id = :rid AND user_id = :uid',
                                    [':rid' => $recordId, ':uid' => $stakeholderUserId]
                                );
                                if (!$existingStakeholder) {
                                    $db->insert('vendor_onboarding_stakeholders', [
                                        'request_id' => $recordId,
                                        'user_id' => $stakeholderUserId,
                                        'role' => 'stakeholder',
                                        'assigned_by' => $user['id']
                                    ]);
                                }
                                $assignedName = !empty($stakeholderUsername) ? $stakeholderUsername : $user['username'];
                                $stakeholderMessage = ' (Stakeholder: ' . $assignedName . ')';
                            } catch (Exception $e) {
                                $stakeholderMessage = ' (Stakeholder assignment error: ' . $e->getMessage() . ')';
                            }
                        }

                        // ====================================================
                        // CUSTOM ONBOARDING FIELDS
                        // Any "custom:<field_name>" column is written back to the
                        // vendor's existing onboarding assessment response (matched
                        // by question field_name). Update-existing-only: a value with
                        // no matching assessment/question is skipped and reported.
                        // Blank cells are left alone (never wipe existing answers).
                        // ====================================================
                        $customSkippedFields = [];
                        foreach ($row as $colKey => $colVal) {
                            if (strncmp((string)$colKey, 'custom:', 7) !== 0) continue;
                            $fieldName = trim(substr($colKey, 7));
                            $colVal = trim((string)$colVal);
                            if ($fieldName === '' || $colVal === '') continue;
                            if ($vasImport->setCustomOnboardingValue($recordId, $fieldName, $colVal)) {
                                $customWrittenCount++;
                            } else {
                                $customSkippedCount++;
                                $customSkippedFields[] = $fieldName;
                            }
                        }
                        $customMessage = '';
                        if (!empty($customSkippedFields)) {
                            $customMessage = ' (Custom fields skipped — no onboarding assessment/question: '
                                . implode(', ', array_slice($customSkippedFields, 0, 5))
                                . (count($customSkippedFields) > 5 ? '…' : '') . ')';
                        }

                        $actionLabel = $isUpdate ? 'Updated' : 'Imported';
                        $successCount++;
                        if ($isUpdate) { $updateCount++; } else { $createCount++; }
                        $importedVendorIds[] = $recordId;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'vendor' => $row['vendor_name'],
                            'vendor_id' => $recordId,
                            'status' => 'success',
                            'message' => $actionLabel . ' successfully' . $stakeholderMessage . $customMessage
                        ];

                    } catch (Exception $e) {
                        $errorCount++;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'vendor' => $row['vendor_name'] ?? 'Unknown',
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ];
                    }
                }

                // Commit the transaction -- everything that didn't throw an exception is saved
                $db->commit();

                // ============================================================
                // AUDIT LOG
                // Record the import in cyber_todo_activities so there's a paper
                // trail. If this fails, we just log the error and move on --
                // the actual import already succeeded.
                // ============================================================
                if ($successCount > 0 && !empty($importedVendorIds)) {
                    try {
                        $importedVendorNames = [];
                        foreach ($importResults as $result) {
                            if ($result['status'] === 'success') {
                                $importedVendorNames[] = $result['vendor'];
                            }
                        }

                        $metadata = json_encode([
                            'vendor_ids' => $importedVendorIds,
                            'vendor_names' => $importedVendorNames,
                            'file_name' => $file['name'],
                            'total_rows' => $rowNumber - 1,
                            'success_count' => $successCount,
                            'created_count' => $createCount,
                            'updated_count' => $updateCount,
                            'error_count' => $errorCount
                        ]);

                        // Build a human-readable description with the first 20 vendor names
                        $description = "Processed {$successCount} vendors ({$createCount} created, {$updateCount} updated) from CSV file: {$file['name']}";
                        if ($errorCount > 0) {
                            $description .= " ({$errorCount} rows failed)";
                        }
                        $description .= "\n\nImported vendors: " . implode(', ', array_slice($importedVendorNames, 0, 20));
                        if (count($importedVendorNames) > 20) {
                            $description .= " and " . (count($importedVendorNames) - 20) . " more...";
                        }

                        $db->query(
                            "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, title, description, metadata, status, created_by)
                             VALUES ('vendor_import', 'vendor_onboarding_requests', 0, 'import', :title, :description, :metadata, 'closed', :created_by)",
                            [
                                ':title' => "Vendor Import: {$createCount} created, {$updateCount} updated",
                                ':description' => $description,
                                ':metadata' => $metadata,
                                ':created_by' => $user['id']
                            ]
                        );

                        $auth->audit($user['id'], 'case_create', 'cyber_todo_activities', null, [
                            'new' => [
                                'todo_type' => 'vendor_import',
                                'title' => "Vendor Import: {$createCount} created, {$updateCount} updated",
                                'vendor_count' => $successCount,
                                'created_count' => $createCount,
                                'updated_count' => $updateCount,
                                'error_count' => $errorCount,
                            ]
                        ]);
                    } catch (Exception $e) {
                        error_log('Failed to log vendor import activity: ' . $e->getMessage());
                    }
                }

                $success = "Import completed: {$successCount} records processed ({$createCount} created, {$updateCount} updated)";
                if ($errorCount > 0) {
                    $success .= ", {$errorCount} records failed";
                }
                if ($customWrittenCount > 0 || $customSkippedCount > 0) {
                    $success .= ". Custom fields: {$customWrittenCount} updated";
                    if ($customSkippedCount > 0) {
                        $success .= ", {$customSkippedCount} skipped (no matching onboarding assessment/question)";
                    }
                }

            } catch (Exception $e) {
                // Something went really wrong -- rollback the whole transaction
                $db->rollback();
                $error = 'Import failed: ' . $e->getMessage();
            }

            fclose($handle);
        } else {
            $error = t('vendor-onboarding-import.unable_to_read');
        }
    }
}

render:
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('vendor-onboarding-import.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        /* Theme variables */
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
        }

        /* Kill the default navbar decorative stripe */
        nav.rd-navbar.rd-navbar-modern.rd-navbar-modern-1::before,
        nav.rd-navbar.rd-navbar-modern.rd-navbar-static::before {
            display: none !important;
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

        /* Main content container */
        .import-container {
            padding: 40px 0;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-title {
            font-size: 20px;
            font-weight: 500;
            margin-bottom: 20px;
            color: #333;
        }
        .btn-primary {
            background: var(--theme-button-color);
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            filter: brightness(1.1);
        }
        .btn-success {
            background: #28a745;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            border: none;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
        }

        /* Success/error message boxes */
        .message {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .message-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Import results table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: var(--theme-header-color);
            color: white;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .table-success {
            background-color: #d4edda;
        }
        .table-danger {
            background-color: #f8d7da;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        .bg-success {
            background-color: #28a745;
            color: white;
        }
        .bg-danger {
            background-color: #dc3545;
            color: white;
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
        .page-title { font-size: 18px; font-weight: 600; color: #333; }
    </style>
</head>
<body style="background: #fff;">
    <?php renderImpersonationBanner(); ?>
    <div class="page" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
        <!-- Header with logo and nav -->
        <header class="section page-header">
            <div class="rd-navbar-wrap">
                <nav class="rd-navbar rd-navbar-modern rd-navbar-modern-1">
                    <div class="rd-navbar-main-outer">
                        <div class="rd-navbar-main">
                            <div class="rd-navbar-panel">
                                <div class="rd-navbar-brand">
                                    <a class="brand" href="index.php">
                                        <img class="brand-logo-dark" src="<?php echo e($theme['logo_url']); ?>" alt="" width="209" height="39"/>
                                    </a>
                                    <div style="margin-top: 5px; color: #333; font-size: 16px; font-weight: 500;">
                                        <?php echo e(t('vendor-onboarding-import.brand_subtitle')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: #333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('vendor-onboarding-import.dashboard')); ?></a>
                                <?php if ($auth->isAdmin()): ?>
                                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                                <?php endif; ?>
                                <a href="vendor-onboarding-list.php"><?php echo e(t('vendor-onboarding-import.onboarding_list')); ?></a>
                                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <div class="import-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <h1 class="page-title"><?php echo e(t('vendor-onboarding-import.heading')); ?></h1>
                    <a href="vendor-onboarding-list.php" class="btn-secondary">
                        &larr; <?php echo e(t('vendor-onboarding-import.back_to_list')); ?>
                    </a>
                </div>

                <?php // Success/error flash messages ?>
                <?php if ($success): ?>
                <div class="message message-success">
                    &#10003; <?php echo e($success); ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="message message-error">
                    &#10007; <?php echo e($error); ?>
                </div>
                <?php endif; ?>

                <!-- Instructions Card -- how to use the import feature -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-onboarding-import.how_to_title')); ?>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('vendor-onboarding-import.step1_title')); ?></h5>
                            <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('vendor-onboarding-import.step1_desc')); ?></p>

                            <a href="?download_template=1" class="btn-success" style="display: inline-block; margin-bottom: 20px;">
                                <?php echo e(t('vendor-onboarding-import.download_template')); ?>
                            </a>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-onboarding-import.step2_title')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><?php echo e(t('vendor-onboarding-import.step2_open')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.step2_review')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.step2_delete')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.step2_add')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.step2_save')); ?></li>
                            </ul>
                        </div>

                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('vendor-onboarding-import.required_fields')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><strong>vendor_name</strong> - Vendor company name</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-onboarding-import.optional_fields')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><strong>stakeholder_username</strong> - Username of user to assign as stakeholder. If left blank, you (the importer) will be assigned as stakeholder.</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-onboarding-import.mass_update_behavior')); ?></h5>
                            <ul style="color: #666; line-height: 1.8; font-size: 13px;">
                                <li><strong>Duplicate detection:</strong> If a vendor with the same <strong>vendor_domain</strong> already exists, the record will be <strong>updated</strong> instead of duplicated</li>
                                <li><strong>New vendors:</strong> Vendors with a new or empty domain will be created as new records</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-onboarding-import.valid_values')); ?></h5>
                            <ul style="color: #666; line-height: 1.8; font-size: 13px;">
                                <li><strong>Yes/No fields:</strong> yes, no, or empty</li>
                                <li><strong>Impact levels:</strong> low, moderate, high, severe</li>
                                <li><strong>Status:</strong> draft, submitted, in_review, approved, rejected, inactive</li>
                                <li><strong>vendor_tier:</strong> 1 (Critical/Monthly), 2 (Standard/90-day), 3 (Low Priority/Annual), or empty</li>
                                <li><strong>vendor_tier_justification:</strong> Reason for tier assignment (logged in additional information)</li>
                                <li><strong>Dates:</strong> YYYY-MM-DD format (e.g., 2025-06-01)</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-onboarding-import.important_notes')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><?php echo e(t('vendor-onboarding-import.note_file_size')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.note_format')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.note_new_records')); ?></li>
                                <li><?php echo e(t('vendor-onboarding-import.note_updated_records')); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Upload Form Card -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-onboarding-import.upload_csv_file')); ?>
                    </h4>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo e($security->getCSRFToken()); ?>">

                        <div style="margin-bottom: 20px;">
                            <label for="csv_file" style="display: block; margin-bottom: 8px; font-weight: 500;"><?php echo e(t('vendor-onboarding-import.select_csv_file')); ?></label>
                            <input type="file" id="csv_file" name="csv_file"
                                   accept=".csv,text/csv" required
                                   style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 500px;">
                            <div style="margin-top: 5px; font-size: 14px; color: #666;"><?php echo e(t('vendor-onboarding-import.upload_hint')); ?></div>
                        </div>

                        <button type="submit" class="btn-primary" style="font-size: 16px;">
                            <?php echo e(t('vendor-onboarding-import.submit_button')); ?>
                        </button>
                    </form>
                </div>

                <!-- Import Results Table -- shows after a successful import -->
                <?php if (!empty($importResults)): ?>
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-onboarding-import.import_results')); ?>
                    </h4>

                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo e(t('vendor-onboarding-import.col_row')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-import.col_vendor_name')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-import.col_status')); ?></th>
                                    <th><?php echo e(t('vendor-onboarding-import.col_message')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($importResults as $result): ?>
                                <tr class="<?php echo $result['status'] === 'success' ? 'table-success' : 'table-danger'; ?>">
                                    <td><?php echo e($result['row']); ?></td>
                                    <td><?php echo e($result['vendor']); ?></td>
                                    <td>
                                        <?php if ($result['status'] === 'success'): ?>
                                            <span class="badge bg-success"><?php echo e(t('vendor-onboarding-import.status_success')); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo e(t('vendor-onboarding-import.status_error')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e($result['message']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>
