<?php
/**
 * GRC Unified Compliance Engine - Evidence Library
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Evidence library with filters (type, status, expiry), upload evidence files
 * (encrypted BLOB via Encryption class), link evidence to controls, show
 * expiry status, and view/download evidence files.
 */

require_once 'includes/init.php';
requireAuth();

$auth = Auth::getInstance();
$user = $auth->getUser();
$theme = getUserTheme();
$session = Session::getInstance();
$acl = ACL::getInstance();
$security = Security::getInstance();
$db = Database::getInstance();

// GRC access check
$isAdmin = $session->get('is_super_admin') || $auth->isAdmin() || hasGroup('administrator');
$isCyberGRC = hasGroup('cyber_grc');
$isAuditor = hasGroup('auditor');

if (!$isAdmin && !$isCyberGRC && !$isAuditor) {
    http_response_code(403);
    die('Access denied. GRC module requires cyber_grc, administrator, or auditor role.');
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();
$encryption = new Encryption();
$msg = '';
$msgType = '';

// Download handler
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $evId = (int)$_GET['download'];
    $ev = $db->fetchOne('SELECT * FROM grc_evidence WHERE id = :id', [':id' => $evId]);
    if ($ev && !empty($ev['encrypted_data'])) {
        try {
            $decrypted = $encryption->decryptRaw($ev['encrypted_data']);
            $filename = $ev['file_name'] ?? 'evidence_' . $evId;
            $mime = $ev['file_mime'] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Length: ' . strlen($decrypted));
            echo $decrypted;
            exit;
        } catch (Exception $ex) {
            $msg = 'Failed to decrypt evidence file.';
            $msgType = 'danger';
        }
    } else {
        $msg = 'Evidence file not found.';
        $msgType = 'danger';
    }
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid CSRF token. Please try again.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            $title = trim($_POST['title'] ?? '');
            $evidenceType = $_POST['evidence_type'] ?? 'document';
            $description = trim($_POST['description'] ?? '');
            $validUntil = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;

            if ($title !== '') {
                // Generate reference
                $last = $db->fetchOne("SELECT evidence_ref FROM grc_evidence WHERE evidence_ref LIKE 'EV-%' ORDER BY id DESC LIMIT 1");
                if ($last) {
                    $num = (int)substr($last['evidence_ref'], 3);
                    $ref = 'EV-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                } else {
                    $ref = 'EV-001';
                }

                $insertData = [
                    'evidence_ref' => $ref,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'evidence_type' => $evidenceType,
                    'collection_method' => 'manual',
                    'collected_at' => date('Y-m-d H:i:s'),
                    'valid_from' => date('Y-m-d H:i:s'),
                    'valid_until' => $validUntil,
                    'status' => 'current',
                    'collected_by' => (int)$user['id'],
                ];

                // Handle external URL
                $externalUrl = trim($_POST['external_url'] ?? '');
                if ($externalUrl !== '' && preg_match('#^https?://#i', $externalUrl)) {
                    $insertData['external_url'] = $externalUrl;
                }

                // Handle file upload
                if (!empty($_FILES['evidence_file']['tmp_name']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
                    $fileData = file_get_contents($_FILES['evidence_file']['tmp_name']);
                    $encryptedData = $encryption->encryptRaw($fileData);
                    $insertData['encrypted_data'] = $encryptedData;
                    $insertData['file_name'] = $_FILES['evidence_file']['name'];
                    $insertData['file_mime'] = $_FILES['evidence_file']['type'];
                    $insertData['file_size'] = $_FILES['evidence_file']['size'];
                }

                $db->insert('grc_evidence', $insertData);
                $newEvId = (int)$db->lastInsertId();

                // Link to controls if specified
                if (!empty($_POST['control_ids'])) {
                    $controlIds = is_array($_POST['control_ids']) ? $_POST['control_ids'] : [$_POST['control_ids']];
                    foreach ($controlIds as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) {
                            $db->insert('grc_evidence_control_map', [
                                'evidence_id' => $newEvId,
                                'control_id' => $cid,
                            ]);
                        }
                    }
                }

                $msg = 'Evidence uploaded successfully.';
                $msgType = 'success';
            } else {
                $msg = 'Title is required.';
                $msgType = 'danger';
            }
        } elseif ($action === 'link_control') {
            $evId = (int)($_POST['evidence_id'] ?? 0);
            $controlId = (int)($_POST['control_id'] ?? 0);
            if ($evId > 0 && $controlId > 0) {
                $existing = $db->fetchOne(
                    'SELECT id FROM grc_evidence_control_map WHERE evidence_id = :eid AND control_id = :cid',
                    [':eid' => $evId, ':cid' => $controlId]
                );
                if (!$existing) {
                    $db->insert('grc_evidence_control_map', [
                        'evidence_id' => $evId,
                        'control_id' => $controlId,
                    ]);
                }
                $msg = 'Control linked successfully.';
                $msgType = 'success';
            }
        } elseif ($action === 'unlink_control') {
            $evId = (int)($_POST['evidence_id'] ?? 0);
            $controlId = (int)($_POST['control_id'] ?? 0);
            if ($evId > 0 && $controlId > 0) {
                $db->delete('grc_evidence_control_map',
                    'evidence_id = :eid AND control_id = :cid',
                    [':eid' => $evId, ':cid' => $controlId]
                );
                $msg = 'Control unlinked.';
                $msgType = 'success';
            }
        } elseif ($action === 'delete') {
            $evId = (int)($_POST['evidence_id'] ?? 0);
            if ($evId > 0) {
                // Remove all linkages first
                $db->query('DELETE FROM grc_evidence_control_map WHERE evidence_id = :eid', [':eid' => $evId]);
                $db->query('DELETE FROM grc_assessment_evidence WHERE evidence_id = :eid', [':eid' => $evId]);
                $db->query('DELETE FROM grc_evidence WHERE id = :eid', [':eid' => $evId]);
                $_SESSION['flash_msg'] = 'Evidence deleted successfully.';
                $_SESSION['flash_type'] = 'success';
                header('Location: grc-evidence.php');
                exit;
            }
        }
        $csrfToken = $security->generateCSRFToken();
    }
}

