<?php
/**
 * SRS Tier CSV Import - Bulk-Update Vendor Tiers Without Losing Your Sanity
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Got 200 vendors that need tier assignments and you don't feel like clicking
 * through each one individually? This page is your new best friend. Upload a CSV
 * with vendor IDs (or names, or domains -- we're flexible) and their desired tier
 * levels, and we'll match them up and update them in bulk inside a database
 * transaction. If anything goes sideways, the whole thing rolls back so you
 * won't end up with half-updated data and a splitting headache. Also includes a
 * handy template download so people stop asking "what format does the CSV need?"
 */

// Boot up and authenticate
require_once 'includes/init.php';
requireAuth();

// The usual suspects -- grab our service singletons
$auth = Auth::getInstance();
$user = $auth->getUser();
$db = Database::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();

// Only admins and cyber_tprm users can import -- we don't want just anyone
// bulk-modifying vendor data. That's a recipe for a "we need to talk" meeting.
$canImport = $acl->hasGroup('administrator') || $acl->hasGroup('cyber_tprm');

if (!$canImport) {
    http_response_code(403);
    die(e(t('vendor-srs-import.access_denied')));
}

// Grab the theme so the page looks consistent with the rest of the app
$theme = getUserTheme($user['id']);

$success = '';
$error = '';
$importResults = [];

// If someone hits ?download_template=1, spit out a sample CSV file so they
// know exactly what columns we expect. Three sample rows, headers at the top.
// Basically the "here, let me draw you a picture" approach to documentation.
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vendor_srs_import_template.csv');

    $output = fopen('php://output', 'w');

    // CSV Headers
    $headers = [
        'id',
        'vendor_name',
        'vendor_domain',
        'vendor_tier'
    ];

    fputcsv($output, $headers);

    // Sample data rows
    $sampleData = [
        ['1', 'Acme Cloud Services', 'acmecloud.com', '1'],
        ['', 'Example Corp', 'example.com', '2'],
        ['', 'Test Vendor Inc', 'testvendor.io', '3']
    ];

    foreach ($sampleData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

// The main import logic -- this fires when someone uploads a CSV file.
// We validate the file, parse each row, try to match it to an existing vendor
// (by ID, name, or domain -- in that order of preference), and update the tier.
// Everything runs inside a transaction because we're not animals.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // First things first: CSRF check to prevent shenanigans
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('vendor-srs-import.err_invalid_request');
    } else {
        $file = $_FILES['csv_file'];

        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = t('vendor-srs-import.err_upload');
        } elseif ($file['size'] > 10485760) { // 10MB limit
            $error = t('vendor-srs-import.err_too_large');
        } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt'])) {
            $error = t('vendor-srs-import.err_invalid_type');
        } else {
            // Alright, the file checks out -- let's crack it open and see what we've got
            $handle = fopen($file['tmp_name'], 'r');

            if ($handle !== false) {
                // Read header row
                $headers = fgetcsv($handle, 0, ',', '"', '\\');

                // Normalize headers to lowercase
                $headers = array_map('strtolower', array_map('trim', $headers));

                $rowNumber = 1;
                $successCount = 0;
                $errorCount = 0;

                // Tier values: 1, 2, 3, or empty (to clear the tier). That's it.
                // No tier 4, no tier "potato", just 1-2-3 or nothing.
                $validTiers = ['1', '2', '3', ''];

                // Wrap everything in a transaction -- if row 87 out of 200 blows up,
                // we don't want the first 86 to be committed with the rest abandoned
                $db->beginTransaction();

                try {
                    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                        $rowNumber++;

                        // Skip empty rows
                        if (empty(array_filter($data))) {
                            continue;
                        }

                        try {
                            // Combine headers with data
                            if (count($headers) !== count($data)) {
                                throw new Exception('Column count mismatch. Expected ' . count($headers) . ' columns, got ' . count($data));
                            }
                            $row = array_combine($headers, $data);

                            // Get values
                            $id = !empty($row['id']) ? intval($row['id']) : null;
                            $vendorName = trim($row['vendor_name'] ?? '');
                            $vendorDomain = trim($row['vendor_domain'] ?? '');
                            $vendorTier = trim($row['vendor_tier'] ?? '');

                            // Validate tier
                            if (!empty($vendorTier) && !in_array($vendorTier, $validTiers)) {
                                throw new Exception("Invalid tier value: '{$vendorTier}'. Must be 1, 2, 3, or empty.");
                            }

                            // Find existing vendor
                            $existingVendor = null;

                            if ($id) {
                                // Try to find by ID first
                                $existingVendor = $db->fetchOne(
                                    'SELECT id, vendor_name, vendor_domain, vendor_tier, last_srs_score_at FROM vendor_onboarding_requests WHERE id = :id',
                                    [':id' => $id]
                                );
                            }

                            if (!$existingVendor && !empty($vendorName)) {
                                // Try to find by exact vendor name match
                                $existingVendor = $db->fetchOne(
                                    'SELECT id, vendor_name, vendor_domain, vendor_tier, last_srs_score_at FROM vendor_onboarding_requests WHERE vendor_name = :name',
                                    [':name' => $vendorName]
                                );
                            }

                            if (!$existingVendor && !empty($vendorDomain)) {
                                // Try to find by domain
                                $existingVendor = $db->fetchOne(
                                    'SELECT id, vendor_name, vendor_domain, vendor_tier, last_srs_score_at FROM vendor_onboarding_requests WHERE vendor_domain = :domain',
                                    [':domain' => $vendorDomain]
                                );
                            }

                            if (!$existingVendor) {
                                throw new Exception("Vendor not found. Provide a valid ID, vendor name, or domain.");
                            }

                            // Build update data
                            $updateData = [];

                            // Update domain if provided and different
                            if (!empty($vendorDomain) && $vendorDomain !== $existingVendor['vendor_domain']) {
                                $updateData['vendor_domain'] = $vendorDomain;
                            }

                            // Update tier
                            if ($vendorTier !== '' && $vendorTier !== $existingVendor['vendor_tier']) {
                                $updateData['vendor_tier'] = $vendorTier ?: null;
                            } elseif ($vendorTier === '' && !empty($existingVendor['vendor_tier'])) {
                                // Clear tier if empty string provided
                                $updateData['vendor_tier'] = null;
                            }

                            // Stagger SRS assessment dates to prevent thundering herd.
                            // When assigning a tier to a vendor that has never been scored,
                            // set last_srs_score_at to a random date within the tier interval
                            // so vendors become due for rescoring at naturally staggered times.
                            if (!empty($vendorTier) && empty($existingVendor['last_srs_score_at'])) {
                                $tierDays = match($vendorTier) {
                                    '1' => 30, '2' => 90, '3' => 365, default => 90
                                };
                                $randomDaysAgo = rand(1, $tierDays);
                                $updateData['last_srs_score_at'] = date('Y-m-d H:i:s', strtotime("-{$randomDaysAgo} days"));
                            }

                            if (empty($updateData)) {
                                $importResults[] = [
                                    'row' => $rowNumber,
                                    'vendor' => $existingVendor['vendor_name'],
                                    'status' => 'skipped',
                                    'message' => 'No changes needed'
                                ];
                                continue;
                            }

                            // Update the vendor
                            $db->update(
                                'vendor_onboarding_requests',
                                $updateData,
                                'id = :id',
                                [':id' => $existingVendor['id']]
                            );

                            $successCount++;
                            $changes = [];
                            if (isset($updateData['vendor_domain'])) {
                                $changes[] = "domain set to '{$updateData['vendor_domain']}'";
                            }
                            if (array_key_exists('vendor_tier', $updateData)) {
                                $tierLabel = $updateData['vendor_tier'] ? "Tier {$updateData['vendor_tier']}" : "unassigned";
                                $changes[] = "tier set to {$tierLabel}";
                            }

                            $importResults[] = [
                                'row' => $rowNumber,
                                'vendor' => $existingVendor['vendor_name'],
                                'status' => 'success',
                                'message' => 'Updated: ' . implode(', ', $changes)
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

                    $db->commit();

                    $auth->audit($user['id'], 'vendor_srs_import', 'vendor_onboarding_requests', null, [
                        'new' => ['updated' => $successCount, 'errors' => $errorCount]
                    ]);

                    $success = "Import completed: {$successCount} records updated successfully";
                    if ($errorCount > 0) {
                        $success .= ", {$errorCount} records failed";
                    }

                } catch (Exception $e) {
                    $db->rollback();
                    $error = 'Import failed: ' . $e->getMessage();
                }

                fclose($handle);
            } else {
                $error = t('vendor-srs-import.err_unreadable');
            }
        }
    }
}

