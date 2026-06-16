<?php
/**
 * FAIR Bulk Import - The CSV Shoveling Machine
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Got 50 vendors to assess and don't feel like clicking through a form 50 times?
 * This page lets you upload a CSV file and create multiple FAIR analyses in one shot.
 * It'll validate your data, encrypt the sensitive stuff, run the FAIR calculations,
 * and give you a nice little report card showing which rows made it and which ones
 * face-planted. Also includes a template download so you don't have to guess the format.
 */

// Standard app bootstrap -- auth, DB, encryption, the whole gang
require_once 'includes/init.php';
require_once 'includes/FairCalculator.php';
requireAuth(); // You shall not pass without credentials
requirePermission("analysis.create");

$auth = Auth::getInstance();
$user = $auth->getUser();
$db = Database::getInstance();
$encryption = new Encryption();
$security = Security::getInstance();

// Grab the user's theme so the page doesn't look like a default Bootstrap nightmare
$theme = getUserTheme($user['id']);

// These will hold any messages we want to show the user after processing
$success = '';
$error = '';
$importResults = []; // Per-row results so the user knows what happened

// ============================================================================
// TEMPLATE DOWNLOAD
// Give the user a nice CSV template with headers and a sample row.
// This way they don't have to reverse-engineer the column order.
// ============================================================================
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fair_analysis_import_template.csv');

    $output = fopen('php://output', 'w');

    // All the column headers -- there's a ton of them because FAIR analysis
    // is thorough like that. Each one maps to a field in the tprm_results table.
    $headers = [
        'vendor_name', 'vendor_domain', 'msa', 'scope_of_work', 'medium_of_data',
        'certifications', 'compliance', 'security_governance', 'incident_response_plan',
        'continuous_monitoring', 'supply_chain_risk_mgmt', 'security_awareness_training',
        'vulnerability_management', 'patch_management', 'access_controls', 'data_encryption',
        'network_security', 'daily_impact', 'security_score', 'vulnerability_data',
        'configuration_data', 'compliance_data', 'risk_assessment', 'threat_intelligence',
        'iso_27001_certified', 'securityscorecard_rating', 'pii_record_count',
        'spii_record_count', 'sox_record_count', 'vendor_risk_assessment',
        'security_questionnaire', 'compliance_questionnaire', 'data_classification',
        'data_sharing', 'business_impact', 'vendor_performance', 'third_party_vendor_list',
        'third_party_risk_assessment', 'third_party_security_questionnaire',
        'third_party_compliance_questionnaire', 'vendor_cyber_insurance_coverage',
        'cost_of_breach', 'cost_of_outage', 'sec_fines', 'compliance_fines',
        'insurance_premiums'
    ];

    fputcsv($output, $headers);

    // A sample data row so users know what "good" data looks like.
    // This is basically a fictional vendor that has their act together.
    $sampleData = [
        'Acme Cloud Services', 'acmecloud.com', 'Yes - Signed 2025-01-15',
        'Cloud storage and backup services for customer data',
        'Encrypted API, SFTP', 'ISO 27001, SOC 2 Type II',
        'GDPR, HIPAA, PCI-DSS compliant', 'CISO-led security committee, quarterly reviews',
        'Documented 24/7 incident response team', 'SIEM monitoring, quarterly audits',
        'Vendor risk assessments, SLA monitoring', 'Monthly security awareness training',
        'Quarterly vulnerability scans', 'Automated monthly patching',
        'MFA enforced, role-based access control', 'AES-256 encryption at rest and in transit',
        'Firewall, IDS/IPS, network segmentation', '50000',
        'B', 'Low severity vulnerabilities identified', 'Strong security configuration',
        'GDPR, HIPAA compliant', 'Medium risk - adequate controls',
        'Medium threat level', '1', 'B', '100000', '50000', '10000',
        'Completed - Medium risk rating', 'Completed - Satisfactory controls',
        'Completed - Compliant', 'Confidential', 'Yes', 'High - critical system',
        'Good - 99.9% uptime', 'AWS, Google Cloud', 'Subcontractors assessed',
        'Security questionnaires completed', 'Compliance verified',
        '5000000', '250000', '100000', '50000', '75000', '25000'
    ];

    fputcsv($output, $sampleData);
    fclose($output);
    exit; // Template delivered. Our work here is done.
}

