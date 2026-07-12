<?php
/**
 * FairScore Unified Assessment - Main Page
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Single questionnaire covering ALL major cybersecurity frameworks with
 * zero duplicate questions. FairScore maturity rating (1-4), auto-gap
 * detection, cross-framework impact analysis, and task management.
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
$isContributor = hasGroup('grc_contributors');

if (!$isAdmin && !$isCyberGRC && !$isAuditor && !$isContributor) {
    http_response_code(403);
    die(e(t('grc-assessment.access_denied')));
}

$readOnly = !$isAdmin && !$isCyberGRC && $isAuditor && !$isContributor;
$canManage = $isAdmin || $isCyberGRC;

$uas = UnifiedAssessmentService::getInstance();
$aiEnabled = AIPlatformService::getInstance()->isEnabled();
$msg = '';
$msgType = '';

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = t('grc-assessment.invalid_csrf');
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_assessment' && $canManage) {
            $title = trim($_POST['title'] ?? '');
            // Handle new scope creation
            if (($_POST['scope_id'] ?? '') === '__new__') {
                $newScopeName = trim($_POST['new_scope_name'] ?? '');
                if ($newScopeName !== '') {
                    $newScopeDesc = trim($_POST['new_scope_description'] ?? '');
                    $grcSvc = GRCService::getInstance();
                    $newScopeId = $grcSvc->createScope($newScopeName, $newScopeDesc ?: null, '#6B7280', 0, (int)$user['id']);
                    $_POST['scope_id'] = $newScopeId;
                } else {
                    $_POST['scope_id'] = null;
                }
            }
            if ($title !== '') {
                $result = $uas->createAssessment($_POST, (int)$user['id']);
                if ($result['success']) {
                    header('Location: grc-assessment.php?view=' . $result['id'] . '&created=1');
                    exit;
                } else {
                    $msg = $result['error'];
                    $msgType = 'danger';
                }
            } else {
                $msg = t('grc-assessment.title_required');
                $msgType = 'danger';
            }
        } elseif ($action === 'update_assessment' && $canManage) {
            // Handle new scope creation
            if (($_POST['scope_id'] ?? '') === '__new__') {
                $newScopeName = trim($_POST['new_scope_name'] ?? '');
                if ($newScopeName !== '') {
                    $grcSvc = GRCService::getInstance();
                    $newScopeId = $grcSvc->createScope($newScopeName, null, '#6B7280', 0, (int)$user['id']);
                    $_POST['scope_id'] = $newScopeId;
                } else {
                    $_POST['scope_id'] = null;
                }
            }
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            if ($assessmentId > 0) {
                $result = $uas->updateAssessment($assessmentId, $_POST);
                if ($result['success']) {
                    $msg = t('grc-assessment.assessment_updated');
                    $msgType = 'success';
                } else {
                    $msg = $result['error'];
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'delete_assessment' && $isAdmin) {
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            $result = $uas->deleteAssessment($assessmentId);
            if ($result['success']) {
                header('Location: grc-assessment.php?deleted=1');
                exit;
            } else {
                $msg = $result['error'];
                $msgType = 'danger';
            }
        } elseif ($action === 'archive_assessment' && $canManage) {
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            $result = $uas->archiveAssessment($assessmentId);
            if ($result['success']) {
                header('Location: grc-assessment.php?archived=1');
                exit;
            } else {
                $msg = $result['error'];
                $msgType = 'danger';
            }
        } elseif ($action === 'unarchive_assessment' && $canManage) {
            $assessmentId = (int)($_POST['assessment_id'] ?? 0);
            $result = $uas->unarchiveAssessment($assessmentId);
            if ($result['success']) {
                header('Location: grc-assessment.php?unarchived=1');
                exit;
            } else {
                $msg = $result['error'];
                $msgType = 'danger';
            }
        } elseif ($action === 'duplicate_assessment' && $canManage) {
            $sourceId = (int)($_POST['assessment_id'] ?? 0);
            $dupMode = $_POST['duplicate_mode'] ?? 'template';
            if ($sourceId > 0) {
                $source = $uas->getAssessment($sourceId);
                if ($source) {
                    // Generate next assessment_ref
                    $lastRef = $db->fetchOne("SELECT assessment_ref FROM grc_assessments WHERE assessment_ref LIKE 'FA-%' ORDER BY id DESC LIMIT 1");
                    $nextNum = $lastRef ? (int)substr($lastRef['assessment_ref'], 3) + 1 : 1;
                    $newRef = 'FA-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

                    // Insert cloned assessment
                    $db->execute(
                        "INSERT INTO grc_assessments (assessment_ref, title, description, assessment_type, scope, scope_id, status, lead_auditor_id, planned_start, planned_end, created_by, created_at, updated_at)
                         VALUES (:ref, :title, :desc, :type, :scope, :scope_id, 'draft', :lead, :ps, :pe, :uid, NOW(), NOW())",
                        [
                            ':ref'      => $newRef,
                            ':title'    => $source['title'] . ' (Copy)',
                            ':desc'     => $source['description'] ?? '',
                            ':type'     => $source['assessment_type'],
                            ':scope'    => $source['scope'] ?? '',
                            ':scope_id' => $source['scope_id'] ?: null,
                            ':lead'     => $source['lead_auditor_id'] ?: null,
                            ':ps'       => $source['planned_start'] ?: null,
                            ':pe'       => $source['planned_end'] ?: null,
                            ':uid'      => (int)$user['id'],
                        ]
                    );
                    $newId = $db->lastInsertId();

                    if ($dupMode === 'full') {
                        // Full copy: clone responses with ratings, conformity, notes
                        $db->execute(
                            "INSERT INTO grc_assessment_responses (assessment_id, question_id, maturity_rating, conformity_status, notes, assessor_user_id, assessed_at, validation_status, created_at, updated_at)
                             SELECT :newId, question_id, maturity_rating, conformity_status, notes, assessor_user_id, assessed_at, 'pending', NOW(), NOW()
                             FROM grc_assessment_responses WHERE assessment_id = :srcId",
                            [':newId' => $newId, ':srcId' => $sourceId]
                        );

                        // Clone evidence links: map old response IDs to new ones
                        $db->execute(
                            "INSERT INTO grc_assessment_response_evidence (response_id, evidence_id, linked_by, linked_at)
                             SELECT nr.id, ore.evidence_id, :uid2, NOW()
                             FROM grc_assessment_response_evidence ore
                             INNER JOIN grc_assessment_responses oldr ON ore.response_id = oldr.id AND oldr.assessment_id = :srcId2
                             INNER JOIN grc_assessment_responses nr ON nr.assessment_id = :newId2 AND nr.question_id = oldr.question_id",
                            [':uid2' => (int)$user['id'], ':srcId2' => $sourceId, ':newId2' => $newId]
                        );
                    } else {
                        // Template mode: create blank response rows (unanswered) for all questions from source
                        $db->execute(
                            "INSERT INTO grc_assessment_responses (assessment_id, question_id, maturity_rating, conformity_status, notes, validation_status, created_at, updated_at)
                             SELECT :newId, question_id, NULL, 'not_assessed', NULL, 'pending', NOW(), NOW()
                             FROM grc_assessment_responses WHERE assessment_id = :srcId",
                            [':newId' => $newId, ':srcId' => $sourceId]
                        );
                    }

                    // Recalculate domain scores
                    $uas->calculateDomainScores($newId);

                    $dupFlag = $dupMode === 'full' ? 'duplicated_full' : 'duplicated_template';
                    header('Location: grc-assessment.php?view=' . $newId . '&' . $dupFlag . '=1');
                    exit;
                } else {
                    $msg = t('grc-assessment.source_not_found');
                    $msgType = 'danger';
                }
            }
        } elseif ($action === 'seed_questions' && $isAdmin) {
            $result = $uas->seedQuestionsFromCatalog();
            if ($result['success']) {
                $msg = 'Questions seeded: ' . $result['inserted'] . ' inserted, ' . $result['skipped'] . ' already existed.';
                $msgType = 'success';
                $mapResult = $uas->seedFrameworkMappings();
                if ($mapResult['success']) {
                    $msg .= ' Mappings: ' . $mapResult['inserted'] . ' inserted, ' . $mapResult['skipped'] . ' skipped.';
                    if (!empty($mapResult['requirements_created'])) {
                        $msg .= ' Requirements auto-created: ' . $mapResult['requirements_created'] . '.';
                    }
                }
            } else {
                $msg = $result['error'];
                $msgType = 'danger';
            }
        }
        $csrfToken = $security->generateCSRFToken();
    }
}

// Handle redirect messages
if (isset($_GET['created'])) { $msg = t('grc-assessment.assessment_created'); $msgType = 'success'; }
if (isset($_GET['duplicated_full'])) { $msg = t('grc-assessment.duplicated_full'); $msgType = 'success'; }
if (isset($_GET['duplicated_template'])) { $msg = t('grc-assessment.duplicated_template'); $msgType = 'success'; }
if (isset($_GET['deleted'])) { $msg = t('grc-assessment.assessment_deleted'); $msgType = 'success'; }

if (!isset($csrfToken)) {
    $csrfToken = $security->generateCSRFToken();
}

// Load data
$users_list = $db->fetchAll('SELECT DISTINCT u.id, u.full_name FROM users u INNER JOIN user_acl_groups uag ON u.id = uag.user_id INNER JOIN acl_groups ag ON uag.group_id = ag.id WHERE u.is_active = 1 AND ag.group_name IN ("cyber_grc", "grc_contributors") ORDER BY u.full_name');
$grc = GRCService::getInstance();
$scopes = $grc->getScopes();
$questionCount = $uas->getQuestionCount();

// View logic
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$assessmentDetail = null;
$responses = [];
$domains = $uas->getDomains();
$progress = null;
$domainScores = [];
$activeDomain = isset($_GET['domain']) ? (int)$_GET['domain'] : 0;

// my_tasks filter: only show questions with tasks assigned to current user
$myTasksFilter = isset($_GET['my_tasks']) && $_GET['my_tasks'] === '1';

if ($viewId > 0) {
    $assessmentDetail = $uas->getAssessment($viewId);
    if ($assessmentDetail) {
        $responses = $uas->getResponses($viewId, $activeDomain ?: null);
        $progress = $uas->getAssessmentProgress($viewId);
        $domainScores = $uas->getDomainScores($viewId);
        // Load tasks for this assessment, build lookup by question_id
        $assessmentTasks = $uas->getTasks($viewId);
        $tasksByQuestion = [];
        foreach ($assessmentTasks as $tk) {
            $qid = (int)($tk['question_id'] ?? 0);
            if ($qid > 0) {
                $tasksByQuestion[$qid][] = $tk;
            }
        }

        // Filter responses to only show questions with tasks assigned to current user
        if ($myTasksFilter) {
            $myTaskQuestionIds = [];
            foreach ($assessmentTasks as $tk) {
                if ((int)($tk['assigned_to'] ?? 0) === (int)$user['id'] && in_array($tk['status'], ['open', 'in_progress'])) {
                    $qid = (int)($tk['question_id'] ?? 0);
                    if ($qid > 0) {
                        $myTaskQuestionIds[$qid] = true;
                    }
                }
            }
            $responses = array_values(array_filter($responses, function($r) use ($myTaskQuestionIds) {
                return isset($myTaskQuestionIds[(int)$r['question_id']]);
            }));
        }
    }
}

// List assessments
$statusFilter = $_GET['status'] ?? '';
$assessments = $uas->getAssessments($statusFilter ?: null, !$statusFilter);

$_page = 'grc_assessment';
?>
<!DOCTYPE html>
<html class="wide wow-animation" lang="en">
<head>
    <title><?php echo e(t('grc-assessment.page_title')); ?></title>
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

        .grc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
        .grc-table th { background: #f3f4f6; padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .grc-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #333; vertical-align: top; }
        .grc-table tr:hover td { background: #f9fafb; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: capitalize; }
        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-under_review { background: #fef3c7; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-archived { background: #e5e7eb; color: #6b7280; }

        .detail-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .detail-item { font-size: 13px; }
        .detail-item .label { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
        .detail-item .value { color: #333; }

        .btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 500; text-decoration: none; border: none; cursor: pointer; transition: background 0.2s; }
        .btn-primary { background: var(--theme-button-color, #ff6543); color: #fff; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-xs { padding: 2px 8px; font-size: 11px; }
        .btn-outline { background: transparent; border: 1px solid #d1d5db; color: #374151; }
        .btn-outline:hover { background: #f3f4f6; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-info { background: #3b82f6; color: #fff !important; }
        .btn-info:hover { opacity: 0.9; text-decoration: none; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-warning { background: #f59e0b; color: #fff; }

        .grc-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
        .grc-form label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .grc-form input, .grc-form select, .grc-form textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; margin-bottom: 12px; font-family: inherit; }
        .grc-form textarea { min-height: 80px; resize: vertical; }
        .grc-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .question-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; transition: border-color 0.2s; }
        .question-card:hover { border-color: #93c5fd; }

        .domain-tab { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none; border: 1px solid #d1d5db; color: #374151; margin: 2px; transition: all 0.2s; }
        .domain-tab:hover { background: #f3f4f6; text-decoration: none; color: #333; }
        .domain-tab.active { background: var(--theme-button-color, #ff6543); color: #fff; border-color: transparent; }

        .maturity-btn-group { display: flex; gap: 2px; }
        .maturity-btn { padding: 4px 10px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-size: 12px; font-weight: 600; border-radius: 4px; transition: all 0.2s; }
        .maturity-btn:hover { background: #f3f4f6; }
        .maturity-btn.active.m-1 { background: #dc3545; color: #fff; border-color: #dc3545; }
        .maturity-btn.active.m-2 { background: #f59e0b; color: #fff; border-color: #f59e0b; }
        .maturity-btn.active.m-3 { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .maturity-btn.active.m-4 { background: #28a745; color: #fff; border-color: #28a745; }
        .maturity-btn.active.m-na { background: #6b7280; color: #fff; border-color: #6b7280; }
        .maturity-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .progress-bar-custom { height: 20px; background: #e5e7eb; border-radius: 10px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #28a745); border-radius: 10px; transition: width 0.5s ease; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; font-weight: 600; }

        .fairscore-big { font-size: 32px; font-weight: 700; }
        .fairscore-good { color: #28a745; }
        .fairscore-ok { color: #f59e0b; }
        .fairscore-low { color: #dc3545; }

        .back-link { display: inline-block; margin-bottom: 16px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #333; }

        .notes-panel textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; font-size: 13px; font-family: inherit; resize: vertical; }
        .notes-panel textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }

        .gap-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #fee2e2; color: #991b1b; }

        .evidence-section { margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .evidence-section-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 6px; }
        .evidence-list { list-style: none; padding: 0; margin: 0 0 8px; }
        .evidence-list li { display: flex; align-items: center; gap: 6px; padding: 5px 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 5px; margin-bottom: 3px; font-size: 12px; }
        .evidence-list li .ev-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; color: #333; }
        .evidence-list li .ev-meta { color: #9ca3af; font-size: 10px; flex-shrink: 0; }
        .evidence-list li .ev-remove { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 13px; padding: 0 3px; flex-shrink: 0; }
        .evidence-list li .ev-remove:hover { color: #b91c1c; }
        .evidence-upload-row { display: flex; gap: 6px; align-items: center; }
        .evidence-upload-row input[type="file"] { flex: 1; font-size: 11px; }
        .btn-upload { padding: 3px 10px; font-size: 11px; border: 1px solid #3b82f6; background: #eff6ff; color: #1e40af; border-radius: 4px; cursor: pointer; white-space: nowrap; }
        .btn-upload:hover { background: #dbeafe; }
        .btn-upload:disabled { opacity: 0.5; cursor: not-allowed; }
        .validated-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #d1fae5; color: #065f46; }
        .rejected-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #fee2e2; color: #991b1b; }
        .assigned-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #dbeafe; color: #1e40af; }
        .assigned-late-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; background: #fee2e2; color: #991b1b; }
        .assistant-btn { border-color: #8b5cf6 !important; color: #7c3aed !important; }
        .assistant-btn:hover { background: #f5f3ff !important; }
        .guidance-content { background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 6px; padding: 12px 14px; font-size: 12px; line-height: 1.6; color: #374151; }
        .guidance-content h2 { font-size: 13px; font-weight: 700; color: #6d28d9; margin: 10px 0 4px; }
        .guidance-content h2:first-child { margin-top: 0; }
        .guidance-content ul { margin: 0 0 6px 16px; padding: 0; }
        .guidance-content li { margin-bottom: 2px; }
        .btn-rewrite { padding: 4px 10px; font-size: 11px; border: 1px solid #8b5cf6; background: #f5f3ff; color: #6d28d9; border-radius: 4px; cursor: pointer; white-space: nowrap; align-self: flex-start; margin-top: 2px; }
        .btn-rewrite:hover { background: #ede9fe; }
        .btn-rewrite:disabled { opacity: 0.5; cursor: not-allowed; }
        .rewrite-preview { background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 6px; padding: 10px 12px; font-size: 12px; margin-top: 6px; }
        .rewrite-preview .rewrite-text { white-space: pre-wrap; color: #374151; margin-bottom: 8px; }
        .rewrite-preview .rewrite-actions { display: flex; gap: 6px; }
        .autosave-indicator { font-size: 10px; color: #9ca3af; margin-left: 8px; transition: opacity 0.3s; }

        .conformity-select { padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; width: 100%; }

        /* Modal overlay - matching existing GRC pages */
        .grc-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; justify-content:center; align-items:center; }
        .grc-modal-overlay.active { display:flex; }
        .grc-modal { background:#fff; border-radius:10px; width:600px; max-width:92vw; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
        .grc-modal.modal-lg { width:800px; }
        .grc-modal-header { padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
        .grc-modal-header h4 { margin:0; font-size:15px; color:#333; }
        .grc-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#6b7280; padding:0 4px; line-height:1; }
        .grc-modal-close:hover { color:#333; }
        .grc-modal-body { padding:20px; overflow-y:auto; flex:1; }
        .grc-modal-footer { padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end; }

        .section-header { font-size: 16px; font-weight: 600; color: #333; margin: 24px 0 12px; padding-bottom: 6px; border-bottom: 2px solid #e5e7eb; }

        .footer-modern, .bg-gray-13 {
            background-color: var(--theme-footer-color) !important;
            padding: 30px 0;
            color: #fff;
            width: 100%;
        }
        .footer-modern .footer-modern-body { padding: 20px 30px; width: 100%; }
        .footer-modern .rights { color: rgba(255,255,255,0.7); font-size: 13px; margin: 0; }

        @media (max-width: 900px) {
            .detail-grid { grid-template-columns: 1fr; }
            .grc-modal { width: 95vw; }
            .main-content { padding: 20px; }
            .grc-form .form-row { grid-template-columns: 1fr; }
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
            <h1 style="font-size:26px;margin:0 0 6px;color:#333;font-weight:600;"><?php echo e(t('grc-assessment.heading')); ?></h1>
            <p style="color:#6b7280;margin:0 0 30px;"><?php echo e(t('grc-assessment.subheading')); ?></p>

            <?php if ($msg): ?>
            <div class="alert alert-<?php echo e($msgType); ?>"><?php echo e($msg); ?></div>
            <?php endif; ?>

<?php if ($assessmentDetail): ?>
    <!-- ═══════════ ASSESSMENT DETAIL VIEW ═══════════ -->
    <a href="grc-assessment.php" class="back-link"><?php echo t('grc-assessment.back_to_assessments'); ?></a>

    <div class="detail-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <h3 style="margin:0 0 8px;font-size:18px;"><?php echo e($assessmentDetail['assessment_ref']); ?> &mdash; <?php echo e($assessmentDetail['title']); ?></h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <span class="status-badge status-<?php echo e($assessmentDetail['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', e($assessmentDetail['status']))); ?></span>
                <?php if ($canManage): ?>
                <button class="btn btn-sm btn-outline" data-action="open-edit-assessment"><?php echo e(t('grc-assessment.edit')); ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($assessmentDetail['description']): ?>
        <p style="color:#6b7280;font-size:13px;margin:0 0 12px;"><?php echo e($assessmentDetail['description']); ?></p>
        <?php endif; ?>
        <div class="detail-grid">
            <div class="detail-item"><div class="label"><?php echo e(t('grc-assessment.type')); ?></div><div class="value"><?php echo ucfirst(str_replace('_', ' ', e($assessmentDetail['assessment_type']))); ?></div></div>
            <div class="detail-item"><div class="label"><?php echo e(t('grc-assessment.lead_auditor')); ?></div><div class="value"><?php echo e($assessmentDetail['lead_auditor_name'] ?? t('grc-assessment.unassigned')); ?></div></div>
            <div class="detail-item"><div class="label"><?php echo e(t('grc-assessment.planned_period')); ?></div><div class="value"><?php echo e($assessmentDetail['planned_start'] ?? '—'); ?> <?php echo e(t('grc-assessment.to')); ?> <?php echo e($assessmentDetail['planned_end'] ?? '—'); ?></div></div>
            <div class="detail-item"><div class="label"><?php echo e(t('grc-assessment.scope')); ?></div><div class="value"><?php echo e($assessmentDetail['scope'] ?? t('grc-assessment.full_organization')); ?></div></div>
        </div>
    </div>

    <!-- Progress & Scores -->
    <?php if ($progress): ?>
    <div class="detail-card">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:24px;align-items:center;">
            <div>
                <div class="label" style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:6px;"><?php echo e(t('grc-assessment.progress')); ?></div>
                <div class="progress-bar-custom">
                    <div class="progress-bar-fill" id="progressBar" style="width:<?php echo $progress['progress_pct']; ?>%"><?php echo $progress['progress_pct']; ?>%</div>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;"><?php echo $progress['answered']; ?> <?php echo e(t('grc-assessment.of')); ?> <?php echo $progress['total_questions']; ?> <?php echo e(t('grc-assessment.answered_lc')); ?></div>
            </div>
            <div style="text-align:center;">
                <div class="label" style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:6px;"><?php echo e(t('grc-assessment.csf_maturity')); ?></div>
                <?php $fs = (float)($assessmentDetail['overall_fairscore'] ?? 0); ?>
                <span id="csfMaturityScore" class="fairscore-big <?php echo $fs >= 3 ? 'fairscore-good' : ($fs >= 2 ? 'fairscore-ok' : 'fairscore-low'); ?>"><?php echo $assessmentDetail['overall_fairscore'] ?? '—'; ?></span>
                <span style="font-size:14px;color:#6b7280;"> / 4.00</span>
            </div>
            <div style="text-align:center;">
                <div class="label" style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;margin-bottom:6px;"><?php echo e(t('grc-assessment.compliance')); ?></div>
                <span id="compliancePct" class="fairscore-big" style="color:#3b82f6;"><?php echo $assessmentDetail['overall_compliance_pct'] ?? '—'; ?></span>
                <span style="font-size:14px;color:#6b7280;">%</span>
            </div>
            <div>
                <button class="btn btn-sm btn-primary" data-action="generate-report"><?php echo e(t('grc-assessment.generate_report')); ?></button>
                <?php
                $hasReport = !empty($assessmentDetail['report_file_name']);
                if ($hasReport): ?>
                <a href="api/grc-assessment-report.php?action=download&id=<?php echo $viewId; ?>" class="btn btn-sm btn-success"><?php echo e(t('grc-assessment.download_pdf')); ?></a>
                <?php endif; ?>
                <span class="autosave-indicator" id="autosaveIndicator" style="opacity:0.5;"><?php echo e(t('grc-assessment.autosave_active')); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <?php if ($myTasksFilter): ?>
    <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:14px;font-weight:500;color:#1e40af;">Showing only questions with tasks assigned to you (<?php echo count($responses); ?> question<?php echo count($responses) !== 1 ? 's' : ''; ?>)</span>
        <a href="grc-assessment.php?view=<?php echo $viewId; ?>" class="btn btn-sm btn-outline" style="color:#1e40af;border-color:#93c5fd;"><?php echo e(t('grc-assessment.show_all_questions')); ?></a>
    </div>
    <?php endif; ?>

    <!-- Filter Toggle Bar -->
    <div id="hideAnsweredBar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <div id="hideAnsweredInfo" style="font-size:13px;color:#6b7280;display:none;">
            <?php echo e(t('grc-assessment.showing')); ?> <span id="hiddenCount">0</span> <?php echo e(t('grc-assessment.of')); ?> <?php echo count($responses); ?> <?php echo e(t('grc-assessment.questions_lc')); ?>
        </div>
        <div style="margin-left:auto;display:flex;gap:6px;">
            <?php
            $unansweredCount = 0;
            foreach ($responses as $r_check) {
                $rAnswered = ((int)($r_check['maturity_rating'] ?? 0) > 0 || ($r_check['conformity_status'] ?? 'not_assessed') !== 'not_assessed');
                if (!$rAnswered) $unansweredCount++;
            }
            if ($unansweredCount > 0): ?>
            <button type="button" id="showUnansweredBtn" class="btn btn-sm btn-warning" data-action="show-unanswered"><?php echo e(t('grc-assessment.show_unanswered', $unansweredCount)); ?></button>
            <?php endif; ?>
            <button type="button" id="toggleAnsweredBtn" class="btn btn-sm btn-outline" data-action="toggle-answered"><?php echo e(t('grc-assessment.show_audited')); ?></button>
        </div>
    </div>

    <!-- Domain Tabs -->
    <div style="overflow-x:auto;white-space:nowrap;margin-bottom:20px;">
        <a href="grc-assessment.php?view=<?php echo $viewId; ?><?php echo $myTasksFilter ? '&my_tasks=1' : ''; ?>" class="domain-tab <?php echo !$activeDomain ? 'active' : ''; ?>"><?php echo e(t('grc-assessment.all_domains')); ?></a>
        <?php foreach ($domains as $dom): ?>
        <a href="grc-assessment.php?view=<?php echo $viewId; ?>&domain=<?php echo $dom['id']; ?>"
           class="domain-tab <?php echo $activeDomain === (int)$dom['id'] ? 'active' : ''; ?>"
           title="<?php echo e($dom['name']); ?>">
            <?php echo e($dom['domain_code']); ?>
            <?php
            $domHasScore = false;
            foreach ($domainScores as $ds) {
                if ((int)$ds['domain_id'] === (int)$dom['id'] && $ds['average_score'] !== null) {
                    $dsColor = $ds['average_score'] >= 3 ? '#28a745' : ($ds['average_score'] >= 2 ? '#f59e0b' : '#dc3545');
                    echo '<span class="domain-score-badge" data-domain-code="' . e($dom['domain_code']) . '" style="background:' . $dsColor . ';color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px;">' . $ds['average_score'] . '</span>';
                    $domHasScore = true;
                }
            }
            if (!$domHasScore) {
                echo '<span class="domain-score-badge" data-domain-code="' . e($dom['domain_code']) . '" style="display:none;color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px;"></span>';
            }
            ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Questions -->
    <div id="questionsContainer">
    <?php
    $currentDomain = '';
    foreach ($responses as $r):
        if ($r['domain_code'] !== $currentDomain):
            if ($currentDomain !== '') echo '</div>';
            $currentDomain = $r['domain_code'];
    ?>
        <div class="domain-group">
            <h3 class="section-header"><?php echo e($r['domain_code']); ?> &mdash; <?php echo e($r['domain_name']); ?></h3>
    <?php endif; ?>

        <?php $isAnswered = ((int)($r['maturity_rating'] ?? 0) > 0 || ($r['conformity_status'] ?? 'not_assessed') !== 'not_assessed'); ?>
        <?php $isValidated = ($r['validation_status'] === 'validated'); ?>
        <div class="question-card" id="q-<?php echo (int)$r['question_id']; ?>" data-question-id="<?php echo (int)$r['question_id']; ?>" data-answered="<?php echo $isAnswered ? '1' : '0'; ?>" data-validated="<?php echo $isValidated ? '1' : '0'; ?>">
            <div style="display:grid;grid-template-columns:1fr 280px;gap:16px;">
                <div>
                    <div style="margin-bottom:6px;">
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#1f2937;color:#fff;margin-right:6px;"><?php echo e($r['question_ref']); ?></span>
                        <span style="font-size:14px;font-weight:500;color:#333;"><?php echo e($r['question_text']); ?></span>
                        <?php if ($r['validation_status'] === 'validated'): ?>
                            <span class="validated-badge"><?php echo e(t('grc-assessment.validated')); ?></span>
                        <?php elseif ($r['validation_status'] === 'rejected'): ?>
                            <span class="rejected-badge"><?php echo e(t('grc-assessment.rejected')); ?></span>
                        <?php endif; ?>
                        <?php
                        $qTasks = $tasksByQuestion[(int)$r['question_id']] ?? [];
                        $activeTasks = array_filter($qTasks, function($qt) { return in_array($qt['status'], ['open', 'in_progress']); });
                        if (!empty($activeTasks)):
                            $hasLate = false;
                            $todayDate = date('Y-m-d');
                            foreach ($activeTasks as $qt) {
                                if (!empty($qt['due_date']) && $qt['due_date'] < $todayDate) { $hasLate = true; break; }
                            }
                        ?>
                            <?php if ($hasLate): ?>
                            <span class="assigned-late-badge"><?php echo e(t('grc-assessment.assigned_past_due')); ?></span>
                            <?php else: ?>
                            <span class="assigned-badge"><?php echo e(t('grc-assessment.assigned')); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['guidance']): ?>
                    <details style="margin-top:4px;">
                        <summary style="font-size:12px;color:#6b7280;cursor:pointer;"><?php echo e(t('grc-assessment.guidance_examples')); ?></summary>
                        <div style="margin-top:6px;padding:8px;background:#f9fafb;border-radius:6px;font-size:12px;color:#374151;">
                            <p style="margin:0 0 4px;"><?php echo nl2br(e($r['guidance'])); ?></p>
                            <?php if ($r['control_examples']): ?>
                            <p style="margin:0;color:#6b7280;font-style:italic;"><?php echo e($r['control_examples']); ?></p>
                            <?php endif; ?>
                            <div style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:8px;">
                                <button class="btn btn-xs btn-outline assistant-btn" data-action="explain-further" data-question-id="<?php echo (int)$r['question_id']; ?>" style="font-size:11px;"><?php echo e(t('grc-assessment.explain_further')); ?></button>
                                <div class="explain-further-content" id="explain-<?php echo (int)$r['question_id']; ?>" style="display:none;margin-top:8px;"></div>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
                <div>
                    <!-- Maturity Rating -->
                    <div style="margin-bottom:8px;">
                        <div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;margin-bottom:4px;"><?php echo e(t('grc-assessment.maturity')); ?></div>
                        <div class="maturity-btn-group">
                            <?php for ($i = 1; $i <= 4; $i++):
                                $mField = 'maturity_' . $i . '_desc';
                                $active = ((int)$r['maturity_rating'] === $i) ? 'active m-' . $i : '';
                            ?>
                            <button type="button" class="maturity-btn <?php echo $active; ?>"
                                    data-rating="<?php echo $i; ?>"
                                    data-question="<?php echo (int)$r['question_id']; ?>"
                                    title="<?php echo e($r[$mField] ?? ''); ?>"
                                    <?php echo $readOnly ? 'disabled' : ''; ?>>
                                <?php echo $i; ?>
                            </button>
                            <?php endfor; ?>
                            <button type="button" class="maturity-btn <?php echo $r['conformity_status'] === 'not_applicable' ? 'active m-na' : ''; ?>"
                                    data-rating="na"
                                    data-question="<?php echo (int)$r['question_id']; ?>"
                                    title="<?php echo e(t('grc-assessment.not_applicable')); ?>"
                                    <?php echo $readOnly ? 'disabled' : ''; ?>>
                                N/A
                            </button>
                        </div>
                    </div>

                    <!-- Conformity Status -->
                    <div style="margin-bottom:8px;">
                        <select class="conformity-select"
                                data-question="<?php echo (int)$r['question_id']; ?>"
                                <?php echo $readOnly ? 'disabled' : ''; ?>>
                            <option value="not_assessed" <?php echo $r['conformity_status'] === 'not_assessed' ? 'selected' : ''; ?>><?php echo e(t('grc-assessment.not_assessed')); ?></option>
                            <option value="conforming" <?php echo $r['conformity_status'] === 'conforming' ? 'selected' : ''; ?>><?php echo e(t('grc-assessment.conforming')); ?></option>
                            <option value="partial" <?php echo $r['conformity_status'] === 'partial' ? 'selected' : ''; ?>><?php echo e(t('grc-assessment.partial')); ?></option>
                            <option value="non_conforming" <?php echo $r['conformity_status'] === 'non_conforming' ? 'selected' : ''; ?>><?php echo e(t('grc-assessment.non_conforming')); ?></option>
                            <option value="not_applicable" <?php echo $r['conformity_status'] === 'not_applicable' ? 'selected' : ''; ?>><?php echo e(t('grc-assessment.not_applicable')); ?></option>
                        </select>
                    </div>

                    <!-- Actions Row -->
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <button class="btn btn-xs btn-outline notes-toggle" data-question="<?php echo (int)$r['question_id']; ?>">
                            <?php echo e(t('grc-assessment.notes')); ?> <?php if ($r['notes']): ?><span style="background:#3b82f6;color:#fff;padding:1px 5px;border-radius:8px;font-size:9px;margin-left:2px;">1</span><?php endif; ?>
                        </button>
                        <button class="btn btn-xs btn-info" data-action="show-frameworks" data-question-id="<?php echo (int)$r['question_id']; ?>"><?php echo e(t('grc-assessment.frameworks')); ?></button>
                        <button class="btn btn-xs btn-outline assistant-btn" data-action="get-guidance" data-question-id="<?php echo (int)$r['question_id']; ?>"><?php echo e(t('grc-assessment.assistant')); ?></button>
                        <?php if ($r['conformity_status'] === 'non_conforming' || $r['conformity_status'] === 'partial'): ?>
                        <span class="gap-badge"><?php echo e(t('grc-assessment.gap')); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Guidance Panel (Assistant, hidden by default) -->
            <div class="guidance-panel" id="guidance-<?php echo (int)$r['question_id']; ?>" style="display:none;margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span class="evidence-section-label" style="margin:0;"><?php echo e(t('grc-assessment.assistant_guidance')); ?></span>
                    <button class="btn btn-xs btn-outline" data-action="close-guidance" data-question-id="<?php echo (int)$r['question_id']; ?>" style="font-size:11px;"><?php echo e(t('grc-assessment.close')); ?></button>
                </div>
                <div class="guidance-content" id="guidance-content-<?php echo (int)$r['question_id']; ?>">
                    <p style="color:#9ca3af;font-size:12px;font-style:italic;"><?php echo e(t('grc-assessment.click_assistant')); ?></p>
                </div>
            </div>

            <!-- Notes Panel (hidden by default) -->
            <div class="notes-panel" id="notes-<?php echo (int)$r['question_id']; ?>" style="display:none;margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px;">
                <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
                    <textarea class="notes-textarea"
                              data-question="<?php echo (int)$r['question_id']; ?>"
                              rows="2"
                              placeholder="<?php echo e(t('grc-assessment.notes_placeholder')); ?>"
                              style="flex:1;"
                              <?php echo $readOnly ? 'readonly' : ''; ?>><?php echo e($r['notes'] ?? ''); ?></textarea>
                    <?php if (!$readOnly): ?>
                    <button class="btn-rewrite" data-action="assistant-rewrite" data-question-id="<?php echo (int)$r['question_id']; ?>" title="<?php echo e(t('grc-assessment.assistant_rewrite')); ?>"><?php echo e(t('grc-assessment.rewrite')); ?></button>
                    <?php endif; ?>
                </div>
                <?php if ($r['assessor_name']): ?>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;"><?php echo e(t('grc-assessment.last_assessed_by')); ?> <?php echo e($r['assessor_name']); ?> <?php echo e(t('grc-assessment.on')); ?> <?php echo e($r['assessed_at']); ?></div>
                <?php endif; ?>

                <?php if ($canManage || $isAuditor): ?>
                <div style="margin-top:8px;display:flex;gap:6px;">
                    <button class="btn btn-xs btn-warning" data-action="open-task" data-question-id="<?php echo (int)$r['question_id']; ?>" data-question-ref="<?php echo e($r['question_ref']); ?>"><?php echo e(t('grc-assessment.assign_task')); ?></button>
                    <button class="btn btn-xs btn-danger" data-action="open-risk" data-question-id="<?php echo (int)$r['question_id']; ?>" data-question-ref="<?php echo e($r['question_ref']); ?>" data-question-text="<?php echo e($r['question_text']); ?>"><?php echo e(t('grc-assessment.log_risk')); ?></button>
                    <button class="btn btn-xs btn-success" data-action="validate-response" data-response-id="<?php echo (int)$r['id']; ?>" data-status="validated"><?php echo e(t('grc-assessment.validate')); ?></button>
                    <button class="btn btn-xs btn-danger" data-action="validate-response" data-response-id="<?php echo (int)$r['id']; ?>" data-status="rejected"><?php echo e(t('grc-assessment.reject')); ?></button>
                </div>
                <?php endif; ?>

                <!-- Evidence Section -->
                <div class="evidence-section" data-response-id="<?php echo (int)$r['id']; ?>">
                    <div class="evidence-section-label"><?php echo e(t('grc-assessment.evidence_attachments')); ?></div>
                    <ul class="evidence-list" id="ev-list-<?php echo (int)$r['id']; ?>">
                        <li style="color:#9ca3af;font-style:italic;border:none;background:none;padding:2px 0;"><?php echo e(t('grc-assessment.loading')); ?></li>
                    </ul>
                    <?php if (!$readOnly): ?>
                    <div class="evidence-upload-row">
                        <input type="file" id="ev-file-<?php echo (int)$r['id']; ?>"
                               accept=".pdf,.png,.jpg,.jpeg,.gif,.docx,.xlsx,.doc,.xls,.csv,.txt,.json,.xml">
                        <button class="btn-upload" data-action="upload-evidence" data-response-id="<?php echo (int)$r['id']; ?>"><?php echo e(t('grc-assessment.upload')); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endforeach; ?>
    <?php if ($currentDomain !== '') echo '</div>'; ?>
    </div>

    <!-- Assigned Tasks Section -->
    <?php
    $displayTasks = $assessmentTasks;
    if ($myTasksFilter) {
        $displayTasks = array_values(array_filter($assessmentTasks, function($tk) use ($user) {
            return (int)($tk['assigned_to'] ?? 0) === (int)$user['id'];
        }));
    }
    ?>
    <?php if (!empty($displayTasks)): ?>
    <div style="margin-top:24px;">
        <h3 style="font-size:16px;font-weight:600;color:#111827;margin:0 0 12px;display:flex;align-items:center;gap:8px;">
            <?php echo $myTasksFilter ? e(t('grc-assessment.my_tasks')) : e(t('grc-assessment.assigned_tasks')); ?>
            <span style="background:#3b82f6;color:#fff;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;"><?php echo count($displayTasks); ?></span>
        </h3>
        <div style="display:grid;gap:8px;">
            <?php
            $todayDate = date('Y-m-d');
            foreach ($displayTasks as $tk):
                $tkLate = !empty($tk['due_date']) && $tk['due_date'] < $todayDate && in_array($tk['status'], ['open', 'in_progress']);
                $priColors = ['critical' => '#dc3545', 'high' => '#f59e0b', 'medium' => '#3b82f6', 'low' => '#6b7280'];
                $pc = $priColors[$tk['priority']] ?? '#6b7280';
                $isDone = in_array($tk['status'], ['completed', 'cancelled']);
            ?>
            <?php
                $isMyTask = (int)($tk['assigned_to'] ?? 0) === (int)$user['id'];
                $typeLabels = ['evidence_request' => t('grc-assessment.evidence_request'), 'remediation' => t('grc-assessment.remediation'), 'review' => t('grc-assessment.review'), 'documentation' => t('grc-assessment.documentation'), 'implementation' => t('grc-assessment.implementation')];
            ?>
            <div id="task-row-<?php echo (int)$tk['id']; ?>" style="background:#fff;border:1px solid <?php echo $tkLate ? '#fecaca' : '#e5e7eb'; ?>;border-radius:8px;padding:14px 18px;<?php if ($tkLate) echo 'border-left:3px solid #dc3545;'; ?><?php if ($isDone) echo 'opacity:0.6;'; ?>">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                    <span style="font-weight:600;font-size:13px;color:#374151;"><?php echo e($tk['task_ref']); ?></span>
                    <span style="font-size:10px;font-weight:600;padding:1px 8px;border-radius:10px;background:<?php echo $pc; ?>20;color:<?php echo $pc; ?>;"><?php echo ucfirst($tk['priority']); ?></span>
                    <span style="font-size:10px;font-weight:600;padding:1px 8px;border-radius:10px;background:<?php echo $tk['status'] === 'in_progress' ? '#dbeafe' : ($isDone ? '#d1fae5' : '#f3f4f6'); ?>;color:<?php echo $tk['status'] === 'in_progress' ? '#1e40af' : ($isDone ? '#065f46' : '#374151'); ?>;"><?php echo ucfirst(str_replace('_', ' ', $tk['status'])); ?></span>
                    <span style="font-size:10px;font-weight:500;padding:1px 8px;border-radius:10px;background:#f3f4f6;color:#6b7280;"><?php echo $typeLabels[$tk['task_type'] ?? 'evidence_request'] ?? ucfirst(str_replace('_', ' ', $tk['task_type'] ?? '')); ?></span>
                    <?php if ($tkLate): ?>
                    <span class="assigned-late-badge"><?php echo e(t('grc-assessment.past_due')); ?></span>
                    <?php endif; ?>
                </div>

                <div style="font-size:14px;font-weight:500;color:#111827;margin-bottom:4px;"><?php echo e($tk['title']); ?></div>

                <?php if (!empty($tk['description'])): ?>
                <div style="font-size:13px;color:#4b5563;margin-bottom:6px;padding:8px 10px;background:#f9fafb;border-radius:6px;border-left:3px solid #d1d5db;"><?php echo nl2br(e($tk['description'])); ?></div>
                <?php endif; ?>

                <?php if (!empty($tk['notes'])): ?>
                <div style="font-size:12px;color:#6b7280;margin-bottom:6px;font-style:italic;"><?php echo e(t('grc-assessment.notes')); ?>: <?php echo nl2br(e($tk['notes'])); ?></div>
                <?php endif; ?>

                <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">
                    <?php echo e(t('grc-assessment.assigned_to')); ?> <strong id="task-assignee-<?php echo (int)$tk['id']; ?>"><?php echo e($tk['assigned_to_name'] ?? t('grc-assessment.unassigned')); ?></strong>
                    &middot; <?php echo e(t('grc-assessment.from')); ?> <?php echo e($tk['assigned_by_name'] ?? '—'); ?>
                    <?php if (!empty($tk['due_date'])): ?>
                    &middot; <?php echo e(t('grc-assessment.due')); ?> <strong><?php echo date('M j, Y', strtotime($tk['due_date'])); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($tk['question_ref'])): ?>
                    &middot; <?php echo e(t('grc-assessment.question_label')); ?> <?php echo e($tk['question_ref']); ?>
                    <?php endif; ?>
                </div>

                <?php if (!$isDone): ?>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding-top:6px;border-top:1px solid #f3f4f6;">
                    <?php if ($isMyTask || $canManage): ?>
                    <?php if ($tk['status'] === 'open'): ?>
                    <button data-action="update-task-status" data-task-id="<?php echo (int)$tk['id']; ?>" data-status="in_progress" class="btn btn-xs" style="font-size:11px;padding:3px 10px;background:#f59e0b;color:#fff;border:none;border-radius:4px;cursor:pointer;"><?php echo e(t('grc-assessment.start_working')); ?></button>
                    <?php endif; ?>
                    <button data-action="update-task-status" data-task-id="<?php echo (int)$tk['id']; ?>" data-status="completed" class="btn btn-xs" style="font-size:11px;padding:3px 10px;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;"><?php echo e(t('grc-assessment.mark_complete')); ?></button>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                    <select data-action="reassign-task" data-task-id="<?php echo (int)$tk['id']; ?>" style="font-size:11px;padding:2px 6px;border:1px solid #d1d5db;border-radius:4px;background:#fff;">
                        <option value=""><?php echo e(t('grc-assessment.reassign')); ?></option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo (int)$u['id'] === (int)($tk['assigned_to'] ?? 0) ? 'disabled' : ''; ?>><?php echo e($u['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button data-action="delete-task" data-task-id="<?php echo (int)$tk['id']; ?>" data-task-ref="<?php echo e($tk['task_ref']); ?>" class="btn btn-xs btn-danger" style="font-size:10px;padding:3px 8px;" title="<?php echo e(t('grc-assessment.delete_task')); ?>"><?php echo e(t('grc-assessment.delete')); ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Assessment Modal -->
    <?php if ($canManage): ?>
    <div class="grc-modal-overlay" id="editAssessmentOverlay">
        <form method="POST" class="grc-modal">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="update_assessment">
            <input type="hidden" name="assessment_id" value="<?php echo $viewId; ?>">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.edit_assessment')); ?></h4>
                <button type="button" class="grc-modal-close" data-action="close-edit-assessment">&times;</button>
            </div>
                <div class="grc-modal-body">
                    <div class="grc-form" style="border:none;padding:0;margin:0;">
                        <label><?php echo e(t('grc-assessment.title')); ?> <span style="color:#dc3545;">*</span></label>
                        <input type="text" name="title" value="<?php echo e($assessmentDetail['title']); ?>" required>
                        <label><?php echo e(t('grc-assessment.description')); ?></label>
                        <div style="display:flex;gap:6px;align-items:flex-start;">
                            <textarea name="description" id="editDescription" rows="2" style="flex:1;"><?php echo e($assessmentDetail['description'] ?? ''); ?></textarea>
                            <button type="button" class="btn-rewrite" data-action="modal-assist" data-target="editDescription" data-field="description" title="<?php echo e(t('grc-assessment.assistant_rewrite')); ?>"><?php echo e(t('grc-assessment.rewrite')); ?></button>
                        </div>
                        <div class="form-row">
                            <div>
                                <label><?php echo e(t('grc-assessment.type')); ?></label>
                                <select name="assessment_type">
                                    <?php foreach (['initial','periodic','targeted','pre_audit','certification'] as $t): ?>
                                    <option value="<?php echo $t; ?>" <?php echo $assessmentDetail['assessment_type'] === $t ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $t)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label><?php echo e(t('grc-assessment.status')); ?></label>
                                <select name="status">
                                    <?php foreach (['draft','in_progress','under_review','completed','archived'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $assessmentDetail['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $s)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label><?php echo e(t('grc-assessment.scope')); ?></label>
                        <select name="scope_id" id="editScopeSelect">
                            <option value=""><?php echo e(t('grc-assessment.select_scope')); ?></option>
                            <?php foreach ($scopes as $sc): ?>
                            <option value="<?php echo (int)$sc['id']; ?>" <?php echo (int)($assessmentDetail['scope_id'] ?? 0) === (int)$sc['id'] ? 'selected' : ''; ?>><?php echo e($sc['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="__new__"><?php echo e(t('grc-assessment.add_new_scope')); ?></option>
                        </select>
                        <div id="editNewScopeFields" style="display:none;margin-top:8px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                            <label style="font-size:12px;"><?php echo e(t('grc-assessment.new_scope_name')); ?></label>
                            <input type="text" name="new_scope_name" placeholder="<?php echo e(t('grc-assessment.scope_name_placeholder')); ?>">
                        </div>
                        <label style="margin-top:8px;"><?php echo e(t('grc-assessment.scope_notes')); ?></label>
                        <div style="display:flex;gap:6px;align-items:flex-start;">
                            <textarea name="scope" id="editScope" rows="2" style="flex:1;"><?php echo e($assessmentDetail['scope'] ?? ''); ?></textarea>
                            <button type="button" class="btn-rewrite" data-action="modal-assist" data-target="editScope" data-field="scope" title="<?php echo e(t('grc-assessment.assistant_rewrite')); ?>"><?php echo e(t('grc-assessment.rewrite')); ?></button>
                        </div>
                        <label><?php echo e(t('grc-assessment.lead_auditor')); ?></label>
                        <select name="lead_auditor_id">
                            <option value=""><?php echo e(t('grc-assessment.select')); ?></option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo (int)$assessmentDetail['lead_auditor_id'] === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-row">
                            <div><label><?php echo e(t('grc-assessment.planned_start')); ?></label><input type="date" name="planned_start" value="<?php echo e($assessmentDetail['planned_start'] ?? ''); ?>"></div>
                            <div><label><?php echo e(t('grc-assessment.planned_end')); ?></label><input type="date" name="planned_end" value="<?php echo e($assessmentDetail['planned_end'] ?? ''); ?>"></div>
                        </div>
                    </div>
                </div>
                <div class="grc-modal-footer">
                    <?php if ($isAdmin): ?>
                    <button type="submit" name="action" value="delete_assessment" class="btn btn-danger" style="margin-right:auto;" data-confirm="<?php echo e(t('grc-assessment.confirm_delete_assessment')); ?>"><?php echo e(t('grc-assessment.delete')); ?></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-warning" data-action="close-edit-assessment"><?php echo e(t('grc-assessment.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('grc-assessment.save_changes')); ?></button>
                </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Task Modal -->
    <div class="grc-modal-overlay" id="taskOverlay">
        <div class="grc-modal">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.assign_task')); ?></h4>
                <button class="grc-modal-close" data-action="close-task">&times;</button>
            </div>
            <div class="grc-modal-body">
                <input type="hidden" id="taskQuestionId">
                <div class="grc-form" style="border:none;padding:0;margin:0;">
                    <label><?php echo e(t('grc-assessment.task_title')); ?> <span style="color:#dc3545;">*</span></label>
                    <input type="text" id="taskTitle" required>
                    <label><?php echo e(t('grc-assessment.description')); ?></label>
                    <textarea id="taskDescription" rows="2"></textarea>
                    <div class="form-row">
                        <div>
                            <label><?php echo e(t('grc-assessment.type')); ?></label>
                            <select id="taskType">
                                <option value="evidence_request"><?php echo e(t('grc-assessment.evidence_request')); ?></option>
                                <option value="remediation"><?php echo e(t('grc-assessment.remediation')); ?></option>
                                <option value="review"><?php echo e(t('grc-assessment.review')); ?></option>
                                <option value="documentation"><?php echo e(t('grc-assessment.documentation')); ?></option>
                                <option value="implementation"><?php echo e(t('grc-assessment.implementation')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo e(t('grc-assessment.priority')); ?></label>
                            <select id="taskPriority">
                                <option value="low"><?php echo e(t('grc-assessment.low')); ?></option>
                                <option value="medium" selected><?php echo e(t('grc-assessment.medium')); ?></option>
                                <option value="high"><?php echo e(t('grc-assessment.high')); ?></option>
                                <option value="critical"><?php echo e(t('grc-assessment.critical')); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label><?php echo e(t('grc-assessment.assign_to')); ?></label>
                            <select id="taskAssignedTo">
                                <option value=""><?php echo e(t('grc-assessment.select')); ?></option>
                                <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label><?php echo e(t('grc-assessment.due_date')); ?></label>
                            <input type="date" id="taskDueDate">
                        </div>
                    </div>
                </div>
            </div>
            <div class="grc-modal-footer">
                <button type="button" class="btn btn-outline" data-action="close-task"><?php echo e(t('grc-assessment.cancel')); ?></button>
                <button type="button" class="btn btn-primary" data-action="submit-task"><?php echo e(t('grc-assessment.create_task')); ?></button>
            </div>
        </div>
    </div>

    <!-- Risk Register Modal -->
    <div class="grc-modal-overlay" id="riskOverlay">
        <div class="grc-modal">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.log_risk')); ?></h4>
                <button class="grc-modal-close" data-action="close-risk">&times;</button>
            </div>
            <div class="grc-modal-body">
                <input type="hidden" id="riskQuestionId">
                <div class="grc-form" style="border:none;padding:0;margin:0;">
                    <label><?php echo e(t('grc-assessment.risk_title')); ?> <span style="color:#dc3545;">*</span></label>
                    <input type="text" id="riskTitle" required>
                    <label><?php echo e(t('grc-assessment.description')); ?></label>
                    <textarea id="riskDescription" rows="2"></textarea>
                    <div class="form-row">
                        <div>
                            <label><?php echo e(t('grc-assessment.category')); ?> <span style="color:#dc3545;">*</span></label>
                            <select id="riskCategory">
                                <option value="compliance"><?php echo e(t('grc-assessment.cat_compliance')); ?></option>
                                <option value="strategic"><?php echo e(t('grc-assessment.cat_strategic')); ?></option>
                                <option value="operational"><?php echo e(t('grc-assessment.cat_operational')); ?></option>
                                <option value="financial"><?php echo e(t('grc-assessment.cat_financial')); ?></option>
                                <option value="reputational"><?php echo e(t('grc-assessment.cat_reputational')); ?></option>
                                <option value="technology"><?php echo e(t('grc-assessment.cat_technology')); ?></option>
                                <option value="third_party"><?php echo e(t('grc-assessment.cat_third_party')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo e(t('grc-assessment.status')); ?></label>
                            <select id="riskStatus">
                                <option value="identified" selected><?php echo e(t('grc-assessment.identified')); ?></option>
                                <option value="assessing"><?php echo e(t('grc-assessment.assessing')); ?></option>
                                <option value="treating"><?php echo e(t('grc-assessment.treating')); ?></option>
                                <option value="monitoring"><?php echo e(t('grc-assessment.monitoring')); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label><?php echo e(t('grc-assessment.likelihood')); ?> <span style="color:#dc3545;">*</span></label>
                            <select id="riskLikelihood">
                                <option value="rare"><?php echo e(t('grc-assessment.rare')); ?></option>
                                <option value="unlikely"><?php echo e(t('grc-assessment.unlikely')); ?></option>
                                <option value="possible" selected><?php echo e(t('grc-assessment.possible')); ?></option>
                                <option value="likely"><?php echo e(t('grc-assessment.likely')); ?></option>
                                <option value="almost_certain"><?php echo e(t('grc-assessment.almost_certain')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label><?php echo e(t('grc-assessment.impact')); ?> <span style="color:#dc3545;">*</span></label>
                            <select id="riskImpact">
                                <option value="insignificant"><?php echo e(t('grc-assessment.insignificant')); ?></option>
                                <option value="minor"><?php echo e(t('grc-assessment.minor')); ?></option>
                                <option value="moderate" selected><?php echo e(t('grc-assessment.moderate')); ?></option>
                                <option value="major"><?php echo e(t('grc-assessment.major')); ?></option>
                                <option value="catastrophic"><?php echo e(t('grc-assessment.catastrophic')); ?></option>
                            </select>
                        </div>
                    </div>
                    <label><?php echo e(t('grc-assessment.risk_owner')); ?></label>
                    <select id="riskOwner">
                        <option value=""><?php echo e(t('grc-assessment.select')); ?></option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grc-modal-footer">
                <button type="button" class="btn btn-outline" data-action="close-risk"><?php echo e(t('grc-assessment.cancel')); ?></button>
                <button type="button" class="btn btn-danger" data-action="submit-risk"><?php echo e(t('grc-assessment.log_risk')); ?></button>
            </div>
        </div>
    </div>

    <!-- Framework Detail Modal -->
    <div class="grc-modal-overlay" id="frameworkOverlay">
        <div class="grc-modal modal-lg">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.framework_mappings')); ?></h4>
                <button class="grc-modal-close" data-action="close-frameworks">&times;</button>
            </div>
            <div class="grc-modal-body" id="frameworkDetailBody">
                <p style="color:#6b7280;"><?php echo e(t('grc-assessment.loading')); ?></p>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ═══════════ ASSESSMENT LIST VIEW ═══════════ -->

    <?php if ($questionCount === 0 && $isAdmin): ?>
    <div class="alert alert-warning">
        <strong><?php echo e(t('grc-assessment.question_bank_empty')); ?></strong> <?php echo e(t('grc-assessment.seed_to_start')); ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="seed_questions">
            <button type="submit" class="btn btn-sm btn-success" style="margin-left:8px;"><?php echo e(t('grc-assessment.seed_questions')); ?></button>
        </form>
    </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <div style="display:flex;gap:4px;">
            <a href="grc-assessment.php" class="domain-tab <?php echo !$statusFilter ? 'active' : ''; ?>"><?php echo e(t('grc-assessment.all')); ?></a>
            <?php foreach (['draft','in_progress','under_review','completed'] as $sf): ?>
            <a href="grc-assessment.php?status=<?php echo $sf; ?>" class="domain-tab <?php echo $statusFilter === $sf ? 'active' : ''; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $sf)); ?>
            </a>
            <?php endforeach; ?>
            <a href="grc-assessment.php?status=archived" class="domain-tab <?php echo $statusFilter === 'archived' ? 'active' : ''; ?>" style="margin-left:8px;"><?php echo e(t('grc-assessment.archived')); ?></a>
        </div>
        <?php if ($canManage): ?>
        <button class="btn btn-sm btn-success" data-action="open-create-assessment"><?php echo e(t('grc-assessment.new_assessment')); ?></button>
        <?php endif; ?>
    </div>

    <?php if (empty($assessments)): ?>
    <div class="detail-card" style="text-align:center;padding:60px;color:#6b7280;"><?php echo e(t('grc-assessment.no_assessments')); ?></div>
    <?php else: ?>
    <table class="grc-table">
        <thead>
            <tr>
                <th><?php echo e(t('grc-assessment.col_ref')); ?></th><th><?php echo e(t('grc-assessment.col_title')); ?></th><th><?php echo e(t('grc-assessment.col_type')); ?></th><th><?php echo e(t('grc-assessment.col_status')); ?></th><th><?php echo e(t('grc-assessment.col_lead')); ?></th>
                <th><?php echo e(t('grc-assessment.col_csf_score')); ?></th><th><?php echo e(t('grc-assessment.col_compliance')); ?></th><th><?php echo e(t('grc-assessment.col_planned')); ?></th><th><?php echo e(t('grc-assessment.col_actions')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($assessments as $a): ?>
            <tr>
                <td><strong><?php echo e($a['assessment_ref']); ?></strong></td>
                <td><?php echo e($a['title']); ?></td>
                <td><?php echo ucfirst(str_replace('_', ' ', e($a['assessment_type']))); ?></td>
                <td><span class="status-badge status-<?php echo e($a['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', e($a['status']))); ?></span></td>
                <td><?php echo e($a['lead_auditor_name'] ?? '—'); ?></td>
                <td><?php echo $a['overall_fairscore'] ?? '—'; ?></td>
                <td><?php echo $a['overall_compliance_pct'] ? $a['overall_compliance_pct'] . '%' : '—'; ?></td>
                <td><?php echo e($a['planned_start'] ?? '—'); ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($a['status'] === 'archived'): ?>
                        <?php if ($canManage): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="assessment_id" value="<?php echo $a['id']; ?>"><button type="submit" name="action" value="unarchive_assessment" class="btn btn-xs btn-info" title="<?php echo e(t('grc-assessment.restore_from_archive')); ?>"><?php echo e(t('grc-assessment.unarchive')); ?></button></form>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="assessment_id" value="<?php echo $a['id']; ?>"><button type="submit" name="action" value="delete_assessment" class="btn btn-xs btn-danger" data-confirm="<?php echo e(t('grc-assessment.confirm_permanently_delete')); ?>" title="<?php echo e(t('grc-assessment.permanently_delete')); ?>"><?php echo e(t('grc-assessment.delete')); ?></button></form>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="grc-assessment.php?view=<?php echo $a['id']; ?>" class="btn btn-xs btn-info"><?php echo e(t('grc-assessment.open')); ?></a>
                        <?php if ($canManage): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>"><input type="hidden" name="assessment_id" value="<?php echo $a['id']; ?>"><button type="submit" name="action" value="archive_assessment" class="btn btn-xs" style="background:#6b7280;color:#fff;" data-confirm="<?php echo e(t('grc-assessment.confirm_archive_assessment')); ?>" title="<?php echo e(t('grc-assessment.archive_assessment')); ?>"><?php echo e(t('grc-assessment.archive')); ?></button></form>
                        <button class="btn btn-xs btn-warning" data-action="duplicate-assessment" data-id="<?php echo $a['id']; ?>" data-title="<?php echo e($a['title']); ?>" title="<?php echo e(t('grc-assessment.duplicate_assessment')); ?>"><?php echo e(t('grc-assessment.duplicate')); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Create Assessment Modal -->
    <?php if ($canManage): ?>
    <div class="grc-modal-overlay" id="createAssessmentOverlay">
        <form method="POST" class="grc-modal">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="create_assessment">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.new_csf_assessment')); ?></h4>
                <button type="button" class="grc-modal-close" data-action="close-create-assessment">&times;</button>
            </div>
                <div class="grc-modal-body">
                    <div class="grc-form" style="border:none;padding:0;margin:0;">
                        <label><?php echo e(t('grc-assessment.title')); ?> <span style="color:#dc3545;">*</span></label>
                        <input type="text" name="title" required placeholder="<?php echo e(t('grc-assessment.title_placeholder')); ?>">
                        <label><?php echo e(t('grc-assessment.description')); ?></label>
                        <div style="display:flex;gap:6px;align-items:flex-start;">
                            <textarea name="description" id="createDescription" rows="2" placeholder="<?php echo e(t('grc-assessment.description_placeholder')); ?>" style="flex:1;"></textarea>
                            <button type="button" class="btn-rewrite" data-action="modal-assist" data-target="createDescription" data-field="description" title="<?php echo e(t('grc-assessment.assistant_rewrite')); ?>"><?php echo e(t('grc-assessment.rewrite')); ?></button>
                        </div>
                        <div class="form-row">
                            <div>
                                <label><?php echo e(t('grc-assessment.type')); ?></label>
                                <select name="assessment_type">
                                    <option value="initial"><?php echo e(t('grc-assessment.type_initial')); ?></option>
                                    <option value="periodic"><?php echo e(t('grc-assessment.type_periodic')); ?></option>
                                    <option value="targeted"><?php echo e(t('grc-assessment.type_targeted')); ?></option>
                                    <option value="pre_audit"><?php echo e(t('grc-assessment.type_pre_audit')); ?></option>
                                    <option value="certification"><?php echo e(t('grc-assessment.type_certification')); ?></option>
                                </select>
                            </div>
                            <div>
                                <label><?php echo e(t('grc-assessment.lead_auditor')); ?></label>
                                <select name="lead_auditor_id">
                                    <option value=""><?php echo e(t('grc-assessment.select')); ?></option>
                                    <?php foreach ($users_list as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo e($u['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label><?php echo e(t('grc-assessment.scope')); ?></label>
                        <select name="scope_id" id="createScopeSelect">
                            <option value=""><?php echo e(t('grc-assessment.select_scope')); ?></option>
                            <?php foreach ($scopes as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="__new__"><?php echo e(t('grc-assessment.add_new_scope')); ?></option>
                        </select>
                        <div id="createNewScopeFields" style="display:none;margin-top:8px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                            <label style="font-size:12px;"><?php echo e(t('grc-assessment.new_scope_name')); ?></label>
                            <input type="text" name="new_scope_name" placeholder="<?php echo e(t('grc-assessment.scope_name_placeholder')); ?>">
                            <label style="font-size:12px;margin-top:4px;"><?php echo e(t('grc-assessment.description_optional')); ?></label>
                            <input type="text" name="new_scope_description" placeholder="<?php echo e(t('grc-assessment.scope_description_placeholder')); ?>">
                        </div>
                        <label style="margin-top:8px;"><?php echo e(t('grc-assessment.scope_notes')); ?></label>
                        <div style="display:flex;gap:6px;align-items:flex-start;">
                            <textarea name="scope" id="createScope" rows="2" placeholder="<?php echo e(t('grc-assessment.scope_details_placeholder')); ?>" style="flex:1;"></textarea>
                            <button type="button" class="btn-rewrite" data-action="modal-assist" data-target="createScope" data-field="scope" title="<?php echo e(t('grc-assessment.assistant_rewrite')); ?>"><?php echo e(t('grc-assessment.rewrite')); ?></button>
                        </div>
                        <div class="form-row">
                            <div><label><?php echo e(t('grc-assessment.planned_start')); ?></label><input type="date" name="planned_start"></div>
                            <div><label><?php echo e(t('grc-assessment.planned_end')); ?></label><input type="date" name="planned_end"></div>
                        </div>
                    </div>
                </div>
                <div class="grc-modal-footer">
                    <button type="button" class="btn btn-outline" data-action="close-create-assessment"><?php echo e(t('grc-assessment.cancel')); ?></button>
                    <button type="submit" class="btn btn-success"><?php echo e(t('grc-assessment.create_assessment')); ?></button>
                </div>
        </form>
    </div>
    <!-- Duplicate Assessment Confirmation Modal -->
    <div class="grc-modal-overlay" id="duplicateAssessmentOverlay">
        <form method="POST" class="grc-modal" style="max-width:480px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="duplicate_assessment">
            <input type="hidden" name="assessment_id" id="duplicateAssessmentId" value="">
            <div class="grc-modal-header">
                <h4><?php echo e(t('grc-assessment.duplicate_assessment')); ?></h4>
                <button type="button" class="grc-modal-close" data-action="close-duplicate">&times;</button>
            </div>
            <div class="grc-modal-body">
                <p style="margin:0 0 16px;color:#374151;font-size:14px;"><?php echo e(t('grc-assessment.create_copy_of')); ?> <strong id="duplicateAssessmentTitle"></strong>?</p>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:border-color 0.2s;" id="dupModeTemplate">
                        <input type="radio" name="duplicate_mode" value="template" checked style="margin-top:2px;">
                        <div>
                            <strong style="font-size:13px;color:#374151;"><?php echo e(t('grc-assessment.dup_template_title')); ?></strong>
                            <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php echo e(t('grc-assessment.dup_template_desc')); ?></p>
                        </div>
                    </label>
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer;transition:border-color 0.2s;" id="dupModeFull">
                        <input type="radio" name="duplicate_mode" value="full" style="margin-top:2px;">
                        <div>
                            <strong style="font-size:13px;color:#374151;"><?php echo e(t('grc-assessment.dup_full_title')); ?></strong>
                            <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php echo e(t('grc-assessment.dup_full_desc')); ?></p>
                        </div>
                    </label>
                </div>
            </div>
            <div class="grc-modal-footer">
                <button type="button" class="btn btn-outline" data-action="close-duplicate"><?php echo e(t('grc-assessment.cancel')); ?></button>
                <button type="submit" class="btn btn-warning"><?php echo e(t('grc-assessment.duplicate')); ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

<?php endif; ?>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    var _csrfToken = <?php echo json_encode($csrfToken); ?>;
    var _assessmentId = <?php echo $viewId; ?>;
    var _aiEnabled = <?php echo json_encode($aiEnabled); ?>;
    var _saveTimeout = null;
    // Filter state managed by _activeFilter in toggle functions

    function syncCsrf(token) {
        if (!token) return;
        _csrfToken = token;
        document.querySelectorAll('input[name="csrf_token"]').forEach(function(el) { el.value = token; });
    }

    function pollAiJob(jobId, onComplete, onError) {
        var interval = setInterval(function() {
            fetch('api/ai-job-status.php?id=' + jobId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.csrf_token) syncCsrf(data.csrf_token);
                if (data.status === 'completed' && data.result) {
                    clearInterval(interval);
                    onComplete(data.result);
                } else if (data.status === 'failed') {
                    clearInterval(interval);
                    onError(data.error || <?php echo json_encode(t('grc-assessment.js_processing_failed')); ?>);
                }
            })
            .catch(function() {
                clearInterval(interval);
                onError(<?php echo json_encode(t('grc-assessment.js_network_error')); ?>);
            });
        }, 3000);
    }

    // Question metadata for static (non-AI) guidance
    var _questionData = <?php
        $qMeta = [];
        if (!empty($responses)) {
            foreach ($responses as $r) {
                $qMeta[$r['question_id']] = [
                    'ref' => $r['question_ref'],
                    'text' => $r['question_text'],
                    'guidance' => $r['guidance'] ?? '',
                    'control_examples' => $r['control_examples'] ?? '',
                    'maturity_1' => $r['maturity_1_desc'] ?? '',
                    'maturity_2' => $r['maturity_2_desc'] ?? '',
                    'maturity_3' => $r['maturity_3_desc'] ?? '',
                    'maturity_4' => $r['maturity_4_desc'] ?? '',
                ];
            }
        }
        echo json_encode($qMeta);
    ?>;

    // Modal helpers
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function highlightDupMode() {
        var radios = document.querySelectorAll('input[name="duplicate_mode"]');
        radios.forEach(function(r) {
            var lbl = r.closest('label');
            if (lbl) lbl.style.borderColor = r.checked ? '#f59e0b' : '#e5e7eb';
        });
    }
    document.querySelectorAll('input[name="duplicate_mode"]').forEach(function(r) {
        r.addEventListener('change', highlightDupMode);
    });
    highlightDupMode();

    // Event delegation
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        switch (action) {
            case 'open-create-assessment':
                openModal('createAssessmentOverlay');
                break;
            case 'close-create-assessment':
                closeModal('createAssessmentOverlay');
                break;
            case 'duplicate-assessment':
                document.getElementById('duplicateAssessmentId').value = btn.getAttribute('data-id');
                document.getElementById('duplicateAssessmentTitle').textContent = btn.getAttribute('data-title');
                // Reset to template mode default
                var tmplRadio = document.querySelector('input[name="duplicate_mode"][value="template"]');
                if (tmplRadio) { tmplRadio.checked = true; highlightDupMode(); }
                openModal('duplicateAssessmentOverlay');
                break;
            case 'close-duplicate':
                closeModal('duplicateAssessmentOverlay');
                break;
            case 'open-edit-assessment':
                openModal('editAssessmentOverlay');
                break;
            case 'close-edit-assessment':
                closeModal('editAssessmentOverlay');
                break;
            case 'open-task':
                document.getElementById('taskQuestionId').value = btn.getAttribute('data-question-id');
                document.getElementById('taskTitle').value = <?php echo json_encode(t('grc-assessment.js_evidence_action_prefix')); ?> + btn.getAttribute('data-question-ref');
                openModal('taskOverlay');
                break;
            case 'close-task':
                closeModal('taskOverlay');
                break;
            case 'submit-task':
                submitTask();
                break;
            case 'open-risk':
                document.getElementById('riskQuestionId').value = btn.getAttribute('data-question-id');
                var qRef = btn.getAttribute('data-question-ref');
                var qText = btn.getAttribute('data-question-text');
                document.getElementById('riskTitle').value = qRef + ': ' + qText;
                document.getElementById('riskDescription').value = '';
                document.getElementById('riskCategory').value = 'compliance';
                document.getElementById('riskStatus').value = 'identified';
                document.getElementById('riskLikelihood').value = 'possible';
                document.getElementById('riskImpact').value = 'moderate';
                document.getElementById('riskOwner').value = '';
                openModal('riskOverlay');
                break;
            case 'close-risk':
                closeModal('riskOverlay');
                break;
            case 'submit-risk':
                submitRisk();
                break;
            case 'delete-task':
                var dtId = parseInt(btn.getAttribute('data-task-id'));
                var dtRef = btn.getAttribute('data-task-ref');
                if (confirm(<?php echo json_encode(t('grc-assessment.js_delete_task_prefix')); ?> + dtRef + <?php echo json_encode(t('grc-assessment.js_delete_task_suffix')); ?>)) {
                    fetch('api/grc-unified-assessment.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_task', task_id: dtId, csrf_token: _csrfToken })
                    }).then(function(r) { return r.json(); }).then(function(resp) {
                        syncCsrf(resp.csrf_token);
                        if (resp.success) {
                            var row = document.getElementById('task-row-' + dtId);
                            if (row) row.remove();
                        } else { alert(resp.error || <?php echo json_encode(t('grc-assessment.js_failed_delete_task')); ?>); }
                    });
                }
                break;
            case 'show-frameworks':
                showQuestionDetail(parseInt(btn.getAttribute('data-question-id')));
                break;
            case 'close-frameworks':
                closeModal('frameworkOverlay');
                break;
            case 'recalculate':
                recalculateScores();
                break;
            case 'generate-report':
                generateReport(btn);
                break;
            case 'validate-response':
                validateResponse(parseInt(btn.getAttribute('data-response-id')), btn.getAttribute('data-status'));
                break;
            case 'upload-evidence':
                uploadEvidence(parseInt(btn.getAttribute('data-response-id')), btn);
                break;
            case 'unlink-evidence':
                unlinkEvidence(parseInt(btn.getAttribute('data-response-id')), parseInt(btn.getAttribute('data-evidence-id')));
                break;
            case 'get-guidance':
                getGuidance(parseInt(btn.getAttribute('data-question-id')), btn);
                break;
            case 'close-guidance':
                var gp = document.getElementById('guidance-' + btn.getAttribute('data-question-id'));
                if (gp) gp.style.display = 'none';
                break;
            case 'assistant-rewrite':
                assistantRewrite(parseInt(btn.getAttribute('data-question-id')), btn);
                break;
            case 'accept-rewrite':
                acceptRewrite(parseInt(btn.getAttribute('data-question-id')));
                break;
            case 'discard-rewrite':
                var rp = document.getElementById('rewrite-preview-' + btn.getAttribute('data-question-id'));
                if (rp) rp.remove();
                break;
            case 'modal-assist':
                modalAssist(btn.getAttribute('data-target'), btn.getAttribute('data-field'), btn);
                break;
            case 'accept-modal-assist':
                acceptModalAssist(btn.getAttribute('data-target'));
                break;
            case 'discard-modal-assist':
                var mp = document.getElementById('modal-assist-preview-' + btn.getAttribute('data-target'));
                if (mp) mp.remove();
                break;
            case 'explain-further':
                explainFurther(parseInt(btn.getAttribute('data-question-id')), btn);
                break;
            case 'toggle-answered':
                toggleAnsweredQuestions();
                break;
            case 'show-unanswered':
                showUnansweredOnly();
                break;
        }
    });

    // Reassign task handler
    document.querySelectorAll('[data-action="reassign-task"]').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var taskId = parseInt(this.getAttribute('data-task-id'));
            var newUserId = parseInt(this.value);
            if (!newUserId) return;
            var selectedText = this.options[this.selectedIndex].text;
            fetch('api/grc-unified-assessment.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reassign_task', task_id: taskId, assigned_to: newUserId, csrf_token: _csrfToken })
            }).then(function(r) { return r.json(); }).then(function(resp) {
                syncCsrf(resp.csrf_token);
                if (resp.success) {
                    var label = document.getElementById('task-assignee-' + taskId);
                    if (label) label.textContent = selectedText;
                    sel.value = '';
                } else { alert(resp.error || <?php echo json_encode(t('grc-assessment.js_failed_reassign_task')); ?>); }
            });
        });
    });

    // Update task status handler (Start Working / Mark Complete)
    document.querySelectorAll('[data-action="update-task-status"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var taskId = parseInt(this.getAttribute('data-task-id'));
            var newStatus = this.getAttribute('data-status');
            var label = newStatus === 'completed' ? 'complete' : 'start';
            if (!confirm(<?php echo json_encode(t('grc-assessment.js_mark_task_as')); ?> + newStatus.replace('_', ' ') + '?')) return;
            btn.disabled = true;
            fetch('api/grc-unified-assessment.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_task', task_id: taskId, status: newStatus, csrf_token: _csrfToken })
            }).then(function(r) { return r.json(); }).then(function(resp) {
                syncCsrf(resp.csrf_token);
                if (resp.success) {
                    var row = document.getElementById('task-row-' + taskId);
                    if (newStatus === 'completed') {
                        if (row) { row.style.opacity = '0.6'; }
                        // Remove action buttons
                        var actions = row ? row.querySelector('[style*="border-top"]') : null;
                        if (actions) actions.innerHTML = '<span style="font-size:12px;color:#065f46;font-weight:500;">' + <?php echo json_encode(t('grc-assessment.js_completed')); ?> + '</span>';
                    } else {
                        // Refresh to show updated status
                        location.reload();
                    }
                } else {
                    alert(resp.error || <?php echo json_encode(t('grc-assessment.js_failed_update_task')); ?>);
                    btn.disabled = false;
                }
            }).catch(function() { btn.disabled = false; });
        });
    });

    // Close modals on overlay click (except edit-assessment modal — requires explicit Cancel/X)
    document.querySelectorAll('.grc-modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay && overlay.id !== 'editAssessmentOverlay') {
                overlay.classList.remove('active');
            }
        });
    });

    // Maturity button click
    document.querySelectorAll('.maturity-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var qid = this.dataset.question;
            var rating = this.dataset.rating;
            var group = this.closest('.maturity-btn-group');

            group.querySelectorAll('.maturity-btn').forEach(function(b) {
                b.className = 'maturity-btn';
            });
            if (rating === 'na') {
                this.className = 'maturity-btn active m-na';
            } else {
                this.className = 'maturity-btn active m-' + rating;
            }

            var data = { action: 'save_response', assessment_id: _assessmentId, question_id: parseInt(qid), csrf_token: _csrfToken };

            if (rating === 'na') {
                data.conformity_status = 'not_applicable';
                data.maturity_rating = '';
                var sel = document.querySelector('.conformity-select[data-question="' + qid + '"]');
                if (sel) sel.value = 'not_applicable';
            } else {
                data.maturity_rating = parseInt(rating);
                var notesEl = document.querySelector('.notes-textarea[data-question="' + qid + '"]');
                if (notesEl) data.notes = notesEl.value;
            }

            saveResponse(data);
        });
    });

    // Conformity dropdown change
    document.querySelectorAll('.conformity-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var qid = this.dataset.question;
            var data = {
                action: 'save_response', assessment_id: _assessmentId, question_id: parseInt(qid),
                csrf_token: _csrfToken, conformity_status: this.value
            };
            var notesEl = document.querySelector('.notes-textarea[data-question="' + qid + '"]');
            if (notesEl) data.notes = notesEl.value;
            saveResponse(data);
        });
    });

    // Notes auto-save with debounce
    document.querySelectorAll('.notes-textarea').forEach(function(ta) {
        ta.addEventListener('input', function() {
            var qid = this.dataset.question;
            var textarea = this;
            clearTimeout(_saveTimeout);
            _saveTimeout = setTimeout(function() {
                var data = {
                    action: 'save_response', assessment_id: _assessmentId, question_id: parseInt(qid),
                    csrf_token: _csrfToken, notes: textarea.value
                };
                saveResponse(data);
            }, 2000);
        });
    });

    // Toggle notes panel + load evidence
    document.querySelectorAll('.notes-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var panel = document.getElementById('notes-' + this.dataset.question);
            if (panel) {
                var showing = panel.style.display === 'none';
                panel.style.display = showing ? 'block' : 'none';
                if (showing) {
                    var evSection = panel.querySelector('.evidence-section');
                    if (evSection) loadEvidenceList(parseInt(evSection.getAttribute('data-response-id')));
                }
            }
        });
    });

    // Scope dropdown: show/hide "Add New" fields
    ['createScopeSelect', 'editScopeSelect'].forEach(function(selId) {
        var sel = document.getElementById(selId);
        if (!sel) return;
        var fieldsId = selId.replace('ScopeSelect', 'NewScopeFields');
        sel.addEventListener('change', function() {
            var fields = document.getElementById(fieldsId);
            if (fields) fields.style.display = this.value === '__new__' ? 'block' : 'none';
        });
    });

    // Form confirm dialogs (CSP-safe)
    document.addEventListener('submit', function(e) {
        var btn = e.submitter;
        if (btn && btn.hasAttribute('data-confirm')) {
            if (!confirm(btn.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });

    function saveResponse(data, _retried) {
        data.csrf_token = _csrfToken;
        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            // Auto-retry once on CSRF failure
            if (!resp.success && resp.error && resp.error.indexOf('CSRF') !== -1 && !_retried) {
                saveResponse(data, true);
                return;
            }
            if (resp.success && resp.progress) {
                var bar = document.getElementById('progressBar');
                if (bar) {
                    bar.style.width = resp.progress.progress_pct + '%';
                    bar.textContent = resp.progress.progress_pct + '%';
                }
                var progText = bar ? bar.parentElement.nextElementSibling : null;
                if (progText && resp.progress.answered !== undefined) {
                    progText.textContent = resp.progress.answered + ' ' + <?php echo json_encode(t('grc-assessment.of')); ?> + ' ' + resp.progress.total_questions + ' ' + <?php echo json_encode(t('grc-assessment.answered_lc')); ?>;
                }
            }
            // Update CSF Maturity and Compliance scores
            if (resp.success) {
                updateScoreDisplays(resp);
            }
            if (!resp.success) {
                alert(<?php echo json_encode(t('grc-assessment.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
            // Update answered state on the card
            if (resp.success && data.question_id) {
                var card = document.getElementById('q-' + data.question_id);
                if (card) {
                    var matBtn = card.querySelector('.maturity-btn.active:not(.m-na)');
                    var naBtn = card.querySelector('.maturity-btn.active.m-na');
                    var hasRating = !!(matBtn || naBtn);
                    var sel = card.querySelector('.conformity-select');
                    var hasConformity = sel && sel.value !== 'not_assessed';
                    card.setAttribute('data-answered', (hasRating || hasConformity) ? '1' : '0');
                }
            }
        })
        .catch(function(err) { console.error('Save error:', err); });
    }

    function recalculateScores() {
        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'calculate_scores', assessment_id: _assessmentId, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) { location.reload(); }
            else { alert(<?php echo json_encode(t('grc-assessment.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>)); }
        });
    }

    function generateReport(btn) {
        // Redirect to Report Editor page where user can edit sections with AI before generating PDF
        window.location.href = 'grc-assessment-report.php?id=' + _assessmentId;
    }

    function showQuestionDetail(questionId) {
        document.getElementById('frameworkDetailBody').innerHTML = '<p style="color:#6b7280;">' + <?php echo json_encode(t('grc-assessment.loading')); ?> + '</p>';
        openModal('frameworkOverlay');

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_question_detail', question_id: questionId, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) {
                var html = '<h4 style="font-size:14px;margin:0 0 16px;">' + escHtml(resp.question.question_ref) + ' &mdash; ' + escHtml(resp.question.question_text) + '</h4>';
                html += '<table class="grc-table"><thead><tr><th>' + <?php echo json_encode(t('grc-assessment.col_framework')); ?> + '</th><th>' + <?php echo json_encode(t('grc-assessment.col_requirement')); ?> + '</th><th>' + <?php echo json_encode(t('grc-assessment.col_title')); ?> + '</th><th>' + <?php echo json_encode(t('grc-assessment.col_strength')); ?> + '</th></tr></thead><tbody>';
                resp.mappings.forEach(function(m) {
                    var sColors = {exact:'#28a745',strong:'#3b82f6',partial:'#f59e0b',related:'#6b7280'};
                    var sColor = sColors[m.mapping_strength] || '#6b7280';
                    html += '<tr><td>' + escHtml(m.framework_code || '') + '</td><td><code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">' + escHtml(m.requirement_ref || '') + '</code></td>';
                    html += '<td>' + escHtml(m.requirement_title || '') + '</td>';
                    html += '<td><span style="background:' + sColor + ';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">' + escHtml(m.mapping_strength || '') + '</span></td></tr>';
                });
                html += '</tbody></table>';
                if (resp.mappings.length === 0) html += '<p style="color:#6b7280;">' + <?php echo json_encode(t('grc-assessment.js_no_mappings')); ?> + '</p>';
                document.getElementById('frameworkDetailBody').innerHTML = html;
            }
        });
    }

    function submitTask() {
        var data = {
            action: 'create_task', assessment_id: _assessmentId, csrf_token: _csrfToken,
            question_id: parseInt(document.getElementById('taskQuestionId').value) || null,
            task_title: document.getElementById('taskTitle').value,
            description: document.getElementById('taskDescription').value,
            task_type: document.getElementById('taskType').value,
            priority: document.getElementById('taskPriority').value,
            assigned_to: parseInt(document.getElementById('taskAssignedTo').value) || null,
            due_date: document.getElementById('taskDueDate').value
        };

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) {
                closeModal('taskOverlay');
                alert(<?php echo json_encode(t('grc-assessment.js_task_prefix')); ?> + resp.ref + <?php echo json_encode(t('grc-assessment.js_task_created_suffix')); ?>);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
        });
    }

    function submitRisk() {
        var data = {
            action: 'create_risk', assessment_id: _assessmentId, csrf_token: _csrfToken,
            question_id: parseInt(document.getElementById('riskQuestionId').value) || null,
            risk_title: document.getElementById('riskTitle').value,
            risk_description: document.getElementById('riskDescription').value,
            risk_category: document.getElementById('riskCategory').value,
            status: document.getElementById('riskStatus').value,
            likelihood: document.getElementById('riskLikelihood').value,
            impact: document.getElementById('riskImpact').value,
            owner_user_id: parseInt(document.getElementById('riskOwner').value) || null
        };

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) {
                closeModal('riskOverlay');
                alert(<?php echo json_encode(t('grc-assessment.js_risk_prefix')); ?> + resp.risk_ref + <?php echo json_encode(t('grc-assessment.js_risk_logged_suffix')); ?>);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
        });
    }

    function validateResponse(responseId, status) {
        var notes = prompt(<?php echo json_encode(t('grc-assessment.js_validation_notes_prompt')); ?>);
        if (notes === null) return;
        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'validate_response', response_id: responseId, validation_status: status, validation_notes: notes, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) { location.reload(); }
            else { alert(<?php echo json_encode(t('grc-assessment.js_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>)); }
        });
    }

    // ─── Modal Assistant (description/scope rewrite) ────────────────────

    function modalAssist(targetId, field, btn) {
        var ta = document.getElementById(targetId);
        if (!ta || !ta.value.trim()) {
            alert(<?php echo json_encode(t('grc-assessment.js_write_content_first')); ?>);
            return;
        }

        if (!_aiEnabled) {
            alert(<?php echo json_encode(t('grc-assessment.js_rewrite_not_configured')); ?>);
            return;
        }

        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment.js_rewriting')); ?>;

        var existing = document.getElementById('modal-assist-preview-' + targetId);
        if (existing) existing.remove();

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assistant_rewrite', text: ta.value,
                context: 'Cybersecurity assessment ' + field,
                field: field, csrf_token: _csrfToken
            })
        })
        .then(function(r) {
            if (!r.ok) {
                if (r.status === 429) throw new Error(<?php echo json_encode(t('grc-assessment.js_rate_limit')); ?>);
                throw new Error(<?php echo json_encode(t('grc-assessment.js_server_error_prefix')); ?> + r.status + ')');
            }
            return r.json();
        })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.queued && resp.job_id) {
                pollAiJob(resp.job_id, function(result) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
                    resp = result;
                    resp.success = true;
                    handleModalRewriteResult(resp, btn, ta, targetId);
                }, function(err) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
                    alert(err);
                });
                return;
            }
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
            handleModalRewriteResult(resp, btn, ta, targetId);
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
            alert(err.message || <?php echo json_encode(t('grc-assessment.js_connection_error')); ?>);
        });
    }

    function handleModalRewriteResult(resp, btn, ta, targetId) {
            if (resp.success && resp.content) {
                var preview = document.createElement('div');
                preview.className = 'rewrite-preview';
                preview.id = 'modal-assist-preview-' + targetId;
                preview.style.marginTop = '8px';
                preview.innerHTML = '<div class="evidence-section-label" style="margin-bottom:4px;color:#6d28d9;">' + <?php echo json_encode(t('grc-assessment.js_proposed_rewrite')); ?> + '</div>' +
                    '<div class="rewrite-text">' + escHtml(resp.content) + '</div>' +
                    '<div class="rewrite-actions" style="margin-top:6px;">' +
                    '<button type="button" class="btn btn-xs btn-success" data-action="accept-modal-assist" data-target="' + targetId + '">' + <?php echo json_encode(t('grc-assessment.js_accept')); ?> + '</button>' +
                    '<button type="button" class="btn btn-xs btn-outline" data-action="discard-modal-assist" data-target="' + targetId + '">' + <?php echo json_encode(t('grc-assessment.js_discard')); ?> + '</button>' +
                    '</div>';
                ta.parentNode.parentNode.insertBefore(preview, ta.parentNode.nextSibling);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_rewrite_failed_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
    }

    function acceptModalAssist(targetId) {
        var preview = document.getElementById('modal-assist-preview-' + targetId);
        var ta = document.getElementById(targetId);
        if (preview && ta) {
            var textEl = preview.querySelector('.rewrite-text');
            if (textEl) ta.value = textEl.textContent;
            preview.remove();
        }
    }

    // ─── Auto-save Timer (every 60 seconds) ────────────────────────────
    // Serializes saves to avoid CSRF token race conditions.

    var _autoSaving = false;
    if (_assessmentId > 0) {
        setInterval(function() {
            if (_autoSaving) return;
            // Collect notes that have content
            var textareas = document.querySelectorAll('.notes-textarea');
            var pending = [];
            textareas.forEach(function(ta) {
                if (ta.value && ta.value.trim() !== '') {
                    pending.push({ qid: parseInt(ta.dataset.question), notes: ta.value });
                }
            });

            if (pending.length === 0) {
                // Just refresh the CSRF token
                fetch('api/grc-unified-assessment.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'refresh_token', csrf_token: _csrfToken })
                })
                .then(function(r) { return r.json(); })
                .then(function(resp) { syncCsrf(resp.csrf_token); })
                .catch(function() {});
                return;
            }

            _autoSaving = true;
            // Save one at a time to keep CSRF tokens in sync
            function saveNext(index) {
                if (index >= pending.length) {
                    _autoSaving = false;
                    var indicator = document.getElementById('autosaveIndicator');
                    if (indicator) {
                        indicator.textContent = <?php echo json_encode(t('grc-assessment.js_saved_prefix')); ?> + new Date().toLocaleTimeString();
                        indicator.style.opacity = '1';
                        setTimeout(function() { indicator.style.opacity = '0.5'; }, 3000);
                    }
                    return;
                }
                var item = pending[index];
                fetch('api/grc-unified-assessment.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_response', assessment_id: _assessmentId,
                        question_id: item.qid, csrf_token: _csrfToken, notes: item.notes
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    syncCsrf(resp.csrf_token);
                    saveNext(index + 1);
                })
                .catch(function() { _autoSaving = false; });
            }
            saveNext(0);
        }, 60000);
    }

    // ─── Assistant Functions ─────────────────────────────────────────────

    function getGuidance(questionId, btn) {
        var panel = document.getElementById('guidance-' + questionId);
        var content = document.getElementById('guidance-content-' + questionId);
        if (!panel || !content) return;

        panel.style.display = 'block';
        btn.disabled = true;

        if (!_aiEnabled) {
            // Static guidance from question catalog data
            btn.textContent = <?php echo json_encode(t('grc-assessment.assistant')); ?>;
            btn.disabled = false;
            content.innerHTML = buildStaticGuidance(questionId);
            return;
        }

        content.innerHTML = '<p style="color:#9ca3af;font-size:12px;font-style:italic;">' + <?php echo json_encode(t('grc-assessment.js_loading_guidance')); ?> + '</p>';
        btn.textContent = <?php echo json_encode(t('grc-assessment.loading')); ?>;

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assistant_guidance', question_id: questionId, csrf_token: _csrfToken })
        })
        .then(function(r) {
            if (!r.ok) {
                if (r.status === 429) throw new Error(<?php echo json_encode(t('grc-assessment.js_rate_limit')); ?>);
                throw new Error(<?php echo json_encode(t('grc-assessment.js_server_error_prefix')); ?> + r.status + ')');
            }
            return r.json();
        })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.queued && resp.job_id) {
                pollAiJob(resp.job_id, function(result) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.assistant')); ?>;
                    if (result.content) {
                        content.innerHTML = markdownToHtml(result.content);
                    } else {
                        content.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + <?php echo json_encode(t('grc-assessment.js_no_guidance_returned')); ?> + '</p>';
                    }
                }, function(err) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.assistant')); ?>;
                    content.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(err) + '</p>';
                });
                return;
            }
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.assistant')); ?>;
            if (resp.success && resp.content) {
                content.innerHTML = markdownToHtml(resp.content);
            } else {
                content.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(resp.error || <?php echo json_encode(t('grc-assessment.js_failed_get_guidance')); ?>) + '</p>';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.assistant')); ?>;
            content.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(err.message || <?php echo json_encode(t('grc-assessment.js_connection_error')); ?>) + '</p>';
        });
    }

    function buildStaticGuidance(questionId) {
        var q = _questionData[questionId];
        if (!q) return '<p style="color:#6b7280;font-size:12px;">No guidance data available for this question.</p>';

        var html = '';

        // Guidance overview
        if (q.guidance) {
            html += '<h2>Overview</h2><p>' + escHtml(q.guidance) + '</p>';
        }

        // Maturity levels explained
        html += '<h2>Maturity Levels</h2><ul>';
        html += '<li><strong>Level 1 (Initial):</strong> ' + escHtml(q.maturity_1 || 'Ad-hoc or undocumented processes.') + '</li>';
        html += '<li><strong>Level 2 (Developing):</strong> ' + escHtml(q.maturity_2 || 'Partially documented, inconsistently applied.') + '</li>';
        html += '<li><strong>Level 3 (Defined):</strong> ' + escHtml(q.maturity_3 || 'Documented policies, consistently implemented.') + '</li>';
        html += '<li><strong>Level 4 (Managed):</strong> ' + escHtml(q.maturity_4 || 'Continuously monitored and optimized.') + '</li>';
        html += '</ul>';

        // Control examples
        if (q.control_examples) {
            html += '<h2>Control Examples &amp; Potential Evidence</h2>';
            html += '<p>' + escHtml(q.control_examples) + '</p>';
        }

        // Auditor tips
        html += '<h2>Auditor Tips</h2><ul>';
        html += '<li>Review the maturity level descriptions above and select the level that best matches your organization\'s current practices.</li>';
        html += '<li>Look for documented policies, standard operating procedures, and evidence of consistent implementation.</li>';
        html += '<li>Consider whether controls are monitored, measured, and regularly improved.</li>';
        html += '<li>Attach supporting evidence (screenshots, policy documents, audit logs) to substantiate your rating.</li>';
        html += '</ul>';

        return html;
    }

    function explainFurther(questionId, btn) {
        var container = document.getElementById('explain-' + questionId);
        if (!container) return;

        // Toggle visibility if already populated
        if (container.innerHTML.trim() !== '' && container.style.display !== 'none') {
            container.style.display = 'none';
            return;
        }
        if (container.innerHTML.trim() !== '') {
            container.style.display = 'block';
            return;
        }

        if (!_aiEnabled) {
            // Static detailed explanation from question catalog data
            var q = _questionData[questionId];
            if (!q) {
                container.innerHTML = '<p style="color:#6b7280;font-size:12px;">No additional detail available.</p>';
                container.style.display = 'block';
                return;
            }
            var html = '<div class="guidance-content" style="font-size:12px;">';
            html += '<h2>Detailed Explanation: ' + escHtml(q.ref) + '</h2>';
            html += '<p><strong>Question:</strong> ' + escHtml(q.text) + '</p>';
            if (q.guidance) {
                html += '<p><strong>What this means:</strong> ' + escHtml(q.guidance) + '</p>';
            }
            html += '<h2>What Each Maturity Level Looks Like</h2>';
            html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin-bottom:8px;">';
            html += '<p style="margin:0 0 6px;"><strong style="color:#dc3545;">Level 1 &mdash; Initial:</strong> ' + escHtml(q.maturity_1 || 'Processes are ad-hoc, undocumented, and reactive. No formal controls exist.') + '</p>';
            html += '<p style="margin:0 0 6px;"><strong style="color:#f59e0b;">Level 2 &mdash; Developing:</strong> ' + escHtml(q.maturity_2 || 'Some documented procedures exist but are inconsistently applied across the organization.') + '</p>';
            html += '<p style="margin:0 0 6px;"><strong style="color:#3b82f6;">Level 3 &mdash; Defined:</strong> ' + escHtml(q.maturity_3 || 'Policies are formally documented, approved, and consistently implemented organization-wide.') + '</p>';
            html += '<p style="margin:0;"><strong style="color:#28a745;">Level 4 &mdash; Managed:</strong> ' + escHtml(q.maturity_4 || 'Controls are continuously monitored, measured with metrics, and regularly improved.') + '</p>';
            html += '</div>';
            if (q.control_examples) {
                html += '<h2>Examples of Evidence to Look For</h2>';
                html += '<p>' + escHtml(q.control_examples) + '</p>';
            }
            html += '<h2>How to Assess This</h2><ul>';
            html += '<li>Start by identifying whether a formal, documented policy or procedure exists for this area.</li>';
            html += '<li>Check if the policy is actively enforced &mdash; are there audit logs, monitoring dashboards, or compliance reports?</li>';
            html += '<li>Interview stakeholders to determine if procedures are followed consistently or only sporadically.</li>';
            html += '<li>Look for evidence of periodic review cycles (annual policy reviews, tabletop exercises, penetration tests).</li>';
            html += '<li>If unsure between two levels, select the lower level and note what is needed to reach the next tier.</li>';
            html += '</ul></div>';
            container.innerHTML = html;
            container.style.display = 'block';
            return;
        }

        // AI-enabled: call AI for a detailed elaboration
        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment.loading')); ?>;
        container.innerHTML = '<p style="color:#9ca3af;font-size:12px;font-style:italic;">' + <?php echo json_encode(t('grc-assessment.js_generating_explanation')); ?> + '</p>';
        container.style.display = 'block';

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'assistant_explain', question_id: questionId, csrf_token: _csrfToken })
        })
        .then(function(r) {
            if (!r.ok) {
                if (r.status === 429) throw new Error(<?php echo json_encode(t('grc-assessment.js_rate_limit')); ?>);
                throw new Error(<?php echo json_encode(t('grc-assessment.js_server_error_prefix')); ?> + r.status + ')');
            }
            return r.json();
        })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.queued && resp.job_id) {
                pollAiJob(resp.job_id, function(result) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.explain_further')); ?>;
                    if (result.content) {
                        container.innerHTML = '<div class="guidance-content">' + markdownToHtml(result.content) + '</div>';
                    } else {
                        container.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + <?php echo json_encode(t('grc-assessment.js_no_explanation_returned')); ?> + '</p>';
                    }
                }, function(err) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.explain_further')); ?>;
                    container.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(err) + '</p>';
                });
                return;
            }
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.explain_further')); ?>;
            if (resp.success && resp.content) {
                container.innerHTML = '<div class="guidance-content">' + markdownToHtml(resp.content) + '</div>';
            } else {
                container.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(resp.error || <?php echo json_encode(t('grc-assessment.js_failed_gen_explanation')); ?>) + '</p>';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.explain_further')); ?>;
            container.innerHTML = '<p style="color:#dc3545;font-size:12px;">' + escHtml(err.message || <?php echo json_encode(t('grc-assessment.js_connection_error')); ?>) + '</p>';
        });
    }

    function assistantRewrite(questionId, btn) {
        var ta = document.querySelector('.notes-textarea[data-question="' + questionId + '"]');
        if (!ta || !ta.value.trim()) {
            alert(<?php echo json_encode(t('grc-assessment.js_write_notes_first')); ?>);
            return;
        }

        if (!_aiEnabled) {
            alert(<?php echo json_encode(t('grc-assessment.js_rewrite_not_configured')); ?>);
            return;
        }

        // Get question text for context
        var card = btn.closest('.question-card');
        var questionText = card ? (card.querySelector('.question-text') || card.querySelector('div > div')).textContent.trim() : '';

        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment.js_rewriting')); ?>;

        // Remove any existing preview
        var existing = document.getElementById('rewrite-preview-' + questionId);
        if (existing) existing.remove();

        fetch('api/grc-unified-assessment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assistant_rewrite', text: ta.value, context: questionText,
                field: 'notes', csrf_token: _csrfToken
            })
        })
        .then(function(r) {
            if (!r.ok) {
                if (r.status === 429) throw new Error(<?php echo json_encode(t('grc-assessment.js_rate_limit')); ?>);
                throw new Error(<?php echo json_encode(t('grc-assessment.js_server_error_prefix')); ?> + r.status + ')');
            }
            return r.json();
        })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.queued && resp.job_id) {
                pollAiJob(resp.job_id, function(result) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
                    if (result.content) {
                        var preview = document.createElement('div');
                        preview.className = 'rewrite-preview';
                        preview.id = 'rewrite-preview-' + questionId;
                        preview.innerHTML = '<div class="evidence-section-label" style="margin-bottom:6px;">' + <?php echo json_encode(t('grc-assessment.js_proposed_rewrite')); ?> + '</div>' +
                            '<div class="rewrite-text">' + escHtml(result.content) + '</div>' +
                            '<div class="rewrite-actions">' +
                            '<button class="btn btn-xs btn-success" data-action="accept-rewrite" data-question-id="' + questionId + '">' + <?php echo json_encode(t('grc-assessment.js_accept')); ?> + '</button>' +
                            '<button class="btn btn-xs btn-outline" data-action="discard-rewrite" data-question-id="' + questionId + '">' + <?php echo json_encode(t('grc-assessment.js_discard')); ?> + '</button>' +
                            '</div>';
                        ta.closest('.notes-panel').insertBefore(preview, ta.closest('div').nextSibling);
                    } else { alert(<?php echo json_encode(t('grc-assessment.js_rewrite_failed')); ?>); }
                }, function(err) {
                    btn.disabled = false;
                    btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
                    alert(err);
                });
                return;
            }
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
            if (resp.success && resp.content) {
                var preview = document.createElement('div');
                preview.className = 'rewrite-preview';
                preview.id = 'rewrite-preview-' + questionId;
                preview.innerHTML = '<div class="evidence-section-label" style="margin-bottom:6px;">' + <?php echo json_encode(t('grc-assessment.js_proposed_rewrite')); ?> + '</div>' +
                    '<div class="rewrite-text">' + escHtml(resp.content) + '</div>' +
                    '<div class="rewrite-actions">' +
                    '<button class="btn btn-xs btn-success" data-action="accept-rewrite" data-question-id="' + questionId + '">' + <?php echo json_encode(t('grc-assessment.js_accept')); ?> + '</button>' +
                    '<button class="btn btn-xs btn-outline" data-action="discard-rewrite" data-question-id="' + questionId + '">' + <?php echo json_encode(t('grc-assessment.js_discard')); ?> + '</button>' +
                    '</div>';
                ta.closest('.notes-panel').insertBefore(preview, ta.closest('div').nextSibling);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_rewrite_failed_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.rewrite')); ?>;
            alert(err.message || <?php echo json_encode(t('grc-assessment.js_connection_error')); ?>);
        });
    }

    function acceptRewrite(questionId) {
        var preview = document.getElementById('rewrite-preview-' + questionId);
        var ta = document.querySelector('.notes-textarea[data-question="' + questionId + '"]');
        if (preview && ta) {
            var rewriteText = preview.querySelector('.rewrite-text');
            if (rewriteText) {
                ta.value = rewriteText.textContent;
                // Trigger save
                var data = {
                    action: 'save_response', assessment_id: _assessmentId,
                    question_id: questionId, csrf_token: _csrfToken, notes: ta.value
                };
                saveResponse(data);
            }
            preview.remove();
        }
    }

    function markdownToHtml(md) {
        // Simple markdown to HTML: headers, bold, bullets
        var html = escHtml(md);
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/^\- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
        html = html.replace(/\n/g, '<br>');
        html = html.replace(/<br><h2>/g, '<h2>');
        html = html.replace(/<\/h2><br>/g, '</h2>');
        html = html.replace(/<br><ul>/g, '<ul>');
        html = html.replace(/<\/ul><br>/g, '</ul>');
        html = html.replace(/<br><li>/g, '<li>');
        html = html.replace(/<\/li><br>/g, '</li>');
        return html;
    }

    // ─── Evidence Functions ─────────────────────────────────────────────

    function loadEvidenceList(responseId) {
        var list = document.getElementById('ev-list-' + responseId);
        if (!list) return;
        list.innerHTML = '<li style="color:#9ca3af;font-style:italic;border:none;background:none;padding:2px 0;">' + <?php echo json_encode(t('grc-assessment.loading')); ?> + '</li>';

        fetch('api/grc-assessment-evidence-upload.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list', response_id: responseId, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (!resp.success) { list.innerHTML = '<li style="color:#dc3545;border:none;background:none;padding:2px 0;">' + escHtml(resp.error || <?php echo json_encode(t('grc-assessment.js_error')); ?>) + '</li>'; return; }
            if (!resp.evidence || resp.evidence.length === 0) {
                list.innerHTML = '<li style="color:#9ca3af;font-style:italic;border:none;background:none;padding:2px 0;">' + <?php echo json_encode(t('grc-assessment.js_no_evidence')); ?> + '</li>';
                return;
            }
            var html = '';
            resp.evidence.forEach(function(ev) {
                var sizeKB = ev.file_size ? Math.round(ev.file_size / 1024) + ' KB' : '';
                html += '<li>';
                html += '<span class="ev-title" title="' + escHtml(ev.file_name || ev.title || '') + '">' + escHtml(ev.evidence_ref || '') + ' &mdash; ' + escHtml(ev.title || ev.file_name || '') + '</span>';
                html += '<span class="ev-meta">' + escHtml(ev.evidence_type || '') + (sizeKB ? ' · ' + sizeKB : '') + '</span>';
                html += '<button class="ev-remove" data-action="unlink-evidence" data-response-id="' + responseId + '" data-evidence-id="' + ev.id + '" title="' + <?php echo json_encode(t('grc-assessment.js_remove_link')); ?> + '">&times;</button>';
                html += '</li>';
            });
            list.innerHTML = html;
        })
        .catch(function() { list.innerHTML = '<li style="color:#dc3545;border:none;background:none;padding:2px 0;">' + <?php echo json_encode(t('grc-assessment.js_failed_load_evidence')); ?> + '</li>'; });
    }

    function uploadEvidence(responseId, btn) {
        var fileInput = document.getElementById('ev-file-' + responseId);
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert(<?php echo json_encode(t('grc-assessment.js_select_file')); ?>);
            return;
        }
        var file = fileInput.files[0];
        if (file.size > 52428800) {
            alert(<?php echo json_encode(t('grc-assessment.js_file_too_large')); ?>);
            return;
        }

        btn.disabled = true;
        btn.textContent = <?php echo json_encode(t('grc-assessment.js_uploading')); ?>;

        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('response_id', responseId);
        fd.append('csrf_token', _csrfToken);
        fd.append('evidence_file', file);
        fd.append('title', file.name);

        fetch('api/grc-assessment-evidence-upload.php', {
            method: 'POST',
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.upload')); ?>;
            if (resp.success) {
                fileInput.value = '';
                loadEvidenceList(responseId);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_upload_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(t('grc-assessment.upload')); ?>;
            alert(<?php echo json_encode(t('grc-assessment.js_upload_failed_prefix')); ?> + err.message);
        });
    }

    function unlinkEvidence(responseId, evidenceId) {
        if (!confirm(<?php echo json_encode(t('grc-assessment.js_remove_evidence_link')); ?>)) return;

        fetch('api/grc-assessment-evidence-upload.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'unlink', response_id: responseId, evidence_id: evidenceId, csrf_token: _csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            syncCsrf(resp.csrf_token);
            if (resp.success) {
                loadEvidenceList(responseId);
            } else {
                alert(<?php echo json_encode(t('grc-assessment.js_unlink_error_prefix')); ?> + (resp.error || <?php echo json_encode(t('grc-assessment.js_unknown_error')); ?>));
            }
        });
    }

    function updateScoreDisplays(resp) {
        // Update CSF Maturity Score
        var matEl = document.getElementById('csfMaturityScore');
        if (matEl && resp.overall_fairscore !== undefined) {
            var fs = resp.overall_fairscore !== null ? parseFloat(resp.overall_fairscore) : 0;
            matEl.textContent = resp.overall_fairscore !== null ? resp.overall_fairscore : '—';
            matEl.className = 'fairscore-big ' + (fs >= 3 ? 'fairscore-good' : (fs >= 2 ? 'fairscore-ok' : 'fairscore-low'));
        }
        // Update Compliance %
        var compEl = document.getElementById('compliancePct');
        if (compEl && resp.overall_compliance_pct !== undefined) {
            compEl.textContent = resp.overall_compliance_pct !== null ? resp.overall_compliance_pct : '—';
        }
        // Update domain tab score badges
        if (resp.domain_scores) {
            resp.domain_scores.forEach(function(ds) {
                var badge = document.querySelector('.domain-score-badge[data-domain-code="' + ds.domain_code + '"]');
                if (badge) {
                    if (ds.average_score !== null) {
                        var c = ds.average_score >= 3 ? '#28a745' : (ds.average_score >= 2 ? '#f59e0b' : '#dc3545');
                        badge.style.background = c;
                        badge.textContent = ds.average_score;
                        badge.style.display = '';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            });
        }
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ─── Hide/Show Answered Questions ───────────────────────────────────
    function toggleAnsweredQuestions() {
        _hideAnswered = !_hideAnswered;
        try { localStorage.setItem(_hideKey, _hideAnswered ? '1' : '0'); } catch(e) {}
        applyAnsweredVisibility();
    }

    var _activeFilter = 'all'; // 'all', 'audited', 'unanswered'

    function resetAllCards() {
        document.querySelectorAll('.question-card').forEach(function(card) {
            card.style.display = '';
            card.style.border = '';
            card.style.boxShadow = '';
        });
    }

    function toggleAnsweredQuestions() {
        if (_activeFilter === 'audited') {
            // Already showing audited, go back to all
            _activeFilter = 'all';
            resetAllCards();
        } else {
            // Show ONLY validated questions, hide everything else
            _activeFilter = 'audited';
            document.querySelectorAll('.question-card').forEach(function(card) {
                if (card.getAttribute('data-validated') === '1') {
                    card.style.display = '';
                    card.style.border = '2px solid #28a745';
                    card.style.boxShadow = '0 0 8px rgba(40,167,69,0.3)';
                } else {
                    card.style.display = 'none';
                    card.style.border = '';
                    card.style.boxShadow = '';
                }
            });
        }
        updateFilterButtons();
        updateFilterInfo();
    }

    function showUnansweredOnly() {
        if (_activeFilter === 'unanswered') {
            // Already showing unanswered, go back to all
            _activeFilter = 'all';
            resetAllCards();
        } else {
            // Show ONLY unanswered questions, hide everything else
            _activeFilter = 'unanswered';
            document.querySelectorAll('.question-card').forEach(function(card) {
                if (card.getAttribute('data-answered') === '0') {
                    card.style.display = '';
                    card.style.border = '2px solid #f59e0b';
                    card.style.boxShadow = '0 0 8px rgba(245,158,11,0.3)';
                } else {
                    card.style.display = 'none';
                    card.style.border = '';
                    card.style.boxShadow = '';
                }
            });
            // Scroll to first unanswered
            var first = document.querySelector('.question-card[data-answered="0"]');
            if (first) first.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        updateFilterButtons();
        updateFilterInfo();
    }

    function updateFilterButtons() {
        var aBtn = document.getElementById('toggleAnsweredBtn');
        var uBtn = document.getElementById('showUnansweredBtn');
        if (aBtn) {
            if (_activeFilter === 'audited') {
                aBtn.textContent = <?php echo json_encode(t('grc-assessment.show_all_questions')); ?>;
                aBtn.className = 'btn btn-sm btn-success';
            } else {
                aBtn.textContent = <?php echo json_encode(t('grc-assessment.show_audited')); ?>;
                aBtn.className = 'btn btn-sm btn-outline';
            }
            aBtn.style.display = (_activeFilter === 'unanswered') ? 'none' : '';
        }
        if (uBtn) {
            if (_activeFilter === 'unanswered') {
                uBtn.textContent = <?php echo json_encode(t('grc-assessment.show_all_questions')); ?>;
                uBtn.className = 'btn btn-sm btn-info';
            } else {
                uBtn.textContent = <?php echo json_encode(t('grc-assessment.js_show_unanswered_questions')); ?>;
                uBtn.className = 'btn btn-sm btn-warning';
            }
            uBtn.style.display = (_activeFilter === 'audited') ? 'none' : '';
        }
    }

    function updateFilterInfo() {
        var visible = document.querySelectorAll('.question-card:not([style*="display: none"]):not([style*="display:none"])');
        var total = document.querySelectorAll('.question-card').length;
        var info = document.getElementById('hideAnsweredInfo');
        var countEl = document.getElementById('hiddenCount');
        if (info && countEl) {
            if (_activeFilter !== 'all') {
                countEl.textContent = visible.length;
                info.style.display = '';
            } else {
                info.style.display = 'none';
            }
        }
    }

    // No persistent filter state — always start showing all questions
})();
</script>
<?php include __DIR__ . '/includes/scroll_to_top.php'; ?>
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
                    <span><?php echo e(t('grc-assessment.all_rights_reserved')); ?></span>
                </p>
            </div>
        </div>
    </footer>
</div>
</body>
</html>
