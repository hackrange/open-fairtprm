<?php
/**
 * Admin User CSV Import Tool
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "I have 200 new employees and I'm not creating accounts one by one"
 * tool. Upload a CSV, it validates everything, creates the user accounts,
 * assigns groups, and even generates random passwords for the lazy ones
 * who left the password column blank. It's like onboarding day, but automated
 * and with fewer awkward icebreaker games.
 *
 * Supports flexible CSV headers (it'll figure out what you meant by "Name"
 * vs "full_name" vs "displayname"), does the whole thing in a transaction
 * so it's all-or-nothing, and logs the import to the audit trail because
 * compliance loves a paper trail.
 */

require_once 'includes/init.php';

// Only admins get to play with the bulk import tool.
// Giving regular users the ability to create accounts would be... chaotic.
requireAdmin();

// Grab our authenticated user info and service instances
$auth = Auth::getInstance();
$user = $auth->getUser();
$db = Database::getInstance();
$security = Security::getInstance();

// Get the user's theme preferences so the page looks pretty
$theme = getUserTheme($user['id']);

// Status message holders -- we'll fill these in if something happens
$success = '';
$error = '';
$importResults = [];

/**
 * Generate a random password that'll make the security team happy.
 * 16 characters of mixed case, numbers, and special chars.
 * Good luck memorizing it -- that's what password managers are for.
 */
function generateRandomPassword($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $charLength = strlen($chars) - 1;
    // Using random_int() instead of rand() because we're security-conscious
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charLength)];
    }
    return $password;
}

// Pre-load all active ACL groups so we can map group names from the CSV.
// Build a lookup table keyed by lowercase name for case-insensitive matching.
$allGroups = $db->fetchAll('SELECT id, group_name, display_name FROM acl_groups WHERE is_active = 1 ORDER BY group_name');
$groupsByName = [];
foreach ($allGroups as $g) {
    $groupsByName[strtolower($g['group_name'])] = $g;
}