// ============================================================================
// CSV IMPORT HANDLER
// The main event. User uploads a CSV, we parse it row by row, encrypt the
// sensitive fields, calculate FAIR metrics, and shove it all into the database.
// If something goes wrong on a row, we log the error and keep trucking.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // CSRF check -- standard operating procedure
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('fair_import-analysis.invalid_token');
        goto render;
    }

    $file = $_FILES['csv_file'];

    // Basic file validation -- size, type, upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = t('fair_import-analysis.upload_error');
    } elseif ($file['size'] > 10485760) { // 10MB -- if your CSV is bigger than this, we need to talk
        $error = t('fair_import-analysis.file_too_large');
    } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt'])) {
        $error = t('fair_import-analysis.invalid_file_type');
    } else {
        // Alright, the file looks legit. Let's crack it open.
        $handle = fopen($file['tmp_name'], 'r');

        if ($handle !== false) {
            // First line is the header row -- we need this to map columns to data
            $headers = fgetcsv($handle, 0, ',', '"', '\\');

            $rowNumber = 1;
            $successCount = 0;
            $errorCount = 0;

            // Wrap everything in a transaction. If something catastrophic happens,
            // we can roll the whole thing back instead of leaving half-baked data.
            $db->beginTransaction();

            try {
                // Process each row in the CSV
                while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    // Skip rows that are completely empty (blank lines happen)
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    try {
                        // Marry the headers with the data so we get nice associative arrays
                        $row = array_combine($headers, $data);

                        // vendor_name is required -- can't assess a vendor with no name
                        if (empty($row['vendor_name'])) {
                            throw new Exception(t('fair_import-analysis.vendor_name_required'));
                        }

                        // security_score must be a letter grade (A through F, just like school)
                        if (empty($row['security_score']) || !in_array($row['security_score'], ['A', 'B', 'C', 'D', 'F'])) {
                            throw new Exception(t('fair_import-analysis.valid_score_required'));
                        }

                        // Time to encrypt all the sensitive fields. There are a LOT of them.
                        // Everything that could contain proprietary vendor info gets locked up.
                        $encryptedData = [];
                        $encryptedFields = [
                            'msa', 'scope_of_work', 'medium_of_data', 'certifications', 'compliance',
                            'security_governance', 'incident_response_plan', 'continuous_monitoring',
                            'supply_chain_risk_mgmt', 'security_awareness_training', 'vulnerability_management',
                            'patch_management', 'access_controls', 'data_encryption', 'network_security',
                            'daily_impact', 'vulnerability_data', 'configuration_data', 'compliance_data',
                            'risk_assessment', 'threat_intelligence', 'vendor_risk_assessment',
                            'security_questionnaire', 'compliance_questionnaire', 'data_classification',
                            'data_sharing', 'business_impact', 'vendor_performance', 'third_party_vendor_list',
                            'third_party_risk_assessment', 'third_party_security_questionnaire',
                            'third_party_compliance_questionnaire', 'vendor_cyber_insurance_coverage',
                            'cost_of_breach', 'cost_of_outage', 'sec_fines', 'compliance_fines',
                            'insurance_premiums'
                        ];

                        foreach ($encryptedFields as $field) {
                            $value = $row[$field] ?? '';
                            $encryptedData[$field] = !empty($value) ? $encryption->encrypt($value) : null;
                        }

                        // These fields stay in plain text -- they're either non-sensitive
                        // or we need them for queries/display without decrypting
                        $encryptedData['vendor_name'] = $row['vendor_name'];
                        $encryptedData['vendor_domain'] = $row['vendor_domain'] ?? null;
                        $encryptedData['security_score'] = $row['security_score'];
                        $encryptedData['iso_27001_certified'] = ($row['iso_27001_certified'] ?? '0') == '1' ? 1 : 0;
                        $encryptedData['securityscorecard_rating'] = !empty($row['securityscorecard_rating']) ? $row['securityscorecard_rating'] : null;
                        $encryptedData['pii_record_count'] = max(0, intval($row['pii_record_count'] ?? 0));
                        $encryptedData['spii_record_count'] = max(0, intval($row['spii_record_count'] ?? 0));
                        $encryptedData['sox_record_count'] = max(0, intval($row['sox_record_count'] ?? 0));
                        $encryptedData['user_id'] = $user['id'];
                        $encryptedData['status'] = 'completed'; // Imports come in fully baked

                        // Run the FAIR calculator on the plaintext data to get risk metrics.
                        // This is where the actual math happens -- loss event frequency,
                        // loss magnitude, annualized loss expectancy, the whole nine yards.
                        $fairResults = FairCalculator::calculate($row);

                        // Store the FAIR results (also encrypted, because paranoia is a feature)
                        $encryptedData['loss_event_frequency'] = $encryption->encrypt((string)$fairResults['loss_event_frequency']);
                        $encryptedData['loss_magnitude'] = $encryption->encrypt((string)$fairResults['loss_magnitude']);
                        $encryptedData['primary_loss_magnitude'] = $encryption->encrypt((string)$fairResults['primary_loss']);
                        $encryptedData['secondary_loss_magnitude'] = $encryption->encrypt((string)$fairResults['secondary_loss']);
                        $encryptedData['ale'] = $encryption->encrypt((string)$fairResults['annualized_loss_expectancy']);
                        $encryptedData['recommended_liability'] = $encryption->encrypt((string)$fairResults['recommended_liability']);
                        $encryptedData['risk_output'] = $fairResults['risk_level'];
                        $encryptedData['completed_at'] = date('Y-m-d H:i:s');

                        // Shove it into the database
                        $db->insert('tprm_results', $encryptedData);

                        $successCount++;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'vendor' => $row['vendor_name'],
                            'status' => 'success',
                            'message' => t('fair_import-analysis.imported_successfully')
                        ];

                    } catch (Exception $e) {
                        // This row didn't make it. Log the error but keep processing.
                        $errorCount++;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'vendor' => $row['vendor_name'] ?? 'Unknown',
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ];
                    }
                }

                // If we got this far, commit everything that worked
                $db->commit();

                $auth->audit($user['id'], 'fair_analysis_import', 'tprm_results', null, [
                    'new' => ['imported' => $successCount, 'errors' => $errorCount]
                ]);

                $success = "Import completed: {$successCount} records imported successfully";
                if ($errorCount > 0) {
                    $success .= ", {$errorCount} records failed";
                }

            } catch (Exception $e) {
                // Something went really wrong -- roll back the whole transaction
                $db->rollback();
                $error = 'Import failed: ' . $e->getMessage();
            }

            fclose($handle);
        } else {
            $error = t('fair_import-analysis.unable_to_read');
        }
    }
}