// Generate CSRF token for GET requests (POST handler above generates its own after validation)
if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Filters
$where = ['1=1'];
$params = [];
if (!empty($_GET['type'])) {
    $where[] = 'e.evidence_type = :type';
    $params[':type'] = $_GET['type'];
}
if (!empty($_GET['status'])) {
    $where[] = 'e.status = :status';
    $params[':status'] = $_GET['status'];
}
if (!empty($_GET['expiry'])) {
    if ($_GET['expiry'] === 'expired') {
        $where[] = 'e.valid_until IS NOT NULL AND e.valid_until < NOW()';
    } elseif ($_GET['expiry'] === 'expiring_30d') {
        $where[] = 'e.valid_until IS NOT NULL AND e.valid_until BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)';
    } elseif ($_GET['expiry'] === 'valid') {
        $where[] = '(e.valid_until IS NULL OR e.valid_until > NOW())';
    }
}

$evidence = $db->fetchAll(
    'SELECT e.*, u.full_name as created_by_name,
            COUNT(DISTINCT ecm.control_id) as linked_controls
     FROM grc_evidence e
     LEFT JOIN users u ON u.id = e.collected_by
     LEFT JOIN grc_evidence_control_map ecm ON ecm.evidence_id = e.id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY e.id
     ORDER BY e.collected_at DESC',
    $params
);

// Detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$evidenceDetail = null;
if ($viewId > 0) {
    $evidenceDetail = $db->fetchOne('SELECT e.*, u.full_name as created_by_name FROM grc_evidence e LEFT JOIN users u ON u.id = e.collected_by WHERE e.id = :id', [':id' => $viewId]);
    if ($evidenceDetail) {
        $evidenceDetail['controls'] = $db->fetchAll(
            'SELECT ic.id, ic.control_ref, ic.title, ic.implementation_status
             FROM grc_internal_controls ic
             JOIN grc_evidence_control_map ecm ON ecm.control_id = ic.id
             WHERE ecm.evidence_id = :eid
             ORDER BY ic.control_ref',
            [':eid' => $viewId]
        );
    }
}

// Get controls for linking
$allControls = $db->fetchAll('SELECT id, control_ref, title FROM grc_internal_controls WHERE is_active = 1 ORDER BY control_ref');

