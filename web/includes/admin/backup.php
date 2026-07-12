<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Database Backup & Restore
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The "oh crap" insurance policy page. Create full database dumps, download
 * them, delete old ones, and restore from backups when things go sideways.
 * Also supports upload-and-restore for when you've got a backup sitting on
 * your laptop and need to push it back up.
 *
 * Uses the BackupService class for all dump/restore operations -- pure PHP/PDO,
 * no mysqldump binary required. Docker containers rejoice.
 *
 * Safety features: auto-creates a pre-restore backup before any restore
 * operation (because restoring on top of your only good copy is how you
 * earn a Wikipedia entry about famous data losses), validates filenames
 * against directory traversal attacks, and restricts web access to the
 * backup directory via .htaccess.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// Flash messages from post-restore redirects
// ============================================================================
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (!empty($_SESSION['flash_needs_key'])) {
    unset($_SESSION['flash_needs_key']);
}

// ============================================================================
// POST Handlers: create_backup, delete_backup, restore_backup, upload_restore_backup
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_backup.invalid_request');
    } elseif (isset($_POST['create_backup'])) {
        try {
            $dbConfig = $config->get('database');
            $backupDir = dirname(dirname(__DIR__)) . '/db_backup';

            // Create backup directory if it doesn't exist
            if (!is_dir($backupDir)) {
                if (!mkdir($backupDir, 0700, true)) {
                    throw new Exception('Failed to create backup directory');
                }
                // Write .htaccess to deny web access
                file_put_contents($backupDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
                // Write index.html as extra protection
                file_put_contents($backupDir . '/index.html', '');
            }

            $timestamp = date('Y-m-d_His');
            $filename = 'tprm_database_backup_' . $timestamp . '.sql';
            $filePath = $backupDir . '/' . $filename;

            $backupService = new BackupService($config->get('database'));
            $backupService->dump($filePath);

            // Verify the backup file was created and has content
            if (!file_exists($filePath) || filesize($filePath) === 0) {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                throw new Exception('Backup file was empty or not created');
            }

            // Set secure permissions on the backup file
            chmod($filePath, 0600);

            $fileSize = filesize($filePath);
            $formattedSize = $fileSize > 1048576
                ? round($fileSize / 1048576, 2) . ' MB'
                : round($fileSize / 1024, 2) . ' KB';

            $auth->audit($user['id'], 'backup_create', 'database', null, [
                'new' => ['filename' => $filename, 'size' => $formattedSize]
            ]);
            $success = t('admin_backup.create_success_prefix') . $filename . ' (' . $formattedSize . ')';
        } catch (Exception $e) {
            error_log('Error creating database backup: ' . $e->getMessage());
            $error = t('admin_backup.create_failed');
        }
    } elseif (isset($_POST['delete_backup'])) {
        try {
            $backupDir = dirname(dirname(__DIR__)) . '/db_backup';
            $requestedFile = basename($_POST['backup_filename'] ?? '');
            $filePath = $backupDir . '/' . $requestedFile;

            if (!preg_match('/^tprm_database_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $requestedFile)) {
                throw new Exception('Invalid backup filename');
            }

            if (!file_exists($filePath)) {
                throw new Exception('Backup file not found');
            }

            if (!unlink($filePath)) {
                throw new Exception('Failed to delete backup file');
            }

            $auth->audit($user['id'], 'backup_delete', 'database', null, [
                'old' => ['filename' => $requestedFile]
            ]);
            $success = t('admin_backup.delete_success_prefix') . $requestedFile;
        } catch (Exception $e) {
            error_log('Error deleting backup: ' . $e->getMessage());
            $error = t('admin_backup.delete_failed');
        }
    } elseif (isset($_POST['restore_backup'])) {
        try {
            $backupDir = dirname(dirname(__DIR__)) . '/db_backup';
            $requestedFile = basename($_POST['backup_filename'] ?? '');
            $filePath = $backupDir . '/' . $requestedFile;

            if (!preg_match('/^tprm_database_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $requestedFile)) {
                throw new Exception('Invalid backup filename');
            }

            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new Exception('Backup file not found');
            }

            if (filesize($filePath) === 0) {
                throw new Exception('Backup file is empty');
            }

            // Create a pre-restore backup automatically
            $preRestoreFile = $backupDir . '/tprm_database_backup_' . date('Y-m-d_His') . '.sql';
            $backupService = new BackupService($config->get('database'));
            $backupService->dump($preRestoreFile);
            if (!file_exists($preRestoreFile) || filesize($preRestoreFile) === 0) {
                if (file_exists($preRestoreFile)) unlink($preRestoreFile);
                throw new Exception('Failed to create pre-restore safety backup.');
            }
            chmod($preRestoreFile, 0600);

            // Restore directly — BackupService handles DEFINER stripping internally
            $backupService->restore($filePath);

            // Re-apply SQL migrations to bring schema up to date
            $backupService->applySqlUpdates();

            // Detect encryption key mismatch: try decrypting a sample value
            $encKeyMismatch = false;
            try {
                $testPdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                        $config->get('database.host'),
                        $config->get('database.port', 3306),
                        $config->get('database.database')),
                    $config->get('database.username'),
                    $config->get('database.password'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $sample = $testPdo->query(
                    "SELECT config_value FROM app_config WHERE is_encrypted = 1 AND config_value IS NOT NULL AND config_value != '' LIMIT 1"
                )->fetchColumn();
                if ($sample) {
                    $testEnc = new Encryption();
                    try {
                        $testEnc->decrypt($sample);
                    } catch (Exception $hmacEx) {
                        $encKeyMismatch = true;
                    }
                }
            } catch (Exception $detectEx) {
                error_log('Encryption mismatch detection error: ' . $detectEx->getMessage());
            }

            if ($encKeyMismatch) {
                $sourceKey = trim($_POST['source_encryption_key'] ?? '');
                if ($sourceKey !== '') {
                    // Re-encrypt with the provided source key
                    $reEncCount = $backupService->reEncryptData($sourceKey);
                    try {
                        $auth->audit($user['id'], 'backup_restore', 'database', null, [
                            'new' => ['restored_from' => $requestedFile, 'safety_backup' => basename($preRestoreFile), 're_encrypted' => $reEncCount]
                        ]);
                    } catch (Exception $e) {
                        error_log('Audit after restore failed: ' . $e->getMessage());
                    }
                    while (ob_get_level()) ob_end_clean();
                    $_SESSION['flash_success'] = t('admin_backup.restore_reenc_1') . $requestedFile . t('admin_backup.restore_reenc_2') . $reEncCount . t('admin_backup.restore_reenc_3') . basename($preRestoreFile) . '.';
                    header('Location: admin.php?section=backup');
                    exit;
                } else {
                    // No source key provided — ask the user for it
                    while (ob_get_level()) ob_end_clean();
                    $_SESSION['flash_error'] = t('admin_backup.restore_key_mismatch');
                    $_SESSION['flash_needs_key'] = true;
                    header('Location: admin.php?section=backup');
                    exit;
                }
            }

            try {
                $auth->audit($user['id'], 'backup_restore', 'database', null, [
                    'new' => ['restored_from' => $requestedFile, 'safety_backup' => basename($preRestoreFile)]
                ]);
            } catch (Exception $e) {
                error_log('Audit after restore failed: ' . $e->getMessage());
            }

            // Redirect for a clean page load — the DB was just replaced, so
            // rendering inline with stale singletons/session state can crash.
            while (ob_get_level()) ob_end_clean();
            $_SESSION['flash_success'] = t('admin_backup.restore_success_1') . $requestedFile . t('admin_backup.restore_success_2') . basename($preRestoreFile) . '.';
            header('Location: admin.php?section=backup');
            exit;
        } catch (Exception $e) {
            error_log('Error restoring database: ' . $e->getMessage());
            // Redirect even on error — DB may be in an inconsistent state
            while (ob_get_level()) ob_end_clean();
            $_SESSION['flash_error'] = t('admin_backup.restore_failed_prefix') . $e->getMessage();
            header('Location: admin.php?section=backup');
            exit;
        }
    } elseif (isset($_POST['upload_restore_backup'])) {
        try {
            $dbConfig = $config->get('database');
            $backupDir = dirname(dirname(__DIR__)) . '/db_backup';

            // Create backup directory if it doesn't exist
            if (!is_dir($backupDir)) {
                if (!mkdir($backupDir, 0700, true)) {
                    throw new Exception('Failed to create backup directory');
                }
                file_put_contents($backupDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
                file_put_contents($backupDir . '/index.html', '');
            }

            if (!isset($_FILES['backup_upload']) || $_FILES['backup_upload']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file was selected for upload',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk',
                    UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension',
                ];
                $errCode = $_FILES['backup_upload']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new Exception($uploadErrors[$errCode] ?? 'Unknown upload error');
            }

            $uploadedFile = $_FILES['backup_upload'];

            // Validate file extension
            $originalName = basename($uploadedFile['name']);
            if (!preg_match('/\.sql$/i', $originalName)) {
                throw new Exception('Only .sql files are allowed');
            }

            // Validate file is not empty
            if ($uploadedFile['size'] === 0) {
                throw new Exception('Uploaded file is empty');
            }

            // Quick content validation: check first bytes for SQL-like content
            $handle = fopen($uploadedFile['tmp_name'], 'r');
            $header = fread($handle, 1024);
            fclose($handle);
            if (stripos($header, 'CREATE') === false
                && stripos($header, 'INSERT') === false
                && stripos($header, 'DROP') === false
                && stripos($header, '--') !== 0
                && stripos($header, '/*') !== 0) {
                throw new Exception('File does not appear to be a valid SQL dump');
            }

            // Save the uploaded file to db_backup with a proper name
            $timestamp = date('Y-m-d_His');
            $savedName = 'tprm_database_backup_' . $timestamp . '.sql';
            $savedPath = $backupDir . '/' . $savedName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $savedPath)) {
                throw new Exception('Failed to save uploaded file');
            }
            chmod($savedPath, 0600);

            // Create a pre-restore safety backup
            $preRestoreFile = $backupDir . '/tprm_database_backup_' . date('Y-m-d_His', strtotime('+1 second')) . '.sql';
            // Ensure unique filename
            $safetyTs = time();
            $preRestoreFile = $backupDir . '/tprm_database_backup_' . date('Y-m-d_His', $safetyTs) . '.sql';
            if (file_exists($preRestoreFile)) {
                $preRestoreFile = $backupDir . '/tprm_database_backup_' . date('Y-m-d_His', $safetyTs + 1) . '.sql';
            }

            $backupService = new BackupService($config->get('database'));
            $backupService->dump($preRestoreFile);
            if (!file_exists($preRestoreFile) || filesize($preRestoreFile) === 0) {
                if (file_exists($preRestoreFile)) unlink($preRestoreFile);
                throw new Exception('Failed to create pre-restore safety backup via PHP/PDO.');
            }
            chmod($preRestoreFile, 0600);

            // Restore directly — BackupService handles DEFINER stripping internally
            $backupService->restore($savedPath);

            // Re-apply SQL migrations to bring schema up to date
            $backupService->applySqlUpdates();

            // Detect encryption key mismatch: try decrypting a sample value
            $encKeyMismatch = false;
            try {
                $testPdo = new PDO(
                    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                        $config->get('database.host'),
                        $config->get('database.port', 3306),
                        $config->get('database.database')),
                    $config->get('database.username'),
                    $config->get('database.password'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $sample = $testPdo->query(
                    "SELECT config_value FROM app_config WHERE is_encrypted = 1 AND config_value IS NOT NULL AND config_value != '' LIMIT 1"
                )->fetchColumn();
                if ($sample) {
                    $testEnc = new Encryption();
                    try {
                        $testEnc->decrypt($sample);
                    } catch (Exception $hmacEx) {
                        $encKeyMismatch = true;
                    }
                }
            } catch (Exception $detectEx) {
                error_log('Encryption mismatch detection error: ' . $detectEx->getMessage());
            }

            if ($encKeyMismatch) {
                $sourceKey = trim($_POST['source_encryption_key'] ?? '');
                if ($sourceKey !== '') {
                    $reEncCount = $backupService->reEncryptData($sourceKey);
                    try {
                        $auth->audit($user['id'], 'backup_upload_restore', 'database', null, [
                            'new' => ['uploaded_file' => $originalName, 'saved_as' => $savedName, 're_encrypted' => $reEncCount]
                        ]);
                    } catch (Exception $e) {
                        error_log('Audit after upload restore failed: ' . $e->getMessage());
                    }
                    while (ob_get_level()) ob_end_clean();
                    $_SESSION['flash_success'] = t('admin_backup.upload_restore_reenc_1') . $originalName . t('admin_backup.upload_restore_reenc_2') . $reEncCount . t('admin_backup.upload_restore_reenc_3') . $savedName . '.';
                    header('Location: admin.php?section=backup');
                    exit;
                } else {
                    while (ob_get_level()) ob_end_clean();
                    $_SESSION['flash_error'] = t('admin_backup.restore_key_mismatch');
                    $_SESSION['flash_needs_key'] = true;
                    header('Location: admin.php?section=backup');
                    exit;
                }
            }

            try {
                $auth->audit($user['id'], 'backup_upload_restore', 'database', null, [
                    'new' => ['uploaded_file' => $originalName, 'saved_as' => $savedName]
                ]);
            } catch (Exception $e) {
                error_log('Audit after upload restore failed: ' . $e->getMessage());
            }

            // Redirect for a clean page load — the DB was just replaced, so
            // rendering inline with stale singletons/session state can crash.
            while (ob_get_level()) ob_end_clean();
            $_SESSION['flash_success'] = t('admin_backup.upload_restore_success_1') . $originalName . t('admin_backup.upload_restore_success_2') . $savedName . t('admin_backup.upload_restore_success_3');
            header('Location: admin.php?section=backup');
            exit;
        } catch (Exception $e) {
            error_log('Error restoring database from upload: ' . $e->getMessage());
            // Redirect even on error — DB may be in an inconsistent state
            while (ob_get_level()) ob_end_clean();
            $_SESSION['flash_error'] = t('admin_backup.upload_restore_failed_prefix') . $e->getMessage();
            header('Location: admin.php?section=backup');
            exit;
        }
    }
}

