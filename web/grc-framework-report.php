<?php
/**
 * GRC Framework Compliance Report
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Generates an auditor-friendly compliance report for a specific framework
 * within the context of a selected assessment. Shows assessment header info,
 * all framework requirements with mapped questions, responses, evidence,
 * notes, and validation status. Includes print-friendly layout.
 *
 * URL: grc-framework-report.php?framework_id=X&assessment_id=Y
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
    die(t('grc-framework-report.access_denied'));
}

$canDownload = $isAdmin || $isCyberGRC || $isAuditor;

$frameworkId = isset($_GET['framework_id']) ? (int)$_GET['framework_id'] : 0;
$assessmentId = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;

if ($frameworkId <= 0 || $assessmentId <= 0) {
    header('Location: grc-frameworks.php');
    exit;
}

$grc = GRCService::getInstance();
$uas = UnifiedAssessmentService::getInstance();

$framework = $grc->getFramework($frameworkId);
$assessment = $uas->getAssessment($assessmentId);

if (!$framework || !$assessment || $assessment['status'] === 'archived') {
    header('Location: grc-frameworks.php');
    exit;
}

// Load scope name
$scopeName = '';
if (!empty($assessment['scope_id'])) {
    $scope = $db->fetchOne('SELECT name FROM grc_scopes WHERE id = :id', [':id' => $assessment['scope_id']]);
    $scopeName = $scope ? $scope['name'] : '';
}

// Load all framework requirements with mapped questions and assessment responses
$rows = $db->fetchAll(
    "SELECT fr.id as req_id, fr.requirement_ref, fr.title as req_title, fr.description as req_description,
            fr.guidance as req_guidance, fr.parent_id, fr.sort_order as req_sort,
            q.id as question_id, q.question_ref, q.question_text, q.guidance as question_guidance,
            q.maturity_1_desc, q.maturity_2_desc, q.maturity_3_desc, q.maturity_4_desc,
            r.id as response_id, r.maturity_rating, r.conformity_status, r.notes,
            r.validation_status, r.validation_notes, r.assessed_at,
            assessor.full_name as assessor_name,
            validator.full_name as validator_name, r.validated_at,
            qfm.mapping_strength
     FROM grc_framework_requirements fr
     LEFT JOIN grc_question_framework_map qfm ON qfm.requirement_id = fr.id AND qfm.framework_id = fr.framework_id
     LEFT JOIN grc_unified_questions q ON q.id = qfm.question_id
     LEFT JOIN grc_assessment_responses r ON r.question_id = q.id AND r.assessment_id = :aid
     LEFT JOIN users assessor ON assessor.id = r.assessor_user_id
     LEFT JOIN users validator ON validator.id = r.validated_by
     WHERE fr.framework_id = :fid
     ORDER BY fr.sort_order, fr.id, q.question_ref",
    [':aid' => $assessmentId, ':fid' => $frameworkId]
);

// Load evidence for all responses in this assessment
$evidenceByResponse = [];
if (!empty($rows)) {
    $responseIds = array_filter(array_unique(array_column($rows, 'response_id')));
    if (!empty($responseIds)) {
        $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
        $evRows = $db->fetchAll(
            "SELECT are.response_id, e.id as evidence_id, e.evidence_ref, e.title, e.file_name, e.evidence_type, e.file_size
             FROM grc_assessment_response_evidence are
             JOIN grc_evidence e ON e.id = are.evidence_id
             WHERE are.response_id IN ($placeholders)
             ORDER BY e.evidence_ref",
            array_values($responseIds)
        );
        foreach ($evRows as $ev) {
            $evidenceByResponse[(int)$ev['response_id']][] = $ev;
        }
    }
}

// Group rows by requirement
$requirements = [];
foreach ($rows as $row) {
    $reqId = (int)$row['req_id'];
    if (!isset($requirements[$reqId])) {
        $requirements[$reqId] = [
            'req_id' => $reqId,
            'requirement_ref' => $row['requirement_ref'],
            'req_title' => $row['req_title'],
            'req_description' => $row['req_description'],
            'req_guidance' => $row['req_guidance'],
            'parent_id' => $row['parent_id'],
            'questions' => [],
        ];
    }
    if (!empty($row['question_id'])) {
        $respId = $row['response_id'] ? (int)$row['response_id'] : null;
        $requirements[$reqId]['questions'][] = [
            'question_ref' => $row['question_ref'],
            'question_text' => $row['question_text'],
            'question_guidance' => $row['question_guidance'],
            'maturity_rating' => $row['maturity_rating'],
            'maturity_1_desc' => $row['maturity_1_desc'],
            'maturity_2_desc' => $row['maturity_2_desc'],
            'maturity_3_desc' => $row['maturity_3_desc'],
            'maturity_4_desc' => $row['maturity_4_desc'],
            'conformity_status' => $row['conformity_status'],
            'notes' => $row['notes'],
            'validation_status' => $row['validation_status'],
            'validation_notes' => $row['validation_notes'],
            'assessor_name' => $row['assessor_name'],
            'validator_name' => $row['validator_name'],
            'assessed_at' => $row['assessed_at'],
            'validated_at' => $row['validated_at'],
            'mapping_strength' => $row['mapping_strength'],
            'evidence' => $respId ? ($evidenceByResponse[$respId] ?? []) : [],
        ];
    }
}

// Compute summary stats
$totalReqs = count($requirements);
$conformingReqs = 0;
$partialReqs = 0;
$nonConformingReqs = 0;
$naReqs = 0;
$notAssessedReqs = 0;

foreach ($requirements as $req) {
    if (empty($req['questions'])) {
        $notAssessedReqs++;
        continue;
    }
    // Use the best conformity status among mapped questions
    $bestStatus = 'not_assessed';
    $statusRank = ['conforming' => 4, 'partial' => 3, 'non_conforming' => 2, 'not_applicable' => 1, 'not_assessed' => 0];
    foreach ($req['questions'] as $q) {
        $s = $q['conformity_status'] ?? 'not_assessed';
        if (($statusRank[$s] ?? 0) > ($statusRank[$bestStatus] ?? 0)) {
            $bestStatus = $s;
        }
    }
    if ($bestStatus === 'conforming') $conformingReqs++;
    elseif ($bestStatus === 'partial') $partialReqs++;
    elseif ($bestStatus === 'non_conforming') $nonConformingReqs++;
    elseif ($bestStatus === 'not_applicable') $naReqs++;
    else $notAssessedReqs++;
}

$applicableReqs = $totalReqs - $naReqs;
$compliancePct = $applicableReqs > 0 ? round((($conformingReqs + $partialReqs * 0.5) / $applicableReqs) * 100, 1) : 0;

$statusLabels = [
    'conforming' => [t('grc-framework-report.status_conforming'), '#059669', '#d1fae5'],
    'partial' => [t('grc-framework-report.status_partial'), '#d97706', '#fef3c7'],
    'non_conforming' => [t('grc-framework-report.status_non_conforming'), '#dc2626', '#fee2e2'],
    'not_applicable' => [t('grc-framework-report.status_na'), '#6b7280', '#f3f4f6'],
    'not_assessed' => [t('grc-framework-report.status_not_assessed'), '#9ca3af', '#f9fafb'],
];

$validationLabels = [
    'validated' => [t('grc-framework-report.val_validated'), '#059669', '#d1fae5'],
    'rejected' => [t('grc-framework-report.val_rejected'), '#dc2626', '#fee2e2'],
    'needs_review' => [t('grc-framework-report.val_needs_review'), '#d97706', '#fef3c7'],
    'pending' => [t('grc-framework-report.val_pending'), '#9ca3af', '#f3f4f6'],
];

$typeLabels = [
    'initial' => t('grc-framework-report.type_initial'),
    'periodic' => t('grc-framework-report.type_periodic'),
    'targeted' => t('grc-framework-report.type_targeted'),
    'pre_audit' => t('grc-framework-report.type_pre_audit'),
    'certification' => t('grc-framework-report.type_certification'),
];

$assessmentStatusLabels = [
    'draft' => [t('grc-framework-report.astatus_draft'), '#6b7280'],
    'in_progress' => [t('grc-framework-report.astatus_in_progress'), '#3b82f6'],
    'under_review' => [t('grc-framework-report.astatus_under_review'), '#d97706'],
    'completed' => [t('grc-framework-report.astatus_completed'), '#059669'],
    'archived' => [t('grc-framework-report.astatus_archived'), '#9ca3af'],
];

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
$csrfToken = $security->getCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($framework['code']); ?> <?php echo e(t('grc-framework-report.compliance_report')); ?> - <?php echo e($assessment['title']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php
    $navFill = $theme['nav_fill_color'] ?? '#1a1a2e';
    $navFont = $theme['nav_font_color'] ?? '#ffffff';
    $btnColor = $theme['button_color'] ?? '#ff6543';
    $headerColor = $theme['header_color'] ?? '#35a0a3';
    $footerColor = $theme['footer_color'] ?? '#1a365d';
    $sidebarWidth = $theme['sidebar_width'] ?? '260px';
    ?>
    <style>
        :root {
            --nav-fill-color: <?php echo e($navFill); ?>;
            --nav-font-color: <?php echo e($navFont); ?>;
            --theme-button-color: <?php echo e($btnColor); ?>;
            --theme-header-color: <?php echo e($headerColor); ?>;
            --theme-footer-color: <?php echo e($footerColor); ?>;
            --sidebar-width: <?php echo e($sidebarWidth); ?>;
        }
        * { box-sizing: border-box; }
        a { text-decoration: none; }
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
        .sidebar-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--nav-font-color); opacity: 0.5; padding: 0 20px; margin-bottom: 10px; cursor: pointer; list-style: none; display: flex; align-items: center; justify-content: space-between; }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after { content: '\25BC'; font-size: 8px; opacity: 0.5; transition: transform 0.2s ease; margin-right: 2px; }
        .sidebar-section:not([open]) .sidebar-section-title::after { transform: rotate(-90deg); }
        .sidebar-nav { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-font-color); opacity: 0.85; text-decoration: none; font-size: 13px; transition: all 0.2s; border-left: 3px solid transparent; }
        .sidebar-nav li a:hover { background: rgba(255,255,255,0.1); opacity: 1; border-left-color: var(--nav-font-color); }
        .sidebar-nav li a.active { background: rgba(255,255,255,0.15); opacity: 1; border-left-color: var(--nav-font-color); font-weight: 500; }
        .sidebar-nav li a .icon { font-size: 16px; width: 20px; text-align: center; opacity: 0.9; }
        .sidebar-nav li a .badge { margin-left: auto; background: rgba(255,255,255,0.2); color: var(--nav-font-color); font-size: 10px; padding: 2px 7px; border-radius: 10px; }
        .sidebar .icon svg { color: var(--nav-font-color, #ffffff); }

        /* Module wrappers (TPRM / GRC collapsible sections) */
        .sidebar-module { border-top: 1px solid rgba(255,255,255,0.08); margin-top: 4px; }
        .sidebar-module:first-of-type { margin-top: 0; border-top: none; }
        .sidebar-module > summary.sidebar-module-header { display: flex; align-items: center; padding: 12px 20px 8px; cursor: pointer; list-style: none; user-select: none; }
        .sidebar-module > summary.sidebar-module-header::-webkit-details-marker { display: none; }
        .sidebar-module > summary.sidebar-module-header::marker { display: none; content: none; font-size: 0; }
        .sidebar-module > summary.sidebar-module-header::before,
        .sidebar-module > summary.sidebar-module-header::after { content: none; display: none; }
        .sidebar-module-header .module-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: var(--theme-button-color, #ff6543); opacity: 1; transition: opacity 0.2s; }
        .sidebar-module-header:hover .module-label { opacity: 0.8; }
        .sidebar-module-body { padding-top: 0; padding-bottom: 4px; position: relative; }
        .sidebar-module-body::before { content: ''; display: block; height: 2px; background: var(--theme-button-color, #ff6543); margin: 0 20px 8px; opacity: 0.5; border-radius: 1px; }
        .sidebar-module .sidebar-nav li a { font-size: 12px; padding-top: 9px; padding-bottom: 9px; }
        .sidebar-module .sidebar-section { margin-bottom: 16px; }
        .sidebar-module .sidebar-section:first-child { margin-top: 4px; }
        .sidebar-module .sidebar-section-title { font-size: 9px; }
        .sidebar-nav li a .badge-danger { background: #dc3545; color: #fff; }

        .main-content { flex: 1; padding: 35px 40px; background: #f9fafb; min-width: 0; overflow-y: auto; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        /* Assessment Header */
        .report-header { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; margin-bottom: 28px; }
        .report-title { font-size: 22px; font-weight: 700; color: #111; margin: 0 0 4px; }
        .report-subtitle { font-size: 14px; color: #6b7280; margin: 0 0 20px; }
        .header-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .header-field { }
        .header-field .label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; margin-bottom: 3px; }
        .header-field .value { font-size: 14px; color: #333; font-weight: 500; }
        .status-badge { display: inline-block; padding: 3px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .scope-notes { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f3f4f6; }
        .scope-notes .label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; margin-bottom: 6px; }
        .scope-notes .value { font-size: 13px; color: #444; line-height: 1.6; white-space: pre-wrap; }

        /* Summary Stats */
        .stats-bar { display: flex; gap: 14px; margin-bottom: 28px; flex-wrap: wrap; }
        .stat-card { background: #fff; border: 2px solid #e5e7eb; border-radius: 10px; padding: 16px 20px; flex: 1; min-width: 120px; text-align: center; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; user-select: none; }
        .stat-card:hover { border-color: #9ca3af; }
        .stat-card.active-filter { border-color: var(--theme-button-color, #ff6543); box-shadow: 0 0 0 3px rgba(255,101,67,0.15); }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; font-weight: 500; }
        .req-card.filter-hidden { display: none; }

        /* Risk Heatmap */
        .heatmap-wrapper { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 28px; max-width: 500px; }
        .heatmap-wrapper h3 { margin: 0 0 16px; font-size: 15px; color: #333; }
        .heatmap { display: grid; grid-template-columns: 30px repeat(5, 1fr); grid-template-rows: repeat(5, 1fr) 30px; gap: 2px; max-width: 350px; }
        .heatmap-cell { display: flex; align-items: center; justify-content: center; min-height: 50px; border-radius: 4px; font-size: 14px; font-weight: 600; color: #fff; }
        .heatmap-label { display: flex; align-items: center; justify-content: center; font-size: 10px; color: #6b7280; font-weight: 600; }
        .hc-green { background: #28a745; }
        .hc-yellow { background: #f59e0b; }
        .hc-orange { background: #f97316; }
        .hc-red { background: #dc3545; }
        .hc-darkred { background: #991b1b; }

        /* Requirement Cards */
        .req-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 16px; overflow: hidden; }
        .req-header { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: flex-start; gap: 12px; }
        .req-ref { font-size: 14px; font-weight: 700; color: var(--theme-button-color); white-space: nowrap; min-width: 70px; }
        .req-title { font-size: 14px; font-weight: 600; color: #333; flex: 1; }
        .req-status { flex-shrink: 0; }
        .req-desc { padding: 0 20px; font-size: 12px; color: #6b7280; margin: 0; line-height: 1.5; }
        .req-desc:not(:empty) { padding-top: 10px; padding-bottom: 10px; border-bottom: 1px solid #f3f4f6; }

        .question-block { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; }
        .question-block:last-child { border-bottom: none; }
        .q-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
        .q-ref { font-size: 12px; font-weight: 700; color: #6d28d9; background: #f5f3ff; padding: 2px 8px; border-radius: 4px; white-space: nowrap; }
        .q-text { font-size: 13px; color: #333; flex: 1; line-height: 1.5; }
        .q-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 10px; }
        .q-detail { }
        .q-detail .label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; }
        .q-detail .value { font-size: 13px; color: #333; margin-top: 2px; }
        .q-notes { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #444; line-height: 1.6; white-space: pre-wrap; margin-bottom: 10px; }
        .q-notes-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 4px; }
        .evidence-list { list-style: none; padding: 0; margin: 0; }
        .evidence-list li { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; font-size: 11px; color: #1e40af; margin-right: 6px; margin-bottom: 4px; }
        .evidence-list li a { color: #1e40af; text-decoration: none; }
        .evidence-list li a:hover { text-decoration: underline; }
        .maturity-bar { display: flex; gap: 3px; margin-top: 2px; }
        .maturity-bar span { width: 22px; height: 8px; border-radius: 2px; background: #e5e7eb; }
        .maturity-bar span.filled { background: #059669; }
        .maturity-bar span.filled.low { background: #dc2626; }
        .maturity-bar span.filled.mid { background: #d97706; }

        .no-questions { padding: 16px 20px; font-size: 12px; color: #9ca3af; font-style: italic; }

        /* Print Button */
        .print-bar { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; }
        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }

        /* Validation label */
        .validation-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; }

        /* Print Styles */
        @media print {
            .top-bar, .sidebar, .print-bar, .back-link, .footer-modern { display: none !important; }
            .main-layout { display: block !important; }
            .main-content { padding: 0 !important; background: #fff !important; }
            .page { min-height: auto; }
            .report-header { border: 2px solid #333; break-inside: avoid; }
            .req-card { break-inside: avoid; border: 1px solid #ccc; }
            .req-card.filter-hidden { display: block !important; }
            .stat-card.active-filter { border-color: #ccc !important; box-shadow: none !important; }
            .question-block { break-inside: avoid; }
            .stats-bar { break-inside: avoid; }
            .stat-card { border: 1px solid #ccc; }
            .heatmap-wrapper { break-before: page; break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .heatmap-cell { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .hc-green { background: #28a745 !important; }
            .hc-yellow { background: #f59e0b !important; }
            .hc-orange { background: #f97316 !important; }
            .hc-red { background: #dc3545 !important; }
            .hc-darkred { background: #991b1b !important; }
            body { font-size: 11px; }
            .report-title { font-size: 18px; }
            .req-ref { font-size: 12px; }
            .req-title { font-size: 12px; }
            .q-text { font-size: 11px; }
            a { color: #333 !important; }
            @page { margin: 1cm; }
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
            <a href="grc-frameworks.php?framework_id=<?php echo $frameworkId; ?>" class="back-link">&larr; <?php echo e(t('grc-framework-report.back_to', $framework['code'])); ?></a>

            <div class="print-bar">
                <button class="btn btn-primary" id="printReportBtn"><?php echo e(t('grc-framework-report.print_report')); ?></button>
                <a href="grc-frameworks.php?framework_id=<?php echo $frameworkId; ?>" class="btn btn-outline"><?php echo e(t('grc-framework-report.back_to_framework')); ?></a>
            </div>

            <!-- Assessment Header -->
            <div class="report-header">
                <div class="report-title"><?php echo e($framework['name']); ?> <?php echo e($framework['version'] ?? ''); ?> &mdash; <?php echo e(t('grc-framework-report.compliance_report')); ?></div>
                <div class="report-subtitle"><?php echo e(t('grc-framework-report.assessment_prefix')); ?> <?php echo e($assessment['assessment_ref'] ?? ''); ?> &mdash; <?php echo e($assessment['title']); ?></div>

                <div class="header-grid">
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.assessment_type')); ?></div>
                        <div class="value"><?php echo e($typeLabels[$assessment['assessment_type']] ?? ucfirst($assessment['assessment_type'] ?? '')); ?></div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.status')); ?></div>
                        <div class="value">
                            <?php
                            $as = $assessmentStatusLabels[$assessment['status']] ?? [t('grc-framework-report.astatus_unknown'), '#6b7280'];
                            ?>
                            <span class="status-badge" style="background: <?php echo $as[1]; ?>20; color: <?php echo $as[1]; ?>;"><?php echo e($as[0]); ?></span>
                        </div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.scope')); ?></div>
                        <div class="value"><?php echo e($scopeName ?: t('grc-framework-report.not_assigned')); ?></div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.lead_auditor')); ?></div>
                        <div class="value"><?php echo e($assessment['lead_auditor_name'] ?? t('grc-framework-report.unassigned')); ?></div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.planned_start')); ?></div>
                        <div class="value"><?php echo !empty($assessment['planned_start']) ? date('M j, Y', strtotime($assessment['planned_start'])) : '&mdash;'; ?></div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.planned_end')); ?></div>
                        <div class="value"><?php echo !empty($assessment['planned_end']) ? date('M j, Y', strtotime($assessment['planned_end'])) : '&mdash;'; ?></div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.framework_compliance')); ?></div>
                        <div class="value" style="color: <?php echo $compliancePct >= 80 ? '#059669' : ($compliancePct >= 50 ? '#d97706' : '#dc2626'); ?>; font-size: 20px; font-weight: 700;"><?php echo $compliancePct; ?>%</div>
                    </div>
                    <div class="header-field">
                        <div class="label"><?php echo e(t('grc-framework-report.report_generated')); ?></div>
                        <div class="value"><?php echo date('M j, Y g:i A'); ?></div>
                    </div>
                </div>

                <?php if (!empty($assessment['scope'])): ?>
                <div class="scope-notes">
                    <div class="label"><?php echo e(t('grc-framework-report.scope_notes')); ?></div>
                    <div class="value"><?php echo e($assessment['scope']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Summary Stats -->
            <div class="stats-bar" id="statsBar">
                <div class="stat-card" data-filter="all">
                    <div class="stat-value"><?php echo $totalReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.total_requirements')); ?></div>
                </div>
                <div class="stat-card" data-filter="conforming">
                    <div class="stat-value" style="color:#059669;"><?php echo $conformingReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.conforming')); ?></div>
                </div>
                <div class="stat-card" data-filter="partial">
                    <div class="stat-value" style="color:#d97706;"><?php echo $partialReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.partial')); ?></div>
                </div>
                <div class="stat-card" data-filter="non_conforming">
                    <div class="stat-value" style="color:#dc2626;"><?php echo $nonConformingReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.non_conforming')); ?></div>
                </div>
                <div class="stat-card" data-filter="not_assessed">
                    <div class="stat-value" style="color:#9ca3af;"><?php echo $notAssessedReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.not_assessed')); ?></div>
                </div>
                <div class="stat-card" data-filter="not_applicable">
                    <div class="stat-value" style="color:#6b7280;"><?php echo $naReqs; ?></div>
                    <div class="stat-label"><?php echo e(t('grc-framework-report.na')); ?></div>
                </div>
            </div>

            <!-- Risk Heatmap -->
            <?php if ($heatmapTotal > 0): ?>
            <div class="heatmap-wrapper">
                <h3><?php echo e(t('grc-framework-report.risk_heatmap')); ?></h3>
                <div style="text-align:center;font-size:11px;color:#6b7280;margin-bottom:4px;"><?php echo e(t('grc-framework-report.impact')); ?> &rarr;</div>
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
                <div style="text-align:left;font-size:11px;color:#6b7280;margin-top:4px;"><?php echo e(t('grc-framework-report.likelihood')); ?> &uarr;</div>
            </div>
            <?php endif; ?>

            <!-- Requirements Detail -->
            <?php foreach ($requirements as $req): ?>
            <?php
            // Determine overall status for this requirement
            $bestStatus = 'not_assessed';
            $statusRank = ['conforming' => 4, 'partial' => 3, 'non_conforming' => 2, 'not_applicable' => 1, 'not_assessed' => 0];
            foreach ($req['questions'] as $q) {
                $s = $q['conformity_status'] ?? 'not_assessed';
                if (($statusRank[$s] ?? 0) > ($statusRank[$bestStatus] ?? 0)) {
                    $bestStatus = $s;
                }
            }
            if (empty($req['questions'])) $bestStatus = 'not_assessed';
            $sl = $statusLabels[$bestStatus];
            ?>
            <div class="req-card" data-req-status="<?php echo $bestStatus; ?>">
                <div class="req-header">
                    <span class="req-ref"><?php echo e($req['requirement_ref']); ?></span>
                    <span class="req-title"><?php echo e($req['req_title']); ?></span>
                    <span class="req-status">
                        <span class="status-badge" style="background:<?php echo $sl[2]; ?>;color:<?php echo $sl[1]; ?>;"><?php echo $sl[0]; ?></span>
                    </span>
                </div>

                <?php if (!empty($req['req_description'])): ?>
                <div class="req-desc"><?php echo e($req['req_description']); ?></div>
                <?php endif; ?>

                <?php if (empty($req['questions'])): ?>
                <div class="no-questions"><?php echo e(t('grc-framework-report.no_questions_mapped')); ?></div>
                <?php else: ?>
                    <?php foreach ($req['questions'] as $q): ?>
                    <div class="question-block">
                        <div class="q-header">
                            <span class="q-ref"><?php echo e($q['question_ref']); ?></span>
                            <span class="q-text"><?php echo e($q['question_text']); ?></span>
                        </div>

                        <div class="q-details">
                            <div class="q-detail">
                                <div class="label"><?php echo e(t('grc-framework-report.maturity_rating')); ?></div>
                                <div class="value">
                                    <?php if ($q['maturity_rating']): ?>
                                        <strong><?php echo (int)$q['maturity_rating']; ?> / 4</strong>
                                        <div class="maturity-bar">
                                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <span class="<?php echo $i <= $q['maturity_rating'] ? 'filled' . ($q['maturity_rating'] <= 1 ? ' low' : ($q['maturity_rating'] <= 2 ? ' mid' : '')) : ''; ?>"></span>
                                            <?php endfor; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;"><?php echo e(t('grc-framework-report.not_rated')); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="q-detail">
                                <div class="label"><?php echo e(t('grc-framework-report.conformity_status')); ?></div>
                                <div class="value">
                                    <?php $csl = $statusLabels[$q['conformity_status'] ?? 'not_assessed']; ?>
                                    <span class="status-badge" style="background:<?php echo $csl[2]; ?>;color:<?php echo $csl[1]; ?>;"><?php echo $csl[0]; ?></span>
                                </div>
                            </div>
                            <div class="q-detail">
                                <div class="label"><?php echo e(t('grc-framework-report.validation')); ?></div>
                                <div class="value">
                                    <?php $vl = $validationLabels[$q['validation_status'] ?? 'pending']; ?>
                                    <span class="validation-badge" style="background:<?php echo $vl[2]; ?>;color:<?php echo $vl[1]; ?>;"><?php echo $vl[0]; ?></span>
                                    <?php if (!empty($q['validator_name'])): ?>
                                        <span style="font-size:11px;color:#6b7280;"><?php echo e(t('grc-framework-report.by', $q['validator_name'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="q-detail">
                                <div class="label"><?php echo e(t('grc-framework-report.assessed_by')); ?></div>
                                <div class="value"><?php echo !empty($q['assessor_name']) ? e($q['assessor_name']) : '&mdash;'; ?>
                                    <?php if ($q['assessed_at']): ?>
                                        <span style="font-size:11px;color:#6b7280;"><?php echo date('M j, Y', strtotime($q['assessed_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="q-detail">
                                <div class="label"><?php echo e(t('grc-framework-report.mapping_strength')); ?></div>
                                <div class="value"><?php echo ucfirst($q['mapping_strength'] ?? 'unknown'); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($q['notes'])): ?>
                        <div class="q-notes-label"><?php echo e(t('grc-framework-report.assessor_notes')); ?></div>
                        <div class="q-notes"><?php echo e($q['notes']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($q['validation_notes'])): ?>
                        <div class="q-notes-label"><?php echo e(t('grc-framework-report.validation_notes')); ?></div>
                        <div class="q-notes"><?php echo e($q['validation_notes']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($q['evidence'])): ?>
                        <div class="q-notes-label"><?php echo e(t('grc-framework-report.evidence_attachments')); ?></div>
                        <ul class="evidence-list">
                            <?php foreach ($q['evidence'] as $ev): ?>
                            <li>
                                <?php if ($canDownload && !empty($ev['file_name'])): ?>
                                <a href="grc-evidence.php?download=<?php echo (int)$ev['evidence_id']; ?>"><?php echo e($ev['evidence_ref']); ?> &mdash; <?php echo e($ev['title'] ?? $ev['file_name']); ?></a>
                                <?php else: ?>
                                <?php echo e($ev['evidence_ref']); ?> &mdash; <?php echo e($ev['title'] ?? $ev['file_name']); ?>
                                <?php endif; ?>
                                <?php if ($ev['file_size']): ?>
                                <span style="color:#6b7280;font-size:10px;">(<?php echo round($ev['file_size'] / 1024); ?> KB)</span>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

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
                    <span><?php echo e(t('grc-framework-report.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>
<script nonce="<?php echo cspNonce(); ?>">
(function() {
    document.getElementById('printReportBtn').addEventListener('click', function() { window.print(); });

    var activeFilter = 'all';
    var statsBar = document.getElementById('statsBar');
    var cards = document.querySelectorAll('.req-card[data-req-status]');
    var statCards = statsBar.querySelectorAll('.stat-card[data-filter]');

    function applyFilter(filter) {
        activeFilter = filter;
        statCards.forEach(function(sc) {
            sc.classList.toggle('active-filter', sc.getAttribute('data-filter') === filter);
        });
        cards.forEach(function(card) {
            if (filter === 'all') {
                card.classList.remove('filter-hidden');
            } else {
                card.classList.toggle('filter-hidden', card.getAttribute('data-req-status') !== filter);
            }
        });
    }

    statCards.forEach(function(sc) {
        sc.addEventListener('click', function() {
            var f = this.getAttribute('data-filter');
            applyFilter(f === activeFilter ? 'all' : f);
        });
    });
})();
</script>
</body>
</html>
