<?php
/**
 * GRC Unified Compliance Engine - Internal Controls
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Lists internal controls with filters (status, type, framework), supports
 * create/edit with autosave, maps controls to requirements via multi-select,
 * and shows evidence count and framework coverage per control.
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
    die(t('grc-controls.access_denied'));
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();
$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc-controls.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // SECURITY (IDOR): owner_user_id must reference a user the owner dropdown
        // actually offers (active administrator/cyber_grc members). The dropdown is
        // rendered later in the page, so validate the submitted id here before it
        // reaches createControl()/updateControl(); an unoffered id is dropped to null
        // and the save is rejected with an error.
        $ownerUserId = null;
        $ownerValid = true;
        if (!empty($_POST['owner_user_id'])) {
            $candidateOwner = (int)$_POST['owner_user_id'];
            $ownerOk = $db->fetchOne(
                "SELECT 1 FROM users u
                   INNER JOIN user_acl_groups uag ON uag.user_id = u.id
                   INNER JOIN acl_groups ag ON ag.id = uag.group_id
                  WHERE u.id = :id AND u.is_active = 1
                    AND ag.group_name IN ('administrator', 'cyber_grc')",
                [':id' => $candidateOwner]
            );
            if ($ownerOk) {
                $ownerUserId = $candidateOwner;
            } else {
                $ownerValid = false;
                $msg = t('grc-controls.invalid_owner');
                $msgType = 'danger';
            }
        }

        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            if ($title !== '' && $ownerValid) {
                $newId = $grc->createControl([
                    'title' => $title,
                    'description' => trim($_POST['description'] ?? ''),
                    'control_type' => $_POST['control_type'] ?? 'preventive',
                    'control_category' => $_POST['control_category'] ?? 'technical',
                    'implementation_status' => $_POST['implementation_status'] ?? 'planned',
                    'owner_user_id' => $ownerUserId,
                    'frequency' => $_POST['frequency'] ?? 'ad_hoc',
                    'risk_level' => $_POST['risk_level'] ?? 'medium',
                    'notes' => trim($_POST['notes'] ?? ''),
                ], (int)$user['id']);
                $msg = t('grc-controls.control_created');
                $msgType = 'success';
            } else {
                $msg = t('grc-controls.title_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'update') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            if ($controlId > 0 && $ownerValid) {
                $grc->updateControl($controlId, [
                    'title' => trim($_POST['title'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'control_type' => $_POST['control_type'] ?? 'preventive',
                    'control_category' => $_POST['control_category'] ?? 'technical',
                    'implementation_status' => $_POST['implementation_status'] ?? 'planned',
                    'owner_user_id' => $ownerUserId,
                    'frequency' => $_POST['frequency'] ?? 'ad_hoc',
                    'risk_level' => $_POST['risk_level'] ?? 'medium',
                    'notes' => trim($_POST['notes'] ?? ''),
                ]);
                $msg = t('grc-controls.control_updated');
                $msgType = 'success';
            }
        } elseif ($action === 'map') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $requirementId = (int)($_POST['requirement_id'] ?? 0);
            $coverage = $_POST['coverage'] ?? 'full';
            if ($controlId > 0 && $requirementId > 0) {
                $grc->mapControlToRequirement($controlId, $requirementId, ['coverage' => $coverage], (int)$user['id']);
                $msg = t('grc-controls.mapping_added');
                $msgType = 'success';
            }
        } elseif ($action === 'bulk_map') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $reqIds = $_POST['requirement_ids'] ?? '';
            $coverages = $_POST['coverages'] ?? '';
            if ($controlId > 0 && $reqIds !== '') {
                $ids = array_filter(array_map('intval', explode(',', $reqIds)));
                $covs = explode(',', $coverages);
                $added = 0;
                foreach ($ids as $idx => $rid) {
                    $cov = $covs[$idx] ?? 'full';
                    if ($grc->mapControlToRequirement($controlId, $rid, ['coverage' => $cov], (int)$user['id'])) {
                        $added++;
                    }
                }
                $msg = $added > 0 ? $added . t('grc-controls.mappings_added_suffix') : t('grc-controls.all_already_mapped');
                $msgType = $added > 0 ? 'success' : 'info';
            }
        } elseif ($action === 'update_coverage') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $requirementId = (int)($_POST['requirement_id'] ?? 0);
            $coverage = $_POST['coverage'] ?? 'full';
            if ($controlId > 0 && $requirementId > 0 && in_array($coverage, ['full', 'partial'], true)) {
                $db->execute(
                    'UPDATE grc_control_requirement_map SET coverage = :cov WHERE control_id = :cid AND requirement_id = :rid',
                    [':cov' => $coverage, ':cid' => $controlId, ':rid' => $requirementId]
                );
                $msg = t('grc-controls.coverage_updated');
                $msgType = 'success';
            }
        } elseif ($action === 'unmap') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $requirementId = (int)($_POST['requirement_id'] ?? 0);
            if ($controlId > 0 && $requirementId > 0) {
                $grc->unmapControlFromRequirement($controlId, $requirementId);
                $msg = t('grc-controls.mapping_removed');
                $msgType = 'success';
            }
        } elseif ($action === 'link_evidence') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $evidenceId = (int)($_POST['evidence_id'] ?? 0);
            if ($controlId > 0 && $evidenceId > 0) {
                $existing = $db->fetchOne(
                    'SELECT id FROM grc_evidence_control_map WHERE evidence_id = :eid AND control_id = :cid',
                    [':eid' => $evidenceId, ':cid' => $controlId]
                );
                if (!$existing) {
                    $db->insert('grc_evidence_control_map', [
                        'evidence_id' => $evidenceId,
                        'control_id' => $controlId,
                        'linked_by' => (int)$user['id'],
                    ]);
                    $msg = t('grc-controls.evidence_linked');
                } else {
                    $msg = t('grc-controls.evidence_already_linked');
                }
                $msgType = 'success';
            }
        } elseif ($action === 'unlink_evidence') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $evidenceId = (int)($_POST['evidence_id'] ?? 0);
            if ($controlId > 0 && $evidenceId > 0) {
                $db->query(
                    'DELETE FROM grc_evidence_control_map WHERE evidence_id = :eid AND control_id = :cid',
                    [':eid' => $evidenceId, ':cid' => $controlId]
                );
                $msg = t('grc-controls.evidence_unlinked');
                $msgType = 'success';
            }
        } elseif ($action === 'upload_evidence') {
            $controlId = (int)($_POST['control_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $evidenceType = $_POST['evidence_type'] ?? 'document';
            $description = trim($_POST['evidence_description'] ?? '');
            $validUntil = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;

            if ($controlId > 0 && $title !== '') {
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

                $externalUrl = trim($_POST['external_url'] ?? '');
                if ($externalUrl !== '' && preg_match('#^https?://#i', $externalUrl)) {
                    $insertData['external_url'] = $externalUrl;
                }

                if (!empty($_FILES['evidence_file']['tmp_name']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
                    require_once __DIR__ . '/includes/classes/Encryption.php';
                    $encryption = new Encryption();
                    $fileData = file_get_contents($_FILES['evidence_file']['tmp_name']);
                    $insertData['encrypted_data'] = $encryption->encryptRaw($fileData);
                    $insertData['file_name'] = $_FILES['evidence_file']['name'];
                    $insertData['file_mime'] = $_FILES['evidence_file']['type'];
                    $insertData['file_size'] = $_FILES['evidence_file']['size'];
                }

                $db->insert('grc_evidence', $insertData);
                $newEvId = (int)$db->lastInsertId();

                $db->insert('grc_evidence_control_map', [
                    'evidence_id' => $newEvId,
                    'control_id' => $controlId,
                    'linked_by' => (int)$user['id'],
                ]);

                $msg = t('grc-controls.evidence_created_linked');
                $msgType = 'success';
            } else {
                $msg = t('grc-controls.evidence_title_required');
                $msgType = 'danger';
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
$filters = [];
if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
if (!empty($_GET['type'])) $filters['type'] = $_GET['type'];
if (!empty($_GET['framework_id'])) $filters['framework_id'] = (int)$_GET['framework_id'];
if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

$controls = $grc->getControls($filters);
$frameworks = $grc->getFrameworks();

// Detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$controlDetail = null;
if ($viewId > 0) {
    $controlDetail = $grc->getControl($viewId);
} elseif ($editId > 0 && !$readOnly) {
    $controlDetail = $grc->getControl($editId);
}

// Get available evidence for linking (not already linked to this control)
$availableEvidence = [];
if ($viewId > 0 && $controlDetail) {
    $availableEvidence = $db->fetchAll(
        'SELECT e.id, e.evidence_ref, e.title, e.evidence_type, e.status
         FROM grc_evidence e
         WHERE e.id NOT IN (
             SELECT ecm.evidence_id FROM grc_evidence_control_map ecm WHERE ecm.control_id = :cid
         )
         ORDER BY e.evidence_ref',
        [':cid' => $viewId]
    );
}

// Get all requirements for mapping dropdown
$allRequirements = [];
foreach ($frameworks as $fw) {
    $reqs = $grc->getAllRequirements((int)$fw['id']);
    foreach ($reqs as $r) {
        $allRequirements[] = [
            'id' => $r['id'],
            'label' => $fw['code'] . ' - ' . $r['requirement_ref'] . ': ' . $r['title'],
        ];
    }
}

// Get questions with their strong/exact framework mappings for typeahead
$questionMappings = $db->fetchAll(
    "SELECT q.id as question_id, q.question_ref, q.question_text, q.domain_id,
            d.domain_code, f.code as framework_code, r.id as requirement_id,
            r.requirement_ref, r.title as requirement_title, qfm.mapping_strength
     FROM grc_unified_questions q
     JOIN grc_security_domains d ON d.id = q.domain_id
     JOIN grc_question_framework_map qfm ON qfm.question_id = q.id
     JOIN grc_framework_requirements r ON r.id = qfm.requirement_id
     JOIN grc_frameworks f ON f.id = qfm.framework_id
     WHERE qfm.mapping_strength IN ('exact', 'strong')
     ORDER BY q.question_ref, f.code"
);
$questionData = [];
foreach ($questionMappings as $qm) {
    $qid = $qm['question_ref'];
    if (!isset($questionData[$qid])) {
        $questionData[$qid] = [
            'ref' => $qm['question_ref'],
            'text' => $qm['question_text'],
            'domain' => $qm['domain_code'],
            'reqs' => [],
        ];
    }
    $questionData[$qid]['reqs'][] = [
        'id' => (int)$qm['requirement_id'],
        'fw' => $qm['framework_code'],
        'ref' => $qm['requirement_ref'],
        'title' => $qm['requirement_title'],
        'strength' => $qm['mapping_strength'],
    ];
}
$questionDataList = array_values($questionData);

// Get users for owner dropdown
$users = $db->fetchAll('SELECT DISTINCT u.id, u.full_name FROM users u INNER JOIN user_acl_groups uag ON uag.user_id = u.id INNER JOIN acl_groups ag ON ag.id = uag.group_id WHERE u.is_active = 1 AND ag.group_name IN (\'administrator\', \'cyber_grc\') ORDER BY u.full_name');

$currentPage = 'grc_controls';
$showForm = isset($_GET['new']) || $editId > 0;
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-controls.page_title')); ?></title>
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
        .filter-bar select, .filter-bar input[type="text"] { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; min-width: 140px; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grc-form .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

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
        .status-implemented { background: #d1fae5; color: #065f46; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-planned { background: #fef3c7; color: #92400e; }
        .status-not_applicable { background: #f3f4f6; color: #6b7280; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        .autosave-status { font-size: 12px; color: #28a745; margin-left: 12px; }

        @media (max-width: 900px) {
            .grc-form .form-row, .grc-form .form-row-3 { grid-template-columns: 1fr; }
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
        <span style="margin-right:auto;font-size:14px;color:#333;"><?php echo e(t('chrome.welcome')); ?> <?php echo e($user['full_name']); ?></span>
        <div class="user-menu">
            <?php if ($isAdmin): ?><a href="admin.php"><?php echo e(t('chrome.admin')); ?></a><?php endif; ?>
            <a href="profile.php"><?php echo e(t('chrome.profile')); ?></a>
            <a href="logout.php"><?php echo e(t('chrome.logout')); ?></a>
        </div>
    </div>

    <div class="main-layout">
        <?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

        <main class="main-content">
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-controls.internal_controls')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc-controls.intro')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($controlDetail && $viewId > 0): ?>
            <!-- Control Detail View -->
            <a href="grc-controls.php" class="back-link">&larr; <?php echo e(t('grc-controls.back_to_controls')); ?></a>

            <div class="detail-card">
                <h3><?php echo e($controlDetail['control_ref']); ?> - <?php echo e($controlDetail['title']); ?></h3>
                <div class="detail-grid">
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.status')); ?></div><div class="value"><span class="status-badge status-<?php echo e($controlDetail['implementation_status']); ?>"><?php echo e(str_replace('_', ' ', $controlDetail['implementation_status'])); ?></span></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.type')); ?></div><div class="value"><?php echo e(ucfirst($controlDetail['control_type'] ?? '-')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.category')); ?></div><div class="value"><?php echo e(ucfirst($controlDetail['control_category'] ?? '-')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.owner')); ?></div><div class="value"><?php echo e($controlDetail['owner_name'] ?? t('grc-controls.unassigned')); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.frequency')); ?></div><div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $controlDetail['frequency'] ?? '-'))); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-controls.risk_level')); ?></div><div class="value"><?php echo e(ucfirst($controlDetail['risk_level'] ?? '-')); ?></div></div>
                </div>
                <?php if (!empty($controlDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;margin:0;"><?php echo e($controlDetail['description']); ?></p>
                <?php endif; ?>
                <?php if (!$readOnly): ?>
                <div style="margin-top:16px;">
                    <a href="grc-controls.php?edit=<?php echo (int)$controlDetail['id']; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc-controls.edit_control')); ?></a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Mapped Requirements -->
            <div class="detail-card">
                <h3><?php echo e(t('grc-controls.mapped_requirements')); ?> (<?php echo count($controlDetail['requirements']); ?>)</h3>
                <?php if (!empty($controlDetail['requirements'])): ?>
                <table class="grc-table">
                    <thead><tr><th><?php echo e(t('grc-controls.framework')); ?></th><th><?php echo e(t('grc-controls.requirement')); ?></th><th><?php echo e(t('grc-controls.coverage')); ?></th><?php if (!$readOnly): ?><th><?php echo e(t('grc-controls.actions')); ?></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($controlDetail['requirements'] as $req): ?>
                    <tr>
                        <td><?php echo e($req['framework_code']); ?></td>
                        <td><?php echo e($req['requirement_ref']); ?> - <?php echo e($req['requirement_title']); ?></td>
                        <td>
                            <?php if (!$readOnly): ?>
                            <form method="post" style="display:inline;" class="coverage-form">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_coverage">
                                <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                                <input type="hidden" name="requirement_id" value="<?php echo (int)$req['requirement_id']; ?>">
                                <select name="coverage" onchange="this.form.submit()" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;cursor:pointer;background:#fff;">
                                    <option value="full" <?php echo ($req['coverage'] ?? 'full') === 'full' ? 'selected' : ''; ?>><?php echo e(t('grc-controls.full')); ?></option>
                                    <option value="partial" <?php echo ($req['coverage'] ?? '') === 'partial' ? 'selected' : ''; ?>><?php echo e(t('grc-controls.partial')); ?></option>
                                </select>
                            </form>
                            <?php else: ?>
                            <?php echo e(ucfirst($req['coverage'] ?? 'full')); ?>
                            <?php endif; ?>
                        </td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="unmap">
                                <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                                <input type="hidden" name="requirement_id" value="<?php echo (int)$req['requirement_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(<?php echo e(json_encode(t('grc-controls.confirm_remove_mapping'))); ?>)"><?php echo e(t('grc-controls.unmap')); ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-controls.no_requirements_mapped')); ?></p>
                <?php endif; ?>

                <?php if (!$readOnly): ?>
                <!-- Typeahead: search by requirement OR question -->
                <div style="margin-top:12px;">
                    <label style="font-size:12px;color:#6b7280;"><?php echo e(t('grc-controls.add_mapping_label')); ?></label>
                    <div style="position:relative;">
                        <input type="text" id="req_map_search" autocomplete="off" placeholder="<?php echo e(t('grc-controls.search_req_or_q_ph')); ?>" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box;">
                        <div id="req_map_dropdown" style="display:none;position:absolute;z-index:1000;top:100%;left:0;right:0;max-height:300px;overflow-y:auto;background:#fff;border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>
                    </div>
                </div>
                <!-- Preview panel for question-based mapping -->
                <div id="bulk_map_preview" style="display:none;margin-top:12px;border:1px solid #d1d5db;border-radius:8px;padding:16px;background:#f9fafb;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <strong id="bulk_map_title" style="font-size:13px;color:#333;"></strong>
                        <button type="button" id="bulk_map_cancel" class="btn btn-sm btn-outline" style="font-size:11px;"><?php echo e(t('grc-controls.cancel')); ?></button>
                    </div>
                    <table class="grc-table" style="margin-bottom:12px;">
                        <thead><tr><th style="width:30px;"><input type="checkbox" id="bulk_check_all" checked></th><th><?php echo e(t('grc-controls.framework')); ?></th><th><?php echo e(t('grc-controls.requirement')); ?></th><th><?php echo e(t('grc-controls.strength')); ?></th><th><?php echo e(t('grc-controls.coverage')); ?></th></tr></thead>
                        <tbody id="bulk_map_body"></tbody>
                    </table>
                    <form method="post" id="bulk_map_form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="bulk_map">
                        <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                        <input type="hidden" name="requirement_ids" id="bulk_req_ids">
                        <input type="hidden" name="coverages" id="bulk_coverages">
                        <button type="submit" class="btn btn-primary" id="bulk_map_submit"><?php echo e(t('grc-controls.map_selected')); ?></button>
                    </form>
                </div>
                <!-- Single requirement mapping form (hidden, used when a single requirement is picked) -->
                <form method="post" id="single_map_form" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="map">
                    <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                    <input type="hidden" name="requirement_id" id="single_req_id">
                    <input type="hidden" name="coverage" id="single_coverage" value="full">
                </form>
                <?php endif; ?>
            </div>

            <!-- Linked Evidence -->
            <div class="detail-card">
                <h3><?php echo e(t('grc-controls.linked_evidence')); ?> (<?php echo count($controlDetail['evidence']); ?>)</h3>
                <?php if (!empty($controlDetail['evidence'])): ?>
                <table class="grc-table">
                    <thead><tr><th><?php echo e(t('grc-controls.reference')); ?></th><th><?php echo e(t('grc-controls.title')); ?></th><th><?php echo e(t('grc-controls.type')); ?></th><th><?php echo e(t('grc-controls.status')); ?></th><th><?php echo e(t('grc-controls.valid_until')); ?></th><?php if (!$readOnly): ?><th><?php echo e(t('grc-controls.actions')); ?></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($controlDetail['evidence'] as $ev): ?>
                    <tr>
                        <td><a href="grc-evidence.php?view=<?php echo (int)$ev['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($ev['evidence_ref']); ?></a></td>
                        <td><?php echo e($ev['title']); ?></td>
                        <td><?php echo e(ucfirst($ev['evidence_type'] ?? '-')); ?></td>
                        <td><?php echo e(ucfirst($ev['status'] ?? '-')); ?></td>
                        <td><?php echo $ev['valid_until'] ? e(date('M j, Y', strtotime($ev['valid_until']))) : '-'; ?></td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="unlink_evidence">
                                <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                                <input type="hidden" name="evidence_id" value="<?php echo (int)$ev['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(<?php echo e(json_encode(t('grc-controls.confirm_unlink_evidence'))); ?>)"><?php echo e(t('grc-controls.unlink')); ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-controls.no_evidence_linked')); ?></p>
                <?php endif; ?>

                <?php if (!$readOnly): ?>
                <!-- Link Existing Evidence -->
                <?php if (!empty($availableEvidence)): ?>
                <form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="link_evidence">
                    <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                    <div style="flex:1;">
                        <label style="font-size:12px;color:#6b7280;"><?php echo e(t('grc-controls.link_existing_evidence')); ?></label>
                        <select name="evidence_id" required style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                            <option value=""><?php echo e(t('grc-controls.select_evidence')); ?></option>
                            <?php foreach ($availableEvidence as $ae): ?>
                            <option value="<?php echo (int)$ae['id']; ?>"><?php echo e($ae['evidence_ref'] . ' - ' . $ae['title'] . ' (' . ucfirst($ae['evidence_type']) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('grc-controls.link')); ?></button>
                </form>
                <?php endif; ?>

                <!-- Create New Evidence -->
                <div style="margin-top:16px;border-top:1px solid #e5e7eb;padding-top:16px;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('newEvidenceForm').style.display = document.getElementById('newEvidenceForm').style.display === 'none' ? 'block' : 'none';">+ <?php echo e(t('grc-controls.new_evidence')); ?></button>
                    <form method="post" enctype="multipart/form-data" id="newEvidenceForm" style="display:none;margin-top:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="action" value="upload_evidence">
                        <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">

                        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div>
                                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.title_required_label')); ?></label>
                                <input type="text" name="title" required placeholder="<?php echo e(t('grc-controls.evidence_title_ph')); ?>" style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.type')); ?></label>
                                <select name="evidence_type" style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                                    <option value="document"><?php echo e(t('grc-controls.ev_document')); ?></option>
                                    <option value="screenshot"><?php echo e(t('grc-controls.ev_screenshot')); ?></option>
                                    <option value="report"><?php echo e(t('grc-controls.ev_report')); ?></option>
                                    <option value="configuration"><?php echo e(t('grc-controls.ev_configuration')); ?></option>
                                    <option value="certificate"><?php echo e(t('grc-controls.ev_certificate')); ?></option>
                                    <option value="policy"><?php echo e(t('grc-controls.ev_policy')); ?></option>
                                    <option value="api_log"><?php echo e(t('grc-controls.ev_api_log')); ?></option>
                                    <option value="manual_upload"><?php echo e(t('grc-controls.ev_manual_upload')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:8px;">
                            <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.description')); ?></label>
                            <textarea name="evidence_description" placeholder="<?php echo e(t('grc-controls.optional_description_ph')); ?>" rows="2" style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>
                        </div>

                        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px;">
                            <div>
                                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.upload_file')); ?></label>
                                <input type="file" name="evidence_file" style="width:100%;font-size:13px;">
                            </div>
                            <div>
                                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.valid_until')); ?></label>
                                <input type="date" name="valid_until" style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                            </div>
                        </div>

                        <div style="margin-top:8px;">
                            <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;"><?php echo e(t('grc-controls.external_url_optional')); ?></label>
                            <input type="url" name="external_url" placeholder="https://..." style="width:100%;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        </div>

                        <div style="margin-top:12px;">
                            <button type="submit" class="btn btn-primary"><?php echo e(t('grc-controls.create_link_evidence')); ?></button>
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('newEvidenceForm').style.display='none';"><?php echo e(t('grc-controls.cancel')); ?></button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($showForm && !$readOnly): ?>
            <!-- Create/Edit Form -->
            <a href="grc-controls.php" class="back-link">&larr; <?php echo e(t('grc-controls.back_to_controls')); ?></a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo $editId > 0 ? e(t('grc-controls.edit_control')) : e(t('grc-controls.create_control')); ?><span class="autosave-status"></span></h3>
                <form method="post" class="grc-autosave-form" data-form-type="control_edit" data-form-id="<?php echo $editId > 0 ? (int)$controlDetail['id'] : 'new'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'create'; ?>">
                    <?php if ($editId > 0): ?>
                    <input type="hidden" name="control_id" value="<?php echo (int)$controlDetail['id']; ?>">
                    <?php endif; ?>

                    <label for="title"><?php echo e(t('grc-controls.title')); ?></label>
                    <input type="text" id="title" name="title" value="<?php echo e($controlDetail['title'] ?? ''); ?>" required placeholder="<?php echo e(t('grc-controls.control_title_ph')); ?>">

                    <label for="description"><?php echo e(t('grc-controls.description')); ?></label>
                    <textarea id="description" name="description" placeholder="<?php echo e(t('grc-controls.describe_control_ph')); ?>"><?php echo e($controlDetail['description'] ?? ''); ?></textarea>

                    <div class="form-row-3">
                        <div>
                            <label for="control_type"><?php echo e(t('grc-controls.type')); ?></label>
                            <select id="control_type" name="control_type">
                                <?php foreach (['preventive', 'detective', 'corrective', 'directive'] as $t): ?>
                                <option value="<?php echo e($t); ?>" <?php echo ($controlDetail['control_type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo e(ucfirst($t)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="control_category"><?php echo e(t('grc-controls.category')); ?></label>
                            <select id="control_category" name="control_category">
                                <?php foreach (['technical', 'administrative', 'physical'] as $c): ?>
                                <option value="<?php echo e($c); ?>" <?php echo ($controlDetail['control_category'] ?? '') === $c ? 'selected' : ''; ?>><?php echo e(ucfirst($c)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="implementation_status"><?php echo e(t('grc-controls.status')); ?></label>
                            <select id="implementation_status" name="implementation_status">
                                <?php foreach (['planned', 'in_progress', 'implemented', 'not_applicable'] as $s): ?>
                                <option value="<?php echo e($s); ?>" <?php echo ($controlDetail['implementation_status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div>
                            <label for="owner_user_id"><?php echo e(t('grc-controls.owner')); ?></label>
                            <select id="owner_user_id" name="owner_user_id">
                                <option value=""><?php echo e(t('grc-controls.select_owner')); ?></option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo ((int)($controlDetail['owner_user_id'] ?? 0)) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="frequency"><?php echo e(t('grc-controls.frequency')); ?></label>
                            <select id="frequency" name="frequency">
                                <?php foreach (['ad_hoc', 'daily', 'weekly', 'monthly', 'quarterly', 'annually', 'continuous'] as $f): ?>
                                <option value="<?php echo e($f); ?>" <?php echo ($controlDetail['frequency'] ?? '') === $f ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $f))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="risk_level"><?php echo e(t('grc-controls.risk_level')); ?></label>
                            <select id="risk_level" name="risk_level">
                                <?php foreach (['low', 'medium', 'high', 'critical'] as $rl): ?>
                                <option value="<?php echo e($rl); ?>" <?php echo ($controlDetail['risk_level'] ?? '') === $rl ? 'selected' : ''; ?>><?php echo e(ucfirst($rl)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label for="notes"><?php echo e(t('grc-controls.notes')); ?></label>
                    <textarea id="notes" name="notes" placeholder="<?php echo e(t('grc-controls.additional_notes_ph')); ?>"><?php echo e($controlDetail['notes'] ?? ''); ?></textarea>

                    <button type="submit" class="btn btn-primary"><?php echo $editId > 0 ? e(t('grc-controls.update_control')) : e(t('grc-controls.create_control')); ?></button>
                    <a href="grc-controls.php" class="btn btn-outline"><?php echo e(t('grc-controls.cancel')); ?></a>
                </form>
            </div>

            <?php else: ?>
            <!-- Control List -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                <form method="get" class="filter-bar">
                    <div>
                        <label><?php echo e(t('grc-controls.status')); ?></label>
                        <select name="status" onchange="this.form.submit()">
                            <option value=""><?php echo e(t('grc-controls.all_statuses')); ?></option>
                            <?php foreach (['planned', 'in_progress', 'implemented', 'not_applicable'] as $s): ?>
                            <option value="<?php echo e($s); ?>" <?php echo ($filters['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $s))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php echo e(t('grc-controls.type')); ?></label>
                        <select name="type" onchange="this.form.submit()">
                            <option value=""><?php echo e(t('grc-controls.all_types')); ?></option>
                            <?php foreach (['preventive', 'detective', 'corrective', 'directive'] as $t): ?>
                            <option value="<?php echo e($t); ?>" <?php echo ($filters['type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo e(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php echo e(t('grc-controls.framework')); ?></label>
                        <select name="framework_id" onchange="this.form.submit()">
                            <option value=""><?php echo e(t('grc-controls.all_frameworks')); ?></option>
                            <?php foreach ($frameworks as $fw): ?>
                            <option value="<?php echo (int)$fw['id']; ?>" <?php echo ((int)($filters['framework_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>><?php echo e($fw['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php echo e(t('grc-controls.search')); ?></label>
                        <input type="text" name="search" value="<?php echo e($filters['search'] ?? ''); ?>" placeholder="<?php echo e(t('grc-controls.ref_or_title_ph')); ?>">
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;"><?php echo e(t('grc-controls.filter')); ?></button>
                </form>
                <?php if (!$readOnly): ?>
                <a href="grc-controls.php?new=1" class="btn btn-primary">+ <?php echo e(t('grc-controls.new_control')); ?></a>
                <?php endif; ?>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th><?php echo e(t('grc-controls.reference')); ?></th>
                        <th><?php echo e(t('grc-controls.title')); ?></th>
                        <th><?php echo e(t('grc-controls.status')); ?></th>
                        <th><?php echo e(t('grc-controls.type')); ?></th>
                        <th><?php echo e(t('grc-controls.owner')); ?></th>
                        <th><?php echo e(t('grc-controls.requirements')); ?></th>
                        <th><?php echo e(t('grc-controls.evidence')); ?></th>
                        <th><?php echo e(t('grc-controls.frameworks')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($controls)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#6b7280;padding:30px;"><?php echo e(t('grc-controls.no_controls_found')); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($controls as $c): ?>
                    <tr>
                        <td><a href="grc-controls.php?view=<?php echo (int)$c['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($c['control_ref']); ?></a></td>
                        <td><?php echo e($c['title']); ?></td>
                        <td><span class="status-badge status-<?php echo e($c['implementation_status']); ?>"><?php echo e(str_replace('_', ' ', $c['implementation_status'])); ?></span></td>
                        <td><?php echo e(ucfirst($c['control_type'] ?? '-')); ?></td>
                        <td><?php echo e($c['owner_name'] ?? '-'); ?></td>
                        <td style="text-align:center;"><?php echo (int)$c['mapped_requirements']; ?></td>
                        <td style="text-align:center;"><?php echo (int)$c['evidence_count']; ?></td>
                        <td style="font-size:12px;color:#6b7280;"><?php echo e($c['frameworks'] ?? '-'); ?></td>
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
                    <span><?php echo e(t('grc-controls.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Autosave every 30 seconds for forms with class .grc-autosave-form
var autosaveTimer;
document.querySelectorAll('.grc-autosave-form').forEach(function(form) {
    form.addEventListener('input', function() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(function() { grcAutosave(form); }, 5000);
    });
});
function grcAutosave(form) {
    var data = {};
    new FormData(form).forEach(function(v, k) { if (k !== 'csrf_token') data[k] = v; });
    fetch('/api/grc-autosave.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            csrf_token: document.querySelector('input[name=csrf_token]').value,
            form_type: form.dataset.formType || 'unknown',
            form_id: form.dataset.formId || null,
            draft_data: data
        })
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.csrf_token) document.querySelector('input[name=csrf_token]').value = d.csrf_token;
        var indicator = form.querySelector('.autosave-status');
        if (indicator) { indicator.textContent = <?php echo json_encode(t('grc-controls.js_draft_saved')); ?>; setTimeout(function() { indicator.textContent = ''; }, 3000); }
    });
}

// Typeahead data: requirements + questions
var reqMapData = <?php echo json_encode(array_map(function($ar) { return ['id' => (int)$ar['id'], 'l' => $ar['label'], 't' => 'req']; }, $allRequirements), JSON_HEX_TAG | JSON_HEX_APOS); ?>;
var questionMapData = <?php echo json_encode($questionDataList, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
(function() {
    var input = document.getElementById('req_map_search');
    var dropdown = document.getElementById('req_map_dropdown');
    var preview = document.getElementById('bulk_map_preview');
    var singleForm = document.getElementById('single_map_form');
    if (!input || !dropdown) return;

    var activeIdx = -1;
    var filtered = [];

    function render(items) {
        filtered = items;
        activeIdx = -1;
        if (!items.length) { dropdown.style.display = 'none'; return; }
        dropdown.innerHTML = '';
        items.slice(0, 40).forEach(function(item, i) {
            var div = document.createElement('div');
            div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f4f6;';
            if (item.t === 'q') {
                div.innerHTML = '<span style="display:inline-block;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:6px;">Q</span>' +
                    '<strong>' + escHtml(item.ref) + '</strong> — ' + escHtml(item.text.length > 90 ? item.text.substring(0, 90) + '...' : item.text) +
                    '<br><span style="font-size:11px;color:#6b7280;margin-left:28px;">' + item.reqs.length + <?php echo json_encode(t('grc-controls.js_mapped_requirement')); ?> + (item.reqs.length !== 1 ? 's' : '') + <?php echo json_encode(t('grc-controls.js_exact_strong_suffix')); ?> + '</span>';
            } else {
                div.innerHTML = '<span style="display:inline-block;background:#f0fdf4;color:#166534;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:6px;">R</span>' + escHtml(item.l);
            }
            div.addEventListener('mouseenter', function() { clearActive(); activeIdx = i; div.style.background = '#eff6ff'; });
            div.addEventListener('mouseleave', function() { div.style.background = ''; });
            div.addEventListener('mousedown', function(e) { e.preventDefault(); selectItem(item); });
            dropdown.appendChild(div);
        });
        if (items.length > 40) {
            var more = document.createElement('div');
            more.textContent = '... ' + (items.length - 40) + <?php echo json_encode(t('grc-controls.js_more_results')); ?>;
            more.style.cssText = 'padding:8px 12px;font-size:11px;color:#9ca3af;font-style:italic;';
            dropdown.appendChild(more);
        }
        dropdown.style.display = 'block';
    }

    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function clearActive() {
        var children = dropdown.children;
        for (var i = 0; i < children.length; i++) children[i].style.background = '';
    }

    function selectItem(item) {
        dropdown.style.display = 'none';
        if (item.t === 'q') {
            // Question selected: show bulk mapping preview
            showBulkPreview(item);
        } else {
            // Single requirement: submit directly with coverage prompt
            input.value = item.l;
            input.style.borderColor = '#22c55e';
            if (singleForm) {
                document.getElementById('single_req_id').value = item.id;
                document.getElementById('single_coverage').value = 'full';
                singleForm.submit();
            }
        }
    }

    function showBulkPreview(q) {
        if (!preview) return;
        input.value = q.ref + ' — ' + q.text.substring(0, 60) + '...';
        input.style.borderColor = '#3b82f6';
        document.getElementById('bulk_map_title').textContent = q.ref + ': ' + q.text;
        var tbody = document.getElementById('bulk_map_body');
        tbody.innerHTML = '';
        q.reqs.forEach(function(r) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="checkbox" class="bulk-check" data-rid="' + r.id + '" checked></td>' +
                '<td>' + escHtml(r.fw) + '</td>' +
                '<td>' + escHtml(r.ref) + ' - ' + escHtml(r.title) + '</td>' +
                '<td><span style="font-size:11px;padding:2px 6px;border-radius:3px;background:' + (r.strength === 'exact' ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e') + ';">' + escHtml(r.strength) + '</span></td>' +
                '<td><select class="bulk-coverage" data-rid="' + r.id + '" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;"><option value="full">' + <?php echo json_encode(t('grc-controls.full')); ?> + '</option><option value="partial">' + <?php echo json_encode(t('grc-controls.partial')); ?> + '</option></select></td>';
            tbody.appendChild(tr);
        });
        preview.style.display = 'block';
        updateBulkButton();
    }

    function updateBulkButton() {
        var checked = document.querySelectorAll('.bulk-check:checked');
        var btn = document.getElementById('bulk_map_submit');
        if (btn) {
            btn.textContent = <?php echo json_encode(t('grc-controls.js_map_prefix')); ?> + checked.length + <?php echo json_encode(t('grc-controls.js_selected_requirement')); ?> + (checked.length !== 1 ? 's' : '');
            btn.disabled = checked.length === 0;
        }
    }

    // Bulk checkboxes
    document.addEventListener('change', function(e) {
        if (e.target.id === 'bulk_check_all') {
            document.querySelectorAll('.bulk-check').forEach(function(cb) { cb.checked = e.target.checked; });
            updateBulkButton();
        }
        if (e.target.classList.contains('bulk-check')) {
            updateBulkButton();
        }
    });

    // Bulk form submission: gather checked IDs and coverages
    var bulkForm = document.getElementById('bulk_map_form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            var ids = [], covs = [];
            document.querySelectorAll('.bulk-check:checked').forEach(function(cb) {
                var rid = cb.dataset.rid;
                ids.push(rid);
                var sel = document.querySelector('.bulk-coverage[data-rid="' + rid + '"]');
                covs.push(sel ? sel.value : 'full');
            });
            document.getElementById('bulk_req_ids').value = ids.join(',');
            document.getElementById('bulk_coverages').value = covs.join(',');
            if (ids.length === 0) { e.preventDefault(); return; }
        });
    }

    // Cancel button
    var cancelBtn = document.getElementById('bulk_map_cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            preview.style.display = 'none';
            input.value = '';
            input.style.borderColor = '#d1d5db';
        });
    }

    input.addEventListener('input', function() {
        input.style.borderColor = '#d1d5db';
        if (preview) preview.style.display = 'none';
        var q = input.value.trim().toLowerCase();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        var terms = q.split(/\s+/);

        // Search questions
        var qMatches = questionMapData.filter(function(qd) {
            var searchStr = (qd.ref + ' ' + qd.domain + ' ' + qd.text).toLowerCase();
            return terms.every(function(t) { return searchStr.indexOf(t) !== -1; });
        }).slice(0, 10).map(function(qd) {
            return { t: 'q', ref: qd.ref, text: qd.text, reqs: qd.reqs };
        });

        // Search requirements
        var rMatches = reqMapData.filter(function(r) {
            var ll = r.l.toLowerCase();
            return terms.every(function(t) { return ll.indexOf(t) !== -1; });
        }).slice(0, 30).map(function(r) {
            return { t: 'req', id: r.id, l: r.l };
        });

        // Questions first, then requirements
        var combined = qMatches.concat(rMatches);
        render(combined);
    });

    input.addEventListener('keydown', function(e) {
        var children = dropdown.children;
        var maxIdx = Math.min(filtered.length, 40) - 1;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIdx < maxIdx) { activeIdx++; clearActive(); children[activeIdx].style.background = '#eff6ff'; children[activeIdx].scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIdx > 0) { activeIdx--; clearActive(); children[activeIdx].style.background = '#eff6ff'; children[activeIdx].scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'Enter' && activeIdx >= 0 && activeIdx < filtered.length) {
            e.preventDefault();
            selectItem(filtered[activeIdx]);
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    });

    input.addEventListener('blur', function() {
        setTimeout(function() { dropdown.style.display = 'none'; }, 200);
    });

    input.addEventListener('focus', function() {
        if (input.value.trim().length >= 2 && filtered.length) dropdown.style.display = 'block';
    });
})();
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
