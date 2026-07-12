<?php
/**
 * GRC Unified Compliance Engine - Risk Register
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Risk register with heatmap visualization, create/edit risks with
 * likelihood/impact assessment, link risks to controls, treatment plans,
 * and risk scoring (inherent vs residual).
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
    die(e(t('grc-risks.access_denied')));
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();
$msg = '';
$msgType = '';

// Enum mappings
$likelihoodOptions = [
    'rare' => t('grc-risks.opt_likelihood_rare'),
    'unlikely' => t('grc-risks.opt_likelihood_unlikely'),
    'possible' => t('grc-risks.opt_likelihood_possible'),
    'likely' => t('grc-risks.opt_likelihood_likely'),
    'almost_certain' => t('grc-risks.opt_likelihood_almost_certain'),
];
$impactOptions = [
    'insignificant' => t('grc-risks.opt_impact_insignificant'),
    'minor' => t('grc-risks.opt_impact_minor'),
    'moderate' => t('grc-risks.opt_impact_moderate'),
    'major' => t('grc-risks.opt_impact_major'),
    'catastrophic' => t('grc-risks.opt_impact_catastrophic'),
];
$likelihoodNumeric = ['rare' => 1, 'unlikely' => 2, 'possible' => 3, 'likely' => 4, 'almost_certain' => 5];
$impactNumeric = ['insignificant' => 1, 'minor' => 2, 'moderate' => 3, 'major' => 4, 'catastrophic' => 5];

$categoryOptions = ['strategic', 'operational', 'financial', 'compliance', 'reputational', 'technology', 'third_party'];
$statusOptions = ['identified', 'assessing', 'treating', 'monitoring', 'closed'];
$treatmentOptions = ['accept', 'mitigate', 'transfer', 'avoid'];

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc-risks.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_risk') {
            $title = trim($_POST['title'] ?? '');
            if ($title !== '') {
                $last = $db->fetchOne("SELECT risk_ref FROM grc_risk_register WHERE risk_ref LIKE 'RSK-%' ORDER BY id DESC LIMIT 1");
                $ref = $last ? 'RSK-' . str_pad((int)substr($last['risk_ref'], 4) + 1, 3, '0', STR_PAD_LEFT) : 'RSK-001';

                $likelihood = $_POST['likelihood'] ?? 'possible';
                $impact = $_POST['impact'] ?? 'moderate';
                $lNum = $likelihoodNumeric[$likelihood] ?? 3;
                $iNum = $impactNumeric[$impact] ?? 3;
                $inherentScore = $lNum * $iNum;
                $residualScore = isset($_POST['residual_risk_score']) && $_POST['residual_risk_score'] !== '' ? (float)$_POST['residual_risk_score'] : $inherentScore;

                // Build control_ids as comma-separated string
                $controlIdStr = null;
                if (!empty($_POST['control_ids'])) {
                    $controlIds = is_array($_POST['control_ids']) ? $_POST['control_ids'] : [$_POST['control_ids']];
                    $controlIds = array_filter(array_map('intval', $controlIds));
                    if (!empty($controlIds)) {
                        $controlIdStr = implode(',', $controlIds);
                    }
                }

                $db->insert('grc_risk_register', [
                    'risk_ref' => $ref,
                    'title' => $title,
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'risk_category' => $_POST['risk_category'] ?? 'compliance',
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                    'inherent_risk_score' => $inherentScore,
                    'residual_risk_score' => $residualScore,
                    'risk_treatment' => $_POST['risk_treatment'] ?? 'mitigate',
                    'treatment_plan' => trim($_POST['treatment_plan'] ?? '') ?: null,
                    'owner_user_id' => !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null,
                    'control_ids' => $controlIdStr,
                    'status' => $_POST['status'] ?? 'identified',
                    'review_date' => !empty($_POST['review_date']) ? $_POST['review_date'] : null,
                    'created_by' => (int)$user['id'],
                ]);

                $msg = t('grc-risks.risk_created');
                $msgType = 'success';
            } else {
                $msg = t('grc-risks.title_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'delete_risk') {
            $riskId = (int)($_POST['risk_id'] ?? 0);
            if ($riskId > 0) {
                $risk = $db->fetchOne('SELECT id FROM grc_risk_register WHERE id = :id', [':id' => $riskId]);
                if ($risk) {
                    $db->delete('grc_risk_register', 'id = :id', [':id' => $riskId]);
                    $msg = t('grc-risks.risk_deleted');
                    $msgType = 'success';
                } else {
                    $msg = t('grc-risks.risk_not_found');
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'update_risk') {
            $riskId = (int)($_POST['risk_id'] ?? 0);
            if ($riskId > 0) {
                $likelihood = $_POST['likelihood'] ?? 'possible';
                $impact = $_POST['impact'] ?? 'moderate';
                $lNum = $likelihoodNumeric[$likelihood] ?? 3;
                $iNum = $impactNumeric[$impact] ?? 3;
                $inherentScore = $lNum * $iNum;
                $residualScore = isset($_POST['residual_risk_score']) && $_POST['residual_risk_score'] !== '' ? (float)$_POST['residual_risk_score'] : $inherentScore;

                $controlIdStr = null;
                if (!empty($_POST['control_ids'])) {
                    $controlIds = is_array($_POST['control_ids']) ? $_POST['control_ids'] : [$_POST['control_ids']];
                    $controlIds = array_filter(array_map('intval', $controlIds));
                    if (!empty($controlIds)) {
                        $controlIdStr = implode(',', $controlIds);
                    }
                }

                $db->update('grc_risk_register', [
                    'title' => trim($_POST['title'] ?? ''),
                    'description' => trim($_POST['description'] ?? '') ?: null,
                    'risk_category' => $_POST['risk_category'] ?? 'compliance',
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                    'inherent_risk_score' => $inherentScore,
                    'residual_risk_score' => $residualScore,
                    'risk_treatment' => $_POST['risk_treatment'] ?? 'mitigate',
                    'treatment_plan' => trim($_POST['treatment_plan'] ?? '') ?: null,
                    'owner_user_id' => !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null,
                    'control_ids' => $controlIdStr,
                    'status' => $_POST['status'] ?? 'identified',
                    'review_date' => !empty($_POST['review_date']) ? $_POST['review_date'] : null,
                ], 'id = :id', [':id' => $riskId]);

                $msg = t('grc-risks.risk_updated');
                $msgType = 'success';
            }
        }
        $csrfToken = $security->generateCSRFToken();
    }
}

// Generate CSRF token for GET requests (POST handler above generates its own after validation)
if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Load risks
$risks = $db->fetchAll(
    'SELECT r.*, u.full_name as owner_name
     FROM grc_risk_register r
     LEFT JOIN users u ON u.id = r.owner_user_id
     ORDER BY r.inherent_risk_score DESC, r.risk_ref'
);

// Detail/Edit view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$riskDetail = null;
$riskControls = [];

if ($viewId > 0 || $editId > 0) {
    $rid = $viewId > 0 ? $viewId : $editId;
    $riskDetail = $db->fetchOne(
        'SELECT r.*, u.full_name as owner_name
         FROM grc_risk_register r
         LEFT JOIN users u ON u.id = r.owner_user_id
         WHERE r.id = :id',
        [':id' => $rid]
    );
    if ($riskDetail && !empty($riskDetail['control_ids'])) {
        $cids = array_filter(array_map('intval', explode(',', $riskDetail['control_ids'])));
        if (!empty($cids)) {
            $placeholders = implode(',', $cids);
            $riskControls = $db->fetchAll(
                "SELECT id, control_ref, title, implementation_status
                 FROM grc_internal_controls
                 WHERE id IN ($placeholders)
                 ORDER BY control_ref"
            );
        }
    }
}

$allControls = $db->fetchAll('SELECT id, control_ref, title FROM grc_internal_controls WHERE is_active = 1 ORDER BY control_ref');

// Build control lookup map for search
$controlMap = [];
foreach ($allControls as $ac) {
    $controlMap[(int)$ac['id']] = $ac['control_ref'] . ' - ' . $ac['title'];
}
$users_list = $db->fetchAll(
    "SELECT DISTINCT u.id, u.full_name
     FROM users u
     JOIN user_acl_groups uag ON uag.user_id = u.id
     JOIN acl_groups ag ON ag.id = uag.group_id
     WHERE u.is_active = 1
       AND ag.group_name IN ('administrator', 'cyber_grc', 'grc_contributors')
     ORDER BY u.full_name"
);

// Build heatmap data (5x5 grid) using likelihood/impact enums
$heatmapData = [];
for ($l = 1; $l <= 5; $l++) {
    for ($i = 1; $i <= 5; $i++) {
        $heatmapData[$l][$i] = 0;
    }
}
foreach ($risks as $r) {
    $lNum = $likelihoodNumeric[$r['likelihood'] ?? 'possible'] ?? 3;
    $iNum = $impactNumeric[$r['impact'] ?? 'moderate'] ?? 3;
    $heatmapData[$lNum][$iNum]++;
}

$currentPage = 'grc_risk_register';
$showForm = isset($_GET['new']) || $editId > 0;

// Helper: get selected control IDs from risk detail
$selectedControlIds = [];
if ($riskDetail && !empty($riskDetail['control_ids'])) {
    $selectedControlIds = array_filter(array_map('intval', explode(',', $riskDetail['control_ids'])));
}
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-risks.page_title')); ?></title>
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

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grc-form .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .grc-form .form-row-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-card h3 { margin: 0 0 12px; font-size: 15px; color: #333; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        .risk-score { display: inline-block; padding: 4px 12px; border-radius: 6px; font-weight: 700; font-size: 14px; }
        .risk-score.critical { background: #991b1b; color: #fff; }
        .risk-score.high { background: #dc3545; color: #fff; }
        .risk-score.medium { background: #f59e0b; color: #fff; }
        .risk-score.low { background: #28a745; color: #fff; }

        .autosave-status { font-size: 12px; color: #28a745; margin-left: 12px; }

        /* Heatmap */
        .heatmap-wrapper { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 30px; max-width: 500px; }
        .heatmap-wrapper h3 { margin: 0 0 16px; font-size: 15px; color: #333; }
        .heatmap { display: grid; grid-template-columns: 30px repeat(5, 1fr); grid-template-rows: repeat(5, 1fr) 30px; gap: 2px; max-width: 350px; }
        .heatmap-cell { display: flex; align-items: center; justify-content: center; min-height: 50px; border-radius: 4px; font-size: 14px; font-weight: 600; color: #fff; }
        .heatmap-label { display: flex; align-items: center; justify-content: center; font-size: 10px; color: #6b7280; font-weight: 600; }
        .hc-green { background: #28a745; }
        .hc-yellow { background: #f59e0b; }
        .hc-orange { background: #f97316; }
        .hc-red { background: #dc3545; }
        .hc-darkred { background: #991b1b; }

        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }

        /* Search & Filter Bar */
        .risk-filter-bar { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
        .risk-filter-bar input, .risk-filter-bar select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; }
        .risk-filter-bar input { min-width: 220px; }
        .risk-filter-bar select { min-width: 150px; color: #374151; background: #fff; cursor: pointer; }
        .risk-filter-bar input:focus, .risk-filter-bar select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }
        .risk-filter-bar select.filter-active { border-color: #3b82f6; background: #eff6ff; }
        .heatmap-active-label { font-size: 12px; color: #6b7280; background: #f3f4f6; padding: 6px 12px; border-radius: 6px; display: none; align-items: center; gap: 6px; }
        .heatmap-active-label.visible { display: inline-flex; }

        /* Sortable Columns */
        .grc-table th.sortable { cursor: pointer; user-select: none; position: relative; padding-right: 24px; transition: background 0.15s; }
        .grc-table th.sortable:hover { background: #e5e7eb; }
        .grc-table th.sortable::after { content: '\2195'; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); font-size: 11px; color: #9ca3af; }
        .grc-table th.sortable.sort-asc::after { content: '\2191'; color: #3b82f6; }
        .grc-table th.sortable.sort-desc::after { content: '\2193'; color: #3b82f6; }
        .btn-clear { background: #fff; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: none; }
        .btn-clear:hover { background: #f3f4f6; border-color: #9ca3af; }
        .btn-clear.visible { display: inline-block; }

        /* Clickable Heatmap */
        .heatmap-cell { cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
        .heatmap-cell:hover { transform: scale(1.08); box-shadow: 0 2px 8px rgba(0,0,0,0.2); z-index: 1; position: relative; }
        .heatmap-cell.heatmap-selected { outline: 3px solid #1d4ed8; outline-offset: -2px; transform: scale(1.08); }

        /* Pagination */
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; flex-wrap: wrap; gap: 12px; }
        .pagination-bar .page-info { font-size: 13px; color: #6b7280; }
        .pagination-controls { display: flex; align-items: center; gap: 4px; }
        .pagination-controls button { padding: 6px 12px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; font-size: 13px; cursor: pointer; color: #374151; transition: all 0.2s; }
        .pagination-controls button:hover:not(:disabled) { background: #f3f4f6; border-color: #9ca3af; }
        .pagination-controls button:disabled { opacity: 0.4; cursor: default; }
        .pagination-controls button.active { background: var(--theme-button-color, #ff6543); color: #fff; border-color: var(--theme-button-color, #ff6543); }
        .pagination-controls select { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; }
        .risk-row-hidden { display: none !important; }

        @media (max-width: 900px) {
            .grc-form .form-row, .grc-form .form-row-3, .grc-form .form-row-4 { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .risk-filter-bar { flex-direction: column; align-items: stretch; }
            .risk-filter-bar input, .risk-filter-bar select { min-width: 100%; }
        }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-risks.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc-risks.subtitle')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <?php if ($riskDetail && $viewId > 0): ?>
            <!-- Risk Detail -->
            <a href="grc-risks.php" class="back-link">&larr; <?php echo e(t('grc-risks.back_to_register')); ?></a>

            <div class="detail-card">
                <h3><?php echo e($riskDetail['risk_ref']); ?> - <?php echo e($riskDetail['title']); ?></h3>
                <div class="detail-grid">
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-risks.category')); ?></div><div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $riskDetail['risk_category'] ?? '-'))); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-risks.status')); ?></div><div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $riskDetail['status'] ?? '-'))); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-risks.owner')); ?></div><div class="value"><?php echo e($riskDetail['owner_name'] ?? '-'); ?></div></div>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-risks.treatment')); ?></div><div class="value"><?php echo e(ucfirst($riskDetail['risk_treatment'] ?? '-')); ?></div></div>
                    <div class="detail-item">
                        <div class="label"><?php echo e(t('grc-risks.likelihood')); ?></div>
                        <div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $riskDetail['likelihood'] ?? '-'))); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label"><?php echo e(t('grc-risks.impact')); ?></div>
                        <div class="value"><?php echo e(ucfirst(str_replace('_', ' ', $riskDetail['impact'] ?? '-'))); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label"><?php echo e(t('grc-risks.inherent_risk_score')); ?></div>
                        <div class="value">
                            <?php
                            $iScore = (float)($riskDetail['inherent_risk_score'] ?? 0);
                            $iClass = $iScore >= 20 ? 'critical' : ($iScore >= 12 ? 'high' : ($iScore >= 6 ? 'medium' : 'low'));
                            ?>
                            <span class="risk-score <?php echo $iClass; ?>"><?php echo number_format($iScore, 0); ?></span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="label"><?php echo e(t('grc-risks.residual_risk_score')); ?></div>
                        <div class="value">
                            <?php
                            $rScore = (float)($riskDetail['residual_risk_score'] ?? 0);
                            $rClass = $rScore >= 20 ? 'critical' : ($rScore >= 12 ? 'high' : ($rScore >= 6 ? 'medium' : 'low'));
                            ?>
                            <span class="risk-score <?php echo $rClass; ?>"><?php echo number_format($rScore, 0); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($riskDetail['review_date'])): ?>
                    <div class="detail-item"><div class="label"><?php echo e(t('grc-risks.review_date')); ?></div><div class="value"><?php echo e($riskDetail['review_date']); ?></div></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($riskDetail['description'])): ?>
                <p style="font-size:13px;color:#374151;"><?php echo e($riskDetail['description']); ?></p>
                <?php endif; ?>
                <?php if (!empty($riskDetail['treatment_plan'])): ?>
                <div style="margin-top:12px;padding:12px 16px;background:#f3f4f6;border-radius:6px;">
                    <div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;margin-bottom:4px;"><?php echo e(t('grc-risks.treatment_plan')); ?></div>
                    <div style="font-size:13px;color:#374151;"><?php echo nl2br(e($riskDetail['treatment_plan'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!$readOnly): ?>
                <div style="margin-top:16px;">
                    <a href="grc-risks.php?edit=<?php echo (int)$riskDetail['id']; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc-risks.edit_risk')); ?></a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Linked Controls -->
            <div class="detail-card">
                <h3><?php echo e(t('grc-risks.mitigating_controls')); ?> (<?php echo count($riskControls); ?>)</h3>
                <?php if (!empty($riskControls)): ?>
                <table class="grc-table">
                    <thead><tr><th><?php echo e(t('grc-risks.col_reference')); ?></th><th><?php echo e(t('grc-risks.col_title')); ?></th><th><?php echo e(t('grc-risks.col_status')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($riskControls as $rc): ?>
                    <tr>
                        <td><a href="grc-controls.php?view=<?php echo (int)$rc['id']; ?>" style="color:#3b82f6;text-decoration:none;"><?php echo e($rc['control_ref']); ?></a></td>
                        <td><?php echo e($rc['title']); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $rc['implementation_status']))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;"><?php echo e(t('grc-risks.no_controls_linked')); ?></p>
                <?php endif; ?>
            </div>

            <?php elseif ($showForm && !$readOnly): ?>
            <!-- Create/Edit Risk -->
            <a href="grc-risks.php" class="back-link">&larr; <?php echo e(t('grc-risks.back_to_register')); ?></a>

            <div class="grc-form">
                <h3 style="margin:0 0 16px;font-size:16px;color:#333;"><?php echo e($editId > 0 ? t('grc-risks.edit_risk') : t('grc-risks.create_risk')); ?><span class="autosave-status"></span></h3>
                <form method="post" class="grc-autosave-form" data-form-type="risk_edit" data-form-id="<?php echo $editId > 0 ? (int)$riskDetail['id'] : 'new'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update_risk' : 'create_risk'; ?>">
                    <?php if ($editId > 0): ?>
                    <input type="hidden" name="risk_id" value="<?php echo (int)$riskDetail['id']; ?>">
                    <?php endif; ?>

                    <label for="title"><?php echo e(t('grc-risks.risk_title')); ?></label>
                    <input type="text" id="title" name="title" value="<?php echo e($riskDetail['title'] ?? ''); ?>" required>

                    <label for="description"><?php echo e(t('grc-risks.description')); ?></label>
                    <textarea id="description" name="description"><?php echo e($riskDetail['description'] ?? ''); ?></textarea>

                    <div class="form-row-3">
                        <div>
                            <label for="risk_category"><?php echo e(t('grc-risks.category')); ?></label>
                            <select id="risk_category" name="risk_category">
                                <?php foreach ($categoryOptions as $cat): ?>
                                <option value="<?php echo e($cat); ?>" <?php echo ($riskDetail['risk_category'] ?? 'compliance') === $cat ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $cat))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="owner_user_id"><?php echo e(t('grc-risks.risk_owner')); ?></label>
                            <select id="owner_user_id" name="owner_user_id">
                                <option value=""><?php echo e(t('grc-risks.select_option')); ?></option>
                                <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo ((int)($riskDetail['owner_user_id'] ?? 0)) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status"><?php echo e(t('grc-risks.status')); ?></label>
                            <select id="status" name="status">
                                <?php foreach ($statusOptions as $s): ?>
                                <option value="<?php echo e($s); ?>" <?php echo ($riskDetail['status'] ?? 'identified') === $s ? 'selected' : ''; ?>><?php echo e(ucfirst(str_replace('_', ' ', $s))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h4 style="font-size:14px;color:#333;margin:16px 0 8px;border-top:1px solid #e5e7eb;padding-top:16px;"><?php echo e(t('grc-risks.risk_assessment')); ?></h4>
                    <div class="form-row">
                        <div>
                            <label for="likelihood"><?php echo e(t('grc-risks.likelihood')); ?></label>
                            <select id="likelihood" name="likelihood">
                                <?php foreach ($likelihoodOptions as $val => $label): ?>
                                <option value="<?php echo e($val); ?>" <?php echo ($riskDetail['likelihood'] ?? 'possible') === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="impact"><?php echo e(t('grc-risks.impact')); ?></label>
                            <select id="impact" name="impact">
                                <?php foreach ($impactOptions as $val => $label): ?>
                                <option value="<?php echo e($val); ?>" <?php echo ($riskDetail['impact'] ?? 'moderate') === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-3">
                        <div>
                            <label><?php echo e(t('grc-risks.inherent_risk_score')); ?></label>
                            <div id="inherent-score-display" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;font-size:14px;font-weight:600;margin-bottom:12px;">
                                <?php
                                $lNum = $likelihoodNumeric[$riskDetail['likelihood'] ?? 'possible'] ?? 3;
                                $iNum = $impactNumeric[$riskDetail['impact'] ?? 'moderate'] ?? 3;
                                echo $lNum * $iNum;
                                ?>
                            </div>
                        </div>
                        <div>
                            <label for="residual_risk_score"><?php echo e(t('grc-risks.residual_risk_score')); ?></label>
                            <input type="number" id="residual_risk_score" name="residual_risk_score" min="0" max="25" step="0.01" value="<?php echo e($riskDetail['residual_risk_score'] ?? ''); ?>" placeholder="<?php echo e(t('grc-risks.residual_placeholder')); ?>">
                        </div>
                        <div>
                            <label for="review_date"><?php echo e(t('grc-risks.review_date')); ?></label>
                            <input type="date" id="review_date" name="review_date" value="<?php echo e($riskDetail['review_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <h4 style="font-size:14px;color:#333;margin:16px 0 8px;border-top:1px solid #e5e7eb;padding-top:16px;"><?php echo e(t('grc-risks.treatment')); ?></h4>
                    <div class="form-row">
                        <div>
                            <label for="risk_treatment"><?php echo e(t('grc-risks.treatment_strategy')); ?></label>
                            <select id="risk_treatment" name="risk_treatment">
                                <?php foreach ($treatmentOptions as $ts): ?>
                                <option value="<?php echo e($ts); ?>" <?php echo ($riskDetail['risk_treatment'] ?? 'mitigate') === $ts ? 'selected' : ''; ?>><?php echo e(ucfirst($ts)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="treatment_plan"><?php echo e(t('grc-risks.treatment_plan')); ?></label>
                            <textarea id="treatment_plan" name="treatment_plan" style="min-height:60px;"><?php echo e($riskDetail['treatment_plan'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <label><?php echo e(t('grc-risks.mitigating_controls')); ?></label>
                    <select name="control_ids[]" multiple style="min-height:100px;">
                        <?php foreach ($allControls as $ac): ?>
                        <option value="<?php echo (int)$ac['id']; ?>" <?php echo in_array((int)$ac['id'], $selectedControlIds) ? 'selected' : ''; ?>><?php echo e($ac['control_ref']); ?> - <?php echo e($ac['title']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn btn-primary"><?php echo e($editId > 0 ? t('grc-risks.update_risk') : t('grc-risks.create_risk')); ?></button>
                        <a href="grc-risks.php" class="btn btn-outline"><?php echo e(t('grc-risks.cancel')); ?></a>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <!-- Risk Heatmap -->
            <?php if (!empty($risks)): ?>
            <div class="heatmap-wrapper">
                <h3><?php echo e(t('grc-risks.heatmap_title')); ?></h3>
                <div style="text-align:center;font-size:11px;color:#6b7280;margin-bottom:4px;"><?php echo e(t('grc-risks.impact')); ?> &rarr;</div>
                <div class="heatmap">
                    <?php
                    function getHeatColor($l, $i) {
                        $s = $l * $i;
                        if ($s >= 20) return 'hc-darkred';
                        if ($s >= 12) return 'hc-red';
                        if ($s >= 6) return 'hc-orange';
                        if ($s >= 3) return 'hc-yellow';
                        return 'hc-green';
                    }
                    for ($l = 5; $l >= 1; $l--):
                    ?>
                    <div class="heatmap-label"><?php echo $l; ?></div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="heatmap-cell <?php echo getHeatColor($l, $i); ?>" data-likelihood="<?php echo $l; ?>" data-impact="<?php echo $i; ?>" title="<?php echo e(t('grc-risks.likelihood')); ?> <?php echo $l; ?>, <?php echo e(t('grc-risks.impact')); ?> <?php echo $i; ?>"><?php echo $heatmapData[$l][$i] > 0 ? $heatmapData[$l][$i] : ''; ?></div>
                    <?php endfor; ?>
                    <?php endfor; ?>
                    <div class="heatmap-label"></div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="heatmap-label"><?php echo $i; ?></div>
                    <?php endfor; ?>
                </div>
                <div style="text-align:left;font-size:11px;color:#6b7280;margin-top:4px;"><?php echo e(t('grc-risks.likelihood')); ?> &uarr;</div>
            </div>
            <?php endif; ?>

            <!-- Risk List -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h2 class="section-header" style="margin:0;border:none;padding:0;"><?php echo e(t('grc-risks.heading')); ?></h2>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" class="btn-clear" id="clearFiltersBtn"><?php echo e(t('grc-risks.clear_filters')); ?></button>
                    <button type="button" class="btn btn-outline" id="downloadCsvBtn" title="<?php echo e(t('grc-risks.download_csv')); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><?php echo e(t('grc-risks.csv')); ?></button>
                    <?php if (!$readOnly): ?>
                    <a href="grc-risks.php?new=1" class="btn btn-primary"><?php echo e(t('grc-risks.new_risk')); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="risk-filter-bar" id="riskFilterBar">
                <input type="text" id="searchTitle" placeholder="<?php echo e(t('grc-risks.search_by_title')); ?>">
                <input type="text" id="searchControls" placeholder="<?php echo e(t('grc-risks.search_by_controls')); ?>">
                <select id="filterCategory">
                    <option value=""><?php echo e(t('grc-risks.all_categories')); ?></option>
                    <?php foreach ($categoryOptions as $cat): ?>
                    <option value="<?php echo e($cat); ?>"><?php echo e(ucfirst(str_replace('_', ' ', $cat))); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterLikelihood">
                    <option value=""><?php echo e(t('grc-risks.all_likelihoods')); ?></option>
                    <?php foreach ($likelihoodOptions as $val => $label): ?>
                    <option value="<?php echo $likelihoodNumeric[$val]; ?>"><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterImpact">
                    <option value=""><?php echo e(t('grc-risks.all_impacts')); ?></option>
                    <?php foreach ($impactOptions as $val => $label): ?>
                    <option value="<?php echo $impactNumeric[$val]; ?>"><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterTreatment">
                    <option value=""><?php echo e(t('grc-risks.all_treatments')); ?></option>
                    <?php foreach ($treatmentOptions as $ts): ?>
                    <option value="<?php echo e($ts); ?>"><?php echo e(ucfirst($ts)); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="heatmap-active-label" id="heatmapFilterLabel"></span>
            </div>

            <table class="grc-table">
                <thead>
                    <tr>
                        <th class="sortable" data-sort-key="ref"><?php echo e(t('grc-risks.col_reference')); ?></th>
                        <th class="sortable" data-sort-key="title"><?php echo e(t('grc-risks.col_title')); ?></th>
                        <th class="sortable" data-sort-key="category"><?php echo e(t('grc-risks.category')); ?></th>
                        <th class="sortable" data-sort-key="owner"><?php echo e(t('grc-risks.owner')); ?></th>
                        <th class="sortable" data-sort-key="likelihood"><?php echo e(t('grc-risks.likelihood')); ?></th>
                        <th class="sortable" data-sort-key="impact"><?php echo e(t('grc-risks.impact')); ?></th>
                        <th class="sortable" data-sort-key="inherent"><?php echo e(t('grc-risks.inherent_score')); ?></th>
                        <th class="sortable" data-sort-key="residual"><?php echo e(t('grc-risks.residual_score')); ?></th>
                        <th class="sortable" data-sort-key="treatment"><?php echo e(t('grc-risks.treatment')); ?></th>
                        <th class="sortable" data-sort-key="status"><?php echo e(t('grc-risks.status')); ?></th>
                        <?php if (!$readOnly): ?><th><?php echo e(t('grc-risks.actions')); ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($risks)): ?>
                    <tr><td colspan="<?php echo $readOnly ? 10 : 11; ?>" style="text-align:center;color:#6b7280;padding:30px;"><?php echo e(t('grc-risks.no_risks_recorded')); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($risks as $r):
                        $iScore = (float)($r['inherent_risk_score'] ?? 0);
                        $rScore = (float)($r['residual_risk_score'] ?? 0);
                        $iClass = $iScore >= 20 ? 'critical' : ($iScore >= 12 ? 'high' : ($iScore >= 6 ? 'medium' : 'low'));
                        $rClass = $rScore >= 20 ? 'critical' : ($rScore >= 12 ? 'high' : ($rScore >= 6 ? 'medium' : 'low'));
                        // Build control names for this risk
                        $rowControlNames = '';
                        if (!empty($r['control_ids'])) {
                            $rcids = array_filter(array_map('intval', explode(',', $r['control_ids'])));
                            $rcNames = [];
                            foreach ($rcids as $rcid) {
                                if (isset($controlMap[$rcid])) $rcNames[] = $controlMap[$rcid];
                            }
                            $rowControlNames = implode(', ', $rcNames);
                        }
                        $rowLikelihood = $likelihoodNumeric[$r['likelihood'] ?? 'possible'] ?? 3;
                        $rowImpact = $impactNumeric[$r['impact'] ?? 'moderate'] ?? 3;
                    ?>
                    <tr class="risk-row" data-title="<?php echo e(strtolower($r['title'])); ?>" data-controls="<?php echo e(strtolower($rowControlNames)); ?>" data-likelihood="<?php echo $rowLikelihood; ?>" data-impact="<?php echo $rowImpact; ?>" data-ref="<?php echo e($r['risk_ref']); ?>" data-category="<?php echo e($r['risk_category'] ?? ''); ?>" data-owner="<?php echo e(strtolower($r['owner_name'] ?? '')); ?>" data-inherent="<?php echo $iScore; ?>" data-residual="<?php echo $rScore; ?>" data-treatment="<?php echo e($r['risk_treatment'] ?? ''); ?>" data-status="<?php echo e($r['status'] ?? ''); ?>">
                        <td><a href="grc-risks.php?view=<?php echo (int)$r['id']; ?>" style="color:#3b82f6;text-decoration:none;font-weight:500;"><?php echo e($r['risk_ref']); ?></a></td>
                        <td><?php echo e($r['title']); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $r['risk_category'] ?? '-'))); ?></td>
                        <td><?php echo e($r['owner_name'] ?? '-'); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $r['likelihood'] ?? '-'))); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $r['impact'] ?? '-'))); ?></td>
                        <td><span class="risk-score <?php echo $iClass; ?>"><?php echo number_format($iScore, 0); ?></span></td>
                        <td><span class="risk-score <?php echo $rClass; ?>"><?php echo number_format($rScore, 0); ?></span></td>
                        <td><?php echo e(ucfirst($r['risk_treatment'] ?? '-')); ?></td>
                        <td><?php echo e(ucfirst(str_replace('_', ' ', $r['status'] ?? '-'))); ?></td>
                        <?php if (!$readOnly): ?>
                        <td style="white-space:nowrap;">
                            <a href="grc-risks.php?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline" style="margin-right:4px;"><?php echo e(t('grc-risks.edit')); ?></a>
                            <form method="post" style="display:inline;" class="delete-risk-form" data-risk-ref="<?php echo e($r['risk_ref']); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <input type="hidden" name="action" value="delete_risk">
                                <input type="hidden" name="risk_id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;"><?php echo e(t('grc-risks.delete')); ?></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($risks)): ?>
            <div class="pagination-bar" id="paginationBar">
                <div class="page-info" id="pageInfo"></div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <label style="font-size:13px;color:#6b7280;"><?php echo e(t('grc-risks.per_page')); ?></label>
                    <select id="perPageSelect">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <div class="pagination-controls" id="paginationControls"></div>
                </div>
            </div>
            <?php endif; ?>
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
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc-risks.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc-risks.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
// Auto-calculate inherent score when likelihood/impact change
var likelihoodMap = {rare:1, unlikely:2, possible:3, likely:4, almost_certain:5};
var impactMap = {insignificant:1, minor:2, moderate:3, major:4, catastrophic:5};
var lSel = document.getElementById('likelihood');
var iSel = document.getElementById('impact');
var scoreDisp = document.getElementById('inherent-score-display');
if (lSel && iSel && scoreDisp) {
    function updateScore() {
        var score = (likelihoodMap[lSel.value] || 3) * (impactMap[iSel.value] || 3);
        scoreDisp.textContent = score;
    }
    lSel.addEventListener('change', updateScore);
    iSel.addEventListener('change', updateScore);
}

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
        if (indicator) { indicator.textContent = <?php echo json_encode(t('grc-risks.js_draft_saved')); ?>; setTimeout(function() { indicator.textContent = ''; }, 3000); }
    });
}