// ============================================================================
// Data Loading: Existing backups
// ============================================================================
$backupDir = dirname(dirname(__DIR__)) . '/db_backup';

// ============================================================================
// HTML: Backup UI
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_backup.page_title')); ?></h1>
    <p><?php echo e(t('admin_backup.page_desc')); ?></p>
</div>

<div class="card">
    <h3><?php echo e(t('admin_backup.create_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo t('admin_backup.create_desc'); ?>
    </p>
    <form method="POST" action="admin.php?section=backup">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" name="create_backup" value="1">
        <button type="submit" class="btn btn-primary" data-disable-on-click="<?php echo e(t('admin_backup.btn_create_disable')); ?>"><?php echo e(t('admin_backup.btn_create')); ?></button>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_backup.existing_heading')); ?></h3>
    <?php
    $backups = [];
    if (is_dir($backupDir)) {
        $files = glob($backupDir . '/tprm_database_backup_*.sql');
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created' => filemtime($file),
            ];
        }
        // Sort by created date, newest first
        usort($backups, function($a, $b) {
            return $b['created'] - $a['created'];
        });
    }
    ?>
    <?php if (empty($backups)): ?>
        <p style="color: #999;"><?php echo e(t('admin_backup.no_backups')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px;">
            <?php echo t('admin_backup.existing_stored_note'); ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th><?php echo e(t('admin_backup.col_filename')); ?></th>
                    <th><?php echo e(t('admin_backup.col_size')); ?></th>
                    <th><?php echo e(t('admin_backup.col_created')); ?></th>
                    <th><?php echo e(t('admin_backup.col_actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><strong><?php echo e($backup['filename']); ?></strong></td>
                    <td>
                        <?php
                        if ($backup['size'] > 1048576) {
                            echo round($backup['size'] / 1048576, 2) . ' MB';
                        } else {
                            echo round($backup['size'] / 1024, 2) . ' KB';
                        }
                        ?>
                    </td>
                    <td><?php echo date('M j, Y g:i A', $backup['created']); ?></td>
                    <td style="white-space: nowrap;">
                        <a href="admin.php?section=backup&download_backup=<?php echo urlencode($backup['filename']); ?>" class="btn btn-primary btn-sm"><?php echo e(t('admin_backup.action_download')); ?></a>
                        <button type="button" class="btn btn-sm" style="background: #f59e0b; color: white;" data-action="confirmRestore" data-arg="<?php echo e($backup['filename']); ?>"><?php echo e(t('admin_backup.action_restore')); ?></button>
                        <form method="POST" action="admin.php?section=backup" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="backup_filename" value="<?php echo e($backup['filename']); ?>">
                            <button type="submit" name="delete_backup" class="btn btn-sm" style="background: #dc2626; color: white;" data-confirm="<?php echo e(t('admin_backup.confirm_delete')); ?>"><?php echo e(t('admin_backup.action_delete')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3><?php echo e(t('admin_backup.upload_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo t('admin_backup.upload_desc'); ?>
    </p>
    <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
        <strong style="color: #92400e;"><?php echo e(t('admin_backup.warning_label')); ?></strong>
        <span style="font-size: 13px; color: #78350f;">
            <?php echo e(t('admin_backup.upload_warning')); ?>
        </span>
    </div>
    <form method="POST" action="admin.php?section=backup" enctype="multipart/form-data" id="uploadRestoreForm">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <input type="hidden" id="upload_source_key_hidden" name="source_encryption_key" value="">
        <div class="form-group">
            <label for="backup_upload"><?php echo e(t('admin_backup.label_select_file')); ?></label>
            <input type="file" id="backup_upload" name="backup_upload" class="form-control" accept=".sql" required
                   style="padding: 8px; border: 2px dashed #ddd; border-radius: 6px; cursor: pointer;"
                   data-file-display="uploadFileName">
            <div id="uploadFileName" style="margin-top: 8px; font-size: 13px; color: #666;"></div>
            <div class="form-help"><?php echo t('admin_backup.upload_form_help'); ?></div>
        </div>
        <button type="button" class="btn" style="background: #f59e0b; color: white;" data-action="confirmUploadRestore"><?php echo e(t('admin_backup.btn_upload_restore')); ?></button>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_backup.info_heading')); ?></h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_backup.info_storage_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_backup.info_storage_desc_prefix')); ?><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;"><?php echo e($backupDir); ?></code><?php echo t('admin_backup.info_storage_desc_suffix'); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_backup.info_contents_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_backup.info_contents_desc')); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_backup.info_safety_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_backup.info_safety_desc')); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_backup.info_method_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_backup.info_method_desc')); ?>
            </p>
        </div>
    </div>
</div>

<!-- Restore Database Confirmation Modal -->
<div id="restoreBackupModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" data-action="closeRestoreModal">&times;</span>
        <h3 style="margin-top: 0; color: #dc2626;"><?php echo e(t('admin_backup.restore_modal_title')); ?></h3>

        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: start; gap: 10px;">
                <span style="font-size: 20px;">&#9888;</span>
                <div>
                    <strong style="color: #991b1b;"><?php echo e(t('admin_backup.destructive_op')); ?></strong>
                    <p style="margin: 5px 0 0; font-size: 13px; color: #991b1b;">
                        <?php echo t('admin_backup.restore_modal_warning'); ?>
                    </p>
                </div>
            </div>
        </div>

        <p style="color: #666; margin-bottom: 15px;">
            <?php echo e(t('admin_backup.restore_modal_from_label')); ?> <br>
            <strong id="restore_filename_display" style="color: #333;"></strong>
        </p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="restore_source_key" style="font-weight: 500;"><?php echo t('admin_backup.label_source_key'); ?></label>
            <input type="text" id="restore_source_key" class="form-control" placeholder="<?php echo e(t('admin_backup.placeholder_source_key')); ?>" autocomplete="off" style="font-family: monospace; font-size: 13px;">
            <div class="form-help"><?php echo t('admin_backup.source_key_help'); ?></div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="restore_confirm_input" style="font-weight: 500;"><?php echo t('admin_backup.type_restore_label'); ?></label>
            <input type="text" id="restore_confirm_input" class="form-control" placeholder="<?php echo e(t('admin_backup.placeholder_type_restore')); ?>" autocomplete="off">
        </div>

        <form method="POST" action="admin.php?section=backup" id="restoreBackupForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="restore_backup_filename" name="backup_filename" value="">
            <input type="hidden" name="restore_backup" value="1">
            <input type="hidden" id="restore_source_key_hidden" name="source_encryption_key" value="">

            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-secondary" data-action="closeRestoreModal"><?php echo e(t('admin_backup.btn_cancel')); ?></button>
                <button type="button" id="confirmRestoreBtn" class="btn" style="background: #f59e0b; color: white;" disabled><?php echo e(t('admin_backup.btn_restore_database')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Restore Confirmation Modal -->
<div id="uploadRestoreModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <span class="close" data-action="closeUploadRestoreModal">&times;</span>
        <h3 style="margin-top: 0; color: #dc2626;"><?php echo e(t('admin_backup.upload_modal_title')); ?></h3>

        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: start; gap: 10px;">
                <span style="font-size: 20px;">&#9888;</span>
                <div>
                    <strong style="color: #991b1b;"><?php echo e(t('admin_backup.destructive_op')); ?></strong>
                    <p style="margin: 5px 0 0; font-size: 13px; color: #991b1b;">
                        <?php echo t('admin_backup.upload_modal_warning'); ?>
                    </p>
                </div>
            </div>
        </div>

        <p style="color: #666; margin-bottom: 15px;">
            <?php echo e(t('admin_backup.upload_modal_file_label')); ?> <strong id="upload_restore_filename_display" style="color: #333;"></strong>
        </p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="upload_restore_source_key" style="font-weight: 500;"><?php echo t('admin_backup.label_source_key'); ?></label>
            <input type="text" id="upload_restore_source_key" class="form-control" placeholder="<?php echo e(t('admin_backup.placeholder_source_key')); ?>" autocomplete="off" style="font-family: monospace; font-size: 13px;">
            <div class="form-help"><?php echo t('admin_backup.source_key_help'); ?></div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label for="upload_restore_confirm_input" style="font-weight: 500;"><?php echo t('admin_backup.type_restore_label'); ?></label>
            <input type="text" id="upload_restore_confirm_input" class="form-control" placeholder="<?php echo e(t('admin_backup.placeholder_type_restore')); ?>" autocomplete="off">
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid #e5e7eb;">
            <button type="button" class="btn btn-secondary" data-action="closeUploadRestoreModal"><?php echo e(t('admin_backup.btn_cancel')); ?></button>
            <button type="button" id="confirmUploadRestoreBtn" class="btn" style="background: #f59e0b; color: white;" disabled><?php echo e(t('admin_backup.btn_upload_restore_short')); ?></button>
        </div>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Backup restore modal functions
function confirmRestore(filename) {
    document.getElementById('restore_backup_filename').value = filename;
    document.getElementById('restore_filename_display').textContent = filename;
    document.getElementById('restore_confirm_input').value = '';
    document.getElementById('confirmRestoreBtn').disabled = true;
    document.getElementById('restoreBackupModal').style.display = 'flex';
}

function closeRestoreModal() {
    document.getElementById('restoreBackupModal').style.display = 'none';
    document.getElementById('restore_confirm_input').value = '';
    document.getElementById('confirmRestoreBtn').disabled = true;
}

function confirmUploadRestore() {
    var fileInput = document.getElementById('backup_upload');
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        alert(<?php echo json_encode(t('admin_backup.js_select_file')); ?>);
        return;
    }
    var file = fileInput.files[0];
    if (!file.name.toLowerCase().endsWith('.sql')) {
        alert(<?php echo json_encode(t('admin_backup.js_only_sql')); ?>);
        return;
    }
    document.getElementById('upload_restore_filename_display').textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
    document.getElementById('upload_restore_confirm_input').value = '';
    document.getElementById('confirmUploadRestoreBtn').disabled = true;
    // Add the submit name to the form so PHP knows it's a restore upload
    var hiddenInput = document.getElementById('uploadRestoreHidden');
    if (!hiddenInput) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'upload_restore_backup';
        hiddenInput.value = '1';
        hiddenInput.id = 'uploadRestoreHidden';
        document.getElementById('uploadRestoreForm').appendChild(hiddenInput);
    }
    document.getElementById('uploadRestoreModal').style.display = 'flex';
}

function closeUploadRestoreModal() {
    document.getElementById('uploadRestoreModal').style.display = 'none';
    document.getElementById('upload_restore_confirm_input').value = '';
    document.getElementById('confirmUploadRestoreBtn').disabled = true;
    // Remove the hidden input so a normal form submit doesn't trigger restore
    var hiddenInput = document.getElementById('uploadRestoreHidden');
    if (hiddenInput) hiddenInput.remove();
}

// Submit restore form when confirm button is clicked
document.getElementById('confirmRestoreBtn')?.addEventListener('click', function() {
    // Copy source key to hidden form field
    var keyVal = document.getElementById('restore_source_key')?.value || '';
    document.getElementById('restore_source_key_hidden').value = keyVal;
    this.disabled = true;
    this.textContent = <?php echo json_encode(t('admin_backup.js_restoring')); ?>;
    document.getElementById('restoreBackupForm').submit();
});
document.getElementById('confirmUploadRestoreBtn')?.addEventListener('click', function() {
    // Copy source key to hidden form field
    var keyVal = document.getElementById('upload_restore_source_key')?.value || '';
    document.getElementById('upload_source_key_hidden').value = keyVal;
    this.disabled = true;
    this.textContent = <?php echo json_encode(t('admin_backup.js_uploading_restoring')); ?>;
    document.getElementById('uploadRestoreForm').submit();
});

// Close restore modals on outside click
document.getElementById('restoreBackupModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRestoreModal();
});
document.getElementById('uploadRestoreModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeUploadRestoreModal();
});

// Bind confirmation input validators (replaces inline oninput handlers)
document.getElementById('restore_confirm_input')?.addEventListener('input', function() {
    document.getElementById('confirmRestoreBtn').disabled = (this.value !== 'RESTORE');
});
document.getElementById('upload_restore_confirm_input')?.addEventListener('input', function() {
    document.getElementById('confirmUploadRestoreBtn').disabled = (this.value !== 'RESTORE');
});

</script>