$csrfToken = $security->generateCSRFToken();
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('vendor-srs-import.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        :root {
            --theme-header-color: <?php echo e($theme['header_color']); ?>;
            --theme-footer-color: <?php echo e($theme['footer_color']); ?>;
            --theme-button-color: <?php echo e($theme['button_color']); ?>;
        }

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
            background: #f9fafb;
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
            background: #059669;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            border: none;
        }
        .btn-success:hover {
            background: #047857;
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
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
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
            background-color: #d1fae5;
        }
        .table-danger {
            background-color: #fee2e2;
        }
        .table-warning {
            background-color: #fef3c7;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        .bg-success {
            background-color: #059669;
            color: white;
        }
        .bg-danger {
            background-color: #dc2626;
            color: white;
        }
        .bg-warning {
            background-color: #d97706;
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
        .tier-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .tier-1 { background: #fef2f2; color: #991b1b; }
        .tier-2 { background: #fef3c7; color: #92400e; }
        .tier-3 { background: #ecfdf5; color: #065f46; }
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
                                        <?php echo e(t('vendor-srs-import.brand_subtitle')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: #333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('vendor-srs-import.dashboard')); ?></a>
                                <?php if ($auth->isAdmin()): ?>
                                    <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                                <?php endif; ?>
                                <a href="vendor-srs-list.php"><?php echo e(t('vendor-srs-import.srs_list')); ?></a>
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
                    <h1 class="page-title"><?php echo e(t('vendor-srs-import.heading')); ?></h1>
                    <a href="vendor-srs-list.php" class="btn-secondary">
                        &larr; <?php echo e(t('vendor-srs-import.back_to_list')); ?>
                    </a>
                </div>

                <?php if ($success): ?>
                <div class="message message-success">
                    <?php echo e($success); ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="message message-error">
                    <?php echo e($error); ?>
                </div>
                <?php endif; ?>

                <!-- Instructions Card -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-srs-import.how_to')); ?>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('vendor-srs-import.step1')); ?></h5>
                            <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('vendor-srs-import.step1_desc')); ?></p>

                            <a href="?download_template=1" class="btn-success" style="display: inline-block; margin-bottom: 20px;">
                                <?php echo e(t('vendor-srs-import.download_template')); ?>
                            </a>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-srs-import.step2')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><?php echo e(t('vendor-srs-import.step2_open')); ?></li>
                                <li>For each vendor you want to update, provide either:
                                    <ul>
                                        <li>The <strong>id</strong> from an export</li>
                                        <li>Or the exact <strong>vendor_name</strong></li>
                                        <li>Or the <strong>vendor_domain</strong></li>
                                    </ul>
                                </li>
                                <li>Set the <strong>vendor_tier</strong> (1, 2, or 3)</li>
                                <li><?php echo e(t('vendor-srs-import.step2_save')); ?></li>
                            </ul>
                        </div>

                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('vendor-srs-import.csv_columns')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><strong>id</strong> - Vendor onboarding ID (for matching)</li>
                                <li><strong>vendor_name</strong> - Exact vendor name (for matching)</li>
                                <li><strong>vendor_domain</strong> - Vendor domain (for matching or to update)</li>
                                <li><strong>vendor_tier</strong> - Tier assignment (1, 2, 3, or empty)</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-srs-import.tier_definitions')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><span class="tier-badge tier-1">Tier 1</span> - Critical vendors (scored frequently)</li>
                                <li><span class="tier-badge tier-2">Tier 2</span> - Important vendors (scored quarterly)</li>
                                <li><span class="tier-badge tier-3">Tier 3</span> - Standard vendors (scored annually)</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('vendor-srs-import.important_notes')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><?php echo e(t('vendor-srs-import.note_existing_only')); ?></li>
                                <li><?php echo e(t('vendor-srs-import.note_must_exist')); ?></li>
                                <li><?php echo e(t('vendor-srs-import.note_export_first')); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Upload Form -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-srs-import.upload_csv')); ?>
                    </h4>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                        <div style="margin-bottom: 20px;">
                            <label for="csv_file" style="display: block; margin-bottom: 8px; font-weight: 500;"><?php echo e(t('vendor-srs-import.select_csv')); ?></label>
                            <input type="file" id="csv_file" name="csv_file"
                                   accept=".csv,text/csv" required
                                   style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 500px;">
                            <div style="margin-top: 5px; font-size: 14px; color: #666;"><?php echo e(t('vendor-srs-import.upload_hint')); ?></div>
                        </div>

                        <button type="submit" class="btn-primary" style="font-size: 16px;">
                            <?php echo e(t('vendor-srs-import.import_button')); ?>
                        </button>
                    </form>
                </div>

                <!-- Import Results -->
                <?php if (!empty($importResults)): ?>
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('vendor-srs-import.import_results')); ?>
                    </h4>

                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo e(t('vendor-srs-import.col_row')); ?></th>
                                    <th><?php echo e(t('vendor-srs-import.col_vendor_name')); ?></th>
                                    <th><?php echo e(t('vendor-srs-import.col_status')); ?></th>
                                    <th><?php echo e(t('vendor-srs-import.col_message')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($importResults as $result): ?>
                                <tr class="<?php echo $result['status'] === 'success' ? 'table-success' : ($result['status'] === 'skipped' ? 'table-warning' : 'table-danger'); ?>">
                                    <td><?php echo e($result['row']); ?></td>
                                    <td><?php echo e($result['vendor']); ?></td>
                                    <td>
                                        <?php if ($result['status'] === 'success'): ?>
                                            <span class="badge bg-success"><?php echo e(t('vendor-srs-import.status_updated')); ?></span>
                                        <?php elseif ($result['status'] === 'skipped'): ?>
                                            <span class="badge bg-warning"><?php echo e(t('vendor-srs-import.status_skipped')); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo e(t('vendor-srs-import.status_error')); ?></span>
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

        <footer class="section footer-modern bg-gray-13">
            <div class="footer-modern-body section-lg">
                <div class="container">
                    <div class="row">
                        <div class="col-md-12 text-center">
                            <p class="rights">
                                <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                                <span class="copyright-year"><?php echo date('Y'); ?></span>
                                <span>.&nbsp;</span>
                                <span><?php echo e(t('vendor-srs-import.all_rights_reserved')); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    <script src="app/js/core.min.js"></script>
    <script src="app/js/script.js"></script>
</body>
</html>