// ============================================================================
// HANDLE CSV TEMPLATE DOWNLOAD
// When someone clicks "Download Template", we generate a CSV with headers
// and sample data so they know what format we expect. It's like giving them
// the answer key but for spreadsheets.
// ============================================================================
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=user_import_template.csv');

    $output = fopen('php://output', 'w');

    // CSV column headers
    $headers = [
        'username',
        'full_name',
        'email',
        'password',
        'groups',
        'status'
    ];

    fputcsv($output, $headers);

    // Throw in some sample rows so people get the idea
    $sampleData = [
        [
            'jsmith',                              // username
            'John Smith',                          // full_name
            'jsmith@example.com',                  // email
            'SecurePass123!',                      // password (or leave empty for random)
            'stakeholder,procurement',             // groups (comma-separated group names)
            'active'                               // status
        ],
        [
            'jane.doe',                            // username
            'Jane Doe',                            // full_name
            'jane.doe@example.com',                // email
            '',                                    // password (empty = random 16-char)
            'cyber_tprm',                          // groups
            'active'                               // status
        ]
    ];

    foreach ($sampleData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

// ============================================================================
// HANDLE CSV IMPORT (POST request with file upload)
// This is where the real magic happens. We parse the CSV, validate every row,
// create user accounts, assign groups, and track what worked and what didn't.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // CSRF check -- because we're not about to let someone trick an admin
    // into importing a bunch of rogue accounts via a sneaky form submission
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = t('admin-users-import.invalid_security_token');
        goto render;
    }

    $file = $_FILES['csv_file'];

    // ---- FILE VALIDATION ----
    // Make sure the upload didn't blow up, isn't too big, and is actually a CSV
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = t('admin-users-import.file_upload_error');
    } elseif ($file['size'] > 10485760) { // 10MB limit -- that's a LOT of users
        $error = t('admin-users-import.file_too_large');
    } elseif (!in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt'])) {
        $error = t('admin-users-import.invalid_file_type');
    } else {
        // Open the CSV file for reading
        $handle = fopen($file['tmp_name'], 'r');

        if ($handle !== false) {
            // ---- PARSE HEADERS ----
            // Read the first row as column headers and normalize them.
            // People use all kinds of names for the same thing ("Name", "Full Name",
            // "display_name", "FULLNAME"...) so we lowercase and clean them up.
            $rawHeaders = fgetcsv($handle, 0, ',', '"', '\\');

            $headers = [];
            $headerMapping = [];
            foreach ($rawHeaders as $idx => $h) {
                // Strip everything that isn't a letter or number, collapse underscores
                $normalized = strtolower(trim($h));
                $normalized = preg_replace('/[^a-z0-9]/', '_', $normalized);
                $normalized = preg_replace('/_+/', '_', $normalized);
                $normalized = trim($normalized, '_');
                $headers[$idx] = $normalized;
                $headerMapping[$normalized] = $idx;
            }

            // ---- FLEXIBLE FIELD MAPPING ----
            // Map a bunch of common column name variations to our expected field names.
            // Because everyone names their CSV columns differently and we're not gonna
            // make them rename them just because we're picky.
            $fieldAliases = [
                'username' => ['username', 'user_name', 'user', 'login', 'userid', 'user_id'],
                'full_name' => ['full_name', 'fullname', 'name', 'display_name', 'displayname'],
                'email' => ['email', 'email_address', 'emailaddress', 'e_mail'],
                'password' => ['password', 'pass', 'pwd'],
                'groups' => ['groups', 'group', 'roles', 'role', 'acl_groups'],
                'status' => ['status', 'active', 'is_active', 'enabled', 'state']
            ];

            // Build the final column index mapping by checking each alias
            $columnMap = [];
            foreach ($fieldAliases as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if (isset($headerMapping[$alias])) {
                        $columnMap[$field] = $headerMapping[$alias];
                        break;
                    }
                }
            }

            // ---- PROCESS ROWS ----
            $rowNumber = 1;
            $successCount = 0;
            $errorCount = 0;
            $importedUserIds = []; // Track imported user IDs for audit/revert

            // What counts as a valid status value
            $validStatuses = ['active', 'inactive', '1', '0', 'yes', 'no', ''];

            // Wrap everything in a transaction -- if something goes wrong mid-import,
            // we can roll it all back instead of leaving half-created users everywhere
            $db->beginTransaction();

            try {
                while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    // Skip blank rows -- Excel loves to add these at the end
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    try {
                        // Helper closure to grab field values by our mapped column names
                        $getField = function($field) use ($columnMap, $data) {
                            if (!isset($columnMap[$field])) {
                                return '';
                            }
                            return isset($data[$columnMap[$field]]) ? $data[$columnMap[$field]] : '';
                        };

                        // ---- VALIDATE REQUIRED FIELDS ----
                        // Username, full name, and email are non-negotiable
                        $usernameRaw = $getField('username');
                        $fullNameRaw = $getField('full_name');
                        $emailRaw = $getField('email');

                        if (empty(trim($usernameRaw))) {
                            throw new Exception('Username is required (column not found or empty). Available columns: ' . implode(', ', $rawHeaders));
                        }
                        if (empty(trim($fullNameRaw))) {
                            throw new Exception('Full name is required (column not found or empty)');
                        }
                        if (empty(trim($emailRaw))) {
                            throw new Exception('Email is required (column not found or empty)');
                        }

                        $username = trim($usernameRaw);
                        $fullName = trim($fullNameRaw);
                        $email = trim($emailRaw);

                        // Username format check -- letters, numbers, dots, underscores, hyphens, and @
                        // The @ is for email-style usernames which some SAML setups use
                        if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $username)) {
                            throw new Exception('Invalid username format. Use only letters, numbers, dots, underscores, hyphens, and @.');
                        }

                        // Email format check -- PHP's built-in validator handles the heavy lifting
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('Invalid email format');
                        }

                        // ---- UNIQUENESS CHECKS ----
                        // Can't have two users with the same username or email.
                        // That would be... confusing.
                        $existingUser = $db->fetchOne(
                            'SELECT id FROM users WHERE username = :username',
                            [':username' => $username]
                        );
                        if ($existingUser) {
                            throw new Exception('Username already exists: ' . $username);
                        }

                        $existingEmail = $db->fetchOne(
                            'SELECT id FROM users WHERE email = :email',
                            [':email' => $email]
                        );
                        if ($existingEmail) {
                            throw new Exception('Email already exists: ' . $email);
                        }

                        // ---- PASSWORD HANDLING ----
                        // If they left the password blank, we generate a random one.
                        // The generated password shows up in the results so the admin
                        // can communicate it to the user. Yes, this is slightly terrifying.
                        $password = trim($getField('password'));
                        $generatedPassword = false;
                        if (empty($password)) {
                            $password = generateRandomPassword(16);
                            $generatedPassword = true;
                        } elseif (strlen($password) < 8) {
                            throw new Exception('Password must be at least 8 characters');
                        }

                        // Hash the password because storing plaintext passwords is a crime against humanity
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        // ---- STATUS HANDLING ----
                        // Default to active because most imports are for new hires, not departures
                        $statusValue = strtolower(trim($getField('status')));
                        if (empty($statusValue)) {
                            $statusValue = 'active';
                        }
                        $isActive = 1; // Default to active
                        if (in_array($statusValue, ['inactive', '0', 'no', 'false', 'disabled'])) {
                            $isActive = 0;
                        }

                        // ---- GROUP ASSIGNMENT ----
                        // Parse the comma-separated group names and look them up.
                        // Valid groups get assigned, invalid ones get mentioned in the results.
                        $groupNames = array_filter(array_map('trim', explode(',', $getField('groups'))));
                        $validGroups = [];
                        $invalidGroups = [];

                        foreach ($groupNames as $gn) {
                            $gnLower = strtolower($gn);
                            if (isset($groupsByName[$gnLower])) {
                                $validGroups[] = $groupsByName[$gnLower];
                            } else {
                                $invalidGroups[] = $gn;
                            }
                        }

                        // ---- CREATE THE USER ----
                        // Insert into the users table. Not an admin, not a super admin --
                        // those privileges are granted separately for obvious reasons.
                        $newUserId = $db->insert('users', [
                            'username' => $username,
                            'password_hash' => $passwordHash,
                            'email' => $email,
                            'full_name' => $fullName,
                            'is_active' => $isActive,
                            'is_admin' => 0,
                            'is_super_admin' => 0
                        ]);

                        // Assign the user to their groups
                        $assignedGroups = [];
                        foreach ($validGroups as $group) {
                            try {
                                $db->insert('user_acl_groups', [
                                    'user_id' => $newUserId,
                                    'group_id' => $group['id'],
                                    'assigned_by' => $user['id']
                                ]);
                                $assignedGroups[] = $group['group_name'];
                            } catch (Exception $e) {
                                // Group assignment failed -- maybe a duplicate. Move on.
                            }
                        }

                        // ---- BUILD RESULT MESSAGE ----
                        // Tell the admin what happened for this row
                        $message = 'User created successfully';
                        if ($generatedPassword) {
                            $message .= ' (Password: ' . $password . ')';
                        }
                        if (!empty($assignedGroups)) {
                            $message .= ' [Groups: ' . implode(', ', $assignedGroups) . ']';
                        }
                        if (!empty($invalidGroups)) {
                            $message .= ' [Invalid groups ignored: ' . implode(', ', $invalidGroups) . ']';
                        }

                        $successCount++;
                        $importedUserIds[] = $newUserId;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'username' => $username,
                            'email' => $email,
                            'status' => 'success',
                            'message' => $message
                        ];

                    } catch (Exception $e) {
                        // This row failed but we keep going -- no reason to abort
                        // the whole import because one row had a typo
                        $errorCount++;
                        $importResults[] = [
                            'row' => $rowNumber,
                            'username' => $username ?? ($usernameRaw ?? 'Unknown'),
                            'email' => $email ?? ($emailRaw ?? ''),
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ];
                    }
                }

                // If we made it here without a fatal exception, commit everything
                $db->commit();

                // ---- AUDIT LOGGING ----
                // Record the import in the activity log so there's a paper trail.
                // Compliance loves paper trails almost as much as they love spreadsheets.
                if ($successCount > 0 && !empty($importedUserIds)) {
                    try {
                        $importedUsernames = [];
                        foreach ($importResults as $result) {
                            if ($result['status'] === 'success') {
                                $importedUsernames[] = $result['username'];
                            }
                        }

                        $metadata = json_encode([
                            'user_ids' => $importedUserIds,
                            'usernames' => $importedUsernames,
                            'file_name' => $file['name'],
                            'total_rows' => $rowNumber - 1,
                            'success_count' => $successCount,
                            'error_count' => $errorCount
                        ]);

                        $description = "Imported {$successCount} users from CSV file: {$file['name']}";
                        if ($errorCount > 0) {
                            $description .= " ({$errorCount} rows failed)";
                        }
                        // Show first 20 usernames in the log, truncate if there are more
                        $description .= "\n\nImported users: " . implode(', ', array_slice($importedUsernames, 0, 20));
                        if (count($importedUsernames) > 20) {
                            $description .= " and " . (count($importedUsernames) - 20) . " more...";
                        }

                        $db->query(
                            "INSERT INTO cyber_todo_activities (todo_type, reference_type, reference_id, activity_type, title, description, metadata, status, created_by)
                             VALUES ('user_import', 'users', 0, 'import', :title, :description, :metadata, 'closed', :created_by)",
                            [
                                ':title' => "User Import: {$successCount} users",
                                ':description' => $description,
                                ':metadata' => $metadata,
                                ':created_by' => $user['id']
                            ]
                        );

                        $auth->audit($user['id'], 'case_create', 'cyber_todo_activities', null, [
                            'new' => [
                                'todo_type' => 'user_import',
                                'title' => "User Import: {$successCount} users",
                                'user_count' => $successCount,
                                'error_count' => $errorCount,
                            ]
                        ]);
                    } catch (Exception $e) {
                        // If audit logging fails, the import still succeeded. Not ideal but not fatal.
                        error_log('Failed to log user import activity: ' . $e->getMessage());
                    }
                }

                $success = "Successfully imported {$successCount} user" . ($successCount !== 1 ? 's' : '');
                if ($errorCount > 0) {
                    $success .= " ({$errorCount} failed)";
                }

            } catch (Exception $e) {
                // Something went really wrong -- roll back all the user creations
                $db->rollback();
                $error = 'Import failed: ' . $e->getMessage();
            }

            fclose($handle);
        } else {
            $error = t('admin-users-import.unable_to_read');
        }
    }
}