render:
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('fair_import-analysis.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
        }

        /* Nuke the navbar pseudo-element that no one asked for */
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
            background: #ffc211;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: #e6ae0f;
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
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #ff6543;
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
        <header class="section page-header">
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
                                        <?php echo e(t('fair_import-analysis.brand_title')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: #333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('fair_import-analysis.nav_dashboard')); ?></a>
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

        <div class="import-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <h1 class="page-title"><?php echo e(t('fair_import-analysis.heading')); ?></h1>
                    <a href="fair_results.php" class="btn-secondary">
                        ← <?php echo e(t('fair_import-analysis.back_to_results')); ?>
                    </a>
                </div>

                <!-- Success/Error messages from the import attempt -->
                <?php if ($success): ?>
                <div class="message message-success">
                    ✓ <?php echo e($success); ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="message message-error">
                    ✗ <?php echo e($error); ?>
                </div>
                <?php endif; ?>

        <!-- Instructions Card -- because nobody reads documentation, but we try anyway -->
                <div class="card">
                    <h4 class="card-title">
                        📖 <?php echo e(t('fair_import-analysis.how_to_title')); ?>
                    </h4>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                            <div>
                                <h5 style="font-size: 16px; margin-bottom: 10px;">📥 <?php echo e(t('fair_import-analysis.step1_title')); ?></h5>
                                <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('fair_import-analysis.step1_desc')); ?></p>

                                <a href="?download_template=1" class="btn-success" style="display: inline-block; margin-bottom: 20px;">
                                    📥 <?php echo e(t('fair_import-analysis.download_template')); ?>
                                </a>

                                <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;">✏️ <?php echo e(t('fair_import-analysis.step2_title')); ?></h5>
                                <ul style="color: #666; line-height: 1.8;">
                                    <li><?php echo e(t('fair_import-analysis.step2_open')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.step2_review')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.step2_delete')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.step2_add')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.step2_save')); ?></li>
                                </ul>
                            </div>

                            <div>
                                <h5 style="font-size: 16px; margin-bottom: 10px;">📋 <?php echo e(t('fair_import-analysis.required_fields_title')); ?></h5>
                                <ul style="color: #666; line-height: 1.8;">
                                    <li><strong>vendor_name</strong> - <?php echo e(t('fair_import-analysis.req_vendor_name')); ?></li>
                                    <li><strong>security_score</strong> - <?php echo e(t('fair_import-analysis.req_security_score')); ?></li>
                                </ul>

                                <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;">⚠️ <?php echo e(t('fair_import-analysis.notes_title')); ?></h5>
                                <ul style="color: #666; line-height: 1.8;">
                                    <li><?php echo e(t('fair_import-analysis.note_size')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.note_format')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.note_encrypted')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.note_iso')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.note_numeric')); ?></li>
                                    <li><?php echo e(t('fair_import-analysis.note_draft')); ?></li>
                                </ul>
                            </div>
                        </div>
                </div>

        <!-- Upload Form -- the moment of truth -->
                <div class="card">
                    <h4 class="card-title">
                        📤 <?php echo e(t('fair_import-analysis.upload_title')); ?>
                    </h4>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($security->getCSRFToken()); ?>">

                            <div style="margin-bottom: 20px;">
                                <label for="csv_file" style="display: block; margin-bottom: 8px; font-weight: 500;"><?php echo e(t('fair_import-analysis.select_file')); ?></label>
                                <input type="file" id="csv_file" name="csv_file"
                                       accept=".csv,text/csv" required
                                       style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 500px;">
                                <div style="margin-top: 5px; font-size: 14px; color: #666;"><?php echo e(t('fair_import-analysis.upload_hint')); ?></div>
                            </div>

                            <button type="submit" class="btn-primary" style="font-size: 16px;">
                                📤 <?php echo e(t('fair_import-analysis.btn_import')); ?>
                            </button>
                        </form>
                </div>

        <!-- Import Results -- the scoreboard showing which rows survived the import -->
        <?php if (!empty($importResults)): ?>
                <div class="card">
                    <h4 class="card-title">
                        📊 <?php echo e(t('fair_import-analysis.results_title')); ?>
                    </h4>

                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php echo e(t('fair_import-analysis.th_row')); ?></th>
                                        <th><?php echo e(t('fair_import-analysis.th_vendor_name')); ?></th>
                                        <th><?php echo e(t('fair_import-analysis.th_status')); ?></th>
                                        <th><?php echo e(t('fair_import-analysis.th_message')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importResults as $result): ?>
                                    <tr class="<?php echo $result['status'] === 'success' ? 'table-success' : 'table-danger'; ?>">
                                        <td><?php echo e($result['row']); ?></td>
                                        <td><?php echo e($result['vendor']); ?></td>
                                        <td>
                                            <?php if ($result['status'] === 'success'): ?>
                                                <span class="badge bg-success"><?php echo e(t('fair_import-analysis.badge_success')); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><?php echo e(t('fair_import-analysis.badge_error')); ?></span>
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
    <script src="app/js/mobile-nav.js"></script>
    <script src="app/js/event-handlers.js?v=2" nonce="<?php echo cspNonce(); ?>"></script>
</body>
</html>
