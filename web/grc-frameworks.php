<?php
/**
 * GRC Unified Compliance Engine - Frameworks
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Lists all compliance frameworks with compliance percentages, requirement
 * trees, and mapped controls per requirement. Admins and GRC users can
 * add/edit framework requirements. Auditors get read-only access.
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
    die(t('grc_frameworks.access_denied'));
}

$readOnly = $isAuditor && !$isAdmin && !$isCyberGRC;

$grc = GRCService::getInstance();
$aiEnabled = AIPlatformService::getInstance()->isEnabled();
$msg = '';
$msgType = '';

// POST handlers for framework requirement management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc_frameworks.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete_framework') {
            $fwId = (int)($_POST['framework_id'] ?? 0);
            if ($fwId > 0) {
                // Prevent deletion of template-generated frameworks
                $fwCheck = $grc->getFramework($fwId);
                if ($fwCheck && !empty($fwCheck['generated_from'])) {
                    $msg = t('grc_frameworks.template_no_delete');
                    $msgType = 'danger';
                } else {
                    $result = $grc->deleteFramework($fwId);
                    if ($result['success']) {
                        $msg = t('grc_frameworks.msg_framework_deleted_prefix') . e($result['name']) . t('grc_frameworks.msg_framework_deleted_suffix');
                        $msgType = 'success';
                    } else {
                        $msg = e($result['error']);
                        $msgType = 'danger';
                    }
                }
            }
        } elseif ($action === 'regenerate_framework') {
            $fwId = (int)($_POST['framework_id'] ?? 0);
            if ($fwId > 0) {
                $result = $grc->regenerateFramework($fwId);
                if ($result['success']) {
                    $msg = t('grc_frameworks.msg_regenerated_prefix') . (int)$result['count'] . t('grc_frameworks.msg_regenerated_suffix');
                    $msgType = 'success';
                } else {
                    $msg = e($result['error']);
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'seed_descriptions') {
            // Seed descriptions from catalog data for a specific framework
            $fwId = (int)($_POST['framework_id'] ?? 0);
            if ($fwId > 0) {
                $fw = $grc->getFramework($fwId);
                if ($fw) {
                    $catalogCode = $fw['generated_from'] ?: $fw['code'];
                    $catalogDir = __DIR__ . '/includes/data/catalog/';
                    $catalogData = null;
                    foreach (glob($catalogDir . '*.php') as $catFile) {
                        $cat = include $catFile;
                        if (is_array($cat) && ($cat['code'] ?? '') === $catalogCode) {
                            $catalogData = $cat;
                            break;
                        }
                    }
                    if ($catalogData && !empty($catalogData['requirements'])) {
                        // Build ref => description lookup from catalog
                        $catalogDescs = [];
                        foreach ($catalogData['requirements'] as $cr) {
                            $ref = trim($cr['ref'] ?? '');
                            $desc = trim($cr['description'] ?? '');
                            if ($ref !== '' && $desc !== '') {
                                $catalogDescs[$ref] = $desc;
                            }
                        }
                        // Update requirements that are missing descriptions
                        $reqs = $grc->getAllRequirements($fwId);
                        $updated = 0;
                        foreach ($reqs as $r) {
                            if (!empty(trim($r['description'] ?? ''))) continue;
                            $ref = $r['requirement_ref'];
                            if (isset($catalogDescs[$ref])) {
                                $db->query(
                                    'UPDATE grc_framework_requirements SET description = :desc, updated_at = NOW() WHERE id = :id',
                                    [':desc' => $catalogDescs[$ref], ':id' => (int)$r['id']]
                                );
                                $updated++;
                            }
                        }
                        if ($updated > 0) {
                            $msg = t('grc_frameworks.msg_seeded_prefix') . $updated . t('grc_frameworks.msg_seeded_suffix');
                            $msgType = 'success';
                        } else {
                            $msg = t('grc_frameworks.catalog_no_descriptions');
                            $msgType = 'warning';
                        }
                    } else {
                        $msg = t('grc_frameworks.msg_no_catalog_prefix') . e($catalogCode) . t('grc_frameworks.msg_no_catalog_suffix');
                        $msgType = 'danger';
                    }
                }
            }
        } elseif ($action === 'add_requirement') {
            $frameworkId = (int)($_POST['framework_id'] ?? 0);
            $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $reqRef = trim($_POST['requirement_ref'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($frameworkId > 0 && $reqRef !== '' && $title !== '') {
                $insertData = [
                    'framework_id' => $frameworkId,
                    'requirement_ref' => $reqRef,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'sort_order' => $sortOrder,
                ];
                if ($parentId !== null) {
                    $insertData['parent_id'] = $parentId;
                }
                $db->insert('grc_framework_requirements', $insertData);
                $msg = t('grc_frameworks.requirement_added');
                $msgType = 'success';
            } else {
                $msg = t('grc_frameworks.fw_ref_title_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'generate_from_catalog') {
            $catalogCode = trim($_POST['catalog_code'] ?? '');
            $scopeId = !empty($_POST['scope_id']) ? (int)$_POST['scope_id'] : null;
            if ($catalogCode !== '') {
                $result = $grc->generateFromCatalog($catalogCode, $scopeId, $user['id']);
                if ($result['success']) {
                    $msg = t('grc_frameworks.msg_generated_prefix') . (int)$result['count'] . t('grc_frameworks.msg_generated_middle') . '<a href="?framework_id=' . (int)$result['framework_id'] . '">' . t('grc_frameworks.view_requirements') . '</a>';
                    $msgType = 'success';
                } else {
                    $msg = e($result['error']);
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'edit_requirement') {
            $reqId = (int)($_POST['requirement_id'] ?? 0);
            $reqRef = trim($_POST['requirement_ref'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($reqId > 0 && $reqRef !== '' && $title !== '') {
                $db->update('grc_framework_requirements', [
                    'requirement_ref' => $reqRef,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'sort_order' => $sortOrder,
                ], 'id = :id', [':id' => $reqId]);
                // PRG redirect back to framework view
                $editedReq = $db->fetchOne('SELECT framework_id FROM grc_framework_requirements WHERE id = :id', [':id' => $reqId]);
                $fwId = $editedReq ? (int)$editedReq['framework_id'] : 0;
                $_SESSION['flash_msg'] = t('grc_frameworks.requirement_updated');
                $_SESSION['flash_type'] = 'success';
                header('Location: grc-frameworks.php?framework_id=' . $fwId);
                exit;
            } else {
                $msg = t('grc_frameworks.ref_title_required');
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

// Pick up flash messages from PRG redirects
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Load data
$scopes = $grc->getScopes();
$frameworks = $grc->getFrameworks(false);
$frameworkStatus = [];
foreach ($frameworks as $fw) {
    $frameworkStatus[$fw['id']] = $grc->getFrameworkComplianceStatus((int)$fw['id']);
}

// Load all assessments for the dropdown
$allAssessments = [];
try {
    $uas = UnifiedAssessmentService::getInstance();
    $allAssessments = $uas->getAssessments(null, true);
} catch (Exception $e) {}
$selectedAssessmentId = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
if ($selectedAssessmentId === 0 && !empty($allAssessments)) {
    $selectedAssessmentId = (int)$allAssessments[0]['id']; // allAssessments is ordered by created_at DESC
}

// Load assessment-based compliance data (selected or latest assessment)
$assessmentCompliance = [];
try {
    if (!isset($uas)) $uas = UnifiedAssessmentService::getInstance();
    $latestAssessment = $selectedAssessmentId > 0
        ? $db->fetchOne("SELECT id FROM grc_assessments WHERE id = :id AND status != 'archived'", [':id' => $selectedAssessmentId])
        : $db->fetchOne("SELECT id FROM grc_assessments WHERE status != 'archived' ORDER BY created_at DESC LIMIT 1");
    if ($latestAssessment) {
        $fwCompliance = $uas->calculateFrameworkCompliance((int)$latestAssessment['id']);
        if (!empty($fwCompliance)) {
            foreach ($fwCompliance as $fc) {
                $code = $fc['framework_code'] ?? $fc['code'] ?? '';
                if ($code !== '') {
                    $assessmentCompliance[$code] = $fc;
                }
            }
        }
    }
} catch (Exception $e) {
    // Unified assessment tables may not exist yet
}

// Selected framework for requirement tree
$selectedFrameworkId = isset($_GET['framework_id']) ? (int)$_GET['framework_id'] : 0;
$selectedFramework = null;
$requirements = [];
if ($selectedFrameworkId > 0) {
    $selectedFramework = $grc->getFramework($selectedFrameworkId);
    if ($selectedFramework) {
        $requirements = $grc->getAllRequirements($selectedFrameworkId);
    }
}

// Edit mode for a requirement
$editReqId = isset($_GET['edit_req']) ? (int)$_GET['edit_req'] : 0;
$editReq = null;
if ($editReqId > 0 && !$readOnly) {
    $editReq = $grc->getRequirement($editReqId);
}

// Risk Heatmap data (5x5 grid from grc_risk_register)
$likelihoodNumeric = ['rare' => 1, 'unlikely' => 2, 'possible' => 3, 'likely' => 4, 'almost_certain' => 5];
$impactNumeric = ['insignificant' => 1, 'minor' => 2, 'moderate' => 3, 'major' => 4, 'catastrophic' => 5];
$fwRisks = $db->fetchAll('SELECT likelihood, impact FROM grc_risk_register WHERE status != :s', [':s' => 'closed']);
$heatmapData = [];
for ($hl = 1; $hl <= 5; $hl++) {
    for ($hi = 1; $hi <= 5; $hi++) {
        $heatmapData[$hl][$hi] = 0;
    }
}
foreach ($fwRisks as $hr) {
    $hlNum = $likelihoodNumeric[$hr['likelihood'] ?? 'possible'] ?? 3;
    $hiNum = $impactNumeric[$hr['impact'] ?? 'moderate'] ?? 3;
    $heatmapData[$hlNum][$hiNum]++;
}
$heatmapTotal = count($fwRisks);

$currentPage = 'grc_frameworks';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc_frameworks.page_title')); ?></title>
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

        /* === Summary Stats Bar === */
        .stats-bar { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
        .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 22px; flex: 1; min-width: 140px; }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: #111; line-height: 1; }
        .stat-card .stat-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 6px; font-weight: 500; }
        .stat-card.accent { border-left: 4px solid var(--theme-button-color, #35a0a3); }

        /* === Year Pills === */
        .year-pills { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .year-pill { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; border: 1px solid #d1d5db; background: #fff; color: #374151; cursor: pointer; text-decoration: none; transition: all 0.15s; }
        .year-pill:hover { background: #f3f4f6; border-color: #9ca3af; }
        .year-pill.active { background: var(--theme-button-color, #35a0a3); color: #fff; border-color: var(--theme-button-color, #35a0a3); }

        /* === Scope Groups === */
        .scope-group { margin-bottom: 32px; }
        .scope-group-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #e5e7eb; cursor: pointer; user-select: none; }
        .scope-group-header .scope-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .scope-group-header .scope-title { font-size: 16px; font-weight: 600; color: #333; }
        .scope-group-header .scope-count { font-size: 12px; color: #9ca3af; font-weight: 400; margin-left: 4px; }
        .scope-group-header .scope-chevron { margin-left: auto; font-size: 18px; color: #9ca3af; transition: transform 0.2s; }
        .scope-group.collapsed .scope-chevron { transform: rotate(-90deg); }
        .scope-group.collapsed .framework-grid { display: none; }

        /* === Framework Cards (Redesigned) === */
        .framework-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }
        .fw-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 0; overflow: hidden; transition: box-shadow 0.2s, transform 0.15s; position: relative; }
        .fw-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .fw-card-link { display: block; text-decoration: none; color: inherit; padding: 22px 22px 0; }
        .fw-card-top { display: flex; align-items: flex-start; gap: 16px; }
        .fw-donut { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
        .fw-donut svg { width: 64px; height: 64px; transform: rotate(-90deg); }
        .fw-donut .donut-track { fill: none; stroke: #e5e7eb; stroke-width: 6; }
        .fw-donut .donut-fill { fill: none; stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset 0.8s ease; }
        .fw-donut .donut-pct { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #333; }
        .fw-card-info { flex: 1; min-width: 0; }
        .fw-card-code { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .fw-card-name { font-size: 15px; font-weight: 600; color: #1f2937; margin: 4px 0 0; line-height: 1.3; }
        .fw-card-scope { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 10px; color: #fff; margin-top: 6px; }
        .fw-card-maturity { display: flex; justify-content: space-between; align-items: center; padding: 6px 22px; border-top: 1px solid #f3f4f6; margin-top: 16px; background: #f0fdf4; }
        .maturity-label { font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .maturity-value { font-size: 15px; font-weight: 700; color: #059669; }
        .fw-card-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border-top: 1px solid #f3f4f6; }
        .fw-metric { text-align: center; padding: 12px 4px; border-right: 1px solid #f3f4f6; }
        .fw-metric:last-child { border-right: none; }
        .fw-metric-val { font-size: 16px; font-weight: 700; color: #111; }
        .fw-metric-val.green { color: #059669; }
        .fw-metric-val.amber { color: #d97706; }
        .fw-metric-val.red { color: #dc2626; }
        .fw-metric-val.gray { color: #6b7280; }
        .fw-metric-lbl { font-size: 9px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .fw-card-actions { display: flex; gap: 6px; padding: 12px 22px; border-top: 1px solid #f3f4f6; background: #fafbfc; }
        .fw-card-actions form { display: inline; }
        .fw-card-actions .btn-sm { font-size: 11px; padding: 4px 10px; }

        /* === Inline Scope Assignment === */
        .scope-assign { display: inline-flex; align-items: center; gap: 4px; margin-top: 6px; }
        .scope-assign select { font-size: 11px; padding: 2px 6px; border: 1px dashed #d1d5db; border-radius: 6px; background: #f9fafb; color: #6b7280; cursor: pointer; appearance: auto; }
        .scope-assign select:focus { border-color: var(--theme-button-color); outline: none; }

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .req-indent-0 td:first-child { padding-left: 16px; font-weight: 600; }
        .req-indent-1 td:first-child { padding-left: 36px; }
        .req-indent-2 td:first-child { padding-left: 56px; }
        .req-indent-3 td:first-child { padding-left: 76px; }

        .badge-count { display: inline-block; background: #e5e7eb; color: #374151; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500; }
        .badge-count.has-controls { background: #d1fae5; color: #065f46; }

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

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .scope-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .scope-table th { background: #f3f4f6; padding: 10px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .scope-table td { padding: 10px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: middle; }
        .scope-table tr:hover td { background: #f9fafb; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { opacity: 0.9; }

        /* Risk Heatmap */
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

        @media (max-width: 900px) {
            .framework-grid { grid-template-columns: 1fr; }
            .stats-bar { flex-direction: column; }
            .grc-form .form-row { grid-template-columns: 1fr; }
            .fw-card-metrics { grid-template-columns: repeat(2, 1fr); }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc_frameworks.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc_frameworks.subheading')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo $msg; ?></div>
            <?php endif; ?>

            <?php if ($selectedFramework): ?>
            <!-- Requirement Tree View -->
            <a href="grc-frameworks.php" class="back-link">&larr; <?php echo e(t('grc_frameworks.back_to_all')); ?></a>
            <h2 class="section-header"><?php echo e($selectedFramework['name']); ?> (<?php echo e($selectedFramework['code']); ?>)</h2>
            <p style="color:#6b7280;font-size:13px;margin:-10px 0 20px;"><?php echo e($selectedFramework['description'] ?? ''); ?></p>

            <?php if (!$readOnly): ?>
            <!-- Add Requirement Form -->
            <details class="grc-form" <?php echo $editReq ? 'open' : ''; ?>>
                <summary style="cursor:pointer;font-weight:500;font-size:14px;color:#333;">
                    <?php echo e($editReq ? t('grc_frameworks.edit_requirement') : t('grc_frameworks.add_requirement')); ?>
                </summary>
                <form method="post" style="margin-top:16px;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editReq ? 'edit_requirement' : 'add_requirement'; ?>">
                    <?php if ($editReq): ?>
                    <input type="hidden" name="requirement_id" value="<?php echo (int)$editReq['id']; ?>">
                    <?php else: ?>
                    <input type="hidden" name="framework_id" value="<?php echo (int)$selectedFrameworkId; ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div>
                            <label for="requirement_ref"><?php echo e(t('grc_frameworks.requirement_reference')); ?></label>
                            <input type="text" id="requirement_ref" name="requirement_ref" value="<?php echo e($editReq['requirement_ref'] ?? ''); ?>" required placeholder="<?php echo e(t('grc_frameworks.ref_placeholder')); ?>">
                        </div>
                        <div>
                            <label for="sort_order"><?php echo e(t('grc_frameworks.sort_order')); ?></label>
                            <input type="number" id="sort_order" name="sort_order" value="<?php echo (int)($editReq['sort_order'] ?? 0); ?>">
                        </div>
                    </div>

                    <label for="title"><?php echo e(t('grc_frameworks.title')); ?></label>
                    <input type="text" id="title" name="title" value="<?php echo e($editReq['title'] ?? ''); ?>" required placeholder="<?php echo e(t('grc_frameworks.title_placeholder')); ?>">

                    <label for="description"><?php echo e(t('grc_frameworks.description')); ?></label>
                    <textarea id="description" name="description" placeholder="<?php echo e(t('grc_frameworks.description_placeholder')); ?>"><?php echo e($editReq['description'] ?? ''); ?></textarea>

                    <?php if (!$editReq): ?>
                    <label for="parent_id"><?php echo e(t('grc_frameworks.parent_requirement')); ?></label>
                    <select id="parent_id" name="parent_id">
                        <option value="">-- <?php echo e(t('grc_frameworks.top_level')); ?> --</option>
                        <?php foreach ($requirements as $r): ?>
                        <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['requirement_ref']); ?> - <?php echo e($r['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary"><?php echo e($editReq ? t('grc_frameworks.update_requirement') : t('grc_frameworks.add_requirement')); ?></button>
                    <?php if ($editReq): ?>
                    <a href="grc-frameworks.php?framework_id=<?php echo (int)$selectedFrameworkId; ?>" class="btn btn-outline"><?php echo e(t('grc_frameworks.cancel')); ?></a>
                    <?php endif; ?>
                </form>
            </details>
            <?php endif; ?>

            <?php
            // Count requirements missing descriptions
            $missingDescCount = 0;
            foreach ($requirements as $r) {
                if (empty(trim($r['description'] ?? ''))) $missingDescCount++;
            }
            // Check if catalog exists for this framework
            $hasCatalog = false;
            if ($missingDescCount > 0 && $selectedFramework) {
                $catCode = $selectedFramework['generated_from'] ?: $selectedFramework['code'];
                foreach (glob(__DIR__ . '/includes/data/catalog/*.php') as $cf) {
                    $cd = include $cf;
                    if (is_array($cd) && ($cd['code'] ?? '') === $catCode) { $hasCatalog = true; break; }
                }
            }
            ?>

            <!-- Toolbar above requirements table -->
            <?php if (!$readOnly && !empty($requirements) && $missingDescCount > 0): ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                <?php if ($hasCatalog): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="seed_descriptions">
                    <input type="hidden" name="framework_id" value="<?php echo (int)$selectedFrameworkId; ?>">
                    <button type="submit" class="btn btn-sm btn-primary"><?php echo e(t('grc_frameworks.seed_from_catalog')); ?> (<?php echo $missingDescCount; ?> <?php echo e(t('grc_frameworks.missing_suffix')); ?>)</button>
                </form>
                <?php endif; ?>
                <?php if ($aiEnabled): ?>
                <button type="button" class="btn btn-sm" id="btnGetDescriptions"
                    style="background:#f5f3ff;color:#6d28d9;border:1px solid #8b5cf6;"
                    data-framework-id="<?php echo (int)$selectedFrameworkId; ?>">
                    <?php echo e(t('grc_frameworks.seed_from_assistant')); ?> (<?php echo $missingDescCount; ?> <?php echo e(t('grc_frameworks.missing_suffix')); ?>)
                </button>
                <span id="descProgress" style="font-size:12px;color:#6b7280;display:none;"></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Requirements Table -->
            <table class="grc-table">
                <thead>
                    <tr>
                        <th style="width:90px;"><?php echo e(t('grc_frameworks.col_reference')); ?></th>
                        <th style="width:22%;"><?php echo e(t('grc_frameworks.col_title')); ?></th>
                        <th><?php echo e(t('grc_frameworks.col_description')); ?></th>
                        <th style="width:70px;"><?php echo e(t('grc_frameworks.col_controls')); ?></th>
                        <th style="width:120px;"><?php echo e(t('grc_frameworks.col_control_refs')); ?></th>
                        <?php if (!$readOnly): ?><th style="width:60px;"><?php echo e(t('grc_frameworks.col_actions')); ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requirements)): ?>
                    <tr><td colspan="<?php echo $readOnly ? 5 : 6; ?>" style="text-align:center;color:#6b7280;padding:30px;"><?php echo e(t('grc_frameworks.no_requirements')); ?></td></tr>
                    <?php else: ?>
                    <?php
                    // Build a depth map for indentation
                    $depthMap = [];
                    foreach ($requirements as $r) {
                        if (empty($r['parent_id'])) {
                            $depthMap[$r['id']] = 0;
                        } else {
                            $depthMap[$r['id']] = isset($depthMap[$r['parent_id']]) ? $depthMap[$r['parent_id']] + 1 : 1;
                        }
                    }
                    ?>
                    <?php foreach ($requirements as $r):
                        $depth = $depthMap[$r['id']] ?? 0;
                        if ($depth > 3) $depth = 3;
                        $controlCount = (int)$r['mapped_controls'];
                        $desc = trim($r['description'] ?? '');
                    ?>
                    <tr class="req-indent-<?php echo $depth; ?>">
                        <td><?php echo e($r['requirement_ref']); ?></td>
                        <td id="title-<?php echo (int)$r['id']; ?>"><?php echo e($r['title']); ?></td>
                        <td id="desc-<?php echo (int)$r['id']; ?>" style="font-size:12px;color:<?php echo $desc !== '' ? '#374151' : '#9ca3af'; ?>;"><?php echo $desc !== '' ? e($desc) : t('grc_frameworks.no_description'); ?></td>
                        <td>
                            <span class="badge-count <?php echo $controlCount > 0 ? 'has-controls' : ''; ?>">
                                <?php echo $controlCount; ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:#6b7280;"><?php echo e($r['control_refs'] ?? '-'); ?></td>
                        <?php if (!$readOnly): ?>
                        <td>
                            <a href="grc-frameworks.php?framework_id=<?php echo (int)$selectedFrameworkId; ?>&amp;edit_req=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline"><?php echo e(t('grc_frameworks.edit')); ?></a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php else: ?>
            <?php
            $currentYear = (int)date('Y');
            $filterYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
            $scopeLookup = [];
            foreach ($scopes as $s) $scopeLookup[$s['id']] = $s;
            $availableYears = [];
            try {
                $yearRows = $db->fetchAll('SELECT DISTINCT compliance_year FROM grc_frameworks ORDER BY compliance_year DESC');
                foreach ($yearRows as $yr) $availableYears[] = (int)$yr['compliance_year'];
            } catch (Exception $e) { $availableYears = [$currentYear]; }
            if (!in_array($currentYear, $availableYears)) array_unshift($availableYears, $currentYear);
            rsort($availableYears);

            // Filter frameworks by year and compute stats
            $yearFrameworks = [];
            $totalPct = 0; $needAttention = 0;
            foreach ($frameworks as $fw) {
                if ((int)($fw['compliance_year'] ?? $currentYear) !== $filterYear) continue;
                $fs = $frameworkStatus[$fw['id']] ?? [];
                $fw['_status'] = $fs;
                $pct = $fs['compliance_percentage'] ?? 0;
                $fw['_pct'] = $pct;
                $totalPct += $pct;
                if ($pct < 100 && !empty($fs['total_requirements'])) $needAttention++;
                $yearFrameworks[] = $fw;
            }
            $avgPct = count($yearFrameworks) > 0 ? round($totalPct / count($yearFrameworks)) : 0;
            $totalReqs = 0;
            foreach ($yearFrameworks as $fw) $totalReqs += ($fw['_status']['total_requirements'] ?? 0);

            // Filter frameworks: only show those with question mappings
            if ($selectedAssessmentId > 0) {
                // Assessment selected: only frameworks with mapped questions in that assessment
                $mappedFwIds = $db->fetchAll(
                    'SELECT DISTINCT qfm.framework_id FROM grc_question_framework_map qfm
                     JOIN grc_assessment_responses r ON r.question_id = qfm.question_id AND r.assessment_id = :aid',
                    [':aid' => $selectedAssessmentId]
                );
            } else {
                // No assessment: only frameworks that have question mappings at all
                $mappedFwIds = $db->fetchAll(
                    'SELECT DISTINCT framework_id FROM grc_question_framework_map'
                );
            }
            $mappedFwIdSet = array_column($mappedFwIds, 'framework_id');
            $yearFrameworks = array_values(array_filter($yearFrameworks, function($fw) use ($mappedFwIdSet) {
                return in_array($fw['id'], $mappedFwIdSet);
            }));
            // Recalculate stats
            $totalPct = 0; $needAttention = 0;
            foreach ($yearFrameworks as $fw) {
                $totalPct += $fw['_pct'];
                if ($fw['_pct'] < 100 && !empty($fw['_status']['total_requirements'])) $needAttention++;
            }
            $avgPct = count($yearFrameworks) > 0 ? round($totalPct / count($yearFrameworks)) : 0;
            $totalReqs = 0;
            foreach ($yearFrameworks as $fw) $totalReqs += ($fw['_status']['total_requirements'] ?? 0);

            // Group frameworks by scope (only used when no assessment selected)
            $scopeGroups = [];
            $unscopedFrameworks = [];
            if (!$selectedAssessmentId) {
                foreach ($yearFrameworks as $fw) {
                    $sid = (int)($fw['scope_id'] ?? 0);
                    if ($sid > 0 && isset($scopeLookup[$sid])) {
                        $scopeGroups[$sid][] = $fw;
                    } else {
                        $unscopedFrameworks[] = $fw;
                    }
                }
            }
            ?>

            <!-- Summary Stats Bar -->
            <div class="stats-bar">
                <div class="stat-card accent">
                    <div class="stat-value"><?php echo count($yearFrameworks); ?></div>
                    <div class="stat-label"><?php echo e(t('grc_frameworks.stat_frameworks')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $avgPct; ?>%</div>
                    <div class="stat-label"><?php echo e(t('grc_frameworks.stat_avg_readiness')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($totalReqs); ?></div>
                    <div class="stat-label"><?php echo e(t('grc_frameworks.stat_total_requirements')); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $needAttention; ?></div>
                    <div class="stat-label"><?php echo e(t('grc_frameworks.stat_need_attention')); ?></div>
                </div>
                <div style="display:flex;align-items:center;margin-left:auto;">
                    <div class="year-pills">
                        <?php foreach ($availableYears as $yr): ?>
                        <a href="grc-frameworks.php?year=<?php echo $yr; ?>" class="year-pill <?php echo $filterYear === $yr ? 'active' : ''; ?>"><?php echo $yr; ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($allAssessments)): ?>
            <div style="margin-bottom:24px;padding:14px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <label style="font-size:13px;font-weight:600;color:#374151;white-space:nowrap;"><?php echo e(t('grc_frameworks.assessment_label')); ?></label>
                    <select id="assessmentSelector" style="flex:1;max-width:500px;padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">-- <?php echo e(t('grc_frameworks.select_assessment')); ?> --</option>
                        <?php foreach ($allAssessments as $aItem): ?>
                        <option value="<?php echo (int)$aItem['id']; ?>" <?php echo $selectedAssessmentId === (int)$aItem['id'] ? 'selected' : ''; ?>>
                            <?php echo e($aItem['assessment_ref'] . ' — ' . $aItem['title'] . ' (' . ucfirst($aItem['status']) . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                if ($selectedAssessmentId > 0):
                    $selAssessment = $uas->getAssessment($selectedAssessmentId);
                    $selScopeName = '';
                    if ($selAssessment && !empty($selAssessment['scope_id'])) {
                        $selScope = $db->fetchOne('SELECT name FROM grc_scopes WHERE id = :id', [':id' => $selAssessment['scope_id']]);
                        $selScopeName = $selScope ? $selScope['name'] : '';
                    }
                ?>
                <div style="display:flex;align-items:center;gap:20px;margin-top:10px;padding-top:10px;border-top:1px solid #f3f4f6;font-size:12px;color:#6b7280;flex-wrap:wrap;">
                    <?php if ($selScopeName): ?>
                    <span><strong style="color:#374151;"><?php echo e(t('grc_frameworks.scope')); ?></strong> <?php echo e($selScopeName); ?></span>
                    <?php endif; ?>
                    <span><strong style="color:#374151;"><?php echo e(t('grc_frameworks.status')); ?></strong> <?php echo ucfirst($selAssessment['status'] ?? ''); ?></span>
                    <?php if (!empty($selAssessment['lead_auditor_name'])): ?>
                    <span><strong style="color:#374151;"><?php echo e(t('grc_frameworks.lead_auditor')); ?></strong> <?php echo e($selAssessment['lead_auditor_name']); ?></span>
                    <?php endif; ?>
                    <span style="margin-left:auto;color:#4f46e5;font-weight:500;"><?php echo e(t('grc_frameworks.select_framework_hint')); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (empty($yearFrameworks)): ?>
            <div style="text-align:center;padding:60px 20px;color:#6b7280;">
                <div style="font-size:48px;opacity:0.3;margin-bottom:12px;">&#9744;</div>
                <p style="font-size:15px;margin:0;"><?php echo e(t('grc_frameworks.no_frameworks_for')); ?> <?php echo (int)$filterYear; ?>.</p>
                <p style="font-size:13px;margin:6px 0 0;opacity:0.7;"><?php echo e(t('grc_frameworks.template_autopopulated')); ?></p>
            </div>
            <?php else: ?>

            <?php
            // Build catalog code lookup so we know which frameworks can be re-populated
            $catalogCodes = [];
            foreach (glob(__DIR__ . '/includes/data/catalog/*.php') as $_catFile) {
                $_catData = include $_catFile;
                if (is_array($_catData) && !empty($_catData['code'])) {
                    $catalogCodes[] = $_catData['code'];
                }
            }

            // Render a framework card (reusable)
            // Using a closure to avoid repeating card HTML
            $renderCard = function($fw) use ($csrfToken, $readOnly, $currentYear, $catalogCodes, $assessmentCompliance, $selectedAssessmentId) {
                $cardLink = $selectedAssessmentId > 0
                    ? 'grc-framework-report.php?framework_id=' . (int)$fw['id'] . '&assessment_id=' . $selectedAssessmentId
                    : 'grc-frameworks.php?framework_id=' . (int)$fw['id'];

                // Use assessment data if available, otherwise fall back to control-based status
                $afc = $assessmentCompliance[$fw['code']] ?? null;
                if ($afc) {
                    $pct = (int)($afc['compliance_pct'] ?? 0);
                    $conforming = (int)($afc['conforming'] ?? 0);
                    $partial = (int)($afc['partial'] ?? 0);
                    $nonConforming = (int)($afc['non_conforming'] ?? 0);
                    $notApplicable = (int)($afc['not_applicable'] ?? 0);
                    $totalMapped = (int)($afc['total_mapped'] ?? 0);
                    $avgMaturity = $afc['avg_maturity'] ?? null;
                    $maturityCount = (int)($afc['maturity_count'] ?? 0);
                } else {
                    $fs = $fw['_status'];
                    $pct = $fw['_pct'];
                    $conforming = $fs['implemented'] ?? 0;
                    $partial = $fs['partial'] ?? 0;
                    $nonConforming = $fs['no_control'] ?? 0;
                    $notApplicable = 0;
                    $totalMapped = $fs['total_requirements'] ?? 0;
                    $avgMaturity = null;
                    $maturityCount = 0;
                }
                $color = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
                $circumference = 2 * M_PI * 26;
                $dashOffset = $circumference - ($circumference * $pct / 100);
                ?>
                <div class="fw-card">
                    <a href="<?php echo $cardLink; ?>" class="fw-card-link">
                        <div class="fw-card-top">
                            <div class="fw-donut">
                                <svg viewBox="0 0 64 64">
                                    <circle class="donut-track" cx="32" cy="32" r="26"/>
                                    <circle class="donut-fill" cx="32" cy="32" r="26" stroke="<?php echo $color; ?>" stroke-dasharray="<?php echo round($circumference, 2); ?>" stroke-dashoffset="<?php echo round($dashOffset, 2); ?>"/>
                                </svg>
                                <span class="donut-pct"><?php echo $pct; ?>%</span>
                            </div>
                            <div class="fw-card-info">
                                <div class="fw-card-code"><?php echo e($fw['code']); ?> <?php echo e($fw['version'] ?? ''); ?></div>
                                <div class="fw-card-name"><?php echo e($fw['name']); ?></div>
                            </div>
                        </div>
                    </a>
                    <?php if ($avgMaturity !== null): ?>
                    <div class="fw-card-maturity">
                        <span class="maturity-label"><?php echo e(t('grc_frameworks.avg_maturity')); ?></span>
                        <span class="maturity-value"><?php echo number_format($avgMaturity, 2); ?> / 4.00</span>
                    </div>
                    <?php endif; ?>
                    <?php if ($totalMapped > 0): ?>
                    <a href="<?php echo $cardLink; ?>" style="text-decoration:none;color:inherit;">
                    <div class="fw-card-metrics">
                        <div class="fw-metric">
                            <div class="fw-metric-val green"><?php echo $conforming; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc_frameworks.metric_conforming')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val amber"><?php echo $partial; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc_frameworks.metric_partial')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val <?php echo $nonConforming > 0 ? 'red' : 'gray'; ?>"><?php echo $nonConforming; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc_frameworks.metric_non_conforming')); ?></div>
                        </div>
                        <div class="fw-metric">
                            <div class="fw-metric-val gray"><?php echo $totalMapped; ?></div>
                            <div class="fw-metric-lbl"><?php echo e(t('grc_frameworks.metric_total_mapped')); ?></div>
                        </div>
                    </div>
                    </a>
                    <?php else: ?>
                    <div style="padding:14px 22px;border-top:1px solid #f3f4f6;text-align:center;color:#9ca3af;font-size:12px;"><?php echo e(t('grc_frameworks.no_requirements_loaded')); ?></div>
                    <?php endif; ?>
                </div>
            <?php }; // end $renderCard ?>

            <div class="framework-grid">
                <?php foreach ($yearFrameworks as $fw) { $renderCard($fw); } ?>
            </div>

            <?php endif; // end yearFrameworks check ?>

            <!-- Risk Heatmap -->
            <?php if ($heatmapTotal > 0): ?>
            <div class="heatmap-wrapper">
                <h3><?php echo e(t('grc_frameworks.risk_heatmap')); ?></h3>
                <div style="text-align:center;font-size:11px;color:#6b7280;margin-bottom:4px;"><?php echo e(t('grc_frameworks.impact')); ?> &rarr;</div>
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
                    for ($hl = 5; $hl >= 1; $hl--):
                    ?>
                    <div class="heatmap-label"><?php echo $hl; ?></div>
                    <?php for ($hi = 1; $hi <= 5; $hi++): ?>
                    <div class="heatmap-cell <?php echo getHeatColor($hl, $hi); ?>"><?php echo $heatmapData[$hl][$hi] > 0 ? $heatmapData[$hl][$hi] : ''; ?></div>
                    <?php endfor; ?>
                    <?php endfor; ?>
                    <div class="heatmap-label"></div>
                    <?php for ($hi = 1; $hi <= 5; $hi++): ?>
                    <div class="heatmap-label"><?php echo $hi; ?></div>
                    <?php endfor; ?>
                </div>
                <div style="text-align:left;font-size:11px;color:#6b7280;margin-top:4px;"><?php echo e(t('grc_frameworks.likelihood')); ?> &uarr;</div>
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
                            <img src="<?php echo e($theme['footer_logo_url']); ?>" alt="<?php echo e(t('grc_frameworks.footer_logo_alt')); ?>" style="max-height: 45px;">
                        </a>
                    <?php endif; ?>
                    <span style="color: rgba(255,255,255,0.7); font-size: 13px;"><?php echo e(getAppVersion()); ?></span>
                </div>
                <p class="rights" style="margin: 0;">
                    <span><?php $cn = getAppConfig('company_name', ''); if ($cn) echo e($cn) . ' '; ?>&copy;&nbsp;</span>
                    <span class="copyright-year"><?php echo date('Y'); ?></span>
                    <span>.&nbsp;</span>
                    <span><?php echo e(t('grc_frameworks.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    // Assessment selector dropdown
    var asSel = document.getElementById('assessmentSelector');
    if (asSel) {
        asSel.addEventListener('change', function() {
            var baseUrl = 'grc-frameworks.php?year=<?php echo $filterYear; ?>';
            if (this.value) {
                window.location = baseUrl + '&assessment_id=' + this.value;
            } else {
                window.location = baseUrl;
            }
        });
    }

    // Scope-assign auto-submit on change
    document.querySelectorAll('.scope-auto-submit').forEach(function(sel) {
        sel.addEventListener('change', function() { this.form.submit(); });
    });

    // Confirm dialogs for forms with data-confirm
    document.addEventListener('submit', function(e) {
        var form = e.target.closest('form[data-confirm]');
        if (form) {
            var msg = form.getAttribute('data-confirm');
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    // Scope group toggle
    document.addEventListener('click', function(e) {
        var header = e.target.closest('[data-action="toggle-scope-group"]');
        if (header) {
            header.parentElement.classList.toggle('collapsed');
        }
    });

    // Seed from Assistant (batch)
    var btnGetDesc = document.getElementById('btnGetDescriptions');
    if (btnGetDesc) {
        var _csrfToken = <?php echo json_encode($csrfToken); ?>;
        btnGetDesc.addEventListener('click', function() {
            var btn = this;
            var progressEl = document.getElementById('descProgress');
            var fwId = btn.getAttribute('data-framework-id');

            btn.disabled = true;
            btn.textContent = <?php echo json_encode(t('grc_frameworks.js_generating_descriptions')); ?>;
            progressEl.style.display = 'inline';
            progressEl.textContent = <?php echo json_encode(t('grc_frameworks.js_may_take_a_minute')); ?>;

            fetch('api/grc-unified-assessment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'generate_requirement_descriptions',
                    framework_id: parseInt(fwId),
                    csrf_token: _csrfToken
                })
            })
            .then(function(r) {
                if (!r.ok) {
                    if (r.status === 429) throw new Error(<?php echo json_encode(t('grc_frameworks.js_rate_limit')); ?>);
                    throw new Error(<?php echo json_encode(t('grc_frameworks.js_server_error_prefix')); ?> + r.status + <?php echo json_encode(t('grc_frameworks.js_server_error_suffix')); ?>);
                }
                return r.json();
            })
            .then(function(resp) {
                if (resp.csrf_token) _csrfToken = resp.csrf_token;
                if (resp.success) {
                    var count = resp.generated || 0;
                    btn.textContent = <?php echo json_encode(t('grc_frameworks.js_done_prefix')); ?> + count + <?php echo json_encode(t('grc_frameworks.js_generated_suffix')); ?>;
                    progressEl.style.display = 'none';
                    if (resp.results && resp.results.length > 0) {
                        resp.results.forEach(function(item) {
                            var descCell = document.getElementById('desc-' + item.id);
                            if (descCell) {
                                descCell.textContent = item.description;
                                descCell.style.color = '#374151';
                            }
                            if (item.title) {
                                var titleCell = document.getElementById('title-' + item.id);
                                if (titleCell) titleCell.textContent = item.title;
                            }
                        });
                    }
                    if (resp.total_missing > count) {
                        progressEl.style.display = 'inline';
                        progressEl.textContent = (resp.total_missing - count) + <?php echo json_encode(t('grc_frameworks.js_remaining_suffix')); ?>;
                        btn.disabled = false;
                        btn.textContent = <?php echo json_encode(t('grc_frameworks.seed_from_assistant')); ?> + ' (' + (resp.total_missing - count) + ' ' + <?php echo json_encode(t('grc_frameworks.js_remaining_word')); ?> + ')';
                    }
                } else {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc_frameworks.seed_from_assistant')); ?>;
                    progressEl.textContent = resp.error || <?php echo json_encode(t('grc_frameworks.js_unknown_error')); ?>;
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = <?php echo json_encode(t('grc_frameworks.seed_from_assistant')); ?>;
                progressEl.textContent = err.message;
            });
        });
    }
})();
</script>
<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
</body>
</html>