// Flash messages from redirects (e.g., delete)
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$currentPage = 'grc_evidence';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title>Evidence Library - GRC Compliance Engine</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="app/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <link rel="stylesheet" href="app/css/mobile-responsive.css">
    <link rel="stylesheet" href="app/css/interactive.css?v=3">
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
        a { text-decoration: none; }
        a:hover { text-decoration: none; }
        body { margin: 0; font-family: 'Roboto', sans-serif; }
        .page { display: flex; flex-direction: column; min-height: 100vh; }
        .page-header { display: none !important; }
        .top-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 20px; display: flex; justify-content: flex-end; align-items: center; flex-shrink: 0; }
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-menu a { color: #333; text-decoration: none; padding: 8px 15px; border-radius: 4px; background: rgba(255,101,67,0.1); transition: background 0.2s; font-size: 14px; }
        .user-menu a:hover { background: rgba(255,101,67,0.2); }
        .main-layout { display: flex; flex: 1 1 auto; min-height: 0; }
        .sidebar { width: var(--sidebar-width) !important; min-width: var(--sidebar-width) !important; max-width: var(--sidebar-width) !important; background: var(--nav-fill-color) !important; padding: 0; flex-shrink: 0; display: flex; flex-direction: column; }
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
        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        .section-header { font-size: 18px; font-weight: 600; color: #333; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; }
        .section-header:first-of-type { margin-top: 0; }

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .filter-bar select { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-danger { background: #dc3545; color: #fff; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize; }
        .status-current { background: #d1fae5; color: #065f46; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .status-expiring { background: #fef3c7; color: #92400e; }
        .status-archived { background: #f3f4f6; color: #6b7280; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        @media (max-width: 900px) {
            .grc-form .form-row { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
        }
        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <span style="margin-right:auto;font-size:14px;color:#333;">Welcome, <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php">Admin</a><?php endif; ?>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

        <main class="main-content">
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;">Evidence Library</h1>
            <p style="color:#6b7280;margin:0 0 30px;">Upload, manage, and link evidence to internal controls</p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($evidenceDetail): ?>
            <!-- Evidence Detail -->
            <a href="grc-evidence.php" class="back-link">&larr; Back to evidence library</a>

            <div class="detail-card">
                <h3><?php echo e($evidenceDetail['evidence_ref']); ?> - <?php echo e($evidenceDetail['title']); ?></h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="label">Type</div>
                        <div class="value"><?php echo e(ucfirst($evidenceDetail['evidence_type'] ?? '-')); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Status</div>
                        <div class="value">
                            <?php
                            $evStatus = $evidenceDetail['status'] ?? 'current';
                            $isExpired = !empty($evidenceDetail['valid_until']) && strtotime($evidenceDetail['valid_until']) < time();
                            $isExpiring = !empty($evidenceDetail['valid_until']) && !$isExpired && strtotime($evidenceDetail['valid_until']) < strtotime('+30 days');
                            if ($isExpired) $evStatus = 'expired';
                            elseif ($isExpiring) $evStatus = 'expiring';
                            ?>
                            <span class="status-badge status-<?php echo e($evStatus); ?>"><?php echo e(ucfirst($evStatus)); ?></span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Collection Method</div>
                        <div class="value"><?php echo e(ucfirst($evidenceDetail['collection_method'] ?? '-')); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Collected At</div>
                        <div class="value"><?php echo $evidenceDetail['collected_at'] ? e(date('M j, Y g:i A', strtotime($evidenceDetail['collected_at']))) : '-'; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Valid Until</div>
                        <div class="value"><?php echo $evidenceDetail['valid_until'] ? e(date('M j, Y', strtotime($evidenceDetail['valid_until']))) : 'No expiry'; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Uploaded By</div>
                        <div class="value"><?php echo e($evidenceDetail['created_by_name'] ?? '-'); ?></div>
                    </div>
                </div>
                <?php if (!empty($evidenceDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;margin:0 0 16px;"><?php echo e($evidenceDetail['description']); ?></p>
                <?php endif; ?>

                <?php if (!empty($evidenceDetail['external_url'])): ?>
                <div style="margin-top:12px;padding:12px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:18px;">&#128279;</span>
                    <div style="flex:1;min-width:0;">
                        <a href="<?php echo e($evidenceDetail['external_url']); ?>" target="_blank" rel="noopener" style="font-size:13px;font-weight:500;color:#1e40af;word-break:break-all;"><?php echo e($evidenceDetail['external_url']); ?></a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($evidenceDetail['file_name'])): ?>
                <div style="margin-top:12px;padding:12px 16px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <strong style="font-size:13px;"><?php echo e($evidenceDetail['file_name']); ?></strong>
                        <span style="font-size:12px;color:#6b7280;margin-left:8px;"><?php echo $evidenceDetail['file_size'] ? e(number_format($evidenceDetail['file_size'] / 1024, 1) . ' KB') : ''; ?></span>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <a href="grc-evidence.php?download=<?php echo (int)$evidenceDetail['id']; ?>" class="btn btn-sm btn-outline">Download</a>
                        <?php if (!$readOnly): ?>
                        <form method="post" style="display:inline;" data-confirm="Delete this evidence permanently? This removes all control and assessment links.">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="evidence_id" value="<?php echo (int)$evidenceDetail['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Linked Controls -->
            <div class="detail-card">
                <h3>Linked Controls</h3>
                <?php if (!empty($evidenceDetail['controls'])): ?>
                <table class="grc-table">
                    <thead><tr><th>Reference</th><th>Title</th><th>Status</th><?php if (!$readOnly): ?><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($evidenceDetail['controls'] as $ctrl): ?>
                    <tr>
                        <td><a href="grc-controls.php?view=<?php echo (int)$ctrl['id']; ?>" style="color:#3b82f6;text-decoration:none;"><?php echo e($ctrl['control_ref']); ?></a></td>
                        <td><?php echo e($ctrl['title']); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $ctrl['implementation_status']))); ?></td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <form method="post" style="display:inline;" data-confirm="Unlink this control?">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="unlink_control">
                                <input type="hidden" name="evidence_id" value="<?php echo (int)$evidenceDetail['id']; ?>">
                                <input type="hidden" name="control_id" value="<?php echo (int)$ctrl['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Unlink</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;">No controls linked.</p>
                <?php endif; ?>

                <?php if (!$readOnly): ?>
                <form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="link_control">
                    <input type="hidden" name="evidence_id" value="<?php echo (int)$evidenceDetail['id']; ?>">
                    <div style="flex:1;">
                        <label style="font-size:12px;color:#6b7280;">Link Control</label>
                        <select name="control_id" required style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                            <option value="">-- Select Control --</option>
                            <?php foreach ($allControls as $ac): ?>
                            <option value="<?php echo (int)$ac['id']; ?>"><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Link</button>
                </form>
                <?php endif; ?>
            </div>

            <?php elseif (isset($_GET['new']) && !$readOnly): ?>
            <!-- Upload Form -->
            <a href="grc-evidence.php" class="back-link">&larr; Back to evidence library</a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;">Upload Evidence</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="upload">

                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required placeholder="Evidence title">

                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Describe this evidence"></textarea>

                    <div class="form-row">
                        <div>
                            <label for="evidence_type">Evidence Type</label>
                            <select id="evidence_type" name="evidence_type">
                                <option value="document">Document</option>
                                <option value="screenshot">Screenshot</option>
                                <option value="api_log">API Log</option>
                                <option value="report">Report</option>
                                <option value="configuration">Configuration</option>
                                <option value="certificate">Certificate</option>
                                <option value="policy">Policy</option>
                                <option value="automated">Automated</option>
                                <option value="manual_upload">Manual Upload</option>
                            </select>
                        </div>
                        <div>
                            <label for="valid_until">Valid Until (optional)</label>
                            <input type="date" id="valid_until" name="valid_until">
                        </div>
                    </div>

                    <label for="external_url">External URL (optional - use instead of or in addition to file upload)</label>
                    <input type="url" id="external_url" name="external_url" placeholder="https://..." style="margin-bottom:12px;">

                    <label for="evidence_file">Evidence File (encrypted at rest)</label>
                    <input type="file" id="evidence_file" name="evidence_file" style="padding:6px;">

                    <label>Link to Controls (optional)</label>
                    <div style="border:1px solid #d1d5db;border-radius:6px;max-height:180px;overflow-y:auto;padding:8px 12px;background:#fff;">
                        <?php if (empty($allControls)): ?>
                        <span style="color:#6b7280;font-size:13px;">No controls available</span>
                        <?php else: ?>
                        <?php foreach ($allControls as $ac): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" name="control_ids[]" value="<?php echo (int)$ac['id']; ?>" style="margin:0;">
                            <span><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <small style="color:#6b7280;font-size:11px;">Select or deselect controls to link to this evidence</small>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn btn-primary">Upload Evidence</button>
                        <a href="grc-evidence.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <!-- Evidence List -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <form method="get" class="filter-bar">
                    <div>
                        <label>Type</label>
                        <select name="type" class="auto-submit">
                            <option value="">All Types</option>
                            <?php foreach (['document', 'screenshot', 'api_log', 'report', 'configuration', 'certificate', 'policy', 'automated', 'manual_upload'] as $t): ?>
                            <option value="<?php echo e($t); ?>" <?php echo ($_GET['type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo e(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status" class="auto-submit">
                            <option value="">All Statuses</option>
                            <option value="current" <?php echo ($_GET['status'] ?? '') === 'current' ? 'selected' : ''; ?>>Current</option>
                            <option value="expired" <?php echo ($_GET['status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="superseded" <?php echo ($_GET['status'] ?? '') === 'superseded' ? 'selected' : ''; ?>>Superseded</option>
                            <option value="draft" <?php echo ($_GET['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        </select>
                    </div>
                    <div>
                        <label>Expiry</label>
                        <select name="expiry" class="auto-submit">
                            <option value="">All</option>
                            <option value="expired" <?php echo ($_GET['expiry'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="expiring_30d" <?php echo ($_GET['expiry'] ?? '') === 'expiring_30d' ? 'selected' : ''; ?>>Expiring in 30 Days</option>
                            <option value="valid" <?php echo ($_GET['expiry'] ?? '') === 'valid' ? 'selected' : ''; ?>>Valid</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;">Filter</button>
                </form>
                <?php if (!$readOnly): ?>
                <a href="grc-evidence.php?new=1" class="btn btn-primary">+ Upload Evidence</a>
                <?php endif; ?>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Valid Until</th>
                        <th>Controls</th>
                        <th>Collected</th>
                        <?php if (!$readOnly): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($evidence)): ?>
                    <tr><td colspan="<?php echo $readOnly ? 7 : 8; ?>" style="text-align:center;color:#6b7280;padding:30px;">No evidence found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($evidence as $ev):
                        $isExp = !empty($ev['valid_until']) && strtotime($ev['valid_until']) < time();
                        $isExpSoon = !empty($ev['valid_until']) && !$isExp && strtotime($ev['valid_until']) < strtotime('+30 days');
                        $statusClass = $isExp ? 'expired' : ($isExpSoon ? 'expiring' : ($ev['status'] ?? 'current'));
                        $statusText = $isExp ? 'Expired' : ($isExpSoon ? 'Expiring Soon' : ucfirst($ev['status'] ?? 'current'));
                    ?>
                    <tr>
                        <td><a href="grc-evidence.php?view=<?php echo (int)$ev['id']; ?>" style="color:#3b82f6;font-weight:500;"><?php echo e($ev['evidence_ref']); ?></a></td>
                        <td>
                            <?php echo e($ev['title']); ?>
                            <?php if (!empty($ev['external_url'])): ?>
                            <br><a href="<?php echo e($ev['external_url']); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#6b7280;">&#128279; <?php echo e(strlen($ev['external_url']) > 50 ? substr($ev['external_url'], 0, 50) . '...' : $ev['external_url']); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(ucfirst($ev['evidence_type'] ?? '-')); ?></td>
                        <td><span class="status-badge status-<?php echo e($statusClass); ?>"><?php echo e($statusText); ?></span></td>
                        <td><?php echo $ev['valid_until'] ? e(date('M j, Y', strtotime($ev['valid_until']))) : '-'; ?></td>
                        <td style="text-align:center;"><?php echo (int)$ev['linked_controls']; ?></td>
                        <td><?php echo $ev['collected_at'] ? e(date('M j, Y', strtotime($ev['collected_at']))) : '-'; ?></td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <form method="post" style="display:inline;" data-confirm="Delete evidence &quot;<?php echo e($ev['evidence_ref']); ?>&quot;? This removes all control and assessment links and cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="evidence_id" value="<?php echo (int)$ev['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </main>
    </div>
    <!-- Footer -->
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

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    // Auto-submit filter dropdowns on change
    document.querySelectorAll('.auto-submit').forEach(function(sel) {
        sel.addEventListener('change', function() { this.form.submit(); });
    });

    // Confirm dialogs for forms with data-confirm
    document.addEventListener('submit', function(e) {
        var form = e.target.closest('form[data-confirm]');
        if (form) {
            if (!confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });
})();
</script>
<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
