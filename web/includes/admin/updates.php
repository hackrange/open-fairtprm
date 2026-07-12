<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: SQL Updates
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The database migration page for people who don't use database migrations.
 * Upload .sql files, preview them before running (please do this), apply them
 * to the database, and keep a log of what was applied when and by whom.
 *
 * Think of it as a manual Flyway or Liquibase, but with more clicking and
 * less YAML. Perfect for hotfixes, schema changes, and that one ALTER TABLE
 * you keep meaning to run but keep forgetting about.
 *
 * Security: validates file contents for SQL-like patterns, restricts to .sql
 * extensions, stores files with safe permissions, and logs everything because
 * audit trails are your friend when something breaks at 3am.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handlers: upload_sql_update, apply_sql_update, delete_sql_update
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_updates.invalid_request');
    } elseif (isset($_POST['upload_sql_update'])) {
        try {
            $updatesDir = dirname(dirname(__DIR__)) . '/sql_updates';

            // Create directory if it doesn't exist
            if (!is_dir($updatesDir)) {
                if (!mkdir($updatesDir, 0700, true)) {
                    throw new Exception('Failed to create SQL updates directory');
                }
                file_put_contents($updatesDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
                file_put_contents($updatesDir . '/index.html', '');
            }

            if (!isset($_FILES['sql_update_file']) || $_FILES['sql_update_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file was selected for upload',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk',
                    UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension',
                ];
                $errCode = $_FILES['sql_update_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new Exception($uploadErrors[$errCode] ?? 'Unknown upload error');
            }

            $uploadedFile = $_FILES['sql_update_file'];

            // Validate file extension -- only .sql allowed
            $originalName = basename($uploadedFile['name']);
            if (!preg_match('/\.sql$/i', $originalName)) {
                throw new Exception('Only .sql files are allowed');
            }

            // Sanitize the filename: only allow alphanumeric, hyphens, underscores, dots
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            if (!preg_match('/\.sql$/i', $safeName)) {
                $safeName .= '.sql';
            }

            if ($uploadedFile['size'] === 0) {
                throw new Exception('Uploaded file is empty');
            }

            // Content validation: check first bytes for SQL-like content
            $handle = fopen($uploadedFile['tmp_name'], 'r');
            $header = fread($handle, 1024);
            fclose($handle);
            if (stripos($header, 'CREATE') === false
                && stripos($header, 'INSERT') === false
                && stripos($header, 'ALTER') === false
                && stripos($header, 'UPDATE') === false
                && stripos($header, 'DROP') === false
                && stripos($header, 'DELETE') === false
                && stripos($header, '--') !== 0
                && stripos($header, '/*') !== 0) {
                throw new Exception('File does not appear to contain valid SQL statements');
            }

            $destPath = $updatesDir . '/' . $safeName;

            // Prevent overwriting existing files
            if (file_exists($destPath)) {
                throw new Exception('A file named "' . $safeName . '" already exists. Delete it first or use a different name.');
            }

            if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                throw new Exception('Failed to save uploaded file');
            }
            chmod($destPath, 0600);

            $success = t('admin_updates.upload_success_prefix') . $safeName;
        } catch (Exception $e) {
            error_log('Error uploading SQL update: ' . $e->getMessage());
            $error = t('admin_updates.upload_failed');
        }

    } elseif (isset($_POST['apply_sql_update'])) {
        try {
            $updatesDir = dirname(dirname(__DIR__)) . '/sql_updates';
            $requestedFile = basename($_POST['update_filename'] ?? '');

            if (empty($requestedFile)) {
                throw new Exception('No update file specified');
            }

            // Strict filename validation -- only .sql files with safe characters
            if (!preg_match('/^[a-zA-Z0-9._-]+\.sql$/i', $requestedFile)) {
                throw new Exception('Invalid filename');
            }

            $filePath = $updatesDir . '/' . $requestedFile;
            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new Exception('Update file not found: ' . $requestedFile);
            }

            $sqlContent = file_get_contents($filePath);
            if ($sqlContent === false || trim($sqlContent) === '') {
                throw new Exception('Unable to read update file or file is empty');
            }

            // Execute the SQL update
            $dbConfig = $config->get('database');
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'] ?? 3306,
                $dbConfig['database'],
                $dbConfig['charset'] ?? 'utf8mb4'
            );
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Execute statements individually for better error handling.
            // Uses a quote-aware splitter so semicolons inside string
            // literals (e.g. HTML email templates) don't break the parse.
            // Ignorable errors (duplicate column, duplicate key, table already
            // exists) are logged but don't stop execution -- this makes
            // migrations safe to re-run on both MySQL and MariaDB.

            // Quote-aware SQL statement splitter: splits on semicolons
            // only when they are outside single-quoted string literals.
            // Handles escaped quotes (\' and '') inside strings.
            $statements = [];
            $current = '';
            $inString = false;
            $len = strlen($sqlContent);
            for ($i = 0; $i < $len; $i++) {
                $ch = $sqlContent[$i];
                if ($inString) {
                    $current .= $ch;
                    if ($ch === '\\' && $i + 1 < $len) {
                        $current .= $sqlContent[++$i]; // skip escaped char
                    } elseif ($ch === "'") {
                        if ($i + 1 < $len && $sqlContent[$i + 1] === "'") {
                            $current .= $sqlContent[++$i]; // skip doubled quote
                        } else {
                            $inString = false;
                        }
                    }
                } else {
                    if ($ch === "'") {
                        $inString = true;
                        $current .= $ch;
                    } elseif ($ch === ';') {
                        $stmt = trim($current);
                        if ($stmt !== '') {
                            $statements[] = $stmt;
                        }
                        $current = '';
                    } elseif ($ch === '-' && $i + 1 < $len && $sqlContent[$i + 1] === '-') {
                        // Skip single-line comment
                        $eol = strpos($sqlContent, "\n", $i);
                        $i = ($eol === false) ? $len : $eol;
                    } elseif ($ch === '/' && $i + 1 < $len && $sqlContent[$i + 1] === '*') {
                        // Keep MySQL conditional comments (/*!...*/), skip regular comments
                        if ($i + 2 < $len && $sqlContent[$i + 2] === '!') {
                            // Conditional comment — keep it as part of the statement
                            $end = strpos($sqlContent, '*/', $i + 2);
                            if ($end !== false) {
                                $current .= substr($sqlContent, $i, $end + 2 - $i);
                                $i = $end + 1;
                            } else {
                                $current .= $ch;
                            }
                        } else {
                            // Regular comment — skip
                            $end = strpos($sqlContent, '*/', $i + 2);
                            $i = ($end === false) ? $len : $end + 1;
                        }
                    } else {
                        $current .= $ch;
                    }
                }
            }
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }

            $stmtNotices = [];
            foreach ($statements as $stmt) {
                if (empty($stmt)) continue;
                try {
                    // Use query() and fully drain any result set rather than
                    // exec(). Idempotency guards in migration files run a no-op
                    // branch via PREPARE/EXECUTE; if that branch (or any other
                    // statement) returns rows, exec() leaves them unbuffered on
                    // the connection and the very next statement fails with
                    // MySQL errno 2014 ("Cannot execute queries while other
                    // unbuffered queries are active"). Draining the cursor keeps
                    // the connection clean for the next statement.
                    $result = $pdo->query($stmt);
                    if ($result instanceof PDOStatement) {
                        do {
                            $result->fetchAll(PDO::FETCH_NUM);
                        } while ($result->nextRowset());
                        $result->closeCursor();
                    }
                } catch (PDOException $stmtEx) {
                    $errCode = isset($stmtEx->errorInfo[1]) ? (int)$stmtEx->errorInfo[1] : 0;
                    // Ignorable errors for idempotent migrations:
                    // 1060 = Duplicate column name (column already exists)
                    // 1061 = Duplicate key name (index already exists)
                    // 1050 = Table already exists (without IF NOT EXISTS)
                    // 1068 = Multiple primary key defined
                    // 1091 = Can't DROP index; check that it exists
                    // 1553 = Cannot drop index needed by FK (replacement already exists)
                    if (in_array($errCode, [1060, 1061, 1050, 1068, 1091, 1553])) {
                        $stmtNotices[] = $stmtEx->getMessage();
                        error_log('SQL update notice (ignorable): ' . $stmtEx->getMessage());
                    } else {
                        throw $stmtEx;
                    }
                }
            }

            // Log the applied update
            $logFile = $updatesDir . '/applied_updates.json';
            $log = [];
            if (file_exists($logFile)) {
                $logData = json_decode(file_get_contents($logFile), true);
                if (is_array($logData)) {
                    $log = $logData;
                }
            }
            $log[] = [
                'filename' => $requestedFile,
                'applied_at' => date('Y-m-d H:i:s'),
                'applied_by' => $user['username'] ?? 'unknown',
                'file_size' => filesize($filePath),
            ];
            file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));

            $success = t('admin_updates.apply_success_prefix') . $requestedFile;
        } catch (PDOException $e) {
            error_log('SQL update execution error: ' . $e->getMessage());
            $error = t('admin_updates.apply_failed_sql');
        } catch (Exception $e) {
            error_log('Error applying SQL update: ' . $e->getMessage());
            $error = t('admin_updates.apply_failed');
        }

    } elseif (isset($_POST['delete_sql_update'])) {
        try {
            $updatesDir = dirname(dirname(__DIR__)) . '/sql_updates';
            $requestedFile = basename($_POST['update_filename'] ?? '');

            if (empty($requestedFile)) {
                throw new Exception('No update file specified');
            }

            if (!preg_match('/^[a-zA-Z0-9._-]+\.sql$/i', $requestedFile)) {
                throw new Exception('Invalid filename');
            }

            $filePath = $updatesDir . '/' . $requestedFile;
            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new Exception('Update file not found');
            }

            if (!unlink($filePath)) {
                throw new Exception('Failed to delete update file');
            }

            $success = t('admin_updates.delete_success_prefix') . $requestedFile;
        } catch (Exception $e) {
            error_log('Error deleting SQL update: ' . $e->getMessage());
            $error = t('admin_updates.delete_failed');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$updatesDir = dirname(dirname(__DIR__)) . '/sql_updates';

// ============================================================================
// HTML: SQL Updates UI
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_updates.page_title')); ?></h1>
    <p><?php echo t('admin_updates.page_intro'); ?></p>
</div>

<div class="card">
    <h3><?php echo e(t('admin_updates.upload_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;">
        <?php echo t('admin_updates.upload_desc'); ?>
    </p>
    <form method="POST" action="admin.php?section=updates" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label for="sql_update_file"><?php echo e(t('admin_updates.select_file_label')); ?></label>
            <input type="file" id="sql_update_file" name="sql_update_file" class="form-control" accept=".sql" required
                   style="padding: 8px; border: 2px dashed #ddd; border-radius: 6px; cursor: pointer;"
                   data-file-display="updateFileName">
            <div id="updateFileName" style="margin-top: 8px; font-size: 13px; color: #666;"></div>
            <div class="form-help"><?php echo t('admin_updates.upload_help'); ?></div>
        </div>
        <button type="submit" name="upload_sql_update" class="btn btn-primary"><?php echo e(t('admin_updates.upload_button')); ?></button>
    </form>
</div>

<div class="card">
    <h3><?php echo e(t('admin_updates.available_heading')); ?></h3>
    <?php
    $updateFiles = [];
    if (is_dir($updatesDir)) {
        $files = glob($updatesDir . '/*.sql');
        foreach ($files as $file) {
            $updateFiles[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }
        usort($updateFiles, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });
    }

    // Load applied updates log (handles both entrypoint and admin panel formats)
    $appliedLog = [];
    $logFile = $updatesDir . '/applied_updates.json';
    $logData = [];
    if (file_exists($logFile)) {
        $rawLog = json_decode(file_get_contents($logFile), true);
        if (is_array($rawLog)) {
            // Detect format: entrypoint writes {"file.sql": {"applied_at":...}}
            // admin panel writes [{"filename": "file.sql", "applied_at":...}]
            if (array_is_list($rawLog)) {
                // Array format from admin panel
                $logData = $rawLog;
                foreach ($rawLog as $entry) {
                    if (isset($entry['filename'])) {
                        $appliedLog[$entry['filename']] = $entry;
                    }
                }
            } else {
                // Object format from entrypoint
                foreach ($rawLog as $fname => $meta) {
                    $entry = array_merge(['filename' => $fname, 'applied_by' => 'entrypoint'], is_array($meta) ? $meta : []);
                    $appliedLog[$fname] = $entry;
                    $logData[] = $entry;
                }
            }
        }
    }
    ?>
    <?php if (empty($updateFiles)): ?>
        <p style="color: #999;"><?php echo e(t('admin_updates.empty_state')); ?></p>
    <?php else: ?>
        <p style="color: #666; margin-bottom: 15px;">
            <?php echo t('admin_updates.storage_note'); ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th><?php echo e(t('admin_updates.th_filename')); ?></th>
                    <th><?php echo e(t('admin_updates.th_size')); ?></th>
                    <th><?php echo e(t('admin_updates.th_uploaded')); ?></th>
                    <th><?php echo e(t('admin_updates.th_status')); ?></th>
                    <th><?php echo e(t('admin_updates.th_actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($updateFiles as $updateFile): ?>
                <?php $wasApplied = isset($appliedLog[$updateFile['filename']]); ?>
                <tr>
                    <td><strong><?php echo e($updateFile['filename']); ?></strong></td>
                    <td>
                        <?php
                        if ($updateFile['size'] > 1048576) {
                            echo round($updateFile['size'] / 1048576, 2) . ' MB';
                        } else {
                            echo round($updateFile['size'] / 1024, 2) . ' KB';
                        }
                        ?>
                    </td>
                    <td><?php echo date('M j, Y g:i A', $updateFile['modified']); ?></td>
                    <td>
                        <?php if ($wasApplied): ?>
                            <span style="display: inline-block; padding: 2px 8px; background: #dcfce7; color: #166534; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                <?php echo e(t('admin_updates.status_applied')); ?> <?php echo date('M j, Y g:i A', strtotime($appliedLog[$updateFile['filename']]['applied_at'])); ?>
                                <?php echo e(t('admin_updates.status_applied_by')); ?> <?php echo e($appliedLog[$updateFile['filename']]['applied_by']); ?>
                            </span>
                        <?php else: ?>
                            <span style="display: inline-block; padding: 2px 8px; background: #fef3c7; color: #92400e; border-radius: 4px; font-size: 12px; font-weight: 500;"><?php echo e(t('admin_updates.status_pending')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <button type="button" class="btn btn-sm" style="background: #6366f1; color: white;" data-action="previewSqlUpdate" data-arg="<?php echo e($updateFile['filename']); ?>"><?php echo e(t('admin_updates.btn_preview')); ?></button>
                        <button type="button" class="btn btn-primary btn-sm" data-action="confirmApplyUpdate" data-arg="<?php echo e($updateFile['filename']); ?>"><?php echo e(t('admin_updates.btn_apply')); ?></button>
                        <form method="POST" action="admin.php?section=updates" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="update_filename" value="<?php echo e($updateFile['filename']); ?>">
                            <button type="submit" name="delete_sql_update" class="btn btn-sm" style="background: #dc2626; color: white;" data-confirm="<?php echo e(t('admin_updates.delete_confirm')); ?>"><?php echo e(t('admin_updates.btn_delete')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if (!empty($appliedLog)): ?>
<div class="card">
    <h3><?php echo e(t('admin_updates.history_heading')); ?></h3>
    <p style="color: #666; margin-bottom: 15px;"><?php echo e(t('admin_updates.history_desc')); ?></p>
    <table>
        <thead>
            <tr>
                <th><?php echo e(t('admin_updates.th_filename')); ?></th>
                <th><?php echo e(t('admin_updates.th_applied_at')); ?></th>
                <th><?php echo e(t('admin_updates.th_applied_by')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Show full log in reverse chronological order
            $fullLog = is_array($logData) ? array_reverse($logData) : [];
            foreach ($fullLog as $entry): ?>
            <tr>
                <td><strong><?php echo e($entry['filename']); ?></strong></td>
                <td><?php echo e($entry['applied_at']); ?></td>
                <td><?php echo e($entry['applied_by']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo e(t('admin_updates.info_heading')); ?></h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_updates.info_storage_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_updates.info_storage_prefix')); ?> <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px;"><?php echo e($updatesDir); ?></code>
                <?php echo t('admin_updates.info_storage_suffix'); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_updates.info_security_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_updates.info_security_body')); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_updates.info_bestpractice_heading')); ?></h4>
            <p style="font-size: 13px; color: #666; margin: 0;">
                <?php echo e(t('admin_updates.info_bestpractice_body')); ?>
            </p>
        </div>
        <div>
            <h4 style="font-size: 14px; color: #333; margin: 0 0 8px;"><?php echo e(t('admin_updates.info_cli_heading')); ?></h4>
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 10px 15px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-top: 5px;">
                <code style="color: #ce9178;">mysql</code> <code style="color: #9cdcfe;">-u tprm_user -p tprm</code> <code style="color: #6a9955;">< update_file.sql</code>
            </div>
        </div>
    </div>
</div>

<!-- Apply Update Confirmation Modal -->
<div id="applyUpdateModal" class="modal">
    <div class="modal-content">
        <span class="close" data-close="applyUpdateModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_updates.modal_confirm_heading')); ?></h3>
        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
            <strong style="color: #92400e;"><?php echo e(t('admin_updates.modal_warning_label')); ?></strong>
            <span style="font-size: 13px; color: #78350f;">
                <?php echo e(t('admin_updates.modal_warning_body')); ?>
            </span>
        </div>
        <p><?php echo e(t('admin_updates.modal_apply_prompt_prefix')); ?> <strong id="applyUpdateFilename"></strong>?</p>
        <form method="POST" action="admin.php?section=updates">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" id="apply_update_filename" name="update_filename" value="">
            <input type="hidden" name="apply_sql_update" value="1">
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn" style="background: #6b7280; color: white;" data-close="applyUpdateModal"><?php echo e(t('admin_updates.btn_cancel')); ?></button>
                <button type="submit" class="btn btn-primary" data-disable-on-click="<?php echo e(t('admin_updates.btn_applying')); ?>"><?php echo e(t('admin_updates.btn_apply_update')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Preview SQL Modal -->
<div id="previewSqlModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <span class="close" data-close="previewSqlModal">&times;</span>
        <h3 style="margin-top: 0; color: var(--theme-header-color);"><?php echo e(t('admin_updates.modal_preview_prefix')); ?> <span id="previewFilename"></span></h3>
        <pre id="previewSqlContent" style="background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 6px; max-height: 500px; overflow: auto; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;"></pre>
        <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="btn" style="background: #6b7280; color: white;" data-close="previewSqlModal"><?php echo e(t('admin_updates.btn_close')); ?></button>
        </div>
    </div>
</div>

<script nonce="<?php echo cspNonce(); ?>">
function confirmApplyUpdate(filename) {
    document.getElementById('applyUpdateFilename').textContent = filename;
    document.getElementById('apply_update_filename').value = filename;
    document.getElementById('applyUpdateModal').style.display = 'flex';
}

function previewSqlUpdate(filename) {
    document.getElementById('previewFilename').textContent = filename;
    document.getElementById('previewSqlContent').textContent = <?php echo json_encode(t('admin_updates.js_loading')); ?>;
    document.getElementById('previewSqlModal').style.display = 'flex';

    fetch('api/preview-sql-update.php?file=' + encodeURIComponent(filename))
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('previewSqlContent').textContent = data.content;
            } else {
                document.getElementById('previewSqlContent').textContent = <?php echo json_encode(t('admin_updates.js_error_prefix')); ?> + (data.error || <?php echo json_encode(t('admin_updates.js_could_not_load')); ?>);
            }
        })
        .catch(function(err) {
            document.getElementById('previewSqlContent').textContent = <?php echo json_encode(t('admin_updates.js_error_loading_preview')); ?> + err.message;
        });
}
</script>