render:
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('admin-users-import.page_title')); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/style.css">
    <style>
        /* Theme CSS variables pulled from the user's saved preferences */
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
        .message-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
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
        .group-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        .group-chip {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 13px;
        }
    </style>
</head>
<body style="background: #fff;">
    <?php renderImpersonationBanner(); ?>
    <div class="page" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
        <!-- Page Header / Navigation -->
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
                                        <?php echo e(t('admin-users-import.import_users')); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex: 1;"></div>
                            <div class="user-menu">
                                <span style="color: #333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
                                <a href="index.php"><?php echo e(t('admin-users-import.dashboard')); ?></a>
                                <a href="admin.php"><?php echo e(t('chrome.admin')); ?></a>
                                <a href="admin.php?section=users"><?php echo e(t('admin-users-import.user_management')); ?></a>
                                <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Main Content Area -->
        <div class="import-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <h1 class="page-title"><?php echo e(t('admin-users-import.bulk_import_users')); ?></h1>
                    <a href="admin.php?section=users" class="btn-secondary">
                        &larr; <?php echo e(t('admin-users-import.back_to_user_mgmt')); ?>
                    </a>
                </div>

                <!-- Success/Error Messages -->
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

                <!-- Security heads-up about generated passwords being visible -->
                <div class="message message-warning">
                    <strong><?php echo e(t('admin-users-import.security_notice_label')); ?></strong> <?php echo e(t('admin-users-import.security_notice_text')); ?>
                </div>

                <!-- Instructions Card -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('admin-users-import.how_to_import')); ?>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('admin-users-import.step1')); ?></h5>
                            <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('admin-users-import.step1_desc')); ?></p>

                            <a href="?download_template=1" class="btn-success" style="display: inline-block; margin-bottom: 20px;">
                                <?php echo e(t('admin-users-import.download_template')); ?>
                            </a>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('admin-users-import.step2')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><?php echo e(t('admin-users-import.step2_open')); ?></li>
                                <li><?php echo e(t('admin-users-import.step2_review')); ?></li>
                                <li><?php echo e(t('admin-users-import.step2_delete')); ?></li>
                                <li><?php echo e(t('admin-users-import.step2_add')); ?></li>
                                <li><?php echo e(t('admin-users-import.step2_save')); ?></li>
                            </ul>
                        </div>

                        <div>
                            <h5 style="font-size: 16px; margin-bottom: 10px;"><?php echo e(t('admin-users-import.required_fields')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><strong>username</strong> - Unique login name (letters, numbers, dots, underscores, hyphens)</li>
                                <li><strong>full_name</strong> - User's display name</li>
                                <li><strong>email</strong> - Unique email address</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('admin-users-import.optional_fields')); ?></h5>
                            <ul style="color: #666; line-height: 1.8;">
                                <li><strong>password</strong> - Minimum 8 characters. If empty, a random 16-character password will be generated.</li>
                                <li><strong>groups</strong> - Comma-separated group names (e.g., <code>stakeholder,procurement</code>)</li>
                                <li><strong>status</strong> - <code>active</code> or <code>inactive</code> (default: active)</li>
                            </ul>

                            <h5 style="font-size: 16px; margin-bottom: 10px; margin-top: 25px;"><?php echo e(t('admin-users-import.available_groups')); ?></h5>
                            <div class="group-list">
                                <?php foreach ($allGroups as $g): ?>
                                <span class="group-chip"><?php echo e($g['group_name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Form Card -->
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('admin-users-import.upload_csv_file')); ?>
                    </h4>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo e($security->getCSRFToken()); ?>">

                        <div style="margin-bottom: 20px;">
                            <label for="csv_file" style="display: block; margin-bottom: 8px; font-weight: 500;"><?php echo e(t('admin-users-import.select_csv_file')); ?></label>
                            <input type="file" id="csv_file" name="csv_file"
                                   accept=".csv,text/csv" required
                                   style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 500px;">
                            <div style="margin-top: 5px; font-size: 14px; color: #666;"><?php echo e(t('admin-users-import.upload_help')); ?></div>
                        </div>

                        <button type="submit" class="btn-primary" style="font-size: 16px;">
                            <?php echo e(t('admin-users-import.import_users_btn')); ?>
                        </button>
                    </form>
                </div>

                <!-- Import Results Table (only shows after an import attempt) -->
                <?php if (!empty($importResults)): ?>
                <div class="card">
                    <h4 class="card-title">
                        <?php echo e(t('admin-users-import.import_results')); ?>
                    </h4>

                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo e(t('admin-users-import.col_row')); ?></th>
                                    <th><?php echo e(t('admin-users-import.col_username')); ?></th>
                                    <th><?php echo e(t('admin-users-import.col_email')); ?></th>
                                    <th><?php echo e(t('admin-users-import.col_status')); ?></th>
                                    <th><?php echo e(t('admin-users-import.col_message')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($importResults as $result): ?>
                                <tr class="<?php echo $result['status'] === 'success' ? 'table-success' : 'table-danger'; ?>">
                                    <td><?php echo e($result['row']); ?></td>
                                    <td><strong><?php echo e($result['username']); ?></strong></td>
                                    <td><?php echo e($result['email']); ?></td>
                                    <td>
                                        <?php if ($result['status'] === 'success'): ?>
                                            <span class="badge bg-success"><?php echo e(t('admin-users-import.badge_success')); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo e(t('admin-users-import.badge_error')); ?></span>
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