// --- Risk Register: Search, Heatmap Filter, Sorting, Pagination ---
// All event listeners attached via addEventListener (CSP-safe, no inline handlers)
(function() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('tr.risk-row'));
    if (!rows.length) return;

    var currentPage = 1;
    var perPage = 25;
    var heatmapLikelihood = null;
    var heatmapImpact = null;
    var sortKey = null;
    var sortDir = 'asc';
    var likelihoodLabels = {1:<?php echo json_encode(t('grc-risks.label_rare')); ?>, 2:<?php echo json_encode(t('grc-risks.label_unlikely')); ?>, 3:<?php echo json_encode(t('grc-risks.label_possible')); ?>, 4:<?php echo json_encode(t('grc-risks.label_likely')); ?>, 5:<?php echo json_encode(t('grc-risks.label_almost_certain')); ?>};
    var impactLabels = {1:<?php echo json_encode(t('grc-risks.label_insignificant')); ?>, 2:<?php echo json_encode(t('grc-risks.label_minor')); ?>, 3:<?php echo json_encode(t('grc-risks.label_moderate')); ?>, 4:<?php echo json_encode(t('grc-risks.label_major')); ?>, 5:<?php echo json_encode(t('grc-risks.label_catastrophic')); ?>};

    var searchTitle = document.getElementById('searchTitle');
    var searchControls = document.getElementById('searchControls');
    var filterCategory = document.getElementById('filterCategory');
    var filterLikelihood = document.getElementById('filterLikelihood');
    var filterImpact = document.getElementById('filterImpact');
    var filterTreatment = document.getElementById('filterTreatment');
    var clearBtn = document.getElementById('clearFiltersBtn');
    var heatmapLabel = document.getElementById('heatmapFilterLabel');
    var pageInfo = document.getElementById('pageInfo');
    var paginationControls = document.getElementById('paginationControls');
    var perPageSelect = document.getElementById('perPageSelect');
    var tbody = document.querySelector('.grc-table tbody');
    var dropdownFilters = [filterCategory, filterLikelihood, filterImpact, filterTreatment];

    var numericKeys = {likelihood:1, impact:1, inherent:1, residual:1};

    function updateDropdownStyles() {
        dropdownFilters.forEach(function(sel) {
            if (sel) sel.classList.toggle('filter-active', sel.value !== '');
        });
    }

    function getFilteredRows() {
        var titleQuery = searchTitle ? searchTitle.value.toLowerCase().trim() : '';
        var controlQuery = searchControls ? searchControls.value.toLowerCase().trim() : '';
        var catVal = filterCategory ? filterCategory.value : '';
        var likVal = filterLikelihood ? filterLikelihood.value : '';
        var impVal = filterImpact ? filterImpact.value : '';
        var treatVal = filterTreatment ? filterTreatment.value : '';
        var filtered = [];
        rows.forEach(function(row) {
            var matchTitle = !titleQuery || (row.dataset.title || '').indexOf(titleQuery) !== -1;
            var matchControls = !controlQuery || (row.dataset.controls || '').indexOf(controlQuery) !== -1;
            var matchCategory = !catVal || row.dataset.category === catVal;
            var matchLikelihood = !likVal || row.dataset.likelihood === likVal;
            var matchImpact = !impVal || row.dataset.impact === impVal;
            var matchTreatment = !treatVal || row.dataset.treatment === treatVal;
            var matchHeatmap = true;
            if (heatmapLikelihood !== null && heatmapImpact !== null) {
                matchHeatmap = parseInt(row.dataset.likelihood) === heatmapLikelihood && parseInt(row.dataset.impact) === heatmapImpact;
            }
            if (matchTitle && matchControls && matchCategory && matchLikelihood && matchImpact && matchTreatment && matchHeatmap) {
                filtered.push(row);
            }
        });
        return filtered;
    }

    function doSort(key, dir) {
        var isNumeric = key in numericKeys;
        rows.sort(function(a, b) {
            var aVal = a.dataset[key] || '';
            var bVal = b.dataset[key] || '';
            if (isNumeric) {
                aVal = parseFloat(aVal) || 0;
                bVal = parseFloat(bVal) || 0;
                return dir === 'asc' ? aVal - bVal : bVal - aVal;
            }
            aVal = aVal.toLowerCase();
            bVal = bVal.toLowerCase();
            if (aVal < bVal) return dir === 'asc' ? -1 : 1;
            if (aVal > bVal) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function restoreDefaultSort() {
        rows.sort(function(a, b) {
            var aI = parseFloat(a.dataset.inherent) || 0;
            var bI = parseFloat(b.dataset.inherent) || 0;
            if (bI !== aI) return bI - aI;
            var aR = (a.dataset.ref || '').toLowerCase();
            var bR = (b.dataset.ref || '').toLowerCase();
            return aR < bR ? -1 : (aR > bR ? 1 : 0);
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function updateSortHeaders() {
        document.querySelectorAll('.grc-table th.sortable').forEach(function(th) {
            th.classList.remove('sort-asc', 'sort-desc');
            if (sortKey && th.dataset.sortKey === sortKey) {
                th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    }

    function updateHeatmapSelection() {
        document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
            cell.classList.remove('heatmap-selected');
            if (heatmapLikelihood !== null && parseInt(cell.dataset.likelihood) === heatmapLikelihood && parseInt(cell.dataset.impact) === heatmapImpact) {
                cell.classList.add('heatmap-selected');
            }
        });
    }

    function paginate(target) {
        var filtered = getFilteredRows();
        var totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
        if (target === 'prev') {
            currentPage = Math.max(1, currentPage - 1);
        } else if (target === 'next') {
            currentPage = Math.min(totalPages, currentPage + 1);
        } else {
            currentPage = parseInt(target) || 1;
        }
        renderTable();
        var table = document.querySelector('.grc-table');
        if (table) table.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    function renderTable() {
        var filtered = getFilteredRows();
        var totalFiltered = filtered.length;
        var totalPages = Math.max(1, Math.ceil(totalFiltered / perPage));
        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * perPage;
        var end = start + perPage;

        rows.forEach(function(row) { row.classList.add('risk-row-hidden'); });
        for (var i = 0; i < filtered.length; i++) {
            if (i >= start && i < end) {
                filtered[i].classList.remove('risk-row-hidden');
            }
        }

        if (pageInfo) {
            if (totalFiltered === 0) {
                pageInfo.textContent = <?php echo json_encode(t('grc-risks.js_no_risks_match')); ?>;
            } else {
                pageInfo.textContent = 'Showing ' + (start + 1) + '-' + Math.min(end, totalFiltered) + ' of ' + totalFiltered + ' risk' + (totalFiltered !== 1 ? 's' : '');
            }
        }

        // Build pagination buttons (no inline onclick - uses event delegation)
        if (paginationControls) {
            var html = '';
            html += '<button data-page="prev" ' + (currentPage <= 1 ? 'disabled' : '') + '>&laquo; ' + <?php echo json_encode(t('grc-risks.js_prev')); ?> + '</button>';
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, startPage + 4);
            if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);
            for (var p = startPage; p <= endPage; p++) {
                html += '<button data-page="' + p + '" class="' + (p === currentPage ? 'active' : '') + '">' + p + '</button>';
            }
            html += '<button data-page="next" ' + (currentPage >= totalPages ? 'disabled' : '') + '>' + <?php echo json_encode(t('grc-risks.js_next')); ?> + ' &raquo;</button>';
            paginationControls.innerHTML = html;
        }

        // Show/hide clear button
        var hasFilters = (searchTitle && searchTitle.value.trim() !== '') ||
                         (searchControls && searchControls.value.trim() !== '') ||
                         (filterCategory && filterCategory.value !== '') ||
                         (filterLikelihood && filterLikelihood.value !== '') ||
                         (filterImpact && filterImpact.value !== '') ||
                         (filterTreatment && filterTreatment.value !== '') ||
                         heatmapLikelihood !== null ||
                         sortKey !== null;
        if (clearBtn) clearBtn.classList.toggle('visible', hasFilters);

        // Show/hide heatmap label (no inline onclick - uses event delegation)
        if (heatmapLabel) {
            if (heatmapLikelihood !== null && heatmapImpact !== null) {
                heatmapLabel.innerHTML = <?php echo json_encode(t('grc-risks.js_heatmap_prefix')); ?> + ' ' + (likelihoodLabels[heatmapLikelihood] || heatmapLikelihood) + ' &times; ' + <?php echo json_encode(t('grc-risks.js_impact_label')); ?> + ' ' + (impactLabels[heatmapImpact] || heatmapImpact) + ' <span class="heatmap-dismiss" style="cursor:pointer;font-weight:bold;margin-left:4px;" title="' + <?php echo json_encode(t('grc-risks.js_remove_heatmap_filter')); ?> + '">&times;</span>';
                heatmapLabel.classList.add('visible');
            } else {
                heatmapLabel.classList.remove('visible');
            }
        }

        updateDropdownStyles();
        updateSortHeaders();
    }

    // --- Attach all event listeners (CSP-safe) ---

    // Text search inputs
    if (searchTitle) searchTitle.addEventListener('input', function() { currentPage = 1; renderTable(); });
    if (searchControls) searchControls.addEventListener('input', function() { currentPage = 1; renderTable(); });

    // Dropdown filters
    dropdownFilters.forEach(function(sel) {
        if (sel) sel.addEventListener('change', function() { currentPage = 1; renderTable(); });
    });

    // Clear Filters button
    if (clearBtn) clearBtn.addEventListener('click', function() {
        if (searchTitle) searchTitle.value = '';
        if (searchControls) searchControls.value = '';
        if (filterCategory) filterCategory.value = '';
        if (filterLikelihood) filterLikelihood.value = '';
        if (filterImpact) filterImpact.value = '';
        if (filterTreatment) filterTreatment.value = '';
        heatmapLikelihood = null;
        heatmapImpact = null;
        updateHeatmapSelection();
        sortKey = null;
        sortDir = 'asc';
        restoreDefaultSort();
        currentPage = 1;
        renderTable();
    });

    // Heatmap cell clicks
    document.querySelectorAll('.heatmap-cell').forEach(function(cell) {
        cell.addEventListener('click', function() {
            var l = parseInt(cell.dataset.likelihood);
            var imp = parseInt(cell.dataset.impact);
            if (heatmapLikelihood === l && heatmapImpact === imp) {
                heatmapLikelihood = null;
                heatmapImpact = null;
            } else {
                heatmapLikelihood = l;
                heatmapImpact = imp;
            }
            updateHeatmapSelection();
            currentPage = 1;
            renderTable();
        });
    });

    // Heatmap label dismiss (event delegation for dynamically-created X span)
    if (heatmapLabel) heatmapLabel.addEventListener('click', function(e) {
        if (e.target.classList.contains('heatmap-dismiss')) {
            heatmapLikelihood = null;
            heatmapImpact = null;
            updateHeatmapSelection();
            currentPage = 1;
            renderTable();
        }
    });

    // Sortable column headers
    document.querySelectorAll('.grc-table th.sortable').forEach(function(th) {
        th.addEventListener('click', function() {
            var key = th.dataset.sortKey;
            if (sortKey === key) {
                if (sortDir === 'asc') {
                    sortDir = 'desc';
                } else {
                    sortKey = null;
                    sortDir = 'asc';
                }
            } else {
                sortKey = key;
                sortDir = 'asc';
            }
            if (sortKey) {
                doSort(sortKey, sortDir);
            } else {
                restoreDefaultSort();
            }
            currentPage = 1;
            renderTable();
        });
    });

    // Pagination controls (event delegation for dynamically-created buttons)
    if (paginationControls) paginationControls.addEventListener('click', function(e) {
        var btn = e.target.closest('button[data-page]');
        if (btn && !btn.disabled) {
            paginate(btn.dataset.page);
        }
    });

    // Per-page select
    if (perPageSelect) perPageSelect.addEventListener('change', function() {
        perPage = parseInt(perPageSelect.value) || 25;
        currentPage = 1;
        renderTable();
    });

    // Delete risk confirm dialogs (event delegation)
    document.querySelectorAll('.delete-risk-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var ref = form.dataset.riskRef || '';
            if (!confirm(<?php echo json_encode(t('grc-risks.js_delete_confirm_prefix')); ?> + ref + <?php echo json_encode(t('grc-risks.js_delete_confirm_suffix')); ?>)) {
                e.preventDefault();
            }
        });
    });

    // CSV Download — exports the currently filtered/sorted rows
    var csvBtn = document.getElementById('downloadCsvBtn');
    if (csvBtn) csvBtn.addEventListener('click', function() {
        var filtered = getFilteredRows();
        var csvRows = [];
        // Header row
        csvRows.push([<?php echo json_encode(t('grc-risks.csv_reference')); ?>,<?php echo json_encode(t('grc-risks.csv_title')); ?>,<?php echo json_encode(t('grc-risks.csv_category')); ?>,<?php echo json_encode(t('grc-risks.csv_owner')); ?>,<?php echo json_encode(t('grc-risks.csv_likelihood')); ?>,<?php echo json_encode(t('grc-risks.csv_impact')); ?>,<?php echo json_encode(t('grc-risks.csv_inherent_score')); ?>,<?php echo json_encode(t('grc-risks.csv_residual_score')); ?>,<?php echo json_encode(t('grc-risks.csv_treatment')); ?>,<?php echo json_encode(t('grc-risks.csv_status')); ?>,<?php echo json_encode(t('grc-risks.csv_mitigating_controls')); ?>].join(','));
        var likLabels = {1:<?php echo json_encode(t('grc-risks.label_rare')); ?>,2:<?php echo json_encode(t('grc-risks.label_unlikely')); ?>,3:<?php echo json_encode(t('grc-risks.label_possible')); ?>,4:<?php echo json_encode(t('grc-risks.label_likely')); ?>,5:<?php echo json_encode(t('grc-risks.label_almost_certain')); ?>};
        var impLabels = {1:<?php echo json_encode(t('grc-risks.label_insignificant')); ?>,2:<?php echo json_encode(t('grc-risks.label_minor')); ?>,3:<?php echo json_encode(t('grc-risks.label_moderate')); ?>,4:<?php echo json_encode(t('grc-risks.label_major')); ?>,5:<?php echo json_encode(t('grc-risks.label_catastrophic')); ?>};
        filtered.forEach(function(row) {
            var cells = row.querySelectorAll('td');
            var ref = (row.dataset.ref || '').replace(/"/g, '""');
            var title = (row.dataset.title || '').replace(/"/g, '""');
            var category = (row.dataset.category || '').replace(/_/g, ' ').replace(/"/g, '""');
            var owner = cells[3] ? cells[3].textContent.trim().replace(/"/g, '""') : '';
            var likelihood = likLabels[parseInt(row.dataset.likelihood)] || '';
            var impact = impLabels[parseInt(row.dataset.impact)] || '';
            var inherent = row.dataset.inherent || '0';
            var residual = row.dataset.residual || '0';
            var treatment = (row.dataset.treatment || '').replace(/"/g, '""');
            var status = (row.dataset.status || '').replace(/_/g, ' ').replace(/"/g, '""');
            var controls = (row.dataset.controls || '').replace(/"/g, '""');
            csvRows.push([
                '"' + ref + '"',
                '"' + title + '"',
                '"' + category + '"',
                '"' + owner + '"',
                '"' + likelihood + '"',
                '"' + impact + '"',
                inherent,
                residual,
                '"' + treatment + '"',
                '"' + status + '"',
                '"' + controls + '"'
            ].join(','));
        });
        var blob = new Blob([csvRows.join('\r\n')], {type: 'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'risk-register-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    // Initial render
    renderTable();
})();
</script>

<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
